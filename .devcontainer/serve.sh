#!/usr/bin/env bash
#
# Main process of the dev container:
# - serves GLPI with PHP's built-in web server on port 8080, waiting for
#   post-create.sh to install it, and restarting it if it stops;
# - runs GLPI's cron every minute, like the system crontab of a production server,
#   so that queued imports are applied by the background task.
#
# Logs: /opt/glpi/files/_log/php-server.log and cron-loop.log
#
# @license GPL-3.0-or-later

set -uo pipefail

GLPI_DIR="${GLPI_DIR:-/opt/glpi}"

cron_loop() {
    while true; do
        if [ -f "$GLPI_DIR/config/config_db.php" ]; then
            php "$GLPI_DIR/front/cron.php" >> "$GLPI_DIR/files/_log/cron-loop.log" 2>&1
        fi
        sleep 60
    done
}

cron_loop &

while true; do
    if [ -f "$GLPI_DIR/config/config_db.php" ]; then
        php -S 0.0.0.0:8080 -t "$GLPI_DIR/public" "$GLPI_DIR/public/index.php" \
            >> "$GLPI_DIR/files/_log/php-server.log" 2>&1
        echo "PHP server stopped (exit $?), restarting..." >&2
    fi
    sleep 2
done
