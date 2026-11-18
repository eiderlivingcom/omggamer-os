"""Turn "one listing per platform" on (or off) for games by title and re-publish them, then report.

    docker compose exec -T api python - on "Hollow Knight: Silksong" < apps/api/scripts/split_game.py
    docker compose exec -T api python - report "Hollow Knight: Silksong" < apps/api/scripts/split_game.py

Same as the product page switch (Publishing history > One listing per platform), for use from the server.
"""

import asyncio
import sys
from datetime import UTC, datetime

from sqlalchemy import func, select, text

from app.db.models import AppSetting, Product, PublishingQueueItem, Storefront, StorefrontProductMapping
from app.db.session import get_sessionmaker
from app.publishing import queue as publish_queue
from app.publishing.connector import ConnectorClient
from app.publishing.runner import listing_groups


async def main(action: str, titles: list[str]) -> None:
    async with get_sessionmaker()() as db:
        print("migration", await db.scalar(text("select version_num from alembic_version")))
        sf = (await db.execute(select(Storefront).where(Storefront.status == "active"))).scalars().first()
        if sf is None:
            print("no active storefront")
            return
        client = ConnectorClient(sf)
        try:
            st = await client.status()
            print("store", sf.code, "connector", st.get("plugin_version"))
        finally:
            await client.aclose()
        row = await db.get(AppSetting, "publishing")
        if row is None:
            row = AppSetting(key="publishing", value={"split_by_platform": "selected", "split_products": []})
            db.add(row)
        ids = list((row.value or {}).get("split_products") or [])
        queued = []
        for t in titles:
            p = (
                (
                    await db.execute(
                        select(Product)
                        .where(func.lower(Product.canonical_title) == t.lower())
                        .order_by(Product.created_at)
                    )
                )
                .scalars()
                .first()
            )
            if p is None:
                print("not found:", t)
                continue
            if action in ("on", "off"):
                ids = [i for i in ids if i != str(p.id)]
                if action == "on":
                    ids.append(str(p.id))
                row.value = {**(row.value or {}), "split_products": ids}
                await db.flush()
                if p.product_status == "published":
                    item = PublishingQueueItem(
                        product_id=p.id,
                        storefront_id=sf.id,
                        action="upsert",
                        status="queued",
                        approval_status="approved",
                        approved_at=datetime.now(UTC),
                    )
                    db.add(item)
                    await db.flush()
                    queued.append(item.id)
            groups = await listing_groups(db, p)
            print(
                p.sku,
                p.canonical_title,
                p.product_status,
                "listings:",
                [(k or "main", pl) for k, pl in groups],
            )
            for m in (
                await db.execute(
                    select(StorefrontProductMapping).where(StorefrontProductMapping.product_id == p.id)
                )
            ).scalars():
                print(
                    "  store",
                    m.listing or "main",
                    m.sync_status,
                    "#",
                    m.external_product_id,
                    len(m.variant_map or {}),
                    "options",
                    (m.last_error or "")[:200],
                )
            q = (
                await db.execute(
                    select(PublishingQueueItem)
                    .where(PublishingQueueItem.product_id == p.id)
                    .order_by(PublishingQueueItem.created_at.desc())
                    .limit(1)
                )
            ).scalar_one_or_none()
            if q:
                print("  last publish", q.status, q.updated_at, (q.last_error or "")[:300])
        await db.commit()
        if queued:
            publish_queue.enqueue(queued)
            print("queued", len(queued))


if __name__ == "__main__":
    asyncio.run(main(sys.argv[1], sys.argv[2:]))
