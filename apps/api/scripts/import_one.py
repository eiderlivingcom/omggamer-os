"""Import one supplier product into the catalogue and report where it landed.

    docker compose exec -T api python - foxreload [external_id] < apps/api/scripts/import_one.py

Without an id, picks a Foxreload Steam product whose Steam app ID, platform and region already exist in OS
(so the cross-supplier match can be checked). Writes one supplier product + its offer; nothing is published.
"""

import asyncio
import sys

from sqlalchemy import select

from app.catalogue.services import CatalogueService
from app.db.models import AuditLog, Product, ProductVariant, Supplier, SupplierOffer, SupplierProduct
from app.db.session import get_sessionmaker
from app.suppliers import registry


async def pick(db, adapter) -> dict | None:  # type: ignore[no-untyped-def,type-arg]
    rows = (
        await db.execute(
            select(Product.canonical_title, ProductVariant.variant_attributes["steam_app_id"].astext)
            .join(ProductVariant, ProductVariant.product_id == Product.id)
            .where(
                ProductVariant.platform == "Steam",
                ProductVariant.region == "Global",
                ProductVariant.edition.is_(None),
                ProductVariant.variant_attributes.has_key("steam_app_id"),
            )
            .order_by(Product.created_at)
            .limit(40)
        )
    ).all()
    for title, steam in rows:
        body = await adapter.client._send(
            "GET", "/api/products/search", params={"query": title[:60], "limit": 10}
        )
        found = body if isinstance(body, list) else (body or {}).get("items") or []
        for p in found:
            a = p.get("attributes") or {}
            if (
                str(a.get("steam_app_id")) == str(steam)
                and str(a.get("platform", "")).lower() == "steam"
                and a.get("region") == "WW"
                and p.get("deliveryType") == "code"
                and p.get("price")
            ):
                print(
                    f"Picked: {p['name']} (Foxreload {p['id']}) — matches OS '{title}' by Steam app {steam}"
                )
                return p
    return None


async def main(code: str, ext: str | None) -> None:
    async with get_sessionmaker()() as db:
        s = (await db.execute(select(Supplier).where(Supplier.code == code))).scalar_one()
        adapter = await registry.adapter_for(db, s)
        try:
            record = await adapter.fetch_one(ext) if ext else await pick(db, adapter)
        finally:
            await adapter.aclose()
        if not record:
            print("No matching product found to import.")
            return
        item = adapter.normalize(record)
        res = await CatalogueService(db).ingest(s, item)
        db.add(
            AuditLog(
                action="import.single",
                entity_type="supplier_product",
                entity_id=str(res.supplier_product_id),
                summary=f"Imported one {s.name} product: {item.original_title}",
            )
        )
        await db.commit()
        p = await db.get(Product, res.product_id)
        v = await db.get(ProductVariant, res.variant_id)
        print(
            f"Result: {res.status}; product {'NEW' if res.product_created else 'EXISTING'}: "
            f"{p.canonical_title} "
            f"[{p.product_status}] id={p.id}"
        )
        print(
            f"Variant {'NEW' if res.variant_created else 'EXISTING'}: {v.sku} | {v.platform} | "
            f"{v.edition or '-'} | "
            f"{v.region} | {v.activation_type}"
        )
        offers = (
            await db.execute(
                select(SupplierOffer, Supplier.name)
                .join(SupplierProduct, SupplierProduct.id == SupplierOffer.supplier_product_id)
                .join(Supplier, Supplier.id == SupplierProduct.supplier_id)
                .where(SupplierOffer.variant_id == v.id)
                .order_by(SupplierOffer.supplier_price)
            )
        ).all()
        print(f"Offers on this variant ({len(offers)}):")
        for o, name in offers[:8]:
            print(
                f"  {name}: {o.supplier_price} {o.currency}, qty {o.available_quantity}, {o.availability_status}"
            )


asyncio.run(
    main(sys.argv[1] if len(sys.argv) > 1 else "foxreload", sys.argv[2] if len(sys.argv) > 2 else None)
)
