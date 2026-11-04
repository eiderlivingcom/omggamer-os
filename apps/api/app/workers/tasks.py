"""Task modules register here. Phase 2 adds imports.*, Phase 4 ai.*, Phase 5 publish.*."""

import time

from app.workers.celery_app import ReliableTask, celery_app


@celery_app.task(base=ReliableTask, name="system.ping")  # type: ignore[misc]
def ping() -> dict[str, float]:
    """Round-trip check used by the health page and deploy smoke test."""
    return {"pong": time.time()}
