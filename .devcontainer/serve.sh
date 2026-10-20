#!/usr/bin/env bash
#
# Main process of the dev container: serves GLPI with PHP's built-in web server on
# port 8080, waiting for post-create.sh to install it, and restarting it if it stops.
#
# Logs: /opt/glpi/files/_log/php-server.log
#
# @license GPL-3.0-or-later

set -uo pipefail

GLPI_DIR="${GLPI_DIR:-/opt/glpi}"

while true; do
    if [ -f "$GLPI_DIR/config/config_db.php" ]; then
        php -S 0.0.0.0:8080 -t "$GLPI_DIR/public" "$GLPI_DIR/public/index.php" \
            >> "$GLPI_DIR/files/_log/php-server.log" 2>&1
        echo "PHP server stopped (exit $?), restarting..." >&2
    fi
    sleep 2
done
