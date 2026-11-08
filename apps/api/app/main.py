"""FastAPI application."""

import time
import uuid
from collections.abc import Awaitable, Callable
from urllib.parse import urlparse

import structlog
from fastapi import APIRouter, FastAPI, Request, Response
from fastapi.responses import JSONResponse

from app import __version__
from app.ai.router import router as ai_router
from app.catalogue.router import router as catalogue_router
from app.core.config import get_settings
from app.core.errors import install_error_handlers
from app.core.logging import configure_logging, log
from app.imports.router import router as imports_router
from app.modules.audit.router import router as audit_router
from app.modules.auth.router import router as auth_router
from app.modules.dashboard.router import router as dashboard_router
from app.modules.roles.router import router as roles_router
from app.modules.system.router import router as system_router
from app.modules.users.router import router as users_router

UNSAFE = {"POST", "PUT", "PATCH", "DELETE"}


def create_app() -> FastAPI:
    s = get_settings()
    configure_logging(s.log_level)
    app = FastAPI(
        title="OMGGamer OS API",
        version=__version__,
        docs_url="/api/docs" if s.environment != "production" else None,
        openapi_url="/api/openapi.json",
    )
    install_error_handlers(app)
    allowed_origin = s.app_origin.rstrip("/")
    allowed_host = urlparse(allowed_origin).netloc

    @app.middleware("http")
    async def request_context(
        request: Request, call_next: Callable[[Request], Awaitable[Response]]
    ) -> Response:
        rid = request.headers.get("x-request-id") or uuid.uuid4().hex[:16]
        structlog.contextvars.clear_contextvars()
        structlog.contextvars.bind_contextvars(request_id=rid)
        start = time.perf_counter()

        # CSRF defence for cookie sessions: state-changing requests must come from our own origin.
        if (
            request.method in UNSAFE
            and request.url.path.startswith("/api/v1/")
            and not request.url.path.startswith("/api/v1/webhooks/")
        ):
            origin = request.headers.get("origin") or request.headers.get("referer") or ""
            if urlparse(origin).netloc != allowed_host and not request.headers.get("authorization"):
                return JSONResponse(
                    status_code=403,
                    content={
                        "error": {
                            "code": "bad_origin",
                            "message": "Request blocked: unknown origin.",
                            "details": None,
                        }
                    },
                )

        response = await call_next(request)
        response.headers["x-request-id"] = rid
        response.headers["x-content-type-options"] = "nosniff"
        response.headers["referrer-policy"] = "strict-origin-when-cross-origin"
        response.headers["cache-control"] = response.headers.get("cache-control", "no-store")
        log.info(
            "request",
            method=request.method,
            path=request.url.path,
            status=response.status_code,
            ms=round((time.perf_counter() - start) * 1000, 1),
        )
        return response

    api = APIRouter(prefix="/api/v1")
    for r in (
        system_router,
        auth_router,
        users_router,
        roles_router,
        dashboard_router,
        audit_router,
        catalogue_router,
        imports_router,
        ai_router,
    ):
        api.include_router(r)
    app.include_router(api)
    return app


app = create_app()
