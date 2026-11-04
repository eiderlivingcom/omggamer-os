"""Suppliers, supplier products and offers, master products, variants and sales channels.

Layers are kept separate on purpose:
supplier_products (raw + normalised supplier data) -> product_variants (what we sell) -> products (master),
and AI or manual content never overwrites supplier data (content versions arrive in Phase 3/4).
"""

import uuid
from datetime import datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import (
    Boolean,
    CheckConstraint,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, Timestamps, UUIDPk

PRODUCT_STATUSES = ("draft", "review", "approved", "published", "archived")
PRODUCT_KINDS = ("digital", "hardware", "accessory")


class Supplier(UUIDPk, Timestamps, Base):
    __tablename__ = "suppliers"
    code: Mapped[str] = mapped_column(String(40), unique=True, nullable=False)
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    enabled: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)


class SupplierConnection(UUIDPk, Timestamps, Base):
    """Credentials are stored encrypted (Fernet) and are never returned by the API."""

    __tablename__ = "supplier_connections"
    __table_args__ = (UniqueConstraint("supplier_id", "environment"),)
    supplier_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("suppliers.id", ondelete="CASCADE"), nullable=False
    )
    environment: Mapped[str] = mapped_column(String(20), nullable=False, default="live")  # live | sandbox
    credentials_enc: Mapped[str | None] = mapped_column(Text)
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="not_configured")
    last_checked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    last_error: Mapped[str | None] = mapped_column(Text)


class SupplierProduct(UUIDPk, Timestamps, Base):
    __tablename__ = "supplier_products"
    __table_args__ = (
        UniqueConstraint("supplier_id", "external_id"),
        Index("ix_supplier_products_normalized", "normalized", postgresql_using="gin"),
        Index(
            "ix_supplier_products_name_trgm",
            "name",
            postgresql_using="gin",
            postgresql_ops={"name": "gin_trgm_ops"},
        ),
    )
    supplier_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("suppliers.id", ondelete="RESTRICT"), nullable=False
    )
    external_id: Mapped[str] = mapped_column(String(80), nullable=False)  # Kinguin: kinguinId
    external_ref: Mapped[str | None] = mapped_column(String(80))  # Kinguin: productId hash
    name: Mapped[str] = mapped_column(String(400), nullable=False)
    normalized: Mapped[dict[str, Any]] = mapped_column(
        JSONB, nullable=False, default=dict, server_default="{}"
    )
    raw_object_key: Mapped[str | None] = mapped_column(String(400))
    checksum: Mapped[str | None] = mapped_column(String(64))
    source_updated_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    variant_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("product_variants.id", ondelete="SET NULL"), index=True
    )
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="active", index=True)


class SupplierOffer(UUIDPk, Timestamps, Base):
    __tablename__ = "supplier_offers"
    __table_args__ = (UniqueConstraint("supplier_product_id", "external_offer_id"),)
    supplier_product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("supplier_products.id", ondelete="CASCADE"), nullable=False, index=True
    )
    external_offer_id: Mapped[str] = mapped_column(String(80), nullable=False)
    cost: Mapped[Decimal] = mapped_column(Numeric(12, 4), nullable=False)
    currency: Mapped[str] = mapped_column(String(3), nullable=False)
    stock: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    is_preorder: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)


class Product(UUIDPk, Timestamps, Base):
    __tablename__ = "products"
    __table_args__ = (
        CheckConstraint(f"status IN {PRODUCT_STATUSES}", name="status_valid"),
        CheckConstraint(f"kind IN {PRODUCT_KINDS}", name="kind_valid"),
        Index(
            "ix_products_title_trgm",
            "title",
            postgresql_using="gin",
            postgresql_ops={"title": "gin_trgm_ops"},
        ),
    )
    slug: Mapped[str] = mapped_column(String(200), unique=True, nullable=False)
    title: Mapped[str] = mapped_column(String(300), nullable=False)
    kind: Mapped[str] = mapped_column(String(20), nullable=False, default="digital")
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="draft", index=True)


class ProductVariant(UUIDPk, Timestamps, Base):
    __tablename__ = "product_variants"
    product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("products.id", ondelete="CASCADE"), nullable=False, index=True
    )
    sku: Mapped[str] = mapped_column(String(120), unique=True, nullable=False)
    platform: Mapped[str | None] = mapped_column(String(80))
    region: Mapped[str | None] = mapped_column(String(80))
    edition: Mapped[str | None] = mapped_column(String(120))


class Channel(UUIDPk, Timestamps, Base):
    """A storefront we publish to (WooCommerce on omggamer.com, or staging)."""

    __tablename__ = "channels"
    code: Mapped[str] = mapped_column(String(40), unique=True, nullable=False)
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    base_url: Mapped[str] = mapped_column(String(300), nullable=False)
    credentials_enc: Mapped[str | None] = mapped_column(Text)
    enabled: Mapped[bool] = mapped_column(Boolean, nullable=False, default=False)


class ChannelListing(UUIDPk, Timestamps, Base):
    __tablename__ = "channel_listings"
    __table_args__ = (
        UniqueConstraint("channel_id", "product_id"),
        UniqueConstraint("channel_id", "external_id"),
    )
    channel_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("channels.id", ondelete="CASCADE"), nullable=False
    )
    product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("products.id", ondelete="CASCADE"), nullable=False, index=True
    )
    external_id: Mapped[int | None] = mapped_column(Integer)  # WooCommerce product ID
    sync_status: Mapped[str] = mapped_column(String(20), nullable=False, default="pending", index=True)
    last_hash: Mapped[str | None] = mapped_column(String(64))
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    last_error: Mapped[str | None] = mapped_column(Text)
