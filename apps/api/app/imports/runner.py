"""Runs one import job in bounded chunks, page by page, with a checkpoint after every page.

A Celery task calls `ImportRunner.run(job_id)`. The run stops after `budget_seconds` and asks to be
called again, so a 134,000-product import is a chain of short tasks rather than one task that runs for
hours. Every page is fetched, saved and checkpointed in one transaction. A crashed worker therefore
loses at most the page it was on, and the next run carries on from the checkpoint. Re-saving a page is
harmless because every write is an upsert.

A session-level advisory lock per job makes sure only one worker processes a job at a time, even if
the watchdog re-queues a job that is still running.
"""

import time
import traceback
import uuid
from collections import Counter
from collections.abc import Callable
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from typing import Any

from sqlalchemy import func, select, text, tuple_, update
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncEngine, AsyncSession, async_sessionmaker

from app.catalogue.services import CatalogueService
from app.core.logging import log
from app.db.models import ImportFailure, ImportJob, ImportLog, Supplier
from app.suppliers import registry
from app.suppliers.base import (
    SupplierAdapter,
    SupplierAuthError,
    SupplierError,
    SupplierNotFound,
    SupplierRequestError,
)

MAX_CONSECUTIVE_FAILURES = 8
CATCH_UP_OVERLAP = timedelta(minutes=10)


def now() -> datetime:
    return datetime.now(UTC)


@dataclass
class Outcome:
    state: str  # done | continue | retry_later | stopped | locked | failed
    delay: float = 0.0
    follow_up_job_id: uuid.UUID | None = None


OnFail = Callable[[AsyncSession, str, dict[str, Any], BaseException], Any]


class ImportRunner:
    def __init__(
        self,
        engine: AsyncEngine,
        *,
        budget_seconds: float = 600.0,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        self.engine = engine
        self.sm = async_sessionmaker(engine, expire_on_commit=False)
        self.budget = budget_seconds
        self.clock = clock
        self._acked = 0

    # ------------------------------------------------------------ entry point

    async def run(self, job_id: uuid.UUID) -> Outcome:
        key = f"import-job:{job_id}"
        async with self.engine.connect() as raw:
            lock = await raw.execution_options(isolation_level="AUTOCOMMIT")
            got = await lock.scalar(text("SELECT pg_try_advisory_lock(hashtextextended(:k, 0))"), {"k": key})
            if not got:
                return Outcome("locked")
            try:
                return await self._run(job_id)
            finally:
                await lock.execute(text("SELECT pg_advisory_unlock(hashtextextended(:k, 0))"), {"k": key})

    async def _run(self, job_id: uuid.UUID) -> Outcome:
        async with self.sm() as db:
            job = await db.get(ImportJob, job_id)
            if job is None or job.status not in ("queued", "running"):
                return Outcome("stopped")
            supplier = await db.get(Supplier, job.supplier_id)
            assert supplier is not None
            if job.status == "queued":
                first = job.started_at is None
                job.status = "running"
                job.started_at = job.started_at or now()
                self._log(
                    db, job, "info", "Import started" if first else "Import resumed", {"type": job.job_type}
                )
            await db.commit()

            try:
                adapter = await registry.adapter_for(db, supplier)
            except SupplierError as exc:
                return await self._fail(db, job, str(exc))

            self._acked = adapter.requests_made  # requests already counted in a committed job row
            try:
                if job.job_type in ("full", "incremental"):
                    return await self._pages(db, job, supplier, adapter)
                if job.job_type == "single":
                    return await self._single(db, job, supplier, adapter)
                if job.job_type == "retry_failed":
                    return await self._retry_failed(db, job, supplier, adapter)
                return await self._fail(db, job, f"Unknown job type {job.job_type}")
            except (SupplierAuthError, SupplierRequestError) as exc:
                await db.rollback()
                return await self._fail(db, job, str(exc), adapter.requests_made - self._acked)
            except SupplierNotFound as exc:
                await db.rollback()
                return await self._fail(
                    db, job, f"Not found at the supplier: {exc}", adapter.requests_made - self._acked
                )
            except Exception as exc:  # temporary supplier errors and anything unexpected: back off and retry
                await db.rollback()
                return await self._back_off(db, job, exc, adapter.requests_made - self._acked)
            finally:
                await adapter.aclose()

    # ------------------------------------------------------------ job types

    async def _pages(
        self, db: AsyncSession, job: ImportJob, supplier: Supplier, adapter: SupplierAdapter
    ) -> Outcome:
        params = job.params or {}
        limit = max(1, min(int(params.get("page_size") or adapter.max_page_size), adapter.max_page_size))
        max_records = int(params["max_records"]) if params.get("max_records") else None
        since_raw = params.get("updated_since")
        updated_since = datetime.fromisoformat(since_raw) if since_raw else None
        svc = CatalogueService(db)
        deadline = self.clock() + self.budget

        while True:
            if self.clock() >= deadline:
                return Outcome("continue")
            await db.refresh(job)  # pick up Pause / Cancel clicked while we were working
            if job.status != "running":
                self._log(
                    db, job, "info", f"Stopped before page {job.checkpoint.get('page', 1)} ({job.status})"
                )
                await db.commit()
                return Outcome("stopped")

            cp = dict(job.checkpoint or {})
            page = int(cp.get("page", 1))
            before = adapter.requests_made
            result = await adapter.fetch_page(page, limit, updated_since)
            job.api_requests += adapter.requests_made - before
            job.last_response_at = now()
            if result.total is not None:
                job.total_records = min(result.total, max_records) if max_records else result.total
                if page == 1:
                    self._log(db, job, "info", f"{supplier.name} reports {result.total:,} matching products")

            records = result.records
            if max_records is not None:
                records = records[: max(0, max_records - job.processed_records)]
            counts = await self._ingest_many(
                db, job, supplier, svc, adapter, records, self._fail_into_job(job)
            )
            job.processed_records += len(records)

            returned = len(result.records) + int(result.raw_meta.get("non_object_results", 0))
            last_id = adapter.external_id(records[-1]) if records else cp.get("last_external_id")
            cp.update(
                page=page + 1,
                limit=limit,
                last_external_id=last_id,
                saved_at=now().isoformat(),
                consecutive_failures=0,
            )
            cp.pop("retry_at", None)
            job.checkpoint = cp
            self._log(
                db,
                job,
                "warning" if counts["failed"] else "info",
                f"Page {page}: {len(records)} records, {counts['created']} new, {counts['updated']} updated, "
                f"{counts['unchanged']} unchanged, {counts['failed']} failed",
                {"page": page, "returned": returned, "api_requests": adapter.requests_made - before},
            )
            await db.commit()
            self._acked = adapter.requests_made

            finished = (
                returned == 0
                or returned < limit
                or (result.total is not None and page * limit >= result.total)
                or (max_records is not None and job.processed_records >= max_records)
            )
            if finished:
                return await self._finish(db, job)

    async def _single(
        self, db: AsyncSession, job: ImportJob, supplier: Supplier, adapter: SupplierAdapter
    ) -> Outcome:
        ext = str((job.params or {}).get("external_id") or "").strip()
        if not ext:
            return await self._fail(db, job, "No product ID was given")
        before = adapter.requests_made
        record = await adapter.fetch_one(ext)
        job.api_requests += adapter.requests_made - before
        job.last_response_at = now()
        job.total_records = 1
        await self._ingest_many(
            db, job, supplier, CatalogueService(db), adapter, [record], self._fail_into_job(job)
        )
        job.processed_records = 1
        await db.commit()
        return await self._finish(db, job)

    async def _retry_failed(
        self, db: AsyncSession, job: ImportJob, supplier: Supplier, adapter: SupplierAdapter
    ) -> Outcome:
        base = (
            select(ImportFailure)
            .join(ImportJob, ImportJob.id == ImportFailure.import_job_id)
            .where(
                ImportJob.supplier_id == supplier.id,
                ImportFailure.resolved_at.is_(None),
                ImportFailure.import_job_id != job.id,
            )
        )
        if job.total_records is None:
            job.total_records = int(await db.scalar(select(func.count()).select_from(base.subquery())) or 0)
            await db.commit()
        svc = CatalogueService(db)
        deadline = self.clock() + self.budget

        while True:
            if self.clock() >= deadline:
                return Outcome("continue")
            await db.refresh(job)
            if job.status != "running":
                return Outcome("stopped")
            cp = dict(job.checkpoint or {})
            q = base.order_by(ImportFailure.created_at, ImportFailure.id).limit(25)
            if cp.get("after_created_at"):
                q = q.where(
                    tuple_(ImportFailure.created_at, ImportFailure.id)
                    > (datetime.fromisoformat(cp["after_created_at"]), uuid.UUID(cp["after_id"]))
                )
            batch = list((await db.execute(q)).scalars())
            if not batch:
                return await self._finish(db, job)

            for failure in batch:
                ext = failure.external_product_id
                if ext.startswith("unknown:"):
                    job.skipped_records += 1
                    continue
                before = adapter.requests_made
                try:
                    record = await adapter.fetch_one(ext)
                except SupplierNotFound:
                    failure.resolved_at = now()
                    failure.error = f"{failure.error}\n[retry] no longer available at the supplier"
                    job.skipped_records += 1
                    continue
                finally:
                    job.api_requests += adapter.requests_made - before
                    job.last_response_at = now()
                counts = await self._ingest_many(
                    db, job, supplier, svc, adapter, [record], self._fail_into_existing(failure)
                )
                if not counts["failed"]:
                    failure.resolved_at = now()
            job.processed_records += len(batch)
            last = batch[-1]
            cp.update(
                after_created_at=last.created_at.isoformat(),
                after_id=str(last.id),
                saved_at=now().isoformat(),
                consecutive_failures=0,
            )
            job.checkpoint = cp
            self._log(db, job, "info", f"Retried {len(batch)} failed records")
            await db.commit()
            self._acked = adapter.requests_made

    # ------------------------------------------------------------ saving records

    async def _ingest_many(
        self,
        db: AsyncSession,
        job: ImportJob,
        supplier: Supplier,
        svc: CatalogueService,
        adapter: SupplierAdapter,
        records: list[dict[str, Any]],
        on_fail: OnFail,
    ) -> Counter[str]:
        counts: Counter[str] = Counter(created=0, updated=0, unchanged=0, failed=0)
        saved_ids: list[str] = []
        page = (job.checkpoint or {}).get("page", 1)
        for i, record in enumerate(records):
            ext = adapter.external_id(record) or f"unknown:{page}:{i}"
            try:
                async with db.begin_nested():
                    result = await svc.ingest(supplier, adapter.normalize(record))
            except Exception as exc:
                counts["failed"] += 1
                job.failed_records += 1
                await on_fail(db, ext, record, exc)
                continue
            counts[result.status] += 1
            saved_ids.append(ext)
            if result.status == "created":
                job.created_records += 1
            elif result.status == "updated":
                job.updated_records += 1
            else:
                job.skipped_records += 1
        if saved_ids:
            # A record that saves now no longer needs retrying, whichever earlier job recorded it.
            await db.execute(
                update(ImportFailure)
                .where(
                    ImportFailure.resolved_at.is_(None),
                    ImportFailure.external_product_id.in_(saved_ids),
                    ImportFailure.import_job_id.in_(
                        select(ImportJob.id).where(ImportJob.supplier_id == supplier.id)
                    ),
                )
                .values(resolved_at=now())
            )
        return counts

    @staticmethod
    def _error_text(exc: BaseException) -> str:
        return "".join(traceback.format_exception_only(exc)).strip()[:4000]

    def _fail_into_job(self, job: ImportJob) -> OnFail:
        async def record(db: AsyncSession, ext: str, payload: dict[str, Any], exc: BaseException) -> None:
            err = self._error_text(exc)
            stmt = pg_insert(ImportFailure).values(
                id=uuid.uuid4(),
                import_job_id=job.id,
                external_product_id=ext[:80],
                payload=payload,
                error=err,
            )
            await db.execute(
                stmt.on_conflict_do_update(
                    index_elements=["import_job_id", "external_product_id"],
                    set_={
                        "attempts": ImportFailure.attempts + 1,
                        "error": err,
                        "payload": stmt.excluded.payload,
                        "resolved_at": None,
                        "updated_at": func.now(),
                    },
                )
            )

        return record

    def _fail_into_existing(self, failure: ImportFailure) -> OnFail:
        async def record(db: AsyncSession, ext: str, payload: dict[str, Any], exc: BaseException) -> None:
            failure.attempts += 1
            failure.error = self._error_text(exc)
            failure.payload = payload

        return record

    # ------------------------------------------------------------ job state

    def _log(
        self, db: AsyncSession, job: ImportJob, level: str, message: str, data: dict[str, Any] | None = None
    ) -> None:
        db.add(ImportLog(import_job_id=job.id, level=level, message=message[:2000], data=data or {}))

    async def _finish(self, db: AsyncSession, job: ImportJob) -> Outcome:
        job.status = "completed"
        job.completed_at = now()
        started = job.started_at or job.completed_at
        summary = {
            "discovered": job.total_records,
            "processed": job.processed_records,
            "created": job.created_records,
            "updated": job.updated_records,
            "skipped": job.skipped_records,
            "failed": job.failed_records,
            "api_requests": job.api_requests,
            "duration_seconds": int((job.completed_at - started).total_seconds()),
        }
        job.checkpoint = {**(job.checkpoint or {}), "summary": summary}
        self._log(
            db,
            job,
            "info",
            f"Import completed: {job.processed_records:,} records, {job.created_records:,} new, "
            f"{job.updated_records:,} updated, {job.skipped_records:,} unchanged or skipped, "
            f"{job.failed_records:,} failed, {job.api_requests:,} API requests",
            summary,
        )
        follow_up = None
        params = job.params or {}
        if job.job_type == "full" and not params.get("max_records") and not params.get("no_catch_up"):
            # Products updated while the full import ran may have moved between pages; pick them up.
            catch_up = ImportJob(
                supplier_id=job.supplier_id,
                job_type="incremental",
                status="queued",
                params={
                    "updated_since": (started - CATCH_UP_OVERLAP).isoformat(),
                    "catch_up_for": str(job.id),
                },
                created_by=job.created_by,
            )
            db.add(catch_up)
            await db.flush()
            follow_up = catch_up.id
            self._log(db, job, "info", "Queued a catch-up import for products updated during this run")
        await db.commit()
        log.info("import_completed", job_id=str(job.id), **summary)
        return Outcome("done", follow_up_job_id=follow_up)

    async def _fail(self, db: AsyncSession, job: ImportJob, message: str, uncounted: int = 0) -> Outcome:
        await db.refresh(job)
        job.api_requests += max(0, uncounted)
        job.status = "failed"
        job.completed_at = now()
        job.last_error = message[:4000]
        self._log(db, job, "error", message)
        await db.commit()
        log.error("import_failed", job_id=str(job.id), error=message)
        return Outcome("failed")

    async def _back_off(
        self, db: AsyncSession, job: ImportJob, exc: BaseException, uncounted: int
    ) -> Outcome:
        await db.refresh(job)
        job.api_requests += max(0, uncounted)
        cp = dict(job.checkpoint or {})
        n = int(cp.get("consecutive_failures", 0)) + 1
        message = self._error_text(exc)
        if n >= MAX_CONSECUTIVE_FAILURES:
            return await self._fail(
                db, job, f"Stopped after {n} failed attempts in a row. Last error: {message}"
            )
        delay = float(min(900, 30 * 2 ** (n - 1)))
        cp.update(consecutive_failures=n, retry_at=(now() + timedelta(seconds=delay)).isoformat())
        job.checkpoint = cp
        job.last_error = message
        self._log(db, job, "warning", f"Attempt {n} failed, retrying in {int(delay)} s: {message}")
        await db.commit()
        return Outcome("retry_later", delay=delay)
