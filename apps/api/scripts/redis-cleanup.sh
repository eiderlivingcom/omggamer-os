#!/bin/sh
# Free the OS Redis after the AI queue flood (2026-11-18). Safe: AI jobs live in Postgres and the AI watchdog
# re-dispatches queued ones; task results are not used by anything.
cd /var/www/os.omggamer.com || exit 1
R="docker compose exec -T redis redis-cli"
echo "before: $($R info memory | grep used_memory_human)"
echo "ai queue: $($R llen ai) messages -> removed: $($R unlink ai)"
n=0
while :; do
  keys=$($R --scan --pattern 'celery-task-meta-*' --count 5000 | head -5000)
  [ -z "$keys" ] && break
  echo "$keys" | xargs $R unlink >/dev/null
  n=$((n + $(echo "$keys" | wc -l)))
done
echo "task results removed: $n"
echo "after: $($R info memory | grep used_memory_human), keys: $($R dbsize)"
for q in default imports ai publish; do echo "queue $q: $($R llen $q)"; done
