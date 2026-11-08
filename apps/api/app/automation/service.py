"""Automation rules (Automation page), checked every 5 minutes by `automation.tick`.

* full_import:   weekly full Kinguin import (weekday 0 = Monday, UTC hour).
* ai_daily:      once a day, queue AI content for up to `limit` products that have none.
* auto_approve:  AI content with no review flags is approved automatically (optionally only for some
                 product types). Publishing stays manual.
* auto_retry:    failed AI jobs and publishing items get one automatic retry per hour, a limited number of
                 times. Orders are not retried automatically: they need a person to look at them.
* notify:        failures are posted to a webhook (Slack/Discord/any JSON endpoint), at most once per event.
Price and stock schedules live in the sync settings (Phase 8).
"""

import hashlib
import json
from datetime import UTC, datetime, timedelta
from typing import Any

import httpx
from sqlalchemy import func, select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.logging import log
from app.db.models import AppSetting, DeadLetter, ImportJob, Order, PublishingQueueItem

DEFAULTS: dict[str, Any] = {
    "full_import": {"enabled": False, "weekday": 0, "hour": 3},
    "ai_daily": {"enabled": False, "hour": 2, "limit": 200},
    "auto_approve": {"enabled": False, "product_types": []},  # empty = all types
    "auto_retry": {"enabled": True, "max": 3},
    "notify": {"enabled": False, "webhook_url": ""},
}
transport_override: httpx.AsyncBaseTransport | None = None


def now() -> datetime:
    return datetime.now(UTC)


async def get_settings(db: AsyncSession) -> dict[str, Any]:
    row = await db.get(AppSetting, "automation")
    value = row.value if row else {}
    return {k: {**v, **(value.get(k) or {})} for k, v in DEFAULTS.items()} | {
        k: v for k, v in value.items() if k not in DEFAULTS
    }


async def save_settings(db: AsyncSession, changes: dict[str, Any], user_id: Any = None) -> dict[str, Any]:
    row = await db.get(AppSetting, "automation")
    if row is None:
        row = AppSetting(key="automation", value={})
        db.add(row)
    value = dict(row.value or {})
    for k, v in changes.items():
        value[k] = {**(value.get(k) or {}), **v} if isinstance(v, dict) else v
    row.value = value
    row.updated_by = user_id
    await db.flush()
    return await get_settings(db)


def should_auto_approve(settings: dict[str, Any], product_type: str, flags: list[str]) -> bool:
    rule = settings["auto_approve"]
    if not rule.get("enabled") or flags:
        return False
    types = rule.get("product_types") or []
    return not types or product_type in types


def _due_daily(last: str | None, hour: int, t: datetime) -> bool:
    return t.hour == hour and (last or "")[:10] != t.date().isoformat()


def _due_weekly(last: str | None, weekday: int, hour: int, t: datetime) -> bool:
    return t.weekday() == weekday and t.hour == hour and (last or "")[:10] != t.date().isoformat()


async def due_jobs(db: AsyncSession, t: datetime | None = None) -> list[str]:
    """Work that is due now; marks it as started so it runs once per window."""
    t = t or now()
    s = await get_settings(db)
    state = s.get("state") or {}
    due: list[str] = []
    if s["full_import"]["enabled"] and _due_weekly(
        state.get("full_import"), int(s["full_import"]["weekday"]), int(s["full_import"]["hour"]), t
    ):
        due.append("full_import")
    if s["ai_daily"]["enabled"] and _due_daily(state.get("ai_daily"), int(s["ai_daily"]["hour"]), t):
        due.append("ai_daily")
    if s["auto_retry"]["enabled"] and (
        not state.get("auto_retry") or t - datetime.fromisoformat(state["auto_retry"]) >= timedelta(hours=1)
    ):
        due.append("auto_retry")
    if s["notify"]["enabled"] and s["notify"].get("webhook_url"):
        due.append("notify")
    if due:
        await save_settings(db, {"state": {**state, **{k: t.isoformat() for k in due}}})
    return due


# ------------------------------------------------------------------ notifications


async def collect_alerts(db: AsyncSession, since: datetime) -> list[dict[str, str]]:
    alerts: list[dict[str, str]] = []
    for job in (
        await db.execute(select(ImportJob).where(ImportJob.status == "failed", ImportJob.updated_at >= since))
    ).scalars():
        alerts.append(
            {"key": f"import:{job.id}", "text": f"Import failed: {job.last_error or 'see the import log'}"}
        )
    for o in (
        await db.execute(
            select(Order).where(
                Order.status.in_(("needs_attention", "partially_fulfilled")), Order.updated_at >= since
            )
        )
    ).scalars():
        alerts.append(
            {
                "key": f"order:{o.id}:{o.status}",
                "text": f"Order #{o.order_number} needs attention: {o.last_error or ''}"[:300],
            }
        )
    failed_pub = int(
        await db.scalar(
            select(func.count())
            .select_from(PublishingQueueItem)
            .where(PublishingQueueItem.status == "failed", PublishingQueueItem.updated_at >= since)
        )
        or 0
    )
    if failed_pub:
        alerts.append(
            {
                "key": f"publish:{since.date()}:{failed_pub}",
                "text": f"{failed_pub} product(s) failed to publish.",
            }
        )
    for d in (
        await db.execute(
            select(DeadLetter).where(DeadLetter.created_at >= since, DeadLetter.resolved_at.is_(None))
        )
    ).scalars():
        alerts.append(
            {"key": f"dead:{d.id}", "text": f"Background task {d.task_name} failed after all retries."}
        )
    return alerts


async def send_alerts(db: AsyncSession) -> int:
    s = await get_settings(db)
    url = str(s["notify"].get("webhook_url") or "")
    if not url.startswith("https://"):
        return 0
    state = s.get("state") or {}
    since = (
        datetime.fromisoformat(state["notify_checked"])
        if state.get("notify_checked")
        else now() - timedelta(hours=1)
    )
    sent_keys: list[str] = list(state.get("notified") or [])[-500:]
    alerts = [
        a
        for a in await collect_alerts(db, since - timedelta(minutes=1))
        if hashlib.sha256(a["key"].encode()).hexdigest()[:16] not in sent_keys
    ]
    sent = 0
    if alerts:
        text = "OMGGamer OS:\n" + "\n".join(f"• {a['text']}" for a in alerts[:20])
        try:
            async with httpx.AsyncClient(timeout=10, transport=transport_override) as client:
                r = await client.post(
                    url, json={"text": text, "content": text[:1900]}
                )  # Slack uses text, Discord content
                r.raise_for_status()
            sent = len(alerts)
            sent_keys += [hashlib.sha256(a["key"].encode()).hexdigest()[:16] for a in alerts]
        except httpx.HTTPError as exc:
            log.warning("notify_failed", error=str(exc))
    await save_settings(
        db, {"state": {**state, "notify_checked": now().isoformat(), "notified": sent_keys[-500:]}}
    )
    return sent


def describe(settings: dict[str, Any]) -> str:
    return json.dumps({k: v for k, v in settings.items() if k != "state"})
