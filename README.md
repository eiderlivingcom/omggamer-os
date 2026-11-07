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

## Phase 3 (this release): Kinguin integration and catalogue import

- Separate Kinguin adapter (`app/suppliers/kinguin`): API client, normaliser, and a contract the import runner
  uses for every supplier. Endpoints, page size (100) and auth (`X-Api-Key`) follow the official Kinguin docs.
- The API key is entered under Settings → API credentials. It is encrypted at rest, never returned and tested
  against `GET /v1/regions`. Production and sandbox keys are kept separately.
- Imports run in Celery (`imports` queue) in 10-minute chunks. Every page is saved with a checkpoint in the same
  transaction, so a crashed worker loses at most one page and resumes on its own. A watchdog re-queues stalled
  jobs every 2 minutes, and a per-job advisory lock stops two workers running the same job.
- 429s honour `Retry-After` (Kinguin documents no rate limit, so requests are also spaced 250 ms apart).
  Timeouts and 5xx back off exponentially. A bad key stops the job with a clear message.
- Records that cannot be saved go to `import_failures` with their original payload, for "Retry failed records".
- Import types: test batches (100, 1,000, 10,000), full, update (`updatedSince`), single product and retry
  failed. A full import queues an automatic catch-up for products changed while it ran.
- The Suppliers page is the import dashboard: live metrics, Start, Pause, Resume, Cancel, Retry failed records,
  the page-by-page log and the failed records.
- Nothing is published to WooCommerce and no AI content is generated during import.

## Phase 2: central catalogue engine

- Three separate concepts: a **master product** (one game), its **variants** (platform, edition, region,
  activation type, language) and **supplier offers** (a seller's price and stock for one variant). One supplier
  record is never assumed to be one master product.
- `supplier_products` keeps the supplier's original title, description and full raw payload. Edits made in
  OMGGamer OS go on `products` and `product_content` and are never overwritten by a re-import.
- Ingest is idempotent: the database upserts on supplier + external ID, and an unchanged payload checksum is a no-op.
  Offers missing from a complete refresh are marked unavailable rather than deleted.
- Parallel importers take a transaction-level advisory lock on the title match key, so they never create duplicate
  master products. Wrongly split products can be merged; the old record keeps a pointer to the survivor.
- Category tree (cycles refused), supplier-genre → category mapping, media, versioned content (one current version
  per locale), import jobs (one active full import per supplier), AI jobs, publishing queue, storefronts,
  storefront product mappings and sync logs.
- Migration `0002` replaces the Phase 1 placeholder tables. It refuses to run if any of them hold data.

## Phase 1

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
| `GET /catalogue/products` | Search (title, developer, publisher, SKU, supplier ID) and filters: type, status, platform, region, supplier, category, availability |
| `GET/PATCH /catalogue/products/{id}` | Product detail with variants, offers, supplier records, media, categories; edit facts |
| `POST /catalogue/products/{id}/merge` · `PUT /catalogue/products/{id}/categories` | Merge duplicates, set categories |
| `GET /catalogue/supplier-products/{id}` | Original supplier data, including the raw payload |
| `GET/POST /catalogue/categories` · `PATCH/DELETE /catalogue/categories/{id}` | Category tree |
| `GET /suppliers` · `GET /storefronts` | Suppliers and storefronts with counts |
| `GET/PUT /suppliers/{code}/connection` · `POST …/connection/test` · `PUT /suppliers/{code}/environment` | Encrypted API keys, connection test, live/sandbox |
| `GET /imports/overview` · `GET/POST /imports` · `GET /imports/{id}` | Import dashboard, start an import |
| `POST /imports/{id}/pause` · `/resume` · `/cancel` · `GET /imports/{id}/logs` · `/failures` | Control and inspect imports |

## Next phases

Phase 4 is the product management interface. Later phases follow the OMGGamer OS master specification:
AI content, categories and pricing, WooCommerce sync with omggamer.com, price and stock sync, orders and
fulfilment, analytics and automation.
