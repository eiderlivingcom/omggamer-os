"""Data access for the catalogue. Repositories run queries; business rules live in services.py."""

import uuid
from collections.abc import Sequence
from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import Select, and_, delete, exists, func, or_, select, text, update
from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.ext.asyncio import AsyncSession

from app.catalogue.normalize import slugify
from app.db.models import (
    Product,
    ProductCategory,
    ProductCategoryMapping,
    ProductContent,
    ProductMedia,
    ProductVariant,
    Storefront,
    StorefrontProductMapping,
    Supplier,
    SupplierOffer,
    SupplierProduct,
)


def now() -> datetime:
    return datetime.now(UTC)


class SupplierRepository:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db

    async def by_code(self, code: str) -> Supplier | None:
        return (await self.db.execute(select(Supplier).where(Supplier.code == code))).scalar_one_or_none()

    async def all_with_counts(self) -> list[tuple[Supplier, int]]:
        counts = (
            select(SupplierProduct.supplier_id, func.count().label("n"))
            .group_by(SupplierProduct.supplier_id)
            .subquery()
        )
        rows = await self.db.execute(
            select(Supplier, func.coalesce(counts.c.n, 0))
            .outerjoin(counts, counts.c.supplier_id == Supplier.id)
            .order_by(Supplier.name)
        )
        return [(s, int(n)) for s, n in rows.tuples().all()]


class StorefrontRepository:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db

    async def all_with_counts(self) -> list[tuple[Storefront, int]]:
        counts = (
            select(StorefrontProductMapping.storefront_id, func.count().label("n"))
            .group_by(StorefrontProductMapping.storefront_id)
            .subquery()
        )
        rows = await self.db.execute(
            select(Storefront, func.coalesce(counts.c.n, 0))
            .outerjoin(counts, counts.c.storefront_id == Storefront.id)
            .order_by(Storefront.name)
        )
        return [(s, int(n)) for s, n in rows.tuples().all()]


class ProductRepository:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db

    async def get(self, product_id: uuid.UUID) -> Product | None:
        return await self.db.get(Product, product_id)

    async def lock_match_key(self, key: str, product_type: str) -> None:
        """Serialise find-or-create for one grouping key across parallel workers (transaction-scoped)."""
        await self.db.execute(
            text("SELECT pg_advisory_xact_lock(hashtextextended(:k, 0))"), {"k": f"{product_type}:{key}"}
        )

    async def find_by_match_key(self, key: str, product_type: str) -> Product | None:
        q = (
            select(Product)
            .where(
                Product.match_key == key,
                Product.product_type == product_type,
                Product.product_status != "merged",
            )
            .order_by(Product.created_at)
            .limit(1)
        )
        return (await self.db.execute(q)).scalar_one_or_none()

    async def unique_slug(self, base: str, exclude_id: uuid.UUID | None = None) -> str:
        root = slugify(base)
        candidate, n = root, 1
        while True:
            q = select(exists().where(Product.slug == candidate))
            if exclude_id:
                q = select(exists().where(Product.slug == candidate, Product.id != exclude_id))
            if not await self.db.scalar(q):
                return candidate
            n += 1
            candidate = f"{root[:200 - len(str(n)) - 1]}-{n}"

    async def resolve_master(self, product: Product) -> Product:
        """Follow master_product_id links after merges (cycle-safe)."""
        seen = {product.id}
        while product.master_product_id and product.master_product_id not in seen:
            nxt = await self.db.get(Product, product.master_product_id)
            if nxt is None:
                break
            seen.add(nxt.id)
            product = nxt
        return product

    def search_query(
        self,
        *,
        q: str | None,
        product_type: str | None,
        status: str | None,
        platform: str | None,
        region: str | None,
        supplier: str | None,
        category_id: uuid.UUID | None,
        availability: str | None,
        include_merged: bool,
    ) -> Select[tuple[Product]]:
        stmt = select(Product)
        if not include_merged:
            stmt = stmt.where(Product.product_status != "merged")
        if product_type:
            stmt = stmt.where(Product.product_type == product_type)
        if status:
            stmt = stmt.where(Product.product_status == status)
        if platform or region:
            conds = [ProductVariant.product_id == Product.id]
            if platform:
                conds.append(func.lower(ProductVariant.platform) == platform.lower())
            if region:
                conds.append(func.lower(ProductVariant.region) == region.lower())
            stmt = stmt.where(exists().where(and_(*conds)))
        if supplier:
            stmt = stmt.where(
                exists().where(
                    SupplierProduct.product_id == Product.id,
                    SupplierProduct.supplier_id == Supplier.id,
                    Supplier.code == supplier,
                )
            )
        if category_id:
            stmt = stmt.where(
                exists().where(
                    ProductCategoryMapping.product_id == Product.id,
                    ProductCategoryMapping.category_id == category_id,
                )
            )
        if availability == "available":
            stmt = stmt.where(
                exists().where(
                    SupplierProduct.product_id == Product.id,
                    SupplierOffer.supplier_product_id == SupplierProduct.id,
                    SupplierOffer.availability_status.in_(["in_stock", "preorder"]),
                    SupplierOffer.available_quantity > 0,
                )
            )
        elif availability == "unavailable":
            stmt = stmt.where(
                ~exists().where(
                    SupplierProduct.product_id == Product.id,
                    SupplierOffer.supplier_product_id == SupplierProduct.id,
                    SupplierOffer.availability_status.in_(["in_stock", "preorder"]),
                    SupplierOffer.available_quantity > 0,
                )
            )
        if q:
            term = q.strip()
            like = f"%{term}%"
            conds_q: list[Any] = [
                Product.canonical_title.ilike(like),
                Product.developer.ilike(like),
                Product.publisher.ilike(like),
                Product.slug == term.lower(),
                exists().where(
                    SupplierProduct.product_id == Product.id, SupplierProduct.external_product_id == term
                ),
                exists().where(ProductVariant.product_id == Product.id, ProductVariant.sku == term),
            ]
            try:
                conds_q.append(Product.id == uuid.UUID(term))
            except ValueError:
                pass
            stmt = stmt.where(or_(*conds_q))
        return stmt

    async def page(
        self, stmt: Select[tuple[Product]], page: int, page_size: int
    ) -> tuple[list[Product], int]:
        total = int(
            await self.db.scalar(select(func.count()).select_from(stmt.order_by(None).subquery())) or 0
        )
        rows = await self.db.execute(
            stmt.order_by(Product.updated_at.desc(), Product.id)
            .offset((page - 1) * page_size)
            .limit(page_size)
        )
        return list(rows.scalars().all()), total

    async def list_summaries(self, ids: Sequence[uuid.UUID]) -> dict[uuid.UUID, dict[str, Any]]:
        """Aggregates for the product list in four set-based queries (no per-row queries)."""
        out: dict[uuid.UUID, dict[str, Any]] = {
            i: {
                "platforms": [],
                "regions": [],
                "suppliers": [],
                "variant_count": 0,
                "offer_count": 0,
                "lowest_price": None,
                "lowest_price_currency": None,
                "cover_url": None,
                "content_status": None,
            }
            for i in ids
        }
        if not ids:
            return out
        v = await self.db.execute(
            select(
                ProductVariant.product_id,
                func.count(),
                func.array_remove(func.array_agg(func.distinct(ProductVariant.platform)), None),
                func.array_remove(func.array_agg(func.distinct(ProductVariant.region)), None),
            )
            .where(ProductVariant.product_id.in_(ids))
            .group_by(ProductVariant.product_id)
        )
        for pid, n, platforms, regions in v.tuples().all():
            out[pid].update(
                variant_count=int(n), platforms=sorted(platforms or []), regions=sorted(regions or [])
            )
        o = await self.db.execute(
            select(
                SupplierProduct.product_id,
                func.count(SupplierOffer.id),
                func.array_remove(func.array_agg(func.distinct(Supplier.code)), None),
            )
            .join(Supplier, Supplier.id == SupplierProduct.supplier_id)
            .outerjoin(SupplierOffer, SupplierOffer.supplier_product_id == SupplierProduct.id)
            .where(SupplierProduct.product_id.in_(ids))
            .group_by(SupplierProduct.product_id)
        )
        for pid, n, suppliers in o.tuples().all():
            out[pid].update(offer_count=int(n), suppliers=sorted(suppliers or []))
        cheapest = await self.db.execute(
            select(SupplierProduct.product_id, SupplierOffer.supplier_price, SupplierOffer.currency)
            .join(SupplierOffer, SupplierOffer.supplier_product_id == SupplierProduct.id)
            .where(
                SupplierProduct.product_id.in_(ids),
                SupplierOffer.availability_status.in_(["in_stock", "preorder"]),
                SupplierOffer.available_quantity > 0,
            )
            .distinct(SupplierProduct.product_id)
            .order_by(SupplierProduct.product_id, SupplierOffer.supplier_price)
        )
        for pid, price, cur in cheapest.tuples().all():
            out[pid].update(lowest_price=price, lowest_price_currency=cur)
        covers = await self.db.execute(
            select(ProductMedia.product_id, ProductMedia.source_url)
            .where(ProductMedia.product_id.in_(ids))
            .distinct(ProductMedia.product_id)
            .order_by(ProductMedia.product_id, (ProductMedia.media_type != "cover"), ProductMedia.position)
        )
        for pid, url in covers.tuples().all():
            out[pid]["cover_url"] = url
        content = await self.db.execute(
            select(ProductContent.product_id, ProductContent.content_status).where(
                ProductContent.product_id.in_(ids),
                ProductContent.is_current.is_(True),
                ProductContent.locale == "en",
            )
        )
        for pid, st in content.tuples().all():
            out[pid]["content_status"] = st
        return out


class VariantRepository:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db

    async def by_key(self, product_id: uuid.UUID, key: str) -> ProductVariant | None:
        q = select(ProductVariant).where(
            ProductVariant.product_id == product_id, ProductVariant.variant_key == key
        )
        return (await self.db.execute(q)).scalar_one_or_none()

    async def unique_sku(self, base: str) -> str:
        root = slugify(base, 150)
        candidate, n = root, 1
        while await self.db.scalar(select(exists().where(ProductVariant.sku == candidate))):
            n += 1
            candidate = f"{root}-{n}"
        return candidate

    async def for_product(self, product_id: uuid.UUID) -> list[ProductVariant]:
        q = (
            select(ProductVariant)
            .where(ProductVariant.product_id == product_id)
            .order_by(ProductVariant.platform, ProductVariant.edition, ProductVariant.region)
        )
        return list((await self.db.execute(q)).scalars().all())


class SupplierProductRepository:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db

    async def upsert(
        self, supplier_id: uuid.UUID, values: dict[str, Any]
    ) -> tuple[SupplierProduct, str | None, bool]:
        """Race-safe insert-or-update keyed on (supplier_id, external_product_id).

        Returns (row, previous_checksum, created). The update only rewrites supplier-owned columns.
        """
        prev = (
            await self.db.execute(
                select(SupplierProduct.payload_checksum).where(
                    SupplierProduct.supplier_id == supplier_id,
                    SupplierProduct.external_product_id == values["external_product_id"],
                )
            )
        ).first()
        ins = insert(SupplierProduct).values(supplier_id=supplier_id, **values)
        owned = {k: ins.excluded[k] for k in values if k != "external_product_id"}
        stmt = ins.on_conflict_do_update(
            index_elements=[SupplierProduct.supplier_id, SupplierProduct.external_product_id],
            set_={**owned, "updated_at": func.now()},
        ).returning(SupplierProduct.id)
        sp_id = (await self.db.execute(stmt)).scalar_one()
        row = await self.db.get(SupplierProduct, sp_id, populate_existing=True)
        assert row is not None
        return row, (prev[0] if prev else None), prev is None

    async def for_product(self, product_id: uuid.UUID) -> list[tuple[SupplierProduct, str]]:
        rows = await self.db.execute(
            select(SupplierProduct, Supplier.code)
            .join(Supplier, Supplier.id == SupplierProduct.supplier_id)
            .where(SupplierProduct.product_id == product_id)
            .order_by(Supplier.code, SupplierProduct.external_product_id)
        )
        return [(sp, code) for sp, code in rows.tuples().all()]


class OfferRepository:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db

    async def upsert_many(
        self, supplier_product_id: uuid.UUID, variant_id: uuid.UUID, offers: Sequence[dict[str, Any]]
    ) -> int:
        if not offers:
            return 0
        ts = now()
        rows = [
            {**o, "supplier_product_id": supplier_product_id, "variant_id": variant_id, "last_synced_at": ts}
            for o in offers
        ]
        ins = insert(SupplierOffer).values(rows)
        stmt = ins.on_conflict_do_update(
            index_elements=[SupplierOffer.supplier_product_id, SupplierOffer.external_offer_id],
            set_={
                "variant_id": ins.excluded.variant_id,
                "seller_id": ins.excluded.seller_id,
                "region": ins.excluded.region,
                "currency": ins.excluded.currency,
                "supplier_price": ins.excluded.supplier_price,
                "available_quantity": ins.excluded.available_quantity,
                "availability_status": ins.excluded.availability_status,
                "last_synced_at": ins.excluded.last_synced_at,
                "updated_at": func.now(),
            },
        )
        await self.db.execute(stmt)
        return len(rows)

    async def mark_missing_unavailable(self, supplier_product_id: uuid.UUID, keep: Sequence[str]) -> int:
        stmt = (
            update(SupplierOffer)
            .where(
                SupplierOffer.supplier_product_id == supplier_product_id,
                SupplierOffer.availability_status != "unavailable",
            )
            .values(availability_status="unavailable", available_quantity=0, last_synced_at=now())
        )
        if keep:
            stmt = stmt.where(SupplierOffer.external_offer_id.not_in(list(keep)))
        res = await self.db.execute(stmt)
        return int(res.rowcount or 0)  # type: ignore[attr-defined]

    async def for_product(self, product_id: uuid.UUID) -> list[SupplierOffer]:
        rows = await self.db.execute(
            select(SupplierOffer)
            .join(SupplierProduct, SupplierProduct.id == SupplierOffer.supplier_product_id)
            .where(SupplierProduct.product_id == product_id)
            .order_by(SupplierOffer.supplier_price)
        )
        return list(rows.scalars().all())


class MediaRepository:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db

    async def add_missing(self, product_id: uuid.UUID, items: Sequence[tuple[str, str]]) -> int:
        if not items:
            return 0
        base = int(
            await self.db.scalar(select(func.count()).where(ProductMedia.product_id == product_id)) or 0
        )
        rows = [
            {"product_id": product_id, "source_url": url, "media_type": mtype, "position": base + i}
            for i, (url, mtype) in enumerate(dict.fromkeys(items))
        ]
        res = await self.db.execute(
            insert(ProductMedia)
            .values(rows)
            .on_conflict_do_nothing(index_elements=[ProductMedia.product_id, ProductMedia.source_url])
        )
        return int(res.rowcount or 0)  # type: ignore[attr-defined]

    async def for_product(self, product_id: uuid.UUID) -> list[ProductMedia]:
        q = select(ProductMedia).where(ProductMedia.product_id == product_id).order_by(ProductMedia.position)
        return list((await self.db.execute(q)).scalars().all())


class CategoryRepository:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db

    async def get(self, category_id: uuid.UUID) -> ProductCategory | None:
        return await self.db.get(ProductCategory, category_id)

    async def by_slugs(self, slugs: Sequence[str]) -> list[ProductCategory]:
        if not slugs:
            return []
        q = select(ProductCategory).where(ProductCategory.slug.in_(list(slugs)))
        return list((await self.db.execute(q)).scalars().all())

    async def all_with_counts(self) -> list[tuple[ProductCategory, int]]:
        counts = (
            select(ProductCategoryMapping.category_id, func.count().label("n"))
            .group_by(ProductCategoryMapping.category_id)
            .subquery()
        )
        rows = await self.db.execute(
            select(ProductCategory, func.coalesce(counts.c.n, 0))
            .outerjoin(counts, counts.c.category_id == ProductCategory.id)
            .order_by(ProductCategory.position, ProductCategory.name)
        )
        return [(c, int(n)) for c, n in rows.tuples().all()]

    async def ancestors(self, category_id: uuid.UUID) -> list[uuid.UUID]:
        res = await self.db.execute(
            text(
                "WITH RECURSIVE up(id, parent_id, depth) AS ("
                " SELECT id, parent_id, 0 FROM product_categories WHERE id = :id"
                " UNION ALL SELECT c.id, c.parent_id, up.depth + 1 FROM product_categories c"
                " JOIN up ON c.id = up.parent_id WHERE up.depth < 50)"
                " SELECT id FROM up"
            ),
            {"id": category_id},
        )
        return [r[0] for r in res.all()]

    async def unique_slug(self, base: str) -> str:
        root = slugify(base)
        candidate, n = root, 1
        while await self.db.scalar(select(exists().where(ProductCategory.slug == candidate))):
            n += 1
            candidate = f"{root}-{n}"
        return candidate

    async def has_children(self, category_id: uuid.UUID) -> bool:
        return bool(await self.db.scalar(select(exists().where(ProductCategory.parent_id == category_id))))

    async def mappings_for(
        self, product_id: uuid.UUID
    ) -> list[tuple[ProductCategory, ProductCategoryMapping]]:
        rows = await self.db.execute(
            select(ProductCategory, ProductCategoryMapping)
            .join(ProductCategoryMapping, ProductCategoryMapping.category_id == ProductCategory.id)
            .where(ProductCategoryMapping.product_id == product_id)
            .order_by(ProductCategory.name)
        )
        return [(c, m) for c, m in rows.tuples().all()]

    async def add_mappings(
        self,
        product_id: uuid.UUID,
        category_ids: Sequence[uuid.UUID],
        source: str,
        confidence: Decimal | None,
    ) -> None:
        if not category_ids:
            return
        await self.db.execute(
            insert(ProductCategoryMapping)
            .values(
                [
                    {"product_id": product_id, "category_id": cid, "source": source, "confidence": confidence}
                    for cid in dict.fromkeys(category_ids)
                ]
            )
            .on_conflict_do_nothing(index_elements=["product_id", "category_id"])
        )

    async def replace_manual(self, product_id: uuid.UUID, category_ids: Sequence[uuid.UUID]) -> None:
        await self.db.execute(
            delete(ProductCategoryMapping).where(ProductCategoryMapping.product_id == product_id)
        )
        await self.add_mappings(product_id, category_ids, "manual", Decimal("1"))


class ContentRepository:
    def __init__(self, db: AsyncSession) -> None:
        self.db = db

    async def current(self, product_id: uuid.UUID, locale: str = "en") -> ProductContent | None:
        q = select(ProductContent).where(
            ProductContent.product_id == product_id,
            ProductContent.locale == locale,
            ProductContent.is_current.is_(True),
        )
        return (await self.db.execute(q)).scalar_one_or_none()

    async def add_version(self, product_id: uuid.UUID, locale: str, values: dict[str, Any]) -> ProductContent:
        """Store a new content version; the previous one stays as history and is no longer current."""
        nxt = (
            int(
                await self.db.scalar(
                    select(func.coalesce(func.max(ProductContent.version), 0)).where(
                        ProductContent.product_id == product_id, ProductContent.locale == locale
                    )
                )
                or 0
            )
            + 1
        )
        await self.db.execute(
            update(ProductContent)
            .where(
                ProductContent.product_id == product_id,
                ProductContent.locale == locale,
                ProductContent.is_current,
            )
            .values(is_current=False, content_status="superseded")
        )
        row = ProductContent(product_id=product_id, locale=locale, version=nxt, is_current=True, **values)
        self.db.add(row)
        await self.db.flush()
        return row
