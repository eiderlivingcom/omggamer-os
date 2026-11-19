"""Image checks: fetch the start of each product image and record what it really is.

ready   the URL answers with a real image (JPEG/PNG/WebP/GIF); type, width, height and size are stored
failed  the URL is broken, answers with something that is not an image, or the image is tiny (under 100 px)

Only the first 256 KB are read. Live and approved games are checked first, then everything never checked, then
images not checked for 30 days. Failed and hidden images are not sent to storefronts.
"""

import asyncio
import ipaddress
import uuid
from datetime import UTC, datetime, timedelta
from urllib.parse import urlparse

import httpx
from sqlalchemy import case, or_, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.logging import log
from app.db.models import Product, ProductMedia
from app.media.inspect import image_info

READ_LIMIT = 256 * 1024
MIN_SIDE = 100
RECHECK_AFTER = timedelta(days=30)
USER_AGENT = "OMGGamer-OS image check"


def _public_http(url: str) -> bool:
    """http(s) to a public host only (never internal addresses)."""
    try:
        u = urlparse(url)
    except ValueError:
        return False
    if u.scheme not in ("http", "https") or not u.hostname:
        return False
    host = u.hostname.lower()
    if host in ("localhost",) or host.endswith((".local", ".internal")):
        return False
    try:
        ip = ipaddress.ip_address(host)
    except ValueError:
        return True
    return ip.is_global


async def check_url(client: httpx.AsyncClient, url: str) -> dict[str, object]:
    out: dict[str, object] = {
        "content_type": None,
        "width": None,
        "height": None,
        "bytes": None,
        "error": None,
    }
    if not _public_http(url):
        out["error"] = "Not a public http(s) address."
        return out
    try:
        async with client.stream("GET", url, headers={"Range": f"bytes=0-{READ_LIMIT - 1}"}) as r:
            if r.status_code >= 400:
                out["error"] = f"HTTP {r.status_code}"
                return out
            total = r.headers.get("content-range", "").rpartition("/")[2]
            size = int(total) if total.isdigit() else int(r.headers.get("content-length") or 0) or None
            buf = bytearray()
            async for chunk in r.aiter_bytes():
                buf.extend(chunk)
                if len(buf) >= READ_LIMIT:
                    break
    except (httpx.HTTPError, ValueError) as exc:
        out["error"] = f"Could not fetch: {type(exc).__name__}"
        return out
    info = image_info(bytes(buf))
    out["bytes"] = size or len(buf)
    if info is None:
        out["content_type"] = (r.headers.get("content-type") or "")[:60] or None
        out["error"] = "Not an image (or an unsupported format)."
        return out
    out["content_type"], out["width"], out["height"] = info
    if min(info[1], info[2]) < MIN_SIDE:
        out["error"] = f"Too small ({info[1]}x{info[2]})."
    return out


def apply(m: ProductMedia, result: dict[str, object]) -> None:
    m.content_type = result["content_type"]  # type: ignore[assignment]
    m.width = result["width"]  # type: ignore[assignment]
    m.height = result["height"]  # type: ignore[assignment]
    m.bytes = result["bytes"]  # type: ignore[assignment]
    m.error = (str(result["error"])[:300]) if result["error"] else None
    m.processing_status = "failed" if result["error"] else "ready"
    m.checked_at = datetime.now(UTC)


async def check_batch(
    db: AsyncSession,
    limit: int = 200,
    *,
    ids: list[uuid.UUID] | None = None,
    transport: httpx.AsyncBaseTransport | None = None,
) -> dict[str, int]:
    """Check up to `limit` images (or exactly `ids`). Caller commits."""
    q = select(ProductMedia).join(Product, Product.id == ProductMedia.product_id)
    if ids:
        q = q.where(ProductMedia.id.in_(ids))
    else:
        stale = datetime.now(UTC) - RECHECK_AFTER
        q = q.where(
            ProductMedia.media_type != "video",
            or_(ProductMedia.checked_at.is_(None), ProductMedia.checked_at < stale),
        ).order_by(
            case((Product.product_status.in_(("published", "approved")), 0), else_=1),
            ProductMedia.checked_at.is_not(None),
            ProductMedia.checked_at,
            ProductMedia.created_at,
        )
    rows = list((await db.execute(q.limit(limit))).scalars())
    stats = {"checked": 0, "ready": 0, "failed": 0}
    if not rows:
        return stats
    sem = asyncio.Semaphore(8)
    async with httpx.AsyncClient(
        timeout=httpx.Timeout(10.0, connect=5.0),
        follow_redirects=True,
        headers={"User-Agent": USER_AGENT},
        transport=transport,
    ) as client:

        async def one(m: ProductMedia) -> tuple[ProductMedia, dict[str, object]]:
            async with sem:
                return m, await check_url(client, m.storage_url or m.source_url)

        for m, result in await asyncio.gather(*(one(m) for m in rows)):
            apply(m, result)
            stats["checked"] += 1
            stats["failed" if result["error"] else "ready"] += 1
    await db.flush()
    log.info("media_checked", **stats)
    return stats
