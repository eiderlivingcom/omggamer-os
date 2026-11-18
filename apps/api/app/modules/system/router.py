"""Liveness for the container platform, and a detailed health view for signed-in users."""

import time

from fastapi import APIRouter
from pydantic import BaseModel
from sqlalchemy import text

from app.core.deps import DB, CurrentUser, require
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
        r = get_redis()
        await r.ping()
        mem = await r.info("memory")
        used, top = int(mem.get("used_memory", 0)), int(mem.get("maxmemory", 0))
        pct = round(used * 100 / top) if top else 0
        detail = "Connected"
        if top:
            detail = f"Connected, {used // 1048576} MB of {top // 1048576} MB used ({pct}%)"
        checks.append(Check(name="Redis", ok=pct < 90, detail=detail))
    except Exception as exc:
        checks.append(Check(name="Redis", ok=False, detail=exc.__class__.__name__))
    checks.append(await _heartbeat(WORKER_HEARTBEAT_KEY, "Background workers"))
    checks.append(await _heartbeat(BEAT_HEARTBEAT_KEY, "Scheduler"))
    from app import __version__

    return Health(ok=all(c.ok for c in checks), checks=checks, version=__version__, migration=migration)


@router.post("/system/redis/clear")
async def clear_redis(actor: CurrentUser, _: User = require("settings:manage")) -> dict:  # type: ignore[type-arg]
    """Clear the queue cache now: task results and the AI queue (AI jobs resume from the database)."""
    import asyncio

    from redis import Redis

    from app.core.config import get_settings
    from app.core.redis_maintenance import clear, status

    def run() -> dict:  # type: ignore[type-arg]
        client = Redis.from_url(get_settings().redis_url, decode_responses=True)
        try:
            removed = clear(client, ai_queue=True)
            return {"removed": removed, "status": status(client)}
        finally:
            client.close()

    return await asyncio.to_thread(run)
