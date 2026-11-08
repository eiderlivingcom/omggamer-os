"""Process one AI job: gather facts, prompt OpenAI, check the answer, save a new content version.

Original supplier data is only read. Generated text goes into product_content as a new version with status
"pending_review", so it always passes through the approval queue.
"""

import json
import time
import traceback
import uuid
from dataclasses import dataclass
from datetime import UTC, datetime
from decimal import Decimal

import httpx
from redis.asyncio import Redis
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncEngine, AsyncSession, async_sessionmaker

from app.ai import content as content_mod
from app.ai import settings as ai_settings
from app.ai.openai_client import (
    AiAuthError,
    AiError,
    AiQuotaError,
    AiRequestError,
    OpenAIClient,
)
from app.catalogue.repositories import ContentRepository
from app.catalogue.services import CatalogueService
from app.core.config import get_settings
from app.core.logging import log
from app.db.models import AiJob, IntegrationCredential, Product, ProductCategory

SLOTS_KEY = "omgos:ai:slots"
SLOT_TTL = 600  # a crashed worker's slot frees itself after 10 minutes
# Tests point the client at an httpx.MockTransport; production leaves this None.
transport_override: httpx.AsyncBaseTransport | None = None


def now() -> datetime:
    return datetime.now(UTC)


@dataclass
class AiOutcome:
    state: str  # done | retry_later | paused | stopped | failed
    delay: float = 0.0


class AiRunner:
    def __init__(self, engine: AsyncEngine, redis_url: str | None = None) -> None:
        self.sm = async_sessionmaker(engine, expire_on_commit=False)
        self.redis_url = redis_url or get_settings().redis_url

    async def _take_slot(self, redis: Redis, job_id: str, limit: int) -> bool:
        t = time.time()
        await redis.zremrangebyscore(SLOTS_KEY, 0, t - SLOT_TTL)
        await redis.zadd(SLOTS_KEY, {job_id: t})
        rank = await redis.zrank(SLOTS_KEY, job_id)
        if rank is not None and rank < max(1, limit):
            return True
        await redis.zrem(SLOTS_KEY, job_id)
        return False

    async def run(self, job_id: uuid.UUID) -> AiOutcome:
        redis = Redis.from_url(self.redis_url, decode_responses=True)
        try:
            async with self.sm() as db:
                job = await db.get(AiJob, job_id)
                if job is None or job.status not in ("queued", "running"):
                    return AiOutcome("stopped")
                settings = await ai_settings.get_settings(db)
                if settings["paused"]:
                    job.status = "paused"
                    await db.commit()
                    return AiOutcome("paused")
                if not await self._take_slot(redis, str(job_id), int(settings["concurrency"])):
                    return AiOutcome("retry_later", delay=15)
                try:
                    return await self._process(db, job, settings)
                finally:
                    await redis.zrem(SLOTS_KEY, str(job_id))
        finally:
            await redis.aclose()

    async def _process(self, db: AsyncSession, job: AiJob, settings: dict) -> AiOutcome:  # type: ignore[type-arg]
        job.status = "running"
        job.attempts += 1
        job.started_at = job.started_at or now()
        await db.commit()

        product = await db.get(Product, job.product_id) if job.product_id else None
        if product is None or product.product_status in ("merged", "archived"):
            return await self._complete(db, job, "skipped_no_data", "Product is missing, merged or archived")

        facts, description, _ = await content_mod.gather(db, product)
        if not facts["supplier_titles"]:
            return await self._complete(db, job, "skipped_no_data", "No supplier data to write from")
        digest = content_mod.source_hash(facts, description)
        prompt = await ai_settings.active_prompt(db)
        prompt_version = f"content-v{prompt.version}"
        locale = str(settings.get("locale") or "en")
        current = await ContentRepository(db).current(product.id, locale)
        if (
            job.task == "content"
            and current is not None
            and current.source_hash == digest
            and current.prompt_version == prompt_version
            and current.content_status != "rejected"
        ):
            return await self._complete(
                db, job, "skipped_unchanged", "Supplier facts unchanged since last generation"
            )

        key = await ai_settings.api_key(db)
        categories = await content_mod.category_names(db)
        values = {
            "facts": json.dumps(facts, ensure_ascii=False, indent=1, default=str),
            "categories": "\n".join(f"- {c}" for c in categories) or "(no categories defined; return null)",
            "description": description or "(none)",
            "language": settings["language"],
            "brand_voice": settings["brand_voice"],
            "seo_requirements": settings["seo_requirements"],
            "product_name": product.canonical_title,
        }
        system = ai_settings.render(prompt.system_prompt, values)
        user = ai_settings.render(prompt.user_template, values)
        model = str(settings["model"])
        temperature = float(settings["temperature"]) if settings.get("send_temperature", True) else None

        try:
            client = OpenAIClient(key or "", transport=transport_override)
        except AiAuthError as exc:
            return await self._fail(db, job, str(exc))
        try:
            try:
                result = await client.structured(
                    model=model,
                    system=system,
                    user=user,
                    schema_name="omggamer_product_content",
                    schema=content_mod.OUTPUT_SCHEMA,
                    max_output_tokens=int(settings["max_output_tokens"]),
                    temperature=temperature,
                )
            except AiRequestError as exc:
                if temperature is not None and "temperature" in str(exc).lower():
                    result = await client.structured(
                        model=model,
                        system=system,
                        user=user,
                        schema_name="omggamer_product_content",
                        schema=content_mod.OUTPUT_SCHEMA,
                        max_output_tokens=int(settings["max_output_tokens"]),
                        temperature=None,
                    )
                else:
                    raise
        except (AiAuthError, AiQuotaError) as exc:
            await self._mark_credential(db, str(exc))
            return await self._fail(db, job, str(exc))
        except AiRequestError as exc:
            return await self._fail(db, job, str(exc))
        except AiError as exc:
            return await self._retry(db, job, settings, str(exc))
        finally:
            await client.aclose()

        job.model = result.model
        job.prompt_version = prompt_version
        job.token_usage = {"input": result.input_tokens, "output": result.output_tokens}
        job.estimated_cost = ai_settings.cost_for(settings, model, result.input_tokens, result.output_tokens)
        try:
            clean, flags = content_mod.check(result.data, facts, description, categories)
        except ValueError as exc:
            return await self._retry(db, job, settings, str(exc))

        missing = clean.pop("missing_information")
        flags_all = flags + [f"missing: {m}" for m in missing]
        await ContentRepository(db).add_version(
            product.id,
            locale,
            {
                "origin": "ai",
                "title": clean["title"],
                "short_description": clean["short_description"],
                "long_description": clean["long_description"],
                "meta_title": clean["meta_title"],
                "meta_description": clean["meta_description"],
                "tags": clean["tags"],
                "image_alt_text": clean["image_alt_text"],
                "suggested_category": clean["suggested_category"],
                "structured_info": content_mod.structured_info(facts),
                "review_flags": flags_all,
                "content_status": "pending_review",
                "model": result.model,
                "prompt_version": prompt_version,
                "generated_at": now(),
                "source_hash": digest,
                "ai_job_id": job.id,
            },
        )
        if product.product_status == "draft":
            product.product_status = "review"
        if clean["suggested_category"]:
            # Recorded as an AI suggestion (source "ai"); manual categories are never replaced.
            cid = await db.scalar(
                select(ProductCategory.id).where(ProductCategory.name == clean["suggested_category"])
            )
            if cid:
                await CatalogueService(db).categories.add_mappings(product.id, [cid], "ai", Decimal("0.6"))
        return await self._complete(db, job, "flagged" if flags else "generated", None)

    async def _complete(self, db: AsyncSession, job: AiJob, outcome: str, note: str | None) -> AiOutcome:
        job.status = "completed"
        job.outcome = outcome
        job.completed_at = now()
        job.error_message = note
        await db.commit()
        log.info("ai_job_completed", job_id=str(job.id), outcome=outcome)
        return AiOutcome("done")

    async def _fail(self, db: AsyncSession, job: AiJob, message: str) -> AiOutcome:
        await db.rollback()
        await db.refresh(job)
        job.status = "failed"
        job.completed_at = now()
        job.error_message = message[:4000]
        await db.commit()
        log.warning("ai_job_failed", job_id=str(job.id), error=message)
        return AiOutcome("failed")

    async def _retry(self, db: AsyncSession, job: AiJob, settings: dict, message: str) -> AiOutcome:  # type: ignore[type-arg]
        if job.attempts >= int(settings["max_retries"]):
            return await self._fail(db, job, f"Gave up after {job.attempts} attempts: {message}")
        await db.rollback()
        await db.refresh(job)
        job.status = "queued"
        job.error_message = message[:4000]
        await db.commit()
        return AiOutcome("retry_later", delay=float(min(600, 30 * 2 ** (job.attempts - 1))))

    async def _mark_credential(self, db: AsyncSession, message: str) -> None:
        await db.rollback()
        row = await db.get(IntegrationCredential, ai_settings.PROVIDER)
        if row is not None:
            row.status = "failed"
            row.last_error = message[:2000]
            row.last_checked_at = now()
            await db.commit()


def error_text(exc: BaseException) -> str:
    return "".join(traceback.format_exception_only(exc)).strip()
