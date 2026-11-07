import uuid
from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field

Environment = Literal["live", "sandbox"]


class ConnectionOut(BaseModel):
    environment: Environment
    configured: bool
    status: str
    last_checked_at: datetime | None
    last_error: str | None


class SupplierConnectionsOut(BaseModel):
    supplier: str
    name: str
    active_environment: Environment
    connections: list[ConnectionOut]


class ConnectionIn(BaseModel):
    environment: Environment = "live"
    api_key: str = Field(min_length=8, max_length=500)


class EnvironmentIn(BaseModel):
    environment: Environment


class ConnectionTestIn(BaseModel):
    environment: Environment | None = None


class ConnectionTestOut(BaseModel):
    ok: bool
    environment: Environment
    message: str


class ImportStartIn(BaseModel):
    supplier: str = "kinguin"
    job_type: Literal["full", "incremental", "single", "retry_failed"] = "full"
    # Controlled test runs: stop after this many records (100, 1,000, ...).
    max_records: int | None = Field(default=None, ge=1, le=1_000_000)
    page_size: int | None = Field(default=None, ge=1, le=100)
    updated_since: datetime | None = None
    external_id: str | None = Field(default=None, max_length=80)


class ImportJobOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    supplier_id: uuid.UUID
    job_type: str
    status: str
    params: dict[str, Any]
    total_records: int | None
    processed_records: int
    created_records: int
    updated_records: int
    skipped_records: int
    failed_records: int
    saved_records: int = 0
    api_requests: int
    current_page: int | None = None
    checkpoint: dict[str, Any]
    last_checkpoint_at: datetime | None = None
    last_response_at: datetime | None
    started_at: datetime | None
    completed_at: datetime | None
    duration_seconds: int | None = None
    last_error: str | None
    created_at: datetime
    updated_at: datetime


class SupplierTotals(BaseModel):
    supplier_products: int
    variants: int
    offers: int
    offers_in_stock: int
    unresolved_failures: int


class ImportOverview(BaseModel):
    supplier: str
    name: str
    active_environment: Environment
    connection_status: str
    active_job: ImportJobOut | None
    recent_jobs: list[ImportJobOut]
    totals: SupplierTotals


class ImportLogOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int
    level: str
    message: str
    data: dict[str, Any]
    created_at: datetime


class ImportFailureOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    import_job_id: uuid.UUID
    external_product_id: str
    error: str
    attempts: int
    resolved_at: datetime | None
    created_at: datetime
    updated_at: datetime
