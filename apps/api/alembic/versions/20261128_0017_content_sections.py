"""Product content sections for the store page (edition includes, activation steps, system requirements, region
warning, delivery note, product pills, Metacritic) and the Rank Math focus keyword

Revision ID: 0017
Revises: 0016
Create Date: 2026-11-28
"""

from collections.abc import Sequence

import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

from alembic import op

revision: str = "0017"
down_revision: str | None = "0016"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.add_column("product_content", sa.Column("focus_keyword", sa.String(120), nullable=True))
    op.add_column(
        "product_content",
        sa.Column("sections", postgresql.JSONB(), nullable=False, server_default=sa.text("'{}'::jsonb")),
    )


def downgrade() -> None:
    op.drop_column("product_content", "sections")
    op.drop_column("product_content", "focus_keyword")
