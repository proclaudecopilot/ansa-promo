<?php
/**
 * ansa™ Meta — REST POST /wp-json/ansa-meta/v1/event: сървърната половина на събитията от браузъра (PageView, ViewContent,
 * ViewCategory, Search, InitiateCheckout, AddPaymentInfo). Браузърът дава име + event_id (същото, с което е извикан fbq) + данни;
 * сървърът добавя кой е човекът (сесия, логнат, попълнено в чекаута), IP, UA, fbp/fbc (и подновява бисквитките на 90 дни), праща към CAPI.
 * За InitiateCheckout/AddPaymentInfo данните за количката се вземат от сървъра, не от браузъра.
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Rest {
	const NS = 'ansa-meta/v1';
	const ALLOWED = array( 'PageView', 'ViewContent', 'ViewCategory', 'Search', 'InitiateCheckout', 'AddPaymentInfo', 'Contact', 'Subscribe', 'Lead' );

	public static function init() { add_action( 'rest_api_init', array( __CLASS__, 'routes' ) ); }

	public static function routes() {
		register_rest_route( self::NS, '/event', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'event' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/ping', array(
			'methods' => 'GET', 'callback' => function () { return array( 'ok' => true, 'ver' => ANSA_META_VER, 'enabled' => Plugin::enabled() ); }, 'permission_callback' => '__return_true',
		) );
	}

	public static function event( \WP_REST_Request $r ) {
		$res = function ( $ok, $extra = array(), $status = 202 ) { $o = new \WP_REST_Response( array_merge( array( 'ok' => $ok ), $extra ), $status ); $o->header( 'Cache-Control', 'no-store' ); return $o; };
		if ( ! Plugin::enabled() ) return $res( false, array( 'skip' => 'disabled' ), 200 );
		if ( strlen( (string) $r->get_body() ) > 20000 ) return $res( false, array( 'skip' => 'size' ), 413 );
		$in = $r->get_json_params(); if ( ! is_array( $in ) ) $in = (array) $r->get_body_params();
		$name = (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $in['name'] ?? '' ) );
		$allowed = (array) apply_filters( 'ansa_meta_rest_events', self::ALLOWED );
		if ( '' === $name || ! in_array( $name, $allowed, true ) ) return $res( false, array( 'skip' => 'event' ), 400 );
		if ( ! Settings::event_on( $name ) ) return $res( false, array( 'skip' => 'off' ), 200 );
		if ( 'PageView' === $name && ! Settings::get( 'mirror_pageview' ) ) return $res( false, array( 'skip' => 'pageview' ), 200 );

		Identity::allow_cookies( true );
		self::load_wc();
		if ( $why = Identity::excluded() ) return $res( false, array( 'skip' => (string) $why ), 200 );
		$ip = Identity::client_ip();
		if ( self::rate_limited( $ip ) ) return $res( false, array( 'skip' => 'rate' ), 429 );

		$id = (string) ( $in['id'] ?? '' ); if ( ! preg_match( '/^[A-Za-z0-9._\-]{8,64}$/', $id ) ) $id = Capi::uuid();
		$url = esc_url_raw( (string) ( $in['url'] ?? '' ) );
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $url || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $home_host ) $url = Capi::current_url();
		$fbclid = (string) ( $in['fbclid'] ?? '' );
		if ( '' === $fbclid && '' !== $url ) { $qs = (string) wp_parse_url( $url, PHP_URL_QUERY ); if ( $qs ) { parse_str( $qs, $qa ); $fbclid = (string) ( $qa['fbclid'] ?? '' ); } }

		/* попълнените billing полета (чекаут) → в сесията + в това събитие */
		$typed = is_array( $in['user'] ?? null ) ? Identity::remember_plain( $in['user'] ) : array();
		$plain = Identity::current( $fbclid );
		foreach ( $typed as $k => $v ) { if ( '' !== $v ) $plain[ $k ] = $v; }

		if ( in_array( $name, array( 'InitiateCheckout', 'AddPaymentInfo' ), true ) ) {
			$custom = Catalog::cart_data();
			if ( ! $custom ) $custom = self::sanitize_custom( (array) ( $in['data'] ?? array() ) );
		} else {
			$custom = self::sanitize_custom( (array) ( $in['data'] ?? array() ) );
		}
		$eid = Capi::track( $name, $custom, $plain, array( 'event_id' => $id, 'url' => $url, 'source' => 'browser' ) );
		return $res( '' !== $eid, array( 'id' => $eid ) );
	}

	/** Сесията/количката на WC в REST контекст (само ако има WC бисквитка — не създаваме сесии за всеки посетител). */
	private static function load_wc() {
		if ( ! function_exists( 'WC' ) ) return;
		$has = false; foreach ( array_keys( $_COOKIE ) as $k ) { if ( 0 === strpos( (string) $k, 'wp_woocommerce_session_' ) ) { $has = true; break; } }
		if ( ! $has && ! is_user_logged_in() ) return;
		try { if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) wc_load_cart(); } catch ( \Throwable $e ) {}
	}

	private static function rate_limited( $ip ) {
		$k = 'ansa_meta_rl_' . md5( (string) $ip ); $n = (int) get_transient( $k );
		if ( $n > 600 ) return true;
		set_transient( $k, $n + 1, 5 * MINUTE_IN_SECONDS );
		return false;
	}

	/** custom_data от браузъра — само познатите ключове. */
	public static function sanitize_custom( array $d ) {
		$out = array();
		foreach ( array( 'content_ids', 'content_type', 'content_name', 'content_category', 'contents', 'value', 'currency', 'num_items', 'search_string', 'product_group_id', 'status' ) as $k ) {
			if ( isset( $d[ $k ] ) ) $out[ $k ] = $d[ $k ];
		}
		return Capi::clean_custom( $out );
	}
}
