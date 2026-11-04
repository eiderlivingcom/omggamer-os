"""Operational commands.

python -m app.cli bootstrap   Create the first Super Admin from BOOTSTRAP_ADMIN_* when no users exist.
"""

import asyncio
import sys

from sqlalchemy import func, select

from app.core.config import get_settings
from app.core.logging import configure_logging, log
from app.core.security import hash_password, validate_password_strength
from app.db.models import AuditLog, Role, User
from app.db.session import get_sessionmaker


async def bootstrap() -> int:
    s = get_settings()
    async with get_sessionmaker()() as db:
        users = int(await db.scalar(select(func.count()).select_from(User)) or 0)
        if users:
            log.info("bootstrap_skipped", reason="users already exist", users=users)
            return 0
        if not s.bootstrap_admin_email or not s.bootstrap_admin_password:
            log.warning(
                "bootstrap_skipped", reason="BOOTSTRAP_ADMIN_EMAIL / BOOTSTRAP_ADMIN_PASSWORD not set"
            )
            return 0
        pw = s.bootstrap_admin_password.get_secret_value()
        weak = validate_password_strength(pw)
        if weak:
            log.error("bootstrap_failed", reason=weak)
            return 1
        role = (await db.execute(select(Role).where(Role.key == "super_admin"))).scalar_one()
        email = s.bootstrap_admin_email.lower().strip()
        db.add(
            User(
                email=email,
                name=s.bootstrap_admin_name,
                password_hash=hash_password(pw),
                role_id=role.id,
                must_change_password=True,
            )
        )
        db.add(
            AuditLog(
                action="user.bootstrap",
                actor_email="system",
                entity_type="user",
                summary=f"Created the first Super Admin {email}",
            )
        )
        await db.commit()
        log.info("bootstrap_done", email=email)
        return 0


def main() -> int:
    configure_logging(get_settings().log_level)
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "bootstrap":
        return asyncio.run(bootstrap())
    print(__doc__)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
