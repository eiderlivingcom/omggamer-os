"""Fulfil a storefront order: verify offers, buy at the supplier exactly once, collect keys, deliver them.

Duplicate purchases are prevented three ways:
1. One worker per order (a Postgres advisory lock).
2. A supplier_orders row with a unique `reference` is committed *before* the purchase request; the reference
   is sent as Kinguin's orderExternalId.
3. A supplier order whose outcome is unknown (timeout, crash) is resolved by searching the supplier for that
   reference before anything is bought again.

Keys are encrypted at rest and never logged; events record counts only.
"""

import json
import uuid
from collections import defaultdict
from dataclasses import dataclass
from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import select, text, update
from sqlalchemy.ext.asyncio import AsyncEngine, AsyncSession, async_sessionmaker

from app.core.logging import log
from app.core.security import decrypt_secret, encrypt_secret
from app.db.models import (
    AppSetting,
    DeliveredKey,
    Order,
    OrderEvent,
    OrderItem,
    ProductVariant,
    Storefront,
    Supplier,
    SupplierOffer,
    SupplierOrder,
    SupplierProduct,
)
from app.pricing.engine import PricingEngine, PricingError
from app.publishing.connector import ConnectorClient, ConnectorError
from app.suppliers import registry
from app.suppliers.base import SupplierError, SupplierRequestError
from app.sync.service import refresh_product

MAX_QTY_PER_OFFER = 9  # Kinguin: qty limit for one offer
MAX_LINES = 10  # Kinguin: max items in `products`
DEFAULTS = {"auto_fulfil": True, "allow_loss": False}


def now() -> datetime:
    return datetime.now(UTC)


@dataclass
class FulfilOutcome:
    state: str  # done | waiting | retry_later | attention | stopped | locked
    delay: float = 0.0


async def order_settings(db: AsyncSession) -> dict[str, Any]:
    row = await db.get(AppSetting, "orders")
    return {**DEFAULTS, **(row.value if row else {})}


def event(
    db: AsyncSession, order: Order, name: str, message: str, data: dict[str, Any] | None = None
) -> None:
    db.add(OrderEvent(order_id=order.id, event=name, message=message[:2000], data=data or {}))


class Fulfiller:
    def __init__(self, engine: AsyncEngine) -> None:
        self.engine = engine
        self.sm = async_sessionmaker(engine, expire_on_commit=False)

    async def run(self, order_id: uuid.UUID) -> FulfilOutcome:
        key = f"order:{order_id}"
        async with self.engine.connect() as raw:
            lock = await raw.execution_options(isolation_level="AUTOCOMMIT")
            if not await lock.scalar(
                text("SELECT pg_try_advisory_lock(hashtextextended(:k, 0))"), {"k": key}
            ):
                return FulfilOutcome("locked")
            try:
                async with self.sm() as db:
                    return await self._run(db, order_id)
            finally:
                await lock.execute(text("SELECT pg_advisory_unlock(hashtextextended(:k, 0))"), {"k": key})

    async def _run(self, db: AsyncSession, order_id: uuid.UUID) -> FulfilOutcome:
        order = await db.get(Order, order_id)
        if order is None or order.status in ("fulfilled", "cancelled", "refunded"):
            return FulfilOutcome("stopped")
        sf = await db.get(Storefront, order.storefront_id)
        assert sf is not None
        order.attempts += 1
        if order.status == "received":
            order.status = "processing"
        await db.commit()

        waiting = False
        try:
            await self._resolve_unknown(db, order)
            attention = await self._purchase(db, order)
            waiting = await self._collect_keys(db, order)
            await self._deliver(db, order, sf)
        except _Retry as exc:
            await db.rollback()
            order = await db.get(Order, order_id)
            assert order is not None
            order.last_error = str(exc)[:4000]
            event(db, order, "retry", str(exc))
            await db.commit()
            return FulfilOutcome("retry_later", delay=exc.delay)

        items = list((await db.execute(select(OrderItem).where(OrderItem.order_id == order.id))).scalars())
        delivered = [i for i in items if i.status == "delivered"]
        open_items = [i for i in items if i.status in ("pending", "purchasing", "purchased")]
        if items and len(delivered) == len([i for i in items if i.status not in ("cancelled", "refunded")]):
            order.status = "fulfilled"
            order.fulfilled_at = now()
            order.last_error = None
            event(db, order, "fulfilled", f"All {len(delivered)} items delivered.")
            await db.commit()
            await self._note(sf, order, "OMGGamer OS: all keys delivered.", None)
            return FulfilOutcome("done")
        if attention or any(i.status == "failed" for i in items):
            order.status = "partially_fulfilled" if delivered else "needs_attention"
            await db.commit()
            await self._note(
                sf,
                order,
                f"OMGGamer OS needs a person to look at this order: {order.last_error or ''}"[:500],
                "on-hold",
            )
            return FulfilOutcome("attention")
        if waiting or open_items:
            order.status = "awaiting_keys" if any(i.status == "purchased" for i in items) else order.status
            await db.commit()
            return FulfilOutcome("waiting", delay=120)
        await db.commit()
        return FulfilOutcome("done")

    # ------------------------------------------------------------------ steps

    async def _adapter(self, db: AsyncSession, supplier_id: uuid.UUID):  # type: ignore[no-untyped-def]
        supplier = await db.get(Supplier, supplier_id)
        assert supplier is not None
        return supplier, await registry.adapter_for(db, supplier)

    async def _resolve_unknown(self, db: AsyncSession, order: Order) -> None:
        """A purchase whose result we never saw: ask the supplier whether it exists before buying again."""
        rows = list(
            (
                await db.execute(
                    select(SupplierOrder).where(
                        SupplierOrder.order_id == order.id, SupplierOrder.status == "unknown"
                    )
                )
            ).scalars()
        )
        for so in rows:
            _, adapter = await self._adapter(db, so.supplier_id)
            try:
                found = await adapter.find_order(so.reference)
            except SupplierError as exc:
                raise _Retry(f"Could not check purchase {so.reference}: {exc}") from exc
            finally:
                await adapter.aclose()
            if found:
                self._apply_supplier_order(so, found)
                await db.execute(
                    update(OrderItem).where(OrderItem.supplier_order_id == so.id).values(status="purchased")
                )
                event(
                    db,
                    order,
                    "purchase_found",
                    f"Purchase {so.reference} exists at the supplier ({so.external_order_id}).",
                )
            else:
                so.status = "failed"
                so.last_error = "Not placed at the supplier (checked by reference)."
                await db.execute(
                    update(OrderItem)
                    .where(OrderItem.supplier_order_id == so.id)
                    .values(status="pending", supplier_order_id=None)
                )
                event(
                    db,
                    order,
                    "purchase_not_found",
                    f"Purchase {so.reference} was never placed; it will be retried.",
                )
            await db.commit()

    @staticmethod
    def _apply_supplier_order(so: SupplierOrder, data: dict[str, Any]) -> None:
        status = str(data.get("status") or "processing").lower()
        so.status = status if status in ("processing", "completed", "canceled", "refunded") else "processing"
        so.external_order_id = str(data.get("orderId") or so.external_order_id or "")
        so.response = {k: v for k, v in data.items() if k != "products"} | {
            "products": [{k: v for k, v in p.items() if k != "keys"} for p in data.get("products") or []]
        }
        if data.get("totalPrice") is not None:
            so.total_price = Decimal(str(data["totalPrice"]))
        if data.get("paymentPrice") is not None:
            so.payment_price = Decimal(str(data["paymentPrice"]))
        so.last_error = None

    async def _purchase(self, db: AsyncSession, order: Order) -> bool:
        settings = await order_settings(db)
        items = list(
            (
                await db.execute(
                    select(OrderItem).where(OrderItem.order_id == order.id, OrderItem.status == "pending")
                )
            ).scalars()
        )
        if not items:
            return False
        attention = False
        rates = await PricingEngine(db).rates()
        plans: dict[uuid.UUID, list[tuple[OrderItem, SupplierOffer, SupplierProduct]]] = defaultdict(list)
        for item in items:
            if item.variant_id is None:
                item.status, item.last_error = (
                    "failed",
                    "This store item is not linked to an OMGGamer variant.",
                )
                attention = True
                continue
            if item.quantity > MAX_QTY_PER_OFFER:
                item.status, item.last_error = (
                    "failed",
                    f"Quantity {item.quantity} exceeds the supplier limit of 9 per offer.",
                )
                attention = True
                continue
            pick = await self._pick_offer(db, item)
            if pick is None:
                item.status, item.last_error = "failed", "No supplier offer is available any more."
                attention = True
                continue
            offer, sp = pick
            try:
                cost = Decimal(offer.supplier_price) * rates.get(offer.currency, order.currency)
            except PricingError as exc:
                item.status, item.last_error = "failed", str(exc)
                attention = True
                continue
            if cost > item.unit_price and not settings["allow_loss"]:
                item.status = "failed"
                item.last_error = (
                    f"Supplier cost {cost:.2f} {order.currency} is above the sale price {item.unit_price} "
                    f"{order.currency}; not bought automatically."
                )
                attention = True
                continue
            item.offer_id, item.supplier_cost, item.supplier_currency = (
                offer.id,
                offer.supplier_price,
                offer.currency,
            )
            plans[sp.supplier_id].append((item, offer, sp))
        if attention:
            order.last_error = "; ".join(
                sorted({i.last_error for i in items if i.status == "failed" and i.last_error})
            )[:4000]
        await db.commit()

        n = int(
            (
                await db.scalar(
                    select(text("count(*)"))
                    .select_from(SupplierOrder)
                    .where(SupplierOrder.order_id == order.id)
                )
            )
            or 0
        )
        for supplier_id, lines in plans.items():
            # Kinguin: pre-orders one per request and never mixed with regular items; max 10 lines per order.
            pre = [x for x in lines if x[1].availability_status == "preorder"]
            regular = [x for x in lines if x[1].availability_status != "preorder"]
            batches = [[x] for x in pre] + [
                regular[i : i + MAX_LINES] for i in range(0, len(regular), MAX_LINES)
            ]
            for batch in batches:
                if not batch:
                    continue
                n += 1
                so = SupplierOrder(
                    order_id=order.id,
                    supplier_id=supplier_id,
                    reference=f"omg-{order.id.hex[:16]}-{n}",
                    status="unknown",  # stays "unknown" until we see the supplier's answer
                    request={
                        "lines": [
                            {
                                "external_product_id": sp.external_product_id,
                                "qty": item.quantity,
                                "price": str(offer.supplier_price),
                                "offer_id": offer.external_offer_id,
                                "order_item_id": str(item.id),
                            }
                            for item, offer, sp in batch
                        ]
                    },
                )
                db.add(so)
                await db.flush()
                for item, _, _ in batch:
                    item.status, item.supplier_order_id = "purchasing", so.id
                await db.commit()  # the intent is durable before the supplier is called

                _, adapter = await self._adapter(db, supplier_id)
                try:
                    result = await adapter.purchase(so.request["lines"], so.reference)
                except SupplierRequestError as exc:
                    kind = (exc.detail or {}).get("kind") if isinstance(exc.detail, dict) else None
                    so.status, so.last_error = "failed", str(exc)[:4000]
                    for item, offer, _ in batch:
                        item.status, item.supplier_order_id = "pending", None
                        if kind == "ProductUnavailable":
                            offer.available_quantity, offer.availability_status = 0, "unavailable"
                    message = {
                        "InsufficientBalance": "Kinguin balance too low. Top it up, then retry.",
                        "ProductUnavailable": "The offer sold out; another offer will be tried.",
                    }.get(kind or "", str(exc))
                    event(db, order, "purchase_failed", message, {"reference": so.reference, "kind": kind})
                    order.last_error = message
                    await db.commit()
                    if kind == "ProductUnavailable":
                        raise _Retry(message, delay=10) from exc
                    attention = True
                    continue
                except SupplierError as exc:
                    # Timeout or server error: the purchase may or may not exist. Resolved on the next run.
                    event(db, order, "purchase_unknown", f"No clear answer for {so.reference}: {exc}")
                    await db.commit()
                    raise _Retry(
                        f"Purchase {so.reference} outcome unknown; checking again shortly.", delay=60
                    ) from exc
                finally:
                    await adapter.aclose()
                self._apply_supplier_order(so, result)
                for item, _, _ in batch:
                    item.status = "purchased"
                event(
                    db,
                    order,
                    "purchased",
                    f"Bought {len(batch)} item(s) at the supplier ({so.external_order_id}).",
                    {"reference": so.reference, "total": str(so.total_price)},
                )
                order.placed_at = order.placed_at or now()
                await db.commit()
        return attention

    async def _pick_offer(
        self, db: AsyncSession, item: OrderItem
    ) -> tuple[SupplierOffer, SupplierProduct] | None:
        """Re-read the supplier live, then take the cheapest offer with enough stock."""
        sps = (
            (
                await db.execute(
                    select(SupplierProduct, Supplier)
                    .join(Supplier, Supplier.id == SupplierProduct.supplier_id)
                    .where(SupplierProduct.variant_id == item.variant_id, Supplier.status == "active")
                )
            )
            .tuples()
            .all()
        )
        for sp, supplier in sps:
            try:
                await refresh_product(db, supplier, sp.external_product_id)
            except Exception as exc:  # stored offers are used if the supplier cannot be reached
                log.warning("order_offer_refresh_failed", external_id=sp.external_product_id, error=str(exc))
        await db.flush()
        rows = (
            (
                await db.execute(
                    select(SupplierOffer, SupplierProduct)
                    .join(SupplierProduct, SupplierProduct.id == SupplierOffer.supplier_product_id)
                    .where(
                        SupplierOffer.variant_id == item.variant_id,
                        SupplierOffer.availability_status.in_(("in_stock", "preorder")),
                        SupplierOffer.available_quantity >= item.quantity,
                    )
                    .order_by(SupplierOffer.supplier_price)
                    .limit(1)
                )
            )
            .tuples()
            .first()
        )
        return (rows[0], rows[1]) if rows else None

    async def _collect_keys(self, db: AsyncSession, order: Order) -> bool:
        """Download keys for unfinished purchases. Returns True while keys are still missing."""
        sos = list(
            (
                await db.execute(
                    select(SupplierOrder).where(
                        SupplierOrder.order_id == order.id,
                        SupplierOrder.status.in_(("processing", "completed")),
                    )
                )
            ).scalars()
        )
        missing = False
        for so in sos:
            items = list(
                (await db.execute(select(OrderItem).where(OrderItem.supplier_order_id == so.id))).scalars()
            )
            need = sum(i.quantity for i in items)
            have = int(
                (
                    await db.scalar(
                        select(text("count(*)"))
                        .select_from(DeliveredKey)
                        .where(DeliveredKey.supplier_order_id == so.id)
                    )
                )
                or 0
            )
            if have >= need:
                continue
            _, adapter = await self._adapter(db, so.supplier_id)
            try:
                if so.status != "completed":
                    self._apply_supplier_order(so, await adapter.order_status(str(so.external_order_id)))
                keys = await adapter.download_keys(str(so.external_order_id))
            except SupplierError as exc:
                raise _Retry(f"Could not download keys for {so.external_order_id}: {exc}") from exc
            finally:
                await adapter.aclose()
            by_ext: dict[str, list[OrderItem]] = defaultdict(list)
            lines = {line["order_item_id"]: line for line in so.request.get("lines", [])}
            for i in items:
                by_ext[str(lines.get(str(i.id), {}).get("external_product_id"))].append(i)
            known = set(
                (
                    await db.execute(
                        select(DeliveredKey.supplier_key_id).where(DeliveredKey.supplier_order_id == so.id)
                    )
                ).scalars()
            )
            added = 0
            for k in keys:
                kid = str(k.get("id") or "")
                serial = k.get("serial")
                if not kid or kid in known or not serial:
                    continue
                target = next(
                    (i for i in by_ext.get(str(k.get("kinguinId")), []) if i.keys_delivered < i.quantity),
                    None,
                )
                db.add(
                    DeliveredKey(
                        supplier_order_id=so.id,
                        order_item_id=target.id if target else None,
                        supplier_key_id=kid,
                        content_type=str(k.get("type") or "text/plain")[:40],
                        key_enc=encrypt_secret(str(serial)),
                    )
                )
                if target:
                    target.keys_delivered += 1
                known.add(kid)
                added += 1
            if added:
                event(
                    db,
                    order,
                    "keys_received",
                    f"{added} key(s) received from the supplier.",
                    {"supplier_order": so.reference},
                )
            if len(known) < need:
                missing = True
            await db.commit()
        return missing

    async def _deliver(self, db: AsyncSession, order: Order, sf: Storefront) -> None:
        rows = list(
            (
                await db.execute(
                    select(DeliveredKey, OrderItem)
                    .join(OrderItem, OrderItem.id == DeliveredKey.order_item_id)
                    .where(OrderItem.order_id == order.id, DeliveredKey.sent_to_storefront_at.is_(None))
                    .order_by(DeliveredKey.created_at)
                )
            )
            .tuples()
            .all()
        )
        if not rows:
            return
        grouped: dict[str, list[dict[str, str]]] = defaultdict(list)
        for key, item in rows:
            grouped[item.external_item_id].append(
                {"type": key.content_type, "serial": decrypt_secret(key.key_enc)}
            )
        payload = {
            "order_id": order.external_order_id,
            "items": [{"item_id": item_id, "keys": keys} for item_id, keys in grouped.items()],
        }
        idem = "deliver:" + ",".join(sorted(str(k.id) for k, _ in rows))[:100]
        try:
            client = ConnectorClient(sf)
            try:
                await client.request("POST", "/orders/deliver", payload, idem[:120])
            finally:
                await client.aclose()
        except ConnectorError as exc:
            raise _Retry(f"Could not deliver keys to {sf.name}: {exc}", delay=60) from exc
        finally:
            payload.clear()  # drop decrypted keys from memory as soon as possible
        sent = now()
        for key, _ in rows:
            key.sent_to_storefront_at = sent
        items = {item.id: item for _, item in rows}
        for item in items.values():
            if item.keys_delivered >= item.quantity:
                item.status = "delivered"
        event(db, order, "keys_delivered", f"{len(rows)} key(s) delivered to the store order.")
        await db.commit()

    async def _note(self, sf: Storefront, order: Order, note: str, status: str | None) -> None:
        try:
            client = ConnectorClient(sf, max_attempts=2, timeout=20)
            try:
                body: dict[str, Any] = {"order_id": order.external_order_id, "note": note}
                if status:
                    body["status"] = status
                await client.request(
                    "POST", "/orders/note", body, f"note:{order.id}:{order.status}:{order.attempts}"
                )
            finally:
                await client.aclose()
        except ConnectorError as exc:
            log.warning("order_note_failed", order_id=str(order.id), error=str(exc))


class _Retry(Exception):
    def __init__(self, message: str, delay: float = 60.0) -> None:
        super().__init__(message)
        self.delay = delay


def safe_payload(payload: dict[str, Any]) -> str:
    """For logs and events: never include anything that looks like a key."""
    return json.dumps({k: v for k, v in payload.items() if k not in ("keys", "serial")}, default=str)[:1000]


__all__ = ["FulfilOutcome", "Fulfiller", "ProductVariant"]
