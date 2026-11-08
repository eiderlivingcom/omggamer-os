"""Phase 10: analytics, automation (schedules, auto-approve, retries, alerts), new suppliers and stores."""

import json
from datetime import UTC, datetime
from decimal import Decimal
from typing import Any

import httpx
import pytest
from httpx import AsyncClient
from sqlalchemy import select

from app.automation import service as automation
from app.catalogue.schemas import NormalizedOffer, NormalizedProduct
from app.catalogue.services import CatalogueService
from app.db.models import (
    AiJob,
    ImportJob,
    Order,
    Product,
    ProductContent,
    PublishingQueueItem,
    Storefront,
    Supplier,
)
from app.db.session import get_sessionmaker
from app.suppliers import registry
from app.suppliers.base import SupplierAdapterBase
from tests.conftest import MakeUser, login


async def seed_catalogue() -> None:
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        for ext, title in [("1", "Hades PC Steam Key"), ("2", "Celeste PC Steam Key")]:
            await CatalogueService(db).ingest(
                k,
                NormalizedProduct(
                    external_product_id=ext,
                    original_title=title,
                    platform="Steam",
                    region="Global",
                    offers=[
                        NormalizedOffer(
                            external_offer_id=f"o{ext}", currency="EUR", price=Decimal("5"), quantity=2
                        )
                    ],
                ),
            )
        p = (await db.execute(select(Product).limit(1))).scalar_one()
        db.add(ProductContent(product_id=p.id, title="x", content_status="approved"))
        sf = (await db.execute(select(Storefront).where(Storefront.code == "omggamer-staging"))).scalar_one()
        db.add(
            Order(
                storefront_id=sf.id,
                external_order_id="1",
                order_number="1",
                status="fulfilled",
                total=Decimal("20"),
                fulfilled_at=datetime.now(UTC),
            )
        )
        await db.commit()


async def test_analytics_summary_uses_real_counts(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    empty = (await client.get("/api/v1/analytics/summary")).json()
    assert (
        empty["catalogue"]["products"] == 0
        and empty["sales"]["orders"] == 0
        and empty["ai"]["completion_pct"] == 0
    )
    await seed_catalogue()
    a = (await client.get("/api/v1/analytics/summary", params={"days": 7})).json()
    assert (
        a["catalogue"]["products"] == 2
        and a["catalogue"]["variants"] == 2
        and a["catalogue"]["offers_available"] == 2
    )
    assert a["catalogue"]["by_supplier"] == [{"supplier": "Kinguin", "products": 2, "records": 2}]
    assert a["ai"]["completion_pct"] == 50.0 and a["ai"]["approved_pct"] == 50.0
    assert (
        a["sales"]["orders"] == 1
        and a["sales"]["revenue"] == "20.00"
        and a["sales"]["fulfilled_pct"] == 100.0
    )
    assert len(a["sales"]["daily"]) == 1 and a["suppliers"]["imports"][0]["supplier"] == "Kinguin"


async def test_automation_schedules_and_auto_retry(
    client: AsyncClient, make_user: MakeUser, monkeypatch: pytest.MonkeyPatch
) -> None:
    await make_user()
    await login(client)
    await seed_catalogue()
    bad = await client.put(
        "/api/v1/automation/settings", json={"notify": {"enabled": True, "webhook_url": "http://x"}}
    )
    assert bad.status_code == 422
    now = datetime.now(UTC)
    s = (
        await client.put(
            "/api/v1/automation/settings",
            json={
                "full_import": {"enabled": True, "weekday": now.weekday(), "hour": now.hour},
                "ai_daily": {"enabled": True, "hour": now.hour, "limit": 10},
                "auto_retry": {"enabled": True, "max": 2},
            },
        )
    ).json()
    assert s["ai_daily"]["limit"] == 10 and s["full_import"]["enabled"]

    from app.workers import tasks

    sent: list[str] = []
    for t in (tasks.run_import, tasks.ai_generate, tasks.publish_run):
        monkeypatch.setattr(t, "apply_async", lambda *a, _n=t.name, **k: sent.append(_n))
    async with get_sessionmaker()() as db:  # a failed AI job and a failed publish item wait for a retry
        p = (await db.execute(select(Product).limit(1))).scalar_one()
        sf = (await db.execute(select(Storefront).limit(1))).scalar_one()
        db.add(AiJob(product_id=p.id, status="failed", attempts=1))
        db.add(
            PublishingQueueItem(
                product_id=p.id, storefront_id=sf.id, status="failed", approval_status="approved", attempts=5
            )
        )
        await db.commit()

    done = await tasks._automation_tick()
    assert "full_import" in done and any(
        d.startswith("ai_daily:1") for d in done
    )  # one product has no content
    assert any(d == "auto_retry:1+1" for d in done)
    assert (
        sent.count("imports.run") == 1 and sent.count("ai.generate") == 2 and sent.count("publish.run") == 1
    )
    again = await tasks._automation_tick()
    assert not any(d.startswith(("full_import", "ai_daily", "auto_retry")) for d in again)  # once per window
    async with get_sessionmaker()() as db:
        assert (await db.execute(select(ImportJob))).scalar_one().params["scheduled"] is True


async def test_alerts_are_sent_once(
    client: AsyncClient, make_user: MakeUser, monkeypatch: pytest.MonkeyPatch
) -> None:
    await make_user()
    await login(client)
    posts: list[dict[str, Any]] = []

    def hook(request: httpx.Request) -> httpx.Response:
        posts.append(json.loads(request.content))
        return httpx.Response(200)

    monkeypatch.setattr(automation, "transport_override", httpx.MockTransport(hook))
    await client.put(
        "/api/v1/automation/settings",
        json={"notify": {"enabled": True, "webhook_url": "https://hooks.example/x"}},
    )
    assert (await client.post("/api/v1/automation/test-notification")).json()["ok"] is True
    async with get_sessionmaker()() as db:
        k = (await db.execute(select(Supplier).where(Supplier.code == "kinguin"))).scalar_one()
        db.add(
            ImportJob(
                supplier_id=k.id,
                job_type="incremental",
                status="failed",
                last_error="Kinguin refused the API key",
            )
        )
        await db.commit()
        assert await automation.send_alerts(db) == 1
        await db.commit()
        assert await automation.send_alerts(db) == 0  # not repeated
        await db.commit()
    assert "refused the API key" in posts[-1]["text"] and len(posts) == 2


def test_auto_approve_rule() -> None:
    s = {"auto_approve": {"enabled": True, "product_types": ["game"]}}
    assert automation.should_auto_approve(s, "game", []) is True
    assert automation.should_auto_approve(s, "game", ["mentions platform Xbox"]) is False
    assert automation.should_auto_approve(s, "dlc", []) is False
    assert automation.should_auto_approve({"auto_approve": {"enabled": False}}, "game", []) is False


async def test_add_supplier_and_storefront(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    assert {"code": "kinguin", "available": True} in (await client.get("/api/v1/suppliers/adapters")).json()
    r = (await client.post("/api/v1/suppliers", json={"name": "G2A", "code": "g2a"})).json()
    assert r["status"] == "disabled" and r["adapter"] is False  # stays off until an adapter exists
    assert (await client.post("/api/v1/suppliers", json={"name": "Again", "code": "g2a"})).status_code == 409
    assert (
        await client.post("/api/v1/suppliers", json={"name": "Bad", "code": "Bad Code!"})
    ).status_code == 422
    sf = (
        await client.post(
            "/api/v1/storefronts",
            json={
                "name": "OMGGamer EU",
                "code": "omggamer-eu",
                "domain": "https://eu.omggamer.com/",
                "currency": "eur",
            },
        )
    ).json()
    assert sf["domain"] == "eu.omggamer.com" and sf["status"] == "disabled"
    codes = {s["code"] for s in (await client.get("/api/v1/storefronts")).json()}
    assert "omggamer-eu" in codes
    con = (await client.get("/api/v1/storefronts/omggamer-eu/connector")).json()
    assert con["url"] == "https://eu.omggamer.com/wp-json/omggamer-connector/v1"


def test_kinguin_adapter_implements_the_common_interface() -> None:
    adapter = registry.ADAPTERS["kinguin"]({}, "live", {"api_key": "k" * 20})
    assert isinstance(adapter, SupplierAdapterBase)
    for name in (
        "authenticate",
        "fetch_products",
        "fetch_product_details",
        "fetch_offers",
        "normalize_product",
        "normalize_offer",
        "check_availability",
        "purchase_offer",
    ):
        assert callable(getattr(adapter, name))
    offer = adapter.normalize_offer(
        {"kinguinId": 9, "name": "X PC Steam Key", "offers": [{"offerId": "a", "price": 1, "qty": 2}]}, "a"
    )
    assert offer is not None and offer.quantity == 2
