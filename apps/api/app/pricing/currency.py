"""Currency rates from the European Central Bank's public daily reference rates (EUR base).

Source: https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml, published on working days around
16:00 CET. Rates entered by hand (source "manual") are never overwritten by a fetch.
"""

import xml.etree.ElementTree as ET
from decimal import Decimal, InvalidOperation

import httpx
from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.ext.asyncio import AsyncSession

from app.db.models.pricing import CurrencyRate

ECB_URL = "https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml"
transport_override: httpx.AsyncBaseTransport | None = None


def parse_ecb(xml_text: str) -> dict[str, Decimal]:
    root = ET.fromstring(xml_text)  # noqa: S314 - see module docstring
    out: dict[str, Decimal] = {}
    for el in root.iter():
        cur, rate = el.attrib.get("currency"), el.attrib.get("rate")
        if cur and rate and len(cur) == 3:
            try:
                value = Decimal(rate)
            except InvalidOperation:
                continue
            if value > 0:
                out[cur.upper()] = value
    return out


async def refresh_ecb(db: AsyncSession) -> dict[str, int]:
    async with httpx.AsyncClient(timeout=20, transport=transport_override) as client:
        r = await client.get(ECB_URL)
        r.raise_for_status()
    rates = parse_ecb(r.text)
    if not rates:
        raise ValueError("The ECB feed contained no rates")
    manual = {
        (b, q)
        for b, q in (
            await db.execute(
                select(CurrencyRate.base, CurrencyRate.quote).where(CurrencyRate.source == "manual")
            )
        ).tuples()
    }
    updated = 0
    for quote, rate in rates.items():
        if ("EUR", quote) in manual:
            continue
        stmt = pg_insert(CurrencyRate).values(base="EUR", quote=quote, rate=rate, source="ecb")
        await db.execute(
            stmt.on_conflict_do_update(
                index_elements=["base", "quote"],
                set_={"rate": rate, "source": "ecb", "updated_at": stmt.excluded.updated_at},
            )
        )
        updated += 1
    await db.flush()
    return {"fetched": len(rates), "updated": updated, "kept_manual": len(rates) - updated}
