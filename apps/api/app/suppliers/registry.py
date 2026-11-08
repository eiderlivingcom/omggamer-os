"""Build the adapter for a supplier from its stored (encrypted) connection."""

import json
from collections.abc import Callable
from typing import Any

import httpx
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

from app.core.security import decrypt_secret
from app.db.models import Supplier, SupplierConnection
from app.suppliers.base import SupplierAdapter, SupplierAuthError
from app.suppliers.kinguin.adapter import KinguinAdapter

ENVIRONMENTS = ("live", "sandbox")
# Tests point adapters at an httpx.MockTransport; production leaves this None (real network).
transport_override: httpx.AsyncBaseTransport | None = None


def active_environment(supplier: Supplier) -> str:
    env = (supplier.configuration or {}).get("active_environment", "live")
    return env if env in ENVIRONMENTS else "live"


async def connection_for(db: AsyncSession, supplier: Supplier, environment: str) -> SupplierConnection | None:
    return (
        await db.execute(
            select(SupplierConnection).where(
                SupplierConnection.supplier_id == supplier.id, SupplierConnection.environment == environment
            )
        )
    ).scalar_one_or_none()


def decrypt_credentials(conn: SupplierConnection | None) -> dict[str, Any]:
    if conn is None or not conn.credentials_enc:
        return {}
    return dict(json.loads(decrypt_secret(conn.credentials_enc)))


# Adapter factory per supplier code: (configuration, environment, credentials) -> adapter.
# Adding a supplier = writing its adapter (see SupplierAdapterBase) and registering it here.
ADAPTERS: dict[str, Callable[[dict[str, Any], str, dict[str, Any]], SupplierAdapter]] = {
    "kinguin": lambda cfg, env, creds: KinguinAdapter.build(
        cfg, env, str(creds.get("api_key") or ""), transport_override
    ),
}


def build_adapter(supplier: Supplier, environment: str, credentials: dict[str, Any]) -> SupplierAdapter:
    factory = ADAPTERS.get(supplier.code)
    if factory is None:
        raise SupplierAuthError(f"No adapter is available for supplier '{supplier.code}' yet.")
    return factory(supplier.configuration or {}, environment, credentials)


async def adapter_for(
    db: AsyncSession, supplier: Supplier, environment: str | None = None
) -> SupplierAdapter:
    env = environment or active_environment(supplier)
    creds = decrypt_credentials(await connection_for(db, supplier, env))
    if not creds:
        raise SupplierAuthError(
            f"No {supplier.name} API key is stored for the {env} environment. "
            "Add it under Settings → API credentials."
        )
    return build_adapter(supplier, env, creds)
