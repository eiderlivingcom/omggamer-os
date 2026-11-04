"""Roles and permissions. The database is seeded from these definitions by the migrations."""

from typing import Final

PERMISSIONS: Final[dict[str, str]] = {
    "dashboard:read": "View the dashboard and system health",
    "catalogue:read": "View products, variants and offers",
    "catalogue:write": "Create and edit products, variants and categories",
    "suppliers:read": "View suppliers, imports and mappings",
    "suppliers:manage": "Connect suppliers and run imports",
    "content:write": "Edit product content and run AI generation",
    "ai:manage": "Manage prompts and AI settings",
    "pricing:read": "View pricing rules and price history",
    "pricing:manage": "Change pricing rules, margins and currencies",
    "media:write": "Upload and process media",
    "publishing:approve": "Approve products for publishing",
    "publishing:manage": "Publish, unpublish and retry synchronisation",
    "orders:read": "View orders and fulfilment",
    "orders:manage": "Fulfil, resend keys and refund orders",
    "analytics:read": "View analytics",
    "automation:manage": "Manage schedules and automation",
    "users:manage": "Create users and change roles",
    "credentials:manage": "Set API credentials",
    "settings:manage": "Change application settings",
    "audit:read": "Read the audit log",
}

_ALL = list(PERMISSIONS)
_READ = [
    "dashboard:read",
    "catalogue:read",
    "suppliers:read",
    "pricing:read",
    "orders:read",
    "analytics:read",
]

ROLES: Final[dict[str, tuple[str, list[str]]]] = {
    "super_admin": ("Super Admin", _ALL),
    "administrator": (
        "Administrator",
        [p for p in _ALL if p not in ("credentials:manage",)],
    ),
    "catalogue_manager": (
        "Catalogue Manager",
        [
            *_READ,
            "catalogue:write",
            "suppliers:manage",
            "content:write",
            "media:write",
            "publishing:approve",
            "publishing:manage",
        ],
    ),
    "content_manager": ("Content Manager", [*_READ, "content:write", "ai:manage", "media:write"]),
    "pricing_manager": ("Pricing Manager", [*_READ, "pricing:manage"]),
    "order_manager": ("Order Manager", [*_READ, "orders:manage"]),
    "viewer": ("Viewer", _READ),
}

ADMIN_ROLES: Final[set[str]] = {"super_admin", "administrator"}


def permissions_for(role_key: str) -> set[str]:
    return set(ROLES.get(role_key, ("", []))[1])
