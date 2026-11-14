#!/bin/sh
# Read-only publishing diagnostics on the OS server. Run from the repo root as root.
docker compose exec -T postgres psql -U omgos -d omggamer_os -f - < apps/api/scripts/diag-publish.sql 2>&1 | cut -c1-420
echo '== containers'
docker compose ps --format '{{.Service}} {{.Status}}' 2>&1
echo '== worker log (publish/errors, last 6h)'
docker compose logs --no-color --since 6h worker 2>&1 | grep -i "publish\|error\|exception" | grep -v -i "api_key\|password\|secret\|token" | tail -15 | cut -c1-300
