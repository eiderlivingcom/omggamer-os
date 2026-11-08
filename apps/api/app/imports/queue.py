"""Hand an import job to the background workers. Kept separate so the API never imports worker code."""

import uuid

from app.core.logging import log
from app.workers.celery_app import celery_app


def enqueue(job_id: uuid.UUID, countdown: float = 0) -> None:
    """Best effort: if the broker is unreachable the job stays queued and the watchdog picks it up later."""
    try:
        celery_app.send_task("imports.run", args=[str(job_id)], queue="imports", countdown=countdown or None)
    except Exception:
        log.exception("import_enqueue_failed", job_id=str(job_id))
