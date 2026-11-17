"""Check both directions of the OMGGamer OS <-> store link (read-only).

    docker compose exec -T api python - omggamer-staging < apps/api/scripts/check_store_link.py

OS -> store: signed status call to the connector. Store -> OS: the store's own signed calls are tested from
WordPress; here we only confirm the webhook route answers (an unsigned call must be refused with 401).
"""

import asyncio
import sys

import httpx
from sqlalchemy import select

from app.db.models import Storefront
from app.db.session import get_sessionmaker
from app.publishing.connector import ConnectorClient, connector_url


async def main(code: str) -> None:
    async with get_sessionmaker()() as db:
        sf = (await db.execute(select(Storefront).where(Storefront.code == code))).scalar_one()
        print("store:", sf.name, "|", connector_url(sf), "| status", sf.status)
        client = ConnectorClient(sf, max_attempts=1, timeout=30)
        try:
            st = await client.status()
            keys = ("plugin_version", "woocommerce", "site_url", "storefront")
            print("OS -> store: OK", {k: st.get(k) for k in keys})
        except Exception as exc:
            print("OS -> store: FAILED", type(exc).__name__, str(exc)[:300])
        finally:
            await client.aclose()
    async with httpx.AsyncClient(timeout=20) as h:
        r = await h.post(f"https://os.omggamer.com/api/v1/webhooks/storefronts/{code}/product-facts", json={})
        print("store -> OS route:", r.status_code, "(401 expected for an unsigned call)")


asyncio.run(main(sys.argv[1] if len(sys.argv) > 1 else "omggamer-staging"))
