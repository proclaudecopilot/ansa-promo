<?php
/**
 * ansa™ Промо — количка и чекаут (Фаза 3; порт на Ansa_Promo_Cart от Shrine 6.19 с поправките от плана §6.1–6.3).
 *
 *  - ansa_promo_add: клиентът праща само product ID-та + кутия; сървърът валидира ред по ред (live → box → count → allowed →
 *    stock → secret), изчиства количката (промо поръчката е самостоятелна) и добавя редовете с група. Грешките са кодове +
 *    текст от копи-регистъра (err.*). Rate limit 10/мин/IP. Същият състав вече в количката → не се добавя пак (идемпотентно).
 *  - pct/packs/secret_pct се записват В РЕДА при добавянето (pct_at_add): публикуване по средата на сесията не преоценява.
 *  - Цени в before_calculate_totals: base = regular × (1 − pct), secret = regular × (1 − spct), book = 0. Base редовете са
 *    заключени (без qty/✕). Счупена група (Σ ≠ packs) → регулярни цени, книгата пада, чекаутът спира с notice + линк.
 *  - Безплатна доставка през package_rates (нулира избрания метод, не сменя метода). Купоните са блокирани (filter
 *    ansa_promo_allow_coupon за ansa Partner, Фаза 5). Shrine Cart_Gift не инжектира подаръци ($_POST['ansa_no_gift']).
 *  - Чекаут (класически / FunnelKit — същите hooks): ред „Твоята кутия и твоите награди“, ☑ сетът, имейлът prefill.
 *    Order meta `_ansa_promo` + плоски ключове (HPOS-safe), Store API вариант, благодаря-блок, lead → converted_order_id.
 *
 * @package AnsaPromo
 * @since 1.1.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Cart {

	const META = 'ansa_promo';      // ключ в cart item data
	const CTX  = 'ansa_promo_ctx';  // ключ в WC session
	private static $mutating = false;

	public static function register() {
		add_action( 'wp_ajax_ansa_promo_add', array( __CLASS__, 'ajax_add' ) );
		add_action( 'wp_ajax_nopriv_ansa_promo_add', array( __CLASS__, 'ajax_add' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_pricing' ), 95 );
		/* v1.5.3: FunnelKit Cart „Rewards“ (безплатен подарък/купон/доставка по праг) не важат за промо количката —
		   сетът, книгата и билетите са наградите ѝ; подаръкът по праг влизаше като чужд ред на €0 (CollaMask ×2). */
		add_filter( 'fkcart_reward_rules_checking', array( __CLASS__, 'no_fk_rewards' ), 1, 2 );
		add_filter( 'fkcart_reward_enabled', array( __CLASS__, 'no_fk_rewards' ), 1, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'strip_foreign' ), 20 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'lock_qty' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_remove_link', array( __CLASS__, 'lock_remove' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'cart_label' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_price_html' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'free_shipping' ), 50, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'block_coupon' ), 10, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill_email' ), 10, 2 );
		add_action( 'woocommerce_review_order_after_cart_contents', array( __CLASS__, 'checkout_rewards_row' ) );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'checkout_cosm_consent' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_meta' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'order_meta_store_api' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'line_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'order_created' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'order_created' ) );
		add_filter( 'woocommerce_order_item_name', array( __CLASS__, 'order_label' ), 10, 2 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 5 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear_ctx' ), 99 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'clear_ctx' ), 99 );
	}

	/* ── помощници ── */
	public static function ctx() { return ( function_exists( 'WC' ) && WC()->session ) ? (array) WC()->session->get( self::CTX, array() ) : array(); }
	private static function set_ctx( $c ) { if ( function_exists( 'WC' ) && WC()->session ) { WC()->session->set( self::CTX, $c ); } }
	public static function clear_ctx() { if ( function_exists( 'WC' ) && WC()->session ) { WC()->session->set( self::CTX, null ); } }
	/** Конфигът за тази количка: черновата, ако добавянето е станало в чернова (проверено на сървъра), иначе публикуваното. */
	public static function cfg() { $ctx = self::ctx(); return ! empty( $ctx['draft'] ) ? Config::get_draft() : Config::get_published(); }
	private static function copy( $k, $vars = array() ) { $c = Copy::effective( self::cfg()['copy'] ); return Copy::render( isset( $c[ $k ] ) ? $c[ $k ] : $k, $vars ); }
	private static function reg_price( $p ) { $r = (float) $p->get_regular_price(); return $r > 0 ? $r : (float) $p->get_price(); }
	public static function promo_url( $cfg = null ) { $cfg = $cfg ?: self::cfg(); return ! empty( $cfg['page_id'] ) ? get_permalink( (int) $cfg['page_id'] ) : home_url( '/' ); }
	public static function checkout_url() { return apply_filters( 'ansa_promo_checkout_url', wc_get_checkout_url(), self::ctx() ); }
	private static function fmt( $n ) { return null === $n ? '' : str_replace( '.', ',', rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' ) ); }

	/** Групата в количката: [ group, box, promo, pct, packs, spct, ship, base[key=>item], book, secret[key=>item], sum, foreign[] ] */
	public static function group( $cart = null ) {
		$cart = $cart ?: ( function_exists( 'WC' ) ? WC()->cart : null ); if ( ! $cart ) { return null; }
		$g = null; $foreign = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item[ self::META ] ) || ! is_array( $item[ self::META ] ) ) { $foreign[] = $key; continue; }
			$m = $item[ self::META ];
			if ( null === $g ) { $g = array( 'group' => (string) $m['group'], 'box' => (string) $m['box'], 'promo' => (string) $m['promo'], 'pct' => (float) ( $m['pct'] ?? 0 ), 'packs' => (int) ( $m['packs'] ?? 0 ), 'spct' => (float) ( $m['spct'] ?? 0 ), 'ship' => ! empty( $m['ship'] ), 'draft' => ! empty( $m['draft'] ), 'base' => array(), 'book' => null, 'secret' => array(), 'sum' => 0 ); }
			if ( 'base' === $m['role'] ) { $g['base'][ $key ] = $item; $g['sum'] += (int) $item['quantity']; }
			elseif ( 'book' === $m['role'] ) { $g['book'] = $key; }
			elseif ( 'secret' === $m['role'] ) { $g['secret'][ $key ] = $item; }
		}
		if ( $g ) { $g['foreign'] = $foreign; }
		return $g;
	}
	public static function group_valid( $g ) { return $g && $g['packs'] > 0 && $g['sum'] === $g['packs'] && count( $g['base'] ) > 0; }

	/** Продуктите в играта като карта parent_id → runtime ред (id, vid, key, name, price, stock). */
	private static function allowed( $cfg ) {
		$out = array();
		foreach ( Frontend::products( $cfg ) as $key => $p ) { $out[ (int) $p['id'] ] = $p; }
		return $out;
	}
	private static function line_src( $p ) { return $p['vid'] ? wc_get_product( (int) $p['vid'] ) : wc_get_product( (int) $p['id'] ); }
	/** Атрибутите за add_to_cart: вариация с „Any…“ атрибут (празна стойност) иначе бива отхвърлена — слагаме първата възможна стойност от родителя. */
	private static function variation_attrs( $src ) {
		if ( ! $src || ! $src->is_type( 'variation' ) ) { return array(); }
		$attrs = (array) $src->get_variation_attributes(); $parent = wc_get_product( $src->get_parent_id() );
		foreach ( $attrs as $k => $v ) {
			if ( '' !== (string) $v || ! $parent ) { continue; }
			foreach ( $parent->get_attributes() as $pa ) { if ( 'attribute_' . sanitize_title( $pa['name'] ) === $k ) { $slugs = $pa->get_slugs(); if ( $slugs ) { $attrs[ $k ] = (string) reset( $slugs ); } break; } }
		}
		return $attrs;
	}

	/* ═══ ADD ═══ */
	public static function ajax_add() {
		if ( ! check_ajax_referer( Frontend::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'nonce', 'msg' => Copy::render( Copy::effective( Config::get_published()['copy'] )['err.nonce'], array() ) ), 403 ); }
		if ( Leads::rate_limited( 'add', 10 ) ) { wp_send_json_error( array( 'code' => 'rate', 'msg' => Copy::render( Copy::effective( Config::get_published()['copy'] )['err.rate'], array() ) ), 429 ); }
		$r = self::perform_add( array(
			'draft'  => ! empty( $_POST['draft'] ), 'box' => sanitize_key( (string) ( $_POST['box'] ?? '' ) ),
			'items'  => json_decode( (string) wp_unslash( $_POST['items'] ?? '[]' ), true ), 'secret' => json_decode( (string) wp_unslash( $_POST['secret'] ?? '[]' ), true ),
			'pay'    => sanitize_key( (string) ( $_POST['pay'] ?? 'card' ) ), 'email' => sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) ), 'cosm' => ! isset( $_POST['cosm'] ) || '0' !== (string) $_POST['cosm'],
			'utm'    => sanitize_key( (string) ( $_POST['utm'] ?? '' ) ), 'lead' => (int) ( $_POST['lead'] ?? 0 ),
		) );
		if ( empty( $r['ok'] ) ) { wp_send_json_error( $r ); }
		wp_send_json_success( $r );
	}

	/**
	 * Цялата логика на add, без HTTP — ползва се от ajax_add и от проверката promo-cart.
	 * $a: box, items[parent ids], secret[parent ids], pay, email, cosm, utm, lead, draft; trusted=true (само от PHP) = права за чернова.
	 * Връща [ok, code, msg] или [ok, group, box, total, checkoutUrl, idempotent].
	 */
	public static function perform_add( $a ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return array( 'ok' => false, 'code' => 'add', 'msg' => 'WooCommerce' ); }
		$may_draft = ! empty( $a['trusted'] ) || current_user_can( Plugin::CAP ) || Frontend::preview_ok();
		$draft = ! empty( $a['draft'] ) && $may_draft;
		$cfg   = $draft ? Config::get_draft() : Config::get_published();
		$copy  = Copy::effective( $cfg['copy'] );
		$err   = function ( $code, $vars = array() ) use ( $copy ) { return array( 'ok' => false, 'code' => $code, 'msg' => Copy::render( $copy[ 'err.' . $code ] ?? $code, $vars ) ); };
		if ( ! $draft && ! Config::is_live( $cfg ) ) { return $err( 'live' ); }
		$box_id = sanitize_key( (string) ( $a['box'] ?? '' ) ); $box = Config::box( $cfg, $box_id );
		if ( ! $box ) { return $err( 'box' ); }
		$bv = array( 'packs' => (int) $box['packs'], 'opk' => 1 === (int) $box['packs'] ? 'опаковка' : 'опаковки', 'box' => $box['name'], 'box_l' => mb_strtolower( $box['name'] ) );
		$items  = is_array( $a['items'] ?? null ) ? array_values( array_filter( array_map( 'absint', $a['items'] ) ) ) : array();
		$secret = is_array( $a['secret'] ?? null ) ? array_values( array_unique( array_filter( array_map( 'absint', $a['secret'] ) ) ) ) : array();
		if ( count( $items ) !== (int) $box['packs'] ) { return $err( 'count', $bv ); }
		$allowed = self::allowed( $cfg ); $counts = array_count_values( $items );
		foreach ( $counts as $pid => $q ) {
			if ( ! isset( $allowed[ $pid ] ) ) { return $err( 'allowed' ); }
			$src = self::line_src( $allowed[ $pid ] );
			if ( ! $src || ! $src->is_purchasable() || ! $src->is_in_stock() || ! $src->has_enough_stock( $q ) ) { return $err( 'stock', array( 'name' => wp_strip_all_tags( $allowed[ $pid ]['name'] ) ) ); }
		}
		$secret_ok = array();
		if ( ! empty( $cfg['secret']['enabled'] ) ) {
			foreach ( $secret as $pid ) { if ( isset( $allowed[ $pid ] ) && ! isset( $counts[ $pid ] ) ) { $src = self::line_src( $allowed[ $pid ] ); if ( $src && $src->is_purchasable() && $src->is_in_stock() ) { $secret_ok[] = $pid; } } }
			$secret_ok = array_slice( $secret_ok, 0, (int) $cfg['secret']['count'] );
		}
		$pay   = ( 'cod' === ( $a['pay'] ?? '' ) && ! empty( $cfg['checkout']['cod_gateway'] ) ) ? 'cod' : 'card';
		$email = sanitize_email( (string) ( $a['email'] ?? '' ) );
		$cosm  = ! isset( $a['cosm'] ) || (bool) $a['cosm'];
		$ctx   = array( 'promo' => $cfg['id'], 'box' => $box_id, 'email' => $email, 'cosm' => $cosm, 'pay' => $pay, 'utm' => sanitize_key( (string) ( $a['utm'] ?? '' ) ), 'lead' => (int) ( $a['lead'] ?? 0 ), 'draft' => $draft, 't' => time() );

		/* идемпотентно: същата кутия и същият състав вече са в количката (двойно докосване на телефона) → само контекстът се опреснява */
		$g = self::group();
		if ( self::group_valid( $g ) && $g['box'] === $box_id && $g['promo'] === $cfg['id'] && ! $g['foreign'] ) {
			$have = array(); foreach ( $g['base'] as $it ) { $have[ (int) $it['product_id'] ] = ( $have[ (int) $it['product_id'] ] ?? 0 ) + (int) $it['quantity']; }
			$hs = array(); foreach ( $g['secret'] as $it ) { $hs[] = (int) $it['product_id']; } sort( $hs ); $ws = $secret_ok; sort( $ws );
			$want = $counts; ksort( $have ); ksort( $want );
			if ( $have === $want && $hs === $ws ) {
				$ctx['group'] = $g['group']; self::set_ctx( $ctx ); self::choose_gateway( $cfg, $pay );
				WC()->cart->calculate_totals();
				return array( 'ok' => true, 'idempotent' => true, 'group' => $g['group'], 'box' => $box_id, 'total' => WC()->cart->get_total( 'edit' ), 'checkoutUrl' => self::checkout_url() );
			}
		}

		/* промото е самостоятелна поръчка */
		self::$mutating = true;
		$_POST['ansa_no_gift'] = 1; /* Shrine Cart_Gift: без подаръци по вариация в промо количката */
		WC()->cart->empty_cart();
		$group = 'ap_' . substr( md5( uniqid( '', true ) ), 0, 10 );
		$meta  = array( 'promo' => $cfg['id'], 'group' => $group, 'box' => $box_id, 'pct' => (float) $box['pct'], 'packs' => (int) $box['packs'], 'spct' => (float) $cfg['secret']['pct'], 'ship' => in_array( 'ship', $box['rw'], true ), 'draft' => $draft, 'cv' => (int) Config::version() );
		$added = 0;
		foreach ( $counts as $pid => $q ) {
			$p = $allowed[ $pid ]; $src = self::line_src( $p ); $vid = $src->is_type( 'variation' ) ? $src->get_id() : 0;
			$key = WC()->cart->add_to_cart( (int) $pid, $q, $vid, self::variation_attrs( $src ), array( self::META => array_merge( $meta, array( 'role' => 'base', 'key' => $p['key'] ) ) ) );
			if ( $key ) { $added++; }
		}
		if ( ! $added ) { self::$mutating = false; WC()->cart->empty_cart(); return $err( 'add' ); }
		if ( in_array( 'book', $box['rw'], true ) && ! empty( $cfg['rewards']['book']['product_id'] ) ) {
			$bp = wc_get_product( (int) $cfg['rewards']['book']['product_id'] );
			if ( $bp && $bp->is_purchasable() ) { WC()->cart->add_to_cart( $bp->get_id(), 1, 0, array(), array( self::META => array_merge( $meta, array( 'role' => 'book', 'key' => 'book' ) ) ) ); }
		}
		foreach ( $secret_ok as $pid ) {
			$p = $allowed[ $pid ]; $src = self::line_src( $p ); $vid = $src->is_type( 'variation' ) ? $src->get_id() : 0;
			WC()->cart->add_to_cart( (int) $pid, 1, $vid, self::variation_attrs( $src ), array( self::META => array_merge( $meta, array( 'role' => 'secret', 'key' => $p['key'] ) ) ) );
		}
		/* нищо чуждо в промо количката (напр. подарък, инжектиран от друг плъгин) */
		foreach ( WC()->cart->get_cart() as $k => $it ) { if ( empty( $it[ self::META ] ) ) { WC()->cart->remove_cart_item( $k ); } }
		self::$mutating = false;
		$ctx['group'] = $group; self::set_ctx( $ctx ); self::choose_gateway( $cfg, $pay );
		WC()->cart->calculate_totals(); WC()->cart->set_session();
		do_action( 'ansa_promo_added', $group, $box, $ctx, $cfg );
		return array( 'ok' => true, 'idempotent' => false, 'group' => $group, 'box' => $box_id, 'total' => WC()->cart->get_total( 'edit' ), 'checkoutUrl' => self::checkout_url() );
	}
	private static function choose_gateway( $cfg, $pay ) {
		$gw = 'cod' === $pay ? ( $cfg['checkout']['cod_gateway'] ?: 'cod' ) : $cfg['checkout']['card_gateway'];
		if ( $gw && WC()->session ) { WC()->session->set( 'chosen_payment_method', $gw ); }
	}

	/* ═══ ЧУЖДИ РЕДОВЕ (v1.5.3) ═══ */
	/** FunnelKit Cart Rewards: нито една награда по праг, докато в количката има промо група. true = наградата се дава. */
	public static function no_fk_rewards( $status, $reward = null ) {
		if ( ! self::group() ) { return $status; }
		return apply_filters( 'ansa_promo_fk_reward', false, $status, $reward );
	}
	/**
	 * Ред без наша META, добавен автоматично от друг плъгин (подарък на FunnelKit Cart Rewards, на Shrine, …), не остава в промо
	 * количката. Разпознава се по cart item data ключовете (fkcart*, *gift*, *reward*); filter ansa_promo_strip_foreign( bool, $key, $item, $g )
	 * позволява и друго правило (напр. всеки чужд ред). Ръчно добавени продукти с цена (order bump) не се пипат.
	 */
	public static function strip_foreign( $cart = null ) {
		if ( self::$mutating ) { return; }
		$cart = $cart instanceof \WC_Cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null ); if ( ! $cart ) { return; }
		$g = self::group( $cart ); if ( ! $g || empty( $g['foreign'] ) ) { return; }
		$std = array( 'key', 'product_id', 'variation_id', 'variation', 'quantity', 'data', 'data_hash', 'line_tax_data', 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_tax' );
		$remove = array();
		foreach ( $g['foreign'] as $key ) {
			$item = $cart->get_cart_item( $key ); if ( ! $item ) { continue; }
			$extra = array_diff( array_keys( $item ), $std );
			$auto  = (bool) preg_grep( '/fkcart|gift|reward/i', $extra );
			if ( apply_filters( 'ansa_promo_strip_foreign', $auto, $key, $item, $g ) ) { $remove[] = $key; }
		}
		if ( ! $remove ) { return; }
		self::$mutating = true;
		foreach ( $remove as $key ) { $cart->remove_cart_item( $key ); }
		self::$mutating = false;
	}

	/* ═══ ЦЕНИ ═══ */
	public static function apply_pricing( $cart ) {
		if ( self::$mutating ) { return; }
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
		self::strip_foreign( $cart );
		$g = self::group( $cart ); if ( ! $g ) { return; }
		$valid = self::group_valid( $g );
		$pct = $valid ? $g['pct'] : 0; $spct = $valid ? $g['spct'] : 0;
		foreach ( $g['base'] as $key => $item ) { $item['data']->set_price( round( self::reg_price( $item['data'] ) * ( 1 - $pct / 100 ), 2 ) ); }
		foreach ( $g['secret'] as $key => $item ) { $item['data']->set_price( round( self::reg_price( $item['data'] ) * ( 1 - $spct / 100 ), 2 ) ); }
		if ( $g['book'] ) {
			$items = $cart->get_cart();
			if ( $valid && isset( $items[ $g['book'] ] ) ) { $items[ $g['book'] ]['data']->set_price( 0 ); }
			elseif ( ! $valid ) { self::$mutating = true; $cart->remove_cart_item( $g['book'] ); self::$mutating = false; }
		}
		$ctx = self::ctx(); if ( isset( $ctx['group'] ) && ( $ctx['invalid'] ?? null ) !== ! $valid ) { $ctx['invalid'] = ! $valid; self::set_ctx( $ctx ); }
	}

	/* ═══ ЗАКЛЮЧВАНЕ ═══ */
	public static function lock_qty( $html, $key, $item ) { if ( ! empty( $item[ self::META ] ) && 'secret' !== $item[ self::META ]['role'] ) { return '<span class="ansa-promo-qty">' . (int) $item['quantity'] . '</span>'; } return $html; }
	public static function lock_remove( $html, $key ) { $item = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart_item( $key ) : null; if ( $item && ! empty( $item[ self::META ] ) && 'secret' !== $item[ self::META ]['role'] ) { return ''; } return $html; }
	public static function cart_label( $name, $item, $key ) {
		if ( empty( $item[ self::META ] ) ) { return $name; }
		$cfg = self::cfg(); $box = Config::box( $cfg, $item[ self::META ]['box'] ); $role = $item[ self::META ]['role'];
		$v = array( 'box_ic' => $box ? $box['ic'] : '', 'box' => $box ? $box['name'] : '', 'pct' => self::fmt( $item[ self::META ]['pct'] ?? ( $box ? $box['pct'] : 0 ) ), 'secret_pct' => self::fmt( $item[ self::META ]['spct'] ?? $cfg['secret']['pct'] ) );
		$tag = self::copy( 'book' === $role ? 'co.tag.book' : ( 'secret' === $role ? 'co.tag.secret' : 'co.tag.base' ), $v );
		return $name . '<br><small class="ansa-promo-tag">' . wp_kses_post( $tag ) . '</small>';
	}
	public static function cart_price_html( $html, $item, $key ) {
		if ( empty( $item[ self::META ] ) || 'book' !== $item[ self::META ]['role'] ) { return $html; }
		return '<span class="ansa-promo-free">' . esc_html( self::copy( 'co.free' ) ) . '</span>';
	}
	public static function check_cart() {
		$g = self::group(); if ( ! $g ) { return; }
		$cfg = self::cfg();
		if ( ! self::group_valid( $g ) ) { wc_add_notice( wp_kses_post( self::copy( 'co.broken', array( 'url' => esc_url( self::promo_url( $cfg ) ) ) ) ), 'error' ); }
		if ( ! $g['draft'] && ! current_user_can( Plugin::CAP ) && ! Config::is_live( $cfg ) ) { wc_add_notice( esc_html( self::copy( 'co.expired' ) ), 'error' ); }
	}

	/* ═══ ДОСТАВКА · КУПОНИ · ИМЕЙЛ ═══ */
	public static function free_shipping( $rates, $package ) {
		$g = self::group(); if ( ! $g || ! $g['ship'] || ! self::group_valid( $g ) ) { return $rates; }
		foreach ( $rates as $id => $rate ) {
			$rate->set_cost( 0 ); $taxes = $rate->get_taxes(); if ( is_array( $taxes ) ) { $rate->set_taxes( array_map( '__return_zero', $taxes ) ); }
			$rate->set_label( $rate->get_label() . ' · ' . self::copy( 'co.ship.free' ) );
		}
		return $rates;
	}
	public static function block_coupon( $valid, $coupon ) {
		$g = self::group(); if ( ! $g || ! $valid ) { return $valid; }
		$cfg = self::cfg(); if ( empty( $cfg['checkout']['block_coupons'] ) ) { return $valid; }
		if ( apply_filters( 'ansa_promo_allow_coupon', false, $coupon, $g ) ) { return $valid; }
		throw new \Exception( self::copy( 'co.coupon' ), 100 );
	}
	public static function prefill_email( $value, $input ) { if ( 'billing_email' === $input && ! $value ) { $ctx = self::ctx(); if ( ! empty( $ctx['email'] ) ) { return $ctx['email']; } } return $value; }

	/* ═══ ЧЕКАУТ ═══ */
	public static function vars( $cfg, $box ) {
		$bl = Config::box( $cfg, 'l' ); $bm = Config::box( $cfg, 'm' );
		return array( 'box' => $box['name'], 'box_l' => mb_strtolower( $box['name'] ), 'box_ic' => $box['ic'], 'packs' => $box['packs'], 'opk' => 1 === (int) $box['packs'] ? 'опаковка' : 'опаковки', 'pct' => self::fmt( $box['pct'] ), 'tickets' => $box['tickets'], 'shans' => 1 === (int) $box['tickets'] ? 'шанс' : 'шанса', 'bileta' => 1 === (int) $box['tickets'] ? 'билет' : 'билета',
			'yacht' => number_format( (float) $cfg['rewards']['yacht']['value'], 0, '', ' ' ), 'ship' => wc_price( $cfg['ship']['paid'] ), 'book_value' => self::fmt( $cfg['rewards']['book']['value'] ), 'cosm_value' => self::fmt( $cfg['rewards']['cosm']['value'] ), 'cosm_pay' => self::fmt( $box['cosm_pay'] ), 'cosm_pay_m' => self::fmt( $bm ? $bm['cosm_pay'] : null ), 'cosm_pay_l' => self::fmt( $bl ? $bl['cosm_pay'] : null ), 'cosm_min' => self::fmt( $bl ? $bl['cosm_pay'] : null ),
			'tickets_s' => 1, 'tickets_m' => $bm ? $bm['tickets'] : 3, 'tickets_l' => $bl ? $bl['tickets'] : 5, 'secret_pct' => self::fmt( $cfg['secret']['pct'] ), 'name' => $cfg['name'], 'deadline' => $cfg['deadline'] ? date_i18n( 'd.m.Y', Config::deadline_ts( $cfg ) ?: time() ) : '', 'max_pct' => self::fmt( Config::max_pct( $cfg ) ), 'url' => esc_url( self::promo_url( $cfg ) ) );
	}
	private static function rewards_list( $cfg, $box, $v ) {
		$copy = Copy::effective( $cfg['copy'] ); $rows = '';
		foreach ( $box['rw'] as $r ) { $rows .= '<li><i>' . esc_html( $cfg['rewards']['icons'][ $r ] ?? '🎁' ) . '</i> ' . wp_kses_post( Copy::render( $copy[ 'rw.' . $r . '.t' ] ?? $r, $v ) ) . '</li>'; }
		return $rows;
	}
	public static function checkout_rewards_row() {
		$g = self::group(); if ( ! $g ) { return; }
		$cfg = self::cfg(); $box = Config::box( $cfg, $g['box'] ); if ( ! $box ) { return; }
		$v = self::vars( $cfg, $box );
		$bonus = Partner::bonus_now( $cfg ); $prow = $bonus > 0 ? '<li><i>🤝</i> ' . wp_kses_post( self::copy( 'co.partner', array_merge( $v, array( 'bonus' => $bonus, 'bonus_bileta' => 1 === $bonus ? 'билет' : 'билета' ) ) ) ) . '</li>' : '';
		echo '<tr class="ansa-promo-co"><td colspan="2"><div class="ansa-promo-co__box"><b>' . wp_kses_post( self::copy( 'co.rewards.h', $v ) ) . '</b><ul>' . self::rewards_list( $cfg, $box, $v ) . $prow . '</ul>' . ( self::group_valid( $g ) ? '' : '<div class="ansa-promo-co__warn">' . wp_kses_post( self::copy( 'co.broken', $v ) ) . '</div>' ) . '</div></td></tr>';
		self::checkout_css();
	}
	public static function checkout_cosm_consent() {
		$g = self::group(); if ( ! $g || ! self::group_valid( $g ) ) { return; }
		$cfg = self::cfg(); $box = Config::box( $cfg, $g['box'] ); if ( ! $box || null === $box['cosm_pay'] ) { return; }
		$v = self::vars( $cfg, $box ); $ctx = self::ctx();
		/* v1.5.0: картичка — чекбокс | икона | текст, подравнено вляво; !important, защото FunnelKit/reskin-ът центрират и презаписват label-ите */
		echo '<div class="ansa-promo-cosm-wrap"><input type="hidden" name="ansa_promo_cosm_seen" value="1"><label class="ansa-promo-cosm"><span class="ansa-promo-cosm__check"><input type="checkbox" name="ansa_promo_cosm" value="1"' . ( isset( $ctx['cosm'] ) && ! $ctx['cosm'] ? '' : ' checked' ) . '></span><span class="ansa-promo-cosm__ic">' . esc_html( self::copy( 'co.cosm.ic' ) ) . '</span><span class="ansa-promo-cosm__txt"><span class="ansa-promo-cosm__h">' . wp_kses_post( self::copy( 'co.cosm.label', $v ) ) . '</span><small>' . wp_kses_post( self::copy( 'co.cosm.sub', $v ) ) . '</small></span></label></div>';
		self::checkout_css();
	}
	private static function checkout_css() { static $d = false; if ( $d ) { return; } $d = true; echo '<style id="ansa-promo-co-css">'
		/* редът с наградите в обобщението на поръчката + благодаря-блокът */
		. '.ansa-promo-co__box{background:linear-gradient(135deg,#fff8f1,#fff3f6)!important;border:1.5px solid #f3cdb8!important;border-radius:16px!important;padding:14px 16px!important;margin:6px 0!important;font-family:inherit;text-align:left!important;color:#1a1a2e;box-shadow:0 6px 18px rgba(232,114,42,.07)}'
		. '.ansa-promo-co__box>b{display:block!important;font-weight:900!important;font-size:15px!important;line-height:1.3;margin:0 0 8px!important;text-align:left!important}'
		. '.ansa-promo-co__box ul{list-style:none!important;margin:0!important;padding:0!important}.ansa-promo-co__box li{display:flex!important;align-items:center;gap:10px;font-size:13.5px!important;font-weight:700!important;line-height:1.35;padding:5px 0!important;margin:0!important;text-align:left!important;border:0!important}'
		. '.ansa-promo-co__box li i{flex:none;font-style:normal;width:30px;height:30px;border-radius:10px;background:#fff;border:1px solid #f3cdb8;display:inline-flex;align-items:center;justify-content:center;font-size:16px;line-height:1}'
		. '.ansa-promo-co__warn{margin-top:8px;color:#b91c1c;font-weight:800;font-size:13px}.ansa-promo-co td{padding:0!important}'
		/* ☑ сетът — картичка: чекбокс | икона | текст */
		. '.ansa-promo-cosm-wrap{margin:14px 0 18px!important;clear:both}'
		. 'label.ansa-promo-cosm{display:flex!important;align-items:flex-start!important;gap:12px!important;width:100%!important;box-sizing:border-box!important;margin:0!important;padding:14px 16px!important;background:linear-gradient(135deg,#fff7f3,#fff1f6)!important;border:1.5px solid #f6c9d6!important;border-radius:16px!important;text-align:left!important;cursor:pointer;font-family:inherit;box-shadow:0 6px 18px rgba(232,114,42,.08);transition:border-color .15s,box-shadow .15s;float:none!important}'
		. 'label.ansa-promo-cosm:has(input:checked){border-color:#e8722a!important;box-shadow:0 8px 24px rgba(232,114,42,.16)}'
		. '.ansa-promo-cosm__check{flex:none!important;display:flex!important;align-items:center;height:40px}'
		. '.ansa-promo-cosm__check input{width:22px!important;height:22px!important;min-width:22px;margin:0!important;accent-color:#e8722a;cursor:pointer;-webkit-appearance:checkbox!important;appearance:auto!important;position:static!important;opacity:1!important;display:inline-block!important;visibility:visible!important}'
		. '.ansa-promo-cosm__ic{flex:none!important;width:40px;height:40px;border-radius:12px;background:#fff;border:1px solid #f6c9d6;display:flex!important;align-items:center;justify-content:center;font-size:22px;line-height:1}'
		. '.ansa-promo-cosm__txt{flex:1 1 auto!important;min-width:0;text-align:left!important;display:block!important}'
		. '.ansa-promo-cosm__h{display:block!important;font-weight:800!important;font-size:15px!important;line-height:1.35!important;color:#1a1a2e!important;margin:2px 0 4px!important;text-align:left!important}'
		/* v1.5.3: „за <s>€129</s> <b>€1</b>“ — задраскана стойност в сиво, цената голяма и оранжева */
		. '.ansa-promo-cosm__h s{color:#9a8a82!important;font-weight:600!important;text-decoration:line-through!important;white-space:nowrap}'
		. '.ansa-promo-cosm__h b{color:#e8722a!important;font-weight:900!important;font-size:18px!important;white-space:nowrap}'
		. '.ansa-promo-cosm__txt small{display:block!important;font-size:12.5px!important;line-height:1.45!important;color:#7a6a62!important;font-weight:600!important;text-align:left!important}'
		. '@media (max-width:480px){.ansa-promo-cosm__ic{display:none!important}label.ansa-promo-cosm{padding:12px 14px!important;gap:10px!important}}'
		/* етикети в количката, безплатна книга, заключено количество, благодаря */
		. '.ansa-promo-tag{color:#c2410c;font-weight:800}.ansa-promo-free{font-weight:900;color:#15803d}.ansa-promo-qty{font-weight:900}.ansa-promo-ty{margin:16px 0!important}.ansa-promo-ty p{margin:6px 0 8px!important;font-size:14px!important;text-align:left!important}'
		. '</style>'; }

	/* ═══ ПОРЪЧКА ═══ */
	/** Метаданните за поръчката от текущата количка (null, ако няма промо група). */
	public static function build_meta( $cosm_posted = null ) {
		$g = self::group(); if ( ! $g ) { return null; }
		$cfg = self::cfg(); $box = Config::box( $cfg, $g['box'] ); if ( ! $box ) { return null; }
		$ctx = self::ctx(); $valid = self::group_valid( $g );
		if ( null !== $box['cosm_pay'] ) { $cosm_on = null !== $cosm_posted ? (bool) $cosm_posted : ( isset( $ctx['cosm'] ) ? (bool) $ctx['cosm'] : true ); } else { $cosm_on = false; }
		$meta = array(
			'promo' => $cfg['id'], 'group' => $g['group'], 'box' => $box['id'], 'packs' => $g['packs'], 'pct' => $g['pct'], 'valid' => $valid,
			'tickets' => array( 'allowance' => $valid ? (int) $box['tickets'] : 0, 'partner' => false, 'issued' => array() ),
			'book' => in_array( 'book', $box['rw'], true ) ? array( 'product_id' => (int) $cfg['rewards']['book']['product_id'] ) : null,
			'cosm' => null !== $box['cosm_pay'] ? array( 'pay' => (float) $box['cosm_pay'], 'value' => (float) $cfg['rewards']['cosm']['value'], 'status' => $cosm_on ? 'reserved' : 'declined', 'consent' => $cosm_on ) : null,
			'ship_free' => $g['ship'], 'lead_email' => (string) ( $ctx['email'] ?? '' ), 'lead_id' => (int) ( $ctx['lead'] ?? 0 ), 'utm' => (string) ( $ctx['utm'] ?? '' ), 'pay' => (string) ( $ctx['pay'] ?? '' ), 'draft' => ! empty( $g['draft'] ), 'ver' => ANSA_PROMO_VER, 'config_version' => Config::version(),
			'secret' => array_values( array_map( function ( $it ) { return (int) $it['product_id']; }, $g['secret'] ) ),
		);
		return apply_filters( 'ansa_promo_order_meta', $meta, $g, $cfg );
	}
	private static function write_meta( $order, $meta ) {
		$order->update_meta_data( '_ansa_promo', $meta );
		$order->update_meta_data( '_ansa_promo_id', $meta['promo'] );
		$order->update_meta_data( '_ansa_promo_group', $meta['group'] );
		$order->update_meta_data( '_ansa_promo_box', $meta['box'] );
		$order->update_meta_data( '_ansa_promo_valid', $meta['valid'] ? '1' : '0' );
		$order->update_meta_data( '_ansa_promo_tickets', (int) $meta['tickets']['allowance'] );
		$order->update_meta_data( '_ansa_promo_cosm', $meta['cosm'] ? '1' : '0' );
		$order->update_meta_data( '_ansa_promo_cosm_status', $meta['cosm'] ? $meta['cosm']['status'] : '' );
		$order->update_meta_data( '_ansa_promo_utm', $meta['utm'] );
		$order->update_meta_data( '_ansa_promo_lead', (int) $meta['lead_id'] );
	}
	public static function order_meta( $order, $data ) {
		/* небутнат чекбокс не се POST-ва → hidden „seen“ казва, че полето е било на екрана */
		$posted = isset( $_POST['ansa_promo_cosm_seen'] ) ? ( isset( $_POST['ansa_promo_cosm'] ) && '1' === (string) $_POST['ansa_promo_cosm'] ) : null;
		$meta = self::build_meta( $posted ); if ( ! $meta ) { return; }
		self::write_meta( $order, $meta );
	}
	public static function order_meta_store_api( $order, $request ) { $meta = self::build_meta( null ); if ( $meta ) { self::write_meta( $order, $meta ); } }
	public static function line_meta( $item, $key, $values, $order ) {
		if ( empty( $values[ self::META ] ) ) { return; }
		$item->add_meta_data( '_ansa_promo_role', $values[ self::META ]['role'], true );
		$item->add_meta_data( '_ansa_promo_box', $values[ self::META ]['box'], true );
		$item->add_meta_data( '_ansa_promo_pct', 'secret' === $values[ self::META ]['role'] ? $values[ self::META ]['spct'] : ( 'book' === $values[ self::META ]['role'] ? 100 : $values[ self::META ]['pct'] ), true );
	}
	/** След като поръчката има ID: lead → converted_order_id, бележка при непълна кутия. */
	public static function order_created( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) { $order = wc_get_order( (int) $order ); if ( ! $order ) { return; } }
		$m = $order->get_meta( '_ansa_promo' ); if ( ! is_array( $m ) ) { return; }
		if ( ! empty( $m['lead_id'] ) ) { Leads::convert( (int) $m['lead_id'], $order->get_id() ); }
		if ( empty( $m['valid'] ) ) { $order->add_order_note( 'ansa™ Промо: кутията е била непълна при поръчката — цените са регулярни, без награди.' ); }
		else { $order->add_order_note( sprintf( 'ansa™ Промо: %s кутия · −%s%% · %d билет(а)%s%s', $m['box'], self::fmt( $m['pct'] ), (int) $m['tickets']['allowance'], $m['cosm'] ? ' · сет: ' . $m['cosm']['status'] : '', $m['ship_free'] ? ' · безплатна доставка' : '' ) ); }
		do_action( 'ansa_promo_order_created', $order, $m );
	}
	public static function order_label( $name, $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) { return $name; }
		$role = $item->get_meta( '_ansa_promo_role' ); if ( ! $role ) { return $name; }
		$copy = Copy::effective( Config::get_published()['copy'] );
		$k = 'book' === $role ? 'oi.book' : ( 'secret' === $role ? 'oi.secret' : 'oi.base' );
		return $name . ' <small class="ansa-promo-tag" style="color:#c2410c;font-weight:800">' . esc_html( Copy::render( $copy[ $k ] ?? $k, array( 'pct' => self::fmt( $item->get_meta( '_ansa_promo_pct' ) ) ) ) ) . '</small>';
	}
	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id ); if ( ! $order ) { return; }
		$m = $order->get_meta( '_ansa_promo' ); if ( ! is_array( $m ) || empty( $m['valid'] ) ) { return; }
		$cfg = ! empty( $m['draft'] ) ? Config::get_draft() : Config::get_published(); $box = Config::box( $cfg, $m['box'] ); if ( ! $box ) { return; }
		$v = self::vars( $cfg, $box ); $v['tickets'] = (int) $m['tickets']['allowance'] + ( ! empty( $m['tickets']['partner'] ) ? (int) ( $m['tickets']['bonus'] ?? 0 ) : 0 ); $v['bileta'] = 1 === $v['tickets'] ? 'билет' : 'билета';
		echo '<div class="ansa-promo-co__box ansa-promo-ty"><b>' . wp_kses_post( self::copy( 'ty.h', $v ) ) . '</b><p>' . wp_kses_post( self::copy( 'ty.p', $v ) ) . '</p><ul>' . self::rewards_list( $cfg, $box, $v ) . '</ul>' . ( $m['cosm'] && 'reserved' === $m['cosm']['status'] ? '<p>' . wp_kses_post( self::copy( 'ty.cosm', $v ) ) . '</p>' : '' ) . '</div>';
		self::checkout_css();
	}
}
