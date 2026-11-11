# Чек-лист запуска

## Конфиг бренда — `theme/podval-theme/inc/brand.php`

- [ ] `legal_name`, `unp`, `legal_address`, `postal_address`, `trade_register`, `bank_details`
- [ ] `phone`, `email`, `telegram`, `instagram`
- [ ] `street_address`, `address`, `hours`, `map_embed_url`, `geo_lat`, `geo_lng`
- [ ] `tagline`, `description`
- [ ] `dev_hosts` — домен стейджинга

## Окружение

- [ ] Свой хостинг, свои аккаунты: Search Console, GA4, Яндекс Метрика и Вебмастер, Merchant Center
- [ ] Свой Telegram-бот и его токен в настройках плагина
- [ ] Свои ключи эквайринга и СДЭК
- [ ] На стейджинге `WP_ENVIRONMENT_TYPE=staging` и пароль; на проде `production`
- [ ] Постоянные ссылки: `/%postname%/`; базы категорий и товаров WooCommerce заданы по карте URL

## Содержимое

- [ ] Тексты в `inc/copy-data.php`
- [ ] Лендинги первой волны в `inc/landings/`, созданы сидером и опубликованы
- [ ] Служебные страницы созданы со слагами из `routes` в `inc/brand.php`
- [ ] Цены в `inc/prices.php`
- [ ] Свои шаблоны принтов в `inc/print-templates.php`
- [ ] Фирменные иконки Mini App и скриншот темы

## Перед открытием индексации

- [ ] `scripts/audit.sh` проходит
- [ ] HTTPS, один вариант домена, 301 со всех остальных
- [ ] У каждой страницы один H1, свои title и description
- [ ] Разметка проходит Rich Results Test
- [ ] PageSpeed на мобильном: главная, лендинг, товар, конструктор
- [ ] Тестовый заказ: конструктор → корзина → оплата → Telegram → письмо
- [ ] Карты сайта отправлены в Search Console, Bing и Яндекс Вебмастер
