#!/bin/sh
# Copy the storefront mu-plugins, brand files and scripts into a WordPress install on this server.
# Run as root:  sh install.sh [/var/www/<site>]
set -eu
SRC=$(cd "$(dirname "$0")" && pwd)
DST=${1:-/var/www/omggamer2-9tw2.1wp.site}
[ -f "$DST/wp-config.php" ] || { echo "No WordPress at $DST" >&2; exit 1; }
U=$(stat -c %U "$DST/wp-config.php")
G=$(stat -c %G "$DST/wp-config.php")
HOME_DIR=$(getent passwd "$U" | cut -d: -f6)

install -d -o "$U" -g "$G" "$DST/wp-content/mu-plugins" "$DST/wp-content/uploads/omg-brand" "$HOME_DIR/omg-scripts"
install -m 644 -o "$U" -g "$G" "$SRC"/mu-plugins/*.php "$DST/wp-content/mu-plugins/"
install -m 644 -o "$U" -g "$G" "$SRC"/brand/* "$DST/wp-content/uploads/omg-brand/"
install -m 644 -o "$U" -g "$G" "$SRC"/scripts/*.php "$HOME_DIR/omg-scripts/"
for P in "$SRC"/plugins/*/ "$SRC"/../api/wp-connector/*/; do
  [ -d "$P" ] || continue
  N=$(basename "$P")
  rm -rf "$DST/wp-content/plugins/$N.new" && cp -R "$P" "$DST/wp-content/plugins/$N.new"
  chown -R "$U:$G" "$DST/wp-content/plugins/$N.new" && find "$DST/wp-content/plugins/$N.new" -type f -exec chmod 644 {} + && find "$DST/wp-content/plugins/$N.new" -type d -exec chmod 755 {} +
  rm -rf "$DST/wp-content/plugins/$N.old"
  if [ -d "$DST/wp-content/plugins/$N" ]; then mv "$DST/wp-content/plugins/$N" "$DST/wp-content/plugins/$N.old"; fi
  mv "$DST/wp-content/plugins/$N.new" "$DST/wp-content/plugins/$N" && rm -rf "$DST/wp-content/plugins/$N.old"
done
echo "Installed into $DST (scripts in $HOME_DIR/omg-scripts)"
