from fastapi import APIRouter
from pydantic import BaseModel
from sqlalchemy import func, select

from app.core.deps import DB, CurrentUser
from app.core.rbac import PERMISSIONS
from app.db.models import Role, User

router = APIRouter(prefix="/roles", tags=["roles"])


class RoleOut(BaseModel):
    key: str
    name: str
    permissions: list[str]
    users: int


class PermissionOut(BaseModel):
    key: str
    description: str


class RolesOut(BaseModel):
    roles: list[RoleOut]
    permissions: list[PermissionOut]


ORDER = [
    "super_admin",
    "administrator",
    "catalogue_manager",
    "content_manager",
    "pricing_manager",
    "order_manager",
    "viewer",
]


@router.get("", response_model=RolesOut)
async def list_roles(db: DB, _: CurrentUser) -> RolesOut:
    counts = dict(
        (await db.execute(select(User.role_id, func.count()).group_by(User.role_id))).tuples().all()
    )
    roles = (await db.execute(select(Role))).scalars().all()
    roles = sorted(roles, key=lambda r: ORDER.index(r.key) if r.key in ORDER else 99)
    return RolesOut(
        roles=[
            RoleOut(
                key=r.key,
                name=r.name,
                permissions=sorted(p.key for p in r.permissions),
                users=counts.get(r.id, 0),
            )
            for r in roles
        ],
        permissions=[PermissionOut(key=k, description=v) for k, v in PERMISSIONS.items()],
    )
