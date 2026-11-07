"""Schema-level guarantees: uniqueness, check constraints, cascades and the indexes the spec requires."""

from decimal import Decimal

import pytest
from sqlalchemy import select, text
from sqlalchemy.exc import IntegrityError

from app.db.models import (
    ImportJob,
    Product,
    ProductCategory,
    ProductCategoryMapping,
    ProductContent,
    ProductVariant,
    Storefront,
    StorefrontProductMapping,
    Supplier,
    SupplierOffer,
    SupplierProduct,
)
from app.db.session import get_sessionmaker


async def _kinguin(db) -> Supplier:  # type: ignore[no-untyped-def]
    return (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()


def _product(slug: str = "star-wars-outlaws", **kw) -> Product:  # type: ignore[no-untyped-def]
    return Product(
        slug=slug, canonical_title=kw.pop("title", "Star Wars Outlaws"), match_key="star wars outlaws", **kw
    )


async def test_supplier_and_external_id_are_unique() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        db.add(SupplierProduct(supplier_id=k.id, external_product_id="230507", original_title="A"))
        await db.commit()
        db.add(SupplierProduct(supplier_id=k.id, external_product_id="230507", original_title="B"))
        with pytest.raises(IntegrityError, match="uq_supplier_products_supplier_id_external_product_id"):
            await db.commit()


async def test_same_external_id_allowed_for_another_supplier() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        other = Supplier(name="G2A", code="g2a")
        db.add(other)
        await db.flush()
        db.add_all(
            [
                SupplierProduct(supplier_id=k.id, external_product_id="1", original_title="A"),
                SupplierProduct(supplier_id=other.id, external_product_id="1", original_title="A"),
            ]
        )
        await db.commit()


async def test_product_variant_offer_chain_and_cascades() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        p = _product()
        db.add(p)
        await db.flush()
        v = ProductVariant(
            product_id=p.id, sku="swo-ps5", platform="PS5", region="Global", variant_key="ps5||global||"
        )
        db.add(v)
        await db.flush()
        sp = SupplierProduct(
            supplier_id=k.id,
            external_product_id="9",
            original_title="SWO PS5",
            product_id=p.id,
            variant_id=v.id,
        )
        db.add(sp)
        await db.flush()
        db.add(
            SupplierOffer(
                supplier_product_id=sp.id,
                variant_id=v.id,
                external_offer_id="o1",
                currency="EUR",
                supplier_price=Decimal("59.99"),
                available_quantity=3,
            )
        )
        await db.commit()
        sp_id = sp.id

        # Deleting the master keeps the supplier record (original data is never lost) but unlinks it.
        await db.delete(p)
        await db.commit()
        db.expire_all()
        sp_after = (await db.execute(select(SupplierProduct).where(SupplierProduct.id == sp_id))).scalar_one()
        assert sp_after.product_id is None and sp_after.variant_id is None
        offer = (await db.execute(select(SupplierOffer))).scalar_one()
        assert offer.variant_id is None and offer.supplier_price == Decimal("59.9900")


async def test_variant_key_unique_per_product_only() -> None:
    async with get_sessionmaker()() as db:
        a, b = _product("a"), _product("b")
        db.add_all([a, b])
        await db.flush()
        db.add_all(
            [
                ProductVariant(product_id=a.id, sku="a1", variant_key="steam||global|key|"),
                ProductVariant(product_id=b.id, sku="b1", variant_key="steam||global|key|"),
            ]
        )
        await db.commit()
        db.add(ProductVariant(product_id=a.id, sku="a2", variant_key="steam||global|key|"))
        with pytest.raises(IntegrityError):
            await db.commit()


@pytest.mark.parametrize(
    "bad",
    [
        {"product_type": "spaceship"},
        {"product_status": "live"},
    ],
)
async def test_product_check_constraints(bad: dict[str, str]) -> None:
    async with get_sessionmaker()() as db:
        db.add(_product(**bad))
        with pytest.raises(IntegrityError):
            await db.commit()


async def test_offer_rejects_negative_price_and_bad_status() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        sp = SupplierProduct(supplier_id=k.id, external_product_id="5", original_title="X")
        db.add(sp)
        await db.flush()
        db.add(
            SupplierOffer(
                supplier_product_id=sp.id, external_offer_id="o", currency="EUR", supplier_price=Decimal("-1")
            )
        )
        with pytest.raises(IntegrityError, match="price_not_negative"):
            await db.commit()
        await db.rollback()
        db.add(
            SupplierOffer(
                supplier_product_id=sp.id,
                external_offer_id="o",
                currency="EUR",
                supplier_price=Decimal("1"),
                availability_status="maybe",
            )
        )
        with pytest.raises(IntegrityError, match="availability_valid"):
            await db.commit()


async def test_only_one_current_content_version() -> None:
    async with get_sessionmaker()() as db:
        p = _product()
        db.add(p)
        await db.flush()
        db.add(ProductContent(product_id=p.id, locale="en", version=1, is_current=True, title="v1"))
        await db.commit()
        db.add(ProductContent(product_id=p.id, locale="en", version=2, is_current=True, title="v2"))
        with pytest.raises(IntegrityError, match="uq_product_content_one_current"):
            await db.commit()


async def test_one_active_full_import_per_supplier() -> None:
    async with get_sessionmaker()() as db:
        kid = (await _kinguin(db)).id
        db.add(ImportJob(supplier_id=kid, job_type="full", status="running"))
        await db.commit()
        db.add(ImportJob(supplier_id=kid, job_type="incremental", status="running"))  # other types are fine
        await db.commit()
        db.add(ImportJob(supplier_id=kid, job_type="full", status="queued"))
        with pytest.raises(IntegrityError, match="uq_import_jobs_one_active_full"):
            await db.commit()
        await db.rollback()
        job = (await db.execute(select(ImportJob).where(ImportJob.job_type == "full"))).scalar_one()
        job.status = "completed"
        await db.commit()
        db.add(ImportJob(supplier_id=kid, job_type="full", status="queued"))
        await db.commit()


async def test_category_tree_and_mapping_cascade() -> None:
    async with get_sessionmaker()() as db:
        games = ProductCategory(name="Games", slug="games")
        db.add(games)
        await db.flush()
        action = ProductCategory(name="Action", slug="action", parent_id=games.id)
        p = _product()
        db.add_all([action, p])
        await db.flush()
        db.add(
            ProductCategoryMapping(
                product_id=p.id, category_id=action.id, source="supplier", confidence=Decimal("0.7")
            )
        )
        await db.commit()
        await db.delete(games)
        with pytest.raises(IntegrityError):  # parent with children cannot be deleted
            await db.commit()
        await db.rollback()
        await db.delete(p)
        await db.commit()
        assert (await db.execute(select(ProductCategoryMapping))).first() is None


async def test_storefront_mapping_unique_per_storefront() -> None:
    async with get_sessionmaker()() as db:
        sf = (await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))).scalar_one()
        a, b = _product("a"), _product("b")
        db.add_all([a, b])
        await db.flush()
        db.add(StorefrontProductMapping(storefront_id=sf.id, product_id=a.id, external_product_id="2362"))
        await db.commit()
        db.add(StorefrontProductMapping(storefront_id=sf.id, product_id=b.id, external_product_id="2362"))
        with pytest.raises(IntegrityError):
            await db.commit()


async def test_required_indexes_exist() -> None:
    async with get_sessionmaker()() as db:
        rows = (
            (await db.execute(text("SELECT indexname FROM pg_indexes WHERE schemaname = 'public'")))
            .scalars()
            .all()
        )
    names = set(rows)
    for required in [
        "ix_supplier_products_external_product_id",
        "ix_supplier_products_supplier_id",
        "uq_supplier_products_supplier_id_external_product_id",
        "uq_products_slug",
        "ix_products_canonical_title_trgm",
        "ix_products_product_status",
        "ix_product_variants_platform",
        "ix_product_variants_region",
        "ix_supplier_offers_region",
        "ix_product_category_mappings_category_id",
        "ix_storefront_product_mappings_sync_status",
    ]:
        assert required in names, required


async def test_seeded_rows() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        sfs = (await db.execute(select(Storefront.code, Storefront.domain).order_by(Storefront.code))).all()
    assert k.status == "active" and k.integration_type == "api"
    assert k.configuration["api_base"] == "https://gateway.kinguin.net/esa/api"
    assert [tuple(r) for r in sfs] == [
        ("omggamer-live", "omggamer.com"),
        ("omggamer-staging", "omggamer2-9tw2.1wp.site"),
    ]
