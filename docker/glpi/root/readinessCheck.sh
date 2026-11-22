#!/usr/bin/env bash
# shellcheck shell=bash
#
# Ready when Apache and PHP-FPM run (the entrypoint starts them once the database is
# installed or configured: GLPI reports OK while database:install is still running)
# and GLPI reports an OK status. system:status prints JSON (GLPI 11 has no --format
# option); each console run logs a "Test logger" line in php-errors.log.

set -e

/livenessCheck.sh

STATUS=$(/usr/local/bin/glpi-console system:status)
[ "$(echo "$STATUS" | jq -r '.glpi.status')" == "OK" ]
