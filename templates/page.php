<?php
/**
 * ansa™ Промо — скелетът на страницата (същите id-та, които promo.js очаква; мокъп → плъгин: s1→apS1, ov→apOv …).
 *
 * @var bool $editor
 * @package AnsaPromo
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="ansa-promo" id="ansaPromo" data-ver="<?php echo esc_attr( ANSA_PROMO_VER ); ?>"<?php echo ! empty( $editor ) ? ' data-editor="1"' : ''; ?> data-no-translation>
  <div class="toast" id="apToast"></div>
  <div class="ov off" id="apOv"><div class="dc" id="apDc"></div></div>
  <div class="ov ov2 off" id="apOv2"><div class="dc pp" id="apDc2"></div></div>
  <div class="wrap">
    <div class="hdr" id="apHdr"><div class="top"><div class="brand"><span></span> <b></b></div><div class="boxchip" id="apChip"></div><div class="topr"><button class="how" id="apHow" type="button"></button><div class="timer" id="apTimer"></div></div></div><div id="apStrip"></div></div>
    <div class="scr" id="apS1"></div>
    <div class="scr" id="apS2"></div>
    <div class="scr" id="apS4"></div>
    <div class="scr" id="apS5"></div>
    <div class="foot"></div>
  </div>
  <div class="sticky" id="apSticky" style="display:none"><div class="ribbon" id="apRib"></div><div class="ctawrap"><button class="cta" id="apStGo" type="button">→</button></div></div>
</div>
