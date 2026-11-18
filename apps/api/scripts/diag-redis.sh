#!/bin/sh
# Redis health for the OS queue (read-only apart from one probe key).
cd /var/www/os.omggamer.com || exit 1
R="docker compose exec -T redis redis-cli"
$R info memory | grep -E 'used_memory_human|maxmemory_human|maxmemory_policy'
echo "probe: $($R set omg:probe 1 ex 60)"
for q in default imports ai publish; do echo "queue $q: $($R llen $q)"; done
echo "keys: $($R dbsize)"
$R info persistence | grep -E 'rdb_last_bgsave_status|rdb_changes_since|aof_enabled'
$R info stats | grep -E 'rejected_connections|evicted_keys'
$R info clients | grep -E 'connected_clients|blocked_clients'
docker compose logs --since 30m redis 2>&1 | tail -8 | cut -c1-200
