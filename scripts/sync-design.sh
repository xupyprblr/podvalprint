#!/usr/bin/env bash
# Перенос дизайн-системы из design/system в тему: токены, компоненты, иконки.
# Google Fonts убирается (шрифты раздаются темой), документационные обёртки .pv — тоже.
# Использование: scripts/sync-design.sh
set -euo pipefail
cd "$(dirname "$0")/.."

SRC=design/system
DST=theme/podval-theme/assets

mkdir -p "$DST/css/pp" "$DST/icons"
cp "$SRC/tokens.css" "$DST/css/pp/tokens.css"
sed -e '/^@import url("https:\/\/fonts\.googleapis\.com/d' \
    -e '/Документационные обёртки превью/,$d' \
    "$SRC/components/bundle.css" > "$DST/css/pp/bundle.css"
cp "$SRC"/assets/Icons/*.svg "$DST/icons/"
echo "Дизайн-система перенесена: $(ls "$DST/icons" | wc -l) иконок, $(wc -c < "$DST/css/pp/bundle.css") байт компонентов"
