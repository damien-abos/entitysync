#!/usr/bin/env bash
# shellcheck shell=bash
#
# Alive when the Apache and PHP-FPM master processes recorded in /data are running.

set -e

# check apache2 status
HTTPD_PID=$(cat /data/httpd.pid)
HTTPD_CMDLINE=$(tr -d '\0' < "/proc/$HTTPD_PID/cmdline")
[ "$HTTPD_CMDLINE" == "/usr/sbin/httpd-f/etc/glpi/apache2/httpd.conf-DFOREGROUND" ]

# check php-fpm status
PHP_FPM_PID=$(cat /data/php-fpm.pid)
PHP_FPM_CMDLINE=$(tr -d '\0' < "/proc/$PHP_FPM_PID/cmdline")
[ "$PHP_FPM_CMDLINE" == "php-fpm: master process (/etc/php85/php-fpm.conf)" ]
