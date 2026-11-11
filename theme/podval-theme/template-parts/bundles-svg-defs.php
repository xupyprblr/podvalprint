<?php
/**
 * SVG symbol defs for bundles page.
 *
 * Содержит:
 *  - #tee, #tee-hang — силуэты футболок
 *  - #prn-A..#prn-H  — абстрактные принты-плейсхолдеры
 *  - #ic-* — иконки UI (стрелки, плюс, ruler, printer, factory, chev)
 *
 * Подключается один раз внутри page-bundles.php.
 * Не используется на других страницах, поэтому inline здесь, а не глобально.
 */
defined( 'ABSPATH' ) || exit;
?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <defs>
    <!-- T-shirt body flat-lay -->
    <symbol id="tee" viewBox="0 0 200 240">
      <path d="M40 18 L72 4 L82 14 Q100 28 118 14 L128 4 L160 18 L196 38 L182 78 L160 70 L160 232 Q160 236 156 236 L44 236 Q40 236 40 232 L40 70 L18 78 L4 38 Z" fill="currentColor" />
      <path d="M82 14 Q100 32 118 14" fill="none" stroke="rgba(0,0,0,0.18)" stroke-width="1.2" />
    </symbol>

    <!-- T-shirt body hanging -->
    <symbol id="tee-hang" viewBox="0 0 200 240">
      <path d="M44 22 L74 6 L86 18 Q100 30 114 18 L126 6 L156 22 L188 42 L176 82 L156 74 L156 236 L44 236 L44 74 L24 82 L12 42 Z" fill="currentColor" />
    </symbol>

    <!-- ====== ABSTRACT PRINT VARIATIONS ====== -->
    <symbol id="prn-A" viewBox="0 0 80 100">
      <text x="40" y="22" text-anchor="middle" font-family="Oswald,Arial" font-size="18" font-weight="700" fill="currentColor" letter-spacing="1">SIGNAL</text>
      <rect x="6" y="30" width="68" height="2" fill="currentColor"/>
      <text x="40" y="50" text-anchor="middle" font-family="Unbounded,Arial" font-size="6.5" font-weight="700" fill="currentColor" letter-spacing="2">VOLUME · 03</text>
      <text x="40" y="80" text-anchor="middle" font-family="Oswald,Arial" font-size="32" font-weight="700" fill="currentColor">/03</text>
    </symbol>

    <symbol id="prn-B" viewBox="0 0 80 100">
      <circle cx="40" cy="48" r="30" fill="none" stroke="currentColor" stroke-width="2.5"/>
      <line x1="14" y1="78" x2="66" y2="18" stroke="currentColor" stroke-width="3"/>
      <text x="40" y="98" text-anchor="middle" font-family="Unbounded,Arial" font-size="5.5" font-weight="700" fill="currentColor" letter-spacing="2.5">NO·SIGNAL</text>
    </symbol>

    <symbol id="prn-C" viewBox="0 0 80 100">
      <polygon points="6,78 26,30 46,78" fill="currentColor"/>
      <polygon points="32,78 54,18 76,78" fill="none" stroke="currentColor" stroke-width="2"/>
      <circle cx="62" cy="22" r="6" fill="currentColor"/>
      <rect x="6" y="84" width="68" height="2" fill="currentColor"/>
      <text x="40" y="96" text-anchor="middle" font-family="Oswald,Arial" font-size="9" font-weight="700" fill="currentColor" letter-spacing="2">RIDGE 84</text>
    </symbol>

    <symbol id="prn-D" viewBox="0 0 80 100">
      <g fill="currentColor">
        <rect x="6"  y="10" width="6" height="80"/>
        <rect x="16" y="22" width="6" height="56"/>
        <rect x="26" y="6"  width="6" height="88"/>
        <rect x="36" y="30" width="6" height="40"/>
        <rect x="46" y="14" width="6" height="72"/>
        <rect x="56" y="38" width="6" height="24"/>
        <rect x="66" y="18" width="6" height="64"/>
      </g>
    </symbol>

    <symbol id="prn-E" viewBox="0 0 80 100">
      <g stroke="currentColor" stroke-width="3" stroke-linecap="square">
        <line x1="40" y1="14" x2="40" y2="62"/>
        <line x1="16" y1="38" x2="64" y2="38"/>
        <line x1="22" y1="20" x2="58" y2="56"/>
        <line x1="58" y1="20" x2="22" y2="56"/>
      </g>
      <text x="40" y="84" text-anchor="middle" font-family="Oswald,Arial" font-size="12" font-weight="700" fill="currentColor" letter-spacing="2">EMPIRE</text>
      <text x="40" y="94" text-anchor="middle" font-family="Unbounded,Arial" font-size="5" font-weight="700" fill="currentColor" letter-spacing="2.5">EST · MMXIX</text>
    </symbol>

    <symbol id="prn-F" viewBox="0 0 80 100">
      <g fill="none" stroke="currentColor" stroke-width="2">
        <rect x="14" y="18" width="52" height="60"/>
        <rect x="22" y="26" width="36" height="44"/>
        <line x1="14" y1="42" x2="66" y2="42"/>
        <line x1="40" y1="18" x2="40" y2="78"/>
      </g>
      <rect x="34" y="60" width="12" height="18" fill="currentColor"/>
      <text x="40" y="94" text-anchor="middle" font-family="Unbounded,Arial" font-size="5" font-weight="700" fill="currentColor" letter-spacing="2">BLOCK · 09</text>
    </symbol>

    <symbol id="prn-G" viewBox="0 0 80 100">
      <g fill="currentColor">
        <circle cx="12" cy="14" r="1.2"/><circle cx="24" cy="14" r="1.6"/><circle cx="36" cy="14" r="2"/><circle cx="48" cy="14" r="2.4"/><circle cx="60" cy="14" r="2.8"/><circle cx="72" cy="14" r="3.2"/>
        <circle cx="12" cy="28" r="1.6"/><circle cx="24" cy="28" r="2"/><circle cx="36" cy="28" r="2.4"/><circle cx="48" cy="28" r="2.8"/><circle cx="60" cy="28" r="3.2"/><circle cx="72" cy="28" r="3.6"/>
        <circle cx="12" cy="42" r="2"/><circle cx="24" cy="42" r="2.4"/><circle cx="36" cy="42" r="2.8"/><circle cx="48" cy="42" r="3.2"/><circle cx="60" cy="42" r="3.6"/><circle cx="72" cy="42" r="4"/>
        <circle cx="12" cy="56" r="2.4"/><circle cx="24" cy="56" r="2.8"/><circle cx="36" cy="56" r="3.2"/><circle cx="48" cy="56" r="3.6"/><circle cx="60" cy="56" r="4"/><circle cx="72" cy="56" r="4.4"/>
      </g>
      <text x="40" y="84" text-anchor="middle" font-family="Oswald,Arial" font-size="11" font-weight="700" fill="currentColor" letter-spacing="3">FADE</text>
    </symbol>

    <symbol id="prn-H" viewBox="0 0 80 100">
      <g fill="currentColor">
        <rect x="8"  y="20" width="2" height="50"/>
        <rect x="12" y="20" width="4" height="50"/>
        <rect x="18" y="20" width="1" height="50"/>
        <rect x="21" y="20" width="3" height="50"/>
        <rect x="26" y="20" width="2" height="50"/>
        <rect x="30" y="20" width="5" height="50"/>
        <rect x="37" y="20" width="1" height="50"/>
        <rect x="40" y="20" width="2" height="50"/>
        <rect x="44" y="20" width="4" height="50"/>
        <rect x="50" y="20" width="1" height="50"/>
        <rect x="53" y="20" width="3" height="50"/>
        <rect x="58" y="20" width="2" height="50"/>
        <rect x="62" y="20" width="4" height="50"/>
        <rect x="68" y="20" width="1" height="50"/>
        <rect x="71" y="20" width="3" height="50"/>
      </g>
      <text x="40" y="84" text-anchor="middle" font-family="Unbounded,Arial" font-size="5.5" font-weight="700" fill="currentColor" letter-spacing="3">0451 · CYBER</text>
    </symbol>

    <!-- ====== UI ICONS (1.5px stroke) ====== -->
    <symbol id="bnd-ic-arrow-dr" viewBox="0 0 32 32">
      <g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="square">
        <path d="M8 8 L24 24"/>
        <path d="M14 24 L24 24 L24 14"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-ruler" viewBox="0 0 32 32">
      <g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="miter">
        <rect x="3" y="11" width="26" height="10"/>
        <path d="M8 11 L8 15 M12 11 L12 17 M16 11 L16 15 M20 11 L20 17 M24 11 L24 15"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-printer" viewBox="0 0 32 32">
      <g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="miter">
        <path d="M8 12 L8 5 L24 5 L24 12"/>
        <rect x="4" y="12" width="24" height="11"/>
        <rect x="9" y="19" width="14" height="8"/>
        <circle cx="24" cy="16" r="1" fill="currentColor" stroke="none"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-factory" viewBox="0 0 32 32">
      <g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="miter">
        <path d="M4 27 L4 14 L12 18 L12 14 L20 18 L20 14 L28 18 L28 27 Z"/>
        <path d="M7 10 L7 5 L11 5 L11 10"/>
        <line x1="10" y1="23" x2="10" y2="25"/>
        <line x1="16" y1="23" x2="16" y2="25"/>
        <line x1="22" y1="23" x2="22" y2="25"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-plus" viewBox="0 0 24 24">
      <g stroke="currentColor" stroke-width="1.4" stroke-linecap="square">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <line x1="5"  y1="12" x2="19" y2="12"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-arrow" viewBox="0 0 16 16">
      <g stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="square">
        <line x1="3" y1="8" x2="13" y2="8"/>
        <path d="M9 4 L13 8 L9 12"/>
      </g>
    </symbol>

    <!-- ====== ICONS ADDED IN прежней версии (single bundle page) ====== -->
    <symbol id="bnd-ic-arrow-l" viewBox="0 0 16 16">
      <g stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="square">
        <line x1="13" y1="8" x2="3" y2="8"/>
        <path d="M7 4 L3 8 L7 12"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-chev-d" viewBox="0 0 16 16">
      <path d="M3 6 L8 11 L13 6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="square"/>
    </symbol>
    <symbol id="bnd-ic-cart" viewBox="0 0 20 20">
      <g fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="miter">
        <path d="M3 4 L5 4 L7 14 L17 14 L18 6 L6 6"/>
        <circle cx="8" cy="17" r="1.2" fill="currentColor" stroke="none"/>
        <circle cx="15" cy="17" r="1.2" fill="currentColor" stroke="none"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-heart" viewBox="0 0 20 20">
      <path d="M10 17 L3.5 10.5 Q1 8 3 5.5 Q5.5 3 8 5.5 L10 7.5 L12 5.5 Q14.5 3 17 5.5 Q19 8 16.5 10.5 Z"
            fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
    </symbol>
    <symbol id="bnd-ic-info-mini" viewBox="0 0 16 16">
      <g fill="none" stroke="currentColor" stroke-width="1.4">
        <circle cx="8" cy="8" r="6.5"/>
        <line x1="8" y1="7" x2="8" y2="12"/>
        <circle cx="8" cy="4.5" r="0.6" fill="currentColor"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-x" viewBox="0 0 16 16">
      <g stroke="currentColor" stroke-width="1.6" stroke-linecap="square">
        <line x1="4" y1="4" x2="12" y2="12"/>
        <line x1="12" y1="4" x2="4" y2="12"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-cotton" viewBox="0 0 32 32">
      <g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round">
        <!-- stylized cotton boll: cluster of 4 rounded shapes + leaf -->
        <circle cx="11" cy="16" r="5"/>
        <circle cx="21" cy="16" r="5"/>
        <circle cx="16" cy="11" r="5"/>
        <circle cx="16" cy="21" r="5"/>
        <path d="M16 6 Q10 4 8 8" stroke-linecap="round"/>
      </g>
    </symbol>

    <!-- ====== ICONS ADDED IN прежней версии (bundle builder page) ====== -->
    <symbol id="bnd-ic-check" viewBox="0 0 16 16">
      <path d="M3 8 L7 12 L13 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square" stroke-linejoin="miter"/>
    </symbol>
    <symbol id="bnd-ic-arrow-d" viewBox="0 0 16 16">
      <g stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="square">
        <line x1="8" y1="3" x2="8" y2="13"/>
        <path d="M4 9 L8 13 L12 9"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-share" viewBox="0 0 16 16">
      <g fill="none" stroke="currentColor" stroke-width="1.3">
        <circle cx="4" cy="8" r="1.6"/>
        <circle cx="12" cy="4" r="1.6"/>
        <circle cx="12" cy="12" r="1.6"/>
        <line x1="5.5" y1="7" x2="10.5" y2="4.8"/>
        <line x1="5.5" y1="9" x2="10.5" y2="11.2"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-search" viewBox="0 0 20 20">
      <g fill="none" stroke="currentColor" stroke-width="1.4">
        <circle cx="9" cy="9" r="5"/>
        <line x1="13" y1="13" x2="17" y2="17" stroke-linecap="square"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-shirt-sliders" viewBox="0 0 24 24">
      <g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="miter">
        <path d="M5 7 L8 4 L9 5 Q12 7 15 5 L16 4 L19 7 L21 9 L19.5 11 L18 10.5 L18 20 L6 20 L6 10.5 L4.5 11 L3 9 Z"/>
        <line x1="9" y1="14" x2="15" y2="14"/>
        <line x1="9" y1="17" x2="13" y2="17"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-chart-up" viewBox="0 0 24 24">
      <g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="miter" stroke-linecap="square">
        <path d="M3 20 L9 14 L13 17 L21 8"/>
        <path d="M16 8 L21 8 L21 13"/>
      </g>
    </symbol>
    <symbol id="bnd-ic-percent" viewBox="0 0 24 24">
      <g fill="none" stroke="currentColor" stroke-width="1.5">
        <circle cx="12" cy="12" r="9"/>
        <line x1="8" y1="16" x2="16" y2="8" stroke-linecap="square"/>
        <circle cx="9" cy="9" r="1.5"/>
        <circle cx="15" cy="15" r="1.5"/>
      </g>
    </symbol>
  </defs>
</svg>
