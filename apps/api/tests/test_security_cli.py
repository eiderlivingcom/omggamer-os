import pytest
from sqlalchemy import select

from app.cli import bootstrap
from app.core.config import get_settings
from app.core.security import decrypt_secret, encrypt_secret, validate_password_strength
from app.db.models import User
from app.db.session import get_sessionmaker


def test_secret_round_trip_and_tamper_detection() -> None:
    token = encrypt_secret("kinguin-api-key-123")
    assert token != "kinguin-api-key-123"
    assert decrypt_secret(token) == "kinguin-api-key-123"
    with pytest.raises(ValueError):
        decrypt_secret(token[:-4] + "AAAA")


@pytest.mark.parametrize(
    ("pw", "ok"),
    [
        ("short1!", False),
        ("alllowercaseletters", False),
        ("Lower-and-1234", True),
        ("NoSymbolsButDigits99", True),
    ],
)
def test_password_strength(pw: str, ok: bool) -> None:
    assert (validate_password_strength(pw) is None) is ok


async def test_bootstrap_creates_first_admin_once(monkeypatch: pytest.MonkeyPatch) -> None:
    s = get_settings()
    from pydantic import SecretStr

    monkeypatch.setattr(s, "bootstrap_admin_email", "Owner@OMGGamer.com")
    monkeypatch.setattr(s, "bootstrap_admin_password", SecretStr("First-Admin-Pass-1"))
    assert await bootstrap() == 0
    assert await bootstrap() == 0  # second run is a no-op
    async with get_sessionmaker()() as db:
        users = (await db.execute(select(User))).scalars().all()
    assert len(users) == 1 and users[0].email == "owner@omggamer.com" and users[0].must_change_password
