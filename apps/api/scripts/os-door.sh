#!/bin/sh
# Staff door for os.omggamer.com. Run from the repo root as root.
#   sh apps/api/scripts/os-door.sh            create the key once (kept), restart web, print the door link
#   sh apps/api/scripts/os-door.sh rotate     new key: every browser must open the new link again
#   sh apps/api/scripts/os-door.sh set KEY    use this key (e.g. to keep an existing link working)
#   sh apps/api/scripts/os-door.sh off        remove the door
# The key lives in /etc/omggamer-os/door.env, outside the app folder, because deploys reset that folder.
set -e
D=/etc/omggamer-os
F=$D/door.env
OWNER=$(stat -c %U .git)
mkdir -p "$D"
case "$1" in
  off|rotate) rm -f "$F" ;;
  set) [ ${#2} -ge 16 ] || { echo "key too short" >&2; exit 1; }; rm -f "$F"; umask 027; echo "OS_DOOR_KEY=$2" > "$F" ;;
esac
if [ "$1" != "off" ] && ! grep -q '^OS_DOOR_KEY=' "$F" 2>/dev/null; then
  umask 027
  echo "OS_DOOR_KEY=$(openssl rand -hex 24)" > "$F"
fi
[ -f "$F" ] && chown root:"$OWNER" "$F" && chmod 640 "$F"
rm -f .env.door
docker compose up -d --no-deps web 2>&1 | tail -1
sleep 6
K=$(sed -n 's/^OS_DOOR_KEY=//p' "$F" 2>/dev/null || true)
[ -n "$K" ] && echo "door is on (key ends ...$(echo "$K" | tail -c 7))" || echo "door is off"
echo "outsider /login (404 = closed): $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3100/login)"
echo "health (200 = open): $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:3100/api/v1/health)"
echo "webhook path (not 404 = open): $(curl -s -o /dev/null -w '%{http_code}' -X POST http://127.0.0.1:3100/api/v1/webhooks/kinguin)"
