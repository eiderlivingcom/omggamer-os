"""Kinguin importer: client retry rules, normalisation, paging, checkpoints, resume, failures and the API.

The Kinguin API is replaced by an in-process httpx.MockTransport that follows the documented request and
response shapes (page/limit, results/item_count, X-Api-Key, 404 for unavailable products). It is test
scaffolding only; production code always talks to the real gateway.
"""

import json
import uuid
from datetime import UTC, datetime, timedelta
from typing import Any

import httpx
import pytest
from httpx import AsyncClient
from sqlalchemy import func, select, update

from app.catalogue.services import CatalogueService
from app.core.security import decrypt_secret, encrypt_secret
from app.db.models import (
    ImportFailure,
    ImportJob,
    ImportLog,
    Product,
    ProductVariant,
    Supplier,
    SupplierConnection,
    SupplierOffer,
    SupplierProduct,
)
from app.db.session import get_engine, get_sessionmaker
from app.imports import queue
from app.imports.runner import ImportRunner
from app.suppliers import registry
from app.suppliers.base import SupplierAuthError, SupplierNotFound, SupplierTemporaryError
from app.suppliers.kinguin import client as kclient
from app.suppliers.kinguin.client import KinguinClient, retry_after_seconds
from app.suppliers.kinguin.normalizer import normalize
from tests.conftest import MakeUser, login

KEY = "good-test-key-123"
PLATFORMS = ["PC Steam", "PC Epic Games", "PC GOG"]
BASE_TIME = datetime(2026, 1, 1, tzinfo=UTC)

DOC_EXAMPLE: dict[str, Any] = {  # the "Get product" example from the official documentation, trimmed
    "name": "Counter-Strike: Source Steam CD Key",
    "description": "Counter-Strike: Source blends Counter-Strike&#39;s award-winning teamplay action.",
    "developers": ["Valve Corporation", "Hidden Path Entertainment"],
    "publishers": ["Valve Corporation"],
    "genres": ["Action"],
    "platform": "Steam",
    "releaseDate": "2004-11-01",
    "qty": 149,
    "price": 5.79,
    "isPreorder": False,
    "regionalLimitations": "Region free",
    "countryLimitation": ["PR", "PS"],
    "regionId": 3,
    "kinguinId": 1949,
    "productId": "5c9b5f6b2539a4e8f172916a",
    "originalName": "Counter-Strike: Source",
    "videos": [{"video_id": "bvI62FUDpKA"}],
    "languages": ["German", "English"],
    "tags": ["base"],
    "offers": [
        {
            "name": "Counter-Strike: Source Steam CD Key",
            "offerId": "5f7efe3b369b4a0001c5b46f",
            "price": 5.79,
            "qty": 149,
            "merchantName": "KinguinHK",
            "isPreorder": False,
        }
    ],
    "images": {
        "screenshots": [{"url": "https://cdns.kinguin.net/media/category//1/_/1_3418.jpg"}],
        "cover": {"url": "https://cdns.kinguin.net/media/catalog/cover.jpg"},
    },
    "updatedAt": "2020-10-24T09:34:13+00:00",
}


def fake_product(i: int) -> dict[str, Any]:
    game = i // 3
    platform = PLATFORMS[i % 3]
    return {
        "kinguinId": 100000 + i,
        "productId": f"pid{i:08d}",
        "name": f"Test Game {game} {platform} CD Key",
        "originalName": f"Test Game {game}",
        "platform": platform,
        "regionalLimitations": "Region free",
        "regionId": 3,
        "developers": ["Dev Studio"],
        "genres": ["Action"],
        "tags": ["base"],
        "price": 10 + i / 100,
        "offers": [
            {
                "offerId": f"off{i}a",
                "price": 10 + i / 100,
                "qty": 5,
                "merchantName": "SellerA",
                "isPreorder": False,
            },
            {
                "offerId": f"off{i}b",
                "price": 12 + i / 100,
                "qty": 0,
                "merchantName": "SellerB",
                "isPreorder": False,
            },
        ],
        "images": {"cover": {"url": f"https://cdn.example/{i}.jpg"}},
        "updatedAt": (BASE_TIME + timedelta(minutes=i)).isoformat(),
    }


class FakeKinguin:
    """Documented API: /v1/products (page, limit ≤ 100, updatedSince), /v1/products/{id}, /v1/regions."""

    def __init__(self, n: int = 250) -> None:
        self.products = [fake_product(i) for i in range(1, n + 1)]
        self.planned: list[httpx.Response] = []  # responses served before normal handling
        self.fail_pages: dict[int, int] = {}  # page -> number of 500s still to return
        self.requests: list[httpx.Request] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        if request.headers.get("X-Api-Key") != KEY:
            return httpx.Response(
                401, json={"kind": "Authorization", "status": 401, "detail": "Invalid authentication data."}
            )
        if self.planned:
            return self.planned.pop(0)
        path = request.url.path.removeprefix("/esa/api")
        if path == "/v1/regions":
            return httpx.Response(200, json=[{"id": 3, "name": "REGION FREE"}])
        if path == "/v1/products":
            q = request.url.params
            page, limit = int(q.get("page", 1)), int(q.get("limit", 25))
            if limit > 100:
                return httpx.Response(
                    400, json={"kind": "ConstraintViolation", "status": 400, "detail": "limit"}
                )
            if self.fail_pages.get(page):
                self.fail_pages[page] -= 1
                return httpx.Response(500, json={"kind": "Error", "status": 500, "detail": "boom"})
            items = self.products
            if q.get("updatedSince"):
                since = datetime.fromisoformat(q["updatedSince"])
                items = [p for p in items if datetime.fromisoformat(p["updatedAt"]) >= since]
            if q.get("sortBy") == "updatedAt":
                items = sorted(items, key=lambda p: p["updatedAt"], reverse=q.get("sortType") == "desc")
            chunk = items[(page - 1) * limit : page * limit]
            return httpx.Response(200, json={"results": chunk, "item_count": len(items)})
        if path.startswith("/v1/products/"):
            kid = int(path.rsplit("/", 1)[1])
            for p in self.products:
                if p.get("kinguinId") == kid:
                    return httpx.Response(200, json=p)
            return httpx.Response(
                404, json={"kind": "ProductNotFound", "status": 404, "detail": "Product not found"}
            )
        return httpx.Response(404, json={"kind": "Http", "status": 404, "detail": "No route"})

    def page_calls(self) -> list[int]:
        return [int(r.url.params["page"]) for r in self.requests if r.url.path.endswith("/v1/products")]


@pytest.fixture
def sleeps(monkeypatch: pytest.MonkeyPatch) -> list[float]:
    calls: list[float] = []

    async def fake_sleep(s: float) -> None:
        calls.append(s)

    monkeypatch.setattr(kclient, "_sleep", fake_sleep)
    return calls


@pytest.fixture
def fake(monkeypatch: pytest.MonkeyPatch, sleeps: list[float]) -> FakeKinguin:
    f = FakeKinguin()
    monkeypatch.setattr(registry, "transport_override", httpx.MockTransport(f.handler))
    return f


@pytest.fixture
def enqueued(monkeypatch: pytest.MonkeyPatch) -> list[uuid.UUID]:
    ids: list[uuid.UUID] = []
    monkeypatch.setattr(queue, "enqueue", lambda job_id, countdown=0: ids.append(job_id))
    return ids


async def connect(key: str = KEY) -> Supplier:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        k.configuration = {**k.configuration, "min_request_interval_ms": 0}
        db.add(
            SupplierConnection(
                supplier_id=k.id,
                environment="live",
                credentials_enc=encrypt_secret(json.dumps({"api_key": key})),
                status="connected",
            )
        )
        await db.commit()
        return k


async def new_job(job_type: str = "full", **params: Any) -> uuid.UUID:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        job = ImportJob(supplier_id=k.id, job_type=job_type, status="queued", params=params)
        db.add(job)
        await db.commit()
        return job.id


async def load(job_id: uuid.UUID) -> ImportJob:
    async with get_sessionmaker()() as db:
        job = await db.get(ImportJob, job_id)
        assert job is not None
        return job


async def count(model: Any) -> int:
    async with get_sessionmaker()() as db:
        return int(await db.scalar(select(func.count()).select_from(model)) or 0)


def runner(**kw: Any) -> ImportRunner:
    return ImportRunner(get_engine(), **kw)


# ------------------------------------------------------------------ client


def client_for(f: FakeKinguin, **kw: Any) -> KinguinClient:
    return KinguinClient(KEY, transport=httpx.MockTransport(f.handler), min_interval=0, **kw)


async def test_client_honours_retry_after_and_retries_server_errors(sleeps: list[float]) -> None:
    f = FakeKinguin(5)
    f.planned = [
        httpx.Response(429, headers={"Retry-After": "7"}, json={"kind": "Http", "status": 429}),
        httpx.Response(503, text="gateway"),
        httpx.Response(200, text="<html>not json</html>"),
    ]
    c = client_for(f)
    body = await c.list_products(1, 100)
    await c.aclose()
    assert len(body["results"]) == 5 and body["item_count"] == 5
    assert sleeps[0] == 7.0 and len(sleeps) == 3 and c.requests == 4 and c.rate_limited == 1
    assert f.requests[-1].url.params["limit"] == "100" and f.requests[-1].url.params["sortBy"] == "updatedAt"


async def test_client_does_not_retry_auth_or_not_found(sleeps: list[float]) -> None:
    f = FakeKinguin(1)
    bad = KinguinClient("wrong-key-0000", transport=httpx.MockTransport(f.handler), min_interval=0)
    with pytest.raises(SupplierAuthError, match="Invalid authentication data"):
        await bad.regions()
    assert bad.requests == 1
    c = client_for(f)
    with pytest.raises(SupplierNotFound):
        await c.get_product(999)
    f.planned = [httpx.Response(500, json={"kind": "Error"}) for _ in range(3)]
    c3 = client_for(f, max_attempts=3)
    with pytest.raises(SupplierTemporaryError, match="after 3 attempts"):
        await c3.regions()
    assert [s for s in sleeps if s > 0] and c3.requests == 3
    with pytest.raises(ValueError):
        await c.list_products(1, 101)  # documented maximum is 100
    for x in (bad, c, c3):
        await x.aclose()


def test_retry_after_parsing() -> None:
    assert retry_after_seconds("12") == 12.0
    assert retry_after_seconds("100000") == 300.0
    assert retry_after_seconds(None) is None and retry_after_seconds("soon") is None
    future = (datetime.now(UTC) + timedelta(seconds=30)).strftime("%a, %d %b %Y %H:%M:%S GMT")
    assert 25 <= (retry_after_seconds(future) or 0) <= 31


# ------------------------------------------------------------------ normaliser


def test_normalizes_the_documented_product_object() -> None:
    p = normalize(DOC_EXAMPLE)
    assert p.external_product_id == "1949" and p.external_product_set_id == "5c9b5f6b2539a4e8f172916a"
    assert p.base_title == "Counter-Strike: Source" and p.platform == "Steam" and p.region == "Global"
    assert (
        p.developer == "Valve Corporation, Hidden Path Entertainment"
        and p.release_date.isoformat() == "2004-11-01"
    )
    assert p.product_type == "game" and p.activation_type == "key" and p.offers_complete
    [o] = p.offers
    assert (o.external_offer_id, o.seller_id, o.currency, str(o.price), o.quantity, o.availability) == (
        "5f7efe3b369b4a0001c5b46f",
        "KinguinHK",
        "EUR",
        "5.7900",
        149,
        "in_stock",
    )
    assert [m.media_type for m in p.media] == ["cover", "screenshot", "video"]
    assert p.variant_attributes["region_id"] == 3 and p.raw_payload == DOC_EXAMPLE


def test_normalizer_handles_variant_shapes() -> None:
    acc = normalize(
        {
            "kinguinId": 5,
            "name": "Star Wars Outlaws Gold Edition PS5 Account",
            "platform": "PlayStation 5",
            "tags": "base",
            "regionalLimitations": "EUROPE",
        }
    )
    assert (
        acc.product_type == "game_account"
        and acc.activation_type == "account"
        and acc.edition == "Gold Edition"
    )
    assert (
        acc.region == "EUROPE"
        and acc.platform == "PlayStation 5"
        and acc.offers == []
        and not acc.offers_complete
    )
    dlc = normalize(
        {
            "kinguinId": "6",
            "name": "Some DLC PC Steam CD Key",
            "tags": ["dlc"],
            "platform": "PC Steam",
            "offers": [{"offerId": "x", "price": "bad"}, {"offerId": "y", "price": 1, "isPreorder": True}],
            "developers": None,
            "releaseDate": "soon",
            "images": [],
        }
    )
    assert dlc.product_type == "dlc" and dlc.platform == "Steam" and dlc.release_date is None
    assert [(o.external_offer_id, o.availability) for o in dlc.offers] == [("y", "preorder")]
    gift = normalize({"kinguinId": 7, "name": "Steam Gift Card 20 EUR", "tags": ["prepaid"]})
    assert gift.product_type == "gift_card"
    from app.suppliers.base import NormalizationError

    for bad in ({"name": "no id"}, {"kinguinId": 8}, {"kinguinId": True, "name": "x"}):
        with pytest.raises(NormalizationError):
            normalize(bad)


# ------------------------------------------------------------------ runner


async def test_full_import_pages_through_everything(fake: FakeKinguin) -> None:
    await connect()
    job_id = await new_job("full", page_size=100)
    out = await runner().run(job_id)
    job = await load(job_id)
    assert out.state == "done" and job.status == "completed"
    assert (job.total_records, job.processed_records, job.created_records, job.failed_records) == (
        250,
        250,
        250,
        0,
    )
    assert job.api_requests == 3 and fake.page_calls() == [1, 2, 3]
    assert job.checkpoint["page"] == 4 and job.checkpoint["summary"]["created"] == 250
    assert await count(SupplierProduct) == 250 and await count(ProductVariant) == 250
    assert await count(Product) == 84  # three platform variants per game share one master product
    assert await count(SupplierOffer) == 500
    # A catch-up import for products updated during the run is queued automatically.
    catch_up = await load(out.follow_up_job_id)  # type: ignore[arg-type]
    assert catch_up.job_type == "incremental" and catch_up.params["catch_up_for"] == str(job_id)

    # Running the catch-up re-reads changed products only; unchanged ones are recognised and skipped.
    fake.products[0]["offers"][0]["price"] = 3.5
    fake.products[0]["updatedAt"] = (BASE_TIME + timedelta(days=400)).isoformat()
    async with get_sessionmaker()() as db:
        await db.execute(
            update(ImportJob)
            .where(ImportJob.id == catch_up.id)
            .values(params={"updated_since": "2027-01-01T00:00:00+00:00"})
        )
        await db.commit()
    assert (await runner().run(catch_up.id)).state == "done"
    cu = await load(catch_up.id)
    assert (cu.processed_records, cu.updated_records) == (1, 1)
    assert await count(SupplierProduct) == 250  # no duplicates


async def test_controlled_batch_stops_at_max_records(fake: FakeKinguin) -> None:
    await connect()
    job_id = await new_job("full", max_records=100, page_size=40)
    out = await runner().run(job_id)
    job = await load(job_id)
    assert out.state == "done" and out.follow_up_job_id is None
    assert (job.total_records, job.processed_records) == (100, 100) and fake.page_calls() == [1, 2, 3]
    assert await count(SupplierProduct) == 100


async def test_import_resumes_after_an_interrupted_worker(
    fake: FakeKinguin, monkeypatch: pytest.MonkeyPatch
) -> None:
    await connect()
    job_id = await new_job("full", page_size=100)
    real = CatalogueService.ingest
    calls = {"n": 0}

    async def crash_midway(self: CatalogueService, supplier: Supplier, item: Any) -> Any:
        calls["n"] += 1
        if calls["n"] == 150:  # halfway through page 2 the worker process dies
            raise SystemExit("worker killed")
        return await real(self, supplier, item)

    monkeypatch.setattr(CatalogueService, "ingest", crash_midway)
    with pytest.raises(SystemExit):
        await runner().run(job_id)
    job = await load(job_id)
    assert job.status == "running" and job.checkpoint["page"] == 2 and job.processed_records == 100
    assert await count(SupplierProduct) == 100  # the half-saved page 2 was rolled back

    monkeypatch.setattr(CatalogueService, "ingest", real)
    assert (await runner().run(job_id)).state == "done"
    job = await load(job_id)
    assert job.processed_records == 250 and job.created_records == 250
    assert fake.page_calls() == [1, 2, 2, 3]  # page 1 was not fetched again
    assert await count(SupplierProduct) == 250


async def test_temporary_failure_backs_off_then_continues(fake: FakeKinguin) -> None:
    await connect()
    job_id = await new_job("full", page_size=100)
    fake.fail_pages = {2: 6}  # the client gives up after 6 attempts on page 2
    out = await runner().run(job_id)
    job = await load(job_id)
    assert out.state == "retry_later" and out.delay == 30 and job.status == "running"
    assert (
        job.checkpoint["page"] == 2
        and job.checkpoint["consecutive_failures"] == 1
        and "retry_at" in job.checkpoint
    )
    assert job.api_requests == 7 and "500" in (job.last_error or "")
    assert (await runner().run(job_id)).state == "done"
    job = await load(job_id)
    assert job.processed_records == 250 and "retry_at" not in job.checkpoint


async def test_time_budget_splits_the_job_into_chunks(fake: FakeKinguin) -> None:
    await connect()
    job_id = await new_job("full", page_size=100)
    clock_values = [0.0, 0.0, 1000.0]  # start, before page 1, before page 2 (budget used up)

    def clock() -> float:
        return clock_values.pop(0) if clock_values else 0.0

    out = await runner(budget_seconds=10, clock=clock).run(job_id)
    assert out.state == "continue" and (await load(job_id)).checkpoint["page"] == 2


async def test_bad_records_are_saved_and_retried(fake: FakeKinguin) -> None:
    await connect()
    fake.products = fake.products[:10]
    del fake.products[3]["name"]  # this record cannot be normalised
    job_id = await new_job("full")
    assert (await runner().run(job_id)).state == "done"
    job = await load(job_id)
    assert (job.processed_records, job.created_records, job.failed_records) == (10, 9, 1)
    async with get_sessionmaker()() as db:
        f = (await db.execute(select(ImportFailure))).scalar_one()
    assert f.external_product_id == "100004" and "no name" in f.error and f.payload["kinguinId"] == 100004

    fake.products[3]["name"] = "Test Game 1 PC Steam CD Key"  # fixed at the supplier
    retry = await new_job("retry_failed")
    assert (await runner().run(retry)).state == "done"
    async with get_sessionmaker()() as db:
        f2 = (await db.execute(select(ImportFailure))).scalar_one()
    assert f2.resolved_at is not None and await count(SupplierProduct) == 10
    r = await load(retry)
    assert (r.total_records, r.processed_records, r.created_records) == (1, 1, 1)


async def test_paused_cancelled_and_bad_key_jobs(fake: FakeKinguin) -> None:
    await connect("wrong-key-0000")
    job_id = await new_job("full")
    assert (await runner().run(job_id)).state == "failed"
    job = await load(job_id)
    assert (
        job.status == "failed" and "refused the API key" in (job.last_error or "") and job.api_requests == 1
    )

    paused = await new_job("incremental", updated_since="2026-01-01T00:00:00+00:00")
    async with get_sessionmaker()() as db:
        await db.execute(update(ImportJob).where(ImportJob.id == paused).values(status="paused"))
        await db.commit()
    assert (await runner().run(paused)).state == "stopped"
    assert fake.page_calls() == [1]  # only the bad-key job's single refused request


async def test_second_runner_on_the_same_job_is_locked_out(fake: FakeKinguin) -> None:
    await connect()
    job_id = await new_job("full")
    from sqlalchemy import text

    async with get_engine().connect() as c:
        c2 = await c.execution_options(isolation_level="AUTOCOMMIT")
        await c2.execute(
            text("SELECT pg_advisory_lock(hashtextextended(:k, 0))"), {"k": f"import-job:{job_id}"}
        )
        assert (await runner().run(job_id)).state == "locked"
        await c2.execute(
            text("SELECT pg_advisory_unlock(hashtextextended(:k, 0))"), {"k": f"import-job:{job_id}"}
        )


# ------------------------------------------------------------------ API


async def test_connection_endpoints_never_return_the_key(
    client: AsyncClient, make_user: MakeUser, fake: FakeKinguin
) -> None:
    await make_user()
    await login(client)
    r = await client.put("/api/v1/suppliers/kinguin/connection", json={"environment": "live", "api_key": KEY})
    assert r.status_code == 200 and KEY not in r.text
    live = next(c for c in r.json()["connections"] if c["environment"] == "live")
    assert live == {**live, "configured": True, "status": "untested"}
    async with get_sessionmaker()() as db:
        conn = (await db.execute(select(SupplierConnection))).scalar_one()
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        k.configuration = {**k.configuration, "min_request_interval_ms": 0}
        await db.commit()
    assert (
        KEY not in conn.credentials_enc and json.loads(decrypt_secret(conn.credentials_enc))["api_key"] == KEY
    )

    t = (await client.post("/api/v1/suppliers/kinguin/connection/test", json={})).json()
    assert t["ok"] is True and "1 regions" in t["message"]
    assert (await client.get("/api/v1/suppliers/kinguin/connection")).json()["connections"][0][
        "status"
    ] == "connected"
    audit = (await client.get("/api/v1/audit-logs")).text
    assert "credentials.updated" in audit and KEY not in audit


async def test_import_api_start_pause_resume_cancel(
    client: AsyncClient, make_user: MakeUser, fake: FakeKinguin, enqueued: list[uuid.UUID]
) -> None:
    await make_user()
    await login(client)
    assert (await client.post("/api/v1/imports", json={"job_type": "full"})).status_code == 409  # no key yet
    await connect()
    r = await client.post("/api/v1/imports", json={"job_type": "full", "max_records": 100})
    assert r.status_code == 201, r.text
    job = r.json()
    assert job["status"] == "queued" and enqueued == [uuid.UUID(job["id"])]
    dup = await client.post("/api/v1/imports", json={"job_type": "full"})
    assert dup.status_code == 409 and "already running" in dup.json()["error"]["message"]
    assert (
        await client.post("/api/v1/imports", json={"job_type": "incremental"})
    ).status_code == 409  # no baseline

    assert (await client.post(f"/api/v1/imports/{job['id']}/pause")).json()["status"] == "paused"
    assert (await runner().run(uuid.UUID(job["id"]))).state == "stopped"
    assert (await client.post(f"/api/v1/imports/{job['id']}/resume")).json()["status"] == "queued"
    assert (await runner().run(uuid.UUID(job["id"]))).state == "done"

    ov = (await client.get("/api/v1/imports/overview")).json()
    assert ov["active_job"] is None and ov["recent_jobs"][0]["saved_records"] == 100
    assert ov["totals"] == {
        **ov["totals"],
        "supplier_products": 100,
        "variants": 100,
        "offers": 200,
        "offers_in_stock": 100,
    }
    d = (await client.get(f"/api/v1/imports/{job['id']}")).json()
    assert d["current_page"] == 2 and d["duration_seconds"] is not None and d["last_checkpoint_at"]
    logs = (await client.get(f"/api/v1/imports/{job['id']}/logs")).json()
    assert logs[0]["message"].startswith("Import completed") and any(
        "Paused by" in x["message"] for x in logs
    )
    assert (await client.post(f"/api/v1/imports/{job['id']}/cancel")).status_code == 409  # already completed

    single = await client.post("/api/v1/imports", json={"job_type": "single", "external_id": "100001"})
    assert single.status_code == 201
    assert (await client.post(f"/api/v1/imports/{single.json()['id']}/cancel")).json()[
        "status"
    ] == "cancelled"
    assert (await client.post("/api/v1/imports", json={"job_type": "retry_failed"})).status_code == 409


async def test_viewer_sees_imports_but_cannot_start_or_set_keys(
    client: AsyncClient, make_user: MakeUser, enqueued: list[uuid.UUID]
) -> None:
    await make_user("viewer@omggamer.com", role="viewer")
    await login(client, "viewer@omggamer.com")
    assert (await client.get("/api/v1/imports/overview")).status_code == 200
    assert (await client.post("/api/v1/imports", json={"job_type": "full"})).status_code == 403
    r = await client.put("/api/v1/suppliers/kinguin/connection", json={"environment": "live", "api_key": KEY})
    assert r.status_code == 403


async def test_watchdog_requeues_stalled_jobs(monkeypatch: pytest.MonkeyPatch) -> None:
    from app.workers import tasks

    sent: list[str] = []
    monkeypatch.setattr(tasks.run_import, "apply_async", lambda args, **kw: sent.append(args[0]))
    stale = await new_job("full")
    waiting = await new_job("incremental", updated_since="2026-01-01T00:00:00+00:00")
    fresh = await new_job("single", external_id="1")
    old = datetime.now(UTC) - timedelta(minutes=30)
    async with get_sessionmaker()() as db:
        await db.execute(
            update(ImportJob).where(ImportJob.id == stale).values(status="running", updated_at=old)
        )
        later = (datetime.now(UTC) + timedelta(minutes=5)).isoformat()
        await db.execute(
            update(ImportJob)
            .where(ImportJob.id == waiting)
            .values(updated_at=old, checkpoint={"retry_at": later})
        )
        await db.commit()
    assert tasks.import_watchdog.run() == 1 and sent == [str(stale)]
    del fresh
    assert await count(ImportLog) == 0


def test_celery_task_runs_an_import_end_to_end(monkeypatch: pytest.MonkeyPatch, sleeps: list[float]) -> None:
    """The real task entry point: its own event loop and engine, chaining chunks until the job is done."""
    from sqlalchemy.orm import Session

    from app.workers import tasks
    from app.workers.celery_app import sync_engine

    f = FakeKinguin(30)
    monkeypatch.setattr(registry, "transport_override", httpx.MockTransport(f.handler))
    chained: list[str] = []
    monkeypatch.setattr(tasks.run_import, "apply_async", lambda args, **kw: chained.append(args[0]))
    with Session(sync_engine()) as s:
        k = s.execute(select(Supplier).where(Supplier.code == "kinguin")).scalar_one()
        k.configuration = {**k.configuration, "min_request_interval_ms": 0}
        s.add(
            SupplierConnection(
                supplier_id=k.id,
                environment="live",
                status="connected",
                credentials_enc=encrypt_secret(json.dumps({"api_key": KEY})),
            )
        )
        job = ImportJob(supplier_id=k.id, job_type="full", status="queued", params={"page_size": 10})
        s.add(job)
        s.commit()
        job_id = job.id
    assert tasks.run_import.run(str(job_id)) == "done"
    with Session(sync_engine()) as s:
        done = s.get(ImportJob, job_id)
        assert done is not None and done.status == "completed" and done.processed_records == 30
        assert s.scalar(select(func.count()).select_from(SupplierProduct)) == 30
    assert len(chained) == 1  # the automatic catch-up import
