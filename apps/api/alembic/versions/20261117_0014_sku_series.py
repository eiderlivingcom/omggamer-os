"""OMGGamer SKU series: game codes (OMG-G004218), variant codes (…-STM-EU-STD) and supplier listing codes (…-KG)

Numbers existing products in the order they were created, re-codes every variant and supplier listing, and
keeps each variant's previous SKU in variant_attributes.legacy_sku.

Revision ID: 0014
Revises: 0013
Create Date: 2026-11-17
"""

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op
from app.catalogue.sku import SEQUENCE, SUPPLIER_CODES, TYPE_LETTERS, variant_base

revision: str = "0014"
down_revision: str | None = "0013"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.execute(f"CREATE SEQUENCE IF NOT EXISTS {SEQUENCE} START 1")
    op.add_column("products", sa.Column("sku", sa.String(24), nullable=True))
    op.create_unique_constraint(op.f("uq_products_sku"), "products", ["sku"])
    op.add_column("supplier_products", sa.Column("sku", sa.String(180), nullable=True))
    op.create_index(op.f("ix_supplier_products_sku"), "supplier_products", ["sku"])

    bind = op.get_bind()
    letters = " ".join(f"WHEN '{t}' THEN '{c}'" for t, c in TYPE_LETTERS.items())
    bind.execute(
        sa.text(
            f"""
            WITH o AS (SELECT id, row_number() OVER (ORDER BY created_at, id) AS rn FROM products)
            UPDATE products p
               SET sku = 'OMG-' || CASE p.product_type {letters} ELSE 'G' END || lpad(o.rn::text, 6, '0')
              FROM o WHERE p.id = o.id
            """  # noqa: S608 - built from constants in app.catalogue.sku
        )
    )
    bind.execute(
        sa.text(f"SELECT setval('{SEQUENCE}', GREATEST((SELECT count(*) FROM products), 1))")  # noqa: S608
    )

    rows = bind.execute(
        sa.text(
            """
            SELECT v.id, p.sku, v.platform, v.region, v.edition
              FROM product_variants v JOIN products p ON p.id = v.product_id
             ORDER BY p.sku, v.created_at, v.id
            """
        )
    ).all()
    taken: set[str] = set()
    values = []
    for vid, product_sku, platform, region, edition in rows:
        base = variant_base(product_sku, platform, region, edition)
        candidate, n = base, 1
        while candidate in taken:
            n += 1
            candidate = f"{base}-{n}"
        taken.add(candidate)
        values.append({"id": vid, "sku": candidate})
    bind.execute(
        sa.text("CREATE TEMP TABLE omg_new_skus (id uuid PRIMARY KEY, sku text NOT NULL) ON COMMIT DROP")
    )
    for i in range(0, len(values), 2000):
        bind.execute(sa.text("INSERT INTO omg_new_skus (id, sku) VALUES (:id, :sku)"), values[i : i + 2000])
    bind.execute(
        sa.text(
            """
            UPDATE product_variants v
               SET variant_attributes = v.variant_attributes || jsonb_build_object('legacy_sku', v.sku),
                   sku = n.sku
              FROM omg_new_skus n WHERE v.id = n.id
            """
        )
    )
    suppliers = " ".join(f"WHEN '{c}' THEN '{k}'" for c, k in SUPPLIER_CODES.items())
    bind.execute(
        sa.text(
            f"""
            UPDATE supplier_products sp
               SET sku = v.sku || '-' || CASE s.code {suppliers} ELSE upper(left(s.code, 2)) END
              FROM product_variants v, suppliers s
             WHERE v.id = sp.variant_id AND s.id = sp.supplier_id
            """  # noqa: S608 - built from constants in app.catalogue.sku
        )
    )


def downgrade() -> None:
    op.execute(
        "UPDATE product_variants SET sku = variant_attributes->>'legacy_sku', "
        "variant_attributes = variant_attributes - 'legacy_sku' WHERE variant_attributes ? 'legacy_sku'"
    )
    op.drop_index(op.f("ix_supplier_products_sku"), table_name="supplier_products")
    op.drop_column("supplier_products", "sku")
    op.drop_constraint(op.f("uq_products_sku"), "products", type_="unique")
    op.drop_column("products", "sku")
    op.execute(f"DROP SEQUENCE IF EXISTS {SEQUENCE}")
