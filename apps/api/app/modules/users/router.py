"""User management: list, create, update role/status, reset password."""

import secrets
import string
import uuid

from fastapi import APIRouter, Request
from pydantic import BaseModel, EmailStr, Field
from sqlalchemy import func, select, update

from app.core.deps import DB, CurrentUser, require
from app.core.errors import AppError
from app.core.security import hash_password
from app.db.models import RefreshToken, Role, User
from app.modules.audit.service import record
from app.modules.auth.schemas import UserOut

router = APIRouter(prefix="/users", tags=["users"])
Manage = require("users:manage")


class UserCreateIn(BaseModel):
    email: EmailStr
    name: str = Field(min_length=1, max_length=160)
    role: str = Field(min_length=1, max_length=64)


class UserUpdateIn(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=160)
    role: str | None = None
    is_active: bool | None = None


class UserCreatedOut(BaseModel):
    user: UserOut
    temporary_password: str


def _temp_password() -> str:
    alphabet = string.ascii_letters + string.digits
    core = "".join(secrets.choice(alphabet) for _ in range(14))
    return f"{core}-{secrets.choice('!#%&*?')}{secrets.randbelow(90) + 10}"


async def _role(db: DB, key: str) -> Role:
    role = (await db.execute(select(Role).where(Role.key == key))).scalar_one_or_none()
    if role is None:
        raise AppError(422, "unknown_role", f"There is no role called {key!r}.")
    return role


def _guard_super_admin(actor: User, role_key: str) -> None:
    if role_key == "super_admin" and actor.role.key != "super_admin":
        raise AppError(403, "forbidden", "Only a Super Admin can give someone the Super Admin role.")


async def _super_admin_count(db: DB) -> int:
    return int(
        await db.scalar(
            select(func.count())
            .select_from(User)
            .join(Role)
            .where(Role.key == "super_admin", User.is_active.is_(True))
        )
        or 0
    )


@router.get("", response_model=list[UserOut])
async def list_users(db: DB, _: User = Manage) -> list[UserOut]:
    users = (await db.execute(select(User).order_by(User.created_at))).scalars().all()
    return [UserOut.of(u) for u in users]


@router.post("", response_model=UserCreatedOut, status_code=201)
async def create_user(
    body: UserCreateIn, request: Request, db: DB, actor: CurrentUser, _: User = Manage
) -> UserCreatedOut:
    email = body.email.lower().strip()
    if await db.scalar(select(func.count()).select_from(User).where(func.lower(User.email) == email)):
        raise AppError(409, "email_taken", "A user with this e-mail already exists.")
    _guard_super_admin(actor, body.role)
    role = await _role(db, body.role)
    temp = _temp_password()
    user = User(
        email=email,
        name=body.name.strip(),
        password_hash=hash_password(temp),
        role_id=role.id,
        must_change_password=True,
    )
    user.role = role
    db.add(user)
    await db.flush()
    await record(
        db,
        "user.created",
        actor=actor,
        entity_type="user",
        entity_id=str(user.id),
        summary=f"Created {email} as {role.name}",
        request=request,
    )
    await db.commit()
    await db.refresh(user)
    return UserCreatedOut(user=UserOut.of(user), temporary_password=temp)


@router.patch("/{user_id}", response_model=UserOut)
async def update_user(
    user_id: uuid.UUID, body: UserUpdateIn, request: Request, db: DB, actor: CurrentUser, _: User = Manage
) -> UserOut:
    user = (await db.execute(select(User).where(User.id == user_id))).scalar_one_or_none()
    if user is None:
        raise AppError(404, "not_found", "User not found.")
    if user.role.key == "super_admin" and actor.role.key != "super_admin":
        raise AppError(403, "forbidden", "Only a Super Admin can change another Super Admin.")
    changes: dict[str, object] = {}
    if body.name is not None and body.name.strip() != user.name:
        changes["name"] = [user.name, body.name.strip()]
        user.name = body.name.strip()
    losing_super = False
    if body.role is not None and body.role != user.role.key:
        _guard_super_admin(actor, body.role)
        role = await _role(db, body.role)
        losing_super = user.role.key == "super_admin"
        changes["role"] = [user.role.key, role.key]
        user.role_id = role.id
        user.role = role
        user.token_version += 1
    if body.is_active is not None and body.is_active != user.is_active:
        if user.id == actor.id and not body.is_active:
            raise AppError(422, "cannot_deactivate_self", "You cannot deactivate your own account.")
        losing_super = losing_super or (user.role.key == "super_admin" and not body.is_active)
        changes["is_active"] = [user.is_active, body.is_active]
        user.is_active = body.is_active
        user.token_version += 1
    if losing_super and await _super_admin_count(db) <= 1:
        raise AppError(422, "last_super_admin", "Keep at least one active Super Admin.")
    if changes:
        await record(
            db,
            "user.updated",
            actor=actor,
            entity_type="user",
            entity_id=str(user.id),
            summary=f"Updated {user.email}",
            data=changes,
            request=request,
        )
    await db.commit()
    await db.refresh(user)
    return UserOut.of(user)


@router.post("/{user_id}/reset-password", response_model=UserCreatedOut)
async def reset_password(
    user_id: uuid.UUID, request: Request, db: DB, actor: CurrentUser, _: User = Manage
) -> UserCreatedOut:
    user = (await db.execute(select(User).where(User.id == user_id))).scalar_one_or_none()
    if user is None:
        raise AppError(404, "not_found", "User not found.")
    if user.role.key == "super_admin" and actor.role.key != "super_admin":
        raise AppError(403, "forbidden", "Only a Super Admin can reset a Super Admin's password.")
    temp = _temp_password()
    user.password_hash = hash_password(temp)
    user.must_change_password = True
    user.token_version += 1
    await db.execute(
        update(RefreshToken)
        .where(RefreshToken.user_id == user.id, RefreshToken.revoked_at.is_(None))
        .values(revoked_at=func.now())
    )
    await record(
        db,
        "user.password_reset",
        actor=actor,
        entity_type="user",
        entity_id=str(user.id),
        summary=f"Reset the password of {user.email}",
        request=request,
    )
    await db.commit()
    return UserCreatedOut(user=UserOut.of(user), temporary_password=temp)
