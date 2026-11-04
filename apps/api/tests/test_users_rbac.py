from httpx import AsyncClient
from sqlalchemy import select

from app.db.models import AuditLog
from app.db.session import get_sessionmaker
from tests.conftest import MakeUser, login


async def test_super_admin_creates_user_with_temporary_password(
    client: AsyncClient, make_user: MakeUser
) -> None:
    await make_user()
    await login(client)
    r = await client.post(
        "/api/v1/users", json={"email": "Content@OMGGamer.com", "name": "Content", "role": "content_manager"}
    )
    assert r.status_code == 201, r.text
    data = r.json()
    assert data["user"]["email"] == "content@omggamer.com" and data["user"]["must_change_password"] is True
    temp = data["temporary_password"]
    assert len(temp) >= 16

    dup = await client.post(
        "/api/v1/users", json={"email": "content@omggamer.com", "name": "x", "role": "viewer"}
    )
    assert dup.status_code == 409

    async with get_sessionmaker()() as db:
        actions = (await db.execute(select(AuditLog.action))).scalars().all()
    assert "user.created" in actions

    await client.post("/api/v1/auth/logout")
    await login(client, "content@omggamer.com", temp)


async def test_viewer_cannot_manage_users_or_read_audit(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user("viewer@omggamer.com", role="viewer")
    await login(client, "viewer@omggamer.com")
    assert (await client.get("/api/v1/dashboard/summary")).status_code == 200
    r = await client.post("/api/v1/users", json={"email": "x@omggamer.com", "name": "x", "role": "viewer"})
    assert r.status_code == 403 and r.json()["error"]["details"]["missing"] == ["users:manage"]
    assert (await client.get("/api/v1/audit-logs")).status_code == 403


async def test_administrator_cannot_grant_super_admin(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user("ops@omggamer.com", role="administrator")
    await login(client, "ops@omggamer.com")
    r = await client.post(
        "/api/v1/users", json={"email": "boss@omggamer.com", "name": "Boss", "role": "super_admin"}
    )
    assert r.status_code == 403


async def test_last_super_admin_is_protected(client: AsyncClient, make_user: MakeUser) -> None:
    me = await make_user()
    await login(client)
    r = await client.patch(f"/api/v1/users/{me.id}", json={"role": "viewer"})
    assert r.status_code == 422 and r.json()["error"]["code"] == "last_super_admin"
    r = await client.patch(f"/api/v1/users/{me.id}", json={"is_active": False})
    assert r.status_code == 422


async def test_role_change_signs_the_user_out(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    other = await make_user("pm@omggamer.com", role="pricing_manager")
    await login(client)
    from httpx import ASGITransport
    from httpx import AsyncClient as C

    from app.main import app

    async with C(
        transport=ASGITransport(app=app),
        base_url="http://testserver",
        headers={"origin": "http://testserver"},
    ) as c2:
        await login(c2, "pm@omggamer.com")
        assert (await c2.get("/api/v1/auth/me")).status_code == 200
        r = await client.patch(f"/api/v1/users/{other.id}", json={"role": "viewer"})
        assert r.status_code == 200 and r.json()["role"] == "viewer"
        assert (await c2.get("/api/v1/auth/me")).status_code == 401


async def test_roles_endpoint_lists_all_seven(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    data = (await client.get("/api/v1/roles")).json()
    assert [r["key"] for r in data["roles"]] == [
        "super_admin",
        "administrator",
        "catalogue_manager",
        "content_manager",
        "pricing_manager",
        "order_manager",
        "viewer",
    ]
    assert len(data["permissions"]) == 20
