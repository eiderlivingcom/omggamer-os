"""Cross-supplier matching: product aliases and the match review queue

Revision ID: 0012
Revises: 0011
Create Date: 2026-11-15
"""

from collections.abc import Sequence

import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

from alembic import op

revision: str = "0012"
down_revision: str | None = "0011"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


def upgrade() -> None:
    op.create_table(
        "product_aliases",
        sa.Column(
            "id", postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text("gen_random_uuid()")
        ),
        sa.Column(
            "product_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("products.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column("alias", sa.String(300), nullable=False),
        sa.Column("alias_key", sa.String(300), nullable=False),
        sa.Column("product_type", sa.String(30), nullable=False),
        sa.Column("source", sa.String(20), nullable=False, server_default="manual"),
        sa.Column(
            "created_by", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL")
        ),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.UniqueConstraint(
            "alias_key", "product_type", name=op.f("uq_product_aliases_alias_key_product_type")
        ),
    )
    op.create_index("ix_product_aliases_product_id", "product_aliases", ["product_id"])
    op.create_table(
        "match_suggestions",
        sa.Column(
            "id", postgresql.UUID(as_uuid=True), primary_key=True, server_default=sa.text("gen_random_uuid()")
        ),
        sa.Column(
            "product_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("products.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "candidate_id",
            postgresql.UUID(as_uuid=True),
            sa.ForeignKey("products.id", ondelete="CASCADE"),
            nullable=False,
        ),
        sa.Column(
            "supplier_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("suppliers.id", ondelete="SET NULL")
        ),
        sa.Column("score", sa.Numeric(4, 3), nullable=False),
        sa.Column("reasons", postgresql.JSONB(), nullable=False, server_default="[]"),
        sa.Column("status", sa.String(20), nullable=False, server_default="pending"),
        sa.Column(
            "decided_by", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL")
        ),
        sa.Column("decided_at", sa.DateTime(timezone=True)),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("updated_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.UniqueConstraint(
            "product_id", "candidate_id", name=op.f("uq_match_suggestions_product_id_candidate_id")
        ),
        sa.CheckConstraint(
            "status IN ('pending', 'accepted', 'rejected', 'superseded')",
            name=op.f("ck_match_suggestions_status_valid"),
        ),
        sa.CheckConstraint(
            "product_id <> candidate_id", name=op.f("ck_match_suggestions_different_products")
        ),
    )
    op.create_index("ix_match_suggestions_product_id", "match_suggestions", ["product_id"])
    op.create_index("ix_match_suggestions_candidate_id", "match_suggestions", ["candidate_id"])
    op.create_index("ix_match_suggestions_status", "match_suggestions", ["status"])
    # Fuzzy title search for match suggestions runs on the normalised title.
    op.execute(
        "CREATE INDEX IF NOT EXISTS ix_products_match_key_trgm ON products USING gin (match_key gin_trgm_ops)"
    )


def downgrade() -> None:
    op.execute("DROP INDEX IF EXISTS ix_products_match_key_trgm")
    op.drop_table("match_suggestions")
    op.drop_table("product_aliases")
