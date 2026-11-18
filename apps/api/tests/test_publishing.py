"""Phase 7: publishing to WooCommerce through the Catalogue Connector.

The store is replaced by an in-process stand-in that verifies the HMAC signature and behaves like the
plugin (upsert keyed on external_id, idempotency keys, status changes). Test scaffolding only.
"""

import hashlib
import hmac
import io
import json
import uuid
import zipfile
from decimal import Decimal
from typing import Any

import httpx
import pytest
from httpx import AsyncClient
from sqlalchemy import select

from app.catalogue.schemas import NormalizedMedia, NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import (
    Product,
    ProductContent,
    PublishingQueueItem,
    StorefrontProductMapping,
    Supplier,
    SyncLog,
)
from app.db.models.pricing import PricingRule
from app.db.session import get_engine, get_sessionmaker
from app.publishing import connector
from app.publishing import queue as publish_queue
from app.publishing.runner import Publisher
from tests.conftest import MakeUser, login


class FakeStore:
    def __init__(self) -> None:
        self.secret = ""
        self.products: dict[str, dict[str, Any]] = {}
        self.next_id = 100
        self.idem: dict[str, dict[str, Any]] = {}
        self.calls: list[tuple[str, dict[str, Any]]] = []
        self.fail_next: list[int] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        ts, sig = request.headers.get("X-OMG-Timestamp", ""), request.headers.get("X-OMG-Signature", "")
        expected = hmac.new(
            self.secret.encode(), f"{ts}.".encode() + request.content, hashlib.sha256
        ).hexdigest()
        if not self.secret or not hmac.compare_digest(sig, expected):
            return httpx.Response(401, json={"code": "omg_bad_signature", "message": "Invalid signature."})
        if self.fail_next:
            return httpx.Response(self.fail_next.pop(0), json={"code": "omg_failed", "message": "boom"})
        path = request.url.path.split("/omggamer-connector/v1")[1]
        body = json.loads(request.content) if request.content else {}
        self.calls.append((path, body))
        key = request.headers.get("X-OMG-Idempotency-Key")
        if key and key in self.idem:
            return httpx.Response(200, json={**self.idem[key], "idempotent_replay": True})
        if path == "/status":
            return httpx.Response(
                200,
                json={
                    "ok": True,
                    "plugin_version": "1.0.0",
                    "woocommerce": "9.4",
                    "dokan": "3.12",
                    "currency": "EUR",
                },
            )
        if path == "/products/upsert":
            ext = body["external_id"]
            created = ext not in self.products
            if created:
                self.products[ext] = {"id": self.next_id}
                self.next_id += 1
            self.products[ext].update(body=body, status=body["status"])
            res = {
                "ok": True,
                "product_id": self.products[ext]["id"],
                "created": created,
                "status": body["status"],
                "variations": {
                    v["variant_id"]: self.products[ext]["id"] * 10 + i for i, v in enumerate(body["variants"])
                },
            }
        elif path == "/products/prices":
            res = {
                "ok": True,
                "results": [{"variant_id": i["variant_id"], "ok": True} for i in body["items"]],
            }
        elif path == "/products/status":
            self.products[body["external_id"]]["status"] = body["status"]
            res = {"ok": True, "status": body["status"]}
        else:
            return httpx.Response(404, json={"code": "rest_no_route"})
        if key:
            self.idem[key] = res
        return httpx.Response(200, json=res)


@pytest.fixture
def store(monkeypatch: pytest.MonkeyPatch) -> FakeStore:
    s = FakeStore()
    monkeypatch.setattr(connector, "transport_override", httpx.MockTransport(s.handler))

    async def no_sleep(_: float) -> None:
        return None

    monkeypatch.setattr(connector, "_sleep", no_sleep)
    return s


@pytest.fixture
def queued(monkeypatch: pytest.MonkeyPatch) -> list[uuid.UUID]:
    ids: list[uuid.UUID] = []
    monkeypatch.setattr(publish_queue, "enqueue", lambda item_ids, countdown=0: ids.extend(item_ids))
    return ids


async def seed(approved: bool = True) -> uuid.UUID:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        svc = CatalogueService(db)
        pid = None
        for ext, title, platform, price in [
            ("7", "Hades PC Steam Key", "Steam", "10.00"),
            ("8", "Hades Switch Key", "Switch", "15.00"),
        ]:
            r = await svc.ingest(
                k,
                NormalizedProduct(
                    external_product_id=ext,
                    original_title=title,
                    base_title="Hades",
                    platform=platform,
                    region="Global",
                    developer="Supergiant Games",
                    media=[NormalizedMedia(url=f"https://cdn.example/{ext}.jpg", media_type="cover")],
                    offers=[
                        NormalizedOffer(
                            external_offer_id=f"o{ext}", currency="EUR", price=Decimal(price), quantity=3
                        )
                    ],
                ),
            )
            pid = r.product_id
        assert pid
        db.add(
            PricingRule(
                name="All",
                scope="global",
                markup_percent=Decimal("20"),
                markup_fixed=Decimal("0"),
                rounding="cents_99",
            )
        )
        db.add(
            ProductContent(
                product_id=pid,
                title="Hades - Digital Key",
                short_description="Escape the Underworld.",
                long_description="Para one.\n\nPara two.",
                meta_title="Hades Key",
                meta_description="Buy Hades",
                tags=["roguelike"],
                content_status="approved" if approved else "pending_review",
            )
        )
        p = await db.get(Product, pid)
        assert p is not None
        p.product_status = "approved" if approved else "review"
        await db.commit()
        return pid


async def connect(client: AsyncClient, store: FakeStore) -> None:
    r = await client.post("/api/v1/storefronts/omggamer-staging/connector/secret")
    assert r.status_code == 200
    store.secret = r.json()["secret"]
    t = (await client.post("/api/v1/storefronts/omggamer-staging/connector/test")).json()
    assert t["status"] == "connected" and t["plugin_version"] == "1.0.0" and t["dokan"] == "3.12"
    assert (
        await client.put("/api/v1/storefronts/omggamer-staging/connector", json={"enabled": True})
    ).json()["enabled"]


def publisher() -> Publisher:
    return Publisher(get_engine())


async def test_publish_create_update_and_no_duplicates(
    client: AsyncClient, make_user: MakeUser, store: FakeStore, queued: list[uuid.UUID]
) -> None:
    await make_user()
    await login(client)
    pid = await seed()
    await connect(client, store)
    r = (
        await client.post(
            "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
        )
    ).json()
    assert r["changed"] == 1
    assert (await publisher().run(queued[0])).state == "done"

    body = store.products[str(pid)]["body"]
    assert body["name"] == "Hades - Digital Key" and body["status"] == "publish" and body["slug"] == "hades"
    assert body["description"] == "<p>Para one.</p><p>Para two.</p>"
    prices = sorted(
        (v["attributes"]["Platform"], v["regular_price"], v["in_stock"]) for v in body["variants"]
    )
    assert prices == [("Steam", "12.99", True), ("Switch", "18.99", True)]
    assert body["seo"] == {"title": "Hades Key", "description": "Buy Hades"} and body["images"][0][
        "url"
    ].endswith(".jpg")
    assert {"name": "Developer", "options": ["Supergiant Games"]} in body["attributes"]

    async with get_sessionmaker()() as db:
        m = (await db.execute(select(StorefrontProductMapping))).scalar_one()
        p = await db.get(Product, pid)
    assert m.external_product_id == "100" and m.sync_status == "synced" and len(m.variant_map) == 2
    assert p is not None and p.product_status == "published"

    # Publishing again without changes sends nothing; after a change it updates the same WooCommerce product.
    await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
    )
    assert (await publisher().run(queued[1])).state == "skipped"
    async with get_sessionmaker()() as db:
        c = (await db.execute(select(ProductContent))).scalar_one()
        c.short_description = "New short text."
        await db.commit()
    await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
    )
    assert (await publisher().run(queued[2])).state == "done"
    assert (
        len(store.products) == 1
        and store.products[str(pid)]["body"]["short_description"] == "New short text."
    )
    upserts = [c for c in store.calls if c[0] == "/products/upsert"]
    assert len(upserts) == 2

    hist = (await client.get(f"/api/v1/catalogue/products/{pid}/publishing")).json()
    assert hist["mappings"][0]["external_product_id"] == "100"
    assert {e["status"] for e in hist["events"] if e["kind"] == "sync"} == {"success", "skipped"}

    un = (await client.post("/api/v1/publishing/unpublish", json={"product_ids": [str(pid)]})).json()
    assert un["queued"] == 1
    assert (await publisher().run(queued[-1])).state == "done"
    async with get_sessionmaker()() as db:
        p = await db.get(Product, pid)
        m = (await db.execute(select(StorefrontProductMapping))).scalar_one()
    assert store.products[str(pid)]["status"] == "draft" and m.sync_status == "unpublished"
    assert p is not None and p.product_status == "unpublished"


async def test_failures_retry_then_fail_and_can_be_retried(
    client: AsyncClient, make_user: MakeUser, store: FakeStore, queued: list[uuid.UUID]
) -> None:
    await make_user()
    await login(client)
    pid = await seed()
    await connect(client, store)
    await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
    )
    item = queued[0]
    store.fail_next = [500, 502, 503]  # one publish attempt = 3 HTTP tries
    out = await publisher().run(item)
    assert out.state == "retry_later" and out.delay == 30
    async with get_sessionmaker()() as db:
        qi = await db.get(PublishingQueueItem, item)
        logs = (await db.execute(select(SyncLog))).scalars().all()
    assert qi is not None and qi.status == "queued" and "503" in (qi.last_error or "")
    assert logs[-1].status == "failed" and logs[-1].response["final"] is False

    store.secret = "rotated-on-the-store"  # signature now wrong: permanent failure
    assert (await publisher().run(item)).state == "failed"
    ov = (await client.get("/api/v1/publishing/overview")).json()
    assert ov["queue"]["failed"] == 1 and ov["listings"]["failed"] == 1
    q = (await client.get("/api/v1/publishing/queue", params={"status": "failed"})).json()
    assert "refused the signature" in q[0]["last_error"] and q[0]["product_title"] == "Hades"

    r = await client.post("/api/v1/storefronts/omggamer-staging/connector/secret")
    store.secret = r.json()["secret"]
    assert (await client.post(f"/api/v1/publishing/queue/{item}/retry")).json()["status"] == "queued"
    assert (await publisher().run(item)).state == "done" and len(store.products) == 1


async def test_guards(
    client: AsyncClient, make_user: MakeUser, store: FakeStore, queued: list[uuid.UUID]
) -> None:
    await make_user()
    await login(client)
    pid = await seed()
    await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
    )
    assert (await publisher().run(queued[0])).state == "failed"  # storefront not enabled
    async with get_sessionmaker()() as db:
        assert "switched off" in ((await db.get(PublishingQueueItem, queued[0])).last_error or "")  # type: ignore[union-attr]
    enable = await client.put("/api/v1/storefronts/omggamer-staging/connector", json={"enabled": True})
    assert enable.status_code == 409  # no secret yet
    await connect(client, store)
    async with get_sessionmaker()() as db:  # no pricing rule → nothing to sell
        for r in (await db.execute(select(PricingRule))).scalars():
            await db.delete(r)
        await db.commit()
    assert (await client.post(f"/api/v1/publishing/queue/{queued[0]}/retry")).status_code == 200
    assert (await publisher().run(queued[0])).state == "failed"
    async with get_sessionmaker()() as db:
        assert "pricing rule" in ((await db.get(PublishingQueueItem, queued[0])).last_error or "")  # type: ignore[union-attr]
    assert store.products == {}
    bad = await client.put(
        "/api/v1/storefronts/omggamer-staging/connector", json={"connector_url": "http://x"}
    )
    assert bad.status_code == 422


async def test_plugin_zip_is_public_and_complete(client: AsyncClient) -> None:
    r = await client.get("/api/v1/connector/plugin.zip")
    assert r.status_code == 200 and r.headers["content-type"] == "application/zip"
    names = zipfile.ZipFile(io.BytesIO(r.content)).namelist()
    assert "omggamer-catalogue-connector/omggamer-catalogue-connector.php" in names
    assert "omggamer-catalogue-connector/includes/class-products.php" in names


async def test_secret_requires_super_admin(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user("cat@omggamer.com", role="catalogue_manager")
    await login(client, "cat@omggamer.com")
    assert (await client.post("/api/v1/storefronts/omggamer-staging/connector/secret")).status_code == 403
    assert (await client.get("/api/v1/storefronts/omggamer-staging/connector")).json()["secret_set"] is False


async def test_one_listing_per_platform(
    client: AsyncClient, make_user: MakeUser, store: FakeStore, queued: list[uuid.UUID]
) -> None:
    await make_user()
    await login(client)
    pid = await seed()
    await connect(client, store)

    # Published as one listing first (the game is not selected for splitting yet).
    await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
    )
    assert (await publisher().run(queued[-1])).state == "done"
    assert set(store.products) == {str(pid)} and len(store.products[str(pid)]["body"]["variants"]) == 2

    info = (await client.get(f"/api/v1/publishing/products/{pid}/listings")).json()
    assert info["mode"] == "selected" and info["selected"] is False
    assert info["listings"] == [{"listing": "main", "platform": None}]

    # Turning it on re-publishes straight away: Steam keeps the original listing, Switch gets its own.
    r = (await client.put(f"/api/v1/publishing/products/{pid}/split", json={"enabled": True})).json()
    assert r == {"enabled": True, "queued": True}
    assert (await publisher().run(queued[-1])).state == "done"
    main, switch = store.products[str(pid)]["body"], store.products[f"{pid}~switch"]["body"]
    assert main["slug"] == "hades" and main["prune_variations"] is True
    assert [v["attributes"]["Platform"] for v in main["variants"]] == ["Steam"]
    assert [v["attributes"]["Platform"] for v in switch["variants"]] == ["Switch"]
    assert switch["slug"] == "hades-switch" and switch["name"].endswith("(Switch)")
    assert switch["sku"].endswith("-NSW") and switch["meta"]["listing_group"] == main["meta"]["listing_group"]
    async with get_sessionmaker()() as db:
        maps = {m.listing: m for m in (await db.execute(select(StorefrontProductMapping))).scalars()}
    assert set(maps) == {"", "switch"} and len(maps["switch"].variant_map) == 1

    # Price updates go to the listing that holds the variant.
    from app.db.models import Storefront
    from app.sync.service import push_prices

    async with get_sessionmaker()() as db:
        sf = (await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))).scalar_one()
        assert sf is not None
        from sqlalchemy import update as sa_update

        from app.db.models.pricing import VariantPrice

        await db.execute(sa_update(VariantPrice).values(pushed_at=None))
        await push_prices(db, sf, since=None, full=True)
        await db.commit()
    price_calls = [c for c in store.calls if c[0] == "/products/prices"]
    assert price_calls
    ext = {i["external_id"] for _, b in price_calls for i in b["items"]}
    assert ext == {str(pid), f"{pid}~switch"}

    # Turning it off folds the game back into one listing and drafts the Switch one.
    r = (await client.put(f"/api/v1/publishing/products/{pid}/split", json={"enabled": False})).json()
    assert r["queued"] is True
    assert (await publisher().run(queued[-1])).state == "done"
    assert store.products[f"{pid}~switch"]["status"] == "draft"
    assert len(store.products[str(pid)]["body"]["variants"]) == 2
    assert store.products[str(pid)]["body"]["prune_variations"] is True

    # Unpublishing takes every listing off.
    await client.put(f"/api/v1/publishing/products/{pid}/split", json={"enabled": True})
    assert (await publisher().run(queued[-1])).state == "done"
    assert store.products[f"{pid}~switch"]["status"] == "publish"
    await client.post("/api/v1/publishing/unpublish", json={"product_ids": [str(pid)]})
    assert (await publisher().run(queued[-1])).state == "done"
    assert {p["status"] for p in store.products.values()} == {"draft"}


async def test_orders_and_facts_accept_listing_ids() -> None:
    from app.sync.facts import find_product

    pid = await seed()
    async with get_sessionmaker()() as db:
        p = await find_product(db, f"{pid}~switch", None)
    assert p is not None and p.id == pid


async def test_new_option_on_live_game_is_republished(
    client: AsyncClient, make_user: MakeUser, store: FakeStore, queued: list[uuid.UUID]
) -> None:
    from datetime import timedelta

    from sqlalchemy import update as sa_update

    from app.db.models import Storefront
    from app.sync.service import queue_new_options

    await make_user()
    await login(client)
    pid = await seed()
    await connect(client, store)
    await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
    )
    assert (await publisher().run(queued[-1])).state == "done"
    async with get_sessionmaker()() as db:
        sf = (await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))).scalar_one()
        assert sf is not None
        assert await queue_new_options(db, sf) == []  # everything sellable is on the store
        # A supplier option the store does not have yet (as if it arrived after the game went live).
        m = (await db.execute(select(StorefrontProductMapping))).scalar_one()
        m.variant_map = dict(list(m.variant_map.items())[:1])
        # Not re-checked within 6 hours of the last publish...
        await db.flush()
        assert await queue_new_options(db, sf) == []
        await db.execute(
            sa_update(PublishingQueueItem).values(
                created_at=PublishingQueueItem.created_at - timedelta(hours=7)
            )
        )
        ids = await queue_new_options(db, sf)
        await db.commit()
    assert len(ids) == 1
    assert (await publisher().run(ids[0])).state == "done"
    async with get_sessionmaker()() as db:
        m = (await db.execute(select(StorefrontProductMapping))).scalar_one()
    assert len(m.variant_map) == 2


async def test_gift_is_its_own_type_and_access_is_not_sold(
    client: AsyncClient, make_user: MakeUser, store: FakeStore, queued: list[uuid.UUID]
) -> None:
    await make_user()
    await login(client)
    pid = await seed()
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        svc = CatalogueService(db)
        for ext, title, platform, act in [
            ("9", "Hades PC Steam Altergift", "Steam", "gift"),
            ("10", "Hades Switch Access", "Switch", "access"),
        ]:
            await svc.ingest(
                k,
                NormalizedProduct(
                    external_product_id=ext,
                    original_title=title,
                    base_title="Hades",
                    platform=platform,
                    region="Global",
                    activation_type=act,
                    offers=[
                        NormalizedOffer(
                            external_offer_id=f"o{ext}", currency="EUR", price=Decimal("9"), quantity=3
                        )
                    ],
                ),
            )
        await db.commit()
    await connect(client, store)
    await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
    )
    assert (await publisher().run(queued[-1])).state == "done"
    opts = sorted(
        (v["attributes"]["Platform"], v["attributes"]["Type"])
        for v in store.products[str(pid)]["body"]["variants"]
    )
    assert opts == [("Steam", "Gift"), ("Steam", "Key"), ("Switch", "Key")]
