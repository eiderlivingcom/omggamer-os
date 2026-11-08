"""Application settings, third-party credentials and versioned AI prompt templates."""

import uuid
from datetime import datetime
from typing import Any

from sqlalchemy import Boolean, DateTime, ForeignKey, Index, Integer, String, Text, UniqueConstraint, func
from sqlalchemy.dialects.postgresql import JSONB
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base, Timestamps, UUIDPk


class AppSetting(Base):
    """One JSON document per settings area ("ai", "pricing", "sync", ...)."""

    __tablename__ = "app_settings"
    key: Mapped[str] = mapped_column(String(60), primary_key=True)
    value: Mapped[dict[str, Any]] = mapped_column(JSONB, nullable=False, default=dict, server_default="{}")
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), nullable=False, server_default=func.now(), onupdate=func.now()
    )
    updated_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))


class IntegrationCredential(Timestamps, Base):
    """API keys for services other than suppliers and storefronts (OpenAI...). Encrypted, never returned."""

    __tablename__ = "integration_credentials"
    provider: Mapped[str] = mapped_column(String(40), primary_key=True)
    credentials_enc: Mapped[str | None] = mapped_column(Text)
    status: Mapped[str] = mapped_column(String(20), nullable=False, default="untested")
    last_checked_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    last_error: Mapped[str | None] = mapped_column(Text)


class AiPrompt(UUIDPk, Timestamps, Base):
    """A prompt template version. Exactly one version per name is active."""

    __tablename__ = "ai_prompts"
    __table_args__ = (
        UniqueConstraint("name", "version"),
        Index("uq_ai_prompts_one_active", "name", unique=True, postgresql_where="is_active"),
    )
    name: Mapped[str] = mapped_column(String(60), nullable=False, default="content")
    version: Mapped[int] = mapped_column(Integer, nullable=False, default=1)
    system_prompt: Mapped[str] = mapped_column(Text, nullable=False)
    user_template: Mapped[str] = mapped_column(Text, nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    notes: Mapped[str | None] = mapped_column(Text)
    created_by: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("users.id", ondelete="SET NULL"))
