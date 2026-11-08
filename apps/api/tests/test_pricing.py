"""Phase 6: pricing engine, currencies, price history, category rules and attributes."""

import uuid
from decimal import Decimal

import httpx
import pytest
from httpx import AsyncClient
from sqlalchemy import select

from app.catalogue.schemas import NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import ProductCategoryMapping, ProductVariant, Storefront, Supplier, SupplierOffer
from app.db.models.pricing import CurrencyRate, PriceHistory, PricingRule, VariantPrice
from app.db.session import get_sessionmaker
from app.pricing import currency
from app.pricing.engine import PricingEngine, Rates, quote, round_up
from tests.conftest import MakeUser, login


def test_rounding_always_goes_up() -> None:
    assert round_up(Decimal("12.10"), "cents_99") == Decimal("12.99")
    assert round_up(Decimal("12.99"), "cents_99") == Decimal("12.99")
    assert round_up(Decimal("13.00"), "cents_99") == Decimal("13.99")
    assert round_up(Decimal("12.10"), "cents_49_99") == Decimal("12.49")
    assert round_up(Decimal("12.50"), "cents_49_99") == Decimal("12.99")
    assert round_up(Decimal("12.01"), "whole") == Decimal("13")
    assert round_up(Decimal("12.01"), "nearest_05") == Decimal("12.05")
    assert round_up(Decimal("12.344"), "none") == Decimal("12.34")


def test_quote_markup_margin_minimum_and_currency() -> None:
    rates = Rates(
        [
            CurrencyRate(base="EUR", quote="USD", rate=Decimal("1.10")),
            CurrencyRate(base="EUR", quote="GBP", rate=Decimal("0.85")),
        ]
    )
    rule = PricingRule(
        name="r",
        scope="global",
        markup_percent=Decimal("10"),
        markup_fixed=Decimal("0.50"),
        min_margin_fixed=Decimal("2"),
        min_price=None,
        rounding="none",
        is_active=True,
    )
    q = quote(Decimal("10"), "EUR", "EUR", rule, rates)
    assert q.selling_price == Decimal("12.00")  # 10 + 10% + 0.50 = 11.50, raised to cost + 2 margin
    q = quote(Decimal("100"), "EUR", "USD", rule, rates)
    assert q.selling_price == Decimal("121.50") and q.fx_rate == Decimal("1.10")
    assert quote(Decimal("10"), "USD", "GBP", rule, rates).fx_rate == Decimal("0.85") / Decimal("1.10")
    rule.min_price, rule.rounding = Decimal("3"), "cents_99"
    assert quote(Decimal("0.20"), "EUR", "EUR", rule, rates).selling_price == Decimal("3.99")
    with pytest.raises(Exception, match="No exchange rate"):
        quote(Decimal("1"), "EUR", "JPY", rule, rates)


async def seed() -> dict[str, uuid.UUID]:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        svc = CatalogueService(db)
        out = {}
        for ext, title, platform, prices in [
            ("1", "Elden Ring PC Steam Key", "Steam", ["20.00", "18.00"]),
            ("2", "Elden Ring PS5 Key", "PS5", ["30.00"]),
        ]:
            r = await svc.ingest(
                k,
                NormalizedProduct(
                    external_product_id=ext,
                    original_title=title,
                    base_title="Elden Ring",
                    platform=platform,
                    region="Global",
                    genres=["RPG"],
                    raw_payload={"kinguinId": ext, "genres": ["RPG"]},
                    offers=[
                        NormalizedOffer(
                            external_offer_id=f"{ext}-{i}", currency="EUR", price=Decimal(p), quantity=2
                        )
                        for i, p in enumerate(prices)
                    ],
                ),
            )
            out[platform] = r.variant_id
            out["product"] = r.product_id
        await db.commit()
        return out


async def test_rules_pick_the_most_specific_and_history_is_kept(
    client: AsyncClient, make_user: MakeUser
) -> None:
    await make_user()
    await login(client)
    ids = await seed()
    staging = "omggamer-staging"
    r = await client.post(
        "/api/v1/pricing/rules", json={"name": "All", "markup_percent": "20", "rounding": "cents_99"}
    )
    assert r.status_code == 201
    await client.post(
        "/api/v1/pricing/rules",
        json={"name": "PS5", "scope": "platform", "scope_value": "PS5", "markup_percent": "5"},
    )
    await client.post(
        "/api/v1/pricing/rules",
        json={
            "name": "Staging only",
            "scope": "platform",
            "scope_value": "PS5",
            "storefront": staging,
            "markup_fixed": "1",
        },
    )
    bad = await client.post("/api/v1/pricing/rules", json={"name": "x", "scope": "category"})
    assert bad.status_code == 422

    prices = (
        await client.get(f"/api/v1/pricing/products/{ids['product']}", params={"storefront": staging})
    ).json()
    by = {p["label"].split(" · ")[0]: p for p in prices}
    assert (
        by["Steam"]["preview_price"] == "21.99"
        and by["Steam"]["cost"] == "18.0000"
        and by["Steam"]["rule"] == "All"
    )
    assert by["PS5"]["preview_price"] == "31.00" and by["PS5"]["rule"] == "Staging only"
    live = (
        await client.get(f"/api/v1/pricing/products/{ids['product']}", params={"storefront": "omggamer-live"})
    ).json()
    assert {p["label"].split(" · ")[0]: p["preview_price"] for p in live}["PS5"] == "31.50"

    rc = (
        await client.post(
            "/api/v1/pricing/recalculate", json={"product_ids": [str(ids["product"])], "storefront": staging}
        )
    ).json()
    assert rc["priced"] == 2 and rc["changed"] == 2
    async with get_sessionmaker()() as db:  # the cheaper Steam seller sells out
        o = (
            await db.execute(select(SupplierOffer).where(SupplierOffer.external_offer_id == "1-1"))
        ).scalar_one()
        o.available_quantity, o.availability_status = 0, "out_of_stock"
        original = o.supplier_price
        await db.commit()
    rc = (
        await client.post(
            "/api/v1/pricing/recalculate", json={"product_ids": [str(ids["product"])], "storefront": staging}
        )
    ).json()
    assert rc["changed"] == 1
    hist = (await client.get("/api/v1/pricing/history", params={"product_id": str(ids["product"])})).json()
    assert (
        hist[0]["old_price"] == "21.99" and hist[0]["new_price"] == "24.99" and hist[0]["cost"] == "20.0000"
    )
    async with get_sessionmaker()() as db:
        o2 = (
            await db.execute(select(SupplierOffer).where(SupplierOffer.external_offer_id == "1-1"))
        ).scalar_one()
        assert o2.supplier_price == original  # supplier prices are never modified
        assert len((await db.execute(select(PriceHistory))).all()) == 3


async def test_no_rule_means_no_price(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    ids = await seed()
    prices = (
        await client.get(f"/api/v1/pricing/products/{ids['product']}", params={"storefront": "omggamer-live"})
    ).json()
    assert all(p["preview_price"] is None and p["note"] == "no pricing rule applies" for p in prices)
    async with get_sessionmaker()() as db:
        sf = (await db.execute(select(Storefront).where(Storefront.code == "omggamer-live"))).scalar_one()
        variants = list((await db.execute(select(ProductVariant))).scalars())
        stats = await PricingEngine(db).recalculate(variants, sf)
        await db.commit()
        vp = (await db.execute(select(VariantPrice))).scalars().all()
    assert stats["errors"] == 2 and all(v.selling_price is None and not v.available for v in vp)


async def test_currencies_manual_and_ecb(
    client: AsyncClient, make_user: MakeUser, monkeypatch: pytest.MonkeyPatch
) -> None:
    await make_user()
    await login(client)
    xml = """<?xml version="1.0" encoding="UTF-8"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01"
      xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="2026-11-06">
      <Cube currency="USD" rate="1.0812"/><Cube currency="GBP" rate="0.8433"/>
      </Cube></Cube></gesmes:Envelope>"""
    monkeypatch.setattr(
        currency, "transport_override", httpx.MockTransport(lambda r: httpx.Response(200, text=xml))
    )
    assert (
        await client.put("/api/v1/pricing/currencies", json={"base": "EUR", "quote": "GBP", "rate": "0.9"})
    ).status_code == 200
    res = (await client.post("/api/v1/pricing/currencies/refresh")).json()
    assert res == {"fetched": 2, "updated": 1, "kept_manual": 1}
    rates = {
        (r["quote"]): (r["rate"], r["source"])
        for r in (await client.get("/api/v1/pricing/currencies")).json()
    }
    assert rates["USD"] == ("1.08120000", "ecb") and rates["GBP"] == ("0.90000000", "manual")
    sf = await client.put("/api/v1/pricing/storefronts/omggamer-live", json={"currency": "usd"})
    assert sf.json() == {"storefront": "omggamer-live", "currency": "USD"}


async def test_category_rules_and_attributes(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    ids = await seed()
    games = (await client.post("/api/v1/catalogue/categories", json={"name": "Games"})).json()
    rpg = (
        await client.post("/api/v1/catalogue/categories", json={"name": "RPG", "parent_id": games["id"]})
    ).json()
    ps = (
        await client.post(
            "/api/v1/catalogue/categories", json={"name": "PlayStation Games", "parent_id": games["id"]}
        )
    ).json()
    r = await client.post(
        "/api/v1/catalogue/category-rules",
        json={"attribute": "genre", "value": "rpg", "category_id": rpg["id"]},
    )
    assert r.status_code == 201 and r.json()["category_name"] == "RPG"
    await client.post(
        "/api/v1/catalogue/category-rules",
        json={"attribute": "platform", "value": "ps5", "category_id": ps["id"]},
    )
    dup = await client.post(
        "/api/v1/catalogue/category-rules",
        json={"attribute": "genre", "value": "RPG", "category_id": rpg["id"]},
    )
    assert dup.status_code == 409
    async with get_sessionmaker()() as db:
        maps = {
            (m.category_id, m.source) for m in (await db.execute(select(ProductCategoryMapping))).scalars()
        }
    assert (uuid.UUID(rpg["id"]), "rule") in maps and (uuid.UUID(ps["id"]), "rule") in maps
    assert (await client.post("/api/v1/catalogue/category-rules/apply")).json() == {"mappings_added": 0}

    attrs = (await client.get("/api/v1/catalogue/attributes")).json()
    assert {a["value"] for a in attrs["platform"]} == {"Steam", "PS5"}
    assert attrs["genre"] == [{"value": "RPG", "products": 1}] and attrs["region"][0]["value"] == "Global"
    del ids


async def test_viewer_reads_but_cannot_change_pricing(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user("viewer@omggamer.com", role="viewer")
    await login(client, "viewer@omggamer.com")
    assert (await client.get("/api/v1/pricing/rules")).status_code == 200
    assert (await client.post("/api/v1/pricing/rules", json={"name": "x"})).status_code == 403
