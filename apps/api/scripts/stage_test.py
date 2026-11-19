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


asyncio.run(main(sys.argv[1], sys.argv[2], int(sys.argv[3]) if len(sys.argv) > 3 else 60))
