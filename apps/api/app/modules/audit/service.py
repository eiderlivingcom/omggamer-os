"""Writes audit log entries. Every state-changing endpoint calls record()."""

from typing import Any

from fastapi import Request
from sqlalchemy.ext.asyncio import AsyncSession

from app.db.models import AuditLog, User


def client_ip(request: Request | None) -> str | None:
    if request is None:
        return None
    fwd = (
        request.headers.get("cf-connecting-ip")
        or request.headers.get("x-forwarded-for", "").split(",")[0].strip()
    )
    ip = fwd or (request.client.host if request.client else None)
    if ip and ip.replace(".", "").replace(":", "").isalnum():
        return ip
    return None


async def record(
    db: AsyncSession,
    action: str,
    *,
    actor: User | None = None,
    actor_email: str | None = None,
    entity_type: str | None = None,
    entity_id: str | None = None,
    summary: str = "",
    data: dict[str, Any] | None = None,
    request: Request | None = None,
) -> None:
    db.add(
        AuditLog(
            actor_id=actor.id if actor else None,
            actor_email=actor.email if actor else actor_email,
            action=action,
            entity_type=entity_type,
            entity_id=entity_id,
            summary=summary[:400],
            data=data or {},
            ip=client_ip(request),
        )
    )
