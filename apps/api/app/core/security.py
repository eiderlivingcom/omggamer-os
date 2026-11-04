"""Passwords, tokens, TOTP and encryption of stored credentials."""

import hashlib
import secrets
import uuid
from datetime import UTC, datetime, timedelta
from typing import Any

import jwt
import pyotp
from argon2 import PasswordHasher
from argon2.exceptions import InvalidHashError, VerificationError
from cryptography.fernet import Fernet, InvalidToken

from app.core.config import get_settings

_hasher = PasswordHasher()
ALGORITHM = "HS256"


def hash_password(password: str) -> str:
    return _hasher.hash(password)


def verify_password(password_hash: str, password: str) -> bool:
    try:
        return _hasher.verify(password_hash, password)
    except (VerificationError, InvalidHashError):
        return False


def password_needs_rehash(password_hash: str) -> bool:
    return _hasher.check_needs_rehash(password_hash)


def validate_password_strength(password: str) -> str | None:
    """Returns a human message when the password is too weak, else None."""
    if len(password) < 12:
        return "Use at least 12 characters."
    classes = sum(
        [
            any(c.islower() for c in password),
            any(c.isupper() for c in password),
            any(c.isdigit() for c in password),
            any(not c.isalnum() for c in password),
        ]
    )
    if classes < 3:
        return "Mix at least three of: lowercase, uppercase, numbers and symbols."
    return None


def create_access_token(user_id: uuid.UUID, role: str, token_version: int) -> str:
    s = get_settings()
    now = datetime.now(UTC)
    payload: dict[str, Any] = {
        "sub": str(user_id),
        "role": role,
        "tv": token_version,
        "iat": now,
        "exp": now + timedelta(minutes=s.access_token_minutes),
        "jti": secrets.token_hex(8),
        "typ": "access",
    }
    return jwt.encode(payload, s.secret_key.get_secret_value(), algorithm=ALGORITHM)


def decode_access_token(token: str) -> dict[str, Any]:
    s = get_settings()
    data: dict[str, Any] = jwt.decode(token, s.secret_key.get_secret_value(), algorithms=[ALGORITHM])
    if data.get("typ") != "access":
        raise jwt.InvalidTokenError("wrong token type")
    return data


def new_refresh_token() -> tuple[str, str]:
    """Returns (token for the cookie, sha256 hash to store)."""
    token = secrets.token_urlsafe(48)
    return token, hash_token(token)


def hash_token(token: str) -> str:
    return hashlib.sha256(token.encode()).hexdigest()


def new_totp_secret() -> str:
    return pyotp.random_base32()


def totp_uri(secret: str, email: str) -> str:
    return str(pyotp.TOTP(secret).provisioning_uri(name=email, issuer_name="OMGGamer OS"))


def verify_totp(secret: str, code: str) -> bool:
    code = (code or "").replace(" ", "")
    return bool(code.isdigit() and pyotp.TOTP(secret).verify(code, valid_window=1))


def _fernet() -> Fernet:
    return Fernet(get_settings().encryption_key.get_secret_value().encode())


def encrypt_secret(value: str) -> str:
    return _fernet().encrypt(value.encode()).decode()


def decrypt_secret(value: str) -> str:
    try:
        return _fernet().decrypt(value.encode()).decode()
    except InvalidToken as exc:  # wrong key or tampered value
        raise ValueError("Stored secret could not be decrypted with the current key.") from exc
