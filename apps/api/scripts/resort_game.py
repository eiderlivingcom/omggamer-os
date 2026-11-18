"""Re-sort one game's supplier records (or the whole catalogue in the background) and re-publish live games.

    docker compose exec -T api python - "Hollow Knight: Silksong" < apps/api/scripts/resort_game.py
    docker compose exec -T api python - --all < apps/api/scripts/resort_game.py     # queues the catalogue job

Reads only stored supplier data (nothing is fetched); see app.catalogue.resort.
"""

import asyncio
import sys

from sqlalchemy import func, select

from app.catalogue.resort import resort
from app.db.models import Product, ProductVariant, SupplierOffer
from app.db.session import get_sessionmaker
from app.publishing import queue as publish_queue
from app.workers.tasks import requeue_live


async def one(title: str) -> None:
    async with get_sessionmaker()() as db:
        p = (
            (await db.execute(select(Product).where(func.lower(Product.canonical_title) == title.lower())))
            .scalars()
            .first()
        )
        if p is None:
            print("not found:", title)
            return
        stats = await resort(db, product_id=p.id)
        ids = await requeue_live(db, {p.id}) if stats.moved else []
        await db.commit()
        print(p.sku, p.canonical_title, stats.as_dict(), "republish queued:", len(ids))
        rows = await db.execute(
            select(
                ProductVariant.sku,
                ProductVariant.platform,
                ProductVariant.region,
                ProductVariant.activation_type,
                func.count(SupplierOffer.id),
            )
            .outerjoin(SupplierOffer, SupplierOffer.variant_id == ProductVariant.id)
            .where(ProductVariant.product_id == p.id)
            .group_by(ProductVariant.id)
            .order_by(ProductVariant.sku)
        )
        for r in rows:
            print("  ", *r)
    publish_queue.enqueue(ids)


if __name__ == "__main__":
    if sys.argv[1:] == ["--all"]:
        from app.workers.tasks import catalogue_resort

        print("queued", catalogue_resort.delay().id)
    else:
        asyncio.run(one(" ".join(sys.argv[1:])))
