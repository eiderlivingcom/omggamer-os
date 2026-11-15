"""Foxreload Public API client (https://public-api.foxreload.com/docs, OpenAPI at /openapi.json).

* Base URL https://public-api.foxreload.com (mirror https://public-api-mirror.foxreload.com).
* Authentication: the `X-API-Key` header. `X-Currency` (usd | rub) prices the catalogue.
* `GET /api/products/` lists products: `categoryId`, `includeDescendants`, `withStockOnly`, `limit` (max 200),
  cursor paging through `nextCursor` (offsets stop at 10,000, so the import walks the cursor).
* `GET /api/products/{id_or_slug}` returns one product.
* Orders: `POST /api/orders/` (itemId, quantity, optional totalPrice; `idempotencyKey` = our reference),
  `POST /api/orders/{id}/pay` (empty provider = pay from balance), `GET /api/orders/{id}` (codes in
  `items[].externalData`), `GET /api/orders/?idempotencyKey=` to find an order by our reference.
* Rate limit documented for the changes feed: 300 requests per minute per key. Requests are spaced out.
"""

import asyncio
import random
import time
from typing import Any

import httpx

from app.suppliers.base import (
    SupplierAuthError,
    SupplierNotFound,
    SupplierRequestError,
    SupplierTemporaryError,
)
from app.suppliers.kinguin.client import retry_after_seconds

LIVE_BASE = "https://public-api.foxreload.com"
MAX_PAGE_SIZE = 200

_sleep = asyncio.sleep


def _detail(r: httpx.Response) -> str:
    try:
        body = r.json()
    except ValueError:
        return r.text[:300]
    if isinstance(body, dict):
        d = body.get("detail") or body.get("message") or body.get("error") or body
        if isinstance(d, list):  # FastAPI validation errors
            d = "; ".join(str(x.get("msg", x)) if isinstance(x, dict) else str(x) for x in d[:3])
        return str(d)[:300]
    return str(body)[:300]


def error_kind(message: str) -> str | None:
    """Map Foxreload's wording to the kinds the fulfilment code understands."""
    m = message.lower()
    if "balance" in m or "insufficient" in m or "not enough funds" in m:
        return "InsufficientBalance"
    if "stock" in m or "unavailable" in m or "not available" in m or "quantity" in m or "sold out" in m:
        return "ProductUnavailable"
    if "price" in m and ("changed" in m or "mismatch" in m or "exceed" in m or "higher" in m):
        return "PriceChanged"
    return None


class FoxreloadClient:
    def __init__(
        self,
        api_key: str,
        base_url: str = LIVE_BASE,
        *,
        currency: str = "usd",
        transport: httpx.AsyncBaseTransport | None = None,
        timeout: float = 40.0,
        max_attempts: int = 5,
        min_interval: float = 0.25,
        backoff_cap: float = 60.0,
    ) -> None:
        if not api_key:
            raise SupplierAuthError(
                "The Foxreload API key is not set. Add it under Settings → API credentials."
            )
        self._http = httpx.AsyncClient(
            base_url=base_url.rstrip("/"),
            headers={
                "X-API-Key": api_key,
                "X-Currency": currency,
                "X-Language": "en",
                "Accept": "application/json",
                "User-Agent": "OMGGamer-OS/1.0",
            },
            timeout=timeout,
            transport=transport,
        )
        self.currency = currency.upper()
        self.max_attempts = max_attempts
        self.min_interval = min_interval
        self.backoff_cap = backoff_cap
        self._last_request = 0.0
        self.requests = 0

    async def aclose(self) -> None:
        await self._http.aclose()

    def _backoff(self, attempt: int) -> float:
        return min(self.backoff_cap, 2.0**attempt) + random.uniform(0, 1)  # noqa: S311 - jitter

    async def _throttle(self) -> None:
        wait = self._last_request + self.min_interval - time.monotonic()
        if wait > 0:
            await _sleep(wait)
        self._last_request = time.monotonic()

    async def _send(
        self,
        method: str,
        path: str,
        *,
        params: dict[str, Any] | None = None,
        json_body: Any = None,
        attempts: int | None = None,
    ) -> Any:
        last = "no response"
        for attempt in range(attempts or self.max_attempts):
            await self._throttle()
            try:
                r = await self._http.request(method, path, params=params, json=json_body)
            except (httpx.TimeoutException, httpx.TransportError) as exc:
                self.requests += 1
                last = f"{type(exc).__name__}: {exc}"
                delay = self._backoff(attempt)
            else:
                self.requests += 1
                if r.status_code in (200, 201):
                    try:
                        return r.json()
                    except ValueError:
                        last, delay = "Foxreload returned a response that is not JSON", self._backoff(attempt)
                elif r.status_code in (401, 403):
                    raise SupplierAuthError(
                        f"Foxreload refused the API key ({r.status_code}): {_detail(r)}", status=r.status_code
                    )
                elif r.status_code == 404:
                    raise SupplierNotFound(_detail(r), status=404)
                elif r.status_code == 429:
                    last = "rate limited (429)"
                    delay = retry_after_seconds(r.headers.get("Retry-After")) or self._backoff(attempt)
                elif r.status_code >= 500:
                    last, delay = (
                        f"Foxreload server error {r.status_code}: {_detail(r)}",
                        self._backoff(attempt),
                    )
                else:
                    msg = _detail(r)
                    raise SupplierRequestError(
                        f"Foxreload rejected the request ({r.status_code}): {msg}",
                        status=r.status_code,
                        detail={"kind": error_kind(msg), "detail": msg},
                    )
            if attempt + 1 < (attempts or self.max_attempts):
                await _sleep(delay)
        raise SupplierTemporaryError(f"Foxreload did not answer properly: {last}")

    # ------------------------------------------------------------------ catalogue

    async def me(self) -> dict[str, Any]:
        return dict(await self._send("GET", "/api/access/me"))

    async def balances(self) -> list[dict[str, Any]]:
        body = await self._send("GET", "/api/access/me/balances/")
        return list(body) if isinstance(body, list) else list((body or {}).get("items", []))

    async def category(self, id_or_slug: str) -> dict[str, Any]:
        return dict(await self._send("GET", f"/api/categories/{id_or_slug}"))

    async def list_products(self, category_id: str, limit: int, cursor: str | None) -> dict[str, Any]:
        params: dict[str, Any] = {
            "categoryId": category_id,
            "includeDescendants": "true",
            "withStockOnly": "false",  # out-of-stock records still update existing offers
            "limit": min(limit, MAX_PAGE_SIZE),
        }
        if cursor:
            params["cursor"] = cursor
        return dict(await self._send("GET", "/api/products/", params=params))

    async def get_product(self, id_or_slug: str) -> dict[str, Any]:
        return dict(await self._send("GET", f"/api/products/{id_or_slug}"))

    # ------------------------------------------------------------------ orders

    async def create_order(self, items: list[dict[str, Any]], reference: str) -> dict[str, Any]:
        body = {"items": items, "idempotencyKey": reference}
        return dict(await self._send("POST", "/api/orders/", json_body=body, attempts=1))

    async def pay_order(self, order_id: str) -> dict[str, Any]:
        return dict(
            await self._send(
                "POST", f"/api/orders/{order_id}/pay", json_body={"paymentProvider": None}, attempts=1
            )
        )

    async def get_order(self, order_id: str) -> dict[str, Any]:
        return dict(await self._send("GET", f"/api/orders/{order_id}"))

    async def find_order(self, reference: str) -> dict[str, Any] | None:
        body = await self._send("GET", "/api/orders/", params={"idempotencyKey": reference, "limit": 1})
        items = (body or {}).get("items") or []
        if not items:
            return None
        return await self.get_order(str(items[0]["id"]))
