"""Kinguin eCommerce API client.

Everything here follows the official documentation (github.com/kinguinltdhk/Kinguin-eCommerce-API):

* Base URLs: production https://gateway.kinguin.net/esa/api, sandbox https://gateway.sandbox.kinguin.net/esa/api.
* Authentication: the `X-Api-Key` header. A bad key answers 401 with {"kind": "Authorization", ...}.
* `GET /v1/products` with `page` (default 1) and `limit` (default 25, maximum 100); the response is
  {"results": [...], "item_count": n}. Also documented: `sortBy` (`updatedAt`), `sortType` (`asc`/`desc`),
  `updatedSince`.
* `GET /v1/products/{kinguinId}` returns one product; 404 when it is unavailable or out of stock.
* `GET /v1/regions` lists regions; used here as a cheap authenticated connection test.
* Errors are JSON objects with `kind`, `status` and `detail`.

The documentation states no rate limit. We therefore space requests out (`min_interval`), honour a
`Retry-After` header when a 429 carries one and otherwise back off exponentially.
"""

import asyncio
import random
import time
from datetime import UTC, datetime
from email.utils import parsedate_to_datetime
from typing import Any

import httpx

from app.core.logging import log
from app.suppliers.base import (
    SupplierAuthError,
    SupplierNotFound,
    SupplierRequestError,
    SupplierTemporaryError,
)

LIVE_BASE = "https://gateway.kinguin.net/esa/api"
SANDBOX_BASE = "https://gateway.sandbox.kinguin.net/esa/api"
MAX_PAGE_SIZE = 100  # documented maximum for `limit`
MAX_RETRY_AFTER = 300.0

# Looked up at call time so tests can replace it and skip real waits.
_sleep = asyncio.sleep


def _detail(r: httpx.Response) -> str:
    try:
        body = r.json()
    except ValueError:
        return r.text[:300]
    if isinstance(body, dict):
        return str(body.get("detail") or body.get("title") or body.get("kind") or body)[:300]
    return str(body)[:300]


def retry_after_seconds(value: str | None) -> float | None:
    """Parse Retry-After: either delta-seconds or an HTTP date."""
    if not value:
        return None
    value = value.strip()
    try:
        return max(0.0, min(float(value), MAX_RETRY_AFTER))
    except ValueError:
        pass
    try:
        when = parsedate_to_datetime(value)
    except (TypeError, ValueError):
        return None
    if when.tzinfo is None:
        when = when.replace(tzinfo=UTC)
    return max(0.0, min((when - datetime.now(UTC)).total_seconds(), MAX_RETRY_AFTER))


class KinguinClient:
    def __init__(
        self,
        api_key: str,
        base_url: str = LIVE_BASE,
        *,
        transport: httpx.AsyncBaseTransport | None = None,
        timeout: float = 30.0,
        max_attempts: int = 6,
        min_interval: float = 0.25,
        backoff_cap: float = 60.0,
    ) -> None:
        if not api_key:
            raise SupplierAuthError(
                "The Kinguin API key is not set. Add it under Settings → API credentials."
            )
        self._http = httpx.AsyncClient(
            base_url=base_url.rstrip("/"),
            headers={"X-Api-Key": api_key, "Accept": "application/json", "User-Agent": "OMGGamer-OS/1.0"},
            timeout=timeout,
            transport=transport,
        )
        self.max_attempts = max_attempts
        self.min_interval = min_interval
        self.backoff_cap = backoff_cap
        self._last_request = 0.0
        self.requests = 0  # every HTTP request sent, including retries
        self.rate_limited = 0

    async def aclose(self) -> None:
        await self._http.aclose()

    def _backoff(self, attempt: int) -> float:
        return min(self.backoff_cap, 2.0**attempt) + random.uniform(0, 1)  # noqa: S311 - jitter, not security

    async def _throttle(self) -> None:
        wait = self._last_request + self.min_interval - time.monotonic()
        if wait > 0:
            await _sleep(wait)
        self._last_request = time.monotonic()

    async def get(self, path: str, params: dict[str, Any] | None = None) -> Any:
        last = "no response"
        for attempt in range(self.max_attempts):
            await self._throttle()
            delay: float
            try:
                r = await self._http.get(path, params=params)
            except (httpx.TimeoutException, httpx.TransportError) as exc:
                self.requests += 1
                last = f"{type(exc).__name__}: {exc}"
                delay = self._backoff(attempt)
            else:
                self.requests += 1
                if r.status_code == 200:
                    try:
                        return r.json()
                    except ValueError:
                        last = "Kinguin returned a response that is not JSON"
                        delay = self._backoff(attempt)
                elif r.status_code in (401, 403):
                    raise SupplierAuthError(
                        f"Kinguin refused the API key ({r.status_code}): {_detail(r)}", status=r.status_code
                    )
                elif r.status_code == 404:
                    raise SupplierNotFound(_detail(r), status=404)
                elif r.status_code == 429:
                    self.rate_limited += 1
                    last = "rate limited (429)"
                    delay = retry_after_seconds(r.headers.get("Retry-After")) or self._backoff(attempt)
                elif r.status_code >= 500:
                    last = f"Kinguin server error {r.status_code}: {_detail(r)}"
                    delay = self._backoff(attempt)
                else:
                    raise SupplierRequestError(
                        f"Kinguin rejected the request ({r.status_code}): {_detail(r)}",
                        status=r.status_code,
                        detail=_detail(r),
                    )
            if attempt + 1 < self.max_attempts:
                log.warning(
                    "kinguin_retry", path=path, attempt=attempt + 1, delay=round(delay, 2), reason=last
                )
                await _sleep(delay)
        raise SupplierTemporaryError(f"Kinguin request failed after {self.max_attempts} attempts: {last}")

    async def list_products(
        self,
        page: int,
        limit: int,
        *,
        updated_since: datetime | None = None,
        sort_by: str | None = "updatedAt",
        sort_type: str = "asc",
    ) -> dict[str, Any]:
        if not 1 <= limit <= MAX_PAGE_SIZE:
            raise ValueError(f"limit must be between 1 and {MAX_PAGE_SIZE}")
        params: dict[str, Any] = {"page": page, "limit": limit}
        if sort_by:
            params["sortBy"] = sort_by
            params["sortType"] = sort_type
        if updated_since:
            params["updatedSince"] = updated_since.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%S+00:00")
        body = await self.get("/v1/products", params)
        if not isinstance(body, dict) or not isinstance(body.get("results"), list):
            raise SupplierTemporaryError(
                "Kinguin product list response has no 'results' list", detail=str(body)[:300]
            )
        return body

    async def get_product(self, kinguin_id: str | int) -> dict[str, Any]:
        body = await self.get(f"/v1/products/{int(kinguin_id)}")
        if not isinstance(body, dict):
            raise SupplierTemporaryError("Kinguin product response is not an object", detail=str(body)[:300])
        return body

    async def regions(self) -> list[Any]:
        body = await self.get("/v1/regions")
        if not isinstance(body, list):
            raise SupplierTemporaryError("Kinguin regions response is not a list", detail=str(body)[:300])
        return body
