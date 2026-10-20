#!/usr/bin/env bash
#
# Entity Sync plugin for GLPI
#
# Builds the deployable archive dist/entitysync-<version>.tar.gz containing only the
# files GLPI needs at runtime (allowlist), with compiled translations, and its
# SHA-256 checksum.
#
# Usage: tools/build-package.sh [--tag vX.Y.Z]
#   --tag   fail if the tag does not match the version declared in setup.php
#
# The archive is reproducible: file order, owners and dates are fixed (dates come
# from SOURCE_DATE_EPOCH, or the last commit, or the current time).
#
# @license GPL-3.0-or-later

set -euo pipefail

PLUGIN_KEY=entitysync
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Runtime files and directories (everything else is left out: tests, tools, docs, CI...).
INCLUDE=(setup.php hook.php LICENSE README.md CHANGELOG.md ajax front public src templates)

VERSION="$(sed -n "s/^define('PLUGIN_ENTITYSYNC_VERSION', '\(.*\)');/\1/p" setup.php)"
if [ -z "$VERSION" ]; then
    echo "Version not found in setup.php" >&2
    exit 1
fi

if [ "${1:-}" = "--tag" ]; then
    TAG="${2:-}"
    if [ "${TAG#v}" != "$VERSION" ]; then
        echo "Tag '$TAG' does not match the plugin version '$VERSION' (setup.php)." >&2
        exit 1
    fi
fi

if ! grep -q "^## ${VERSION}\b" CHANGELOG.md; then
    echo "CHANGELOG.md has no '## ${VERSION}' section." >&2
    exit 1
fi

BUILD="$ROOT/dist/build"
STAGE="$BUILD/$PLUGIN_KEY"
ARCHIVE="$ROOT/dist/${PLUGIN_KEY}-${VERSION}.tar.gz"

rm -rf "$BUILD" "$ARCHIVE" "$ARCHIVE.sha256"
mkdir -p "$STAGE/locales"

for item in "${INCLUDE[@]}"; do
    cp -R "$item" "$STAGE/"
done

# Translations: compiled catalogs only.
for po in locales/*.po; do
    lang="$(basename "$po" .po)"
    msgfmt --check -o "$STAGE/locales/$lang.mo" "$po"
done

# Sanity checks on the staged tree.
find "$STAGE" -name '*.php' -print0 | xargs -0 -n 1 php -l > /dev/null
if find "$STAGE" -name '.*' -not -path "$STAGE" | grep -q .; then
    echo "Hidden files found in the package:" >&2
    find "$STAGE" -name '.*' -not -path "$STAGE" >&2
    exit 1
fi

EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$ROOT" log -1 --format=%ct 2>/dev/null || date +%s)}"
tar --sort=name --owner=0 --group=0 --numeric-owner --mtime="@${EPOCH}" \
    --mode='u+rwX,go+rX,go-w' -C "$BUILD" -cf - "$PLUGIN_KEY" | gzip -9n > "$ARCHIVE"
(cd "$ROOT/dist" && sha256sum "$(basename "$ARCHIVE")" > "$(basename "$ARCHIVE").sha256")
rm -rf "$BUILD"

echo "Package: dist/$(basename "$ARCHIVE") ($(du -h "$ARCHIVE" | cut -f1))"
tar -tzf "$ARCHIVE" | sed 's/^/  /'
