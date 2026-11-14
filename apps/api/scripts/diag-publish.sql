-- Read-only: latest publish requests and the product/mapping state behind them.
\pset pager off
\echo '== latest 12 publish requests'
SELECT q.created_at::timestamp(0), q.updated_at::timestamp(0) AS updated, q.action, q.status, q.approval_status, q.attempts,
       left(p.title, 50) AS title, p.product_status, s.code AS storefront, s.status AS sf_status,
       m.sync_status AS mapping, m.external_product_id AS wc_id, left(coalesce(q.last_error, m.last_error, ''), 160) AS error
FROM publishing_queue q
JOIN products p ON p.id = q.product_id
JOIN storefronts s ON s.id = q.storefront_id
LEFT JOIN storefront_product_mappings m ON m.product_id = q.product_id AND m.storefront_id = q.storefront_id
ORDER BY q.created_at DESC LIMIT 12;
\echo '== products updated in the last 2 days'
SELECT p.updated_at::timestamp(0), left(p.title, 50), p.product_status FROM products p ORDER BY p.updated_at DESC LIMIT 8;
\echo '== latest sync logs'
SELECT l.created_at::timestamp(0), l.operation, l.status, left(p.title, 40), left(l.response::text, 160) FROM sync_logs l LEFT JOIN products p ON p.id = l.product_id ORDER BY l.created_at DESC LIMIT 10;
\echo '== product status counts'
SELECT product_status, count(*) FROM products GROUP BY 1 ORDER BY 2 DESC;
