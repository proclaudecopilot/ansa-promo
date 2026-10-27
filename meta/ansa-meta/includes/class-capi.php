<?php
/**
 * ansa™ Meta — Conversions API: строи събитието, събира го в буфер и го праща на shutdown (след като отговорът
 * към браузъра е тръгнал — fastcgi_finish_request), при грешка → опашка с повторения (class-queue.php). Дневник в ansa_meta_log.
 *
 * Събитие (docs: Conversions API → Parameters → Server event):
 *   event_name · event_time · event_id (общ с Pixel-а за дедупликация) · action_source=website · event_source_url ·
 *   user_data (Identity::user_data) · custom_data (Catalog::*) · opt_out · data_processing_options
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Capi {
	const CRON     = 'ansa_meta_flush';
	const SCHEDULE = 'ansa_meta_minute';

	private static $buffer   = array();
	private static $finished = false;

	public static function init() {
		add_filter( 'cron_schedules', function ( $s ) { $s[ self::SCHEDULE ] = array( 'interval' => 60, 'display' => 'ansa™ Meta: всяка минута' ); return $s; } );
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) self::schedule();
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 9999 );
	}

	public static function schedule()   { if ( ! wp_next_scheduled( self::CRON ) ) wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON ); }
	public static function unschedule() { $ts = wp_next_scheduled( self::CRON ); while ( $ts ) { wp_unschedule_event( $ts, self::CRON ); $ts = wp_next_scheduled( self::CRON ); } }

	public static function cron() {
		Queue::process();
		Schema::purge( Settings::get( 'log_keep', 500 ) );
	}

	public static function uuid() { return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) ); }

	/** Адресът на страницата; при ajax/REST — referer-ът (ако е от този сайт). */
	public static function current_url() {
		$home = home_url( '/' ); $host = (string) wp_parse_url( $home, PHP_URL_HOST );
		$is_sub = ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON );
		if ( $is_sub ) {
			$ref = function_exists( 'wp_get_referer' ) ? (string) wp_get_referer() : (string) ( $_SERVER['HTTP_REFERER'] ?? '' );
			if ( '' !== $ref && strtolower( (string) wp_parse_url( $ref, PHP_URL_HOST ) ) === strtolower( $host ) ) return esc_url_raw( $ref );
			return $home;
		}
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$h   = (string) ( $_SERVER['HTTP_HOST'] ?? $host );
		return esc_url_raw( ( is_ssl() ? 'https' : 'http' ) . '://' . $h . $uri ) ?: $home;
	}

	/** Чисти custom_data: числата закръглени, текстът без боклук, празните махнати. */
	public static function clean_custom( array $c ) {
		$out = array();
		foreach ( $c as $k => $v ) {
			$k = (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $k ) ); if ( '' === $k ) continue;
			if ( null === $v || '' === $v || ( is_array( $v ) && ! $v ) ) continue;
			if ( 'contents' === $k && is_array( $v ) ) {
				$rows = array();
				foreach ( $v as $row ) { if ( ! is_array( $row ) || ! isset( $row['id'] ) ) continue; $rows[] = array( 'id' => mb_substr( (string) $row['id'], 0, 100 ), 'quantity' => max( 1, (int) ( $row['quantity'] ?? 1 ) ), 'item_price' => round( (float) ( $row['item_price'] ?? 0 ), 2 ) ); }
				if ( $rows ) $out[ $k ] = array_slice( $rows, 0, 100 );
			} elseif ( 'content_ids' === $k ) {
				$ids = array(); foreach ( (array) $v as $id ) { $id = trim( (string) $id ); if ( '' !== $id ) $ids[] = mb_substr( $id, 0, 100 ); }
				if ( $ids ) $out[ $k ] = array_slice( array_values( array_unique( $ids ) ), 0, 100 );
			} elseif ( in_array( $k, array( 'value', 'shipping', 'item_price', 'predicted_ltv' ), true ) ) {
				$out[ $k ] = round( (float) $v, 2 );
			} elseif ( 'num_items' === $k ) {
				$out[ $k ] = (int) $v;
			} elseif ( 'currency' === $k ) {
				$cur = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $v ) ); if ( 3 === strlen( $cur ) ) $out[ $k ] = $cur;
			} elseif ( is_scalar( $v ) ) {
				$out[ $k ] = mb_substr( trim( (string) $v ), 0, 500 );
			} elseif ( is_array( $v ) ) {
				$out[ $k ] = array_slice( array_map( function ( $x ) { return is_scalar( $x ) ? mb_substr( (string) $x, 0, 200 ) : ''; }, array_values( $v ) ), 0, 50 );
			}
		}
		return $out;
	}

	/** Строи събитието за Conversions API. $plain = чистите данни за човека (Identity::current / from_order …). */
	public static function event( $name, array $custom = array(), array $plain = array(), array $opts = array() ) {
		$now = time(); $time = (int) ( $opts['time'] ?? $now );
		if ( $time <= 0 || $time > $now || $time < $now - 6 * DAY_IN_SECONDS ) $time = $now; // Meta: до 7 дни назад
		$id = trim( (string) ( $opts['event_id'] ?? '' ) ); if ( '' === $id ) $id = self::uuid();
		$url = trim( (string) ( $opts['url'] ?? '' ) ); if ( '' === $url ) $url = self::current_url();
		$ev = array(
			'event_name'       => (string) $name,
			'event_time'       => $time,
			'event_id'         => $id,
			'action_source'    => 'website',
			'event_source_url' => $url,
			'user_data'        => Identity::user_data( $plain ),
			'opt_out'          => false,
		);
		$custom = self::clean_custom( $custom );
		if ( $custom ) $ev['custom_data'] = $custom;
		if ( Settings::get( 'ldu' ) ) { $ev['data_processing_options'] = array( 'LDU' ); $ev['data_processing_options_country'] = 0; $ev['data_processing_options_state'] = 0; }
		else { $ev['data_processing_options'] = array(); }
		return apply_filters( 'ansa_meta_event', $ev, $name, $opts );
	}

	/**
	 * Записва събитие за изпращане в края на заявката. Връща event_id ('' = не е изпратено: изключено/събитието е спряно).
	 * $opts: event_id, time, url, source (server|browser|promo|queue), now (bool — прати веднага, без буфер).
	 */
	public static function track( $name, array $custom = array(), array $plain = array(), array $opts = array() ) {
		if ( ! Plugin::enabled() || ! Settings::event_on( $name ) ) return '';
		$ev = self::event( $name, $custom, $plain, $opts );
		if ( ! is_array( $ev ) || empty( $ev['event_name'] ) ) return '';
		$row = array( 'ev' => $ev, 'src' => (string) ( $opts['source'] ?? 'server' ) );
		if ( ! empty( $opts['now'] ) ) { self::deliver( array( $row ) ); return $ev['event_id']; }
		self::$buffer[] = $row;
		if ( self::$finished ) { self::flush(); } // вече сме след shutdown flush-а → веднага
		return $ev['event_id'];
	}

	public static function pending() { return count( self::$buffer ); }

	/** shutdown: отговорът към браузъра тръгва, после пращаме. */
	public static function flush() {
		if ( ! self::$buffer ) return;
		$batch = self::$buffer; self::$buffer = array();
		if ( ! self::$finished ) { self::$finished = true; self::finish_request(); }
		self::deliver( $batch );
	}

	private static function finish_request() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) return;
		if ( ! apply_filters( 'ansa_meta_finish_request', true ) ) return;
		if ( function_exists( 'fastcgi_finish_request' ) ) { @fastcgi_finish_request(); }
		elseif ( function_exists( 'litespeed_finish_request' ) ) { @litespeed_finish_request(); }
	}

	/** Праща партида; при грешка, която си струва повторение → опашката. При „невалидно“ в партида от няколко — пробва едно по едно. */
	public static function deliver( array $batch, $test = '' ) {
		$events = array(); foreach ( $batch as $b ) { $events[] = $b['ev']; }
		$res = self::send( $events, $test );
		if ( ! $res['ok'] && ! $res['retry'] && count( $batch ) > 1 ) {
			foreach ( $batch as $b ) {
				$r1 = self::send( array( $b['ev'] ), $test ); self::log( $b['ev'], $b['src'], $r1 );
				if ( ! $r1['ok'] && $r1['retry'] ) Queue::push( $b['ev'], $b['src'], $r1['error'] );
			}
			return $res;
		}
		foreach ( $batch as $b ) { self::log( $b['ev'], $b['src'], $res ); }
		if ( ! $res['ok'] && $res['retry'] ) { foreach ( $batch as $b ) { Queue::push( $b['ev'], $b['src'], $res['error'] ); } }
		return $res;
	}

	/** HTTP към graph.facebook.com/{v}/{pixel}/events. Връща [ok, retry, http, error, body, ms, received, trace]. */
	public static function send( array $events, $test = '' ) {
		$pixel = Settings::pixel_id(); $token = Settings::token();
		$base = array( 'ok' => false, 'retry' => false, 'http' => 0, 'error' => '', 'body' => null, 'ms' => 0, 'received' => 0, 'trace' => '' );
		if ( '' === $pixel || '' === $token ) return array_merge( $base, array( 'error' => 'няма Pixel ID или access token' ) );
		if ( ! $events ) return array_merge( $base, array( 'ok' => true ) );
		$body = array( 'data' => array_values( $events ), 'access_token' => $token, 'partner_agent' => 'ansa-meta-' . ANSA_META_VER );
		$test = trim( (string) $test );
		if ( '' === $test && Settings::get( 'test_mode' ) && '' !== (string) Settings::get( 'test_code', '' ) ) $test = (string) Settings::get( 'test_code' ); // тестов режим: всичко към Test events
		if ( '' !== $test ) $body['test_event_code'] = $test;
		$url = sprintf( 'https://graph.facebook.com/%s/%s/events', Settings::api_version(), $pixel );
		$timeout = ( self::$finished || ( defined( 'DOING_CRON' ) && DOING_CRON ) || is_admin() ) ? 10 : 4; // ако браузърът още чака — кратко; повторението е от опашката
		$t0 = microtime( true );
		$r = wp_remote_post( $url, array( 'timeout' => (int) apply_filters( 'ansa_meta_timeout', $timeout ), 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ), 'user-agent' => 'ansa-meta/' . ANSA_META_VER . ' WordPress' ) );
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $r ) ) return array_merge( $base, array( 'retry' => true, 'error' => 'HTTP: ' . $r->get_error_message(), 'ms' => $ms ) );
		$code = (int) wp_remote_retrieve_response_code( $r ); $j = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 === $code && is_array( $j ) && isset( $j['events_received'] ) ) {
			return array_merge( $base, array( 'ok' => true, 'http' => 200, 'body' => $j, 'ms' => $ms, 'received' => (int) $j['events_received'], 'trace' => (string) ( $j['fbtrace_id'] ?? '' ) ) );
		}
		$err = is_array( $j ) && isset( $j['error'] ) && is_array( $j['error'] ) ? $j['error'] : array();
		$ecode = (int) ( $err['code'] ?? 0 ); $sub = (int) ( $err['error_subcode'] ?? 0 );
		$msg = trim( (string) ( $err['message'] ?? '' ) . ( ! empty( $err['error_user_msg'] ) ? ' · ' . $err['error_user_msg'] : '' ) );
		if ( '' === $msg ) $msg = 'HTTP ' . $code;
		/* 100 = невалиден параметър, 200 = права, 803 = няма такъв обект (pixel), 2635 = остаряла API версия → повторението не помага */
		$permanent = in_array( $ecode, array( 100, 200, 803, 2635 ), true ) || ( 400 === $code && 0 === $ecode );
		return array_merge( $base, array( 'retry' => ! $permanent, 'http' => $code, 'body' => $j, 'ms' => $ms, 'error' => '(' . $ecode . ( $sub ? '/' . $sub : '' ) . ') ' . $msg ) );
	}

	/** Тест: PageView + Purchase към „Test events“ в Events Manager (test_event_code от настройките). */
	public static function test( $code = '' ) {
		$code = trim( (string) ( $code ?: Settings::get( 'test_code', '' ) ) );
		if ( '' === $code ) return array( 'ok' => false, 'retry' => false, 'http' => 0, 'error' => 'няма Test event code — вземи го от Events Manager → Test events', 'body' => null, 'ms' => 0 );
		$plain = array( 'em' => 'test@ansa.bg', 'ph' => '0888123456', 'fn' => 'Тест', 'ln' => 'Тестов', 'ct' => 'София', 'zp' => '1000', 'country' => 'BG', 'external_id' => 'test-' . wp_rand( 1000, 9999 ), 'ip' => Identity::client_ip(), 'ua' => Identity::user_agent(), 'fbp' => 'fb.1.' . ( time() * 1000 ) . '.1234567890' );
		$events = array(
			self::event( 'PageView', array(), $plain, array( 'url' => home_url( '/' ) ) ),
			self::event( 'Purchase', array( 'content_ids' => array( 'test-1' ), 'content_type' => 'product', 'contents' => array( array( 'id' => 'test-1', 'quantity' => 1, 'item_price' => 12.34 ) ), 'value' => 12.34, 'currency' => Catalog::currency(), 'num_items' => 1, 'order_id' => 'test-' . time() ), $plain, array( 'url' => wc_get_checkout_url() ) ),
		);
		$res = self::send( $events, $code );
		foreach ( $events as $ev ) self::log( $ev, 'test', $res );
		return $res;
	}

	/** Дневникът: един ред на събитие; при debug — целият payload, иначе резюме (кои user_data ключове има, стойност, id-та). */
	public static function log( array $ev, $src, array $res ) {
		global $wpdb;
		$cd = (array) ( $ev['custom_data'] ?? array() );
		$summary = array(
			'user'     => array_keys( (array) ( $ev['user_data'] ?? array() ) ),
			'value'    => $cd['value'] ?? null, 'currency' => $cd['currency'] ?? null,
			'ids'      => array_slice( (array) ( $cd['content_ids'] ?? array() ), 0, 10 ),
			'order_id' => $cd['order_id'] ?? null, 'url' => (string) ( $ev['event_source_url'] ?? '' ), 'time' => (int) ( $ev['event_time'] ?? 0 ),
		);
		$payload = Settings::get( 'debug' ) ? wp_json_encode( $ev, JSON_UNESCAPED_UNICODE ) : wp_json_encode( $summary, JSON_UNESCAPED_UNICODE );
		$resp = $res['ok'] ? ( 'received=' . (int) ( $res['received'] ?? 0 ) . ( ! empty( $res['trace'] ) ? ' · fbtrace ' . $res['trace'] : '' ) ) : (string) $res['error'];
		try {
			$wpdb->insert( Schema::log_table(), array(
				'at' => gmdate( 'Y-m-d H:i:s' ), 'event_name' => mb_substr( (string) $ev['event_name'], 0, 64 ), 'event_id' => mb_substr( (string) $ev['event_id'], 0, 64 ),
				'source' => mb_substr( (string) $src, 0, 16 ), 'ok' => $res['ok'] ? 1 : 0, 'http' => (int) $res['http'], 'ms' => min( 65535, (int) $res['ms'] ),
				'response' => mb_substr( $resp, 0, 500 ), 'payload' => mb_substr( (string) $payload, 0, 60000 ),
			), array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' ) );
		} catch ( \Throwable $e ) {}
	}

	/** Статистика за Състояние: последните 24 ч по събитие (ok/fail), опашка, последна грешка, средно ключове за съвпадение на Purchase. */
	public static function stats( $hours = 24 ) {
		global $wpdb; $l = Schema::log_table(); $q = Schema::queue_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );
		$by = $wpdb->get_results( $wpdb->prepare( "SELECT event_name, SUM(ok) AS ok, SUM(1-ok) AS fail, AVG(ms) AS ms FROM $l WHERE at >= %s AND source <> 'test' GROUP BY event_name ORDER BY ok DESC", $since ), ARRAY_A );
		$last_err = $wpdb->get_row( "SELECT at, event_name, response FROM $l WHERE ok = 0 ORDER BY id DESC LIMIT 1", ARRAY_A );
		$queue = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $q" );
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM $l WHERE event_name = 'Purchase' AND ok = 1 AND at >= %s ORDER BY id DESC LIMIT 200", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$keys = array(); $n = 0;
		foreach ( (array) $rows as $p ) {
			$j = json_decode( (string) $p, true ); if ( ! is_array( $j ) ) continue;
			$u = isset( $j['user'] ) ? (array) $j['user'] : array_keys( (array) ( $j['user_data'] ?? array() ) );
			$n++; foreach ( $u as $k ) { $keys[ $k ] = ( $keys[ $k ] ?? 0 ) + 1; }
		}
		arsort( $keys );
		return array( 'by' => (array) $by, 'last_error' => $last_err, 'queue' => $queue, 'purchase_n' => $n, 'purchase_keys' => $keys );
	}
}
