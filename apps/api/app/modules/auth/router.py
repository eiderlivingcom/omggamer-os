"""Sign in, token refresh with rotation, sign out, password change and two-factor authentication."""

import uuid
from datetime import UTC, datetime, timedelta

from fastapi import APIRouter, Request, Response
from sqlalchemy import func, select, update

from app.core.config import get_settings
from app.core.deps import ACCESS_COOKIE, DB, REFRESH_COOKIE, CurrentUser
from app.core.errors import AppError
from app.core.redis import get_redis
from app.core.security import (
    create_access_token,
    decrypt_secret,
    encrypt_secret,
    hash_password,
    hash_token,
    new_refresh_token,
    new_totp_secret,
    password_needs_rehash,
    totp_uri,
    validate_password_strength,
    verify_password,
    verify_totp,
)
from app.db.models import RefreshToken, User
from app.modules.audit.service import client_ip, record
from app.modules.auth.schemas import LoginIn, LoginOut, PasswordChangeIn, TotpCodeIn, TotpSetupOut, UserOut

router = APIRouter(prefix="/auth", tags=["auth"])

# A real hash so that unknown e-mails take as long as wrong passwords.
_DUMMY_HASH = hash_password("not-a-real-password-0")
REFRESH_PATH = "/api/v1/auth"


def _set_session_cookies(response: Response, access: str, refresh: str) -> None:
    s = get_settings()
    response.set_cookie(
        ACCESS_COOKIE,
        access,
        max_age=s.access_token_minutes * 60,
        httponly=True,
        secure=s.cookie_secure,
        samesite="lax",
        path="/",
    )
    response.set_cookie(
        REFRESH_COOKIE,
        refresh,
        max_age=s.refresh_token_days * 86400,
        httponly=True,
        secure=s.cookie_secure,
        samesite="strict",
        path=REFRESH_PATH,
    )


def _clear_session_cookies(response: Response) -> None:
    response.delete_cookie(ACCESS_COOKIE, path="/")
    response.delete_cookie(REFRESH_COOKIE, path=REFRESH_PATH)


async def _issue_session(
    db: DB, user: User, request: Request, response: Response, family: uuid.UUID | None = None
) -> RefreshToken:
    s = get_settings()
    token, token_h = new_refresh_token()
    rt = RefreshToken(
        user_id=user.id,
        token_hash=token_h,
        family_id=family or uuid.uuid4(),
        expires_at=datetime.now(UTC) + timedelta(days=s.refresh_token_days),
        user_agent=(request.headers.get("user-agent") or "")[:400],
        ip=client_ip(request),
    )
    db.add(rt)
    await db.flush()
    _set_session_cookies(response, create_access_token(user.id, user.role.key, user.token_version), token)
    return rt


async def _rate_limited(key: str) -> bool:
    s = get_settings()
    r = get_redis()
    n = await r.incr(key)
    if n == 1:
        await r.expire(key, s.login_window_seconds)
    return int(n) > s.login_max_attempts


@router.post("/login", response_model=LoginOut)
async def login(body: LoginIn, request: Request, response: Response, db: DB) -> LoginOut:
    email = body.email.lower().strip()
    ip = client_ip(request) or "unknown"
    if await _rate_limited(f"omgos:login:{ip}:{email}"):
        raise AppError(429, "too_many_attempts", "Too many sign-in attempts. Wait 15 minutes and try again.")

    user = (await db.execute(select(User).where(func.lower(User.email) == email))).scalar_one_or_none()
    ok = verify_password(user.password_hash if user else _DUMMY_HASH, body.password)
    if not user or not ok or not user.is_active:
        await record(db, "auth.login_failed", actor_email=email, summary="Failed sign-in", request=request)
        await db.commit()
        raise AppError(401, "invalid_credentials", "The e-mail or password is not correct.")

    if user.totp_enabled:
        if not body.totp_code:
            return LoginOut(status="2fa_required")
        if not user.totp_secret_enc or not verify_totp(decrypt_secret(user.totp_secret_enc), body.totp_code):
            await record(db, "auth.2fa_failed", actor=user, summary="Wrong two-factor code", request=request)
            await db.commit()
            raise AppError(401, "invalid_2fa_code", "The two-factor code is not correct.")

    if password_needs_rehash(user.password_hash):
        user.password_hash = hash_password(body.password)
    user.last_login_at = datetime.now(UTC)
    await _issue_session(db, user, request, response)
    await record(db, "auth.login", actor=user, summary="Signed in", request=request)
    await db.commit()
    await get_redis().delete(f"omgos:login:{ip}:{email}")
    return LoginOut(status="ok", user=UserOut.of(user))


@router.post("/refresh", response_model=UserOut)
async def refresh(request: Request, response: Response, db: DB) -> UserOut:
    token = request.cookies.get(REFRESH_COOKIE)
    if not token:
        raise AppError(401, "not_authenticated", "Sign in to continue.")
    rt = (
        await db.execute(select(RefreshToken).where(RefreshToken.token_hash == hash_token(token)))
    ).scalar_one_or_none()
    now = datetime.now(UTC)
    if rt is None:
        _clear_session_cookies(response)
        raise AppError(401, "invalid_token", "Your session has expired. Sign in again.")
    if rt.revoked_at is not None:
        # A rotated token was used again: treat the whole session family as stolen.
        await db.execute(
            update(RefreshToken)
            .where(RefreshToken.family_id == rt.family_id, RefreshToken.revoked_at.is_(None))
            .values(revoked_at=now)
        )
        await record(
            db,
            "auth.refresh_reuse",
            actor_email=None,
            entity_type="user",
            entity_id=str(rt.user_id),
            summary="Reused session token; all sessions in that family were signed out",
            request=request,
        )
        await db.commit()
        _clear_session_cookies(response)
        raise AppError(401, "invalid_token", "Your session has expired. Sign in again.")
    if rt.expires_at < now:
        _clear_session_cookies(response)
        raise AppError(401, "invalid_token", "Your session has expired. Sign in again.")
    user = (await db.execute(select(User).where(User.id == rt.user_id))).scalar_one_or_none()
    if user is None or not user.is_active:
        _clear_session_cookies(response)
        raise AppError(401, "invalid_token", "Your session has expired. Sign in again.")
    new_rt = await _issue_session(db, user, request, response, family=rt.family_id)
    rt.revoked_at = now
    rt.replaced_by = new_rt.id
    await db.commit()
    return UserOut.of(user)


@router.post("/logout", status_code=204)
async def logout(request: Request, response: Response, db: DB) -> Response:
    token = request.cookies.get(REFRESH_COOKIE)
    if token:
        rt = (
            await db.execute(select(RefreshToken).where(RefreshToken.token_hash == hash_token(token)))
        ).scalar_one_or_none()
        if rt and rt.revoked_at is None:
            await db.execute(
                update(RefreshToken)
                .where(RefreshToken.family_id == rt.family_id, RefreshToken.revoked_at.is_(None))
                .values(revoked_at=datetime.now(UTC))
            )
            await record(
                db,
                "auth.logout",
                entity_type="user",
                entity_id=str(rt.user_id),
                summary="Signed out",
                request=request,
            )
            await db.commit()
    _clear_session_cookies(response)
    response.status_code = 204
    return response


@router.get("/me", response_model=UserOut)
async def me(user: CurrentUser) -> UserOut:
    return UserOut.of(user)


@router.post("/password", response_model=UserOut)
async def change_password(
    body: PasswordChangeIn, request: Request, response: Response, user: CurrentUser, db: DB
) -> UserOut:
    if not verify_password(user.password_hash, body.current_password):
        raise AppError(400, "wrong_password", "Your current password is not correct.")
    weak = validate_password_strength(body.new_password)
    if weak:
        raise AppError(422, "weak_password", weak)
    if verify_password(user.password_hash, body.new_password):
        raise AppError(422, "same_password", "Choose a password you have not used here before.")
    user.password_hash = hash_password(body.new_password)
    user.must_change_password = False
    user.token_version += 1  # signs out every other session
    await db.execute(
        update(RefreshToken)
        .where(RefreshToken.user_id == user.id, RefreshToken.revoked_at.is_(None))
        .values(revoked_at=datetime.now(UTC))
    )
    await _issue_session(db, user, request, response)
    await record(db, "auth.password_changed", actor=user, summary="Changed password", request=request)
    await db.commit()
    return UserOut.of(user)


@router.post("/2fa/setup", response_model=TotpSetupOut)
async def totp_setup(user: CurrentUser, db: DB) -> TotpSetupOut:
    if user.totp_enabled:
        raise AppError(409, "2fa_already_enabled", "Two-factor authentication is already on.")
    secret = new_totp_secret()
    user.totp_secret_enc = encrypt_secret(secret)
    await db.commit()
    return TotpSetupOut(secret=secret, otpauth_uri=totp_uri(secret, user.email))


@router.post("/2fa/enable", response_model=UserOut)
async def totp_enable(body: TotpCodeIn, request: Request, user: CurrentUser, db: DB) -> UserOut:
    if not user.totp_secret_enc:
        raise AppError(400, "2fa_not_started", "Start the two-factor setup first.")
    if not verify_totp(decrypt_secret(user.totp_secret_enc), body.code):
        raise AppError(
            400, "invalid_2fa_code", "The code is not correct. Check the time on your phone and try again."
        )
    user.totp_enabled = True
    await record(
        db, "auth.2fa_enabled", actor=user, summary="Turned on two-factor authentication", request=request
    )
    await db.commit()
    return UserOut.of(user)


@router.post("/2fa/disable", response_model=UserOut)
async def totp_disable(body: TotpCodeIn, request: Request, user: CurrentUser, db: DB) -> UserOut:
    if not user.totp_enabled or not user.totp_secret_enc:
        raise AppError(400, "2fa_not_enabled", "Two-factor authentication is not on.")
    if not verify_totp(decrypt_secret(user.totp_secret_enc), body.code):
        raise AppError(400, "invalid_2fa_code", "The code is not correct.")
    user.totp_enabled = False
    user.totp_secret_enc = None
    await record(
        db, "auth.2fa_disabled", actor=user, summary="Turned off two-factor authentication", request=request
    )
    await db.commit()
    return UserOut.of(user)
