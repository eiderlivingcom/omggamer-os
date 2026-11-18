"""Redis clean-up: task results always go; the AI queue only when asked or when Redis is nearly full."""

from redis import Redis

from app.core.config import get_settings
from app.core.redis_maintenance import auto_clean, clear, status


def test_clear_and_auto_clean() -> None:
    r = Redis.from_url(get_settings().redis_url, decode_responses=True)
    try:
        for i in range(30):
            r.set(f"celery-task-meta-{i}", "x")
        r.rpush("ai", *[f"m{i}" for i in range(5)])
        r.rpush("publish", "keep")
        out = auto_clean(r)  # far below 80%: AI queue kept
        assert out["removed"]["task_results"] == 30 and out["removed"]["ai_messages"] == 0
        assert r.llen("ai") == 5 and r.llen("publish") == 1
        assert clear(r, ai_queue=True)["ai_messages"] == 5 and r.llen("ai") == 0
        assert r.llen("publish") == 1  # other queues are never touched
        assert set(status(r)["queues"]) == {"default", "imports", "ai", "publish"}
    finally:
        r.delete("publish", "ai")
        r.close()
