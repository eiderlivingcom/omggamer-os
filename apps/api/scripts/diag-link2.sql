\echo '== failed price syncs'
select created_at, left(response::text, 400) from sync_logs where operation = 'price' and status <> 'success' order by created_at desc limit 5;
\echo '== queued publishing items'
select q.created_at, q.updated_at, q.attempts, q.approval_status, p.canonical_title, left(q.last_error, 160) from publishing_queue q join products p on p.id = q.product_id where q.status in ('queued','running') order by q.created_at;
\echo '== automation / sync settings'
select key, left(value::text, 300) from app_settings where key in ('sync', 'automation', 'orders');
