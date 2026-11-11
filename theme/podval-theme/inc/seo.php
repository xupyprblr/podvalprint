<?php
/**
 * Podval Print — SEO Module
 * 
 * Масштабируемая SEO-система:
 * - JSON-LD LocalBusiness (на всех страницах, один раз)
 * - JSON-LD FAQPage (на страницах с FAQ-данными)
 * - JSON-LD BreadcrumbList (автоматические хлебные крошки)
 * 
 * Подключение: require_once в functions.php
 * Использование в шаблонах: podval_seo_faq_schema( $faq_array )
 */

defined( 'ABSPATH' ) || exit;

/* =============================================
   1. LOCAL BUSINESS SCHEMA — на всех страницах
   ============================================= */

add_action( 'wp_head', 'podval_seo_local_business_schema', 90 );

function podval_seo_local_business_schema() {
    // Выводим один раз — на всех страницах сайта
    $site_name = podval_brand( 'name', get_bloginfo( 'name' ) );
    $site_url  = home_url( '/' );
    $logo_url  = '';

    $custom_logo_id = get_theme_mod( 'custom_logo' );
    if ( $custom_logo_id ) {
        $logo_url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
    }

    // Данные из Customizer (те же что в header/footer)
    $phone = get_theme_mod( 'podval_header_phone', podval_brand( 'phone' ) );
    $tg    = get_theme_mod( 'podval_header_tg', '' );
    $email = get_theme_mod( 'podval_footer_email', podval_brand( 'email' ) );

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'LocalBusiness',
        '@id'         => $site_url . '#business',
        'name'        => $site_name,
        'url'         => $site_url,
        'address'     => array_filter( [
            '@type'           => 'PostalAddress',
            'streetAddress'   => podval_brand( 'street_address' ),
            'addressLocality' => podval_brand( 'city' ),
            'addressCountry'  => podval_brand( 'country' ),
        ] ),
        'priceRange'      => 'BYN',
        'paymentAccepted' => 'Cash, Credit Card, Bank Transfer',
        'areaServed'      => [
            '@type' => 'Country',
            'name'  => 'Беларусь',
        ],
    ];

    // Описание и координаты — из конфига бренда; пустые не выводим.
    if ( podval_brand( 'description' ) ) {
        $schema['description'] = podval_brand( 'description' );
    }
    if ( podval_brand( 'geo_lat' ) && podval_brand( 'geo_lng' ) ) {
        $schema['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => (string) podval_brand( 'geo_lat' ), 'longitude' => (string) podval_brand( 'geo_lng' ) ];
    }
    if ( podval_brand( 'legal_name' ) ) {
        $schema['legalName'] = podval_brand( 'legal_name' );
    }
    if ( $logo_url ) {
        $schema['logo']  = $logo_url;
        $schema['image'] = $logo_url;
    }
    if ( $phone ) {
        $schema['telephone'] = preg_replace( '/[^\d+]/', '', $phone );
    }
    if ( $email ) {
        $schema['email'] = $email;
    }

    // Соцсети
    $socials = [];
    $tg_url = get_theme_mod( 'podval_header_tg', '' );
    $ig_url = get_theme_mod( 'podval_footer_ig', '' );
    $yt_url = get_theme_mod( 'podval_footer_yt', '' );
    $tt_url = get_theme_mod( 'podval_footer_tt', '' );
    foreach ( [ $tg_url, $ig_url, $yt_url, $tt_url ] as $s ) {
        if ( $s ) $socials[] = $s;
    }
    if ( $socials ) {
        $schema['sameAs'] = $socials;
    }

    echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
}


/* =============================================
   2. FAQ PAGE SCHEMA — вызывается из шаблонов
   ============================================= */

/**
 * Выводит JSON-LD FAQPage schema.
 * 
 * Вызов из шаблона:
 *   podval_seo_faq_schema([
 *       [ 'q' => 'Вопрос?', 'a' => 'Ответ.' ],
 *       [ 'q' => 'Другой вопрос?', 'a' => 'Другой ответ.' ],
 *   ]);
 * 
 * @param array $faq_items Массив [ ['q' => ..., 'a' => ...], ... ]
 */
function podval_seo_faq_schema( $faq_items ) {
    if ( empty( $faq_items ) ) return;

    $entities = [];
    foreach ( $faq_items as $item ) {
        if ( empty( $item['q'] ) || empty( $item['a'] ) ) continue;
        $entities[] = [
            '@type' => 'Question',
            'name'  => wp_strip_all_tags( $item['q'] ),
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text'  => wp_strip_all_tags( $item['a'] ),
            ],
        ];
    }

    if ( empty( $entities ) ) return;

    $schema = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entities,
    ];

    echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
}


/* =============================================
   3. BREADCRUMB SCHEMA — автоматический
   ============================================= */

add_action( 'wp_head', 'podval_seo_breadcrumb_schema', 91 );

function podval_seo_breadcrumb_schema() {
    // Не дублируем если Yoast уже выводит breadcrumb schema
    if ( defined( 'WPSEO_VERSION' ) ) return;

    $items = [];
    $pos   = 1;

    // Главная
    $items[] = [
        '@type'    => 'ListItem',
        'position' => $pos++,
        'name'     => 'Главная',
        'item'     => home_url( '/' ),
    ];

    if ( is_product_category() ) {
        $term = get_queried_object();
        if ( $term && ! is_wp_error( $term ) ) {
            // Если есть родитель
            if ( $term->parent ) {
                $parent = get_term( $term->parent, 'product_cat' );
                if ( $parent && ! is_wp_error( $parent ) ) {
                    $items[] = [
                        '@type'    => 'ListItem',
                        'position' => $pos++,
                        'name'     => $parent->name,
                        'item'     => get_term_link( $parent ),
                    ];
                }
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $term->name,
                'item'     => get_term_link( $term ),
            ];
        }
    } elseif ( is_product_tag() ) {
        $term = get_queried_object();
        if ( $term && ! is_wp_error( $term ) ) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $term->name,
                'item'     => get_term_link( $term ),
            ];
        }
    } elseif ( is_singular( 'product' ) ) {
        global $post;
        $cats = get_the_terms( $post->ID, 'product_cat' );
        if ( $cats && ! is_wp_error( $cats ) ) {
            // Берём первую категорию (не uncategorized)
            $cat = null;
            foreach ( $cats as $c ) {
                if ( $c->slug !== 'uncategorized' ) { $cat = $c; break; }
            }
            if ( ! $cat ) $cat = $cats[0];
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $cat->name,
                'item'     => get_term_link( $cat ),
            ];
        }
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => get_the_title(),
            'item'     => get_permalink(),
        ];
    } elseif ( is_page() ) {
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => get_the_title(),
            'item'     => get_permalink(),
        ];
    }

    if ( count( $items ) < 2 ) return; // Только главная — не нужно

    $schema = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $items,
    ];

    echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
}

/* =============================================
   <title> страницы поиска
   Yoast по умолчанию отдаёт «You searched for %s» — английский шаблон
   (в админке Yoast → Search appearance → Archives → Search pages не заполнен).
   Перебиваем на уровне темы, чтобы не зависеть от настроек плагина.
   ============================================= */
add_filter( 'wpseo_title', 'podval_seo_search_title', 20 );
add_filter( 'pre_get_document_title', 'podval_seo_search_title', 20 );
function podval_seo_search_title( $title ) {
    if ( ! is_search() ) return $title;
    $q = get_search_query();
    return $q !== ''
        ? sprintf( 'Поиск: «%s» — %s', $q, get_bloginfo( 'name' ) )
        : sprintf( 'Поиск — %s', get_bloginfo( 'name' ) );
}
add_filter( 'wpseo_robots', function( $robots ) {
    return is_search() ? 'noindex, follow' : $robots;
} );
