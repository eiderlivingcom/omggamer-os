import pyotp
from httpx import AsyncClient

from tests.conftest import PASSWORD, MakeUser, login


async def test_login_sets_cookies_and_me_works(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    r = await client.post("/api/v1/auth/login", json={"email": "ADMIN@omggamer.com", "password": PASSWORD})
    assert r.status_code == 200
    body = r.json()
    assert body["status"] == "ok" and body["user"]["role"] == "super_admin"
    assert "omg_at" in r.cookies and "omg_rt" in r.cookies
    me = await client.get("/api/v1/auth/me")
    assert me.status_code == 200 and me.json()["email"] == "admin@omggamer.com"
    assert "users:manage" in me.json()["permissions"]


async def test_wrong_password_and_unknown_email_look_the_same(
    client: AsyncClient, make_user: MakeUser
) -> None:
    await make_user()
    a = await client.post("/api/v1/auth/login", json={"email": "admin@omggamer.com", "password": "wrong"})
    b = await client.post("/api/v1/auth/login", json={"email": "nobody@omggamer.com", "password": "wrong"})
    assert a.status_code == b.status_code == 401
    assert a.json()["error"]["code"] == b.json()["error"]["code"] == "invalid_credentials"


async def test_inactive_user_cannot_sign_in(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user(active=False)
    r = await client.post("/api/v1/auth/login", json={"email": "admin@omggamer.com", "password": PASSWORD})
    assert r.status_code == 401


async def test_login_rate_limit(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    for _ in range(5):
        await client.post("/api/v1/auth/login", json={"email": "admin@omggamer.com", "password": "nope"})
    r = await client.post("/api/v1/auth/login", json={"email": "admin@omggamer.com", "password": PASSWORD})
    assert r.status_code == 429 and r.json()["error"]["code"] == "too_many_attempts"


async def test_requests_without_session_are_rejected(client: AsyncClient) -> None:
    r = await client.get("/api/v1/auth/me")
    assert r.status_code == 401
    r = await client.get("/api/v1/dashboard/summary")
    assert r.status_code == 401


async def test_refresh_rotates_and_reuse_kills_the_family(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    old_rt = client.cookies.get("omg_rt", path="/api/v1/auth")
    r = await client.post("/api/v1/auth/refresh")
    assert r.status_code == 200
    new_rt = client.cookies.get("omg_rt", path="/api/v1/auth")
    assert new_rt and new_rt != old_rt
    # Re-use the rotated token: the whole family is revoked, including the new token.
    client.cookies.clear()
    assert (
        await client.post("/api/v1/auth/refresh", headers={"cookie": f"omg_rt={old_rt}"})
    ).status_code == 401
    assert (
        await client.post("/api/v1/auth/refresh", headers={"cookie": f"omg_rt={new_rt}"})
    ).status_code == 401


async def test_logout_revokes_refresh(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    rt = client.cookies.get("omg_rt", path="/api/v1/auth")
    assert (await client.post("/api/v1/auth/logout")).status_code == 204
    client.cookies.clear()
    assert (await client.post("/api/v1/auth/refresh", headers={"cookie": f"omg_rt={rt}"})).status_code == 401


async def test_must_change_password_blocks_everything_else(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user(must_change=True)
    await login(client)
    assert (await client.get("/api/v1/dashboard/summary")).json()["error"][
        "code"
    ] == "password_change_required"
    weak = await client.post(
        "/api/v1/auth/password", json={"current_password": PASSWORD, "new_password": "aaaaaaaaaaaa"}
    )
    assert weak.status_code == 422
    ok = await client.post(
        "/api/v1/auth/password", json={"current_password": PASSWORD, "new_password": "New-Stronger-Pass-7"}
    )
    assert ok.status_code == 200 and ok.json()["must_change_password"] is False
    assert (await client.get("/api/v1/dashboard/summary")).status_code == 200


async def test_two_factor_flow(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    setup = (await client.post("/api/v1/auth/2fa/setup")).json()
    totp = pyotp.TOTP(setup["secret"])
    assert (await client.post("/api/v1/auth/2fa/enable", json={"code": "000000"})).status_code == 400
    assert (await client.post("/api/v1/auth/2fa/enable", json={"code": totp.now()})).json()[
        "totp_enabled"
    ] is True
    await client.post("/api/v1/auth/logout")
    step1 = await client.post(
        "/api/v1/auth/login", json={"email": "admin@omggamer.com", "password": PASSWORD}
    )
    assert step1.json() == {"status": "2fa_required", "user": None}
    assert "omg_at" not in step1.cookies
    bad = await client.post(
        "/api/v1/auth/login",
        json={"email": "admin@omggamer.com", "password": PASSWORD, "totp_code": "123456"},
    )
    assert bad.status_code == 401
    good = await client.post(
        "/api/v1/auth/login",
        json={"email": "admin@omggamer.com", "password": PASSWORD, "totp_code": totp.now()},
    )
    assert good.json()["status"] == "ok"


async def test_cross_origin_write_is_blocked(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    r = await client.post(
        "/api/v1/auth/login",
        json={"email": "admin@omggamer.com", "password": PASSWORD},
        headers={"origin": "https://evil.example"},
    )
    assert r.status_code == 403 and r.json()["error"]["code"] == "bad_origin"
