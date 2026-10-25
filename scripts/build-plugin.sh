#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT

mkdir -p "$root/build" "$staging/pelican-pangolin"
cp -R \
    "$root/config" \
    "$root/database" \
    "$root/docs" \
    "$root/src" \
    "$root/resources" \
    "$root/CHANGELOG.md" \
    "$root/LICENSE" \
    "$root/README.md" \
    "$root/composer.json" \
    "$root/plugin.json" \
    "$staging/pelican-pangolin/"

rm -f "$root/build/pelican-pangolin.zip"
php "$root/scripts/build-archive.php" "$staging/pelican-pangolin" "$root/build/pelican-pangolin.zip"

printf 'Built %s\n' "$root/build/pelican-pangolin.zip"
