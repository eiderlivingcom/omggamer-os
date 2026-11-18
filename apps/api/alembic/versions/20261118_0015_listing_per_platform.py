"""One store listing per platform: storefront_product_mappings.listing ("" = the game's main listing)

Revision ID: 0015
Revises: 0014
Create Date: 2026-11-18
"""

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0015"
down_revision: str | None = "0014"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.add_column(
        "storefront_product_mappings",
        sa.Column("listing", sa.String(60), nullable=False, server_default=""),
    )
    op.drop_constraint(
        op.f("uq_storefront_product_mappings_storefront_id_product_id"), "storefront_product_mappings"
    )
    op.create_unique_constraint(
        op.f("uq_storefront_product_mappings_storefront_id_product_id_listing"),
        "storefront_product_mappings",
        ["storefront_id", "product_id", "listing"],
    )


def downgrade() -> None:
    op.execute("DELETE FROM storefront_product_mappings WHERE listing <> ''")
    op.drop_constraint(
        op.f("uq_storefront_product_mappings_storefront_id_product_id_listing"), "storefront_product_mappings"
    )
    op.create_unique_constraint(
        op.f("uq_storefront_product_mappings_storefront_id_product_id"),
        "storefront_product_mappings",
        ["storefront_id", "product_id"],
    )
    op.drop_column("storefront_product_mappings", "listing")
