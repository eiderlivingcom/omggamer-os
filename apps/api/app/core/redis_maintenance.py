"""Keep the queue Redis healthy: memory use, and clearing what is safe to clear.

Safe to remove at any time:
- task results (celery-task-meta-*): nothing reads them;
- the AI queue: AI jobs live in Postgres and the AI watchdog re-dispatches queued ones a few at a time.
Imports, publishing, price sync and orders queues are never touched.
"""

from typing import Any

from redis import Redis

QUEUES = ("default", "imports", "ai", "publish")
AUTO_CLEAR_PCT = 80.0


def status(r: Redis) -> dict[str, Any]:
    info = r.info("memory")
    used, peak = int(info.get("used_memory", 0)), int(info.get("maxmemory", 0))
    return {
        "used_mb": round(used / 1048576, 1),
        "max_mb": round(peak / 1048576, 1) if peak else None,
        "used_pct": round(used * 100 / peak, 1) if peak else None,
        "keys": int(r.dbsize()),
        "queues": {q: int(r.llen(q)) for q in QUEUES},
    }


def clear(r: Redis, *, ai_queue: bool) -> dict[str, int]:
    """Remove task results, and the AI queue when asked. Returns how many of each were removed."""
    results = 0
    batch: list[Any] = []
    for key in r.scan_iter(match="celery-task-meta-*", count=5000):
        batch.append(key)
        if len(batch) >= 5000:
            results += int(r.unlink(*batch))
            batch = []
    if batch:
        results += int(r.unlink(*batch))
    ai = 0
    if ai_queue:
        ai = int(r.llen("ai"))
        r.unlink("ai")
    return {"task_results": results, "ai_messages": ai}


def auto_clean(r: Redis) -> dict[str, Any]:
    """Hourly: always drop task results; empty the AI queue only when Redis is over 80% full."""
    before = status(r)
    full = before["used_pct"] is not None and before["used_pct"] >= AUTO_CLEAR_PCT
    removed = clear(r, ai_queue=full)
    return {"before": before, "removed": removed, "after": status(r)}
