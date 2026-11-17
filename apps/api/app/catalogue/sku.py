"""OMGGamer SKU series: one code per game, per variant and per supplier listing.

    Game (product)     OMG-G004218                  OMG- + type letter + 6-digit number (never reused)
    Variant            OMG-G004218-STM-EU-STD       + platform, region and edition codes
    Supplier listing   OMG-G004218-STM-EU-STD-KG    + supplier code (KG Kinguin, FX Foxreload)

The game number comes from a database sequence, so it is unique and stays the same for the life of the
product. Variant codes are readable (platform / region / edition); when two variants of one game would get the
same code (another activation type or language), they get -2, -3... Region codes use the defined regions in
app.catalogue.regions, so "EUROPE - all countries" and "EU" are both EU.
"""

import re
import uuid

from sqlalchemy import exists, select, text
from sqlalchemy.ext.asyncio import AsyncSession

from app.catalogue import regions
from app.db.models import Product, ProductVariant, Supplier, SupplierProduct

SEQUENCE = "product_sku_seq"

TYPE_LETTERS = {
    "game": "G",
    "game_account": "A",
    "game_currency": "C",
    "dlc": "D",
    "software": "S",
    "gift_card": "V",
    "subscription": "M",
    "hardware": "H",
    "accessory": "X",
}

PLATFORM_CODES = {
    "steam": "STM",
    "epic games": "EPC",
    "epic": "EPC",
    "gog": "GOG",
    "gog.com": "GOG",
    "xbox": "XBX",
    "xbox live": "XBX",
    "xbox one": "XB1",
    "xbox series x|s": "XSX",
    "xbox series": "XSX",
    "playstation": "PSN",
    "psn": "PSN",
    "playstation 4": "PS4",
    "ps4": "PS4",
    "playstation 5": "PS5",
    "ps5": "PS5",
    "nintendo switch": "NSW",
    "switch": "NSW",
    "nintendo": "NIN",
    "ea app": "EAA",
    "origin": "EAA",
    "ubisoft connect": "UBI",
    "uplay": "UBI",
    "battle.net": "BNT",
    "rockstar": "RSG",
    "rockstar games launcher": "RSG",
    "microsoft store": "MSS",
    "windows": "WIN",
    "pc": "PC",
    "mac": "MAC",
    "android": "AND",
    "ios": "IOS",
    "meta quest": "MQ",
    "other": "OTH",
}

REGION_CODES = {
    "Global": "GL",
    "Global (some countries excluded)": "GLX",
    "Europe": "EU",
    "Europe (some countries excluded)": "EUX",
    "Europe & North America": "ENA",
    "North America": "NA",
    "United States": "US",
    "Canada": "CA",
    "Americas": "AM",
    "Latin America": "LA",
    "Argentina": "AR",
    "Brazil": "BR",
    "Rest of the World": "ROW",
    "Asia": "AS",
    "South East Asia": "SEA",
    "China": "CN",
    "Japan": "JP",
    "India": "IN",
    "South Korea": "KR",
    "Taiwan": "TW",
    "Singapore": "SG",
    "Malaysia": "MY",
    "Australia & Oceania": "OC",
    "Australia": "AU",
    "New Zealand": "NZ",
    "Middle East & North Africa": "MEA",
    "Europe, Middle East & Africa": "EMA",
    "Africa": "AF",
    "South Africa": "ZA",
    "United Arab Emirates": "AE",
    "Turkey": "TR",
    "Russia & CIS": "CIS",
    "Russia": "RU",
    "Russia (VPN activation)": "RUV",
    "United Kingdom": "UK",
    "Ireland": "IE",
    "Germany": "DE",
    "Greece": "GR",
    "Czechia": "CZ",
    "Other": "OTH",
}

EDITION_CODES = {
    "standard": "STD",
    "deluxe": "DLX",
    "digital deluxe": "DDX",
    "gold": "GLD",
    "ultimate": "ULT",
    "premium": "PRM",
    "complete": "CMP",
    "definitive": "DEF",
    "game of the year": "GTY",
    "goty": "GTY",
    "collector's": "COL",
    "collectors": "COL",
    "special": "SPE",
    "enhanced": "ENH",
    "anniversary": "ANV",
    "legendary": "LGD",
    "season pass": "SSP",
}

SUPPLIER_CODES = {"kinguin": "KG", "foxreload": "FX"}

_WORD = re.compile(r"[A-Za-z0-9]+")


def _letters(text_: str, n: int = 3) -> str:
    """Fallback code: initials of up to n words, or the first n letters of a single word."""
    words = _WORD.findall(text_ or "")
    if not words:
        return "OTH"
    if len(words) == 1:
        return words[0][:n].upper()
    return "".join(w[0] for w in words[:n]).upper()


def platform_code(platform: str | None) -> str:
    p = (platform or "").strip().lower()
    return PLATFORM_CODES.get(p) or (_letters(p) if p else "ANY")


def region_code(region: str | None) -> str:
    name = regions.canonical(region)
    return REGION_CODES.get(name) or _letters(name)


def edition_code(edition: str | None) -> str:
    e = re.sub(r"\s+edition$", "", (edition or "").strip().lower())
    if not e:
        return "STD"
    return EDITION_CODES.get(e) or _letters(e)


def supplier_code(code: str | None) -> str:
    c = (code or "").strip().lower()
    return SUPPLIER_CODES.get(c) or (_letters(c, 2) if c else "XX")


def product_code(number: int, product_type: str) -> str:
    return f"OMG-{TYPE_LETTERS.get(product_type, 'G')}{number:06d}"


async def assign_product(db: AsyncSession, product: Product) -> str:
    """Give the product its game code (once; it never changes)."""
    if not product.sku:
        number = int(await db.scalar(text(f"SELECT nextval('{SEQUENCE}')")))
        product.sku = product_code(number, product.product_type)
    return product.sku


def variant_base(product_sku: str, platform: str | None, region: str | None, edition: str | None) -> str:
    return f"{product_sku}-{platform_code(platform)}-{region_code(region)}-{edition_code(edition)}"


async def free_variant_sku(db: AsyncSession, base: str, exclude: uuid.UUID | None = None) -> str:
    """base, or base-2, base-3... whichever no other variant has."""
    candidate, n = base, 1
    while True:
        cond = [ProductVariant.sku == candidate]
        if exclude is not None:
            cond.append(ProductVariant.id != exclude)
        if not await db.scalar(select(exists().where(*cond))):
            return candidate
        n += 1
        candidate = f"{base}-{n}"


def _fits(sku_: str | None, base: str) -> bool:
    return bool(sku_) and (sku_ == base or (sku_.startswith(base + "-") and sku_[len(base) + 1 :].isdigit()))


async def new_variant_sku(
    db: AsyncSession, product: Product, platform: str | None, region: str | None, edition: str | None
) -> str:
    product_sku = await assign_product(db, product)
    return await free_variant_sku(db, variant_base(product_sku, platform, region, edition))


async def assign_variant(db: AsyncSession, product: Product, v: ProductVariant) -> str:
    """Variant code under the product's game code; kept when it already fits."""
    base = variant_base(await assign_product(db, product), v.platform, v.region, v.edition)
    if not _fits(v.sku, base):
        v.sku = await free_variant_sku(db, base, v.id)
    return v.sku


async def assign_supplier_product(db: AsyncSession, sp: SupplierProduct) -> str | None:
    """Supplier listing code: the variant code plus the supplier."""
    if sp.variant_id is None:
        return None
    variant = await db.get(ProductVariant, sp.variant_id)
    supplier = await db.get(Supplier, sp.supplier_id)
    if variant is None or not variant.sku or not variant.sku.startswith("OMG-"):
        return None
    sp.sku = f"{variant.sku}-{supplier_code(supplier.code if supplier else None)}"
    return sp.sku


async def refresh_product(db: AsyncSession, product: Product) -> None:
    """Re-code every variant and supplier listing of a product (after a merge moved variants into it)."""
    await assign_product(db, product)
    variants = list(
        (await db.execute(select(ProductVariant).where(ProductVariant.product_id == product.id))).scalars()
    )
    for v in variants:
        await assign_variant(db, product, v)
    await db.flush()
    q = select(SupplierProduct).where(SupplierProduct.product_id == product.id)
    for sp in list((await db.execute(q)).scalars()):
        await assign_supplier_product(db, sp)
