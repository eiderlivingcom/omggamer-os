\pset pager off
SELECT status, count(*) FROM publishing_queue WHERE updated_at > now() - interval '2 hours' GROUP BY 1;
SELECT left(p.canonical_title, 40) AS game, left(q.last_error, 160) AS error FROM publishing_queue q JOIN products p ON p.id = q.product_id
 WHERE q.status = 'failed' AND q.updated_at > now() - interval '2 hours' ORDER BY q.updated_at DESC LIMIT 10;
SELECT count(*) AS listings, count(DISTINCT product_id) AS games FROM storefront_product_mappings WHERE sync_status = 'synced';
SELECT count(*) AS skipped_notes FROM publishing_queue WHERE status = 'completed' AND last_error IS NOT NULL AND updated_at > now() - interval '2 hours';
