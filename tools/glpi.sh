#!/usr/bin/env bash
#
# Entity Sync plugin for GLPI
#
# Drives the GLPI + MariaDB stack of docker/compose.yml, used by the dev container
# and the CI.
#
#   tools/glpi.sh up       build and start the stack, install and activate the plugin
#   tools/glpi.sh down     stop the stack (data kept)
#   tools/glpi.sh reset    stop the stack and delete its data (database, GLPI files)
#   tools/glpi.sh exec ... run a command in the GLPI container (e.g. glpi-console)
#
# `up` is idempotent: run it again after changing the GLPI version (with `reset`
# first: the database is not migrated) or a setting below.
#
# Environment variables (defaults in brackets):
#   COMPOSE                       Compose command                         [docker compose]
#   GLPI_VERSION                  GLPI release, "X.Y.x" (latest of a branch) or
#                                 "latest"                                [11.0.13]
#   GLPI_HTTP_PORT, DB_PORT       Published ports                         [8080, 3306]
#   ENTITYSYNC_RUN_TIME_BUDGET    Seconds per execution of the background task
#                                 (PLUGIN_ENTITYSYNC_RUN_TIME_BUDGET)    [unset: 50]
#
# @license GPL-3.0-or-later

set -euo pipefail

PLUGIN_KEY=entitysync
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT/docker/compose.yml"
read -r -a COMPOSE <<< "${COMPOSE:-docker compose}"

log() { printf '\033[1;34m[glpi]\033[0m %s\n' "$*"; }

compose() { "${COMPOSE[@]}" -f "$COMPOSE_FILE" "$@"; }

in_glpi() { compose exec -T glpi "$@"; }

# GLPI_VERSION: exact release, "X.Y.x" for the latest release of a branch (e.g. 12.0.x),
# or "latest" for the latest stable release.
resolve_version() {
    local version="${GLPI_VERSION:-}" pattern
    case "$version" in
        latest) pattern='[0-9]+\.[0-9]+\.[0-9]+' ;;
        *.x)    pattern="${version%.x}"; pattern="${pattern//./\\.}\\.[0-9]+" ;;
        *)      echo "$version"; return ;;
    esac
    git ls-remote --tags --refs https://github.com/glpi-project/glpi.git \
        | sed 's#.*refs/tags/##' \
        | grep -E "^${pattern}\$" \
        | sort -V \
        | tail -n 1
}

wait_ready() {
    log "Waiting for GLPI..."
    for _ in $(seq 1 90); do
        if in_glpi /readinessCheck.sh > /dev/null 2>&1; then
            return
        fi
        sleep 2
    done
    echo "GLPI did not become ready; see: $(printf '%q ' "${COMPOSE[@]}")-f docker/compose.yml logs glpi" >&2
    exit 1
}

# Plugin state in GLPI's plugin list: Enabled, Not installed, Installed / not activated...
plugin_status() {
    in_glpi glpi-console plugin:list --no-interaction 2>/dev/null \
        | awk -v key="$PLUGIN_KEY" '$0 ~ "\\| *" key " *\\|" {print}'
}

cmd_up() {
    local version
    version="$(resolve_version)"
    if [ -n "$version" ]; then
        export GLPI_VERSION="$version"
    elif [ -n "${GLPI_VERSION:-}" ]; then
        echo "Unable to resolve the GLPI release '$GLPI_VERSION'." >&2
        exit 1
    fi

    # Translations are read from the compiled .mo files of the repository.
    make -C "$ROOT" --no-print-directory locales-mo

    # The container user (uid 1000) writes the logs into the repository: the directory
    # must be writable whatever the uid of the checkout (CI runners use another one).
    mkdir -p "$ROOT/docker/log"
    chmod 0777 "$ROOT/docker/log"

    log "Starting GLPI ${GLPI_VERSION:-(default version)}..."
    compose up -d --build
    wait_ready

    # Local constants, loaded by the image's config/local_define.php.
    {
        echo '<?php'
        if [ -n "${ENTITYSYNC_RUN_TIME_BUDGET:-}" ]; then
            printf "define('PLUGIN_ENTITYSYNC_RUN_TIME_BUDGET', %d);\n" "$ENTITYSYNC_RUN_TIME_BUDGET"
        fi
    } | in_glpi sh -c 'cat > /data/glpi/config/local_define.php'

    local status
    status="$(plugin_status)"
    case "$status" in
        *"Not installed"*|"")
            log "Installing the plugin..."
            in_glpi glpi-console plugin:install "$PLUGIN_KEY" --username=glpi --no-interaction ;;
        *"To update"*|*"update"*)
            log "Updating the plugin..."
            in_glpi glpi-console plugin:install "$PLUGIN_KEY" --username=glpi --force --no-interaction ;;
    esac
    if ! plugin_status | grep -q "Enabled"; then
        log "Activating the plugin..."
        in_glpi glpi-console plugin:activate "$PLUGIN_KEY" --no-interaction
    fi

    log "GLPI ready on http://localhost:${GLPI_HTTP_PORT:-8080} (login glpi / glpi), logs in docker/log/."
}

case "${1:-}" in
    up)    cmd_up ;;
    down)  compose down ;;
    reset) compose down --volumes ;;
    exec)  shift; in_glpi "$@" ;;
    *)
        echo "Usage: $0 up|down|reset|exec <command...>" >&2
        exit 2 ;;
esac
