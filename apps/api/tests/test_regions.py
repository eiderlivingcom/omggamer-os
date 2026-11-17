"""Defined regions: supplier labels map onto one list; duplicates are pushed once; GOG.com is a store."""

from decimal import Decimal

from sqlalchemy import select

from app.catalogue import regions
from app.catalogue.schemas import NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import Product, Storefront, Supplier
from app.db.session import get_sessionmaker
from app.publishing.runner import build_payload
from tests.test_publishing import seed


def test_labels_map_onto_the_defined_list() -> None:
    same = {
        "Rest of the World": [
            "REST OF THE WORLD",
            "Rest of the world (RoW) - custom",
            "Rest of World (RoW)",
            "RoW",
            "Nacon ROW",
        ],
        "Europe": ["EUROPE", "EUROPE - all countries", "EU", "European Union"],
        "Global": ["Global", "WW", "Region free", "", None],
        "Asia": ["ASIA", "ASIA - all countries"],
        "Global (some countries excluded)": ["NSFW (region locked in CN, DE, RU, KR, SA, UA)"],
        "Europe (some countries excluded)": ["EU without DE"],
        "Other": ["OTHER", "template 1", "1564976", "bread and fred ROW - INVERSED"],
    }
    for name, labels in same.items():
        assert {regions.canonical(x) for x in labels} == {name}, name
    assert all(regions.definition(n) for n in regions.REGIONS)
    assert regions.canonical("Poland") == "Poland"  # an undefined place keeps a tidy name
    assert regions.platform_name("GOG") == "PC" and regions.store_of("GOG.com") == "GOG.com"
    assert regions.platform_name("Steam") == "Steam" and regions.store_of("Steam") is None


async def test_publish_sends_defined_regions_once() -> None:
    pid = await seed()
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        svc = CatalogueService(db)
        for ext, region, price in [("9", "EUROPE", "9.00"), ("10", "EUROPE - all countries", "8.00")]:
            await svc.ingest(
                k,
                NormalizedProduct(
                    external_product_id=ext,
                    original_title="Hades PC Steam Key EU",
                    base_title="Hades",
                    platform="Steam",
                    region=region,
                    offers=[
                        NormalizedOffer(
                            external_offer_id=f"o{ext}", currency="EUR", price=Decimal(price), quantity=2
                        )
                    ],
                ),
            )
        await svc.ingest(
            k,
            NormalizedProduct(
                external_product_id="11",
                original_title="Hades GOG Key",
                base_title="Hades",
                platform="GOG",
                region="Rest of the world (RoW) - custom",
                offers=[
                    NormalizedOffer(external_offer_id="o11", currency="EUR", price=Decimal("7"), quantity=1)
                ],
            ),
        )
        await db.commit()
        product = await db.get(Product, pid)
        sf = (await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))).scalar_one()
        assert product is not None
        payload = await build_payload(db, product, sf, "publish")
    combos = [(v["attributes"].get("Platform"), v["attributes"].get("Region")) for v in payload["variants"]]
    assert len(combos) == len(set(combos))  # no two variations with the same options
    assert ("Steam", "Europe") in combos and ("PC", "Rest of the World") in combos
    eu = [v for v in payload["variants"] if v["attributes"].get("Region") == "Europe"]
    assert len(eu) == 1 and Decimal(eu[0]["regular_price"]) < Decimal("10.8")  # the cheaper offer is kept
    shown = {a["name"]: a["options"] for a in payload["attributes"]}
    assert shown["Activates on"] == ["GOG.com"]
    assert "GOG" not in {p for p, _ in combos}
    assert payload["meta"]["region_notes"]["Rest of the World"].startswith("Activates in countries outside")
