"""OMGGamer SKU series: game, variant and supplier listing codes; and no publishing without offers."""

from decimal import Decimal

from httpx import AsyncClient
from sqlalchemy import select

from app.catalogue import sku
from app.catalogue.schemas import NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import Product, ProductVariant, Supplier, SupplierOffer, SupplierProduct
from app.db.session import get_sessionmaker
from tests.conftest import MakeUser, login


def test_codes() -> None:
    assert sku.product_code(4218, "game") == "OMG-G004218"
    assert sku.product_code(7, "game_account") == "OMG-A000007"
    base = sku.variant_base("OMG-G004218", "Steam", "EUROPE - all countries", None)
    assert base == "OMG-G004218-STM-EU-STD"
    assert sku.variant_base("OMG-G1", "Xbox Series X|S", "REST OF THE WORLD", "Deluxe Edition").endswith(
        "-XSX-ROW-DLX"
    )
    assert sku.supplier_code("kinguin") == "KG" and sku.supplier_code("foxreload") == "FX"


def item(ext: str, region: str, price: str = "10", qty: int = 3) -> NormalizedProduct:
    return NormalizedProduct(
        external_product_id=ext,
        original_title="Hades PC Steam Key",
        base_title="Hades",
        platform="Steam",
        region=region,
        offers=[
            NormalizedOffer(external_offer_id=f"o{ext}", currency="EUR", price=Decimal(price), quantity=qty)
        ],
    )


async def test_ingest_assigns_the_series() -> None:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        f = (await db.execute(select(Supplier).where(Supplier.code == "foxreload"))).scalar_one()
        svc = CatalogueService(db)
        r1 = await svc.ingest(k, item("1", "Global"))
        await svc.ingest(k, item("2", "EUROPE"))
        await svc.ingest(f, item("3", "Global"))  # same variant from another supplier
        await db.commit()
        p = await db.get(Product, r1.product_id)
        assert p is not None and p.sku is not None and p.sku.startswith("OMG-G")
        skus = {v.sku for v in (await db.execute(select(ProductVariant))).scalars()}
        assert skus == {f"{p.sku}-STM-GL-STD", f"{p.sku}-STM-EU-STD"}
        listing = {sp.sku for sp in (await db.execute(select(SupplierProduct))).scalars()}
        assert {f"{p.sku}-STM-GL-STD-KG", f"{p.sku}-STM-EU-STD-KG", f"{p.sku}-STM-GL-STD-FX"} == listing


async def test_search_by_sku_and_no_publish_without_offers(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        r = await CatalogueService(db).ingest(k, item("9", "Global"))
        p = await db.get(Product, r.product_id)
        assert p is not None
        p.product_status = "approved"
        code = p.sku
        await db.execute(
            SupplierOffer.__table__.update().values(available_quantity=0, availability_status="out_of_stock")
        )
        await db.commit()
    found = (await client.get("/api/v1/catalogue/products", params={"q": code})).json()
    assert found["total"] == 1 and found["items"][0]["sku"] == code
    res = (
        await client.post(
            "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(r.product_id)]}
        )
    ).json()
    assert res["changed"] == 0 and "no_offers_in_stock" in res["details"]
