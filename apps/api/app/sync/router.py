"""Sync settings and status, plus the server-to-server webhooks (Kinguin, storefront availability checks).

Webhook routes live under /api/v1/webhooks/, which the browser-origin check exempts; each one
authenticates the caller itself.
"""

import hmac
import json
import secrets
import time
import uuid
from datetime import UTC, datetime
from typing import Any

from fastapi import APIRouter, Query, Request
from pydantic import BaseModel, Field
from sqlalchemy import func, select
from sqlalchemy.dialects.postgresql import insert as pg_insert

from app.core.deps import DB, require
from app.core.errors import AppError
from app.core.security import encrypt_secret
from app.db.models import Storefront, Supplier, SyncLog, User, WebhookInbox
from app.db.models.pricing import VariantPrice
from app.modules.audit.service import record
from app.publishing.connector import secret_for, sign
from app.suppliers import registry
from app.sync import service
from app.workers.celery_app import celery_app

router = APIRouter(tags=["sync"])


def _now() -> datetime:
    return datetime.now(UTC)


class SyncSettingsIn(BaseModel):
    enabled: bool | None = None
    interval_minutes: int | None = Field(default=None, ge=5, le=1440)
    cron: str | None = Field(default=None, max_length=60)
    full_check_hour: int | None = Field(default=None, ge=0, le=23)
    strict_checkout: bool | None = None


# ------------------------------------------------------------------ settings and status


@router.get("/sync/overview", response_model=dict[str, Any])
async def overview(db: DB, _: User = require("catalogue:read")) -> dict[str, Any]:
    s = await service.get_settings(db)
    pending = int(
        await db.scalar(
            select(func.count())
            .select_from(VariantPrice)
            .where(
                (VariantPrice.pushed_at.is_not(None))
                & (
                    (VariantPrice.pushed_price.is_distinct_from(VariantPrice.selling_price))
                    | (VariantPrice.pushed_in_stock.is_distinct_from(VariantPrice.available))
                )
            )
        )
        or 0
    )
    logs = await db.execute(
        select(SyncLog, Storefront.code)
        .join(Storefront, Storefront.id == SyncLog.storefront_id)
        .where(SyncLog.operation == "price")
        .order_by(SyncLog.created_at.desc())
        .limit(30)
    )
    inbox = dict(
        (await db.execute(select(WebhookInbox.state, func.count()).group_by(WebhookInbox.state)))
        .tuples()
        .all()
    )
    kinguin = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one_or_none()
    webhook_set = False
    if kinguin is not None:
        conn = await registry.connection_for(db, kinguin, registry.active_environment(kinguin))
        webhook_set = bool(registry.decrypt_credentials(conn).get("webhook_secret")) if conn else False
    return {
        "settings": s,
        "pending_changes": pending,
        "webhooks": {k: int(v) for k, v in inbox.items()},
        "kinguin_webhook_configured": webhook_set,
        "kinguin_webhook_url": "https://os.omggamer.com/api/v1/webhooks/kinguin",
        "recent": [
            {
                "storefront": code,
                "status": s_.status,
                "response": s_.response,
                "created_at": s_.created_at.isoformat(),
            }
            for s_, code in logs.tuples().all()
        ],
    }


@router.put("/sync/settings", response_model=dict[str, Any])
async def put_settings(
    body: SyncSettingsIn, request: Request, db: DB, actor: User = require("automation:manage")
) -> dict[str, Any]:
    changes = body.model_dump(exclude_none=True)
    if "cron" in body.model_fields_set and body.cron in ("", None):
        changes["cron"] = None
    if changes.get("cron"):
        from celery.schedules import crontab

        parts = changes["cron"].split()
        if len(parts) != 5:
            raise AppError(
                422, "validation_error", "A custom schedule needs 5 cron fields, e.g. */10 * * * *"
            )
        try:
            crontab(*parts)
        except Exception as exc:
            raise AppError(422, "validation_error", f"Invalid cron expression: {exc}") from exc
    s = await service.save_settings(db, changes, actor.id)
    await record(
        db,
        "sync.settings",
        actor=actor,
        summary="Changed price/stock sync settings",
        data=changes,
        request=request,
    )
    await db.commit()
    return s


@router.post("/sync/run-now", response_model=dict[str, Any])
async def run_now(db: DB, _: User = require("automation:manage")) -> dict[str, Any]:
    celery_app.send_task("sync.run", queue="default")
    return {"queued": True}


@router.post("/sync/push", response_model=dict[str, Any])
async def push_now(db: DB, full: bool = False, _: User = require("publishing:manage")) -> dict[str, Any]:
    celery_app.send_task("sync.push", kwargs={"full": full}, queue="publish")
    return {"queued": True, "full": full}


@router.post("/suppliers/{code}/webhook-secret", response_model=dict[str, str])
async def kinguin_webhook_secret(
    code: str, request: Request, db: DB, actor: User = require("credentials:manage")
) -> dict[str, str]:
    supplier = (await db.execute(select(Supplier).where(Supplier.code == code))).scalar_one_or_none()
    if supplier is None:
        raise AppError(404, "not_found", "Supplier not found.")
    env = registry.active_environment(supplier)
    conn = await registry.connection_for(db, supplier, env)
    if conn is None or not conn.credentials_enc:
        raise AppError(409, "not_configured", "Save the API key first.")
    creds = registry.decrypt_credentials(conn)
    creds["webhook_secret"] = secrets.token_urlsafe(32)
    conn.credentials_enc = encrypt_secret(json.dumps(creds))
    await record(
        db,
        "credentials.updated",
        actor=actor,
        entity_type="supplier",
        entity_id=str(supplier.id),
        summary=f"Generated a {supplier.name} webhook secret",
        request=request,
    )
    await db.commit()
    return {
        "secret": creds["webhook_secret"],
        "url": f"https://os.omggamer.com/api/v1/webhooks/{code}",
        "note": "Shown once. In Kinguin → My Stores → Webhooks, set this URL and secret for product.update.",
    }


# ------------------------------------------------------------------ webhooks


@router.post("/webhooks/kinguin", response_model=dict[str, Any])
async def kinguin_webhook(request: Request, db: DB) -> dict[str, Any]:
    """Kinguin product.update / order.status (documented headers X-Event-Name and X-Event-Secret)."""
    supplier = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one_or_none()
    if supplier is None:
        raise AppError(404, "not_found", "Unknown supplier.")
    conn = await registry.connection_for(db, supplier, registry.active_environment(supplier))
    expected = str(registry.decrypt_credentials(conn).get("webhook_secret") or "") if conn else ""
    given = request.headers.get("x-event-secret", "")
    if not expected or not hmac.compare_digest(expected, given):
        raise AppError(401, "unauthorized", "Invalid webhook secret.")
    event = request.headers.get("x-event-name", "")
    try:
        payload = json.loads(await request.body())
    except ValueError as exc:
        raise AppError(400, "bad_request", "Body must be JSON.") from exc
    if not isinstance(payload, dict):
        raise AppError(400, "bad_request", "Body must be a JSON object.")
    if event == "product.update":
        key = f"{payload.get('kinguinId')}:{payload.get('updatedAt')}"
    elif event == "order.status":
        key = f"{payload.get('orderId')}:{payload.get('status')}:{payload.get('updatedAt')}"
    else:
        return {"ok": True, "ignored": event}
    res = await db.execute(
        pg_insert(WebhookInbox)
        .values(id=uuid.uuid4(), source="kinguin", event=event, dedupe_key=key[:120], payload=payload)
        .on_conflict_do_nothing(index_elements=["source", "dedupe_key"])
        .returning(WebhookInbox.id)
    )
    inbox_id = res.scalar_one_or_none()
    await db.commit()
    if inbox_id is not None:
        task = "sync.refresh_product" if event == "product.update" else "orders.supplier_status"
        celery_app.send_task(task, args=[str(inbox_id)], queue="default")
    return {"ok": True, "duplicate": inbox_id is None}


class AvailabilityIn(BaseModel):
    items: list[dict[str, Any]] = Field(max_length=100)


async def verify_storefront(request: Request, db: DB, code: str) -> tuple[Storefront, bytes]:
    """Storefront → OMGGamer OS requests use the same HMAC scheme as OMGGamer OS → storefront."""
    sf = (await db.execute(select(Storefront).where(Storefront.code == code))).scalar_one_or_none()
    if sf is None or not sf.credentials_enc:
        raise AppError(401, "unauthorized", "Unknown storefront.")
    body = await request.body()
    ts, sig = request.headers.get("x-omg-timestamp", ""), request.headers.get("x-omg-signature", "")
    if not ts.isdigit() or abs(time.time() - int(ts)) > 300:
        raise AppError(401, "unauthorized", "Request timestamp outside the allowed window.")
    if not hmac.compare_digest(sign(secret_for(sf), int(ts), body), sig.lower()):
        raise AppError(401, "unauthorized", "Invalid signature.")
    return sf, body


@router.post("/webhooks/storefronts/{code}/availability", response_model=dict[str, Any])
async def availability(
    code: str, request: Request, db: DB, live: bool = Query(default=True)
) -> dict[str, Any]:
    sf, body = await verify_storefront(request, db, code)
    try:
        data = AvailabilityIn.model_validate_json(body)
        ids = [uuid.UUID(str(i["variant_id"])) for i in data.items]
    except Exception as exc:
        raise AppError(422, "validation_error", "Send items with variant_id.") from exc
    results = await service.check_availability(db, sf, ids, live=live)
    await db.commit()
    return {"ok": True, "items": results, "checked_at": _now().isoformat()}
