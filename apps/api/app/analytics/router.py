"""Analytics computed live from the database. No sample or invented figures: empty data shows as zero."""

from datetime import UTC, datetime, timedelta
from decimal import Decimal
from typing import Any

from fastapi import APIRouter, Query
from sqlalchemy import Date, case, cast, distinct, func, select

from app.core.deps import DB, require
from app.db.models import (
    AiJob,
    ImportJob,
    Order,
    OrderItem,
    Product,
    ProductCategory,
    ProductCategoryMapping,
    ProductContent,
    ProductVariant,
    Supplier,
    SupplierOffer,
    SupplierOrder,
    SupplierProduct,
    SyncLog,
    User,
)
from app.db.models.pricing import VariantPrice

router = APIRouter(prefix="/analytics", tags=["analytics"])


def _n(v: Any) -> int:
    return int(v or 0)


def _pct(part: int, whole: int) -> float:
    return round(100.0 * part / whole, 1) if whole else 0.0


@router.get("/summary", response_model=dict[str, Any])
async def summary(
    db: DB, days: int = Query(default=30, ge=1, le=365), _: User = require("analytics:read")
) -> dict[str, Any]:
    since = datetime.now(UTC) - timedelta(days=days)
    live = Product.product_status != "merged"

    products = _n(await db.scalar(select(func.count()).select_from(Product).where(live)))
    variants = _n(await db.scalar(select(func.count()).select_from(ProductVariant)))
    offers = _n(await db.scalar(select(func.count()).select_from(SupplierOffer)))
    offers_available = _n(
        await db.scalar(
            select(func.count())
            .select_from(SupplierOffer)
            .where(
                SupplierOffer.availability_status.in_(("in_stock", "preorder")),
                SupplierOffer.available_quantity > 0,
            )
        )
    )
    by_status = dict(
        (
            await db.execute(
                select(Product.product_status, func.count()).where(live).group_by(Product.product_status)
            )
        )
        .tuples()
        .all()
    )

    by_supplier = [
        {"supplier": name, "products": _n(p), "records": _n(r)}
        for name, p, r in (
            await db.execute(
                select(
                    Supplier.name,
                    func.count(distinct(SupplierProduct.product_id)),
                    func.count(SupplierProduct.id),
                )
                .outerjoin(SupplierProduct, SupplierProduct.supplier_id == Supplier.id)
                .group_by(Supplier.name)
                .order_by(Supplier.name)
            )
        )
        .tuples()
        .all()
    ]
    by_category = [
        {"category": name, "products": _n(n)}
        for name, n in (
            await db.execute(
                select(ProductCategory.name, func.count(ProductCategoryMapping.product_id))
                .join(ProductCategoryMapping, ProductCategoryMapping.category_id == ProductCategory.id)
                .group_by(ProductCategory.name)
                .order_by(func.count(ProductCategoryMapping.product_id).desc())
                .limit(20)
            )
        )
        .tuples()
        .all()
    ]

    content = dict(
        (
            await db.execute(
                select(ProductContent.content_status, func.count(distinct(ProductContent.product_id)))
                .where(ProductContent.is_current.is_(True))
                .group_by(ProductContent.content_status)
            )
        )
        .tuples()
        .all()
    )
    with_content = sum(_n(v) for v in content.values())
    ai_cost = await db.scalar(
        select(func.coalesce(func.sum(AiJob.estimated_cost), 0)).where(AiJob.created_at >= since)
    )
    ai_jobs = dict(
        (
            await db.execute(
                select(AiJob.status, func.count()).where(AiJob.created_at >= since).group_by(AiJob.status)
            )
        )
        .tuples()
        .all()
    )

    sync = dict(
        (
            await db.execute(
                select(SyncLog.status, func.count())
                .where(
                    SyncLog.created_at >= since,
                    SyncLog.operation.in_(("create", "update", "upsert", "unpublish")),
                )
                .group_by(SyncLog.status)
            )
        )
        .tuples()
        .all()
    )
    sync_ok, sync_failed = _n(sync.get("success")), _n(sync.get("failed"))
    price_failures = _n(
        await db.scalar(
            select(func.count())
            .select_from(SyncLog)
            .where(SyncLog.created_at >= since, SyncLog.operation == "price", SyncLog.status == "failed")
        )
    )
    priced = dict(
        (await db.execute(select(VariantPrice.available, func.count()).group_by(VariantPrice.available)))
        .tuples()
        .all()
    )

    imports = [
        {
            "supplier": name,
            "jobs": _n(total),
            "completed": _n(done),
            "failed": _n(failed),
            "records_failed": _n(rec_failed),
            "api_requests": _n(reqs),
            "last_import": last.isoformat() if last else None,
        }
        for name, total, done, failed, rec_failed, reqs, last in (
            await db.execute(
                select(
                    Supplier.name,
                    func.count(ImportJob.id),
                    func.count(case((ImportJob.status == "completed", 1))),
                    func.count(case((ImportJob.status == "failed", 1))),
                    func.coalesce(func.sum(ImportJob.failed_records), 0),
                    func.coalesce(func.sum(ImportJob.api_requests), 0),
                    func.max(ImportJob.completed_at),
                )
                .outerjoin(
                    ImportJob, (ImportJob.supplier_id == Supplier.id) & (ImportJob.created_at >= since)
                )
                .group_by(Supplier.name)
            )
        )
        .tuples()
        .all()
    ]
    purchases = dict(
        (
            await db.execute(
                select(SupplierOrder.status, func.count())
                .where(SupplierOrder.created_at >= since)
                .group_by(SupplierOrder.status)
            )
        )
        .tuples()
        .all()
    )
    bought = _n(purchases.get("processing")) + _n(purchases.get("completed"))

    orders = dict(
        (
            await db.execute(
                select(Order.status, func.count()).where(Order.created_at >= since).group_by(Order.status)
            )
        )
        .tuples()
        .all()
    )
    revenue = Decimal(
        await db.scalar(
            select(func.coalesce(func.sum(Order.total), 0)).where(
                Order.created_at >= since, Order.status == "fulfilled"
            )
        )
        or 0
    )
    cost = Decimal(
        await db.scalar(
            select(func.coalesce(func.sum(SupplierOrder.payment_price), 0)).where(
                SupplierOrder.created_at >= since, SupplierOrder.status.in_(("processing", "completed"))
            )
        )
        or 0
    )
    avg_fulfil = await db.scalar(
        select(func.avg(func.extract("epoch", Order.fulfilled_at - Order.created_at))).where(
            Order.created_at >= since, Order.fulfilled_at.is_not(None)
        )
    )
    units = _n(
        await db.scalar(
            select(func.coalesce(func.sum(OrderItem.quantity), 0))
            .join(Order, Order.id == OrderItem.order_id)
            .where(Order.created_at >= since, OrderItem.status == "delivered")
        )
    )
    day = cast(Order.created_at, Date)
    daily = [
        {"date": d.isoformat(), "orders": _n(n), "revenue": str(r or 0)}
        for d, n, r in (
            await db.execute(
                select(
                    day,
                    func.count(),
                    func.coalesce(func.sum(case((Order.status == "fulfilled", Order.total))), 0),
                )
                .where(Order.created_at >= since)
                .group_by(day)
                .order_by(day)
            )
        )
        .tuples()
        .all()
    ]
    order_total = sum(_n(v) for v in orders.values())
    return {
        "days": days,
        "catalogue": {
            "products": products,
            "variants": variants,
            "offers": offers,
            "offers_available": offers_available,
            "by_status": {k: _n(v) for k, v in by_status.items()},
            "by_supplier": by_supplier,
            "by_category": by_category,
        },
        "ai": {
            "products_with_content": with_content,
            "completion_pct": _pct(with_content, products),
            "by_status": {k: _n(v) for k, v in content.items()},
            "approved_pct": _pct(_n(content.get("approved")), products),
            "jobs": {k: _n(v) for k, v in ai_jobs.items()},
            "cost_usd": str(ai_cost or 0),
        },
        "publishing": {
            "sync_success": sync_ok,
            "sync_failed": sync_failed,
            "success_rate_pct": _pct(sync_ok, sync_ok + sync_failed),
            "price_push_failures": price_failures,
        },
        "stock": {
            "variants_priced": _n(priced.get(True)),
            "variants_unavailable": _n(priced.get(False)),
            "availability_pct": _pct(_n(priced.get(True)), _n(priced.get(True)) + _n(priced.get(False))),
            "offers_available_pct": _pct(offers_available, offers),
        },
        "suppliers": {
            "imports": imports,
            "purchases": {k: _n(v) for k, v in purchases.items()},
            "purchase_success_pct": _pct(bought, sum(_n(v) for v in purchases.values())),
        },
        "sales": {
            "orders": order_total,
            "by_status": {k: _n(v) for k, v in orders.items()},
            "fulfilled_pct": _pct(_n(orders.get("fulfilled")), order_total),
            "units_delivered": units,
            "revenue": str(revenue),
            "supplier_cost": str(cost),
            "gross_margin": str(revenue - cost),
            "avg_fulfilment_seconds": int(avg_fulfil) if avg_fulfil else None,
            "daily": daily,
        },
    }
