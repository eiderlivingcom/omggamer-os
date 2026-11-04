"""Liveness for the container platform, and a detailed health view for signed-in users."""

import time

from fastapi import APIRouter
from pydantic import BaseModel
from sqlalchemy import text

from app.core.deps import DB, require
from app.core.redis import BEAT_HEARTBEAT_KEY, WORKER_HEARTBEAT_KEY, get_redis
from app.db.models import User

router = APIRouter(tags=["system"])

HEARTBEAT_STALE_SECONDS = 120


class Check(BaseModel):
    name: str
    ok: bool
    detail: str


class Health(BaseModel):
    ok: bool
    checks: list[Check]
    version: str
    migration: str | None


@router.get("/health")
async def liveness() -> dict[str, str]:
    return {"status": "ok"}


async def _heartbeat(key: str, label: str) -> Check:
    try:
        raw = await get_redis().get(key)
    except Exception as exc:
        return Check(name=label, ok=False, detail=f"Redis error: {exc.__class__.__name__}")
    if not raw:
        return Check(name=label, ok=False, detail="No heartbeat received yet")
    age = int(time.time() - float(raw))
    return Check(name=label, ok=age < HEARTBEAT_STALE_SECONDS, detail=f"Last heartbeat {age}s ago")


@router.get("/system/health", response_model=Health)
async def health(db: DB, _: User = require("dashboard:read")) -> Health:
    checks: list[Check] = []
    migration: str | None = None
    try:
        migration = await db.scalar(text("SELECT version_num FROM alembic_version LIMIT 1"))
        checks.append(Check(name="Database", ok=True, detail=f"PostgreSQL connected, schema {migration}"))
    except Exception as exc:
        checks.append(Check(name="Database", ok=False, detail=exc.__class__.__name__))
    try:
        pong = await get_redis().ping()
        checks.append(Check(name="Redis", ok=bool(pong), detail="Connected"))
    except Exception as exc:
        checks.append(Check(name="Redis", ok=False, detail=exc.__class__.__name__))
    checks.append(await _heartbeat(WORKER_HEARTBEAT_KEY, "Background workers"))
    checks.append(await _heartbeat(BEAT_HEARTBEAT_KEY, "Scheduler"))
    from app import __version__

    return Health(ok=all(c.ok for c in checks), checks=checks, version=__version__, migration=migration)
