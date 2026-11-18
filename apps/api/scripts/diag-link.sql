-- OS <-> store health since go-live (read-only).
\echo '== storefronts'
select code, domain, status, configuration->>'connector_url' url from storefronts;
\echo '== sync_logs last 24h by operation/status'
select operation, status, count(*), max(created_at) from sync_logs where created_at > now() - interval '24 hours' group by 1,2 order by 4 desc;
\echo '== latest failed sync responses'
select created_at, operation, left(response::text, 220) from sync_logs where status <> 'success' order by created_at desc limit 6;
\echo '== publishing queue last 24h'
select status, count(*), max(updated_at) from publishing_queue where updated_at > now() - interval '24 hours' group by 1;
select updated_at, left(last_error, 200) from publishing_queue where status = 'failed' order by updated_at desc limit 6;
\echo '== orders'
select created_at, external_order_id, status, store_status from orders order by created_at desc limit 5;
\echo '== webhook inbox last 24h'
select source, event, count(*), max(created_at) from webhook_inbox where created_at > now() - interval '24 hours' group by 1,2;
