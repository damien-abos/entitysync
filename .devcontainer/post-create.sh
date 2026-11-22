#!/usr/bin/env bash
#
# Runs once when the dev container is created: installs the tools of `make check`
# (PHP CLI with the extensions of the engine and the e2e client, gettext, shellcheck).
# GLPI itself runs in docker/compose.yml, started by `make glpi-up` (postStartCommand).
#
# @license GPL-3.0-or-later

set -euo pipefail

sudo apt-get update -q
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends \
    make \
    gettext \
    shellcheck \
    php-cli \
    php-curl \
    php-intl \
    php-mbstring \
    php-mysql
sudo rm -rf /var/lib/apt/lists/*

# The named volume holding the Claude Code settings is created owned by root.
sudo chown vscode:vscode /home/vscode/.claude

cat <<'TXT'

  GLPI starts with the container (make glpi-up): http://localhost:8080 (glpi / glpi).
  Useful commands: make help | make check | make e2e | make package

TXT
