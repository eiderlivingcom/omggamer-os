"""guaranteed-profit pricing rules

Revision ID: 0010
Revises: 0009
Create Date: 2026-11-13
"""

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0010"
down_revision: str | None = "0009"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

RULE_COLS = [
    ("target_profit_percent", sa.Numeric(8, 3)),
    ("target_profit_fixed", sa.Numeric(12, 4)),
    ("gateway_fee_percent", sa.Numeric(8, 3)),
    ("gateway_fee_fixed", sa.Numeric(12, 4)),
    ("sale_percent", sa.Numeric(8, 3)),
    ("coupon_percent", sa.Numeric(8, 3)),
    ("pro_percent", sa.Numeric(8, 3)),
]


def upgrade() -> None:
    op.add_column("pricing_rules", sa.Column("mode", sa.String(20), nullable=False, server_default="markup"))
    for name, typ in RULE_COLS:
        op.add_column("pricing_rules", sa.Column(name, typ, nullable=True))
    op.create_check_constraint(
        op.f("ck_pricing_rules_mode_valid"), "pricing_rules", "mode IN ('markup', 'guaranteed')"
    )
    for name in ("sale_price", "floor_price", "pushed_sale_price"):
        op.add_column("variant_prices", sa.Column(name, sa.Numeric(12, 2), nullable=True))


def downgrade() -> None:
    for name in ("pushed_sale_price", "floor_price", "sale_price"):
        op.drop_column("variant_prices", name)
    op.drop_constraint(op.f("ck_pricing_rules_mode_valid"), "pricing_rules", type_="check")
    for name, _ in reversed(RULE_COLS):
        op.drop_column("pricing_rules", name)
    op.drop_column("pricing_rules", "mode")
