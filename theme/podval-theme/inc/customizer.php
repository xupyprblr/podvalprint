<?php
/**
 * Podval Print — Customizer
 */
defined( 'ABSPATH' ) || exit;

add_action( 'customize_register', function( WP_Customize_Manager $wp_customize ) {

  /* ════════════════════════════════════════
     PANEL
  ════════════════════════════════════════ */
  $wp_customize->add_panel( 'podval_panel', [
    'title'    => '🛒 Podval Print',
    'priority' => 30,
  ] );

  /* ════════════════════════════════════════
     СЕКЦИЯ: Топбар
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_topbar', [
    'title' => 'Бегущая строка',
    'panel' => 'podval_panel',
  ] );
  $wp_customize->add_setting( 'podval_ticker_text', [
    'default'           => '',
    'sanitize_callback' => 'wp_kses_post',
    'transport'         => 'postMessage',
  ] );
  $wp_customize->add_control( 'podval_ticker_text', [
    'label'       => 'Текст бегущей строки',
    'description' => 'Используйте ✦ как разделитель между фразами',
    'section'     => 'podval_topbar',
    'type'        => 'textarea',
  ] );

  // Ссылка по клику на строку
  $wp_customize->add_setting( 'podval_ticker_url', [
    'default'           => '',
    'sanitize_callback' => 'esc_url_raw',
  ] );
  $wp_customize->add_control( 'podval_ticker_url', [
    'label'       => 'Ссылка (куда ведёт клик по строке)',
    'description' => 'Оставьте пустым — строка не будет кликабельной',
    'section'     => 'podval_topbar',
    'type'        => 'url',
  ] );

  // Режим повторения — как на референсе (одно слово / одно слово / ...)
  $wp_customize->add_setting( 'podval_ticker_repeat', [
    'default'           => '0',
    'sanitize_callback' => 'absint',
  ] );
  $wp_customize->add_control( 'podval_ticker_repeat', [
    'label'       => 'Повторять текст бесконечно',
    'description' => 'Включите — текст будет повторяться как отдельные слова через разделитель «✦»',
    'section'     => 'podval_topbar',
    'type'        => 'checkbox',
  ] );

  // Скрыть строку
  $wp_customize->add_setting( 'podval_ticker_hide', [
    'default'           => '0',
    'sanitize_callback' => 'absint',
  ] );
  $wp_customize->add_control( 'podval_ticker_hide', [
    'label'       => 'Скрыть строку',
    'description' => 'Полностью скрывает бегущую строку на сайте',
    'section'     => 'podval_topbar',
    'type'        => 'checkbox',
  ] );


  /* ════════════════════════════════════════
     СЕКЦИЯ: Шапка (Header)
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_header', [
    'title'       => '📞 Шапка сайта',
    'panel'       => 'podval_panel',
    'description' => 'Контактные данные в правой части шапки',
    'priority'    => 25,
  ] );

  $wp_customize->add_setting( 'podval_header_phone', [
    'default'           => '',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_header_phone', [
    'label'       => 'Номер телефона',
    'description' => 'Формат: +375XXXXXXXXX (отображается и используется как callto:)',
    'section'     => 'podval_header',
    'type'        => 'text',
  ] );

  $wp_customize->add_setting( 'podval_header_tg', [
    'default'           => '',
    'sanitize_callback' => 'esc_url_raw',
  ] );
  $wp_customize->add_control( 'podval_header_tg', [
    'label'       => 'Telegram — ссылка',
    'description' => 'Например: https://t.me/username или https://t.me/yourchannel',
    'section'     => 'podval_header',
    'type'        => 'url',
  ] );

  /* ════════════════════════════════════════
     СЕКЦИЯ: Навигация
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_nav', [
    'title'       => 'Меню навигации',
    'panel'       => 'podval_panel',
    'description' => 'Меню автоматически строится из родительских категорий WooCommerce (Товары → Категории). Подкатегории отображаются в выпадающем списке. Порядок — по полю "Порядок" категории.',
  ] );

  // Поле для исключения категорий
  $wp_customize->add_setting( 'podval_nav_exclude_slugs', [
    'default'           => '',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_nav_exclude_slugs', [
    'label'       => 'Скрыть категории из меню',
    'description' => 'Slug категорий через запятую (напр: misc, uncategorized)',
    'section'     => 'podval_nav',
    'type'        => 'text',
  ] );

  // Публичность раздела «Бандлы»
  $wp_customize->add_setting( 'podval_bundles_public', [
    'default'           => false,
    'sanitize_callback' => 'wp_validate_boolean',
  ] );
  $wp_customize->add_control( 'podval_bundles_public', [
    'label'       => 'Показывать раздел «Бандлы»',
    'description' => 'OFF: бандлы скрыты от посетителей (нет в меню, /bundles/ отдаёт 404, single-страницы получают noindex, но открываются по прямой ссылке — для проверки админом). ON: раздел публичен.',
    'section'     => 'podval_nav',
    'type'        => 'checkbox',
  ] );

  /* ════════════════════════════════════════
     СЕКЦИЯ: Главный слайдер (до 5 слайдов)
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_slider', [
    'title'       => '🎞 Главный слайдер',
    'panel'       => 'podval_panel',
    'description' => 'Настройте каждый слайд. Оставьте заголовок пустым — слайд не будет показан.',
  ] );

  // Количество активных слайдов
  $wp_customize->add_setting( 'podval_slides_count', [
    'default'           => '3',
    'sanitize_callback' => 'absint',
  ] );
  $wp_customize->add_control( 'podval_slides_count', [
    'label'   => 'Количество слайдов (1–5)',
    'section' => 'podval_slider',
    'type'    => 'number',
    'input_attrs' => [ 'min' => 1, 'max' => 5 ],
  ] );

  // Демо-слайдов нет: слайды заполняются в настройках, тексты — свои.
  $slide_blank    = [ 'label' => '', 'title1' => '', 'title2' => '', 'desc' => '', 'btn1' => '', 'btn1url' => '#', 'btn2' => '', 'btn2url' => '#', 'image' => '' ];
  $slide_defaults = array_fill( 1, 5, $slide_blank );

  $slide_fields = [
    'image'    => [ 'label' => 'Фоновое изображение',     'type' => 'image'    ],
    'label'    => [ 'label' => 'Надпись над заголовком',   'type' => 'text'     ],
    'title1'   => [ 'label' => 'Заголовок (строка 1)',      'type' => 'text'     ],
    'title2'   => [ 'label' => 'Заголовок (строка 2, жёлтая)', 'type' => 'text' ],
    'desc'     => [ 'label' => 'Подпись (необязательно)',   'type' => 'text'     ],
    'btn1'     => [ 'label' => 'Кнопка 1 — текст',          'type' => 'text'     ],
    'btn1url'  => [ 'label' => 'Кнопка 1 — ссылка',         'type' => 'url'      ],
    'btn2'     => [ 'label' => 'Кнопка 2 — текст (или пусто)', 'type' => 'text'  ],
    'btn2url'  => [ 'label' => 'Кнопка 2 — ссылка',         'type' => 'url'      ],
    'bg_pos_d' => [ 'label' => 'Фокус фона: десктоп',       'type' => 'text',
                    'description' => 'Куда центрировать кроп изображения на десктопе. Пусто = по центру. Примеры: "center", "right", "70% center", "60% 30%". Принимает любое валидное значение CSS object-position.' ],
    'bg_pos_m' => [ 'label' => 'Фокус фона: мобила',        'type' => 'text',
                    'description' => 'То же для мобильных (≤768px). Пусто = берётся значение десктопа. Пример: "75% center" — центрировать на правой ⅓ изображения.' ],
  ];

  for ( $i = 1; $i <= 5; $i++ ) {
    // Разделитель-заголовок для каждого слайда
    $wp_customize->add_setting( "podval_slide_{$i}_divider", [ 'sanitize_callback' => '__return_empty_string' ] );
    $wp_customize->add_control( new WP_Customize_Control( $wp_customize, "podval_slide_{$i}_divider", [
      'label'       => "── СЛАЙД {$i} ──",
      'section'     => 'podval_slider',
      'type'        => 'hidden',
      'description' => $i <= 3 ? '' : 'Слайд показывается если он включён в счётчике выше',
    ] ) );

    foreach ( $slide_fields as $key => $field ) {
      $setting_id = "podval_slide_{$i}_{$key}";
      $default    = $slide_defaults[ $i ][ $key ] ?? '';

      if ( $field['type'] === 'image' ) {
        $wp_customize->add_setting( $setting_id, [
          'default'           => $default,
          'sanitize_callback' => 'esc_url_raw',
        ] );
        $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, $setting_id, [
          'label'   => "Слайд {$i}: " . $field['label'],
          'section' => 'podval_slider',
        ] ) );
      } else {
        $wp_customize->add_setting( $setting_id, [
          'default'           => $default,
          'sanitize_callback' => $field['type'] === 'url' ? 'esc_url_raw' : 'sanitize_text_field',
        ] );
        $control_args = [
          'label'   => "Слайд {$i}: " . $field['label'],
          'section' => 'podval_slider',
          'type'    => $field['type'],
        ];
        if ( ! empty( $field['description'] ) ) {
          $control_args['description'] = $field['description'];
        }
        $wp_customize->add_control( $setting_id, $control_args );
      }
    }
  }

  /* ════════════════════════════════════════
     СЕКЦИЯ: Промо-баннер
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_promo', [
    'title' => 'Промо-баннер (главная)',
    'panel' => 'podval_panel',
  ] );
  $wp_customize->add_setting( 'podval_promo_title', [ 'default' => "ПРОИЗВОДСТВО\nМЕРЧА", 'sanitize_callback' => 'sanitize_textarea_field' ] );
  $wp_customize->add_control( 'podval_promo_title', [ 'label' => 'Заголовок (каждая строка — перенос)', 'section' => 'podval_promo', 'type' => 'textarea' ] );
  $wp_customize->add_setting( 'podval_promo_sub', [ 'default' => 'Создадим коллаборацию вашей мечты', 'sanitize_callback' => 'sanitize_text_field' ] );
  $wp_customize->add_control( 'podval_promo_sub', [ 'label' => 'Подзаголовок', 'section' => 'podval_promo', 'type' => 'text' ] );
  $wp_customize->add_setting( 'podval_promo_url', [ 'default' => '#', 'sanitize_callback' => 'esc_url_raw' ] );
  $wp_customize->add_control( 'podval_promo_url', [ 'label' => 'Ссылка кнопки', 'section' => 'podval_promo', 'type' => 'url' ] );



  // Подписи контактов в мобильном меню
  $wp_customize->add_setting( 'podval_mob_orders_label', [ 'default' => 'Вопросы о заказах', 'sanitize_callback' => 'sanitize_text_field' ] );
  $wp_customize->add_control( 'podval_mob_orders_label', [ 'label' => 'Мобильное меню — подпись 1 (над телефоном)', 'section' => 'podval_header', 'type' => 'text' ] );
  $wp_customize->add_setting( 'podval_mob_podval_label', [ 'default' => 'Производство мерча', 'sanitize_callback' => 'sanitize_text_field' ] );
  $wp_customize->add_control( 'podval_mob_podval_label', [ 'label' => 'Мобильное меню — подпись 2 (над email)', 'section' => 'podval_header', 'type' => 'text' ] );

  /* ════════════════════════════════════════
     СЕКЦИЯ: «Свой дизайн» на главной
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_cd_home', [
    'title'       => '🎨 Свой дизайн на главной',
    'panel'       => 'podval_panel',
    'description' => 'Сетка из 8 карточек раздела «Свой дизайн». Порядок фиксированных — WooCommerce → Товары → Сортировка (перетаскиванием).',
    'priority'    => 85,
  ] );
  $wp_customize->add_setting( 'podval_cd_fixed', [ 'default' => 3, 'sanitize_callback' => 'absint' ] );
  $wp_customize->add_control( 'podval_cd_fixed', [
    'label'       => 'Сколько первых карточек зафиксировать',
    'description' => 'Первые N товаров по сортировке стоят всегда на своих местах. 0 — всё случайно, 8 — всё по сортировке.',
    'section'     => 'podval_cd_home',
    'type'        => 'number',
    'input_attrs' => [ 'min' => 0, 'max' => 8, 'step' => 1 ],
  ] );
  $wp_customize->add_setting( 'podval_cd_shuffle', [ 'default' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ] );
  $wp_customize->add_control( 'podval_cd_shuffle', [
    'label'       => 'Остальные места — случайные товары раздела на каждом показе',
    'section'     => 'podval_cd_home',
    'type'        => 'checkbox',
  ] );

  /* ════════════════════════════════════════
     СЕКЦИЯ: Нижняя бегущая строка (теги)
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_brand_strip', [
    'title'       => '🏷️ Нижняя бегущая строка',
    'panel'       => 'podval_panel',
    'description' => 'Строка между контентом и футером. При наведении — жёлтый цвет, клик — переход на коллекцию с этим тэгом.',
    'priority'    => 90,
  ] );

  $wp_customize->add_setting( 'podval_strip_source', [
    'default'           => 'tags',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_strip_source', [
    'label'       => 'Источник данных',
    'description' => '«Автоматически» — берёт тэги товаров WooCommerce. «Вручную» — вы задаёте список ниже.',
    'section'     => 'podval_brand_strip',
    'type'        => 'radio',
    'choices'     => [
      'tags'   => 'Автоматически из тэгов WooCommerce',
      'custom' => 'Ввести вручную',
    ],
  ] );

  $wp_customize->add_setting( 'podval_strip_custom', [
    'default'           => '',
    'sanitize_callback' => 'sanitize_textarea_field',
  ] );
  $wp_customize->add_control( 'podval_strip_custom', [
    'label'       => 'Список тэгов (только при ручном режиме)',
    'description' => 'Формат: Текст|slug, через запятую. Например: НОВИНКИ|novinki, ХУДИ|hudi

Slug — это slug тэга/категории товара в WooCommerce.',
    'section'     => 'podval_brand_strip',
    'type'        => 'textarea',
  ] );


  /* ════════════════════════════════════════
     БАННЕРЫ КАТАЛОГА — 2 секции: категории + теги
  ════════════════════════════════════════ */

  // Хелпер: регистрирует настройки баннера для одного slug
  if ( ! function_exists('podval_register_banner_controls') ) {
    function podval_register_banner_controls( $wp_customize, $section, $slug, $name ) {
      $safe = sanitize_key( $slug );

      $wp_customize->add_setting( "podval_cat_banner_group_{$safe}", [ 'sanitize_callback' => '__return_empty_string' ] );
      $wp_customize->add_control( new WP_Customize_Control( $wp_customize, "podval_cat_banner_group_{$safe}", [
        'label' => '── ' . $name . ' ──', 'section' => $section, 'type' => 'hidden',
      ] ) );

      $wp_customize->add_setting( "podval_cat_banner_img_{$safe}", [ 'default' => '', 'sanitize_callback' => 'esc_url_raw' ] );
      $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, "podval_cat_banner_img_{$safe}", [
        'label' => $name . ' — Фон баннера', 'section' => $section,
      ] ) );

      $wp_customize->add_setting( "podval_cat_banner_title_{$safe}", [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ] );
      $wp_customize->add_control( "podval_cat_banner_title_{$safe}", [ 'label' => $name . ' — Заголовок', 'section' => $section, 'type' => 'text' ] );

      $wp_customize->add_setting( "podval_cat_banner_sub_{$safe}", [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ] );
      $wp_customize->add_control( "podval_cat_banner_sub_{$safe}", [ 'label' => $name . ' — Подзаголовок', 'section' => $section, 'type' => 'text' ] );

      $wp_customize->add_setting( "podval_cat_banner_btn_{$safe}", [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ] );
      $wp_customize->add_control( "podval_cat_banner_btn_{$safe}", [ 'label' => $name . ' — Текст кнопки', 'section' => $section, 'type' => 'text' ] );

      $wp_customize->add_setting( "podval_cat_banner_url_{$safe}", [ 'default' => '', 'sanitize_callback' => 'esc_url_raw' ] );
      $wp_customize->add_control( "podval_cat_banner_url_{$safe}", [ 'label' => $name . ' — Ссылка кнопки', 'section' => $section, 'type' => 'url' ] );

      $wp_customize->add_setting( "podval_cat_banner_after_{$safe}", [ 'default' => '4', 'sanitize_callback' => 'absint' ] );
      $wp_customize->add_control( "podval_cat_banner_after_{$safe}", [
        'label' => $name . ' — После карточки №', 'description' => 'По умолчанию: 4',
        'section' => $section, 'type' => 'number',
        'input_attrs' => [ 'min' => 1, 'max' => 20, 'step' => 1 ],
      ] );
    }
  }

  // ── Секция 1: Баннеры — Категории ──
  $wp_customize->add_section( 'podval_cat_banners', [
    'title'       => '🖼️ Баннеры — Категории',
    'panel'       => 'podval_panel',
    'description' => 'Баннер вставляется после N-й карточки на странице категории. «Общий каталог» — фолбэк для категорий без своего баннера.',
    'priority'    => 35,
  ] );

  $banner_cats = [ 'shop' => 'Общий каталог' ];
  if ( function_exists('get_terms') ) {
    $cats = get_terms([ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 50, 'exclude' => [ get_option('default_product_cat') ] ]);
    if ( ! is_wp_error($cats) ) {
      foreach ( $cats as $c ) $banner_cats[ $c->slug ] = $c->name . ' (' . $c->slug . ')';
    }
  }
  foreach ( $banner_cats as $slug => $name ) {
    podval_register_banner_controls( $wp_customize, 'podval_cat_banners', $slug, $name );
  }

  // ── Секция 2: Баннеры — Теги ──
  $wp_customize->add_section( 'podval_tag_banners', [
    'title'       => '🏷️ Баннеры — Теги',
    'panel'       => 'podval_panel',
    'description' => 'Баннер вставляется после N-й карточки на странице тега. Если не задан — используется фолбэк из «Общий каталог» в секции категорий.',
    'priority'    => 36,
  ] );

  if ( function_exists('get_terms') ) {
    $tags = get_terms([ 'taxonomy' => 'product_tag', 'hide_empty' => true, 'number' => 50, 'orderby' => 'count', 'order' => 'DESC' ]);
    if ( ! is_wp_error($tags) && ! empty($tags) ) {
      foreach ( $tags as $t ) {
        podval_register_banner_controls( $wp_customize, 'podval_tag_banners', 'tag__' . $t->slug, $t->name );
      }
    }
  }


  /* ════════════════════════════════════════
     СЕКЦИЯ: Изображения тегов — ПЕРЕНЕСЕНА
     Управление: Товары → Метки → редактировать тег → поле «Изображение»
     Старые значения из Customizer используются как fallback.
  ════════════════════════════════════════ */

  /* ════════════════════════════════════════
     СЕКЦИЯ: Фоновые декоры между секциями (главная)
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_section_decors', [
    'title'       => '🎨 Декоры между секциями',
    'panel'       => 'podval_panel',
    'description' => 'Фоновые изображения-декоры между секциями на главной странице. Каждый слот — это полоса на всю ширину экрана между двумя блоками.',
    'priority'    => 38,
  ] );

  $decor_slots = [
    1 => 'После Hero (перед Свой Дизайн)',
    2 => 'После Свой Дизайн (перед Магазин)',
    3 => 'После Магазин (перед Коллекции)',
    4 => 'После Коллекции (перед Услуги)',
    5 => 'После Услуги (перед рядами коллекций)',
    6 => 'После рядов коллекций (перед промо)',
  ];

  foreach ( $decor_slots as $slot_num => $slot_label ) {
    $prefix = "podval_decor_{$slot_num}";

    // Включить/выключить
    $wp_customize->add_setting( "{$prefix}_enabled", [
      'default' => false, 'sanitize_callback' => 'wp_validate_boolean',
    ] );
    $wp_customize->add_control( "{$prefix}_enabled", [
      'label'   => "Слот {$slot_num}: {$slot_label}",
      'section' => 'podval_section_decors',
      'type'    => 'checkbox',
    ] );

    // Изображение (десктоп)
    $wp_customize->add_setting( "{$prefix}_image", [
      'default' => '', 'sanitize_callback' => 'esc_url_raw',
    ] );
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, "{$prefix}_image", [
      'label'   => "Изображение (десктоп)",
      'description' => 'Рекомендуемый размер: 1920×120–300px, PNG/WebP с прозрачностью',
      'section' => 'podval_section_decors',
    ] ) );

    // Изображение (мобильное, опционально)
    $wp_customize->add_setting( "{$prefix}_image_mob", [
      'default' => '', 'sanitize_callback' => 'esc_url_raw',
    ] );
    $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, "{$prefix}_image_mob", [
      'label'   => "Изображение (мобильное, опционально)",
      'description' => 'Если не задано — используется десктопное. Рекомендуемый размер: 768×80–200px',
      'section' => 'podval_section_decors',
    ] ) );

    // Высота (десктоп)
    $wp_customize->add_setting( "{$prefix}_height", [
      'default' => '120', 'sanitize_callback' => 'absint',
    ] );
    $wp_customize->add_control( "{$prefix}_height", [
      'label'   => "Высота (px, десктоп)",
      'section' => 'podval_section_decors',
      'type'    => 'number',
      'input_attrs' => [ 'min' => 20, 'max' => 500, 'step' => 10 ],
    ] );

    // Высота (мобильное)
    $wp_customize->add_setting( "{$prefix}_height_mob", [
      'default' => '60', 'sanitize_callback' => 'absint',
    ] );
    $wp_customize->add_control( "{$prefix}_height_mob", [
      'label'   => "Высота (px, мобильное)",
      'section' => 'podval_section_decors',
      'type'    => 'number',
      'input_attrs' => [ 'min' => 10, 'max' => 300, 'step' => 5 ],
    ] );

    // Режим отображения (cover/contain/repeat)
    $wp_customize->add_setting( "{$prefix}_mode", [
      'default' => 'cover', 'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( "{$prefix}_mode", [
      'label'   => "Режим заливки",
      'section' => 'podval_section_decors',
      'type'    => 'select',
      'choices' => [
        'cover'    => 'Заполнить (cover)',
        'contain'  => 'Вписать (contain)',
        'repeat-x' => 'Повторять по горизонтали',
        'tile'     => 'Плитка (repeat)',
      ],
    ] );

    // Позиция по вертикали
    $wp_customize->add_setting( "{$prefix}_position", [
      'default' => 'center', 'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( "{$prefix}_position", [
      'label'   => "Вертикальная позиция",
      'section' => 'podval_section_decors',
      'type'    => 'select',
      'choices' => [
        'top'    => 'Сверху',
        'center' => 'Центр',
        'bottom' => 'Снизу',
      ],
    ] );

    // Наложение полосок hazard (поверх картинки)
    $wp_customize->add_setting( "{$prefix}_overlay", [
      'default' => 'none', 'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( "{$prefix}_overlay", [
      'label'   => "Наложение эффекта",
      'section' => 'podval_section_decors',
      'type'    => 'select',
      'choices' => [
        'none'      => 'Без эффекта',
        'stripes'   => 'Hazard-полосы',
        'fade'      => 'Затухание к краям',
        'vignette'  => 'Виньетка',
        'scanline'  => 'Scanline (glitch-полоса)',
        'pulse'     => '⚡ Пульсирующее свечение',
        'sweep'     => '⚡ Бегущий луч',
        'sparks'    => '⚡ Искры / частицы',
      ],
    ] );

    // Разделитель визуальный
    $wp_customize->add_setting( "{$prefix}_sep", [ 'sanitize_callback' => 'sanitize_text_field' ] );
    $wp_customize->add_control( new class( $wp_customize, "{$prefix}_sep", [
      'section' => 'podval_section_decors',
      'settings' => "{$prefix}_sep",
    ] ) extends WP_Customize_Control {
      public function render_content() {
        echo '<hr style="border:none;border-top:1px solid #555;margin:20px 0">';
      }
    } );
  }

  /* ════════════════════════════════════════
     СЕКЦИЯ: Футер — Колонка 1 (Юр. информация)
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_footer_col1', [
    'title'       => '⚖️ Футер — Юридическая информация',
    'panel'       => 'podval_panel',
    'description' => 'Колонка 1: юридические данные под логотипом',
  ] );
  $wp_customize->add_setting( 'podval_footer_legal_info', [
    'default'           => '',
    'sanitize_callback' => 'sanitize_textarea_field',
  ] );
  $wp_customize->add_control( 'podval_footer_legal_info', [
    'label'       => 'Юридические данные',
    'description' => 'Каждая строка — отдельный абзац',
    'section'     => 'podval_footer_col1',
    'type'        => 'textarea',
  ] );

  /* ════════════════════════════════════════
     СЕКЦИЯ: Футер — Колонка 2 (Режим работы)
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_footer_col2', [
    'title' => '🕐 Футер — Режим работы',
    'panel' => 'podval_panel',
  ] );
  $wp_customize->add_setting( 'podval_footer_hours_title', [ 'default' => 'Режим работы', 'sanitize_callback' => 'sanitize_text_field' ] );
  $wp_customize->add_control( 'podval_footer_hours_title', [ 'label' => 'Заголовок колонки', 'section' => 'podval_footer_col2', 'type' => 'text' ] );
  $wp_customize->add_setting( 'podval_footer_hours', [
    'default'           => "Пн–Пт: 11:00–21:00\nСб: 12:00–18:00\nВс: выходной",
    'sanitize_callback' => 'sanitize_textarea_field',
  ] );
  $wp_customize->add_control( 'podval_footer_hours', [
    'label'       => 'Часы работы',
    'description' => 'Каждая строка — отдельная запись',
    'section'     => 'podval_footer_col2',
    'type'        => 'textarea',
  ] );
  $wp_customize->add_setting( 'podval_footer_hours_note', [ 'default' => 'Заявки принимаем круглосуточно', 'sanitize_callback' => 'sanitize_text_field' ] );
  $wp_customize->add_control( 'podval_footer_hours_note', [ 'label' => 'Примечание под часами', 'section' => 'podval_footer_col2', 'type' => 'text' ] );

  /* ════════════════════════════════════════
     СЕКЦИЯ: Футер — Колонка 3 (Информация / ссылки)
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_footer_col3', [
    'title'       => '🔗 Футер — Информация (ссылки)',
    'panel'       => 'podval_panel',
    'description' => 'До 6 ссылок: О нас, Доставка, Политика и т.д.',
  ] );
  $wp_customize->add_setting( 'podval_footer_info_title', [ 'default' => 'Информация', 'sanitize_callback' => 'sanitize_text_field' ] );
  $wp_customize->add_control( 'podval_footer_info_title', [ 'label' => 'Заголовок колонки', 'section' => 'podval_footer_col3', 'type' => 'text' ] );
  for ( $i = 1; $i <= 6; $i++ ) {
    $defaults = [
      1 => [ 'О нас',                       podval_url( 'about' ) ],
      2 => [ 'Доставка и оплата',            podval_url( 'delivery' ) ],
      3 => [ 'Возврат',                      podval_url( 'returns' ) ],
      4 => [ 'Политика конфиденциальности',  podval_url( 'privacy' ) ],
      5 => [ 'Контакты',                     podval_url( 'contacts' ) ],
      6 => [ '',                             '' ],
    ];
    $wp_customize->add_setting( "podval_footer_link{$i}_label", [ 'default' => $defaults[$i][0], 'sanitize_callback' => 'sanitize_text_field' ] );
    $wp_customize->add_control( "podval_footer_link{$i}_label", [ 'label' => "Ссылка {$i} — текст", 'section' => 'podval_footer_col3', 'type' => 'text' ] );
    $wp_customize->add_setting( "podval_footer_link{$i}_url", [ 'default' => $defaults[$i][1], 'sanitize_callback' => 'esc_url_raw' ] );
    $wp_customize->add_control( "podval_footer_link{$i}_url", [ 'label' => "Ссылка {$i} — URL", 'section' => 'podval_footer_col3', 'type' => 'url' ] );
  }

  /* ════════════════════════════════════════
     СЕКЦИЯ: Футер — Колонка 4 (Контакты)
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_footer_col4', [
    'title'       => '📬 Футер — Контакты и соцсети',
    'panel'       => 'podval_panel',
    'description' => 'Телефон, email, адрес и иконки соцсетей',
  ] );
  $contact_fields = [
    [ 'podval_footer_contacts_title', 'Заголовок колонки',  'Контакты',          'text' ],
    [ 'podval_footer_phone',          'Телефон',             '',  'text' ],
    [ 'podval_footer_email',          'Email',               '',                  'text' ],
    [ 'podval_footer_address',        'Адрес',               '',                  'text' ],
    [ 'podval_footer_tg',             'Telegram — ссылка',   '',                  'url'  ],
    [ 'podval_footer_ig',             'Instagram — ссылка',  '',                  'url'  ],
    [ 'podval_footer_yt',             'YouTube — ссылка',    '',                  'url'  ],
    [ 'podval_footer_tt',             'TikTok — ссылка',     '',                  'url'  ],
    [ 'podval_footer_maps',           'Google Maps — ссылка','',                  'url'  ],
  ];
  foreach ( $contact_fields as [ $id, $label, $default, $type ] ) {
    $cb = $type === 'url' ? 'esc_url_raw' : 'sanitize_text_field';
    $wp_customize->add_setting( $id, [ 'default' => $default, 'sanitize_callback' => $cb ] );
    $wp_customize->add_control( $id, [ 'label' => $label, 'section' => 'podval_footer_col4', 'type' => $type ] );
  }

  /* ════════════════════════════════════════
     СЕКЦИЯ: Футер — Подвал (нижняя строка)
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_footer_bottom', [
    'title'       => '📄 Футер — Нижняя строка',
    'panel'       => 'podval_panel',
    'description' => 'Подвал: левый и правый текст',
  ] );
  $wp_customize->add_setting( 'podval_footer_bottom_left', [
    'default'           => '',
    'sanitize_callback' => 'wp_kses_post',
  ] );
  $wp_customize->add_control( 'podval_footer_bottom_left', [
    'label'       => 'Текст слева (HTML разрешён)',
    'description' => 'Например: ООО «Название», УНП',
    'section'     => 'podval_footer_bottom',
    'type'        => 'textarea',
  ] );
  $wp_customize->add_setting( 'podval_footer_bottom_right', [
    'default'           => '',
    'sanitize_callback' => 'wp_kses_post',
  ] );
  $wp_customize->add_control( 'podval_footer_bottom_right', [
    'label'       => 'Текст справа (HTML разрешён)',
    'description' => 'Если пусто — автоматически © 2025 Название сайта',
    'section'     => 'podval_footer_bottom',
    'type'        => 'textarea',
  ] );

  /* ════════════════════════════════════════
     СЕКЦИЯ: Услуги — Hero баннер
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_svc_hero', [
    'title'       => '🛠 Услуги — Hero баннер',
    'panel'       => 'podval_panel',
    'description' => 'Настройки баннера на странице раздела «Услуги»',
  ] );

  $svc_hero_fields = [
    [ 'podval_svc_hero_badge',  'Бейдж (над заголовком)',       'Услуги',                   'text' ],
    [ 'podval_svc_hero_title',  'Заголовок (первая строка)',    'Печать и производство',     'text' ],
    [ 'podval_svc_hero_accent', 'Заголовок (accent строка)',    'под ваш бренд',             'text' ],
    [ 'podval_svc_hero_desc',   'Описание',                     'От единичного принта на футболке до серийного производства мерча. Всё — на собственном оборудовании, без посредников.', 'textarea' ],
    [ 'podval_svc_hero_btn1',   'Кнопка 1 — текст',            'Смотреть услуги',           'text' ],
    [ 'podval_svc_hero_btn2',   'Кнопка 2 — текст',            'Связаться с нами',          'text' ],
  ];
  foreach ( $svc_hero_fields as [ $id, $label, $default, $type ] ) {
    $wp_customize->add_setting( $id, [ 'default' => $default, 'sanitize_callback' => 'sanitize_text_field' ] );
    $wp_customize->add_control( $id, [ 'label' => $label, 'section' => 'podval_svc_hero', 'type' => $type ] );
  }

  // Фоновое изображение
  $wp_customize->add_setting( 'podval_svc_hero_image', [
    'default'           => '',
    'sanitize_callback' => 'esc_url_raw',
  ] );
  $wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, 'podval_svc_hero_image', [
    'label'       => 'Фоновое изображение',
    'description' => 'Рекомендуемый размер: 1920×800px. Текст будет поверх с затемняющим градиентом.',
    'section'     => 'podval_svc_hero',
  ] ) );

  /* ════════════════════════════════════════
     СЕКЦИЯ: DTF — Цены
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_dtf_prices', [
    'title'       => '💰 DTF — Цены',
    'panel'       => 'podval_panel',
    'description' => 'Цены на DTF-печать, отображаются на странице услуги «DTF печать»',
  ] );

  // Таблица 1: Печать трансферов (в рулон)
  $wp_customize->add_setting( 'podval_dtf_table1', [
    'default'           => '40|35|32|30|25',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_dtf_table1', [
    'label'       => 'Печать трансферов (цены через |)',
    'description' => 'Формат: до1м|1-5м|5-10м|10-50м|50м+. Пример: 40|35|32|30|25',
    'section'     => 'podval_dtf_prices',
    'type'        => 'text',
  ] );

  // Таблица 2: Печать + нанесение
  $wp_customize->add_setting( 'podval_dtf_table2', [
    'default'           => "10|9|8|7|Договорная\n12|11|10|9|Договорная\n15|13|11|10|Договорная\n20|18|16|14|Договорная",
    'sanitize_callback' => 'sanitize_textarea_field',
  ] );
  $wp_customize->add_control( 'podval_dtf_table2', [
    'label'       => 'Печать + нанесение (строки)',
    'description' => 'Каждая строка = размер (A6, A5, A4, A3). Цены через |: 1-4шт|5-10|11-20|21-50|50+',
    'section'     => 'podval_dtf_prices',
    'type'        => 'textarea',
  ] );

  /* ════════════════════════════════════════
     СЕКЦИЯ: Свой Дизайн — Hero
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_cd_hero', [
    'title'       => '🎨 Свой Дизайн — Hero',
    'panel'       => 'podval_panel',
    'description' => 'Баннер на странице категории «Свой Дизайн»',
  ] );

  // Фоновое изображение
  $wp_customize->add_setting( 'podval_cd_hero_bg', [
    'default'           => '',
    'sanitize_callback' => 'absint',
  ] );
  $wp_customize->add_control( new WP_Customize_Media_Control( $wp_customize, 'podval_cd_hero_bg', [
    'label'     => 'Фоновое изображение',
    'section'   => 'podval_cd_hero',
    'mime_type' => 'image',
  ] ) );

  // Бейдж
  $wp_customize->add_setting( 'podval_cd_hero_badge', [
    'default'           => 'ПЕЧАТЬ НА ЗАКАЗ В МИНСКЕ',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_cd_hero_badge', [
    'label'   => 'Бейдж',
    'section' => 'podval_cd_hero',
    'type'    => 'text',
  ] );

  // Заголовок
  $wp_customize->add_setting( 'podval_cd_hero_title', [
    'default'           => 'Печать на футболках и майках в Минске на заказ',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_cd_hero_title', [
    'label'   => 'Заголовок',
    'section' => 'podval_cd_hero',
    'type'    => 'text',
  ] );

  // Подпись
  $wp_customize->add_setting( 'podval_cd_hero_desc', [
    'default'           => 'Печатаем на футболках, худи, свитшотах, кепках и аксессуарах. Принт со своим дизайном — от 1 штуки, от 30 минут. Любые изображения, любой тираж.',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_cd_hero_desc', [
    'label'   => 'Описание',
    'section' => 'podval_cd_hero',
    'type'    => 'textarea',
  ] );

  /* ════════════════════════════════════════
     Секция «Очистка файлов» перенесена в плагин
     Podval Print Cleanup (WooCommerce → 🗑 Очистка принтов)
  ════════════════════════════════════════ */

  /* ════════════════════════════════════════
     СЕКЦИЯ: Бандлы — Hero на /bundles/
     ────────────────────────────────────────
     Управление верхним блоком страницы /bundles/.
     Defaults намеренно совпадают с прежним хардкодом
     (page-bundles.php до прежней версии) — миграция без визуальной
     регрессии. Шкала скидок и состав бандлов вынесены
     в meta-box CPT podval_bundle (см. прежней версии).
  ════════════════════════════════════════ */
  $wp_customize->add_section( 'podval_bundles_hero', [
    'title'    => 'Бандлы — Hero',
    'panel'    => 'podval_panel',
    'priority' => 75,
  ] );

  // Лейбл-стрелка над заголовком
  $wp_customize->add_setting( 'podval_bundles_hero_label', [
    'default'           => '→ Новый раздел',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_bundles_hero_label', [
    'label'       => 'Лейбл над заголовком',
    'description' => 'Например: «→ Новый раздел». Оставьте пустым — лейбл скроется.',
    'section'     => 'podval_bundles_hero',
    'type'        => 'text',
  ] );

  // Заголовок (с поддержкой <br>)
  $wp_customize->add_setting( 'podval_bundles_hero_title', [
    'default'           => 'Наборы<br>футболок',
    'sanitize_callback' => 'wp_kses_post',
  ] );
  $wp_customize->add_control( 'podval_bundles_hero_title', [
    'label'       => 'Заголовок',
    'description' => 'Можно использовать &lt;br&gt; для переноса строки.',
    'section'     => 'podval_bundles_hero',
    'type'        => 'textarea',
  ] );

  // Подзаголовок
  $wp_customize->add_setting( 'podval_bundles_hero_sub', [
    'default'           => 'Собери коллекцию любимых принтов и&nbsp;получи выгоду до&nbsp;20% за&nbsp;комплект.',
    'sanitize_callback' => 'wp_kses_post',
  ] );
  $wp_customize->add_control( 'podval_bundles_hero_sub', [
    'label'       => 'Подзаголовок',
    'description' => 'Можно использовать &amp;nbsp; для неразрывных пробелов.',
    'section'     => 'podval_bundles_hero',
    'type'        => 'textarea',
  ] );

  // CTA: текст кнопки
  $wp_customize->add_setting( 'podval_bundles_hero_cta_text', [
    'default'           => 'Смотреть наборы',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_bundles_hero_cta_text', [
    'label'   => 'Текст кнопки',
    'section' => 'podval_bundles_hero',
    'type'    => 'text',
  ] );

  // CTA: ссылка (может быть якорь или URL)
  $wp_customize->add_setting( 'podval_bundles_hero_cta_url', [
    'default'           => '#bundles-grid',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_bundles_hero_cta_url', [
    'label'       => 'Ссылка кнопки',
    'description' => 'Якорь (#bundles-grid) или полный URL. По умолчанию — скролл к сетке бандлов.',
    'section'     => 'podval_bundles_hero',
    'type'        => 'text',
  ] );

  // Бейдж скидки — число
  $wp_customize->add_setting( 'podval_bundles_hero_badge_num', [
    'default'           => '−15%',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_bundles_hero_badge_num', [
    'label'       => 'Бейдж — число',
    'description' => 'Например: «−15%». Оставьте пустым — бейдж скроется полностью.',
    'section'     => 'podval_bundles_hero',
    'type'        => 'text',
  ] );

  // Бейдж скидки — подпись
  $wp_customize->add_setting( 'podval_bundles_hero_badge_sub', [
    'default'           => 'за комплект',
    'sanitize_callback' => 'sanitize_text_field',
  ] );
  $wp_customize->add_control( 'podval_bundles_hero_badge_sub', [
    'label'   => 'Бейдж — подпись',
    'section' => 'podval_bundles_hero',
    'type'    => 'text',
  ] );

  // Опциональная картинка Hero (заменяет SVG-композицию из 4 футболок)
  $wp_customize->add_setting( 'podval_bundles_hero_image', [
    'default'           => '',
    'sanitize_callback' => 'absint',
  ] );
  $wp_customize->add_control( new WP_Customize_Media_Control( $wp_customize, 'podval_bundles_hero_image', [
    'label'       => 'Картинка Hero (опционально)',
    'description' => 'Если загружено — заменит SVG-композицию из 4 футболок. Рекомендуемое соотношение 16:9, минимум 1920×1080. Оставьте пустым — будет показана SVG-композиция по умолчанию.',
    'section'     => 'podval_bundles_hero',
    'mime_type'   => 'image',
  ] ) );

} );
