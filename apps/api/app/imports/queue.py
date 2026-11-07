"""Hand an import job to the background workers. Kept separate so the API never imports worker code."""

import uuid

from app.workers.celery_app import celery_app


def enqueue(job_id: uuid.UUID, countdown: float = 0) -> None:
    celery_app.send_task("imports.run", args=[str(job_id)], queue="imports", countdown=countdown or None)
