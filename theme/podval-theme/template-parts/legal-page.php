<?php
/**
 * Служебная страница: заголовок, дата обновления, текст из редактора страницы
 * и, по запросу, реквизиты. Сам текст хранится в WordPress, не в шаблоне.
 *
 * Аргументы: requisites (bool) — показать блок реквизитов.
 *
 * @package Podval Print
 */

defined( 'ABSPATH' ) || exit;

$show_requisites = ! empty( $args['requisites'] );

while ( have_posts() ) :
	the_post();
	?>
<article class="podval-legal" itemscope itemtype="https://schema.org/WebPage">
  <meta itemprop="name" content="<?php echo esc_attr( get_the_title() . ' — ' . podval_brand( 'name' ) ); ?>">
  <meta itemprop="dateModified" content="<?php echo esc_attr( get_the_modified_date( 'Y-m-d' ) ); ?>">

  <div class="podval-legal__header">
    <nav class="podval-legal__breadcrumbs" aria-label="Навигация">
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Главная</a>
      <span>—</span>
      <span><?php the_title(); ?></span>
    </nav>
    <h1 class="podval-legal__title"><?php the_title(); ?></h1>
    <p class="podval-legal__updated">Последнее обновление: <?php echo esc_html( get_the_modified_date( 'd.m.Y' ) ); ?></p>
  </div>

  <div class="podval-legal__content" itemprop="text">
    <?php the_content(); ?>
    <?php
    if ( $show_requisites ) {
    	get_template_part( 'template-parts/requisites' );
    }
    ?>
  </div>
</article>
	<?php
endwhile;
