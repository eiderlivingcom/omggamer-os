#!/bin/sh
# Copies the Kinguin pricing rules to Foxreload, then queues a full price recalculation. Run from the repo root.
docker compose exec -T postgres psql -U omgos -d omggamer_os -f - < apps/api/scripts/foxreload-pricing.sql 2>&1 | cut -c1-400
docker compose exec -T api python -c "from app.workers.celery_app import celery_app; celery_app.send_task('pricing.recalculate_all', kwargs={'storefront': None}, queue='default'); print('recalculation queued')" 2>&1 | tail -2
