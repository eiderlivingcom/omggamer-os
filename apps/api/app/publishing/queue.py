"""Hand publishing queue items to the `publish` worker queue. The publishing watchdog re-queues stragglers."""

import uuid
from collections.abc import Iterable

from app.core.logging import log
from app.workers.celery_app import celery_app


def enqueue(item_ids: Iterable[uuid.UUID], countdown: float = 0) -> None:
    for item_id in item_ids:
        try:
            celery_app.send_task(
                "publish.run", args=[str(item_id)], queue="publish", countdown=countdown or None
            )
        except Exception:
            log.exception("publish_enqueue_failed", item_id=str(item_id))
