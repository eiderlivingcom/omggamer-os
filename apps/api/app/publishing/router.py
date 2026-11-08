"""Storefront connector settings, the publishing queue and the connector plugin download."""

import io
import json
import secrets
import uuid
import zipfile
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

from fastapi import APIRouter, Query, Request, Response
from pydantic import BaseModel, Field
from sqlalchemy import func, select, update

from app.catalogue.bulk import default_storefront
from app.core.deps import DB, require
from app.core.errors import AppError
from app.core.security import encrypt_secret
from app.db.models import Product, PublishingQueueItem, Storefront, StorefrontProductMapping, SyncLog, User
from app.db.models.jobs import ACTIVE_JOB_STATUSES
from app.modules.audit.service import record
from app.publishing import queue as publish_queue
from app.publishing.connector import ConnectorClient, ConnectorError, connector_url

router = APIRouter(tags=["publishing"])
PLUGIN_DIR = Path(__file__).resolve().parents[2] / "wp-connector" / "omggamer-catalogue-connector"


def _now() -> datetime:
    return datetime.now(UTC)


class ConnectorOut(BaseModel):
    storefront: str
    name: str
    domain: str
    enabled: bool
    url: str
    secret_set: bool
    status: str | None
    checked_at: str | None
    error: str | None
    plugin_version: str | None
    woocommerce: str | None
    dokan: str | None
    currency: str | None


class ConnectorIn(BaseModel):
    enabled: bool | None = None
    connector_url: str | None = Field(default=None, max_length=300)


class SecretOut(BaseModel):
    secret: str
    note: str


class QueueItemOut(BaseModel):
    id: uuid.UUID
    product_id: uuid.UUID
    product_title: str | None
    storefront: str | None
    action: str
    status: str
    approval_status: str
    attempts: int
    last_error: str | None
    created_at: datetime
    updated_at: datetime


class UnpublishIn(BaseModel):
    product_ids: list[uuid.UUID] = Field(min_length=1, max_length=500)
    storefront: str | None = Field(default=None, max_length=40)


def _out(sf: Storefront) -> ConnectorOut:
    cfg = sf.configuration or {}
    return ConnectorOut(
        storefront=sf.code,
        name=sf.name,
        domain=sf.domain,
        enabled=sf.status == "active",
        url=connector_url(sf),
        secret_set=bool(sf.credentials_enc),
        status=cfg.get("connector_status"),
        checked_at=cfg.get("connector_checked_at"),
        error=cfg.get("connector_error"),
        plugin_version=cfg.get("plugin_version"),
        woocommerce=cfg.get("woocommerce_version"),
        dokan=cfg.get("dokan_version"),
        currency=cfg.get("store_currency"),
    )


async def _sf(db: DB, code: str) -> Storefront:
    sf = (await db.execute(select(Storefront).where(Storefront.code == code))).scalar_one_or_none()
    if sf is None:
        raise AppError(404, "not_found", "Storefront not found.")
    return sf


@router.get("/storefronts/{code}/connector", response_model=ConnectorOut)
async def get_connector(code: str, db: DB, _: User = require("catalogue:read")) -> ConnectorOut:
    return _out(await _sf(db, code))


@router.put("/storefronts/{code}/connector", response_model=ConnectorOut)
async def put_connector(
    code: str, body: ConnectorIn, request: Request, db: DB, actor: User = require("publishing:manage")
) -> ConnectorOut:
    sf = await _sf(db, code)
    cfg = dict(sf.configuration or {})
    if body.connector_url is not None:
        url = body.connector_url.strip()
        if url and not url.startswith("https://"):
            raise AppError(422, "validation_error", "The connector URL must start with https://")
        cfg["connector_url"] = url or None
    sf.configuration = cfg
    if body.enabled is not None:
        if body.enabled and not sf.credentials_enc:
            raise AppError(
                409, "not_configured", "Generate the connector secret and test the connection first."
            )
        sf.status = "active" if body.enabled else "disabled"
    await record(
        db,
        "storefront.connector",
        actor=actor,
        entity_type="storefront",
        entity_id=str(sf.id),
        summary=f"{'Enabled' if sf.status == 'active' else 'Disabled'} publishing to {sf.name}",
        request=request,
    )
    await db.commit()
    return _out(sf)


@router.post("/storefronts/{code}/connector/secret", response_model=SecretOut)
async def new_secret(
    code: str, request: Request, db: DB, actor: User = require("credentials:manage")
) -> SecretOut:
    sf = await _sf(db, code)
    secret = secrets.token_urlsafe(48)
    sf.credentials_enc = encrypt_secret(json.dumps({"secret": secret}))
    sf.configuration = {**(sf.configuration or {}), "connector_status": "untested", "connector_error": None}
    await record(
        db,
        "credentials.updated",
        actor=actor,
        entity_type="storefront",
        entity_id=str(sf.id),
        summary=f"Generated a new connector secret for {sf.name}",
        request=request,
    )
    await db.commit()
    return SecretOut(
        secret=secret,
        note="Shown once. Paste it in WordPress → WooCommerce → OMGGamer Connector, then press Test.",
    )


@router.post("/storefronts/{code}/connector/test", response_model=ConnectorOut)
async def test_connector(code: str, db: DB, _: User = require("publishing:manage")) -> ConnectorOut:
    sf = await _sf(db, code)
    cfg = dict(sf.configuration or {})
    try:
        client = ConnectorClient(sf, max_attempts=2, timeout=20)
        try:
            info = await client.status()
        finally:
            await client.aclose()
        cfg.update(
            connector_status="connected",
            connector_error=None,
            plugin_version=info.get("plugin_version"),
            woocommerce_version=info.get("woocommerce"),
            dokan_version=info.get("dokan"),
            store_currency=info.get("currency"),
        )
    except ConnectorError as exc:
        cfg.update(connector_status="failed", connector_error=str(exc)[:1000])
    cfg["connector_checked_at"] = _now().isoformat()
    sf.configuration = cfg
    await db.commit()
    return _out(sf)


@router.get("/connector/plugin.zip", include_in_schema=False)
async def plugin_zip() -> Response:
    """The connector plugin itself (public: it contains no secrets)."""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        for path in sorted(PLUGIN_DIR.rglob("*")):
            if path.is_file():
                z.write(path, Path("omggamer-catalogue-connector") / path.relative_to(PLUGIN_DIR))
    return Response(
        buf.getvalue(),
        media_type="application/zip",
        headers={"Content-Disposition": 'attachment; filename="omggamer-catalogue-connector.zip"'},
    )


# ------------------------------------------------------------------ queue


@router.get("/publishing/overview", response_model=dict[str, Any])
async def overview(db: DB, _: User = require("catalogue:read")) -> dict[str, Any]:
    queue = dict(
        (
            await db.execute(
                select(PublishingQueueItem.status, func.count()).group_by(PublishingQueueItem.status)
            )
        )
        .tuples()
        .all()
    )
    listings = dict(
        (
            await db.execute(
                select(StorefrontProductMapping.sync_status, func.count()).group_by(
                    StorefrontProductMapping.sync_status
                )
            )
        )
        .tuples()
        .all()
    )
    approved = int(
        await db.scalar(select(func.count()).select_from(Product).where(Product.product_status == "approved"))
        or 0
    )
    sfs = [_out(s) for s in (await db.execute(select(Storefront).order_by(Storefront.code))).scalars()]
    return {
        "queue": {k: int(v) for k, v in queue.items()},
        "listings": {k: int(v) for k, v in listings.items()},
        "approved_not_published": approved,
        "storefronts": [s.model_dump() for s in sfs],
    }


@router.get("/publishing/queue", response_model=list[QueueItemOut])
async def list_queue(
    db: DB,
    status: str | None = Query(default=None, max_length=20),
    limit: int = Query(default=100, ge=1, le=500),
    _: User = require("catalogue:read"),
) -> list[QueueItemOut]:
    q = (
        select(PublishingQueueItem, Product.canonical_title, Storefront.code)
        .join(Product, Product.id == PublishingQueueItem.product_id)
        .outerjoin(Storefront, Storefront.id == PublishingQueueItem.storefront_id)
    )
    if status:
        q = q.where(PublishingQueueItem.status == status)
    rows = await db.execute(q.order_by(PublishingQueueItem.updated_at.desc()).limit(limit))
    return [
        QueueItemOut(
            id=i.id,
            product_id=i.product_id,
            product_title=t,
            storefront=c,
            action=i.action,
            status=i.status,
            approval_status=i.approval_status,
            attempts=i.attempts,
            last_error=i.last_error,
            created_at=i.created_at,
            updated_at=i.updated_at,
        )
        for i, t, c in rows.tuples().all()
    ]


@router.post("/publishing/queue/{item_id}/retry", response_model=dict[str, Any])
async def retry(item_id: uuid.UUID, db: DB, _: User = require("publishing:manage")) -> dict[str, Any]:
    item = await db.get(PublishingQueueItem, item_id)
    if item is None:
        raise AppError(404, "not_found", "Queue item not found.")
    if item.status not in ("failed", "cancelled"):
        raise AppError(409, "invalid_state", f"A {item.status} item cannot be retried.")
    item.status, item.attempts, item.last_error = "queued", 0, None
    await db.commit()
    publish_queue.enqueue([item.id])
    return {"status": "queued"}


@router.post("/publishing/queue/{item_id}/cancel", response_model=dict[str, Any])
async def cancel(item_id: uuid.UUID, db: DB, _: User = require("publishing:manage")) -> dict[str, Any]:
    item = await db.get(PublishingQueueItem, item_id)
    if item is None:
        raise AppError(404, "not_found", "Queue item not found.")
    if item.status not in ("queued", "failed"):
        raise AppError(409, "invalid_state", f"A {item.status} item cannot be cancelled.")
    item.status = "cancelled"
    await db.commit()
    return {"status": "cancelled"}


@router.post("/publishing/retry-failed", response_model=dict[str, int])
async def retry_failed(db: DB, _: User = require("publishing:manage")) -> dict[str, int]:
    res = await db.execute(
        update(PublishingQueueItem)
        .where(PublishingQueueItem.status == "failed")
        .values(status="queued", attempts=0, last_error=None)
        .returning(PublishingQueueItem.id)
    )
    ids = list(res.scalars())
    await db.commit()
    publish_queue.enqueue(ids)
    return {"queued": len(ids)}


@router.post("/publishing/unpublish", response_model=dict[str, int])
async def unpublish(
    body: UnpublishIn, request: Request, db: DB, actor: User = require("publishing:manage")
) -> dict[str, int]:
    sf = await default_storefront(db, body.storefront)
    ids = []
    for pid in dict.fromkeys(body.product_ids):
        busy = await db.scalar(
            select(PublishingQueueItem.id).where(
                PublishingQueueItem.product_id == pid,
                PublishingQueueItem.storefront_id == sf.id,
                PublishingQueueItem.status.in_(ACTIVE_JOB_STATUSES),
            )
        )
        if busy or await db.get(Product, pid) is None:
            continue
        item = PublishingQueueItem(
            product_id=pid,
            storefront_id=sf.id,
            action="unpublish",
            status="queued",
            approval_status="approved",
            approved_by=actor.id,
            approved_at=_now(),
        )
        db.add(item)
        await db.flush()
        ids.append(item.id)
    await record(
        db,
        "publishing.unpublish",
        actor=actor,
        summary=f"Unpublish {len(ids)} products from {sf.name}",
        request=request,
    )
    await db.commit()
    publish_queue.enqueue(ids)
    return {"queued": len(ids)}


@router.get("/publishing/sync-logs", response_model=list[dict[str, Any]])
async def sync_logs(
    db: DB, limit: int = Query(default=100, ge=1, le=500), _: User = require("catalogue:read")
) -> list[dict[str, Any]]:
    rows = await db.execute(
        select(SyncLog, Storefront.code, Product.canonical_title)
        .join(Storefront, Storefront.id == SyncLog.storefront_id)
        .outerjoin(Product, Product.id == SyncLog.product_id)
        .order_by(SyncLog.created_at.desc())
        .limit(limit)
    )
    return [
        {
            "id": str(s.id),
            "storefront": code,
            "product_id": str(s.product_id) if s.product_id else None,
            "product_title": title,
            "operation": s.operation,
            "status": s.status,
            "response": s.response,
            "created_at": s.created_at.isoformat(),
        }
        for s, code, title in rows.tuples().all()
    ]
