<?php
/**
 * ansa™ Промо — Черен петък 2026: страницата {сайт}/cheren-petak-2026 (на корена, както /kozmetichen-set-promo).
 *
 * Един адрес за всички източници: вариантът (студен трафик / партньорски линк / ранен достъп / публичен) се решава В БРАУЗЪРА от адреса и
 * бисквитките (bf.js), а фазата идва от сървъра — затова публикуваната страница е една и съща за всички и може да се кешира (FlyingPress).
 * При зареждане bf.js вика ajax ansa_bf_runtime: свежата фаза (сменя се по часовник), цените, името на партньора при ?ref=КОД (ansa Partner),
 * проверката на ?ea=ТОКЕН (ранният достъп, бисквитка), и контекстът на сесията (ваучерът от портфейла, подаръкът от партньора).
 *
 * Маркери в адреса: ?ref=КОД (ansa Partner) → партньорски вариант · ?ea=ТОКЕН (от имейла на списъка) → ранен достъп · ?utm_* /
 * fbclid / ?src=cold → студен (подготовка). Чернова: ?ansa_bf=draft (manage_woocommerce) или ?ansa_bf_pw=ПАРОЛА (бисквитка 30 дни).
 *
 * @package AnsaPromo
 * @since 1.45.0
 */
namespace AnsaPromo\BF;

use AnsaPromo\Catalog;
use AnsaPromo\Insights;
use AnsaPromo\Leads as SeasonLeads;

defined( 'ABSPATH' ) || exit;

final class Page {

	const QV        = 'ansa_promo_bf';
	const NONCE     = 'ansa_bf_front';
	const PW_COOKIE = 'ansa_bf_pw';
	const EA_COOKIE = 'ansa_bf_ea';
	const RUNTIME_TTL = 5 * MINUTE_IN_SECONDS;
	private static $pw_ok = null; private static $pw_err = false;

	public static function register() {
		add_action( 'parse_request', array( __CLASS__, 'route' ) );
		add_filter( 'pre_handle_404', array( __CLASS__, 'no_404' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'render_route' ), 0 );
		add_action( 'wp_ajax_ansa_bf_runtime', array( __CLASS__, 'ajax_runtime' ) );
		add_action( 'wp_ajax_nopriv_ansa_bf_runtime', array( __CLASS__, 'ajax_runtime' ) );
		/* за имейлите във FunnelKit: [ansa_bf_early_url] = личният линк с токена, [ansa_bf_url] = публичният адрес; и двата приемат utm_source/utm_medium/utm_campaign/utm_content */
		add_shortcode( 'ansa_bf_early_url', array( __CLASS__, 'sc_early_url' ) );
		add_shortcode( 'ansa_bf_url', array( __CLASS__, 'sc_url' ) );
		foreach ( array( 'woocommerce_update_product', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_update_product_variation', 'save_post_product' ) as $h ) { add_action( $h, array( __CLASS__, 'purge_runtime' ) ); }
	}
	public static function purge_runtime() { delete_transient( Config::runtime_key() ); }
	public static function sc_early_url( $atts ) { $a = is_array( $atts ) ? $atts : array(); return Boost::merge( 'bf_early_url', array( 'utm_content' => (string) ( $a['utm_content'] ?? 'bf_early' ) ) ); } /* 1.46.0: личен, когато FKA знае контакта */
	public static function sc_url( $atts ) { return esc_url_raw( self::sc_utm( self::url(), $atts, 'bf_public' ) ); }
	private static function sc_utm( $url, $atts, $content ) {
		$a = shortcode_atts( array( 'utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'bf2026', 'utm_content' => $content ), is_array( $atts ) ? $atts : array() );
		$q = array(); foreach ( $a as $k => $v ) { $v = sanitize_text_field( (string) $v ); if ( '' !== $v ) { $q[ $k ] = $v; } }
		return $q ? add_query_arg( $q, $url ) : $url;
	}

	/* ── адрес ── */
	public static function path( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return trim( (string) $cfg['slug'], '/' ) ?: 'cheren-petak-2026'; }
	public static function url( $cfg = null ) { return apply_filters( 'ansa_promo_bf_url', home_url( '/' . self::path( $cfg ) . '/' ), $cfg ); }
	/** Адресите по източник — за рекламите, имейлите и сторитата (показват се в админа). */
	public static function urls( $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $u = self::url( $cfg );
		return array(
			'cold'   => add_query_arg( array( 'utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'bf2026-signup', 'src' => 'cold' ), $u ),
			'early'  => Config::early_url( $cfg ),
			'email'  => add_query_arg( array( 'utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'bf2026' ), $u ),
			'public' => add_query_arg( array( 'utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'bf2026-public' ), $u ),
			'aff'    => add_query_arg( 'ref', 'КОД', $u ),
		);
	}

	public static function route( $wp ) {
		$cfg = Config::get_published(); $draft = Config::get_draft();
		$req = trim( (string) ( $wp->request ?? '' ), '/' );
		if ( '' === $req ) { $uri = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); $home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ); if ( $home && 0 === strpos( $uri, $home ) ) { $uri = substr( $uri, strlen( $home ) ); } $req = trim( (string) $uri, '/' ); }
		if ( $req !== self::path( $cfg ) && $req !== self::path( $draft ) && ! isset( $_GET['ansa_bf_page'] ) ) { return; }
		$wp->query_vars = array( self::QV => 1 );
	}
	public static function is_route() { global $wp; return ! empty( $wp->query_vars[ self::QV ] ); }
	public static function no_404( $pre, $q ) { return self::is_route() ? true : $pre; }

	/* ── чернова / преглед с парола ── */
	public static function is_draft_request() { return ( isset( $_GET['ansa_bf'] ) && 'draft' === $_GET['ansa_bf'] && current_user_can( \AnsaPromo\Plugin::CAP ) ) || self::preview_ok() || self::share_ok(); }
	/* ── 1.48.0: тайният линк за екипа — ?ansa_bf_share=<exp>.<hmac>, подписан със salt-а на сайта, важи 1 час (човекът: „secret link за екипа… да се генерира от настройки, и да е валиден 1 час… без да са логнати“).
	   Който го отвори, получава прегледа (чернова + лентата с всички фази и входове) като бисквитка за остатъка от часа; след изтичането — живата страница. ── */
	const SHARE_COOKIE = 'ansa_bf_share'; const SHARE_TTL = HOUR_IN_SECONDS;
	public static function share_sig( $exp ) { return substr( hash_hmac( 'sha256', 'bf-share|' . (int) $exp, wp_salt( 'auth' ) ), 0, 24 ); }
	public static function new_share_link( $cfg = null ) { $exp = time() + self::SHARE_TTL; return array( 'url' => add_query_arg( array( 'ansa_bf_share' => $exp . '.' . self::share_sig( $exp ), 'reset' => '1' ), self::url( $cfg ?: Config::get_published() ) ), 'exp' => $exp ); }
	public static function share_valid( $tok ) { $tok = (string) $tok; if ( ! preg_match( '/^(\d{9,11})\.([a-f0-9]{24})$/', $tok, $m ) ) { return 0; } $exp = (int) $m[1]; if ( $exp < time() ) { return 0; } return hash_equals( self::share_sig( $exp ), $m[2] ) ? $exp : 0; }
	private static $share_ok = null; public static $share_err = false;
	public static function share_ok() {
		if ( null !== self::$share_ok ) { return self::$share_ok; }
		self::$share_ok = false;
		if ( ! empty( $_GET['ansa_bf_share'] ) && self::share_valid( (string) wp_unslash( $_GET['ansa_bf_share'] ) ) ) { self::$share_ok = true; }
		elseif ( ! empty( $_COOKIE[ self::SHARE_COOKIE ] ) && self::share_valid( (string) $_COOKIE[ self::SHARE_COOKIE ] ) ) { self::$share_ok = true; }
		return self::$share_ok;
	}
	public static function preview_token( $pw ) { return hash_hmac( 'sha256', 'bf|' . (string) $pw, wp_salt( 'auth' ) ); }
	public static function preview_ok() {
		if ( null !== self::$pw_ok ) { return self::$pw_ok; }
		self::$pw_ok = false; $pw = (string) ( Config::get_draft()['preview']['password'] ?? '' ); if ( '' === $pw ) { return false; }
		$c = isset( $_COOKIE[ self::PW_COOKIE ] ) ? (string) $_COOKIE[ self::PW_COOKIE ] : '';
		self::$pw_ok = '' !== $c && hash_equals( self::preview_token( $pw ), $c );
		return self::$pw_ok;
	}
	public static function preview_link( $cfg = null ) { $cfg = $cfg ?: Config::get_draft(); $pw = (string) ( $cfg['preview']['password'] ?? '' ); return '' !== $pw ? add_query_arg( 'ansa_bf_pw', rawurlencode( $pw ), self::url( $cfg ) ) : add_query_arg( 'ansa_bf', 'draft', self::url( $cfg ) ); }
	public static function cfg() { return self::is_draft_request() ? Config::get_draft() : Config::get_published(); }

	/* ── рендер ── */
	public static function render_route() {
		if ( ! self::is_route() ) { return; }
		/* ?ansa_bf_pw=ПАРОЛА → бисквитка + redirect без параметъра */
		$given = isset( $_GET['ansa_bf_pw'] ) ? (string) wp_unslash( $_GET['ansa_bf_pw'] ) : null; $pw = (string) ( Config::get_draft()['preview']['password'] ?? '' );
		if ( null !== $given && '' !== $pw ) {
			if ( hash_equals( $pw, $given ) ) { setcookie( self::PW_COOKIE, self::preview_token( $given ), time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true ); wp_safe_redirect( remove_query_arg( 'ansa_bf_pw' ) ); exit; }
			self::$pw_err = true;
		}
		/* 1.48.0: тайният линк → бисквитка до изтичането му + redirect без параметъра (адресът в лентата остава чист; лентата на прегледа носи ?ansa_bf=draft, който тук минава през бисквитката) */
		if ( ! empty( $_GET['ansa_bf_share'] ) ) { $tok = (string) wp_unslash( $_GET['ansa_bf_share'] ); $exp = self::share_valid( $tok ); if ( $exp ) { setcookie( self::SHARE_COOKIE, $tok, $exp, '/', '', is_ssl(), true ); $_COOKIE[ self::SHARE_COOKIE ] = $tok; wp_safe_redirect( add_query_arg( 'ansa_bf', 'draft', remove_query_arg( 'ansa_bf_share' ) ) ); exit; } self::$share_err = true; }
		$draft = self::is_draft_request(); $cfg = $draft ? Config::get_draft() : Config::get_published();
		status_header( 200 ); header( 'Content-Type: text/html; charset=utf-8' );
		if ( $draft || ! empty( $_GET['ea'] ) || ! empty( $_GET['ref'] ) ) { nocache_headers(); if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } } /* маркерите не бива да влизат в кеша като „страница“ */
		if ( ! $draft && empty( $cfg['enabled'] ) ) { header( 'X-Robots-Tag: noindex' ); nocache_headers(); echo self::soon_html( $cfg ); exit; }
		if ( $draft ) { header( 'X-Robots-Tag: noindex' ); }
		/* ?ea=ТОКЕН: бисквитката за ранния достъп се слага тук (сървърно, за всеки случай) и от bf.js */
		if ( ! empty( $_GET['ea'] ) && self::ea_valid( (string) wp_unslash( $_GET['ea'] ), $cfg ) ) { setcookie( self::EA_COOKIE, (string) $cfg['early']['token'], time() + (int) $cfg['early']['cookie_days'] * DAY_IN_SECONDS, '/', '', is_ssl(), false ); }
		echo self::page_html( $cfg, $draft ); exit;
	}
	public static function ea_valid( $token, $cfg = null ) { $cfg = $cfg ?: Config::get_published(); $t = (string) ( $cfg['early']['token'] ?? '' ); return '' !== $t && hash_equals( $t, preg_replace( '/[^a-z0-9]/i', '', (string) $token ) ); }
	/** Има ли посетителят достъп до ранния достъп (бисквитка от линка на имейла / контекст). */
	public static function early_ok( $cfg = null ) {
		$cfg = $cfg ?: Config::get_published();
		if ( ! empty( $_GET['ea'] ) && self::ea_valid( (string) wp_unslash( $_GET['ea'] ), $cfg ) ) { return true; }
		if ( ! empty( $_COOKIE[ self::EA_COOKIE ] ) && self::ea_valid( (string) $_COOKIE[ self::EA_COOKIE ], $cfg ) ) { return true; }
		$ctx = Cart::ctx(); return ! empty( $ctx['early'] );
	}

	/** Продуктите с живи WC данни (кеширано за публикуваното). */
	public static function products( $cfg, $draft = false ) {
		$key = Config::runtime_key(); $prods = ( ! $draft ) ? get_transient( $key ) : false;
		if ( is_array( $prods ) ) { return $prods; }
		$prods = array();
		foreach ( (array) $cfg['products'] as $p ) {
			if ( empty( $p['enabled'] ) ) { continue; }
			$pid = '' !== $p['sku'] ? ( Catalog::find_by_sku( $p['sku'] ) ?: (int) $p['product_id'] ) : (int) $p['product_id'];
			$wc = Catalog::wc_line( $pid ); if ( ! $wc['ok'] ) { continue; }
			$img = ''; $v = (string) $p['img']; if ( ctype_digit( $v ) && (int) $v > 0 ) { $img = (string) wp_get_attachment_image_url( (int) $v, 'medium' ); } elseif ( '' !== $v ) { $img = $v; }
			if ( '' === $img ) { $img = $wc['img']; }
			list( $wn, $wds, $wdesc ) = \AnsaPromo\Frontend::wc_texts( $wc );
			$prods[ $p['key'] ] = array(
				'key' => $p['key'], 'id' => (int) ( $wc['product_id'] ?: $pid ), 'vid' => (int) $wc['variation_id'],
				'name' => '' !== $p['name'] ? $p['name'] : ( $wn ?: $wc['wc_name'] ), 'sub' => '' !== $p['sub'] ? $p['sub'] : $wds, 'cat' => $p['cat'],
				'desc' => '' !== $p['desc'] ? $p['desc'] : $wdesc, 'ing' => $p['ing'], 'how' => $p['how'], 'who' => $p['who'],
				'img' => $img, 'price' => (float) $wc['price'], 'stock' => (bool) $wc['stock'], 'giftOnly' => ! empty( $p['gift_only'] ), 'isNew' => ! empty( $p['is_new'] ), 'fav' => ! empty( $p['fav'] ),
			);
		}
		if ( ! $draft ) { set_transient( $key, $prods, self::RUNTIME_TTL ); }
		return $prods;
	}
	/** Маската (CollaMask) като ред: [id, vid, name, price, img] или null. */
	public static function mask( $cfg ) {
		$pid = '' !== (string) $cfg['aff']['mask_sku'] ? ( Catalog::find_by_sku( $cfg['aff']['mask_sku'] ) ?: (int) $cfg['aff']['mask_product_id'] ) : (int) $cfg['aff']['mask_product_id'];
		if ( ! $pid ) { return null; } $wc = Catalog::wc_line( $pid ); if ( ! $wc['ok'] ) { return null; }
		return array( 'id' => (int) ( $wc['product_id'] ?: $pid ), 'vid' => (int) $wc['variation_id'], 'name' => $wc['wc_name'], 'price' => (float) $wc['price'], 'img' => $wc['img'] ?: ANSA_PROMO_URL . 'assets/img/bf/mask.jpg', 'stock' => (bool) $wc['stock'] );
	}
	/** Партньорът по ref код (ansa Partner): [code, name] или null; кеш 10 мин. */
	public static function partner( $code ) {
		$code = strtoupper( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $code ) ); if ( '' === $code ) { return null; }
		$k = 'ansa_bf_partner_' . md5( $code ); $c = get_transient( $k ); if ( is_array( $c ) ) { return $c['ok'] ? $c : null; }
		$out = array( 'ok' => false );
		try {
			if ( class_exists( 'AP_Coupons' ) && class_exists( 'AP_Partners' ) && function_exists( 'wc_format_coupon_code' ) ) {
				$map = \AP_Coupons::get_mapping_by_code( wc_format_coupon_code( $code ) );
				if ( $map && 1 === (int) $map->active ) { $p = \AP_Partners::get( (int) $map->partner_id ); if ( $p && 'active' === (string) $p->status ) { $out = array( 'ok' => true, 'code' => $code, 'name' => (string) current( explode( ' ', trim( (string) $p->display_name ) ) ), 'partner_id' => (int) $p->partner_id ); } }
			}
		} catch ( \Throwable $e ) { $out = array( 'ok' => false ); }
		set_transient( $k, $out, 10 * MINUTE_IN_SECONDS );
		return $out['ok'] ? $out : null;
	}
	public static function logo( $cfg ) { $v = (string) ( $cfg['logo'] ?? '' ); $url = ''; if ( ctype_digit( $v ) && (int) $v > 0 ) { $url = (string) wp_get_attachment_image_url( (int) $v, 'full' ); } elseif ( '' !== $v ) { $url = $v; } return $url ?: ANSA_PROMO_URL . 'assets/img/bf/logo-white.png'; }

	/** Всичко, което bf.js трябва да знае. Никакви цени не идват от клиента. */
	/** 1.46.2: първият активен код на ansa Partner — за бутона „партньор“ в админския преглед. */
	public static function preview_ref() {
		if ( ! class_exists( 'AP_Schema' ) || ! method_exists( 'AP_Schema', 'table' ) ) { return ''; }
		$c = get_transient( 'ansa_bf_preview_ref' ); if ( is_string( $c ) ) { return $c; }
		global $wpdb; $code = '';
		try { $code = (string) $wpdb->get_var( 'SELECT c.coupon_code FROM ' . \AP_Schema::table( 'coupons' ) . ' c JOIN ' . \AP_Schema::table( 'partners' ) . " p ON p.partner_id = c.partner_id WHERE c.active = 1 AND p.status = 'active' ORDER BY c.partner_id ASC LIMIT 1" ); } catch ( \Throwable $e ) { $code = ''; }
		set_transient( 'ansa_bf_preview_ref', $code, HOUR_IN_SECONDS ); return $code;
	}
	/** $phase_force: 1.46.2 — админският преглед на друга фаза (само в чернова; публикуваното не се пипа). */
	public static function runtime( $cfg, $draft = false, $phase_force = null ) {
		$prods = self::products( $cfg, $draft ); $phase = ( $draft && $phase_force && in_array( $phase_force, Config::PHASES, true ) ) ? (string) $phase_force : Config::phase_now( $cfg ); $pt = Config::phase_ts( $cfg ); $mask = self::mask( $cfg );
		$cats = array(); foreach ( $prods as $p ) { if ( '' !== $p['cat'] && ! in_array( $p['cat'], $cats, true ) ) { $cats[] = $p['cat']; } }
		return array(
			'ver' => ANSA_PROMO_VER, 'id' => $cfg['id'], 'name' => $cfg['name'], 'version' => Config::version(),
			'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE ), 'draft' => (bool) $draft, 'enabled' => ! empty( $cfg['enabled'] ),
			'phase' => $phase, 'phase_end' => Config::phase_end( $cfg, $phase ), 'now' => Config::now(), 'live' => Config::is_live( $cfg ) || $draft,
			'preview' => $draft ? array( 'phase' => ( $phase_force && in_array( $phase_force, Config::PHASES, true ) ) ? (string) $phase_force : '', 'ref' => self::preview_ref(), 'real' => Config::phase_now( $cfg ) ) : null,
			'dates' => array( 'early_date' => $pt['early_start'] ? wp_date( 'd.m', $pt['early_start'] ) : '', 'early_time' => $pt['early_start'] ? wp_date( 'H:i', $pt['early_start'] ) : '', 'public_date' => $pt['public_start'] ? wp_date( 'd.m', $pt['public_start'] ) : '', 'public_time' => $pt['public_start'] ? wp_date( 'H:i', $pt['public_start'] ) : '' ),
			'url' => self::url( $cfg ), 'home' => home_url( '/' ), 'shop' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ), 'logo' => self::logo( $cfg ),
			'products' => (object) $prods, 'order' => array_keys( $prods ), 'cats' => $cats,
			'cold' => array( 'enabled' => ! empty( $cfg['cold']['enabled'] ), 'voucher' => (float) $cfg['cold']['voucher'], 'minutes' => (int) $cfg['cold']['minutes'], 'landing_minutes' => (int) $cfg['cold']['landing_minutes'] ),
			'seats' => array( 'enabled' => ! empty( $cfg['seats']['enabled'] ), 'n' => Config::seats_now( $cfg ), 'max' => (int) $cfg['seats']['max'] ),
			'aff' => array( 'enabled' => ! empty( $cfg['aff']['enabled'] ), 'voucher' => (float) $cfg['aff']['voucher'], 'mask' => $mask ? array( 'name' => $mask['name'], 'price' => $mask['price'], 'img' => $mask['img'] ) : null ),
			'ship' => array( 'free_from' => (int) $cfg['ship']['free_from'], 'paid' => (float) $cfg['ship']['paid'] ),
			'cod' => '' !== (string) $cfg['checkout']['cod_gateway'],
			'listv' => array( 'enabled' => ! empty( $cfg['listv']['enabled'] ), 'amount' => (float) $cfg['listv']['amount'] ), /* 1.46.0 */
			'lucky' => Boost::lucky_runtime( $cfg ),
			'assets' => ANSA_PROMO_URL . 'assets/img/bf/',
			'copy' => Copy::effective( $cfg['copy'] ),
		);
	}
	/** Свеж runtime за кеширана страница + контекстът на посетителя (?ref=КОД, ?ea=ТОКЕН, сесията). */
	public static function ajax_runtime() {
		$draft = self::is_draft_request(); $cfg = $draft ? Config::get_draft() : Config::get_published();
		$r = self::runtime( $cfg, $draft, $draft ? sanitize_key( (string) ( $_REQUEST['phase'] ?? '' ) ) : null );
		$ref = isset( $_REQUEST['ref'] ) ? (string) wp_unslash( $_REQUEST['ref'] ) : ''; $ea = isset( $_REQUEST['ea'] ) ? (string) wp_unslash( $_REQUEST['ea'] ) : '';
		$ctx = Cart::ctx();
		if ( '' !== $ref && ! empty( $cfg['aff']['enabled'] ) ) { $p = self::partner( $ref ); if ( $p ) { $r['partner'] = array( 'code' => $p['code'], 'name' => $p['name'] ); Cart::remember_ref( $p['code'] ); } }
		elseif ( ! empty( $ctx['ref'] ) ) { $p = self::partner( (string) $ctx['ref'] ); if ( $p ) { $r['partner'] = array( 'code' => $p['code'], 'name' => $p['name'] ); } }
		/* 1.46.0: личният линк ?u= (подписан имейл) → ваучерът на човека влиза в сесията; и автоматичното издаване при старта на ранния достъп */
		if ( ! empty( $_REQUEST['u'] ) ) { try { Boost::identify( (string) wp_unslash( $_REQUEST['u'] ), $cfg ); } catch ( \Throwable $e ) {} }
		if ( ! $draft ) { try { Boost::maybe_auto(); } catch ( \Throwable $e ) {} }
		$early = false;
		if ( '' !== $ea && self::ea_valid( $ea, $cfg ) ) { $early = true; setcookie( self::EA_COOKIE, (string) $cfg['early']['token'], time() + (int) $cfg['early']['cookie_days'] * DAY_IN_SECONDS, '/', '', is_ssl(), false ); $ctx['early'] = 1; Cart::set_ctx( $ctx ); }
		elseif ( self::early_ok( $cfg ) ) { $early = true; }
		$r['early_ok'] = $early;
		$r['ctx'] = Cart::ctx_public();
		if ( ! empty( $_REQUEST['view'] ) ) { try { Insights::hit( 'view', 'bf', sanitize_key( (string) ( $_REQUEST['variant'] ?? '' ) ) ); } catch ( \Throwable $e ) {} }
		nocache_headers(); wp_send_json_success( $r );
	}

	public static function meta( $cfg ) { $c = Copy::effective( $cfg['copy'] ); return array( 'title' => wp_strip_all_tags( $c['page.title'] ), 'desc' => wp_strip_all_tags( $c['page.desc'] ) ); }
	public static function page_html( $cfg, $draft = false ) {
		$rt = self::runtime( $cfg, $draft, $draft ? sanitize_key( (string) ( $_GET['phase'] ?? '' ) ) : null ); $m = self::meta( $cfg ); $url = self::url( $cfg ); $ver = ANSA_PROMO_VER;
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. ( $draft ? '<meta name="robots" content="noindex,nofollow">' : '' )
			. '<title>' . esc_html( $m['title'] ) . '</title><meta name="description" content="' . esc_attr( $m['desc'] ) . '"><link rel="canonical" href="' . esc_url( $url ) . '">'
			. '<meta property="og:type" content="website"><meta property="og:title" content="' . esc_attr( $m['title'] ) . '"><meta property="og:description" content="' . esc_attr( $m['desc'] ) . '"><meta property="og:url" content="' . esc_url( $url ) . '">'
			. '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap">'
			. '<link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/bf.css?ver=' . $ver ) . '">'
			. '<style>html,body{margin:0;padding:0;background:#faf3ec}</style>' . apply_filters( 'ansa_promo_standalone_head', '', 'bf', $cfg ) . '</head>'
			. '<body class="ansa-bf-page' . ( $draft ? ' ansa-bf-draft' : '' ) . '"><div class="ansa-bf" id="ansaBf" data-ver="' . esc_attr( $ver ) . '"><noscript><p style="padding:24px;text-align:center;font:700 16px/1.4 Nunito,sans-serif">Страницата има нужда от JavaScript.</p></noscript></div>'
			. '<script>window.AnsaBfRuntime=' . wp_json_encode( $rt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script><script src="' . esc_url( ANSA_PROMO_URL . 'assets/bf.js?ver=' . $ver ) . '"></script>' . apply_filters( 'ansa_promo_standalone_footer', '', 'bf', $cfg ) . '</body></html>';
	}
	private static function soon_html( $cfg ) {
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $cfg['name'] ) . '</title>'
			. '<style>html,body{margin:0;background:#0f0f1a;color:#fff;font-family:Nunito,-apple-system,BlinkMacSystemFont,sans-serif}body{min-height:100dvh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}p{font-size:20px;font-weight:800;line-height:1.4}a{color:#f59e0b}</style></head>'
			. '<body><p>' . esc_html( $cfg['name'] ) . ' — скоро.<br><a href="' . esc_url( home_url( '/' ) ) . '" style="font-size:15px">към ansa.bg ›</a></p>' . ( self::$pw_err ? '<p style="font-size:14px;color:#f87171">Грешна парола.</p>' : '' ) . ( self::$share_err ? '<p style="font-size:14px;color:#f87171">Тайният линк е изтекъл или невалиден — поискай нов от админа.</p>' : '' ) . '</body></html>';
	}
}
