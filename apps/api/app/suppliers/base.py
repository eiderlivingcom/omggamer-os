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

    async def purchase(self, lines: list[dict[str, Any]], reference: str) -> dict[str, Any]: ...

    async def find_order(self, reference: str) -> dict[str, Any] | None: ...

    async def order_status(self, order_id: str) -> dict[str, Any]: ...

    async def download_keys(self, order_id: str) -> list[dict[str, Any]]: ...

    async def return_keys(self, order_id: str) -> list[dict[str, Any]]: ...

    async def aclose(self) -> None: ...


class SupplierAdapterBase:
    """Common supplier interface (master specification, Phase 10). A new supplier is one subclass plus one
    entry in app.suppliers.registry.ADAPTERS; the catalogue, pricing, publishing and order code never change.

    Subclasses implement the low-level methods (test_connection, fetch_page, fetch_one, external_id,
    normalize, purchase, find_order, order_status, download_keys, return_keys, aclose). The methods named in
    the specification are provided on top of them.
    """

    code: str = ""
    max_page_size: int = 100

    # --- specification names -------------------------------------------------------------------
    async def authenticate(self) -> dict[str, Any]:
        return await self.test_connection()  # type: ignore[attr-defined, no-any-return]

    async def fetch_products(self, page: int, page_size: int, updated_since: datetime | None = None) -> Page:
        return await self.fetch_page(page, page_size, updated_since)  # type: ignore[attr-defined, no-any-return]

    async def fetch_product_details(self, external_id: str) -> dict[str, Any]:
        return await self.fetch_one(external_id)  # type: ignore[attr-defined, no-any-return]

    async def fetch_offers(self, external_id: str) -> list[Any]:
        return list(self.normalize_product(await self.fetch_product_details(external_id)).offers)

    def normalize_product(self, record: dict[str, Any]) -> NormalizedProduct:
        return self.normalize(record)  # type: ignore[attr-defined, no-any-return]

    def normalize_offer(self, record: dict[str, Any], offer_id: str) -> Any:
        return next(
            (o for o in self.normalize_product(record).offers if o.external_offer_id == offer_id), None
        )

    async def check_availability(self, external_id: str, quantity: int = 1) -> bool:
        try:
            offers = await self.fetch_offers(external_id)
        except SupplierNotFound:
            return False
        return any(o.availability in ("in_stock", "preorder") and o.quantity >= quantity for o in offers)

    async def purchase_offer(self, lines: list[dict[str, Any]], reference: str) -> dict[str, Any]:
        return await self.purchase(lines, reference)  # type: ignore[attr-defined, no-any-return]
