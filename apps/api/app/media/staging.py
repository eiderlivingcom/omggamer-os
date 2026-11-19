"""Product images renamed for SEO, held on OMGGamer OS only while a game is being published.

Suppliers name their files anything ("ats_logo_2_1_1.png"). When a game is published, its images are
downloaded here under their SEO file name, which is the image's AI alt text written as a file name
("american-truck-simulator-2026-pc-steam-key-cover.png"). The store fetches them from
os.omggamer.com/api/v1/files/media/<game>/<file>, and once the game is published the copies are deleted.
If an image cannot be fetched, the store gets the supplier's URL (the connector still saves it under the
SEO name).
"""

import os
import re
import shutil
import uuid
from pathlib import Path
from typing import Any

import httpx

from app.core.config import get_settings
from app.core.logging import log
from app.media.inspect import image_info
from app.media.service import USER_AGENT, _public_http

MAX_BYTES = 15 * 1024 * 1024
FILE_RE = re.compile(r"^[a-z0-9][a-z0-9-]{0,140}\.(jpg|jpeg|png|webp|gif)$")


def stage_dir() -> Path:
    return Path(os.environ.get("OMG_MEDIA_STAGE_DIR", "/data/media-stage"))


def product_dir(product_id: uuid.UUID) -> Path:
    return stage_dir() / str(product_id)


def public_url(product_id: uuid.UUID, filename: str) -> str:
    return f"{get_settings().app_origin.rstrip('/')}/api/v1/files/media/{product_id}/{filename}"


async def _download(client: httpx.AsyncClient, url: str, dest: Path) -> bool:
    if not _public_http(url):
        return False
    try:
        async with client.stream("GET", url) as r:
            if r.status_code >= 400:
                return False
            buf = bytearray()
            async for chunk in r.aiter_bytes():
                buf.extend(chunk)
                if len(buf) > MAX_BYTES:
                    return False
    except httpx.HTTPError:
        return False
    if image_info(bytes(buf[:262144])) is None:
        return False  # not an image: let the store skip it rather than save a broken file
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(dest.suffix + ".part")
    tmp.write_bytes(bytes(buf))
    tmp.replace(dest)
    return True


async def stage_images(
    product_id: uuid.UUID,
    images: list[dict[str, Any]],
    *,
    transport: httpx.AsyncBaseTransport | None = None,
) -> list[dict[str, Any]]:
    """Copies of the images under their SEO names; returns the image list pointing at the copies."""
    out: list[dict[str, Any]] = []
    async with httpx.AsyncClient(
        timeout=httpx.Timeout(20.0, connect=5.0),
        follow_redirects=True,
        headers={"User-Agent": USER_AGENT},
        transport=transport,
    ) as client:
        for img in images:
            name = str(img.get("filename") or "")
            src = str(img.get("source") or img.get("url") or "")
            item = {**img, "source": src}
            if FILE_RE.match(name) and src:
                dest = product_dir(product_id) / name
                if dest.exists() or await _download(client, src, dest):
                    item["url"] = public_url(product_id, name)
                else:
                    log.info("media_stage_skipped", product_id=str(product_id), source=src)
            out.append(item)
    return out


def clear(product_id: uuid.UUID) -> None:
    """Delete the game's staged copies (after the store has them)."""
    shutil.rmtree(product_dir(product_id), ignore_errors=True)


def staged_file(product_id: str, filename: str) -> Path | None:
    try:
        pid = uuid.UUID(product_id)
    except ValueError:
        return None
    if not FILE_RE.match(filename):
        return None
    path = product_dir(pid) / filename
    return path if path.is_file() else None
