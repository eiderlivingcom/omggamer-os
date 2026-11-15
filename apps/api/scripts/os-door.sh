#!/bin/sh
# Staff door for os.omggamer.com. Run from the repo root as root.
#   sh apps/api/scripts/os-door.sh          create the key once (kept), restart web, print the door link
#   sh apps/api/scripts/os-door.sh rotate   new key: every browser must open the new link again
#   sh apps/api/scripts/os-door.sh off      remove the door
set -e
F=.env.door
case "$1" in
  off) rm -f "$F" ;;
  rotate) rm -f "$F" ;;
esac
if [ "$1" != "off" ] && ! grep -q '^OS_DOOR_KEY=' "$F" 2>/dev/null; then
  umask 077
  echo "OS_DOOR_KEY=$(openssl rand -hex 24)" > "$F"
fi
docker compose up -d --no-deps web 2>&1 | tail -1
sleep 6
K=$(sed -n 's/^OS_DOOR_KEY=//p' "$F" 2>/dev/null || true)
[ -n "$K" ] && echo "door link: https://os.omggamer.com/?door=$K" || echo "door is off"
echo "outsider /login (404 = closed): $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3100/login)"
echo "health (200 = open): $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3100/api/v1/health)"
echo "webhook path (not 404 = open): $(curl -s -o /dev/null -w '%{http_code}' -X POST http://127.0.0.1:3100/api/v1/webhooks/kinguin)"
