"""Match review (possible duplicates across suppliers) and product aliases."""

import uuid
from typing import Any, Literal

from fastapi import APIRouter, Query, Request
from pydantic import BaseModel, Field
from sqlalchemy import func, select

from app.catalogue import matching
from app.core.deps import DB, CurrentUser, require
from app.core.errors import AppError
from app.db.models import AppSetting, MatchSuggestion, Product, ProductAlias, Supplier, User
from app.modules.audit.service import record

router = APIRouter(tags=["matching"])


class AliasIn(BaseModel):
    alias: str = Field(min_length=2, max_length=300)


class ScanIn(BaseModel):
    restart: bool = False


async def _suggestion(db: DB, sid: uuid.UUID) -> MatchSuggestion:
    s = await db.get(MatchSuggestion, sid)
    if s is None:
        raise AppError(404, "not_found", "Suggestion not found.")
    return s


@router.get("/catalogue/type-counts", response_model=dict[str, int])
async def type_counts(db: DB, _: User = require("catalogue:read")) -> dict[str, int]:
    """Products per type, for the type tabs on the catalogue screen (merged duplicates excluded)."""
    rows = await db.execute(
        select(Product.product_type, func.count(Product.id))
        .where(Product.product_status != "merged")
        .group_by(Product.product_type)
    )
    return {t: int(n) for t, n in rows.tuples().all()}


@router.get("/catalogue/matches", response_model=dict[str, Any])
async def list_matches(
    db: DB,
    status: Literal["pending", "accepted", "rejected", "superseded"] = "pending",
    page: int = Query(default=1, ge=1),
    page_size: int = Query(default=20, ge=1, le=50),
    _: User = require("catalogue:read"),
) -> dict[str, Any]:
    total = (
        await db.scalar(select(func.count(MatchSuggestion.id)).where(MatchSuggestion.status == status)) or 0
    )
    order = (
        (MatchSuggestion.score.desc(), MatchSuggestion.created_at.desc())
        if status == "pending"
        else (MatchSuggestion.decided_at.desc().nulls_last(),)
    )
    rows = (
        await db.execute(
            select(MatchSuggestion, Supplier.name)
            .outerjoin(Supplier, Supplier.id == MatchSuggestion.supplier_id)
            .where(MatchSuggestion.status == status)
            .order_by(*order)
            .offset((page - 1) * page_size)
            .limit(page_size)
        )
    ).all()
    items = []
    for s, supplier_name in rows:
        a, b = await db.get(Product, s.product_id), await db.get(Product, s.candidate_id)
        if a is None or b is None:
            continue
        items.append(
            {
                "id": str(s.id),
                "score": float(s.score),
                "reasons": s.reasons,
                "status": s.status,
                "supplier": supplier_name,
                "created_at": s.created_at.isoformat(),
                "decided_at": s.decided_at.isoformat() if s.decided_at else None,
                "product": await matching.product_summary(db, a),
                "candidate": await matching.product_summary(db, b),
            }
        )
    scan = await db.get(AppSetting, "matching_scan")
    counts = dict(
        (await db.execute(select(MatchSuggestion.status, func.count()).group_by(MatchSuggestion.status)))
        .tuples()
        .all()
    )
    return {
        "items": items,
        "total": int(total),
        "page": page,
        "page_size": page_size,
        "counts": {k: int(v) for k, v in counts.items()},
        "scan": scan.value if scan else None,
    }


@router.post("/catalogue/matches/{sid}/accept", response_model=dict[str, Any])
async def accept_match(
    sid: uuid.UUID, request: Request, db: DB, actor: CurrentUser, _: User = require("catalogue:write")
) -> dict[str, Any]:
    s = await _suggestion(db, sid)
    a, b = await db.get(Product, s.product_id), await db.get(Product, s.candidate_id)
    titles = f"{a.canonical_title if a else '?'} + {b.canonical_title if b else '?'}"
    try:
        survivor = await matching.accept(db, s, actor.id)
    except AppError:
        await db.commit()  # a stale suggestion is closed even though nothing was merged
        raise
    await record(
        db,
        "product.matched",
        actor=actor,
        entity_type="product",
        entity_id=str(survivor.id),
        summary=f"Matched {titles} into {survivor.canonical_title}",
        data={"suggestion": str(s.id), "product": str(s.product_id), "candidate": str(s.candidate_id)},
        request=request,
    )
    await db.commit()
    return {"ok": True, "product_id": str(survivor.id), "title": survivor.canonical_title}


@router.post("/catalogue/matches/{sid}/reject", response_model=dict[str, Any])
async def reject_match(
    sid: uuid.UUID, request: Request, db: DB, actor: CurrentUser, _: User = require("catalogue:write")
) -> dict[str, Any]:
    s = await _suggestion(db, sid)
    await matching.reject(db, s, actor.id)
    await record(
        db,
        "product.match_rejected",
        actor=actor,
        entity_type="product",
        entity_id=str(s.product_id),
        summary="Marked two products as different items",
        data={"suggestion": str(s.id), "candidate": str(s.candidate_id)},
        request=request,
    )
    await db.commit()
    return {"ok": True}


@router.post("/catalogue/matches/scan", response_model=dict[str, Any])
async def scan_matches(
    body: ScanIn, db: DB, actor: CurrentUser, _: User = require("catalogue:write")
) -> dict[str, Any]:
    """Queue a search for possible duplicates among products imported before matching existed."""
    from app.workers.celery_app import celery_app

    celery_app.send_task("matching.scan", kwargs={"restart": body.restart}, queue="default")
    return {"queued": True}


@router.get("/catalogue/products/{product_id}/aliases", response_model=list[dict[str, Any]])
async def list_aliases(
    product_id: uuid.UUID, db: DB, _: User = require("catalogue:read")
) -> list[dict[str, Any]]:
    rows = (
        await db.execute(
            select(ProductAlias)
            .where(ProductAlias.product_id == product_id)
            .order_by(ProductAlias.created_at)
        )
    ).scalars()
    return [
        {"id": str(a.id), "alias": a.alias, "source": a.source, "created_at": a.created_at.isoformat()}
        for a in rows
    ]


@router.post("/catalogue/products/{product_id}/aliases", response_model=dict[str, Any])
async def add_alias(
    product_id: uuid.UUID,
    body: AliasIn,
    request: Request,
    db: DB,
    actor: CurrentUser,
    _: User = require("catalogue:write"),
) -> dict[str, Any]:
    p = await db.get(Product, product_id)
    if p is None or p.product_status == "merged":
        raise AppError(404, "not_found", "Product not found.")
    a = await matching.add_alias(db, p, body.alias, "manual", actor.id)
    await record(
        db,
        "product.alias_added",
        actor=actor,
        entity_type="product",
        entity_id=str(p.id),
        summary=f"Added alias “{a.alias}” to {p.canonical_title}",
        request=request,
    )
    await db.commit()
    return {"id": str(a.id), "alias": a.alias, "source": a.source, "created_at": a.created_at.isoformat()}


@router.delete("/catalogue/products/{product_id}/aliases/{alias_id}", status_code=204)
async def delete_alias(
    product_id: uuid.UUID,
    alias_id: uuid.UUID,
    request: Request,
    db: DB,
    actor: CurrentUser,
    _: User = require("catalogue:write"),
) -> None:
    a = await db.get(ProductAlias, alias_id)
    if a is None or a.product_id != product_id:
        raise AppError(404, "not_found", "Alias not found.")
    await db.delete(a)
    await record(
        db,
        "product.alias_removed",
        actor=actor,
        entity_type="product",
        entity_id=str(product_id),
        summary=f"Removed alias “{a.alias}”",
        request=request,
    )
    await db.commit()
