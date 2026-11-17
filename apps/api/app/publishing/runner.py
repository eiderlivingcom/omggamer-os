"""Publish one queue item to its storefront through the Catalogue Connector.

The payload uses approved content only, the stored selling prices for that storefront (recalculated first)
and the product's category paths. An unchanged payload is not sent again. Every attempt is written to
sync_logs; the storefront product ID is saved in storefront_product_mappings.
"""

import uuid
from dataclasses import dataclass
from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncEngine, AsyncSession, async_sessionmaker

from app.ai import settings as ai_settings
from app.catalogue import regions
from app.catalogue.normalize import payload_checksum, slugify
from app.core.logging import log
from app.db.models import (
    Product,
    ProductCategory,
    ProductCategoryMapping,
    ProductContent,
    ProductMedia,
    ProductVariant,
    PublishingQueueItem,
    Storefront,
    StorefrontProductMapping,
    SupplierProduct,
    SyncLog,
)
from app.db.models.pricing import VariantPrice
from app.pricing.engine import PricingEngine
from app.publishing.connector import ConnectorClient, ConnectorError
from app.sync.service import mark_pushed

MAX_ATTEMPTS = 5


def now() -> datetime:
    return datetime.now(UTC)


class PublishError(Exception):
    """A problem in OMGGamer OS data that retrying will not fix."""


@dataclass
class PublishOutcome:
    state: str  # done | skipped | retry_later | failed | stopped
    delay: float = 0.0


async def category_paths(db: AsyncSession, product_id: uuid.UUID) -> list[list[str]]:
    cats = {c.id: c for c in (await db.execute(select(ProductCategory))).scalars()}
    mapped = (
        await db.execute(
            select(ProductCategoryMapping.category_id).where(ProductCategoryMapping.product_id == product_id)
        )
    ).scalars()
    paths = []
    for cid in mapped:
        path: list[str] = []
        cur, guard = cats.get(cid), 0
        while cur is not None and guard < 20:
            path.insert(0, cur.name)
            cur = cats.get(cur.parent_id) if cur.parent_id else None
            guard += 1
        if path:
            paths.append(path)
    return sorted(paths)


async def build_payload(db: AsyncSession, product: Product, sf: Storefront, status: str) -> dict[str, Any]:
    variants = list(
        (
            await db.execute(
                select(ProductVariant)
                .where(ProductVariant.product_id == product.id)
                .order_by(ProductVariant.sku)
            )
        ).scalars()
    )
    if not variants:
        raise PublishError("The product has no variants.")
    await PricingEngine(db).recalculate(variants, sf, reason="publish")
    prices = {
        vp.variant_id: vp
        for vp in (
            await db.execute(
                select(VariantPrice).where(
                    VariantPrice.storefront_id == sf.id, VariantPrice.variant_id.in_([v.id for v in variants])
                )
            )
        ).scalars()
    }
    # Suppliers name the same region many ways; the store only gets the defined region names. Variants that
    # end up identical (same platform, edition and region) are sent once: the in-stock one with the lowest
    # price.
    region_of = {v.id: regions.canonical(v.region) for v in variants}
    # Stores that suppliers list as a platform (GOG.com is a store like OMGGamer): the platform is PC and the
    # store is shown as where the key activates.
    platform_of = {v.id: regions.platform_name(v.platform) for v in variants}
    activates_on = sorted({s for s in (regions.store_of(v.platform) for v in variants) if s})

    def rank(v: ProductVariant) -> tuple[int, Decimal]:
        p = prices.get(v.id)
        ok = bool(p and p.available and p.selling_price is not None)
        price = p.selling_price if ok and p and p.selling_price is not None else Decimal("9e9")
        return (0 if ok else 1, price)

    best: dict[tuple[str, str, str], ProductVariant] = {}
    for v in sorted(variants, key=rank):
        best.setdefault(((platform_of[v.id] or "").lower(), (v.edition or "").lower(), region_of[v.id]), v)
    keep = {v.id for v in best.values()}
    variants = [v for v in variants if v.id in keep]

    if status == "publish" and not any(p.selling_price is not None for p in prices.values()):
        raise PublishError(
            "No variant has a selling price. Add a pricing rule (Pricing) and make sure an offer is in stock."
        )

    locale = str((await ai_settings.get_settings(db)).get("locale") or "en")
    content = (
        await db.execute(
            select(ProductContent).where(
                ProductContent.product_id == product.id,
                ProductContent.locale == locale,
                ProductContent.is_current.is_(True),
                ProductContent.content_status == "approved",
            )
        )
    ).scalar_one_or_none()
    media = list(
        (
            await db.execute(
                select(ProductMedia)
                .where(
                    ProductMedia.product_id == product.id,
                    ProductMedia.media_type.in_(("cover", "screenshot")),
                )
                .order_by(ProductMedia.media_type != "cover", ProductMedia.position)
                .limit(8)
            )
        ).scalars()
    )
    ext_ids = list(
        (
            await db.execute(
                select(SupplierProduct.external_product_id).where(SupplierProduct.product_id == product.id)
            )
        ).scalars()
    )
    alt = (content.image_alt_text if content else {}) or {}
    info = (content.structured_info if content else {}) or {}

    dims = {"Platform": "platform", "Edition": "edition", "Region": "region"}
    used = {name for name, attr in dims.items() if any(getattr(v, attr) for v in variants)}

    def v_attrs(v: ProductVariant) -> dict[str, str]:
        out = {}
        for name, attr in dims.items():
            if name in used:
                if attr == "region":
                    value = region_of[v.id]
                elif attr == "platform":
                    value = platform_of[v.id]
                else:
                    value = getattr(v, attr)
                out[name] = value or ("Standard" if attr == "edition" else "Any")
        return out

    display = []
    for name, value in (
        ("Developer", product.developer),
        ("Publisher", product.publisher),
        ("Genre", ", ".join(info.get("genres") or [])),
        ("Activation", ", ".join(sorted({v.activation_type for v in variants if v.activation_type}))),
        ("Activates on", ", ".join(activates_on)),
    ):
        if value:
            display.append({"name": name, "options": [x.strip() for x in str(value).split(",") if x.strip()]})

    return {
        "external_id": str(product.id),
        "name": content.title if content and content.title else product.canonical_title,
        "slug": product.slug,
        "status": status,
        "short_description": content.short_description if content else "",
        "description": (content.long_description or "").replace("\n\n", "</p><p>").join(["<p>", "</p>"])
        if content and content.long_description
        else "",
        "categories": await category_paths(db, product.id),
        "tags": list(content.tags) if content else [],
        "images": image_seo(
            content.title if content and content.title else product.canonical_title,
            product.product_type,
            variants,
            media,
            alt,
        ),
        "attributes": display,
        "variants": [
            {
                "variant_id": str(v.id),
                "sku": v.sku,
                "attributes": v_attrs(v),
                "regular_price": str(prices[v.id].selling_price)
                if v.id in prices and prices[v.id].selling_price is not None
                else None,
                "sale_price": str(prices[v.id].sale_price)
                if v.id in prices and prices[v.id].sale_price is not None
                else None,
                "floor_price": str(prices[v.id].floor_price)
                if v.id in prices and prices[v.id].floor_price is not None
                else None,
                "in_stock": bool(
                    v.id in prices and prices[v.id].available and prices[v.id].selling_price is not None
                ),
            }
            for v in variants
        ],
        "meta": {
            "kinguin_ids": ",".join(ext_ids),
            "product_type": product.product_type,
            # What each region on this product means, for the region note on the product page.
            "region_notes": {
                name: regions.definition(name) for name in sorted({region_of[v.id] for v in variants})
            },
        },
        "seo": {
            "title": content.meta_title if content else "",
            "description": content.meta_description if content else "",
        },
    }


class Publisher:
    def __init__(self, engine: AsyncEngine) -> None:
        self.sm = async_sessionmaker(engine, expire_on_commit=False)

    async def run(self, item_id: uuid.UUID) -> PublishOutcome:
        async with self.sm() as db:
            item = (
                await db.execute(
                    select(PublishingQueueItem)
                    .where(PublishingQueueItem.id == item_id)
                    .with_for_update(skip_locked=True)
                )
            ).scalar_one_or_none()
            if item is None or item.status not in ("queued", "running"):
                return PublishOutcome("stopped")
            if item.approval_status != "approved":
                return await self._fail(db, item, None, "The publishing request is not approved.")
            sf = await db.get(Storefront, item.storefront_id) if item.storefront_id else None
            product = await db.get(Product, item.product_id)
            if sf is None or product is None:
                return await self._fail(
                    db, item, sf.id if sf else None, "The product or storefront no longer exists."
                )
            if sf.status != "active":
                return await self._fail(
                    db, item, sf.id, f"Publishing to {sf.name} is switched off (Publishing → Storefronts)."
                )
            item.status = "running"
            item.attempts += 1
            await db.commit()
            # Plain values stay valid after a rollback expires the ORM objects.
            pid, sf_id, attempts = product.id, sf.id, item.attempts

            mapping = (
                await db.execute(
                    select(StorefrontProductMapping).where(
                        StorefrontProductMapping.storefront_id == sf.id,
                        StorefrontProductMapping.product_id == product.id,
                    )
                )
            ).scalar_one_or_none()
            try:
                if item.action == "unpublish":
                    return await self._unpublish(db, item, sf, product, mapping)
                if product.product_status not in ("approved", "published", "unpublished"):
                    raise PublishError(
                        f"The product is {product.product_status}; only approved products are published."
                    )
                payload = await build_payload(db, product, sf, "publish")
            except PublishError as exc:
                await db.rollback()
                return await self._fail(db, item, sf_id, str(exc), pid)

            digest = payload_checksum(payload)
            if mapping and mapping.last_hash == digest and mapping.sync_status == "synced":
                # The store already has exactly this version: the product is live there.
                product.product_status = "published"
                item.status = "completed"
                item.last_error = None
                db.add(
                    SyncLog(
                        storefront_id=sf.id,
                        product_id=product.id,
                        operation="update",
                        status="skipped",
                        response={"message": "unchanged since last sync"},
                    )
                )
                await db.commit()
                return PublishOutcome("skipped")

            try:
                client = ConnectorClient(sf)
            except ConnectorError as exc:
                await db.rollback()
                return await self._fail(db, item, sf_id, str(exc), pid)
            try:
                result = await client.upsert(payload, f"{item.id}:{digest[:16]}")
            except ConnectorError as exc:
                await db.rollback()
                if exc.temporary and attempts < MAX_ATTEMPTS:
                    return await self._retry(db, item, sf_id, str(exc), pid)
                return await self._fail(db, item, sf_id, str(exc), pid)
            finally:
                await client.aclose()

            values = {
                "external_product_id": str(result.get("product_id") or ""),
                "sync_status": "synced",
                "last_synced_at": now(),
                "last_hash": digest,
                "last_error": None,
                "variant_map": {str(k): v for k, v in (result.get("variations") or {}).items()},
            }
            stmt = pg_insert(StorefrontProductMapping).values(
                storefront_id=sf.id, product_id=product.id, **values
            )
            await db.execute(
                stmt.on_conflict_do_update(index_elements=["storefront_id", "product_id"], set_=values)
            )
            await mark_pushed(db, sf_id, [uuid.UUID(v["variant_id"]) for v in payload["variants"]])
            product.product_status = "published"
            item.status = "completed"
            item.last_error = None
            db.add(
                SyncLog(
                    storefront_id=sf.id,
                    product_id=product.id,
                    operation="create" if result.get("created") else "update",
                    status="success",
                    response={
                        k: result.get(k)
                        for k in (
                            "product_id",
                            "created",
                            "type",
                            "status",
                            "permalink",
                            "warnings",
                            "idempotent_replay",
                        )
                    },
                )
            )
            await db.commit()
            log.info(
                "published", product_id=str(product.id), storefront=sf.code, wc_id=result.get("product_id")
            )
            return PublishOutcome("done")

    async def _unpublish(
        self,
        db: AsyncSession,
        item: PublishingQueueItem,
        sf: Storefront,
        product: Product,
        mapping: StorefrontProductMapping | None,
    ) -> PublishOutcome:
        pid, sf_id, attempts = product.id, sf.id, item.attempts
        if mapping is None or not mapping.external_product_id:
            item.status = "completed"
            await db.commit()
            return PublishOutcome("skipped")
        try:
            client = ConnectorClient(sf)
        except ConnectorError as exc:
            return await self._fail(db, item, sf_id, str(exc), pid)
        try:
            await client.set_status(str(pid), "draft", f"{item.id}:unpublish")
        except ConnectorError as exc:
            await db.rollback()
            if exc.temporary and attempts < MAX_ATTEMPTS:
                return await self._retry(db, item, sf_id, str(exc), pid)
            return await self._fail(db, item, sf_id, str(exc), pid)
        finally:
            await client.aclose()
        mapping.sync_status = "unpublished"
        mapping.last_synced_at = now()
        mapping.last_hash = None
        others = await db.scalar(
            select(StorefrontProductMapping.id).where(
                StorefrontProductMapping.product_id == product.id,
                StorefrontProductMapping.sync_status == "synced",
                StorefrontProductMapping.id != mapping.id,
            )
        )
        if not others and product.product_status == "published":
            product.product_status = "unpublished"
        item.status = "completed"
        db.add(
            SyncLog(
                storefront_id=sf.id,
                product_id=product.id,
                operation="unpublish",
                status="success",
                response={},
            )
        )
        await db.commit()
        return PublishOutcome("done")

    async def _record_failure(
        self,
        db: AsyncSession,
        sf_id: uuid.UUID | None,
        product_id: uuid.UUID | None,
        message: str,
        final: bool,
    ) -> None:
        if sf_id is None or product_id is None:
            return
        db.add(
            SyncLog(
                storefront_id=sf_id,
                product_id=product_id,
                operation="upsert",
                status="failed",
                response={"error": message[:2000], "final": final},
            )
        )
        if final:
            values = {"sync_status": "failed", "last_error": message[:2000]}
            stmt = pg_insert(StorefrontProductMapping).values(
                storefront_id=sf_id, product_id=product_id, **values
            )
            await db.execute(
                stmt.on_conflict_do_update(index_elements=["storefront_id", "product_id"], set_=values)
            )

    async def _fail(
        self,
        db: AsyncSession,
        item: PublishingQueueItem,
        sf_id: uuid.UUID | None,
        message: str,
        product_id: uuid.UUID | None = None,
    ) -> PublishOutcome:
        await db.refresh(item)
        item.status = "failed"
        item.last_error = message[:4000]
        await self._record_failure(db, sf_id, product_id or item.product_id, message, True)
        await db.commit()
        log.warning("publish_failed", item_id=str(item.id), error=message)
        return PublishOutcome("failed")

    async def _retry(
        self,
        db: AsyncSession,
        item: PublishingQueueItem,
        sf_id: uuid.UUID,
        message: str,
        product_id: uuid.UUID,
    ) -> PublishOutcome:
        await db.refresh(item)
        item.status = "queued"
        item.last_error = message[:4000]
        await self._record_failure(db, sf_id, product_id, message, False)
        await db.commit()
        return PublishOutcome("retry_later", delay=float(min(900, 30 * 2 ** (item.attempts - 1))))


TYPE_WORDS = {
    "game": "Key",
    "dlc": "DLC Key",
    "software": "Software Key",
    "gift_card": "Gift Card",
    "game_currency": "Game Cash",
    "game_account": "Account",
    "subscription": "Subscription",
}


def image_seo(
    title: str, product_type: str, variants: list[Any], media: list[Any], ai_alt: dict[str, str]
) -> list[dict[str, str]]:
    """SEO names for product images: file name, title and alt text describe the product.

    E.g. "age-of-empires-ii-mountain-royals-steam-key-cover.jpg" and "Age of Empires II: The Mountain Royals
    Steam Key cover". Alt text written in AI Studio (approved content) is used when there is one.
    """
    platforms = [v.platform for v in variants if getattr(v, "platform", None)]
    platform = max(set(platforms), key=platforms.count) if platforms else ""
    if platform and platform.lower() in title.lower():
        platform = ""
    kind = TYPE_WORDS.get(product_type, "")
    label = " ".join(x for x in (title, platform, kind) if x).strip()
    out: list[dict[str, str]] = []
    shot = 0
    for m in media:
        if m.media_type == "cover" and not any(o["role"] == "cover" for o in out):
            role, suffix = "cover", "cover"
        else:
            shot += 1
            role, suffix = "screenshot", f"screenshot {shot}"
        path = m.source_url.split("?")[0].lower()
        ext = next((e for e in (".png", ".webp", ".gif", ".jpeg", ".jpg") if path.endswith(e)), ".jpg")
        out.append(
            {
                "url": m.source_url,
                "alt": (ai_alt.get(m.source_url) or f"{label} {suffix}").strip()[:250],
                "title": f"{label} {suffix}"[:250],
                "filename": f"{slugify(f'{label} {suffix}', 120)}{ext}",
                "role": role,
            }
        )
    return out
