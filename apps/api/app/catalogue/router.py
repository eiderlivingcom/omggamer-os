"""Catalogue API: products, supplier records, categories, suppliers and storefronts."""

import uuid
from typing import Literal

from fastapi import APIRouter, Query, Request, Response
from sqlalchemy import select

from app.ai import queue as ai_queue
from app.catalogue.bulk import BulkService
from app.catalogue.repositories import StorefrontRepository, SupplierRepository
from app.catalogue.schemas import (
    ActivityOut,
    BulkIn,
    BulkResult,
    CategoryIn,
    CategoryNode,
    CategoryRef,
    CategoryUpdateIn,
    ContentOut,
    ContentVersionOut,
    MediaOut,
    MergeIn,
    OfferOut,
    ProductCategoriesIn,
    ProductDetail,
    ProductListItem,
    ProductPage,
    ProductType,
    ProductUpdateIn,
    PublishingEvent,
    PublishingHistory,
    PublishingMappingOut,
    StorefrontOut,
    SupplierOut,
    SupplierProductDetail,
    SupplierProductOut,
    VariantOut,
)
from app.catalogue.services import CatalogueService
from app.core.deps import DB, CurrentUser, require
from app.core.errors import AppError
from app.db.models import (
    AuditLog,
    Product,
    ProductContent,
    PublishingQueueItem,
    Storefront,
    StorefrontProductMapping,
    Supplier,
    SupplierProduct,
    SyncLog,
    User,
)
from app.modules.audit.service import record
from app.publishing import queue as publish_queue

router = APIRouter(tags=["catalogue"])


async def _product(db: DB, product_id: uuid.UUID) -> Product:
    p = await db.get(Product, product_id)
    if p is None:
        raise AppError(404, "not_found", "Product not found.")
    return p


@router.get("/catalogue/products", response_model=ProductPage)
async def list_products(
    db: DB,
    _: User = require("catalogue:read"),
    q: str | None = Query(
        default=None,
        max_length=200,
        description="Title, product ID, supplier ID, SKU, developer or publisher",
    ),
    product_type: ProductType | None = None,
    status: str | None = Query(default=None, max_length=20),
    platform: str | None = Query(default=None, max_length=80),
    region: str | None = Query(default=None, max_length=80),
    supplier: str | None = Query(default=None, max_length=40),
    category_id: uuid.UUID | None = None,
    availability: Literal["available", "unavailable"] | None = None,
    edition: str | None = Query(default=None, max_length=120),
    ai_status: Literal["none", "draft", "pending_review", "approved", "rejected"] | None = None,
    publishing: Literal["not_published", "pending", "published", "failed", "unpublished"] | None = None,
    include_merged: bool = False,
    page: int = Query(default=1, ge=1, le=100000),
    page_size: int = Query(default=50, ge=1, le=200),
) -> ProductPage:
    svc = CatalogueService(db)
    stmt = svc.products.search_query(
        q=q,
        product_type=product_type,
        status=status,
        platform=platform,
        region=region,
        supplier=supplier,
        category_id=category_id,
        availability=availability,
        include_merged=include_merged,
        edition=edition,
        ai_status=ai_status,
        publishing=publishing,
    )
    rows, total = await svc.products.page(stmt, page, page_size)
    agg = await svc.products.list_summaries([p.id for p in rows])
    return ProductPage(
        items=[
            ProductListItem(
                id=p.id,
                canonical_title=p.canonical_title,
                slug=p.slug,
                product_type=p.product_type,
                product_status=p.product_status,
                developer=p.developer,
                publisher=p.publisher,
                updated_at=p.updated_at,
                **agg[p.id],
            )
            for p in rows
        ],
        total=total,
        page=page,
        page_size=page_size,
    )


async def _detail(db: DB, p: Product) -> ProductDetail:
    svc = CatalogueService(db)
    variants = await svc.variants.for_product(p.id)
    offers = await svc.offers.for_product(p.id)
    sps = await svc.supplier_products.for_product(p.id)
    cats = await svc.categories.mappings_for(p.id)
    media = await svc.media.for_product(p.id)
    content = await svc.current_content(p.id)
    merged_from = (
        (await db.execute(select(Product.id).where(Product.master_product_id == p.id))).scalars().all()
    )

    available = [
        o for o in offers if o.availability_status in ("in_stock", "preorder") and o.available_quantity > 0
    ]

    def variant_out(v: object) -> VariantOut:
        out = VariantOut.model_validate(v)
        mine = [o for o in offers if o.variant_id == out.id]
        cheap = sorted((o for o in available if o.variant_id == out.id), key=lambda o: o.supplier_price)
        out.offer_count = len(mine)
        if cheap:
            out.lowest_price, out.lowest_price_currency = cheap[0].supplier_price, cheap[0].currency
        return out

    return ProductDetail(
        id=p.id,
        master_product_id=p.master_product_id,
        canonical_title=p.canonical_title,
        slug=p.slug,
        match_key=p.match_key,
        product_type=p.product_type,
        product_status=p.product_status,
        brand=p.brand,
        developer=p.developer,
        publisher=p.publisher,
        release_date=p.release_date,
        created_at=p.created_at,
        updated_at=p.updated_at,
        variants=[variant_out(v) for v in variants],
        offers=[OfferOut.model_validate(o) for o in offers],
        supplier_products=[
            SupplierProductOut(
                id=sp.id,
                supplier_code=code,
                external_product_id=sp.external_product_id,
                external_product_set_id=sp.external_product_set_id,
                original_title=sp.original_title,
                variant_id=sp.variant_id,
                import_status=sp.import_status,
                last_fetched_at=sp.last_fetched_at,
            )
            for sp, code in sps
        ],
        categories=[
            CategoryRef(id=c.id, name=c.name, slug=c.slug, source=m.source, confidence=m.confidence)
            for c, m in cats
        ],
        media=[MediaOut.model_validate(m) for m in media],
        content=ContentOut.model_validate(content) if content else None,
        merged_from=list(merged_from),
    )


@router.get("/catalogue/products/{product_id}", response_model=ProductDetail)
async def get_product(product_id: uuid.UUID, db: DB, _: User = require("catalogue:read")) -> ProductDetail:
    return await _detail(db, await _product(db, product_id))


@router.patch("/catalogue/products/{product_id}", response_model=ProductDetail)
async def update_product(
    product_id: uuid.UUID,
    body: ProductUpdateIn,
    request: Request,
    db: DB,
    actor: CurrentUser,
    _: User = require("catalogue:write"),
) -> ProductDetail:
    p = await _product(db, product_id)
    if p.product_status == "merged":
        raise AppError(
            409, "product_merged", "This product was merged into another one; edit that product instead."
        )
    changes: dict[str, list[object]] = {}
    for field, value in body.model_dump(exclude_unset=True).items():
        if getattr(p, field) != value:
            changes[field] = [str(getattr(p, field)), str(value)]
            setattr(p, field, value)
    if "canonical_title" in changes:
        # The slug follows the title only while the product has never been published.
        if p.product_status in ("draft", "review"):
            p.slug = await CatalogueService(db).products.unique_slug(p.canonical_title, exclude_id=p.id)
    if changes:
        await record(
            db,
            "product.updated",
            actor=actor,
            entity_type="product",
            entity_id=str(p.id),
            summary=f"Edited {p.canonical_title}",
            data=changes,
            request=request,
        )
    await db.commit()
    await db.refresh(p)
    return await _detail(db, p)


@router.post("/catalogue/products/{product_id}/merge", response_model=ProductDetail)
async def merge_product(
    product_id: uuid.UUID,
    body: MergeIn,
    request: Request,
    db: DB,
    actor: CurrentUser,
    _: User = require("catalogue:write"),
) -> ProductDetail:
    source = await _product(db, product_id)
    title = source.canonical_title
    target = await CatalogueService(db).merge(product_id, body.target_product_id)
    await record(
        db,
        "product.merged",
        actor=actor,
        entity_type="product",
        entity_id=str(target.id),
        summary=f"Merged {title} into {target.canonical_title}",
        data={"source": str(product_id), "target": str(target.id)},
        request=request,
    )
    await db.commit()
    await db.refresh(target)
    return await _detail(db, target)


@router.put("/catalogue/products/{product_id}/categories", response_model=ProductDetail)
async def set_categories(
    product_id: uuid.UUID,
    body: ProductCategoriesIn,
    request: Request,
    db: DB,
    actor: CurrentUser,
    _: User = require("catalogue:write"),
) -> ProductDetail:
    p = await _product(db, product_id)
    await CatalogueService(db).set_product_categories(p.id, body.category_ids)
    await record(
        db,
        "product.categories_set",
        actor=actor,
        entity_type="product",
        entity_id=str(p.id),
        summary=f"Set categories of {p.canonical_title}",
        data={"category_ids": [str(c) for c in body.category_ids]},
        request=request,
    )
    await db.commit()
    return await _detail(db, p)


@router.get("/catalogue/supplier-products/{supplier_product_id}", response_model=SupplierProductDetail)
async def get_supplier_product(
    supplier_product_id: uuid.UUID, db: DB, _: User = require("catalogue:read")
) -> SupplierProductDetail:
    row = (
        await db.execute(
            select(SupplierProduct, Supplier.code)
            .join(Supplier, Supplier.id == SupplierProduct.supplier_id)
            .where(SupplierProduct.id == supplier_product_id)
        )
    ).first()
    if row is None:
        raise AppError(404, "not_found", "Supplier record not found.")
    sp, code = row
    return SupplierProductDetail(
        id=sp.id,
        supplier_code=code,
        external_product_id=sp.external_product_id,
        external_product_set_id=sp.external_product_set_id,
        original_title=sp.original_title,
        variant_id=sp.variant_id,
        import_status=sp.import_status,
        last_fetched_at=sp.last_fetched_at,
        original_description=sp.original_description,
        raw_payload=sp.raw_payload,
        payload_checksum=sp.payload_checksum,
    )


# --- categories


@router.get("/catalogue/categories", response_model=list[CategoryNode])
async def category_tree(db: DB, _: User = require("catalogue:read")) -> list[CategoryNode]:
    rows = await CatalogueService(db).categories.all_with_counts()
    nodes = {
        c.id: CategoryNode(
            id=c.id, name=c.name, slug=c.slug, parent_id=c.parent_id, position=c.position, product_count=n
        )
        for c, n in rows
    }
    roots: list[CategoryNode] = []
    for node in nodes.values():
        parent = nodes.get(node.parent_id) if node.parent_id else None
        (parent.children if parent else roots).append(node)
    return roots


@router.post("/catalogue/categories", response_model=CategoryNode, status_code=201)
async def create_category(
    body: CategoryIn, request: Request, db: DB, actor: CurrentUser, _: User = require("catalogue:write")
) -> CategoryNode:
    cat = await CatalogueService(db).create_category(body.name, body.parent_id, body.slug, body.position)
    await record(
        db,
        "category.created",
        actor=actor,
        entity_type="category",
        entity_id=str(cat.id),
        summary=f"Created category {cat.name}",
        request=request,
    )
    await db.commit()
    return CategoryNode(
        id=cat.id,
        name=cat.name,
        slug=cat.slug,
        parent_id=cat.parent_id,
        position=cat.position,
        product_count=0,
    )


@router.patch("/catalogue/categories/{category_id}", response_model=CategoryNode)
async def update_category(
    category_id: uuid.UUID,
    body: CategoryUpdateIn,
    request: Request,
    db: DB,
    actor: CurrentUser,
    _: User = require("catalogue:write"),
) -> CategoryNode:
    svc = CatalogueService(db)
    cat = await svc.categories.get(category_id)
    if cat is None:
        raise AppError(404, "not_found", "Category not found.")
    if body.name is not None:
        cat.name = body.name.strip()
    if body.position is not None:
        cat.position = body.position
    if body.move_to_root:
        await svc.move_category(cat, None)
    elif body.parent_id is not None:
        await svc.move_category(cat, body.parent_id)
    await record(
        db,
        "category.updated",
        actor=actor,
        entity_type="category",
        entity_id=str(cat.id),
        summary=f"Updated category {cat.name}",
        request=request,
    )
    await db.commit()
    return CategoryNode(
        id=cat.id,
        name=cat.name,
        slug=cat.slug,
        parent_id=cat.parent_id,
        position=cat.position,
        product_count=0,
    )


@router.delete("/catalogue/categories/{category_id}", status_code=204)
async def delete_category(
    category_id: uuid.UUID, request: Request, db: DB, actor: CurrentUser, _: User = require("catalogue:write")
) -> Response:
    svc = CatalogueService(db)
    cat = await svc.categories.get(category_id)
    if cat is None:
        raise AppError(404, "not_found", "Category not found.")
    name = cat.name
    await svc.delete_category(cat)
    await record(
        db,
        "category.deleted",
        actor=actor,
        entity_type="category",
        entity_id=str(category_id),
        summary=f"Deleted category {name}",
        request=request,
    )
    await db.commit()
    return Response(status_code=204)


# --- suppliers & storefronts


@router.get("/suppliers", response_model=list[SupplierOut])
async def list_suppliers(db: DB, _: User = require("suppliers:read")) -> list[SupplierOut]:
    out = []
    for s, n in await SupplierRepository(db).all_with_counts():
        item = SupplierOut.model_validate(s)
        item.supplier_products = n
        out.append(item)
    return out


@router.get("/storefronts", response_model=list[StorefrontOut])
async def list_storefronts(db: DB, _: User = require("catalogue:read")) -> list[StorefrontOut]:
    out = []
    for s, n in await StorefrontRepository(db).all_with_counts():
        item = StorefrontOut.model_validate(s)
        item.mapped_products = n
        out.append(item)
    return out


# ------------------------------------------------------------ Phase 4: bulk, history, activity


@router.post("/catalogue/products/bulk", response_model=BulkResult)
async def bulk(body: BulkIn, request: Request, db: DB, actor: CurrentUser) -> BulkResult:
    svc = BulkService(db, actor)
    result = await svc.run(body)
    await record(
        db,
        f"product.bulk.{body.action}",
        actor=actor,
        entity_type="product",
        summary=f"Bulk {body.action.replace('_', ' ')}: {result.changed} of {result.requested} products",
        data={"product_ids": [str(i) for i in body.product_ids[:50]], **result.details},
        request=request,
    )
    for pid in body.product_ids[:200] if result.changed else []:
        await record(
            db,
            f"product.{body.action}",
            actor=actor,
            entity_type="product",
            entity_id=str(pid),
            summary=f"{body.action.replace('_', ' ').capitalize()} (bulk)",
            request=request,
        )
    await db.commit()
    ai_queue.enqueue(svc.ai_job_ids)
    publish_queue.enqueue(svc.publish_ids)
    return result


@router.get("/catalogue/products/{product_id}/content", response_model=list[ContentVersionOut])
async def content_versions(
    product_id: uuid.UUID, db: DB, _: User = require("catalogue:read")
) -> list[ProductContent]:
    await _product(db, product_id)
    q = (
        select(ProductContent)
        .where(ProductContent.product_id == product_id)
        .order_by(ProductContent.locale, ProductContent.version.desc())
        .limit(50)
    )
    return list((await db.execute(q)).scalars())


@router.get("/catalogue/products/{product_id}/publishing", response_model=PublishingHistory)
async def publishing_history(
    product_id: uuid.UUID, db: DB, _: User = require("catalogue:read")
) -> PublishingHistory:
    await _product(db, product_id)
    maps = await db.execute(
        select(StorefrontProductMapping, Storefront.code)
        .join(Storefront, Storefront.id == StorefrontProductMapping.storefront_id)
        .where(StorefrontProductMapping.product_id == product_id)
    )
    queue = await db.execute(
        select(PublishingQueueItem, Storefront.code)
        .outerjoin(Storefront, Storefront.id == PublishingQueueItem.storefront_id)
        .where(PublishingQueueItem.product_id == product_id)
        .order_by(PublishingQueueItem.created_at.desc())
        .limit(50)
    )
    logs = await db.execute(
        select(SyncLog, Storefront.code)
        .join(Storefront, Storefront.id == SyncLog.storefront_id)
        .where(SyncLog.product_id == product_id)
        .order_by(SyncLog.created_at.desc())
        .limit(100)
    )
    events = [
        PublishingEvent(
            kind="queue",
            storefront=code,
            action=q.action,
            status=q.status,
            detail=q.last_error,
            created_at=q.created_at,
        )
        for q, code in queue.tuples().all()
    ] + [
        PublishingEvent(
            kind="sync",
            storefront=code,
            action=s.operation,
            status=s.status,
            detail=str(s.response.get("message") or s.response.get("error") or "") or None,
            created_at=s.created_at,
        )
        for s, code in logs.tuples().all()
    ]
    events.sort(key=lambda e: e.created_at, reverse=True)
    return PublishingHistory(
        mappings=[
            PublishingMappingOut(
                storefront=code,
                external_product_id=m.external_product_id,
                sync_status=m.sync_status,
                last_synced_at=m.last_synced_at,
                last_error=m.last_error,
            )
            for m, code in maps.tuples().all()
        ],
        events=events,
    )


@router.get("/catalogue/products/{product_id}/activity", response_model=list[ActivityOut])
async def activity(
    product_id: uuid.UUID,
    db: DB,
    limit: int = Query(default=100, ge=1, le=500),
    _: User = require("catalogue:read"),
) -> list[AuditLog]:
    await _product(db, product_id)
    q = (
        select(AuditLog)
        .where(AuditLog.entity_type == "product", AuditLog.entity_id == str(product_id))
        .order_by(AuditLog.created_at.desc())
        .limit(limit)
    )
    return list((await db.execute(q)).scalars())
