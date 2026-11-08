"""Orders: the storefront order webhook and the admin API. Keys are never returned by any of these routes."""

import uuid
from datetime import UTC, datetime, timedelta
from decimal import Decimal, InvalidOperation
from typing import Any

from fastapi import APIRouter, Query, Request
from pydantic import BaseModel, Field
from sqlalchemy import func, select
from sqlalchemy.dialects.postgresql import insert as pg_insert

from app.core.deps import DB, require
from app.core.errors import AppError
from app.db.models import (
    AppSetting,
    DeliveredKey,
    Order,
    OrderEvent,
    OrderItem,
    SupplierOrder,
    User,
)
from app.modules.audit.service import record
from app.orders.fulfil import DEFAULTS, event, order_settings
from app.suppliers import registry
from app.suppliers.base import SupplierError
from app.sync.router import verify_storefront
from app.workers.celery_app import celery_app

router = APIRouter(tags=["orders"])


def _now() -> datetime:
    return datetime.now(UTC)


def enqueue(order_id: uuid.UUID, countdown: float = 0) -> None:
    try:
        celery_app.send_task(
            "orders.fulfil", args=[str(order_id)], queue="default", countdown=countdown or None
        )
    except Exception:  # the orders watchdog picks it up
        from app.core.logging import log

        log.exception("order_enqueue_failed", order_id=str(order_id))


def _dec(v: Any) -> Decimal:
    try:
        return Decimal(str(v)).quantize(Decimal("0.01"))
    except (InvalidOperation, ValueError):
        return Decimal("0")


# ------------------------------------------------------------------ webhook from the store


@router.post("/webhooks/storefronts/{code}/orders", response_model=dict[str, Any])
async def order_webhook(code: str, request: Request, db: DB) -> dict[str, Any]:
    """Paid storefront order. Idempotent: the same WooCommerce order is stored once."""
    import json

    sf, body = await verify_storefront(request, db, code)
    try:
        data = json.loads(body)
        ext = str(data["order_id"])
        items = data["items"]
        assert isinstance(items, list)
    except Exception as exc:
        raise AppError(422, "validation_error", "Send order_id and items.") from exc

    stmt = (
        pg_insert(Order)
        .values(
            id=uuid.uuid4(),
            storefront_id=sf.id,
            external_order_id=ext[:80],
            order_number=str(data.get("number") or ext)[:80],
            status="received",
            store_status=str(data.get("status") or "")[:30],
            currency=str(data.get("currency") or "EUR")[:3].upper(),
            total=_dec(data.get("total")),
        )
        .on_conflict_do_update(
            index_elements=["storefront_id", "external_order_id"],
            set_={"store_status": str(data.get("status") or "")[:30], "updated_at": func.now()},
        )
        .returning(Order.id)
    )
    order_id = (await db.execute(stmt)).scalar_one()
    order = await db.get(Order, order_id)
    assert order is not None
    created = 0
    for raw in items[:100]:
        if not isinstance(raw, dict) or not raw.get("item_id"):
            continue
        variant_id = raw.get("variant_id")
        product_id = raw.get("product_external_id")
        try:
            vid = uuid.UUID(str(variant_id)) if variant_id else None
            pid = uuid.UUID(str(product_id)) if product_id else None
        except ValueError:
            vid = pid = None
        if vid is None:
            continue  # not an OMGGamer item (the store fulfils it itself)
        qty = max(1, int(raw.get("quantity") or 1))
        res = await db.execute(
            pg_insert(OrderItem)
            .values(
                id=uuid.uuid4(),
                order_id=order.id,
                external_item_id=str(raw["item_id"])[:80],
                name=str(raw.get("name") or "")[:300],
                product_id=pid,
                variant_id=vid,
                quantity=qty,
                unit_price=(_dec(raw.get("line_total")) / qty).quantize(Decimal("0.01")),
                status="pending",
            )
            .on_conflict_do_nothing(index_elements=["order_id", "external_item_id"])
            .returning(OrderItem.id)
        )
        created += len(res.all())
    if created:
        event(db, order, "received", f"Order {order.order_number} received with {created} OMGGamer item(s).")
    await db.commit()
    settings = await order_settings(db)
    if created and settings["auto_fulfil"]:
        enqueue(order.id)
    return {"ok": True, "order_id": str(order.id), "items_added": created}


# ------------------------------------------------------------------ admin API


class OrderSettingsIn(BaseModel):
    auto_fulfil: bool | None = None
    allow_loss: bool | None = None


class NoteIn(BaseModel):
    note: str = Field(default="", max_length=1000)


def _order_out(o: Order) -> dict[str, Any]:
    return {
        "id": str(o.id),
        "external_order_id": o.external_order_id,
        "order_number": o.order_number,
        "status": o.status,
        "store_status": o.store_status,
        "currency": o.currency,
        "total": str(o.total),
        "attempts": o.attempts,
        "last_error": o.last_error,
        "created_at": o.created_at.isoformat(),
        "updated_at": o.updated_at.isoformat(),
        "fulfilled_at": o.fulfilled_at.isoformat() if o.fulfilled_at else None,
    }


async def _order(db: DB, order_id: uuid.UUID) -> Order:
    o = await db.get(Order, order_id)
    if o is None:
        raise AppError(404, "not_found", "Order not found.")
    return o


@router.get("/orders/overview", response_model=dict[str, Any])
async def overview(db: DB, _: User = require("orders:read")) -> dict[str, Any]:
    counts = dict(
        (await db.execute(select(Order.status, func.count()).group_by(Order.status))).tuples().all()
    )
    since = _now() - timedelta(days=30)
    revenue = await db.scalar(
        select(func.coalesce(func.sum(Order.total), 0)).where(
            Order.created_at >= since, Order.status == "fulfilled"
        )
    )
    cost = await db.scalar(
        select(func.coalesce(func.sum(SupplierOrder.payment_price), 0)).where(
            SupplierOrder.created_at >= since, SupplierOrder.status.in_(("processing", "completed"))
        )
    )
    return {
        "counts": {k: int(v) for k, v in counts.items()},
        "revenue_30d": str(revenue or 0),
        "supplier_cost_30d": str(cost or 0),
        "settings": await order_settings(db),
    }


@router.put("/orders/settings", response_model=dict[str, Any])
async def put_settings(
    body: OrderSettingsIn, request: Request, db: DB, actor: User = require("orders:manage")
) -> dict[str, Any]:
    row = await db.get(AppSetting, "orders")
    if row is None:
        row = AppSetting(key="orders", value={})
        db.add(row)
    row.value = {**(row.value or {}), **body.model_dump(exclude_none=True)}
    await record(
        db,
        "orders.settings",
        actor=actor,
        summary="Changed order fulfilment settings",
        data=body.model_dump(exclude_none=True),
        request=request,
    )
    await db.commit()
    return {**DEFAULTS, **row.value}


@router.get("/orders", response_model=list[dict[str, Any]])
async def list_orders(
    db: DB,
    status: str | None = Query(default=None, max_length=30),
    q: str | None = Query(default=None, max_length=80),
    limit: int = Query(default=100, ge=1, le=500),
    _: User = require("orders:read"),
) -> list[dict[str, Any]]:
    stmt = select(Order)
    if status:
        stmt = stmt.where(Order.status == status)
    if q:
        stmt = stmt.where((Order.external_order_id == q.strip()) | (Order.order_number == q.strip()))
    rows = (await db.execute(stmt.order_by(Order.created_at.desc()).limit(limit))).scalars()
    return [_order_out(o) for o in rows]


@router.get("/orders/{order_id}", response_model=dict[str, Any])
async def get_order(order_id: uuid.UUID, db: DB, _: User = require("orders:read")) -> dict[str, Any]:
    o = await _order(db, order_id)
    items = list((await db.execute(select(OrderItem).where(OrderItem.order_id == o.id))).scalars())
    sos = list(
        (
            await db.execute(
                select(SupplierOrder).where(SupplierOrder.order_id == o.id).order_by(SupplierOrder.created_at)
            )
        ).scalars()
    )
    events = list(
        (
            await db.execute(
                select(OrderEvent)
                .where(OrderEvent.order_id == o.id)
                .order_by(OrderEvent.id.desc())
                .limit(200)
            )
        ).scalars()
    )
    keys = dict(
        (
            await db.execute(
                select(DeliveredKey.order_item_id, func.count())
                .where(DeliveredKey.order_item_id.in_([i.id for i in items]))
                .group_by(DeliveredKey.order_item_id)
            )
        )
        .tuples()
        .all()
    )
    return {
        **_order_out(o),
        "items": [
            {
                "id": str(i.id),
                "external_item_id": i.external_item_id,
                "name": i.name,
                "product_id": str(i.product_id) if i.product_id else None,
                "variant_id": str(i.variant_id) if i.variant_id else None,
                "quantity": i.quantity,
                "unit_price": str(i.unit_price),
                "supplier_cost": str(i.supplier_cost) if i.supplier_cost else None,
                "supplier_currency": i.supplier_currency,
                "status": i.status,
                "keys": int(keys.get(i.id, 0)),
                "last_error": i.last_error,
            }
            for i in items
        ],
        "supplier_orders": [
            {
                "id": str(s.id),
                "reference": s.reference,
                "external_order_id": s.external_order_id,
                "status": s.status,
                "total_price": str(s.total_price) if s.total_price is not None else None,
                "payment_price": str(s.payment_price) if s.payment_price is not None else None,
                "keys_returned_at": s.keys_returned_at.isoformat() if s.keys_returned_at else None,
                "last_error": s.last_error,
                "created_at": s.created_at.isoformat(),
            }
            for s in sos
        ],
        "events": [
            {"event": e.event, "message": e.message, "created_at": e.created_at.isoformat()} for e in events
        ],
    }


@router.post("/orders/{order_id}/retry", response_model=dict[str, Any])
async def retry(
    order_id: uuid.UUID, request: Request, db: DB, actor: User = require("orders:manage")
) -> dict[str, Any]:
    o = await _order(db, order_id)
    if o.status in ("fulfilled", "cancelled", "refunded"):
        raise AppError(409, "invalid_state", f"A {o.status} order cannot be retried.")
    items = list(
        (
            await db.execute(
                select(OrderItem).where(OrderItem.order_id == o.id, OrderItem.status == "failed")
            )
        ).scalars()
    )
    for i in items:
        i.status, i.last_error = "pending", None
    o.status, o.last_error = "processing", None
    event(db, o, "retry_requested", f"Retry requested by {actor.email}.")
    await record(
        db,
        "order.retry",
        actor=actor,
        entity_type="order",
        entity_id=str(o.id),
        summary="Retried order",
        request=request,
    )
    await db.commit()
    enqueue(o.id)
    return {"status": "queued"}


@router.post("/orders/{order_id}/resend-keys", response_model=dict[str, Any])
async def resend(
    order_id: uuid.UUID, request: Request, db: DB, actor: User = require("orders:manage")
) -> dict[str, Any]:
    o = await _order(db, order_id)
    rows = list(
        (
            await db.execute(
                select(DeliveredKey)
                .join(OrderItem, OrderItem.id == DeliveredKey.order_item_id)
                .where(OrderItem.order_id == o.id)
            )
        ).scalars()
    )
    for k in rows:
        k.sent_to_storefront_at = None
    if o.status == "fulfilled":
        o.status = "awaiting_keys"
    event(
        db, o, "resend_requested", f"{actor.email} asked to send the {len(rows)} key(s) to the store again."
    )
    await record(
        db,
        "order.resend_keys",
        actor=actor,
        entity_type="order",
        entity_id=str(o.id),
        summary="Resent keys",
        request=request,
    )
    await db.commit()
    enqueue(o.id)
    return {"keys": len(rows)}


@router.post("/orders/{order_id}/resolve", response_model=dict[str, Any])
async def resolve(
    order_id: uuid.UUID, body: NoteIn, request: Request, db: DB, actor: User = require("orders:manage")
) -> dict[str, Any]:
    o = await _order(db, order_id)
    o.status, o.fulfilled_at = "fulfilled", _now()
    event(db, o, "resolved_manually", f"Marked as handled by {actor.email}. {body.note}".strip())
    await record(
        db,
        "order.resolve",
        actor=actor,
        entity_type="order",
        entity_id=str(o.id),
        summary="Resolved manually",
        data={"note": body.note},
        request=request,
    )
    await db.commit()
    await db.refresh(o)
    return _order_out(o)


@router.post("/orders/{order_id}/cancel", response_model=dict[str, Any])
async def cancel(
    order_id: uuid.UUID, body: NoteIn, request: Request, db: DB, actor: User = require("orders:manage")
) -> dict[str, Any]:
    o = await _order(db, order_id)
    placed = await db.scalar(
        select(func.count())
        .select_from(SupplierOrder)
        .where(
            SupplierOrder.order_id == o.id, SupplierOrder.status.in_(("unknown", "processing", "completed"))
        )
    )
    if placed:
        raise AppError(
            409, "already_purchased", "Items were already bought at the supplier; use Refund instead."
        )
    o.status = "cancelled"
    for i in (await db.execute(select(OrderItem).where(OrderItem.order_id == o.id))).scalars():
        i.status = "cancelled"
    event(db, o, "cancelled", f"Cancelled by {actor.email}. {body.note}".strip())
    await record(
        db,
        "order.cancel",
        actor=actor,
        entity_type="order",
        entity_id=str(o.id),
        summary="Cancelled order",
        request=request,
    )
    await db.commit()
    await db.refresh(o)
    return _order_out(o)


@router.post("/orders/{order_id}/refund", response_model=dict[str, Any])
async def refund(
    order_id: uuid.UUID, body: NoteIn, request: Request, db: DB, actor: User = require("orders:manage")
) -> dict[str, Any]:
    """Return unused keys to the supplier where its rules allow it, then mark the order refunded.

    The customer's payment is refunded in WooCommerce by the payment gateway, not from here."""
    o = await _order(db, order_id)
    if o.status == "refunded":
        raise AppError(409, "invalid_state", "This order is already refunded.")
    results = []
    for so in (await db.execute(select(SupplierOrder).where(SupplierOrder.order_id == o.id))).scalars():
        if so.keys_returned_at or not so.external_order_id or so.status not in ("processing", "completed"):
            continue
        from app.db.models import Supplier

        supplier = await db.get(Supplier, so.supplier_id)
        assert supplier is not None
        adapter = await registry.adapter_for(db, supplier)
        try:
            returned = await adapter.return_keys(so.external_order_id)
            so.keys_returned_at = _now()
            results.append(
                {
                    "reference": so.reference,
                    "keys": [{"id": r.get("id"), "status": r.get("status")} for r in returned],
                }
            )
        except SupplierError as exc:
            results.append({"reference": so.reference, "error": str(exc)[:500]})
        finally:
            await adapter.aclose()
    o.status = "refunded"
    for i in (await db.execute(select(OrderItem).where(OrderItem.order_id == o.id))).scalars():
        i.status = "refunded"
    event(db, o, "refunded", f"Refunded by {actor.email}. {body.note}".strip(), {"supplier_returns": results})
    await record(
        db,
        "order.refund",
        actor=actor,
        entity_type="order",
        entity_id=str(o.id),
        summary="Refunded order",
        data={"note": body.note, "supplier_returns": results},
        request=request,
    )
    await db.commit()
    await db.refresh(o)
    return {**_order_out(o), "supplier_returns": results}
