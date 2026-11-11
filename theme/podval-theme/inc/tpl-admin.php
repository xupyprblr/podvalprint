<?php
/**
 * Визуальная настройка конструктора шаблонов.
 *
 * Товары → Конструктор шаблонов. Четыре вкладки:
 *   Основы      — превью из медиатеки, названия, видимость, порядок
 *   Раскладки   — визуальный редактор слотов (drag + resize)
 *   Цены        — суммы надбавок
 *   Оформление  — рукописный шрифт, дефолтный тон фото
 *
 * ВАЖНО: код остаётся источником структуры. В БД (опция podval_tpl_overrides)
 * хранятся ТОЛЬКО переопределения, которые накладываются поверх реестра
 * через фильтр podval_tpl_registry. Новый шаблон по-прежнему добавляется
 * в PHP — админка не создаёт основы с нуля, она их донастраивает.
 *
 * @package Podval Print
 *
 */

defined( 'ABSPATH' ) || exit;

const PODVAL_TPL_OPT = 'podval_tpl_overrides';

/**
 * Текущие оверрайды.
 *
 * @return array
 */
function podval_tpl_overrides() {
	$o = get_option( PODVAL_TPL_OPT, array() );
	return is_array( $o ) ? $o : array();
}

/* ═══════════════════════════════════════════
   1. НАЛОЖЕНИЕ ОВЕРРАЙДОВ НА РЕЕСТР
   ═══════════════════════════════════════════ */

add_filter( 'podval_tpl_registry', function( $registry ) {
	$ov = podval_tpl_overrides();

	/* ── Основы ─────────────────────────────── */
	if ( ! empty( $ov['bases'] ) ) {
		foreach ( $ov['bases'] as $id => $b ) {
			if ( ! isset( $registry[ $id ] ) ) {
				continue;
			}
			if ( isset( $b['enabled'] ) && ! $b['enabled'] ) {
				unset( $registry[ $id ] );
				continue;
			}
			if ( ! empty( $b['title'] ) ) {
				$registry[ $id ]['title'] = $b['title'];
			}
			if ( isset( $b['subtitle'] ) && '' !== $b['subtitle'] ) {
				$registry[ $id ]['subtitle'] = $b['subtitle'];
			}
			if ( ! empty( $b['preview_id'] ) ) {
				$registry[ $id ]['preview_id'] = (int) $b['preview_id'];
			}
			if ( isset( $b['order'] ) ) {
				$registry[ $id ]['order'] = (int) $b['order'];
			}
		}
	}

	/* ── Текстовые поля: пресеты и режим ввода ── */
	if ( ! empty( $ov['texts'] ) ) {
		foreach ( $ov['texts'] as $tpl_id => $fields ) {
			if ( ! isset( $registry[ $tpl_id ] ) ) {
				continue;
			}
			foreach ( $fields as $key => $f ) {
				if ( ! isset( $registry[ $tpl_id ]['texts'][ $key ] ) ) {
					continue;
				}
				if ( isset( $f['presets'] ) && is_array( $f['presets'] ) ) {
					$registry[ $tpl_id ]['texts'][ $key ]['presets'] = array_values( array_filter( $f['presets'] ) );
				}
				if ( isset( $f['locked'] ) ) {
					$registry[ $tpl_id ]['texts'][ $key ]['locked'] = (bool) $f['locked'];
				}
			}

			// Значение по умолчанию должно оставаться валидным для locked-поля.
			foreach ( $registry[ $tpl_id ]['texts'] as $key => $def ) {
				if ( empty( $def['locked'] ) || empty( $def['presets'] ) ) {
					continue;
				}
				if ( ! in_array( $def['default'], $def['presets'], true ) ) {
					$registry[ $tpl_id ]['texts'][ $key ]['default'] = $def['presets'][0];
				}
			}
		}
	}

	/* ── Раскладки ──────────────────────────── */
	if ( ! empty( $ov['layouts'] ) ) {
		foreach ( $ov['layouts'] as $tpl_id => $layouts ) {
			if ( ! isset( $registry[ $tpl_id ] ) ) {
				continue;
			}
			foreach ( $layouts as $lay_id => $lay ) {
				if ( empty( $lay['slots'] ) || ! is_array( $lay['slots'] ) ) {
					continue;
				}
				$slots = array();
				foreach ( array_values( $lay['slots'] ) as $i => $s ) {
					$slots[] = array(
						'key' => 's' . ( $i + 1 ),
						'x'   => podval_tpl_clamp01( $s['x'] ?? 0 ),
						'y'   => podval_tpl_clamp01( $s['y'] ?? 0 ),
						'w'   => podval_tpl_clamp01( $s['w'] ?? 0.5 ),
						'h'   => podval_tpl_clamp01( $s['h'] ?? 0.5 ),
					);
				}
				$new = array(
					'title' => ! empty( $lay['title'] ) ? $lay['title'] : ( count( $slots ) . ' фото' ),
					'slots' => $slots,
				);

				// Пропорция области коллажа задаётся в коде и админкой не правится —
				// переносим её из дефолта, иначе первое же сохранение раскладок
				// превратило бы ровные квадраты в вытянутые прямоугольники.
				$defaults = podval_tpl_registry_defaults();
				if ( isset( $defaults[ $tpl_id ]['layouts'][ $lay_id ]['aspect'] ) ) {
					$new['aspect'] = $defaults[ $tpl_id ]['layouts'][ $lay_id ]['aspect'];
				}

				// Исключение из предела печати 30 × 42 — тоже флаг кода.
				if ( ! empty( $defaults[ $tpl_id ]['layouts'][ $lay_id ]['no_print_cap'] ) ) {
					$new['no_print_cap'] = true;
				}

				// Свободная компоновка строится на лету: флаги сохраняем, иначе
				// случайное сохранение раскладок превратило бы её в фиксированную.
				if ( ! empty( $defaults[ $tpl_id ]['layouts'][ $lay_id ]['free'] ) ) {
					$new['free'] = true;
					$new['max']  = (int) ( $defaults[ $tpl_id ]['layouts'][ $lay_id ]['max'] ?? 9 );
					$new['slots'] = array();
				}

				$registry[ $tpl_id ]['layouts'][ $lay_id ] = $new;
			}
		}
	}

	/* ── Тон фото по умолчанию ──────────────── */
	$tone = $ov['appearance']['default_tone'] ?? '';
	if ( $tone ) {
		foreach ( $registry as $id => $tpl ) {
			if ( isset( $tpl['styles']['photo_tone']['options'][ $tone ] ) ) {
				$registry[ $id ]['styles']['photo_tone']['default'] = $tone;
			}
		}
	}

	/* ── Сортировка основ ───────────────────── */
	uasort( $registry, function( $a, $b ) {
		return ( $a['order'] ?? 100 ) <=> ( $b['order'] ?? 100 );
	} );

	return $registry;
}, 5 );

/* ── Цены надбавок ──────────────────────────── */

add_filter( 'podval_tpl_addons', 'podval_tpl_apply_addon_overrides', 5 );

/**
 * Наложение цен из админки на определения надбавок.
 *
 * @param array $addons Дефолтные надбавки.
 * @return array
 */
function podval_tpl_apply_addon_overrides( $addons ) {
	$ov = podval_tpl_overrides();
	if ( empty( $ov['addons'] ) ) {
		return $addons;
	}
	foreach ( $ov['addons'] as $code => $a ) {
		if ( ! isset( $addons[ $code ] ) ) {
			continue;
		}
		if ( isset( $a['amount'] ) ) {
			$addons[ $code ]['amount'] = (float) $a['amount'];
		}
		if ( isset( $a['enabled'] ) && ! $a['enabled'] && empty( $addons[ $code ]['auto'] ) ) {
			unset( $addons[ $code ] );
		}
	}
	return $addons;
}

/** Сколько слотов под рукописные шрифты показывать в админке. */
define( 'PODVAL_TPL_FONT_SLOTS', 3 );

/**
 * Очистка списка шрифтов из формы.
 *
 * @param array $raw Сырые слоты из POST.
 * @return array Только слоты с адресом файла, с переиндексацией.
 */
function podval_tpl_sanitize_fonts( $raw ) {
	$out = array();
	foreach ( (array) $raw as $slot ) {
		$url = esc_url_raw( $slot['url'] ?? '' );
		if ( ! $url ) {
			continue;
		}
		$out[] = array(
			'id'    => absint( $slot['id'] ?? 0 ),
			'url'   => $url,
			'label' => sanitize_text_field( $slot['label'] ?? '' ),
		);
	}
	return $out;
}

/**
 * Список загруженных рукописных шрифтов, приведённый к одному виду.
 *
 * Раньше шрифт был один (`script_font_url`). Старый ключ читается как
 * первый слот, чтобы уже настроенный сайт после обновления не потерял
 * шрифт и не потребовал ручной перенастройки.
 *
 * @return array Список [ 'url', 'label', 'family' ], только заполненные.
 */
function podval_tpl_script_fonts() {
	$ov    = podval_tpl_overrides();
	$slots = (array) ( $ov['appearance']['script_fonts'] ?? array() );

	// Миграция с одиночного шрифта.
	if ( ! $slots && ! empty( $ov['appearance']['script_font_url'] ) ) {
		$slots = array( array(
			'url'   => $ov['appearance']['script_font_url'],
			'label' => 'Рукописный',
		) );
	}

	$out = array();
	$i   = 0;
	foreach ( $slots as $slot ) {
		$url = $slot['url'] ?? '';
		if ( ! $url ) {
			continue;
		}
		$i++;
		$out[] = array(
			'url'    => $url,
			'label'  => $slot['label'] ?: ( 'Почерк ' . $i ),
			'family' => 'PodvalTplScript' . $i,
		);
	}
	return $out;
}

/* ── Выбор почерка и цвета чернил в конструкторе ──────────────
   Стили добавляются к шаблонам динамически: список почерков зависит от
   того, что загружено в админке, а в статическом реестре его не описать.
   Только для движков, где рукописный текст вообще есть. */

add_filter( 'podval_tpl_registry', function( $registry ) {
	$fonts = podval_tpl_script_fonts();

	foreach ( $registry as $id => $tpl ) {
		if ( ! in_array( $tpl['engine'] ?? '', array( 'polaroid', 'single' ), true ) ) {
			continue;
		}

		// У «Одно фото + имя» рукописная подпись есть только в одном из
		// вариантов макета — в блочном почерк и цвет чернил не нужны.
		$when = isset( $tpl['styles']['footer_kind']['options']['script'] )
			? array( 'footer_kind' => array( 'script' ) )
			: null;

		// Почерк предлагаем только когда есть из чего выбирать: одна
		// кнопка в строке опций — это не выбор, а лишний шум.
		if ( count( $fonts ) > 1 ) {
			$options = array();
			foreach ( $fonts as $i => $font ) {
				$options[ 'f' . $i ] = $font['label'];
			}
			$registry[ $id ]['styles']['script_font'] = array(
				'label'   => 'Почерк',
				'options' => $options,
				'default' => 'f0',
			);
			if ( $when ) {
				$registry[ $id ]['styles']['script_font']['when'] = $when;
			}
		}

		$registry[ $id ]['styles']['script_color'] = array(
			'label'   => 'Цвет надписи',
			'options' => array(
				'black' => 'Чёрный',
				'blue'  => 'Синий',
				'red'   => 'Красный',
			),
			'default' => 'black',
		);
		if ( $when ) {
			$registry[ $id ]['styles']['script_color']['when'] = $when;
		}
	}

	return $registry;
}, 20 );

/* ── Рукописный шрифт на фронте ─────────────── */

add_action( 'wp_head', function() {
	$fonts = podval_tpl_script_fonts();
	if ( ! $fonts ) {
		return;
	}

	$css = '';
	foreach ( $fonts as $font ) {
		$fmt  = ( substr( $font['url'], -6 ) === '.woff2' ) ? 'woff2' : 'woff';
		$css .= sprintf(
			'@font-face{font-family:"%s";src:url("%s") format("%s");font-display:swap;font-weight:400}',
			esc_attr( $font['family'] ),
			esc_url( $font['url'] ),
			esc_attr( $fmt )
		);
	}

	// Первый шрифт — значение по умолчанию: он подставляется, пока клиент
	// не выбрал другой, и работает там, где выбора нет вовсе.
	$css .= sprintf(
		'.tplb-page{--tplb-script:"%s",Georgia,serif}',
		esc_attr( $fonts[0]['family'] )
	);

	printf( '<style id="podval-tpl-script-font">%s</style>' . "\n", $css );
}, 5 );

/* Разрешаем загрузку шрифтов в медиатеку. */
add_filter( 'upload_mimes', function( $mimes ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $mimes;
	}
	$mimes['woff']  = 'font/woff';
	$mimes['woff2'] = 'font/woff2';
	return $mimes;
} );

add_filter( 'wp_check_filetype_and_ext', function( $data, $file, $filename, $mimes ) {
	if ( ! empty( $data['ext'] ) ) {
		return $data;
	}
	$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	if ( in_array( $ext, array( 'woff', 'woff2' ), true ) && current_user_can( 'manage_options' ) ) {
		$data['ext']  = $ext;
		$data['type'] = 'font/' . $ext;
	}
	return $data;
}, 10, 4 );

/* ═══════════════════════════════════════════
   2. СТРАНИЦА НАСТРОЕК
   ═══════════════════════════════════════════ */

add_action( 'admin_menu', function() {
	add_submenu_page(
		PODVAL_ADMIN_MENU, // раздел сайта в админке
		'Конструктор шаблонов',
		'Конструктор шаблонов',
		'manage_woocommerce',
		'podval-tpl-settings',
		'podval_tpl_settings_page',
		1
	);
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
	// страница живёт в разделе сайта — сверяем по ?page=, а не по имени хука.
	if ( ! isset( $_GET['page'] ) || 'podval-tpl-settings' !== $_GET['page'] ) {
		return;
	}
	wp_enqueue_media();
	$ver = wp_get_theme()->get( 'Version' );
	wp_enqueue_style( 'podval-tpl-admin', get_template_directory_uri() . '/assets/css/admin-tpl.css', array(), $ver );
	wp_enqueue_script( 'podval-tpl-admin', get_template_directory_uri() . '/assets/js/admin-tpl.js', array( 'jquery' ), $ver, true );
} );


/**
 * Группы для навигации админки: реальные SKU-группы + псевдогруппа «Общие».
 * Ключ '' («не шаблонный товар») из podval_tpl_groups() отбрасывается.
 *
 * @return array key => label
 */
function podval_tpl_admin_groups() {
	$out = array();
	foreach ( podval_tpl_groups() as $key => $label ) {
		if ( '' === $key ) {
			continue;
		}
		$out[ $key ] = $label;
	}
	$out['shared'] = 'Общие';
	return $out;
}

/**
 * Основы, относящиеся к группе, для админки.
 * Для реальной группы — собственные основы + подмешанные общие (помечаются).
 * Для псевдогруппы «Общие» — только шаблоны с group=shared.
 *
 * @param string $group Ключ группы.
 * @return array tpl_id => [ 'tpl' => definition, 'shared' => bool ]
 */
function podval_tpl_admin_bases_for( $group ) {
	$raw = podval_tpl_raw_registry();
	$out = array();

	if ( 'shared' === $group ) {
		foreach ( $raw as $id => $tpl ) {
			if ( 'shared' === $tpl['group'] ) {
				$out[ $id ] = array( 'tpl' => $tpl, 'shared' => true );
			}
		}
		return $out;
	}

	foreach ( $raw as $id => $tpl ) {
		if ( $tpl['group'] === $group ) {
			$out[ $id ] = array( 'tpl' => $tpl, 'shared' => false );
		}
	}

	$map = podval_tpl_shared_map();
	if ( ! empty( $map[ $group ] ) ) {
		foreach ( $map[ $group ] as $id ) {
			if ( isset( $raw[ $id ] ) && ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array( 'tpl' => $raw[ $id ], 'shared' => true );
			}
		}
	}

	return $out;
}

/**
 * Рендер страницы настроек.
 */
function podval_tpl_settings_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Недостаточно прав.' );
	}

	$tabs = array(
		'bases'      => 'Основы',
		'layouts'    => 'Раскладки',
		'texts'      => 'Надписи',
		'prices'     => 'Цены',
		'appearance' => 'Оформление',
	);
	$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'bases';
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'bases';
	}

	// Первый уровень для вкладок «Основы» и «Раскладки» — SKU-группа.
	$groups = podval_tpl_admin_groups();
	$group  = isset( $_GET['group'] ) ? sanitize_key( $_GET['group'] ) : '';
	if ( ! isset( $groups[ $group ] ) ) {
		$group = key( $groups );
	}

	if ( isset( $_POST['podval_tpl_save'] ) ) {
		podval_tpl_settings_save( $tab );
		echo '<div class="notice notice-success is-dismissible"><p>Сохранено. Кеш сброшен.</p></div>';
	}

	$ov       = podval_tpl_overrides();
	$registry = podval_tpl_registry();

	echo '<div class="wrap podval-tpl-admin">';
	echo '<h1>Конструктор шаблонов</h1>';

	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $key => $label ) {
		printf(
			'<a href="%s" class="nav-tab%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=podval-tpl-settings&tab=' . $key . '&group=' . $group ) ),
			( $key === $tab ) ? ' nav-tab-active' : '',
			esc_html( $label )
		);
	}
	echo '</nav>';

	$per_group = in_array( $tab, array( 'bases', 'layouts', 'texts' ), true );

	if ( $per_group ) {
		echo '<div class="mtpl-groupbar"><span class="mtpl-groupbar__label">Группа:</span><ul class="subsubsub">';
		$last = array_key_last( $groups );
		foreach ( $groups as $key => $label ) {
			$count = count( podval_tpl_admin_bases_for( $key ) );
			printf(
				'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a>%s</li>',
				esc_url( admin_url( 'admin.php?page=podval-tpl-settings&tab=' . $tab . '&group=' . $key ) ),
				( $key === $group ) ? 'current' : '',
				esc_html( $label ),
				(int) $count,
				( $key === $last ) ? '' : ' |'
			);
		}
		echo '</ul></div>';
	}

	echo '<form method="post" class="podval-tpl-form">';
	if ( $per_group ) {
		echo '<input type="hidden" name="podval_tpl_group_ctx" value="' . esc_attr( $group ) . '">';
	}
	wp_nonce_field( 'podval_tpl_settings', 'podval_tpl_nonce' );

	if ( 'bases' === $tab ) {
		podval_tpl_tab_bases( $group, $ov );
	} elseif ( 'layouts' === $tab ) {
		podval_tpl_tab_layouts( $group, $ov );
	} elseif ( 'texts' === $tab ) {
		podval_tpl_tab_texts( $group, $ov );
	} elseif ( 'prices' === $tab ) {
		podval_tpl_tab_prices( $ov );
	} else {
		podval_tpl_tab_appearance( $ov );
	}

	echo '<p class="submit"><button type="submit" name="podval_tpl_save" value="1" class="button button-primary button-large">Сохранить</button></p>';
	echo '</form></div>';
}

/* ── Вкладка: основы ───────────────────────── */

function podval_tpl_tab_bases( $group, $ov ) {
	$bases  = podval_tpl_admin_bases_for( $group );
	$groups = podval_tpl_admin_groups();

	if ( ! $bases ) {
		echo '<div class="notice notice-info inline"><p>В группе «' . esc_html( $groups[ $group ] ) . '» пока нет основ. Они добавляются в коде — <code>inc/print-templates.php</code>, функция <code>podval_tpl_registry_defaults()</code>, ключ <code>group</code>.</p></div>';
		return;
	}

	echo '<p class="description">Порядок задаётся числом — основы сортируются по возрастанию. Отключённая основа не показывается в конструкторе.</p>';
	echo '<div class="mtpl-bases">';

	foreach ( $bases as $id => $row ) {
		$tpl      = $row['tpl'];
		$b        = $ov['bases'][ $id ] ?? array();
		$enabled  = ! isset( $b['enabled'] ) || $b['enabled'];
		$prev_id  = (int) ( $b['preview_id'] ?? 0 );
		$prev_url = $prev_id ? wp_get_attachment_image_url( $prev_id, 'medium' ) : podval_tpl_preview_url( $tpl );

		echo '<div class="mtpl-base-card' . ( $enabled ? '' : ' is-off' ) . ( $row['shared'] ? ' is-shared' : '' ) . '">';

		echo '<div class="mtpl-base-media" data-target="' . esc_attr( $id ) . '">';
		if ( $prev_url ) {
			echo '<img src="' . esc_url( $prev_url ) . '" alt="">';
		} else {
			echo '<span class="mtpl-noimg">нет превью</span>';
		}
		echo '<button type="button" class="button mtpl-pick" data-target="' . esc_attr( $id ) . '">Выбрать из медиатеки</button>';
		echo '<button type="button" class="button-link mtpl-clear" data-target="' . esc_attr( $id ) . '">Убрать</button>';
		echo '<input type="hidden" name="bases[' . esc_attr( $id ) . '][preview_id]" id="mtpl-prev-' . esc_attr( $id ) . '" value="' . esc_attr( $prev_id ) . '">';
		echo '</div>';

		echo '<div class="mtpl-base-fields">';
		echo '<code class="mtpl-id">' . esc_html( $id ) . '</code>';

		if ( $row['shared'] ) {
			echo '<span class="mtpl-badge-shared" title="Этот шаблон подмешан в несколько групп — правки повлияют на все">общий</span>';
		}

		echo '<label>Название<input type="text" name="bases[' . esc_attr( $id ) . '][title]" value="' . esc_attr( $b['title'] ?? $tpl['title'] ) . '"></label>';
		echo '<label>Подзаголовок<input type="text" name="bases[' . esc_attr( $id ) . '][subtitle]" value="' . esc_attr( $b['subtitle'] ?? ( $tpl['subtitle'] ?? '' ) ) . '"></label>';

		echo '<div class="mtpl-row">';
		echo '<label class="mtpl-narrow">Порядок<input type="number" name="bases[' . esc_attr( $id ) . '][order]" value="' . esc_attr( $b['order'] ?? 10 ) . '" step="10" min="0"></label>';
		echo '<label class="mtpl-check"><input type="checkbox" name="bases[' . esc_attr( $id ) . '][enabled]" value="1"' . checked( $enabled, true, false ) . '> Показывать</label>';
		echo '</div>';

		$ph = $tpl['photos'];
		echo '<p class="description">Движок: <code>' . esc_html( $tpl['engine'] ) . '</code> · фото: '
			. esc_html( $ph['min'] === $ph['max'] ? $ph['min'] : $ph['min'] . '–' . $ph['max'] ) . '</p>';

		if ( $row['shared'] ) {
			$in = array();
			foreach ( podval_tpl_shared_map() as $g => $ids ) {
				if ( in_array( $id, $ids, true ) && isset( $groups[ $g ] ) ) {
					$in[] = $groups[ $g ];
				}
			}
			if ( $in ) {
				echo '<p class="description">Используется в группах: <strong>' . esc_html( implode( ', ', $in ) ) . '</strong></p>';
			}
		}

		echo '</div></div>';
	}

	echo '</div>';
}

/* ── Вкладка: раскладки ────────────────────── */

function podval_tpl_tab_layouts( $group, $ov ) {
	$bases  = podval_tpl_admin_bases_for( $group );
	$groups = podval_tpl_admin_groups();
	$any    = false;

	echo '<p class="description">Тащите прямоугольники мышью, за правый нижний угол — меняйте размер. Координаты в долях 0..1 сохраняются автоматически.</p>';

	foreach ( $bases as $tpl_id => $row ) {
		$tpl = $row['tpl'];
		if ( empty( $tpl['layouts'] ) ) {
			continue;
		}
		// Статичная геометрия (PAPA и 4 фото) задаётся в коде вместе
		// с буквами — редактировать мышью там нечего.
		$editable = array_filter( $tpl['layouts'], function( $l ) {
			return empty( $l['static'] );
		} );
		if ( ! $editable ) {
			continue;
		}
		$any = true;

		echo '<h2 class="mtpl-tpl-head">' . esc_html( $tpl['title'] ) . ' <code>' . esc_html( $tpl_id ) . '</code>';
		if ( $row['shared'] ) {
			echo ' <span class="mtpl-badge-shared">общий</span>';
		}
		echo '</h2>';
		echo '<p class="description">Зазоры между кадрами и пустые поля по краям конструктор выравнивает сам: '
			. 'расстояния приводятся к одному значению, а область коллажа обрезается по габариту слотов. '
			. 'Здесь достаточно расставить кадры — попадать в пиксель не нужно.</p>';
		echo '<div class="mtpl-layouts">';

		foreach ( $tpl['layouts'] as $lay_id => $lay ) {
			// Свободная компоновка не имеет фиксированных слотов — редактировать
			// в холсте нечего, показ только запутал бы.
			if ( ! empty( $lay['free'] ) || ! empty( $lay['static'] ) ) {
				continue;
			}
			$saved = $ov['layouts'][ $tpl_id ][ $lay_id ]['slots'] ?? null;
			$slots = is_array( $saved ) ? $saved : $lay['slots'];

			echo '<div class="mtpl-layout" data-tpl="' . esc_attr( $tpl_id ) . '" data-lay="' . esc_attr( $lay_id ) . '">';
			echo '<div class="mtpl-layout-head">';
			echo '<input type="text" class="mtpl-lay-title" name="layouts[' . esc_attr( $tpl_id ) . '][' . esc_attr( $lay_id ) . '][title]" value="' . esc_attr( $ov['layouts'][ $tpl_id ][ $lay_id ]['title'] ?? $lay['title'] ) . '">';
			echo '<code>' . esc_html( $lay_id ) . '</code>';
			echo '<button type="button" class="button button-small mtpl-add-slot">+ слот</button>';
			echo '<button type="button" class="button button-small mtpl-reset" data-default="' . esc_attr( wp_json_encode( $lay['slots'] ) ) . '">Сбросить</button>';
			echo '</div>';

			$aspect = isset( $lay['aspect'] ) ? (float) $lay['aspect'] : 1.05;
			echo '<div class="mtpl-canvas" data-tpl="' . esc_attr( $tpl_id ) . '" data-lay="' . esc_attr( $lay_id ) . '" style="aspect-ratio:1 / ' . esc_attr( $aspect ) . '">';
			foreach ( array_values( $slots ) as $i => $sl ) {
				printf(
					'<div class="mtpl-rect" data-i="%d" style="left:%s%%;top:%s%%;width:%s%%;height:%s%%">
						<span class="mtpl-rect-n">%d</span>
						<span class="mtpl-rect-del" title="Удалить">×</span>
						<span class="mtpl-rect-grip"></span>
					</div>',
					$i,
					esc_attr( $sl['x'] * 100 ),
					esc_attr( $sl['y'] * 100 ),
					esc_attr( $sl['w'] * 100 ),
					esc_attr( $sl['h'] * 100 ),
					$i + 1
				);
			}
			echo '</div>';

			echo '<textarea class="mtpl-json" name="layouts[' . esc_attr( $tpl_id ) . '][' . esc_attr( $lay_id ) . '][json]" rows="3">' . esc_textarea( wp_json_encode( array_values( $slots ) ) ) . '</textarea>';
			echo '</div>';
		}

		echo '</div>';
	}

	if ( ! $any ) {
		echo '<div class="notice notice-info inline"><p>В группе «' . esc_html( $groups[ $group ] ) . '» нет основ с настраиваемыми раскладками. У шаблонов вроде «Слово из фото» раскладка вычисляется автоматически — по длине слова.</p></div>';
	}
}

/* ── Вкладка: надписи ──────────────────────── */

function podval_tpl_tab_texts( $group, $ov ) {
	$bases  = podval_tpl_admin_bases_for( $group );
	$groups = podval_tpl_admin_groups();
	$any    = false;

	echo '<p class="description">Варианты — по одному на строку. Режим «только из списка» убирает поле свободного ввода: клиент выбирает готовую надпись, и макет гарантированно соберётся.</p>';

	foreach ( $bases as $tpl_id => $row ) {
		$tpl = $row['tpl'];
		if ( empty( $tpl['texts'] ) ) {
			continue;
		}
		$any = true;

		echo '<h2 class="mtpl-tpl-head">' . esc_html( $tpl['title'] ) . ' <code>' . esc_html( $tpl_id ) . '</code>';
		if ( $row['shared'] ) {
			echo ' <span class="mtpl-badge-shared">общий</span>';
		}
		echo '</h2>';

		echo '<table class="widefat mtpl-texts"><thead><tr>';
		echo '<th style="width:210px">Поле</th><th>Варианты (по одному на строку)</th><th style="width:170px">Режим</th>';
		echo '</tr></thead><tbody>';

		foreach ( $tpl['texts'] as $key => $def ) {
			$cur     = $ov['texts'][ $tpl_id ][ $key ] ?? array();
			$presets = isset( $cur['presets'] ) && is_array( $cur['presets'] )
				? $cur['presets']
				: (array) ( $def['presets'] ?? array() );
			$locked  = isset( $cur['locked'] ) ? (bool) $cur['locked'] : ! empty( $def['locked'] );

			$name = 'texts[' . esc_attr( $tpl_id ) . '][' . esc_attr( $key ) . ']';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $def['label'] ) . '</strong><br><code>' . esc_html( $key ) . '</code>';
			echo '<p class="description">до ' . (int) $def['max'] . ' симв.' . ( ! empty( $def['required'] ) ? ' · обязательное' : '' ) . '</p>';
			if ( ! empty( $def['pattern'] ) ) {
				echo '<p class="description">Есть ограничение по символам в коде — варианты должны ему соответствовать.</p>';
			}
			echo '</td>';

			echo '<td><textarea name="' . $name . '[presets]" rows="5" class="mtpl-presets" placeholder="BEST DAD&#10;FAMILY&#10;ЛУЧШИЙ ПАПА">' . esc_textarea( implode( "\n", $presets ) ) . '</textarea></td>';

			echo '<td>';
			echo '<label class="mtpl-check"><input type="checkbox" name="' . $name . '[locked]" value="1"' . checked( $locked, true, false ) . '> Только из списка</label>';
			if ( ! $presets ) {
				echo '<p class="description" style="color:#b32d2e">Список пуст — режим не сработает, останется свободный ввод.</p>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	if ( ! $any ) {
		echo '<div class="notice notice-info inline"><p>В группе «' . esc_html( $groups[ $group ] ) . '» нет основ с текстовыми полями.</p></div>';
	}
}

/* ── Вкладка: цены ─────────────────────────── */

function podval_tpl_tab_prices( $ov ) {
	// Дефолты «из кода» — снимаем только собственный оверрайд-фильтр.
	remove_filter( 'podval_tpl_addons', 'podval_tpl_apply_addon_overrides', 5 );
	$addons = podval_tpl_addons();
	add_filter( 'podval_tpl_addons', 'podval_tpl_apply_addon_overrides', 5 );

	echo '<table class="widefat mtpl-prices"><thead><tr>';
	echo '<th>Надбавка</th><th>Тип</th><th style="width:140px">Сумма, Br</th><th style="width:120px">Показывать</th>';
	echo '</tr></thead><tbody>';

	foreach ( $addons as $code => $a ) {
		$cur     = $ov['addons'][ $code ] ?? array();
		$amount  = isset( $cur['amount'] ) ? (float) $cur['amount'] : (float) $a['amount'];
		$enabled = ! isset( $cur['enabled'] ) || $cur['enabled'];

		echo '<tr>';
		echo '<td><strong>' . esc_html( $a['title'] ) . '</strong><br><code>' . esc_html( $code ) . '</code>';
		if ( ! empty( $a['hint'] ) ) {
			echo '<br><span class="description">' . esc_html( $a['hint'] ) . '</span>';
		}
		echo '</td>';
		echo '<td>' . ( ! empty( $a['auto'] ) ? 'авто' : 'чекбокс' ) . '</td>';
		echo '<td><input type="number" step="0.01" min="0" name="addons[' . esc_attr( $code ) . '][amount]" value="' . esc_attr( $amount ) . '"></td>';
		echo '<td>';
		if ( empty( $a['auto'] ) ) {
			echo '<input type="checkbox" name="addons[' . esc_attr( $code ) . '][enabled]" value="1"' . checked( $enabled, true, false ) . '>';
		} else {
			echo '<span class="description">всегда</span>';
		}
		echo '</td></tr>';
	}

	echo '</tbody></table>';
	echo '<p class="description">Авто-надбавки применяются по условию (тип изделия, размер). Условия задаются в коде — <code>inc/print-templates.php</code>, функция <code>podval_tpl_addons()</code>.</p>';
}

/* ── Вкладка: оформление ───────────────────── */

function podval_tpl_tab_appearance( $ov ) {
	$font_id  = (int) ( $ov['appearance']['script_font_id'] ?? 0 );
	$font_url = $ov['appearance']['script_font_url'] ?? '';

	echo '<h2>Рукописные шрифты</h2>';
	echo '<p class="description">Используются в подписях «Vintage 1994» и на полароидах. Нужен шрифт <strong>с кириллицей</strong> в формате woff2 — иначе превью не совпадёт с печатью.<br>';
	echo 'Загрузите один или несколько: при двух и более клиент сам выбирает почерк в конструкторе. Подпись — то, что он увидит на кнопке.</p>';

	$slots = (array) ( $ov['appearance']['script_fonts'] ?? array() );

	// Старый одиночный шрифт показываем первым слотом, иначе после
	// обновления настроенный сайт выглядел бы сброшенным.
	if ( ! $slots && $font_url ) {
		$slots = array( array( 'id' => $font_id, 'url' => $font_url, 'label' => 'Рукописный' ) );
	}

	$demo_css = '';
	for ( $i = 0; $i < PODVAL_TPL_FONT_SLOTS; $i++ ) {
		$s_id    = (int) ( $slots[ $i ]['id'] ?? 0 );
		$s_url   = $slots[ $i ]['url'] ?? '';
		$s_label = $slots[ $i ]['label'] ?? '';

		if ( $s_url ) {
			$fmt       = ( substr( $s_url, -6 ) === '.woff2' ) ? 'woff2' : 'woff';
			$demo_css .= sprintf(
				'@font-face{font-family:"MtplPreview%d";src:url("%s") format("%s");font-display:swap}',
				$i,
				esc_url( $s_url ),
				esc_attr( $fmt )
			);
		}

		echo '<div class="mtpl-font-slot" style="margin:14px 0;padding:12px;border:1px solid #dcdcde;background:#fff">';
		printf( '<input type="hidden" name="appearance[script_fonts][%1$d][id]" id="mtpl-font-id-%1$d" value="%2$s">', $i, esc_attr( $s_id ) );
		printf( '<input type="hidden" name="appearance[script_fonts][%1$d][url]" id="mtpl-font-url-%1$d" value="%2$s">', $i, esc_attr( $s_url ) );

		echo '<p>';
		printf( '<button type="button" class="button mtpl-font-pick" data-slot="%d">Загрузить / выбрать</button> ', $i );
		printf( '<button type="button" class="button-link mtpl-font-clear" data-slot="%d">Убрать</button>', $i );
		echo '</p>';

		echo '<p><label>Подпись для клиента<br>';
		printf(
			'<input type="text" class="regular-text" name="appearance[script_fonts][%1$d][label]" id="mtpl-font-label-%1$d" value="%2$s" placeholder="Например: Ручка">',
			$i,
			esc_attr( $s_label )
		);
		echo '</label></p>';

		printf(
			'<p id="mtpl-font-name-%d" class="description">%s</p>',
			$i,
			$s_url ? esc_html( basename( $s_url ) ) : 'Не выбран'
		);

		echo '<div class="mtpl-font-demo"' . ( $s_url ? ' style="font-family:\'MtplPreview' . (int) $i . '\',Georgia,serif"' : '' ) . '>';
		echo '<div>Vintage 1994</div>';
		echo '<div>интересно, кто будет моей женой?</div>';
		echo '</div>';
		echo '</div>';
	}

	if ( $demo_css ) {
		echo '<style>' . $demo_css . '</style>';
	}

	$tone = $ov['appearance']['default_tone'] ?? '';
	echo '<h2>Тон фото по умолчанию</h2>';
	echo '<select name="appearance[default_tone]">';
	foreach ( array( '' => '— как задано в шаблоне —', 'color' => 'Цветные', 'mono' => 'Ч/Б' ) as $v => $l ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $tone, $v, false ), esc_html( $l ) );
	}
	echo '</select>';
	echo '<p class="description">Ч/Б в конструкторе — это только превью (CSS-фильтр). Обесцвечивание для печати делает дизайнер.</p>';
}

/* ═══════════════════════════════════════════
   3. СОХРАНЕНИЕ
   ═══════════════════════════════════════════ */

function podval_tpl_settings_save( $tab ) {
	if ( ! isset( $_POST['podval_tpl_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['podval_tpl_nonce'] ), 'podval_tpl_settings' ) ) {
		wp_die( 'Ошибка безопасности.' );
	}
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Недостаточно прав.' );
	}

	$ov = podval_tpl_overrides();

	// ВАЖНО: форма присылает только основы текущей группы, поэтому
	// оверрайды СЛИВАЮТСЯ с существующими, а не заменяют их целиком.
	// Иначе сохранение «Папы» стирало бы настройки «Мамы».
	if ( 'bases' === $tab && isset( $_POST['bases'] ) ) {
		$clean = isset( $ov['bases'] ) && is_array( $ov['bases'] ) ? $ov['bases'] : array();
		foreach ( (array) wp_unslash( $_POST['bases'] ) as $id => $b ) {
			$id = sanitize_key( $id );
			$clean[ $id ] = array(
				'preview_id' => absint( $b['preview_id'] ?? 0 ),
				'title'      => sanitize_text_field( $b['title'] ?? '' ),
				'subtitle'   => sanitize_text_field( $b['subtitle'] ?? '' ),
				'order'      => absint( $b['order'] ?? 10 ),
				'enabled'    => ! empty( $b['enabled'] ),
			);
		}
		$ov['bases'] = $clean;
	}

	if ( 'layouts' === $tab && isset( $_POST['layouts'] ) ) {
		$clean = isset( $ov['layouts'] ) && is_array( $ov['layouts'] ) ? $ov['layouts'] : array();
		foreach ( (array) wp_unslash( $_POST['layouts'] ) as $tpl_id => $layouts ) {
			$tpl_id = sanitize_key( $tpl_id );
			foreach ( (array) $layouts as $lay_id => $lay ) {
				$lay_id = sanitize_key( $lay_id );
				$slots  = json_decode( $lay['json'] ?? '[]', true );
				if ( ! is_array( $slots ) || ! $slots ) {
					continue;
				}
				$out = array();
				foreach ( $slots as $s ) {
					$out[] = array(
						'x' => podval_tpl_clamp01( $s['x'] ?? 0 ),
						'y' => podval_tpl_clamp01( $s['y'] ?? 0 ),
						'w' => podval_tpl_clamp01( $s['w'] ?? 0.5 ),
						'h' => podval_tpl_clamp01( $s['h'] ?? 0.5 ),
					);
				}
				$clean[ $tpl_id ][ $lay_id ] = array(
					'title' => sanitize_text_field( $lay['title'] ?? '' ),
					'slots' => $out,
				);
			}
		}
		$ov['layouts'] = $clean;
	}

	if ( 'texts' === $tab && isset( $_POST['texts'] ) ) {
		$clean = isset( $ov['texts'] ) && is_array( $ov['texts'] ) ? $ov['texts'] : array();
		foreach ( (array) wp_unslash( $_POST['texts'] ) as $tpl_id => $fields ) {
			$tpl_id = sanitize_key( $tpl_id );
			foreach ( (array) $fields as $key => $f ) {
				$key   = sanitize_key( $key );
				$lines = preg_split( '/\r\n|\r|\n/', (string) ( $f['presets'] ?? '' ) );
				$list  = array();
				foreach ( $lines as $line ) {
					$line = sanitize_text_field( $line );
					if ( '' !== $line && ! in_array( $line, $list, true ) ) {
						$list[] = $line;
					}
				}
				$clean[ $tpl_id ][ $key ] = array(
					'presets' => $list,
					'locked'  => ! empty( $f['locked'] ) && ! empty( $list ),
				);
			}
		}
		$ov['texts'] = $clean;
	}

	if ( 'prices' === $tab && isset( $_POST['addons'] ) ) {
		$clean = array();
		foreach ( (array) wp_unslash( $_POST['addons'] ) as $code => $a ) {
			$code = sanitize_key( $code );
			$clean[ $code ] = array(
				'amount'  => round( (float) ( $a['amount'] ?? 0 ), 2 ),
				'enabled' => ! empty( $a['enabled'] ),
			);
		}
		$ov['addons'] = $clean;
	}

	if ( 'appearance' === $tab && isset( $_POST['appearance'] ) ) {
		$a = (array) wp_unslash( $_POST['appearance'] );
		$ov['appearance'] = array(
			'script_fonts'    => podval_tpl_sanitize_fonts( $a['script_fonts'] ?? array() ),
			'default_tone'    => in_array( ( $a['default_tone'] ?? '' ), array( 'color', 'mono' ), true ) ? $a['default_tone'] : '',
		);
	}

	update_option( PODVAL_TPL_OPT, $ov, false );

	if ( function_exists( 'podval_flush_all_cache' ) ) {
		podval_flush_all_cache( 'tpl_settings_save' );
	}
}

/**
 * Реестр БЕЗ наложенных оверрайдов — для админки,
 * чтобы показывать исходные значения из кода как дефолты.
 *
 * @return array
 */
function podval_tpl_raw_registry() {
	return podval_tpl_registry_defaults();
}
