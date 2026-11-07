"""Turn one Kinguin product object into a NormalizedProduct.

Field meanings come from the documented Product and Offer objects. Records differ (missing offers,
missing images, strings where lists are expected), so every field is read defensively. Anything we do
not map is still kept: the whole record goes into `raw_payload`.
"""

import re
from datetime import date
from decimal import Decimal, InvalidOperation
from typing import Any

from pydantic import ValidationError

from app.catalogue.normalize import edition_of
from app.catalogue.schemas import NormalizedMedia, NormalizedOffer, NormalizedProduct
from app.suppliers.base import NormalizationError

# Kinguin platform names (GET /v1/platforms) → the platform label OMGGamer uses. Unknown names pass through.
PLATFORMS = {
    "pc steam": "Steam",
    "pc epic games": "Epic Games",
    "pc gog": "GOG",
    "pc battle.net": "Battle.net",
    "pc ubisoft connect": "Ubisoft Connect",
    "pc rockstar games": "Rockstar Games",
    "pc mog station": "Mog Station",
    "pc digital download": "PC",
    "pc": "PC",
    "ea app": "EA App",
}
_GLOBAL_REGIONS = {"region free", "global", "worldwide"}
_ACCOUNT = re.compile(r"\baccount\b", re.IGNORECASE)
_GIFT = re.compile(r"\bgift ?card\b|\bvoucher\b|\btop ?up\b|\bwallet\b", re.IGNORECASE)


def _text(v: Any, max_len: int | None = None) -> str | None:
    if v is None:
        return None
    s = str(v).strip()
    if not s:
        return None
    return s[:max_len] if max_len else s


def _list(v: Any) -> list[str]:
    if v is None:
        return []
    if isinstance(v, str):
        return [v.strip()] if v.strip() else []
    if isinstance(v, list):
        return [str(x).strip() for x in v if x is not None and str(x).strip()]
    return []


def _int(v: Any) -> int:
    try:
        return max(0, int(v))
    except (TypeError, ValueError):
        return 0


def _price(v: Any) -> Decimal | None:
    if v is None or isinstance(v, bool):
        return None
    try:
        d = Decimal(str(v))
    except InvalidOperation:
        return None
    return d.quantize(Decimal("0.0001")) if d >= 0 else None


def _date(v: Any) -> date | None:
    s = _text(v)
    if not s:
        return None
    try:
        return date.fromisoformat(s[:10])
    except ValueError:
        return None


def platform_label(raw: str | None) -> str | None:
    if not raw:
        return None
    return PLATFORMS.get(raw.strip().lower(), raw.strip())


def region_label(raw: str | None) -> str:
    if not raw or raw.strip().lower() in _GLOBAL_REGIONS:
        return "Global"
    return raw.strip()


def product_type_for(name: str, tags: list[str]) -> str:
    t = {x.lower() for x in tags}
    if "dlc" in t:
        return "dlc"
    if "software" in t:
        return "software"
    if "prepaid" in t or _GIFT.search(name):
        return "gift_card"
    if _ACCOUNT.search(name):
        return "game_account"
    return "game"


def _media(images: Any, videos: Any) -> list[NormalizedMedia]:
    out: list[NormalizedMedia] = []
    seen: set[str] = set()

    def add(url: Any, kind: str) -> None:
        u = _text(url)
        if u and u.startswith(("http://", "https://")) and u not in seen and len(u) <= 1000:
            seen.add(u)
            out.append(NormalizedMedia(url=u, media_type=kind))

    if isinstance(images, dict):
        cover = images.get("cover")
        if isinstance(cover, dict):
            add(cover.get("url") or cover.get("thumbnail"), "cover")
        shots = images.get("screenshots")
        if isinstance(shots, list):
            for s in shots:
                if isinstance(s, dict):
                    add(s.get("url"), "screenshot")
    if isinstance(videos, list):
        for v in videos:
            vid = _text(v.get("video_id")) if isinstance(v, dict) else None
            if vid and re.fullmatch(r"[\w-]{6,20}", vid):
                add(f"https://www.youtube.com/watch?v={vid}", "video")
    return out


def _offers(record: dict[str, Any]) -> tuple[list[NormalizedOffer], bool]:
    raw = record.get("offers")
    if not isinstance(raw, list):
        # No offer list in this response: keep existing offers as they are.
        return [], False
    out: list[NormalizedOffer] = []
    for o in raw:
        if not isinstance(o, dict):
            continue
        oid, price = _text(o.get("offerId"), 80), _price(o.get("price"))
        if not oid or price is None:
            continue
        qty = _int(o.get("qty"))
        availability = (
            "preorder" if o.get("isPreorder") is True else ("in_stock" if qty > 0 else "out_of_stock")
        )
        out.append(
            NormalizedOffer(
                external_offer_id=oid,
                seller_id=_text(o.get("merchantName"), 80),
                currency="EUR",  # documented: offer price in EUR
                price=price,
                quantity=qty,
                availability=availability,
            )
        )
    return out, True


def normalize(record: dict[str, Any]) -> NormalizedProduct:
    if not isinstance(record, dict):
        raise NormalizationError("record is not an object")
    kid = record.get("kinguinId")
    if kid is None or isinstance(kid, bool) or not str(kid).strip().isdigit():
        raise NormalizationError(f"record has no valid kinguinId ({kid!r})")
    name = _text(record.get("name"), 500)
    if not name:
        raise NormalizationError(f"product {kid} has no name")
    tags = _list(record.get("tags"))
    activation = "account" if _ACCOUNT.search(name) else ("gift_card" if _GIFT.search(name) else "key")
    offers, complete = _offers(record)
    attrs = {
        k: v
        for k, v in {
            "region_id": record.get("regionId"),
            "country_limitation": _list(record.get("countryLimitation")) or None,
            "languages": _list(record.get("languages")) or None,
            "tags": tags or None,
            "age_rating": _text(record.get("ageRating")),
            "steam_app_id": _text(record.get("steam")),
            "metacritic_score": record.get("metacriticScore"),
            "kinguin_platform": _text(record.get("platform")),
        }.items()
        if v is not None
    }
    try:
        return NormalizedProduct(
            external_product_id=str(kid).strip(),
            external_product_set_id=_text(record.get("productId"), 80),
            original_title=name,
            original_description=_text(record.get("description")),
            base_title=_text(record.get("originalName"), 300),
            product_type=product_type_for(name, tags),
            platform=platform_label(_text(record.get("platform"), 80)),
            edition=edition_of(name),
            region=region_label(_text(record.get("regionalLimitations"), 80)),
            activation_type=activation,
            variant_attributes=attrs,
            developer=_text(", ".join(_list(record.get("developers"))), 300),
            publisher=_text(", ".join(_list(record.get("publishers"))), 300),
            release_date=_date(record.get("releaseDate")),
            genres=_list(record.get("genres")),
            media=_media(record.get("images"), record.get("videos")),
            offers=offers,
            offers_complete=complete,
            raw_payload=record,
        )
    except ValidationError as exc:
        raise NormalizationError(f"product {kid}: {exc.errors()[0].get('msg', exc)}") from exc
