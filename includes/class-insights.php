<?php
/**
 * ansa™ Промо — Insights.
 *
 * v1.3.0 (Фаза 5): дневен rollup в {prefix}ansa_promo_stats (day, step, box, utm, n) — пази се за съвместимост/CSV.
 *
 * v1.17.0 — Insights 2 („да следим истински как се движи играта“, човекът): всяка **сесия** на посетител има sid от браузъра
 * (sessionStorage, promo.js) и ред в {prefix}ansa_promo_sessions — кога, откъде (UTM на продукта + utm_source/medium/campaign +
 * referrer), устройство, лийдът от попъпа (имейлът), коя кутия, кои продукти е сложила, най-далечната стъпка и поръчката.
 * Всяко действие е ред в {prefix}ansa_promo_events: view → gate → box → item (продукт в кутията) → full → order → add (към плащане)
 * → created (поръчка) → paid (билет) → activated. Клиентските идват през `ansa_promo_beacon` (БЕЗ nonce — кешираната страница
 * носи изтекъл nonce и губеше всички „view“; само rate limit + whitelist; черновата/редакторът не броят), сървърните от hook-овете.
 * Екранът 📈 Insights: KPI, фуния по сесии, по UTM/кутия/устройство/ден, продуктите в кутиите, **жив фийд** на последните сесии
 * (кой, откъде, какво е цъкнал, докъде е стигнал, поръчката) с автоматично опресняване; CSV на сесиите и на стария rollup.
 *
 * @package AnsaPromo
 * @since 1.3.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Insights {

	const STEPS   = array( 'view', 'gate', 'box', 'full', 'order', 'add', 'created', 'paid', 'activated' );
	const CLIENT  = array( 'view', 'gate', 'box', 'item', 'full', 'order', 'add' );
	/* редът на стъпките за „най-далечна“ (item не е стъпка на фунията — брои се отделно) */
	const ORDER   = array( 'view' => 1, 'gate' => 2, 'box' => 3, 'item' => 3, 'full' => 4, 'order' => 5, 'add' => 6, 'created' => 7, 'paid' => 8, 'activated' => 9 );
	const FUNNEL  = array( 'view', 'gate', 'box', 'full', 'order', 'add', 'created', 'paid', 'activated' );
	const LABELS  = array( 'view' => 'Видели страницата', 'gate' => 'Минали попъпа', 'box' => 'Избрали кутия', 'item' => 'Сложили продукт', 'full' => 'Напълнили кутията', 'order' => 'Екран „Поръчка“', 'add' => 'Към плащане', 'created' => 'Поръчка', 'paid' => 'Платена (билет)', 'activated' => 'Активиран билет' );
	const SHORT   = array( 'view' => '👀 видяла', 'gate' => '📧 попъп', 'box' => '📦 кутия', 'item' => '➕ продукт', 'full' => '✅ пълна', 'order' => '🧾 поръчка', 'add' => '💳 плащане', 'created' => '🛒 поръчала', 'paid' => '🎟️ платена', 'activated' => '🏆 активиран' );
	const NONCE   = 'ansa_promo_insights';

	public static function register() {
		add_action( 'wp_ajax_ansa_promo_beacon', array( __CLASS__, 'ajax_beacon' ) );
		add_action( 'wp_ajax_nopriv_ansa_promo_beacon', array( __CLASS__, 'ajax_beacon' ) );
		add_action( 'wp_ajax_ansa_promo_insights_feed', array( __CLASS__, 'ajax_feed' ) );
		add_action( 'wp_ajax_ansa_promo_insights_selftest', array( __CLASS__, 'ajax_selftest' ) ); /* 1.17.1 */
		add_action( 'ansa_promo_order_created', array( __CLASS__, 'on_order' ), 10, 2 );
		add_action( 'ansa_promo_ticket_issued', array( __CLASS__, 'on_ticket' ), 10, 2 );
		add_action( 'ansa_promo_ticket_activated', array( __CLASS__, 'on_activated' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 20, 3 );
	}

	/* ── v1.3.0: дневен rollup (пази се) ── */
	public static function hit( $step, $box = '', $utm = '' ) {
		global $wpdb;
		if ( ! in_array( $step, self::STEPS, true ) ) { return false; }
		$box = substr( preg_replace( '/[^a-z]/', '', (string) $box ), 0, 8 ); $utm = substr( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $utm ), 0, 64 );
		$t = Schema::table( 'stats' ); $day = current_time( 'Y-m-d' );
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $t SET n = n + 1 WHERE day = %s AND step = %s AND box = %s AND utm = %s", $day, $step, $box, $utm ) );
		if ( ! $n ) {
			$ok = $wpdb->insert( $t, array( 'day' => $day, 'step' => $step, 'box' => $box, 'utm' => $utm, 'n' => 1 ), array( '%s', '%s', '%s', '%s', '%d' ) );
			if ( ! $ok ) { $wpdb->query( $wpdb->prepare( "UPDATE $t SET n = n + 1 WHERE day = %s AND step = %s AND box = %s AND utm = %s", $day, $step, $box, $utm ) ); }
		}
		return true;
	}

	/* ── Insights 2: сесии и събития ── */
	private static function sid( $v ) { $v = substr( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $v ), 0, 40 ); return strlen( $v ) >= 8 ? $v : ''; }
	/** 1.17.1: sid и от бисквитка — кешираната страница (FlyingPress) върти старото promo.js без sid, а runtime ajax-ът минава през PHP
	 *  при всяко отваряне; така сесията се брои и без нов JS. Бисквитката се слага от ajax отговора (1 ден). */
	const COOKIE = 'ansa_promo_sid';
	public static function cookie_sid( $create = false ) {
		$v = self::sid( $_COOKIE[ self::COOKIE ] ?? '' );
		if ( '' === $v && $create ) {
			/* 1.17.2: без бисквитка (блокирани, или headers вече пратени) — отпечатък IP + User-Agent + ден: същият човек = същата сесия за деня */
			$v = 'h' . substr( md5( Leads::ip() . '|' . (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) . '|' . current_time( 'Y-m-d' ) . '|' . wp_salt( 'nonce' ) ), 0, 16 );
			if ( ! headers_sent() ) { @setcookie( self::COOKIE, $v, array( 'expires' => time() + DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ); }
			$_COOKIE[ self::COOKIE ] = $v;
		}
		return $v;
	}
	/** sid от заявката или от бисквитката (създава я). */
	public static function request_sid( $v = '' ) { $s = self::sid( $v ); return '' !== $s ? $s : self::cookie_sid( true ); }
	private static function tok( $v, $len = 64, $re = '/[^a-z0-9_\-\.]/i' ) { return substr( preg_replace( $re, '', (string) $v ), 0, $len ); }

	/** Записва събитие и опреснява сесията. $d: sid, ev, box, utm, k, n, lead, items, src, med, camp, ref, dev. */
	public static function track( $d ) {
		global $wpdb;
		$sid = self::sid( $d['sid'] ?? '' ); $ev = sanitize_key( (string) ( $d['ev'] ?? '' ) );
		if ( '' === $sid || ! isset( self::ORDER[ $ev ] ) ) { return false; }
		$box = self::tok( $d['box'] ?? '', 8, '/[^a-z]/' ); $utm = self::tok( $d['utm'] ?? '', 64 ); $k = self::tok( $d['k'] ?? '', 64 ); $n = max( 0, min( 65535, (int) ( $d['n'] ?? 0 ) ) );
		$now = current_time( 'mysql' ); $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, step, box, utm FROM $ts WHERE sid = %s", $sid ), ARRAY_A );
		$upd = array( 'last_at' => $now );
		if ( $box ) { $upd['box'] = $box; }
		if ( $utm ) { $upd['utm'] = $utm; }
		if ( ! empty( $d['lead'] ) ) { $upd['lead_id'] = (int) $d['lead']; }
		if ( isset( $d['items'] ) && is_array( $d['items'] ) ) { $upd['items'] = wp_json_encode( array_slice( array_map( function ( $x ) { return self::tok( $x, 64 ); }, array_values( $d['items'] ) ), 0, 40 ) ); }
		if ( ! empty( $d['order_id'] ) ) { $upd['order_id'] = (int) $d['order_id']; }
		$ord = self::ORDER[ $ev ];
		/* 1.17.1: сървърна дедупликация — стъпка веднъж на сесия (box: веднъж на кутия; item: веднъж на продукт); клиентът дедупира само в таба */
		if ( $row ) {
			$dup = 'item' === $ev ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $te WHERE sid = %s AND ev = %s AND k = %s LIMIT 1", $sid, $ev, $k ) )
				: ( 'box' === $ev ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $te WHERE sid = %s AND ev = %s AND box = %s LIMIT 1", $sid, $ev, $box ) ) : $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $te WHERE sid = %s AND ev = %s LIMIT 1", $sid, $ev ) ) );
			if ( $dup ) { $wpdb->update( $ts, $upd, array( 'id' => (int) $row['id'] ) ); return true; }
		}
		if ( ! $row ) {
			$ins = array_merge( array(
				'sid' => $sid, 'started_at' => $now, 'utm' => $utm, 'src' => self::tok( $d['src'] ?? '', 64 ), 'med' => self::tok( $d['med'] ?? '', 64 ), 'camp' => self::tok( $d['camp'] ?? '', 128, '/[^a-z0-9_\-\.\s]/i' ),
				'ref' => substr( esc_url_raw( (string) ( $d['ref'] ?? '' ) ), 0, 191 ), 'device' => in_array( $d['dev'] ?? '', array( 'mobile', 'desktop' ), true ) ? $d['dev'] : '', 'box' => $box, 'step' => 'item' === $ev ? 'box' : $ev, 'ip' => Leads::ip(),
			), $upd );
			$wpdb->insert( $ts, $ins );
		} else {
			$cur = self::ORDER[ $row['step'] ] ?? 0;
			if ( $ord > $cur && 'item' !== $ev ) { $upd['step'] = $ev; }
			if ( ! $box && $row['box'] ) { $box = $row['box']; }
			if ( ! $utm && $row['utm'] ) { $utm = $row['utm']; }
			$wpdb->update( $ts, $upd, array( 'id' => (int) $row['id'] ) );
		}
		$wpdb->insert( $te, array( 'sid' => $sid, 'at' => $now, 'ev' => $ev, 'box' => $box, 'k' => $k, 'n' => $n, 'utm' => $utm ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) );
		return true;
	}

	public static function ajax_beacon() {
		/* 1.17.0: без nonce — кешираната страница (FlyingPress) носи изтекъл nonce и всички „view“ падаха с 403; броячът е публичен, с whitelist и rate limit */
		if ( Leads::rate_limited( 'beacon', 120 ) ) { wp_send_json_error( array( 'code' => 'rate' ), 429 ); }
		$ev = sanitize_key( (string) ( $_POST['ev'] ?? $_POST['step'] ?? '' ) );
		if ( ! in_array( $ev, self::CLIENT, true ) ) { wp_send_json_error( array( 'code' => 'step' ), 400 ); }
		if ( ! empty( $_POST['draft'] ) || ! empty( $_POST['editor'] ) ) { wp_send_json_success( array( 'skipped' => true ) ); } /* черновата/редакторът не броят */
		$box = (string) ( $_POST['box'] ?? '' ); $utm = (string) ( $_POST['utm'] ?? '' );
		if ( 'item' !== $ev ) { self::hit( $ev, $box, $utm ); }
		$items = json_decode( (string) wp_unslash( $_POST['items'] ?? '' ), true );
		self::track( array(
			'sid' => self::request_sid( (string) ( $_POST['sid'] ?? '' ) ), 'ev' => $ev, 'box' => $box, 'utm' => $utm, 'k' => (string) ( $_POST['k'] ?? '' ), 'n' => (int) ( $_POST['n'] ?? 0 ), 'lead' => (int) ( $_POST['lead'] ?? 0 ),
			'items' => is_array( $items ) ? $items : null, 'src' => (string) ( $_POST['src'] ?? '' ), 'med' => (string) ( $_POST['med'] ?? '' ), 'camp' => (string) ( $_POST['camp'] ?? '' ), 'ref' => (string) wp_unslash( $_POST['ref'] ?? '' ), 'dev' => (string) ( $_POST['dev'] ?? '' ),
		) );
		wp_send_json_success( array( 'ok' => true ) );
	}

	/** 1.17.1: runtime ajax-ът (promo.js при всяко отваряне, и от кеширана страница) брои „view“ със sid от заявката или от бисквитката. */
	public static function on_runtime() {
		if ( Frontend::is_draft_request() || ! empty( $_GET['editor'] ) ) { return; }
		$pub = Config::get_published(); if ( ! Config::is_live( $pub ) ) { return; }
		$sid = self::request_sid( (string) ( $_GET['sid'] ?? '' ) ); if ( '' === $sid ) { return; }
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		self::track( array( 'sid' => $sid, 'ev' => 'view', 'utm' => (string) ( $_GET['utm'] ?? '' ), 'ref' => (string) ( $_SERVER['HTTP_REFERER'] ?? '' ), 'dev' => preg_match( '/Mobile|Android|iPhone|iPad/i', $ua ) ? 'mobile' : 'desktop' ) );
	}

	/** 1.17.1: самодиагностика — таблици, редове, последно събитие, режимът на играта. */
	public static function diagnostics() {
		global $wpdb; $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' ); $tst = Schema::table( 'stats' ); $pub = Config::get_published();
		$ok_s = Schema::exists( $ts ); $ok_e = Schema::exists( $te );
		$out = array( 'tables' => $ok_s && $ok_e, 'db_ver' => (string) get_option( Schema::OPT_DB_VER, '' ), 'sessions' => $ok_s ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ts" ) : 0, 'events' => $ok_e ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $te" ) : 0,
			'last_event' => $ok_e ? (string) $wpdb->get_var( "SELECT MAX(at) FROM $te" ) : '', 'legacy_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(n),0) FROM $tst WHERE day = %s AND step = %s", current_time( 'Y-m-d' ), 'view' ) ),
			'live' => Config::is_live( $pub ), 'enabled' => ! empty( $pub['enabled'] ), 'preview_pw' => ! empty( Config::get_draft()['preview']['enabled'] ), 'page_id' => (int) $pub['page_id'], 'cache' => class_exists( '\\FlyingPress\\Purge' ) ? 'FlyingPress' : ( defined( 'WP_CACHE' ) && WP_CACHE ? 'да (WP_CACHE)' : 'не е засечен' ), 'db_error' => (string) $wpdb->last_error );
		return $out;
	}
	public static function ajax_selftest() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'auth' ), 403 ); }
		global $wpdb; $sid = 'selftest' . substr( md5( uniqid( '', true ) ), 0, 12 ); $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' );
		$wpdb->last_error = '';
		$ok1 = self::track( array( 'sid' => $sid, 'ev' => 'view', 'utm' => 'selftest', 'dev' => 'desktop' ) ); $e1 = $wpdb->last_error;
		$ok2 = self::track( array( 'sid' => $sid, 'ev' => 'item', 'k' => 'selftest', 'n' => 1 ) ); $e2 = $wpdb->last_error;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ts WHERE sid = %s", $sid ), ARRAY_A ); $n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $te WHERE sid = %s", $sid ) );
		$wpdb->delete( $ts, array( 'sid' => $sid ) ); $wpdb->delete( $te, array( 'sid' => $sid ) );
		$ok = $ok1 && $ok2 && $row && 2 === $n;
		wp_send_json_success( array( 'ok' => $ok, 'msg' => $ok ? 'Записът в базата работи: сесия + 2 събития (изтрити след теста).' : 'Записът НЕ минава: ' . ( $e1 ?: $e2 ?: ( $row ? 'събития: ' . $n . ' вместо 2' : 'сесията не е записана' ) ), 'diag' => self::diagnostics() ) );
	}

	/* сървърните стъпки — през sid-а, записан в поръчката (Cart::build_meta → _ansa_promo.sid) */
	private static function order_sid( $order ) { $m = $order ? (array) $order->get_meta( '_ansa_promo' ) : array(); return (string) ( $m['sid'] ?? '' ); }
	public static function on_order( $order, $m ) {
		if ( ! empty( $m['draft'] ) ) { return; }
		self::hit( 'created', (string) ( $m['box'] ?? '' ), (string) ( $m['utm'] ?? '' ) );
		if ( ! empty( $m['sid'] ) ) { self::track( array( 'sid' => $m['sid'], 'ev' => 'created', 'box' => (string) ( $m['box'] ?? '' ), 'utm' => (string) ( $m['utm'] ?? '' ), 'order_id' => $order->get_id(), 'lead' => (int) ( $m['lead_id'] ?? 0 ), 'n' => (int) ( $m['count'] ?? 0 ) ) ); }
	}
	public static function on_ticket( $row, $order ) {
		$m = (array) $order->get_meta( '_ansa_promo' ); if ( ! empty( $m['draft'] ) ) { return; }
		self::hit( 'paid', (string) ( $row['box'] ?? '' ), (string) ( $m['utm'] ?? '' ) );
		if ( ! empty( $m['sid'] ) ) { self::track( array( 'sid' => $m['sid'], 'ev' => 'paid', 'box' => (string) ( $row['box'] ?? '' ), 'utm' => (string) ( $m['utm'] ?? '' ), 'order_id' => $order->get_id(), 'n' => (int) ( $row['tickets'] ?? 0 ) ) ); }
	}
	public static function on_activated( $row, $user ) {
		$o = wc_get_order( (int) $row['order_id'] ); $m = $o ? (array) $o->get_meta( '_ansa_promo' ) : array();
		self::hit( 'activated', (string) ( $row['box'] ?? '' ), (string) ( $m['utm'] ?? '' ) );
		if ( ! empty( $m['sid'] ) ) { self::track( array( 'sid' => $m['sid'], 'ev' => 'activated', 'box' => (string) ( $row['box'] ?? '' ), 'utm' => (string) ( $m['utm'] ?? '' ), 'order_id' => (int) $row['order_id'] ) ); }
	}
	/** Статусът на поръчката се чете на живо във фийда; тук само опресняваме last_at, за да изплува сесията. */
	public static function on_status( $order_id, $from, $to ) {
		global $wpdb; $o = wc_get_order( (int) $order_id ); $sid = self::order_sid( $o ); if ( '' === $sid ) { return; }
		$wpdb->update( Schema::table( 'sessions' ), array( 'last_at' => current_time( 'mysql' ) ), array( 'sid' => $sid ) );
	}

	/* ── отчети ── */
	private static function since( $days ) { return $days > 0 ? gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( (int) $days - 1 ) . ' days', current_time( 'timestamp' ) ) ) : ''; }

	/** Фунията по сесии за период: колко сесии са стигнали поне до всяка стъпка + разбивки. */
	public static function sessions_report( $days = 30 ) {
		global $wpdb; $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' );
		$since = self::since( $days ); $w = $since ? $wpdb->prepare( 'WHERE started_at >= %s', $since ) : '';
		$rows = $wpdb->get_results( "SELECT utm, src, device, box, step, items, lead_id, order_id, started_at FROM $ts $w", ARRAY_A );
		$out = array( 'sessions' => count( (array) $rows ), 'funnel' => array_fill_keys( self::FUNNEL, 0 ), 'by_utm' => array(), 'by_src' => array(), 'by_dev' => array(), 'by_box' => array(), 'by_day' => array(), 'items' => array(), 'leads' => 0, 'orders' => 0 );
		foreach ( (array) $rows as $r ) {
			$o = self::ORDER[ $r['step'] ] ?? 1;
			foreach ( self::FUNNEL as $st ) { if ( $o >= self::ORDER[ $st ] ) { $out['funnel'][ $st ]++; } }
			if ( $r['lead_id'] ) { $out['leads']++; } if ( $r['order_id'] ) { $out['orders']++; }
			$day = substr( (string) $r['started_at'], 0, 10 );
			foreach ( array( 'by_utm' => $r['utm'] ?: '—', 'by_src' => $r['src'] ?: ( $r['utm'] ? 'utm' : 'директно' ), 'by_dev' => $r['device'] ?: '—', 'by_box' => $r['box'] ?: '—', 'by_day' => $day ) as $grp => $key ) {
				if ( ! isset( $out[ $grp ][ $key ] ) ) { $out[ $grp ][ $key ] = array( 'n' => 0, 'gate' => 0, 'box' => 0, 'full' => 0, 'add' => 0, 'created' => 0, 'paid' => 0 ); }
				$out[ $grp ][ $key ]['n']++;
				foreach ( array( 'gate', 'box', 'full', 'add', 'created', 'paid' ) as $st ) { if ( $o >= self::ORDER[ $st ] ) { $out[ $grp ][ $key ][ $st ]++; } }
			}
			$items = $r['items'] ? json_decode( $r['items'], true ) : null;
			if ( is_array( $items ) ) { foreach ( array_count_values( array_map( 'strval', $items ) ) as $k => $q ) { if ( ! isset( $out['items'][ $k ] ) ) { $out['items'][ $k ] = array( 'sessions' => 0, 'packs' => 0, 'ordered' => 0 ); } $out['items'][ $k ]['sessions']++; $out['items'][ $k ]['packs'] += $q; if ( $r['order_id'] ) { $out['items'][ $k ]['ordered'] += $q; } } }
		}
		/* „цъкнал“ продукт — събитията item (и без да е напълнил) */
		$we = $since ? $wpdb->prepare( 'WHERE at >= %s AND ev = %s', $since, 'item' ) : $wpdb->prepare( 'WHERE ev = %s', 'item' );
		foreach ( (array) $wpdb->get_results( "SELECT k, COUNT(DISTINCT sid) AS s, COUNT(*) AS c FROM $te $we GROUP BY k", ARRAY_A ) as $r ) { if ( ! isset( $out['items'][ $r['k'] ] ) ) { $out['items'][ $r['k'] ] = array( 'sessions' => 0, 'packs' => 0, 'ordered' => 0 ); } $out['items'][ $r['k'] ]['clicked'] = (int) $r['s']; $out['items'][ $r['k'] ]['clicks'] = (int) $r['c']; }
		foreach ( array( 'by_utm', 'by_src', 'by_dev', 'by_box' ) as $g ) { uasort( $out[ $g ], function ( $a, $b ) { return $b['n'] <=> $a['n']; } ); }
		ksort( $out['by_day'] ); uasort( $out['items'], function ( $a, $b ) { return ( $b['sessions'] + ( $b['clicked'] ?? 0 ) ) <=> ( $a['sessions'] + ( $a['clicked'] ?? 0 ) ); } );
		return $out;
	}

	/** Последните сесии за фийда: кой (лийд имейл), откъде, кутия, продукти, стъпки с време, поръчка на живо. */
	public static function feed( $limit = 40, $since_id = 0 ) {
		global $wpdb; $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' ); $tl = Schema::table( 'leads' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.*, l.email AS lead_email FROM $ts s LEFT JOIN $tl l ON l.id = s.lead_id ORDER BY s.last_at DESC LIMIT %d", max( 1, min( 200, (int) $limit ) ) ), ARRAY_A );
		if ( ! $rows ) { return array(); }
		$sids = array_map( function ( $r ) { return $r['sid']; }, $rows );
		$in = implode( ',', array_fill( 0, count( $sids ), '%s' ) );
		$evs = $wpdb->get_results( $wpdb->prepare( "SELECT sid, at, ev, box, k, n FROM $te WHERE sid IN ($in) ORDER BY id ASC", $sids ), ARRAY_A );
		$by = array(); foreach ( (array) $evs as $e ) { $by[ $e['sid'] ][] = $e; }
		$names = self::product_names(); $boxes = self::box_names();
		$out = array();
		foreach ( $rows as $r ) {
			$o = $r['order_id'] ? wc_get_order( (int) $r['order_id'] ) : null;
			$items = $r['items'] ? json_decode( $r['items'], true ) : array(); $items = is_array( $items ) ? $items : array();
			$ic = array(); foreach ( array_count_values( array_map( 'strval', $items ) ) as $k => $q ) { $ic[] = ( $q > 1 ? $q . '× ' : '' ) . ( $names[ $k ] ?? $k ); }
			$timeline = array(); $seen = array();
			foreach ( (array) ( $by[ $r['sid'] ] ?? array() ) as $e ) {
				$key = $e['ev'] . '|' . $e['k']; if ( isset( $seen[ $key ] ) && 'item' !== $e['ev'] ) { continue; } $seen[ $key ] = 1;
				$timeline[] = array( 'ev' => $e['ev'], 't' => substr( $e['at'], 11, 5 ), 'l' => self::SHORT[ $e['ev'] ] ?? $e['ev'], 'x' => 'item' === $e['ev'] ? ( $names[ $e['k'] ] ?? $e['k'] ) : ( 'box' === $e['ev'] ? ( $boxes[ $e['box'] ] ?? $e['box'] ) : '' ) );
			}
			$out[] = array(
				'id' => (int) $r['id'], 'sid' => $r['sid'], 'started' => $r['started_at'], 'last' => $r['last_at'], 'ago' => human_time_diff( strtotime( $r['last_at'] ), current_time( 'timestamp' ) ),
				'utm' => $r['utm'], 'utm_name' => $r['utm'] ? ( $names[ $r['utm'] ] ?? $r['utm'] ) : '', 'src' => trim( $r['src'] . ( $r['med'] ? ' / ' . $r['med'] : '' ) . ( $r['camp'] ? ' · ' . $r['camp'] : '' ) ), 'ref' => $r['ref'] ? (string) wp_parse_url( $r['ref'], PHP_URL_HOST ) : '', 'device' => $r['device'],
				'email' => $r['lead_email'] ? (string) $r['lead_email'] : ( $o ? (string) $o->get_billing_email() : '' ), 'name' => $o ? trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) : '',
				'box' => $r['box'], 'box_name' => $boxes[ $r['box'] ] ?? $r['box'], 'items' => $ic, 'step' => $r['step'], 'step_label' => self::SHORT[ $r['step'] ] ?? $r['step'], 'timeline' => $timeline,
				'order' => $o ? array( 'id' => $o->get_id(), 'number' => $o->get_order_number(), 'status' => wc_get_order_status_name( $o->get_status() ), 'total' => $o->get_total(), 'edit' => $o->get_edit_order_url() ) : null,
			);
		}
		return $out;
	}
	private static function product_names() { static $n = null; if ( null === $n ) { $n = array(); foreach ( (array) Config::get_published()['products'] as $p ) { $n[ $p['key'] ] = $p['name'] ?: $p['key']; } } return $n; }
	private static function box_names() { static $n = null; if ( null === $n ) { $n = array(); foreach ( (array) Config::get_published()['boxes'] as $b ) { $n[ $b['id'] ] = $b['ic'] . ' ' . $b['name']; } } return $n; }

	public static function ajax_feed() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'auth' ), 403 ); }
		$days = (int) ( $_GET['days'] ?? 30 );
		$feed = self::feed( (int) ( $_GET['limit'] ?? 40 ) );
		wp_send_json_success( array( 'feed' => $feed, 'rows_html' => self::feed_rows( $feed ), 'kpi' => self::kpi( self::sessions_report( $days ) ), 'at' => date_i18n( 'H:i:s' ) ) );
	}
	public static function kpi( $r ) {
		$f = $r['funnel'];
		return array( 'sessions' => $r['sessions'], 'gate' => $f['gate'], 'box' => $f['box'], 'full' => $f['full'], 'add' => $f['add'], 'created' => $f['created'], 'paid' => $f['paid'], 'leads' => $r['leads'], 'orders' => $r['orders'], 'conv' => self::pct( $f['created'], $r['sessions'] ) );
	}

	/* ── v1.3.0: старият rollup (CSV, съвместимост) ── */
	public static function report( $days = 30 ) {
		global $wpdb; $t = Schema::table( 'stats' );
		$where = $days > 0 ? $wpdb->prepare( 'WHERE day >= %s', gmdate( 'Y-m-d', strtotime( '-' . ( (int) $days - 1 ) . ' days', current_time( 'timestamp' ) ) ) ) : '';
		$rows = $wpdb->get_results( "SELECT day, step, box, utm, n FROM $t $where", ARRAY_A );
		$out = array( 'by_step' => array_fill_keys( self::STEPS, 0 ), 'by_box' => array(), 'by_utm' => array(), 'days' => array(), 'rows' => count( (array) $rows ) );
		foreach ( (array) $rows as $r ) {
			$n = (int) $r['n']; $out['by_step'][ $r['step'] ] = ( $out['by_step'][ $r['step'] ] ?? 0 ) + $n;
			$b = $r['box'] ?: '—'; $u = $r['utm'] ?: '—';
			$out['by_box'][ $b ][ $r['step'] ] = ( $out['by_box'][ $b ][ $r['step'] ] ?? 0 ) + $n;
			$out['by_utm'][ $u ][ $r['step'] ] = ( $out['by_utm'][ $u ][ $r['step'] ] ?? 0 ) + $n;
			$out['days'][ $r['day'] ][ $r['step'] ] = ( $out['days'][ $r['day'] ][ $r['step'] ] ?? 0 ) + $n;
		}
		ksort( $out['days'] ); uasort( $out['by_utm'], function ( $a, $b ) { return ( $b['view'] ?? 0 ) <=> ( $a['view'] ?? 0 ); } );
		return $out;
	}
	public static function csv( $days = 30 ) {
		global $wpdb; $t = Schema::table( 'stats' );
		$where = $days > 0 ? $wpdb->prepare( 'WHERE day >= %s', gmdate( 'Y-m-d', strtotime( '-' . ( (int) $days - 1 ) . ' days', current_time( 'timestamp' ) ) ) ) : '';
		$rows = $wpdb->get_results( "SELECT day, step, box, utm, n FROM $t $where ORDER BY day, step, box, utm", ARRAY_A );
		$fh = fopen( 'php://temp', 'w+' ); fputs( $fh, "\xEF\xBB\xBF" ); fputcsv( $fh, array( 'day', 'step', 'box', 'utm', 'n' ), ';' );
		foreach ( (array) $rows as $r ) { fputcsv( $fh, $r, ';' ); }
		rewind( $fh ); $csv = stream_get_contents( $fh ); fclose( $fh ); return $csv;
	}
	/** 1.17.0: CSV на сесиите за период. */
	public static function csv_sessions( $days = 30 ) {
		global $wpdb; $ts = Schema::table( 'sessions' ); $tl = Schema::table( 'leads' );
		$since = self::since( $days ); $w = $since ? $wpdb->prepare( 'WHERE s.started_at >= %s', $since ) : '';
		$rows = $wpdb->get_results( "SELECT s.sid, s.started_at, s.last_at, s.utm, s.src, s.med, s.camp, s.ref, s.device, l.email, s.box, s.items, s.step, s.order_id FROM $ts s LEFT JOIN $tl l ON l.id = s.lead_id $w ORDER BY s.started_at", ARRAY_A );
		$fh = fopen( 'php://temp', 'w+' ); fputs( $fh, "\xEF\xBB\xBF" ); fputcsv( $fh, array( 'sid', 'започната', 'последно', 'utm', 'source', 'medium', 'campaign', 'referrer', 'устройство', 'имейл', 'кутия', 'продукти', 'стъпка', 'поръчка' ), ';' );
		foreach ( (array) $rows as $r ) { $it = $r['items'] ? json_decode( $r['items'], true ) : array(); $r['items'] = is_array( $it ) ? implode( ' ', $it ) : ''; fputcsv( $fh, $r, ';' ); }
		rewind( $fh ); $csv = stream_get_contents( $fh ); fclose( $fh ); return $csv;
	}
	public static function pct( $a, $b ) { return $b > 0 ? number_format( 100 * $a / $b, 1, ',', '' ) . '%' : '—'; }

	/* ── екранът 📈 Insights (в рамката на приложението: карти .ap-sec) ── */
	public static function render( $days ) {
		$days = in_array( (int) $days, array( 1, 7, 30, 90, 0 ), true ) ? (int) $days : 30;
		$r = self::sessions_report( $days ); $f = $r['funnel']; $names = self::product_names(); $boxes = self::box_names(); $legacy = self::report( $days );
		$cols = array( 'n' => 'сесии', 'gate' => 'попъп', 'box' => 'кутия', 'full' => 'пълна', 'add' => 'плащане', 'created' => 'поръчка', 'paid' => 'платена' );
		$tbl = function ( $title, $rows, $first, $fmt = null ) use ( $cols ) {
			$h = '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">' . $title . '</span></div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>' . esc_html( $first ) . '</th>';
			foreach ( $cols as $c ) { $h .= '<th>' . esc_html( $c ) . '</th>'; } $h .= '<th>конверсия</th></tr></thead><tbody>';
			foreach ( $rows as $k => $v ) { $h .= '<tr><td><b>' . esc_html( $fmt ? $fmt( $k ) : $k ) . '</b></td>'; foreach ( array_keys( $cols ) as $c ) { $h .= '<td>' . (int) $v[ $c ] . '</td>'; } $h .= '<td>' . esc_html( self::pct( $v['created'], $v['n'] ) ) . '</td></tr>'; }
			if ( ! $rows ) { $h .= '<tr><td colspan="9"><small>още няма данни за периода</small></td></tr>'; }
			return $h . '</tbody></table></div></div>';
		};
		$chips = ''; foreach ( array( 1 => 'днес', 7 => '7 дни', 30 => '30 дни', 90 => '90 дни', 0 => 'всичко' ) as $d => $l ) { $chips .= '<a class="ap-chip' . ( $d === $days ? ' on' : '' ) . '" href="' . esc_url( Admin::url( array( 'tab' => 'insights', 'days' => $d ) ) ) . '">' . esc_html( $l ) . '</a>'; }
		echo '<div class="ap-actions" style="padding:0 0 12px"><div class="ap-chips">' . $chips . '</div><span class="sp"></span>';
		Admin::form_open_public( 'export_sessions', 'insights' ); echo '<input type="hidden" name="days" value="' . (int) $days . '"><button class="button">⬇ CSV сесии</button></form> ';
		Admin::form_open_public( 'export_stats', 'insights' ); echo '<input type="hidden" name="days" value="' . (int) $days . '"><button class="button">⬇ CSV стар rollup</button></form></div>';
		/* KPI */
		$k = self::kpi( $r ); global $wpdb; $first = (string) $wpdb->get_var( 'SELECT MIN(started_at) FROM ' . Schema::table( 'sessions' ) );
		echo '<div class="ap-kpis" style="padding:0 0 14px" id="apInsKpi">'
			. '<div class="ap-kpi"><b data-k="sessions">' . (int) $k['sessions'] . '</b><span>сесии (влезли)' . ( $first ? ' · от ' . esc_html( date_i18n( 'd.m H:i', strtotime( $first ) ) ) : '' ) . '</span></div>'
			. '<div class="ap-kpi"><b>' . (int) $legacy['by_step']['view'] . '</b><span>видели (стар брояч, цял период)</span></div>'
			. '<div class="ap-kpi"><b data-k="gate">' . (int) $k['gate'] . '</b><span>минали попъпа</span></div>'
			. '<div class="ap-kpi"><b data-k="box">' . (int) $k['box'] . '</b><span>избрали кутия</span></div>'
			. '<div class="ap-kpi"><b data-k="full">' . (int) $k['full'] . '</b><span>напълнили</span></div>'
			. '<div class="ap-kpi"><b data-k="add">' . (int) $k['add'] . '</b><span>към плащане</span></div>'
			. '<div class="ap-kpi good"><b data-k="created">' . (int) $k['created'] . '</b><span>поръчали</span></div>'
			. '<div class="ap-kpi good"><b data-k="paid">' . (int) $k['paid'] . '</b><span>платени (билет)</span></div>'
			. '<div class="ap-kpi"><b data-k="conv">' . esc_html( $k['conv'] ) . '</b><span>конверсия сесия → поръчка</span></div>'
			. '</div>';
		/* 1.17.1: диагностика — защо е 0 */
		$dg = self::diagnostics(); $bad = ! $dg['tables'] || ! $dg['live'];
		echo '<div class="ap-sec' . ( $bad ? ' ap-sec--danger' : '' ) . '"><div class="ap-sec__h"><span class="ap-sec__t">🩺 Работи ли отчитането?</span><button type="button" class="ap-btn ap-btn--secondary ap-btn--sm" id="apInsTest">▶ Тест на записа</button></div><div class="ap-sec__b"><div class="ap-env" style="padding:0">'
			. '<div class="ap-env__r ' . ( $dg['tables'] ? 'ok' : 'bad' ) . '"><span class="ap-env__dot ' . ( $dg['tables'] ? 'ok' : 'bad' ) . '"></span><span class="ap-env__l">Таблици</span><span class="ap-env__v">' . ( $dg['tables'] ? 'sessions + events са налице' : 'ЛИПСВАТ — деактивирай/активирай плъгина (Schema::install)' ) . ' · DB ver ' . esc_html( $dg['db_ver'] ) . ( $dg['db_error'] ? ' · грешка: ' . esc_html( $dg['db_error'] ) : '' ) . '</span></div>'
			. '<div class="ap-env__r ' . ( $dg['events'] ? 'ok' : 'warn' ) . '"><span class="ap-env__dot ' . ( $dg['events'] ? 'ok' : 'warn' ) . '"></span><span class="ap-env__l">Записани досега</span><span class="ap-env__v">' . (int) $dg['sessions'] . ' сесии · ' . (int) $dg['events'] . ' събития · последно: ' . esc_html( $dg['last_event'] ?: 'още нищо' ) . ' · стар rollup днес (view): ' . (int) $dg['legacy_today'] . '</span></div>'
			. '<div class="ap-env__r ' . ( $dg['live'] ? 'ok' : 'bad' ) . '"><span class="ap-env__dot ' . ( $dg['live'] ? 'ok' : 'bad' ) . '"></span><span class="ap-env__l">Играта</span><span class="ap-env__v">' . ( $dg['live'] ? 'ВКЛЮЧЕНА — публичните посещения се броят' : ( $dg['enabled'] ? 'изтекла — не се брои нищо' : 'ИЗКЛЮЧЕНА — публичната страница е „скоро“, не се брои нищо' ) ) . ( $dg['preview_pw'] ? ' · прегледът с парола е включен: екипът с паролата вижда черновата и НЕ се брои' : '' ) . '</span></div>'
			. '<div class="ap-env__r info"><span class="ap-env__dot info"></span><span class="ap-env__l">Кеш на страницата</span><span class="ap-env__v">' . esc_html( $dg['cache'] ) . ' — от 1.17.1 „view“ се брои и от кеширана страница (runtime ajax + бисквитка), но продуктите/кутията идват от новото promo.js: след ъпдейт изчисти кеша (🧰 Настройки → Публикуване → 🧹).</span></div>'
			. '</div><div class="ap-note" id="apInsTestOut" style="padding:8px 4px 0"></div></div></div>';
		/* фуния */
		$max = max( 1, (int) $f['view'] );
		echo '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🔻 Фунията по сесии</span></div><div class="ap-sec__hint">Всяка сесия се брои веднъж на стъпка — „докъде е стигнала“. Процентът е спрямо видели / спрямо предишната стъпка.</div><div class="ap-funnel">';
		$prev = null;
		foreach ( self::FUNNEL as $st ) { $n = (int) $f[ $st ]; $w = max( 2, round( 100 * $n / $max ) ); echo '<div class="ap-fn"><span class="ap-fn__l">' . esc_html( self::LABELS[ $st ] ) . '</span><span class="ap-fn__bar"><i style="width:' . $w . '%"></i></span><b class="ap-fn__n">' . $n . '</b><small>' . esc_html( self::pct( $n, $max ) ) . ( null !== $prev ? ' · ' . esc_html( self::pct( $n, $prev ) ) . ' от предишната' : '' ) . '</small></div>'; $prev = $n ?: $prev; }
		echo '</div></div>';
		/* жив фийд */
		echo '<div class="ap-sec ap-sec--table" id="apInsFeed"><div class="ap-sec__h"><span class="ap-sec__t">🟢 На живо — последните сесии</span><label class="ap-tglwrap on" style="margin-left:auto"><input type="checkbox" id="apInsLive" checked><span class="ap-tgl"></span>опреснява на 20 с</label><small id="apInsAt" style="margin-left:8px"></small></div>'
			. '<div class="ap-sec__hint">Кой е влязъл (имейлът от попъпа или от поръчката), откъде, какво е цъкнал и докъде е стигнал. Статусът на поръчката е на живо от WooCommerce.</div><div class="ap-sec__b"><table class="widefat striped" id="apInsFeedT"><thead><tr><th>кога</th><th>кой</th><th>откъде</th><th>устр.</th><th>кутия · продукти</th><th>пътят</th><th>поръчка</th></tr></thead><tbody>' . self::feed_rows( self::feed( 40 ) ) . '</tbody></table></div></div>';
		/* продукти */
		echo '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🧴 Продуктите — цъкнати и в кутиите</span></div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>продукт</th><th>цъкнали (сесии)</th><th>цъквания</th><th>в пълни кутии (сесии)</th><th>опаковки</th><th>поръчани опаковки</th></tr></thead><tbody>';
		foreach ( $r['items'] as $kk => $v ) { echo '<tr><td><b>' . esc_html( $names[ $kk ] ?? $kk ) . '</b> <small>' . esc_html( $kk ) . '</small></td><td>' . (int) ( $v['clicked'] ?? 0 ) . '</td><td>' . (int) ( $v['clicks'] ?? 0 ) . '</td><td>' . (int) $v['sessions'] . '</td><td>' . (int) $v['packs'] . '</td><td>' . (int) $v['ordered'] . '</td></tr>'; }
		if ( ! $r['items'] ) { echo '<tr><td colspan="6"><small>още няма данни за периода</small></td></tr>'; }
		echo '</tbody></table></div></div>';
		/* разбивки */
		echo $tbl( '🏷️ По UTM (кацане с продукт)', $r['by_utm'], 'UTM', function ( $k ) use ( $names ) { return ( $names[ $k ] ?? $k ) . ( isset( $names[ $k ] ) ? ' (' . $k . ')' : '' ); } );
		echo $tbl( '📣 По източник (utm_source / директно)', $r['by_src'], 'източник' );
		echo $tbl( '📦 По кутия', $r['by_box'], 'кутия', function ( $k ) use ( $boxes ) { return $boxes[ $k ] ?? $k; } );
		echo $tbl( '📱 По устройство', $r['by_dev'], 'устройство' );
		echo $tbl( '📅 По дни', array_reverse( $r['by_day'], true ), 'ден' );
		/* стар rollup */
		echo '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🗂️ Старият дневен rollup (до 1.16.0 — само броячи, без сесии)</span></div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>стъпка</th><th>общо</th><th>% от видели</th></tr></thead><tbody>';
		foreach ( self::STEPS as $s ) { echo '<tr><td>' . esc_html( self::LABELS[ $s ] ) . ' <small>' . esc_html( $s ) . '</small></td><td><b>' . (int) $legacy['by_step'][ $s ] . '</b></td><td>' . esc_html( self::pct( $legacy['by_step'][ $s ], $legacy['by_step']['view'] ) ) . '</td></tr>'; }
		echo '</tbody></table></div></div>';
		/* стил + живото опресняване */
		echo '<style>.ap-funnel{padding:4px 20px 6px;display:grid;gap:7px}.ap-fn{display:grid;grid-template-columns:190px 1fr 60px 220px;gap:10px;align-items:center;font-size:12.5px}.ap-fn__l{font-weight:800}.ap-fn__bar{height:16px;background:#fdebe9;border-radius:999px;overflow:hidden}.ap-fn__bar i{display:block;height:100%;background:linear-gradient(90deg,#f8a41d,#f47a49);border-radius:999px}.ap-fn__n{font-weight:900;text-align:right}.ap-fn small{font-weight:700;color:#a08579}.ap-tl{display:flex;gap:4px;flex-wrap:wrap}.ap-tl span{font-size:10.5px;font-weight:800;background:#fdebe9;border-radius:999px;padding:1px 7px;white-space:nowrap}.ap-tl span.hi{background:#def3e7;color:#1f7a4d}.ap-tl span.ord{background:#fdeecd;color:#a86b09}@media(max-width:900px){.ap-fn{grid-template-columns:1fr 60px}.ap-fn__bar,.ap-fn small{grid-column:1/-1}}</style>';
		echo '<script>(function(){var live=document.getElementById("apInsLive"),tb=document.querySelector("#apInsFeedT tbody"),at=document.getElementById("apInsAt"),t;var ajax=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',nonce=' . wp_json_encode( wp_create_nonce( self::NONCE ) ) . ',days=' . (int) $days . ';'
			. 'function tick(){if(!live.checked)return;fetch(ajax+"?action=ansa_promo_insights_feed&nonce="+nonce+"&days="+days+"&limit=40",{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(r){if(!r||!r.success)return;tb.innerHTML=r.data.rows_html||tb.innerHTML;Object.keys(r.data.kpi||{}).forEach(function(k){var el=document.querySelector("#apInsKpi [data-k=\\""+k+"\\"]");if(el)el.textContent=r.data.kpi[k]});at.textContent="опреснено "+r.data.at}).catch(function(){})}'
			. 'function arm(){clearInterval(t);if(live.checked)t=setInterval(tick,20000)}live.addEventListener("change",function(){live.parentNode.classList.toggle("on",live.checked);arm()});arm();'
			. 'var tb2=document.getElementById("apInsTest"),out=document.getElementById("apInsTestOut");if(tb2)tb2.onclick=function(){out.textContent="… тествам";fetch(ajax+"?action=ansa_promo_insights_selftest&nonce="+nonce,{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(r){var d=r&&r.data||{};out.innerHTML=(d.ok?"<span class=\"good\">✓ ":"<span class=\"bad\">✕ ")+(d.msg||"грешка")+"</span>"}).catch(function(){out.textContent="мрежова грешка"})};})();</script>';
	}
	/** Редовете на фийда (HTML) — и за ajax опресняването. */
	public static function feed_rows( $feed ) {
		if ( ! $feed ) { return '<tr><td colspan="7"><small>Още няма сесии — стъпките се записват, когато играта е публикувана и някой отвори страницата (черновата и редакторът не броят).</small></td></tr>'; }
		$h = '';
		foreach ( $feed as $s ) {
			$who = $s['email'] ? '<b>' . esc_html( $s['email'] ) . '</b>' . ( $s['name'] ? '<br><small>' . esc_html( $s['name'] ) . '</small>' : '' ) : '<small>анонимна</small>';
			$from = ( $s['utm_name'] ? '🏷️ ' . esc_html( $s['utm_name'] ) : '' ) . ( $s['src'] ? ( $s['utm_name'] ? '<br>' : '' ) . '<small>' . esc_html( $s['src'] ) . '</small>' : '' ) . ( $s['ref'] ? '<br><small>↩ ' . esc_html( $s['ref'] ) . '</small>' : '' );
			$tl = '<div class="ap-tl">'; foreach ( $s['timeline'] as $e ) { $cls = in_array( $e['ev'], array( 'created', 'paid', 'activated' ), true ) ? ' hi' : ( in_array( $e['ev'], array( 'add', 'order' ), true ) ? ' ord' : '' ); $tl .= '<span class="' . $cls . '" title="' . esc_attr( $e['t'] ) . '">' . esc_html( $e['l'] . ( $e['x'] ? ' ' . $e['x'] : '' ) ) . '</span>'; } $tl .= '</div>';
			$ord = $s['order'] ? '<a href="' . esc_url( $s['order']['edit'] ) . '">#' . esc_html( $s['order']['number'] ) . '</a><br><small>' . esc_html( $s['order']['status'] ) . ' · ' . esc_html( number_format( (float) $s['order']['total'], 2, ',', '' ) ) . ' €</small>' : '<small>' . esc_html( $s['step_label'] ) . '</small>';
			$h .= '<tr><td><b>' . esc_html( substr( $s['last'], 11, 5 ) ) . '</b><br><small>' . esc_html( substr( $s['started'], 0, 10 ) ) . ' · преди ' . esc_html( $s['ago'] ) . '</small></td><td>' . $who . '</td><td>' . ( $from ?: '<small>директно</small>' ) . '</td><td>' . ( 'mobile' === $s['device'] ? '📱' : ( 'desktop' === $s['device'] ? '🖥️' : '—' ) ) . '</td><td>' . ( $s['box'] ? '<b>' . esc_html( $s['box_name'] ) . '</b>' : '<small>—</small>' ) . ( $s['items'] ? '<br><small>' . esc_html( implode( ', ', $s['items'] ) ) . '</small>' : '' ) . '</td><td>' . $tl . '</td><td>' . $ord . '</td></tr>';
		}
		return $h;
	}
}
