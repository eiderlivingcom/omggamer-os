"""Catalogue rules.

- `ingest` turns one normalised supplier record into supplier_product -> variant -> master product + offers.
  It is idempotent: running the same record twice changes nothing and creates nothing.
- Supplier-owned columns are refreshed on every ingest; master product facts are only filled when empty,
  so edits made in OMGGamer OS are never overwritten by the supplier.
- `merge` combines duplicate master products without losing variants, offers or supplier links.
"""

import uuid
from decimal import Decimal

from sqlalchemy import func, select, update
from sqlalchemy.ext.asyncio import AsyncSession

from app.catalogue.normalize import base_title, match_key, payload_checksum, slugify, variant_key
from app.catalogue.repositories import (
    CategoryRepository,
    MediaRepository,
    OfferRepository,
    ProductRepository,
    SupplierProductRepository,
    VariantRepository,
    now,
)
from app.catalogue.schemas import IngestResult, NormalizedProduct
from app.core.errors import AppError
from app.db.models import (
    Product,
    ProductCategory,
    ProductCategoryMapping,
    ProductContent,
    ProductMedia,
    ProductVariant,
    StorefrontProductMapping,
    Supplier,
    SupplierOffer,
    SupplierProduct,
)


class CatalogueService:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db
        self.products = ProductRepository(db)
        self.variants = VariantRepository(db)
        self.supplier_products = SupplierProductRepository(db)
        self.offers = OfferRepository(db)
        self.media = MediaRepository(db)
        self.categories = CategoryRepository(db)

    # ------------------------------------------------------------------------------------------------- ingest

    async def ingest(self, supplier: Supplier, item: NormalizedProduct) -> IngestResult:
        """Store one supplier record. Caller owns the transaction (commit after one or many records)."""
        checksum = payload_checksum(item.raw_payload or item.model_dump(mode="json", exclude={"raw_payload"}))
        sp, prev_checksum, created = await self.supplier_products.upsert(
            supplier.id,
            {
                "external_product_id": item.external_product_id,
                "external_product_set_id": item.external_product_set_id,
                "original_title": item.original_title,
                "original_description": item.original_description,
                "raw_payload": item.raw_payload,
                "payload_checksum": checksum,
                "last_fetched_at": now(),
            },
        )
        unchanged = (
            not created
            and prev_checksum == checksum
            and sp.variant_id is not None
            and sp.product_id is not None
        )

        if unchanged:
            sp.import_status = "unchanged"
            assert sp.product_id and sp.variant_id
            await self.db.flush()
            return IngestResult(
                supplier_product_id=sp.id,
                product_id=sp.product_id,
                variant_id=sp.variant_id,
                status="unchanged",
                product_created=False,
                variant_created=False,
                offers_upserted=0,
                offers_marked_unavailable=0,
            )

        product, product_created = await self._master_for(sp, item)
        variant, variant_created = await self._variant_for(product, item)
        self._fill_empty_facts(product, item)

        sp.product_id = product.id
        sp.variant_id = variant.id
        sp.import_status = "new" if created else "updated"

        offer_rows = [
            {
                "external_offer_id": o.external_offer_id,
                "seller_id": o.seller_id,
                "region": o.region or item.region,
                "currency": o.currency,
                "supplier_price": o.price,
                "available_quantity": o.quantity,
                "availability_status": o.availability,
            }
            for o in item.offers
        ]
        upserted = await self.offers.upsert_many(sp.id, variant.id, offer_rows)
        marked = 0
        if item.offers_complete:
            marked = await self.offers.mark_missing_unavailable(
                sp.id, [o.external_offer_id for o in item.offers]
            )

        await self.media.add_missing(product.id, [(m.url, m.media_type) for m in item.media])
        await self._map_supplier_genres(product.id, item.genres)
        await self.db.flush()
        return IngestResult(
            supplier_product_id=sp.id,
            product_id=product.id,
            variant_id=variant.id,
            status="created" if created else "updated",
            product_created=product_created,
            variant_created=variant_created,
            offers_upserted=upserted,
            offers_marked_unavailable=marked,
        )

    async def _master_for(self, sp: SupplierProduct, item: NormalizedProduct) -> tuple[Product, bool]:
        # An already linked record keeps its product (including manual relinks and merges).
        if sp.product_id:
            existing = await self.products.get(sp.product_id)
            if existing is not None:
                return await self.products.resolve_master(existing), False
        title = (item.base_title or base_title(item.original_title)).strip()
        key = match_key(item.base_title or item.original_title) or slugify(item.original_title)
        await self.products.lock_match_key(key, item.product_type)
        found = await self.products.find_by_match_key(key, item.product_type)
        if found is not None:
            return await self.products.resolve_master(found), False
        product = Product(
            canonical_title=title[:300],
            slug=await self.products.unique_slug(title),
            match_key=key,
            product_type=item.product_type,
            product_status="draft",
        )
        self.db.add(product)
        await self.db.flush()
        return product, True

    async def _variant_for(self, product: Product, item: NormalizedProduct) -> tuple[ProductVariant, bool]:
        key = variant_key(item.platform, item.edition, item.region, item.activation_type, item.language)
        found = await self.variants.by_key(product.id, key)
        if found is not None:
            if item.variant_attributes:
                found.variant_attributes = {**found.variant_attributes, **item.variant_attributes}
            return found, False
        parts = [product.slug, item.platform, item.edition, item.region, item.activation_type]
        variant = ProductVariant(
            product_id=product.id,
            sku=await self.variants.unique_sku("-".join(p for p in parts if p)),
            platform=item.platform,
            edition=item.edition,
            region=item.region,
            activation_type=item.activation_type,
            language=item.language,
            variant_attributes=item.variant_attributes,
            variant_key=key,
        )
        self.db.add(variant)
        await self.db.flush()
        return variant, True

    @staticmethod
    def _fill_empty_facts(product: Product, item: NormalizedProduct) -> None:
        for attr in ("brand", "developer", "publisher", "release_date"):
            if getattr(product, attr) in (None, "") and getattr(item, attr) not in (None, ""):
                setattr(product, attr, getattr(item, attr))

    async def _map_supplier_genres(self, product_id: uuid.UUID, genres: list[str]) -> None:
        if not genres:
            return
        cats = await self.categories.by_slugs([slugify(g) for g in genres])
        await self.categories.add_mappings(product_id, [c.id for c in cats], "supplier", Decimal("0.700"))

    # -------------------------------------------------------------------------------------------------- merge

    async def merge(self, source_id: uuid.UUID, target_id: uuid.UUID) -> Product:
        """Move everything from `source` into `target`; `source` stays as a pointer (status 'merged')."""
        if source_id == target_id:
            raise AppError(422, "merge_same_product", "Choose two different products to merge.")
        source = await self.products.get(source_id)
        target = await self.products.get(target_id)
        if source is None or target is None:
            raise AppError(404, "not_found", "Product not found.")
        if source.product_status == "merged":
            raise AppError(409, "already_merged", "This product was already merged into another product.")
        target = await self.products.resolve_master(target)
        if target.id == source.id:
            raise AppError(422, "merge_cycle", "The target product is itself merged into this product.")

        for v in await self.variants.for_product(source.id):
            twin = await self.variants.by_key(target.id, v.variant_key)
            if twin is None:
                v.product_id = target.id
                continue
            await self.db.execute(
                update(SupplierOffer).where(SupplierOffer.variant_id == v.id).values(variant_id=twin.id)
            )
            await self.db.execute(
                update(SupplierProduct).where(SupplierProduct.variant_id == v.id).values(variant_id=twin.id)
            )
            await self.db.delete(v)
        await self.db.flush()

        await self.db.execute(
            update(SupplierProduct)
            .where(SupplierProduct.product_id == source.id)
            .values(product_id=target.id)
        )
        mappings = (
            (
                await self.db.execute(
                    select(ProductCategoryMapping).where(ProductCategoryMapping.product_id == source.id)
                )
            )
            .scalars()
            .all()
        )
        for m in mappings:
            await self.categories.add_mappings(target.id, [m.category_id], m.source, m.confidence)
        media = (
            (await self.db.execute(select(ProductMedia).where(ProductMedia.product_id == source.id)))
            .scalars()
            .all()
        )
        await self.media.add_missing(target.id, [(m.source_url, m.media_type) for m in media])

        target_has_listing = {
            sid
            for (sid,) in (
                await self.db.execute(
                    select(StorefrontProductMapping.storefront_id).where(
                        StorefrontProductMapping.product_id == target.id
                    )
                )
            ).all()
        }
        for listing in (
            (
                await self.db.execute(
                    select(StorefrontProductMapping).where(StorefrontProductMapping.product_id == source.id)
                )
            )
            .scalars()
            .all()
        ):
            if listing.storefront_id not in target_has_listing:
                listing.product_id = target.id

        await self.db.execute(
            update(Product).where(Product.master_product_id == source.id).values(master_product_id=target.id)
        )
        source.master_product_id = target.id
        source.product_status = "merged"
        for attr in ("brand", "developer", "publisher", "release_date"):
            if getattr(target, attr) in (None, "") and getattr(source, attr) not in (None, ""):
                setattr(target, attr, getattr(source, attr))
        await self.db.flush()
        return target

    # --------------------------------------------------------------------------------------------- categories

    async def create_category(
        self, name: str, parent_id: uuid.UUID | None, slug: str | None, position: int
    ) -> ProductCategory:
        if parent_id and await self.categories.get(parent_id) is None:
            raise AppError(422, "unknown_parent", "The parent category does not exist.")
        if slug:
            slug = slugify(slug)
            if await self.categories.by_slugs([slug]):
                raise AppError(409, "slug_taken", f"A category with the slug '{slug}' already exists.")
        cat = ProductCategory(
            name=name.strip(),
            parent_id=parent_id,
            slug=slug or await self.categories.unique_slug(name),
            position=position,
        )
        self.db.add(cat)
        await self.db.flush()
        return cat

    async def move_category(self, cat: ProductCategory, parent_id: uuid.UUID | None) -> None:
        if parent_id is None:
            cat.parent_id = None
            return
        if parent_id == cat.id:
            raise AppError(422, "category_cycle", "A category cannot be its own parent.")
        if await self.categories.get(parent_id) is None:
            raise AppError(422, "unknown_parent", "The parent category does not exist.")
        if cat.id in await self.categories.ancestors(parent_id):
            raise AppError(
                422, "category_cycle", "A category cannot be moved inside one of its own subcategories."
            )
        cat.parent_id = parent_id

    async def delete_category(self, cat: ProductCategory) -> None:
        if await self.categories.has_children(cat.id):
            raise AppError(409, "category_has_children", "Move or delete the subcategories first.")
        await self.db.delete(cat)

    async def set_product_categories(self, product_id: uuid.UUID, category_ids: list[uuid.UUID]) -> None:
        if category_ids:
            found = int(
                await self.db.scalar(
                    select(func.count())
                    .select_from(ProductCategory)
                    .where(ProductCategory.id.in_(category_ids))
                )
                or 0
            )
            if found != len(set(category_ids)):
                raise AppError(422, "unknown_category", "One or more categories do not exist.")
        await self.categories.replace_manual(product_id, category_ids)

    async def current_content(self, product_id: uuid.UUID) -> ProductContent | None:
        q = select(ProductContent).where(
            ProductContent.product_id == product_id,
            ProductContent.locale == "en",
            ProductContent.is_current.is_(True),
        )
        return (await self.db.execute(q)).scalar_one_or_none()
