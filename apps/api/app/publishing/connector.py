"""Signed HTTP client for the OMGGamer Catalogue Connector WordPress plugin.

Every request carries X-OMG-Timestamp and X-OMG-Signature = HMAC-SHA256(secret, "<timestamp>.<body>").
Writes also carry X-OMG-Idempotency-Key so a retried request is answered from the plugin's stored response.
"""

import asyncio
import hashlib
import hmac
import json
import random
import time
from typing import Any

import httpx

from app.core.security import decrypt_secret
from app.db.models import Storefront

API_PATH = "/wp-json/omggamer-connector/v1"
transport_override: httpx.AsyncBaseTransport | None = None
_sleep = asyncio.sleep


class ConnectorError(Exception):
    temporary = False

    def __init__(self, message: str, status: int | None = None) -> None:
        super().__init__(message)
        self.status = status


class ConnectorTemporaryError(ConnectorError):
    temporary = True


def connector_url(sf: Storefront) -> str:
    url = str((sf.configuration or {}).get("connector_url") or "").strip()
    if url:
        return url.rstrip("/")
    domain = sf.domain.removeprefix("https://").removeprefix("http://").rstrip("/")
    return f"https://{domain}{API_PATH}"


def secret_for(sf: Storefront) -> str:
    if not sf.credentials_enc:
        raise ConnectorError(
            f"No connector secret is set for {sf.name}. Generate one under Publishing → Storefronts."
        )
    return str(json.loads(decrypt_secret(sf.credentials_enc)).get("secret") or "")


def sign(secret: str, timestamp: int, body: bytes) -> str:
    return hmac.new(secret.encode(), f"{timestamp}.".encode() + body, hashlib.sha256).hexdigest()


class ConnectorClient:
    def __init__(self, sf: Storefront, *, max_attempts: int = 3, timeout: float = 90.0) -> None:
        self.base = connector_url(sf)
        self.secret = secret_for(sf)
        self.max_attempts = max_attempts
        self._http = httpx.AsyncClient(timeout=timeout, transport=transport_override, follow_redirects=False)

    async def aclose(self) -> None:
        await self._http.aclose()

    async def request(
        self,
        method: str,
        path: str,
        payload: dict[str, Any] | None = None,
        idempotency_key: str | None = None,
    ) -> dict[str, Any]:
        body = b"" if payload is None else json.dumps(payload, separators=(",", ":"), default=str).encode()
        last = ""
        for attempt in range(self.max_attempts):
            ts = int(time.time())
            headers = {
                "X-OMG-Timestamp": str(ts),
                "X-OMG-Signature": sign(self.secret, ts, body),
                "Content-Type": "application/json",
                "User-Agent": "OMGGamer-OS/1.0",
            }
            if idempotency_key:
                headers["X-OMG-Idempotency-Key"] = idempotency_key
            try:
                r = await self._http.request(method, self.base + path, content=body or None, headers=headers)
            except (httpx.TimeoutException, httpx.TransportError) as exc:
                last = f"{type(exc).__name__}: {exc}"
            else:
                try:
                    data = r.json()
                except ValueError:
                    data = {"message": r.text[:300]}
                message = str(data.get("message") or data.get("code") or "") if isinstance(data, dict) else ""
                if r.status_code < 300 and isinstance(data, dict):
                    return data
                if r.status_code in (401, 403):
                    raise ConnectorError(
                        f"The store refused the signature ({r.status_code}): {message}", r.status_code
                    )
                if r.status_code == 404 and "rest_no_route" in json.dumps(data):
                    raise ConnectorError(
                        "The OMGGamer Catalogue Connector plugin is not active on the store.", 404
                    )
                if r.status_code in (400, 404, 422):
                    raise ConnectorError(
                        f"The store rejected the request ({r.status_code}): {message}", r.status_code
                    )
                last = f"store answered {r.status_code}: {message}"
            if attempt + 1 < self.max_attempts:
                await _sleep(min(20.0, 2.0**attempt) + random.uniform(0, 1))  # noqa: S311 - jitter
        raise ConnectorTemporaryError(f"Store request failed after {self.max_attempts} attempts: {last}")

    async def status(self) -> dict[str, Any]:
        return await self.request("GET", "/status")

    async def upsert(self, payload: dict[str, Any], key: str) -> dict[str, Any]:
        return await self.request("POST", "/products/upsert", payload, key)

    async def set_status(self, external_id: str, status: str, key: str) -> dict[str, Any]:
        return await self.request(
            "POST", "/products/status", {"external_id": external_id, "status": status}, key
        )

    async def prices(self, items: list[dict[str, Any]], key: str) -> dict[str, Any]:
        return await self.request("POST", "/products/prices", {"items": items}, key)
