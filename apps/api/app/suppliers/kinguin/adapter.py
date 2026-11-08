"""Kinguin implementation of the SupplierAdapter contract."""

from datetime import datetime
from typing import Any

import httpx

from app.catalogue.schemas import NormalizedProduct
from app.suppliers.base import Page, SupplierAdapterBase
from app.suppliers.kinguin import normalizer
from app.suppliers.kinguin.client import LIVE_BASE, MAX_PAGE_SIZE, SANDBOX_BASE, KinguinClient


class KinguinAdapter(SupplierAdapterBase):
    code = "kinguin"
    max_page_size = MAX_PAGE_SIZE

    def __init__(self, client: KinguinClient) -> None:
        self.client = client

    @classmethod
    def build(
        cls,
        configuration: dict[str, Any],
        environment: str,
        api_key: str,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> "KinguinAdapter":
        if environment == "sandbox":
            base = configuration.get("sandbox_api_base") or SANDBOX_BASE
        else:
            base = configuration.get("api_base") or LIVE_BASE
        interval = float(configuration.get("min_request_interval_ms", 250)) / 1000
        return cls(KinguinClient(api_key, base, transport=transport, min_interval=interval))

    @property
    def requests_made(self) -> int:
        return self.client.requests

    async def test_connection(self) -> dict[str, Any]:
        regions = await self.client.regions()
        return {"regions": len(regions)}

    async def fetch_page(self, page: int, page_size: int, updated_since: datetime | None = None) -> Page:
        body = await self.client.list_products(page, page_size, updated_since=updated_since)
        records = [r for r in body["results"] if isinstance(r, dict)]
        total = body.get("item_count")
        meta = {k: v for k, v in body.items() if k != "results"}
        meta["non_object_results"] = len(body["results"]) - len(records)
        return Page(records=records, total=total if isinstance(total, int) else None, raw_meta=meta)

    async def fetch_one(self, external_id: str) -> dict[str, Any]:
        return await self.client.get_product(external_id)

    def external_id(self, record: dict[str, Any]) -> str | None:
        kid = record.get("kinguinId") if isinstance(record, dict) else None
        return str(kid) if kid is not None else None

    def normalize(self, record: dict[str, Any]) -> NormalizedProduct:
        return normalizer.normalize(record)

    # ------------------------------------------------------------------ purchasing

    async def purchase(self, lines: list[dict[str, Any]], reference: str) -> dict[str, Any]:
        """lines: [{external_product_id, qty, price, offer_id}] → Kinguin order object."""
        products = []
        for line in lines:
            item: dict[str, Any] = {
                "kinguinId": int(line["external_product_id"]),
                "qty": int(line["qty"]),
                "price": float(line["price"]),
            }
            if line.get("offer_id"):
                item["offerId"] = line["offer_id"]
            products.append(item)
        return await self.client.place_order(products, reference)

    async def find_order(self, reference: str) -> dict[str, Any] | None:
        return await self.client.find_order(reference)

    async def order_status(self, order_id: str) -> dict[str, Any]:
        return await self.client.get_order(order_id)

    async def download_keys(self, order_id: str) -> list[dict[str, Any]]:
        return await self.client.order_keys(order_id)

    async def return_keys(self, order_id: str) -> list[dict[str, Any]]:
        return await self.client.return_keys(order_id)

    async def aclose(self) -> None:
        await self.client.aclose()
