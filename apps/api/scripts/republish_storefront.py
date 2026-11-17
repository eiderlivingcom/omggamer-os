"""Re-push every product already listed on a storefront, so it picks up the current publishing rules
(defined region names, one variation per platform/edition/region, GOG.com shown as PC + "Activates on").

    docker compose exec -T api python - omggamer-staging < apps/api/scripts/republish_storefront.py

Only products that already have a listing on that storefront are queued; nothing new is published and
products that are no longer approved/published are skipped. Uses the normal publishing queue.
"""

import asyncio
import sys
from datetime import UTC, datetime

from sqlalchemy import select

from app.db.models import Product, PublishingQueueItem, Storefront, StorefrontProductMapping
from app.db.models.jobs import ACTIVE_JOB_STATUSES
from app.db.session import get_sessionmaker
from app.publishing import queue


async def main(code: str) -> None:
    async with get_sessionmaker()() as db:
        sf = (await db.execute(select(Storefront).where(Storefront.code == code))).scalar_one()
        rows = (
            (
                await db.execute(
                    select(Product.id)
                    .join(StorefrontProductMapping, StorefrontProductMapping.product_id == Product.id)
                    .where(
                        StorefrontProductMapping.storefront_id == sf.id,
                        Product.product_status.in_(("approved", "published")),
                    )
                )
            )
            .scalars()
            .all()
        )
        busy = set(
            (
                await db.execute(
                    select(PublishingQueueItem.product_id).where(
                        PublishingQueueItem.storefront_id == sf.id,
                        PublishingQueueItem.status.in_(ACTIVE_JOB_STATUSES),
                    )
                )
            ).scalars()
        )
        items = []
        for pid in rows:
            if pid in busy:
                continue
            item = PublishingQueueItem(
                product_id=pid,
                storefront_id=sf.id,
                action="upsert",
                status="queued",
                approval_status="approved",
                approved_at=datetime.now(UTC),
            )
            db.add(item)
            items.append(item)
        await db.flush()
        ids = [i.id for i in items]
        await db.commit()
    for n, i in enumerate(ids):
        queue.enqueue([i], countdown=n * 2)  # paced: one product every 2 seconds
    print(f"{code}: {len(rows)} listed products, {len(ids)} queued, {len(busy)} already in the queue")


asyncio.run(main(sys.argv[1] if len(sys.argv) > 1 else "omggamer-staging"))
