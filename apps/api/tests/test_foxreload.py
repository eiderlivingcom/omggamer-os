"""Foxreload connector: normalising, cursor paging, Steam app ID matching with Kinguin, and the order flow."""

import json
from decimal import Decimal

import httpx
import pytest
from sqlalchemy import func, select

from app.catalogue.schemas import NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import Product, ProductVariant, Supplier, SupplierOffer
from app.db.session import get_sessionmaker
from app.suppliers import base as sbase
from app.suppliers.foxreload import client as fxclient
from app.suppliers.foxreload.adapter import FoxreloadAdapter
from app.suppliers.foxreload.client import FoxreloadClient
from app.suppliers.foxreload.normalizer import normalize, platform_label, region_label


def fx(
    pid: str,
    name: str,
    platform: str = "Steam",
    region: str | None = "WW",
    price: str | None = "12.50",
    qty: int = 5,
    steam: int | None = None,
    delivery: str = "code",
) -> dict:  # type: ignore[type-arg]
    attrs: dict = {"platform": platform}  # type: ignore[type-arg]
    if region:
        attrs["region"] = region
    if steam:
        attrs["steam_app_id"], attrs["steam_app_type"] = steam, "game"
    return {
        "id": pid,
        "slug": pid,
        "categoryId": "c1",
        "name": name,
        "description": None,
        "price": price,
        "currency": "usd",
        "quantity": qty,
        "attributes": attrs,
        "deliveryType": delivery,
        "isService": False,
        "updatedAt": "2026-11-15T00:00:00Z",
        "stockChangedAt": "2026-11-15T00:00:00Z",
        "userGuide": None,
    }


@pytest.fixture(autouse=True)
def _no_sleep(monkeypatch: pytest.MonkeyPatch) -> None:
    async def nap(_: float) -> None:
        return None

    monkeypatch.setattr(fxclient, "_sleep", nap)


def test_labels_match_existing_vocabulary() -> None:
    assert platform_label("steam", "x") == "Steam"
    assert platform_label("Rockstar Games Launcher", "GTA V") == "Rockstar Games"
    assert platform_label("Ubisoft Connect", "x") == "Ubisoft"
    assert platform_label("Xbox", "Star Wars Outlaws (Xbox Series X|S)") == "Xbox Series X|S"
    assert platform_label("Xbox", "Some Game (Xbox One)") == "Xbox One"
    assert region_label("WW", None) == "Global"
    assert region_label(None, "US") == "United States"
    assert region_label("EU", "EU") == "EUROPE"
    assert region_label("LATAM", None) == "Latin America (LATAM)"
    assert region_label(None, None) == "Global"


def test_normalize_edition_platform_region_offer() -> None:
    p = normalize(fx("p1", "Star Wars Outlaws | Gold Edition", "Ubisoft Connect", "EU", "17.30", 109))
    assert p.edition == "Gold Edition" and p.platform == "Ubisoft" and p.region == "EUROPE"
    assert p.activation_type == "key" and p.product_type == "game"
    o = p.offers[0]
    assert (
        o.currency == "USD"
        and o.price == Decimal("17.3000")
        and o.quantity == 109
        and o.availability == "in_stock"
    )
    sold_out = normalize(fx("p2", "Game", price=None, qty=0))
    assert sold_out.offers == [] and sold_out.offers_complete  # existing offer gets marked unavailable


def test_non_code_products_are_rejected() -> None:
    with pytest.raises(sbase.NormalizationError):
        normalize(fx("p3", "eSIM Europe 10GB", delivery="esim"))


async def test_cursor_paging_and_filtering() -> None:
    calls: list[dict] = []  # type: ignore[type-arg]

    def handler(req: httpx.Request) -> httpx.Response:
        if req.url.path == "/api/categories/games":
            return httpx.Response(200, json={"id": "cat-games", "slug": "games"})
        if req.url.path == "/api/products/":
            calls.append(dict(req.url.params))
            assert req.headers["X-API-Key"] == "k"
            if "cursor" not in req.url.params:
                return httpx.Response(
                    200,
                    json={
                        "items": [fx("a", "A"), fx("e", "eSIM", delivery="esim")],
                        "total": 3,
                        "limit": 2,
                        "nextCursor": "CUR1",
                    },
                )
            assert req.url.params["cursor"] == "CUR1"
            return httpx.Response(
                200, json={"items": [fx("b", "B")], "total": 3, "limit": 2, "nextCursor": None}
            )
        return httpx.Response(404, json={"detail": "nope"})

    ad = FoxreloadAdapter(FoxreloadClient("k", transport=httpx.MockTransport(handler), min_interval=0))
    p1 = await ad.fetch_page(1, 2)
    assert [r["id"] for r in p1.records] == ["a"] and p1.raw_meta["next_cursor"] == "CUR1"
    assert p1.raw_meta["non_object_results"] == 1 and p1.total == 3
    ad2 = FoxreloadAdapter(FoxreloadClient("k", transport=httpx.MockTransport(handler), min_interval=0))
    ad2.set_cursor("CUR1")  # resumed job: the runner hands back the saved cursor
    p2 = await ad2.fetch_page(2, 2)
    assert [r["id"] for r in p2.records] == ["b"] and p2.raw_meta["next_cursor"] is None
    assert calls[0]["categoryId"] == "cat-games" and calls[0]["includeDescendants"] == "true"
    await ad.aclose()
    await ad2.aclose()


async def test_same_steam_app_joins_existing_kinguin_product_and_variant() -> None:
    async with get_sessionmaker()() as db:
        kin = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        fox = (await db.execute(select(Supplier).where(Supplier.code == "foxreload"))).scalar_one()
        svc = CatalogueService(db)
        k = NormalizedProduct(
            external_product_id="111",
            original_title="Grand Theft Auto V Steam CD Key",
            base_title="Grand Theft Auto V",
            platform="Steam",
            region="Global",
            activation_type="key",
            variant_attributes={"steam_app_id": "271590"},
            offers=[
                NormalizedOffer(external_offer_id="o1", currency="EUR", price=Decimal("14.00"), quantity=3)
            ],
            raw_payload={"kinguinId": 111},
        )
        r1 = await svc.ingest(kin, k)
        # Different title at Foxreload, same Steam app: must land on the same product and variant.
        r2 = await svc.ingest(
            fox, normalize(fx("fx-gta", "GTA V [Global / Steam]", steam=271590, price="11.36"))
        )
        await db.commit()
        assert r2.product_id == r1.product_id and r2.variant_id == r1.variant_id
        assert await db.scalar(select(func.count()).select_from(Product)) == 1
        offers = (
            (await db.execute(select(SupplierOffer).where(SupplierOffer.variant_id == r1.variant_id)))
            .scalars()
            .all()
        )
        assert {o.currency for o in offers} == {"EUR", "USD"}
        v = await db.get(ProductVariant, r1.variant_id)
        assert v is not None and v.variant_attributes.get("steam_app_id") == "271590"


async def test_order_flow_create_pay_codes_and_idempotency() -> None:
    state = {"orders": {}, "paid": 0, "created": 0}

    def order_body(oid: str, status: str, codes: list[str]) -> dict:  # type: ignore[type-arg]
        return {
            "id": oid,
            "idempotencyKey": "REF-1",
            "price": "11.36",
            "currency": "usd",
            "status": status,
            "items": [
                {
                    "id": "it1",
                    "index": 0,
                    "product": {"id": "fx-gta", "name": "GTA V"},
                    "quantity": 1,
                    "fulfillmentStatus": "completed" if codes else "processing",
                    "externalData": codes,
                    "externalId": None,
                    "error": None,
                }
            ],
        }

    def handler(req: httpx.Request) -> httpx.Response:
        if req.method == "GET" and req.url.path == "/api/orders/":
            ref = req.url.params.get("idempotencyKey")
            found = [o for o in state["orders"].values() if o["idempotencyKey"] == ref]
            return httpx.Response(200, json={"items": found, "limit": 1, "offset": 0, "total": len(found)})
        if req.method == "POST" and req.url.path == "/api/orders/":
            body = json.loads(req.content)
            assert body["idempotencyKey"] == "REF-1" and body["items"][0] == {
                "itemId": "fx-gta",
                "quantity": 1,
                "totalPrice": "11.36",
            }
            state["created"] += 1
            state["orders"]["ord1"] = order_body("ord1", "active", [])
            return httpx.Response(201, json=state["orders"]["ord1"])
        if req.method == "POST" and req.url.path == "/api/orders/ord1/pay":
            state["paid"] += 1
            state["orders"]["ord1"] = order_body("ord1", "completed", ["AAAA-BBBB-CCCC"])
            return httpx.Response(
                200,
                json={"id": "pay1", "confirmationToken": None, "confirmationUrl": None, "returnUrl": None},
            )
        if req.method == "GET" and req.url.path == "/api/orders/ord1":
            return httpx.Response(200, json=state["orders"]["ord1"])
        return httpx.Response(404, json={"detail": "not found"})

    ad = FoxreloadAdapter(FoxreloadClient("k", transport=httpx.MockTransport(handler), min_interval=0))
    res = await ad.purchase([{"external_product_id": "fx-gta", "qty": 1, "price": "11.36"}], "REF-1")
    assert res["orderId"] == "ord1" and res["status"] == "completed"
    keys = await ad.download_keys("ord1")
    assert keys == [
        {"id": "it1:0", "serial": "AAAA-BBBB-CCCC", "type": "text/plain", "external_product_id": "fx-gta"}
    ]
    # A retry with the same reference finds the paid order instead of buying again.
    again = await ad.purchase([{"external_product_id": "fx-gta", "qty": 1, "price": "11.36"}], "REF-1")
    assert again["orderId"] == "ord1" and state["created"] == 1 and state["paid"] == 1
    await ad.aclose()


async def test_low_balance_is_reported_as_insufficient_balance() -> None:
    def handler(req: httpx.Request) -> httpx.Response:
        if req.method == "GET" and req.url.path == "/api/orders/":
            return httpx.Response(200, json={"items": [], "limit": 1, "offset": 0, "total": 0})
        if req.method == "POST" and req.url.path == "/api/orders/":
            return httpx.Response(
                201, json={"id": "o9", "status": "active", "items": [], "price": "5", "currency": "usd"}
            )
        if req.url.path == "/api/orders/o9/pay":
            return httpx.Response(400, json={"detail": "Insufficient balance"})
        return httpx.Response(404, json={"detail": "x"})

    ad = FoxreloadAdapter(FoxreloadClient("k", transport=httpx.MockTransport(handler), min_interval=0))
    with pytest.raises(sbase.SupplierRequestError) as exc:
        await ad.purchase([{"external_product_id": "x", "qty": 1, "price": "5"}], "REF-9")
    assert exc.value.detail["kind"] == "InsufficientBalance"
    await ad.aclose()
