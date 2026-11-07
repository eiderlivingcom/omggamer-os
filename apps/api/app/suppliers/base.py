"""The contract every supplier adapter implements. The import runner only talks to this interface."""

from dataclasses import dataclass, field
from datetime import datetime
from typing import Any, Protocol

from app.catalogue.schemas import NormalizedProduct


class SupplierError(Exception):
    """Base class. `temporary` errors are retried later; the others stop the job with a clear message."""

    temporary = False

    def __init__(self, message: str, *, status: int | None = None, detail: Any = None) -> None:
        super().__init__(message)
        self.status = status
        self.detail = detail


class SupplierAuthError(SupplierError):
    """The API key is missing, wrong or revoked."""


class SupplierRequestError(SupplierError):
    """The supplier rejected the request (4xx other than auth/404/429). Retrying will not help."""


class SupplierNotFound(SupplierError):
    """The product does not exist or is no longer available."""


class SupplierTemporaryError(SupplierError):
    """Rate limited, timed out, 5xx or unreadable, and still failing after the client's own retries."""

    temporary = True


class NormalizationError(Exception):
    """A supplier record could not be turned into a NormalizedProduct. Saved as a failed record."""


@dataclass
class Page:
    records: list[dict[str, Any]]
    total: int | None  # total records matching the query, when the supplier reports it
    raw_meta: dict[str, Any] = field(default_factory=dict)  # response fields other than the records


class SupplierAdapter(Protocol):
    code: str
    max_page_size: int

    @property
    def requests_made(self) -> int: ...

    async def test_connection(self) -> dict[str, Any]: ...

    async def fetch_page(self, page: int, page_size: int, updated_since: datetime | None = None) -> Page: ...

    async def fetch_one(self, external_id: str) -> dict[str, Any]: ...

    def external_id(self, record: dict[str, Any]) -> str | None: ...

    def normalize(self, record: dict[str, Any]) -> NormalizedProduct: ...

    async def aclose(self) -> None: ...
