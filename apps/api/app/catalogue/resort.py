"""Re-sort supplier records into the option they really are, from the data the supplier sent.

Records were first sorted on the supplier's coarse region and type: a UK Xbox key sat in "Europe", a Steam
"Altergift" in "Steam Key". The supplier normalizers now read the country, the delivery type and the devices
from the title too. This re-reads every stored record (raw_payload; nothing is fetched), moves records and
their offers to the right option (creating it under the game's SKU series), and removes options that are left
with no supplier record and no orders. Supplier data is never changed.
"""

import uuid
from dataclasses import dataclass, field

from sqlalchemy import delete, exists, select, update
from sqlalchemy.ext.asyncio import AsyncSession

from app.catalogue import sku
from app.catalogue.services import CatalogueService
from app.core.logging import log
from app.db.models import Product, ProductVariant, Supplier, SupplierOffer, SupplierProduct
from app.db.models.orders import OrderItem
from app.suppliers.base import NormalizationError
from app.suppliers.kinguin.normalizer import normalize as kinguin_normalize

NORMALIZERS = {"kinguin": kinguin_normalize}


@dataclass
class ResortStats:
    checked: int = 0
    moved: int = 0
    failed: int = 0
    removed_options: int = 0
    products: set[uuid.UUID] = field(default_factory=set)

    def as_dict(self) -> dict[str, int]:
        return {
            "checked": self.checked,
            "moved": self.moved,
            "failed": self.failed,
            "removed_options": self.removed_options,
            "games": len(self.products),
        }


async def _remove_empty_options(db: AsyncSession, product_ids: set[uuid.UUID]) -> int:
    """Options of these games that no supplier record and no order points at any more."""
    if not product_ids:
        return 0
    res = await db.execute(
        delete(ProductVariant)
        .where(
            ProductVariant.product_id.in_(product_ids),
            ~exists().where(SupplierProduct.variant_id == ProductVariant.id),
            ~exists().where(OrderItem.variant_id == ProductVariant.id),
        )
        .returning(ProductVariant.id)
    )
    return len(list(res.scalars()))


async def resort(
    db: AsyncSession,
    *,
    product_id: uuid.UUID | None = None,
    batch: int = 500,
    commit: bool = True,
) -> ResortStats:
    stats = ResortStats()
    svc = CatalogueService(db)
    for code, norm in NORMALIZERS.items():
        supplier = (await db.execute(select(Supplier).where(Supplier.code == code))).scalar_one_or_none()
        if supplier is None:
            continue
        last: uuid.UUID | None = None
        while True:
            q = (
                select(SupplierProduct)
                .where(SupplierProduct.supplier_id == supplier.id, SupplierProduct.product_id.is_not(None))
                .order_by(SupplierProduct.id)
                .limit(batch)
            )
            if product_id is not None:
                q = q.where(SupplierProduct.product_id == product_id)
            if last is not None:
                q = q.where(SupplierProduct.id > last)
            rows = list((await db.execute(q)).scalars())
            if not rows:
                break
            last = rows[-1].id
            touched: set[uuid.UUID] = set()
            for sp in rows:
                stats.checked += 1
                try:
                    item = norm(sp.raw_payload or {})
                except NormalizationError:
                    stats.failed += 1
                    continue
                product = await db.get(Product, sp.product_id)
                if product is None:
                    continue
                variant, _ = await svc._variant_for(product, item)  # same rules as an import
                if variant.id == sp.variant_id:
                    continue
                sp.variant_id = variant.id
                await db.execute(
                    update(SupplierOffer)
                    .where(SupplierOffer.supplier_product_id == sp.id)
                    .values(variant_id=variant.id)
                )
                await sku.assign_supplier_product(db, sp)
                stats.moved += 1
                touched.add(product.id)
            await db.flush()
            stats.removed_options += await _remove_empty_options(db, touched)
            stats.products |= touched
            if commit:
                await db.commit()
            if product_id is None:
                log.info("resort_batch", supplier=code, **stats.as_dict())
    return stats
