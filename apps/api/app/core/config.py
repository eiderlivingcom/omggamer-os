"""Application settings, read from the environment (and an optional .env file)."""

from functools import lru_cache
from typing import Literal

from pydantic import Field, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    environment: Literal["development", "test", "production"] = "production"
    app_name: str = "OMGGamer OS"
    # Public origin of the admin app, used for the CSRF origin check and cookie scope.
    app_origin: str = "https://os.omggamer.com"

    database_url: str = Field(
        default="postgresql+asyncpg://omg:omg@localhost:5432/omggamer_os",
        description="SQLAlchemy async URL (postgresql+asyncpg://...)",
    )
    redis_url: str = "redis://localhost:6379/0"

    secret_key: SecretStr = Field(description="Signs access tokens. 64+ random characters.")
    encryption_key: SecretStr = Field(description="Fernet key for supplier and API credentials at rest.")

    access_token_minutes: int = 15
    refresh_token_days: int = 7
    cookie_secure: bool = True

    login_max_attempts: int = 8
    login_window_seconds: int = 900
    require_2fa_for_admins: bool = False

    bootstrap_admin_email: str | None = None
    bootstrap_admin_password: SecretStr | None = None
    bootstrap_admin_name: str = "Super Admin"

    log_level: str = "INFO"

    @property
    def sync_database_url(self) -> str:
        """psycopg URL for Alembic and Celery (synchronous drivers)."""
        return self.database_url.replace("+asyncpg", "+psycopg")


@lru_cache
def get_settings() -> Settings:
    return Settings()
