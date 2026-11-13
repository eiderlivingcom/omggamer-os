-- Read-only: why WooCommerce sync / publishing fails. Run: psql -f diag-sync.sql
\pset pager off
\echo '== storefronts'
SELECT code, domain, status, credentials_enc IS NOT NULL AS has_creds, configuration FROM storefronts;
\echo '== publishing_queue by status'
SELECT status, approval_status, count(*), max(updated_at) AS last FROM publishing_queue GROUP BY 1,2 ORDER BY 1,2;
\echo '== publishing_queue top errors'
SELECT left(last_error, 300) AS error, count(*), max(updated_at) AS last, max(attempts) AS max_attempts FROM publishing_queue WHERE last_error IS NOT NULL GROUP BY 1 ORDER BY 2 DESC LIMIT 12;
\echo '== mappings by sync_status'
SELECT sync_status, count(*), max(last_synced_at) AS last_ok FROM storefront_product_mappings GROUP BY 1;
\echo '== mapping top errors'
SELECT left(last_error, 300) AS error, count(*), max(updated_at) AS last FROM storefront_product_mappings WHERE last_error IS NOT NULL GROUP BY 1 ORDER BY 2 DESC LIMIT 12;
\echo '== sync_logs last 7 days'
SELECT operation, status, count(*), max(created_at) AS last FROM sync_logs WHERE created_at > now() - interval '7 days' GROUP BY 1,2 ORDER BY 1,2;
\echo '== sync_logs failed: top responses'
SELECT operation, left(response::text, 400) AS response, count(*), max(created_at) AS last FROM sync_logs WHERE status = 'failed' GROUP BY 1,2 ORDER BY 3 DESC LIMIT 12;
\echo '== latest 8 sync_logs'
SELECT created_at, operation, status, left(response::text, 250) FROM sync_logs ORDER BY created_at DESC LIMIT 8;
\echo '== app_settings (sync related keys)'
SELECT key, left(value::text, 300) FROM app_settings WHERE key ILIKE ANY (ARRAY['%sync%','%publish%','%automation%']);
\echo '== audit: storefront / publishing changes'
SELECT created_at, actor_email, action, left(summary, 160), left(data::text, 200) FROM audit_logs WHERE entity_type ILIKE '%storefront%' OR action ILIKE '%storefront%' OR action ILIKE '%publish%' ORDER BY created_at DESC LIMIT 15;
\echo '== failed item product'
SELECT q.created_at, q.action, p.title FROM publishing_queue q JOIN products p ON p.id = q.product_id WHERE q.status = 'failed';
