"""Phase 4: list columns and filters, bulk operations, content versions, publishing history and activity."""

import uuid
from decimal import Decimal

import pytest
from httpx import AsyncClient
from sqlalchemy import select

from app.ai import queue as ai_queue
from app.catalogue.schemas import NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import (
    AiJob,
    Product,
    ProductContent,
    PublishingQueueItem,
    Storefront,
    StorefrontProductMapping,
    Supplier,
)
from app.db.session import get_sessionmaker
from app.publishing import queue as publish_queue
from tests.conftest import MakeUser, login


@pytest.fixture
def queued(monkeypatch: pytest.MonkeyPatch) -> dict[str, list[uuid.UUID]]:
    sent: dict[str, list[uuid.UUID]] = {"ai": [], "publish": []}
    monkeypatch.setattr(ai_queue, "enqueue", lambda ids, countdown=0: sent["ai"].extend(ids))
    monkeypatch.setattr(publish_queue, "enqueue", lambda ids, countdown=0: sent["publish"].extend(ids))
    return sent


async def seed() -> dict[str, str]:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        svc = CatalogueService(db)
        ids: dict[str, str] = {}
        for ext, title, base, platform, edition in [
            ("1001", "Star Wars Outlaws Gold Edition PS5 Key", "Star Wars Outlaws", "PS5", "Gold Edition"),
            ("1002", "Elden Ring PC Steam Key", "Elden Ring", "Steam", None),
            ("1003", "Hades PC Steam Key", "Hades", "Steam", None),
        ]:
            r = await svc.ingest(
                k,
                NormalizedProduct(
                    external_product_id=ext,
                    original_title=title,
                    base_title=base,
                    platform=platform,
                    edition=edition,
                    region="Global",
                    offers=[
                        NormalizedOffer(
                            external_offer_id=f"o{ext}", currency="EUR", price=Decimal("9.99"), quantity=3
                        )
                    ],
                ),
            )
            ids[ext] = str(r.product_id)
        # Elden Ring already has AI content waiting for review and a live WooCommerce listing.
        db.add(
            ProductContent(
                product_id=uuid.UUID(ids["1002"]), title="Elden Ring", content_status="pending_review"
            )
        )
        sf = (await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))).scalar_one()
        db.add(
            StorefrontProductMapping(
                storefront_id=sf.id,
                product_id=uuid.UUID(ids["1002"]),
                external_product_id="77",
                sync_status="synced",
            )
        )
        await db.commit()
        return ids


async def test_list_shows_phase4_columns_and_filters(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    ids = await seed()

    def titles(r):  # type: ignore[no-untyped-def]
        return sorted(i["canonical_title"] for i in r.json()["items"])

    page = (await client.get("/api/v1/catalogue/products")).json()
    er = next(i for i in page["items"] if i["id"] == ids["1002"])
    assert er["external_ids"] == ["1002"] and er["publishing_status"] == "published"
    assert er["content_status"] == "pending_review" and er["product_status"] == "draft"
    swo = next(i for i in page["items"] if i["id"] == ids["1001"])
    assert swo["editions"] == ["Gold Edition"] and swo["publishing_status"] == "not_published"

    get = client.get
    assert titles(await get("/api/v1/catalogue/products", params={"edition": "gold edition"})) == [
        "Star Wars Outlaws"
    ]
    assert titles(await get("/api/v1/catalogue/products", params={"ai_status": "pending_review"})) == [
        "Elden Ring"
    ]
    assert titles(await get("/api/v1/catalogue/products", params={"ai_status": "none"})) == [
        "Hades",
        "Star Wars Outlaws",
    ]
    assert titles(await get("/api/v1/catalogue/products", params={"publishing": "published"})) == [
        "Elden Ring"
    ]
    assert titles(await get("/api/v1/catalogue/products", params={"publishing": "not_published"})) == [
        "Hades",
        "Star Wars Outlaws",
    ]
    assert titles(await get("/api/v1/catalogue/products", params={"status": "draft", "q": "1003"})) == [
        "Hades"
    ]


async def test_bulk_actions(
    client: AsyncClient, make_user: MakeUser, queued: dict[str, list[uuid.UUID]]
) -> None:
    await make_user()
    await login(client)
    ids = await seed()
    every = list(ids.values())

    async def bulk(action: str, product_ids: list[str], **kw: object) -> dict:  # type: ignore[type-arg]
        r = await client.post(
            "/api/v1/catalogue/products/bulk", json={"action": action, "product_ids": product_ids, **kw}
        )
        assert r.status_code == 200, r.text
        return r.json()

    gen = await bulk("generate_ai", every)
    assert gen["changed"] == 2 and gen["details"] == {"has_content": 1}  # Elden Ring already has content
    assert len(queued["ai"]) == 2
    again = await bulk("generate_ai", [ids["1001"]])
    assert again["details"] == {"already_queued": 1}
    regen = await bulk("regenerate_ai", [ids["1002"]])
    assert regen["changed"] == 1

    pub = await bulk("publish", [ids["1003"]])
    assert pub["changed"] == 0 and pub["details"] == {"not_approved": 1}
    ok = await bulk("approve", [ids["1002"], ids["1003"]])
    assert ok["changed"] == 2
    async with get_sessionmaker()() as db:
        c = (await db.execute(select(ProductContent))).scalar_one()
        assert c.content_status == "approved"
    pub = await bulk("publish", [ids["1002"], ids["1003"]])
    assert pub["changed"] == 2 and len(queued["publish"]) == 2
    async with get_sessionmaker()() as db:
        items = (await db.execute(select(PublishingQueueItem, Storefront.code).join(Storefront))).all()
        assert {code for _, code in items} == {"omggamer-staging"}  # staging is the safe default
        assert all(i.approval_status == "approved" for i, _ in items)

    rej = await bulk("reject", [ids["1002"]])
    assert rej["changed"] == 1
    arch = await bulk("archive", [ids["1001"]])
    assert arch["changed"] == 1
    cats = (await client.post("/api/v1/catalogue/categories", json={"name": "RPG"})).json()
    assigned = await bulk("assign_categories", [ids["1002"], ids["1003"]], category_ids=[cats["id"]])
    assert assigned["changed"] == 2
    lst = (await client.get("/api/v1/catalogue/products", params={"category_id": cats["id"]})).json()
    assert lst["total"] == 2 and lst["items"][0]["categories"] == ["RPG"]

    async with get_sessionmaker()() as db:
        job = (await db.execute(select(AiJob).where(AiJob.product_id == uuid.UUID(ids["1003"])))).scalar_one()
        job.status = "failed"
        await db.commit()
    retry = await bulk("retry_failed", [ids["1003"], ids["1001"]])
    assert retry["changed"] == 1 and retry["details"] == {"nothing_failed": 1}

    async with get_sessionmaker()() as db:
        statuses = {str(p.id): p.product_status for p in (await db.execute(select(Product))).scalars()}
    assert statuses == {ids["1001"]: "archived", ids["1002"]: "draft", ids["1003"]: "approved"}

    act = (await client.get(f"/api/v1/catalogue/products/{ids['1002']}/activity")).json()
    assert {a["action"] for a in act} >= {"product.approve", "product.publish", "product.reject"}
    hist = (await client.get(f"/api/v1/catalogue/products/{ids['1002']}/publishing")).json()
    assert hist["mappings"][0]["sync_status"] == "synced" and hist["events"][0]["kind"] == "queue"
    versions = (await client.get(f"/api/v1/catalogue/products/{ids['1002']}/content")).json()
    assert versions[0]["content_status"] == "rejected" and versions[0]["is_current"] is True


async def test_bulk_respects_roles(
    client: AsyncClient, make_user: MakeUser, queued: dict[str, list[uuid.UUID]]
) -> None:
    await make_user("content@omggamer.com", role="content_manager")
    await login(client, "content@omggamer.com")
    ids = await seed()
    r = await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "approve", "product_ids": [ids["1001"]]}
    )
    assert r.status_code == 403
    r = await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "generate_ai", "product_ids": [ids["1001"]]}
    )
    assert r.status_code == 200 and r.json()["changed"] == 1
