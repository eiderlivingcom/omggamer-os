"""Media: product images across the catalogue, image checks, cover and visibility."""

import uuid
from datetime import datetime
from typing import Any, Literal

from fastapi import APIRouter, Query, Request
from fastapi.responses import FileResponse
from pydantic import BaseModel
from sqlalchemy import and_, exists, func, or_, select

from app.core.deps import DB, require
from app.core.errors import AppError
from app.db.models import Product, ProductMedia, User
from app.media.service import check_batch
from app.media.staging import staged_file
from app.modules.audit.service import record

router = APIRouter(prefix="/media", tags=["media"])
files_router = APIRouter(tags=["media"])


class MediaRow(BaseModel):
    id: uuid.UUID
    product_id: uuid.UUID
    product_title: str
    product_sku: str | None
    product_status: str
    url: str
    media_type: str
    processing_status: str
    position: int
    content_type: str | None
    width: int | None
    height: int | None
    bytes: int | None
    checked_at: datetime | None
    error: str | None
    hidden: bool


class MediaPage(BaseModel):
    items: list[MediaRow]
    total: int


class MediaPatch(BaseModel):
    make_cover: bool | None = None
    hidden: bool | None = None


class CheckIn(BaseModel):
    ids: list[uuid.UUID] | None = None
    product_id: uuid.UUID | None = None


def _row(m: ProductMedia, p: Product) -> MediaRow:
    return MediaRow(
        id=m.id,
        product_id=p.id,
        product_title=p.canonical_title,
        product_sku=p.sku,
        product_status=p.product_status,
        url=m.storage_url or m.source_url,
        media_type=m.media_type,
        processing_status=m.processing_status,
        position=m.position,
        content_type=m.content_type,
        width=m.width,
        height=m.height,
        bytes=m.bytes,
        checked_at=m.checked_at,
        error=m.error,
        hidden=m.hidden,
    )


@router.get("/overview", response_model=dict[str, Any])
async def overview(db: DB, _: User = require("catalogue:read")) -> dict[str, Any]:
    by_type = dict(
        (
            await db.execute(select(ProductMedia.media_type, func.count()).group_by(ProductMedia.media_type))
        ).all()
    )
    by_status = dict(
        (
            await db.execute(
                select(ProductMedia.processing_status, func.count()).group_by(ProductMedia.processing_status)
            )
        ).all()
    )
    hidden = await db.scalar(select(func.count()).where(ProductMedia.hidden.is_(True)))
    has_cover = exists().where(
        ProductMedia.product_id == Product.id,
        ProductMedia.media_type == "cover",
        ProductMedia.hidden.is_(False),
        ProductMedia.processing_status != "failed",
    )
    no_cover = await db.scalar(select(func.count()).select_from(Product).where(~has_cover))
    live_no_cover = await db.scalar(
        select(func.count()).select_from(Product).where(Product.product_status == "published", ~has_cover)
    )
    live_failed = await db.scalar(
        select(func.count())
        .select_from(ProductMedia)
        .join(Product, Product.id == ProductMedia.product_id)
        .where(Product.product_status == "published", ProductMedia.processing_status == "failed")
    )
    last = await db.scalar(select(func.max(ProductMedia.checked_at)))
    return {
        "total": sum(by_type.values()),
        "by_type": by_type,
        "by_status": by_status,
        "hidden": hidden or 0,
        "products_without_cover": no_cover or 0,
        "live_without_cover": live_no_cover or 0,
        "live_failed": live_failed or 0,
        "last_checked_at": last,
    }


@router.get("", response_model=MediaPage)
async def list_media(
    db: DB,
    q: str | None = Query(default=None, max_length=200),
    media_type: str | None = Query(default=None, max_length=20),
    status: str | None = Query(default=None, max_length=20),
    scope: Literal["all", "live", "approved"] = "all",
    hidden: bool | None = None,
    limit: int = Query(default=48, ge=1, le=200),
    offset: int = Query(default=0, ge=0),
    _: User = require("catalogue:read"),
) -> MediaPage:
    cond = []
    if q:
        like = f"%{q.strip()}%"
        cond.append(or_(Product.canonical_title.ilike(like), Product.sku.ilike(like)))
    if media_type:
        cond.append(ProductMedia.media_type == media_type)
    if status:
        cond.append(ProductMedia.processing_status == status)
    if scope == "live":
        cond.append(Product.product_status == "published")
    elif scope == "approved":
        cond.append(Product.product_status.in_(("approved", "published")))
    if hidden is not None:
        cond.append(ProductMedia.hidden.is_(hidden))
    where = and_(*cond) if cond else None
    base = select(ProductMedia, Product).join(Product, Product.id == ProductMedia.product_id)
    count = (
        select(func.count()).select_from(ProductMedia).join(Product, Product.id == ProductMedia.product_id)
    )
    if where is not None:
        base, count = base.where(where), count.where(where)
    rows = (
        await db.execute(
            base.order_by(
                (Product.product_status == "published").desc(),
                ProductMedia.processing_status == "ready",
                Product.canonical_title,
                ProductMedia.position,
            )
            .limit(limit)
            .offset(offset)
        )
    ).all()
    return MediaPage(items=[_row(m, p) for m, p in rows], total=int(await db.scalar(count) or 0))


@router.get("/missing-covers", response_model=list[dict[str, Any]])
async def missing_covers(
    db: DB, limit: int = Query(default=50, ge=1, le=200), _: User = require("catalogue:read")
) -> list[dict[str, Any]]:
    """Games with no usable cover, live games first (they show a screenshot or a placeholder on the store)."""
    has_cover = exists().where(
        ProductMedia.product_id == Product.id,
        ProductMedia.media_type == "cover",
        ProductMedia.hidden.is_(False),
        ProductMedia.processing_status != "failed",
    )
    shots = (
        select(func.count())
        .where(ProductMedia.product_id == Product.id, ProductMedia.hidden.is_(False))
        .scalar_subquery()
    )
    rows = (
        await db.execute(
            select(Product.id, Product.canonical_title, Product.sku, Product.product_status, shots)
            .where(~has_cover)
            .order_by(
                (Product.product_status == "published").desc(),
                Product.product_status,
                Product.canonical_title,
            )
            .limit(limit)
        )
    ).all()
    return [
        {"product_id": pid, "title": t, "sku": s, "status": st, "other_images": n}
        for pid, t, s, st, n in rows
    ]


@router.patch("/{media_id}", response_model=MediaRow)
async def update_media(
    media_id: uuid.UUID, body: MediaPatch, request: Request, db: DB, actor: User = require("catalogue:write")
) -> MediaRow:
    m = await db.get(ProductMedia, media_id)
    if m is None:
        raise AppError(404, "not_found", "Image not found.")
    p = await db.get(Product, m.product_id)
    assert p is not None
    if body.make_cover:
        # The current cover becomes a screenshot; this image moves to the front as the cover.
        for other in (
            await db.execute(select(ProductMedia).where(ProductMedia.product_id == m.product_id))
        ).scalars():
            if other.id != m.id and other.media_type == "cover":
                other.media_type = "screenshot"
            other.position = 0 if other.id == m.id else other.position + 1
        m.media_type = "cover"
        m.hidden = False
    if body.hidden is not None:
        m.hidden = body.hidden
    await record(
        db,
        "media.update",
        actor=actor,
        summary=f"Image {'set as cover' if body.make_cover else ('hidden' if body.hidden else 'shown')}: "
        f"{p.canonical_title}",
        request=request,
    )
    await db.commit()
    await db.refresh(m)
    return _row(m, p)


@router.post("/check", response_model=dict[str, int])
async def check_now(body: CheckIn, db: DB, _: User = require("catalogue:write")) -> dict[str, int]:
    """Check images now: the given ones, one game's, or the next 60 in line."""
    ids = body.ids
    if body.product_id is not None:
        ids = list(
            (
                await db.execute(select(ProductMedia.id).where(ProductMedia.product_id == body.product_id))
            ).scalars()
        )
    stats = await check_batch(db, limit=len(ids) if ids else 60, ids=ids or None)
    await db.commit()
    return stats


TYPES = {
    "jpg": "image/jpeg",
    "jpeg": "image/jpeg",
    "png": "image/png",
    "webp": "image/webp",
    "gif": "image/gif",
}


@files_router.get("/files/media/{product_id}/{filename}", include_in_schema=False)
async def staged_media(product_id: str, filename: str) -> FileResponse:
    """A game's image under its SEO name, while the game is being published (public: the store fetches it)."""
    path = staged_file(product_id, filename)
    if path is None:
        raise AppError(404, "not_found", "Image not found.")
    return FileResponse(
        path, media_type=TYPES[filename.rsplit(".", 1)[1]], headers={"Cache-Control": "no-store"}
    )
