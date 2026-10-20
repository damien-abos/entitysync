#!/usr/bin/env bash
#
# Runs once when the dev container is created: installs GLPI and the plugin.
#
# @license GPL-3.0-or-later

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

make glpi-install

cat <<'TXT'

  GLPI is starting on http://localhost:8080 (login glpi / glpi).
  Useful commands: make help | make check | make e2e | make package

TXT
