# Подвальный Принт — памятка для работы с кодом

Сайт печати на одежде в Беларуси. WordPress + WooCommerce, своя тема и три плагина.
Цель сайта — органика в Google по Беларуси, поэтому скорость, разметка и уникальность текстов важнее украшений.

## Жёсткие правила

1. Бренд, телефон, e-mail, адрес, УНП и прочие реквизиты — только через `podval_brand( 'ключ' )`
   (`theme/podval-theme/inc/brand.php`). Строкой в шаблонах и модулях их писать нельзя.
2. Ссылки на служебные страницы — `podval_url( 'privacy' )` и т. п., не `home_url( '/что-то/' )`.
3. Тексты для посетителей в шаблонах не хранятся. Маркетинговые и SEO-блоки — `inc/copy-data.php`
   через `podval_copy()`, лендинги — `inc/landings/<слаг>.php`, служебные страницы — в редакторе WordPress.
   Тексты пишутся с нуля под этот бренд; чужие тексты не переносятся и не перефразируются.
4. Цены в текстах — шорткодом `[podval_price key="…"]` из реестра `inc/prices.php`, не числом.
5. Перед коммитом: `scripts/audit.sh` (должен пройти), `php -l` для изменённых PHP, `node --check` для JS,
   `scripts/build.sh --no-zip` после правки JS с парой `.min.js`.
6. Секреты не коммитим.

## Префиксы

| Что | Префикс |
|---|---|
| Тема: функции, хуки, опции, мета, CSS-классы, handle'ы | `podval_`, `PODVAL_`, `_podval_`, `podval-` |
| Плагин Telegram-заказов | `ptg_`, `PTG_` |
| Плагин очистки файлов принтов | `pfc_`, `PFC_` |
| Плагин СДЭК | `podval_cdek_` |
| Text domain темы | `podval-print` |
| REST | `podval/v1` |

## Карта темы

- `functions.php` — подключение модулей, WooCommerce-хуки, AJAX.
- `inc/brand.php`, `inc/copy.php`, `inc/copy-data.php` — бренд и тексты.
- `inc/service-landing*.php`, `template-parts/service/` — движок SEO-лендингов (секции, калькулятор, Schema.org).
- `inc/prices.php` — реестр цен.
- `inc/print-templates.php`, `inc/tpl-builder.php`, `page-tpl-builder.php`, `assets/js/tpl-builder.js` — конструктор шаблонов принтов.
- `page-artwork-upload.php` — загрузка своего макета.
- `inc/cpt-bundle.php`, `inc/cart-bundle.php` — наборы со скидкой.
- `inc/seo.php` — LocalBusiness, BreadcrumbList, FAQPage.
- `inc/google-feed.php` — фид Google Merchant.
- `inc/analytics.php` — GA4, Метрика, цели.

## Проверка в браузере

Локальный стенд собирается из WordPress, WooCommerce и плагина SQLite Database Integration без MySQL.
После правок шаблонов нужно открыть главную, карточку товара, лендинг, корзину и админ-раздел сайта
и убедиться, что нет ошибок PHP в `wp-content/debug.log` и ошибок JS в консоли.

## Известное состояние

См. `docs/import-audit.md`: что осталось сделать до запуска и какие части ещё ждут своих текстов и дизайна.
