# Подвальный Принт

Код сайта «Подвальный Принт»: печать на одежде (DTF), конструктор своего дизайна, авторские коллекции.
WordPress + WooCommerce.

| Компонент | Путь |
|---|---|
| Тема **Podval Print** | `theme/podval-theme` |
| Плагин **Podval Telegram Orders** — заказы в Telegram, Mini App | `plugins/podval-telegram-orders` |
| Плагин **Podval — СДЭК Доставка** | `plugins/podval-cdek` |
| Плагин **Podval Print Cleanup** — очистка файлов принтов | `plugins/podval-print-cleanup` |

## Где что лежит

- `theme/podval-theme/inc/brand.php` — название, реквизиты, контакты, адреса служебных страниц. Единственное место, где они заданы.
- `theme/podval-theme/inc/copy-data.php` — маркетинговые и SEO-тексты шаблонов. Пустой блок не выводится.
- `theme/podval-theme/inc/landings/` — определения SEO-лендингов, по файлу на страницу (`_example.php` — образец).
- `content/` — тексты страниц и лендингов в Markdown до заливки на сайт.
- `seo/` — ядро запросов, карта URL.
- `docs/` — чек-лист запуска, отчёт об импорте кода.
- `scripts/` — сборка и аудит.

## Сборка и проверка

```bash
scripts/build.sh            # минификация JS + ZIP темы и плагинов в dist/
scripts/build.sh --no-zip   # только минификация JS
scripts/audit.sh            # аудит следов и зашитых реквизитов, код возврата 1 — есть находки
```

После правки любого файла из `assets/js/`, у которого есть пара `.min.js`, пересоберите его: тема отдаёт `.min.js`, если он лежит рядом.

## Деплой

Каждая папка ставится на сайт как есть:

- `theme/podval-theme` → `wp-content/themes/podval-theme`
- `plugins/<имя>` → `wp-content/plugins/<имя>`

На сайт попадают только эти папки. `docs/`, `scripts/`, `content/`, `seo/` не выкладываются.

## Правила

- Бренд, контакты и реквизиты в коде — только через `podval_brand()` и `podval_url()`.
- Тексты для посетителей — в `inc/copy-data.php`, `inc/landings/` или в редакторе WordPress, не в шаблонах.
- Ключи API, токены ботов и пароли в репозиторий не коммитим — они хранятся в настройках WordPress и секретах GitHub.
- Версию поднимаем в `style.css` темы и в главном файле плагина.
