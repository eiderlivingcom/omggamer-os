"""AI Studio: settings, latest job errors, and one live test request to the provider (prints no keys).

docker compose exec -T api python - < apps/api/scripts/check_ai_provider.py
"""

import asyncio
import time

from sqlalchemy import select

from app.ai import settings as ai_settings
from app.ai.openai_client import OpenAIClient
from app.db.models import AiJob
from app.db.session import get_sessionmaker


async def main() -> None:
    async with get_sessionmaker()() as db:
        s = await ai_settings.get_settings(db)
        print(
            "provider",
            ai_settings.provider_of(s),
            "| model",
            s["model"],
            "| base",
            ai_settings.base_url_of(s),
        )
        print(
            "paused",
            s.get("paused"),
            "| cooldown_until",
            s.get("cooldown_until"),
            "| concurrency",
            s["concurrency"],
        )
        print("reason", (s.get("cooldown_reason") or "")[:200])
        rows = (
            await db.execute(
                select(AiJob.status, AiJob.attempts, AiJob.updated_at, AiJob.error_message)
                .where(AiJob.error_message.is_not(None))
                .order_by(AiJob.updated_at.desc())
                .limit(4)
            )
        ).all()
        for r in rows:
            print("job", r.status, r.attempts, r.updated_at.isoformat()[:19], (r.error_message or "")[:200])
        key = await ai_settings.api_key(db)
    client = OpenAIClient(
        key or "",
        base_url=ai_settings.base_url_of(s),
        label="provider",
        json_mode="json_object",
        timeout=110,
        max_attempts=1,
    )
    t = time.time()
    try:
        out = await client.structured(
            model=str(s["model"]),
            system="Reply with JSON.",
            user='Return {"ok": true}',
            schema_name="t",
            schema={"type": "object", "properties": {"ok": {"type": "boolean"}}, "required": ["ok"]},
            max_output_tokens=50,
            temperature=None,
        )
        print(f"live test OK in {time.time() - t:.1f}s, model {out.model}")
    except Exception as exc:
        print(f"live test FAILED after {time.time() - t:.1f}s: {type(exc).__name__}: {str(exc)[:300]}")
    finally:
        await client.aclose()


asyncio.run(main())
