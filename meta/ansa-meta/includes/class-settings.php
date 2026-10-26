<?php
/**
 * ansa™ Meta — настройките (option ansa_meta). Константи в wp-config.php печелят над option-а:
 *   define( 'ANSA_META_PIXEL_ID', '1234567890' );
 *   define( 'ANSA_META_TOKEN', 'EAAB...' );
 *   define( 'ANSA_META_DISABLED', true ); // аварийно изключване
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION = 'ansa_meta';
	private static $all = null;

	public static function defaults() {
		return array(
			'enabled'         => 1,
			'pixel_id'        => '',
			'token'           => '',
			'test_code'       => '',
			'api_version'     => 'v24.0',
			'browser'         => 1,        // Pixel-ът в браузъра се зарежда от нас (препоръчително — общ event_id)
			'id_format'       => 'id',     // id | sku | wc_post_id  (content_ids, да съвпада с каталога в Meta)
			'purchase_on'     => 'order',  // order = при създаване на поръчката · paid = при плащане (наложен платеж = веднага)
			'mirror_pageview' => 1,        // PageView и от сървъра
			'events'          => array(
				'PageView' => 1, 'ViewContent' => 1, 'ViewCategory' => 1, 'Search' => 1, 'AddToCart' => 1,
				'InitiateCheckout' => 1, 'AddPaymentInfo' => 1, 'Purchase' => 1, 'Lead' => 1, 'CompleteRegistration' => 1,
			),
			'exclude_roles'   => array( 'administrator', 'shop_manager' ),
			'internal_ips'    => '',
			'consent_cookie'  => '',
			'consent_value'   => '',
			'bot_filter'      => 1,
			'ldu'             => 0,
			'log_keep'        => 500,
			'debug'           => 0,
		);
	}

	public static function events_list() {
		return array(
			'PageView'             => 'PageView — всяка страница',
			'ViewContent'          => 'ViewContent — продуктова страница',
			'ViewCategory'         => 'ViewCategory — страница на категория (custom събитие, както при Facebook for WooCommerce)',
			'Search'               => 'Search — търсене в сайта',
			'AddToCart'            => 'AddToCart — добавяне в количката (вкл. кутията на ansa™ Промо — едно събитие за цялата кутия)',
			'InitiateCheckout'     => 'InitiateCheckout — отваряне на чекаута',
			'AddPaymentInfo'       => 'AddPaymentInfo — избран начин на плащане / натиснато „Поръчай“ (носи и попълнените billing полета)',
			'Purchase'             => 'Purchase — поръчката (от сървъра при създаване + от браузъра на „Благодаря“, един event_id)',
			'Lead'                 => 'Lead — имейлът от попъпа на ansa™ Промо',
			'CompleteRegistration' => 'CompleteRegistration — нова клиентска регистрация',
		);
	}

	public static function all() {
		if ( null !== self::$all ) return self::$all;
		$o = get_option( self::OPTION, array() );
		if ( ! is_array( $o ) ) $o = array();
		$d = self::defaults();
		$a = array_merge( $d, $o );
		$a['events'] = array_merge( $d['events'], is_array( $o['events'] ?? null ) ? $o['events'] : array() );
		if ( defined( 'ANSA_META_PIXEL_ID' ) && ANSA_META_PIXEL_ID ) $a['pixel_id'] = (string) ANSA_META_PIXEL_ID;
		if ( defined( 'ANSA_META_TOKEN' ) && ANSA_META_TOKEN ) $a['token'] = (string) ANSA_META_TOKEN;
		return self::$all = $a;
	}

	public static function get( $k, $d = null ) { $a = self::all(); return array_key_exists( $k, $a ) ? $a[ $k ] : $d; }
	public static function pixel_id() { return preg_replace( '/\D+/', '', (string) self::get( 'pixel_id', '' ) ); }
	public static function token() { return trim( (string) self::get( 'token', '' ) ); }
	public static function api_version() { $v = (string) self::get( 'api_version', 'v24.0' ); return preg_match( '/^v\d+\.\d+$/', $v ) ? $v : 'v24.0'; }
	public static function token_from_constant() { return defined( 'ANSA_META_TOKEN' ) && ANSA_META_TOKEN; }
	public static function pixel_from_constant() { return defined( 'ANSA_META_PIXEL_ID' ) && ANSA_META_PIXEL_ID; }

	/** Включено ли е събитието (непознато/custom = включено). */
	public static function event_on( $name ) {
		$e = (array) self::get( 'events', array() );
		return ! array_key_exists( $name, $e ) || ! empty( $e[ $name ] );
	}

	public static function save( array $in ) {
		$clean = self::sanitize( $in );
		update_option( self::OPTION, $clean, false );
		self::$all = null;
		return $clean;
	}

	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$cur = get_option( self::OPTION, array() ); if ( ! is_array( $cur ) ) $cur = array();
		$o   = array();
		$o['enabled']         = empty( $in['enabled'] ) ? 0 : 1;
		$o['pixel_id']        = preg_replace( '/\D+/', '', (string) ( $in['pixel_id'] ?? '' ) );
		$tok                  = trim( (string) ( $in['token'] ?? '' ) );
		$o['token']           = ! empty( $in['token_clear'] ) ? '' : ( '' !== $tok ? $tok : (string) ( $cur['token'] ?? '' ) ); // празно поле = пази стария
		$o['test_code']       = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in['test_code'] ?? '' ) ) );
		$v                    = trim( (string) ( $in['api_version'] ?? '' ) );
		$o['api_version']     = preg_match( '/^v\d+\.\d+$/', $v ) ? $v : $d['api_version'];
		$o['browser']         = empty( $in['browser'] ) ? 0 : 1;
		$o['id_format']       = in_array( $in['id_format'] ?? '', array( 'id', 'sku', 'wc_post_id' ), true ) ? $in['id_format'] : 'id';
		$o['purchase_on']     = in_array( $in['purchase_on'] ?? '', array( 'order', 'paid' ), true ) ? $in['purchase_on'] : 'order';
		$o['mirror_pageview'] = empty( $in['mirror_pageview'] ) ? 0 : 1;
		$ev                   = array();
		foreach ( array_keys( $d['events'] ) as $k ) { $ev[ $k ] = empty( $in['events'][ $k ] ) ? 0 : 1; }
		$o['events']          = $ev;
		$roles                = array();
		foreach ( (array) ( $in['exclude_roles'] ?? array() ) as $r ) { $r = sanitize_key( (string) $r ); if ( '' !== $r ) $roles[] = $r; }
		$o['exclude_roles']   = array_values( array_unique( $roles ) );
		$ips                  = preg_split( '/[\s,]+/', (string) ( $in['internal_ips'] ?? '' ) );
		$ips                  = array_filter( array_map( 'trim', (array) $ips ), function ( $s ) { return '' !== $s && preg_match( '#^[0-9a-fA-F.:/]+$#', $s ); } );
		$o['internal_ips']    = implode( "\n", array_unique( $ips ) );
		$o['consent_cookie']  = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $in['consent_cookie'] ?? '' ) );
		$o['consent_value']   = sanitize_text_field( (string) ( $in['consent_value'] ?? '' ) );
		$o['bot_filter']      = empty( $in['bot_filter'] ) ? 0 : 1;
		$o['ldu']             = empty( $in['ldu'] ) ? 0 : 1;
		$o['log_keep']        = max( 50, min( 20000, (int) ( $in['log_keep'] ?? $d['log_keep'] ) ) );
		$o['debug']           = empty( $in['debug'] ) ? 0 : 1;
		return $o;
	}

	/** Ролите за изключване — с имената им. */
	public static function roles() {
		if ( ! function_exists( 'wp_roles' ) ) return array();
		$out = array();
		foreach ( (array) wp_roles()->roles as $k => $r ) { $out[ $k ] = (string) ( $r['name'] ?? $k ); }
		return $out;
	}
}
