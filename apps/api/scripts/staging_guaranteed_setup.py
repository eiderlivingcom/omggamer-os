"""One-off: guaranteed-profit pricing on the staging storefront (safe to run again).

Creates/updates the "OMG guaranteed profit" rule, switches publishing to staging on, reprices the products that
are (or were being) published there and sends them again.

    docker compose exec -T api python - < apps/api/scripts/staging_guaranteed_setup.py
"""

import asyncio
from decimal import Decimal

from sqlalchemy import select

from app.db.models import AuditLog, ProductVariant, PublishingQueueItem, Storefront, StorefrontProductMapping
from app.db.models.pricing import PricingRule, VariantPrice
from app.db.session import get_sessionmaker
from app.pricing.engine import PricingEngine

RULE = {
    "scope": "global",
    "scope_value": None,
    "storefront_id": None,
    "priority": 100,
    "markup_percent": Decimal("0"),
    "markup_fixed": Decimal("0"),
    "min_price": Decimal("0.99"),
    "rounding": "cents_99",
    "is_active": True,
    "mode": "guaranteed",
    "target_profit_percent": Decimal("15"),
    "target_profit_fixed": Decimal("0.50"),
    "gateway_fee_percent": Decimal("3.5"),
    "gateway_fee_fixed": Decimal("0.30"),
    "sale_percent": Decimal("50"),
    "coupon_percent": Decimal("10"),
    "pro_percent": Decimal("10"),
}


async def main() -> list[str]:
    async with get_sessionmaker()() as db:
        sf = (await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))).scalar_one()
        rule = (
            await db.execute(select(PricingRule).where(PricingRule.name == "OMG guaranteed profit"))
        ).scalar_one_or_none()
        if rule is None:
            rule = PricingRule(name="OMG guaranteed profit")
            db.add(rule)
        for k, v in RULE.items():
            setattr(rule, k, v)
        others = (await db.execute(select(PricingRule).where(PricingRule.is_active))).scalars().all()
        for r in others:
            if r is not rule:
                print(f"other active rule kept: {r.name} ({r.scope}) priority {r.priority} mode {r.mode}")
        sf.status = "active"
        db.add(AuditLog(action="pricing.rule.saved", entity_type="pricing_rule", summary="Guaranteed-profit rule set up"))
        db.add(AuditLog(action="storefront.updated", entity_type="storefront", entity_id=str(sf.id),
                        summary="Publishing to OMGGamer staging switched on"))
        await db.flush()

        items = (
            await db.execute(
                select(PublishingQueueItem).where(
                    PublishingQueueItem.storefront_id == sf.id, PublishingQueueItem.status == "failed"
                )
            )
        ).scalars().all()
        mapped = (
            await db.execute(select(StorefrontProductMapping.product_id).where(StorefrontProductMapping.storefront_id == sf.id))
        ).scalars().all()
        pids = {i.product_id for i in items} | set(mapped)
        variants = (await db.execute(select(ProductVariant).where(ProductVariant.product_id.in_(pids)))).scalars().all()
        stats = await PricingEngine(db).recalculate(variants, sf, reason="guaranteed-profit rule")
        print("recalculated:", stats)
        rows = (
            await db.execute(
                select(VariantPrice).where(
                    VariantPrice.storefront_id == sf.id, VariantPrice.variant_id.in_([v.id for v in variants])
                )
            )
        ).scalars().all()
        for r in rows:
            print(f"  cost {r.cost} {r.cost_currency} -> regular {r.selling_price} sale {r.sale_price} floor {r.floor_price} {r.currency}")
        for i in items:
            i.status, i.attempts, i.last_error = "queued", 0, None
        await db.commit()
        return [str(i.id) for i in items]


ids = asyncio.run(main())
from app.workers.tasks import publish_run  # noqa: E402

for item_id in ids:
    publish_run.apply_async((item_id,), queue="publish")
print("queued for publishing:", len(ids))
