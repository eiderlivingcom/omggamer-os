"""Region, delivery type and devices from supplier titles; re-sorting stored records into the right option."""

from typing import Any

from sqlalchemy import select

from app.catalogue import listing_facts as f
from app.catalogue.resort import resort
from app.catalogue.services import CatalogueService
from app.db.models import ProductVariant, Supplier, SupplierOffer, SupplierProduct
from app.db.session import get_sessionmaker
from app.suppliers.kinguin.normalizer import normalize


def test_country_from_title_inside_the_suppliers_region() -> None:
    t = "Hollow Knight: Silksong {} XBOX One / Xbox Series X|S / PC CD Key"
    assert f.country_region(t.format("UK"), "EUROPE") == "United Kingdom"
    assert f.country_region(t.format("PL"), "EUROPE") == "Poland"
    assert f.country_region(t.format("US"), "AMERICAS") == "United States"
    assert f.country_region(t.format("AU"), "AUSTRALIA AND OCEANIA") == "Australia"
    assert f.country_region(t.format("ZA"), "AFRICA") == "South Africa"
    assert f.country_region(t.format("EU"), "EUROPE") is None  # already the region
    assert (
        f.country_region(t.format("US"), "EUROPE") is None
    )  # does not fit the supplier's region: not trusted
    assert f.country_region("Hollow Knight: Silksong PC Steam CD Key", "REGION FREE") is None
    assert f.country_region("Call of Duty: WWII US PS4 CD Key", "AMERICAS") == "United States"


def test_delivery_type_and_devices() -> None:
    assert f.delivery_type("Hollow Knight: Silksong EU PC Steam Altergift") == "gift"
    assert f.delivery_type("Hollow Knight: Silksong Nintendo Switch Access") == "access"
    assert f.delivery_type("Anno 1800 Early Access PC Steam CD Key") == "key"
    assert f.delivery_type("FIFA 23 PS5 Account") == "account"
    assert f.delivery_type("Steam Gift Card 20 USD") == "gift_card"
    assert f.delivery_type("Hollow Knight: Silksong PC Steam CD Key") == "key"
    assert f.devices("Hollow Knight: Silksong UK XBOX One / Xbox Series X|S / PC CD Key") == [
        "Xbox One",
        "Xbox Series X|S",
        "PC",
    ]
    assert f.devices("Game Nintendo Switch 2 Key") == ["Nintendo Switch 2"]


def record(kid: int, name: str, platform: str, region: str, price: float = 9.0) -> dict[str, Any]:
    return {
        "kinguinId": kid,
        "productId": f"p{kid}",
        "name": name,
        "originalName": "Hollow Knight: Silksong",
        "platform": platform,
        "regionalLimitations": region,
        "offers": [{"offerId": f"o{kid}", "price": price, "qty": 3, "merchantName": "S"}],
    }


def test_kinguin_normalizer_uses_the_title() -> None:
    uk = normalize(
        record(1, "Hollow Knight: Silksong UK XBOX One / Xbox Series X|S / PC CD Key", "Xbox One", "EUROPE")
    )
    assert (uk.region, uk.activation_type) == ("United Kingdom", "key")
    assert uk.variant_attributes["devices"] == ["Xbox One", "Xbox Series X|S", "PC"]
    assert uk.variant_attributes["kinguin_region"] == "EUROPE"
    gift = normalize(record(2, "Hollow Knight: Silksong EU PC Steam Altergift", "PC Steam", "EUROPE"))
    assert (gift.platform, gift.region, gift.activation_type) == ("Steam", "EUROPE", "gift")


async def test_resort_moves_records_and_offers_and_drops_empty_options() -> None:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        svc = CatalogueService(db)
        raws = [
            record(
                383820,
                "Hollow Knight: Silksong EU XBOX One / Xbox Series X|S / PC CD Key",
                "Xbox One",
                "EUROPE",
            ),
            record(
                383821,
                "Hollow Knight: Silksong UK XBOX One / Xbox Series X|S / PC CD Key",
                "Xbox One",
                "EUROPE",
            ),
            record(381327, "Hollow Knight: Silksong PC Steam Altergift", "PC Steam", "REGION FREE"),
            record(300108, "Hollow Knight: Silksong PC Steam CD Key", "PC Steam", "REGION FREE"),
        ]
        for raw in raws:
            await svc.ingest(k, normalize(raw))
        await db.commit()

        # As the old import sorted them: the UK key in Europe, the gift with the Steam keys.
        sps = {sp.external_product_id: sp for sp in (await db.execute(select(SupplierProduct))).scalars()}
        pid = sps["383820"].product_id
        for wrong, right in (("383821", "383820"), ("381327", "300108")):
            sps[wrong].variant_id = sps[right].variant_id
            for o in (
                await db.execute(
                    select(SupplierOffer).where(SupplierOffer.supplier_product_id == sps[wrong].id)
                )
            ).scalars():
                o.variant_id = sps[right].variant_id
        await db.commit()
        await db.execute(
            ProductVariant.__table__.delete().where(
                ProductVariant.id.notin_(
                    select(SupplierProduct.variant_id).where(SupplierProduct.variant_id.is_not(None))
                )
            )
        )
        await db.commit()
        assert len(list((await db.execute(select(ProductVariant))).scalars())) == 2

        stats = await resort(db)
        assert stats.moved == 2 and stats.products == {pid}

        rows = (
            await db.execute(
                select(
                    SupplierProduct.external_product_id, ProductVariant.region, ProductVariant.activation_type
                ).join(ProductVariant, ProductVariant.id == SupplierProduct.variant_id)
            )
        ).all()
        got = {ext: (region, act) for ext, region, act in rows}
        assert got["383821"] == ("United Kingdom", "key")
        assert got["383820"] == ("EUROPE", "key")
        assert got["381327"] == ("Global", "gift")
        assert got["300108"] == ("Global", "key")
        # Offers follow their record.
        uk_offer = (
            await db.execute(
                select(SupplierOffer).where(SupplierOffer.supplier_product_id == sps["383821"].id)
            )
        ).scalar_one()
        assert uk_offer.variant_id == sps["383821"].variant_id
        # Running it again changes nothing.
        assert (await resort(db)).moved == 0
