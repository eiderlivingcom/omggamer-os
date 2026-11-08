"""Hand AI jobs to the `ai` worker queue. Best effort: the AI watchdog re-queues anything left behind."""

import uuid
from collections.abc import Iterable

from app.core.logging import log
from app.workers.celery_app import celery_app


def enqueue(job_ids: Iterable[uuid.UUID], countdown: float = 0) -> None:
    for job_id in job_ids:
        try:
            celery_app.send_task("ai.generate", args=[str(job_id)], queue="ai", countdown=countdown or None)
        except Exception:
            log.exception("ai_enqueue_failed", job_id=str(job_id))
