<?php
/**
 * ansa™ Промо — фронт.
 *
 * Фаза 0: [ansa_promo] печата заместител. Фаза 1 носи порта на мокъп v73.
 *
 * Shortcode-ът се регистрира на init (20): пенсионираният модул на Shrine регистрира
 * своя на woocommerce_init (= init 0), така че дори при Shrine БЕЗ guard (< 6.19.22)
 * страницата я поема този плъгин. Guard-ът в Shrine остава задължителен — той спира
 * и ajax/админ/количка частите на стария модул, не само shortcode-а.
 *
 * @package AnsaPromo
 * @since 1.0.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_shortcode' ), 20 );
	}

	public static function add_shortcode() {
		add_shortcode( Plugin::SHORTCODE, array( __CLASS__, 'shortcode' ) );
	}

	public static function shortcode( $atts = array(), $content = '' ) {
		$html = '<div class="ansa-promo" id="ansaPromo" data-ver="' . esc_attr( ANSA_PROMO_VER ) . '">';
		$html .= '<p class="ansa-promo-soon" style="text-align:center;font:700 22px/1.3 Nunito,system-ui,sans-serif;padding:48px 16px;margin:0">ansa™ Промо — скоро.</p>';
		if ( current_user_can( Plugin::CAP ) ) {
			$html .= '<p class="ansa-promo-admin-note" style="text-align:center;font:500 13px/1.5 system-ui,sans-serif;opacity:.6;margin:0 0 24px">Фаза 0 · ansa™ Промо ' . esc_html( ANSA_PROMO_VER ) . ' · това го виждаш само като админ. Страницата идва с Фаза 1.</p>';
		}
		$html .= '</div>';
		return $html;
	}
}
