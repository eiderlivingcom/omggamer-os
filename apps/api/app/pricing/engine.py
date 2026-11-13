"""Selling price calculation.

    selling price = converted supplier cost + markup (percent and fixed),
                    then raised to the minimum margin and minimum price, then rounded up.

The most specific active rule wins: product > category > platform > supplier > global. A rule for the
specific storefront beats a rule for all storefronts at the same level; then the higher priority wins.
Supplier purchase prices are read, never written.
"""

import uuid
from collections.abc import Sequence
from dataclasses import dataclass
from datetime import UTC, datetime
from decimal import ROUND_CEILING, ROUND_HALF_UP, Decimal

from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession

from app.db.models import (
    ProductCategoryMapping,
    ProductVariant,
    Storefront,
    Supplier,
    SupplierOffer,
    SupplierProduct,
)
from app.db.models.pricing import CurrencyRate, PriceHistory, PricingRule, VariantPrice

SCOPE_RANK = {"product": 5, "category": 4, "platform": 3, "supplier": 2, "global": 1}
CENT = Decimal("0.01")


class PricingError(Exception):
    pass


def now() -> datetime:
    return datetime.now(UTC)


def storefront_currency(sf: Storefront) -> str:
    return str((sf.configuration or {}).get("currency") or "EUR").upper()


class Rates:
    def __init__(self, rows: Sequence[CurrencyRate]) -> None:
        self.r: dict[tuple[str, str], Decimal] = {(x.base, x.quote): Decimal(x.rate) for x in rows}

    def _direct(self, src: str, dst: str) -> Decimal | None:
        if src == dst:
            return Decimal(1)
        if (src, dst) in self.r:
            return self.r[(src, dst)]
        if (dst, src) in self.r:
            return Decimal(1) / self.r[(dst, src)]
        return None

    def get(self, src: str, dst: str) -> Decimal:
        src, dst = src.upper(), dst.upper()
        direct = self._direct(src, dst)
        if direct is not None:
            return direct
        for pivot in ("EUR", "USD"):
            a, b = self._direct(src, pivot), self._direct(pivot, dst)
            if a is not None and b is not None:
                return a * b
        raise PricingError(f"No exchange rate from {src} to {dst}. Add it under Pricing → Currencies.")


def round_up(price: Decimal, mode: str) -> Decimal:
    if mode == "cents_99":
        return (price + CENT).to_integral_value(ROUND_CEILING) - CENT
    if mode == "cents_49_99":
        whole = (
            price.to_integral_value(ROUND_CEILING) - 1 if price != price.to_integral_value() else price - 1
        )
        for candidate in (whole + Decimal("0.49"), whole + Decimal("0.99"), whole + Decimal("1.49")):
            if candidate >= price:
                return candidate
        return whole + Decimal("1.99")
    if mode == "whole":
        return price.to_integral_value(ROUND_CEILING)
    if mode == "nearest_05":
        return (price / Decimal("0.05")).to_integral_value(ROUND_CEILING) * Decimal("0.05")
    return price.quantize(CENT, rounding=ROUND_HALF_UP)


@dataclass
class Context:
    product_id: uuid.UUID
    platform: str | None
    category_ids: set[str]
    supplier_code: str | None


@dataclass
class Quote:
    selling_price: Decimal
    currency: str
    cost: Decimal
    cost_currency: str
    fx_rate: Decimal
    rule: PricingRule | None
    offer_id: uuid.UUID | None = None
    sale_price: Decimal | None = None  # storewide sale price (guaranteed-profit rules)
    floor_price: Decimal | None = None  # lowest price a sale may reach after every discount


def pick_rule(rules: Sequence[PricingRule], ctx: Context, storefront_id: uuid.UUID) -> PricingRule | None:
    def matches(r: PricingRule) -> bool:
        if not r.is_active or (r.storefront_id is not None and r.storefront_id != storefront_id):
            return False
        v = (r.scope_value or "").strip().lower()
        if r.scope == "global":
            return True
        if r.scope == "product":
            return v == str(ctx.product_id)
        if r.scope == "category":
            return v in ctx.category_ids
        if r.scope == "platform":
            return bool(ctx.platform) and v == (ctx.platform or "").lower()
        if r.scope == "supplier":
            return bool(ctx.supplier_code) and v == (ctx.supplier_code or "").lower()
        return False

    candidates = [r for r in rules if matches(r)]
    if not candidates:
        return None
    return max(candidates, key=lambda r: (SCOPE_RANK[r.scope], r.storefront_id is not None, r.priority))


def _pct(v: Decimal | None) -> Decimal:
    return Decimal(v or 0) / 100


def guaranteed(base: Decimal, rule: PricingRule) -> tuple[Decimal, Decimal, Decimal | None]:
    """Regular price, floor and sale % for a guaranteed-profit rule (before rounding the regular price).

    floor   = (cost x (1 + profit %) + profit amount + fixed payment fee) / (1 - payment fee %)
              i.e. the least a customer may pay so the order still covers cost, profit and payment fees.
    regular = floor / ((1 - sale %) x (1 - coupon %) x (1 - PRO %))
              so that the storewide sale, a coupon and the PRO discount together end exactly at the floor.
    """
    gw = _pct(rule.gateway_fee_percent)
    if gw >= 1:
        raise PricingError("The payment fee percentage must be below 100%.")
    need = base * (1 + _pct(rule.target_profit_percent)) + Decimal(rule.target_profit_fixed or 0)
    need += Decimal(rule.gateway_fee_fixed or 0)
    floor = need / (1 - gw)
    keep = (1 - _pct(rule.sale_percent)) * (1 - _pct(rule.coupon_percent)) * (1 - _pct(rule.pro_percent))
    if keep <= 0:
        raise PricingError("The sale, coupon and PRO discounts add up to 100% or more.")
    regular = floor / keep
    return regular, floor, (_pct(rule.sale_percent) if rule.sale_percent else None)


def quote(cost: Decimal, cost_currency: str, currency: str, rule: PricingRule | None, rates: Rates) -> Quote:
    fx = rates.get(cost_currency, currency)
    base = cost * fx
    sale_pct: Decimal | None = None
    floor_price: Decimal | None = None
    if rule is None:
        price = base
        floor = base
        mode = "none"
    elif rule.mode == "guaranteed":
        price, floor_price, sale_pct = guaranteed(base, rule)
        floor = price  # the regular price itself is the floor for rounding
        if rule.min_price is not None:
            floor = max(floor, Decimal(rule.min_price))
        mode = rule.rounding
    else:
        price = base * (1 + Decimal(rule.markup_percent) / 100) + Decimal(rule.markup_fixed)
        floor = base
        if rule.min_margin_percent is not None:
            floor = max(floor, base * (1 + Decimal(rule.min_margin_percent) / 100))
        if rule.min_margin_fixed is not None:
            floor = max(floor, base + Decimal(rule.min_margin_fixed))
        if rule.min_price is not None:
            floor = max(floor, Decimal(rule.min_price))
        mode = rule.rounding
    price = max(price, floor)
    final = round_up(price, mode)
    if final < floor:  # rounding never takes a price below the floor
        final = round_up(floor, "none") if mode == "none" else round_up(floor + CENT, mode)
    final = final.quantize(CENT)
    q = Quote(final, currency, cost, cost_currency.upper(), fx, rule)
    if floor_price is not None:
        q.floor_price = floor_price.quantize(CENT, rounding=ROUND_CEILING)
        if sale_pct:
            q.sale_price = (final * (1 - sale_pct)).quantize(CENT, rounding=ROUND_CEILING)
    return q


class PricingEngine:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db
        self._rules: list[PricingRule] | None = None
        self._rates: Rates | None = None

    async def rules(self) -> list[PricingRule]:
        if self._rules is None:
            self._rules = list(
                (await self.db.execute(select(PricingRule).where(PricingRule.is_active))).scalars()
            )
        return self._rules

    async def rates(self) -> Rates:
        if self._rates is None:
            self._rates = Rates(list((await self.db.execute(select(CurrencyRate))).scalars()))
        return self._rates

    async def _contexts(self, variants: Sequence[ProductVariant]) -> dict[uuid.UUID, set[str]]:
        pids = list({v.product_id for v in variants})
        rows = await self.db.execute(
            select(ProductCategoryMapping.product_id, ProductCategoryMapping.category_id).where(
                ProductCategoryMapping.product_id.in_(pids)
            )
        )
        cats: dict[uuid.UUID, set[str]] = {p: set() for p in pids}
        for pid, cid in rows.tuples().all():
            cats[pid].add(str(cid))
        return cats

    async def _cheapest(self, variant_ids: Sequence[uuid.UUID]) -> dict[uuid.UUID, tuple[SupplierOffer, str]]:
        rows = await self.db.execute(
            select(SupplierOffer, Supplier.code)
            .join(SupplierProduct, SupplierProduct.id == SupplierOffer.supplier_product_id)
            .join(Supplier, Supplier.id == SupplierProduct.supplier_id)
            .where(
                SupplierOffer.variant_id.in_(variant_ids),
                SupplierOffer.availability_status.in_(("in_stock", "preorder")),
                SupplierOffer.available_quantity > 0,
                Supplier.status == "active",
            )
            .order_by(SupplierOffer.variant_id, SupplierOffer.supplier_price)
        )
        best: dict[uuid.UUID, tuple[SupplierOffer, str]] = {}
        rates = await self.rates()
        for offer, code in rows.tuples().all():
            assert offer.variant_id is not None
            cur = best.get(offer.variant_id)
            if cur is None:
                best[offer.variant_id] = (offer, code)
                continue
            # Compare in a common currency when sellers price in different currencies.
            try:
                if offer.supplier_price * rates.get(offer.currency, "EUR") < cur[
                    0
                ].supplier_price * rates.get(cur[0].currency, "EUR"):
                    best[offer.variant_id] = (offer, code)
            except PricingError:
                continue
        return best

    async def preview(
        self, variants: Sequence[ProductVariant], sf: Storefront
    ) -> dict[uuid.UUID, Quote | str]:
        """Price each variant without saving: a Quote, or the reason it cannot be priced."""
        rules = await self.rules()
        rates = await self.rates()
        cats = await self._contexts(variants)
        cheapest = await self._cheapest([v.id for v in variants])
        currency = storefront_currency(sf)
        out: dict[uuid.UUID, Quote | str] = {}
        for v in variants:
            best = cheapest.get(v.id)
            if best is None:
                out[v.id] = "no available offer"
                continue
            offer, code = best
            ctx = Context(v.product_id, v.platform, cats.get(v.product_id, set()), code)
            rule = pick_rule(rules, ctx, sf.id)
            if rule is None:
                # Never fall back to selling at cost: without a rule the variant stays unpriced.
                out[v.id] = "no pricing rule applies"
                continue
            try:
                q = quote(Decimal(offer.supplier_price), offer.currency, currency, rule, rates)
                q.offer_id = offer.id
                out[v.id] = q
            except PricingError as exc:
                out[v.id] = str(exc)
        return out

    async def recalculate(
        self, variants: Sequence[ProductVariant], sf: Storefront, reason: str = "recalculated"
    ) -> dict[str, int]:
        """Store current selling prices; write price history whenever a price changes."""
        quotes = await self.preview(variants, sf)
        existing = {
            vp.variant_id: vp
            for vp in (
                await self.db.execute(
                    select(VariantPrice).where(
                        VariantPrice.storefront_id == sf.id,
                        VariantPrice.variant_id.in_([v.id for v in variants]),
                    )
                )
            ).scalars()
        }
        stats = {"priced": 0, "changed": 0, "unavailable": 0, "errors": 0}
        currency = storefront_currency(sf)
        for v in variants:
            q = quotes[v.id]
            old = existing.get(v.id)
            old_price = old.selling_price if old else None
            if isinstance(q, Quote):
                values = {
                    "offer_id": q.offer_id,
                    "cost": q.cost,
                    "cost_currency": q.cost_currency,
                    "fx_rate": q.fx_rate,
                    "selling_price": q.selling_price,
                    "sale_price": q.sale_price,
                    "floor_price": q.floor_price,
                    "currency": q.currency,
                    "rule_id": q.rule.id if q.rule else None,
                    "available": True,
                    "computed_at": now(),
                }
                stats["priced"] += 1
            else:
                values = {
                    "offer_id": None,
                    "cost": None,
                    "cost_currency": None,
                    "fx_rate": None,
                    "selling_price": None,
                    "sale_price": None,
                    "floor_price": None,
                    "currency": currency,
                    "rule_id": None,
                    "available": False,
                    "computed_at": now(),
                }
                stats["unavailable" if q == "no available offer" else "errors"] += 1
            stmt = pg_insert(VariantPrice).values(variant_id=v.id, storefront_id=sf.id, **values)
            await self.db.execute(
                stmt.on_conflict_do_update(index_elements=["variant_id", "storefront_id"], set_=values)
            )
            if old_price != values["selling_price"] and (
                old is not None or values["selling_price"] is not None
            ):
                stats["changed"] += 1
                self.db.add(
                    PriceHistory(
                        variant_id=v.id,
                        storefront_id=sf.id,
                        old_price=old_price,
                        new_price=values["selling_price"],
                        cost=values["cost"],
                        cost_currency=values["cost_currency"],
                        currency=values["currency"],
                        rule_id=values["rule_id"],
                        reason=reason if values["selling_price"] is not None else "unavailable",
                    )
                )
        await self.db.flush()
        return stats
