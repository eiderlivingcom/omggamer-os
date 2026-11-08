"""Phase 9: order fulfilment. Kinguin and the store are in-process stand-ins following the documented APIs."""

import hashlib
import hmac
import json
import time
import uuid
from decimal import Decimal
from typing import Any

import httpx
import pytest
from httpx import AsyncClient
from sqlalchemy import select

from app.catalogue.services import CatalogueService
from app.core.security import decrypt_secret
from app.db.models import (
    DeliveredKey,
    Order,
    OrderEvent,
    OrderItem,
    Product,
    ProductVariant,
    Supplier,
    SupplierOrder,
)
from app.db.models.pricing import PricingRule
from app.db.session import get_engine, get_sessionmaker
from app.orders.fulfil import Fulfiller
from app.suppliers import registry
from app.suppliers.kinguin import client as kclient
from app.suppliers.kinguin.normalizer import normalize
from app.workers import celery_app as celery_mod
from tests.conftest import MakeUser, login
from tests.test_kinguin_import import KEY, FakeKinguin
from tests.test_kinguin_import import connect as connect_kinguin
from tests.test_publishing import FakeStore
from tests.test_publishing import connect as connect_store

RECORDS = [
    {
        "kinguinId": 501,
        "name": "Hades PC Steam CD Key",
        "originalName": "Hades",
        "platform": "PC Steam",
        "regionalLimitations": "Region free",
        "offers": [{"offerId": "off-501", "price": 10.0, "qty": 5, "merchantName": "S"}],
    },
    {
        "kinguinId": 502,
        "name": "Hades Switch Key",
        "originalName": "Hades",
        "platform": "Switch",
        "regionalLimitations": "Region free",
        "offers": [{"offerId": "off-502", "price": 15.0, "qty": 5, "merchantName": "S"}],
    },
]


class OrderingKinguin(FakeKinguin):
    def __init__(self) -> None:
        super().__init__(0)
        self.products = json.loads(json.dumps(RECORDS))
        self.orders: dict[str, dict[str, Any]] = {}
        self.posts = 0
        self.timeout_after_create = False
        self.balance_error = False
        self.deliver_keys = True

    def handler(self, request: httpx.Request) -> httpx.Response:
        path = request.url.path.removeprefix("/esa/api")
        if request.headers.get("X-Api-Key") != KEY:
            return httpx.Response(401, json={"kind": "Authorization"})
        if path == "/v1/order" and request.method == "POST":
            self.posts += 1
            body = json.loads(request.content)
            if self.balance_error:
                return httpx.Response(
                    400, json={"kind": "InsufficientBalance", "status": 400, "detail": "No funds"}
                )
            ref = body["orderExternalId"]
            if any(o["orderExternalId"] == ref for o in self.orders.values()):
                return httpx.Response(
                    400, json={"kind": "ConstraintViolation", "detail": "orderExternalId not unique"}
                )
            oid = f"K{len(self.orders) + 1:04d}"
            products = [
                {
                    "kinguinId": p["kinguinId"],
                    "offerId": p.get("offerId"),
                    "qty": p["qty"],
                    "price": p["price"],
                }
                for p in body["products"]
            ]
            self.orders[oid] = {
                "orderId": oid,
                "orderExternalId": ref,
                "status": "processing",
                "products": products,
                "totalPrice": sum(p["price"] * p["qty"] for p in products),
                "paymentPrice": 9.5,
            }
            if self.timeout_after_create:
                self.timeout_after_create = False
                raise httpx.ReadTimeout("gateway timeout after the order was created")
            return httpx.Response(201, json=self.orders[oid])
        if path == "/v1/order" and request.method == "GET":
            ref = request.url.params.get("orderExternalId")
            res = [o for o in self.orders.values() if o["orderExternalId"] == ref]
            return httpx.Response(200, json={"results": res, "item_count": len(res)})
        if path.startswith("/v1/order/"):
            return httpx.Response(200, json=self.orders[path.rsplit("/", 1)[1]])
        if path.startswith("/v2/order/") and path.endswith("/keys"):
            oid = path.split("/")[3]
            o = self.orders[oid]
            if not self.deliver_keys:
                return httpx.Response(200, json=[])
            o["status"] = "completed"
            keys = [
                {
                    "id": f"{oid}-{p['kinguinId']}-{n}",
                    "serial": f"SERIAL-{p['kinguinId']}-{n}",
                    "type": "text/plain",
                    "kinguinId": p["kinguinId"],
                    "offerId": p["offerId"],
                }
                for p in o["products"]
                for n in range(p["qty"])
            ]
            return httpx.Response(200, json=keys)
        if path.startswith("/v2/order/") and path.endswith("/keys/return"):
            return httpx.Response(200, json=[{"id": "k1", "status": "RETURNED"}])
        return super().handler(request)


class KeyStore(FakeStore):
    def __init__(self) -> None:
        super().__init__()
        self.delivered: dict[str, list[str]] = {}
        self.notes: list[dict[str, Any]] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        path = request.url.path
        if path.endswith("/orders/deliver") or path.endswith("/orders/note"):
            ts, sig = request.headers.get("X-OMG-Timestamp", ""), request.headers.get("X-OMG-Signature", "")
            expected = hmac.new(
                self.secret.encode(), f"{ts}.".encode() + request.content, hashlib.sha256
            ).hexdigest()
            if not hmac.compare_digest(sig, expected):
                return httpx.Response(401, json={"message": "bad"})
            body = json.loads(request.content)
            if path.endswith("/orders/note"):
                self.notes.append(body)
                return httpx.Response(200, json={"ok": True})
            for row in body["items"]:
                for k in row["keys"]:
                    if k["serial"] not in self.delivered.setdefault(row["item_id"], []):
                        self.delivered[row["item_id"]].append(k["serial"])
            return httpx.Response(200, json={"ok": True})
        return super().handler(request)


@pytest.fixture
def kinguin(monkeypatch: pytest.MonkeyPatch) -> OrderingKinguin:
    f = OrderingKinguin()
    monkeypatch.setattr(registry, "transport_override", httpx.MockTransport(f.handler))

    async def no_sleep(_: float) -> None:
        return None

    monkeypatch.setattr(kclient, "_sleep", no_sleep)
    return f


@pytest.fixture
def store(monkeypatch: pytest.MonkeyPatch) -> KeyStore:
    from app.publishing import connector

    s = KeyStore()
    monkeypatch.setattr(connector, "transport_override", httpx.MockTransport(s.handler))

    async def no_sleep(_: float) -> None:
        return None

    monkeypatch.setattr(connector, "_sleep", no_sleep)
    return s


@pytest.fixture(autouse=True)
def no_celery(monkeypatch: pytest.MonkeyPatch) -> list[str]:
    sent: list[str] = []
    monkeypatch.setattr(celery_mod.celery_app, "send_task", lambda name, *a, **k: sent.append(name))
    return sent


async def setup(client: AsyncClient, store: KeyStore) -> dict[str, uuid.UUID]:
    await connect_kinguin()
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        for r in RECORDS:
            await CatalogueService(db).ingest(k, normalize(r))
        db.add(
            PricingRule(
                name="All",
                scope="global",
                markup_percent=Decimal("20"),
                markup_fixed=Decimal("0"),
                rounding="cents_99",
            )
        )
        variants = {v.platform: v.id for v in (await db.execute(select(ProductVariant))).scalars()}
        product = (await db.execute(select(Product))).scalar_one()
        await db.commit()
    await connect_store(client, store)
    return {"product": product.id, **{p: v for p, v in variants.items() if p}}


def signed(store: KeyStore, payload: dict[str, Any]) -> tuple[bytes, dict[str, str]]:
    body = json.dumps(payload).encode()
    ts = int(time.time())
    sig = hmac.new(store.secret.encode(), f"{ts}.".encode() + body, hashlib.sha256).hexdigest()
    return body, {"X-OMG-Timestamp": str(ts), "X-OMG-Signature": sig, "content-type": "application/json"}


async def place(
    client: AsyncClient,
    store: KeyStore,
    ids: dict[str, uuid.UUID],
    order_id: str = "9001",
    steam_price: str = "25.98",
    switch_qty: int = 1,
) -> uuid.UUID:
    payload = {
        "order_id": order_id,
        "number": order_id,
        "status": "processing",
        "currency": "EUR",
        "total": "50.00",
        "items": [
            {
                "item_id": "11",
                "name": "Hades Steam",
                "variant_id": str(ids["Steam"]),
                "product_external_id": str(ids["product"]),
                "quantity": 2,
                "line_total": steam_price,
            },
            {
                "item_id": "12",
                "name": "Hades Switch",
                "variant_id": str(ids["Switch"]),
                "product_external_id": str(ids["product"]),
                "quantity": switch_qty,
                "line_total": "18.99",
            },
            {
                "item_id": "13",
                "name": "Gift wrap",
                "quantity": 1,
                "line_total": "1.00",
            },  # not an OMGGamer item
        ],
    }
    body, headers = signed(store, payload)
    jar = dict(client.cookies)
    client.cookies.clear()
    r = await client.post(
        "/api/v1/webhooks/storefronts/omggamer-staging/orders", content=body, headers=headers
    )
    client.cookies.update(jar)
    assert r.status_code == 200, r.text
    return uuid.UUID(r.json()["order_id"])


def fulfiller() -> Fulfiller:
    return Fulfiller(get_engine())


async def test_order_is_bought_once_keys_encrypted_and_delivered(
    client: AsyncClient, make_user: MakeUser, kinguin: OrderingKinguin, store: KeyStore
) -> None:
    await make_user()
    await login(client)
    ids = await setup(client, store)
    oid = await place(client, store, ids)
    assert await place(client, store, ids) == oid  # the store resending the webhook adds nothing

    assert (await fulfiller().run(oid)).state == "done"
    assert kinguin.posts == 1
    sent = next(iter(kinguin.orders.values()))
    assert sent["orderExternalId"].startswith("omg-") and {p["kinguinId"] for p in sent["products"]} == {
        501,
        502,
    }
    assert store.delivered == {"11": ["SERIAL-501-0", "SERIAL-501-1"], "12": ["SERIAL-502-0"]}
    async with get_sessionmaker()() as db:
        order = await db.get(Order, oid)
        items = {i.external_item_id: i for i in (await db.execute(select(OrderItem))).scalars()}
        keys = (await db.execute(select(DeliveredKey))).scalars().all()
        events = " ".join(e.message for e in (await db.execute(select(OrderEvent))).scalars())
    assert order is not None and order.status == "fulfilled" and set(items) == {"11", "12"}
    assert all(i.status == "delivered" for i in items.values())
    assert len(keys) == 3 and all("SERIAL" not in k.key_enc for k in keys)  # encrypted at rest
    assert sorted(decrypt_secret(k.key_enc) for k in keys)[0] == "SERIAL-501-0"
    detail = (await client.get(f"/api/v1/orders/{oid}")).json()
    assert "SERIAL" not in json.dumps(detail) and "SERIAL" not in events  # never exposed or logged
    assert (
        detail["items"][0]["keys"] in (1, 2) and detail["supplier_orders"][0]["external_order_id"] == "K0001"
    )
    assert any("all keys delivered" in n["note"] for n in store.notes)

    resend = (await client.post(f"/api/v1/orders/{oid}/resend-keys")).json()
    assert resend["keys"] == 3
    await fulfiller().run(oid)
    assert store.delivered["11"] == ["SERIAL-501-0", "SERIAL-501-1"]  # the store de-duplicates

    refund = (await client.post(f"/api/v1/orders/{oid}/refund", json={"note": "customer request"})).json()
    assert refund["status"] == "refunded" and refund["supplier_returns"][0]["keys"][0]["status"] == "RETURNED"


async def test_unknown_purchase_outcome_is_resolved_without_buying_twice(
    client: AsyncClient, make_user: MakeUser, kinguin: OrderingKinguin, store: KeyStore
) -> None:
    await make_user()
    await login(client)
    ids = await setup(client, store)
    oid = await place(client, store, ids)
    kinguin.timeout_after_create = True  # Kinguin creates the order but the answer never arrives
    out = await fulfiller().run(oid)
    assert out.state == "retry_later" and kinguin.posts == 1
    async with get_sessionmaker()() as db:
        so = (await db.execute(select(SupplierOrder))).scalar_one()
    assert so.status == "unknown"
    assert (await fulfiller().run(oid)).state == "done"
    assert kinguin.posts == 1 and len(kinguin.orders) == 1  # found by reference, not bought again
    async with get_sessionmaker()() as db:
        so = (await db.execute(select(SupplierOrder))).scalar_one()
        events = [e.event for e in (await db.execute(select(OrderEvent).order_by(OrderEvent.id))).scalars()]
    assert so.external_order_id == "K0001" and "purchase_found" in events


async def test_attention_cases_balance_loss_and_limits(
    client: AsyncClient, make_user: MakeUser, kinguin: OrderingKinguin, store: KeyStore
) -> None:
    await make_user()
    await login(client)
    ids = await setup(client, store)

    kinguin.balance_error = True
    oid = await place(client, store, ids)
    assert (await fulfiller().run(oid)).state == "attention"
    detail = (await client.get(f"/api/v1/orders/{oid}")).json()
    assert detail["status"] == "needs_attention" and "balance too low" in detail["last_error"]
    assert any(n.get("status") == "on-hold" for n in store.notes)
    kinguin.balance_error = False
    assert (await client.post(f"/api/v1/orders/{oid}/retry")).json()["status"] == "queued"
    assert (await fulfiller().run(oid)).state == "done"

    # Supplier price rises above what the customer paid: never bought automatically at a loss.
    kinguin.products[0]["offers"][0]["price"] = 30.0
    loss = await place(client, store, ids, order_id="9002")
    posts = kinguin.posts
    assert (await fulfiller().run(loss)).state == "attention"
    assert kinguin.posts == posts + 1  # only the Switch line is bought; the loss-making Steam line is held
    detail = (await client.get(f"/api/v1/orders/{loss}")).json()
    assert "above the sale price" in detail["last_error"]
    assert (
        await client.post(f"/api/v1/orders/{loss}/cancel", json={})
    ).status_code == 409  # Switch line was bought

    too_many = await place(client, store, ids, order_id="9003", switch_qty=12)
    await fulfiller().run(too_many)
    d = (await client.get(f"/api/v1/orders/{too_many}")).json()
    assert any("limit of 9" in (i["last_error"] or "") for i in d["items"])

    ov = (await client.get("/api/v1/orders/overview")).json()
    assert (
        ov["counts"]["fulfilled"] == 1
        and ov["counts"]["needs_attention"] + ov["counts"].get("partially_fulfilled", 0) >= 1
    )


async def test_waiting_for_keys_then_completes(
    client: AsyncClient, make_user: MakeUser, kinguin: OrderingKinguin, store: KeyStore
) -> None:
    await make_user()
    await login(client)
    ids = await setup(client, store)
    oid = await place(client, store, ids)
    kinguin.deliver_keys = False
    out = await fulfiller().run(oid)
    assert out.state == "waiting" and out.delay == 120
    async with get_sessionmaker()() as db:
        assert (await db.get(Order, oid)).status == "awaiting_keys"  # type: ignore[union-attr]
    kinguin.deliver_keys = True
    assert (await fulfiller().run(oid)).state == "done" and kinguin.posts == 1


async def test_order_webhook_rejects_bad_signature_and_viewer_cannot_manage(
    client: AsyncClient, make_user: MakeUser, store: KeyStore
) -> None:
    client.cookies.clear()
    r = await client.post(
        "/api/v1/webhooks/storefronts/omggamer-staging/orders",
        json={"order_id": "1", "items": []},
        headers={"X-OMG-Timestamp": str(int(time.time())), "X-OMG-Signature": "x"},
    )
    assert r.status_code == 401
    await make_user("viewer@omggamer.com", role="viewer")
    await login(client, "viewer@omggamer.com")
    assert (await client.get("/api/v1/orders")).status_code == 200
    assert (await client.put("/api/v1/orders/settings", json={"allow_loss": True})).status_code == 403
