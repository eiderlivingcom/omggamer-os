from decimal import Decimal

from httpx import AsyncClient
from sqlalchemy import select

from app.catalogue.schemas import NormalizedMedia, NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import Supplier
from app.db.session import get_sessionmaker
from tests.conftest import MakeUser, login


async def seed() -> dict[str, str]:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        svc = CatalogueService(db)
        ids = {}
        for ext, title, platform, base, price, qty, dev in [
            ("230507", "Star Wars Outlaws PS5 Account", "PS5", "Star Wars Outlaws", "15.01", 1, "Massive"),
            ("231907", "Star Wars Outlaws PC Steam Key", "Steam", "Star Wars Outlaws", "29.85", 0, "Massive"),
            (
                "321361",
                "Grand Theft Auto V Enhanced PC Epic Games Account",
                "Epic Games",
                "Grand Theft Auto V Enhanced",
                "26.16",
                25,
                "Rockstar North",
            ),
        ]:
            r = await svc.ingest(
                k,
                NormalizedProduct(
                    external_product_id=ext,
                    original_title=title,
                    base_title=base,
                    platform=platform,
                    region="Global",
                    developer=dev,
                    media=[NormalizedMedia(url=f"https://cdn.example/{ext}.jpg", media_type="cover")],
                    offers=[
                        NormalizedOffer(
                            external_offer_id=f"o{ext}",
                            currency="EUR",
                            price=Decimal(price),
                            quantity=qty,
                            availability="in_stock" if qty else "out_of_stock",
                        )
                    ],
                    raw_payload={"kinguinId": int(ext), "name": title},
                ),
            )
            ids[ext] = str(r.product_id)
            ids[f"sp{ext}"] = str(r.supplier_product_id)
        await db.commit()
        return ids


async def test_product_list_filters_search_and_aggregates(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    ids = await seed()
    page = (await client.get("/api/v1/catalogue/products")).json()
    assert page["total"] == 2
    swo = next(i for i in page["items"] if i["canonical_title"] == "Star Wars Outlaws")
    assert swo["platforms"] == ["PS5", "Steam"] and swo["variant_count"] == 2 and swo["offer_count"] == 2
    assert swo["lowest_price"] == "15.0100" and swo["suppliers"] == ["kinguin"]  # out-of-stock offer ignored
    assert swo["cover_url"].startswith("https://cdn.example/")

    def titles(r):  # type: ignore[no-untyped-def]
        return [i["canonical_title"] for i in r.json()["items"]]

    assert titles(await client.get("/api/v1/catalogue/products", params={"q": "321361"})) == [
        "Grand Theft Auto V Enhanced"
    ]
    assert titles(await client.get("/api/v1/catalogue/products", params={"q": "rockstar"})) == [
        "Grand Theft Auto V Enhanced"
    ]
    assert titles(await client.get("/api/v1/catalogue/products", params={"q": "outlaws"})) == [
        "Star Wars Outlaws"
    ]
    assert titles(await client.get("/api/v1/catalogue/products", params={"platform": "epic games"})) == [
        "Grand Theft Auto V Enhanced"
    ]
    assert (
        titles(await client.get("/api/v1/catalogue/products", params={"supplier": "kinguin", "page_size": 1}))
        and (await client.get("/api/v1/catalogue/products", params={"page_size": 1, "page": 2})).json()[
            "total"
        ]
        == 2
    )
    assert (await client.get("/api/v1/catalogue/products", params={"supplier": "g2a"})).json()["total"] == 0
    assert ids["230507"] == ids["231907"]


async def test_product_detail_update_merge_and_categories(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    ids = await seed()
    d = (await client.get(f"/api/v1/catalogue/products/{ids['230507']}")).json()
    assert len(d["variants"]) == 2 and len(d["offers"]) == 2 and len(d["supplier_products"]) == 2
    ps5 = next(v for v in d["variants"] if v["platform"] == "PS5")
    assert ps5["offer_count"] == 1 and ps5["lowest_price"] == "15.0100"

    raw = (await client.get(f"/api/v1/catalogue/supplier-products/{ids['sp230507']}")).json()
    assert raw["raw_payload"] == {"kinguinId": 230507, "name": "Star Wars Outlaws PS5 Account"}

    upd = await client.patch(f"/api/v1/catalogue/products/{ids['230507']}", json={"publisher": "Ubisoft"})
    assert upd.status_code == 200 and upd.json()["publisher"] == "Ubisoft"
    bad = await client.patch(
        f"/api/v1/catalogue/products/{ids['230507']}", json={"product_status": "published"}
    )
    assert bad.status_code == 422  # publishing goes through the publishing queue, not a field edit

    games = (await client.post("/api/v1/catalogue/categories", json={"name": "Games"})).json()
    action = (
        await client.post("/api/v1/catalogue/categories", json={"name": "Action", "parent_id": games["id"]})
    ).json()
    tree = (await client.get("/api/v1/catalogue/categories")).json()
    assert tree[0]["name"] == "Games" and tree[0]["children"][0]["name"] == "Action"
    r = await client.put(
        f"/api/v1/catalogue/products/{ids['230507']}/categories", json={"category_ids": [action["id"]]}
    )
    assert [c["name"] for c in r.json()["categories"]] == ["Action"]
    cyc = await client.patch(f"/api/v1/catalogue/categories/{games['id']}", json={"parent_id": action["id"]})
    assert cyc.status_code == 422
    assert (await client.delete(f"/api/v1/catalogue/categories/{games['id']}")).status_code == 409

    m = await client.post(
        f"/api/v1/catalogue/products/{ids['321361']}/merge", json={"target_product_id": ids["230507"]}
    )
    assert m.status_code == 200 and len(m.json()["merged_from"]) == 1
    assert (await client.get("/api/v1/catalogue/products")).json()["total"] == 1
    assert (await client.get("/api/v1/catalogue/products", params={"include_merged": "true"})).json()[
        "total"
    ] == 2


async def test_viewer_can_read_but_not_write(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user("viewer@omggamer.com", role="viewer")
    await login(client, "viewer@omggamer.com")
    ids = await seed()
    assert (await client.get("/api/v1/catalogue/products")).status_code == 200
    assert (await client.get("/api/v1/suppliers")).json()[0]["supplier_products"] == 3
    assert {s["code"] for s in (await client.get("/api/v1/storefronts")).json()} == {
        "omggamer-live",
        "omggamer-staging",
    }
    r = await client.patch(f"/api/v1/catalogue/products/{ids['230507']}", json={"publisher": "x"})
    assert r.status_code == 403
    assert (await client.post("/api/v1/catalogue/categories", json={"name": "x"})).status_code == 403
