"""Request dependencies: the signed-in user and permission checks."""

import uuid
from typing import Annotated, Any

import jwt
from fastapi import Depends, Request
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.errors import AppError
from app.core.rbac import permissions_for
from app.core.security import decode_access_token
from app.db.models import User
from app.db.session import get_db

ACCESS_COOKIE = "omg_at"
REFRESH_COOKIE = "omg_rt"

# Endpoints a user who must change their password can still call.
PASSWORD_CHANGE_ALLOWED = {
    "/api/v1/auth/me",
    "/api/v1/auth/password",
    "/api/v1/auth/logout",
    "/api/v1/auth/refresh",
}

DB = Annotated[AsyncSession, Depends(get_db)]


def _token_from(request: Request) -> str | None:
    auth = request.headers.get("authorization", "")
    if auth.lower().startswith("bearer "):
        return auth[7:].strip()
    return request.cookies.get(ACCESS_COOKIE)


async def current_user(request: Request, db: DB) -> User:
    token = _token_from(request)
    if not token:
        raise AppError(401, "not_authenticated", "Sign in to continue.")
    try:
        claims = decode_access_token(token)
        user_id = uuid.UUID(claims["sub"])
    except (jwt.PyJWTError, KeyError, ValueError) as exc:
        raise AppError(401, "invalid_token", "Your session has expired. Sign in again.") from exc
    user = (await db.execute(select(User).where(User.id == user_id))).scalar_one_or_none()
    if user is None or not user.is_active or user.token_version != claims.get("tv"):
        raise AppError(401, "invalid_token", "Your session has expired. Sign in again.")
    if user.must_change_password and request.url.path not in PASSWORD_CHANGE_ALLOWED:
        raise AppError(403, "password_change_required", "Choose a new password before continuing.")
    request.state.user = user
    return user


CurrentUser = Annotated[User, Depends(current_user)]


def require(*perms: str) -> Any:
    """Depends() that requires the user's role to grant every listed permission.

    Use as a parameter default: `_: User = require("users:manage")`.
    """

    async def _check(user: CurrentUser) -> User:
        have = permissions_for(user.role.key)
        missing = [p for p in perms if p not in have]
        if missing:
            raise AppError(403, "forbidden", "Your role does not allow this action.", {"missing": missing})
        return user

    return Depends(_check)
