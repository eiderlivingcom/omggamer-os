"""Apply category mapping rules (supplier genre / platform / product type / tag → category) in bulk SQL.

Rule-based mappings use source "rule". Existing mappings, including manual ones, are never removed.
"""

import uuid
from collections.abc import Sequence

from sqlalchemy import exists, func, literal, select, text
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession

from app.db.models import Product, ProductCategoryMapping, ProductVariant, SupplierProduct
from app.db.models.pricing import CategoryMappingRule


async def apply_rules(db: AsyncSession, product_ids: Sequence[uuid.UUID] | None = None) -> int:
    rules = list((await db.execute(select(CategoryMappingRule))).scalars())
    added = 0
    for rule in rules:
        value = rule.value.strip().lower()
        if rule.attribute == "product_type":
            cond = func.lower(Product.product_type) == value
        elif rule.attribute == "platform":
            cond = exists().where(
                ProductVariant.product_id == Product.id, func.lower(ProductVariant.platform) == value
            )
        else:
            key = "genres" if rule.attribute == "genre" else "tags"  # fixed literals only
            arr = f"supplier_products.raw_payload->'{key}'"
            sql = (
                "EXISTS (SELECT 1 FROM jsonb_array_elements_text("  # noqa: S608
                f"CASE WHEN jsonb_typeof({arr}) = 'array' THEN {arr} ELSE '[]'::jsonb END) AS v(x) "
                "WHERE lower(v.x) = :rule_value)"
            )
            # Case-insensitive match of the value inside the supplier's JSON array.
            cond = exists().where(
                SupplierProduct.product_id == Product.id, text(sql).bindparams(rule_value=value)
            )
        src = select(Product.id, literal(rule.category_id), literal("rule"), literal(None)).where(
            cond, Product.product_status != "merged"
        )
        if product_ids is not None:
            src = src.where(Product.id.in_(list(product_ids)))
        res = await db.execute(
            pg_insert(ProductCategoryMapping)
            .from_select(["product_id", "category_id", "source", "confidence"], src)
            .on_conflict_do_nothing()
            .returning(ProductCategoryMapping.product_id)
        )
        added += len(res.all())
    await db.flush()
    return added
