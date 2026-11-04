"""Tests run against a real PostgreSQL and Redis (TEST_DATABASE_URL / TEST_REDIS_URL)."""

import os
from collections.abc import AsyncIterator, Awaitable, Callable

os.environ.setdefault("TEST_DATABASE_URL", "postgresql+asyncpg://postgres@127.0.0.1:55432/omg_test")
os.environ.setdefault("TEST_REDIS_URL", "redis://127.0.0.1:56379/15")
os.environ.update(
    {
        "ENVIRONMENT": "test",
        "DATABASE_URL": os.environ["TEST_DATABASE_URL"],
        "REDIS_URL": os.environ["TEST_REDIS_URL"],
        "SECRET_KEY": "test-secret-key-test-secret-key-test-secret-key-0123456789",
        "ENCRYPTION_KEY": "2y8k3nKqzT5pX1bJm9cVfQ7wL0sR4dH6aE3uY8iO1Zk=",
        "APP_ORIGIN": "http://testserver",
        "COOKIE_SECURE": "false",
        "LOGIN_MAX_ATTEMPTS": "5",
        "LOG_LEVEL": "WARNING",
    }
)

import pytest
from httpx import ASGITransport, AsyncClient
from sqlalchemy import create_engine, select, text

from alembic import command
from alembic.config import Config
from app.core.config import get_settings
from app.core.redis import get_redis
from app.core.security import hash_password
from app.db.models import Role, User
from app.db.session import get_sessionmaker

PASSWORD = "Correct-Horse-42!"
DATA_TABLES = [
    "refresh_tokens",
    "audit_logs",
    "dead_letters",
    "ai_usage",
    "ai_jobs",
    "publish_jobs",
    "channel_listings",
    "import_job_chunks",
    "import_jobs",
    "supplier_offers",
    "supplier_products",
    "product_variants",
    "products",
    "webhook_inbox",
    "users",
]


@pytest.fixture(scope="session", autouse=True)
def database() -> None:
    url = get_settings().sync_database_url
    eng = create_engine(url)
    with eng.begin() as c:
        c.execute(text("DROP SCHEMA public CASCADE; CREATE SCHEMA public;"))
    eng.dispose()
    cfg = Config(os.path.join(os.path.dirname(__file__), "..", "alembic.ini"))
    cfg.set_main_option("script_location", os.path.join(os.path.dirname(__file__), "..", "alembic"))
    command.upgrade(cfg, "head")


@pytest.fixture(autouse=True)
async def clean() -> AsyncIterator[None]:
    async with get_sessionmaker()() as db:
        await db.execute(text(f"TRUNCATE {', '.join(DATA_TABLES)} RESTART IDENTITY CASCADE"))
        await db.commit()
    await get_redis().flushdb()
    yield


@pytest.fixture
async def client() -> AsyncIterator[AsyncClient]:
    from app.main import app

    async with AsyncClient(
        transport=ASGITransport(app=app),
        base_url="http://testserver",
        headers={"origin": "http://testserver"},
    ) as c:
        yield c


MakeUser = Callable[..., Awaitable[User]]


@pytest.fixture
def make_user() -> MakeUser:
    async def _make(
        email: str = "admin@omggamer.com",
        role: str = "super_admin",
        password: str = PASSWORD,
        must_change: bool = False,
        active: bool = True,
    ) -> User:
        async with get_sessionmaker()() as db:
            r = (await db.execute(select(Role).where(Role.key == role))).scalar_one()
            u = User(
                email=email,
                name=email.split("@")[0],
                password_hash=hash_password(password),
                role_id=r.id,
                must_change_password=must_change,
                is_active=active,
            )
            db.add(u)
            await db.commit()
            await db.refresh(u)
            return u

    return _make


async def login(client: AsyncClient, email: str = "admin@omggamer.com", password: str = PASSWORD) -> None:
    r = await client.post("/api/v1/auth/login", json={"email": email, "password": password})
    assert r.status_code == 200, r.text
    assert r.json()["status"] == "ok"
