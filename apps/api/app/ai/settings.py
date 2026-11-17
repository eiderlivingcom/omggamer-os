"""AI settings, the encrypted provider keys and versioned prompt templates."""

import json
from decimal import Decimal
from typing import Any

from sqlalchemy import select, update
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.security import decrypt_secret, encrypt_secret
from app.db.models import AiPrompt, AppSetting, IntegrationCredential

PROVIDER = "openai"  # the original provider; kept as the default

# OpenAI-compatible providers AI Studio can talk to. OmniRoute is the self-hosted router on the Oracle server
# that sends requests to the free models connected in its dashboard (a combo name such as "free-stack" is
# used as the model and falls back between its models).
PROVIDERS: dict[str, dict[str, str]] = {
    "openai": {"label": "OpenAI", "base_url": "https://api.openai.com/v1", "model": "gpt-4o-mini"},
    "omniroute": {"label": "OmniRoute", "base_url": "https://omniroute.1wp.site/v1", "model": "free-stack"},
}

DEFAULTS: dict[str, Any] = {
    "provider": "openai",
    "base_url": "",  # empty: the provider's standard address
    # "auto": ask for strict JSON schema output and fall back to plain JSON mode when a model does not support
    # it (common with free models). "schema" or "json_object" force one of them.
    "json_mode": "auto",
    "model": "gpt-4o-mini",
    "temperature": 0.4,
    "send_temperature": True,  # some models reject a temperature; turn this off for them
    "max_output_tokens": 1800,
    "batch_size": 50,
    "concurrency": 2,
    "max_retries": 3,
    "language": "English",
    "locale": "en",
    "brand_voice": (
        "Friendly, clear and trustworthy, written for gamers. "
        "Short paragraphs, no hype words, no exclamation marks."
    ),
    "seo_requirements": (
        "Meta title up to 60 characters including the platform. Meta description 120-160 characters. "
        "Use the product name naturally; do not stuff keywords."
    ),
    "paused": False,
    # USD per 1M tokens. Check your provider's current price list and adjust; unknown models cost 0.
    "pricing": {"gpt-4o-mini": {"input_per_million": 0.15, "output_per_million": 0.60}},
}

DEFAULT_SYSTEM_PROMPT = """You write product copy for OMGGamer, an online store for digital games,
keys and accounts.

Rules you must follow:
- Use only the facts provided in the input. Treat the supplier description as a source of facts,
  not as text to copy.
- Never invent or assume game features, supported platforms, editions, languages, activation methods, region
  compatibility, system requirements, developer or publisher details, release dates or other specifications.
- If a fact is not provided, leave it out. Never fill gaps with guesses. List anything important that is
  missing in "missing_information".
- Only mention the platforms, editions and regions listed in the facts.
- suggested_category must be exactly one of the categories listed in the input, or null.
- Write in {language}. Brand voice: {brand_voice}
- SEO requirements: {seo_requirements}
- long_description is plain text with short paragraphs separated by blank lines. No HTML, no markdown.
- tags: 3 to 10 short lowercase tags taken from the facts (genre, platform, edition, product type)."""

DEFAULT_USER_TEMPLATE = """Facts about the product (JSON, from the supplier):
{facts}

Categories you may choose from for suggested_category:
{categories}

Original supplier description (plain text, may be empty):
{description}

Write the OMGGamer product content."""


def merged(value: dict[str, Any] | None) -> dict[str, Any]:
    out = {**DEFAULTS, **(value or {})}
    out["pricing"] = {**DEFAULTS["pricing"], **((value or {}).get("pricing") or {})}
    return out


async def get_settings(db: AsyncSession) -> dict[str, Any]:
    row = await db.get(AppSetting, "ai")
    return merged(row.value if row else None)


async def save_settings(db: AsyncSession, changes: dict[str, Any], user_id: Any = None) -> dict[str, Any]:
    row = await db.get(AppSetting, "ai")
    if row is None:
        row = AppSetting(key="ai", value={})
        db.add(row)
    value = {**(row.value or {}), **changes}
    row.value = value
    row.updated_by = user_id
    await db.flush()
    return merged(value)


def cost_for(settings: dict[str, Any], model: str, input_tokens: int, output_tokens: int) -> Decimal:
    price = settings.get("pricing", {}).get(model)
    if not price:
        return Decimal("0")
    total = (
        Decimal(str(price.get("input_per_million", 0))) * input_tokens
        + Decimal(str(price.get("output_per_million", 0))) * output_tokens
    )
    return (total / Decimal(1_000_000)).quantize(Decimal("0.000001"))


def provider_of(settings: dict[str, Any]) -> str:
    p = str(settings.get("provider") or PROVIDER)
    return p if p in PROVIDERS else PROVIDER


def provider_label(settings: dict[str, Any]) -> str:
    return PROVIDERS[provider_of(settings)]["label"]


def base_url_of(settings: dict[str, Any]) -> str:
    return str(settings.get("base_url") or "").strip() or PROVIDERS[provider_of(settings)]["base_url"]


# ------------------------------------------------------------------ credentials


async def active_provider(db: AsyncSession) -> str:
    return provider_of(await get_settings(db))


async def credential(db: AsyncSession, provider: str | None = None) -> IntegrationCredential | None:
    return await db.get(IntegrationCredential, provider or await active_provider(db))


async def api_key(db: AsyncSession, provider: str | None = None) -> str | None:
    row = await credential(db, provider)
    if row is None or not row.credentials_enc:
        return None
    return str(json.loads(decrypt_secret(row.credentials_enc)).get("api_key") or "") or None


async def set_api_key(db: AsyncSession, key: str, provider: str | None = None) -> IntegrationCredential:
    provider = provider or await active_provider(db)
    row = await credential(db, provider)
    if row is None:
        row = IntegrationCredential(provider=provider)
        db.add(row)
    row.credentials_enc = encrypt_secret(json.dumps({"api_key": key.strip()}))
    row.status = "untested"
    row.last_error = None
    row.last_checked_at = None
    await db.flush()
    return row


# ------------------------------------------------------------------ prompts


async def active_prompt(db: AsyncSession, name: str = "content") -> AiPrompt:
    row = (
        await db.execute(select(AiPrompt).where(AiPrompt.name == name, AiPrompt.is_active.is_(True)))
    ).scalar_one_or_none()
    if row is None:
        row = AiPrompt(
            name=name,
            version=1,
            system_prompt=DEFAULT_SYSTEM_PROMPT,
            user_template=DEFAULT_USER_TEMPLATE,
            is_active=True,
            notes="Default prompt",
        )
        db.add(row)
        await db.flush()
    return row


async def new_prompt_version(
    db: AsyncSession,
    system_prompt: str,
    user_template: str,
    notes: str | None,
    user_id: Any,
    name: str = "content",
) -> AiPrompt:
    current = await active_prompt(db, name)
    await db.execute(update(AiPrompt).where(AiPrompt.name == name).values(is_active=False))
    await db.flush()
    row = AiPrompt(
        name=name,
        version=current.version + 1,
        system_prompt=system_prompt,
        user_template=user_template,
        is_active=True,
        notes=notes,
        created_by=user_id,
    )
    db.add(row)
    await db.flush()
    return row


class _Blank(dict[str, str]):
    def __missing__(self, key: str) -> str:
        return ""


def render(template: str, values: dict[str, Any]) -> str:
    """Fill {placeholders}; unknown ones become empty so an edited template can never crash a job."""
    try:
        return template.format_map(_Blank({k: str(v) for k, v in values.items()}))
    except (ValueError, IndexError):
        # A stray brace in an edited template: fall back to simple replacement.
        out = template
        for k, v in values.items():
            out = out.replace("{" + k + "}", str(v))
        return out
