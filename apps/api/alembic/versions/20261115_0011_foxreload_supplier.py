"""Foxreload supplier and a Steam app ID index for cross-supplier matching

Revision ID: 0011
Revises: 0010
Create Date: 2026-11-15
"""

from collections.abc import Sequence

from alembic import op

revision: str = "0011"
down_revision: str | None = "0010"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.execute(
        "INSERT INTO suppliers (id, code, name, integration_type, status, configuration, created_at, updated_at) "
        "VALUES (gen_random_uuid(), 'foxreload', 'Foxreload', 'api', 'active', "
        '\'{"api_base": "https://public-api.foxreload.com", "currency": "usd", "import_category": "games"}\'::jsonb, '
        "now(), now()) ON CONFLICT (code) DO NOTHING"
    )
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_product_variants_steam_app_id "
        "ON product_variants ((variant_attributes ->> 'steam_app_id')) "
        "WHERE variant_attributes ? 'steam_app_id'"
    )


def downgrade() -> None:
    op.execute("DROP INDEX IF EXISTS ix_product_variants_steam_app_id")
    op.execute(
        "DELETE FROM suppliers WHERE code = 'foxreload' AND NOT EXISTS (SELECT 1 FROM supplier_products sp WHERE sp.supplier_id = suppliers.id)"
    )
