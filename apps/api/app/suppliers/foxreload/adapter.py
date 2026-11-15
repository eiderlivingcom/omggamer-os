"""Foxreload implementation of the SupplierAdapter contract.

Orders are translated into the shape the fulfilment code already uses for Kinguin:
{orderId, status: processing|completed|canceled|refunded, totalPrice, products} and keys
[{id, serial, type, external_product_id}].
"""

from datetime import datetime
from typing import Any

import httpx

from app.catalogue.schemas import NormalizedProduct
from app.suppliers.base import NormalizationError, Page, SupplierAdapterBase, SupplierRequestError
from app.suppliers.foxreload import normalizer
from app.suppliers.foxreload.client import LIVE_BASE, MAX_PAGE_SIZE, FoxreloadClient, error_kind

_STATUS = {
    "active": "processing",  # created, not paid yet
    "paid": "processing",
    "processing": "processing",
    "action_required": "processing",
    "completed": "completed",
    "cancelled": "canceled",
    "failed": "canceled",
    "refunded": "refunded",
}


class FoxreloadAdapter(SupplierAdapterBase):
    code = "foxreload"
    max_page_size = MAX_PAGE_SIZE

    def __init__(self, client: FoxreloadClient, category: str = "games") -> None:
        self.client = client
        self.category = category
        self._category_id: str | None = None
        self._cursor: str | None = None

    @classmethod
    def build(
        cls,
        configuration: dict[str, Any],
        environment: str,
        api_key: str,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> "FoxreloadAdapter":
        base = configuration.get("api_base") or LIVE_BASE
        interval = float(configuration.get("min_request_interval_ms", 250)) / 1000
        currency = str(configuration.get("currency") or "usd").lower()
        client = FoxreloadClient(api_key, base, currency=currency, transport=transport, min_interval=interval)
        return cls(client, str(configuration.get("import_category") or "games"))

    @property
    def requests_made(self) -> int:
        return self.client.requests

    async def test_connection(self) -> dict[str, Any]:
        me = await self.client.me()
        bal = await self.client.balances()
        return {"account": me.get("email"), "balances": bal}

    # ------------------------------------------------------------------ catalogue

    def set_cursor(self, cursor: str | None) -> None:
        """The import runner hands back the cursor saved with the last checkpoint."""
        self._cursor = cursor or None

    async def _category(self) -> str:
        if self._category_id is None:
            self._category_id = str((await self.client.category(self.category))["id"])
        return self._category_id

    async def fetch_page(self, page: int, page_size: int, updated_since: datetime | None = None) -> Page:
        body = await self.client.list_products(
            await self._category(), page_size, self._cursor if page > 1 else None
        )
        raw = [r for r in body.get("items") or [] if isinstance(r, dict)]
        records = [r for r in raw if r.get("deliveryType") in (None, "code")]
        nxt = body.get("nextCursor")
        self._cursor = nxt
        total = body.get("total")
        return Page(
            records=records,
            total=total if isinstance(total, int) else None,
            raw_meta={
                "next_cursor": nxt,
                # Items skipped here (eSIM, top-ups...) still count towards the page size.
                "non_object_results": len(body.get("items") or []) - len(records),
            },
        )

    async def fetch_one(self, external_id: str) -> dict[str, Any]:
        return await self.client.get_product(external_id)

    def external_id(self, record: dict[str, Any]) -> str | None:
        rid = record.get("id") if isinstance(record, dict) else None
        return str(rid) if rid else None

    def normalize(self, record: dict[str, Any]) -> NormalizedProduct:
        return normalizer.normalize(record)

    # ------------------------------------------------------------------ purchasing

    @staticmethod
    def _shape(order: dict[str, Any]) -> dict[str, Any]:
        status = _STATUS.get(str(order.get("status") or "").lower(), "processing")
        return {
            "orderId": order.get("id"),
            "status": status,
            "totalPrice": order.get("price"),
            "paymentPrice": order.get("price"),
            "currency": str(order.get("currency") or "").upper(),
            "foxreloadStatus": order.get("status"),
            "products": [
                {
                    "external_product_id": (it.get("product") or {}).get("id"),
                    "name": (it.get("product") or {}).get("name"),
                    "qty": it.get("quantity"),
                    "fulfillmentStatus": it.get("fulfillmentStatus"),
                    "error": it.get("error"),
                }
                for it in order.get("items") or []
                if isinstance(it, dict)
            ],
        }

    async def purchase(self, lines: list[dict[str, Any]], reference: str) -> dict[str, Any]:
        """lines: [{external_product_id, qty, price}]. `price` is the unit cost OS priced the sale on.

        Foxreload refuses the item if its price is now higher (totalPrice): no loss on a price jump.
        """
        order = await self.client.find_order(reference)
        if order is None:
            items = []
            for line in lines:
                item: dict[str, Any] = {
                    "itemId": str(line["external_product_id"]),
                    "quantity": int(line["qty"]),
                }
                if line.get("price") not in (None, ""):
                    item["totalPrice"] = str(round(float(line["price"]) * int(line["qty"]), 4))
                items.append(item)
            order = await self.client.create_order(items, reference)
        if str(order.get("status")) == "active":
            try:
                await self.client.pay_order(str(order["id"]))
            except SupplierRequestError as exc:
                msg = str((exc.detail or {}).get("detail") or exc)
                raise SupplierRequestError(
                    f"Foxreload could not take payment for {reference}: {msg}",
                    status=exc.status,
                    detail={"kind": error_kind(msg) or "PaymentFailed", "detail": msg},
                ) from exc
            order = await self.client.get_order(str(order["id"]))
        return self._shape(order)

    async def find_order(self, reference: str) -> dict[str, Any] | None:
        order = await self.client.find_order(reference)
        return self._shape(order) if order else None

    async def order_status(self, order_id: str) -> dict[str, Any]:
        return self._shape(await self.client.get_order(order_id))

    async def download_keys(self, order_id: str) -> list[dict[str, Any]]:
        order = await self.client.get_order(order_id)
        out: list[dict[str, Any]] = []
        for it in order.get("items") or []:
            if not isinstance(it, dict):
                continue
            pid = (it.get("product") or {}).get("id")
            for n, code in enumerate(it.get("externalData") or []):
                if code:
                    out.append(
                        {
                            "id": f"{it.get('id')}:{n}",
                            "serial": str(code),
                            "type": "text/plain",
                            "external_product_id": pid,
                        }
                    )
        return out

    async def return_keys(self, order_id: str) -> list[dict[str, Any]]:
        raise SupplierRequestError("Foxreload has no key return API; cancel or refund in the Foxreload app.")

    async def aclose(self) -> None:
        await self.client.aclose()


__all__ = ["FoxreloadAdapter", "NormalizationError"]
