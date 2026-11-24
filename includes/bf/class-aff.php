<?php
/**
 * ansa™ Промо — Черен петък 2026 · 1.57.0: партньорите (ansa Partner) — комисионата, кратките линкове и ваучерът с код.
 *
 *  1. КОМИСИОНАТА. ansa Partner брои поръчката по партньорския код В НЕЯ. Досега кодът влизаше в количката само през сесийния ref на
 *     Partner, а той се „изразходва“ при първото прилагане: при втора количка в същата сесия (клиентът се връща от чекаута и си избира
 *     наново) perform_add изпразваше количката заедно с кода и нямаше кой да го върне — подаръкът оставаше, комисионата не.
 *     ensure_coupon() слага кода на партньора при ВСЯКО правене на количката, а order_coupon() е предпазната мрежа при поръчката:
 *     щом поръчката носи подаръка на партньор (meta aff), а кодът му липсва, добавя го като ред „купон“ с 0 € отстъпка.
 *  2. КРАТКИТЕ ЛИНКОВЕ. ansa.bg/p/КОД (линкът от портала на партньора) водеше към началната страница — без подаръка от Черния петък.
 *     Докато промото е в пауза или в публичната фаза, vanity() го праща към страницата на Черния петък с ?ref=КОД (кодът за играта
 *     остава към Сезона). Настройка aff.vanity.
 *  3. ВАУЧЕРЪТ С КОД. „Ваучер 5 €“ беше отстъпка само в количката на Черния петък. Сега е истински ваучер с код и срок
 *     (aff.voucher_days, 90 дни): издава се при избора (един на имейл), записва се в лийда (voucher / voucher_exp) и като WooCommerce
 *     купон (fixed_cart, еднократен) — на Черния петък се приспада сам (отрицателна такса, както досега), а ако не се използва, клиентът
 *     го въвежда като код за отстъпка при следваща поръчка. aff.voucher_days = 0 връща старото поведение (без код).
 *
 * @package AnsaPromo
 * @since 1.57.0
 */
namespace AnsaPromo\BF;

use AnsaPromo\Partner;
use AnsaPromo\Schema;

defined( 'ABSPATH' ) || exit;

final class Aff {

	const MAIL_HOOK = 'ansa_promo_bf_affv_mail';
	const LINK_KEY  = 'ansa_partner_link_applied'; /* AP_Attribution::SESSION_LINK — източник „линк“ в заключената атрибуция */
	const DAY_CAP   = 1000;                        /* най-много нови ваучери за денонощие (срещу скрипт, който сече кодове) */
	const COUNTED   = '_ansa_bf_affv_counted';     /* поръчката е увеличила броя ползвания на купона (за да се върне при отказ) */

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'vanity' ), 0 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_coupon' ), 30, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'order_coupon' ), 30, 1 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'one_voucher' ), 20, 3 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'order_done' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'order_done' ), 20 );
		foreach ( array( 'on-hold', 'processing', 'completed' ) as $st ) { add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'order_done' ), 28 ); }
		foreach ( array( 'cancelled', 'failed' ) as $st ) { add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'release' ), 28 ); }
		add_action( self::MAIL_HOOK, array( __CLASS__, 'mail_cron' ) );
	}

	/* ═══ 1. КОМИСИОНАТА ═══ */

	private static function fmt_code( $code ) { return function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( (string) $code ) : strtolower( (string) $code ); }
	private static function is_partner_code( $code ) { try { return class_exists( '\\AnsaPromo\\Partner' ) && Partner::is_partner_code( (string) $code ); } catch ( \Throwable $e ) { return false; } }

	/**
	 * Кодът на партньора от линка е в количката след всяко perform_add (а не само докато сесийният ref на Partner е жив).
	 * Ако в количката вече има партньорски код (последният клик на друг линк, приложен от Partner) — той остава: един код на поръчка.
	 */
	public static function ensure_coupon( $ref ) {
		try {
			if ( '' === (string) $ref || ! function_exists( 'WC' ) || ! WC()->cart ) { return false; }
			$p = Page::partner( $ref ); if ( ! $p ) { return false; }
			$code = self::fmt_code( $p['code'] ); $cart = WC()->cart;
			foreach ( (array) $cart->get_applied_coupons() as $ac ) { if ( self::is_partner_code( $ac ) ) { return true; } }
			if ( WC()->session ) { WC()->session->set( self::LINK_KEY, $code ); }
			$cart->apply_coupon( $code );
			if ( function_exists( 'wc_clear_notices' ) ) { wc_clear_notices(); }
			return (bool) $cart->has_discount( $code );
		} catch ( \Throwable $e ) { return false; }
	}

	/**
	 * Предпазната мрежа при поръчката: подаръкът е от партньор (meta aff), а в поръчката няма партньорски код (WooCommerce е отказал купона —
	 * срок, лимит, ограничение; или друг плъгин го е махнал) → кодът влиза като ред „купон“ с 0 € отстъпка, за да я заключи ansa Partner.
	 */
	public static function order_coupon( $order ) {
		try {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! class_exists( '\\WC_Order_Item_Coupon' ) ) { return; }
			$m = $order->get_meta( '_ansa_promo_bf' ); if ( ! is_array( $m ) || empty( $m['aff']['code'] ) || ! empty( $m['draft'] ) ) { return; }
			$p = Page::partner( (string) $m['aff']['code'] ); if ( ! $p ) { return; }
			foreach ( (array) $order->get_coupon_codes() as $c ) { if ( self::is_partner_code( $c ) ) { return; } }
			$code = self::fmt_code( $p['code'] ); if ( ! self::is_partner_code( $code ) ) { return; }
			$item = new \WC_Order_Item_Coupon(); $item->set_props( array( 'code' => $code, 'discount' => 0, 'discount_tax' => 0 ) );
			$order->add_item( $item );
			$order->update_meta_data( '_ansa_bf_ref_fallback', $code );
		} catch ( \Throwable $e ) {}
	}

	/* ═══ 2. КРАТКИТЕ ЛИНКОВЕ ═══ */

	/** Накъде води ansa.bg/p/КОД сега: адресът на Черния петък с ?ref=КОД или '' (оставя го на ansa Partner). */
	public static function vanity_target( $code, $cfg = null ) {
		try {
			$cfg = $cfg ?: Config::get_published();
			if ( empty( $cfg['enabled'] ) || empty( $cfg['aff']['enabled'] ) || empty( $cfg['aff']['vanity'] ) ) { return ''; }
			if ( ! in_array( Config::phase_now( $cfg ), array( 'pause', 'public' ), true ) ) { return ''; }
			$p = Page::partner( $code ); if ( ! $p ) { return ''; }
			if ( class_exists( 'AP_Coupons' ) ) { $map = \AP_Coupons::get_mapping_by_code( self::fmt_code( $p['code'] ) ); if ( $map && 'game' === (string) ( $map->kind ?? 'std' ) ) { return ''; } } /* кодът за играта → Сезонът */
			return add_query_arg( 'ref', rawurlencode( $p['code'] ), Page::url( $cfg ) );
		} catch ( \Throwable $e ) { return ''; }
	}
	/** template_redirect 0 — преди vanity_redirect на ansa Partner (1). */
	public static function vanity() {
		$code = function_exists( 'get_query_var' ) ? (string) get_query_var( 'ansa_partner_ref' ) : ''; if ( '' === $code ) { return; }
		$url = self::vanity_target( sanitize_text_field( $code ) ); if ( '' === $url ) { return; }
		$cfg = Config::get_published(); $left = Config::phase_end( $cfg ) - Config::now();
		/* кешируем redirect (както при ansa Partner), но не през края на фазата */
		if ( ! headers_sent() ) { if ( $left >= 120 ) { header( 'X-LiteSpeed-Cache-Control: public, max-age=' . min( 600, $left - 60 ) ); } else { nocache_headers(); } }
		wp_safe_redirect( $url, 302 ); exit;
	}

	/* ═══ 3. ВАУЧЕРЪТ С КОД ═══ */

	/** Ваучерът е с код и срок (иначе — старото: отстъпка само в количката на Черния петък). */
	public static function on( $cfg ) { return ! empty( $cfg['aff']['enabled'] ) && (int) ( $cfg['aff']['voucher_days'] ?? 0 ) > 0 && (float) ( $cfg['aff']['voucher'] ?? 0 ) > 0; }
	public static function days( $cfg ) { return max( 0, (int) ( $cfg['aff']['voucher_days'] ?? 0 ) ); }

	/** Редът на ваучера на този имейл (един на имейл — и използваният се връща, за да не се издава втори). */
	public static function row_for( $email ) {
		global $wpdb; $email = strtolower( sanitize_email( (string) $email ) ); if ( ! is_email( $email ) ) { return null; }
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . " WHERE promo = %s AND kind = 'aff' AND email = %s AND voucher <> '' ORDER BY id DESC LIMIT 1", Leads::PROMO, $email ), ARRAY_A );
		return is_array( $r ) ? $r : null;
	}
	/** Редът по код, само ако е партньорски ваучер (евтино: първо формата на кода). */
	public static function row_by_code( $code ) {
		static $memo = array(); $code = strtoupper( trim( (string) $code ) );
		if ( ! preg_match( '/^[A-Z]{2,6}\d{1,4}-[A-Z0-9]{6,8}$/', $code ) ) { return null; }
		if ( array_key_exists( $code, $memo ) && $memo[ $code ][0] > time() - 5 ) { return $memo[ $code ][1]; }
		$r = null; try { $r = Leads::voucher_row( $code ); if ( ! $r || 'aff' !== (string) ( $r['kind'] ?? '' ) ) { $r = null; } } catch ( \Throwable $e ) { $r = null; }
		$memo[ $code ] = array( time(), $r ); return $r;
	}
	public static function is_voucher_code( $code ) { return null !== self::row_by_code( $code ); }
	private static function exp_ts( $row ) { return ! empty( $row['voucher_exp'] ) ? (int) strtotime( (string) $row['voucher_exp'] . ' UTC' ) : 0; }
	/** Ваучерът в контекста на сесията. */
	public static function slot( $row, $cfg ) { return array( 'code' => (string) $row['voucher'], 'amount' => (float) $cfg['aff']['voucher'], 'exp' => self::exp_ts( $row ), 'lead' => (int) $row['id'] ); }
	/** За bf.js: [code, amount, exp, live] или null. */
	public static function ctx_public( $ctx ) {
		$v = is_array( $ctx['affv'] ?? null ) ? $ctx['affv'] : null; if ( ! $v || '' === (string) ( $v['code'] ?? '' ) ) { return null; }
		return array( 'code' => (string) $v['code'], 'amount' => (float) ( $v['amount'] ?? 0 ), 'exp' => (int) ( $v['exp'] ?? 0 ), 'live' => Leads::voucher_valid( (string) $v['code'] ) );
	}

	/** Нов ваучер за имейла: кодът в реда на лийда + WooCommerce купон + имейл. Връща реда или null. */
	public static function issue( $email, $lead_id, $ref, $cfg ) {
		global $wpdb; $email = strtolower( sanitize_email( (string) $email ) );
		if ( ! is_email( $email ) || ! self::on( $cfg ) || 'over' === Config::phase_now( $cfg ) ) { return null; }
		$t = Schema::table( 'leads' );
		$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE promo = %s AND kind = 'aff' AND voucher <> '' AND created_at >= %s", Leads::PROMO, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		if ( $today >= (int) apply_filters( 'ansa_promo_bf_affv_day_cap', self::DAY_CAP ) ) { return null; }
		$amount = (float) $cfg['aff']['voucher']; $exp = time() + self::days( $cfg ) * DAY_IN_SECONDS; $exp_sql = gmdate( 'Y-m-d H:i:s', $exp );
		$pre = strtoupper( preg_replace( '/[^a-z]/i', '', (string) ( $cfg['aff']['voucher_prefix'] ?? 'BFP' ) ) ) ?: 'BFP';
		$code = Leads::new_voucher_code( $cfg, $pre . (int) round( $amount ) . '-' );
		$ref = strtoupper( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $ref ) ); $lead_id = (int) $lead_id; $done = false;
		if ( $lead_id > 0 ) {
			$own = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d AND promo = %s AND kind = 'aff' AND email = %s AND voucher = ''", $lead_id, Leads::PROMO, $email ), ARRAY_A );
			if ( is_array( $own ) ) { $done = false !== $wpdb->update( $t, array( 'voucher' => $code, 'voucher_exp' => $exp_sql, 'bonus' => 'v5' ), array( 'id' => $lead_id ) ); }
		}
		if ( ! $done ) { $lead_id = Leads::capture( $email, 'aff', 'v5', $ref, '', true, $code, $exp ); if ( ! $lead_id ) { return null; } }
		$row = Leads::voucher_row( $code ); if ( ! $row ) { return null; }
		self::wc_coupon( $code, $amount, $exp, $email, $ref, (int) $row['id'], $cfg );
		if ( ! empty( $cfg['aff']['voucher_mail'] ) && function_exists( 'wp_schedule_single_event' ) ) { wp_schedule_single_event( time(), self::MAIL_HOOK, array( (int) $row['id'] ) ); }
		do_action( 'ansa_promo_bf_affv_issued', $row, $cfg );
		return $row;
	}
	/** WooCommerce купонът на ваучера (за поръчка извън Черния петък): фиксирана сума, еднократен, със срока на ваучера. Връща id или 0. */
	public static function wc_coupon( $code, $amount, $exp, $email, $ref, $lead_id, $cfg ) {
		try {
			if ( ! class_exists( '\\WC_Coupon' ) ) { return 0; }
			if ( function_exists( 'wc_get_coupon_id_by_code' ) && (int) wc_get_coupon_id_by_code( $code ) > 0 ) { return (int) wc_get_coupon_id_by_code( $code ); }
			$c = new \WC_Coupon(); $c->set_code( $code ); $c->set_discount_type( 'fixed_cart' ); $c->set_amount( (float) $amount ); $c->set_usage_limit( 1 ); $c->set_individual_use( false ); $c->set_date_expires( (int) $exp );
			$min = (float) ( $cfg['aff']['voucher_min'] ?? 0 ); if ( $min > 0 ) { $c->set_minimum_amount( $min ); }
			$c->set_description( sprintf( 'ansa™ Черен петък: ваучер от партньор %s · %s · лийд #%d', $ref, $email, (int) $lead_id ) );
			$c->update_meta_data( '_ansa_bf_voucher', 'aff' ); $c->update_meta_data( '_ansa_bf_lead', (int) $lead_id );
			return (int) $c->save();
		} catch ( \Throwable $e ) { return 0; }
	}

	/** Ваучерът на имейла влиза в контекста (издава се, ако още няма). Не записва контекста — връща го. */
	public static function attach( $ctx, $email, $lead_id, $ref, $cfg ) {
		unset( $ctx['affv'] );
		try {
			if ( ! self::on( $cfg ) ) { return $ctx; }
			$row = self::row_for( $email ); if ( ! $row ) { $row = self::issue( $email, $lead_id, $ref, $cfg ); }
			if ( $row ) { $ctx['affv'] = self::slot( $row, $cfg ); }
		} catch ( \Throwable $e ) {}
		return $ctx;
	}
	/** Избран ваучер, имейлът е известен, но в сесията няма код (записани преди 1.57.0 / нова сесия) → вземи го или го издай. Записва контекста. */
	public static function sync_ctx( $cfg ) {
		$ctx = Cart::ctx();
		try {
			if ( 'v5' !== (string) ( $ctx['affgift'] ?? '' ) || empty( $ctx['email'] ) || ! self::on( $cfg ) || ! empty( $ctx['affv']['code'] ) ) { return $ctx; }
			$p = Page::partner( (string) ( $ctx['ref'] ?? '' ) ); if ( ! $p ) { return $ctx; }
			$ctx = self::attach( $ctx, (string) $ctx['email'], (int) ( $ctx['lead'] ?? 0 ), $p['code'], $cfg );
			if ( ! empty( $ctx['affv'] ) ) { Cart::set_ctx( $ctx ); }
		} catch ( \Throwable $e ) {}
		return $ctx;
	}
	/** Личният линк от имейла (?u=, подписан имейл): подаръкът от партньора се връща в сесията — партньорът (за комисионата), изборът и ваучерът. */
	public static function restore( $email, $cfg ) {
		try {
			global $wpdb; $email = strtolower( sanitize_email( (string) $email ) ); if ( ! is_email( $email ) || empty( $cfg['aff']['enabled'] ) ) { return; }
			$ctx = Cart::ctx(); if ( ! empty( $ctx['ref'] ) ) { return; }
			$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . " WHERE promo = %s AND kind = 'aff' AND email = %s AND ref_code <> '' ORDER BY id DESC LIMIT 1", Leads::PROMO, $email ), ARRAY_A );
			if ( ! is_array( $r ) ) { return; } $p = Page::partner( (string) $r['ref_code'] ); if ( ! $p ) { return; }
			Cart::remember_ref( $p['code'] ); $ctx = Cart::ctx(); $ctx['ref'] = $p['code'];
			if ( empty( $ctx['affgift'] ) && in_array( (string) $r['bonus'], array( 'v5', 'mask' ), true ) ) { $ctx['affgift'] = (string) $r['bonus']; }
			if ( empty( $ctx['email'] ) ) { $ctx['email'] = $email; } if ( empty( $ctx['lead'] ) ) { $ctx['lead'] = (int) $r['id']; }
			if ( 'v5' === (string) ( $ctx['affgift'] ?? '' ) ) { $ctx = self::attach( $ctx, $email, (int) $r['id'], $p['code'], $cfg ); }
			Cart::set_ctx( $ctx );
		} catch ( \Throwable $e ) {}
	}
	/** Код, въведен в страницата (ansa_bf_claim): ваучерът и партньорът му влизат в сесията. */
	public static function apply_row( $row, $cfg ) {
		$ctx = Cart::ctx(); $ctx['affv'] = self::slot( $row, $cfg ); $ctx['affgift'] = 'v5';
		if ( empty( $ctx['ref'] ) && '' !== (string) ( $row['ref_code'] ?? '' ) && Page::partner( (string) $row['ref_code'] ) ) { Cart::remember_ref( (string) $row['ref_code'] ); $c2 = Cart::ctx(); $ctx['ref'] = (string) ( $c2['ref'] ?? $row['ref_code'] ); }
		if ( empty( $ctx['lead'] ) ) { $ctx['lead'] = (int) $row['id'] ; }
		Cart::set_ctx( $ctx ); return $ctx;
	}

	/** Има ли в количката ваучерът, приложен като купон (въведен на ръка в чекаута)? */
	private static function coupon_in_cart( $cart ) { try { foreach ( (array) $cart->get_applied_coupons() as $ac ) { if ( self::is_voucher_code( $ac ) ) { return true; } } } catch ( \Throwable $e ) {} return false; }

	/**
	 * Ваучерът от партньора за тази количка (отрицателната такса): [code, amount, partner] или null.
	 * Както досега: промо група с платена опаковка, в публичната фаза (или чернова / служебна поръчка), жив партньор.
	 * С код (aff.voucher_days > 0): ваучерът трябва да е в сесията, неизползван и в срок; без код — старото поведение.
	 */
	public static function fee( $ctx, $g, $cfg, $cart = null ) {
		if ( ! $g || 'promo' !== $g['mode'] || 'v5' !== (string) ( $ctx['affgift'] ?? '' ) || (int) $g['paid'] <= 0 ) { return null; }
		if ( ! ( 'public' === Config::phase_now( $cfg ) || ! empty( $g['draft'] ) || ! empty( $g['staff'] ) ) ) { return null; }
		if ( empty( $cfg['aff']['enabled'] ) || (float) $cfg['aff']['voucher'] <= 0 ) { return null; }
		$p = Page::partner( (string) ( $ctx['ref'] ?? '' ) ); if ( ! $p ) { return null; }
		if ( ! self::on( $cfg ) ) { return array( 'code' => '', 'amount' => (float) $cfg['aff']['voucher'], 'partner' => $p ); }
		$v = is_array( $ctx['affv'] ?? null ) ? $ctx['affv'] : null;
		if ( ! $v || '' === (string) ( $v['code'] ?? '' ) || ! Leads::voucher_valid( (string) $v['code'] ) ) { return null; }
		if ( $cart && self::coupon_in_cart( $cart ) ) { return null; } /* вече е приложен като купон — не два пъти */
		return array( 'code' => (string) $v['code'], 'amount' => (float) $cfg['aff']['voucher'], 'partner' => $p );
	}

	/** Най-много един партньорски ваучер на количка (купоните са с различни кодове и WooCommerce иначе ги събира). */
	public static function one_voucher( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! is_object( $coupon ) ) { return $valid; }
		$dup = false;
		try {
			$code = strtoupper( (string) $coupon->get_code() ); if ( ! self::is_voucher_code( $code ) ) { return $valid; }
			$applied = array();
			if ( $discounts && is_callable( array( $discounts, 'get_object' ) ) ) { $obj = $discounts->get_object(); if ( $obj instanceof \WC_Cart ) { $applied = $obj->get_applied_coupons(); } elseif ( $obj instanceof \WC_Order ) { $applied = $obj->get_coupon_codes(); } }
			if ( ! $applied && function_exists( 'WC' ) && WC()->cart ) { $applied = WC()->cart->get_applied_coupons(); }
			foreach ( (array) $applied as $ac ) { if ( strtoupper( (string) $ac ) !== $code && self::is_voucher_code( $ac ) ) { $dup = true; break; } }
		} catch ( \Throwable $e ) { return $valid; }
		if ( $dup ) { throw new \Exception( 'Само един ваучер може да се използва на поръчка.', 100 ); }
		return $valid;
	}

	/* ── поръчката: ваучерът става „използван“ ── */

	private static function order_obj( $order ) { if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) { return $order; } return function_exists( 'wc_get_order' ) ? ( wc_get_order( (int) $order ) ?: null ) : null; }
	private static function coupon_obj( $code ) { if ( ! class_exists( '\\WC_Coupon' ) || ! function_exists( 'wc_get_coupon_id_by_code' ) ) { return null; } $id = (int) wc_get_coupon_id_by_code( $code ); return $id > 0 ? new \WC_Coupon( $id ) : null; }
	/** Ваучерът е приспаднат като такса (или е заменен с маската) → купонът му също е „ползван“, веднъж на поръчка. */
	private static function count_use( $order, $code ) {
		if ( '' !== (string) $order->get_meta( self::COUNTED ) ) { return; }
		try { $c = self::coupon_obj( $code ); if ( $c ) { $c->increase_usage_count( (string) $order->get_billing_email() ); } } catch ( \Throwable $e ) {}
		$order->update_meta_data( self::COUNTED, strtoupper( (string) $code ) ); $order->save();
	}
	/**
	 * При създаване на поръчката и при платен статус (идемпотентно):
	 *  - ваучерът, приспаднат в количката на Черния петък (meta affv) → използван;
	 *  - ваучер, въведен като купон (всяка поръчка в сайта) → използван;
	 *  - избрана маска при неизползван ваучер в сесията → ваучерът отпада (подаръкът е един);
	 *  - бележка, ако кодът на партньора е добавен от предпазната мрежа.
	 */
	public static function order_done( $order ) {
		try {
			$order = self::order_obj( $order ); if ( ! $order ) { return; } $id = (int) $order->get_id(); if ( ! $id ) { return; }
			foreach ( (array) $order->get_coupon_codes() as $c ) { $row = self::row_by_code( $c ); if ( $row ) { Leads::voucher_use( (string) $row['voucher'], $id ); } }
			$m = $order->get_meta( '_ansa_promo_bf' ); if ( ! is_array( $m ) ) { return; }
			if ( ! empty( $m['affv']['code'] ) ) { Leads::voucher_use( (string) $m['affv']['code'], $id ); self::count_use( $order, (string) $m['affv']['code'] ); }
			elseif ( ! empty( $m['affv_void'] ) ) { Leads::voucher_use( (string) $m['affv_void'], $id ); self::count_use( $order, (string) $m['affv_void'] ); }
			$fb = (string) $order->get_meta( '_ansa_bf_ref_fallback' );
			if ( '' !== $fb && ! $order->get_meta( '_ansa_bf_ref_fallback_noted' ) ) { $order->add_order_note( sprintf( 'ansa™ Черен петък: поръчката носи подаръка на партньор %s, но кодът му липсваше в количката — добавен е като купон с 0 € отстъпка, за да се отчете комисионата.', strtoupper( $fb ) ) ); $order->update_meta_data( '_ansa_bf_ref_fallback_noted', 1 ); $order->save(); }
		} catch ( \Throwable $e ) {}
	}
	/** Отказана / неуспешна поръчка: ваучерът, който тя е „изразходвала“, се връща на клиента. */
	public static function release( $order ) {
		try {
			global $wpdb; $order = self::order_obj( $order ); if ( ! $order ) { return; } $id = (int) $order->get_id(); if ( ! $id ) { return; }
			$codes = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT voucher FROM ' . Schema::table( 'leads' ) . " WHERE promo = %s AND kind = 'aff' AND voucher_order_id = %d", Leads::PROMO, $id ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'leads' ) . " SET voucher_order_id = NULL WHERE promo = %s AND kind = 'aff' AND voucher_order_id = %d", Leads::PROMO, $id ) );
			foreach ( $codes as $vc ) { Leads::voucher_valid( (string) $vc, true ); }
			$code = (string) $order->get_meta( self::COUNTED );
			if ( '' !== $code ) { $c = self::coupon_obj( $code ); if ( $c ) { $c->decrease_usage_count( (string) $order->get_billing_email() ); } $order->delete_meta_data( self::COUNTED ); $order->save(); }
		} catch ( \Throwable $e ) {}
	}

	/* ── имейлът с кода ── */

	public static function mail_cron( $lead_id ) {
		try {
			global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . " WHERE id = %d AND promo = %s AND kind = 'aff' AND voucher <> ''", (int) $lead_id, Leads::PROMO ), ARRAY_A );
			if ( is_array( $row ) ) { self::mail( $row, Config::get_published() ); }
		} catch ( \Throwable $e ) {}
	}
	/** Имейлът „Твоят ваучер“ през WC mailer-а (шаблонът на магазина). Връща true при подаден wp_mail. */
	public static function mail( $row, $cfg ) {
		if ( ! function_exists( 'WC' ) || ! is_email( (string) $row['email'] ) || ! empty( $row['voucher_order_id'] ) ) { return false; }
		$p = Page::partner( (string) ( $row['ref_code'] ?? '' ) ); $copy = Copy::effective( $cfg['copy'] );
		$v = self::vars( $row, $cfg, $p ? $p['name'] : 'ansa™' );
		$subject = wp_strip_all_tags( Copy::render( $copy['em.affv.subject'], $v ) ); $heading = wp_strip_all_tags( Copy::render( $copy['em.affv.h'], $v ) ); $body = wpautop( Copy::render( $copy['em.affv.body'], $v ) );
		if ( '' === $subject ) { return false; }
		$mailer = WC()->mailer(); $html = apply_filters( 'ansa_promo_bf_affv_email_html', $mailer->wrap_message( $heading, $body ), $row, $v );
		return (bool) $mailer->send( (string) $row['email'], $subject, $html, "Content-Type: text/html\r\n" );
	}
	/** Плейсхолдърите за текстовете с кода. */
	public static function vars( $row, $cfg, $partner = '' ) {
		$exp = self::exp_ts( $row );
		return array( 'code' => (string) $row['voucher'], 'aff_voucher' => Cart::fmt( $cfg['aff']['voucher'] ), 'aff_exp' => $exp ? wp_date( 'd.m.Y', $exp ) : '', 'aff_days' => self::days( $cfg ), 'partner' => (string) $partner, 'url' => Page::url( $cfg ) );
	}
	/** FunnelKit merge tags {{ansa_promo_bf_aff_voucher}} / {{ansa_promo_bf_aff_voucher_exp}} — за автоматизацията на тага bf-partner. */
	public static function merge( $key, $email ) {
		$row = '' !== (string) $email ? self::row_for( $email ) : null; if ( ! $row || ! empty( $row['voucher_order_id'] ) ) { return ''; }
		$exp = self::exp_ts( $row ); if ( $exp && $exp < time() ) { return ''; }
		return 'bf_aff_voucher' === $key ? (string) $row['voucher'] : ( $exp ? wp_date( 'd.m.Y', $exp ) : '' );
	}
	/** За диагностиката: [issued, used, live]. */
	public static function stats() {
		global $wpdb; $t = Schema::table( 'leads' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS issued, SUM(voucher_order_id IS NOT NULL) AS used, SUM(voucher_order_id IS NULL AND voucher_exp > %s) AS live FROM $t WHERE promo = %s AND kind = 'aff' AND voucher <> ''", gmdate( 'Y-m-d H:i:s' ), Leads::PROMO ), ARRAY_A );
		return array_map( 'intval', is_array( $r ) ? $r : array( 'issued' => 0, 'used' => 0, 'live' => 0 ) );
	}
}
