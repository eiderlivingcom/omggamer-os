"""One defined list of regions, and the mapping from every supplier label onto it.

Suppliers write the same region many ways ("REST OF THE WORLD", "Rest of the world (RoW) - custom", "RoW",
"EUROPE - all countries", "EU", "WW"...). Every label is mapped onto exactly one name below, so a product
never shows two regions that mean the same thing. A label nobody has defined yet keeps a tidy version of its
own name if it looks like a place, otherwise it becomes "Other" (shown with a "check the notes" hint).
"""

import re

# Name → what it means for the buyer. The order is the order regions are listed in.
REGIONS: dict[str, str] = {
    "Global": "Activates in every country.",
    "Global (some countries excluded)": (
        "Activates worldwide except a few countries named in the product notes "
        "(often China, Germany, Russia or Saudi Arabia)."
    ),
    "Europe": "Activates in European countries, including the EU, the UK, Switzerland and Norway.",
    "Europe (some countries excluded)": (
        "Activates in Europe except the countries named in the product notes."
    ),
    "Europe & North America": "Activates in Europe, the United States and Canada.",
    "North America": "Activates in the United States and Canada.",
    "United States": "Activates in the United States only.",
    "Canada": "Activates in Canada only.",
    "Americas": "Activates in North, Central and South America.",
    "Latin America": "Activates in Mexico, Central America and South America.",
    "Argentina": "Activates in Argentina only.",
    "Brazil": "Activates in Brazil only.",
    "Rest of the World": (
        "Activates in countries outside Europe, the UK, the United States and Canada. "
        "It does not work in those regions."
    ),
    "Asia": "Activates in Asian countries.",
    "South East Asia": (
        "Activates in Brunei, Indonesia, Malaysia, the Philippines, Singapore, Thailand and Vietnam."
    ),
    "China": "Activates in mainland China only.",
    "Japan": "Activates in Japan only.",
    "India": "Activates in India only.",
    "South Korea": "Activates in South Korea only.",
    "Taiwan": "Activates in Taiwan only.",
    "Singapore": "Activates in Singapore only.",
    "Malaysia": "Activates in Malaysia only.",
    "Australia & Oceania": "Activates in Australia, New Zealand and the Pacific islands.",
    "Australia": "Activates in Australia only.",
    "New Zealand": "Activates in New Zealand only.",
    "Middle East & North Africa": "Activates in the Middle East and North Africa (MENA).",
    "Europe, Middle East & Africa": "Activates in Europe, the Middle East and Africa (EMEA).",
    "Africa": "Activates in African countries.",
    "South Africa": "Activates in South Africa only.",
    "United Arab Emirates": "Activates in the United Arab Emirates only.",
    "Turkey": "Activates in Turkey (Türkiye) only.",
    "Russia & CIS": (
        "Activates in Russia and CIS countries (Armenia, Azerbaijan, Belarus, Kazakhstan, Kyrgyzstan, "
        "Moldova, Tajikistan, Uzbekistan)."
    ),
    "Russia": "Activates in Russia only.",
    "Russia (VPN activation)": (
        "A Russian-region key: activating it from outside Russia needs a VPN set to Russia."
    ),
    "United Kingdom": "Activates in the United Kingdom only.",
    "Ireland": "Activates in Ireland only.",
    "Germany": "Activates in Germany only.",
    "Greece": "Activates in Greece only.",
    "Czechia": "Activates in Czechia only.",
    "Other": "A supplier-specific region. Check the product notes before buying.",
}

# Exact labels (lower case) and codes → region name.
_ALIASES: dict[str, str] = {
    **{k: "Global" for k in ("global", "ww", "worldwide", "world", "all", "region free", "gl")},
    **{
        k: "Europe"
        for k in (
            "europe",
            "europe - all countries",
            "eu",
            "eur",
            "european union",
            "european union + uk",
            "eu xbox supported countries",
            "siee",
        )
    },
    "eu/na": "Europe & North America",
    **{
        k: "Rest of the World"
        for k in (
            "rest of the world",
            "rest of world",
            "rest of the world (row) - custom",
            "row",
            "outside europe",
        )
    },
    "north america": "North America",
    "na": "North America",
    **{k: "United States" for k in ("united states", "us", "usa", "xbox us")},
    **{k: "Canada" for k in ("canada", "ca")},
    "americas": "Americas",
    **{k: "Latin America" for k in ("latin america", "latin america (latam)", "latam")},
    **{k: "Argentina" for k in ("argentina", "ar")},
    **{k: "Brazil" for k in ("brazil", "br")},
    **{k: "Asia" for k in ("asia", "asia - all countries", "as")},
    **{
        k: "South East Asia"
        for k in ("south east asia", "southeast asia", "sea", "south east asia (bn, id, my, ph, sg, th, vn)")
    },
    **{k: "China" for k in ("china", "cn")},
    **{k: "Japan" for k in ("japan", "jp")},
    **{k: "India" for k in ("india", "in")},
    **{k: "South Korea" for k in ("south korea", "korea", "kr")},
    **{k: "Taiwan" for k in ("taiwan", "tw")},
    **{k: "Singapore" for k in ("singapore", "sg")},
    **{k: "Malaysia" for k in ("malaysia", "my")},
    **{k: "Australia & Oceania" for k in ("australia and oceania", "oceania", "australia & oceania")},
    **{k: "Australia" for k in ("australia", "au")},
    **{k: "New Zealand" for k in ("new zealand", "nz")},
    **{k: "Middle East & North Africa" for k in ("mena", "middle east and north africa (mena)")},
    "emea": "Europe, Middle East & Africa",
    "africa": "Africa",
    **{k: "South Africa" for k in ("south africa", "za")},
    **{k: "United Arab Emirates" for k in ("uae", "ae", "united arab emirates")},
    **{k: "Turkey" for k in ("turkey", "turkey (türkiye)", "türkiye", "tr")},
    **{k: "Russia & CIS" for k in ("ru + cis", "cis", "russia and cis", "russia & cis")},
    **{k: "Russia" for k in ("russia", "ru")},
    "ru vpn": "Russia (VPN activation)",
    **{k: "United Kingdom" for k in ("united kingdom", "uk", "gb")},
    **{k: "Ireland" for k in ("ireland", "ie")},
    **{k: "Germany" for k in ("germany", "de")},
    **{k: "Greece" for k in ("greece", "gr")},
    **{k: "Czechia" for k in ("czechia", "czech republic", "cz")},
    # "INVERSED" may mean the opposite of RoW: not guessed.
    **{k: "Other" for k in ("other", "other - custom", "bread and fred row - inversed")},
}

_RULES: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"^region free\b|\bregion free$"), "Global"),
    (
        re.compile(r"^(nsfw|adult)\b|^global (minus|without|except)|^without |^outside ru"),
        "Global (some countries excluded)",
    ),
    (re.compile(r"^eu(rope)? (without|except|minus)\b"), "Europe (some countries excluded)"),
    (re.compile(r"\brow\b|rest of (the )?world"), "Rest of the World"),
]
_PLACE = re.compile(r"^[a-zà-ÿ][a-zà-ÿ .'-]{2,30}$")
# Labels seen from suppliers that are product or seller names, not places.
_NOT_PLACES = {"neon", "altergifts", "bitjem prepaids", "the outlast trials", "template 1", "aw"}


def canonical(label: str | None) -> str:
    """The defined region name for a supplier label. Empty means Global."""
    raw = re.sub(r"\s+", " ", (label or "").strip())
    low = raw.lower()
    if not low:
        return "Global"
    if low in _ALIASES:
        return _ALIASES[low]
    for pattern, name in _RULES:
        if pattern.search(low):
            return name
    if low in _NOT_PLACES or any(ch.isdigit() for ch in low) or not _PLACE.match(low):
        return "Other"
    known = {name.lower(): name for name in REGIONS}
    return known.get(low, raw.title())


def definition(name: str) -> str:
    return REGIONS.get(name, "Check the product notes for where this product activates.")


# ------------------------------------------------------------------ stores listed as platforms

# Stores (not platforms) that suppliers put in the platform field. Their games are PC games.
_STORES = {"gog": "GOG.com", "gog.com": "GOG.com", "gog com": "GOG.com", "pc gog": "GOG.com"}


def store_of(platform: str | None) -> str | None:
    """The store a key activates on when the supplier gave a store as the platform (e.g. GOG.com)."""
    return _STORES.get((platform or "").strip().lower())


def platform_name(platform: str | None) -> str | None:
    """The platform to show: PC for store-only labels such as GOG.com, otherwise unchanged."""
    return "PC" if store_of(platform) else platform
