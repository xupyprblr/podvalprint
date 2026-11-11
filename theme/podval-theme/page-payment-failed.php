<?php
/**
 * Podval Print — Payment Failed page
 * URL: /payment-failed/
 * Template Name: Ошибка оплаты
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>

<div class="podval-pay-result">
  <div class="podval-pay-result__icon podval-pay-result__icon--fail">
    <svg viewBox="0 0 80 80" fill="none">
      <circle cx="40" cy="40" r="38" stroke="currentColor" stroke-width="2"/>
      <path d="M28 28l24 24M52 28L28 52" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
    </svg>
  </div>

  <h1 class="podval-pay-result__title">Оплата не прошла</h1>
  <p class="podval-pay-result__text">К сожалению, платёж не был завершён. Попробуйте ещё раз или выберите другой способ оплаты. Если проблема повторяется — свяжитесь с нами.</p>

  <div class="podval-pay-result__actions">
    <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="podval-pay-result__btn-primary">Попробовать снова</a>
    <a href="<?php echo esc_url( home_url('/') ); ?>" class="podval-pay-result__btn-outline">На главную</a>
  </div>

  <div class="podval-pay-result__contact">
    <?php $phone = get_theme_mod( 'podval_header_phone', podval_brand( 'phone' ) ); ?>
    <p>Нужна помощь? Напишите нам:</p>
    <?php if ( $phone ) : ?>
    <a href="tel:<?php echo esc_attr( preg_replace('/[^\d+]/', '', $phone) ); ?>"><?php echo esc_html( $phone ); ?></a>
    <?php endif; ?>
  </div>
</div>

<?php get_footer(); ?>
