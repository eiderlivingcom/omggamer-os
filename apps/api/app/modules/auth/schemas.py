import uuid
from datetime import datetime

from pydantic import BaseModel, EmailStr, Field

from app.core.rbac import permissions_for
from app.db.models import User


class LoginIn(BaseModel):
    email: EmailStr
    password: str = Field(min_length=1, max_length=200)
    totp_code: str | None = Field(default=None, max_length=12)


class UserOut(BaseModel):
    id: uuid.UUID
    email: str
    name: str
    role: str
    role_name: str
    permissions: list[str]
    is_active: bool
    must_change_password: bool
    totp_enabled: bool
    last_login_at: datetime | None
    created_at: datetime

    @classmethod
    def of(cls, u: User) -> "UserOut":
        return cls(
            id=u.id,
            email=u.email,
            name=u.name,
            role=u.role.key,
            role_name=u.role.name,
            permissions=sorted(permissions_for(u.role.key)),
            is_active=u.is_active,
            must_change_password=u.must_change_password,
            totp_enabled=u.totp_enabled,
            last_login_at=u.last_login_at,
            created_at=u.created_at,
        )


class LoginOut(BaseModel):
    status: str  # "ok" | "2fa_required"
    user: UserOut | None = None


class PasswordChangeIn(BaseModel):
    current_password: str = Field(min_length=1, max_length=200)
    new_password: str = Field(min_length=12, max_length=200)


class TotpSetupOut(BaseModel):
    secret: str
    otpauth_uri: str


class TotpCodeIn(BaseModel):
    code: str = Field(min_length=6, max_length=12)
