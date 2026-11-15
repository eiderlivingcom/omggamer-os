"""Cross-supplier matching: scoring, suggestions, accept (merge + alias), reject, manual aliases, scan."""

from decimal import Decimal

from httpx import AsyncClient
from sqlalchemy import func, select

from app.catalogue import matching
from app.catalogue.schemas import NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import MatchSuggestion, Product, ProductAlias, Supplier, SupplierProduct
from app.db.session import get_sessionmaker
from tests.conftest import MakeUser, login


def item(ext: str, title: str, platform: str = "Steam") -> NormalizedProduct:
    return NormalizedProduct(
        external_product_id=ext,
        original_title=title,
        platform=platform,
        region="Global",
        offers=[
            NormalizedOffer(external_offer_id=f"o{ext}", currency="USD", price=Decimal("10"), quantity=3)
        ],
    )


async def ingest(code: str, *items: NormalizedProduct) -> None:
    async with get_sessionmaker()() as db:
        sup = (await db.execute(select(Supplier).where(Supplier.code == code))).scalar_one()
        for it in items:
            await CatalogueService(db).ingest(sup, it)
        await db.commit()


def test_scoring_rules() -> None:
    same = matching.score_pair("gta 6 new", "gta 6 2026", 0.4)
    assert same is not None and same[0] >= Decimal("0.95")
    assert matching.score_pair("fifa 23", "fifa 24", 0.8) is None  # sequels are different games
    assert matching.score_pair("diablo iii", "diablo 4", 0.6) is None  # roman numerals count as numbers
    words = matching.score_pair("star wars outlaws", "star wars outlaws gold", 0.7)
    assert words is not None and words[0] >= Decimal("0.6")


async def test_suggestion_accept_merges_and_saves_alias(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    await ingest("kinguin", item("k1", "GTA 6 New PC Steam Key"))
    await ingest("foxreload", item("f1", "GTA 6 2026"))
    page = (await client.get("/api/v1/catalogue/matches")).json()
    assert page["total"] == 1
    s = page["items"][0]
    assert s["supplier"] == "Foxreload" and s["score"] >= 0.95
    assert s["candidate"]["suppliers"][0]["code"] == "kinguin"

    r = await client.post(f"/api/v1/catalogue/matches/{s['id']}/accept")
    assert r.status_code == 200, r.text
    async with get_sessionmaker()() as db:
        live = (await db.execute(select(Product).where(Product.product_status != "merged"))).scalars().all()
        assert len(live) == 1
        sps = (await db.execute(select(SupplierProduct))).scalars().all()
        assert {sp.product_id for sp in sps} == {live[0].id}  # both suppliers now offer the same product
        alias = (await db.execute(select(ProductAlias))).scalar_one()
        assert alias.product_id == live[0].id and alias.source == "review"
    assert (await client.post(f"/api/v1/catalogue/matches/{s['id']}/accept")).status_code == 409

    # The next supplier record with that name links straight to the product: no new product, no suggestion.
    await ingest("foxreload", item("f2", "GTA 6 2026", platform="Xbox Series X|S"))
    async with get_sessionmaker()() as db:
        assert await db.scalar(select(func.count(Product.id)).where(Product.product_status != "merged")) == 1
        assert await db.scalar(select(func.count(MatchSuggestion.id))) == 1


async def test_reject_and_same_supplier_never_suggested(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    await ingest("kinguin", item("k1", "Hades PC Steam Key"), item("k2", "Hades II PC Steam Key"))
    await ingest(
        "kinguin", item("k3", "Hades New PC Steam Key")
    )  # same supplier: separate items, no suggestion
    assert (await client.get("/api/v1/catalogue/matches")).json()["total"] == 0
    await ingest("foxreload", item("f1", "Hades 2026"))
    page = (await client.get("/api/v1/catalogue/matches")).json()
    titles = {i["candidate"]["title"] for i in page["items"]}
    assert "Hades II" not in titles and page["total"] >= 1  # the sequel is never offered
    sid = page["items"][0]["id"]
    assert (await client.post(f"/api/v1/catalogue/matches/{sid}/reject")).status_code == 200
    assert (await client.get("/api/v1/catalogue/matches", params={"status": "rejected"})).json()["total"] == 1


async def test_manual_alias_and_scan(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    await ingest("kinguin", item("k1", "Grand Theft Auto V PC Rockstar Key"))
    async with get_sessionmaker()() as db:
        p = (await db.execute(select(Product))).scalar_one()
    r = await client.post(f"/api/v1/catalogue/products/{p.id}/aliases", json={"alias": "GTA V"})
    assert r.status_code == 200, r.text
    dup = await client.post(
        f"/api/v1/catalogue/products/{p.id}/aliases", json={"alias": "Grand Theft Auto V"}
    )
    assert dup.status_code == 422  # already the product's own name
    await ingest("foxreload", item("f1", "GTA V"))
    async with get_sessionmaker()() as db:
        assert await db.scalar(select(func.count(Product.id))) == 1  # linked through the alias
    aliases = (await client.get(f"/api/v1/catalogue/products/{p.id}/aliases")).json()
    assert [a["alias"] for a in aliases] == ["GTA V"]
    assert (
        await client.delete(f"/api/v1/catalogue/products/{p.id}/aliases/{aliases[0]['id']}")
    ).status_code == 204

    # Products imported before matching existed are checked by the scan.
    await ingest("kinguin", item("k2", "Celeste PC Steam Key"))
    await ingest("foxreload", item("f2", "Celeste 2018"))
    async with get_sessionmaker()() as db:
        await db.execute(MatchSuggestion.__table__.delete())
        r1 = await matching.scan(db, limit=1, restart=True)
        r2 = await matching.scan(db, limit=50)
        await db.commit()
    assert r1["checked"] == 1 and not r1["complete"] and r2["complete"]
    assert r1["suggestions"] + r2["suggestions"] >= 1
