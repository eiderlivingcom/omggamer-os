"""Stage one image under an SEO name, print its public URL, wait, then delete it (manual check of the flow).

    docker compose exec -T api python - <image url> <seo-name.png> [seconds] < apps/api/scripts/stage_test.py
"""

import asyncio
import sys
import uuid

from app.media import staging


async def main(url: str, name: str, wait: int) -> None:
    pid = uuid.uuid4()
    out = await staging.stage_images(pid, [{"url": url, "filename": name}])
    print("staged:", out[0]["url"])
    await asyncio.sleep(wait)
    staging.clear(pid)
    print("deleted:", not staging.product_dir(pid).exists())


ATS = (
    "https://static.kinguin.net/media/catalog/category/cache/1/thumbnail/9df78eab33525d08d6e5fb8d27136e95/"
    "ats_logo_2_1_1.png"
)
args = sys.argv[1:] or [ATS, "american-truck-simulator-2026-pc-steam-key-cover.png", "90"]
asyncio.run(main(args[0], args[1], int(args[2]) if len(args) > 2 else 60))
