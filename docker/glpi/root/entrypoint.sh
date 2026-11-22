#!/usr/bin/env bash
# shellcheck shell=bash
#
# Prepares the data directories, installs or reconfigures the GLPI database, then
# starts PHP-FPM, the cron (supercronic) and Apache. The database must be reachable
# before the container starts (compose: depends_on service_healthy).

set -e

# create folders
mkdir -p \
    /data/glpi/config.previous \
    /data/glpi/config \
    /data/glpi/files/{_cache,_cron,_dumps,_graphs,_inventories,_locales,_lock,_log,_pictures,_plugins,_rss,_sessions,_themes,_tmp,_uploads} \
    /data/glpi/marketplace \
    /data/log \
    /data/apache2/log

db_args=(
    --db-host="$GLPI_DATABASE_HOST"
    --db-port="$GLPI_DATABASE_PORT"
    --db-name="$GLPI_DATABASE_NAME"
    --db-user="$GLPI_DATABASE_USERNAME"
    --db-password="$GLPI_DATABASE_PASSWORD"
)

# check if config already exists
if [ -f /data/glpi/config/config_db.php ]; then
    # backup config files
    cp /data/glpi/config/{config_db.php,glpicrypt.key} /data/glpi/config.previous/
    # reconfigure database if required
    glpi-console database:configure \
        "${db_args[@]}" \
        --reconfigure \
        --no-interaction \
        --verbose
else
    # install database and install schema
    glpi-console database:install \
        "${db_args[@]}" \
        --default-language="$GLPI_DEFAULT_LANGUAGE" \
        --reconfigure \
        --no-telemetry \
        --no-interaction \
        --verbose
fi

# start php-fpm
/usr/sbin/php-fpm85 -F -O &

# start supercronic
supercronic /etc/crontabs/glpi &

exec /usr/sbin/httpd -f /etc/glpi/apache2/httpd.conf -D FOREGROUND
