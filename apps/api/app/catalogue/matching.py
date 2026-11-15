"""Cross-supplier matching.

Suppliers name the same item differently ("GTA 6 New", "GTA 6 2026"). Exact rules link most records on import
(same Steam app ID, same normalised title, or a saved alias). For the rest, `suggest_for` looks for similar
products from *other* suppliers and queues a suggestion for a person to review. Nothing is merged
automatically: accepting a suggestion merges the two products and saves the supplier's title as an alias, so
the next import links it directly.
"""

import re
import uuid
from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import String, cast, func, select, text, update
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession

from app.catalogue.normalize import match_key
from app.core.errors import AppError
from app.db.models import (
    MatchSuggestion,
    Product,
    ProductAlias,
    ProductVariant,
    StorefrontProductMapping,
    SupplierProduct,
)

# Candidates come from a trigram search on the normalised title, then get scored here.
SEARCH_THRESHOLD = 0.3
SUGGEST_AT = Decimal("0.6")
MAX_CANDIDATES = 3
_YEAR = re.compile(r"^(19[89]\d|20[0-3]\d)$")
# Words suppliers add that do not change which item it is.
_FILLER = {
    "new",
    "the",
    "a",
    "an",
    "of",
    "and",
    "game",
    "pc",
    "official",
    "original",
    "latest",
    "version",
    "full",
}
_ROMAN = {
    "ii": "2",
    "iii": "3",
    "iv": "4",
    "v": "5",
    "vi": "6",
    "vii": "7",
    "viii": "8",
    "ix": "9",
    "x": "10",
}


def core_tokens(key: str) -> list[str]:
    """Tokens that identify the item: no filler words or years; roman numerals as digits."""
    out = []
    for t in key.split():
        if t in _FILLER or _YEAR.match(t):
            continue
        out.append(_ROMAN.get(t, t))
    return out


def numbers(tokens: list[str]) -> set[str]:
    return {t for t in tokens if t.isdigit()}


def score_pair(key_a: str, key_b: str, trigram: float) -> tuple[Decimal, list[str]] | None:
    """Similarity of two normalised titles, or None when they are clearly different items (sequel numbers)."""
    a, b = core_tokens(key_a), core_tokens(key_b)
    na, nb = numbers(a), numbers(b)
    if (na or nb) and na != nb:
        return None  # "FIFA 23" vs "FIFA 24", "Diablo 3" vs "Diablo 4": different games.
    reasons = [f"Titles {round(trigram * 100)}% alike"]
    score = trigram
    if a and a == b:
        score = max(score, 0.95)
        reasons = ["Same title once years and filler words are removed"]
    elif a and b:
        sa, sb = set(a), set(b)
        jac = len(sa & sb) / len(sa | sb)
        if jac * 0.9 > score:
            score = jac * 0.9
            reasons = [f"{len(sa & sb)} of {len(sa | sb)} title words match"]
    return Decimal(str(round(min(score, 0.99), 3))), reasons


def _adjust(score: Decimal, reasons: list[str], p: Product, c: Product) -> Decimal:
    for attr, label in (("developer", "developer"), ("publisher", "publisher")):
        x, y = (getattr(p, attr) or "").strip().lower(), (getattr(c, attr) or "").strip().lower()
        if x and y and x == y:
            score += Decimal("0.03")
            reasons.append(f"Same {label}")
    if p.release_date and c.release_date:
        gap = abs(p.release_date.year - c.release_date.year)
        if gap == 0:
            score += Decimal("0.03")
            reasons.append("Same release year")
        elif gap > 1:
            score -= Decimal("0.2")
            reasons.append("Release years differ")
    return max(Decimal("0"), min(score, Decimal("0.99")))


async def find_by_alias(db: AsyncSession, key: str, product_type: str) -> Product | None:
    if not key:
        return None
    pid = await db.scalar(
        select(ProductAlias.product_id).where(
            ProductAlias.alias_key == key, ProductAlias.product_type == product_type
        )
    )
    return await db.get(Product, pid) if pid else None


async def suggest_for(db: AsyncSession, product: Product, supplier_id: uuid.UUID | None) -> int:
    """Queue suggestions for a product that only one supplier has so far. Returns how many were added."""
    if not product.match_key or product.product_status == "merged":
        return 0
    await db.execute(
        text("SELECT set_config('pg_trgm.similarity_threshold', :t, true)"), {"t": str(SEARCH_THRESHOLD)}
    )
    sim = func.similarity(Product.match_key, product.match_key)
    q = (
        select(Product, sim.label("sim"))
        .where(
            Product.product_type == product.product_type,
            Product.product_status != "merged",
            Product.id != product.id,
            Product.match_key.op("%")(product.match_key),
        )
        .order_by(sim.desc())
        .limit(15)
    )
    if supplier_id is not None:
        # Only items this supplier does not already supply: two records from one supplier are separate offers.
        q = q.where(
            ~select(SupplierProduct.id)
            .where(SupplierProduct.product_id == Product.id, SupplierProduct.supplier_id == supplier_id)
            .exists()
        )
    added = 0
    scored: list[tuple[Decimal, Product, list[str]]] = []
    for cand, trigram in (await db.execute(q)).all():
        r = score_pair(product.match_key, cand.match_key, float(trigram))
        if r is None:
            continue
        score, reasons = r
        score = _adjust(score, reasons, product, cand)
        if score >= SUGGEST_AT:
            scored.append((score, cand, reasons))
    for score, cand, reasons in sorted(scored, key=lambda x: x[0], reverse=True)[:MAX_CANDIDATES]:
        res = await db.execute(
            pg_insert(MatchSuggestion)
            .values(
                id=uuid.uuid4(),
                product_id=product.id,
                candidate_id=cand.id,
                supplier_id=supplier_id,
                score=score,
                reasons=reasons,
                status="pending",
            )
            .on_conflict_do_nothing(index_elements=["product_id", "candidate_id"])
        )
        added += res.rowcount or 0
    return added


async def scan(db: AsyncSession, limit: int = 2000, restart: bool = False) -> dict[str, Any]:
    """Look for suggestions among existing single-supplier products, newest first, `limit` per run.

    A cursor in app_settings remembers where the last run stopped, so repeated runs cover the whole catalogue.
    """
    from app.db.models import AppSetting

    row = await db.get(AppSetting, "matching_scan")
    if row is None:
        row = AppSetting(key="matching_scan", value={})
        db.add(row)
    before = None if restart else (row.value or {}).get("before")
    one_supplier = (
        select(
            SupplierProduct.product_id.label("pid"),
            func.min(cast(SupplierProduct.supplier_id, String)).label("sid"),
        )
        .where(SupplierProduct.product_id.is_not(None))
        .group_by(SupplierProduct.product_id)
        .having(func.count(func.distinct(SupplierProduct.supplier_id)) == 1)
        .subquery()
    )
    q = (
        select(Product, one_supplier.c.sid)
        .join(one_supplier, one_supplier.c.pid == Product.id)
        .where(Product.product_status != "merged")
        .order_by(Product.created_at.desc(), Product.id.desc())
        .limit(limit)
    )
    if before:
        q = q.where(Product.created_at < datetime.fromisoformat(before))
    rows = (await db.execute(q)).all()
    found = 0
    for product, sid in rows:
        found += await suggest_for(db, product, uuid.UUID(sid))
    done = len(rows) < limit
    row.value = {
        "before": None if done else rows[-1][0].created_at.isoformat(),
        "last_run_at": datetime.now(UTC).isoformat(),
        "complete": done,
    }
    await db.flush()
    return {"checked": len(rows), "suggestions": found, "complete": done}


async def add_alias(
    db: AsyncSession, product: Product, alias: str, source: str, actor_id: uuid.UUID | None
) -> ProductAlias:
    alias = alias.strip()[:300]
    key = match_key(alias)
    if not key:
        raise AppError(422, "alias_empty", "Enter a name with letters or numbers.")
    if key == product.match_key:
        raise AppError(422, "alias_same", "That name already matches this product.")
    clash = await db.scalar(
        select(Product.id).where(
            Product.match_key == key,
            Product.product_type == product.product_type,
            Product.id != product.id,
            Product.product_status != "merged",
        )
    )
    if clash:
        raise AppError(
            409, "alias_is_product", "Another product already has this name. Merge the two products instead."
        )
    existing = (
        await db.execute(
            select(ProductAlias).where(
                ProductAlias.alias_key == key, ProductAlias.product_type == product.product_type
            )
        )
    ).scalar_one_or_none()
    if existing is not None:
        if existing.product_id != product.id:
            raise AppError(409, "alias_taken", "This name is already an alias of another product.")
        return existing
    row = ProductAlias(
        product_id=product.id,
        alias=alias,
        alias_key=key,
        product_type=product.product_type,
        source=source,
        created_by=actor_id,
    )
    db.add(row)
    await db.flush()
    return row


async def accept(db: AsyncSession, s: MatchSuggestion, actor_id: uuid.UUID | None) -> Product:
    """Merge the two products and remember the merged product's title as an alias of the survivor."""
    from app.catalogue.services import CatalogueService

    if s.status != "pending":
        raise AppError(409, "match_decided", "This suggestion was already handled.")
    a, b = await db.get(Product, s.product_id), await db.get(Product, s.candidate_id)
    if a is None or b is None or "merged" in (a.product_status, b.product_status):
        s.status = "superseded"
        await db.flush()
        raise AppError(
            409, "match_stale", "One of these products was already merged. The suggestion was closed."
        )
    # The survivor is the product already on a storefront, else the one that was in the catalogue first.
    listed = {
        pid
        for (pid,) in (
            await db.execute(
                select(StorefrontProductMapping.product_id).where(
                    StorefrontProductMapping.product_id.in_([a.id, b.id])
                )
            )
        ).all()
    }
    if (a.id in listed) != (b.id in listed):
        target, source = (a, b) if a.id in listed else (b, a)
    else:
        target, source = (b, a) if b.created_at <= a.created_at else (a, b)
    source_title, source_key = source.canonical_title, source.match_key
    survivor = await CatalogueService(db).merge(source.id, target.id)
    if source_key and source_key != survivor.match_key:
        existing = await db.scalar(
            select(ProductAlias.id).where(
                ProductAlias.alias_key == source_key, ProductAlias.product_type == survivor.product_type
            )
        )
        if existing is None:
            db.add(
                ProductAlias(
                    product_id=survivor.id,
                    alias=source_title[:300],
                    alias_key=source_key,
                    product_type=survivor.product_type,
                    source="review",
                    created_by=actor_id,
                )
            )
    # Aliases that pointed at the merged product now point at the survivor.
    await db.execute(
        update(ProductAlias).where(ProductAlias.product_id == source.id).values(product_id=survivor.id)
    )
    now = datetime.now(UTC)
    s.status, s.decided_by, s.decided_at = "accepted", actor_id, now
    await db.execute(
        update(MatchSuggestion)
        .where(
            MatchSuggestion.id != s.id,
            MatchSuggestion.status == "pending",
            (MatchSuggestion.product_id == source.id) | (MatchSuggestion.candidate_id == source.id),
        )
        .values(status="superseded", decided_at=now)
    )
    await db.flush()
    return survivor


async def reject(db: AsyncSession, s: MatchSuggestion, actor_id: uuid.UUID | None) -> None:
    if s.status != "pending":
        raise AppError(409, "match_decided", "This suggestion was already handled.")
    s.status, s.decided_by, s.decided_at = "rejected", actor_id, datetime.now(UTC)
    await db.flush()


async def product_summary(db: AsyncSession, p: Product) -> dict[str, Any]:
    """What a reviewer needs to compare two products side by side."""
    from app.db.models import ProductMedia, Supplier, SupplierOffer

    suppliers = [
        {"code": code, "title": title, "external_id": ext}
        for code, title, ext in (
            await db.execute(
                select(Supplier.code, SupplierProduct.original_title, SupplierProduct.external_product_id)
                .join(Supplier, Supplier.id == SupplierProduct.supplier_id)
                .where(SupplierProduct.product_id == p.id)
                .order_by(Supplier.code)
                .limit(6)
            )
        ).all()
    ]
    variants = [
        " · ".join(x for x in (plat, reg, ed) if x)
        for plat, reg, ed in (
            await db.execute(
                select(ProductVariant.platform, ProductVariant.region, ProductVariant.edition)
                .where(ProductVariant.product_id == p.id)
                .order_by(ProductVariant.platform)
                .limit(6)
            )
        ).all()
    ]
    image = await db.scalar(
        select(ProductMedia.source_url)
        .where(ProductMedia.product_id == p.id)
        .order_by((ProductMedia.media_type == "cover").desc(), ProductMedia.created_at)
        .limit(1)
    )
    offers = await db.scalar(
        select(func.count(SupplierOffer.id))
        .join(ProductVariant, ProductVariant.id == SupplierOffer.variant_id)
        .where(ProductVariant.product_id == p.id, SupplierOffer.availability_status == "in_stock")
    )
    return {
        "id": str(p.id),
        "title": p.canonical_title,
        "product_type": p.product_type,
        "status": p.product_status,
        "developer": p.developer,
        "publisher": p.publisher,
        "release_date": p.release_date.isoformat() if p.release_date else None,
        "image": image,
        "suppliers": suppliers,
        "variants": variants,
        "offers_in_stock": int(offers or 0),
    }
