"""Phase 5: AI content pipeline with an in-process stand-in for the OpenAI Chat Completions API.

The stand-in follows the documented request/response shape (messages, response_format json_schema,
choices[0].message.content, usage.prompt_tokens/completion_tokens). Test scaffolding only.
"""

import json
import uuid
from decimal import Decimal
from typing import Any

import httpx
import pytest
from httpx import AsyncClient
from sqlalchemy import select

from app.ai import openai_client, runner
from app.ai import queue as ai_queue
from app.ai import settings as ai_settings
from app.ai.runner import AiRunner
from app.catalogue.schemas import NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import (
    AiJob,
    IntegrationCredential,
    Product,
    ProductCategory,
    ProductContent,
    Supplier,
    SupplierProduct,
)
from app.db.session import get_engine, get_sessionmaker
from tests.conftest import MakeUser, login

KEY = "sk-test-0123456789abcdefghij"

GOOD = {
    "title": "Hades PC Steam Key",
    "short_description": "Battle out of the underworld in this rogue-like dungeon crawler.",
    "long_description": "Hades is a rogue-like dungeon crawler.\n\nRedeem the key on Steam.",
    "meta_title": "Hades PC Steam Key | OMGGamer",
    "meta_description": "Buy the Hades Steam key for PC. Instant digital delivery from OMGGamer.",
    "tags": ["Action", "roguelike", "steam"],
    "suggested_category": "action",
    "image_alt_text": [{"url": "https://cdn.example/hades.jpg", "alt": "Hades cover art"}],
    "missing_information": [],
}


class FakeOpenAI:
    def __init__(self) -> None:
        self.answers: list[httpx.Response | dict[str, Any]] = []
        self.requests: list[dict[str, Any]] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        if request.headers.get("Authorization") != f"Bearer {KEY}":
            return httpx.Response(
                401, json={"error": {"message": "Incorrect API key provided", "code": "invalid_api_key"}}
            )
        if request.url.path.endswith("/models"):
            return httpx.Response(200, json={"data": [{"id": "gpt-4o-mini"}]})
        body = json.loads(request.content)
        self.requests.append(body)
        answer = self.answers.pop(0) if self.answers else GOOD
        if isinstance(answer, httpx.Response):
            return answer
        return httpx.Response(
            200,
            headers={"x-request-id": "req_1"},
            json={
                "model": body["model"],
                "choices": [
                    {"finish_reason": "stop", "message": {"role": "assistant", "content": json.dumps(answer)}}
                ],
                "usage": {"prompt_tokens": 1000, "completion_tokens": 500},
            },
        )


@pytest.fixture
def fake(monkeypatch: pytest.MonkeyPatch) -> FakeOpenAI:
    f = FakeOpenAI()
    monkeypatch.setattr(runner, "transport_override", httpx.MockTransport(f.handler))

    async def no_sleep(_: float) -> None:
        return None

    monkeypatch.setattr(openai_client, "_sleep", no_sleep)
    return f


@pytest.fixture
def queued(monkeypatch: pytest.MonkeyPatch) -> list[uuid.UUID]:
    ids: list[uuid.UUID] = []
    monkeypatch.setattr(ai_queue, "enqueue", lambda job_ids, countdown=0: ids.extend(job_ids))
    return ids


async def seed(key: str | None = KEY) -> uuid.UUID:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        db.add(ProductCategory(name="Action", slug="action"))
        r = await CatalogueService(db).ingest(
            k,
            NormalizedProduct(
                external_product_id="555",
                original_title="Hades PC Steam CD Key",
                original_description="<p>Defy the god of the dead and hack out of the Underworld.</p>",
                base_title="Hades",
                platform="Steam",
                region="Global",
                developer="Supergiant Games",
                genres=["Action"],
                media=[],
                offers=[
                    NormalizedOffer(external_offer_id="a", currency="EUR", price=Decimal("12.50"), quantity=5)
                ],
                raw_payload={"kinguinId": 555, "name": "Hades PC Steam CD Key", "genres": ["Action"]},
            ),
        )
        from app.db.models import ProductMedia

        db.add(
            ProductMedia(
                product_id=r.product_id, source_url="https://cdn.example/hades.jpg", media_type="cover"
            )
        )
        if key:
            await ai_settings.set_api_key(db, key)
        await db.commit()
        return r.product_id


async def job_for(product_id: uuid.UUID, task: str = "content") -> uuid.UUID:
    async with get_sessionmaker()() as db:
        j = AiJob(product_id=product_id, task=task, status="queued")
        db.add(j)
        await db.commit()
        return j.id


async def load(model: Any, ident: Any) -> Any:
    async with get_sessionmaker()() as db:
        return await db.get(model, ident)


def run() -> AiRunner:
    from app.core.config import get_settings

    return AiRunner(get_engine(), get_settings().redis_url)


async def test_generates_checked_content_without_touching_supplier_data(fake: FakeOpenAI) -> None:
    pid = await seed()
    jid = await job_for(pid)
    assert (await run().run(jid)).state == "done"
    job = await load(AiJob, jid)
    assert job.status == "completed" and job.outcome == "generated" and job.prompt_version == "content-v1"
    assert job.token_usage == {"input": 1000, "output": 500}
    assert job.estimated_cost == Decimal("0.000450")  # 1000 * 0.15/M + 500 * 0.60/M
    async with get_sessionmaker()() as db:
        c = (await db.execute(select(ProductContent))).scalar_one()
        sp = (await db.execute(select(SupplierProduct))).scalar_one()
        p = await db.get(Product, pid)
    assert c.content_status == "pending_review" and c.is_current and c.version == 1 and c.origin == "ai"
    assert c.title == "Hades PC Steam Key" and c.tags == ["action", "roguelike", "steam"]
    assert c.suggested_category == "Action" and c.review_flags == []
    assert c.image_alt_text == {"https://cdn.example/hades.jpg": "Hades cover art"}
    assert (
        c.structured_info["platforms"] == ["Steam"] and c.structured_info["developer"] == "Supergiant Games"
    )
    assert sp.original_title == "Hades PC Steam CD Key" and "Underworld" in (sp.original_description or "")
    assert p is not None and p.product_status == "review"

    req = fake.requests[0]
    assert req["response_format"]["json_schema"]["strict"] is True and req["model"] == "gpt-4o-mini"
    user = req["messages"][1]["content"]
    assert '"platforms": [\n  "Steam"\n ]' in user and "12.5" not in user  # prices are never sent
    assert "- Action" in user and "Underworld" in user


async def test_invented_facts_are_flagged(fake: FakeOpenAI) -> None:
    pid = await seed()
    fake.answers = [
        {
            **GOOD,
            "long_description": "Play on Xbox and PS5. Deluxe Edition with German audio. 8 GB RAM.",
            "suggested_category": "Racing",
            "meta_title": "x" * 70,
        }
    ]
    await run().run(await job_for(pid))
    async with get_sessionmaker()() as db:
        c = (await db.execute(select(ProductContent))).scalar_one()
        job = (await db.execute(select(AiJob))).scalar_one()
    flags = " | ".join(c.review_flags)
    for expected in (
        "platform Xbox",
        "platform PlayStation",
        "'deluxe'",
        "language 'german'",
        "system requirements",
        "category 'Racing' does not exist",
        "meta title is 70",
    ):
        assert expected in flags, expected
    assert c.suggested_category is None and job.outcome == "flagged"


async def test_unchanged_facts_are_not_regenerated(fake: FakeOpenAI) -> None:
    pid = await seed()
    await run().run(await job_for(pid))
    async with get_sessionmaker()() as db:  # only the price changes
        offer = (await db.execute(select(SupplierProduct))).scalar_one()
        from app.db.models import SupplierOffer

        o = (await db.execute(select(SupplierOffer))).scalar_one()
        o.supplier_price = Decimal("8.00")
        del offer
        await db.commit()
    jid = await job_for(pid)
    await run().run(jid)
    assert (await load(AiJob, jid)).outcome == "skipped_unchanged" and len(fake.requests) == 1
    forced = await job_for(pid, "regenerate")
    await run().run(forced)
    async with get_sessionmaker()() as db:
        versions = (await db.execute(select(ProductContent).order_by(ProductContent.version))).scalars().all()
    assert [(v.version, v.is_current, v.content_status) for v in versions] == [
        (1, False, "superseded"),
        (2, True, "pending_review"),
    ]


async def test_errors_retry_fail_and_mark_the_key(fake: FakeOpenAI) -> None:
    pid = await seed()
    fake.answers = [httpx.Response(500, json={"error": {"message": "boom"}}) for _ in range(4)]
    jid = await job_for(pid)
    out = await run().run(jid)
    job = await load(AiJob, jid)
    assert (
        out.state == "retry_later"
        and out.delay == 30
        and job.status == "queued"
        and "boom" in job.error_message
    )
    fake.answers = [
        httpx.Response(
            429, json={"error": {"message": "You exceeded your quota", "code": "insufficient_quota"}}
        )
    ]
    assert (await run().run(jid)).state == "failed"
    assert "no remaining quota" in (await load(AiJob, jid)).error_message
    cred = await load(IntegrationCredential, "openai")
    assert cred.status == "failed"

    async with get_sessionmaker()() as db:
        await ai_settings.set_api_key(db, "sk-wrong-000000000000000000")
        await db.commit()
    jid2 = await job_for(pid)
    assert (await run().run(jid2)).state == "failed"
    assert "refused the API key" in (await load(AiJob, jid2)).error_message


async def test_pause_holds_jobs(fake: FakeOpenAI) -> None:
    pid = await seed()
    async with get_sessionmaker()() as db:
        await ai_settings.save_settings(db, {"paused": True})
        await db.commit()
    jid = await job_for(pid)
    assert (await run().run(jid)).state == "paused" and (await load(AiJob, jid)).status == "paused"
    assert fake.requests == []


async def test_ai_studio_api(
    client: AsyncClient, make_user: MakeUser, fake: FakeOpenAI, queued: list[uuid.UUID]
) -> None:
    await make_user()
    await login(client)
    pid = await seed(key=None)
    assert (await client.post("/api/v1/ai/generate", json={})).status_code == 409  # no key yet
    r = await client.put("/api/v1/ai/credentials", json={"api_key": KEY})
    assert r.status_code == 200 and KEY not in r.text and r.json()["configured"] is True
    assert (await client.post("/api/v1/ai/credentials/test")).json()["ok"] is True

    s = (
        await client.put(
            "/api/v1/ai/settings", json={"model": "gpt-4o-mini", "batch_size": 10, "concurrency": 3}
        )
    ).json()
    assert s["batch_size"] == 10 and s["concurrency"] == 3
    ov = (await client.get("/api/v1/ai/overview")).json()
    assert ov["eligible_products"] == 1 and ov["key"]["status"] == "connected" and ov["prompt_version"] == 1

    gen = (await client.post("/api/v1/ai/generate", json={"scope": "eligible"})).json()
    assert gen["queued"] == 1 and len(queued) == 1
    assert (await client.post("/api/v1/ai/generate", json={"scope": "eligible"})).json()["queued"] == 0
    await run().run(queued[0])
    costs = (await client.get("/api/v1/ai/costs")).json()
    assert (
        costs["products"] == 1
        and costs["total_cost"] == "0.000450"
        and costs["rows"][0]["input_tokens"] == 1000
    )
    log = (await client.get("/api/v1/ai/jobs")).json()
    assert log[0]["product_title"] == "Hades" and log[0]["outcome"] == "generated"

    p2 = (
        await client.post(
            "/api/v1/ai/prompts",
            json={
                "system_prompt": "S" * 60 + " {language}",
                "user_template": "Facts: {facts} {unknown_placeholder}",
            },
        )
    ).json()
    assert p2["version"] == 2 and p2["is_active"]
    versions = (await client.get("/api/v1/ai/prompts")).json()
    assert [v["version"] for v in versions] == [2, 1] and [v["is_active"] for v in versions] == [True, False]

    regen = (
        await client.post(
            "/api/v1/ai/generate", json={"scope": "selected", "product_ids": [str(pid)], "regenerate": True}
        )
    ).json()
    assert regen["queued"] == 1
    await run().run(queued[-1])
    assert (
        "Facts: {" in fake.requests[-1]["messages"][1]["content"]
    )  # new prompt version used, unknown placeholder blank

    assert (await client.post("/api/v1/ai/pause")).json()["paused"] is True
    assert (await client.post("/api/v1/ai/resume")).json()["paused"] is False


async def test_viewer_cannot_run_ai(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user("viewer@omggamer.com", role="viewer")
    await login(client, "viewer@omggamer.com")
    assert (await client.get("/api/v1/ai/overview")).status_code == 200
    assert (await client.post("/api/v1/ai/generate", json={})).status_code == 403
    assert (await client.put("/api/v1/ai/settings", json={"model": "x"})).status_code == 403
    assert (await client.put("/api/v1/ai/credentials", json={"api_key": KEY})).status_code == 403
