"""Master catalogue.

Three independent concepts, never collapsed into one table:

1. Master product (`products`): the game or item itself, e.g. "Star Wars Outlaws".
2. Variant (`product_variants`): what is sold — platform, edition, region, activation type.
3. Supplier offer (`supplier_offers`): who sells a variant, at what price, with what stock.

`supplier_products` keeps every supplier record exactly as received (title, description, raw payload).
Many supplier products can point at the same master product, and generated or edited text lives in
`product_content`, so original supplier data is never overwritten.
"""

import uuid
from datetime import date, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import (
    CheckConstraint,
    Date,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    PrimaryKeyConstraint,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, Timestamps, UUIDPk

PRODUCT_TYPES = (
    "game",
    "dlc",
    "software",
    "gift_card",
    "game_account",
    "subscription",
    "hardware",
    "accessory",
)
PRODUCT_STATUSES = ("draft", "review", "approved", "published", "unpublished", "archived", "merged")
SUPPLIER_STATUSES = ("active", "disabled")
IMPORT_STATUSES = ("new", "linked", "unchanged", "updated", "failed", "skipped", "removed")
AVAILABILITY = ("in_stock", "out_of_stock", "preorder", "unavailable")
CONTENT_STATUSES = ("draft", "pending_review", "approved", "rejected", "superseded")
MAPPING_SOURCES = ("supplier", "ai", "manual", "rule")
MEDIA_TYPES = ("cover", "screenshot", "video", "banner", "logo")
MEDIA_STATUSES = ("pending", "processing", "ready", "failed")


def _in(col: str, values: tuple[str, ...]) -> str:
    return f"{col} IN ({', '.join(repr(v) for v in values)})"


def trgm(name: str, col: str) -> Index:
    return Index(name, col, postgresql_using="gin", postgresql_ops={col: "gin_trgm_ops"})


class Supplier(UUIDPk, Timestamps, Base):
    __tablename__ = "suppliers"
    __table_args__ = (CheckConstraint(_in("status", SUPPLIER_STATUSES), name="status_valid"),)
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    code: Mapped[str] = mapped_column(String(40), unique=True, nullable=False)
    integration_type: Mapped[str] = mapped_column(
        String(30), nullable=False, default="api"
    )  # api | feed | manual
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="active", index=True)
    configuration: Mapped[dict[str, Any]] = mapped_column(
        JSONB, nullable=False, default=dict, server_default="{}"
    )


class SupplierConnection(UUIDPk, Timestamps, Base):
    """API credentials per supplier and environment. Encrypted (Fernet); never returned by the API."""

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


class Product(UUIDPk, Timestamps, Base):
    """The master product. `master_product_id` points at the surviving product after a merge."""

    __tablename__ = "products"
    __table_args__ = (
        CheckConstraint(_in("product_type", PRODUCT_TYPES), name="product_type_valid"),
        CheckConstraint(_in("product_status", PRODUCT_STATUSES), name="product_status_valid"),
        CheckConstraint("master_product_id IS NULL OR master_product_id <> id", name="not_own_master"),
        trgm("ix_products_canonical_title_trgm", "canonical_title"),
        Index("ix_products_match_key_type", "match_key", "product_type"),
    )
    master_product_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("products.id", ondelete="SET NULL"), index=True
    )
    product_type: Mapped[str] = mapped_column(String(30), nullable=False, default="game", index=True)
    canonical_title: Mapped[str] = mapped_column(String(300), nullable=False)
    slug: Mapped[str] = mapped_column(String(220), unique=True, nullable=False)
    # Normalised title used to group supplier records into one master product (see services.normalize).
    match_key: Mapped[str] = mapped_column(String(300), nullable=False)
    brand: Mapped[str | None] = mapped_column(String(160))
    developer: Mapped[str | None] = mapped_column(String(300))
    publisher: Mapped[str | None] = mapped_column(String(300))
    release_date: Mapped[date | None] = mapped_column(Date)
    product_status: Mapped[str] = mapped_column(String(20), nullable=False, default="draft", index=True)


class ProductVariant(UUIDPk, Timestamps, Base):
    __tablename__ = "product_variants"
    __table_args__ = (UniqueConstraint("product_id", "variant_key"),)
    product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("products.id", ondelete="CASCADE"), nullable=False, index=True
    )
    sku: Mapped[str] = mapped_column(String(160), unique=True, nullable=False)
    platform: Mapped[str | None] = mapped_column(String(80), index=True)
    edition: Mapped[str | None] = mapped_column(String(120))
    region: Mapped[str | None] = mapped_column(String(80), index=True)
    activation_type: Mapped[str | None] = mapped_column(String(40))  # key | account | gift | ...
    language: Mapped[str | None] = mapped_column(String(80))
    variant_attributes: Mapped[dict[str, Any]] = mapped_column(
        JSONB, nullable=False, default=dict, server_default="{}"
    )
    # Lower-cased platform|edition|region|activation|language, unique per product (NULL-safe dedupe).
    variant_key: Mapped[str] = mapped_column(String(400), nullable=False)


class SupplierProduct(UUIDPk, Timestamps, Base):
    __tablename__ = "supplier_products"
    __table_args__ = (
        UniqueConstraint("supplier_id", "external_product_id"),
        CheckConstraint(_in("import_status", IMPORT_STATUSES), name="import_status_valid"),
        trgm("ix_supplier_products_original_title_trgm", "original_title"),
    )
    supplier_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("suppliers.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    external_product_id: Mapped[str] = mapped_column(String(80), nullable=False, index=True)
    external_product_set_id: Mapped[str | None] = mapped_column(String(80), index=True)
    product_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("products.id", ondelete="SET NULL"), index=True
    )
    variant_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("product_variants.id", ondelete="SET NULL"), index=True
    )
    original_title: Mapped[str] = mapped_column(String(500), nullable=False)
    original_description: Mapped[str | None] = mapped_column(Text)
    raw_payload: Mapped[dict[str, Any]] = mapped_column(
        JSONB, nullable=False, default=dict, server_default="{}"
    )
    payload_checksum: Mapped[str | None] = mapped_column(String(64))
    last_fetched_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    import_status: Mapped[str] = mapped_column(String(20), nullable=False, default="new", index=True)


class SupplierOffer(UUIDPk, Timestamps, Base):
    __tablename__ = "supplier_offers"
    __table_args__ = (
        UniqueConstraint("supplier_product_id", "external_offer_id"),
        CheckConstraint(_in("availability_status", AVAILABILITY), name="availability_valid"),
        CheckConstraint("supplier_price >= 0", name="price_not_negative"),
        CheckConstraint("available_quantity >= 0", name="quantity_not_negative"),
    )
    supplier_product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("supplier_products.id", ondelete="CASCADE"), nullable=False, index=True
    )
    variant_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("product_variants.id", ondelete="SET NULL"), index=True
    )
    external_offer_id: Mapped[str] = mapped_column(String(80), nullable=False)
    seller_id: Mapped[str | None] = mapped_column(String(80))
    region: Mapped[str | None] = mapped_column(String(80), index=True)
    currency: Mapped[str] = mapped_column(String(3), nullable=False)
    supplier_price: Mapped[Decimal] = mapped_column(Numeric(12, 4), nullable=False)
    available_quantity: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    availability_status: Mapped[str] = mapped_column(
        String(20), nullable=False, default="in_stock", index=True
    )
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))


class ProductContent(UUIDPk, Timestamps, Base):
    """Generated or edited copy, one version series per locale. Supplier text stays in supplier_products."""

    __tablename__ = "product_content"
    __table_args__ = (
        UniqueConstraint("product_id", "locale", "version"),
        CheckConstraint(_in("content_status", CONTENT_STATUSES), name="content_status_valid"),
        Index(
            "uq_product_content_one_current",
            "product_id",
            "locale",
            unique=True,
            postgresql_where="is_current",
        ),
    )
    product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("products.id", ondelete="CASCADE"), nullable=False, index=True
    )
    locale: Mapped[str] = mapped_column(String(10), nullable=False, default="en")
    version: Mapped[int] = mapped_column(Integer, nullable=False, default=1)
    is_current: Mapped[bool] = mapped_column(nullable=False, default=True)
    origin: Mapped[str] = mapped_column(String(20), nullable=False, default="ai")  # ai | manual
    title: Mapped[str | None] = mapped_column(String(300))
    short_description: Mapped[str | None] = mapped_column(Text)
    long_description: Mapped[str | None] = mapped_column(Text)
    meta_title: Mapped[str | None] = mapped_column(String(300))
    meta_description: Mapped[str | None] = mapped_column(String(500))
    image_alt_text: Mapped[dict[str, Any]] = mapped_column(
        JSONB, nullable=False, default=dict, server_default="{}"
    )
    tags: Mapped[list[str]] = mapped_column(JSONB, nullable=False, default=list, server_default="[]")
    content_status: Mapped[str] = mapped_column(String(20), nullable=False, default="draft", index=True)
    model: Mapped[str | None] = mapped_column(String(80))
    prompt_version: Mapped[str | None] = mapped_column(String(40))
    generated_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))


class ProductCategory(UUIDPk, Timestamps, Base):
    __tablename__ = "product_categories"
    __table_args__ = (CheckConstraint("parent_id IS NULL OR parent_id <> id", name="not_own_parent"),)
    name: Mapped[str] = mapped_column(String(160), nullable=False)
    slug: Mapped[str] = mapped_column(String(200), unique=True, nullable=False)
    parent_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("product_categories.id", ondelete="RESTRICT"), index=True
    )
    position: Mapped[int] = mapped_column(Integer, nullable=False, default=0)


class ProductCategoryMapping(Base):
    __tablename__ = "product_category_mappings"
    __table_args__ = (
        PrimaryKeyConstraint("product_id", "category_id"),
        CheckConstraint(_in("source", MAPPING_SOURCES), name="source_valid"),
        CheckConstraint(
            "confidence IS NULL OR (confidence >= 0 AND confidence <= 1)", name="confidence_range"
        ),
        Index("ix_product_category_mappings_category_id", "category_id"),
    )
    product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("products.id", ondelete="CASCADE"), nullable=False
    )
    category_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("product_categories.id", ondelete="CASCADE"), nullable=False
    )
    source: Mapped[str] = mapped_column(String(20), nullable=False, default="manual")
    confidence: Mapped[Decimal | None] = mapped_column(Numeric(4, 3))
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )


class ProductMedia(UUIDPk, Timestamps, Base):
    __tablename__ = "product_media"
    __table_args__ = (
        UniqueConstraint("product_id", "source_url"),
        CheckConstraint(_in("media_type", MEDIA_TYPES), name="media_type_valid"),
        CheckConstraint(_in("processing_status", MEDIA_STATUSES), name="processing_status_valid"),
    )
    product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("products.id", ondelete="CASCADE"), nullable=False, index=True
    )
    source_url: Mapped[str] = mapped_column(String(1000), nullable=False)
    storage_url: Mapped[str | None] = mapped_column(String(1000))
    media_type: Mapped[str] = mapped_column(String(20), nullable=False, default="screenshot")
    processing_status: Mapped[str] = mapped_column(String(20), nullable=False, default="pending", index=True)
    position: Mapped[int] = mapped_column(Integer, nullable=False, default=0)


class Storefront(UUIDPk, Timestamps, Base):
    __tablename__ = "storefronts"
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    code: Mapped[str] = mapped_column(String(40), unique=True, nullable=False)
    domain: Mapped[str] = mapped_column(String(255), nullable=False)
    platform: Mapped[str] = mapped_column(String(30), nullable=False, default="woocommerce")
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="disabled")  # active | disabled
    credentials_enc: Mapped[str | None] = mapped_column(Text)
    configuration: Mapped[dict[str, Any]] = mapped_column(
        JSONB, nullable=False, default=dict, server_default="{}"
    )


class StorefrontProductMapping(UUIDPk, Timestamps, Base):
    __tablename__ = "storefront_product_mappings"
    __table_args__ = (
        UniqueConstraint("storefront_id", "product_id"),
        UniqueConstraint("storefront_id", "external_product_id"),
    )
    storefront_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("storefronts.id", ondelete="CASCADE"), nullable=False
    )
    product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("products.id", ondelete="CASCADE"), nullable=False, index=True
    )
    external_product_id: Mapped[str | None] = mapped_column(String(80))  # WooCommerce product ID
    sync_status: Mapped[str] = mapped_column(String(20), nullable=False, default="pending", index=True)
    last_synced_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    last_hash: Mapped[str | None] = mapped_column(String(64))
    last_error: Mapped[str | None] = mapped_column(Text)


class SyncLog(UUIDPk, Base):
    __tablename__ = "sync_logs"
    __table_args__ = (Index("ix_sync_logs_storefront_created", "storefront_id", "created_at"),)
    storefront_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("storefronts.id", ondelete="CASCADE"), nullable=False
    )
    product_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("products.id", ondelete="SET NULL"), index=True
    )
    operation: Mapped[str] = mapped_column(
        String(30), nullable=False
    )  # create | update | unpublish | price | stock
    status: Mapped[str] = mapped_column(String(20), nullable=False)  # success | failed
    response: Mapped[dict[str, Any]] = mapped_column(JSONB, nullable=False, default=dict, server_default="{}")
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
