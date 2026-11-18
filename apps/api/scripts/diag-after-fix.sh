#!/bin/sh
# Queue and job health after the Redis fix (read-only).
cd /var/www/os.omggamer.com || exit 1
sh apps/api/scripts/diag-redis.sh 2>&1 | head -6
docker compose exec -T postgres psql -U omgos -d omggamer_os -tA -c "select 'sync '||operation||' '||status||' '||count(*) from sync_logs where created_at > now() - interval '20 minutes' group by operation,status" -c "select 'ai '||status||' '||count(*) from ai_jobs group by status" -c "select 'publish '||status||' '||count(*) from publishing_queue where updated_at > now() - interval '20 minutes' group by status" -c "select 'sync last_run '||(value->>'last_run_at') from app_settings where key='sync'"
docker compose logs --since 10m worker 2>&1 | grep -ciE 'heartbeat_failed' | sed 's/^/heartbeat failures (10 min): /'
