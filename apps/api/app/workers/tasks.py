"""Celery tasks. Import jobs run in the `imports` queue; AI and publishing tasks arrive in later phases."""

import asyncio
import time
import uuid
from datetime import UTC, datetime, timedelta
from typing import TYPE_CHECKING

from sqlalchemy import select, update
from sqlalchemy.ext.asyncio import create_async_engine
from sqlalchemy.orm import Session
from sqlalchemy.pool import NullPool

from app.core.logging import log
from app.db.models.settings import AppSetting as AppSettingValue
from app.workers.celery_app import ReliableTask, celery_app, settings, sync_engine

if TYPE_CHECKING:
    from app.ai.runner import AiOutcome
    from app.imports.runner import Outcome

STALE_AFTER = timedelta(minutes=10)
QUEUED_GRACE = timedelta(minutes=2)


@celery_app.task(base=ReliableTask, name="system.ping")  # type: ignore[misc]
def ping() -> dict[str, float]:
    """Round-trip check used by the health page and deploy smoke test."""
    return {"pong": time.time()}


async def _run_import(job_id: str) -> "Outcome":
    from app.imports.runner import ImportRunner

    # A fresh engine per task: asyncio.run() creates a new event loop each time.
    engine = create_async_engine(settings.database_url, poolclass=NullPool)
    try:
        return await ImportRunner(engine).run(uuid.UUID(job_id))
    finally:
        await engine.dispose()


@celery_app.task(base=ReliableTask, name="imports.run")  # type: ignore[misc]
def run_import(job_id: str) -> str:
    """Process one chunk of an import job and queue the next chunk until the job is done."""
    outcome = asyncio.run(_run_import(job_id))
    if outcome.state == "continue":
        run_import.apply_async((job_id,), queue="imports")
    elif outcome.state == "retry_later":
        run_import.apply_async((job_id,), queue="imports", countdown=outcome.delay)
    if outcome.follow_up_job_id:
        run_import.apply_async((str(outcome.follow_up_job_id),), queue="imports", countdown=5)
    log.info("import_chunk", job_id=job_id, state=outcome.state)
    return str(outcome.state)


@celery_app.task(base=ReliableTask, name="imports.watchdog")  # type: ignore[misc]
def import_watchdog() -> int:
    """Re-queue imports whose worker died (no progress for 10 minutes). The job lock prevents double runs."""
    from app.db.models import ImportJob

    now = datetime.now(UTC)
    requeued = 0
    with Session(sync_engine()) as s:
        jobs = s.execute(
            select(ImportJob).where(
                ((ImportJob.status == "running") & (ImportJob.updated_at < now - STALE_AFTER))
                | ((ImportJob.status == "queued") & (ImportJob.updated_at < now - QUEUED_GRACE))
            )
        ).scalars()
        for job in jobs:
            retry_at = (job.checkpoint or {}).get("retry_at")
            if retry_at and datetime.fromisoformat(retry_at) > now:
                continue
            run_import.apply_async((str(job.id),), queue="imports")
            requeued += 1
    if requeued:
        log.warning("import_watchdog_requeued", jobs=requeued)
    return requeued


async def _run_ai(job_id: str) -> "AiOutcome":
    from app.ai.runner import AiRunner

    engine = create_async_engine(settings.database_url, poolclass=NullPool)
    try:
        return await AiRunner(engine).run(uuid.UUID(job_id))
    finally:
        await engine.dispose()


@celery_app.task(base=ReliableTask, name="ai.generate")  # type: ignore[misc]
def ai_generate(job_id: str) -> str:
    """Generate content for one AI job. Retries and pauses re-schedule the same job."""
    outcome = asyncio.run(_run_ai(job_id))
    if outcome.state == "retry_later":
        ai_generate.apply_async((job_id,), queue="ai", countdown=outcome.delay)
    return str(outcome.state)


@celery_app.task(base=ReliableTask, name="ai.watchdog")  # type: ignore[misc]
def ai_watchdog() -> int:
    """Re-queue AI jobs a dead worker left behind: queued > 5 min or running > 15 min without an update."""
    from app.db.models import AiJob

    now = datetime.now(UTC)
    with Session(sync_engine()) as s:
        paused = (
            s.execute(select(AppSettingValue.value).where(AppSettingValue.key == "ai")).scalar_one_or_none()
            or {}
        )
        if paused.get("paused"):
            return 0
        ids = list(
            s.execute(
                select(AiJob.id)
                .where(
                    ((AiJob.status == "queued") & (AiJob.updated_at < now - timedelta(minutes=5)))
                    | ((AiJob.status == "running") & (AiJob.updated_at < now - timedelta(minutes=15)))
                )
                .limit(500)
            ).scalars()
        )
        if ids:
            s.execute(
                update(AiJob).where(AiJob.id.in_(ids), AiJob.status == "running").values(status="queued")
            )
            s.execute(update(AiJob).where(AiJob.id.in_(ids)).values(updated_at=now))
            s.commit()
    for job_id in ids:
        ai_generate.apply_async((str(job_id),), queue="ai")
    if ids:
        log.warning("ai_watchdog_requeued", jobs=len(ids))
    return len(ids)
