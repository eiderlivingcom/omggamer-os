"""Store a supplier API key (encrypted, live environment) and test it. The key comes from the SUPPLIER_KEY
environment variable, never from the command line or the repository.

    SUPPLIER_KEY=... docker compose exec -T -e SUPPLIER_KEY api python - foxreload < apps/api/scripts/set_supplier_key.py
"""

import asyncio
import json
import os
import sys
from datetime import UTC, datetime

from sqlalchemy import select

from app.core.security import encrypt_secret
from app.db.models import AuditLog, Supplier, SupplierConnection
from app.db.session import get_sessionmaker
from app.suppliers import registry


async def main(code: str) -> None:
    key = (os.environ.get("SUPPLIER_KEY") or "").strip()
    if len(key) < 8:
        print("SUPPLIER_KEY is not set")
        return
    async with get_sessionmaker()() as db:
        s = (await db.execute(select(Supplier).where(Supplier.code == code))).scalar_one()
        conn = await registry.connection_for(db, s, "live")
        if conn is None:
            conn = SupplierConnection(supplier_id=s.id, environment="live")
            db.add(conn)
        conn.credentials_enc = encrypt_secret(json.dumps({"api_key": key}))
        adapter = registry.build_adapter(s, "live", {"api_key": key})
        try:
            info = await adapter.test_connection()
            conn.status, conn.last_error = "connected", None
            print(f"{s.name}: connected ({info.get('account') or info})")
        except Exception as exc:  # noqa: BLE001 - report any failure, key is kept for a later retry
            conn.status, conn.last_error = "failed", str(exc)[:2000]
            print(f"{s.name}: saved, but the test failed: {exc}")
        finally:
            await adapter.aclose()
        conn.last_checked_at = datetime.now(UTC)
        db.add(AuditLog(action="credentials.updated", entity_type="supplier", entity_id=str(s.id),
                        summary=f"Stored the {s.name} live API key (server script)"))
        await db.commit()


asyncio.run(main(sys.argv[1] if len(sys.argv) > 1 else "foxreload"))
