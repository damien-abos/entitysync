#!/usr/bin/env bash
#
# Entity Sync plugin for GLPI
#
# Installs a GLPI release for development or CI, then installs and activates the
# plugin from this repository (linked, not copied, so code changes apply at once).
#
# Idempotent: an existing installation of the same version is kept; the database
# is installed only when GLPI is not configured yet.
#
# Environment variables (defaults in brackets):
#   GLPI_VERSION   GLPI release, or "latest" for the latest 11.0.x  [latest]
#   GLPI_DIR       Installation directory                            [/opt/glpi]
#   GLPI_ENV       GLPI environment (production, development...)     [development]
#   GLPI_LANG      Default language                                  [en_GB]
#   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD                  [127.0.0.1, 3306, glpi, glpi, glpi]
#
# @license GPL-3.0-or-later

set -euo pipefail

PLUGIN_KEY=entitysync
PLUGIN_SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

GLPI_VERSION="${GLPI_VERSION:-latest}"
GLPI_DIR="${GLPI_DIR:-/opt/glpi}"
GLPI_ENV="${GLPI_ENV:-development}"
GLPI_LANG="${GLPI_LANG:-en_GB}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-glpi}"
DB_USER="${DB_USER:-glpi}"
DB_PASSWORD="${DB_PASSWORD:-glpi}"

log() { printf '\033[1;34m[install-glpi]\033[0m %s\n' "$*"; }

console() {
    local extra=()
    if [ "$(id -u)" -eq 0 ]; then
        extra+=(--allow-superuser)
    fi
    php "$GLPI_DIR/bin/console" --no-interaction "${extra[@]}" "$@"
}

resolve_version() {
    if [ "$GLPI_VERSION" != "latest" ]; then
        echo "$GLPI_VERSION"
        return
    fi
    git ls-remote --tags --refs https://github.com/glpi-project/glpi.git 'refs/tags/11.0.*' \
        | sed 's#.*refs/tags/##' \
        | grep -E '^11\.0\.[0-9]+$' \
        | sort -V \
        | tail -n 1
}

wait_for_db() {
    log "Waiting for the database at $DB_HOST:$DB_PORT..."
    for _ in $(seq 1 60); do
        # shellcheck disable=SC2016  # PHP code, not shell expansions
        if php -r 'mysqli_report(MYSQLI_REPORT_OFF); exit(@mysqli_connect($argv[1], $argv[2], $argv[3], $argv[4], (int) $argv[5]) ? 0 : 1);' \
            "$DB_HOST" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_PORT"; then
            return
        fi
        sleep 2
    done
    echo "Database not reachable." >&2
    exit 1
}

VERSION="$(resolve_version)"
if [ -z "$VERSION" ]; then
    echo "Unable to resolve the GLPI version." >&2
    exit 1
fi

# 1. GLPI files
if [ -f "$GLPI_DIR/version/$VERSION" ]; then
    log "GLPI $VERSION already present in $GLPI_DIR"
else
    if [ -d "$GLPI_DIR/bin" ]; then
        echo "$GLPI_DIR holds another GLPI version; remove it first (or set GLPI_DIR)." >&2
        exit 1
    fi
    log "Downloading GLPI $VERSION..."
    mkdir -p "$GLPI_DIR"
    archive="$(mktemp)"
    curl -fsSL -o "$archive" "https://github.com/glpi-project/glpi/releases/download/${VERSION}/glpi-${VERSION}.tgz"
    tar -xzf "$archive" -C "$GLPI_DIR" --strip-components=1
    rm -f "$archive"
fi

# 2. Environment (development enables strict checks: Twig strict variables, debug logs).
cat > "$GLPI_DIR/config/local_define.php" <<PHP
<?php
define('GLPI_ENVIRONMENT_TYPE', '${GLPI_ENV}');
PHP

# 3. Database
wait_for_db
if [ -f "$GLPI_DIR/config/config_db.php" ]; then
    log "GLPI already configured, database installation skipped"
else
    log "Installing the GLPI database..."
    console db:install \
        --db-host="$DB_HOST" --db-port="$DB_PORT" --db-name="$DB_NAME" \
        --db-user="$DB_USER" --db-password="$DB_PASSWORD" \
        --default-language="$GLPI_LANG"
fi

# 4. Plugin (linked to the repository)
ln -sfn "$PLUGIN_SRC" "$GLPI_DIR/plugins/$PLUGIN_KEY"
make -C "$PLUGIN_SRC" --no-print-directory locales-mo
plugin_state() {
    # Plugin::ACTIVATED = 1, NOTINSTALLED = 2, NOTACTIVATED = 4, NOTUPDATED = 6; empty when unknown.
    # shellcheck disable=SC2016  # PHP code, not shell expansions
    php -r 'mysqli_report(MYSQLI_REPORT_OFF);
        $db = @mysqli_connect($argv[1], $argv[2], $argv[3], $argv[4], (int) $argv[5]);
        $res = $db ? @mysqli_query($db, "SELECT state FROM glpi_plugins WHERE directory = \"" . $argv[6] . "\"") : false;
        $row = $res ? mysqli_fetch_row($res) : null;
        echo $row[0] ?? "";' \
        "$DB_HOST" "$DB_USER" "$DB_PASSWORD" "$DB_NAME" "$DB_PORT" "$PLUGIN_KEY"
}

console plugin:list > /dev/null  # refreshes the plugin states stored in the database
state="$(plugin_state)"
case "$state" in
    1|4) log "Plugin already installed" ;;
    6)   log "Updating the plugin..."; console plugin:install "$PLUGIN_KEY" --username=glpi --force ;;
    *)   log "Installing the plugin..."; console plugin:install "$PLUGIN_KEY" --username=glpi ;;
esac
if [ "$(plugin_state)" != "1" ]; then
    log "Activating the plugin..."
    console plugin:activate "$PLUGIN_KEY"
fi
console cache:clear >/dev/null

log "GLPI $VERSION ready in $GLPI_DIR (login glpi / glpi)."
