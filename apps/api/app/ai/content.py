"""What the AI is given (facts), what it must return (schema) and how its answer is checked."""

import html
import re
import uuid
from typing import Any

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.catalogue.normalize import fold, payload_checksum
from app.db.models import Product, ProductCategory, ProductMedia, ProductVariant, SupplierProduct

MAX_DESCRIPTION = 6000

OUTPUT_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "required": [
        "title",
        "short_description",
        "long_description",
        "meta_title",
        "meta_description",
        "tags",
        "suggested_category",
        "image_alt_text",
        "missing_information",
    ],
    "properties": {
        "title": {"type": "string", "description": "SEO-friendly product title"},
        "short_description": {"type": "string", "description": "One or two sentences"},
        "long_description": {"type": "string", "description": "Plain text, short paragraphs"},
        "meta_title": {"type": "string"},
        "meta_description": {"type": "string"},
        "tags": {"type": "array", "items": {"type": "string"}},
        "suggested_category": {"type": ["string", "null"]},
        "image_alt_text": {
            "type": "array",
            "items": {
                "type": "object",
                "additionalProperties": False,
                "required": ["url", "alt"],
                "properties": {"url": {"type": "string"}, "alt": {"type": "string"}},
            },
        },
        "missing_information": {"type": "array", "items": {"type": "string"}},
    },
}


def plain_text(value: str | None) -> str:
    if not value:
        return ""
    text = re.sub(r"(?i)<br\s*/?>|</p>|</li>|</h\d>", "\n", value)
    text = re.sub(r"<[^>]+>", " ", text)
    text = html.unescape(text)
    text = re.sub(r"[ \t]+", " ", text)
    return re.sub(r"\n\s*\n+", "\n\n", text).strip()


def _uniq(values: list[Any]) -> list[str]:
    out: list[str] = []
    for v in values:
        s = str(v).strip() if v is not None else ""
        if s and s not in out:
            out.append(s)
    return out


async def gather(db: AsyncSession, product: Product) -> tuple[dict[str, Any], str, list[str]]:
    """Facts for the prompt, the supplier description and the image URLs. Prices are deliberately left out."""
    variants = list(
        (await db.execute(select(ProductVariant).where(ProductVariant.product_id == product.id))).scalars()
    )
    sps = list(
        (
            await db.execute(
                select(SupplierProduct)
                .where(SupplierProduct.product_id == product.id)
                .order_by(SupplierProduct.created_at)
            )
        ).scalars()
    )
    media = list(
        (
            await db.execute(
                select(ProductMedia)
                .where(
                    ProductMedia.product_id == product.id,
                    ProductMedia.media_type.in_(("cover", "screenshot")),
                )
                .order_by(ProductMedia.media_type != "cover", ProductMedia.position)
                .limit(6)
            )
        ).scalars()
    )
    raw = [sp.raw_payload or {} for sp in sps]
    languages = _uniq(
        [lang for v in variants for lang in (v.variant_attributes or {}).get("languages") or []]
    )
    languages += [
        x for x in _uniq([lang for r in raw for lang in (r.get("languages") or [])]) if x not in languages
    ]
    genres = _uniq([g for r in raw for g in (r.get("genres") or [])])
    requirements = next((r.get("systemRequirements") for r in raw if r.get("systemRequirements")), None)
    facts: dict[str, Any] = {
        "product_name": product.canonical_title,
        "supplier_titles": _uniq([sp.original_title for sp in sps])[:10],
        "product_type": product.product_type,
        "platforms": _uniq([v.platform for v in variants]),
        "editions": _uniq([v.edition for v in variants]) or ["Standard"],
        "regions": _uniq([v.region for v in variants]),
        "activation": _uniq([v.activation_type for v in variants]),
        "languages": languages,
        "genres": genres,
        "developer": product.developer,
        "publisher": product.publisher,
        "release_date": product.release_date.isoformat() if product.release_date else None,
        "age_rating": next((r.get("ageRating") for r in raw if r.get("ageRating")), None),
        "system_requirements": requirements,
        "image_urls": [m.source_url for m in media],
    }
    description = ""
    for sp in sps:
        description = plain_text(sp.original_description)
        if description:
            break
    return facts, description[:MAX_DESCRIPTION], [m.source_url for m in media]


def source_hash(facts: dict[str, Any], description: str) -> str:
    return payload_checksum({"facts": facts, "description": description})


async def category_names(db: AsyncSession) -> list[str]:
    return list((await db.execute(select(ProductCategory.name).order_by(ProductCategory.name))).scalars())


# ------------------------------------------------------------------ fact check

PLATFORM_TERMS = {
    "Steam": ["steam"],
    "PlayStation": ["playstation", "ps4", "ps5", "psn"],
    "Xbox": ["xbox"],
    "Nintendo": ["nintendo", "switch"],
    "Epic Games": ["epic games"],
    "GOG": ["gog"],
    "EA App": ["ea app", "origin"],
    "Ubisoft Connect": ["ubisoft connect", "uplay"],
    "Battle.net": ["battle.net", "battlenet"],
    "Rockstar": ["rockstar games launcher", "rockstar launcher"],
    "macOS": ["macos", "mac os"],
    "Linux": ["linux"],
    "Android": ["android"],
    "iOS": ["ios", "iphone", "ipad"],
}
EDITION_TERMS = [
    "deluxe",
    "gold edition",
    "ultimate",
    "premium edition",
    "complete edition",
    "definitive",
    "goty",
    "game of the year",
    "collector",
    "season pass",
    "legendary edition",
]
REGION_TERMS = [
    "europe",
    "worldwide",
    "global",
    "region free",
    "north america",
    "united states",
    "united kingdom",
    "asia",
    "latin america",
    "rest of the world",
    "russia",
    "turkey",
    "india",
    "australia",
]
LANGUAGE_TERMS = [
    "english",
    "german",
    "french",
    "spanish",
    "italian",
    "polish",
    "russian",
    "japanese",
    "chinese",
    "korean",
    "portuguese",
    "turkish",
    "dutch",
    "arabic",
    "czech",
    "swedish",
]
SPEC_TERMS = ["ram", "directx", "processor", "graphics card", "gpu", "cpu", "storage:", "vram", "gb of"]


def _contains(corpus: str, term: str) -> bool:
    return re.search(rf"(?<![a-z0-9]){re.escape(term)}(?![a-z0-9])", corpus) is not None


def check(
    output: dict[str, Any], facts: dict[str, Any], description: str, categories: list[str]
) -> tuple[dict[str, Any], list[str]]:
    """Return the cleaned output and review flags for anything the supplier facts do not support."""
    flags: list[str] = []
    out = dict(output)
    source = fold(" ".join([str(v) for v in facts.values() if v]) + " " + description)
    written = fold(
        " ".join(
            str(out.get(k) or "")
            for k in ("title", "short_description", "long_description", "meta_title", "meta_description")
        )
        + " "
        + " ".join(out.get("tags") or [])
    )

    for label, terms in PLATFORM_TERMS.items():
        if any(_contains(written, t) for t in terms) and not any(_contains(source, t) for t in terms):
            flags.append(f"mentions platform {label}, which is not in the supplier data")
    for term in EDITION_TERMS:
        if _contains(written, term) and not _contains(source, term):
            flags.append(f"mentions '{term}', which is not in the supplier data")
    for term in REGION_TERMS:
        if _contains(written, term) and not _contains(source, term):
            flags.append(f"mentions region '{term}', which is not in the supplier data")
    for term in LANGUAGE_TERMS:
        if _contains(written, term) and not _contains(source, term):
            flags.append(f"mentions language '{term}', which is not in the supplier data")
    if not facts.get("system_requirements") and any(_contains(written, t) for t in SPEC_TERMS):
        flags.append("mentions system requirements, which the supplier did not provide")
    for name in ("developer", "publisher"):
        if not facts.get(name) and _contains(written, name):
            flags.append(f"mentions a {name}, but the supplier gave none")

    cat = out.get("suggested_category")
    if cat:
        match = next((c for c in categories if c.lower() == str(cat).strip().lower()), None)
        if match is None:
            flags.append(f"suggested category '{cat}' does not exist")
        out["suggested_category"] = match

    title = str(out.get("title") or "").strip()
    if not title:
        raise ValueError("The model returned an empty title")
    out["title"] = title[:300]
    meta_title = str(out.get("meta_title") or "").strip()
    meta_description = str(out.get("meta_description") or "").strip()
    if len(meta_title) > 60:
        flags.append(f"meta title is {len(meta_title)} characters (limit 60)")
    if len(meta_description) > 160:
        flags.append(f"meta description is {len(meta_description)} characters (limit 160)")
    out["meta_title"] = meta_title[:300]
    out["meta_description"] = meta_description[:500]
    out["tags"] = _uniq([fold(t)[:40] for t in (out.get("tags") or [])])[:10]

    images = set(facts.get("image_urls") or [])
    out["image_alt_text"] = {
        i["url"]: str(i.get("alt") or "")[:200]
        for i in (out.get("image_alt_text") or [])
        if isinstance(i, dict) and i.get("url") in images
    }
    missing = [str(m) for m in (out.get("missing_information") or []) if str(m).strip()][:20]
    out["missing_information"] = missing
    return out, flags


def structured_info(facts: dict[str, Any]) -> dict[str, Any]:
    """Structured product information always comes from supplier facts, never from the model."""
    keys = (
        "platforms",
        "editions",
        "regions",
        "activation",
        "languages",
        "genres",
        "developer",
        "publisher",
        "release_date",
        "age_rating",
    )
    return {k: facts.get(k) for k in keys if facts.get(k)}


def job_id_str(job_id: uuid.UUID | None) -> str:
    return str(job_id) if job_id else ""
