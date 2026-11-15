"""Turn one Foxreload product into a NormalizedProduct.

Foxreload products are one platform + region each (`attributes.platform`, `attributes.region` /
`attributes.country_code`), priced in the requested currency, one offer per product. Platform and region
codes are translated to the labels OMGGamer OS already uses for Kinguin, so the same game, edition, platform
and region lands on the same variant whichever supplier sells it. `steam_app_id` is kept for exact matching.
"""

import re
from decimal import Decimal, InvalidOperation
from typing import Any

from pydantic import ValidationError

from app.catalogue.normalize import edition_of
from app.catalogue.schemas import NormalizedOffer, NormalizedProduct
from app.suppliers.base import NormalizationError

_ACCOUNT = re.compile(r"\baccount\b", re.IGNORECASE)
_GIFT = re.compile(r"\bgift ?card\b|\bvoucher\b|\btop ?up\b|\bwallet\b", re.IGNORECASE)

PLATFORMS = {
    "steam": "Steam",
    "rockstar": "Rockstar Games",
    "rockstar games": "Rockstar Games",
    "rockstar games launcher": "Rockstar Games",
    "ubisoft": "Ubisoft",
    "ubisoft connect": "Ubisoft",
    "uplay": "Ubisoft",
    "ea": "EA App",
    "ea app": "EA App",
    "origin": "EA App",
    "gog": "GOG.com",
    "gog.com": "GOG.com",
    "epic": "Epic Games",
    "epic games": "Epic Games",
    "epic games store": "Epic Games",
    "battle.net": "Battle.net",
    "battlenet": "Battle.net",
    "blizzard": "Battle.net",
    "microsoft store": "MS Store (PC)",
    "ms store": "MS Store (PC)",
    "windows": "MS Store (PC)",
    "android": "Android",
    "ios": "iOS",
    "meta quest": "Meta Quest",
}

# Region / country codes → the region labels already in use.
REGIONS = {
    "WW": "Global",
    "GLOBAL": "Global",
    "WORLDWIDE": "Global",
    "EU": "EUROPE",
    "EUROPE": "EUROPE",
    "US": "United States",
    "USA": "United States",
    "GB": "United Kingdom",
    "UK": "United Kingdom",
    "NA": "North America",
    "LATAM": "Latin America (LATAM)",
    "AS": "ASIA",
    "ASIA": "ASIA",
    "SEA": "South East Asia (BN, ID, MY, PH, SG, TH, VN)",
    "ROW": "REST OF THE WORLD",
    "EMEA": "EMEA",
    "CIS": "RU + CIS",
    "RU": "Russia",
    "MENA": "Middle East and North Africa (MENA)",
    "AR": "Argentina",
    "BR": "Brazil",
    "TR": "Turkey (Türkiye)",
    "IN": "India",
    "CN": "China",
    "JP": "Japan",
    "KR": "South Korea",
    "DE": "Germany",
    "CA": "Canada",
    "AU": "Australia",
    "NZ": "New Zealand",
    "ZA": "South Africa",
    "SG": "Singapore",
    "AE": "United Arab Emirates",
}


def _text(v: Any, max_len: int | None = None) -> str | None:
    if v is None:
        return None
    s = str(v).strip()
    if not s:
        return None
    return s[:max_len] if max_len else s


def _price(v: Any) -> Decimal | None:
    if v is None or isinstance(v, bool):
        return None
    try:
        d = Decimal(str(v))
    except InvalidOperation:
        return None
    return d.quantize(Decimal("0.0001")) if d >= 0 else None


def _int(v: Any) -> int:
    try:
        return max(0, int(v))
    except (TypeError, ValueError):
        return 0


def platform_label(raw: str | None, name: str) -> str | None:
    r = (raw or "").strip().lower()
    n = name.lower()
    if "xbox" in r or (not r and "xbox" in n):
        if "series" in n:
            return "Xbox Series X|S"
        return "Xbox 360" if "360" in n else "Xbox One"
    if "playstation" in r or r in ("psn", "ps", "ps4", "ps5") or (not r and "playstation" in n):
        if "ps5" in n or "playstation 5" in n or r == "ps5":
            return "PlayStation 5"
        if "ps4" in n or "playstation 4" in n or r == "ps4":
            return "PlayStation 4"
        return "PlayStation"
    if "nintendo" in r or "switch" in r or "eshop" in r:
        return "Nintendo Switch 2" if "switch 2" in n else "Nintendo Switch"
    if not r:
        return None
    if r in PLATFORMS:
        return PLATFORMS[r]
    for k, v in PLATFORMS.items():
        if r.startswith(k):
            return v
    return raw.strip() if raw and raw.strip()[:1].isupper() else (raw or "").strip().title() or None


def region_label(region: str | None, country: str | None) -> str:
    code = (_text(region) or _text(country) or "").upper()
    if not code:
        return "Global"
    return REGIONS.get(code, code)


def product_type_for(name: str, attrs: dict[str, Any]) -> str:
    if str(attrs.get("steam_app_type") or "").lower() == "dlc":
        return "dlc"
    if _GIFT.search(name):
        return "gift_card"
    if _ACCOUNT.search(name):
        return "game_account"
    return "game"


def normalize(record: dict[str, Any]) -> NormalizedProduct:
    if not isinstance(record, dict):
        raise NormalizationError("record is not an object")
    pid = _text(record.get("id"), 80)
    if not pid:
        raise NormalizationError("record has no id")
    name = _text(record.get("name"), 500)
    if not name:
        raise NormalizationError(f"product {pid} has no name")
    if record.get("deliveryType") not in (None, "code"):
        raise NormalizationError(f"product {pid} is not a code product ({record.get('deliveryType')})")
    attrs = record.get("attributes") if isinstance(record.get("attributes"), dict) else {}
    steam = attrs.get("steam_app_id")
    variant_attrs = {
        k: v
        for k, v in {
            "steam_app_id": str(steam) if steam not in (None, "") else None,
            "foxreload_platform": _text(attrs.get("platform")),
            "foxreload_region": _text(attrs.get("region")),
            "country_code": _text(attrs.get("country_code")),
            "foxreload_slug": _text(record.get("slug")),
        }.items()
        if v is not None
    }
    price = _price(record.get("price"))
    qty = _int(record.get("quantity"))
    offers = []
    if price is not None:
        offers.append(
            NormalizedOffer(
                external_offer_id=pid,
                seller_id="foxreload",
                region=region_label(attrs.get("region"), attrs.get("country_code")),
                currency=str(record.get("currency") or "usd").upper()[:3],
                price=price,
                quantity=qty,
                availability="in_stock" if qty > 0 else "out_of_stock",
            )
        )
    try:
        return NormalizedProduct(
            external_product_id=pid,
            original_title=name,
            original_description=_text(record.get("description")),
            product_type=product_type_for(name, attrs),
            platform=platform_label(_text(attrs.get("platform"), 80), name),
            edition=edition_of(name),
            region=region_label(attrs.get("region"), attrs.get("country_code")),
            activation_type="account"
            if _ACCOUNT.search(name)
            else ("gift_card" if _GIFT.search(name) else "key"),
            variant_attributes=variant_attrs,
            offers=offers,
            offers_complete=True,
            raw_payload=record,
        )
    except ValidationError as exc:
        raise NormalizationError(f"product {pid}: {exc.errors()[0].get('msg', exc)}") from exc
