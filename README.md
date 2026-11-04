# OMGGamer OS

Central catalogue and commerce platform for OMGGamer, served at **https://os.omggamer.com**.
OMGGamer OS is the master catalogue; WooCommerce on omggamer.com is the storefront it publishes to.

| Part | Stack | Folder |
|---|---|---|
| Admin app | Next.js 15, React 19, TypeScript, Tailwind | `apps/web` |
| API | Python 3.12, FastAPI, SQLAlchemy 2 (async), Pydantic 2 | `apps/api` |
| Database | PostgreSQL 16, Alembic migrations | `apps/api/alembic` |
| Background work | Celery 5 workers + beat, Redis 7 | `apps/api/app/workers` |
| Deployment | Docker Compose on XGamer Main (xCloud) | `docker-compose.yml` |

## Phase 1 (this release)

- Sign-in with Argon2 passwords, 15-minute access tokens and rotating refresh tokens in HTTP-only cookies.
  Re-using a rotated refresh token signs out that whole session family.
- Optional TOTP two-factor authentication, login rate limiting, forced password change for new accounts.
- Seven roles (Super Admin, Administrator, Catalogue/Content/Pricing/Order Manager, Viewer) and 20 permissions,
  checked on every API endpoint. The last active Super Admin cannot be demoted or deactivated.
- Users, roles matrix and audit log screens. Every sign-in and change is written to `audit_logs`.
- Dashboard with live database counts (no sample data) and a health view for PostgreSQL, Redis, workers and scheduler.
- Core catalogue schema for later phases: suppliers, encrypted supplier connections, supplier products and offers,
  products, variants, channels, channel listings, import jobs and chunks, AI jobs and usage, publish jobs,
  webhook inbox and dead letters. Kinguin and the two OMGGamer channels (staging, live) are seeded.
- Celery tasks retry with exponential backoff; a task that still fails is written to `dead_letters`.

## Run the tests

```bash
cd apps/api
pip install -r requirements-dev.txt
export TEST_DATABASE_URL=postgresql+asyncpg://postgres@127.0.0.1:5432/omg_test   # an empty database
export TEST_REDIS_URL=redis://127.0.0.1:6379/15
pytest -q
ruff check . && ruff format --check .
SECRET_KEY=x ENCRYPTION_KEY=x mypy app
```

```bash
cd apps/web
npm ci && npm run lint && npm run typecheck && npm run build
```

## Run locally with Docker

```bash
cp .env.example .env   # fill in the secrets as described in the file
docker compose up --build
# open http://localhost:3100 (set APP_ORIGIN=http://localhost:3100 and COOKIE_SECURE=false in .env for plain HTTP)
```

The first start runs the migrations and creates the Super Admin from `BOOTSTRAP_ADMIN_EMAIL` /
`BOOTSTRAP_ADMIN_PASSWORD` if the database has no users. That person must choose a new password at first sign-in.

## Deploy (xCloud, XGamer Main)

xCloud deploys this repository as a Docker Compose site:

- Domain `os.omggamer.com`, proxied to host port **3100** (the `web` service, bound to 127.0.0.1 only).
- xCloud's nginx terminates TLS. Postgres and Redis publish no ports.
- Environment values are supplied as the site's `.env` (see `.env.example`). Keep `SECRET_KEY`, `ENCRYPTION_KEY`
  and `POSTGRES_PASSWORD` out of the repository; losing `ENCRYPTION_KEY` makes stored supplier keys unreadable.
- Nightly `pg_dump` backups are kept for 14 days in the `backups` volume.

Redeploy: push to `main`, then trigger a deploy of the site in xCloud. Migrations run automatically on start.

## API

All routes are under `/api/v1`. State-changing requests must come from the app's own origin (CSRF check).

| Route | Purpose |
|---|---|
| `GET /health` | Liveness (public) |
| `GET /system/health` | Database, Redis, worker and scheduler status |
| `POST /auth/login` · `/auth/refresh` · `/auth/logout` · `GET /auth/me` | Sessions |
| `POST /auth/password` · `/auth/2fa/setup` · `/auth/2fa/enable` · `/auth/2fa/disable` | Account security |
| `GET/POST /users` · `PATCH /users/{id}` · `POST /users/{id}/reset-password` | User management |
| `GET /roles` | Roles and permission matrix |
| `GET /dashboard/summary` · `GET /dashboard/activity` | Dashboard |
| `GET /audit-logs` | Audit trail with filters and cursor paging |

## Next phases

2. Kinguin connection and resumable catalogue import into OMGGamer OS.
3. Catalogue editor, categories and attributes, pricing rules.
4. AI Studio (OpenAI by default, provider-switchable), prompt versions, cost tracking.
5. WordPress connector plugin and WooCommerce sync with omggamer.com.
6. Orders and Kinguin fulfilment. 7. Analytics and automation.
