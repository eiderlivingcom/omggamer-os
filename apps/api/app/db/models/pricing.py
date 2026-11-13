"""Pricing engine: rules, currency rates, calculated selling prices and price history; category mapping rules.

Supplier purchase prices (supplier_offers.supplier_price) are never modified. Selling prices are derived
from them and stored separately per variant and storefront.
"""

import uuid
from datetime import datetime
from decimal import Decimal

from sqlalchemy import (
    Boolean,
    CheckConstraint,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    String,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, Timestamps, UUIDPk

RULE_SCOPES = ("global", "supplier", "platform", "category", "product")
ROUNDING = ("none", "cents_99", "cents_49_99", "whole", "nearest_05")
CATEGORY_RULE_ATTRIBUTES = ("genre", "platform", "product_type", "tag")


def _in(col: str, values: tuple[str, ...]) -> str:
    return f"{col} IN ({', '.join(repr(v) for v in values)})"


class PricingRule(UUIDPk, Timestamps, Base):
    __tablename__ = "pricing_rules"
    __table_args__ = (
        CheckConstraint(_in("scope", RULE_SCOPES), name="scope_valid"),
        CheckConstraint(_in("rounding", ROUNDING), name="rounding_valid"),
        CheckConstraint("markup_percent >= -100", name="markup_percent_range"),
        CheckConstraint("mode IN ('markup', 'guaranteed')", name="mode_valid"),
        Index("ix_pricing_rules_scope", "scope", "scope_value"),
    )
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    scope: Mapped[str] = mapped_column(String(20), nullable=False, default="global")
    # supplier code, platform name, category id or product id (as text), depending on scope
    scope_value: Mapped[str | None] = mapped_column(String(80))
    storefront_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("storefronts.id", ondelete="CASCADE"))
    priority: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    markup_percent: Mapped[Decimal] = mapped_column(Numeric(8, 3), nullable=False, default=Decimal("0"))
    markup_fixed: Mapped[Decimal] = mapped_column(Numeric(12, 4), nullable=False, default=Decimal("0"))
    min_margin_percent: Mapped[Decimal | None] = mapped_column(Numeric(8, 3))
    min_margin_fixed: Mapped[Decimal | None] = mapped_column(Numeric(12, 4))
    min_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 4))
    rounding: Mapped[str] = mapped_column(String(20), nullable=False, default="none")
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    # "markup": price = cost + markup. "guaranteed": the price is worked out backwards so that, after the
    # storewide sale, a coupon and the PRO discount, the sale still covers cost + profit + payment fees.
    mode: Mapped[str] = mapped_column(String(20), nullable=False, default="markup", server_default="markup")
    target_profit_percent: Mapped[Decimal | None] = mapped_column(Numeric(8, 3))
    target_profit_fixed: Mapped[Decimal | None] = mapped_column(Numeric(12, 4))
    gateway_fee_percent: Mapped[Decimal | None] = mapped_column(Numeric(8, 3))
    gateway_fee_fixed: Mapped[Decimal | None] = mapped_column(Numeric(12, 4))
    sale_percent: Mapped[Decimal | None] = mapped_column(Numeric(8, 3))
    coupon_percent: Mapped[Decimal | None] = mapped_column(Numeric(8, 3))
    pro_percent: Mapped[Decimal | None] = mapped_column(Numeric(8, 3))


class CurrencyRate(Base):
    """How many units of `quote` one unit of `base` buys. Manual rates win over fetched ones."""

    __tablename__ = "currency_rates"
    base: Mapped[str] = mapped_column(String(3), primary_key=True)
    quote: Mapped[str] = mapped_column(String(3), primary_key=True)
    rate: Mapped[Decimal] = mapped_column(Numeric(18, 8), nullable=False)
    source: Mapped[str] = mapped_column(String(20), nullable=False, default="manual")  # manual | ecb
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now(), onupdate=func.now()
    )
    __table_args__ = (CheckConstraint("rate > 0", name="rate_positive"),)


class VariantPrice(Base):
    """The current calculated selling price of a variant for a storefront."""

    __tablename__ = "variant_prices"
    __table_args__ = (UniqueConstraint("variant_id", "storefront_id"),)
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    variant_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("product_variants.id", ondelete="CASCADE"), nullable=False, index=True
    )
    storefront_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("storefronts.id", ondelete="CASCADE"), nullable=False
    )
    offer_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("supplier_offers.id", ondelete="SET NULL"))
    cost: Mapped[Decimal | None] = mapped_column(Numeric(12, 4))
    cost_currency: Mapped[str | None] = mapped_column(String(3))
    fx_rate: Mapped[Decimal | None] = mapped_column(Numeric(18, 8))
    selling_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 2))  # None when no offer is available
    # Guaranteed-profit rules: storewide sale price and the lowest price a sale may reach after all discounts.
    sale_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 2))
    floor_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 2))
    currency: Mapped[str] = mapped_column(String(3), nullable=False)
    rule_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("pricing_rules.id", ondelete="SET NULL"))
    available: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)
    computed_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    # What the storefront currently shows, as last confirmed by the connector (price/stock sync).
    pushed_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 2))
    pushed_sale_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 2))
    pushed_in_stock: Mapped[bool | None] = mapped_column(Boolean)
    pushed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))


class PriceHistory(Base):
    __tablename__ = "price_history"
    __table_args__ = (Index("ix_price_history_variant_created", "variant_id", "created_at"),)
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    variant_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("product_variants.id", ondelete="CASCADE"), nullable=False
    )
    storefront_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("storefronts.id", ondelete="CASCADE"), nullable=False
    )
    old_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 2))
    new_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 2))
    cost: Mapped[Decimal | None] = mapped_column(Numeric(12, 4))
    cost_currency: Mapped[str | None] = mapped_column(String(3))
    currency: Mapped[str] = mapped_column(String(3), nullable=False)
    rule_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("pricing_rules.id", ondelete="SET NULL"))
    reason: Mapped[str] = mapped_column(String(40), nullable=False, default="recalculated")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )


class CategoryMappingRule(UUIDPk, Timestamps, Base):
    """Supplier attribute value → category, e.g. genre "RPG" → Games > PC Games > RPG."""

    __tablename__ = "category_mapping_rules"
    __table_args__ = (
        CheckConstraint(_in("attribute", CATEGORY_RULE_ATTRIBUTES), name="attribute_valid"),
        UniqueConstraint("attribute", "value", "category_id"),
    )
    attribute: Mapped[str] = mapped_column(String(20), nullable=False)
    value: Mapped[str] = mapped_column(String(120), nullable=False)  # compared case-insensitively
    category_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("product_categories.id", ondelete="CASCADE"), nullable=False, index=True
    )
