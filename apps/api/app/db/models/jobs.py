"""Background work: imports, AI jobs, the publishing queue, incoming webhooks and dead letters."""

import uuid
from datetime import datetime
from decimal import Decimal
from typing import Any

from sqlalchemy import (
    CheckConstraint,
    DateTime,
    ForeignKey,
    Index,
    Integer,
    Numeric,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, Timestamps, UUIDPk

JOB_STATUSES = ("queued", "running", "paused", "completed", "failed", "cancelled")
ACTIVE_JOB_STATUSES = ("queued", "running", "paused")
IMPORT_JOB_TYPES = ("full", "incremental", "single", "retry_failed")
APPROVAL_STATUSES = ("pending", "approved", "rejected")


def _in(col: str, values: tuple[str, ...]) -> str:
    return f"{col} IN ({', '.join(repr(v) for v in values)})"


class ImportJob(UUIDPk, Timestamps, Base):
    __tablename__ = "import_jobs"
    __table_args__ = (
        CheckConstraint(_in("status", JOB_STATUSES), name="status_valid"),
        CheckConstraint(_in("job_type", IMPORT_JOB_TYPES), name="job_type_valid"),
        # At most one active full import per supplier: a second "Start import" is refused by the database.
        Index(
            "uq_import_jobs_one_active_full",
            "supplier_id",
            unique=True,
            postgresql_where="job_type = 'full' AND status IN ('queued', 'running', 'paused')",
        ),
    )
    supplier_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("suppliers.id", ondelete="RESTRICT"), nullable=False, index=True
    )
    job_type: Mapped[str] = mapped_column(String(20), nullable=False, default="full")
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="queued", index=True)
    params: Mapped[dict[str, Any]] = mapped_column(JSONB, nullable=False, default=dict, server_default="{}")
    total_records: Mapped[int | None] = mapped_column(Integer)
    processed_records: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    created_records: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    updated_records: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    skipped_records: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    failed_records: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    api_requests: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    checkpoint: Mapped[dict[str, Any]] = mapped_column(
        JSONB, nullable=False, default=dict, server_default="{}"
    )
    last_response_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    started_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    completed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    last_error: Mapped[str | None] = mapped_column(Text)
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))


class ImportFailure(UUIDPk, Timestamps, Base):
    """A record an import could not save. Kept for 'Retry failed records'."""

    __tablename__ = "import_failures"
    __table_args__ = (UniqueConstraint("import_job_id", "external_product_id"),)
    import_job_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("import_jobs.id", ondelete="CASCADE"), nullable=False, index=True
    )
    external_product_id: Mapped[str] = mapped_column(String(80), nullable=False)
    payload: Mapped[dict[str, Any]] = mapped_column(JSONB, nullable=False, default=dict, server_default="{}")
    error: Mapped[str] = mapped_column(Text, nullable=False)
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=1)
    resolved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))


class AiJob(UUIDPk, Timestamps, Base):
    __tablename__ = "ai_jobs"
    __table_args__ = (CheckConstraint(_in("status", JOB_STATUSES), name="status_valid"),)
    product_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("products.id", ondelete="CASCADE"), index=True
    )
    task: Mapped[str] = mapped_column(String(40), nullable=False, default="content")
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="queued", index=True)
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    model: Mapped[str | None] = mapped_column(String(80))
    prompt_version: Mapped[str | None] = mapped_column(String(40))
    token_usage: Mapped[dict[str, Any]] = mapped_column(
        JSONB, nullable=False, default=dict, server_default="{}"
    )  # {"input": n, "output": n}
    estimated_cost: Mapped[Decimal] = mapped_column(Numeric(12, 6), nullable=False, default=Decimal("0"))
    error_message: Mapped[str | None] = mapped_column(Text)
    completed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))


class PublishingQueueItem(UUIDPk, Timestamps, Base):
    __tablename__ = "publishing_queue"
    __table_args__ = (
        CheckConstraint(_in("status", JOB_STATUSES), name="status_valid"),
        CheckConstraint(_in("approval_status", APPROVAL_STATUSES), name="approval_status_valid"),
    )
    product_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey("products.id", ondelete="CASCADE"), nullable=False, index=True
    )
    storefront_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey("storefronts.id", ondelete="CASCADE"), index=True
    )
    action: Mapped[str] = mapped_column(String(20), nullable=False, default="upsert")  # upsert | unpublish
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="queued", index=True)
    approval_status: Mapped[str] = mapped_column(String(20), nullable=False, default="pending", index=True)
    approved_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
    approved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    last_error: Mapped[str | None] = mapped_column(Text)


class WebhookInbox(UUIDPk, Base):
    """Incoming webhooks are stored first, then processed idempotently."""

    __tablename__ = "webhook_inbox"
    __table_args__ = (UniqueConstraint("source", "dedupe_key"),)
    source: Mapped[str] = mapped_column(String(40), nullable=False)
    event: Mapped[str] = mapped_column(String(80), nullable=False)
    dedupe_key: Mapped[str] = mapped_column(String(120), nullable=False)
    payload: Mapped[dict[str, Any]] = mapped_column(JSONB, nullable=False)
    state: Mapped[str] = mapped_column(String(20), nullable=False, default="received", index=True)
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    last_error: Mapped[str | None] = mapped_column(Text)
    received_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
    processed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))


class DeadLetter(UUIDPk, Base):
    """Work that failed after all retries. Visible in the UI and retryable by hand."""

    __tablename__ = "dead_letters"
    task_name: Mapped[str] = mapped_column(String(200), nullable=False, index=True)
    task_id: Mapped[str | None] = mapped_column(String(80))
    args: Mapped[dict[str, Any]] = mapped_column(JSONB, nullable=False, default=dict, server_default="{}")
    error: Mapped[str] = mapped_column(Text, nullable=False)
    attempts: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    resolved_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now()
    )
