<?php
/**
 * Реестр цен — слой API.
 *
 * В этом релизе только чтение: дефолты в коде по ответам Alex + фильтр
 * `podval_prices`. DTF-таблицы читаются из Customizer (podval_dtf_table1/2)
 * и не дублируются.
 *
 * Сигнатуры функций ниже — контракт для TASK-price-center.md: экран
 * управления ценами обязан их сохранить.
 *
 * Заглушки: изделие с 'placeholder' => true на проде (! podval_is_dev_host())
 * считается изделием без цены. На деве заглушки видны как обычные цены.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

/** Дефолт podval_dtf_table2 — тот же, что в customizer.php и single-product-service.php. */
const PODVAL_PRICES_T2_DEFAULT = "10|9|8|7|Договорная\n12|11|10|9|Договорная\n15|13|11|10|Договорная\n20|18|16|14|Договорная";
/** Дефолт podval_dtf_table1. */
const PODVAL_PRICES_T1_DEFAULT = '40|35|32|30|25';

/**
 * Число из ячейки Customizer: «12,5» → 12.5, «Договорная» / «» / «—» → null.
 */
function podval_prices_parse_num( $v ): ?float {
	$v = trim( (string) $v );
	$v = str_replace( array( ' ', "\xc2\xa0", 'р', 'Br', 'BYN' ), '', $v );
	$v = str_replace( ',', '.', $v );
	if ( $v === '' || ! is_numeric( $v ) ) {
		return null;
	}
	$f = (float) $v;
	return $f >= 0 ? $f : null;
}

/**
 * podval_dtf_table2 → [ 'A6' => [5 × ?float], … 'A3' => […] ].
 * Строки через \n, колонки через |. Пустая настройка → дефолт.
 */
function podval_prices_parse_transfer( $raw ): array {
	$raw = trim( str_replace( "\r", '', (string) $raw ) );
	if ( $raw === '' ) {
		$raw = PODVAL_PRICES_T2_DEFAULT;
	}
	$rows  = array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ), 'strlen' ) );
	$sizes = array( 'A6', 'A5', 'A4', 'A3' );
	$out   = array();
	foreach ( $sizes as $i => $size ) {
		$cols = isset( $rows[ $i ] ) ? explode( '|', $rows[ $i ] ) : array();
		$cells = array();
		for ( $t = 0; $t < 5; $t++ ) {
			$cells[] = isset( $cols[ $t ] ) ? podval_prices_parse_num( $cols[ $t ] ) : null;
		}
		$out[ $size ] = $cells;
	}
	return $out;
}

/**
 * podval_dtf_table1 → 5 × ?float. Пустая настройка → дефолт.
 */
function podval_prices_parse_roll( $raw ): array {
	$raw = trim( (string) $raw );
	if ( $raw === '' ) {
		$raw = PODVAL_PRICES_T1_DEFAULT;
	}
	$cols = explode( '|', $raw );
	$out  = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$out[] = isset( $cols[ $i ] ) ? podval_prices_parse_num( $cols[ $i ] ) : null;
	}
	return $out;
}

/**
 * Реестр цен. Static-кэш; podval_prices( true ) — сброс (тесты, Customizer preview).
 */
function podval_prices( bool $reset = false ): array {
	static $data = null;
	if ( $reset ) {
		$data = null;
	}
	if ( null !== $data ) {
		return $data;
	}

	$t1 = function_exists( 'get_theme_mod' ) ? get_theme_mod( 'podval_dtf_table1', PODVAL_PRICES_T1_DEFAULT ) : PODVAL_PRICES_T1_DEFAULT;
	$t2 = function_exists( 'get_theme_mod' ) ? get_theme_mod( 'podval_dtf_table2', PODVAL_PRICES_T2_DEFAULT ) : PODVAL_PRICES_T2_DEFAULT;

	$data = array(
		'currency' => 'BYN',
		'tiers'    => array(
			// note выводится строкой под таблицей: «1–4 шт — в цену входит…».
			array( 'min' => 1,  'max' => 4,    'label' => '1–4',   'note' => 'в цену входит подготовка малого тиража' ),
			array( 'min' => 5,  'max' => 10,   'label' => '5–10' ),
			array( 'min' => 11, 'max' => 20,   'label' => '11–20' ),
			array( 'min' => 21, 'max' => 50,   'label' => '21–50' ),
			array( 'min' => 51, 'max' => null, 'label' => '50+',   'note' => 'цену согласуем индивидуально' ),
		),
		// Цена изделия с ОДНИМ нанесением (печать + перенос включены). От 5 шт
		// действует скидка, её размер обсуждается индивидуально — поэтому в
		// реестр и калькулятор она не заложена: цена здесь — за 1 шт без скидки.
		'garments' => array(
			'classic'    => array( 'label' => 'Футболка классическая', 'price' => 55 ),
			'oversize'   => array( 'label' => 'Футболка оверсайз',     'price' => 75 ),
			'tank'       => array( 'label' => 'Майка',                 'price' => 55 ),
			'longsleeve' => array( 'label' => 'Лонгслив',              'price' => 70 ),
			'hoodie'     => array( 'label' => 'Худи',                  'price' => 150 ),
			'sweatshirt' => array( 'label' => 'Свитшот',               'price' => 55, 'placeholder' => true ),
			'shopper'    => array( 'label' => 'Шоппер',                'price' => 55, 'placeholder' => true ),
			'cap'        => array( 'label' => 'Кепка',                 'price' => 55, 'placeholder' => true ),
		),
		'placements' => array(
			'second_side' => array( 'label' => 'Печать на второй стороне', 'price' => 10 ),
			'sleeve'      => array( 'label' => 'Печать на рукаве',          'price' => 5, 'max' => 2 ),
		),
		'transfer' => podval_prices_parse_transfer( $t2 ),
		// Размеры принтов в сантиметрах — общие для переноса и раскладки на плёнке.
		'sizes'    => array(
			'A6' => array( 'w' => 10.5, 'h' => 14.8, 'label' => '10×15 см' ),
			'A5' => array( 'w' => 14.8, 'h' => 21.0, 'label' => '15×21 см' ),
			'A4' => array( 'w' => 21.0, 'h' => 29.7, 'label' => '21×30 см' ),
			'A3' => array( 'w' => 29.7, 'h' => 42.0, 'label' => '30×42 см' ),
		),
		'roll'     => array(
			'labels' => array( 'до 1 м', '1–5 м', '5–10 м', '10–50 м', '50+ м' ),
			'price'  => podval_prices_parse_roll( $t1 ),
			// Параметры раскладки. width — рабочая ширина плёнки, gap — зазор между
			// принтами и до края. Расчёт метража — ориентировочный: реальную
			// раскладку делает оператор и она бывает плотнее.
			'width'  => 32.0,
			'gap'    => 0.5,
			// Минимальный метраж в расчёте: колонка «до 1 м» — это цена за метр,
			// меньше метра плёнки не отрезаем.
			'min_m'  => 1.0,
			// Границы тиров по метражу — соответствуют labels выше.
			'tiers'  => array( 1.0, 5.0, 10.0, 50.0 ),
		),
	);

	if ( function_exists( 'apply_filters' ) ) {
		$data = apply_filters( 'podval_prices', $data );
	}
	return $data;
}

/** Метка версии цен — пишется в заказ рядом с расчётом. */
function podval_prices_version(): string {
	return substr( md5( (string) json_encode( podval_prices() ) ), 0, 10 );
}

/**
 * Индекс тира 0…4 для количества.
 */
function podval_price_tier( int $qty ): int {
	$qty   = max( 1, $qty );
	$tiers = podval_prices()['tiers'];
	foreach ( $tiers as $i => $t ) {
		if ( $qty >= (int) $t['min'] && ( null === $t['max'] || $qty <= (int) $t['max'] ) ) {
			return (int) $i;
		}
	}
	return count( $tiers ) - 1;
}

/** Заглушка ли цена изделия (без учёта хоста). */
function podval_price_garment_is_placeholder( string $key ): bool {
	$g = podval_prices()['garments'][ $key ] ?? null;
	return is_array( $g ) && ! empty( $g['placeholder'] );
}

/**
 * Цена изделия с одним нанесением. null — цены нет или это заглушка на проде.
 * $qty зарезервирован под экран цен и сейчас на результат не влияет.
 */
function podval_price_garment( string $key, int $qty = 1 ): ?float {
	$g = podval_prices()['garments'][ $key ] ?? null;
	if ( ! is_array( $g ) || ! isset( $g['price'] ) || ! is_numeric( $g['price'] ) ) {
		return null;
	}
	if ( ! empty( $g['placeholder'] ) && ! ( function_exists( 'podval_is_dev_host' ) && podval_is_dev_host() ) ) {
		return null;
	}
	return (float) $g['price'];
}

/** Доплата за нанесение на нашем изделии. */
function podval_price_placement( string $key ): ?float {
	$p = podval_prices()['placements'][ $key ] ?? null;
	if ( ! is_array( $p ) || ! isset( $p['price'] ) || ! is_numeric( $p['price'] ) ) {
		return null;
	}
	return (float) $p['price'];
}

/**
 * Индекс тира рулона по метражу (0…4) — границы из реестра.
 */
function podval_price_roll_tier( float $metres ): int {
	$bounds = podval_prices()['roll']['tiers'];
	foreach ( $bounds as $i => $max ) {
		if ( $metres <= $max ) {
			return (int) $i;
		}
	}
	return count( $bounds );
}

/** Цена метра плёнки при данном метраже; null — «Договорная». */
function podval_price_roll_rate( float $metres ): ?float {
	$cell = podval_prices()['roll']['price'][ podval_price_roll_tier( $metres ) ] ?? null;
	return is_numeric( $cell ) ? (float) $cell : null;
}

/**
 * Метраж плёнки под набор принтов.
 *
 * Модель простая и объяснимая клиенту: принты одного размера кладём рядами
 * поперёк рулона. Для каждого размера берём ту из двух ориентаций, при которой
 * на метр влезает больше, — так расчёт не наказывает за «неудобный» поворот.
 * Зазор учитывается и между принтами, и до края: n = ⌊(width + gap) / (w + gap)⌋.
 *
 * @param array $counts [ 'A3' => 2, 'A4' => 5, … ]
 * @return array m (метры, вверх до 0,1), rows — по размерам: per_row, rows, cm
 */
function podval_price_roll_metres( array $counts ): array {
	$reg   = podval_prices();
	$roll  = $reg['roll'];
	$width = (float) $roll['width'];
	$gap   = (float) $roll['gap'];
	$cm    = 0.0;
	$rows  = array();

	foreach ( $reg['sizes'] as $key => $size ) {
		$n = max( 0, (int) ( $counts[ $key ] ?? 0 ) );
		if ( ! $n ) {
			continue;
		}
		$best = null;
		foreach ( array( array( $size['w'], $size['h'] ), array( $size['h'], $size['w'] ) ) as $o ) {
			$per_row = (int) floor( ( $width + $gap ) / ( $o[0] + $gap ) );
			if ( $per_row < 1 ) {
				continue; // не влезает поперёк рулона в этой ориентации
			}
			$row_cm = $o[1] + $gap;
			if ( null === $best || ( $row_cm / $per_row ) < ( $best['row_cm'] / $best['per_row'] ) ) {
				$best = array( 'per_row' => $per_row, 'row_cm' => $row_cm );
			}
		}
		if ( null === $best ) {
			continue;
		}
		$r   = (int) ceil( $n / $best['per_row'] );
		$len = $r * $best['row_cm'];
		$cm += $len;
		$rows[ $key ] = array( 'qty' => $n, 'per_row' => $best['per_row'], 'rows' => $r, 'cm' => $len );
	}

	return array( 'm' => ceil( ( $cm / 100 ) * 10 ) / 10, 'rows' => $rows );
}

/** Печать + перенос на вещь клиента за один принт. */
function podval_price_transfer( string $size, int $qty ): ?float {
	$row = podval_prices()['transfer'][ strtoupper( $size ) ] ?? null;
	if ( ! is_array( $row ) ) {
		return null;
	}
	$cell = $row[ podval_price_tier( $qty ) ] ?? null;
	return is_numeric( $cell ) ? (float) $cell : null;
}

/** Минимальная цена за 1 шт среди изделий с ценой. */
function podval_price_from( array $garment_keys ): ?float {
	$min = null;
	foreach ( $garment_keys as $key ) {
		$p = podval_price_garment( (string) $key );
		if ( null !== $p && ( null === $min || $p < $min ) ) {
			$min = $p;
		}
	}
	return $min;
}

/**
 * Значение реестра по пути: 'garments.classic' → price, 'placements.sleeve',
 * 'transfer.A4.1' → ячейка тира, 'roll.0'. Заглушки на проде → null.
 */
function podval_price_get( string $path ) {
	$parts = array_values( array_filter( explode( '.', trim( $path ) ), 'strlen' ) );
	if ( ! $parts ) {
		return null;
	}
	switch ( $parts[0] ) {
		case 'garments':
			return isset( $parts[1] ) ? podval_price_garment( $parts[1] ) : null;
		case 'placements':
			return isset( $parts[1] ) ? podval_price_placement( $parts[1] ) : null;
		case 'transfer':
			if ( ! isset( $parts[1], $parts[2] ) || ! ctype_digit( $parts[2] ) ) {
				return null;
			}
			$row = podval_prices()['transfer'][ strtoupper( $parts[1] ) ] ?? null;
			$c   = is_array( $row ) ? ( $row[ (int) $parts[2] ] ?? null ) : null;
			return is_numeric( $c ) ? (float) $c : null;
		case 'roll':
			if ( ! isset( $parts[1] ) || ! ctype_digit( $parts[1] ) ) {
				return null;
			}
			$c = podval_prices()['roll']['price'][ (int) $parts[1] ] ?? null;
			return is_numeric( $c ) ? (float) $c : null;
	}
	return null;
}

/** 55.0 → «55», 62.5 → «62,5». Для текстов (шорткод, сводки). */
function podval_price_num( ?float $v ): string {
	if ( null === $v ) {
		return '';
	}
	$s = number_format( $v, ( abs( $v - round( $v ) ) < 0.001 ) ? 0 : 2, ',', ' ' );
	return preg_replace( '/,(\d)0$/', ',$1', $s );
}

/**
 * Округление до 0,5 Br.
 * Сначала до 9 знаков: убирает хвост float (25.25 * 2 = 50.499999…) одинаково
 * в PHP и в JS-копии (service-landing.js), иначе на границе половинок PHP
 * (с его pre-rounding) и Math.round разошлись бы на 0,5 Br.
 */
function podval_price_round_half( float $v ): float {
	return round( round( $v * 2, 9 ) ) / 2;
}

/**
 * Шорткод [podval_price key="garments.classic"] → «55» (без валюты).
 * Неизвестный ключ или цены нет → пустая строка + error_log.
 */
function podval_price_shortcode( $atts ): string {
	$key = is_array( $atts ) && isset( $atts['key'] ) ? (string) $atts['key'] : '';
	$v   = podval_price_get( $key );
	if ( null === $v ) {
		error_log( '[podval-theme] [podval_price] unknown key or no price: "' . $key . '"' );
		return '';
	}
	return podval_price_num( (float) $v );
}
if ( function_exists( 'add_shortcode' ) ) {
	add_shortcode( 'podval_price', 'podval_price_shortcode' );
}

/**
 * Расчёт калькулятора — один для серверного HTML, пересчёта заявки и тестов.
 * JS-копия в assets/js/service-landing.js обязана давать те же числа.
 *
 * @param array $p       Параметры: mode, garment, second, sleeves, size1, size2, qty.
 * @param array $allowed Белые списки лендинга: garments[], placements[], own(bool).
 * @return array|null    null — режим недоступен на этом лендинге.
 */
function podval_price_calc( array $p, array $allowed ): ?array {
	$garments   = array_values( array_filter( (array) ( $allowed['garments'] ?? array() ), function ( $k ) {
		return null !== podval_price_garment( (string) $k );
	} ) );
	$placements = (array) ( $allowed['placements'] ?? array() );
	$own        = ! empty( $allowed['own'] );

	$roll = ! empty( $allowed['roll'] );

	$modes = array(
		'ours' => (bool) $garments,
		'own'  => $own,
		'roll' => $roll,
	);
	$mode = (string) ( $p['mode'] ?? '' );
	if ( empty( $modes[ $mode ] ) ) {
		// Режим недоступен на этом лендинге — берём первый включённый.
		$mode = '';
		foreach ( $modes as $k => $on ) {
			if ( $on ) {
				$mode = $k;
				break;
			}
		}
		if ( '' === $mode ) {
			return null;
		}
	}

	$qty = (int) ( $p['qty'] ?? 1 );
	$qty = max( 1, min( 100000, $qty ) );

	$res = array(
		'mode'       => $mode,
		'qty'        => $qty,
		'tier'       => podval_price_tier( $qty ),
		'unit'       => null,
		'total'      => null,
		'from'       => false,
		'hint'       => null,
		'prices_ver' => podval_prices_version(),
	);

	$sleeves = (int) ( $p['sleeves'] ?? 0 );

	if ( 'roll' === $mode ) {
		// Трансферы на плёнке: считаем метраж по набору принтов, цену — по метру.
		$counts = array();
		foreach ( array_keys( podval_prices()['sizes'] ) as $size ) {
			$counts[ $size ] = max( 0, min( 100000, (int) ( $p['counts'][ $size ] ?? 0 ) ) );
		}
		$nest = podval_price_roll_metres( $counts );
		$min  = (float) podval_prices()['roll']['min_m'];
		$m    = (float) $nest['m'];
		$bill = max( $min, $m );
		$rate = podval_price_roll_rate( $bill );

		$res['counts']  = $counts;
		$res['qty']     = array_sum( $counts );
		$res['metres']  = $m;
		$res['billed']  = $bill;
		$res['rows']    = $nest['rows'];
		$res['tier']    = podval_price_roll_tier( $bill );
		$res['unit']    = $rate;                                        // цена за метр
		$res['total']   = null !== $rate ? podval_price_round_half( $rate * $bill ) : null;
		if ( ! $res['qty'] ) {
			$res['total'] = null;
		} elseif ( $m < $min ) {
			$res['hint'] = array( 'type' => 'roll_min', 'm' => $m, 'min' => $min );
		} elseif ( null !== $rate ) {
			$next = null;
			foreach ( podval_prices()['roll']['tiers'] as $bound ) {
				if ( $bill < $bound ) {
					$next = (float) $bound;
					break;
				}
			}
			$next_rate = null !== $next ? podval_price_roll_rate( $next + 0.1 ) : null;
			if ( null !== $next && null !== $next_rate && $next_rate < $rate ) {
				$res['hint'] = array( 'type' => 'roll_next', 'm' => round( $next - $bill + 0.1, 1 ), 'from' => $rate, 'to' => $next_rate );
			}
		}
		return $res;
	}

	if ( 'ours' === $mode ) {
		$g = (string) ( $p['garment'] ?? '' );
		if ( ! in_array( $g, $garments, true ) ) {
			$g = $garments[0];
		}
		$second_price = in_array( 'second_side', $placements, true ) ? podval_price_placement( 'second_side' ) : null;
		$sleeve_price = in_array( 'sleeve', $placements, true ) ? podval_price_placement( 'sleeve' ) : null;
		$sleeve_max   = (int) ( podval_prices()['placements']['sleeve']['max'] ?? 2 );

		$second  = ( null !== $second_price ) && ! empty( $p['second'] );
		$sleeves = ( null !== $sleeve_price ) ? max( 0, min( $sleeve_max, $sleeves ) ) : 0;

		$unit = podval_price_garment( $g, $qty ) + ( $second ? $second_price : 0 ) + $sleeves * (float) $sleeve_price;

		$res['garment'] = $g;
		$res['second']  = $second;
		$res['sleeves'] = $sleeves;
		$res['unit']    = podval_price_round_half( $unit );
		$res['total']   = $res['unit'] * $qty;
		// Скидка от 5 шт (размер — индивидуально): до порога — сколько добавить,
		// после — что скидка действует.
		$res['hint'] = $qty >= 5 ? array( 'type' => 'ours_bulk' ) : array( 'type' => 'ours_next', 'n' => 5 - $qty );
		return $res;
	}

	// own — вещь клиента
	$sizes = array( 'A6', 'A5', 'A4', 'A3' );
	$s1    = strtoupper( (string) ( $p['size1'] ?? 'A4' ) );
	$s2    = strtoupper( (string) ( $p['size2'] ?? '' ) );
	if ( ! in_array( $s1, $sizes, true ) ) {
		$s1 = 'A4';
	}
	if ( ! in_array( $s2, $sizes, true ) ) {
		$s2 = '';
	}
	$sleeves = max( 0, min( 2, $sleeves ) );

	$unit_at = function ( int $q ) use ( $s1, $s2, $sleeves ): ?float {
		$a = podval_price_transfer( $s1, $q );
		$b = $s2 !== '' ? podval_price_transfer( $s2, $q ) : 0.0;
		$c = $sleeves ? podval_price_transfer( 'A6', $q ) : 0.0;
		if ( null === $a || null === $b || null === $c ) {
			return null;
		}
		return podval_price_round_half( $a + $b + $sleeves * $c );
	};

	$unit = $unit_at( $qty );

	$res['size1']   = $s1;
	$res['size2']   = $s2;
	$res['sleeves'] = $sleeves;
	$res['unit']    = $unit;
	$res['total']   = null !== $unit ? $unit * $qty : null;

	if ( $qty <= 4 ) {
		$next = $unit_at( 5 );
		if ( null !== $unit && null !== $next && $next < $unit ) {
			$res['hint'] = array( 'type' => 'own_next', 'n' => 5 - $qty, 'from' => $unit, 'to' => $next );
		}
	} elseif ( $qty >= 51 ) {
		$res['hint'] = array( 'type' => 'own_bulk' );
		$res['from'] = null !== $unit; // «до X Br/шт»
	}
	return $res;
}

/**
 * Читаемая сводка расчёта: «Футболка классическая · 2-я сторона · 1 рукав · 10 шт · ≈ 65 Br/шт · 650 Br».
 */
function podval_price_calc_summary( ?array $c, string $lead_time = '' ): string {
	if ( ! $c ) {
		return '';
	}
	$reg   = podval_prices();
	$parts = array();
	if ( 'roll' === $c['mode'] ) {
		$items = array();
		foreach ( (array) ( $c['counts'] ?? array() ) as $size => $n ) {
			if ( $n ) {
				$items[] = $size . ' × ' . $n;
			}
		}
		if ( ! $items ) {
			return ''; // принты не выбраны — в заявку писать нечего
		}
		$parts[] = 'Трансферы на плёнке: ' . implode( ', ', $items );
		$parts[] = '≈ ' . podval_price_num( (float) $c['metres'] ) . ' м';
		if ( null !== $c['total'] ) {
			if ( $c['metres'] < $c['billed'] ) {
				$parts[] = 'к оплате ' . podval_price_num( (float) $c['billed'] ) . ' м (минимум)';
			}
			$parts[] = podval_price_num( (float) $c['unit'] ) . ' Br/м';
			$parts[] = podval_price_num( (float) $c['total'] ) . ' Br';
		} else {
			$parts[] = 'цена — индивидуально';
		}
		if ( $lead_time !== '' ) {
			$parts[] = 'срок ' . $lead_time;
		}
		return implode( ' · ', $parts );
	}
	if ( 'ours' === $c['mode'] ) {
		$parts[] = $reg['garments'][ $c['garment'] ]['label'] ?? $c['garment'];
		if ( ! empty( $c['second'] ) ) {
			$parts[] = '2-я сторона';
		}
	} else {
		$parts[] = 'Своя вещь: принт ' . $c['size1'];
		if ( ! empty( $c['size2'] ) ) {
			$parts[] = '2-й принт ' . $c['size2'];
		}
	}
	if ( ! empty( $c['sleeves'] ) ) {
		$parts[] = $c['sleeves'] . ( 1 === (int) $c['sleeves'] ? ' рукав' : ' рукава' );
	}
	$parts[] = $c['qty'] . ' шт';
	if ( null !== $c['unit'] ) {
		$parts[] = ( ! empty( $c['from'] ) ? 'до ' : '≈ ' ) . podval_price_num( (float) $c['unit'] ) . ' Br/шт';
		$parts[] = podval_price_num( (float) $c['total'] ) . ' Br за ' . $c['qty'] . ' шт';
	} else {
		$parts[] = 'цена — индивидуально';
	}
	if ( $lead_time !== '' ) {
		$parts[] = 'срок ' . $lead_time;
	}
	return implode( ' · ', $parts );
}
