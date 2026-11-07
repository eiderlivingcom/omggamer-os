"""Text normalisation used to group supplier records into master products and variants.

Supplier titles carry the variant in the name ("Star Wars Outlaws Gold Edition (PS5) Digital Key (Global)").
`match_key` strips platform, region, edition and delivery words so every variant of the same game lands on one
master product. When a supplier provides a clean base name (Kinguin's `originalName`), that is preferred.
"""

import hashlib
import json
import re
import unicodedata
from typing import Any

_PLATFORM = (
    r"pc|mac|macos|linux|steam|steam deck|epic( games)?( store)?|gog( com)?|origin|ea app|ea play|uplay|"
    r"ubisoft connect|battle\.?net|rockstar( games)?( launcher)?|microsoft store|windows( 10| 11)?( store)?|"
    r"xbox( one| 360| series x\|s| series x| series s| series)?|xbox live|"
    r"ps ?[345]|playstation( [345])?( network)?|psn|nintendo( switch)?( eshop)?|switch|eshop|"
    r"android|ios|google play|app store"
)
_REGION = (
    r"global|worldwide|row|rest of (the )?world|eu|europe|emea|us|usa|na|north america|uk|united kingdom|"
    r"latam|asia|apac|oceania|au|australia|nz|ca|canada|in|india|tr|turkey|ru|russia|cis|br|brazil|mx|mexico|"
    r"de|germany|fr|france|pl|poland|jp|japan|cn|china|kr|korea|ar|argentina|za|south africa|me|middle east"
)
_DELIVERY = (
    r"cd ?key|key|digital( code| key| download)?|code|account|gift ?card|voucher|dlc|download|steam gift|gift"
)
_EDITION = (
    # Words that are always an edition marker on their own...
    r"(?:(?:standard|deluxe|gold|ultimate|premium|digital deluxe|super deluxe|game of the year|goty|"
    r"collector'?s)(?: edition| bundle| pack)?"
    # ...and words that can be part of a real title ("GTA V Enhanced", "Dark Souls Remastered") unless
    # followed by "Edition".
    r"|(?:complete|definitive|special|limited|enhanced|anniversary|legendary|founder'?s|launch|day one|"
    r"cross-gen|remastered)(?: edition| bundle| pack))"
)

_BRACKETS = re.compile(r"\(([^)]*)\)|\[([^\]]*)\]")
_WORDS = re.compile(rf"\b({_PLATFORM}|{_REGION}|{_DELIVERY})\b", re.IGNORECASE)
_EDITION_RE = re.compile(rf"\b{_EDITION}\b", re.IGNORECASE)
_SPACES = re.compile(r"\s+")
_PUNCT_EDGES = re.compile(r"^[\s:\-\u2013\u2014|,/+.]+|[\s:\-\u2013\u2014|,/+.]+$")


def fold(text: str) -> str:
    """Lower-case, strip accents and trademark signs, collapse spaces."""
    # Strip trademark signs before NFKD, which would turn "™" into "TM".
    t = (text or "").replace("™", "").replace("®", "").replace("©", "")
    t = unicodedata.normalize("NFKD", t)
    t = "".join(c for c in t if not unicodedata.combining(c))
    return _SPACES.sub(" ", t).strip().lower()


def base_title(title: str) -> str:
    """Human-readable title without platform, region, edition and delivery words."""
    t = _BRACKETS.sub(" ", title or "")
    t = _EDITION_RE.sub(" ", t)
    t = _WORDS.sub(" ", t)
    t = _SPACES.sub(" ", t)
    # Words like "Account" or "Key" can leave a dangling separator ("Outlaws -").
    t = _PUNCT_EDGES.sub("", t)
    return t.strip() or (title or "").strip()


def match_key(title: str) -> str:
    """Grouping key: folded base title with punctuation removed."""
    k = fold(base_title(title))
    k = re.sub(r"[^a-z0-9 ]+", " ", k)
    return _SPACES.sub(" ", k).strip()[:300]


def slugify(text: str, max_len: int = 200) -> str:
    s = re.sub(r"[^a-z0-9]+", "-", fold(text)).strip("-")
    return (s or "item")[:max_len].strip("-")


def variant_key(
    platform: str | None,
    edition: str | None,
    region: str | None,
    activation_type: str | None,
    language: str | None,
) -> str:
    """NULL-safe identity of a variant within one product."""
    return "|".join(fold(v) if v else "" for v in (platform, edition, region, activation_type, language))[
        :400
    ]


def payload_checksum(payload: dict[str, Any]) -> str:
    raw = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False, default=str)
    return hashlib.sha256(raw.encode()).hexdigest()
