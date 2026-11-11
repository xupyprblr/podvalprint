<?php
/**
 * Лендинги услуг — ядро.
 *
 * Одна сущность: страница WP + шаблон page-service-landing.php + мета _podval_sl
 * (PHP-массив) + _podval_sl_ver. Цен в мете нет — только ключи реестра
 * inc/prices.php.
 *
 *  1. Схема данных (UI мета-бокса, санитизация и дефолты — из одного описания)
 *  2. Чтение: podval_sl_get(), podval_sl_landings(), podval_sl_page_id()
 *  3. Навигация и перелинковка: группы меню, главная, похожие, карта категорий
 *  4. Цены и калькулятор: конфиг data-calc, серверный пересчёт заявки
 *  5. Рендер: podval_sl_render_section(), страховка шаблона, FAQ
 *  6. Schema.org (Service + FAQPage) и крошки Yoast
 *  7. 301-редиректы со старых URL товаров-услуг
 *  8. AJAX podval_sl_nonces (P2)
 *  9. Сброс кэша
 *
 * Мета-бокс — inc/service-landing-admin.php, сидер — inc/service-landing-seed.php.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

const PODVAL_SL_TEMPLATE      = 'page-service-landing.php';
const PODVAL_SL_VER          = 1;
const PODVAL_SL_LIST_KEY     = 'podval_sl_landings_v1';
const PODVAL_SL_CATMAP_KEY   = 'podval_sl_catmap_v1';

/* ═══════════════════════════════════════════
   1. СХЕМА ДАННЫХ
   ═══════════════════════════════════════════ */

/** Группы меню «Услуги» (§6.1). */
function podval_sl_groups(): array {
	return array(
		'clothing'   => 'Печать на одежде',
		'task'       => 'Под задачу',
		'production' => 'Производство',
	);
}

/**
 * Описание полей _podval_sl.
 *
 * Типы: text, textarea, html (wp_kses_post), int, id, bool, select, keys
 * (мультивыбор из options), image, url, date, color_list, text_list, id_list,
 * group (fields), repeater (fields, max).
 */
function podval_sl_schema(): array {
	static $schema = null;
	if ( null !== $schema ) {
		return $schema;
	}

	$garment_opts = array();
	foreach ( podval_prices()['garments'] as $k => $g ) {
		$garment_opts[ $k ] = $g['label'] . ( ! empty( $g['placeholder'] ) ? ' (цена-заглушка)' : '' );
	}
	$placement_opts = array();
	foreach ( podval_prices()['placements'] as $k => $pl ) {
		$placement_opts[ $k ] = $pl['label'];
	}

	$schema = array(
		array( 'key' => 'hero', 'type' => 'group', 'label' => '01 · Hero', 'fields' => array(
			array( 'key' => 'subtitle', 'type' => 'textarea', 'label' => 'Подзаголовок', 'help' => 'Он же description в schema Service.' ),
			array( 'key' => 'facts', 'type' => 'repeater', 'label' => 'Факты', 'max' => 2, 'help' => '«от X Br» подставляется первым автоматически из реестра цен.', 'fields' => array(
				array( 'key' => 'value', 'type' => 'text', 'label' => 'Значение' ),
				array( 'key' => 'label', 'type' => 'text', 'label' => 'Подпись' ),
			) ),
			array( 'key' => 'image_id', 'type' => 'image', 'label' => 'Фото (LCP), 1200×900' ),
			array( 'key' => 'layout', 'type' => 'select', 'label' => 'Как показывать фото', 'default' => 'frame', 'options' => array(
				'frame' => 'В рамке рядом с текстом (4:3, кадрируется по центру)',
				'bleed' => 'Во всю ширину справа, до края экрана',
				'full'  => 'Целиком, без обрезки (высота по пропорциям файла)',
			), 'help' => 'В рамке фото кадрируется под 4:3 — важное не ставьте у краёв. «Во всю ширину» тянет фото до правого края экрана. «Целиком» ничего не режет.' ),
			array( 'key' => 'badge', 'type' => 'text', 'label' => 'Бейдж', 'help' => 'Например: DTF · 100% хлопок' ),
			array( 'key' => 'cta_primary', 'type' => 'group', 'label' => 'Кнопка 1', 'fields' => array(
				array( 'key' => 'label', 'type' => 'text', 'label' => 'Текст', 'default' => 'Создать принт' ),
				array( 'key' => 'product_id', 'type' => 'id', 'label' => 'ID товара', 'help' => 'Ссылка = страница товара. Пусто — кнопки нет.' ),
			) ),
			array( 'key' => 'cta_secondary', 'type' => 'group', 'label' => 'Кнопка 2', 'fields' => array(
				array( 'key' => 'label', 'type' => 'text', 'label' => 'Текст', 'default' => 'Рассчитать тираж' ),
				array( 'key' => 'anchor', 'type' => 'text', 'label' => 'Якорь', 'default' => 'zayavka' ),
			) ),
		) ),
		array( 'key' => 'sections', 'type' => 'sections', 'label' => 'Порядок секций', 'help' => 'Перетаскивать не нужно: стрелки двигают секцию, галочка показывает или скрывает её. Hero всегда первый — в нём H1 и картинка первого экрана. Секция без содержимого не выводится, даже если включена.' ),
		array( 'key' => 'nav', 'type' => 'group', 'label' => 'Навигация', 'fields' => array(
			array( 'key' => 'group', 'type' => 'select', 'label' => 'Колонка меню', 'options' => podval_sl_groups(), 'default' => 'clothing' ),
			array( 'key' => 'order', 'type' => 'int', 'label' => 'Порядок', 'default' => 10 ),
			array( 'key' => 'short_title', 'type' => 'text', 'label' => 'Короткое название', 'help' => 'Для меню и футера. Пусто — H1.' ),
		) ),
		array( 'key' => 'card', 'type' => 'group', 'label' => 'Карточка (хаб, похожие, главная)', 'fields' => array(
			array( 'key' => 'excerpt', 'type' => 'textarea', 'label' => 'Анонс' ),
			array( 'key' => 'lead_time', 'type' => 'text', 'label' => 'Срок', 'default' => '1–3 дня' ),
		) ),
		array( 'key' => 'home', 'type' => 'bool', 'label' => 'Плитка на главной', 'default' => false ),
		array( 'key' => 'categories', 'type' => 'text_list', 'label' => 'Категории товаров (слаги)', 'max' => 10, 'help' => 'С карточек товаров этих категорий будет ссылка на лендинг.' ),
		array( 'key' => 'calc', 'type' => 'group', 'label' => '02 · Калькулятор', 'fields' => array(
			array( 'key' => 'enabled', 'type' => 'bool', 'label' => 'Показывать', 'default' => true ),
			array( 'key' => 'mode_default', 'type' => 'select', 'label' => 'Режим по умолчанию', 'options' => array( 'ours' => 'Наша вещь', 'own' => 'Своя вещь', 'roll' => 'Трансферы на плёнке' ), 'default' => 'ours' ),
			array( 'key' => 'garments', 'type' => 'keys', 'label' => 'Изделия «Наша вещь»', 'options' => $garment_opts ),
			array( 'key' => 'placements', 'type' => 'keys', 'label' => 'Доплаты', 'options' => $placement_opts ),
			array( 'key' => 'own', 'type' => 'bool', 'label' => 'Режим «Своя вещь»', 'default' => true ),
			array( 'key' => 'roll', 'type' => 'bool', 'label' => 'Режим «Трансферы на плёнке» (метраж DTF)', 'default' => false, 'help' => 'Метраж считается по числу принтов A6–A3 и ширине плёнки из реестра; цена за метр — из таблицы «DTF — Цены».' ),
			array( 'key' => 'note', 'type' => 'textarea', 'label' => 'Примечание' ),
		) ),
		array( 'key' => 'prices_note', 'type' => 'html', 'label' => '03 · Текст под таблицами цен' ),
		array( 'key' => 'prices_roll', 'type' => 'bool', 'label' => 'Таблица «DTF-трансферы на плёнке» (за метр)', 'default' => false ),
		array( 'key' => 'base_items', 'type' => 'repeater', 'label' => '04 · База', 'max' => 8, 'fields' => array(
			array( 'key' => 'title', 'type' => 'text', 'label' => 'Модель' ),
			array( 'key' => 'garment', 'type' => 'select', 'label' => 'Изделие реестра', 'options' => array( '' => '—' ) + $garment_opts ),
			array( 'key' => 'fabric', 'type' => 'text', 'label' => 'Ткань' ),
			array( 'key' => 'density', 'type' => 'text', 'label' => 'Плотность' ),
			array( 'key' => 'colors', 'type' => 'color_list', 'label' => 'Цвета (#hex)', 'max' => 12 ),
			array( 'key' => 'sizes', 'type' => 'text', 'label' => 'Размеры', 'default' => 'XS–3XL' ),
			array( 'key' => 'image_id', 'type' => 'image', 'label' => 'Фото' ),
			array( 'key' => 'note', 'type' => 'text', 'label' => 'Примечание' ),
			array( 'key' => 'product_id', 'type' => 'id', 'label' => 'ID товара для заказа', 'help' => 'Карточка станет ссылкой на конструктор. Пусто — берётся товар из кнопки 1 в Hero.' ),
		) ),
		array( 'key' => 'tech', 'type' => 'repeater', 'label' => '05 · Технологии', 'max' => 4, 'fields' => array(
			array( 'key' => 'name', 'type' => 'text', 'label' => 'Название' ),
			array( 'key' => 'best_for', 'type' => 'text', 'label' => 'Когда выбирать' ),
			array( 'key' => 'pros', 'type' => 'text_list', 'label' => 'Плюсы', 'max' => 6 ),
			array( 'key' => 'cons', 'type' => 'text_list', 'label' => 'Минусы', 'max' => 6 ),
			array( 'key' => 'durability', 'type' => 'text', 'label' => 'Стойкость' ),
			array( 'key' => 'max_size', 'type' => 'text', 'label' => 'Макс. размер' ),
			array( 'key' => 'lead_time', 'type' => 'text', 'label' => 'Срок' ),
		) ),
		array( 'key' => 'steps', 'type' => 'repeater', 'label' => '06 · Как заказать', 'max' => 6, 'fields' => array(
			array( 'key' => 'title', 'type' => 'text', 'label' => 'Шаг' ),
			array( 'key' => 'text', 'type' => 'textarea', 'label' => 'Текст' ),
		) ),
		array( 'key' => 'artwork_reqs', 'type' => 'text_list', 'label' => '06 · Требования к макету', 'max' => 10 ),
		array( 'key' => 'gallery', 'type' => 'repeater', 'label' => '07 · Работы', 'max' => 12, 'fields' => array(
			array( 'key' => 'image_id', 'type' => 'image', 'label' => 'Фото' ),
			array( 'key' => 'alt', 'type' => 'text', 'label' => 'Alt (с запросом)' ),
			array( 'key' => 'caption', 'type' => 'text', 'label' => 'Подпись' ),
		) ),
		array( 'key' => 'products', 'type' => 'group', 'label' => '08 · Готовые принты', 'fields' => array(
			array( 'key' => 'category', 'type' => 'text', 'label' => 'Слаг категории' ),
			array( 'key' => 'limit', 'type' => 'int', 'label' => 'Сколько карточек', 'default' => 8 ),
			array( 'key' => 'title', 'type' => 'text', 'label' => 'Заголовок', 'default' => 'Не хотите рисовать? Готовые принты' ),
		) ),
		array( 'key' => 'reviews', 'type' => 'repeater', 'label' => '09 · Отзывы (Google Maps, без рейтинга)', 'max' => 6, 'fields' => array(
			array( 'key' => 'name', 'type' => 'text', 'label' => 'Имя' ),
			array( 'key' => 'text', 'type' => 'textarea', 'label' => 'Текст' ),
			array( 'key' => 'date', 'type' => 'date', 'label' => 'Дата (ГГГГ-ММ-ДД)' ),
			array( 'key' => 'source_url', 'type' => 'url', 'label' => 'Ссылка на отзыв' ),
		) ),
		array( 'key' => 'faq', 'type' => 'repeater', 'label' => '10 · FAQ', 'max' => 12, 'help' => 'В ответах работает [podval_price key="garments.classic"].', 'fields' => array(
			array( 'key' => 'q', 'type' => 'text', 'label' => 'Вопрос' ),
			array( 'key' => 'a', 'type' => 'html', 'label' => 'Ответ' ),
		) ),
		array( 'key' => 'related', 'type' => 'id_list', 'label' => '13 · Похожие (ID страниц)', 'max' => 6, 'help' => 'Пусто — 3 лендинга из той же колонки меню.' ),
		array( 'key' => 'form', 'type' => 'group', 'label' => '12 · Форма', 'fields' => array(
			array( 'key' => 'enabled', 'type' => 'bool', 'label' => 'Показывать', 'default' => true ),
			array( 'key' => 'title', 'type' => 'text', 'label' => 'Заголовок', 'default' => 'Рассчитаем тираж за час' ),
			array( 'key' => 'subtitle', 'type' => 'textarea', 'label' => 'Подзаголовок', 'default' => 'Опишите задачу и прикрепите макет — ответим в Telegram или по телефону в рабочее время.' ),
		) ),
		array( 'key' => 'local', 'type' => 'group', 'label' => '14 · Локальный блок', 'fields' => array(
			array( 'key' => 'show', 'type' => 'bool', 'label' => 'Показывать', 'default' => true ),
			array( 'key' => 'image_id', 'type' => 'image', 'label' => 'Карта / фото точки', 'help' => 'Адрес, часы и ссылка на карты — из Customizer (футер).' ),
		) ),
	);
	return $schema;
}

/** Дефолт узла схемы. */
function podval_sl_default_for( array $f ) {
	if ( array_key_exists( 'default', $f ) ) {
		return $f['default'];
	}
	switch ( $f['type'] ) {
		case 'group':
			$out = array();
			foreach ( $f['fields'] as $sub ) {
				$out[ $sub['key'] ] = podval_sl_default_for( $sub );
			}
			return $out;
		case 'repeater':
		case 'keys':
		case 'text_list':
		case 'color_list':
		case 'id_list':
			return array();
		case 'bool':
			return false;
		case 'int':
		case 'id':
		case 'image':
			return 0;
		case 'select':
			$keys = array_keys( $f['options'] );
			return (string) ( $keys[0] ?? '' );
		case 'sections':
			$out = array();
			foreach ( podval_sl_sections() as $key ) {
				$out[] = array( 'key' => $key, 'on' => true );
			}
			return $out;
	}
	return '';
}

/** Дефолты всего _podval_sl. */
function podval_sl_defaults(): array {
	return podval_sl_default_for( array( 'type' => 'group', 'fields' => podval_sl_schema() ) );
}

/**
 * Санитизация значения по узлу схемы. Отсутствующее значение → дефолт.
 * Используется и при сохранении, и при чтении (защита от ручной правки меты).
 */
function podval_sl_clean( array $f, $v ) {
	$missing = ( null === $v );
	switch ( $f['type'] ) {
		case 'group':
			$v   = is_array( $v ) ? $v : array();
			$out = array();
			foreach ( $f['fields'] as $sub ) {
				$out[ $sub['key'] ] = podval_sl_clean( $sub, $v[ $sub['key'] ] ?? null );
			}
			return $out;

		case 'repeater':
			if ( ! is_array( $v ) ) {
				return array();
			}
			$out = array();
			foreach ( array_values( $v ) as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$group = array( 'type' => 'group', 'fields' => $f['fields'] );
				$clean = podval_sl_clean( $group, $row );
				// Пустые строки репитера не храним. «Пустая» = совпадает с дефолтами:
				// поля с непустым дефолтом (sizes = XS–3XL) иначе делали бы любую
				// пустую строку «заполненной».
				if ( $clean !== podval_sl_default_for( $group ) ) {
					$out[] = $clean;
				}
				if ( ! empty( $f['max'] ) && count( $out ) >= (int) $f['max'] ) {
					break;
				}
			}
			return $out;

		case 'text':
			return $missing ? podval_sl_default_for( $f ) : sanitize_text_field( (string) $v );
		case 'textarea':
			return $missing ? podval_sl_default_for( $f ) : sanitize_textarea_field( (string) $v );
		case 'html':
			return $missing ? podval_sl_default_for( $f ) : wp_kses_post( (string) $v );
		case 'int':
			return $missing ? podval_sl_default_for( $f ) : (int) $v;
		case 'id':
		case 'image':
			return absint( $v );
		case 'bool':
			return $missing ? (bool) podval_sl_default_for( $f ) : ( ! empty( $v ) && $v !== 'false' && $v !== '0' );
		case 'url':
			return $missing ? '' : esc_url_raw( (string) $v );
		case 'date':
			$v = sanitize_text_field( (string) $v );
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		case 'select':
			$v = (string) $v;
			return array_key_exists( $v, $f['options'] ) ? $v : podval_sl_default_for( $f );
		case 'keys':
			$out = array();
			foreach ( (array) $v as $k ) {
				$k = (string) $k;
				if ( array_key_exists( $k, $f['options'] ) && ! in_array( $k, $out, true ) ) {
					$out[] = $k;
				}
			}
			return $out;
		case 'text_list':
			$out = array();
			foreach ( (array) $v as $s ) {
				$s = sanitize_text_field( (string) $s );
				if ( $s !== '' ) {
					$out[] = $s;
				}
			}
			return ! empty( $f['max'] ) ? array_slice( $out, 0, (int) $f['max'] ) : $out;
		case 'color_list':
			$out = array();
			foreach ( (array) $v as $c ) {
				$c = sanitize_hex_color( trim( (string) $c ) );
				if ( $c ) {
					$out[] = $c;
				}
			}
			return ! empty( $f['max'] ) ? array_slice( $out, 0, (int) $f['max'] ) : $out;
		case 'id_list':
			$out = array_values( array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) );
			return ! empty( $f['max'] ) ? array_slice( $out, 0, (int) $f['max'] ) : $out;

		case 'sections':
			// Порядок берём из сохранённого значения, но состав — всегда из кода:
			// секции, которых больше нет, отбрасываем, а новые (появившиеся в
			// обновлении темы) вставляем на их место по умолчанию. Иначе после
			// апдейта старые страницы молча теряли бы новый блок.
			$known   = podval_sl_sections();
			$saved   = array();
			foreach ( (array) $v as $row ) {
				$key = is_array( $row ) ? (string) ( $row['key'] ?? '' ) : (string) $row;
				if ( ! in_array( $key, $known, true ) || isset( $saved[ $key ] ) ) {
					continue;
				}
				$on = is_array( $row ) ? ! empty( $row['on'] ) : true;
				$saved[ $key ] = array( 'key' => $key, 'on' => $on );
			}
			$out = array();
			foreach ( $known as $i => $key ) {
				if ( isset( $saved[ $key ] ) ) {
					continue;
				}
				// Секция не сохранена (новая в обновлении темы или её убрали из
				// меты руками). Ставим её ПОСЛЕ ближайшей предшествующей соседки
				// по порядку из кода: привязка к последователю ломала бы
				// расстановку, если редактор поднял его наверх.
				$anchor = null;
				$where  = 'after';
				foreach ( array_reverse( array_slice( $known, 0, $i ) ) as $prev ) {
					if ( isset( $saved[ $prev ] ) ) {
						$anchor = $prev;
						break;
					}
				}
				if ( null === $anchor ) {
					$where = 'before';
					foreach ( array_slice( $known, $i + 1 ) as $next ) {
						if ( isset( $saved[ $next ] ) ) {
							$anchor = $next;
							break;
						}
					}
				}
				$saved = podval_sl_insert_section( $saved, $key, $anchor, $where );
			}
			foreach ( $saved as $row ) {
				$out[] = $row;
			}
			// Закреплённая секция — всегда первая и всегда включена.
			usort( $out, function ( $a, $b ) {
				return ( PODVAL_SL_SECTION_PINNED === $b['key'] ) <=> ( PODVAL_SL_SECTION_PINNED === $a['key'] );
			} );
			foreach ( $out as &$row ) {
				if ( PODVAL_SL_SECTION_PINNED === $row['key'] ) {
					$row['on'] = true;
				}
			}
			unset( $row );
			return $out;
	}
	return null;
}

/**
 * Вставка секции в упорядоченную карту рядом с $anchor.
 *
 * @param string      $where 'after' или 'before'.
 * @param string|null $anchor Ключ-ориентир; null — в конец.
 */
function podval_sl_insert_section( array $map, string $key, ?string $anchor, string $where = 'after' ): array {
	$row = array( 'key' => $key, 'on' => true );
	if ( null === $anchor || ! isset( $map[ $anchor ] ) ) {
		$map[ $key ] = $row;
		return $map;
	}
	$out = array();
	foreach ( $map as $k => $v ) {
		if ( 'before' === $where && $k === $anchor ) {
			$out[ $key ] = $row;
		}
		$out[ $k ] = $v;
		if ( 'after' === $where && $k === $anchor ) {
			$out[ $key ] = $row;
		}
	}
	return $out;
}

/** Санитизация всего массива _podval_sl. */
function podval_sl_sanitize( $raw ): array {
	return podval_sl_clean( array( 'type' => 'group', 'fields' => podval_sl_schema() ), is_array( $raw ) ? $raw : array() );
}

/* ═══════════════════════════════════════════
   2. ЧТЕНИЕ
   ═══════════════════════════════════════════ */

/** Страница — лендинг? Шаблон или (при пустом шаблоне) мета _podval_sl. */
function podval_sl_is_landing( $post_id = 0 ): bool {
	$post_id = $post_id ? (int) $post_id : (int) get_queried_object_id();
	if ( ! $post_id || get_post_type( $post_id ) !== 'page' ) {
		return false;
	}
	$tpl = (string) get_page_template_slug( $post_id );
	if ( PODVAL_SL_TEMPLATE === $tpl ) {
		return true;
	}
	return ( '' === $tpl || 'default' === $tpl ) && is_array( get_post_meta( $post_id, '_podval_sl', true ) );
}

/** Текущий запрос — страница-лендинг. */
function podval_sl_is_current(): bool {
	return is_page() && podval_sl_is_landing( get_queried_object_id() );
}

/**
 * Данные лендинга: дефолты + валидация, static-кэш.
 */
function podval_sl_get( $post_id ): array {
	static $cache = array();
	$post_id = (int) $post_id;
	if ( ! isset( $cache[ $post_id ] ) ) {
		$raw               = get_post_meta( $post_id, '_podval_sl', true );
		$cache[ $post_id ] = podval_sl_sanitize( is_array( $raw ) ? $raw : array() );
	}
	return $cache[ $post_id ];
}

/** H1 лендинга = заголовок страницы. */
function podval_sl_h1( $post_id ): string {
	return wp_strip_all_tags( get_the_title( $post_id ) );
}

/**
 * Список опубликованных лендингов (транзиент, сбрасывается podval_flush_all_cache).
 * Цены в транзиент не пишутся — «от X Br» считается из реестра при выводе.
 *
 * @param array $args group, home (bool), exclude (int[]), limit (int).
 * @return array[] id, title, short_title, url, slug, group, order, excerpt, lead_time, image_id, home, categories, garments
 */
function podval_sl_landings( array $args = array() ): array {
	$list = get_transient( PODVAL_SL_LIST_KEY );
	if ( ! is_array( $list ) ) {
		$list = array();
		$q    = new WP_Query( array(
			'post_type'              => 'page',
			'post_status'            => 'publish',
			'posts_per_page'         => 100,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				'relation' => 'OR',
				array( 'key' => '_wp_page_template', 'value' => PODVAL_SL_TEMPLATE ),
				array( 'key' => '_podval_sl', 'compare' => 'EXISTS' ),
			),
			'orderby'                => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
		) );
		foreach ( $q->posts as $p ) {
			if ( ! podval_sl_is_landing( $p->ID ) ) {
				continue;
			}
			$sl     = podval_sl_get( $p->ID );
			$list[] = array(
				'id'          => (int) $p->ID,
				'title'       => podval_sl_h1( $p->ID ),
				'short_title' => $sl['nav']['short_title'],
				'url'         => get_permalink( $p ),
				'slug'        => $p->post_name,
				'group'       => $sl['nav']['group'],
				'order'       => (int) $sl['nav']['order'],
				'excerpt'     => $sl['card']['excerpt'] !== '' ? $sl['card']['excerpt'] : $sl['hero']['subtitle'],
				'lead_time'   => $sl['card']['lead_time'],
				'image_id'    => (int) $sl['hero']['image_id'],
				'home'        => (bool) $sl['home'],
				'categories'  => $sl['categories'],
				'garments'    => $sl['calc']['garments'],
				'own'         => (bool) $sl['calc']['own'],
			);
		}
		usort( $list, function ( $a, $b ) {
			return ( $a['order'] <=> $b['order'] ) ?: strcmp( $a['title'], $b['title'] );
		} );
		set_transient( PODVAL_SL_LIST_KEY, $list, 12 * HOUR_IN_SECONDS );
	}

	$out = array();
	foreach ( $list as $row ) {
		if ( ! empty( $args['group'] ) && $row['group'] !== $args['group'] ) {
			continue;
		}
		if ( isset( $args['home'] ) && (bool) $args['home'] !== $row['home'] ) {
			continue;
		}
		if ( ! empty( $args['exclude'] ) && in_array( $row['id'], array_map( 'intval', (array) $args['exclude'] ), true ) ) {
			continue;
		}
		$out[] = $row;
		if ( ! empty( $args['limit'] ) && count( $out ) >= (int) $args['limit'] ) {
			break;
		}
	}
	return $out;
}

/** ID опубликованного лендинга по слагу; 0 — нет. */
function podval_sl_page_id( string $slug ): int {
	foreach ( podval_sl_landings() as $row ) {
		if ( $row['slug'] === $slug ) {
			return $row['id'];
		}
	}
	return 0;
}

/** URL лендинга по слагу с фолбэком. */
function podval_sl_url( string $slug, string $fallback = '' ): string {
	$id = podval_sl_page_id( $slug );
	if ( $id ) {
		return get_permalink( $id );
	}
	return $fallback !== '' ? $fallback : home_url( '/' );
}

/** URL хаба — архив категории uslugi. */
function podval_sl_hub_url(): string {
	$link = get_term_link( 'uslugi', 'product_cat' );
	return is_wp_error( $link ) ? home_url( '/' ) : $link;
}

/** Контакт Telegram для текстов «напишите нам». */
function podval_sl_tg_url(): string {
	return (string) get_theme_mod( 'podval_header_tg', get_theme_mod( 'podval_footer_tg', '' ) );
}

/* ═══════════════════════════════════════════
   3. НАВИГАЦИЯ И ПЕРЕЛИНКОВКА
   ═══════════════════════════════════════════ */

/** Лендинги по колонкам меню: [ group => [ 'label', 'items' => [ [title,url] ] ] ]. */
function podval_sl_nav_groups(): array {
	$out = array();
	foreach ( podval_sl_groups() as $g => $label ) {
		$items = array();
		foreach ( podval_sl_landings( array( 'group' => $g ) ) as $row ) {
			$items[] = array( 'title' => $row['title'], 'short' => $row['short_title'] ?: $row['title'], 'url' => $row['url'] );
		}
		if ( $items ) {
			$out[ $g ] = array( 'label' => $label, 'items' => $items );
		}
	}
	return $out;
}

/** Ссылки колонки футера «Печать на одежде»: сначала clothing, затем остальные. */
function podval_sl_footer_links( int $limit = 6 ): array {
	$rows = podval_sl_landings( array( 'group' => 'clothing' ) );
	if ( count( $rows ) < $limit ) {
		foreach ( podval_sl_landings() as $row ) {
			if ( 'clothing' !== $row['group'] ) {
				$rows[] = $row;
			}
		}
	}
	return array_slice( $rows, 0, $limit );
}

/** Лендинги для плиток главной. */
function podval_sl_home_cards(): array {
	return podval_sl_landings( array( 'home' => true, 'limit' => 4 ) );
}

/** «Похожие услуги»: related или 3 из той же группы (добивка — из других). */
function podval_sl_related( int $post_id ): array {
	$sl   = podval_sl_get( $post_id );
	$all  = podval_sl_landings();
	$byid = array();
	foreach ( $all as $row ) {
		$byid[ $row['id'] ] = $row;
	}
	$out = array();
	foreach ( $sl['related'] as $rid ) {
		if ( isset( $byid[ $rid ] ) && $rid !== $post_id ) {
			$out[] = $byid[ $rid ];
		}
	}
	if ( $out ) {
		return array_slice( $out, 0, 3 );
	}
	$out = podval_sl_landings( array( 'group' => $sl['nav']['group'], 'exclude' => array( $post_id ), 'limit' => 3 ) );
	if ( count( $out ) < 3 ) {
		$ids = wp_list_pluck( $out, 'id' );
		foreach ( $all as $row ) {
			if ( $row['id'] !== $post_id && ! in_array( $row['id'], $ids, true ) ) {
				$out[] = $row;
			}
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
	}
	return $out;
}

/** Обратная карта product_cat slug → landing_id (транзиент). */
function podval_sl_catmap(): array {
	$map = get_transient( PODVAL_SL_CATMAP_KEY );
	if ( ! is_array( $map ) ) {
		$map = array();
		foreach ( podval_sl_landings() as $row ) {
			foreach ( $row['categories'] as $slug ) {
				if ( ! isset( $map[ $slug ] ) ) {
					$map[ $slug ] = $row['id'];
				}
			}
		}
		set_transient( PODVAL_SL_CATMAP_KEY, $map, 12 * HOUR_IN_SECONDS );
	}
	return $map;
}

/** Лендинг для карточки товара (§6.4) или null. */
function podval_sl_landing_for_product( int $product_id ): ?array {
	if ( has_term( array( 'uslugi', 'custom-design' ), 'product_cat', $product_id ) ) {
		return null;
	}
	$map = podval_sl_catmap();
	if ( ! $map ) {
		return null;
	}
	$terms = get_the_terms( $product_id, 'product_cat' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return null;
	}
	// Своя категория товара приоритетнее родительской: сначала прямые
	// совпадения, затем предки (товары коллекций лежат в дочерних рубриках).
	$slugs = array();
	foreach ( $terms as $t ) {
		$slugs[] = $t->slug;
	}
	foreach ( $terms as $t ) {
		foreach ( get_ancestors( $t->term_id, 'product_cat', 'taxonomy' ) as $anc_id ) {
			$anc = get_term( $anc_id, 'product_cat' );
			if ( $anc instanceof WP_Term ) {
				$slugs[] = $anc->slug;
			}
		}
	}
	foreach ( $slugs as $slug ) {
		if ( isset( $map[ $slug ] ) ) {
			foreach ( podval_sl_landings() as $row ) {
				if ( $row['id'] === $map[ $slug ] ) {
					return $row;
				}
			}
		}
	}
	return null;
}

/* ═══════════════════════════════════════════
   4. ЦЕНЫ И КАЛЬКУЛЯТОР
   ═══════════════════════════════════════════ */

/** Белые списки калькулятора лендинга. */
function podval_sl_calc_allowed( array $sl ): array {
	return array(
		'garments'   => $sl['calc']['garments'],
		'placements' => $sl['calc']['placements'],
		'own'        => (bool) $sl['calc']['own'],
		'roll'       => (bool) $sl['calc']['roll'],
	);
}

/** Изделия лендинга, у которых есть цена (с учётом заглушек). */
function podval_sl_priced_garments( array $garment_keys ): array {
	$out = array();
	foreach ( $garment_keys as $k ) {
		$p = podval_price_garment( (string) $k );
		if ( null !== $p ) {
			$out[ $k ] = $p;
		}
	}
	return $out;
}

/** «от X Br» для лендинга (строка из транзиента или полный массив) — null, если цен нет. */
function podval_sl_price_from( array $landing ): ?float {
	$keys = isset( $landing['calc'] ) ? $landing['calc']['garments'] : ( $landing['garments'] ?? array() );
	return podval_price_from( (array) $keys );
}

/**
 * «от X Br» для hero, карточки и sticky-бара.
 *
 * Основной источник — изделия лендинга (podval_price_from). Если изделия у
 * лендинга не заданы вовсе, а режим «Своя вещь» включён (DTF-печать) — минимальная
 * цена переноса с подписью «за принт». Если изделия заданы, но цен нет
 * (заглушки на проде) — null: «цена по запросу», фейковых цифр нет.
 *
 * @return array|null [ 'value' => float, 'label' => string ]
 */
function podval_sl_from_info( array $garments, bool $own ): ?array {
	$v = podval_price_from( $garments );
	if ( null !== $v ) {
		return array( 'value' => $v, 'label' => 'цена с печатью' );
	}
	if ( ! $garments && $own ) {
		$min = null;
		foreach ( podval_prices()['transfer'] as $row ) {
			foreach ( $row as $cell ) {
				if ( is_numeric( $cell ) && ( null === $min || $cell < $min ) ) {
					$min = (float) $cell;
				}
			}
		}
		if ( null !== $min ) {
			return array( 'value' => $min, 'label' => 'за принт на вашей вещи' );
		}
	}
	return null;
}

/**
 * URL товара для модели из секции «На чём печатаем»: свой товар строки, иначе
 * товар из первой кнопки hero. Пусто — карточка остаётся не кликабельной.
 */
function podval_sl_base_url( array $item, array $sl ): string {
	foreach ( array( (int) ( $item['product_id'] ?? 0 ), (int) $sl['hero']['cta_primary']['product_id'] ) as $pid ) {
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			return (string) get_permalink( $pid );
		}
	}
	return '';
}

/**
 * Подпись цены под вариантом калькулятора: «55,00 Br» / «+10,00 Br» / «—».
 * Тот же HTML строит service-landing.js при пересчёте — держать синхронно.
 */
function podval_sl_opt_price( ?float $v, bool $plus = false ): string {
	if ( null === $v ) {
		return '<small class="sl-seg__price sl-seg__price--na">по запросу</small>';
	}
	if ( 0.0 === $v ) {
		return '<small class="sl-seg__price sl-seg__price--zero">без доплаты</small>';
	}
	return '<small class="sl-seg__price">' . ( $plus ? '+' : '' ) . podval_sl_byn( $v ) . '</small>';
}

/** Подсказка калькулятора — тот же HTML, что строит service-landing.js. */
function podval_sl_calc_hint_html( ?array $calc ): string {
	if ( ! $calc || empty( $calc['hint'] ) ) {
		return '';
	}
	switch ( $calc['hint']['type'] ) {
		case 'roll_min':
			return 'Минимальный отрез — ' . podval_price_num( (float) $calc['hint']['min'] ) . ' м, считаем по нему';
		case 'roll_next':
			return 'Ещё ' . podval_price_num( (float) $calc['hint']['m'] ) . ' м — и метр будет ' . podval_sl_byn( $calc['hint']['from'] ) . ' → <span class="sl-nowrap">' . podval_sl_byn( $calc['hint']['to'] ) . '/м</span>';
		case 'own_next':
			return 'Ещё ' . (int) $calc['hint']['n'] . ' шт — и нанесение ' . podval_sl_byn( $calc['hint']['from'] ) . ' → <span class="sl-nowrap">' . podval_sl_byn( $calc['hint']['to'] ) . '/шт</span>';
		case 'own_bulk':
			return 'Для тиража от 50 шт снизим цену индивидуально';
		case 'ours_next':
			return 'Ещё ' . (int) $calc['hint']['n'] . ' шт — и будет скидка на тираж';
		case 'ours_bulk':
			return 'От 5 шт действует скидка — размер обсудим индивидуально';
	}
	return '';
}

/**
 * Сумма в BYN в формате currency.js: «55,00 Br» внутри [data-price-byn].
 * Другой формат дал бы скачок цифр при первом PodvalCurrency.refresh().
 */
function podval_sl_byn( ?float $v ): string {
	if ( null === $v ) {
		return '';
	}
	return '<span data-price-byn="' . esc_attr( rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' ) ) . '">'
		. esc_html( number_format( $v, 2, ',', '' ) ) . '&nbsp;Br</span>';
}

/**
 * Конфиг калькулятора для data-calc — только уже разрешённые цены.
 * null — калькулятор не выводится.
 */
function podval_sl_calc_config( array $sl ): ?array {
	if ( empty( $sl['calc']['enabled'] ) ) {
		return null;
	}
	$reg      = podval_prices();
	$garments = array();
	foreach ( podval_sl_priced_garments( $sl['calc']['garments'] ) as $k => $price ) {
		$garments[] = array( 'key' => $k, 'label' => $reg['garments'][ $k ]['label'], 'price' => $price );
	}
	$own  = (bool) $sl['calc']['own'];
	$roll = (bool) $sl['calc']['roll'];
	if ( ! $garments && ! $own && ! $roll ) {
		return null;
	}
	$pl = $sl['calc']['placements'];

	$modes = array( 'ours' => (bool) $garments, 'own' => $own, 'roll' => $roll );
	$mode  = $sl['calc']['mode_default'];
	if ( empty( $modes[ $mode ] ) ) {
		foreach ( $modes as $k => $on ) {
			if ( $on ) {
				$mode = $k;
				break;
			}
		}
	}

	$tiers = array();
	foreach ( $reg['tiers'] as $t ) {
		$tiers[] = array( 'min' => (int) $t['min'], 'max' => null === $t['max'] ? null : (int) $t['max'] );
	}

	$sizes = array();
	foreach ( $reg['sizes'] as $key => $size ) {
		$sizes[ $key ] = $size['label'];
	}

	// Переход в конструктор из результата калькулятора — тот же товар, что в
	// первой кнопке hero. Ведём только из режима «Наша вещь»: в остальных
	// конструктор не поможет, там нужна заявка.
	$product = null;
	$pid     = (int) $sl['hero']['cta_primary']['product_id'];
	if ( $pid && 'publish' === get_post_status( $pid ) ) {
		$product = array( 'url' => get_permalink( $pid ), 'label' => 'Собрать в конструкторе' );
	}

	return array(
		'mode'       => $mode,
		'modes'      => $modes,
		'product'    => $product,
		'sizes'      => $sizes,
		'roll'       => $roll ? array(
			'price'  => $reg['roll']['price'],
			'width'  => $reg['roll']['width'],
			'gap'    => $reg['roll']['gap'],
			'minM'   => $reg['roll']['min_m'],
			'tiers'  => array_map( 'floatval', $reg['roll']['tiers'] ),
			'dims'   => array_map( function ( $s ) { return array( 'w' => $s['w'], 'h' => $s['h'] ); }, $reg['sizes'] ),
		) : null,
		'garments'   => $garments,
		'second'     => in_array( 'second_side', $pl, true ) ? podval_price_placement( 'second_side' ) : null,
		'sleeve'     => in_array( 'sleeve', $pl, true ) ? podval_price_placement( 'sleeve' ) : null,
		'sleeveMax'  => (int) ( $reg['placements']['sleeve']['max'] ?? 2 ),
		'transfer'   => $own ? $reg['transfer'] : new stdClass(),
		'tiers'      => $tiers,
		'lead'       => $sl['card']['lead_time'],
	);
}

/** Параметры расчёта по умолчанию для режима. */
function podval_sl_calc_default_params( array $cfg, string $mode ): array {
	if ( 'roll' === $mode ) {
		// Стартовый набор — один A3: показывает и метраж, и минимальный метр.
		$counts = array();
		foreach ( array_keys( $cfg['sizes'] ) as $size ) {
			$counts[ $size ] = 'A3' === $size ? 1 : 0;
		}
		return array( 'mode' => 'roll', 'counts' => $counts, 'qty' => 1 );
	}
	return 'own' === $mode
		? array( 'mode' => 'own', 'size1' => 'A4', 'size2' => '', 'sleeves' => 0, 'qty' => 1 )
		: array( 'mode' => 'ours', 'garment' => $cfg['garments'][0]['key'] ?? '', 'second' => false, 'sleeves' => 0, 'qty' => 1 );
}

/**
 * Пересчёт расчёта из заявки на сервере (§7.2 п.4). Цифрам клиента не доверяем:
 * из sl_calc берутся только параметры, цены — из реестра.
 */
function podval_sl_request_calc( int $landing_id, $raw, int $qty = 0 ): ?array {
	if ( ! $landing_id ) {
		return null;
	}
	$params = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
	if ( ! is_array( $params ) || empty( $params['mode'] ) ) {
		return null;
	}
	$sl = podval_sl_get( $landing_id );
	if ( empty( $sl['calc']['enabled'] ) ) {
		return null;
	}
	$counts = array();
	foreach ( array_keys( podval_prices()['sizes'] ) as $size ) {
		$counts[ $size ] = (int) ( $params['counts'][ $size ] ?? 0 );
	}
	$clean = array(
		'mode'    => in_array( $params['mode'], array( 'own', 'roll' ), true ) ? $params['mode'] : 'ours',
		'counts'  => $counts,
		'garment' => sanitize_key( (string) ( $params['garment'] ?? '' ) ),
		'second'  => ! empty( $params['second'] ),
		'sleeves' => (int) ( $params['sleeves'] ?? 0 ),
		'size1'   => sanitize_text_field( (string) ( $params['size1'] ?? '' ) ),
		'size2'   => sanitize_text_field( (string) ( $params['size2'] ?? '' ) ),
		'qty'     => $qty > 0 ? $qty : (int) ( $params['qty'] ?? 1 ),
	);
	$calc = podval_price_calc( $clean, podval_sl_calc_allowed( $sl ) );
	if ( ! $calc ) {
		return null;
	}
	// Хранимый набор полей (§7.2): mode, garment|sizes, second, sleeves, qty, unit, total, prices_ver
	$stored = array( 'mode' => $calc['mode'] );
	if ( 'roll' === $calc['mode'] ) {
		return array(
			'mode'       => 'roll',
			'counts'     => array_filter( $calc['counts'] ),
			'metres'     => $calc['metres'],
			'billed'     => $calc['billed'],
			'qty'        => $calc['qty'],
			'unit'       => $calc['unit'],
			'total'      => $calc['total'],
			'from'       => false,
			'prices_ver' => $calc['prices_ver'],
		);
	}
	if ( 'ours' === $calc['mode'] ) {
		$stored['garment'] = $calc['garment'];
		$stored['second']  = $calc['second'];
	} else {
		$stored['sizes'] = array_values( array_filter( array( $calc['size1'], $calc['size2'] ) ) );
		$stored['size1'] = $calc['size1'];
		$stored['size2'] = $calc['size2'];
	}
	$stored += array(
		'sleeves'    => $calc['sleeves'],
		'qty'        => $calc['qty'],
		'unit'       => $calc['unit'],
		'total'      => $calc['total'],
		'from'       => $calc['from'],
		'prices_ver' => $calc['prices_ver'],
	);
	return $stored;
}

/* ═══════════════════════════════════════════
   5. РЕНДЕР
   ═══════════════════════════════════════════ */

/**
 * Секции лендинга: ключ => название в мета-боксе. Порядок массива — порядок
 * по умолчанию (§2 ТЗ); на конкретной странице он переопределяется полем
 * «Порядок секций» (_podval_sl['sections']).
 *
 * Новую секцию достаточно добавить сюда и создать
 * template-parts/service/section-{ключ}.php: на уже сохранённых страницах она
 * сама встанет на своё место по умолчанию (см. podval_sl_clean(), тип sections).
 */
function podval_sl_section_labels(): array {
	return array(
		'hero'     => '01 · Hero',
		'calc'     => '02 · Калькулятор',
		'prices'   => '03 · Цены',
		'base'     => '04 · База',
		'tech'     => '05 · Технологии',
		'steps'    => '06 · Как заказать',
		'gallery'  => '07 · Работы',
		'products' => '08 · Готовые принты',
		'reviews'  => '09 · Отзывы',
		'faq'      => '10 · FAQ',
		'seo'      => '11 · SEO-текст',
		'form'     => '12 · Форма заявки',
		'related'  => '13 · Похожие услуги',
		'local'    => '14 · Локальный блок',
	);
}

/** Ключи секций в порядке по умолчанию. */
function podval_sl_sections(): array {
	return array_keys( podval_sl_section_labels() );
}

/**
 * Секция, которая всегда идёт первой и не выключается: в ней H1 и LCP-картинка
 * с fetchpriority="high". Если сдвинуть её вниз, браузер получит приоритетную
 * загрузку картинки, которой нет на первом экране, а H1 уедет из зоны видимости.
 */
const PODVAL_SL_SECTION_PINNED = 'hero';

/**
 * Порядок и видимость секций конкретного лендинга.
 *
 * @return string[] ключи включённых секций в порядке вывода.
 */
function podval_sl_order( array $sl ): array {
	$out = array();
	foreach ( $sl['sections'] as $row ) {
		if ( ! empty( $row['on'] ) ) {
			$out[] = $row['key'];
		}
	}
	return $out;
}

/**
 * Рендер секции template-parts/service/section-{$name}.php.
 * Пустая секция ничего не выводит — проверку делает сам шаблон секции.
 */
function podval_sl_render_section( string $name, array $data ): void {
	if ( ! in_array( $name, podval_sl_sections(), true ) ) {
		return;
	}
	get_template_part( 'template-parts/service/section', $name, $data );
}

/** FAQ с раскрытыми шорткодами — и для HTML, и для JSON-LD. */
function podval_sl_faq_items( array $sl ): array {
	$out = array();
	foreach ( $sl['faq'] as $row ) {
		if ( $row['q'] === '' || $row['a'] === '' ) {
			continue;
		}
		$out[] = array( 'q' => $row['q'], 'a' => do_shortcode( $row['a'] ) );
	}
	return $out;
}

/** Картинка вложения с width/height (CLS). $attrs: class, loading, fetchpriority, sizes, alt. */
function podval_sl_img( int $id, string $size, array $attrs = array() ): string {
	if ( ! $id || ! wp_attachment_is_image( $id ) ) {
		return '';
	}
	$attrs = array_merge( array( 'loading' => 'lazy', 'decoding' => 'async' ), $attrs );
	if ( ! empty( $attrs['fetchpriority'] ) ) {
		$attrs['loading'] = 'eager';
	}
	return wp_get_attachment_image( $id, $size, false, $attrs );
}

/**
 * [podval_sl_link slug="futbolki-s-logotipom"]текст[/podval_sl_link] — ссылка на
 * лендинг только если он опубликован, иначе просто текст. Спецслаги: hub
 * (архив «Услуги»), catalog (каталог), custom-design (конструктор).
 * Нужен SEO-текстам: соседний лендинг может ещё быть черновиком.
 */
add_shortcode( 'podval_sl_link', function ( $atts, $content = '' ) {
	$atts = shortcode_atts( array( 'slug' => '' ), $atts );
	$slug = sanitize_title( $atts['slug'] );
	$text = do_shortcode( (string) $content );
	$url  = '';
	if ( 'hub' === $slug ) {
		$url = podval_sl_hub_url();
	} elseif ( 'catalog' === $slug ) {
		$url = function_exists( 'podval_get_catalog_url' ) ? podval_get_catalog_url() : '';
	} elseif ( 'custom-design' === $slug ) {
		$l   = get_term_link( 'custom-design', 'product_cat' );
		$url = is_wp_error( $l ) ? '' : $l;
	} elseif ( $slug !== '' ) {
		$pid = podval_sl_page_id( $slug );
		$url = ( $pid && $pid !== (int) get_queried_object_id() ) ? get_permalink( $pid ) : '';
	}
	return $url ? '<a href="' . esc_url( $url ) . '">' . $text . '</a>' : $text;
} );

/** Страховка Р1: у страницы есть _podval_sl, а шаблон слетел в «по умолчанию». */
add_filter( 'template_include', function ( $template ) {
	if ( ! is_page() ) {
		return $template;
	}
	$id  = get_queried_object_id();
	$tpl = (string) get_page_template_slug( $id );
	if ( ( '' === $tpl || 'default' === $tpl ) && is_array( get_post_meta( $id, '_podval_sl', true ) ) ) {
		$located = locate_template( PODVAL_SL_TEMPLATE );
		if ( $located ) {
			return $located;
		}
	}
	return $template;
}, 100 );

add_filter( 'body_class', function ( $classes ) {
	if ( podval_sl_is_current() ) {
		$classes[] = 'sl-page';
	}
	return $classes;
} );

/* ═══════════════════════════════════════════
   6. SCHEMA.ORG И КРОШКИ
   ═══════════════════════════════════════════ */

add_action( 'wp_head', 'podval_sl_schema_output', 92 );

/**
 * Service + FAQPage — отдельные JSON-LD, граф Yoast не трогаем (Р5).
 * LocalBusiness / BreadcrumbList / Organization здесь не выводим — они уже есть.
 */
function podval_sl_schema_output(): void {
	if ( ! podval_sl_is_current() ) {
		return;
	}
	$id  = get_queried_object_id();
	$sl  = podval_sl_get( $id );
	$h1  = podval_sl_h1( $id );
	$url = get_permalink( $id );

	$service = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'Service',
		'@id'         => $url . '#service',
		'name'        => $h1,
		'serviceType' => trim( preg_replace( '/\s+в\s+Минске$/u', '', $h1 ) ),
		'url'         => $url,
		'provider'    => array( '@id' => home_url( '/' ) . '#business' ),
		'areaServed'  => array(
			array( '@type' => 'City', 'name' => 'Минск' ),
			array( '@type' => 'Country', 'name' => 'Беларусь' ),
		),
	);
	if ( $sl['hero']['subtitle'] !== '' ) {
		$service['description'] = $sl['hero']['subtitle'];
	}
	if ( $sl['hero']['image_id'] ) {
		$img = wp_get_attachment_image_url( $sl['hero']['image_id'], 'full' );
		if ( $img ) {
			$service['image'] = $img;
		}
	}
	$priced = podval_sl_priced_garments( $sl['calc']['garments'] );
	if ( $priced ) {
		$service['offers'] = array(
			'@type'         => 'AggregateOffer',
			'lowPrice'      => min( $priced ),
			'priceCurrency' => 'BYN',
			'offerCount'    => count( $priced ),
		);
	}
	echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $service, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";

	// FAQPage — только если секция FAQ реально выводится: разметка без видимого
	// на странице контента считается нарушением правил Google.
	$faq = in_array( 'faq', podval_sl_order( $sl ), true ) ? podval_sl_faq_items( $sl ) : array();
	if ( count( $faq ) >= 3 && function_exists( 'podval_seo_faq_schema' ) ) {
		podval_seo_faq_schema( $faq );
	}
}

/** Yoast BreadcrumbList: «Главная → Услуги → Лендинг». */
add_filter( 'wpseo_breadcrumb_links', function ( $links ) {
	if ( ! is_array( $links ) || count( $links ) < 2 || ! podval_sl_is_current() ) {
		return $links;
	}
	$hub = array( 'url' => podval_sl_hub_url(), 'text' => 'Услуги' );
	array_splice( $links, count( $links ) - 1, 0, array( $hub ) );
	return $links;
} );

/* ═══════════════════════════════════════════
   7. 301-РЕДИРЕКТЫ
   ═══════════════════════════════════════════ */

/**
 * Карта 301 (§8.1). page — слаг целевого лендинга: редирект включается только
 * когда лендинг опубликован. url — безусловный редирект (хаб).
 */
function podval_sl_redirect_map(): array {
	// Старых URL у сайта нет. Формат: '/старый/путь/' => array( 'page' => 'слаг-лендинга' ) либо array( 'hub' => true ).
	$map = array();
	return (array) apply_filters( 'podval_sl_redirect_map', $map );
}

/** Нормализация пути: декодирование, нижний регистр, завершающий слэш, без префикса установки. */
function podval_sl_normalize_path( string $path ): string {
	$path = rawurldecode( $path );
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$home = '/' . trim( $home, '/' );
	if ( '/' !== $home && 0 === strpos( $path, $home . '/' ) ) {
		$path = substr( $path, strlen( $home ) );
	}
	$path = '/' . trim( preg_replace( '#/+#', '/', $path ), '/' ) . '/';
	$path = ( '//' === $path ) ? '/' : $path;
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $path, 'UTF-8' ) : strtolower( $path );
}

add_action( 'template_redirect', 'podval_sl_redirects', 1 );

function podval_sl_redirects(): void {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}
	$uri  = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
	$path = wp_parse_url( $uri, PHP_URL_PATH );
	if ( ! is_string( $path ) || $path === '' ) {
		return;
	}
	$norm = podval_sl_normalize_path( $path );

	foreach ( podval_sl_redirect_map() as $from => $rule ) {
		if ( podval_sl_normalize_path( $from ) !== $norm ) {
			continue;
		}
		$target = '';
		if ( ! empty( $rule['page'] ) ) {
			$pid = podval_sl_page_id( (string) $rule['page'] );
			if ( $pid ) {
				$target = get_permalink( $pid );
			}
		} elseif ( ! empty( $rule['hub'] ) ) {
			$target = podval_sl_hub_url();
		} elseif ( ! empty( $rule['url'] ) ) {
			$target = (string) $rule['url'];
		}
		if ( ! $target ) {
			return; // целевой лендинг не опубликован — работает старый URL
		}
		$tpath = wp_parse_url( $target, PHP_URL_PATH );
		if ( is_string( $tpath ) && podval_sl_normalize_path( $tpath ) === $norm ) {
			return; // защита от петли
		}
		$qs = wp_parse_url( $uri, PHP_URL_QUERY );
		if ( $qs ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $qs;
		}
		wp_safe_redirect( $target, 301, 'podval-theme' );
		exit;
	}
}

/* ═══════════════════════════════════════════
   8. AJAX: СВЕЖИЕ NONCE (P2)
   Страница лежит в кэше WP-Optimize дольше жизни nonce гостя (12–24 ч).
   Форма при первом фокусе запрашивает свежие значения.
   ═══════════════════════════════════════════ */

add_action( 'wp_ajax_podval_sl_nonces', 'podval_sl_ajax_nonces' );
add_action( 'wp_ajax_nopriv_podval_sl_nonces', 'podval_sl_ajax_nonces' );

function podval_sl_ajax_nonces(): void {
	nocache_headers();
	wp_send_json_success( array(
		'svc'    => wp_create_nonce( 'podval_service_nonce' ),
		'upload' => wp_create_nonce( 'podval_nonce' ),
	) );
}

/* ═══════════════════════════════════════════
   9. СБРОС КЭША
   Навигация, главная, хаб, футер и карточки читают лендинги из транзиента —
   публикация, снятие с публикации и сохранение меты сбрасывают всё сразу.
   ═══════════════════════════════════════════ */

/** Страница — лендинг или была им (мета осталась после смены шаблона). */
function podval_sl_touches_landings( $post ): bool {
	$post = get_post( $post );
	if ( ! $post || 'page' !== $post->post_type ) {
		return false;
	}
	if ( PODVAL_SL_TEMPLATE === get_page_template_slug( $post ) || metadata_exists( 'post', $post->ID, '_podval_sl' ) ) {
		return true;
	}
	// Страница была в списке опубликованных (например, шаблон сменили).
	$list = get_transient( PODVAL_SL_LIST_KEY );
	return is_array( $list ) && in_array( (int) $post->ID, array_map( 'intval', wp_list_pluck( $list, 'id' ) ), true );
}

add_action( 'save_post_page', function ( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( podval_sl_touches_landings( $post ) && function_exists( 'podval_flush_all_cache' ) ) {
		podval_flush_all_cache( 'service-landing' );
	}
}, 20, 2 );

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( $new === $old || ! ( $post instanceof WP_Post ) || 'page' !== $post->post_type ) {
		return;
	}
	if ( ( 'publish' === $new || 'publish' === $old ) && podval_sl_touches_landings( $post ) && function_exists( 'podval_flush_all_cache' ) ) {
		podval_flush_all_cache( 'service-landing' );
	}
}, 10, 3 );

add_action( 'before_delete_post', function ( $post_id ) {
	if ( podval_sl_touches_landings( $post_id ) && function_exists( 'podval_flush_all_cache' ) ) {
		podval_flush_all_cache( 'service-landing' );
	}
} );

require get_template_directory() . '/inc/service-landing-admin.php';
require get_template_directory() . '/inc/service-landing-seed.php';

/* ═══════════════════════════════════════════
   10. ПИСЬМО «ЗАЯВКА ПРИНЯТА»
   Тело — woocommerce/emails/customer-on-hold-order.php (ветка service).
   Тема и заголовок стандартного on-hold («Ваш заказ ожидает оплаты») для заявки
   неверны — подменяем только для _order_type = service.
   ═══════════════════════════════════════════ */

add_filter( 'woocommerce_email_subject_customer_on_hold_order', function ( $subject, $order ) {
	if ( $order instanceof WC_Order && 'service' === $order->get_meta( '_order_type' ) ) {
		return sprintf( 'Заявка #%s принята — %s', $order->get_order_number(), podval_brand( 'name' ) );
	}
	return $subject;
}, 10, 2 );

add_filter( 'woocommerce_email_heading_customer_on_hold_order', function ( $heading, $order ) {
	if ( $order instanceof WC_Order && 'service' === $order->get_meta( '_order_type' ) ) {
		return 'Заявка принята';
	}
	return $heading;
}, 10, 2 );
