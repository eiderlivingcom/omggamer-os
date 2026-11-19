"""Image checks on product media: real type, size and dimensions, check time and error; images hidden from stores

Revision ID: 0016
Revises: 0015
Create Date: 2026-11-19
"""

from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0016"
down_revision: str | None = "0015"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.add_column("product_media", sa.Column("content_type", sa.String(60), nullable=True))
    op.add_column("product_media", sa.Column("width", sa.Integer(), nullable=True))
    op.add_column("product_media", sa.Column("height", sa.Integer(), nullable=True))
    op.add_column("product_media", sa.Column("bytes", sa.Integer(), nullable=True))
    op.add_column("product_media", sa.Column("checked_at", sa.DateTime(timezone=True), nullable=True))
    op.add_column("product_media", sa.Column("error", sa.String(300), nullable=True))
    op.add_column(
        "product_media", sa.Column("hidden", sa.Boolean(), nullable=False, server_default=sa.text("false"))
    )
    op.create_index(op.f("ix_product_media_checked_at"), "product_media", ["checked_at"])


def downgrade() -> None:
    op.drop_index(op.f("ix_product_media_checked_at"), table_name="product_media")
    for col in ("hidden", "error", "checked_at", "bytes", "height", "width", "content_type"):
        op.drop_column("product_media", col)
