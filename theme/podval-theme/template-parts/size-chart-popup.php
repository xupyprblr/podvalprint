<?php
/**
 * Попап «Таблица размеров» — переиспользуемый
 * ------------------------------------------------------------------
 * Раньше разметка жила прямо в woocommerce/single-product.php. Теперь
 * она нужна ещё и на странице бандла, поэтому вынесена сюда, чтобы
 * два экземпляра не разъезжались при правках.
 *
 * Аргументы через set_query_var / get_template_part( ..., $args ):
 *   sc_type  string|int  значение меты _podval_size_chart
 *   sc_id    string      id элемента (дефолт 'size-chart-popup')
 *
 * Стили: assets/css/style-size-chart.css
 * JS:    assets/js/main.js (.js-size-chart-open / .js-sc-close / .sc-tab)
 *
 * @package podval-print
 */
defined( 'ABSPATH' ) || exit;

$sc_type    = $args['sc_type'] ?? '';
$sc_dom_id  = $args['sc_id'] ?? 'size-chart-popup';
if ( ! $sc_type ) {
	return;
}
$is_cpt_chart = is_numeric( $sc_type ) && get_post_type( (int) $sc_type ) === 'podval_size_chart';
?>
<div class="sc-overlay js-sc-close" id="<?php echo esc_attr( $sc_dom_id ); ?>">
  <div class="sc-popup" onclick="event.stopPropagation()">
    <div class="sc-header">
      <h3 class="sc-title">Таблица размеров</h3>
      <button class="sc-close js-sc-close" aria-label="Закрыть">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 6 6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="sc-body">

      <?php if ( $is_cpt_chart ) :
        $sc_id = (int) $sc_type;
        $sc_image_id   = get_post_meta( $sc_id, '_sc_image_id', true );
        $sc_columns    = json_decode( get_post_meta( $sc_id, '_sc_columns', true ), true );
        $sc_rows       = json_decode( get_post_meta( $sc_id, '_sc_rows', true ), true );
        $sc_disclaimer = get_post_meta( $sc_id, '_sc_disclaimer', true );
        if ( ! is_array( $sc_columns ) ) $sc_columns = [];
        if ( ! is_array( $sc_rows ) )    $sc_rows = [];
      ?>
      <div class="sc-panel is-active">
        <?php if ( $sc_image_id ) :
          $sc_img_meta = wp_get_attachment_image_src( $sc_image_id, 'medium' );
          $sc_img_w = $sc_img_meta ? (int) $sc_img_meta[1] : 260;
          $sc_img_h = $sc_img_meta ? (int) $sc_img_meta[2] : 260;
        ?>
        <div class="sc-illustration">
          <img src="<?php echo esc_url( $sc_img_meta ? $sc_img_meta[0] : wp_get_attachment_image_url( $sc_image_id, 'medium' ) ); ?>" alt="<?php echo esc_attr( get_the_title( $sc_id ) ); ?>" class="sc-img" width="<?php echo $sc_img_w; ?>" height="<?php echo $sc_img_h; ?>" loading="lazy" decoding="async">
        </div>
        <?php endif; ?>
        <?php if ( ! empty( $sc_columns ) && ! empty( $sc_rows ) ) : ?>
        <table class="sc-table">
          <thead><tr><?php foreach ( $sc_columns as $col ) : ?><th><?php echo esc_html( $col ); ?></th><?php endforeach; ?></tr></thead>
          <tbody>
            <?php foreach ( $sc_rows as $row ) : ?>
            <tr><?php foreach ( $sc_columns as $ci => $col ) : ?><td><?php echo esc_html( isset( $row[ $ci ] ) ? $row[ $ci ] : '' ); ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php if ( $sc_disclaimer ) : ?>
      <p class="sc-disclaimer"><?php echo esc_html( $sc_disclaimer ); ?></p>
      <?php endif; ?>

      <?php elseif ( $sc_type === 'tshirt' ) : ?>
      <div class="sc-tabs">
        <button class="sc-tab is-active" data-tab="classic">Classic</button>
        <button class="sc-tab" data-tab="oversize">Oversize</button>
      </div>
      <div class="sc-panel is-active" data-panel="classic">
        <div class="sc-illustration">
          <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/size-chart-classic.png' ); ?>" alt="Classic T-shirt размеры" class="sc-img" width="408" height="508" loading="lazy" decoding="async">
        </div>
        <table class="sc-table">
          <thead><tr><th>Размер</th><th>A, Ширина</th><th>B, Длина</th></tr></thead>
          <tbody>
            <tr><td>XS/44</td><td>48</td><td>64</td></tr>
            <tr><td>S/46</td><td>50</td><td>70</td></tr>
            <tr><td>M/48</td><td>53</td><td>72</td></tr>
            <tr><td>L/50</td><td>56</td><td>74</td></tr>
            <tr><td>XL/52</td><td>59</td><td>76</td></tr>
            <tr><td>2XL/54</td><td>62</td><td>78</td></tr>
            <tr><td>3XL/56</td><td>65</td><td>80</td></tr>
          </tbody>
        </table>
      </div>
      <div class="sc-panel" data-panel="oversize">
        <div class="sc-illustration">
          <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/size-chart-oversize.png' ); ?>" alt="Oversize T-shirt размеры" class="sc-img" width="506" height="520" loading="lazy" decoding="async">
        </div>
        <table class="sc-table">
          <thead><tr><th>Размер</th><th>A, Ширина</th><th>B, Длина</th><th>C, Рукав</th></tr></thead>
          <tbody>
            <tr><td>XS</td><td>53</td><td>71</td><td>39</td></tr>
            <tr><td>S</td><td>55</td><td>73</td><td>41</td></tr>
            <tr><td>M</td><td>57</td><td>74</td><td>42</td></tr>
            <tr><td>L</td><td>59</td><td>76</td><td>42</td></tr>
            <tr><td>XL</td><td>61</td><td>77</td><td>42</td></tr>
            <tr><td>2XL</td><td>63</td><td>80</td><td>43</td></tr>
            <tr><td>3XL</td><td>65</td><td>82</td><td>44</td></tr>
          </tbody>
        </table>
      </div>
      <p class="sc-disclaimer">* Фактические размеры одежды могут отличаться на +/- 1 см. Рекомендуем сравнить размеры этого изделия с аналогичной одеждой, которая у вас имеется.</p>

      <?php elseif ( $sc_type === 'hoodie' ) : ?>
      <div class="sc-panel is-active" data-panel="hoodie">
        <div class="sc-illustration">
          <img src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/size-chart-hoodie.png' ); ?>" alt="Худи размеры" class="sc-img" width="398" height="457" loading="lazy" decoding="async">
        </div>
        <table class="sc-table">
          <thead><tr><th>Размер</th><th>A, Длина</th><th>B, Ширина</th><th>C, Рукав</th></tr></thead>
          <tbody>
            <tr><td>XS</td><td>70</td><td>53</td><td>71</td></tr>
            <tr><td>S — M</td><td>72</td><td>55</td><td>79</td></tr>
            <tr><td>L — XL</td><td>73</td><td>57</td><td>82</td></tr>
            <tr><td>2XL — 3XL</td><td>75</td><td>59</td><td>85</td></tr>
          </tbody>
        </table>
      </div>
      <p class="sc-disclaimer">* Фактические размеры одежды могут отличаться на +/- 1 см. Рекомендуем сравнить размеры этого изделия с аналогичной одеждой, которая у вас имеется.</p>
      <?php endif; ?>

    </div>
  </div>
</div>
