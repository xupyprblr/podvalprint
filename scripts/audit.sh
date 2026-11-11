#!/usr/bin/env bash
# Аудит следов исходной кодовой базы и зашитых реквизитов.
# Гоняется в CI и перед каждым релизом. Сам скрипт и docs/ на сайт не выкладываются.
# Код возврата 1 — найдено совпадение.
set -uo pipefail
cd "$(dirname "$0")/.."

SCOPE=(theme plugins)
fail=0

check() { # check <название> <regex> [доп. фильтр grep -v]
  local name="$1" re="$2" skip="${3:-^$}"
  local out
  out="$(grep -rInEi --include='*.php' --include='*.js' --include='*.css' --include='*.json' \
        --include='*.html' --include='*.txt' --include='*.svg' -- "$re" "${SCOPE[@]}" | grep -viE "$skip" || true)"
  if [[ -n "$out" ]]; then
    echo "✗ $name"; echo "$out" | cut -c1-180 | head -20; fail=1
  else
    echo "✓ $name"
  fi
}

# 1. Прежние названия и префиксы
check "прежний бренд"            'geek[ _-]?print|geekprint'
check "прежние префиксы темы"    '(^|[^a-z])merch([_:-]|[A-Z]|[ ]store)|Merch[ _][A-Z]' 'merchant'
check "прежние префиксы плагинов" '(^|[^a-z])(wto|mpc)[_-]|woo-telegram-orders'
check "прежние дев-хосты и слаги" 'm12wear|m12\.by|m12korp|[^a-z0-9 ]m12[_-]|is_m12'
# 2. Чужие реквизиты и идентификаторы
check "чужие реквизиты"          '193833273|757163|BY71ALFA|ALFABY2X|738[- ]?66[- ]?66|старовиленск|madjest|geek_print'
check "чужие рекламные ID"       'AW-17725099048|YTiCCLu6'
# 3. Реквизиты и контакты строкой в коде (должны идти через podval_brand())
check "телефон строкой в коде"   "'\+?375[ (]*[0-9]{2}[) ]*[0-9][0-9 -]{6,}'" 'tests/|placeholder'
check "e-mail строкой в коде"    "'[a-z0-9._-]+@[a-z0-9-]+\.[a-z]{2,}'" 'tests/|example\.|\.test'
check "УНП/IBAN строкой в коде"  "УНП:? *[0-9]{9}|IBAN|р/с *BY"

# 4. Имена файлов
names="$(find "${SCOPE[@]}" -iname '*merch*' -o -iname '*geek*' -o -iname '*m12*' -o -iname '*wto*' -o -iname '*mpc*')"
if [[ -n "$names" ]]; then echo "✗ имена файлов"; echo "$names"; fail=1; else echo "✓ имена файлов"; fi

# 5. Метаданные картинок
meta="$(grep -laiE 'geek|photoshop|adobe|xmp' $(find "${SCOPE[@]}" -name '*.png' -o -name '*.jpg' -o -name '*.svg') 2>/dev/null || true)"
if [[ -n "$meta" ]]; then echo "✗ метаданные картинок"; echo "$meta"; fail=1; else echo "✓ метаданные картинок"; fi

exit $fail
