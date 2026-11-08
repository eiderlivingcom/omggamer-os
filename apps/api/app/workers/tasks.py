"""Celery tasks. Import jobs run in the `imports` queue; AI and publishing tasks arrive in later phases."""

import asyncio
import time
import uuid
from datetime import UTC, datetime, timedelta
from typing import TYPE_CHECKING

from sqlalchemy import func, select, update
from sqlalchemy.ext.asyncio import create_async_engine
from sqlalchemy.orm import Session
from sqlalchemy.pool import NullPool

from app.core.logging import log
from app.db.models.settings import AppSetting as AppSettingValue
from app.workers.celery_app import ReliableTask, celery_app, settings, sync_engine

if TYPE_CHECKING:
    from sqlalchemy.ext.asyncio import AsyncEngine

    from app.ai.runner import AiOutcome
    from app.imports.runner import Outcome
    from app.orders.fulfil import FulfilOutcome
    from app.publishing.runner import PublishOutcome

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
    if outcome.state == "done":
        celery_app.send_task("sync.push", queue="publish", countdown=10)  # new supplier prices → storefronts
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


async def _recalc_all(storefront: str | None) -> dict[str, int]:
    from sqlalchemy.ext.asyncio import async_sessionmaker

    from app.catalogue.bulk import default_storefront
    from app.db.models import ProductVariant, Storefront
    from app.pricing.engine import PricingEngine

    engine = create_async_engine(settings.database_url, poolclass=NullPool)
    totals: dict[str, int] = {}
    try:
        sm = async_sessionmaker(engine, expire_on_commit=False)
        async with sm() as db:
            sfs = (
                [await default_storefront(db, storefront)]
                if storefront
                else list((await db.execute(select(Storefront))).scalars())
            )
            last = None
            while True:
                q = select(ProductVariant).order_by(ProductVariant.id).limit(500)
                if last is not None:
                    q = q.where(ProductVariant.id > last)
                batch = list((await db.execute(q)).scalars())
                if not batch:
                    break
                pricing = PricingEngine(db)
                for sf in sfs:
                    for k, v in (await pricing.recalculate(batch, sf)).items():
                        totals[k] = totals.get(k, 0) + v
                await db.commit()
                last = batch[-1].id
    finally:
        await engine.dispose()
    return totals


@celery_app.task(
    base=ReliableTask, name="pricing.recalculate_all", time_limit=60 * 60, soft_time_limit=55 * 60
)  # type: ignore[misc]
def pricing_recalculate_all(storefront: str | None = None) -> dict[str, int]:
    totals = asyncio.run(_recalc_all(storefront))
    log.info("pricing_recalculated", **totals)
    return totals


async def _refresh_rates() -> dict[str, int]:
    from sqlalchemy.ext.asyncio import async_sessionmaker

    from app.pricing.currency import refresh_ecb

    engine = create_async_engine(settings.database_url, poolclass=NullPool)
    try:
        async with async_sessionmaker(engine, expire_on_commit=False)() as db:
            result = await refresh_ecb(db)
            await db.commit()
            return result
    finally:
        await engine.dispose()


@celery_app.task(base=ReliableTask, name="pricing.refresh_rates")  # type: ignore[misc]
def pricing_refresh_rates() -> dict[str, int]:
    return asyncio.run(_refresh_rates())


async def _apply_category_rules() -> int:
    from sqlalchemy.ext.asyncio import async_sessionmaker

    from app.pricing.category_rules import apply_rules

    engine = create_async_engine(settings.database_url, poolclass=NullPool)
    try:
        async with async_sessionmaker(engine, expire_on_commit=False)() as db:
            n = await apply_rules(db)
            await db.commit()
            return n
    finally:
        await engine.dispose()


@celery_app.task(base=ReliableTask, name="catalogue.apply_category_rules")  # type: ignore[misc]
def apply_category_rules() -> int:
    return asyncio.run(_apply_category_rules())


async def _publish(item_id: str) -> "PublishOutcome":
    from app.publishing.runner import Publisher

    engine = create_async_engine(settings.database_url, poolclass=NullPool)
    try:
        return await Publisher(engine).run(uuid.UUID(item_id))
    finally:
        await engine.dispose()


@celery_app.task(base=ReliableTask, name="publish.run")  # type: ignore[misc]
def publish_run(item_id: str) -> str:
    outcome = asyncio.run(_publish(item_id))
    if outcome.state == "retry_later":
        publish_run.apply_async((item_id,), queue="publish", countdown=outcome.delay)
    return str(outcome.state)


@celery_app.task(base=ReliableTask, name="publish.watchdog")  # type: ignore[misc]
def publish_watchdog() -> int:
    """Re-queue publishing items left queued > 5 min or running > 15 min (a worker died)."""
    from app.db.models import PublishingQueueItem

    now = datetime.now(UTC)
    with Session(sync_engine()) as s:
        ids = list(
            s.execute(
                select(PublishingQueueItem.id)
                .where(
                    (
                        (PublishingQueueItem.status == "queued")
                        & (PublishingQueueItem.updated_at < now - timedelta(minutes=5))
                    )
                    | (
                        (PublishingQueueItem.status == "running")
                        & (PublishingQueueItem.updated_at < now - timedelta(minutes=15))
                    )
                )
                .limit(500)
            ).scalars()
        )
        if ids:
            s.execute(
                update(PublishingQueueItem)
                .where(PublishingQueueItem.id.in_(ids))
                .values(status="queued", updated_at=now)
            )
            s.commit()
    for item_id in ids:
        publish_run.apply_async((str(item_id),), queue="publish")
    return len(ids)


# ------------------------------------------------------------------ Phase 8: price, stock and offer sync


def _session_engine() -> "AsyncEngine":
    return create_async_engine(settings.database_url, poolclass=NullPool)


async def _sync_push(full: bool) -> dict[str, int]:
    from sqlalchemy.ext.asyncio import async_sessionmaker

    from app.db.models import Storefront
    from app.sync import service

    engine = _session_engine()
    totals: dict[str, int] = {}
    try:
        async with async_sessionmaker(engine, expire_on_commit=False)() as db:
            st = await service.get_settings(db)
            since_raw = st.get("last_push_at")
            since = datetime.fromisoformat(since_raw) - timedelta(minutes=2) if since_raw else None
            started = datetime.now(UTC)
            for sf in list(
                (await db.execute(select(Storefront).where(Storefront.status == "active"))).scalars()
            ):
                stats = await service.push_prices(db, sf, since=since, full=full or since is None)
                for k, v in stats.items():
                    totals[k] = totals.get(k, 0) + v
            await service.save_settings(db, {"last_push_at": started.isoformat()})
            await db.commit()
    finally:
        await engine.dispose()
    return totals


@celery_app.task(base=ReliableTask, name="sync.push", time_limit=60 * 60, soft_time_limit=55 * 60)  # type: ignore[misc]
def sync_push(full: bool = False) -> dict[str, int]:
    """Push changed selling prices and stock to every active storefront."""
    import redis as redis_sync

    lock = redis_sync.Redis.from_url(settings.redis_url).lock("omgos:sync:push", timeout=3600, blocking=False)
    if not lock.acquire(blocking=False):
        return {"skipped": 1}
    try:
        return asyncio.run(_sync_push(full))
    finally:
        try:
            lock.release()
        except Exception:  # the lock already expired
            log.warning("sync_push_lock_release_failed")


async def _sync_run() -> str:
    from sqlalchemy.ext.asyncio import async_sessionmaker

    from app.db.models import ImportJob, Supplier
    from app.db.models.jobs import ACTIVE_JOB_STATUSES
    from app.suppliers import registry

    engine = _session_engine()
    try:
        async with async_sessionmaker(engine, expire_on_commit=False)() as db:
            supplier = (
                await db.execute(select(Supplier).where(Supplier.code == "kinguin"))
            ).scalar_one_or_none()
            if supplier is None or supplier.status != "active":
                return "no supplier"
            if await db.scalar(
                select(ImportJob.id)
                .where(ImportJob.supplier_id == supplier.id, ImportJob.status.in_(ACTIVE_JOB_STATUSES))
                .limit(1)
            ):
                return "import running"
            last = await db.scalar(
                select(func.max(ImportJob.started_at)).where(
                    ImportJob.supplier_id == supplier.id,
                    ImportJob.status == "completed",
                    ImportJob.job_type.in_(("full", "incremental")),
                    ImportJob.params["max_records"].astext.is_(None),
                )
            )
            if last is None:
                return "no full import yet"
            job = ImportJob(
                supplier_id=supplier.id,
                job_type="incremental",
                status="queued",
                params={
                    "updated_since": (last - timedelta(minutes=5)).isoformat(),
                    "scheduled": True,
                    "environment": registry.active_environment(supplier),
                },
            )
            db.add(job)
            await db.commit()
            run_import.apply_async((str(job.id),), queue="imports")
            return "queued"
    finally:
        await engine.dispose()


@celery_app.task(base=ReliableTask, name="sync.run")  # type: ignore[misc]
def sync_run() -> str:
    """Scheduled offer refresh: an update import from Kinguin; prices are pushed when it completes."""
    return asyncio.run(_sync_run())


async def _sync_tick() -> str:
    from celery.schedules import crontab
    from sqlalchemy.ext.asyncio import async_sessionmaker

    from app.sync import service

    engine = _session_engine()
    try:
        async with async_sessionmaker(engine, expire_on_commit=False)() as db:
            st = await service.get_settings(db)
            if not st["enabled"]:
                return "disabled"
            now = datetime.now(UTC).replace(second=0, microsecond=0)
            last_raw = st.get("last_run_at")
            last = datetime.fromisoformat(last_raw) if last_raw else None
            if st.get("cron"):
                due = (
                    crontab(*st["cron"].split()).is_due(last or now - timedelta(days=1))[0] if last else True
                )
            else:
                due = last is None or now - last >= timedelta(minutes=int(st["interval_minutes"]))
            full = (
                now.hour == int(st["full_check_hour"])
                and (st.get("last_full_at") or "")[:10] != now.date().isoformat()
            )
            if not due and not full:
                return "not due"
            changes: dict[str, str] = {}
            if due:
                changes["last_run_at"] = now.isoformat()
            if full:
                changes["last_full_at"] = now.isoformat()
            await service.save_settings(db, changes)
            await db.commit()
    finally:
        await engine.dispose()
    if due:
        sync_run.apply_async(queue="default")
    if full:
        sync_push.apply_async(kwargs={"full": True}, queue="publish")
    return "started"


@celery_app.task(name="sync.tick")  # type: ignore[misc]
def sync_tick() -> str:
    return asyncio.run(_sync_tick())


async def _refresh(inbox_id: str) -> str:
    from sqlalchemy.ext.asyncio import async_sessionmaker

    from app.db.models import Supplier, WebhookInbox
    from app.sync import service

    engine = _session_engine()
    try:
        async with async_sessionmaker(engine, expire_on_commit=False)() as db:
            row = await db.get(WebhookInbox, uuid.UUID(inbox_id))
            if row is None or row.state == "processed":
                return "done"
            row.attempts += 1
            supplier = (await db.execute(select(Supplier).where(Supplier.code == row.source))).scalar_one()
            ext = str(row.payload.get("kinguinId") or "")
            try:
                await service.refresh_product(db, supplier, ext)
            except Exception as exc:
                row.state, row.last_error = "failed", str(exc)[:2000]
                await db.commit()
                raise
            row.state, row.processed_at, row.last_error = "processed", datetime.now(UTC), None
            await db.commit()
            return "processed"
    finally:
        await engine.dispose()


@celery_app.task(base=ReliableTask, name="sync.refresh_product")  # type: ignore[misc]
def sync_refresh_product(inbox_id: str) -> str:
    """Kinguin product.update webhook: refresh that product's offers, then push changed prices."""
    out = asyncio.run(_refresh(inbox_id))
    sync_push.apply_async(queue="publish", countdown=30)
    return out


# ------------------------------------------------------------------ Phase 9: orders and fulfilment


async def _fulfil(order_id: str) -> "FulfilOutcome":
    from app.orders.fulfil import Fulfiller

    engine = _session_engine()
    try:
        return await Fulfiller(engine).run(uuid.UUID(order_id))
    finally:
        await engine.dispose()


@celery_app.task(base=ReliableTask, name="orders.fulfil")  # type: ignore[misc]
def orders_fulfil(order_id: str) -> str:
    """Buy, collect keys and deliver for one order; re-schedules itself while waiting for keys."""
    outcome = asyncio.run(_fulfil(order_id))
    if outcome.state in ("retry_later", "waiting"):
        orders_fulfil.apply_async((order_id,), queue="default", countdown=outcome.delay or 120)
    return str(outcome.state)


async def _supplier_status(inbox_id: str) -> str | None:
    from sqlalchemy.ext.asyncio import async_sessionmaker

    from app.db.models import SupplierOrder, WebhookInbox

    engine = _session_engine()
    try:
        async with async_sessionmaker(engine, expire_on_commit=False)() as db:
            row = await db.get(WebhookInbox, uuid.UUID(inbox_id))
            if row is None:
                return None
            so = (
                await db.execute(
                    select(SupplierOrder).where(
                        SupplierOrder.external_order_id == str(row.payload.get("orderId") or "")
                    )
                )
            ).scalar_one_or_none()
            row.state, row.processed_at = "processed", datetime.now(UTC)
            await db.commit()
            return str(so.order_id) if so else None
    finally:
        await engine.dispose()


@celery_app.task(base=ReliableTask, name="orders.supplier_status")  # type: ignore[misc]
def orders_supplier_status(inbox_id: str) -> str:
    """Kinguin order.status webhook: continue fulfilment of the matching order straight away."""
    order_id = asyncio.run(_supplier_status(inbox_id))
    if order_id:
        orders_fulfil.apply_async((order_id,), queue="default")
    return order_id or "no matching order"


@celery_app.task(base=ReliableTask, name="orders.watchdog")  # type: ignore[misc]
def orders_watchdog() -> int:
    """Pick up orders a dead worker left behind (no update for 10 minutes)."""
    from app.db.models import Order

    now = datetime.now(UTC)
    with Session(sync_engine()) as s:
        ids = list(
            s.execute(
                select(Order.id)
                .where(
                    Order.status.in_(("received", "processing", "awaiting_keys")),
                    Order.updated_at < now - timedelta(minutes=10),
                )
                .limit(200)
            ).scalars()
        )
        if ids:
            s.execute(update(Order).where(Order.id.in_(ids)).values(updated_at=now))
            s.commit()
    for oid in ids:
        orders_fulfil.apply_async((str(oid),), queue="default")
    return len(ids)


# ------------------------------------------------------------------ Phase 10: automation


async def _automation_tick() -> list[str]:
    from sqlalchemy import insert, literal
    from sqlalchemy.ext.asyncio import async_sessionmaker

    from app.ai.router import _eligible
    from app.automation import service as automation
    from app.db.models import AiJob, ImportJob, Product, PublishingQueueItem, Supplier
    from app.db.models.jobs import ACTIVE_JOB_STATUSES
    from app.suppliers import registry

    engine = _session_engine()
    done: list[str] = []
    try:
        async with async_sessionmaker(engine, expire_on_commit=False)() as db:
            due = await automation.due_jobs(db)
            await db.commit()
            s = await automation.get_settings(db)
            if "full_import" in due:
                supplier = (
                    await db.execute(select(Supplier).where(Supplier.code == "kinguin"))
                ).scalar_one_or_none()
                busy = supplier is None or await db.scalar(
                    select(ImportJob.id)
                    .where(ImportJob.supplier_id == supplier.id, ImportJob.status.in_(ACTIVE_JOB_STATUSES))
                    .limit(1)
                )
                if supplier is not None and not busy:
                    job = ImportJob(
                        supplier_id=supplier.id,
                        job_type="full",
                        status="queued",
                        params={"scheduled": True, "environment": registry.active_environment(supplier)},
                    )
                    db.add(job)
                    await db.commit()
                    run_import.apply_async((str(job.id),), queue="imports")
                    done.append("full_import")
            if "ai_daily" in due:
                ids_q = _eligible().order_by(Product.updated_at.desc()).limit(int(s["ai_daily"]["limit"]))
                res = await db.execute(
                    insert(AiJob)
                    .from_select(
                        ["product_id", "task", "status"],
                        select(ids_q.subquery().c.id, literal("content"), literal("queued")),
                    )
                    .returning(AiJob.id)
                )
                ids = list(res.scalars())
                await db.commit()
                for jid in ids:
                    ai_generate.apply_async((str(jid),), queue="ai")
                done.append(f"ai_daily:{len(ids)}")
            if "auto_retry" in due:
                limit = int(s["auto_retry"]["max"])
                ai_ids = list(
                    (
                        await db.execute(
                            update(AiJob)
                            .where(AiJob.status == "failed", AiJob.attempts < limit * 3)
                            .values(status="queued")
                            .returning(AiJob.id)
                        )
                    ).scalars()
                )
                pub_ids = list(
                    (
                        await db.execute(
                            update(PublishingQueueItem)
                            .where(
                                PublishingQueueItem.status == "failed",
                                PublishingQueueItem.attempts < limit * 5,
                            )
                            .values(status="queued")
                            .returning(PublishingQueueItem.id)
                        )
                    ).scalars()
                )
                await db.commit()
                for jid in ai_ids:
                    ai_generate.apply_async((str(jid),), queue="ai")
                for pid in pub_ids:
                    publish_run.apply_async((str(pid),), queue="publish")
                done.append(f"auto_retry:{len(ai_ids)}+{len(pub_ids)}")
            if "notify" in due:
                n = await automation.send_alerts(db)
                await db.commit()
                done.append(f"notify:{n}")
    finally:
        await engine.dispose()
    return done


@celery_app.task(name="automation.tick")  # type: ignore[misc]
def automation_tick() -> list[str]:
    return asyncio.run(_automation_tick())
