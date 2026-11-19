"""Storefront home content: only live products can be chosen; sections and banners are sent to the store."""

import uuid
from typing import Any

from httpx import AsyncClient

from tests.conftest import MakeUser, login
from tests.test_publishing import FakeStore, connect, publisher, queued, seed, store  # noqa: F401

BASE = "/api/v1/storefronts/omggamer-staging/home-content"
BANNER: dict[str, Any] = {
    "title": "American Truck Simulator",
    "highlight": "2026",
    "subtitle": "Haul across America.",
    "tags": ["Hot deal"],
    "button": "Shop now",
    "url": "https://omggamer.com/products/american-truck-simulator/",
    "image": "https://omggamer.com/wp-content/uploads/ats-banner.jpg",
}


async def test_only_live_products_and_push_to_store(
    client: AsyncClient,
    make_user: MakeUser,
    store: FakeStore,  # noqa: F811
    queued: list[uuid.UUID],  # noqa: F811
) -> None:
    await make_user()
    await login(client)
    pid = await seed()
    await connect(client, store)

    # Not live yet: nothing to choose, and choosing it is refused.
    assert (await client.get(f"{BASE}/search", params={"q": "hades"})).json() == []
    r = await client.put(BASE, json={"sections": {"flash": [{"product_id": str(pid)}]}})
    assert r.status_code == 422 and r.json()["error"]["code"] == "not_live"

    await client.post(
        "/api/v1/catalogue/products/bulk", json={"action": "publish", "product_ids": [str(pid)]}
    )
    assert (await publisher().run(queued[-1])).state == "done"
    found = (await client.get(f"{BASE}/search", params={"q": "hades"})).json()
    assert [(f["product_id"], f["listing"], f["wc_id"]) for f in found] == [(str(pid), "", "100")]

    r = await client.put(
        BASE,
        json={
            "sections": {"flash": [{"product_id": str(pid)}], "accessories": []},
            "banners": [BANNER, {**BANNER, "title": "Old banner", "active": False}],
        },
    )
    assert r.status_code == 200, r.text
    assert store.home_content["sections"] == {
        "flash": [100],
        "best": [],
        "accounts": [],
        "hardware": [],
        "accessories": [],
    }
    assert [b["title"] for b in store.home_content["banners"]] == ["American Truck Simulator"]  # active only

    got = (await client.get(BASE)).json()
    assert (
        got["sections"]["flash"]["items"][0]["live"] is True
        and got["sections"]["flash"]["name"] == "Flash deals"
    )
    assert [b["title"] for b in got["banners"]] == ["American Truck Simulator", "Old banner"]

    bad = await client.put(BASE, json={"sections": {"homepage-top": []}})
    assert bad.status_code == 422 and bad.json()["error"]["code"] == "invalid_section"
    dates = {**BANNER, "starts_at": "2026-12-01T00:00:00Z", "ends_at": "2026-11-01T00:00:00Z"}
    assert (await client.put(BASE, json={"banners": [dates]})).json()["error"]["code"] == "bad_dates"
