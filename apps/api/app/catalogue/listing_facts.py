"""Facts a supplier only states in a record's title.

Kinguin's region field is coarse: a UK, Polish or US key comes back as "EUROPE" or "AMERICAS", and only the
title ("Hollow Knight: Silksong UK XBOX One / Xbox Series X|S / PC CD Key") says where it works. The title
also says what the buyer receives (a key, a Steam gift link, shared account access) and on which devices.

    country_region(title, region)  -> "United Kingdom" when the title has a country code that fits the region
    delivery_type(title)           -> "key" | "gift" | "access" | "account" | "gift_card"
    devices(title)                 -> ["Xbox One", "Xbox Series X|S", "PC"]
"""

import re

from app.catalogue import regions

# Country / area codes as they appear in supplier titles (upper case, whole word) → defined region name.
CODES: dict[str, str] = {
    "UK": "United Kingdom",
    "GB": "United Kingdom",
    "IE": "Ireland",
    "DE": "Germany",
    "FR": "France",
    "ES": "Spain",
    "IT": "Italy",
    "NL": "Netherlands",
    "BE": "Belgium",
    "AT": "Austria",
    "CH": "Switzerland",
    "PL": "Poland",
    "CZ": "Czechia",
    "SK": "Slovakia",
    "HU": "Hungary",
    "RO": "Romania",
    "GR": "Greece",
    "PT": "Portugal",
    "SE": "Sweden",
    "NO": "Norway",
    "DK": "Denmark",
    "FI": "Finland",
    "TR": "Turkey",
    "US": "United States",
    "USA": "United States",
    "CA": "Canada",
    "MX": "Mexico",
    "BR": "Brazil",
    "AR": "Argentina",
    "CL": "Chile",
    "CO": "Colombia",
    "PE": "Peru",
    "AU": "Australia",
    "NZ": "New Zealand",
    "ZA": "South Africa",
    "NG": "Nigeria",
    "EG": "Egypt",
    "AE": "United Arab Emirates",
    "UAE": "United Arab Emirates",
    "SA": "Saudi Arabia",
    "KSA": "Saudi Arabia",
    "IN": "India",
    "JP": "Japan",
    "KR": "South Korea",
    "CN": "China",
    "HK": "Hong Kong",
    "TW": "Taiwan",
    "SG": "Singapore",
    "MY": "Malaysia",
    "TH": "Thailand",
    "ID": "Indonesia",
    "PH": "Philippines",
    "VN": "Vietnam",
    "RU": "Russia",
    "UA": "Ukraine",
    "KZ": "Kazakhstan",
}

# A country fits the supplier's broad region (so a typo or another code never moves a key across the world).
_PARENT: dict[str, set[str]] = {
    "Europe": {
        "United Kingdom",
        "Ireland",
        "Germany",
        "France",
        "Spain",
        "Italy",
        "Netherlands",
        "Belgium",
        "Austria",
        "Switzerland",
        "Poland",
        "Czechia",
        "Slovakia",
        "Hungary",
        "Romania",
        "Greece",
        "Portugal",
        "Sweden",
        "Norway",
        "Denmark",
        "Finland",
        "Turkey",
    },
    "Americas": {"United States", "Canada", "Mexico", "Brazil", "Argentina", "Chile", "Colombia", "Peru"},
    "North America": {"United States", "Canada", "Mexico"},
    "Latin America": {"Mexico", "Brazil", "Argentina", "Chile", "Colombia", "Peru"},
    "Australia & Oceania": {"Australia", "New Zealand"},
    "Africa": {"South Africa", "Nigeria", "Egypt"},
    "Middle East & North Africa": {"United Arab Emirates", "Saudi Arabia", "Egypt"},
    "Asia": {
        "India",
        "Japan",
        "South Korea",
        "China",
        "Hong Kong",
        "Taiwan",
        "Singapore",
        "Malaysia",
        "Thailand",
        "Indonesia",
        "Philippines",
        "Vietnam",
        "Kazakhstan",
    },
    "South East Asia": {"Singapore", "Malaysia", "Thailand", "Indonesia", "Philippines", "Vietnam"},
    "Russia & CIS": {"Russia", "Ukraine", "Kazakhstan"},
}

_TOKEN = re.compile(r"(?<![A-Za-z0-9])([A-Z]{2,3})(?![A-Za-z0-9|])")
# Platform words: the region code sits right before them ("... UK XBOX One", "... EU PC Steam").
_PLATFORM_WORD = re.compile(
    r"^\s*(pc|xbox|playstation|ps[345]|psn|nintendo|switch|steam|epic|gog|ea|origin|uplay|ubisoft|battle|"
    r"rockstar|microsoft|windows|mac|android|ios|meta|cd|key|account|gift|altergift|access)\b",
    re.IGNORECASE,
)


def country_region(title: str | None, region: str | None) -> str | None:
    """The country a key is limited to, when the title names one inside the supplier's region; else None."""
    if not title:
        return None
    parent = regions.canonical(region)
    for m in _TOKEN.finditer(title):
        code = m.group(1)
        name = CODES.get(code)
        if not name or not _PLATFORM_WORD.match(title[m.end() :]):
            continue
        if parent in ("Global", "Other") or name in _PARENT.get(parent, set()):
            return name
    return None


_GIFT_LINK = re.compile(r"\balter ?gift\b|\bsteam gift\b|(?<!card )\bgift\b(?! ?card)", re.IGNORECASE)
_ACCESS = re.compile(r"(?<!early )\baccess\b", re.IGNORECASE)
_ACCOUNT = re.compile(r"\baccount\b", re.IGNORECASE)
_GIFT_CARD = re.compile(r"\bgift ?card\b|\bvoucher\b|\btop ?up\b|\bwallet\b", re.IGNORECASE)


def delivery_type(title: str | None) -> str:
    """What the buyer receives: key, gift (a store gift link), access (shared account), account, gift_card."""
    t = title or ""
    if _ACCOUNT.search(t):
        return "account"
    if _GIFT_CARD.search(t):
        return "gift_card"
    if _ACCESS.search(t):
        return "access"
    if _GIFT_LINK.search(t):
        return "gift"
    return "key"


DELIVERY_LABELS = {
    "key": "Key",
    "gift": "Gift",
    "access": "Access",
    "account": "Account",
    "gift_card": "Gift Card",
}

# Delivery types the store does not sell (held back until the owner decides).
NOT_SOLD = {"access"}

_DEVICES = [
    (re.compile(r"xbox one", re.I), "Xbox One"),
    (re.compile(r"xbox series x\s*\|\s*s|xbox series", re.I), "Xbox Series X|S"),
    (re.compile(r"\bps5\b|playstation 5", re.I), "PS5"),
    (re.compile(r"\bps4\b|playstation 4", re.I), "PS4"),
    (re.compile(r"nintendo switch 2|switch 2", re.I), "Nintendo Switch 2"),
    (re.compile(r"nintendo switch|\bswitch\b", re.I), "Nintendo Switch"),
    (re.compile(r"\bpc\b|windows", re.I), "PC"),
    (re.compile(r"\bmac\b|macos", re.I), "Mac"),
    (re.compile(r"\blinux\b", re.I), "Linux"),
]


def devices(title: str | None) -> list[str]:
    """Devices the title says the item runs on, in a fixed order."""
    t = title or ""
    out: list[str] = []
    for rx, name in _DEVICES:
        if rx.search(t) and name not in out:
            if name == "Nintendo Switch" and "Nintendo Switch 2" in out:
                continue
            out.append(name)
    return out
