"""Phase 8: price/stock push, Kinguin webhook refresh, availability before checkout and the sync schedule."""

import hashlib
import hmac
import json
import time
import uuid
from datetime import UTC, datetime, timedelta
from decimal import Decimal
from typing import Any

import httpx
import pytest
from httpx import AsyncClient
from sqlalchemy import select

from app.db.models import (
    ProductVariant,
    Storefront,
    Supplier,
    SupplierOffer,
    SyncLog,
    WebhookInbox,
)
from app.db.models.pricing import VariantPrice
from app.db.session import get_sessionmaker
from app.suppliers import registry
from app.sync import service
from app.workers import celery_app as celery_mod
from tests.conftest import MakeUser, login
from tests.test_kinguin_import import KEY as KINGUIN_KEY
from tests.test_kinguin_import import FakeKinguin
from tests.test_kinguin_import import connect as connect_kinguin
from tests.test_publishing import FakeStore, connect, seed


class PriceStore(FakeStore):
    def __init__(self) -> None:
        super().__init__()
        self.prices: dict[str, dict[str, Any]] = {}

    def handler(self, request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith("/products/prices"):
            ts, sig = request.headers.get("X-OMG-Timestamp", ""), request.headers.get("X-OMG-Signature", "")
            expected = hmac.new(
                self.secret.encode(), f"{ts}.".encode() + request.content, hashlib.sha256
            ).hexdigest()
            if not hmac.compare_digest(sig, expected):
                return httpx.Response(401, json={"message": "bad"})
            items = json.loads(request.content)["items"]
            for i in items:
                self.prices[i["variant_id"]] = i
            return httpx.Response(
                200,
                json={"ok": True, "results": [{"variant_id": i["variant_id"], "ok": True} for i in items]},
            )
        return super().handler(request)


@pytest.fixture
def store(monkeypatch: pytest.MonkeyPatch) -> PriceStore:
    from app.publishing import connector

    s = PriceStore()
    monkeypatch.setattr(connector, "transport_override", httpx.MockTransport(s.handler))

    async def no_sleep(_: float) -> None:
        return None

    monkeypatch.setattr(connector, "_sleep", no_sleep)
    return s


@pytest.fixture
def sent(monkeypatch: pytest.MonkeyPatch) -> list[tuple[str, Any]]:
    calls: list[tuple[str, Any]] = []
    monkeypatch.setattr(
        celery_mod.celery_app,
        "send_task",
        lambda name, args=None, kwargs=None, **kw: calls.append((name, args)),
    )
    from app.publishing import queue as publish_queue

    monkeypatch.setattr(
        publish_queue, "enqueue", lambda ids, countdown=0: calls.append(("publish.run", list(ids)))
    )
    return calls


async def publish(client: AsyncClient, store: PriceStore, sent: list[tuple[str, Any]]) -> uuid.UUID:
    from app.db.session import get_engine
    from app.publishing.runner import Publisher

    pid = await seed()
    await connect(client, store)
    await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
    )
    item = next(a for n, a in sent if n == "publish.run")[0]
    assert (await Publisher(get_engine()).run(item)).state == "done"
    return pid


async def staging() -> Storefront:
    async with get_sessionmaker()() as db:
        return (
            await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))
        ).scalar_one()


async def test_only_changed_prices_and_stock_are_pushed(
    client: AsyncClient, make_user: MakeUser, store: PriceStore, sent: list[tuple[str, Any]]
) -> None:
    await make_user()
    await login(client)
    await publish(client, store, sent)
    async with get_sessionmaker()() as db:
        vps = (await db.execute(select(VariantPrice))).scalars().all()
        assert all(v.pushed_price == v.selling_price and v.pushed_in_stock for v in vps)  # set by the publish
        sf = await db.get(Storefront, (await staging()).id)
        assert sf is not None
        stats = await service.push_prices(db, sf, since=datetime.now(UTC) - timedelta(minutes=5))
    assert stats["changed"] == 0 and store.prices == {}

    since = datetime.now(UTC)
    async with get_sessionmaker()() as db:  # Steam offer gets cheaper, Switch sells out
        offers = {o.external_offer_id: o for o in (await db.execute(select(SupplierOffer))).scalars()}
        offers["o7"].supplier_price = Decimal("5.00")
        offers["o8"].available_quantity, offers["o8"].availability_status = 0, "out_of_stock"
        await db.commit()
        sf = await db.get(Storefront, (await staging()).id)
        stats = await service.push_prices(db, sf, since=since)  # type: ignore[arg-type]
        await db.commit()
    assert stats == {"checked": 2, "changed": 2, "pushed": 2, "failed": 0}
    pushed = sorted(((v["regular_price"], v["in_stock"]) for v in store.prices.values()), key=str)
    assert pushed == [("6.99", True), (None, False)]
    async with get_sessionmaker()() as db:
        logs = (await db.execute(select(SyncLog).where(SyncLog.operation == "price"))).scalars().all()
        assert logs[-1].status == "success" and logs[-1].response["ok"] == 2
        sf = await db.get(Storefront, (await staging()).id)
        again = await service.push_prices(db, sf, since=since)  # type: ignore[arg-type]
    assert again["changed"] == 0  # nothing new to send
    ov = (await client.get("/api/v1/sync/overview")).json()
    assert ov["pending_changes"] == 0 and ov["recent"][0]["status"] == "success"


async def test_availability_check_for_checkout(
    client: AsyncClient, make_user: MakeUser, store: PriceStore, sent: list[tuple[str, Any]]
) -> None:
    await make_user()
    await login(client)
    await publish(client, store, sent)
    async with get_sessionmaker()() as db:
        variants = {v.platform: v.id for v in (await db.execute(select(ProductVariant))).scalars()}
        o = (
            await db.execute(select(SupplierOffer).where(SupplierOffer.external_offer_id == "o8"))
        ).scalar_one()
        o.available_quantity, o.availability_status = 0, "out_of_stock"
        await db.commit()

    body = json.dumps(
        {
            "items": [
                {"variant_id": str(variants["Steam"])},
                {"variant_id": str(variants["Switch"])},
                {"variant_id": str(uuid.uuid4())},
            ]
        }
    ).encode()

    def signed(secret: str, ts: int | None = None) -> dict[str, str]:
        t = ts or int(time.time())
        return {
            "X-OMG-Timestamp": str(t),
            "X-OMG-Signature": hmac.new(secret.encode(), f"{t}.".encode() + body, hashlib.sha256).hexdigest(),
            "content-type": "application/json",
        }

    url = "/api/v1/webhooks/storefronts/omggamer-staging/availability?live=false"
    client.cookies.clear()  # server-to-server: no session, no browser origin
    assert (await client.post(url, content=body, headers=signed("wrong"))).status_code == 401
    assert (
        await client.post(url, content=body, headers=signed(store.secret, int(time.time()) - 900))
    ).status_code == 401
    r = await client.post(url, content=body, headers=signed(store.secret))
    assert r.status_code == 200, r.text
    items = {i["variant_id"]: i for i in r.json()["items"]}
    assert (
        items[str(variants["Steam"])]["available"] is True
        and items[str(variants["Steam"])]["price"] == "12.99"
    )
    assert items[str(variants["Switch"])]["available"] is False
    assert items[str(variants["Switch"])]["reason"] == "no available offer"
    assert [i for i in items.values() if i["reason"] == "unknown variant"]


async def test_kinguin_webhook_refreshes_offers(
    client: AsyncClient, make_user: MakeUser, sent: list[tuple[str, Any]], monkeypatch: pytest.MonkeyPatch
) -> None:
    from app.suppliers.kinguin import client as kclient

    await make_user()
    await login(client)
    fake = FakeKinguin(3)
    monkeypatch.setattr(registry, "transport_override", httpx.MockTransport(fake.handler))

    async def no_sleep(_: float) -> None:
        return None

    monkeypatch.setattr(kclient, "_sleep", no_sleep)
    await connect_kinguin(KINGUIN_KEY)
    secret = (await client.post("/api/v1/suppliers/kinguin/webhook-secret")).json()["secret"]
    client.cookies.clear()
    payload = {
        "kinguinId": 100001,
        "productId": "pid00000001",
        "qty": 0,
        "updatedAt": "2026-11-08T10:00:00+00:00",
    }
    bad = await client.post(
        "/api/v1/webhooks/kinguin",
        json=payload,
        headers={"X-Event-Name": "product.update", "X-Event-Secret": "nope"},
    )
    assert bad.status_code == 401
    hdrs = {"X-Event-Name": "product.update", "X-Event-Secret": secret}
    r = await client.post("/api/v1/webhooks/kinguin", json=payload, headers=hdrs)
    assert r.json() == {"ok": True, "duplicate": False}
    assert (await client.post("/api/v1/webhooks/kinguin", json=payload, headers=hdrs)).json()[
        "duplicate"
    ] is True
    inbox_id = next(a for n, a in sent if n == "sync.refresh_product")[0]

    from app.workers.tasks import _refresh

    assert await _refresh(inbox_id) == "processed"  # first sighting: product imported
    async with get_sessionmaker()() as db:
        row = await db.get(WebhookInbox, uuid.UUID(inbox_id))
        assert row is not None and row.state == "processed"
        assert len((await db.execute(select(SupplierOffer))).scalars().all()) == 2

    fake.products = [
        p for p in fake.products if p["kinguinId"] != 100001
    ]  # now out of stock at Kinguin (404)
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        await service.refresh_product(db, k, "100001")
        await db.commit()
        offers = (await db.execute(select(SupplierOffer))).scalars().all()
    assert all(o.available_quantity == 0 and o.availability_status == "unavailable" for o in offers)


async def test_sync_settings_and_schedule(
    client: AsyncClient, make_user: MakeUser, sent: list[tuple[str, Any]], monkeypatch: pytest.MonkeyPatch
) -> None:
    await make_user()
    await login(client)
    assert (await client.put("/api/v1/sync/settings", json={"cron": "every hour"})).status_code == 422
    s = (await client.put("/api/v1/sync/settings", json={"enabled": True, "interval_minutes": 15})).json()
    assert s["enabled"] and s["interval_minutes"] == 15

    from app.workers import tasks

    started: list[str] = []
    monkeypatch.setattr(tasks.sync_run, "apply_async", lambda *a, **k: started.append("run"))
    monkeypatch.setattr(tasks.sync_push, "apply_async", lambda *a, **k: started.append("push"))
    assert await tasks._sync_tick() == "started"
    assert await tasks._sync_tick() in ("not due", "started")  # same minute: not due again
    assert started.count("run") == 1
    async with get_sessionmaker()() as db:
        await service.save_settings(
            db, {"last_run_at": (datetime.now(UTC) - timedelta(minutes=16)).isoformat()}
        )
        await db.commit()
    await tasks._sync_tick()
    assert started.count("run") == 2
