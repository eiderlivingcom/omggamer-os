"""Price, stock and offer synchronisation. AI content is never touched here.

* refresh_product: re-read one supplier product (webhook or availability check) and update its offers.
* push_prices: recalculate selling prices for variants whose offers changed and send only the prices and
  stock states that differ from what the storefront shows.
* check_availability: before checkout, confirm each variant still has a purchasable offer (live check).
"""

import uuid
from collections.abc import Sequence
from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import or_, select, update
from sqlalchemy.ext.asyncio import AsyncSession

from app.catalogue.services import CatalogueService
from app.core.logging import log
from app.db.models import (
    AppSetting,
    ProductVariant,
    Storefront,
    StorefrontProductMapping,
    Supplier,
    SupplierOffer,
    SupplierProduct,
    SyncLog,
)
from app.db.models.pricing import VariantPrice
from app.pricing.engine import PricingEngine, Quote, storefront_currency
from app.publishing.connector import ConnectorClient
from app.suppliers import registry
from app.suppliers.base import SupplierNotFound

PUSH_BATCH = 200
DEFAULTS: dict[str, Any] = {
    "enabled": False,
    "interval_minutes": 15,  # 5, 15, 30, 60 ... or a custom cron in `cron`
    "cron": None,
    "full_check_hour": 4,  # daily full price/stock comparison at this UTC hour
    "strict_checkout": False,
}


def now() -> datetime:
    return datetime.now(UTC)


async def get_settings(db: AsyncSession) -> dict[str, Any]:
    row = await db.get(AppSetting, "sync")
    return {**DEFAULTS, **(row.value if row else {})}


async def save_settings(db: AsyncSession, changes: dict[str, Any], user_id: Any = None) -> dict[str, Any]:
    row = await db.get(AppSetting, "sync")
    if row is None:
        row = AppSetting(key="sync", value={})
        db.add(row)
    row.value = {**(row.value or {}), **changes}
    row.updated_by = user_id
    await db.flush()
    return {**DEFAULTS, **row.value}


# ------------------------------------------------------------------ supplier refresh


async def refresh_product(db: AsyncSession, supplier: Supplier, external_id: str) -> uuid.UUID | None:
    """Fetch one product from the supplier and update its offers. Unavailable products lose their stock."""
    adapter = await registry.adapter_for(db, supplier)
    try:
        try:
            record = await adapter.fetch_one(external_id)
        except SupplierNotFound:
            sp = (
                await db.execute(
                    select(SupplierProduct).where(
                        SupplierProduct.supplier_id == supplier.id,
                        SupplierProduct.external_product_id == external_id,
                    )
                )
            ).scalar_one_or_none()
            if sp is None:
                return None
            await db.execute(
                update(SupplierOffer)
                .where(SupplierOffer.supplier_product_id == sp.id)
                .values(available_quantity=0, availability_status="unavailable", last_synced_at=now())
            )
            return sp.product_id
        result = await CatalogueService(db).ingest(supplier, adapter.normalize(record))
        return result.product_id
    finally:
        await adapter.aclose()


# ------------------------------------------------------------------ price push


async def mark_pushed(db: AsyncSession, sf_id: uuid.UUID, variant_ids: Sequence[uuid.UUID]) -> None:
    """Record that the storefront now shows the stored price/stock for these variants."""
    if not variant_ids:
        return
    await db.execute(
        update(VariantPrice)
        .where(VariantPrice.storefront_id == sf_id, VariantPrice.variant_id.in_(list(variant_ids)))
        .values(
            pushed_price=VariantPrice.selling_price,
            pushed_sale_price=VariantPrice.sale_price,
            pushed_in_stock=VariantPrice.available,
            pushed_at=now(),
        )
    )


async def push_prices(
    db: AsyncSession, sf: Storefront, *, since: datetime | None, full: bool = False
) -> dict[str, int]:
    """Send changed prices and stock for products already published to `sf`."""
    stats = {"checked": 0, "changed": 0, "pushed": 0, "failed": 0}
    if sf.status != "active" or not sf.credentials_enc:
        return stats
    mapped = select(StorefrontProductMapping.product_id).where(
        StorefrontProductMapping.storefront_id == sf.id, StorefrontProductMapping.sync_status == "synced"
    )
    q = select(ProductVariant).where(ProductVariant.product_id.in_(mapped))
    if not full and since is not None:
        changed_offers = select(SupplierOffer.variant_id).where(SupplierOffer.updated_at >= since)
        never = select(VariantPrice.variant_id).where(
            VariantPrice.storefront_id == sf.id, VariantPrice.pushed_at.is_not(None)
        )
        q = q.where(or_(ProductVariant.id.in_(changed_offers), ProductVariant.id.notin_(never)))
    last = None
    engine = PricingEngine(db)
    client = ConnectorClient(sf)
    try:
        while True:
            batch_q = q.order_by(ProductVariant.id).limit(500)
            if last is not None:
                batch_q = batch_q.where(ProductVariant.id > last)
            variants = list((await db.execute(batch_q)).scalars())
            if not variants:
                break
            last = variants[-1].id
            stats["checked"] += len(variants)
            await engine.recalculate(variants, sf, reason="sync")
            rows = list(
                (
                    await db.execute(
                        select(VariantPrice).where(
                            VariantPrice.storefront_id == sf.id,
                            VariantPrice.variant_id.in_([v.id for v in variants]),
                        )
                    )
                ).scalars()
            )
            by_variant = {v.id: v for v in variants}
            todo = [
                r
                for r in rows
                if r.pushed_at is None
                or r.pushed_price != r.selling_price
                or r.pushed_sale_price != r.sale_price
                or r.pushed_in_stock != r.available
            ]
            stats["changed"] += len(todo)
            for i in range(0, len(todo), PUSH_BATCH):
                chunk = todo[i : i + PUSH_BATCH]
                items = [
                    {
                        "external_id": str(by_variant[r.variant_id].product_id),
                        "variant_id": str(r.variant_id),
                        "regular_price": str(r.selling_price) if r.selling_price is not None else None,
                        "sale_price": str(r.sale_price) if r.sale_price is not None else None,
                        "floor_price": str(r.floor_price) if r.floor_price is not None else None,
                        "in_stock": bool(r.available and r.selling_price is not None),
                    }
                    for r in chunk
                ]
                key = f"prices:{sf.id}:{now().strftime('%Y%m%d%H%M')}:{chunk[0].variant_id}:{len(chunk)}"
                try:
                    res = await client.prices(items, key)
                except Exception as exc:  # recorded and retried on the next run
                    stats["failed"] += len(chunk)
                    db.add(
                        SyncLog(
                            storefront_id=sf.id,
                            operation="price",
                            status="failed",
                            response={"error": str(exc)[:2000], "items": len(chunk)},
                        )
                    )
                    await db.commit()
                    continue
                ok = [uuid.UUID(x["variant_id"]) for x in res.get("results", []) if x.get("ok")]
                await mark_pushed(db, sf.id, ok)
                stats["pushed"] += len(ok)
                stats["failed"] += len(chunk) - len(ok)
                db.add(
                    SyncLog(
                        storefront_id=sf.id,
                        operation="price",
                        status="success" if len(ok) == len(chunk) else "failed",
                        response={
                            "items": len(chunk),
                            "ok": len(ok),
                            "errors": [x for x in res.get("results", []) if not x.get("ok")][:20],
                        },
                    )
                )
                await db.commit()
            await db.commit()
    finally:
        await client.aclose()
    log.info("prices_pushed", storefront=sf.code, **stats)
    return stats


# ------------------------------------------------------------------ availability before checkout


async def check_availability(
    db: AsyncSession, sf: Storefront, variant_ids: Sequence[uuid.UUID], *, live: bool = True
) -> list[dict[str, Any]]:
    variants = list(
        (await db.execute(select(ProductVariant).where(ProductVariant.id.in_(list(variant_ids))))).scalars()
    )
    found = {v.id for v in variants}
    out: list[dict[str, Any]] = [
        {
            "variant_id": str(v),
            "available": False,
            "price": None,
            "currency": storefront_currency(sf),
            "reason": "unknown variant",
        }
        for v in variant_ids
        if v not in found
    ]
    if live and variants:
        # Re-read the supplier records behind these variants so stale stock never reaches checkout.
        sps = (
            (
                await db.execute(
                    select(SupplierProduct, Supplier)
                    .join(Supplier, Supplier.id == SupplierProduct.supplier_id)
                    .where(
                        SupplierProduct.variant_id.in_([v.id for v in variants]), Supplier.status == "active"
                    )
                )
            )
            .tuples()
            .all()
        )
        for sp, supplier in sps:
            try:
                await refresh_product(db, supplier, sp.external_product_id)
            except Exception as exc:  # the stored data is used when the supplier cannot be reached
                log.warning("availability_refresh_failed", external_id=sp.external_product_id, error=str(exc))
        await db.flush()
    quotes = await PricingEngine(db).preview(variants, sf)
    stored = {
        vp.variant_id: vp
        for vp in (
            await db.execute(
                select(VariantPrice).where(
                    VariantPrice.storefront_id == sf.id, VariantPrice.variant_id.in_(list(found))
                )
            )
        ).scalars()
    }
    for v in variants:
        q = quotes[v.id]
        shown = stored.get(v.id)
        if isinstance(q, Quote):
            price_changed = (
                shown is not None and shown.pushed_price is not None and shown.pushed_price != q.selling_price
            )
            out.append(
                {
                    "variant_id": str(v.id),
                    "available": True,
                    "price": str(q.selling_price),
                    "currency": q.currency,
                    "price_changed": price_changed,
                    "reason": None,
                }
            )
        else:
            out.append(
                {
                    "variant_id": str(v.id),
                    "available": False,
                    "price": None,
                    "currency": storefront_currency(sf),
                    "reason": q,
                }
            )
    return out


def money(v: Decimal | None) -> str | None:
    return str(v) if v is not None else None
