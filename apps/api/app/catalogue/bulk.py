"""Bulk operations on products: AI generation, categories, approval, publishing, archiving and retries.

Every action is validated per product. Products an action does not apply to are skipped, not failed,
and the counts say why (for example "not_approved" when publishing a product that was never approved).
"""

import uuid
from collections import Counter
from datetime import UTC, datetime

from sqlalchemy import select, update
from sqlalchemy.ext.asyncio import AsyncSession

from app.catalogue.schemas import BulkIn, BulkResult
from app.catalogue.services import CatalogueService
from app.core.errors import AppError
from app.core.rbac import permissions_for
from app.db.models import (
    AiJob,
    Product,
    ProductCategory,
    ProductContent,
    PublishingQueueItem,
    Storefront,
    User,
)
from app.db.models.jobs import ACTIVE_JOB_STATUSES

PERMISSION = {
    "generate_ai": "content:write",
    "regenerate_ai": "content:write",
    "assign_categories": "catalogue:write",
    "archive": "catalogue:write",
    "approve": "publishing:approve",
    "reject": "publishing:approve",
    "publish": "publishing:manage",
    "retry_failed": "catalogue:write",
}
LOCKED = ("merged",)  # never touched by bulk actions


def now() -> datetime:
    return datetime.now(UTC)


async def default_storefront(db: AsyncSession, code: str | None) -> Storefront:
    if code:
        sf = (await db.execute(select(Storefront).where(Storefront.code == code))).scalar_one_or_none()
        if sf is None:
            raise AppError(404, "not_found", "Storefront not found.")
        return sf
    # The storefront marked default in its configuration, else staging (safer than live), else any.
    rows = list((await db.execute(select(Storefront).order_by(Storefront.code))).scalars())
    for sf in rows:
        if (sf.configuration or {}).get("default"):
            return sf
    for sf in rows:
        if sf.code.endswith("staging"):
            return sf
    if not rows:
        raise AppError(409, "no_storefront", "No storefront is configured.")
    return rows[0]


class BulkService:
    def __init__(self, db: AsyncSession, actor: User) -> None:
        self.db = db
        self.actor = actor
        self.ai_job_ids: list[uuid.UUID] = []
        self.publish_ids: list[uuid.UUID] = []

    async def run(self, body: BulkIn) -> BulkResult:
        perm = PERMISSION[body.action]
        if perm not in permissions_for(self.actor.role.key):
            raise AppError(403, "forbidden", "Your role does not allow this action.", {"missing": [perm]})
        ids = list(dict.fromkeys(body.product_ids))
        products = list((await self.db.execute(select(Product).where(Product.id.in_(ids)))).scalars())
        counts: Counter[str] = Counter()
        counts["not_found"] = len(ids) - len(products)
        handler = getattr(self, f"_{body.action}")
        changed = 0
        for p in products:
            if p.product_status in LOCKED:
                counts["merged"] += 1
                continue
            outcome = await handler(p, body)
            counts[outcome] += 1
            if outcome == "changed":
                changed += 1
        await self.db.flush()
        details = {k: v for k, v in counts.items() if v and k != "changed"}
        return BulkResult(
            action=body.action,
            requested=len(ids),
            changed=changed,
            skipped=len(ids) - changed,
            details=details,
        )

    # ------------------------------------------------------------ helpers

    async def _content(self, p: Product) -> ProductContent | None:
        return await CatalogueService(self.db).current_content(p.id)

    async def _active_ai_job(self, p: Product) -> bool:
        q = select(AiJob.id).where(AiJob.product_id == p.id, AiJob.status.in_(ACTIVE_JOB_STATUSES)).limit(1)
        return (await self.db.scalar(q)) is not None

    async def _queue_ai(self, p: Product, task: str) -> str:
        if await self._active_ai_job(p):
            return "already_queued"
        job = AiJob(product_id=p.id, task=task, status="queued")
        self.db.add(job)
        await self.db.flush()
        self.ai_job_ids.append(job.id)
        return "changed"

    # ------------------------------------------------------------ actions

    async def _generate_ai(self, p: Product, body: BulkIn) -> str:
        c = await self._content(p)
        if c is not None and c.content_status in ("pending_review", "approved", "draft"):
            return "has_content"  # never regenerate unnecessarily; use regenerate_ai to force
        return await self._queue_ai(p, "content")

    async def _regenerate_ai(self, p: Product, body: BulkIn) -> str:
        return await self._queue_ai(p, "regenerate")

    async def _assign_categories(self, p: Product, body: BulkIn) -> str:
        if not body.category_ids:
            raise AppError(422, "validation_error", "Choose at least one category.")
        found = set(
            (
                await self.db.execute(
                    select(ProductCategory.id).where(ProductCategory.id.in_(body.category_ids))
                )
            ).scalars()
        )
        if len(found) != len(set(body.category_ids)):
            raise AppError(422, "unknown_category", "One or more categories do not exist.")
        svc = CatalogueService(self.db)
        await svc.categories.add_mappings(p.id, body.category_ids, "manual", None)
        p.updated_at = now()
        return "changed"

    async def _approve(self, p: Product, body: BulkIn) -> str:
        if p.product_status in ("approved", "published"):
            return "already_approved"
        if p.product_status == "archived":
            return "archived"
        c = await self._content(p)
        if c is not None and c.content_status in ("draft", "pending_review"):
            c.content_status = "approved"
        p.product_status = "approved"
        return "changed"

    async def _reject(self, p: Product, body: BulkIn) -> str:
        c = await self._content(p)
        touched = False
        if c is not None and c.content_status in ("draft", "pending_review", "approved"):
            c.content_status = "rejected"
            touched = True
        if p.product_status in ("review", "approved"):
            p.product_status = "draft"
            touched = True
        return "changed" if touched else "nothing_to_reject"

    async def _archive(self, p: Product, body: BulkIn) -> str:
        if p.product_status == "archived":
            return "already_archived"
        p.product_status = "archived"
        return "changed"

    async def _publish(self, p: Product, body: BulkIn) -> str:
        if p.product_status not in ("approved", "published", "unpublished"):
            return "not_approved"
        sf = await default_storefront(self.db, body.storefront)
        busy = await self.db.scalar(
            select(PublishingQueueItem.id)
            .where(
                PublishingQueueItem.product_id == p.id,
                PublishingQueueItem.storefront_id == sf.id,
                PublishingQueueItem.status.in_(ACTIVE_JOB_STATUSES),
            )
            .limit(1)
        )
        if busy:
            return "already_queued"
        item = PublishingQueueItem(
            product_id=p.id,
            storefront_id=sf.id,
            action="upsert",
            status="queued",
            approval_status="approved",
            approved_by=self.actor.id,
            approved_at=now(),
        )
        self.db.add(item)
        await self.db.flush()
        self.publish_ids.append(item.id)
        return "changed"

    async def _retry_failed(self, p: Product, body: BulkIn) -> str:
        perms = permissions_for(self.actor.role.key)
        n = 0
        if "content:write" in perms:
            failed_ai = list(
                (
                    await self.db.execute(
                        select(AiJob).where(AiJob.product_id == p.id, AiJob.status == "failed")
                    )
                ).scalars()
            )
            if failed_ai and not await self._active_ai_job(p):
                job = failed_ai[0]
                job.status, job.error_message = "queued", None
                self.ai_job_ids.append(job.id)
                n += 1
        if "publishing:manage" in perms:
            res = await self.db.execute(
                update(PublishingQueueItem)
                .where(PublishingQueueItem.product_id == p.id, PublishingQueueItem.status == "failed")
                .values(status="queued", last_error=None)
                .returning(PublishingQueueItem.id)
            )
            ids = list(res.scalars())
            self.publish_ids.extend(ids)
            n += len(ids)
        return "changed" if n else "nothing_failed"
