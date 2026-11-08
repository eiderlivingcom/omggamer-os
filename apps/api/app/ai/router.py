"""AI Studio API: settings, OpenAI key, prompt versions, generation control, job log and costs."""

import uuid
from datetime import UTC, datetime, timedelta
from decimal import Decimal
from typing import Any, Literal

from fastapi import APIRouter, Query, Request
from pydantic import BaseModel, ConfigDict, Field
from sqlalchemy import Select, func, insert, literal, select, update

from app.ai import queue as ai_queue
from app.ai import settings as ai_settings
from app.ai.openai_client import AiError, OpenAIClient
from app.core.deps import DB, CurrentUser, require
from app.core.errors import AppError
from app.db.models import AiJob, AiPrompt, Product, ProductContent, User
from app.db.models.jobs import ACTIVE_JOB_STATUSES
from app.modules.audit.service import record

router = APIRouter(prefix="/ai", tags=["ai"])


def _now() -> datetime:
    return datetime.now(UTC)


class AiSettingsIn(BaseModel):
    model: str | None = Field(default=None, min_length=1, max_length=80)
    temperature: float | None = Field(default=None, ge=0, le=2)
    send_temperature: bool | None = None
    max_output_tokens: int | None = Field(default=None, ge=200, le=16000)
    batch_size: int | None = Field(default=None, ge=1, le=5000)
    concurrency: int | None = Field(default=None, ge=1, le=20)
    max_retries: int | None = Field(default=None, ge=1, le=10)
    language: str | None = Field(default=None, min_length=2, max_length=40)
    locale: str | None = Field(default=None, min_length=2, max_length=10)
    brand_voice: str | None = Field(default=None, max_length=2000)
    seo_requirements: str | None = Field(default=None, max_length=2000)
    pricing: dict[str, dict[str, float]] | None = None


class KeyIn(BaseModel):
    api_key: str = Field(min_length=20, max_length=500)


class CredentialOut(BaseModel):
    configured: bool
    status: str
    last_checked_at: datetime | None
    last_error: str | None


class PromptOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    name: str
    version: int
    system_prompt: str
    user_template: str
    is_active: bool
    notes: str | None
    created_at: datetime


class PromptIn(BaseModel):
    system_prompt: str = Field(min_length=50, max_length=20000)
    user_template: str = Field(min_length=20, max_length=20000)
    notes: str | None = Field(default=None, max_length=500)


class GenerateIn(BaseModel):
    scope: Literal["eligible", "selected"] = "eligible"
    product_ids: list[uuid.UUID] = Field(default_factory=list, max_length=5000)
    limit: int | None = Field(default=None, ge=1, le=5000)
    regenerate: bool = False


class GenerateOut(BaseModel):
    queued: int
    skipped: int


class JobOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    product_id: uuid.UUID | None
    product_title: str | None = None
    task: str
    status: str
    outcome: str | None
    attempts: int
    model: str | None
    prompt_version: str | None
    token_usage: dict[str, Any]
    estimated_cost: Decimal
    error_message: str | None
    created_at: datetime
    started_at: datetime | None
    completed_at: datetime | None


class Overview(BaseModel):
    paused: bool
    key: CredentialOut
    settings: dict[str, Any]
    prompt_version: int
    jobs: dict[str, int]
    eligible_products: int
    awaiting_review: int


class CostRow(BaseModel):
    model: str | None
    jobs: int
    products: int
    input_tokens: int
    output_tokens: int
    cost: Decimal


class Costs(BaseModel):
    days: int
    rows: list[CostRow]
    total_cost: Decimal
    products: int
    cost_per_product: Decimal
    failed_requests: int


def _eligible() -> Select[tuple[uuid.UUID]]:
    """Products with no current AI content, not archived/merged and without an active AI job."""
    has_content = (
        select(ProductContent.id)
        .where(ProductContent.product_id == Product.id, ProductContent.is_current.is_(True))
        .exists()
    )
    active_job = (
        select(AiJob.id).where(AiJob.product_id == Product.id, AiJob.status.in_(ACTIVE_JOB_STATUSES)).exists()
    )
    return select(Product.id).where(
        Product.product_status.notin_(("archived", "merged")), ~has_content, ~active_job
    )


async def _credential_out(db: DB) -> CredentialOut:
    row = await ai_settings.credential(db)
    return CredentialOut(
        configured=bool(row and row.credentials_enc),
        status=row.status if row else "not_configured",
        last_checked_at=row.last_checked_at if row else None,
        last_error=row.last_error if row else None,
    )


@router.get("/overview", response_model=Overview)
async def overview(db: DB, _: User = require("catalogue:read")) -> Overview:
    s = await ai_settings.get_settings(db)
    prompt = await ai_settings.active_prompt(db)
    await db.commit()
    counts = dict(
        (await db.execute(select(AiJob.status, func.count()).group_by(AiJob.status))).tuples().all()
    )
    eligible = int(await db.scalar(select(func.count()).select_from(_eligible().subquery())) or 0)
    awaiting = int(
        await db.scalar(
            select(func.count())
            .select_from(ProductContent)
            .where(ProductContent.is_current.is_(True), ProductContent.content_status == "pending_review")
        )
        or 0
    )
    public = {k: v for k, v in s.items() if k != "paused"}
    return Overview(
        paused=bool(s["paused"]),
        key=await _credential_out(db),
        settings=public,
        prompt_version=prompt.version,
        jobs={k: int(v) for k, v in counts.items()},
        eligible_products=eligible,
        awaiting_review=awaiting,
    )


@router.put("/settings", response_model=dict[str, Any])
async def put_settings(
    body: AiSettingsIn, request: Request, db: DB, actor: User = require("ai:manage")
) -> dict:  # type: ignore[type-arg]
    changes = body.model_dump(exclude_none=True)
    s = await ai_settings.save_settings(db, changes, actor.id)
    await record(db, "ai.settings", actor=actor, summary="Changed AI settings", data=changes, request=request)
    await db.commit()
    return {k: v for k, v in s.items() if k != "paused"}


@router.get("/credentials", response_model=CredentialOut)
async def get_credentials(db: DB, _: User = require("ai:manage")) -> CredentialOut:
    return await _credential_out(db)


@router.put("/credentials", response_model=CredentialOut)
async def put_credentials(
    body: KeyIn, request: Request, db: DB, actor: User = require("credentials:manage")
) -> CredentialOut:
    await ai_settings.set_api_key(db, body.api_key)
    await record(
        db,
        "credentials.updated",
        actor=actor,
        entity_type="integration",
        entity_id="openai",
        summary="Replaced the OpenAI API key",
        request=request,
    )
    await db.commit()
    return await _credential_out(db)


@router.post("/credentials/test", response_model=dict[str, Any])
async def test_credentials(db: DB, _: User = require("ai:manage")) -> dict[str, Any]:
    from app.ai import runner

    key = await ai_settings.api_key(db)
    row = await ai_settings.credential(db)
    if not key or row is None:
        raise AppError(409, "not_configured", "No OpenAI API key is stored.")
    ok, message = False, ""
    try:
        client = OpenAIClient(key, transport=runner.transport_override, max_attempts=2)
        try:
            n = await client.check()
        finally:
            await client.aclose()
        ok, message = True, f"Connected to OpenAI ({n} models available)."
    except AiError as exc:
        message = str(exc)
    row.status = "connected" if ok else "failed"
    row.last_checked_at = _now()
    row.last_error = None if ok else message[:2000]
    await db.commit()
    return {"ok": ok, "message": message}


@router.get("/prompts", response_model=list[PromptOut])
async def prompts(db: DB, _: User = require("ai:manage")) -> list[AiPrompt]:
    await ai_settings.active_prompt(db)
    await db.commit()
    q = select(AiPrompt).where(AiPrompt.name == "content").order_by(AiPrompt.version.desc()).limit(50)
    return list((await db.execute(q)).scalars())


@router.post("/prompts", response_model=PromptOut, status_code=201)
async def new_prompt(
    body: PromptIn, request: Request, db: DB, actor: User = require("ai:manage")
) -> AiPrompt:
    row = await ai_settings.new_prompt_version(
        db, body.system_prompt, body.user_template, body.notes, actor.id
    )
    await record(db, "ai.prompt", actor=actor, summary=f"Saved prompt version {row.version}", request=request)
    await db.commit()
    return row


@router.post("/generate", response_model=GenerateOut)
async def generate(
    body: GenerateIn, request: Request, db: DB, actor: CurrentUser, _: User = require("content:write")
) -> GenerateOut:
    s = await ai_settings.get_settings(db)
    if not await ai_settings.api_key(db):
        raise AppError(
            409, "not_configured", "Add the OpenAI API key under Settings → API credentials first."
        )
    limit = body.limit or int(s["batch_size"])
    if body.scope == "eligible":
        ids_q = _eligible().order_by(Product.updated_at.desc()).limit(limit)
    else:
        if not body.product_ids:
            raise AppError(422, "validation_error", "Select at least one product.")
        active_job = (
            select(AiJob.id)
            .where(AiJob.product_id == Product.id, AiJob.status.in_(ACTIVE_JOB_STATUSES))
            .exists()
        )
        ids_q = select(Product.id).where(
            Product.id.in_(body.product_ids),
            Product.product_status.notin_(("archived", "merged")),
            ~active_job,
        )
        if not body.regenerate:
            has_content = (
                select(ProductContent.id)
                .where(
                    ProductContent.product_id == Product.id,
                    ProductContent.is_current.is_(True),
                    ProductContent.content_status.in_(("draft", "pending_review", "approved")),
                )
                .exists()
            )
            ids_q = ids_q.where(~has_content)
    task = "regenerate" if body.regenerate else "content"
    res = await db.execute(
        insert(AiJob)
        .from_select(
            ["product_id", "task", "status", "created_by"],
            select(ids_q.subquery().c.id, literal(task), literal("queued"), literal(actor.id)),
        )
        .returning(AiJob.id)
    )
    job_ids = list(res.scalars())
    requested = len(body.product_ids) if body.scope == "selected" else len(job_ids)
    await record(
        db,
        "ai.generate",
        actor=actor,
        summary=f"Queued AI generation for {len(job_ids)} products",
        data={"scope": body.scope, "regenerate": body.regenerate},
        request=request,
    )
    await db.commit()
    ai_queue.enqueue(job_ids)
    return GenerateOut(queued=len(job_ids), skipped=max(0, requested - len(job_ids)))


@router.post("/pause", response_model=dict[str, Any])
async def pause(request: Request, db: DB, actor: User = require("content:write")) -> dict[str, Any]:
    await ai_settings.save_settings(db, {"paused": True}, actor.id)
    await record(db, "ai.paused", actor=actor, summary="Paused AI generation", request=request)
    await db.commit()
    return {"paused": True}


@router.post("/resume", response_model=dict[str, Any])
async def resume(request: Request, db: DB, actor: User = require("content:write")) -> dict[str, Any]:
    await ai_settings.save_settings(db, {"paused": False}, actor.id)
    res = await db.execute(
        update(AiJob).where(AiJob.status == "paused").values(status="queued").returning(AiJob.id)
    )
    ids = list(res.scalars())
    queued = list((await db.execute(select(AiJob.id).where(AiJob.status == "queued"))).scalars())
    await record(db, "ai.resumed", actor=actor, summary="Resumed AI generation", request=request)
    await db.commit()
    ai_queue.enqueue(dict.fromkeys(ids + queued))
    return {"paused": False, "requeued": len(set(ids + queued))}


@router.post("/jobs/retry-failed", response_model=GenerateOut)
async def retry_failed(request: Request, db: DB, actor: User = require("content:write")) -> GenerateOut:
    res = await db.execute(
        update(AiJob)
        .where(AiJob.status == "failed")
        .values(status="queued", error_message=None, attempts=0, completed_at=None)
        .returning(AiJob.id)
    )
    ids = list(res.scalars())
    await record(
        db, "ai.retry_failed", actor=actor, summary=f"Retried {len(ids)} failed AI jobs", request=request
    )
    await db.commit()
    ai_queue.enqueue(ids)
    return GenerateOut(queued=len(ids), skipped=0)


@router.get("/jobs", response_model=list[JobOut])
async def jobs(
    db: DB,
    status: str | None = Query(default=None, max_length=20),
    product_id: uuid.UUID | None = None,
    limit: int = Query(default=100, ge=1, le=500),
    _: User = require("catalogue:read"),
) -> list[JobOut]:
    q = select(AiJob, Product.canonical_title).outerjoin(Product, Product.id == AiJob.product_id)
    if status:
        q = q.where(AiJob.status == status)
    if product_id:
        q = q.where(AiJob.product_id == product_id)
    rows = await db.execute(q.order_by(AiJob.created_at.desc()).limit(limit))
    out = []
    for job, title in rows.tuples().all():
        j = JobOut.model_validate(job)
        j.product_title = title
        out.append(j)
    return out


@router.get("/costs", response_model=Costs)
async def costs(
    db: DB, days: int = Query(default=30, ge=1, le=365), _: User = require("catalogue:read")
) -> Costs:
    since = _now() - timedelta(days=days)
    inp = func.coalesce(AiJob.token_usage["input"].as_integer(), 0)
    out = func.coalesce(AiJob.token_usage["output"].as_integer(), 0)
    rows = await db.execute(
        select(
            AiJob.model,
            func.count(),
            func.count(func.distinct(AiJob.product_id)),
            func.sum(inp),
            func.sum(out),
            func.sum(AiJob.estimated_cost),
        )
        .where(AiJob.created_at >= since, AiJob.model.is_not(None))
        .group_by(AiJob.model)
    )
    data = [
        CostRow(
            model=m,
            jobs=int(n),
            products=int(p),
            input_tokens=int(i or 0),
            output_tokens=int(o or 0),
            cost=Decimal(c or 0),
        )
        for m, n, p, i, o, c in rows.tuples().all()
    ]
    products = int(
        await db.scalar(
            select(func.count(func.distinct(AiJob.product_id))).where(
                AiJob.created_at >= since, AiJob.model.is_not(None)
            )
        )
        or 0
    )
    failed = int(
        await db.scalar(
            select(func.count()).select_from(AiJob).where(AiJob.created_at >= since, AiJob.status == "failed")
        )
        or 0
    )
    total = sum((r.cost for r in data), Decimal("0"))
    return Costs(
        days=days,
        rows=data,
        total_cost=total,
        products=products,
        cost_per_product=(total / products).quantize(Decimal("0.000001")) if products else Decimal("0"),
        failed_requests=failed,
    )
