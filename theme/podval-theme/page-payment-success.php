<?php
/**
 * Podval Print — Payment Success page
 * URL: /payment-success/
 * Template Name: Оплата успешна
 */
defined( 'ABSPATH' ) || exit;
get_header();
?>

<div class="podval-pay-result">
  <div class="podval-pay-result__icon podval-pay-result__icon--success">
    <svg viewBox="0 0 80 80" fill="none">
      <circle cx="40" cy="40" r="38" stroke="currentColor" stroke-width="2"/>
      <path d="M24 42l10 10 22-22" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </div>

  <h1 class="podval-pay-result__title">Оплата прошла успешно!</h1>
  <p class="podval-pay-result__text">Спасибо за покупку! Ваш заказ оплачен и уже обрабатывается. Мы свяжемся с вами для подтверждения.</p>

  <div class="podval-pay-result__actions">
    <a href="<?php echo esc_url( home_url('/') ); ?>" class="podval-pay-result__btn-primary">На главную</a>
    <a href="<?php echo esc_url( podval_get_catalog_url() ); ?>" class="podval-pay-result__btn-outline">Продолжить покупки</a>
  </div>
</div>

<?php get_footer(); ?>
