#!/usr/bin/env bash
# Сборка: минификация JS темы и ZIP-архивы темы и плагинов в dist/.
# Использование: scripts/build.sh [--no-zip]
set -euo pipefail
cd "$(dirname "$0")/.."

TERSER="${TERSER:-npx --yes terser@5}"
JS_DIR="theme/podval-theme/assets/js"
MINIFIED=(artwork-upload card-carousel checkout home-anchors main product service-landing service tpl-builder)

for name in "${MINIFIED[@]}"; do
  $TERSER "$JS_DIR/$name.js" --compress --mangle --comments false -o "$JS_DIR/$name.min.js"
done
echo "JS: ${#MINIFIED[@]} файлов минифицировано"

[[ "${1:-}" == "--no-zip" ]] && exit 0

rm -rf dist && mkdir -p dist
VERSION="$(sed -n 's/^Version: //p' theme/podval-theme/style.css)"
( cd theme && zip -qr "../dist/podval-theme-$VERSION.zip" podval-theme -x '*.DS_Store' )
for dir in plugins/*/; do
  name="$(basename "$dir")"
  ( cd plugins && zip -qr "../dist/$name-$VERSION.zip" "$name" -x '*/tests/*' '*.DS_Store' )
done
ls -1 dist
