"""Product facts for the storefront's AI Studio.

The WooCommerce AI Studio asks OMGGamer OS for everything known about a product (all suppliers, attributes,
system requirements, activation details and the current OS AI text), so the store writes its page from the
same source data as the OS instead of calling supplier APIs itself. Prices and stock are not included.
"""

import uuid
from typing import Any

from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.ai import content as content_mod
from app.catalogue.repositories import ContentRepository
from app.db.models import Product, ProductCategory, ProductCategoryMapping, Supplier, SupplierProduct


def _activation(raw: dict[str, Any]) -> str:
    text = raw.get("activationDetails") or raw.get("activation_details") or ""
    return content_mod.plain_text(str(text))[:1500]


async def find_product(db: AsyncSession, product_id: str | None, title: str | None) -> Product | None:
    if product_id:
        try:
            p = await db.get(Product, uuid.UUID(product_id.split("~", 1)[0]))  # per-platform listing ids
        except ValueError:
            p = None
        if p is not None and p.product_status != "merged":
            return p
    t = (title or "").strip()
    if len(t) < 3:
        return None
    return (
        await db.execute(
            select(Product)
            .where(Product.product_status != "merged", Product.canonical_title.ilike(f"%{t[:200]}%"))
            .order_by(func.length(Product.canonical_title))
            .limit(1)
        )
    ).scalar_one_or_none()


async def product_facts(db: AsyncSession, product: Product) -> dict[str, Any]:
    facts, description, images = await content_mod.gather(db, product)
    rows = (
        await db.execute(
            select(SupplierProduct, Supplier.name)
            .join(Supplier, Supplier.id == SupplierProduct.supplier_id)
            .where(SupplierProduct.product_id == product.id)
            .order_by(SupplierProduct.created_at)
        )
    ).all()
    suppliers = []
    extra: dict[str, Any] = {}
    for sp, name in rows:
        raw = sp.raw_payload or {}
        suppliers.append(
            {
                "supplier": name,
                "title": sp.original_title,
                "description": content_mod.plain_text(sp.original_description)[:5000],
                "activation_details": _activation(raw),
            }
        )
        for key, out in (
            ("steam", "steam_app_id"),
            ("metacriticScore", "metacritic"),
            ("isPreorder", "is_preorder"),
            ("originalName", "original_name"),
        ):
            if raw.get(key) not in (None, "", []) and out not in extra:
                extra[out] = raw[key]
        if raw.get("tags") and "tags" not in extra:
            extra["tags"] = [str(t) for t in raw["tags"]][:20]
        vids = [v.get("video_id") for v in raw.get("videos") or [] if isinstance(v, dict)]
        vids = [v for v in vids if v]
        if vids and "videos" not in extra:
            extra["videos"] = vids[:3]
    cats = (
        await db.execute(
            select(ProductCategory.name)
            .join(ProductCategoryMapping, ProductCategoryMapping.category_id == ProductCategory.id)
            .where(ProductCategoryMapping.product_id == product.id)
        )
    ).scalars()
    current = await ContentRepository(db).current(product.id, "en")
    ai = None
    if current is not None and current.content_status != "rejected":
        ai = {
            "status": current.content_status,
            "title": current.title,
            "short_description": current.short_description,
            "long_description": current.long_description,
            "tags": current.tags,
        }
    return {
        "os_product_id": str(product.id),
        "title": product.canonical_title,
        "facts": {k: v for k, v in facts.items() if k != "image_urls"},
        **extra,
        "categories": list(cats),
        "supplier_description": description,
        "suppliers": suppliers,
        "image_count": len(images),
        "os_ai_content": ai,
    }
