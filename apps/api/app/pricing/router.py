"""Pricing rules, currencies, price previews, recalculation, price history; attributes and category rules."""

import uuid
from datetime import datetime
from decimal import Decimal
from typing import Any, Literal

from fastapi import APIRouter, Query, Request, Response
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import func, select, text
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.deps import DB, require
from app.core.errors import AppError
from app.db.models import Product, ProductCategory, ProductVariant, Storefront, User
from app.db.models.pricing import CategoryMappingRule, CurrencyRate, PriceHistory, PricingRule, VariantPrice
from app.modules.audit.service import record
from app.pricing import category_rules, currency
from app.pricing.engine import PricingEngine, Quote, storefront_currency

router = APIRouter(tags=["pricing"])


# ------------------------------------------------------------------ schemas


class RuleIn(BaseModel):
    name: str = Field(min_length=1, max_length=120)
    scope: Literal["global", "supplier", "platform", "category", "product"] = "global"
    scope_value: str | None = Field(default=None, max_length=80)
    storefront: str | None = Field(default=None, max_length=40)
    priority: int = 0
    markup_percent: Decimal = Field(default=Decimal("0"), ge=-100, le=1000)
    markup_fixed: Decimal = Field(default=Decimal("0"), ge=-1000, le=10000)
    min_margin_percent: Decimal | None = Field(default=None, ge=0, le=1000)
    min_margin_fixed: Decimal | None = Field(default=None, ge=0, le=10000)
    min_price: Decimal | None = Field(default=None, ge=0, le=100000)
    rounding: Literal["none", "cents_99", "cents_49_99", "whole", "nearest_05"] = "none"
    is_active: bool = True
    # Guaranteed profit after discounts
    mode: Literal["markup", "guaranteed"] = "markup"
    target_profit_percent: Decimal | None = Field(default=None, ge=0, le=1000)
    target_profit_fixed: Decimal | None = Field(default=None, ge=0, le=10000)
    gateway_fee_percent: Decimal | None = Field(default=None, ge=0, lt=100)
    gateway_fee_fixed: Decimal | None = Field(default=None, ge=0, le=1000)
    sale_percent: Decimal | None = Field(default=None, ge=0, le=95)
    coupon_percent: Decimal | None = Field(default=None, ge=0, le=95)
    pro_percent: Decimal | None = Field(default=None, ge=0, le=95)


class RuleOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    name: str
    scope: str
    scope_value: str | None
    storefront_id: uuid.UUID | None
    priority: int
    markup_percent: Decimal
    markup_fixed: Decimal
    min_margin_percent: Decimal | None
    min_margin_fixed: Decimal | None
    min_price: Decimal | None
    rounding: str
    is_active: bool
    mode: str
    target_profit_percent: Decimal | None
    target_profit_fixed: Decimal | None
    gateway_fee_percent: Decimal | None
    gateway_fee_fixed: Decimal | None
    sale_percent: Decimal | None
    coupon_percent: Decimal | None
    pro_percent: Decimal | None
    updated_at: datetime


class RateIn(BaseModel):
    base: str = Field(min_length=3, max_length=3)
    quote: str = Field(min_length=3, max_length=3)
    rate: Decimal = Field(gt=0)


class RateOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    base: str
    quote: str
    rate: Decimal
    source: str
    updated_at: datetime


class VariantPriceOut(BaseModel):
    variant_id: uuid.UUID
    label: str
    storefront: str
    currency: str
    cost: Decimal | None
    cost_currency: str | None
    fx_rate: Decimal | None
    preview_price: Decimal | None
    preview_sale_price: Decimal | None = None
    preview_floor_price: Decimal | None = None
    stored_price: Decimal | None
    rule: str | None
    note: str | None


class RecalcIn(BaseModel):
    product_ids: list[uuid.UUID] | None = Field(default=None, max_length=500)
    storefront: str | None = Field(default=None, max_length=40)


class HistoryOut(BaseModel):
    variant_id: uuid.UUID
    label: str
    storefront: str
    old_price: Decimal | None
    new_price: Decimal | None
    cost: Decimal | None
    cost_currency: str | None
    currency: str
    reason: str
    created_at: datetime


class StorefrontPricingIn(BaseModel):
    currency: str = Field(min_length=3, max_length=3)


class CategoryRuleIn(BaseModel):
    attribute: Literal["genre", "platform", "product_type", "tag"]
    value: str = Field(min_length=1, max_length=120)
    category_id: uuid.UUID


class CategoryRuleOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    attribute: str
    value: str
    category_id: uuid.UUID
    category_name: str | None = None


# ------------------------------------------------------------------ helpers


async def _storefront(db: AsyncSession, code: str | None) -> Storefront:
    from app.catalogue.bulk import default_storefront

    return await default_storefront(db, code)


def _label(v: ProductVariant) -> str:
    return " · ".join(x for x in (v.platform, v.edition or "Standard", v.region) if x)


def _rule_values(body: RuleIn) -> dict[str, Any]:
    data = body.model_dump(exclude={"storefront"})
    if body.scope == "global":
        data["scope_value"] = None
    elif not (body.scope_value or "").strip():
        raise AppError(422, "validation_error", f"Choose the {body.scope} this rule applies to.")
    else:
        data["scope_value"] = body.scope_value.strip()  # type: ignore[union-attr]
    if body.mode == "guaranteed" and body.target_profit_percent is None:
        raise AppError(
            422, "validation_error", "Enter the profit % this rule must keep after every discount."
        )
    return data


# ------------------------------------------------------------------ rules


@router.get("/pricing/rules", response_model=list[RuleOut])
async def list_rules(db: DB, _: User = require("pricing:read")) -> list[PricingRule]:
    q = select(PricingRule).order_by(PricingRule.scope, PricingRule.priority.desc(), PricingRule.name)
    return list((await db.execute(q)).scalars())


@router.post("/pricing/rules", response_model=RuleOut, status_code=201)
async def create_rule(
    body: RuleIn, request: Request, db: DB, actor: User = require("pricing:manage")
) -> PricingRule:
    sf_id = (await _storefront(db, body.storefront)).id if body.storefront else None
    rule = PricingRule(**_rule_values(body), storefront_id=sf_id)
    db.add(rule)
    await db.flush()
    await record(
        db,
        "pricing.rule_created",
        actor=actor,
        entity_type="pricing_rule",
        entity_id=str(rule.id),
        summary=f"Created pricing rule {rule.name}",
        data=body.model_dump(mode="json"),
        request=request,
    )
    await db.commit()
    return rule


@router.put("/pricing/rules/{rule_id}", response_model=RuleOut)
async def update_rule(
    rule_id: uuid.UUID, body: RuleIn, request: Request, db: DB, actor: User = require("pricing:manage")
) -> PricingRule:
    rule = await db.get(PricingRule, rule_id)
    if rule is None:
        raise AppError(404, "not_found", "Pricing rule not found.")
    for k, v in _rule_values(body).items():
        setattr(rule, k, v)
    rule.storefront_id = (await _storefront(db, body.storefront)).id if body.storefront else None
    await record(
        db,
        "pricing.rule_updated",
        actor=actor,
        entity_type="pricing_rule",
        entity_id=str(rule.id),
        summary=f"Changed pricing rule {rule.name}",
        data=body.model_dump(mode="json"),
        request=request,
    )
    await db.commit()
    await db.refresh(rule)
    return rule


@router.delete("/pricing/rules/{rule_id}", status_code=204)
async def delete_rule(
    rule_id: uuid.UUID, request: Request, db: DB, actor: User = require("pricing:manage")
) -> Response:
    rule = await db.get(PricingRule, rule_id)
    if rule is None:
        raise AppError(404, "not_found", "Pricing rule not found.")
    await db.delete(rule)
    await record(
        db,
        "pricing.rule_deleted",
        actor=actor,
        entity_type="pricing_rule",
        entity_id=str(rule_id),
        summary=f"Deleted pricing rule {rule.name}",
        request=request,
    )
    await db.commit()
    return Response(status_code=204)


# ------------------------------------------------------------------ currencies


@router.get("/pricing/currencies", response_model=list[RateOut])
async def rates(db: DB, _: User = require("pricing:read")) -> list[CurrencyRate]:
    return list(
        (await db.execute(select(CurrencyRate).order_by(CurrencyRate.base, CurrencyRate.quote))).scalars()
    )


@router.put("/pricing/currencies", response_model=RateOut)
async def set_rate(
    body: RateIn, request: Request, db: DB, actor: User = require("pricing:manage")
) -> CurrencyRate:
    base, quote_ = body.base.upper(), body.quote.upper()
    if base == quote_:
        raise AppError(422, "validation_error", "Choose two different currencies.")
    row = await db.get(CurrencyRate, (base, quote_))
    if row is None:
        row = CurrencyRate(base=base, quote=quote_, rate=body.rate, source="manual")
        db.add(row)
    row.rate, row.source = body.rate, "manual"
    await record(
        db, "pricing.rate", actor=actor, summary=f"Set {base}→{quote_} to {body.rate}", request=request
    )
    await db.commit()
    await db.refresh(row)
    return row


@router.delete("/pricing/currencies/{base}/{quote_}", status_code=204)
async def delete_rate(base: str, quote_: str, db: DB, _: User = require("pricing:manage")) -> Response:
    row = await db.get(CurrencyRate, (base.upper(), quote_.upper()))
    if row is None:
        raise AppError(404, "not_found", "Rate not found.")
    await db.delete(row)
    await db.commit()
    return Response(status_code=204)


@router.post("/pricing/currencies/refresh", response_model=dict[str, int])
async def refresh_rates(db: DB, _: User = require("pricing:manage")) -> dict[str, int]:
    try:
        result = await currency.refresh_ecb(db)
    except Exception as exc:
        raise AppError(502, "rates_unavailable", f"Could not fetch ECB rates: {exc}") from exc
    await db.commit()
    return result


@router.put("/pricing/storefronts/{code}", response_model=dict[str, str])
async def storefront_pricing(
    code: str, body: StorefrontPricingIn, db: DB, _: User = require("pricing:manage")
) -> dict[str, str]:
    sf = await _storefront(db, code)
    sf.configuration = {**(sf.configuration or {}), "currency": body.currency.upper()}
    await db.commit()
    return {"storefront": sf.code, "currency": storefront_currency(sf)}


# ------------------------------------------------------------------ prices


@router.get("/pricing/products/{product_id}", response_model=list[VariantPriceOut])
async def product_prices(
    product_id: uuid.UUID, db: DB, storefront: str | None = None, _: User = require("pricing:read")
) -> list[VariantPriceOut]:
    if await db.get(Product, product_id) is None:
        raise AppError(404, "not_found", "Product not found.")
    sfs = (
        [await _storefront(db, storefront)]
        if storefront
        else list((await db.execute(select(Storefront))).scalars())
    )
    variants = list(
        (await db.execute(select(ProductVariant).where(ProductVariant.product_id == product_id))).scalars()
    )
    engine = PricingEngine(db)
    rules = {r.id: r.name for r in await engine.rules()}
    out: list[VariantPriceOut] = []
    for sf in sfs:
        preview = await engine.preview(variants, sf)
        stored = {
            vp.variant_id: vp
            for vp in (
                await db.execute(
                    select(VariantPrice).where(
                        VariantPrice.storefront_id == sf.id,
                        VariantPrice.variant_id.in_([v.id for v in variants]),
                    )
                )
            ).scalars()
        }
        for v in variants:
            q = preview[v.id]
            sp = stored.get(v.id)
            out.append(
                VariantPriceOut(
                    variant_id=v.id,
                    label=_label(v),
                    storefront=sf.code,
                    currency=storefront_currency(sf),
                    cost=q.cost if isinstance(q, Quote) else None,
                    cost_currency=q.cost_currency if isinstance(q, Quote) else None,
                    fx_rate=q.fx_rate if isinstance(q, Quote) else None,
                    preview_price=q.selling_price if isinstance(q, Quote) else None,
                    preview_sale_price=q.sale_price if isinstance(q, Quote) else None,
                    preview_floor_price=q.floor_price if isinstance(q, Quote) else None,
                    stored_price=sp.selling_price if sp else None,
                    rule=rules.get(q.rule.id) if isinstance(q, Quote) and q.rule else None,
                    note=None if isinstance(q, Quote) else q,
                )
            )
    return out


@router.post("/pricing/recalculate", response_model=dict[str, Any])
async def recalculate(
    body: RecalcIn, request: Request, db: DB, actor: User = require("pricing:manage")
) -> dict[str, Any]:
    if body.product_ids is None:
        from app.workers.celery_app import celery_app

        celery_app.send_task(
            "pricing.recalculate_all", kwargs={"storefront": body.storefront}, queue="default"
        )
        await record(
            db, "pricing.recalculate_all", actor=actor, summary="Recalculated all prices", request=request
        )
        await db.commit()
        return {"queued": True}
    sfs = (
        [await _storefront(db, body.storefront)]
        if body.storefront
        else list((await db.execute(select(Storefront))).scalars())
    )
    variants = list(
        (
            await db.execute(select(ProductVariant).where(ProductVariant.product_id.in_(body.product_ids)))
        ).scalars()
    )
    totals: dict[str, int] = {}
    for sf in sfs:
        stats = await PricingEngine(db).recalculate(variants, sf)
        for k, v in stats.items():
            totals[k] = totals.get(k, 0) + v
    await db.commit()
    return {"queued": False, **totals}


@router.get("/pricing/history", response_model=list[HistoryOut])
async def history(
    db: DB,
    product_id: uuid.UUID | None = None,
    limit: int = Query(default=100, ge=1, le=500),
    _: User = require("pricing:read"),
) -> list[HistoryOut]:
    q = (
        select(PriceHistory, ProductVariant, Storefront.code)
        .join(ProductVariant, ProductVariant.id == PriceHistory.variant_id)
        .join(Storefront, Storefront.id == PriceHistory.storefront_id)
    )
    if product_id:
        q = q.where(ProductVariant.product_id == product_id)
    rows = await db.execute(q.order_by(PriceHistory.created_at.desc(), PriceHistory.id.desc()).limit(limit))
    return [
        HistoryOut(
            variant_id=v.id,
            label=_label(v),
            storefront=code,
            old_price=h.old_price,
            new_price=h.new_price,
            cost=h.cost,
            cost_currency=h.cost_currency,
            currency=h.currency,
            reason=h.reason,
            created_at=h.created_at,
        )
        for h, v, code in rows.tuples().all()
    ]


# ------------------------------------------------------------------ attributes and category rules

ATTRIBUTE_COLUMNS = {
    "platform": ProductVariant.platform,
    "edition": ProductVariant.edition,
    "region": ProductVariant.region,
    "activation_type": ProductVariant.activation_type,
    "language": ProductVariant.language,
}


@router.get("/catalogue/attributes", response_model=dict[str, list[dict[str, Any]]])
async def attributes(
    db: DB, limit: int = Query(default=100, ge=1, le=1000), _: User = require("catalogue:read")
) -> dict:  # type: ignore[type-arg]
    out: dict[str, list[dict[str, Any]]] = {}
    for name, col in ATTRIBUTE_COLUMNS.items():
        rows = await db.execute(
            select(col, func.count(func.distinct(ProductVariant.product_id)))
            .where(col.is_not(None))
            .group_by(col)
            .order_by(func.count(func.distinct(ProductVariant.product_id)).desc())
            .limit(limit)
        )
        out[name] = [{"value": v, "products": int(n)} for v, n in rows.tuples().all()]
    for name, col in (("developer", Product.developer), ("publisher", Product.publisher)):
        rows = await db.execute(
            select(col, func.count())
            .where(col.is_not(None), Product.product_status != "merged")
            .group_by(col)
            .order_by(func.count().desc())
            .limit(limit)
        )
        out[name] = [{"value": v, "products": int(n)} for v, n in rows.tuples().all()]
    genre_sql = (
        "SELECT g.value, count(DISTINCT sp.product_id) AS n FROM supplier_products sp "
        "CROSS JOIN LATERAL jsonb_array_elements_text("
        "CASE WHEN jsonb_typeof(sp.raw_payload->'genres') = 'array' "
        "THEN sp.raw_payload->'genres' ELSE '[]'::jsonb END) AS g(value) "
        "GROUP BY g.value ORDER BY n DESC LIMIT :limit"
    )
    rows = await db.execute(text(genre_sql), {"limit": limit})
    out["genre"] = [{"value": v, "products": int(n)} for v, n in rows.tuples().all()]
    return out


@router.get("/catalogue/category-rules", response_model=list[CategoryRuleOut])
async def list_category_rules(db: DB, _: User = require("catalogue:read")) -> list[CategoryRuleOut]:
    rows = await db.execute(
        select(CategoryMappingRule, ProductCategory.name)
        .join(ProductCategory, ProductCategory.id == CategoryMappingRule.category_id)
        .order_by(CategoryMappingRule.attribute, CategoryMappingRule.value)
    )
    out = []
    for r, name in rows.tuples().all():
        o = CategoryRuleOut.model_validate(r)
        o.category_name = name
        out.append(o)
    return out


@router.post("/catalogue/category-rules", response_model=CategoryRuleOut, status_code=201)
async def create_category_rule(
    body: CategoryRuleIn, db: DB, _: User = require("catalogue:write")
) -> CategoryRuleOut:
    cat = await db.get(ProductCategory, body.category_id)
    if cat is None:
        raise AppError(422, "unknown_category", "Category not found.")
    exists = await db.scalar(
        select(CategoryMappingRule.id).where(
            CategoryMappingRule.attribute == body.attribute,
            func.lower(CategoryMappingRule.value) == body.value.strip().lower(),
            CategoryMappingRule.category_id == body.category_id,
        )
    )
    if exists:
        raise AppError(409, "duplicate", "This rule already exists.")
    rule = CategoryMappingRule(
        attribute=body.attribute, value=body.value.strip(), category_id=body.category_id
    )
    db.add(rule)
    await db.flush()
    await category_rules.apply_rules(db)
    await db.commit()
    out = CategoryRuleOut.model_validate(rule)
    out.category_name = cat.name
    return out


@router.delete("/catalogue/category-rules/{rule_id}", status_code=204)
async def delete_category_rule(rule_id: uuid.UUID, db: DB, _: User = require("catalogue:write")) -> Response:
    rule = await db.get(CategoryMappingRule, rule_id)
    if rule is None:
        raise AppError(404, "not_found", "Rule not found.")
    await db.delete(rule)  # mappings already made stay; remove them per product if needed
    await db.commit()
    return Response(status_code=204)


@router.post("/catalogue/category-rules/apply", response_model=dict[str, int])
async def apply_category_rules(db: DB, _: User = require("catalogue:write")) -> dict[str, int]:
    added = await category_rules.apply_rules(db)
    await db.commit()
    return {"mappings_added": added}
