"""Storefront home content: which products each home section shows, and the hero banners.

Only products live on the store can be chosen (a synced store listing). Saved per storefront in app
settings ("storefront_content:<code>") and sent to the store through the catalogue connector
(/storefront/content), which the store's theme reads. A section left empty stays automatic on the store.
"""

import uuid
from datetime import datetime
from typing import Any

from fastapi import APIRouter, Query, Request
from pydantic import BaseModel, Field, HttpUrl
from sqlalchemy import func, or_, select

from app.catalogue.bulk import default_storefront
from app.core.deps import DB, require
from app.core.errors import AppError
from app.db.models import AppSetting, Product, Storefront, StorefrontProductMapping, User
from app.modules.audit.service import record
from app.publishing.connector import ConnectorClient, ConnectorError

router = APIRouter(tags=["storefront content"])

SECTIONS = {
    "flash": "Flash deals",
    "best": "Best selling games",
    "accounts": "Game accounts",
    "hardware": "Hardware",
    "accessories": "Accessories",
}
MAX_PER_SECTION = 24
MAX_BANNERS = 8


class Pick(BaseModel):
    product_id: uuid.UUID
    listing: str = Field(default="", max_length=60)


class Banner(BaseModel):
    title: str = Field(min_length=1, max_length=80)
    highlight: str = Field(default="", max_length=60)
    subtitle: str = Field(default="", max_length=200)
    tags: list[str] = Field(default_factory=list, max_length=3)
    button: str = Field(default="Shop now", max_length=40)
    url: str = Field(default="", max_length=500)
    image: HttpUrl
    image_mobile: HttpUrl | None = None
    active: bool = True
    starts_at: datetime | None = None
    ends_at: datetime | None = None


class ContentIn(BaseModel):
    sections: dict[str, list[Pick]] = Field(default_factory=dict)
    banners: list[Banner] = Field(default_factory=list, max_length=MAX_BANNERS)


def _key(sf: Storefront) -> str:
    return f"storefront_content:{sf.code}"


def _label(title: str, listing: str) -> str:
    return title if not listing else f"{title} · {listing.replace('-', ' ')}"


async def _live(
    db: DB, sf: Storefront, picks: list[Pick]
) -> dict[tuple[uuid.UUID, str], tuple[Product, str]]:
    """The picks that are live on the store: (product, WooCommerce ID)."""
    if not picks:
        return {}
    rows = (
        await db.execute(
            select(StorefrontProductMapping, Product)
            .join(Product, Product.id == StorefrontProductMapping.product_id)
            .where(
                StorefrontProductMapping.storefront_id == sf.id,
                StorefrontProductMapping.product_id.in_({p.product_id for p in picks}),
                StorefrontProductMapping.sync_status == "synced",
                StorefrontProductMapping.external_product_id.is_not(None),
                Product.product_status == "published",
            )
        )
    ).all()
    return {(m.product_id, m.listing): (p, str(m.external_product_id)) for m, p in rows}


async def _load(db: DB, sf: Storefront) -> dict[str, Any]:
    row = await db.get(AppSetting, _key(sf))
    value = (row.value if row else None) or {}
    return {
        "sections": value.get("sections") or {},
        "banners": value.get("banners") or [],
        "pushed_at": value.get("pushed_at"),
    }


@router.get("/storefronts/{code}/home-content", response_model=dict[str, Any])
async def get_content(code: str, db: DB, _: User = require("catalogue:read")) -> dict[str, Any]:
    sf = await default_storefront(db, code)
    data = await _load(db, sf)
    picks = [Pick(**p) for items in data["sections"].values() for p in items]
    live = await _live(db, sf, picks)
    sections = {}
    for key, name in SECTIONS.items():
        items = []
        for p in data["sections"].get(key, []):
            pick = Pick(**p)
            found = live.get((pick.product_id, pick.listing))
            items.append(
                {
                    "product_id": str(pick.product_id),
                    "listing": pick.listing,
                    "title": _label(found[0].canonical_title, pick.listing) if found else "No longer live",
                    "sku": found[0].sku if found else None,
                    "wc_id": found[1] if found else None,
                    "live": bool(found),
                }
            )
        sections[key] = {"name": name, "items": items}
    return {"sections": sections, "banners": data["banners"], "pushed_at": data["pushed_at"]}


@router.get("/storefronts/{code}/home-content/search", response_model=list[dict[str, Any]])
async def search_live(
    code: str,
    db: DB,
    q: str = Query(min_length=2, max_length=120),
    limit: int = Query(default=20, ge=1, le=50),
    _: User = require("catalogue:read"),
) -> list[dict[str, Any]]:
    """Products live on the store (each store listing), to add to a section."""
    sf = await default_storefront(db, code)
    like = f"%{q.strip()}%"
    rows = (
        await db.execute(
            select(StorefrontProductMapping, Product)
            .join(Product, Product.id == StorefrontProductMapping.product_id)
            .where(
                StorefrontProductMapping.storefront_id == sf.id,
                StorefrontProductMapping.sync_status == "synced",
                StorefrontProductMapping.external_product_id.is_not(None),
                Product.product_status == "published",
                or_(Product.canonical_title.ilike(like), Product.sku.ilike(like)),
            )
            .order_by(
                func.length(Product.canonical_title),
                Product.canonical_title,
                StorefrontProductMapping.listing,
            )
            .limit(limit)
        )
    ).all()
    return [
        {
            "product_id": str(p.id),
            "listing": m.listing,
            "title": _label(p.canonical_title, m.listing),
            "sku": p.sku,
            "product_type": p.product_type,
            "wc_id": m.external_product_id,
        }
        for m, p in rows
    ]


@router.put("/storefronts/{code}/home-content", response_model=dict[str, Any])
async def save_content(
    code: str, body: ContentIn, request: Request, db: DB, actor: User = require("publishing:manage")
) -> dict[str, Any]:
    """Save the sections and banners and send them to the store."""
    sf = await default_storefront(db, code)
    unknown = set(body.sections) - set(SECTIONS)
    if unknown:
        raise AppError(422, "invalid_section", f"Unknown section: {', '.join(sorted(unknown))}.")
    picks = [p for items in body.sections.values() for p in items]
    live = await _live(db, sf, picks)
    sections: dict[str, list[dict[str, str]]] = {}
    wc: dict[str, list[int]] = {}
    for key in SECTIONS:
        items = body.sections.get(key, [])
        if len(items) > MAX_PER_SECTION:
            raise AppError(422, "too_many", f"{SECTIONS[key]}: at most {MAX_PER_SECTION} products.")
        seen: set[tuple[uuid.UUID, str]] = set()
        sections[key], wc[key] = [], []
        for p in items:
            k = (p.product_id, p.listing)
            if k in seen:
                continue
            seen.add(k)
            if k not in live:
                raise AppError(
                    422, "not_live", f"{SECTIONS[key]}: only products live on {sf.name} can be chosen."
                )
            sections[key].append({"product_id": str(p.product_id), "listing": p.listing})
            wc[key].append(int(live[k][1]))
    for b in body.banners:
        if b.starts_at and b.ends_at and b.ends_at <= b.starts_at:
            raise AppError(422, "bad_dates", f'Banner "{b.title}": the end must be after the start.')
    banners = [b.model_dump(mode="json") for b in body.banners]

    try:
        client = ConnectorClient(sf)
    except ConnectorError as exc:
        raise AppError(409, "store_not_connected", str(exc)) from exc
    try:
        res = await client.request(
            "POST",
            "/storefront/content",
            {"sections": wc, "banners": [b for b in banners if b.get("active", True)]},
            f"home-content:{sf.id}:{datetime.now().strftime('%Y%m%d%H%M%S%f')}",
        )
    except ConnectorError as exc:
        raise AppError(502, "store_error", f"The store did not accept it: {exc}") from exc
    finally:
        await client.aclose()

    row = await db.get(AppSetting, _key(sf))
    value = {"sections": sections, "banners": banners, "pushed_at": datetime.now().astimezone().isoformat()}
    if row is None:
        db.add(AppSetting(key=_key(sf), value=value, updated_by=actor.id))
    else:
        row.value = value
        row.updated_by = actor.id
    await record(
        db,
        "storefront.home_content",
        actor=actor,
        summary=f"Home content on {sf.name}: "
        + ", ".join(f"{SECTIONS[k]} {len(v)}" for k, v in sections.items() if v)
        + f"; {len(banners)} banners",
        request=request,
    )
    await db.commit()
    return {"ok": True, "store": res}
