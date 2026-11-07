"""Supplier connections (encrypted API keys) and the import job manager behind the import dashboard."""

import json
import uuid
from datetime import UTC, datetime
from typing import Any

from fastapi import APIRouter, Query, Request
from sqlalchemy import distinct, func, select
from sqlalchemy.exc import IntegrityError

from app.core.deps import DB, require
from app.core.errors import AppError
from app.core.security import encrypt_secret
from app.db.models import (
    ImportFailure,
    ImportJob,
    ImportLog,
    Supplier,
    SupplierConnection,
    SupplierOffer,
    SupplierProduct,
    User,
)
from app.db.models.jobs import ACTIVE_JOB_STATUSES
from app.imports import queue
from app.imports.schemas import (
    ConnectionIn,
    ConnectionOut,
    ConnectionTestIn,
    ConnectionTestOut,
    EnvironmentIn,
    ImportFailureOut,
    ImportJobOut,
    ImportLogOut,
    ImportOverview,
    ImportStartIn,
    SupplierConnectionsOut,
    SupplierTotals,
)
from app.modules.audit.service import record
from app.suppliers import registry
from app.suppliers.base import SupplierAuthError, SupplierError

router = APIRouter(tags=["imports"])


def _now() -> datetime:
    return datetime.now(UTC)


async def _supplier(db: DB, code: str) -> Supplier:
    s = (await db.execute(select(Supplier).where(Supplier.code == code))).scalar_one_or_none()
    if s is None:
        raise AppError(404, "not_found", "Supplier not found.")
    return s


async def _job(db: DB, job_id: uuid.UUID) -> ImportJob:
    job = await db.get(ImportJob, job_id)
    if job is None:
        raise AppError(404, "not_found", "Import job not found.")
    return job


def job_out(job: ImportJob) -> ImportJobOut:
    out = ImportJobOut.model_validate(job)
    cp = job.checkpoint or {}
    out.saved_records = job.created_records + job.updated_records + job.skipped_records
    out.current_page = cp.get("page") if job.job_type in ("full", "incremental") else None
    if cp.get("saved_at"):
        out.last_checkpoint_at = datetime.fromisoformat(cp["saved_at"])
    if job.started_at:
        out.duration_seconds = int(((job.completed_at or _now()) - job.started_at).total_seconds())
    return out


# ------------------------------------------------------------------ connections


async def _connections_out(db: DB, s: Supplier) -> SupplierConnectionsOut:
    rows = {
        c.environment: c
        for c in (
            await db.execute(select(SupplierConnection).where(SupplierConnection.supplier_id == s.id))
        ).scalars()
    }
    return SupplierConnectionsOut(
        supplier=s.code,
        name=s.name,
        active_environment=registry.active_environment(s),
        connections=[
            ConnectionOut(
                environment=env,
                configured=bool(rows.get(env) and rows[env].credentials_enc),
                status=rows[env].status if env in rows else "not_configured",
                last_checked_at=rows[env].last_checked_at if env in rows else None,
                last_error=rows[env].last_error if env in rows else None,
            )
            for env in registry.ENVIRONMENTS
        ],
    )


@router.get("/suppliers/{code}/connection", response_model=SupplierConnectionsOut)
async def get_connection(code: str, db: DB, _: User = require("suppliers:read")) -> SupplierConnectionsOut:
    return await _connections_out(db, await _supplier(db, code))


@router.put("/suppliers/{code}/connection", response_model=SupplierConnectionsOut)
async def set_connection(
    code: str, body: ConnectionIn, request: Request, db: DB, actor: User = require("credentials:manage")
) -> SupplierConnectionsOut:
    s = await _supplier(db, code)
    conn = await registry.connection_for(db, s, body.environment)
    if conn is None:
        conn = SupplierConnection(supplier_id=s.id, environment=body.environment)
        db.add(conn)
    conn.credentials_enc = encrypt_secret(json.dumps({"api_key": body.api_key.strip()}))
    conn.status = "untested"
    conn.last_error = None
    conn.last_checked_at = None
    await record(
        db,
        "credentials.updated",
        actor=actor,
        entity_type="supplier",
        entity_id=str(s.id),
        summary=f"Replaced the {s.name} {body.environment} API key",
        data={"environment": body.environment},  # never the key
        request=request,
    )
    await db.commit()
    return await _connections_out(db, s)


@router.put("/suppliers/{code}/environment", response_model=SupplierConnectionsOut)
async def set_environment(
    code: str, body: EnvironmentIn, request: Request, db: DB, actor: User = require("credentials:manage")
) -> SupplierConnectionsOut:
    s = await _supplier(db, code)
    active = await db.scalar(
        select(func.count())
        .select_from(ImportJob)
        .where(ImportJob.supplier_id == s.id, ImportJob.status.in_(ACTIVE_JOB_STATUSES))
    )
    if active:
        raise AppError(
            409, "import_active", "Finish or cancel the running import before switching environment."
        )
    s.configuration = {**(s.configuration or {}), "active_environment": body.environment}
    await record(
        db,
        "supplier.environment",
        actor=actor,
        entity_type="supplier",
        entity_id=str(s.id),
        summary=f"{s.name} now uses the {body.environment} API",
        request=request,
    )
    await db.commit()
    return await _connections_out(db, s)


@router.post("/suppliers/{code}/connection/test", response_model=ConnectionTestOut)
async def test_connection(
    code: str, body: ConnectionTestIn, db: DB, _: User = require("suppliers:manage")
) -> ConnectionTestOut:
    s = await _supplier(db, code)
    env = body.environment or registry.active_environment(s)
    conn = await registry.connection_for(db, s, env)
    if conn is None or not conn.credentials_enc:
        raise AppError(409, "not_configured", f"No {s.name} API key is stored for the {env} environment.")
    ok, message = False, ""
    try:
        adapter = registry.build_adapter(s, env, registry.decrypt_credentials(conn))
        try:
            info = await adapter.test_connection()
        finally:
            await adapter.aclose()
        ok, message = True, f"Connected to the {env} API ({info.get('regions', 0)} regions available)."
    except SupplierError as exc:
        message = str(exc)
    conn.status = "connected" if ok else "failed"
    conn.last_checked_at = _now()
    conn.last_error = None if ok else message[:2000]
    await db.commit()
    return ConnectionTestOut(ok=ok, environment=env, message=message)


# ------------------------------------------------------------------ imports


async def _totals(db: DB, s: Supplier) -> SupplierTotals:
    sp = select(SupplierProduct.id).where(SupplierProduct.supplier_id == s.id)
    return SupplierTotals(
        supplier_products=int(
            await db.scalar(
                select(func.count()).select_from(SupplierProduct).where(SupplierProduct.supplier_id == s.id)
            )
            or 0
        ),
        variants=int(
            await db.scalar(
                select(func.count(distinct(SupplierProduct.variant_id))).where(
                    SupplierProduct.supplier_id == s.id
                )
            )
            or 0
        ),
        offers=int(
            await db.scalar(
                select(func.count())
                .select_from(SupplierOffer)
                .where(SupplierOffer.supplier_product_id.in_(sp))
            )
            or 0
        ),
        offers_in_stock=int(
            await db.scalar(
                select(func.count())
                .select_from(SupplierOffer)
                .where(
                    SupplierOffer.supplier_product_id.in_(sp),
                    SupplierOffer.availability_status.in_(("in_stock", "preorder")),
                    SupplierOffer.available_quantity > 0,
                )
            )
            or 0
        ),
        unresolved_failures=int(
            await db.scalar(
                select(func.count())
                .select_from(ImportFailure)
                .join(ImportJob, ImportJob.id == ImportFailure.import_job_id)
                .where(ImportJob.supplier_id == s.id, ImportFailure.resolved_at.is_(None))
            )
            or 0
        ),
    )


@router.get("/imports/overview", response_model=ImportOverview)
async def overview(db: DB, supplier: str = "kinguin", _: User = require("suppliers:read")) -> ImportOverview:
    s = await _supplier(db, supplier)
    conns = await _connections_out(db, s)
    active_conn = next(c for c in conns.connections if c.environment == conns.active_environment)
    jobs = list(
        (
            await db.execute(
                select(ImportJob)
                .where(ImportJob.supplier_id == s.id)
                .order_by(ImportJob.created_at.desc())
                .limit(15)
            )
        ).scalars()
    )
    active = next((j for j in jobs if j.status in ACTIVE_JOB_STATUSES), None)
    if active is None:
        active = (
            await db.execute(
                select(ImportJob)
                .where(ImportJob.supplier_id == s.id, ImportJob.status.in_(ACTIVE_JOB_STATUSES))
                .order_by(ImportJob.created_at.desc())
                .limit(1)
            )
        ).scalar_one_or_none()
    return ImportOverview(
        supplier=s.code,
        name=s.name,
        active_environment=conns.active_environment,
        connection_status=active_conn.status,
        active_job=job_out(active) if active else None,
        recent_jobs=[job_out(j) for j in jobs],
        totals=await _totals(db, s),
    )


@router.get("/imports", response_model=list[ImportJobOut])
async def list_jobs(
    db: DB,
    supplier: str = "kinguin",
    limit: int = Query(50, ge=1, le=200),
    _: User = require("suppliers:read"),
) -> list[ImportJobOut]:
    s = await _supplier(db, supplier)
    rows = await db.execute(
        select(ImportJob)
        .where(ImportJob.supplier_id == s.id)
        .order_by(ImportJob.created_at.desc())
        .limit(limit)
    )
    return [job_out(j) for j in rows.scalars()]


@router.post("/imports", response_model=ImportJobOut, status_code=201)
async def start_import(
    body: ImportStartIn, request: Request, db: DB, actor: User = require("suppliers:manage")
) -> ImportJobOut:
    s = await _supplier(db, body.supplier)
    if s.status != "active":
        raise AppError(409, "supplier_inactive", f"{s.name} is not active.")
    try:
        adapter = await registry.adapter_for(db, s)  # proves credentials exist for the active environment
        await adapter.aclose()
    except SupplierAuthError as exc:
        raise AppError(409, "not_configured", str(exc)) from exc

    params: dict[str, Any] = {}
    if body.max_records:
        params["max_records"] = body.max_records
    if body.page_size:
        params["page_size"] = body.page_size
    params["environment"] = registry.active_environment(s)

    if body.job_type == "incremental":
        since = body.updated_since
        if since is None:
            last = await db.scalar(
                select(func.max(ImportJob.started_at)).where(
                    ImportJob.supplier_id == s.id,
                    ImportJob.status == "completed",
                    ImportJob.job_type.in_(("full", "incremental")),
                    ImportJob.params["max_records"].astext.is_(None),
                )
            )
            if last is None:
                raise AppError(
                    409, "no_baseline", "Run a full import first; an update import needs a starting point."
                )
            since = last
        params["updated_since"] = since.astimezone(UTC).isoformat()
    elif body.job_type == "single":
        if not body.external_id or not body.external_id.strip().isdigit():
            raise AppError(422, "validation_error", "Enter the supplier's numeric product ID.")
        params["external_id"] = body.external_id.strip()
    elif body.job_type == "retry_failed":
        pending = await db.scalar(
            select(func.count())
            .select_from(ImportFailure)
            .join(ImportJob, ImportJob.id == ImportFailure.import_job_id)
            .where(ImportJob.supplier_id == s.id, ImportFailure.resolved_at.is_(None))
        )
        if not pending:
            raise AppError(409, "nothing_to_retry", "There are no failed records to retry.")

    if body.job_type != "full":
        busy = await db.scalar(
            select(func.count())
            .select_from(ImportJob)
            .where(
                ImportJob.supplier_id == s.id,
                ImportJob.job_type == body.job_type,
                ImportJob.status.in_(ACTIVE_JOB_STATUSES),
            )
        )
        if busy:
            raise AppError(409, "import_active", "An import of this type is already running.")

    job = ImportJob(
        supplier_id=s.id, job_type=body.job_type, status="queued", params=params, created_by=actor.id
    )
    db.add(job)
    try:
        await db.flush()
    except IntegrityError as exc:
        await db.rollback()
        raise AppError(
            409,
            "import_active",
            "A full import is already running for this supplier. Pause, resume or cancel it.",
        ) from exc
    db.add(ImportLog(import_job_id=job.id, level="info", message=f"Queued by {actor.email}", data=params))
    await record(
        db,
        "import.started",
        actor=actor,
        entity_type="import_job",
        entity_id=str(job.id),
        summary=f"Started a {body.job_type} {s.name} import",
        data=params,
        request=request,
    )
    await db.commit()
    await db.refresh(job)
    queue.enqueue(job.id)
    return job_out(job)


@router.get("/imports/{job_id}", response_model=ImportJobOut)
async def get_job(job_id: uuid.UUID, db: DB, _: User = require("suppliers:read")) -> ImportJobOut:
    return job_out(await _job(db, job_id))


async def _transition(
    db: DB, job_id: uuid.UUID, allowed: tuple[str, ...], to: str, actor: User, request: Request, verb: str
) -> ImportJob:
    job = (
        await db.execute(select(ImportJob).where(ImportJob.id == job_id).with_for_update())
    ).scalar_one_or_none()
    if job is None:
        raise AppError(404, "not_found", "Import job not found.")
    if job.status not in allowed:
        raise AppError(409, "invalid_state", f"A {job.status} import cannot be {verb}.")
    job.status = to
    if to == "cancelled":
        job.completed_at = _now()
    if to == "queued":
        cp = dict(job.checkpoint or {})
        cp.pop("retry_at", None)
        cp["consecutive_failures"] = 0
        job.checkpoint = cp
    db.add(ImportLog(import_job_id=job.id, level="info", message=f"{verb.capitalize()} by {actor.email}"))
    await record(
        db,
        f"import.{verb}",
        actor=actor,
        entity_type="import_job",
        entity_id=str(job.id),
        summary=f"Import {verb}",
        request=request,
    )
    try:
        await db.commit()
    except IntegrityError as exc:
        await db.rollback()
        raise AppError(
            409, "import_active", "Another full import is already active for this supplier."
        ) from exc
    await db.refresh(job)
    return job


@router.post("/imports/{job_id}/pause", response_model=ImportJobOut)
async def pause(
    job_id: uuid.UUID, request: Request, db: DB, actor: User = require("suppliers:manage")
) -> ImportJobOut:
    return job_out(await _transition(db, job_id, ("queued", "running"), "paused", actor, request, "paused"))


@router.post("/imports/{job_id}/resume", response_model=ImportJobOut)
async def resume(
    job_id: uuid.UUID, request: Request, db: DB, actor: User = require("suppliers:manage")
) -> ImportJobOut:
    job = await _transition(db, job_id, ("paused", "failed"), "queued", actor, request, "resumed")
    queue.enqueue(job.id)
    return job_out(job)


@router.post("/imports/{job_id}/cancel", response_model=ImportJobOut)
async def cancel(
    job_id: uuid.UUID, request: Request, db: DB, actor: User = require("suppliers:manage")
) -> ImportJobOut:
    return job_out(
        await _transition(
            db, job_id, ("queued", "running", "paused"), "cancelled", actor, request, "cancelled"
        )
    )


@router.get("/imports/{job_id}/logs", response_model=list[ImportLogOut])
async def logs(
    job_id: uuid.UUID,
    db: DB,
    before_id: int | None = None,
    limit: int = Query(100, ge=1, le=500),
    _: User = require("suppliers:read"),
) -> list[ImportLog]:
    await _job(db, job_id)
    q = select(ImportLog).where(ImportLog.import_job_id == job_id)
    if before_id:
        q = q.where(ImportLog.id < before_id)
    return list((await db.execute(q.order_by(ImportLog.id.desc()).limit(limit))).scalars())


@router.get("/imports/{job_id}/failures", response_model=list[ImportFailureOut])
async def failures(
    job_id: uuid.UUID,
    db: DB,
    unresolved: bool = True,
    limit: int = Query(100, ge=1, le=500),
    _: User = require("suppliers:read"),
) -> list[ImportFailure]:
    await _job(db, job_id)
    q = select(ImportFailure).where(ImportFailure.import_job_id == job_id)
    if unresolved:
        q = q.where(ImportFailure.resolved_at.is_(None))
    return list((await db.execute(q.order_by(ImportFailure.created_at.desc()).limit(limit))).scalars())
