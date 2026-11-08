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

## Phase 7: WooCommerce synchronisation

- WordPress plugin **OMGGamer Catalogue Connector** (`apps/api/wp-connector`, also served at
  `/api/v1/connector/plugin.zip`). It is the secure receiving endpoint on the store:
  - HMAC-SHA256 signed requests with a 5-minute replay window, plus idempotency keys.
  - A per-product database lock, and lookup by `_omg_external_id`, so products are never duplicated.
  - Simple or variable products (variations per platform/edition/region, mapped by `_omg_variant_id`).
  - Images downloaded once each, categories created by path, tags, attributes (using the store's `pa_*`
    attributes when they exist), and Yoast / Rank Math SEO fields.
  - New products are owned by the configured Dokan vendor.
  - It never deletes products, never touches orders, and writes only the fields OMGGamer OS owns.
- OMGGamer OS publisher (`publish` queue):
  - Only approved products with approved content are sent, using stored selling prices that are
    recalculated just before sending. Products without a price are refused.
  - An unchanged payload is not sent again. Temporary errors retry with backoff (5 attempts); a bad
    signature or rejected data fails immediately.
  - Every attempt goes to `sync_logs`, the WooCommerce IDs go to `storefront_product_mappings`, and a
    watchdog re-queues stalled items.
- Publishing page: queue (retry and cancel), sync log, and per-storefront connector setup (generate the
  secret, test the connection, switch publishing on or off). Unpublish sets the store product to draft.

## Phase 6: categories, attributes and pricing

- Category tree management (configurable, cycle-safe) and category rules: supplier genre, platform, product type
  or tag → category. Rules apply when they are created, nightly and on demand, and never remove existing
  mappings. The attributes view lists every platform, edition, region, activation method, language, developer,
  publisher and genre with product counts.
- Pricing rules per storefront and scope (all products, supplier, platform, category, product) with percentage
  and fixed markup, minimum margin (percentage or amount), minimum selling price and rounding (always up).
  The most specific rule wins. Without a matching rule a variant gets no price: nothing is ever sold at cost.
- Currency conversion uses European Central Bank reference rates (fetched daily at 15:30 UTC). Manual rates
  override them. Each storefront has its own currency.
- Calculated selling prices are stored per variant and storefront from the cheapest available offer.
  Every change is written to price history. Supplier purchase prices are never modified.

## Phase 5: AI content engine

- OpenAI Chat Completions with Structured Outputs (strict JSON schema): SEO title, short and long description,
  meta title and description, tags, suggested category and image alt text. Structured product information
  (platforms, editions, regions, languages, developer...) always comes from supplier facts, never the model.
- The prompt gets supplier facts and the original description, never prices. Answers are checked against the
  facts: platforms, editions, regions, languages, system requirements or developer details the supplier did not
  provide are flagged for review, unknown categories are dropped and SEO length limits are checked.
- Results are saved as a new content version with status "pending_review". The supplier's original data is
  never changed. A product whose facts have not changed is skipped (price changes never trigger
  regeneration); "Regenerate" forces a new version.
- Jobs run in the `ai` Celery queue with a Redis limit on parallel requests, retries with backoff,
  pause and resume, and a watchdog. Auth, quota and bad-request errors stop the job with a clear message.
- AI Studio: start generation, pause and resume, retry failed, AI log, costs (tokens, cost per product,
  failed requests), settings (model, temperature, max tokens, batch size, concurrency, retries, language,
  brand voice, SEO rules, prices) and versioned prompt templates. The OpenAI key is under API credentials.

## Phase 4: product management interface

- Catalogue list with server-side search (title, Kinguin ID, product ID, SKU, developer, publisher), pagination
  (up to 200 per page) and filters for supplier, category, platform, region, edition, type, availability,
  AI status, approval and publishing. Columns: image, title, Kinguin ID, category, platform and region,
  supplier, offers, lowest available supplier price, AI, approval and publishing status, last updated.
- Bulk operations: generate or regenerate AI content, assign categories, approve, reject, publish, archive and
  retry failed jobs. Each action checks the user's role, and skipped products are reported with a reason.
- Product detail with ten sections: overview, original supplier data (including the raw payload), variants,
  offers, AI content (side by side with the original, plus version history), categories and attributes,
  media, SEO preview, publishing history and activity log.

## Phase 3: Kinguin integration and catalogue import

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
| `POST /catalogue/products/bulk` | Bulk AI, categories, approve, reject, publish, archive, retry |
| `GET /catalogue/products/{id}/content` · `/publishing` · `/activity` | Content versions, publishing history, activity log |
| `GET /ai/overview` · `PUT /ai/settings` · `GET/PUT /ai/credentials` · `GET/POST /ai/prompts` | AI Studio setup |
| `POST /ai/generate` · `/ai/pause` · `/ai/resume` · `/ai/jobs/retry-failed` · `GET /ai/jobs` · `/ai/costs` | AI generation |
| `GET/POST /pricing/rules` · `PUT/DELETE /pricing/rules/{id}` | Pricing rules |
| `GET/PUT /pricing/currencies` · `POST /pricing/currencies/refresh` · `PUT /pricing/storefronts/{code}` | Currencies |
| `GET /pricing/products/{id}` · `POST /pricing/recalculate` · `GET /pricing/history` | Prices and history |
| `GET /catalogue/attributes` · `GET/POST/DELETE /catalogue/category-rules` · `POST …/apply` | Attributes, category rules |
| `GET/PUT /storefronts/{code}/connector` · `POST …/connector/secret` · `POST …/connector/test` | Store connector |
| `GET /publishing/overview` · `/publishing/queue` · `/publishing/sync-logs` | Publishing status |
| `POST /publishing/queue/{id}/retry` · `/cancel` · `/publishing/retry-failed` · `/publishing/unpublish` | Publishing control |
| `GET /connector/plugin.zip` | The WordPress connector plugin (public, no secrets) |
| `GET /suppliers` · `GET /storefronts` | Suppliers and storefronts with counts |
| `GET/PUT /suppliers/{code}/connection` · `POST …/connection/test` · `PUT /suppliers/{code}/environment` | Encrypted API keys, connection test, live/sandbox |
| `GET /imports/overview` · `GET/POST /imports` · `GET /imports/{id}` | Import dashboard, start an import |
| `POST /imports/{id}/pause` · `/resume` · `/cancel` · `GET /imports/{id}/logs` · `/failures` | Control and inspect imports |

## Next phases

Phase 8 is price, stock and offer synchronisation. Later phases follow the OMGGamer OS master specification:
AI content, categories and pricing, WooCommerce sync with omggamer.com, price and stock sync, orders and
fulfilment, analytics and automation.
