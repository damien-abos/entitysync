# Entity Sync plugin for GLPI — development image.
#
# PHP 8.3 (Debian bookworm, Xdebug included in the base image) with the extensions
# required by GLPI 11, gettext tools and a MariaDB client.

FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

RUN export DEBIAN_FRONTEND=noninteractive \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        gettext \
        mariadb-client \
        shellcheck \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl mysqli opcache zip \
    && rm -rf /var/lib/apt/lists/*

COPY php-glpi.ini /usr/local/etc/php/conf.d/zz-glpi.ini

# GLPI is installed in /opt/glpi by post-create.sh. Both directories are named
# volumes (GLPI, Claude Code settings) that inherit this ownership when created.
RUN mkdir -p /opt/glpi /home/vscode/.claude \
    && chown vscode:vscode /opt/glpi /home/vscode/.claude
