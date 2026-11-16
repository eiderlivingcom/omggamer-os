"""Product type "game_currency" (game cash: V-Bucks, Robux, FC Points, Shark Cards...) and reclassify products

Revision ID: 0013
Revises: 0012
Create Date: 2026-11-16
"""

import re
from collections.abc import Sequence

import sqlalchemy as sa

from alembic import op

revision: str = "0013"
down_revision: str | None = "0012"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

OLD = ("game", "dlc", "software", "gift_card", "game_account", "subscription", "hardware", "accessory")
NEW = (
    "game",
    "dlc",
    "software",
    "gift_card",
    "game_currency",
    "game_account",
    "subscription",
    "hardware",
    "accessory",
)

# Same rule as app.catalogue.normalize.is_game_currency at the time of this migration.
NAMED = re.compile(
    r"\b(v-?bucks|robux|minecoins|primogems|genesis crystals|shark\s+(?:cash\s+)?card|virtual currency"
    r"|in-?game currency|game cash|gta\s?\$)",
    re.IGNORECASE,
)
AMOUNT = re.compile(
    r"\b\d[\d,.]*\s*(?:k\s+)?(?:\+\s*\d[\d,.]*\s*)?(?:bonus\s+)?"
    r"(?:fc |fifa |apex |valorant |riot |cod |nba |vc |r6 |ultimate team |call of duty |g-)?"
    r"(?:points|coins|diamonds|gems|crystals|credits|uc|vp|rp|cp|tokens|crowns|platinum|shards|bucks|gold|silver|atoms)\b"
    r"(?!\s+(?:edition|pack\s+edition|ultimate))",
    re.IGNORECASE,
)
ACCOUNT = re.compile(r"\baccount\b", re.IGNORECASE)


def _check(values: tuple[str, ...]) -> str:
    return "product_type IN (" + ", ".join(f"'{v}'" for v in values) + ")"


def upgrade() -> None:
    op.drop_constraint(op.f("ck_products_product_type_valid"), "products", type_="check")
    op.create_check_constraint(op.f("ck_products_product_type_valid"), "products", _check(NEW))
    conn = op.get_bind()
    rows = conn.execute(
        sa.text(
            "SELECT p.id, sp.original_title, coalesce(sp.raw_payload->'tags', '[]'::jsonb)::text AS tags "
            "FROM products p JOIN supplier_products sp ON sp.product_id = p.id "
            "WHERE p.product_type IN ('game', 'gift_card') AND p.product_status <> 'merged'"
        )
    ).fetchall()
    hits: set[str] = set()
    for pid, title, tags in rows:
        t = title or ""
        if ACCOUNT.search(t) or '"dlc"' in tags or '"software"' in tags:
            continue
        if NAMED.search(t) or AMOUNT.search(t):
            hits.add(str(pid))
    ids = list(hits)
    for i in range(0, len(ids), 1000):
        conn.execute(
            sa.text(
                "UPDATE products SET product_type = 'game_currency', updated_at = now() WHERE id = ANY(CAST(:ids AS uuid[]))"
            ),
            {"ids": ids[i : i + 1000]},
        )


def downgrade() -> None:
    op.execute("UPDATE products SET product_type = 'gift_card' WHERE product_type = 'game_currency'")
    op.drop_constraint(op.f("ck_products_product_type_valid"), "products", type_="check")
    op.create_check_constraint(op.f("ck_products_product_type_valid"), "products", _check(OLD))
