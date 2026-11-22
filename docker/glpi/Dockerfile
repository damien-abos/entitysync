# syntax=docker/dockerfile:1
#
# GLPI image used to develop and test the Entity Sync plugin (dev container and CI).
#
# Alpine, Apache (port 8080) + PHP-FPM 8.5, everything runs as the non-root user
# `glpi` (uid/gid 1000). See docs/developer.md, "GLPI stack".
#
# The Alpine release is pinned: the availability of the php85 packages depends on it.

FROM alpine:3.24

# GLPI release to install (the CI builds the image for several releases).
ARG GLPI_VERSION=11.0.13
ARG GLPI_DOWNLOAD_URL=https://github.com/glpi-project/glpi/releases/download/${GLPI_VERSION}/glpi-${GLPI_VERSION}.tgz

ENV GLPI_INSTALL_MODE=DOCKER
ENV GLPI_CONFIG_DIR=/data/glpi/config
ENV GLPI_VAR_DIR=/data/glpi/files
ENV GLPI_MARKETPLACE_DIR=/data/glpi/marketplace
ENV GLPI_PLUGINS_DIR=/var/www/glpi/plugins

ENV GLPI_DATABASE_HOST=glpi-db
ENV GLPI_DATABASE_PORT=3306
ENV GLPI_DATABASE_NAME=glpi
ENV GLPI_DATABASE_USERNAME=glpi
ENV GLPI_DATABASE_PASSWORD=
ENV GLPI_DEFAULT_LANGUAGE=en_GB

RUN \
  echo "**** install runtime packages ****" && \
  apk add -U --upgrade --no-cache \
    apache2 \
    apache2-http2 \
    apache2-proxy \
    bash \
    ca-certificates \
    curl \
    icu-data-full \
    jq \
    php85 \
    php85-bcmath \
    php85-bz2 \
    php85-common \
    php85-ctype \
    php85-curl \
    php85-dom \
    php85-exif \
    php85-fileinfo \
    php85-fpm \
    php85-gd \
    php85-iconv \
    php85-intl \
    php85-ldap \
    php85-mbstring \
    php85-mysqli \
    php85-mysqlnd \
    php85-openssl \
    php85-phar \
    php85-session \
    php85-simplexml \
    php85-sodium \
    php85-tokenizer \
    php85-xml \
    php85-xmlreader \
    php85-xmlwriter \
    php85-zip \
    supercronic \
  && echo "**** php command ****" && \
  { [ -e /usr/bin/php ] || ln -s /usr/bin/php85 /usr/bin/php; } && \
  echo "**** install glpi ${GLPI_VERSION} ****" && \
  curl -fsSL -o /tmp/glpi.tar.gz "${GLPI_DOWNLOAD_URL}" && \
  tar -xz -f /tmp/glpi.tar.gz -C /var/www/ && \
  rm -f /var/www/glpi/install/install.php && \
  echo "**** local settings kept in the data volume ****" && \
  mkdir -p /var/www/glpi/config && \
  printf '%s\n' '<?php' \
    '// Local settings kept in the data volume (written by tools/glpi.sh).' \
    "if (is_file('/data/glpi/config/local_define.php')) {" \
    "    require_once '/data/glpi/config/local_define.php';" \
    '}' > /var/www/glpi/config/local_define.php && \
  echo "**** prepare working directories ****" && \
  # They exist in the image (owned by glpi below) so that a new volume, or a
  # directory mounted inside it (compose: the log directory), keeps that owner.
  mkdir -p /data/apache2/log /data/glpi/config /data/glpi/files/_log && \
  echo "**** create glpi user ****" && \
  addgroup -g 1000 -S glpi && \
  adduser -S -D -H -u 1000 -s /sbin/nologin -h /data -G glpi glpi && \
  chown -R glpi:glpi /data && \
  echo "**** cleanup ****" && \
  rm -rf \
    /etc/crontabs/root \
    /tmp/*

# copy local files
COPY root/ /
# fix rights
RUN \
  echo "**** Change owner and set executable files ****" && \
  chown -R glpi:glpi /etc/crontabs/glpi && \
  chmod a+x /entrypoint.sh /livenessCheck.sh /readinessCheck.sh /usr/local/bin/glpi-console

USER glpi

# ports and volumes
EXPOSE 8080
VOLUME [ "/data", "/var/www/glpi/plugins", "/var/www/glpi/pics/logos" ]

ENTRYPOINT [ "/entrypoint.sh" ]
