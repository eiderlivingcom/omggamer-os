import time

from httpx import AsyncClient
from sqlalchemy import select

from app.core.redis import BEAT_HEARTBEAT_KEY, WORKER_HEARTBEAT_KEY, get_redis
from app.db.models import Product, Storefront, StorefrontProductMapping, Supplier, SupplierProduct
from app.db.session import get_sessionmaker
from tests.conftest import MakeUser, login


async def test_dashboard_counts_come_from_the_database(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    empty = (await client.get("/api/v1/dashboard/summary")).json()
    assert empty["catalogue"]["products"] == 0
    assert empty["suppliers"]["suppliers"] == 1  # Kinguin is seeded by the migration
    assert empty["suppliers"]["connected"] == 0

    async with get_sessionmaker()() as db:
        kinguin = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        channel = (
            await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))
        ).scalar_one()
        p1 = Product(slug="a", canonical_title="Game A", match_key="game a", product_status="draft")
        p2 = Product(slug="b", canonical_title="Game B", match_key="game b", product_status="published")
        db.add_all(
            [
                p1,
                p2,
                SupplierProduct(
                    supplier_id=kinguin.id, external_product_id="230507", original_title="Star Wars Outlaws"
                ),
            ]
        )
        await db.flush()
        db.add(
            StorefrontProductMapping(
                storefront_id=channel.id, product_id=p2.id, external_product_id="2362", sync_status="synced"
            )
        )
        await db.commit()

    data = (await client.get("/api/v1/dashboard/summary")).json()
    assert data["catalogue"]["products"] == 2
    assert data["catalogue"]["by_status"]["draft"] == 1 and data["catalogue"]["by_status"]["published"] == 1
    assert data["suppliers"]["supplier_products"] == 1
    assert data["publishing"]["published_listings"] == 1
    assert data["users_active"] == 1


async def test_activity_shows_recent_audit_entries(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    items = (await client.get("/api/v1/dashboard/activity")).json()
    assert items and items[0]["action"] == "auth.login"


async def test_health_reports_each_dependency(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    h = (await client.get("/api/v1/system/health")).json()
    names = {c["name"]: c for c in h["checks"]}
    assert names["Database"]["ok"] and names["Redis"]["ok"]
    assert names["Background workers"]["ok"] is False  # no worker running in tests
    assert h["ok"] is False and h["migration"] == "0003"

    await get_redis().set(WORKER_HEARTBEAT_KEY, str(time.time()))
    await get_redis().set(BEAT_HEARTBEAT_KEY, str(time.time()))
    h = (await client.get("/api/v1/system/health")).json()
    assert h["ok"] is True


async def test_liveness_is_public(client: AsyncClient) -> None:
    assert (await client.get("/api/v1/health")).json() == {"status": "ok"}
