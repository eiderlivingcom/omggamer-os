"""Celery: Redis broker, retries with backoff, dead letters, and heartbeats for the health page."""

import threading
import time
import traceback
from typing import Any

import redis as redis_sync
from celery import Celery, Task
from celery.schedules import crontab
from celery.signals import beat_init, worker_ready
from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from app.core.config import get_settings
from app.core.logging import configure_logging, log
from app.core.redis import BEAT_HEARTBEAT_KEY, WORKER_HEARTBEAT_KEY

settings = get_settings()
configure_logging(settings.log_level)

celery_app = Celery(
    "omggamer_os", broker=settings.redis_url, backend=settings.redis_url, include=["app.workers.tasks"]
)
celery_app.conf.update(
    task_acks_late=True,  # a crashed worker hands the task back instead of losing it
    task_reject_on_worker_lost=True,
    worker_prefetch_multiplier=1,
    task_default_queue="default",
    task_routes={
        "imports.*": {"queue": "imports"},
        "ai.*": {"queue": "ai"},
        "publish.*": {"queue": "publish"},
    },
    task_time_limit=60 * 30,
    task_soft_time_limit=60 * 25,
    result_expires=60 * 60 * 24,
    broker_connection_retry_on_startup=True,
    timezone="UTC",
    beat_schedule={
        "imports-watchdog": {"task": "imports.watchdog", "schedule": 120.0},
        "ai-watchdog": {"task": "ai.watchdog", "schedule": 180.0},
        # ECB publishes around 16:00 CET on working days.
        "currency-rates": {"task": "pricing.refresh_rates", "schedule": crontab(minute=30, hour=15)},
        "category-rules": {"task": "catalogue.apply_category_rules", "schedule": crontab(minute=15, hour=3)},
    },
)

_sync_engine = None


def sync_engine() -> Any:
    global _sync_engine
    if _sync_engine is None:
        _sync_engine = create_engine(settings.sync_database_url, pool_pre_ping=True)
    return _sync_engine


class ReliableTask(Task):  # type: ignore[misc]
    """Base task: exponential backoff on any error; after the last retry the call lands in dead_letters."""

    autoretry_for = (Exception,)
    retry_backoff = True
    retry_backoff_max = 600
    retry_jitter = True
    max_retries = 5

    def on_failure(self, exc: BaseException, task_id: str, args: Any, kwargs: Any, einfo: Any) -> None:
        from app.db.models import DeadLetter

        try:
            with Session(sync_engine()) as s:
                s.add(
                    DeadLetter(
                        task_name=self.name,
                        task_id=task_id,
                        args={"args": list(args or []), "kwargs": dict(kwargs or {})},
                        error="".join(traceback.format_exception(exc))[-8000:],
                        attempts=int(self.request.retries or 0) + 1,
                    )
                )
                s.commit()
        except Exception:
            log.exception("dead_letter_write_failed", task=self.name, task_id=task_id)
        log.error("task_failed", task=self.name, task_id=task_id, error=str(exc))


def _beat(key: str) -> None:
    client = redis_sync.Redis.from_url(settings.redis_url)
    while True:
        try:
            client.set(key, str(time.time()), ex=600)
        except Exception:
            log.warning("heartbeat_failed", key=key)
        time.sleep(20)


@worker_ready.connect  # type: ignore[misc]
def _worker_ready(**_: Any) -> None:
    threading.Thread(target=_beat, args=(WORKER_HEARTBEAT_KEY,), daemon=True).start()


@beat_init.connect  # type: ignore[misc]
def _beat_init(**_: Any) -> None:
    threading.Thread(target=_beat, args=(BEAT_HEARTBEAT_KEY,), daemon=True).start()
