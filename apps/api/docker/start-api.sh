#!/bin/sh
# Apply database migrations, create the first Super Admin if none exists, then serve the API.
set -e
alembic upgrade head
python -m app.cli bootstrap
exec uvicorn app.main:app --host 0.0.0.0 --port 8000 --workers "${API_WORKERS:-2}" \
  --proxy-headers --forwarded-allow-ips='*' --no-server-header
