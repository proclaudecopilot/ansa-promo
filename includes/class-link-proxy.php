<?php
/**
 * 1.13.1: HTTPS прокси за брандирания домейн на SendGrid (url2581.ansa.bg).
 *
 * Защо: SendGrid пренаписва всеки линк в имейла към http://url2581.ansa.bg/ls/click?… (CNAME → sendgrid.net).
 * SendGrid няма сертификат за този домейн; щом браузърът на клиента вдигне линка към https://, пада
 * NET::ERR_CERT_COMMON_NAME_INVALID. Вече изпратените писма (15 000 на 16.10.2026) не могат да се поправят,
 * затова домейнът трябва да отговаря по HTTPS от НАШ сървър и да препраща заявката към SendGrid, който
 * връща 302 към истинския адрес (и брои клика). Същото за пиксела за отваряне (/wf/open).
 *
 * Как: хостингът насочва url2581.ansa.bg (A запис → IP-то на сайта, вместо CNAME към sendgrid.net) като
 * допълнителен домейн на WordPress приложението + Let's Encrypt. Заявка с този Host стига до WordPress,
 * този клас я хваща на plugins_loaded (1) — преди WooCommerce, темата и canonical redirect — и я препраща
 * към sendgrid.net със същия път и Host хедър, после връща Location/тялото на клиента. Нищо не се записва.
 *
 * Настройка: 🧰 Инструменти → „🔗 Линковете от SendGrid (HTTPS)“ (option ansa_promo_link_proxy, извън
 * draft/published, без публикуване) или константа ANSA_PROMO_LINK_PROXY_HOST в wp-config.php.
 * Проверка: https://<host>/__ansa_promo_ping → „ok <версия>“; Doctor ред „SendGrid линкове“.
 * По избор: reset_days — срокът на линка за парола (password_reset_expiration) става N дни, със задна дата,
 * за да работят и ключовете от вече изпратените писма (по подразбиране WP ги пази 1 ден).
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

class Link_Proxy {
	const OPT    = 'ansa_promo_link_proxy';
	const ORIGIN = 'sendgrid.net';
	const PING   = '/__ansa_promo_ping';

	public static function defaults() { return array( 'enabled' => false, 'host' => '', 'reset_days' => 0 ); }

	public static function settings() {
		$s = array_merge( self::defaults(), (array) get_option( self::OPT, array() ) );
		$s['host'] = self::norm_host( (string) $s['host'] ); $s['enabled'] = ! empty( $s['enabled'] ); $s['reset_days'] = max( 0, min( 60, (int) $s['reset_days'] ) );
		if ( defined( 'ANSA_PROMO_LINK_PROXY_HOST' ) && \ANSA_PROMO_LINK_PROXY_HOST ) { $s['host'] = self::norm_host( (string) \ANSA_PROMO_LINK_PROXY_HOST ); $s['enabled'] = true; $s['const'] = true; }
		return $s;
	}

	public static function save( $enabled, $host, $reset_days ) {
		update_option( self::OPT, array( 'enabled' => (bool) $enabled, 'host' => self::norm_host( (string) $host ), 'reset_days' => max( 0, min( 60, (int) $reset_days ) ) ), false );
		delete_transient( self::OPT . '_status' );
	}

	public static function norm_host( $h ) {
		$h = strtolower( trim( $h ) ); $h = preg_replace( '#^https?://#', '', $h ); $h = preg_replace( '#[/:].*$#', '', $h );
		return preg_match( '/^[a-z0-9.-]{3,253}$/', $h ) ? $h : '';
	}

	/** Host-ът на текущата заявка (без порт). */
	public static function request_host() {
		$h = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ); return preg_replace( '#:\d+$#', '', $h );
	}

	/** plugins_loaded (1): филтърът за срока на паролата + самото прокси, ако заявката е за брандирания домейн. */
	public static function init() {
		$s = self::settings();
		if ( $s['enabled'] && $s['reset_days'] > 0 ) {
			add_filter( 'password_reset_expiration', function () use ( $s ) { return $s['reset_days'] * DAY_IN_SECONDS; }, 20 );
		}
		if ( $s['enabled'] && '' !== $s['host'] && self::request_host() === $s['host'] ) { self::handle( $s ); }
	}

	/** Препраща заявката към SendGrid и връща отговора. Никога не се връща (exit). */
	public static function handle( $s ) {
		nocache_headers();
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		if ( self::PING === strtok( $uri, '?' ) ) { status_header( 200 ); header( 'Content-Type: text/plain; charset=utf-8' ); echo 'ok ' . ANSA_PROMO_VER; exit; }
		$res = self::forward( $s['host'], $uri );
		if ( is_wp_error( $res ) ) { self::fail( $res->get_error_message() ); }
		$code = (int) wp_remote_retrieve_response_code( $res ); $loc = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( $code >= 300 && $code < 400 && '' !== $loc ) {
			if ( ! preg_match( '#^https?://#i', $loc ) ) { $loc = 'https://' . $s['host'] . '/' . ltrim( $loc, '/' ); }
			$loc = wp_sanitize_redirect( $loc );
			status_header( $code ); header( 'Location: ' . $loc, true, $code ); exit; /* нарочно без wp_safe_redirect — целта е ansa.bg или какъвто адрес е бил в имейла */
		}
		status_header( $code ?: 502 );
		$ct = (string) wp_remote_retrieve_header( $res, 'content-type' ); header( 'Content-Type: ' . ( $ct ?: 'text/plain; charset=utf-8' ) );
		echo (string) wp_remote_retrieve_body( $res ); exit;
	}

	/** GET/HEAD към sendgrid.net със същия път и Host хедъра на брандирания домейн; първо https, после http. */
	public static function forward( $host, $uri ) {
		$uri = '/' . ltrim( preg_replace( '/[\x00-\x1f\x7f\s]/', '', substr( $uri, 0, 4000 ) ), '/' );
		$args = array(
			'method' => 'HEAD' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ? 'HEAD' : 'GET',
			'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'decompress' => false, 'user-agent' => (string) ( $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0' ),
			'headers' => array( 'Host' => $host, 'Accept' => (string) ( $_SERVER['HTTP_ACCEPT'] ?? '*/*' ), 'Accept-Language' => (string) ( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '' ), 'X-Forwarded-For' => (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 'X-Forwarded-Proto' => is_ssl() ? 'https' : 'http' ),
		);
		$res = wp_remote_request( 'https://' . self::ORIGIN . $uri, $args );
		if ( is_wp_error( $res ) ) { $res = wp_remote_request( 'http://' . self::ORIGIN . $uri, $args ); }
		return $res;
	}

	/** SendGrid не отговаря: клиентът отива на началната страница, не на бяла грешка. */
	private static function fail( $why ) {
		if ( function_exists( 'error_log' ) ) { error_log( 'ansa-promo link proxy: ' . $why ); }
		status_header( 302 ); header( 'Location: ' . home_url( '/' ), true, 302 ); exit;
	}

	/** Doctor: насочен ли е домейнът към нас и отговаря ли по HTTPS с валиден сертификат. */
	public static function status() {
		$s = self::settings();
		if ( ! $s['enabled'] || '' === $s['host'] ) { return array( 'изключено (🧰 Инструменти → 🔗 Линковете от SendGrid)', 'info' ); }
		$c = get_transient( self::OPT . '_status' ); if ( is_array( $c ) && 2 === count( $c ) ) { return $c; } /* 60 с кеш — проверката е жива HTTP заявка */
		$ip = gethostbyname( $s['host'] ); $me = (string) ( $_SERVER['SERVER_ADDR'] ?? '' ); $dns = $ip === $s['host'] ? 'DNS: не се резолва' : 'DNS: ' . $ip . ( $me ? ( $ip === $me ? ' (= този сървър)' : ' (този сървър е ' . $me . ')' ) : '' );
		$r = wp_remote_get( 'https://' . $s['host'] . self::PING, array( 'timeout' => 8, 'sslverify' => true, 'redirection' => 0 ) );
		$https = is_wp_error( $r ) ? 'HTTPS: ✗ ' . $r->get_error_message() : ( 0 === strpos( (string) wp_remote_retrieve_body( $r ), 'ok ' ) ? 'HTTPS: ✓ сертификатът е валиден и заявката стига до плъгина' : 'HTTPS: ✗ отговаря ' . wp_remote_retrieve_response_code( $r ) . ', но не от плъгина (домейнът още сочи другаде?)' );
		$ok = false === strpos( $https, '✗' );
		$out = array( $s['host'] . ( ! empty( $s['const'] ) ? ' (константа)' : '' ) . ' · ' . $dns . ' · ' . $https . ' · линк за парола: ' . ( $s['reset_days'] ? $s['reset_days'] . ' дни' : 'стандартно (1 ден)' ), $ok ? 'ok' : 'bad' );
		set_transient( self::OPT . '_status', $out, 60 ); return $out;
	}
}
