"""CatalogueService: grouping, idempotent ingest, offer updates, fact protection, merges and categories."""

import asyncio
from decimal import Decimal

import pytest
from sqlalchemy import func, select

from app.catalogue.schemas import NormalizedMedia, NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.core.errors import AppError
from app.db.models import (
    Product,
    ProductCategory,
    ProductCategoryMapping,
    ProductMedia,
    ProductVariant,
    Supplier,
    SupplierOffer,
    SupplierProduct,
)
from app.db.session import get_sessionmaker


def item(
    ext: str,
    title: str,
    platform: str,
    region: str = "Global",
    edition: str | None = None,
    offers: list[NormalizedOffer] | None = None,
    **kw,
) -> NormalizedProduct:  # type: ignore[no-untyped-def]
    return NormalizedProduct(
        external_product_id=ext,
        original_title=title,
        base_title=kw.pop("base_title", "Star Wars Outlaws"),
        platform=platform,
        region=region,
        edition=edition,
        activation_type=kw.pop("activation_type", "key"),
        offers=offers
        if offers is not None
        else [
            NormalizedOffer(external_offer_id=f"{ext}-a", currency="eur", price=Decimal("59.99"), quantity=4)
        ],
        raw_payload={"kinguinId": ext, "name": title, **kw.pop("raw", {})},
        **kw,
    )


async def _kinguin(db) -> Supplier:  # type: ignore[no-untyped-def]
    return (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()


async def count(db, model) -> int:  # type: ignore[no-untyped-def]
    return int(await db.scalar(select(func.count()).select_from(model)) or 0)


async def test_variants_of_one_game_share_a_master_product() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        svc = CatalogueService(db)
        a = await svc.ingest(
            k,
            item(
                "1",
                "Star Wars Outlaws Gold Edition (PS5) Digital Key (Global)",
                "PS5",
                edition="Gold Edition",
            ),
        )
        b = await svc.ingest(
            k,
            item(
                "2",
                "Star Wars Outlaws Standard Edition (PC) Digital Key",
                "Steam",
                edition="Standard Edition",
            ),
        )
        c = await svc.ingest(
            k,
            item("3", "Star Wars Outlaws PS5 Account", "PS5", activation_type="account", product_type="game"),
        )
        await db.commit()
        assert a.product_created and not b.product_created and not c.product_created
        assert a.product_id == b.product_id == c.product_id
        assert len({a.variant_id, b.variant_id, c.variant_id}) == 3
        p = await db.get(Product, a.product_id)
        assert p is not None and p.canonical_title == "Star Wars Outlaws" and p.slug == "star-wars-outlaws"
        assert await count(db, Product) == 1 and await count(db, ProductVariant) == 3
        assert await count(db, SupplierProduct) == 3 and await count(db, SupplierOffer) == 3


async def test_two_supplier_records_for_one_variant_share_it() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        svc = CatalogueService(db)
        a = await svc.ingest(k, item("10", "Star Wars Outlaws PC Steam Key", "Steam"))
        b = await svc.ingest(k, item("11", "Star Wars Outlaws (PC) Steam CD Key Global", "Steam"))
        await db.commit()
        assert a.variant_id == b.variant_id and a.supplier_product_id != b.supplier_product_id
        assert await count(db, SupplierOffer) == 2  # two sellers' offers on the same variant


async def test_ingest_is_idempotent_and_detects_changes() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        svc = CatalogueService(db)
        first = await svc.ingest(k, item("20", "Star Wars Outlaws PC Steam Key", "Steam"))
        again = await svc.ingest(k, item("20", "Star Wars Outlaws PC Steam Key", "Steam"))
        await db.commit()
        assert first.status == "created" and again.status == "unchanged"
        assert (
            await count(db, SupplierProduct),
            await count(db, ProductVariant),
            await count(db, SupplierOffer),
        ) == (1, 1, 1)

        changed = item(
            "20",
            "Star Wars Outlaws PC Steam Key",
            "Steam",
            offers=[
                NormalizedOffer(external_offer_id="20-b", currency="EUR", price=Decimal("49.50"), quantity=1)
            ],
            raw={"price": 49.5},
        )
        res = await svc.ingest(k, changed)
        await db.commit()
        assert res.status == "updated" and res.offers_upserted == 1 and res.offers_marked_unavailable == 1
        offers = {o.external_offer_id: o for o in (await db.execute(select(SupplierOffer))).scalars()}
        assert offers["20-a"].availability_status == "unavailable" and offers["20-a"].available_quantity == 0
        assert offers["20-b"].supplier_price == Decimal("49.5000")


async def test_original_supplier_data_is_kept_and_edits_are_not_overwritten() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        svc = CatalogueService(db)
        res = await svc.ingest(
            k,
            item(
                "30",
                "Star Wars Outlaws PC",
                "Steam",
                developer="Massive",
                publisher="Ubisoft",
                original_description="<p>Supplier text</p>",
            ),
        )
        await db.commit()
        p = await db.get(Product, res.product_id)
        assert p is not None and p.developer == "Massive"
        p.developer = "Massive Entertainment"  # edited in OMGGamer OS
        p.canonical_title = "Star Wars Outlaws"
        await db.commit()
        await svc.ingest(
            k,
            item(
                "30",
                "Star Wars Outlaws PC",
                "Steam",
                developer="Massive",
                publisher="Ubisoft",
                original_description="<p>New supplier text</p>",
                raw={"v": 2},
            ),
        )
        await db.commit()
        await db.refresh(p)
        sp = (await db.execute(select(SupplierProduct))).scalar_one()
        assert p.developer == "Massive Entertainment"
        assert sp.original_description == "<p>New supplier text</p>" and sp.raw_payload["v"] == 2


async def test_parallel_ingest_does_not_duplicate_master_products() -> None:
    titles = [
        ("40", "Star Wars Outlaws PS5 Key", "PS5"),
        ("41", "Star Wars Outlaws Xbox Key", "Xbox"),
        ("42", "Star Wars Outlaws PC Key", "Steam"),
        ("43", "Star Wars Outlaws Switch Key", "Switch"),
    ]

    async def run(ext: str, title: str, platform: str) -> None:
        async with get_sessionmaker()() as db:
            await CatalogueService(db).ingest(await _kinguin(db), item(ext, title, platform))
            await db.commit()

    await asyncio.gather(*(run(*t) for t in titles))
    async with get_sessionmaker()() as db:
        assert await count(db, Product) == 1 and await count(db, ProductVariant) == 4


async def test_media_and_supplier_genres_map_to_existing_categories() -> None:
    async with get_sessionmaker()() as db:
        db.add(ProductCategory(name="Action", slug="action"))
        await db.commit()
        k = await _kinguin(db)
        res = await CatalogueService(db).ingest(
            k,
            item(
                "50",
                "Star Wars Outlaws PC",
                "Steam",
                genres=["Action", "Unknown Genre"],
                media=[
                    NormalizedMedia(url="https://cdn.example/cover.jpg", media_type="cover"),
                    NormalizedMedia(url="https://cdn.example/1.jpg"),
                ],
            ),
        )
        await db.commit()
        maps = (await db.execute(select(ProductCategoryMapping))).scalars().all()
        assert len(maps) == 1 and maps[0].product_id == res.product_id and maps[0].source == "supplier"
        assert await count(db, ProductMedia) == 2


async def test_merge_moves_everything_and_keeps_a_pointer() -> None:
    async with get_sessionmaker()() as db:
        k = await _kinguin(db)
        svc = CatalogueService(db)
        a = await svc.ingest(k, item("60", "Star Wars Outlaws PC", "Steam"))
        b = await svc.ingest(
            k, item("61", "SW Outlaws PC", "Steam", base_title="SW Outlaws")
        )  # same variant, wrong grouping
        c = await svc.ingest(k, item("62", "SW Outlaws PS5", "PS5", base_title="SW Outlaws"))
        await db.commit()
        assert a.product_id != b.product_id
        target = await svc.merge(b.product_id, a.product_id)
        await db.commit()
        assert target.id == a.product_id
        src = await db.get(Product, b.product_id)
        assert src is not None and src.product_status == "merged" and src.master_product_id == a.product_id
        variants = (
            (await db.execute(select(ProductVariant).where(ProductVariant.product_id == a.product_id)))
            .scalars()
            .all()
        )
        assert {v.platform for v in variants} == {"Steam", "PS5"}  # duplicate Steam variant folded into one
        sps = (await db.execute(select(SupplierProduct))).scalars().all()
        assert {sp.product_id for sp in sps} == {a.product_id}
        steam = next(v for v in variants if v.platform == "Steam")
        assert {sp.variant_id for sp in sps if sp.external_product_id in ("60", "61")} == {steam.id}
        assert c.variant_id in {v.id for v in variants}

        # Re-ingesting a record that belonged to the merged product lands on the surviving product.
        again = await svc.ingest(
            k, item("61", "SW Outlaws PC", "Steam", base_title="SW Outlaws", raw={"v": 2})
        )
        await db.commit()
        assert again.product_id == a.product_id

        with pytest.raises(AppError):
            await svc.merge(b.product_id, a.product_id)  # already merged
        with pytest.raises(AppError):
            await svc.merge(a.product_id, a.product_id)


async def test_category_move_rejects_cycles() -> None:
    async with get_sessionmaker()() as db:
        svc = CatalogueService(db)
        games = await svc.create_category("Games", None, None, 0)
        pc = await svc.create_category("PC Games", games.id, None, 0)
        action = await svc.create_category("Action", pc.id, None, 0)
        await db.commit()
        assert action.slug == "action"
        with pytest.raises(AppError, match="own subcategories"):
            await svc.move_category(games, action.id)
        with pytest.raises(AppError):
            await svc.move_category(games, games.id)
        await svc.move_category(action, games.id)
        await db.commit()
        dup = await svc.create_category("Action", None, None, 0)
        assert dup.slug == "action-2"
        with pytest.raises(AppError, match="subcategories first"):
            await svc.delete_category(games)
