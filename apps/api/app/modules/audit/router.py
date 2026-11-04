import uuid
from datetime import datetime
from typing import Any

from fastapi import APIRouter, Query
from pydantic import BaseModel
from sqlalchemy import select

from app.core.deps import DB, require
from app.db.models import AuditLog, User

router = APIRouter(prefix="/audit-logs", tags=["audit"])


class AuditOut(BaseModel):
    id: uuid.UUID
    created_at: datetime
    actor_email: str | None
    action: str
    entity_type: str | None
    entity_id: str | None
    summary: str
    data: dict[str, Any]
    ip: str | None


class AuditPage(BaseModel):
    items: list[AuditOut]
    next_before: datetime | None


@router.get("", response_model=AuditPage)
async def list_audit(
    db: DB,
    _: User = require("audit:read"),
    action: str | None = Query(default=None, max_length=80),
    actor: str | None = Query(default=None, max_length=320),
    before: datetime | None = None,
    limit: int = Query(default=50, ge=1, le=200),
) -> AuditPage:
    q = select(AuditLog).order_by(AuditLog.created_at.desc()).limit(limit + 1)
    if action:
        q = q.where(AuditLog.action.startswith(action))
    if actor:
        q = q.where(AuditLog.actor_email.ilike(f"%{actor}%"))
    if before:
        q = q.where(AuditLog.created_at < before)
    rows = list((await db.execute(q)).scalars().all())
    more = len(rows) > limit
    rows = rows[:limit]
    return AuditPage(
        items=[
            AuditOut(
                id=r.id,
                created_at=r.created_at,
                actor_email=r.actor_email,
                action=r.action,
                entity_type=r.entity_type,
                entity_id=r.entity_id,
                summary=r.summary,
                data=r.data,
                ip=str(r.ip) if r.ip else None,
            )
            for r in rows
        ],
        next_before=rows[-1].created_at if more and rows else None,
    )
