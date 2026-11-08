"""Catalogue models: the normalised input every supplier adapter produces, and API request/response shapes."""

import uuid
from datetime import date, datetime
from decimal import Decimal
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

from app.db.models.catalogue import AVAILABILITY, MEDIA_TYPES, PRODUCT_STATUSES, PRODUCT_TYPES

ProductType = Literal[
    "game", "dlc", "software", "gift_card", "game_account", "subscription", "hardware", "accessory"
]
Availability = Literal["in_stock", "out_of_stock", "preorder", "unavailable"]
MediaType = Literal["cover", "screenshot", "video", "banner", "logo"]

assert set(ProductType.__args__) == set(PRODUCT_TYPES)  # type: ignore[attr-defined]
assert set(Availability.__args__) == set(AVAILABILITY)  # type: ignore[attr-defined]
assert set(MediaType.__args__) == set(MEDIA_TYPES)  # type: ignore[attr-defined]

# ------------------------------------------------------------
# Normalised supplier input (produced by supplier adapters, consumed by CatalogueService.ingest)
# ------------------------------------------------------------


class NormalizedOffer(BaseModel):
    external_offer_id: str = Field(min_length=1, max_length=80)
    seller_id: str | None = Field(default=None, max_length=80)
    region: str | None = Field(default=None, max_length=80)
    currency: str = Field(min_length=3, max_length=3)
    price: Decimal = Field(ge=0, max_digits=12, decimal_places=4)
    quantity: int = Field(ge=0, default=0)
    availability: Availability = "in_stock"

    @field_validator("currency")
    @classmethod
    def _upper(cls, v: str) -> str:
        return v.upper()


class NormalizedMedia(BaseModel):
    url: str = Field(min_length=8, max_length=1000)
    media_type: MediaType = "screenshot"


class NormalizedProduct(BaseModel):
    """One supplier record, reduced to the facts OMGGamer OS stores. Missing facts stay None."""

    external_product_id: str = Field(min_length=1, max_length=80)
    external_product_set_id: str | None = Field(default=None, max_length=80)
    original_title: str = Field(min_length=1, max_length=500)
    original_description: str | None = None
    # Clean product name when the supplier provides one (e.g. Kinguin originalName); used for grouping.
    base_title: str | None = Field(default=None, max_length=300)
    product_type: ProductType = "game"
    platform: str | None = Field(default=None, max_length=80)
    edition: str | None = Field(default=None, max_length=120)
    region: str | None = Field(default=None, max_length=80)
    activation_type: str | None = Field(default=None, max_length=40)
    language: str | None = Field(default=None, max_length=80)
    variant_attributes: dict[str, Any] = Field(default_factory=dict)
    brand: str | None = Field(default=None, max_length=160)
    developer: str | None = Field(default=None, max_length=300)
    publisher: str | None = Field(default=None, max_length=300)
    release_date: date | None = None
    genres: list[str] = Field(default_factory=list)
    media: list[NormalizedMedia] = Field(default_factory=list)
    offers: list[NormalizedOffer] = Field(default_factory=list)
    # True when `offers` is the supplier's full current list, so missing offers can be marked unavailable.
    offers_complete: bool = True
    raw_payload: dict[str, Any] = Field(default_factory=dict)


class IngestResult(BaseModel):
    supplier_product_id: uuid.UUID
    product_id: uuid.UUID
    variant_id: uuid.UUID
    status: Literal["created", "updated", "unchanged"]
    product_created: bool
    variant_created: bool
    offers_upserted: int
    offers_marked_unavailable: int


# ------------------------------------------------------------
# API responses
# ------------------------------------------------------------


class ORM(BaseModel):
    model_config = ConfigDict(from_attributes=True)


class OfferOut(ORM):
    id: uuid.UUID
    supplier_product_id: uuid.UUID
    variant_id: uuid.UUID | None
    external_offer_id: str
    seller_id: str | None
    region: str | None
    currency: str
    supplier_price: Decimal
    available_quantity: int
    availability_status: str
    last_synced_at: datetime | None


class VariantOut(ORM):
    id: uuid.UUID
    sku: str
    platform: str | None
    edition: str | None
    region: str | None
    activation_type: str | None
    language: str | None
    variant_attributes: dict[str, Any]
    offer_count: int = 0
    lowest_price: Decimal | None = None
    lowest_price_currency: str | None = None


class SupplierProductOut(ORM):
    id: uuid.UUID
    supplier_code: str
    external_product_id: str
    external_product_set_id: str | None
    original_title: str
    variant_id: uuid.UUID | None
    import_status: str
    last_fetched_at: datetime | None


class SupplierProductDetail(SupplierProductOut):
    original_description: str | None
    raw_payload: dict[str, Any]
    payload_checksum: str | None


class CategoryRef(ORM):
    id: uuid.UUID
    name: str
    slug: str
    source: str
    confidence: Decimal | None


class MediaOut(ORM):
    id: uuid.UUID
    source_url: str
    storage_url: str | None
    media_type: str
    processing_status: str
    position: int


class ContentOut(ORM):
    id: uuid.UUID
    locale: str
    version: int
    origin: str
    title: str | None
    short_description: str | None
    long_description: str | None
    meta_title: str | None
    meta_description: str | None
    image_alt_text: dict[str, Any]
    tags: list[str]
    content_status: str
    model: str | None
    prompt_version: str | None
    generated_at: datetime | None


class ProductListItem(BaseModel):
    id: uuid.UUID
    canonical_title: str
    slug: str
    product_type: str
    product_status: str
    developer: str | None
    publisher: str | None
    cover_url: str | None
    platforms: list[str]
    regions: list[str]
    suppliers: list[str]
    variant_count: int
    offer_count: int
    lowest_price: Decimal | None
    lowest_price_currency: str | None
    content_status: str | None
    external_ids: list[str] = Field(default_factory=list)
    categories: list[str] = Field(default_factory=list)
    editions: list[str] = Field(default_factory=list)
    publishing_status: str = "not_published"
    updated_at: datetime


class ProductPage(BaseModel):
    items: list[ProductListItem]
    total: int
    page: int
    page_size: int


class ProductDetail(BaseModel):
    id: uuid.UUID
    master_product_id: uuid.UUID | None
    canonical_title: str
    slug: str
    match_key: str
    product_type: str
    product_status: str
    brand: str | None
    developer: str | None
    publisher: str | None
    release_date: date | None
    created_at: datetime
    updated_at: datetime
    variants: list[VariantOut]
    offers: list[OfferOut]
    supplier_products: list[SupplierProductOut]
    categories: list[CategoryRef]
    media: list[MediaOut]
    content: ContentOut | None
    merged_from: list[uuid.UUID]


class ProductUpdateIn(BaseModel):
    canonical_title: str | None = Field(default=None, min_length=1, max_length=300)
    product_type: ProductType | None = None
    brand: str | None = Field(default=None, max_length=160)
    developer: str | None = Field(default=None, max_length=300)
    publisher: str | None = Field(default=None, max_length=300)
    release_date: date | None = None
    product_status: Literal["draft", "review", "archived"] | None = None


class MergeIn(BaseModel):
    target_product_id: uuid.UUID


class CategoryNode(BaseModel):
    id: uuid.UUID
    name: str
    slug: str
    parent_id: uuid.UUID | None
    position: int
    product_count: int
    children: list["CategoryNode"] = Field(default_factory=list)


class CategoryIn(BaseModel):
    name: str = Field(min_length=1, max_length=160)
    parent_id: uuid.UUID | None = None
    slug: str | None = Field(default=None, max_length=200)
    position: int = 0


class CategoryUpdateIn(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=160)
    parent_id: uuid.UUID | None = None
    move_to_root: bool = False
    position: int | None = None


class ProductCategoriesIn(BaseModel):
    category_ids: list[uuid.UUID] = Field(max_length=50)


class SupplierOut(ORM):
    id: uuid.UUID
    name: str
    code: str
    integration_type: str
    status: str
    configuration: dict[str, Any]
    supplier_products: int = 0
    created_at: datetime


class StorefrontOut(ORM):
    id: uuid.UUID
    name: str
    code: str
    domain: str
    platform: str
    status: str
    mapped_products: int = 0


assert set(PRODUCT_STATUSES) >= {"draft", "review", "archived"}


# ------------------------------------------------------------
# Phase 4: bulk operations, history and activity
# ------------------------------------------------------------

BulkAction = Literal[
    "generate_ai",
    "regenerate_ai",
    "assign_categories",
    "approve",
    "reject",
    "publish",
    "archive",
    "retry_failed",
]


class BulkIn(BaseModel):
    action: BulkAction
    product_ids: list[uuid.UUID] = Field(min_length=1, max_length=500)
    category_ids: list[uuid.UUID] = Field(default_factory=list, max_length=50)
    storefront: str | None = Field(default=None, max_length=40)
    note: str | None = Field(default=None, max_length=500)


class BulkResult(BaseModel):
    action: str
    requested: int
    changed: int
    skipped: int
    details: dict[str, int] = Field(default_factory=dict)


class ContentVersionOut(ContentOut):
    is_current: bool
    created_at: datetime


class PublishingEvent(BaseModel):
    kind: Literal["queue", "sync"]
    storefront: str | None
    action: str
    status: str
    detail: str | None
    created_at: datetime


class PublishingMappingOut(BaseModel):
    storefront: str
    external_product_id: str | None
    sync_status: str
    last_synced_at: datetime | None
    last_error: str | None


class PublishingHistory(BaseModel):
    mappings: list[PublishingMappingOut]
    events: list[PublishingEvent]


class ActivityOut(ORM):
    id: uuid.UUID
    created_at: datetime
    actor_email: str | None
    action: str
    summary: str
    data: dict[str, Any]
