<?php
/**
 * ansa™ Meta — кой е човекът: нормализация и SHA-256 по спецификацията на Meta (Customer Information Parameters),
 * бисквитките _fbp / _fbc / ansa_meta_vid (first-party, с дълъг живот от сървъра), IP, user agent,
 * и откъде ги вземаме: поръчката, логнатият потребител, WC клиентът в сесията, имейлът от попъпа, попълненото в чекаута.
 *
 * Нормализация (docs: Conversions API → Parameters → Customer information parameters):
 *   em — малки букви, без интервали · ph — само цифри, с код на държавата, без водещи нули/„+“ · fn/ln — малки букви, без пунктуация (UTF-8 е ок)
 *   ct — малки букви, без интервали и пунктуация · zp — малки букви, без интервали/тирета · country — ISO 2 букви, малки · st — само за САЩ (2 букви)
 *   external_id — хешира се (препоръка на Meta) · client_ip_address, client_user_agent, fbp, fbc — НЕ се хешират
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Identity {
	const VID           = 'ansa_meta_vid';   // first-party visitor id (2 години)
	const SESSION_EMAIL = 'ansa_meta_email'; // WC session: имейлът от попъпа на промото
	const SESSION_PLAIN = 'ansa_meta_plain'; // WC session: попълнените billing полета от чекаута (идват с AddPaymentInfo)
	const COOKIE_DAYS   = 90;
	const VID_DAYS      = 730;
	const BOT_RE        = '/bot|crawl|spider|slurp|headless|phantom|lighthouse|pingdom|gtmetrix|facebookexternalhit|wget\/|curl\/|python-requests|go-http-client|ahrefs|semrush|mj12|bingpreview|yandex|baidu|duckduck|applebot|petalbot|screaming|dataprovider|uptimerobot|site24x7/i';

	private static $cookies_ok = false;
	private static $excluded   = null;

	/** Телефонни кодове по държава (ISO 2 букви) — за ph без код; BG е по подразбиране за ansa.bg. */
	private static $cc = array(
		'bg' => '359', 'ro' => '40', 'gr' => '30', 'rs' => '381', 'mk' => '389', 'tr' => '90', 'de' => '49', 'at' => '43', 'ch' => '41',
		'gb' => '44', 'uk' => '44', 'ie' => '353', 'fr' => '33', 'es' => '34', 'it' => '39', 'pt' => '351', 'nl' => '31', 'be' => '32',
		'pl' => '48', 'cz' => '420', 'sk' => '421', 'hu' => '36', 'hr' => '385', 'si' => '386', 'us' => '1', 'ca' => '1', 'se' => '46',
		'no' => '47', 'dk' => '45', 'fi' => '358', 'cy' => '357', 'il' => '972', 'ua' => '380', 'md' => '373', 'ru' => '7', 'al' => '355', 'me' => '382', 'ba' => '387',
	);

	/* ───────── нормализация + хеш ───────── */
	public static function hash( $v ) { $v = (string) $v; return '' === $v ? '' : hash( 'sha256', $v ); }

	public static function norm_email( $e ) {
		$e = strtolower( trim( (string) $e ) );
		return ( '' !== $e && false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ) ? $e : '';
	}

	public static function norm_phone( $p, $country = 'bg' ) {
		$d = preg_replace( '/\D+/', '', (string) $p );
		if ( '' === $d ) return '';
		if ( 0 === strpos( $d, '00' ) ) $d = substr( $d, 2 );
		$cc = self::$cc[ strtolower( (string) $country ) ] ?? '';
		if ( '' !== $cc && '' !== $d ) {
			if ( '0' === $d[0] ) { $d = $cc . ltrim( $d, '0' ); }                               // 0888 123 456 → 359888123456
			elseif ( 0 !== strpos( $d, $cc ) && strlen( $d ) <= 10 ) { $d = $cc . $d; }         // 888123456 → 359888123456
		}
		$len = strlen( $d );
		return ( $len >= 8 && $len <= 15 ) ? $d : '';
	}

	public static function norm_name( $s ) {
		$s = mb_strtolower( trim( (string) $s ), 'UTF-8' );
		$s = preg_replace( '/[^\p{L}\p{M}\s]/u', '', $s );
		$s = preg_replace( '/\s+/u', ' ', trim( (string) $s ) );
		return (string) $s;
	}

	public static function norm_city( $s ) {
		$s = mb_strtolower( trim( (string) $s ), 'UTF-8' );
		return (string) preg_replace( '/[^\p{L}\p{M}]/u', '', $s );
	}

	public static function norm_zip( $s ) {
		$s = strtolower( (string) preg_replace( '/[\s\-]+/', '', (string) $s ) );
		if ( preg_match( '/^\d{5}\d+$/', $s ) ) $s = substr( $s, 0, 5 ); // US ZIP+4 → 5 цифри
		return (string) preg_replace( '/[^a-z0-9]/', '', $s );
	}

	public static function norm_country( $s ) {
		$s = strtolower( trim( (string) $s ) );
		return preg_match( '/^[a-z]{2}$/', $s ) ? $s : '';
	}

	public static function norm_state( $s, $country ) {
		if ( 'us' !== self::norm_country( $country ) ) return '';
		$s = strtolower( (string) preg_replace( '/[^A-Za-z]/', '', (string) $s ) );
		return 2 === strlen( $s ) ? $s : '';
	}

	/** Чистите стойности → user_data за Conversions API (хешираните като масиви, както Meta приема). */
	public static function user_data( array $p ) {
		$u = array();
		$country = self::norm_country( $p['country'] ?? '' );
		$em = self::norm_email( $p['em'] ?? '' );                                 if ( '' !== $em ) $u['em'] = array( self::hash( $em ) );
		$ph = self::norm_phone( $p['ph'] ?? '', $country ?: 'bg' );               if ( '' !== $ph ) $u['ph'] = array( self::hash( $ph ) );
		$fn = self::norm_name( $p['fn'] ?? '' );                                  if ( '' !== $fn ) $u['fn'] = array( self::hash( $fn ) );
		$ln = self::norm_name( $p['ln'] ?? '' );                                  if ( '' !== $ln ) $u['ln'] = array( self::hash( $ln ) );
		$ct = self::norm_city( $p['ct'] ?? '' );                                  if ( '' !== $ct ) $u['ct'] = array( self::hash( $ct ) );
		$zp = self::norm_zip( $p['zp'] ?? '' );                                   if ( '' !== $zp ) $u['zp'] = array( self::hash( $zp ) );
		$st = self::norm_state( $p['st'] ?? '', $country );                       if ( '' !== $st ) $u['st'] = array( self::hash( $st ) );
		if ( '' !== $country ) $u['country'] = array( self::hash( $country ) );
		$ext = trim( (string) ( $p['external_id'] ?? '' ) );                      if ( '' !== $ext ) $u['external_id'] = array( self::hash( $ext ) );
		if ( ! empty( $p['ip'] ) && filter_var( $p['ip'], FILTER_VALIDATE_IP ) ) $u['client_ip_address'] = (string) $p['ip'];
		if ( ! empty( $p['ua'] ) ) $u['client_user_agent'] = mb_substr( (string) $p['ua'], 0, 512 );
		if ( ! empty( $p['fbp'] ) && self::valid_fbp( $p['fbp'] ) ) $u['fbp'] = (string) $p['fbp'];
		if ( ! empty( $p['fbc'] ) && self::valid_fbc( $p['fbc'] ) ) $u['fbc'] = (string) $p['fbc'];
		return apply_filters( 'ansa_meta_user_data', $u, $p );
	}

	/** Advanced Matching за fbq('init') — чистите нормализирани стойности (Pixel-ът ги хешира сам); external_id — хеширан, както от сървъра. */
	public static function pixel_am( array $p ) {
		$am = array();
		$country = self::norm_country( $p['country'] ?? '' );
		$v = self::norm_email( $p['em'] ?? '' );                   if ( '' !== $v ) $am['em'] = $v;
		$v = self::norm_phone( $p['ph'] ?? '', $country ?: 'bg' ); if ( '' !== $v ) $am['ph'] = $v;
		$v = self::norm_name( $p['fn'] ?? '' );                    if ( '' !== $v ) $am['fn'] = $v;
		$v = self::norm_name( $p['ln'] ?? '' );                    if ( '' !== $v ) $am['ln'] = $v;
		$v = self::norm_city( $p['ct'] ?? '' );                    if ( '' !== $v ) $am['ct'] = $v;
		$v = self::norm_zip( $p['zp'] ?? '' );                     if ( '' !== $v ) $am['zp'] = $v;
		$v = self::norm_state( $p['st'] ?? '', $country );         if ( '' !== $v ) $am['st'] = $v;
		if ( '' !== $country ) $am['country'] = $country;
		$v = trim( (string) ( $p['external_id'] ?? '' ) );         if ( '' !== $v ) $am['external_id'] = self::hash( $v );
		return $am;
	}

	/* ───────── заявката: IP, UA, бисквитки ───────── */
	public static function client_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $k ) {
			if ( empty( $_SERVER[ $k ] ) ) continue;
			$parts = explode( ',', (string) $_SERVER[ $k ] );
			$v = trim( (string) $parts[0] );
			if ( filter_var( $v, FILTER_VALIDATE_IP ) ) return $v;
		}
		return '';
	}

	public static function user_agent() { return isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; }
	public static function is_bot( $ua ) { $ua = (string) $ua; return '' === $ua || (bool) preg_match( self::BOT_RE, $ua ); }

	public static function valid_fbp( $v ) { return (bool) preg_match( '/^fb\.\d\.\d{10,16}\.\d{1,20}$/', (string) $v ); }
	public static function valid_fbc( $v ) { return (bool) preg_match( '/^fb\.\d\.\d{10,16}\.[A-Za-z0-9_\-]{1,512}$/', (string) $v ); }

	/** Бисквитки от сървъра само там, където отговорът не се кешира (REST, ajax, чекаут). */
	public static function allow_cookies( $ok = true ) { self::$cookies_ok = (bool) $ok; }

	private static function set_cookie( $name, $value, $days ) {
		$_COOKIE[ $name ] = $value;
		if ( ! self::$cookies_ok || headers_sent() ) return;
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$domain = ( '' !== $host && ! filter_var( $host, FILTER_VALIDATE_IP ) && false !== strpos( $host, '.' ) ) ? '.' . preg_replace( '/^www\./', '', $host ) : '';
		@setcookie( $name, $value, array( 'expires' => time() + $days * DAY_IN_SECONDS, 'path' => '/', 'domain' => $domain, 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
	}

	/** _fbp: от бисквитката; ако я няма — наша (Pixel-ът ползва съществуващата). При разрешени бисквитки се подновява на 90 дни от сървъра (first-party → Safari не я реже на 7 дни). */
	public static function fbp() {
		$v = isset( $_COOKIE['_fbp'] ) ? (string) $_COOKIE['_fbp'] : '';
		if ( ! self::valid_fbp( $v ) ) { $v = 'fb.1.' . (int) round( microtime( true ) * 1000 ) . '.' . random_int( 1000000000, 9999999999 ); }
		self::set_cookie( '_fbp', $v, self::COOKIE_DAYS );
		return $v;
	}

	/** _fbc: от бисквитката; иначе от fbclid (в заявката или подаден от браузъра) → fb.1.<ms>.<fbclid>. */
	public static function fbc( $fbclid = '' ) {
		$v = isset( $_COOKIE['_fbc'] ) ? (string) $_COOKIE['_fbc'] : '';
		if ( '' === $fbclid && ! empty( $_GET['fbclid'] ) ) $fbclid = (string) $_GET['fbclid'];
		$fbclid = (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $fbclid );
		if ( ! self::valid_fbc( $v ) ) {
			if ( '' === $fbclid ) return '';
			$v = 'fb.1.' . (int) round( microtime( true ) * 1000 ) . '.' . $fbclid;
		} elseif ( '' !== $fbclid && substr( $v, -strlen( $fbclid ) ) !== $fbclid ) {
			$v = 'fb.1.' . (int) round( microtime( true ) * 1000 ) . '.' . $fbclid; // нов клик → нова fbc
		}
		self::set_cookie( '_fbc', $v, self::COOKIE_DAYS );
		return $v;
	}

	/** Наш visitor id (external_id за гости) — 32 hex; създава се от браузъра, подновява се от сървъра. */
	public static function vid() {
		$v = isset( $_COOKIE[ self::VID ] ) ? (string) $_COOKIE[ self::VID ] : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $v ) ) { $v = bin2hex( random_bytes( 16 ) ); }
		self::set_cookie( self::VID, $v, self::VID_DAYS );
		return $v;
	}

	/* ───────── изключване: роли, вътрешни IP, ботове, съгласие ───────── */
	public static function excluded() {
		if ( null !== self::$excluded ) return self::$excluded;
		$r = false;
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$u = wp_get_current_user(); $ex = (array) Settings::get( 'exclude_roles', array() );
			if ( $u && array_intersect( $ex, (array) $u->roles ) ) $r = 'role';
		}
		if ( ! $r && Settings::get( 'bot_filter' ) && self::is_bot( self::user_agent() ) ) $r = 'bot';
		if ( ! $r ) {
			$ip = self::client_ip();
			foreach ( preg_split( '/[\s,]+/', (string) Settings::get( 'internal_ips', '' ) ) as $c ) { if ( '' !== $c && self::ip_in( $ip, $c ) ) { $r = 'ip'; break; } }
		}
		if ( ! $r && ! self::consent() ) $r = 'consent';
		self::$excluded = apply_filters( 'ansa_meta_excluded', $r );
		return self::$excluded;
	}
	public static function reset_excluded() { self::$excluded = null; }

	public static function consent() {
		$name = (string) Settings::get( 'consent_cookie', '' );
		if ( '' === $name ) return true;
		$v = isset( $_COOKIE[ $name ] ) ? (string) $_COOKIE[ $name ] : '';
		if ( '' === $v ) return false;
		$want = (string) Settings::get( 'consent_value', '' );
		return '' === $want || false !== stripos( $v, $want );
	}

	public static function ip_in( $ip, $cidr ) {
		if ( '' === $ip || '' === $cidr ) return false;
		if ( false === strpos( $cidr, '/' ) ) return $ip === $cidr;
		list( $net, $bits ) = explode( '/', $cidr, 2 ); $bits = (int) $bits;
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && filter_var( $net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$mask = $bits <= 0 ? 0 : ( -1 << ( 32 - min( 32, $bits ) ) );
			return ( ip2long( $ip ) & $mask ) === ( ip2long( $net ) & $mask );
		}
		return $ip === $net;
	}

	/* ───────── откъде идват данните ───────── */
	public static function from_user( $uid ) {
		$u = get_userdata( (int) $uid ); if ( ! $u ) return array();
		$m = function ( $k ) use ( $uid ) { return (string) get_user_meta( (int) $uid, $k, true ); };
		return array_filter( array(
			'em' => (string) $u->user_email, 'fn' => $u->first_name ?: $m( 'billing_first_name' ), 'ln' => $u->last_name ?: $m( 'billing_last_name' ),
			'ph' => $m( 'billing_phone' ), 'ct' => $m( 'billing_city' ), 'zp' => $m( 'billing_postcode' ), 'country' => $m( 'billing_country' ), 'st' => $m( 'billing_state' ),
			'external_id' => 'u' . (int) $uid,
		), 'strlen' );
	}

	public static function from_wc_customer() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) return array();
		$c = WC()->customer;
		return array_filter( array(
			'em' => (string) $c->get_billing_email(), 'ph' => (string) $c->get_billing_phone(), 'fn' => (string) $c->get_billing_first_name(), 'ln' => (string) $c->get_billing_last_name(),
			'ct' => (string) $c->get_billing_city(), 'zp' => (string) $c->get_billing_postcode(), 'country' => (string) $c->get_billing_country(), 'st' => (string) $c->get_billing_state(),
		), 'strlen' );
	}

	public static function from_order( $order ) {
		if ( ! is_object( $order ) ) return array();
		$ctx = json_decode( (string) $order->get_meta( Woo::META_CTX ), true ); if ( ! is_array( $ctx ) ) $ctx = array();
		$p = array(
			'em' => (string) $order->get_billing_email(), 'ph' => (string) $order->get_billing_phone(),
			'fn' => (string) $order->get_billing_first_name(), 'ln' => (string) $order->get_billing_last_name(),
			'ct' => (string) $order->get_billing_city(), 'st' => (string) $order->get_billing_state(), 'zp' => (string) $order->get_billing_postcode(), 'country' => (string) $order->get_billing_country(),
			'external_id' => $order->get_customer_id() ? 'u' . (int) $order->get_customer_id() : (string) ( $ctx['vid'] ?? '' ),
			'fbp' => (string) ( $ctx['fbp'] ?? '' ), 'fbc' => (string) ( $ctx['fbc'] ?? '' ), 'ip' => (string) ( $ctx['ip'] ?? '' ), 'ua' => (string) ( $ctx['ua'] ?? '' ),
		);
		if ( '' === $p['ph'] && method_exists( $order, 'get_shipping_phone' ) ) $p['ph'] = (string) $order->get_shipping_phone();
		if ( '' === $p['fn'] ) $p['fn'] = (string) $order->get_shipping_first_name();
		if ( '' === $p['ln'] ) $p['ln'] = (string) $order->get_shipping_last_name();
		if ( '' === $p['ct'] ) $p['ct'] = (string) $order->get_shipping_city();
		if ( '' === $p['zp'] ) $p['zp'] = (string) $order->get_shipping_postcode();
		if ( '' === $p['country'] ) $p['country'] = (string) $order->get_shipping_country();
		if ( '' === $p['ip'] ) $p['ip'] = (string) $order->get_customer_ip_address();
		if ( '' === $p['ua'] ) $p['ua'] = (string) $order->get_customer_user_agent();
		/* поръчка без наш контекст (от админа/телефон) и без клиент → външен id = имейлът, за да се свързва между събитията */
		if ( '' === $p['external_id'] && '' !== $p['em'] ) $p['external_id'] = 'e' . self::norm_email( $p['em'] );
		return apply_filters( 'ansa_meta_from_order', $p, $order );
	}

	/** Контекстът на заявката, който се записва в поръчката при чекаут (за Purchase от сървъра по-късно). */
	public static function ctx( $fbclid = '' ) {
		return array( 'fbp' => self::fbp(), 'fbc' => self::fbc( $fbclid ), 'ip' => self::client_ip(), 'ua' => self::user_agent(), 'vid' => self::vid(), 'url' => Capi::current_url(), 'ts' => time() );
	}

	/** Всичко, което знаем за текущия посетител (приоритет: логнат → WC клиент → попълнено в чекаута → имейл от попъпа). */
	public static function current( $fbclid = '' ) {
		$p = array();
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) $p = self::from_user( get_current_user_id() );
		foreach ( self::from_wc_customer() as $k => $v ) { if ( empty( $p[ $k ] ) ) $p[ $k ] = $v; }
		foreach ( (array) self::session_get( self::SESSION_PLAIN, array() ) as $k => $v ) { if ( empty( $p[ $k ] ) && is_string( $v ) ) $p[ $k ] = $v; }
		if ( empty( $p['em'] ) ) { $se = (string) self::session_get( self::SESSION_EMAIL, '' ); if ( '' !== $se ) $p['em'] = $se; }
		$p['fbp'] = self::fbp(); $p['fbc'] = self::fbc( $fbclid ); $p['ip'] = self::client_ip(); $p['ua'] = self::user_agent();
		if ( empty( $p['external_id'] ) ) { $has = isset( $_COOKIE[ self::VID ] ) && preg_match( '/^[a-f0-9]{32}$/', (string) $_COOKIE[ self::VID ] ); if ( $has || self::$cookies_ok ) $p['external_id'] = self::vid(); }
		return apply_filters( 'ansa_meta_current', $p );
	}

	/* ───────── WC session ───────── */
	public static function session_get( $k, $d = null ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) return $d;
		try { $v = WC()->session->get( $k ); } catch ( \Throwable $e ) { return $d; }
		return null === $v ? $d : $v;
	}

	public static function session_set( $k, $v ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) return;
		try {
			if ( method_exists( WC()->session, 'set_customer_session_cookie' ) && ! WC()->session->has_session() ) WC()->session->set_customer_session_cookie( true );
			WC()->session->set( $k, $v );
		} catch ( \Throwable $e ) {}
	}

	/** Попълнените billing полета (от браузъра, с AddPaymentInfo) → пазим ги в сесията за следващите събития. */
	public static function remember_plain( array $in ) {
		$keep = array(); $allowed = array( 'em', 'ph', 'fn', 'ln', 'ct', 'zp', 'country', 'st' );
		foreach ( $allowed as $k ) { if ( ! empty( $in[ $k ] ) && is_string( $in[ $k ] ) ) $keep[ $k ] = mb_substr( sanitize_text_field( $in[ $k ] ), 0, 120 ); }
		if ( ! $keep ) return array();
		$cur = (array) self::session_get( self::SESSION_PLAIN, array() );
		self::session_set( self::SESSION_PLAIN, array_merge( $cur, $keep ) );
		return $keep;
	}
}
