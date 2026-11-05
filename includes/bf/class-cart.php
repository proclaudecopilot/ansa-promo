<?php
/**
 * ansa™ Промо — Черен петък 2026: количка и чекаут.
 *
 * Правилото: всяка платена опаковка (вариацията „1 брой“ на РЕДОВНА цена) носи една опаковка подарък по избор (ред на €0). Подаръците са най-много
 * колкото платените; повече → излишните отпадат (shrink), по-малко → бележка „можеш да избереш още“. Чекаутът е този на сайта (FunnelKit) —
 * тук само се прави количката (ansa_bf_add), налагат се цените, таксите (ваучерите), доставката и мета-данните на поръчката.
 *
 *  - Ред = cart item meta `ansa_promo_bf` { promo, group, mode (promo|wallet), role (base|gift|pgift), key, phase, draft, t }.
 *  - Контекст = WC session `ansa_promo_bf_ctx` { mode, group, phase, variant, email, lead, utm, sid, ref, affgift (v5|mask), voucher {code, amount, exp, lead}, early }.
 *  - Ваучерите са отрицателни такси (woocommerce_cart_calculate_fees): студеният (10 €, 30 мин., важи в цялата количка, и без промо група) и
 *    партньорският (5 € в публичната промоция). Маската (CollaMask) е ред role=pgift на €0.
 *  - Партньорският код на ansa Partner минава (атрибуция), но без отстъпка (zero discount) — като в Сезона; другите купони са блокирани.
 *  - Сезонът (AnsaPromo\Cart) не пипа тези редове: за него са „чужди“, но той действа само когато има СВОЯ група; perform_add изпразва количката.
 *
 * @package AnsaPromo
 * @since 1.45.0
 */
namespace AnsaPromo\BF;

use AnsaPromo\Insights;
use AnsaPromo\Partner;
use AnsaPromo\Leads as SeasonLeads;

defined( 'ABSPATH' ) || exit;

final class Cart {

	const META = 'ansa_promo_bf';
	const CTX  = 'ansa_promo_bf_ctx';
	private static $mutating = false;

	public static function register() {
		add_action( 'wp_ajax_ansa_bf_add', array( __CLASS__, 'ajax_add' ) );
		foreach ( array( 'wp_ajax_ansa_bf_add', 'wp_ajax_nopriv_ansa_bf_add' ) as $h ) { add_action( $h, array( Leads::class, 'pixel_cookies' ), 0 ); } /* 1.45.4: Pixel CAPI Pro бисквитки */
		add_action( 'wp_ajax_nopriv_ansa_bf_add', array( __CLASS__, 'ajax_add' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_pricing' ), 94 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'strip_foreign' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'strip_foreign' ), PHP_INT_MAX );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'strip_foreign' ), PHP_INT_MAX );
		add_filter( 'fkcart_reward_rules_checking', array( __CLASS__, 'no_fk_rewards' ), 1, 2 );
		add_filter( 'fkcart_reward_enabled', array( __CLASS__, 'no_fk_rewards' ), 1, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'lock_qty' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( __CLASS__, 'lock_remove' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'cart_label' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_price_html' ), 10, 3 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'fees' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'free_shipping' ), 50, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'block_coupon' ), 10, 2 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( __CLASS__, 'zero_partner_discount' ), 50, 5 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill_email' ), 10, 2 );
		add_action( 'woocommerce_review_order_after_cart_contents', array( __CLASS__, 'checkout_row' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_meta' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'order_meta_store_api' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'order_created' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'order_created' ) );
		add_filter( 'woocommerce_order_item_name', array( __CLASS__, 'order_label' ), 10, 2 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 6 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear_ctx' ), 99 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'clear_ctx' ), 99 );
	}

	/* ── контекст ── */
	public static function ctx() { return ( function_exists( 'WC' ) && WC()->session ) ? (array) WC()->session->get( self::CTX, array() ) : array(); }
	public static function set_ctx( $c ) { if ( function_exists( 'WC' ) && WC()->session ) { if ( ! WC()->session->has_session() ) { WC()->session->set_customer_session_cookie( true ); } WC()->session->set( self::CTX, $c ); } }
	/** След поръчка: количката е нова, но ваучерът/партньорът/ранният достъп на посетителя остават (ваучерът е еднократен — Leads го маркира). */
	public static function clear_ctx() { $c = self::ctx(); if ( ! $c ) { return; } $keep = array_intersect_key( $c, array_flip( array( 'email', 'lead', 'ref', 'affgift', 'early', 'utm', 'sid' ) ) ); self::set_ctx( $keep ); }
	/** Безопасната част за bf.js. */
	public static function ctx_public() {
		$c = self::ctx(); $v = is_array( $c['voucher'] ?? null ) ? $c['voucher'] : null;
		return array( 'email' => (string) ( $c['email'] ?? '' ), 'lead' => (int) ( $c['lead'] ?? 0 ), 'affgift' => (string) ( $c['affgift'] ?? '' ), 'ref' => (string) ( $c['ref'] ?? '' ), 'early' => ! empty( $c['early'] ), 'mode' => (string) ( $c['mode'] ?? '' ),
			'voucher' => $v ? array( 'code' => (string) $v['code'], 'amount' => (float) $v['amount'], 'exp' => (int) $v['exp'], 'live' => Leads::voucher_valid( (string) $v['code'] ) ) : null );
	}
	public static function remember_ref( $code ) {
		$code = strtoupper( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $code ) ); if ( '' === $code ) { return; }
		$c = self::ctx(); if ( ( $c['ref'] ?? '' ) !== $code ) { $c['ref'] = $code; self::set_ctx( $c ); }
		/* ansa Partner: ref-ът в неговата сесия (атрибуция при поръчка), ако неговият скрипт не го е записал (кеширана страница) */
		try { if ( class_exists( 'AP_Coupons_Runtime' ) && function_exists( 'WC' ) && WC()->session && ! WC()->session->get( 'ansa_partner_ref' ) && Partner::is_partner_code( $code ) ) { WC()->session->set( 'ansa_partner_ref', array( 'code' => function_exists( 'wc_format_coupon_code' ) ? wc_format_coupon_code( $code ) : strtolower( $code ), 't' => time() ) ); } } catch ( \Throwable $e ) {}
	}
	public static function cfg() { $ctx = self::ctx(); return ! empty( $ctx['draft'] ) ? Config::get_draft() : Config::get_published(); }
	private static function copy( $k, $vars = array() ) { $c = Copy::effective( self::cfg()['copy'] ); return Copy::render( isset( $c[ $k ] ) ? $c[ $k ] : $k, $vars ); }
	public static function fmt( $n ) { return null === $n ? '' : str_replace( '.', ',', rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' ) ); }
	public static function checkout_url() { return apply_filters( 'ansa_promo_bf_checkout_url', wc_get_checkout_url(), self::ctx() ); }
	private static function reg_price( $p ) { $r = (float) $p->get_regular_price(); return $r > 0 ? $r : (float) $p->get_price(); }
	private static function plural( $n, $one, $many ) { return 1 === (int) $n ? $one : $many; }

	/** Групата в количката: [promo, group, mode, phase, draft, base[key=>item], gifts[key=>item], pgift, paid, ngifts, foreign[]] или null. */
	public static function group( $cart = null ) {
		$cart = $cart ?: ( function_exists( 'WC' ) ? WC()->cart : null ); if ( ! $cart ) { return null; }
		$g = null; $foreign = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ self::META ] ) || ! is_array( $item[ self::META ] ) ) { $foreign[] = $key; continue; }
			$m = $item[ self::META ];
			if ( null === $g ) { $g = array( 'promo' => (string) ( $m['promo'] ?? '' ), 'group' => (string) ( $m['group'] ?? '' ), 'mode' => (string) ( $m['mode'] ?? 'promo' ), 'phase' => (string) ( $m['phase'] ?? '' ), 'draft' => ! empty( $m['draft'] ), 't' => (int) ( $m['t'] ?? 0 ), 'base' => array(), 'gifts' => array(), 'pgift' => '', 'lucky' => '', 'paid' => 0, 'ngifts' => 0 ); }
			$role = (string) ( $m['role'] ?? '' );
			if ( 'base' === $role ) { $g['base'][ $key ] = $item; $g['paid'] += (int) $item['quantity']; }
			elseif ( 'gift' === $role ) { $g['gifts'][ $key ] = $item; $g['ngifts'] += (int) $item['quantity']; }
			elseif ( 'pgift' === $role ) { $g['pgift'] = $key; }
			elseif ( 'lucky' === $role ) { $g['lucky'] = $key; } /* 1.46.0: подаръкът от раздаването „Ти си късметлия“ */
		}
		if ( $g ) { $g['foreign'] = $foreign; }
		return $g;
	}
	private static function allowed( $cfg, $draft = false ) { $out = array(); foreach ( Page::products( $cfg, $draft ) as $p ) { $out[ (int) $p['id'] ] = $p; } return $out; }
	private static function line_src( $p ) { return ! empty( $p['vid'] ) ? wc_get_product( (int) $p['vid'] ) : wc_get_product( (int) $p['id'] ); }
	private static function variation_attrs( $src ) {
		if ( ! $src || ! $src->is_type( 'variation' ) ) { return array(); }
		$attrs = (array) $src->get_variation_attributes(); $parent = wc_get_product( $src->get_parent_id() );
		foreach ( $attrs as $k => $v ) { if ( '' !== (string) $v || ! $parent ) { continue; } foreach ( $parent->get_attributes() as $pa ) { if ( 'attribute_' . sanitize_title( $pa['name'] ) === $k ) { $slugs = $pa->get_slugs(); if ( $slugs ) { $attrs[ $k ] = (string) reset( $slugs ); } break; } } }
		return $attrs;
	}
	private static function counts( $in ) { $o = array(); foreach ( (array) $in as $r ) { $id = (int) ( is_array( $r ) ? ( $r['id'] ?? 0 ) : $r ); $q = is_array( $r ) ? max( 1, min( 20, (int) ( $r['q'] ?? 1 ) ) ) : 1; if ( $id ) { $o[ $id ] = ( $o[ $id ] ?? 0 ) + $q; } } return $o; }

	/* ═══ ADD ═══ */
	public static function ajax_add() {
		if ( ! check_ajax_referer( Page::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'nonce', 'msg' => self::copy( 'err.nonce' ) ), 403 ); }
		if ( SeasonLeads::rate_limited( 'bf_add', 12 ) ) { wp_send_json_error( array( 'code' => 'rate', 'msg' => self::copy( 'err.rate' ) ), 429 ); }
		$r = self::perform_add( array(
			'draft' => ! empty( $_POST['draft'] ), 'mode' => sanitize_key( (string) ( $_POST['mode'] ?? 'promo' ) ),
			'items' => json_decode( (string) wp_unslash( $_POST['items'] ?? '[]' ), true ), 'gifts' => json_decode( (string) wp_unslash( $_POST['gifts'] ?? '[]' ), true ),
			'pay' => sanitize_key( (string) ( $_POST['pay'] ?? 'card' ) ), 'email' => sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) ),
			'variant' => sanitize_key( (string) ( $_POST['variant'] ?? '' ) ), 'utm' => sanitize_key( (string) ( $_POST['utm'] ?? '' ) ), 'lead' => (int) ( $_POST['lead'] ?? 0 ),
			'sid' => Insights::request_sid( (string) ( $_POST['sid'] ?? '' ) ), 'ref' => (string) wp_unslash( $_POST['ref'] ?? '' ), 'affgift' => sanitize_key( (string) ( $_POST['affgift'] ?? '' ) ),
		) );
		if ( empty( $r['ok'] ) ) { wp_send_json_error( $r ); }
		wp_send_json_success( $r );
	}

	/**
	 * Цялата логика на add, без HTTP. $a: mode (promo|wallet), items [{id,q}] (родителски WC id), gifts [{id,q}], pay, email, variant, utm, lead, sid, ref, affgift, draft.
	 * Връща [ok, group, total, checkoutUrl, savings] или [ok=false, code, msg].
	 */
	public static function perform_add( $a ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return array( 'ok' => false, 'code' => 'add', 'msg' => 'WooCommerce' ); }
		$may_draft = ! empty( $a['trusted'] ) || current_user_can( \AnsaPromo\Plugin::CAP ) || Page::preview_ok() || Page::share_ok(); /* 1.48.0: тайният линк */
		$draft = ! empty( $a['draft'] ) && $may_draft; $cfg = $draft ? Config::get_draft() : Config::get_published(); $copy = Copy::effective( $cfg['copy'] );
		$err = function ( $code, $vars = array() ) use ( $copy ) { return array( 'ok' => false, 'code' => $code, 'msg' => Copy::render( $copy[ 'err.' . $code ] ?? $code, $vars ) ); };
		$mode = 'wallet' === (string) ( $a['mode'] ?? '' ) ? 'wallet' : 'promo'; $phase = Config::phase_now( $cfg );
		if ( ! $draft && empty( $cfg['enabled'] ) ) { return $err( 'live' ); }
		if ( 'promo' === $mode && ! $draft ) {
			if ( ! in_array( $phase, array( 'early', 'public' ), true ) ) { return $err( 'live' ); }
			if ( 'early' === $phase && ! Page::early_ok( $cfg ) ) { return $err( 'early' ); }
		}
		if ( 'wallet' === $mode && empty( $cfg['cold']['enabled'] ) && ! $draft ) { return $err( 'live' ); }
		$allowed = self::allowed( $cfg, $draft ); $items = self::counts( $a['items'] ?? array() ); $gifts = 'wallet' === $mode ? array() : self::counts( $a['gifts'] ?? array() );
		if ( ! $items ) { return $err( 'add' ); }
		$paid = array_sum( $items ); $ng = array_sum( $gifts );
		if ( $ng > $paid ) { return $err( 'count' ); }
		foreach ( $items as $pid => $q ) {
			if ( ! isset( $allowed[ $pid ] ) || ! empty( $allowed[ $pid ]['giftOnly'] ) ) { return $err( 'allowed' ); }
			$src = self::line_src( $allowed[ $pid ] ); if ( ! $src || ! $src->is_purchasable() || ! $src->is_in_stock() || ! $src->has_enough_stock( $q + (int) ( $gifts[ $pid ] ?? 0 ) ) ) { return $err( 'stock', array( 'name' => wp_strip_all_tags( $allowed[ $pid ]['name'] ) ) ); }
		}
		foreach ( $gifts as $pid => $q ) {
			if ( ! isset( $allowed[ $pid ] ) ) { return $err( 'allowed' ); }
			$src = self::line_src( $allowed[ $pid ] ); if ( ! $src || ! $src->is_purchasable() || ! $src->is_in_stock() ) { return $err( 'stock', array( 'name' => wp_strip_all_tags( $allowed[ $pid ]['name'] ) ) ); }
		}
		$prev = self::ctx(); $pay = ( 'cod' === ( $a['pay'] ?? '' ) && ! empty( $cfg['checkout']['cod_gateway'] ) ) ? 'cod' : 'card';
		$ref = strtoupper( preg_replace( '/[^a-z0-9_\-]/i', '', (string) ( $a['ref'] ?? '' ) ) ) ?: (string) ( $prev['ref'] ?? '' );
		$affgift = in_array( (string) ( $a['affgift'] ?? '' ), array( 'v5', 'mask' ), true ) ? (string) $a['affgift'] : (string) ( $prev['affgift'] ?? '' );
		$partner = ( '' !== $ref && ! empty( $cfg['aff']['enabled'] ) ) ? Page::partner( $ref ) : null; if ( ! $partner ) { $affgift = ''; }
		$ctx = array_merge( $prev, array( 'mode' => $mode, 'promo' => $cfg['id'], 'phase' => $phase, 'variant' => sanitize_key( (string) ( $a['variant'] ?? '' ) ), 'pay' => $pay, 'utm' => sanitize_key( (string) ( $a['utm'] ?? '' ) ), 'sid' => (string) ( $a['sid'] ?? '' ), 'draft' => $draft, 't' => time(), 'ref' => $ref, 'affgift' => $affgift ) );
		$email = sanitize_email( (string) ( $a['email'] ?? '' ) ); if ( '' !== $email ) { $ctx['email'] = $email; } if ( ! empty( $a['lead'] ) ) { $ctx['lead'] = (int) $a['lead']; }
		self::$mutating = true;
		$_POST['ansa_no_gift'] = 1; /* Shrine Cart_Gift: без подаръци по вариация */
		WC()->cart->empty_cart();
		$group = 'bf_' . substr( md5( uniqid( '', true ) ), 0, 10 );
		$meta = array( 'promo' => $cfg['id'], 'group' => $group, 'mode' => $mode, 'phase' => $phase, 'draft' => $draft, 'cv' => (int) Config::version(), 't' => time() );
		$added = 0;
		foreach ( $items as $pid => $q ) { $p = $allowed[ $pid ]; $src = self::line_src( $p ); $vid = $src->is_type( 'variation' ) ? $src->get_id() : 0; $k = WC()->cart->add_to_cart( (int) $pid, $q, $vid, self::variation_attrs( $src ), array( self::META => array_merge( $meta, array( 'role' => 'base', 'key' => $p['key'] ) ) ) ); if ( $k ) { $added++; } }
		if ( ! $added ) { self::$mutating = false; WC()->cart->empty_cart(); return $err( 'add' ); }
		foreach ( $gifts as $pid => $q ) { $p = $allowed[ $pid ]; $src = self::line_src( $p ); $vid = $src->is_type( 'variation' ) ? $src->get_id() : 0; WC()->cart->add_to_cart( (int) $pid, $q, $vid, self::variation_attrs( $src ), array( self::META => array_merge( $meta, array( 'role' => 'gift', 'key' => $p['key'] ) ) ) ); }
		/* маската от партньора — само в публичната промоция (или чернова) */
		/* 1.46.0: „Ти си късметлия“ — 1 бр маска на 0 € към поръчката (промо режим, фазите от настройката, поне една платена опаковка) */
		$lucky = ! empty( $a['lucky'] ) || ! empty( $prev['lucky'] );
		if ( $lucky && 'promo' === $mode && ! empty( $cfg['lucky']['enabled'] ) && ( $draft || in_array( $phase, Boost::lucky_phases( $cfg ), true ) ) ) { $lp = Boost::lucky_product( $cfg ); if ( $lp && $lp['stock'] ) { $lsrc = $lp['vid'] ? wc_get_product( $lp['vid'] ) : wc_get_product( $lp['id'] ); if ( $lsrc ) { WC()->cart->add_to_cart( (int) $lp['id'], 1, (int) $lp['vid'], self::variation_attrs( $lsrc ), array( self::META => array_merge( $meta, array( 'role' => 'lucky', 'key' => 'lucky' ) ) ) ); $ctx['lucky'] = 1; } } }
		elseif ( empty( $a['lucky'] ) ) { unset( $ctx['lucky'] ); }
		if ( 'promo' === $mode && 'mask' === $affgift && ( 'public' === $phase || $draft ) ) { $mk = Page::mask( $cfg ); if ( $mk && $mk['stock'] ) { $msrc = $mk['vid'] ? wc_get_product( $mk['vid'] ) : wc_get_product( $mk['id'] ); if ( $msrc ) { WC()->cart->add_to_cart( (int) $mk['id'], 1, (int) $mk['vid'], self::variation_attrs( $msrc ), array( self::META => array_merge( $meta, array( 'role' => 'pgift', 'key' => 'mask' ) ) ) ); } } }
		foreach ( WC()->cart->get_cart() as $k => $it ) { if ( empty( $it[ self::META ] ) ) { WC()->cart->remove_cart_item( $k ); } }
		self::$mutating = false;
		$ctx['group'] = $group; self::set_ctx( $ctx );
		$gw = 'cod' === $pay ? ( $cfg['checkout']['cod_gateway'] ?: 'cod' ) : $cfg['checkout']['card_gateway']; if ( $gw && WC()->session ) { WC()->session->set( 'chosen_payment_method', $gw ); }
		if ( '' !== $ref && class_exists( '\\AnsaPromo\\Partner' ) ) { try { Partner::reapply_ref( $group, null, $ctx, $cfg ); } catch ( \Throwable $e ) {} }
		WC()->cart->calculate_totals(); WC()->cart->set_session();
		try { Insights::hit( 'add', 'bf', (string) $ctx['variant'] ); } catch ( \Throwable $e ) {}
		do_action( 'ansa_promo_bf_added', $group, $ctx, $cfg );
		do_action( 'ansa_promo_added', $group, 'bf:' . $mode, $ctx, $cfg ); /* 1.45.4: хукът на Сезона → Pixel CAPI Pro праща AddToCart с реалната количка */
		$sv = self::savings(); return array( 'ok' => true, 'group' => $group, 'total' => WC()->cart->get_total( 'edit' ), 'checkoutUrl' => self::checkout_url(), 'savings' => $sv );
	}

	/* ═══ ЧУЖДИ РЕДОВЕ / FK REWARDS ═══ */
	public static function no_fk_rewards( $status, $reward = null ) { return self::group() ? false : $status; }
	public static function strip_foreign( $cart = null ) {
		if ( self::$mutating ) { return; }
		$cart = $cart instanceof \WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null ); if ( ! $cart ) { return; }
		$g = self::group( $cart ); if ( ! $g || empty( $g['foreign'] ) ) { return; }
		$std = array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' ); $remove = array();
		foreach ( $g['foreign'] as $key ) {
			$item = $cart->get_cart_item( $key ); if ( ! $item ) { continue; }
			$auto = (bool) preg_grep( '/fkcart|gift|reward|free/i', array_diff( array_keys( $item ), $std ) );
			if ( ! $auto && (float) ( $item['line_subtotal'] ?? 0 ) <= 0 && (float) ( $item['line_total'] ?? 0 ) <= 0 ) { $p = is_object( $item['data'] ?? null ) ? $item['data'] : wc_get_product( (int) ( $item['variation_id'] ?: $item['product_id'] ) ); if ( $p && (float) $p->get_regular_price() > 0 ) { $auto = true; } }
			if ( apply_filters( 'ansa_promo_bf_strip_foreign', $auto, $key, $item, $g ) ) { $remove[] = $key; }
		}
		if ( ! $remove ) { return; }
		self::$mutating = true; foreach ( $remove as $key ) { $cart->remove_cart_item( $key ); } self::$mutating = false;
	}

	/* ═══ ЦЕНИ ═══ */
	public static function apply_pricing( $cart ) {
		if ( self::$mutating ) { return; }
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
		$g = self::group( $cart ); if ( ! $g ) { return; }
		/* подаръците са най-много колкото платените: излишните отпадат отзад напред */
		$over = (int) $g['ngifts'] - (int) $g['paid'];
		if ( $over > 0 ) {
			self::$mutating = true;
			foreach ( array_reverse( array_keys( $g['gifts'] ), true ) as $key ) {
				if ( $over <= 0 ) { break; } $q = (int) $cart->get_cart_item( $key )['quantity'];
				if ( $q <= $over ) { $cart->remove_cart_item( $key ); $over -= $q; } else { $cart->set_quantity( $key, $q - $over, false ); $over = 0; }
			}
			self::$mutating = false; $g = self::group( $cart ); $ctx = self::ctx(); $ctx['shrunk'] = 1; self::set_ctx( $ctx );
		}
		if ( $g['pgift'] && (int) $g['paid'] <= 0 ) { self::$mutating = true; $cart->remove_cart_item( $g['pgift'] ); self::$mutating = false; $g = self::group( $cart ); if ( ! $g ) { return; } }
		if ( $g['lucky'] && (int) $g['paid'] <= 0 ) { self::$mutating = true; $cart->remove_cart_item( $g['lucky'] ); self::$mutating = false; $g = self::group( $cart ); if ( ! $g ) { return; } }
		foreach ( $g['base'] as $key => $item ) { $item['data']->set_price( self::reg_price( $item['data'] ) ); }
		foreach ( $g['gifts'] as $key => $item ) { $item['data']->set_price( 0 ); }
		if ( $g['pgift'] ) { $items = $cart->get_cart(); if ( isset( $items[ $g['pgift'] ] ) ) { $items[ $g['pgift'] ]['data']->set_price( 0 ); } }
		if ( $g['lucky'] ) { $items = $cart->get_cart(); if ( isset( $items[ $g['lucky'] ] ) ) { $items[ $g['lucky'] ]['data']->set_price( 0 ); } }
	}

	/* ═══ ТАКСИ: ваучерите ═══ */
	/** 1.45.2: ваучерът е ОТРИЦАТЕЛНА такса, а WooCommerce разпределя ДДС върху всяка отрицателна такса (независимо от $taxable) и я третира
	 *  като сума БЕЗ ДДС → „−10“ ставаше „−12,00“ в чекаута при цени с включен 20 % ДДС. Затова таксата се подава нето: брутната сума ÷ (1 + реалната
	 *  ставка на количката = ДДС на редовете / сумата им без ДДС), и в чекаута се вижда точно −10,00 € (ДДС редът също намалява правилно). */
	public static function fee_net( $cart, $gross ) {
		$gross = (float) $gross; if ( $gross <= 0 || ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() || ! wc_prices_include_tax() ) { return $gross; }
		$ex = 0.0; $tax = 0.0; foreach ( $cart->get_cart() as $it ) { $ex += (float) ( $it['line_subtotal'] ?? 0 ); $tax += (float) ( $it['line_subtotal_tax'] ?? 0 ); }
		if ( $ex <= 0 || $tax <= 0 ) { return $gross; }
		return round( $gross / ( 1 + $tax / $ex ), 4 );
	}
	public static function fees( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
		$ctx = self::ctx(); if ( ! $ctx ) { return; }
		$cfg = self::cfg(); $g = self::group( $cart ); $sub = 0.0;
		foreach ( $cart->get_cart() as $item ) { $sub += (float) $item['line_subtotal']; }
		if ( $sub <= 0 ) { return; }
		/* студеният ваучер (портфейлът): важи в цялата количка, докато е жив и неизползван */
		$v = is_array( $ctx['voucher'] ?? null ) ? $ctx['voucher'] : null;
		if ( $v && Leads::voucher_valid( (string) $v['code'] ) && (float) $v['amount'] > 0 ) {
			$amt = min( (float) $v['amount'], $sub ); $sub -= $amt;
			$cart->add_fee( self::copy( 'co.fee.cold', array( 'voucher' => self::fmt( $v['amount'] ), 'code' => (string) $v['code'] ) ), -self::fee_net( $cart, $amt ), false );
		}
		/* партньорският ваучер: само в публичната промоция, при промо група с платени опаковки */
		if ( $g && 'promo' === $g['mode'] && 'v5' === (string) ( $ctx['affgift'] ?? '' ) && (int) $g['paid'] > 0 && ( 'public' === Config::phase_now( $cfg ) || $g['draft'] ) && ! empty( $cfg['aff']['enabled'] ) && (float) $cfg['aff']['voucher'] > 0 && $sub > 0 ) {
			$p = Page::partner( (string) ( $ctx['ref'] ?? '' ) );
			if ( $p ) { $amt = min( (float) $cfg['aff']['voucher'], $sub ); $cart->add_fee( self::copy( 'co.fee.aff', array( 'aff_voucher' => self::fmt( $cfg['aff']['voucher'] ), 'partner' => $p['name'] ) ), -self::fee_net( $cart, $amt ), false ); }
		}
	}

	/* ═══ ЗАКЛЮЧВАНЕ / ЕТИКЕТИ ═══ */
	public static function lock_qty( $html, $key, $item ) { return ! empty( $item[ self::META ] ) ? '<span class="ansa-promo-qty">' . (int) $item['quantity'] . '</span>' : $html; }
	public static function lock_remove( $html, $key ) { $item = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_item( $key ) : null; return ( $item && ! empty( $item[ self::META ] ) ) ? '' : $html; }
	public static function cart_label( $name, $item, $key ) {
		if ( empty( $item[ self::META ] ) ) { return $name; }
		$role = (string) $item[ self::META ]['role']; $name = preg_replace( '/\s*(?:[-–—,·|]|&ndash;|&mdash;)\s*(?:1|една|един)\s*(?:бр\.?|брой|опаковка|кутия|кутийка|pc\.?|pcs\.?)(\s*<\/a>)?\s*$/iu', '$1', (string) $name );
		$ctx = self::ctx(); $p = ! empty( $ctx['ref'] ) ? Page::partner( (string) $ctx['ref'] ) : null;
		$tag = 'gift' === $role ? self::copy( 'co.gift.tag' ) : ( 'lucky' === $role ? self::copy( 'co.lucky.tag' ) : ( 'pgift' === $role ? self::copy( 'co.pgift.tag', array( 'partner' => $p ? $p['name'] : 'партньора' ) ) : ( 'wallet' === (string) ( $item[ self::META ]['mode'] ?? '' ) ? self::copy( 'co.wallet.tag' ) : self::copy( 'co.base.tag' ) ) ));
		return $name . '<br><small class="ansa-promo-tag ansa-promo-lock">' . wp_kses_post( $tag ) . '</small>';
	}
	public static function cart_price_html( $html, $item, $key ) { if ( empty( $item[ self::META ] ) || ! in_array( $item[ self::META ]['role'], array( 'gift', 'pgift', 'lucky' ), true ) ) { return $html; } $reg = ! empty( $item['data'] ) ? self::reg_price( $item['data'] ) : 0; return ( $reg > 0 ? '<s class="ansa-promo-reg">' . esc_html( self::fmt( $reg ) ) . ' €</s> ' : '' ) . '<span class="ansa-promo-free">' . esc_html( self::copy( 'co.free' ) ) . '</span>'; } /* 1.48.4: зачеркнатата редовна цена пред „0 € · подарък“ */

	public static function check_cart() {
		$g = self::group(); if ( ! $g ) { return; }
		$cfg = self::cfg(); $url = esc_url( Page::url( $cfg ) );
		if ( 'promo' === $g['mode'] && ! $g['draft'] && ! current_user_can( \AnsaPromo\Plugin::CAP ) ) {
			if ( ! Config::is_live( $cfg ) ) { wc_add_notice( wp_kses_post( self::copy( 'co.closed', array( 'url' => $url ) ) ), 'error' ); }
			elseif ( 'early' === Config::phase_now( $cfg ) && ! Page::early_ok( $cfg ) ) { wc_add_notice( esc_html( self::copy( 'err.early' ) ), 'error' ); }
		}
		$ctx = self::ctx();
		if ( ! empty( $ctx['shrunk'] ) ) { wc_add_notice( esc_html( self::copy( 'co.broken' ) ), 'notice' ); unset( $ctx['shrunk'] ); self::set_ctx( $ctx ); }
		if ( 'promo' === $g['mode'] && (int) $g['ngifts'] < (int) $g['paid'] ) { $n = (int) $g['paid'] - (int) $g['ngifts']; wc_add_notice( wp_kses_post( self::copy( 'co.more', array( 'n' => $n, 'podaruk' => self::plural( $n, 'подарък', 'подаръка' ), 'url' => $url ) ) ), 'notice' ); }
	}

	/* ═══ ДОСТАВКА · КУПОНИ · ИМЕЙЛ ═══ */
	public static function free_shipping( $rates, $package ) {
		$g = self::group(); if ( ! $g || 'promo' !== $g['mode'] ) { return $rates; }
		$cfg = self::cfg(); if ( (int) $cfg['ship']['free_from'] <= 0 || (int) $g['paid'] < (int) $cfg['ship']['free_from'] ) { return $rates; }
		foreach ( $rates as $id => $rate ) { $rate->set_cost( 0 ); $taxes = $rate->get_taxes(); if ( is_array( $taxes ) ) { $rate->set_taxes( array_map( '__return_zero', $taxes ) ); } $rate->set_label( $rate->get_label() . ' · ' . self::copy( 'co.ship.free' ) ); }
		return $rates;
	}
	public static function block_coupon( $valid, $coupon ) {
		$g = self::group(); if ( ! $g || ! $valid ) { return $valid; }
		$cfg = self::cfg(); if ( empty( $cfg['checkout']['block_coupons'] ) ) { return $valid; }
		$code = is_object( $coupon ) ? $coupon->get_code() : (string) $coupon;
		if ( class_exists( '\\AnsaPromo\\Partner' ) && Partner::is_partner_code( $code, $coupon ) ) { return $valid; }
		if ( apply_filters( 'ansa_promo_bf_allow_coupon', false, $coupon, $g ) ) { return $valid; }
		throw new \Exception( self::copy( 'co.coupon' ), 100 );
	}
	public static function zero_partner_discount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( empty( $cart_item[ self::META ] ) || ! class_exists( '\\AnsaPromo\\Partner' ) ) { return $discount; }
		return Partner::is_partner_code( $coupon->get_code(), $coupon ) ? 0 : $discount;
	}
	public static function prefill_email( $value, $input ) { if ( 'billing_email' === $input && ! $value ) { $ctx = self::ctx(); if ( ! empty( $ctx['email'] ) ) { return $ctx['email']; } } return $value; }

	/* ═══ СПЕСТЯВАНЕ ═══ */
	/** [gifts (стойността на подаръците), pgift, vouchers, total, pct, paid, ngifts, sub] от текущата количка. */
	public static function savings( $cart = null ) {
		$cart = $cart ?: ( function_exists( 'WC' ) ? WC()->cart : null ); $g = self::group( $cart ); if ( ! $g ) { return null; }
		$sub = 0.0; $gv = 0.0; $pg = 0.0;
		foreach ( $g['base'] as $it ) { $sub += self::reg_price( $it['data'] ) * (int) $it['quantity']; }
		foreach ( $g['gifts'] as $it ) { $gv += self::reg_price( $it['data'] ) * (int) $it['quantity']; }
		if ( $g['pgift'] ) { $it = $cart->get_cart_item( $g['pgift'] ); if ( $it ) { $pg = self::reg_price( $it['data'] ); } }
		if ( $g['lucky'] ) { $it = $cart->get_cart_item( $g['lucky'] ); if ( $it ) { $pg += self::reg_price( $it['data'] ); } }
		/* 1.45.2: таксата е нето (fee_net) — брутото за показване е total + tax, щом WC вече ги е сметнал */
		$vo = 0.0; foreach ( (array) $cart->get_fees() as $fee ) { if ( (float) $fee->amount < 0 ) { $vo += -( isset( $fee->total ) ? (float) $fee->total + (float) ( $fee->tax ?? 0 ) : (float) $fee->amount ); } }
		$total = $gv + $pg + $vo; $full = $sub + $gv + $pg;
		return array( 'gifts' => round( $gv, 2 ), 'pgift' => round( $pg, 2 ), 'vouchers' => round( $vo, 2 ), 'total' => round( $total, 2 ), 'pct' => $full > 0 ? (int) round( $total / $full * 100 ) : 0, 'paid' => (int) $g['paid'], 'ngifts' => (int) $g['ngifts'], 'sub' => round( $sub, 2 ) );
	}

	/* ═══ ЧЕКАУТ ═══ */
	public static function checkout_row() {
		$g = self::group(); if ( ! $g ) { return; }
		self::css(); /* 1.45.2: и за портфейла (wallet) — иначе степерът на FunnelKit се показваше на редовете с ваучера (и се чупеше в reskin-а: два плюса/минуса) */
		if ( 'promo' !== $g['mode'] ) { return; }
		/* 1.48.4: „Спестяваш“ е голямото в кутията (човекът: „навсякъде да ти се говори, че спестяваш мега много“): заглавието е малко, под него „Спестяваш 177 €“ едро зелено, „плащаш 177 € вместо ~~354 €~~ · −50 %“ и бележката за подаръците */
		$sv = self::savings(); $total = (float) WC()->cart->get_total( 'edit' ); $ship = (float) WC()->cart->get_shipping_total() + (float) WC()->cart->get_shipping_tax(); $pay = max( 0, $total - $ship );
		$v = array( 'paid' => $sv['paid'], 'gifts' => $sv['ngifts'], 'podaruk' => self::plural( $sv['ngifts'], 'подарък', 'подаръка' ), 'save' => self::fmt( $sv['total'] ), 'pct' => $sv['pct'], 'total' => self::fmt( $pay ), 'full' => self::fmt( $pay + $sv['total'] ) );
		echo '<tr class="ansa-promo-co ansa-bf-co" data-ver="' . esc_attr( ANSA_PROMO_VER ) . '"><td colspan="2"><div class="ansa-promo-co__box"><b>' . wp_kses_post( self::copy( 'co.h', $v ) ) . '</b>'
			. ( $sv['total'] > 0 ? '<p class="ansa-bf-save">' . wp_kses_post( self::copy( 'co.save', $v ) ) . '</p><p class="ansa-bf-save-sub">' . wp_kses_post( self::copy( 'co.save.sub', $v ) ) . '</p>' : '' )
			. '<p class="ansa-bf-note">' . wp_kses_post( self::copy( 'co.note', $v ) ) . '</p></div></td></tr>';
		self::css();
	}
	/** 1.45.2: FunnelKit рисува таксите в обобщението като <tr class="cart-subtotal fee">, а Checkout Reskin-ът крие текста на ВСЕКИ tr.cart-subtotal th span
	 *  и пише „Междинна сума“ през ::before — затова редът на ваучера се казваше „Междинна сума −12,00“. Тук за .fee връщаме истинското име („Ваучер 10 € · КОД“). */
	private static function css() { static $d = false; if ( $d ) { return; } $d = true; echo '<style id="ansa-bf-co-css">.ansa-promo-co__box{background:#fff8f1!important;border:1.5px solid #f3cdb8!important;border-radius:16px!important;padding:14px 16px!important;margin:6px 0!important;text-align:left!important;color:#1a1a2e}.ansa-promo-co__box>b{display:block!important;font-weight:900!important;font-size:15px!important;margin:0 0 6px!important}.ansa-bf-save{margin:0!important;font-weight:900;color:#15803d;font-size:22px!important;line-height:1.1!important}.ansa-bf-save-sub{margin:4px 0 0!important;font-weight:700;color:#2d2d3f;font-size:13px!important}.ansa-bf-save-sub s{color:#8a6a55}.ansa-bf-note{margin:8px 0 0!important;padding-top:8px;border-top:1px dashed #f3cdb8;font-weight:700;color:#8a6a55;font-size:12.5px!important;line-height:1.35!important}.ansa-promo-reg{color:#8a6a55;font-weight:700;font-size:12px}.ansa-promo-tag{color:#c2410c;font-weight:800}.ansa-promo-free{font-weight:900;color:#15803d}.ansa-promo-qty{font-weight:900}tr.cart_item:has(.ansa-promo-lock) .wfacp_quantity_selector,tr.cart_item:has(.ansa-promo-lock) .product-quantity,tr.cart_item:has(.ansa-promo-lock) .wfacp_delete_item_wrap,tr.cart_item:has(.ansa-promo-lock) .wfacp_mini_cart_remove_item_from_cart{display:none!important}.ansa-promo-co td{padding:0!important}body table.wfacp_mini_cart_reviews tr.cart-subtotal.fee th span{font-size:13px!important}body table.wfacp_mini_cart_reviews tr.cart-subtotal.fee th span::before{content:none!important}.ansa-promo-ty{margin:16px 0!important}.ansa-promo-ty p{margin:6px 0 0!important;font-size:14px!important}</style>'; }

	/* ═══ ПОРЪЧКА ═══ */
	public static function build_meta() {
		$g = self::group(); if ( ! $g ) { return null; }
		$cfg = self::cfg(); $ctx = self::ctx(); $sv = self::savings(); $p = ! empty( $ctx['ref'] ) ? Page::partner( (string) $ctx['ref'] ) : null;
		$gifts = array(); foreach ( $g['gifts'] as $it ) { $gifts[] = array( 'id' => (int) $it['product_id'], 'name' => wp_strip_all_tags( $it['data']->get_name() ), 'qty' => (int) $it['quantity'] ); }
		$v = is_array( $ctx['voucher'] ?? null ) && Leads::voucher_valid( (string) $ctx['voucher']['code'] ) ? array( 'kind' => 'cold', 'code' => (string) $ctx['voucher']['code'], 'amount' => (float) $ctx['voucher']['amount'] ) : null;
		$meta = array(
			'promo' => $cfg['id'], 'group' => $g['group'], 'mode' => $g['mode'], 'phase' => $g['phase'], 'variant' => (string) ( $ctx['variant'] ?? '' ), 'paid' => (int) $g['paid'], 'ngifts' => (int) $g['ngifts'], 'gifts' => $gifts,
			'pgift' => $g['pgift'] ? 'mask' : '', 'lucky' => $g['lucky'] ? 1 : 0, 'voucher' => $v, 'aff' => $p ? array( 'code' => $p['code'], 'name' => $p['name'], 'gift' => (string) ( $ctx['affgift'] ?? '' ) ) : null,
			'savings' => $sv, 'lead_id' => (int) ( $ctx['lead'] ?? 0 ), 'lead_email' => (string) ( $ctx['email'] ?? '' ), 'utm' => (string) ( $ctx['utm'] ?? '' ), 'sid' => (string) ( $ctx['sid'] ?? '' ), 'pay' => (string) ( $ctx['pay'] ?? '' ), 'draft' => ! empty( $g['draft'] ), 'ver' => ANSA_PROMO_VER, 'config_version' => Config::version(),
		);
		return apply_filters( 'ansa_promo_bf_order_meta', $meta, $g, $cfg );
	}
	private static function write_meta( $order, $m ) {
		$order->update_meta_data( '_ansa_promo_bf', $m );
		$order->update_meta_data( '_ansa_bf_promo', $m['promo'] ); $order->update_meta_data( '_ansa_bf_mode', $m['mode'] ); $order->update_meta_data( '_ansa_bf_phase', $m['phase'] ); $order->update_meta_data( '_ansa_bf_variant', $m['variant'] );
		$order->update_meta_data( '_ansa_bf_paid', (int) $m['paid'] ); $order->update_meta_data( '_ansa_bf_gifts', (int) $m['ngifts'] ); $order->update_meta_data( '_ansa_bf_savings', (float) ( $m['savings']['total'] ?? 0 ) );
		$order->update_meta_data( '_ansa_bf_voucher', $m['voucher'] ? $m['voucher']['code'] : '' ); $order->update_meta_data( '_ansa_bf_ref', $m['aff'] ? $m['aff']['code'] : '' ); $order->update_meta_data( '_ansa_bf_lead', (int) $m['lead_id'] );
	}
	public static function order_meta( $order, $data ) { $m = self::build_meta(); if ( $m ) { self::write_meta( $order, $m ); } }
	public static function order_meta_store_api( $order, $request ) { $m = self::build_meta(); if ( $m ) { self::write_meta( $order, $m ); } }
	public static function line_meta( $item, $key, $values, $order ) {
		if ( empty( $values[ self::META ] ) ) { return; }
		$item->add_meta_data( '_ansa_bf_role', (string) $values[ self::META ]['role'], true );
		$item->add_meta_data( '_ansa_bf_key', (string) ( $values[ self::META ]['key'] ?? '' ), true );
	}
	public static function order_created( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) { $order = wc_get_order( (int) $order ); if ( ! $order ) { return; } }
		$m = $order->get_meta( '_ansa_promo_bf' ); if ( ! is_array( $m ) ) { return; }
		Leads::on_order( $order, $m );
		$order->add_order_note( sprintf( 'ansa™ Черен петък: %s · фаза %s · %d платени + %d подаръка%s%s · спестени %s €', $m['mode'], $m['phase'], (int) $m['paid'], (int) $m['ngifts'], $m['pgift'] ? ' + маска' : '', $m['voucher'] ? ' · ваучер ' . $m['voucher']['code'] . ' (−' . self::fmt( $m['voucher']['amount'] ) . ' €)' : '', self::fmt( $m['savings']['total'] ?? 0 ) ) . ( $m['aff'] ? ' · партньор ' . $m['aff']['code'] : '' ) );
		try { Insights::hit( 'created', 'bf', (string) $m['variant'] ); } catch ( \Throwable $e ) {}
		do_action( 'ansa_promo_bf_order_created', $order, $m );
	}
	public static function order_label( $name, $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) { return $name; }
		$role = (string) $item->get_meta( '_ansa_bf_role' ); if ( '' === $role ) { return $name; }
		$c = Copy::effective( Config::get_published()['copy'] );
		return $name . ' <small class="ansa-promo-tag" style="color:#c2410c;font-weight:800">' . esc_html( 'gift' === $role ? $c['co.gift.tag'] : ( 'lucky' === $role ? $c['co.lucky.tag'] : ( 'pgift' === $role ? wp_strip_all_tags( Copy::render( $c['co.pgift.tag'], array( 'partner' => 'партньор' ) ) ) : $c['co.base.tag'] ) ) ) . '</small>';
	}
	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id ); if ( ! $order ) { return; }
		$m = $order->get_meta( '_ansa_promo_bf' ); if ( ! is_array( $m ) ) { return; }
		$cfg = ! empty( $m['draft'] ) ? Config::get_draft() : Config::get_published(); $pt = Config::phase_ts( $cfg );
		$v = array( 'paid' => (int) $m['paid'], 'gifts' => (int) $m['ngifts'], 'podaruk' => self::plural( (int) $m['ngifts'], 'подарък', 'подаръка' ), 'save' => self::fmt( $m['savings']['total'] ?? 0 ), 'pct' => (int) ( $m['savings']['pct'] ?? 0 ), 'early_date' => $pt['early_start'] ? wp_date( 'd.m', $pt['early_start'] ) : '', 'early_time' => $pt['early_start'] ? wp_date( 'H:i', $pt['early_start'] ) : '' );
		$c = Copy::effective( $cfg['copy'] );
		if ( 'wallet' === $m['mode'] ) { echo '<div class="ansa-promo-co__box ansa-promo-ty"><p>' . wp_kses_post( Copy::render( $c['ty.wallet'], $v ) ) . '</p></div>'; }
		else { echo '<div class="ansa-promo-co__box ansa-promo-ty"><b>' . wp_kses_post( Copy::render( $c['ty.h'], $v ) ) . '</b>' . ( (float) ( $m['savings']['total'] ?? 0 ) > 0 ? '<p class="ansa-bf-save">' . wp_kses_post( Copy::render( $c['ty.save'], $v ) ) . '</p>' : '' ) . '<p>' . wp_kses_post( Copy::render( $c['ty.p'], $v ) ) . '</p></div>'; } /* 1.48.4: „Спести 177 € · −50 %“ едро и на благодаря-страницата */
		self::css();
	}
}
