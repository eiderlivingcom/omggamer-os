"""Automation settings, test notification, and adding suppliers and storefronts."""

import re
from typing import Any, Literal

import httpx
from fastapi import APIRouter, Request
from pydantic import BaseModel, Field
from sqlalchemy import select

from app.automation import service
from app.core.deps import DB, require
from app.core.errors import AppError
from app.db.models import Storefront, Supplier, User
from app.modules.audit.service import record
from app.suppliers import registry

router = APIRouter(tags=["automation"])
STOREFRONT_PLATFORMS = {"woocommerce": "OMGGamer Catalogue Connector (WordPress plugin)"}


class FullImportIn(BaseModel):
    enabled: bool | None = None
    weekday: int | None = Field(default=None, ge=0, le=6)
    hour: int | None = Field(default=None, ge=0, le=23)


class AiDailyIn(BaseModel):
    enabled: bool | None = None
    hour: int | None = Field(default=None, ge=0, le=23)
    limit: int | None = Field(default=None, ge=1, le=5000)


class AutoApproveIn(BaseModel):
    enabled: bool | None = None
    product_types: list[str] | None = Field(default=None, max_length=10)


class AutoRetryIn(BaseModel):
    enabled: bool | None = None
    max: int | None = Field(default=None, ge=1, le=10)


class NotifyIn(BaseModel):
    enabled: bool | None = None
    webhook_url: str | None = Field(default=None, max_length=500)


class AutomationIn(BaseModel):
    full_import: FullImportIn | None = None
    ai_daily: AiDailyIn | None = None
    auto_approve: AutoApproveIn | None = None
    auto_retry: AutoRetryIn | None = None
    notify: NotifyIn | None = None


def _public(s: dict[str, Any]) -> dict[str, Any]:
    out = {k: v for k, v in s.items() if k != "state"}
    state = s.get("state") or {}
    out["last_runs"] = {
        k: v for k, v in state.items() if k in ("full_import", "ai_daily", "auto_retry", "notify_checked")
    }
    return out


@router.get("/automation/settings", response_model=dict[str, Any])
async def get_settings(db: DB, _: User = require("automation:manage")) -> dict[str, Any]:
    return _public(await service.get_settings(db))


@router.put("/automation/settings", response_model=dict[str, Any])
async def put_settings(
    body: AutomationIn, request: Request, db: DB, actor: User = require("automation:manage")
) -> dict[str, Any]:
    changes = {k: v for k, v in body.model_dump(exclude_none=True).items() if v}
    url = (changes.get("notify") or {}).get("webhook_url")
    if url and not url.startswith("https://"):
        raise AppError(422, "validation_error", "The webhook URL must start with https://")
    s = await service.save_settings(db, changes, actor.id)
    await record(
        db,
        "automation.settings",
        actor=actor,
        summary="Changed automation settings",
        data={k: v for k, v in changes.items() if k != "notify"},
        request=request,
    )
    await db.commit()
    return _public(s)


@router.post("/automation/test-notification", response_model=dict[str, Any])
async def test_notification(db: DB, _: User = require("automation:manage")) -> dict[str, Any]:
    s = await service.get_settings(db)
    url = str(s["notify"].get("webhook_url") or "")
    if not url:
        raise AppError(409, "not_configured", "Set a webhook URL first.")
    try:
        async with httpx.AsyncClient(timeout=10, transport=service.transport_override) as client:
            text = "OMGGamer OS test notification: alerts are working."
            r = await client.post(url, json={"text": text, "content": text})
            r.raise_for_status()
    except httpx.HTTPError as exc:
        return {"ok": False, "message": str(exc)[:300]}
    return {"ok": True, "message": "Sent."}


# ------------------------------------------------------------------ suppliers and storefronts


class SupplierIn(BaseModel):
    name: str = Field(min_length=2, max_length=120)
    code: str = Field(min_length=2, max_length=40)
    integration_type: Literal["api", "feed", "manual"] = "api"


class StorefrontIn(BaseModel):
    name: str = Field(min_length=2, max_length=120)
    code: str = Field(min_length=2, max_length=40)
    domain: str = Field(min_length=4, max_length=255)
    platform: Literal["woocommerce"] = "woocommerce"
    currency: str = Field(default="EUR", min_length=3, max_length=3)


def _code(v: str) -> str:
    v = v.strip().lower()
    if not re.fullmatch(r"[a-z0-9][a-z0-9-]{1,39}", v):
        raise AppError(422, "validation_error", "Codes use lowercase letters, numbers and dashes.")
    return v


@router.get("/suppliers/adapters", response_model=list[dict[str, Any]])
async def adapters(_: User = require("suppliers:read")) -> list[dict[str, Any]]:
    return [{"code": code, "available": True} for code in sorted(registry.ADAPTERS)]


@router.post("/suppliers", response_model=dict[str, Any], status_code=201)
async def add_supplier(
    body: SupplierIn, request: Request, db: DB, actor: User = require("credentials:manage")
) -> dict[str, Any]:
    code = _code(body.code)
    if await db.scalar(select(Supplier.id).where(Supplier.code == code)):
        raise AppError(409, "duplicate", "A supplier with this code exists.")
    has_adapter = code in registry.ADAPTERS
    s = Supplier(
        name=body.name.strip(),
        code=code,
        integration_type=body.integration_type,
        status="active" if has_adapter else "disabled",
        configuration={},
    )
    db.add(s)
    await record(
        db,
        "supplier.created",
        actor=actor,
        entity_type="supplier",
        summary=f"Added supplier {s.name}",
        request=request,
    )
    await db.commit()
    return {
        "id": str(s.id),
        "code": s.code,
        "name": s.name,
        "status": s.status,
        "adapter": has_adapter,
        "note": None
        if has_adapter
        else "No adapter is registered for this code yet; it stays disabled until one is added.",
    }


@router.get("/storefronts/platforms", response_model=dict[str, str])
async def platforms(_: User = require("catalogue:read")) -> dict[str, str]:
    return STOREFRONT_PLATFORMS


@router.post("/storefronts", response_model=dict[str, Any], status_code=201)
async def add_storefront(
    body: StorefrontIn, request: Request, db: DB, actor: User = require("credentials:manage")
) -> dict[str, Any]:
    code = _code(body.code)
    if await db.scalar(select(Storefront.id).where(Storefront.code == code)):
        raise AppError(409, "duplicate", "A storefront with this code exists.")
    domain = body.domain.strip().removeprefix("https://").removeprefix("http://").strip("/")
    sf = Storefront(
        name=body.name.strip(),
        code=code,
        domain=domain,
        platform=body.platform,
        status="disabled",
        configuration={"currency": body.currency.upper()},
    )
    db.add(sf)
    await record(
        db,
        "storefront.created",
        actor=actor,
        entity_type="storefront",
        summary=f"Added storefront {sf.name}",
        request=request,
    )
    await db.commit()
    return {"id": str(sf.id), "code": sf.code, "name": sf.name, "domain": sf.domain, "status": sf.status}
