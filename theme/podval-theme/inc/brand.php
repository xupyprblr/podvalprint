<?php
/**
 * Бренд, реквизиты и адреса страниц — единственное место, где они заданы.
 *
 * Правила:
 *  - пустое значение означает «не выводить»: шаблоны сами прячут пустые блоки;
 *  - в шаблонах и модулях бренд и контакты берутся только через podval_brand()
 *    и podval_url(), строкой в коде их писать нельзя (scripts/audit.sh это ловит);
 *  - значения можно переопределить фильтром podval_brand из mu-плагина окружения.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

/** Значения по умолчанию. Заполняются перед запуском (docs/launch-checklist.md). */
function podval_brand_defaults(): array {
	return array(
		// Название
		'name'           => 'Подвальный Принт',
		'name_latin'     => 'Podval Print',
		'logo_word_1'    => 'Подвальный',
		'logo_word_2'    => 'Принт',
		'tagline'        => '',
		'description'    => '', // одно-два предложения для разметки LocalBusiness

		// Юридические данные (оферта, политика, подвал, письма)
		'legal_name'     => '',
		'unp'            => '',
		'legal_address'  => '',
		'postal_address' => '',
		'trade_register' => '',
		'bank_details'   => '',

		// Контакты и точка выдачи
		'phone'          => '',
		'email'          => '',
		'telegram'       => '',
		'instagram'      => '',
		'city'           => 'Минск',
		'country'        => 'BY',
		'street_address' => '',
		'address'        => '',
		'hours'          => array(),
		'map_embed_url'  => '',
		'geo_lat'        => '',
		'geo_lng'        => '',

		// Хосты разработки: закрыты от индексации, цены-заглушки видны.
		'dev_hosts'      => array(),

		// Адреса служебных страниц (слаги страниц WordPress).
		'routes'         => array(
			'about'    => '/o-nas/',
			'contacts' => '/kontakty/',
			'delivery' => '/dostavka-i-oplata/',
			'returns'  => '/garantiya-i-vozvrat/',
			'oferta'   => '/oferta/',
			'privacy'  => '/politika-konfidencialnosti/',
			'bundles'  => '/nabory/',
		),
	);
}

/**
 * Значение бренда по ключу.
 *
 * @param string $key     Ключ из podval_brand_defaults().
 * @param mixed  $default Что вернуть, если значение пустое.
 * @return mixed
 */
function podval_brand( string $key, $default = '' ) {
	static $brand = null;
	if ( null === $brand ) {
		$brand = podval_brand_defaults();
		if ( function_exists( 'apply_filters' ) ) {
			$brand = (array) apply_filters( 'podval_brand', $brand );
		}
	}
	if ( ! isset( $brand[ $key ] ) || '' === $brand[ $key ] || array() === $brand[ $key ] ) {
		return $default;
	}
	return $brand[ $key ];
}

/** Телефон в формате для tel: — только цифры и плюс. */
function podval_brand_tel( string $phone = '' ): string {
	$phone = '' !== $phone ? $phone : (string) podval_brand( 'phone' );
	return (string) preg_replace( '/[^\d+]/', '', $phone );
}

/**
 * URL служебной страницы по ключу маршрута.
 *
 * @param string $route  Ключ из 'routes'.
 * @param string $anchor Якорь без решётки.
 */
function podval_url( string $route, string $anchor = '' ): string {
	$routes = (array) podval_brand( 'routes', array() );
	$path   = isset( $routes[ $route ] ) ? (string) $routes[ $route ] : '/';
	$url    = home_url( $path );
	return '' !== $anchor ? $url . '#' . $anchor : $url;
}

/** Слаг служебной страницы (без слэшей) — для регистрации CPT и поиска страниц. */
function podval_route_slug( string $route ): string {
	$routes = (array) podval_brand( 'routes', array() );
	return isset( $routes[ $route ] ) ? trim( (string) $routes[ $route ], '/' ) : '';
}
