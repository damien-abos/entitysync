# Entity Sync plugin for GLPI — development image.
#
# PHP 8.3 on Debian 13 "trixie" (Xdebug included in the base image) with the
# extensions required by GLPI 11, gettext tools and a MariaDB client.
#
# The base image tag pins the major version of the image (4-) so that fixes and
# security updates are picked up on rebuild without breaking changes.

FROM mcr.microsoft.com/devcontainers/php:4-8.3-trixie

# The base image declares the Yarn APT repository with the key fetched when the
# image was built; if Yarn rotates its key, `apt-get update` fails on that
# repository. Yarn is not used here, so the repository is dropped (only in
# sources.list.d: the Debian sources are left untouched). No-op when absent.
RUN find /etc/apt/sources.list.d -type f -exec grep -l 'dl.yarnpkg.com' {} + 2>/dev/null \
        | xargs -r rm -f

RUN export DEBIAN_FRONTEND=noninteractive \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        gettext \
        mariadb-client \
        shellcheck \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl mysqli opcache zip \
    && rm -rf /var/lib/apt/lists/*

COPY php-glpi.ini /usr/local/etc/php/conf.d/zz-glpi.ini

# GLPI is installed in /opt/glpi by post-create.sh. Both directories are named
# volumes (GLPI, Claude Code settings) that inherit this ownership when created.
RUN mkdir -p /opt/glpi /home/vscode/.claude \
    && chown vscode:vscode /opt/glpi /home/vscode/.claude
