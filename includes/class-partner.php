<?php
/**
 * ansa™ Промо — мост към ansa Partner (Фаза 5, план §6.4).
 *
 * Промото блокира купоните, но партньорският код (AP_Coupons::get_mapping_by_code) МИНАВА — така атрибуцията на Partner
 * (lock при чекаут, комисионна при доставка) оцелява. Отстъпката му в промо количката е 0 (не се стаква с кутията), а
 * клиентът получава +N билета. N (1.5.0): Partner ≥ 5.9 го казва за всеки код през AP_Coupons::promo_bonus_tickets()
 * — кодът за играта носи своя брой, стандартният код на партньор с код за играта носи 0; null (стар Partner или
 * стандартен код без код за играта) = глобалният tickets.partner_bonus. Понеже perform_add() изпразва количката (и купоните с нея),
 * след добавянето сесийният ref на Partner (?ref=КОД, 48 ч) се прилага отново. Без Partner на сайта — нищо не се закача.
 * Filter `ansa_promo_is_partner_coupon( bool, $code, $coupon )` за други партньорски системи.
 *
 * @package AnsaPromo
 * @since 1.3.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Partner {

	public static function register() {
		add_filter( 'ansa_promo_allow_coupon', array( __CLASS__, 'allow' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( __CLASS__, 'zero_discount' ), 50, 5 );
		add_filter( 'woocommerce_coupon_get_free_shipping', array( __CLASS__, 'no_free_shipping' ), 50, 2 );
		add_action( 'ansa_promo_added', array( __CLASS__, 'reapply_ref' ), 10, 4 );
		add_filter( 'ansa_promo_order_meta', array( __CLASS__, 'order_meta' ), 10, 3 );
	}

	public static function available() { return class_exists( 'AP_Coupons' ) && is_callable( array( 'AP_Coupons', 'get_mapping_by_code' ) ); }

	/** Партньорски ли е този код? */
	public static function is_partner_code( $code, $coupon = null ) {
		$code = (string) $code; $is = false;
		if ( '' !== $code && self::available() ) { try { $is = (bool) \AP_Coupons::get_mapping_by_code( function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( $code ) : $code ); } catch ( \Throwable $e ) { $is = false; } }
		return (bool) apply_filters( 'ansa_promo_is_partner_coupon', $is, $code, $coupon );
	}
	/** Приложените партньорски кодове в количката. */
	public static function applied( $cart = null ) {
		$cart = $cart ?: ( function_exists( 'WC' ) ? WC()->cart : null ); if ( ! $cart ) { return array(); }
		return array_values( array_filter( (array) $cart->get_applied_coupons(), array( __CLASS__, 'is_partner_code' ) ) );
	}

	public static function allow( $allow, $coupon, $group ) { return $allow || self::is_partner_code( is_object( $coupon ) ? $coupon->get_code() : (string) $coupon, $coupon ); }

	/** В промо количка партньорският код не дава отстъпка (кутията вече е с −pct%), само атрибуция + билети. */
	public static function zero_discount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( empty( $cart_item[ Cart::META ] ) ) { return $discount; }
		return self::is_partner_code( $coupon->get_code(), $coupon ) ? 0 : $discount;
	}
	public static function no_free_shipping( $free, $coupon ) {
		if ( ! $free || ! Cart::group() ) { return $free; }
		return self::is_partner_code( $coupon->get_code(), $coupon ) ? false : $free;
	}

	/** След perform_add (количката е била изпразнена): ref-ът от сесията на Partner се прилага пак. */
	public static function reapply_ref( $group, $box, $ctx, $cfg ) {
		if ( ! self::available() || ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) { return; }
		$ref = WC()->session->get( 'ansa_partner_ref' ); $code = is_array( $ref ) ? (string) ( $ref['code'] ?? '' ) : (string) $ref; $t = is_array( $ref ) ? (int) ( $ref['t'] ?? 0 ) : time();
		if ( '' === $code || ( $t && time() - $t > 48 * HOUR_IN_SECONDS ) || ! self::is_partner_code( $code ) ) { return; }
		if ( WC()->cart->has_discount( $code ) ) { return; }
		try { WC()->cart->apply_coupon( $code ); } catch ( \Throwable $e ) {}
		wc_clear_notices();
	}

	/**
	 * Бонус билети за даден партньорски код: Partner ≥ 5.9 решава за кода (код за играта → неговият брой; стандартен код
	 * при наличен код за играта → 0); null/стар Partner → глобалният tickets.partner_bonus. Filter `ansa_promo_partner_bonus`.
	 */
	public static function bonus_for_code( $code, $cfg ) {
		$n = null;
		if ( '' !== (string) $code && self::available() && is_callable( array( 'AP_Coupons', 'promo_bonus_tickets' ) ) ) {
			try { $n = \AP_Coupons::promo_bonus_tickets( function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( $code ) : $code ); } catch ( \Throwable $e ) { $n = null; }
		}
		if ( null === $n ) { $n = (int) ( $cfg['tickets']['partner_bonus'] ?? 0 ); }
		return max( 0, (int) apply_filters( 'ansa_promo_partner_bonus', (int) $n, $code, $cfg ) );
	}
	/** В _ansa_promo: tickets.partner / bonus / code — Tickets::issue() добавя бонуса. */
	public static function order_meta( $meta, $g, $cfg ) {
		$codes = self::applied(); if ( ! $codes ) { return $meta; }
		$meta['tickets']['partner'] = true; $meta['tickets']['bonus'] = self::bonus_for_code( $codes[0], $cfg ); $meta['tickets']['partner_code'] = $codes[0];
		return $meta;
	}
	/** Бонус билети за текущата количка (за чекаут реда). */
	public static function bonus_now( $cfg ) { $codes = self::applied(); return $codes ? self::bonus_for_code( $codes[0], $cfg ) : 0; }
}
