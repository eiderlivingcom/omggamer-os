"""Dashboard numbers. Every figure is a live query; empty tables return zeros, never sample data."""

from datetime import datetime
from decimal import Decimal

from fastapi import APIRouter
from pydantic import BaseModel
from sqlalchemy import func, select
from sqlalchemy.sql import Select

from app.core.deps import DB, require
from app.db.models import (
    AiJob,
    AiUsage,
    AuditLog,
    ChannelListing,
    DeadLetter,
    ImportJob,
    Product,
    ProductVariant,
    PublishJob,
    Supplier,
    SupplierConnection,
    SupplierOffer,
    SupplierProduct,
    User,
)
from app.db.models.catalogue import PRODUCT_STATUSES
from app.modules.audit.router import AuditOut

router = APIRouter(prefix="/dashboard", tags=["dashboard"])


class CatalogueStats(BaseModel):
    products: int
    by_status: dict[str, int]
    variants: int


class SupplierStats(BaseModel):
    suppliers: int
    connected: int
    supplier_products: int
    active_offers: int


class ImportStats(BaseModel):
    running: int
    queued: int
    failed: int
    last_finished_at: datetime | None


class AiStats(BaseModel):
    queued: int
    running: int
    failed: int
    succeeded: int
    cost_usd_30d: Decimal


class PublishingStats(BaseModel):
    published_listings: int
    pending_listings: int
    sync_failures: int
    publish_queue: int


class Summary(BaseModel):
    catalogue: CatalogueStats
    suppliers: SupplierStats
    imports: ImportStats
    ai: AiStats
    publishing: PublishingStats
    dead_letters_open: int
    users_active: int


async def _count(db: DB, q: Select[tuple[int]]) -> int:
    return int(await db.scalar(q) or 0)


async def _by_state(db: DB, col: object, model: object) -> dict[str, int]:
    rows = (await db.execute(select(col, func.count()).select_from(model).group_by(col))).tuples().all()  # type: ignore[call-overload]
    return {str(k): int(v) for k, v in rows}


@router.get("/summary", response_model=Summary)
async def summary(db: DB, _: User = require("dashboard:read")) -> Summary:
    by_status = {s: 0 for s in PRODUCT_STATUSES}
    by_status.update(await _by_state(db, Product.status, Product))
    imports = await _by_state(db, ImportJob.state, ImportJob)
    ai = await _by_state(db, AiJob.state, AiJob)
    listings = await _by_state(db, ChannelListing.sync_status, ChannelListing)
    cost = await db.scalar(
        select(func.coalesce(func.sum(AiUsage.cost_usd), 0)).where(
            AiUsage.created_at > func.now() - func.make_interval(0, 0, 0, 30)
        )
    )
    return Summary(
        catalogue=CatalogueStats(
            products=sum(by_status.values()),
            by_status=by_status,
            variants=await _count(db, select(func.count()).select_from(ProductVariant)),
        ),
        suppliers=SupplierStats(
            suppliers=await _count(db, select(func.count()).select_from(Supplier)),
            connected=await _count(
                db,
                select(func.count(func.distinct(SupplierConnection.supplier_id))).where(
                    SupplierConnection.status == "connected"
                ),
            ),
            supplier_products=await _count(db, select(func.count()).select_from(SupplierProduct)),
            active_offers=await _count(
                db, select(func.count()).select_from(SupplierOffer).where(SupplierOffer.is_active.is_(True))
            ),
        ),
        imports=ImportStats(
            running=imports.get("running", 0),
            queued=imports.get("queued", 0),
            failed=imports.get("failed", 0),
            last_finished_at=await db.scalar(select(func.max(ImportJob.finished_at))),
        ),
        ai=AiStats(
            queued=ai.get("queued", 0),
            running=ai.get("running", 0),
            failed=ai.get("failed", 0),
            succeeded=ai.get("succeeded", 0),
            cost_usd_30d=Decimal(str(cost or 0)),
        ),
        publishing=PublishingStats(
            published_listings=listings.get("synced", 0),
            pending_listings=listings.get("pending", 0),
            sync_failures=listings.get("failed", 0),
            publish_queue=await _count(
                db,
                select(func.count())
                .select_from(PublishJob)
                .where(PublishJob.state.in_(["queued", "running"])),
            ),
        ),
        dead_letters_open=await _count(
            db, select(func.count()).select_from(DeadLetter).where(DeadLetter.resolved_at.is_(None))
        ),
        users_active=await _count(db, select(func.count()).select_from(User).where(User.is_active.is_(True))),
    )


@router.get("/activity", response_model=list[AuditOut])
async def activity(db: DB, _: User = require("dashboard:read")) -> list[AuditOut]:
    rows = (await db.execute(select(AuditLog).order_by(AuditLog.created_at.desc()).limit(15))).scalars().all()
    return [
        AuditOut(
            id=r.id,
            created_at=r.created_at,
            actor_email=r.actor_email,
            action=r.action,
            entity_type=r.entity_type,
            entity_id=r.entity_id,
            summary=r.summary,
            data=r.data,
            ip=str(r.ip) if r.ip else None,
        )
        for r in rows
    ]
