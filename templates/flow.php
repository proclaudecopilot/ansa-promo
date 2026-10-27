<?php
/**
 * ansa™ Промо — вариант B „потокът без кутии“ (1.34.0): скелетът на [ansa_promo variant="flow"].
 *
 * Всичко се рендира от assets/flow.js в #flApp (мокъпът: docs/ansa-desktop-mockup.html — под 900px мобилният изглед, над 900px десктоп
 * с лепкав панел „Твоята оферта“). Данните са същият window.AnsaPromoRuntime като на основната страница; поръчката минава през същия
 * ansa_promo_add (с variant=flow), броенето — през същия ansa_promo_beacon (с variant=flow).
 *
 * @package AnsaPromo
 * @since 1.34.0
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="ansa-flow" id="ansaFlow" data-ver="<?php echo esc_attr( ANSA_PROMO_VER ); ?>" data-no-translation>
  <div class="phone" id="flApp" aria-busy="true"></div>
  <noscript><p style="text-align:center;padding:40px 16px;font:700 16px/1.4 Nunito,system-ui,sans-serif">Включи JavaScript, за да видиш офертата на Сезона на ansa™.</p></noscript>
</div>
