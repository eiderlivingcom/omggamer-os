"""Media: image header parsing, image checks, the Media API, and failed / hidden images kept off the store."""

import struct
import uuid

import httpx
from httpx import AsyncClient
from sqlalchemy import select

from app.db.models import ProductMedia
from app.db.session import get_sessionmaker
from app.media.inspect import image_info
from app.media.service import check_batch
from app.publishing.runner import build_payload
from tests.conftest import MakeUser, login
from tests.test_publishing import seed


def png(w: int, h: int) -> bytes:
    return (
        b"\x89PNG\r\n\x1a\n"
        + struct.pack(">I", 13)
        + b"IHDR"
        + struct.pack(">II", w, h)
        + b"\x08\x02\x00\x00\x00"
    )


def jpeg(w: int, h: int) -> bytes:
    app0 = b"\xff\xe0" + struct.pack(">H", 16) + b"JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
    sof = b"\xff\xc0" + struct.pack(">HBHHB", 17, 8, h, w, 3) + b"\x01\x22\x00\x02\x11\x01\x03\x11\x01"
    return b"\xff\xd8" + app0 + sof + b"\xff\xd9"


def test_image_info_reads_sizes() -> None:
    assert image_info(png(800, 600)) == ("image/png", 800, 600)
    assert image_info(jpeg(1440, 1260)) == ("image/jpeg", 1440, 1260)
    assert image_info(b"GIF89a" + struct.pack("<HH", 320, 200) + b"\x00" * 20) == ("image/gif", 320, 200)
    vp8x = b"RIFF\x00\x00\x00\x00WEBPVP8X" + b"\x0a\x00\x00\x00" + b"\x00" * 4 + (1919).to_bytes(3, "little")
    assert image_info(vp8x + (1079).to_bytes(3, "little")) == ("image/webp", 1920, 1080)
    assert image_info(b"<!doctype html><html>Not found</html>") is None


def handler(request: httpx.Request) -> httpx.Response:
    name = request.url.path.rsplit("/", 1)[-1]
    if name.endswith("7.jpg"):  # the seeded Steam cover
        return httpx.Response(206, content=jpeg(1440, 1260), headers={"content-range": "bytes 0-99/250000"})
    if name.endswith("8.jpg"):  # the seeded Switch cover: a broken link
        return httpx.Response(404)
    if name == "tiny.png":
        return httpx.Response(200, content=png(40, 40))
    return httpx.Response(200, content=b"<html>placeholder</html>", headers={"content-type": "text/html"})


async def test_check_batch_records_what_each_image_is() -> None:
    pid = await seed()
    async with get_sessionmaker()() as db:
        db.add(
            ProductMedia(product_id=pid, source_url="https://cdn.example/tiny.png", media_type="screenshot")
        )
        db.add(
            ProductMedia(product_id=pid, source_url="https://cdn.example/page.html", media_type="screenshot")
        )
        db.add(ProductMedia(product_id=pid, source_url="http://127.0.0.1/x.jpg", media_type="screenshot"))
        await db.commit()
        stats = await check_batch(db, transport=httpx.MockTransport(handler))
        await db.commit()
        rows = {
            m.source_url.rsplit("/", 1)[-1]: m for m in (await db.execute(select(ProductMedia))).scalars()
        }
    assert stats == {"checked": 5, "ready": 1, "failed": 4}
    good = rows["7.jpg"]
    assert (good.processing_status, good.content_type, good.width, good.height, good.bytes) == (
        "ready",
        "image/jpeg",
        1440,
        1260,
        250000,
    )
    assert rows["8.jpg"].processing_status == "failed" and rows["8.jpg"].error == "HTTP 404"
    assert rows["tiny.png"].error == "Too small (40x40)."
    assert rows["page.html"].error.startswith("Not an image")
    assert rows["x.jpg"].error == "Not a public http(s) address."
    # Checked images are not checked again until they are 30 days old.
    async with get_sessionmaker()() as db:
        assert (await check_batch(db, transport=httpx.MockTransport(handler)))["checked"] == 0


async def test_media_api_and_store_payload(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    pid = await seed()
    async with get_sessionmaker()() as db:
        await check_batch(db, transport=httpx.MockTransport(handler))
        await db.commit()

    ov = (await client.get("/api/v1/media/overview")).json()
    assert ov["total"] == 2 and ov["by_status"] == {"ready": 1, "failed": 1}

    page = (await client.get("/api/v1/media", params={"status": "failed"})).json()
    assert page["total"] == 1 and page["items"][0]["error"] == "HTTP 404"
    failed_id = page["items"][0]["id"]

    # A failed image never reaches the store.
    from app.db.models import Product, Storefront

    async with get_sessionmaker()() as db:
        p = await db.get(Product, pid)
        sf = (await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))).scalar_one()
        assert p is not None
        payload = await build_payload(db, p, sf, "draft")
    assert [i["url"].rsplit("/", 1)[-1] for i in payload["images"]] == ["7.jpg"]

    # Hide the good one too: the store gets no images from it; show it again and make it the cover.
    ok = (await client.get("/api/v1/media", params={"status": "ready"})).json()["items"][0]
    r = await client.patch(f"/api/v1/media/{ok['id']}", json={"hidden": True})
    assert r.status_code == 200 and r.json()["hidden"] is True
    r = await client.patch(f"/api/v1/media/{ok['id']}", json={"hidden": False, "make_cover": True})
    assert r.json()["media_type"] == "cover" and r.json()["position"] == 0
    assert (await client.patch(f"/api/v1/media/{uuid.uuid4()}", json={"hidden": True})).status_code == 404

    missing = (await client.get("/api/v1/media/missing-covers")).json()
    assert all(row["product_id"] != str(pid) for row in missing)
    assert failed_id
