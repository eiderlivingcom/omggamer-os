-- Keep one waiting publish job per game and storefront; cancel the extra copies.
WITH keep AS (
  SELECT DISTINCT ON (product_id, storefront_id) id FROM publishing_queue
   WHERE status IN ('queued', 'running') ORDER BY product_id, storefront_id, (status = 'running') DESC, created_at
)
UPDATE publishing_queue SET status = 'cancelled', last_error = 'Duplicate of another queued job', updated_at = now()
 WHERE status = 'queued' AND id NOT IN (SELECT id FROM keep) RETURNING id;
SELECT status, count(*) FROM publishing_queue WHERE updated_at > now() - interval '30 minutes' GROUP BY 1;
