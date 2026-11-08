"""Customer orders from storefronts, supplier purchases and delivered keys.

Digital keys are stored encrypted (Fernet) in delivered_keys.key_enc and are never written to logs,
events or API responses of OMGGamer OS. They are sent only to the storefront order over a signed request.
"""

import uuid
from datetime import datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import (
    CheckConstraint,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, Timestamps, UUIDPk

ORDER_STATUSES = (
    "received",
    "processing",
    "awaiting_keys",
    "fulfilled",
    "partially_fulfilled",
    "needs_attention",
    "cancelled",
    "refunded",
)
ITEM_STATUSES = ("pending", "purchasing", "purchased", "delivered", "failed", "cancelled", "refunded")
SUPPLIER_ORDER_STATUSES = ("pending", "processing", "completed", "canceled", "refunded", "failed", "unknown")


def _in(col: str, values: tuple[str, ...]) -> str:
    return f"{col} IN ({', '.join(repr(v) for v in values)})"


class Order(UUIDPk, Timestamps, Base):
    __tablename__ = "orders"
    __table_args__ = (
        UniqueConstraint("storefront_id", "external_order_id"),
        CheckConstraint(_in("status", ORDER_STATUSES), name="status_valid"),
    )
    storefront_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("storefronts.id", ondelete="RESTRICT"), nullable=False
    )
    external_order_id: Mapped[str] = mapped_column(String(80), nullable=False)  # WooCommerce order ID
    order_number: Mapped[str | None] = mapped_column(String(80))
    status: Mapped[str] = mapped_column(String(30), nullable=False, default="received", index=True)
    store_status: Mapped[str | None] = mapped_column(String(30))  # status reported by WooCommerce
    currency: Mapped[str] = mapped_column(String(3), nullable=False, default="EUR")
    total: Mapped[Decimal] = mapped_column(Numeric(12, 2), nullable=False, default=Decimal("0"))
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    last_error: Mapped[str | None] = mapped_column(Text)
    placed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    fulfilled_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))


class OrderItem(UUIDPk, Timestamps, Base):
    __tablename__ = "order_items"
    __table_args__ = (
        UniqueConstraint("order_id", "external_item_id"),
        CheckConstraint(_in("status", ITEM_STATUSES), name="status_valid"),
        CheckConstraint("quantity > 0", name="quantity_positive"),
    )
    order_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("orders.id", ondelete="CASCADE"), nullable=False, index=True
    )
    external_item_id: Mapped[str] = mapped_column(String(80), nullable=False)
    name: Mapped[str | None] = mapped_column(String(300))
    product_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("products.id", ondelete="SET NULL"))
    variant_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("product_variants.id", ondelete="SET NULL"), index=True
    )
    offer_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("supplier_offers.id", ondelete="SET NULL"))
    supplier_order_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("supplier_orders.id", ondelete="SET NULL")
    )
    quantity: Mapped[int] = mapped_column(Integer, nullable=False, default=1)
    unit_price: Mapped[Decimal] = mapped_column(
        Numeric(12, 2), nullable=False, default=Decimal("0")
    )  # sold for
    supplier_cost: Mapped[Decimal | None] = mapped_column(Numeric(12, 4))
    supplier_currency: Mapped[str | None] = mapped_column(String(3))
    keys_delivered: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="pending")
    last_error: Mapped[str | None] = mapped_column(Text)


class SupplierOrder(UUIDPk, Timestamps, Base):
    """One purchase at a supplier. `reference` is sent as orderExternalId and makes retries safe."""

    __tablename__ = "supplier_orders"
    __table_args__ = (CheckConstraint(_in("status", SUPPLIER_ORDER_STATUSES), name="status_valid"),)
    order_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("orders.id", ondelete="CASCADE"), nullable=False, index=True
    )
    supplier_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("suppliers.id", ondelete="RESTRICT"), nullable=False
    )
    reference: Mapped[str] = mapped_column(String(80), nullable=False, unique=True)
    external_order_id: Mapped[str | None] = mapped_column(String(80), index=True)  # Kinguin orderId
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="pending")
    request: Mapped[dict[str, Any]] = mapped_column(JSONB, nullable=False, default=dict, server_default="{}")
    response: Mapped[dict[str, Any]] = mapped_column(JSONB, nullable=False, default=dict, server_default="{}")
    total_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 4))
    payment_price: Mapped[Decimal | None] = mapped_column(Numeric(12, 4))
    keys_returned_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    last_error: Mapped[str | None] = mapped_column(Text)


class DeliveredKey(UUIDPk, Base):
    __tablename__ = "delivered_keys"
    __table_args__ = (UniqueConstraint("supplier_order_id", "supplier_key_id"),)
    supplier_order_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("supplier_orders.id", ondelete="CASCADE"), nullable=False, index=True
    )
    order_item_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("order_items.id", ondelete="SET NULL"), index=True
    )
    supplier_key_id: Mapped[str] = mapped_column(String(80), nullable=False)
    content_type: Mapped[str] = mapped_column(String(40), nullable=False, default="text/plain")
    key_enc: Mapped[str] = mapped_column(Text, nullable=False)
    sent_to_storefront_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )


class OrderEvent(Base):
    __tablename__ = "order_events"
    __table_args__ = (Index("ix_order_events_order_created", "order_id", "id"),)
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    order_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("orders.id", ondelete="CASCADE"), nullable=False)
    event: Mapped[str] = mapped_column(String(60), nullable=False)
    message: Mapped[str] = mapped_column(Text, nullable=False, default="")
    data: Mapped[dict[str, Any]] = mapped_column(JSONB, nullable=False, default=dict, server_default="{}")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
