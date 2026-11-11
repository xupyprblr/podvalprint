<?php
/**
 * Template Name: О нас
 *
 * Текст страницы редактируется в WordPress. Контакты, часы и карта — из конфига
 * бренда и настроек темы; разметка LocalBusiness выводится в inc/seo.php.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

get_header();

$phone   = get_theme_mod( 'podval_header_phone', podval_brand( 'phone' ) );
$email   = get_theme_mod( 'podval_footer_email', podval_brand( 'email' ) );
$tg      = get_theme_mod( 'podval_footer_tg', podval_brand( 'telegram' ) );
$ig      = get_theme_mod( 'podval_footer_ig', podval_brand( 'instagram' ) );
$address = get_theme_mod( 'podval_footer_address', podval_brand( 'address' ) );
$hours   = (array) podval_brand( 'hours', array() );
$map     = podval_brand( 'map_embed_url' );
$tagline = podval_brand( 'tagline' );
?>

<article class="podval-about">

  <section class="podval-about__hero">
    <div class="podval-about__hero-inner">
      <h1 class="podval-about__hero-title">
        <span class="podval-about__hero-accent"><?php echo esc_html( podval_brand( 'logo_word_1' ) ); ?></span>
        <?php echo esc_html( podval_brand( 'logo_word_2' ) ); ?>
      </h1>
      <?php if ( $tagline ) : ?>
        <p class="podval-about__hero-tagline"><?php echo esc_html( $tagline ); ?></p>
      <?php endif; ?>
    </div>
  </section>

  <?php while ( have_posts() ) : the_post(); ?>
    <?php if ( trim( (string) get_the_content() ) !== '' ) : ?>
    <section class="podval-about__section">
      <div class="podval-about__container podval-about__content">
        <?php the_content(); ?>
      </div>
    </section>
    <?php endif; ?>
  <?php endwhile; ?>

  <?php if ( $address || $phone || $email ) : ?>
  <section class="podval-about__section podval-about__section--dark" id="contacts">
    <div class="podval-about__container">
      <h2 class="podval-about__section-title">Как нас найти</h2>

      <div class="podval-about__contacts-grid">
        <div class="podval-about__contact-info">
          <?php if ( $address ) : ?>
          <div class="podval-about__contact-block">
            <h3>Адрес</h3>
            <p><?php echo esc_html( $address ); ?></p>
          </div>
          <?php endif; ?>
          <?php if ( $hours ) : ?>
          <div class="podval-about__contact-block">
            <h3>Режим работы</h3>
            <p><?php echo implode( '<br>', array_map( 'esc_html', $hours ) ); ?></p>
          </div>
          <?php endif; ?>
          <div class="podval-about__contact-block">
            <h3>Связаться</h3>
            <p>
              <?php if ( $phone ) : ?><a href="tel:<?php echo esc_attr( podval_brand_tel( $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a><br><?php endif; ?>
              <?php if ( $email ) : ?><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a><?php endif; ?>
              <?php if ( $tg ) : ?><br><a href="<?php echo esc_url( $tg ); ?>" target="_blank" rel="noopener">Telegram</a><?php endif; ?>
              <?php if ( $ig ) : ?><br><a href="<?php echo esc_url( $ig ); ?>" target="_blank" rel="noopener">Instagram</a><?php endif; ?>
            </p>
          </div>
          <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>" class="podval-about__cta-btn">Перейти в каталог</a>
        </div>

        <?php if ( $map ) : ?>
        <div class="podval-about__map">
          <iframe src="<?php echo esc_url( $map ); ?>" width="100%" height="100%"
            style="border:0; min-height:400px; border-radius:10px;" allowfullscreen loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            title="<?php echo esc_attr( podval_brand( 'name' ) . ' на карте' ); ?>"></iframe>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

</article>

<?php get_footer(); ?>
