"""Guaranteed-profit pricing: after the sale, a coupon and PRO, a sale still covers cost, profit and fees."""

from decimal import Decimal

from httpx import AsyncClient

from app.db.models.pricing import PricingRule
from app.pricing.engine import Rates, quote
from tests.conftest import MakeUser, login


def rule(**kw: object) -> PricingRule:
    base = dict(
        name="g",
        scope="global",
        priority=0,
        markup_percent=Decimal("0"),
        markup_fixed=Decimal("0"),
        rounding="cents_99",
        is_active=True,
        mode="guaranteed",
        target_profit_percent=Decimal("15"),
        target_profit_fixed=Decimal("0.50"),
        gateway_fee_percent=Decimal("3.5"),
        gateway_fee_fixed=Decimal("0.30"),
        sale_percent=Decimal("50"),
        coupon_percent=Decimal("10"),
        pro_percent=Decimal("10"),
    )
    base.update(kw)
    return PricingRule(**base)


def net_after_everything(q, r: PricingRule) -> Decimal:
    """What is left of the cheapest possible sale after the payment fee."""
    paid = q.sale_price * (1 - r.coupon_percent / 100) * (1 - r.pro_percent / 100)
    return paid * (1 - r.gateway_fee_percent / 100) - r.gateway_fee_fixed


def test_guaranteed_profit_survives_every_discount() -> None:
    rates = Rates([])
    for sale in ("40", "50", "60"):
        r = rule(sale_percent=Decimal(sale))
        for cost in ("1.00", "9.99", "47.30", "250"):
            q = quote(Decimal(cost), "USD", "USD", r, rates)
            assert q.sale_price is not None and q.floor_price is not None
            assert q.sale_price < q.selling_price
            assert str(q.selling_price).endswith(".99")
            need = Decimal(cost) * Decimal("1.15") + Decimal("0.50")
            assert net_after_everything(q, r) >= need - Decimal("0.01"), (sale, cost)
            # the floor is exactly what has to be paid to keep that profit
            assert (
                q.floor_price * (1 - r.gateway_fee_percent / 100) - r.gateway_fee_fixed
            ) >= need - Decimal("0.01")


def test_markup_rules_unchanged() -> None:
    r = rule(mode="markup", markup_percent=Decimal("15"))
    q = quote(Decimal("10"), "USD", "USD", r, Rates([]))
    assert q.selling_price == Decimal("11.99") and q.sale_price is None and q.floor_price is None


async def test_create_guaranteed_rule(client: AsyncClient, make_user: MakeUser) -> None:
    await make_user()
    await login(client)
    bad = await client.post("/api/v1/pricing/rules", json={"name": "x", "mode": "guaranteed"})
    assert bad.status_code == 422
    r = await client.post(
        "/api/v1/pricing/rules",
        json={
            "name": "OMG guaranteed",
            "mode": "guaranteed",
            "target_profit_percent": "15",
            "target_profit_fixed": "0.5",
            "gateway_fee_percent": "3.5",
            "gateway_fee_fixed": "0.3",
            "sale_percent": "50",
            "coupon_percent": "10",
            "pro_percent": "10",
            "rounding": "cents_99",
        },
    )
    assert r.status_code in (200, 201), r.text
    body = r.json()
    assert body["mode"] == "guaranteed" and Decimal(body["sale_percent"]) == 50
