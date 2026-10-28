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
 * v1.21.0 — три екрана (?view=): 📊 Преглед (период днес/7/30/90/всичко), 🟢 На живо (отделен таб: последните 40/100/200 сесии с опресняване + KPI за
 * днес), 📜 История (от–до, по подразбиране от старта 16.10.2026: KPI, „загубени“ — напълнили, но не поръчали и т.н., фуния с посетители, докъде са
 * стигнали и къде са спрели, по дни, списък на сесиите с филтри стъпка / стигнали поне до · спрели точно на / поръчка / кутия / устройство, на страници; CSV
 * на точно този филтър). sessions_report() приема и период (range), feed() — филтрите на sessions_where().
 *
 * @package AnsaPromo
 * @since 1.3.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Insights {

	const STEPS   = array( 'view', 'gate', 'box', 'full', 'order', 'add', 'created', 'paid', 'activated' );
	const CLIENT  = array( 'view', 'gate', 'box', 'item', 'full', 'order', 'add', 'sh_view', 'sh_pick', 'sh_more', 'pop_view', 'pop_cta', 'pop_close' ); /* 1.36.0: pop_* = приветственият попъп на B */ /* 1.24.2: sh_* = 4-тата опция (3+2) в Shrine; sh_add идва само от сървъра */
	/* 1.24.2: събитията от продуктовите страници (Shrine, промо картата „5 = 3+2“): видяла картата · избрала я · „Виж повече“ · сложила кутията в количката */
	const SHRINE  = array( 'sh_view', 'sh_pick', 'sh_more', 'sh_add' );
	/* редът на стъпките за „най-далечна“ (item не е стъпка на фунията — брои се отделно) */
	const ORDER   = array( 'view' => 1, 'gate' => 2, 'box' => 3, 'item' => 3, 'full' => 4, 'order' => 5, 'add' => 6, 'created' => 7, 'paid' => 8, 'activated' => 9, 'sh_view' => 1, 'sh_pick' => 1, 'sh_more' => 1, 'sh_add' => 4 );
	const FUNNEL  = array( 'view', 'gate', 'box', 'full', 'order', 'add', 'created', 'paid', 'activated' );
	const LABELS  = array( 'view' => 'Видели страницата', 'gate' => 'Минали попъпа', 'box' => 'Избрали кутия', 'item' => 'Сложили продукт', 'full' => 'Напълнили кутията', 'order' => 'Екран „Поръчка“', 'add' => 'Към плащане', 'created' => 'Поръчка', 'paid' => 'Платена (билет)', 'activated' => 'Активиран билет' );
	const SHORT   = array( 'view' => '👀 видяла', 'gate' => '📧 попъп', 'box' => '📦 кутия', 'item' => '➕ продукт', 'full' => '✅ пълна', 'order' => '🧾 поръчка', 'add' => '💳 плащане', 'created' => '🛒 поръчала', 'paid' => '🎟️ платена', 'activated' => '🏆 активиран', 'sh_view' => '🛍️ видяла 3+2', 'sh_pick' => '👆 избрала 3+2', 'sh_more' => '👁️ виж повече', 'sh_add' => '🛒 3+2 в количката', 'pop_view' => '👋 попъп B', 'pop_cta' => '👋 „Започваме“', 'pop_close' => '👋 затворила попъпа' );
	const NONCE   = 'ansa_promo_insights';

	public static function register() {
		add_action( 'wp_ajax_ansa_promo_beacon', array( __CLASS__, 'ajax_beacon' ) );
		add_action( 'wp_ajax_nopriv_ansa_promo_beacon', array( __CLASS__, 'ajax_beacon' ) );
		add_action( 'wp_ajax_ansa_promo_insights_feed', array( __CLASS__, 'ajax_feed' ) );
		add_action( 'wp_ajax_ansa_promo_insights_selftest', array( __CLASS__, 'ajax_selftest' ) ); /* 1.17.1 */
		add_action( 'wp_ajax_ansa_promo_insights_backfill', array( __CLASS__, 'ajax_backfill' ) ); /* 1.21.1: сверка с поръчките */
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
	/** 1.28.0: посетителят (vid) — от promo.js (localStorage, 30 дни) или от бисквитка ansa_promo_vid (30 дни), която се слага тук, ако няма. */
	const VID_COOKIE = 'ansa_promo_vid';
	public static function request_vid( $v = '' ) {
		$v = self::sid( $v ); if ( '' !== $v ) { return $v; }
		$v = self::sid( $_COOKIE[ self::VID_COOKIE ] ?? '' );
		if ( '' === $v ) { $v = 'v' . substr( md5( uniqid( '', true ) . wp_salt( 'nonce' ) ), 0, 20 ); if ( ! headers_sent() ) { @setcookie( self::VID_COOKIE, $v, array( 'expires' => time() + 30 * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ); } $_COOKIE[ self::VID_COOKIE ] = $v; }
		return $v;
	}
	private static function tok( $v, $len = 64, $re = '/[^a-z0-9_\-\.]/i' ) { return substr( preg_replace( $re, '', (string) $v ), 0, $len ); }

	/** Записва събитие и опреснява сесията. $d: sid, ev, box, utm, k, n, lead, items, src, med, camp, ref, dev. */
	/** Стъпката на фунията за събитие: item → box (в кутия е сложен продукт), sh_add → full (кутията е в количката), другите sh_* → view. */
	private static function step_of( $ev ) { if ( in_array( $ev, self::FUNNEL, true ) ) { return $ev; } if ( 'item' === $ev ) { return 'box'; } if ( 'sh_add' === $ev ) { return 'full'; } return 'view'; }

	public static function track( $d ) {
		global $wpdb;
		$sid = self::sid( $d['sid'] ?? '' ); $ev = sanitize_key( (string) ( $d['ev'] ?? '' ) );
		if ( '' === $sid || ! isset( self::ORDER[ $ev ] ) ) { return false; }
		$box = self::tok( $d['box'] ?? '', 8, '/[^a-z]/' ); $utm = self::tok( $d['utm'] ?? '', 64 ); $k = self::tok( $d['k'] ?? '', 64 ); $n = max( 0, min( 65535, (int) ( $d['n'] ?? 0 ) ) );
		$vid = self::sid( $d['vid'] ?? '' ); $internal = ! empty( $d['internal'] ) ? 1 : 0; /* 1.28.0 */
		$variant = self::tok( $d['variant'] ?? '', 8, '/[^a-z]/' ); /* 1.34.0: '' = A, flow = B; първият на сесията остава */
		$now = ! empty( $d['at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $d['at'] ) ? (string) $d['at'] : current_time( 'mysql' ); /* 1.28.0: сървърните събития носят своето време (датата на поръчката) */ $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, step, box, utm, vid, internal, variant FROM $ts WHERE sid = %s", $sid ), ARRAY_A );
		$upd = array( 'last_at' => $now );
		if ( $variant && ( ! $row || '' === (string) ( $row['variant'] ?? '' ) ) ) { $upd['variant'] = $variant; } elseif ( ! $variant && $row ) { $variant = (string) ( $row['variant'] ?? '' ); }
		if ( $box ) { $upd['box'] = $box; }
		/* 1.28.0: utm = ПЪРВИЯТ UTM на сесията (не се презаписва), utm_last = последният; отчетите избират атрибуцията */
		if ( $utm ) { $upd['utm_last'] = $utm; if ( ! $row || '' === (string) $row['utm'] ) { $upd['utm'] = $utm; } }
		if ( $vid && ( ! $row || '' === (string) $row['vid'] ) ) { $upd['vid'] = $vid; }
		if ( $internal && ( ! $row || empty( $row['internal'] ) ) ) { $upd['internal'] = 1; }
		if ( ! empty( $d['lead'] ) ) { $upd['lead_id'] = (int) $d['lead']; }
		if ( isset( $d['items'] ) && is_array( $d['items'] ) ) { $upd['items'] = wp_json_encode( array_slice( array_map( function ( $x ) { return self::tok( $x, 64 ); }, array_values( $d['items'] ) ), 0, 40 ) ); }
		if ( ! empty( $d['order_id'] ) ) { $upd['order_id'] = (int) $d['order_id']; }
		$ord = self::ORDER[ $ev ];
		/* 1.17.1: сървърна дедупликация — стъпка веднъж на сесия (box: веднъж на кутия; item: веднъж на продукт); клиентът дедупира само в таба */
		if ( $row ) {
			$dup = ( 'item' === $ev || in_array( $ev, self::SHRINE, true ) ) ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $te WHERE sid = %s AND ev = %s AND k = %s LIMIT 1", $sid, $ev, $k ) )
				: ( 'box' === $ev ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $te WHERE sid = %s AND ev = %s AND box = %s LIMIT 1", $sid, $ev, $box ) ) : $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $te WHERE sid = %s AND ev = %s LIMIT 1", $sid, $ev ) ) );
			if ( $dup ) { $wpdb->update( $ts, $upd, array( 'id' => (int) $row['id'] ) ); return true; }
		}
		if ( ! $row ) {
			$ins = array_merge( array(
				'sid' => $sid, 'started_at' => $now, 'utm' => $utm, 'variant' => $variant, 'src' => self::tok( $d['src'] ?? '', 64 ), 'med' => self::tok( $d['med'] ?? '', 64 ), 'camp' => self::tok( $d['camp'] ?? '', 128, '/[^\p{L}\p{N}_\-\.\s@]/u' ), /* 1.29.0: и кирилица (операторът при 📞) */
				'ref' => substr( esc_url_raw( (string) ( $d['ref'] ?? '' ) ), 0, 191 ), 'device' => in_array( $d['dev'] ?? '', array( 'mobile', 'desktop' ), true ) ? $d['dev'] : '', 'box' => $box, 'step' => self::step_of( $ev ), 'ip' => Leads::ip(), 'utm_last' => $utm, 'vid' => $vid, 'internal' => $internal,
			), $upd );
			$wpdb->insert( $ts, $ins );
		} else {
			$cur = self::ORDER[ $row['step'] ] ?? 0; $sev = self::step_of( $ev );
			if ( $ord > $cur && ( in_array( $ev, self::FUNNEL, true ) || 'sh_add' === $ev ) ) { $upd['step'] = $sev; } /* 1.24.2: sh_add = кутията е пълна (в количката) */
			if ( ! $box && $row['box'] ) { $box = $row['box']; }
			if ( ! $utm && $row['utm'] ) { $utm = $row['utm']; }
			unset( $upd['utm'] ); if ( isset( $upd['utm_last'] ) && '' === (string) $row['utm'] ) { $upd['utm'] = $upd['utm_last']; } /* първият остава първи */
			$wpdb->update( $ts, $upd, array( 'id' => (int) $row['id'] ) );
		}
		$wpdb->insert( $te, array( 'sid' => $sid, 'at' => $now, 'ev' => $ev, 'box' => $box, 'k' => $k, 'n' => $n, 'utm' => $utm, 'variant' => $variant ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
		return true;
	}

	public static function ajax_beacon() {
		/* 1.17.0: без nonce — кешираната страница (FlyingPress) носи изтекъл nonce и всички „view“ падаха с 403; броячът е публичен, с whitelist и rate limit */
		if ( Leads::rate_limited( 'beacon', 120 ) ) { wp_send_json_error( array( 'code' => 'rate' ), 429 ); }
		$ev = sanitize_key( (string) ( $_POST['ev'] ?? $_POST['step'] ?? '' ) );
		if ( ! in_array( $ev, self::CLIENT, true ) ) { wp_send_json_error( array( 'code' => 'step' ), 400 ); }
		if ( ! empty( $_POST['draft'] ) || ! empty( $_POST['editor'] ) ) { wp_send_json_success( array( 'skipped' => true ) ); } /* черновата/редакторът не броят */
		if ( Insights_Reports::is_bot( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) ) { wp_send_json_success( array( 'skipped' => 'bot' ) ); } /* 1.28.0: ботовете не правят сесии */
		$box = (string) ( $_POST['box'] ?? '' ); $utm = (string) ( $_POST['utm'] ?? '' );
		if ( 'item' !== $ev ) { self::hit( $ev, $box, $utm ); }
		$items = json_decode( (string) wp_unslash( $_POST['items'] ?? '' ), true );
		self::track( array(
			'sid' => self::request_sid( (string) ( $_POST['sid'] ?? '' ) ), 'ev' => $ev, 'box' => $box, 'utm' => $utm, 'k' => (string) ( $_POST['k'] ?? '' ), 'n' => (int) ( $_POST['n'] ?? 0 ), 'lead' => (int) ( $_POST['lead'] ?? 0 ),
			'items' => is_array( $items ) ? $items : null, 'src' => (string) ( $_POST['src'] ?? '' ), 'med' => (string) ( $_POST['med'] ?? '' ), 'camp' => (string) ( $_POST['camp'] ?? '' ), 'ref' => (string) wp_unslash( $_POST['ref'] ?? '' ), 'dev' => (string) ( $_POST['dev'] ?? '' ),
			'vid' => self::request_vid( (string) ( $_POST['vid'] ?? '' ) ), 'internal' => Insights_Reports::is_internal_request(), /* 1.28.0 */
			'variant' => (string) ( $_POST['variant'] ?? '' ), /* 1.34.0 */
		) );
		wp_send_json_success( array( 'ok' => true ) );
	}

	/** 1.17.1: runtime ajax-ът (promo.js при всяко отваряне, и от кеширана страница) брои „view“ със sid от заявката или от бисквитката. */
	public static function on_runtime() {
		if ( Frontend::is_draft_request() || ! empty( $_GET['editor'] ) ) { return; }
		$pub = Config::get_published(); if ( ! Config::is_live( $pub ) ) { return; }
		$sid = self::request_sid( (string) ( $_GET['sid'] ?? '' ) ); if ( '' === $sid ) { return; }
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ); if ( Insights_Reports::is_bot( $ua ) ) { return; } /* 1.28.0 */
		self::track( array( 'sid' => $sid, 'ev' => 'view', 'utm' => (string) ( $_GET['utm'] ?? '' ), 'variant' => (string) ( $_GET['variant'] ?? '' ), 'ref' => (string) ( $_SERVER['HTTP_REFERER'] ?? '' ), 'dev' => preg_match( '/Mobile|Android|iPhone|iPad/i', $ua ) ? 'mobile' : 'desktop', 'vid' => self::request_vid( (string) ( $_GET['vid'] ?? '' ) ), 'internal' => Insights_Reports::is_internal_request() ) );
	}

	/** 1.17.1: самодиагностика — таблици, редове, последно събитие, режимът на играта. */
	public static function diagnostics() {
		global $wpdb; $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' ); $tst = Schema::table( 'stats' ); $pub = Config::get_published();
		$ok_s = Schema::exists( $ts ); $ok_e = Schema::exists( $te );
		$out = array( 'tables' => $ok_s && $ok_e, 'db_ver' => (string) get_option( Schema::OPT_DB_VER, '' ), 'sessions' => $ok_s ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ts" ) : 0, 'events' => $ok_e ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $te" ) : 0,
			'last_event' => $ok_e ? (string) $wpdb->get_var( "SELECT MAX(at) FROM $te" ) : '', 'legacy_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(n),0) FROM $tst WHERE day = %s AND step = %s", current_time( 'Y-m-d' ), 'view' ) ),
			'orders_wc' => count( self::promo_order_ids() ), /* 1.21.1: без черновите */
			'orders_sess' => $ok_s ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ts WHERE order_id IS NOT NULL AND order_id > 0" ) : 0,
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

	/* сървърните стъпки — през sid-а, записан в поръчката (Cart::build_meta → _ansa_promo.sid).
	   1.21.1 (човекът: „има 43 поръчки, а Insights отчита 16“): Insights брои сесии, а сесия имаше само поръчка със sid (от 1.17.0, 16.10 следобед) —
	   по-ранните поръчки и тези без sid (стар promo.js от кеша) изобщо не влизаха. Сега поръчка без sid получава сесия „order-<id>“ (стъпка
	   created/paid/activated по билета), и назад за старите (backfill_orders), и занапред. Така „поръчали“ в Insights = промо поръчките в WooCommerce. */
	const ORDER_SID = 'order-';
	public static function order_sid_for( $order_id ) { return self::ORDER_SID . (int) $order_id; }
	public static function is_order_sid( $sid ) { return 0 === strpos( (string) $sid, self::ORDER_SID ) || Phone_Orders::is_phone_sid( $sid ); } /* 1.29.0: и phone-<id> — без проследен път */
	/** sid-ът на поръчката: от meta, иначе order-<id>. */
	private static function order_sid( $order, $m = null ) { $m = null === $m ? ( $order ? (array) $order->get_meta( '_ansa_promo' ) : array() ) : (array) $m; if ( ! empty( $m['phone_order'] ) && $order ) { return Phone_Orders::sid_for( $order->get_id() ); } /* 1.29.0: сесията на оператора не е на клиента */ $s = self::sid( $m['sid'] ?? '' ); return '' !== $s ? $s : ( $order ? self::order_sid_for( $order->get_id() ) : '' ); }
	public static function on_order( $order, $m ) {
		if ( ! empty( $m['draft'] ) ) { return; }
		self::hit( 'created', (string) ( $m['box'] ?? '' ), (string) ( $m['utm'] ?? '' ) );
		$po = ! empty( $m['phone_order'] ) && is_array( $m['phone_order'] ) ? $m['phone_order'] : null; /* 1.29.0: 📞 — източник „телефон“, не е вътрешна (клиентът е истински) */
		self::track( array( 'sid' => self::order_sid( $order, $m ), 'ev' => 'created', 'box' => (string) ( $m['box'] ?? '' ), 'utm' => (string) ( $m['utm'] ?? '' ), 'variant' => (string) ( $m['variant'] ?? '' ), 'order_id' => $order->get_id(), 'lead' => (int) ( $m['lead_id'] ?? 0 ), 'n' => (int) ( $m['count'] ?? 0 ), 'dev' => self::device_of( (string) $order->get_customer_user_agent() ), 'internal' => ! $po && Insights_Reports::is_internal_email( (string) $order->get_billing_email() ), 'at' => ( $dc = $order->get_date_created() ) ? $dc->date( 'Y-m-d H:i:s' ) : '', 'src' => $po ? 'phone' : '', 'med' => $po ? 'call' : '', 'camp' => $po ? (string) ( $po['by'] ?: 'обаждане' ) : '' ) ); /* 1.28.0: тестовите поръчки (probe@example.com) са вътрешни; времето е на поръчката */
	}
	public static function on_ticket( $row, $order ) {
		$m = (array) $order->get_meta( '_ansa_promo' ); if ( ! empty( $m['draft'] ) ) { return; }
		self::hit( 'paid', (string) ( $row['box'] ?? '' ), (string) ( $m['utm'] ?? '' ) );
		self::track( array( 'sid' => self::order_sid( $order, $m ), 'ev' => 'paid', 'box' => (string) ( $row['box'] ?? '' ), 'utm' => (string) ( $m['utm'] ?? '' ), 'order_id' => $order->get_id(), 'n' => (int) ( $row['tickets'] ?? 0 ) ) );
	}
	public static function on_activated( $row, $user ) {
		$o = wc_get_order( (int) $row['order_id'] ); $m = $o ? (array) $o->get_meta( '_ansa_promo' ) : array();
		self::hit( 'activated', (string) ( $row['box'] ?? '' ), (string) ( $m['utm'] ?? '' ) );
		if ( $o ) { self::track( array( 'sid' => self::order_sid( $o, $m ), 'ev' => 'activated', 'box' => (string) ( $row['box'] ?? '' ), 'utm' => (string) ( $m['utm'] ?? '' ), 'order_id' => (int) $row['order_id'] ) ); }
	}
	/** Статусът на поръчката се чете на живо във фийда; тук само опресняваме last_at, за да изплува сесията. */
	public static function on_status( $order_id, $from, $to ) {
		global $wpdb; $o = wc_get_order( (int) $order_id ); if ( ! $o || ! $o->get_meta( '_ansa_promo_id' ) ) { return; }
		/* 1.24.26: кога поръчката е влязла в текущия си статус — за „застояла в път ≥ N дни“ (прогнозата за взети) */
		try { $o->update_meta_data( self::STATUS_AT_META, (string) time() ); $o->save_meta_data(); } catch ( \Throwable $e ) {}
		$sid = self::order_sid( $o ); if ( '' === $sid ) { return; }
		$wpdb->update( Schema::table( 'sessions' ), array( 'last_at' => current_time( 'mysql' ) ), array( 'sid' => $sid ) );
	}
	private static function device_of( $ua ) { return '' === $ua ? '' : ( preg_match( '/Mobile|Android|iPhone|iPad/i', $ua ) ? 'mobile' : 'desktop' ); }

	/** 1.21.1: сесия за всяка промо поръчка без такава (назад във времето). Връща броя добавени/свързани. Без $force — най-много веднъж на 10 мин. */
	public static function backfill_orders( $force = false ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return 0; }
		if ( ! $force && get_transient( 'ansa_promo_ins_backfill' ) ) { return 0; }
		set_transient( 'ansa_promo_ins_backfill', 1, 10 * MINUTE_IN_SECONDS );
		global $wpdb; $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' );
		$have = array(); foreach ( (array) $wpdb->get_results( "SELECT order_id FROM $ts WHERE order_id IS NOT NULL AND order_id > 0", ARRAY_A ) as $r ) { $have[ (int) $r['order_id'] ] = 1; }
		$ids = wc_get_orders( array( 'limit' => 2000, 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'status' => array_keys( wc_get_order_statuses() ), 'meta_key' => '_ansa_promo_id', 'meta_compare' => 'EXISTS' ) );
		$n = 0;
		foreach ( (array) $ids as $oid ) {
			$oid = (int) $oid; if ( isset( $have[ $oid ] ) ) { continue; }
			$o = wc_get_order( $oid ); if ( ! $o ) { continue; }
			$m = (array) $o->get_meta( '_ansa_promo' ); if ( ! empty( $m['draft'] ) ) { continue; }
			$sid = self::order_sid( $o, $m ); $t = Tickets::for_order( $oid );
			$step = $t ? ( 'activated' === $t['status'] ? 'activated' : ( 'void' === $t['status'] ? 'created' : 'paid' ) ) : 'created';
			$dc = $o->get_date_created(); $at = $dc ? $dc->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
			$box = self::tok( $m['box'] ?? '', 8, '/[^a-z]/' ); $utm = self::tok( $m['utm'] ?? '', 64 );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, step, box, utm, last_at FROM $ts WHERE sid = %s", $sid ), ARRAY_A );
			if ( $row ) {
				$upd = array( 'order_id' => $oid ); if ( ( self::ORDER[ $step ] ?? 0 ) > ( self::ORDER[ $row['step'] ] ?? 0 ) ) { $upd['step'] = $step; } if ( $box && ! $row['box'] ) { $upd['box'] = $box; } if ( $utm && ! $row['utm'] ) { $upd['utm'] = $utm; } if ( $at > (string) $row['last_at'] ) { $upd['last_at'] = $at; }
				$wpdb->update( $ts, $upd, array( 'id' => (int) $row['id'] ) );
			} else {
				$wpdb->insert( $ts, array( 'sid' => $sid, 'started_at' => $at, 'last_at' => $at, 'utm' => $utm, 'utm_last' => $utm, 'device' => self::device_of( (string) $o->get_customer_user_agent() ), 'lead_id' => ! empty( $m['lead_id'] ) ? (int) $m['lead_id'] : null, 'box' => $box, 'step' => $step, 'order_id' => $oid, 'ip' => substr( (string) $o->get_customer_ip_address(), 0, 45 ), 'internal' => Insights_Reports::is_internal_email( (string) $o->get_billing_email() ) ? 1 : 0 ) );
			}
			$evs = array( array( 'created', $at ) ); if ( $t && 'void' !== $t['status'] ) { $evs[] = array( 'paid', (string) ( $t['created_at'] ?: $at ) ); } if ( $t && 'activated' === $t['status'] ) { $evs[] = array( 'activated', (string) ( $t['activated_at'] ?: $at ) ); }
			foreach ( $evs as $e ) { if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $te WHERE sid = %s AND ev = %s LIMIT 1", $sid, $e[0] ) ) ) { $wpdb->insert( $te, array( 'sid' => $sid, 'at' => $e[1], 'ev' => $e[0], 'box' => $box, 'k' => '', 'n' => (int) ( $m['count'] ?? 0 ), 'utm' => $utm ), array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) ); } }
			$n++;
		}
		return $n;
	}
	public static function ajax_backfill() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'auth' ), 403 ); }
		$n = self::backfill_orders( true ); $d = self::diagnostics();
		wp_send_json_success( array( 'ok' => true, 'n' => $n, 'msg' => ( $n ? 'Добавени ' . $n . ' сесии за поръчки без такава.' : 'Няма поръчки без сесия.' ) . ' Промо поръчки в WooCommerce: ' . (int) $d['orders_wc'] . ' · със сесия в Insights: ' . (int) $d['orders_sess'] . '. Презареди страницата.', 'diag' => $d ) );
	}
	/** 1.21.1: промо поръчките в WooCommerce (всички статуси, без черновите — те не броят никъде в Insights). $extra — още аргументи за wc_get_orders. */
	public static function promo_order_ids( $extra = array() ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return array(); }
		$ids = array();
		foreach ( (array) wc_get_orders( array_merge( array( 'limit' => -1, 'return' => 'objects', 'status' => array_keys( wc_get_order_statuses() ), 'meta_key' => '_ansa_promo_id', 'meta_compare' => 'EXISTS' ), $extra ) ) as $o ) { $m = (array) $o->get_meta( '_ansa_promo' ); if ( empty( $m['draft'] ) ) { $ids[] = $o->get_id(); } }
		return $ids;
	}
	/** 1.21.1: промо поръчките за период (по датата на поръчката, в часовата зона на сайта); null без WooCommerce. */
	public static function wc_orders_count( $rg ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return null; }
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		try { $a = ( new \DateTime( $rg['from'] . ' 00:00:00', $tz ) )->getTimestamp(); $b = ( new \DateTime( $rg['to'] . ' 23:59:59', $tz ) )->getTimestamp(); } catch ( \Exception $e ) { return null; }
		return count( self::promo_order_ids( array( 'date_created' => $a . '...' . $b ) ) );
	}

	/* ── отчети ── */
	/** 1.21.0 (човекът): историята се гледа от старта на сезона — „всичко от 16.10.2026“; по-рано сесии няма (Insights 2 е от 1.17.0, 16.10.2026). */
	const HISTORY_FROM = '2026-10-16';
	private static function since( $days ) { return $days > 0 ? gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( (int) $days - 1 ) . ' days', current_time( 'timestamp' ) ) ) : ''; }
	/** 1.21.0: период от–до (Y-m-d). Невалидна/празна дата → от старта / до днес; разменени → оправят се. */
	public static function range( $from = '', $to = '' ) {
		$ok = function ( $d ) { return is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) ); };
		$from = $ok( $from ) ? $from : self::HISTORY_FROM; $to = $ok( $to ) ? $to : current_time( 'Y-m-d' );
		if ( $from > $to ) { $x = $from; $from = $to; $to = $x; }
		return array( 'from' => $from, 'to' => $to );
	}
	private static function where_range( $col, $rg ) { global $wpdb; return $wpdb->prepare( "$col >= %s AND $col <= %s", $rg['from'] . ' 00:00:00', $rg['to'] . ' 23:59:59' ); }

	/* ── 1.28.0 (т. 2): ЕДИН период за всички екрани — от–до с бързи чипове; пази се (бисквитка в портала, user meta в админа) ── */
	const PERIOD_COOKIE = 'ansa_promo_ins_period';
	private static $period = null; public static $period_explicit = false;
	/** Първият ден с данни (за „всичко“). */
	public static function first_day() { global $wpdb; $d = (string) $wpdb->get_var( 'SELECT MIN(started_at) FROM ' . Schema::table( 'sessions' ) ); return $d ? substr( $d, 0, 10 ) : self::HISTORY_FROM; }
	/** Бързите периоди: key => [from, to, label]. */
	public static function quick_ranges() {
		$today = current_time( 'Y-m-d' ); $now = current_time( 'timestamp' ); $d = function ( $n ) use ( $now ) { return gmdate( 'Y-m-d', $now - $n * DAY_IN_SECONDS ); };
		return array( 'today' => array( $today, $today, 'днес' ), 'yesterday' => array( $d( 1 ), $d( 1 ), 'вчера' ), '7' => array( $d( 6 ), $today, '7 дни' ), '30' => array( $d( 29 ), $today, '30 дни' ), '90' => array( $d( 89 ), $today, '90 дни' ), 'season' => array( self::HISTORY_FROM, $today, 'от старта' ), 'all' => array( self::first_day(), $today, 'всичко' ) );
	}
	/** Периодът на заявката: ?from/?to · ?days (старите линкове: 1 = днес, 7/30/90, 0 = всичко) · запомненият · 30 дни. Връща range + key + label + days. */
	public static function period() {
		if ( null !== self::$period ) { return self::$period; }
		$q = self::quick_ranges(); $from = ''; $to = '';
		if ( isset( $_GET['days'] ) ) { $d = (int) $_GET['days']; $k = 1 === $d ? 'today' : ( $d > 1 ? (string) $d : 'all' ); if ( ! isset( $q[ $k ] ) ) { $k = '30'; } $from = $q[ $k ][0]; $to = $q[ $k ][1]; self::$period_explicit = true; }
		elseif ( ! empty( $_GET['from'] ) || ! empty( $_GET['to'] ) ) { $from = (string) $_GET['from']; $to = (string) $_GET['to']; self::$period_explicit = true; }
		else { $saved = self::period_saved(); if ( $saved ) { $from = $saved[0]; $to = $saved[1]; } else { $from = $q['30'][0]; $to = $q['30'][1]; } }
		$rg = self::range( $from, $to ); if ( self::$period_explicit ) { self::period_save( $rg ); }
		$key = ''; foreach ( $q as $k => $v ) { if ( $v[0] === $rg['from'] && $v[1] === $rg['to'] ) { $key = $k; break; } }
		$rg['key'] = (string) $key; $rg['label'] = self::period_label( $rg ); $rg['days'] = self::range_days( $rg );
		self::$period = $rg; return $rg;
	}
	private static function period_saved() {
		$v = '';
		if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'get_current_user_id' ) && get_current_user_id() ) { $v = (string) get_user_meta( get_current_user_id(), self::PERIOD_COOKIE, true ); }
		if ( '' === $v ) { $v = (string) ( $_COOKIE[ self::PERIOD_COOKIE ] ?? '' ); }
		return preg_match( '/^(\d{4}-\d{2}-\d{2})\|(\d{4}-\d{2}-\d{2})$/', $v, $m ) ? array( $m[1], $m[2] ) : null;
	}
	private static function period_save( $rg ) {
		$v = $rg['from'] . '|' . $rg['to'];
		if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'get_current_user_id' ) && get_current_user_id() ) { update_user_meta( get_current_user_id(), self::PERIOD_COOKIE, $v ); return; }
		if ( ! headers_sent() ) { @setcookie( self::PERIOD_COOKIE, $v, array( 'expires' => time() + 30 * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ); }
	}
	public static function range_days( $rg ) { return max( 1, (int) round( ( strtotime( $rg['to'] ) - strtotime( $rg['from'] ) ) / DAY_IN_SECONDS ) + 1 ); }
	/** Предишният период със същата дължина (т. 21). */
	public static function prev_range( $rg ) { $n = self::range_days( $rg ); $to = strtotime( $rg['from'] ) - DAY_IN_SECONDS; return array( 'from' => gmdate( 'Y-m-d', $to - ( $n - 1 ) * DAY_IN_SECONDS ), 'to' => gmdate( 'Y-m-d', $to ) ); }
	public static function period_label( $rg ) { $today = current_time( 'Y-m-d' ); if ( $rg['from'] === $rg['to'] ) { return $rg['from'] === $today ? 'днес' : date_i18n( 'd.m.Y', strtotime( $rg['from'] ) ); } return date_i18n( 'd.m.Y', strtotime( $rg['from'] ) ) . ' – ' . date_i18n( 'd.m.Y', strtotime( $rg['to'] ) ); }

	/* ── 1.28.0 (т. 12, 17, 22): филтрите на екрана — атрибуция, вътрешен трафик, UTM/кампания/устройство/кутия ── */
	const FILTER_KEYS = array( 'f_utm', 'f_src', 'f_med', 'f_camp', 'f_dev', 'f_box', 'attr', 'internal' );
	public static function filters() {
		$g = $_GET; $f = array( 'attr' => 'last' === ( $g['attr'] ?? '' ) ? 'last' : 'first', 'internal' => ! empty( $g['internal'] ) );
		foreach ( array( 'utm' => 64, 'src' => 64, 'med' => 64, 'camp' => 128 ) as $k => $len ) { $v = isset( $g[ 'f_' . $k ] ) ? self::tok( wp_unslash( $g[ 'f_' . $k ] ), $len, 'camp' === $k ? '/[^\p{L}\p{N}_\-\.\s@]/u' : '/[^a-z0-9_\-\.]/i' ) : ''; $f[ $k ] = $v; }
		$f['dev'] = in_array( $g['f_dev'] ?? '', array( 'mobile', 'desktop' ), true ) ? (string) $g['f_dev'] : ''; $f['box'] = self::tok( $g['f_box'] ?? '', 8, '/[^a-z]/' );
		return $f;
	}
	/** Има ли активен филтър по измерение (UTM/кампания/устройство/кутия). */
	public static function filters_active( $f ) { return '' !== $f['utm'] || '' !== $f['src'] || '' !== $f['med'] || '' !== $f['camp'] || '' !== $f['dev'] || '' !== $f['box']; }
	/** WHERE парчетата за филтрите (без alias). $o: attr, internal, filter. */
	private static function filter_where( $o, $alias = '' ) {
		global $wpdb; $w = array(); $a = $alias ? $alias . '.' : ''; $f = isset( $o['filter'] ) && is_array( $o['filter'] ) ? $o['filter'] : array();
		if ( empty( $o['internal'] ) ) { $w[] = "{$a}internal = 0"; }
		$utm_col = 'last' === ( $o['attr'] ?? 'first' ) ? "(CASE WHEN {$a}utm_last <> '' THEN {$a}utm_last ELSE {$a}utm END)" : "{$a}utm";
		if ( '' !== (string) ( $f['utm'] ?? '' ) ) { $w[] = $wpdb->prepare( "$utm_col = %s", $f['utm'] ); }
		foreach ( array( 'src', 'med', 'camp', 'dev' => 'device', 'box' ) as $k => $col ) { if ( is_int( $k ) ) { $k = $col; } if ( '' !== (string) ( $f[ $k ] ?? '' ) ) { $w[] = $wpdb->prepare( "{$a}$col = %s", $f[ $k ] ); } }
		return $w;
	}

	/* ── 1.28.0 (т. 21): Δ спрямо предишния период ── */
	/** Числото от стойността на плочка („1 234“, „12,5%“, „120,00 €“). */
	public static function num( $v ) { $v = str_replace( array( ' ', "\xC2\xA0", '€', '%' ), '', (string) $v ); $v = str_replace( ',', '.', $v ); return is_numeric( $v ) ? (float) $v : null; }
	/** HTML за Δ: ▲ +12 % / ▼ −8 % / = ; $inverse = по-малко е по-добре. */
	public static function delta_html( $cur, $prev, $inverse = false ) {
		$c = self::num( $cur ); $p = self::num( $prev ); if ( null === $c || null === $p ) { return ''; }
		if ( 0.0 === $p ) { return $c > 0 ? '<span class="ap-d up" title="предишният период: 0">▲ ново</span>' : '<span class="ap-d" title="предишният период: 0">=</span>'; }
		$d = 100 * ( $c - $p ) / abs( $p ); $good = $inverse ? $d < 0 : $d > 0; $cls = abs( $d ) < 0.5 ? '' : ( $good ? ' up' : ' down' );
		return '<span class="ap-d' . $cls . '" title="предишният период: ' . esc_attr( (string) $prev ) . '">' . ( abs( $d ) < 0.5 ? '=' : ( $d > 0 ? '▲ +' : '▼ −' ) . number_format( abs( $d ), abs( $d ) < 10 ? 1 : 0, ',', '' ) . ' %' ) . '</span>';
	}
	public static function delta_text( $cur, $prev ) { return trim( wp_strip_all_tags( str_replace( array( '▲', '▼' ), array( '↑', '↓' ), self::delta_html( $cur, $prev ) ) ) ); }
	/* ── 1.28.0 (т. 7): sparkline (SVG) ── */
	public static function spark( $vals, $w = 120, $h = 28 ) {
		$vals = array_values( array_map( 'floatval', (array) $vals ) ); $n = count( $vals ); if ( $n < 2 ) { return ''; }
		$max = max( $vals ); $min = min( $vals ); $rng = $max - $min; $pts = array();
		foreach ( $vals as $i => $v ) { $x = $i * ( $w - 2 ) / ( $n - 1 ) + 1; $y = $rng > 0 ? ( $h - 3 ) - ( $v - $min ) / $rng * ( $h - 6 ) + 1.5 : $h / 2; $pts[] = round( $x, 1 ) . ',' . round( $y, 1 ); }
		$last = end( $pts );
		return '<svg class="ap-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true"><polyline fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" points="' . implode( ' ', $pts ) . '"/><circle r="2.2" fill="currentColor" cx="' . explode( ',', $last )[0] . '" cy="' . explode( ',', $last )[1] . '"/></svg>';
	}

	/** Фунията по сесии за период: колко сесии са стигнали поне до всяка стъпка + разбивки.
	 *  1.21.0: $range (от–до, виж range()) печели над $days; + stopped (най-далечната стъпка — къде точно е спряла сесията), vfunnel/vstopped (уникални посетители за същото).
	 *  1.28.0: $o = [ attr => first|last (т. 12), internal => bool (вкл. вътрешния трафик, т. 17), filter => filters() (т. 22), totals => orders_stats()['by_id'] (оборот по
	 *  измерение, т. 19–20), cfg ]. Сесиите „само поръчка“ (order-<id>, без проследен път) НЕ влизат във фунията и разбивките — броят се отделно (orders_only, т. 11).
	 *  Посетител = vid (бисквитка 30 дни), резерва IP, резерва sid (т. 13). + bounce/dur/tto (т. 16), by_week (т. 19), rev/delivered по ред (т. 20), dev_stop (т. 26),
	 *  camp_day (т. 24). */
	/** 1.34.0: човешкото име на варианта в отчетите ('' = A — екранът с кутиите; flow = B — потокът без кутии). */
	public static function variant_label( $v ) { $v = (string) $v; return '' === $v ? 'кутиите (стар дизайн)' : ( 'flow' === $v ? 'потокът без кутии (нов дизайн)' : $v ); } /* 1.37.0: без A/B в имената — новият дизайн е на главния адрес */
	public static function sessions_report( $days = 30, $range = null, $o = array() ) {
		global $wpdb; $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' ); $o = is_array( $o ) ? $o : array();
		$since = self::since( $days );
		$w = array(); if ( is_array( $range ) ) { $w[] = self::where_range( 'started_at', $range ); } elseif ( $since ) { $w[] = $wpdb->prepare( 'started_at >= %s', $since ); }
		$w = array_merge( $w, self::filter_where( $o ) ); $where = $w ? 'WHERE ' . implode( ' AND ', $w ) : '';
		$rows = $wpdb->get_results( "SELECT utm, utm_last, src, med, camp, ref, device, box, step, items, lead_id, order_id, started_at, last_at, ip, sid, vid, internal, variant FROM $ts $where", ARRAY_A );
		$totals = isset( $o['totals'] ) && is_array( $o['totals'] ) ? $o['totals'] : array(); $attr = 'last' === ( $o['attr'] ?? 'first' ) ? 'last' : 'first';
		$cfg = isset( $o['cfg'] ) && is_array( $o['cfg'] ) ? $o['cfg'] : Config::get_published();
		$zero = array( 'n' => 0, 'v' => 0, 'gate' => 0, 'box' => 0, 'full' => 0, 'add' => 0, 'created' => 0, 'paid' => 0, 'rev' => 0.0, 'delivered' => 0, 'rev_del' => 0.0 );
		$out = array( 'sessions' => 0, 'visitors' => 0, 'funnel' => array_fill_keys( self::FUNNEL, 0 ), 'stopped' => array_fill_keys( self::FUNNEL, 0 ), 'vfunnel' => array_fill_keys( self::FUNNEL, 0 ), 'vstopped' => array_fill_keys( self::FUNNEL, 0 ), 'by_utm' => array(), 'by_src' => array(), 'by_dev' => array(), 'by_box' => array(), 'by_day' => array(), 'items' => array(), 'leads' => 0, 'orders' => 0,
			/* 1.27.0 (човекът: „от кой UTM са дошли, коя кутия са избрали, докъде са я напълнили, къде са дропнали“): by_camp = utm_source / medium · campaign, by_ref = домейнът на referrer-а, by_hour = часът на влизане (0–23), by_utm_box = UTM × избрана кутия, fill = докъде е напълнена кутията (виж по-долу) */
			'by_camp' => array(), 'by_ref' => array(), 'by_hour' => array(), 'by_utm_box' => array(), 'fill' => array(), 'by_variant' => array(), /* 1.34.0: A/B */
			'by_channel' => array(), 'by_chdet' => array(), 'day_ch' => array(), 'chdet_link' => array(), /* 1.38.0: каналът (Channel::of) — по канал, по канал · детайл, дни × канал */
			/* 1.28.0 */
			'attr' => $attr, 'orders_only' => 0, 'orders_only_rev' => 0.0, 'bounce' => 0, 'dur_sum' => 0, 'dur_n' => 0, 'dur_avg' => 0.0, 'tto_avg' => 0.0, 'tto_med' => 0.0, 'tto_n' => 0, 'by_week' => array(), 'dev_stop' => array(), 'camp_day' => array(), 'rev' => 0.0, 'delivered' => 0, 'rev_del' => 0.0, 'internal_n' => 0 );
		/* 1.20.4 (човекът): уникални посетители = различни IP (без IP — сесията е свой посетител); общо и във всяка разбивка (v). 1.28.0: по vid, резерва IP */
		$vis = array(); $vg = array(); $vf = array(); $vs = array(); $weeks = Config::weekly_on( $cfg ) ? Config::season_weeks( $cfg ) : array(); $created_sids = array(); $tracked = array();
		foreach ( (array) $rows as $r ) {
			$tot = $r['order_id'] && isset( $totals[ (int) $r['order_id'] ] ) ? $totals[ (int) $r['order_id'] ] : null;
			if ( ! empty( $r['internal'] ) ) { $out['internal_n']++; }
			if ( self::is_order_sid( $r['sid'] ) ) { $out['orders_only']++; if ( $tot ) { $out['orders_only_rev'] += (float) $tot['total']; } continue; } /* т. 11: без проследен път — отделно */
			$tracked[] = $r; $out['sessions']++;
			$vk = '' !== (string) $r['vid'] ? 'v:' . $r['vid'] : ( '' !== (string) $r['ip'] ? (string) $r['ip'] : 'sid:' . $r['sid'] ); $vis[ $vk ] = 1;
			$o_ = self::ORDER[ $r['step'] ] ?? 1; $ex = isset( $out['stopped'][ $r['step'] ] ) ? $r['step'] : 'view';
			$out['stopped'][ $ex ]++; $vs[ $ex ][ $vk ] = 1;
			foreach ( self::FUNNEL as $st ) { if ( $o_ >= self::ORDER[ $st ] ) { $out['funnel'][ $st ]++; $vf[ $st ][ $vk ] = 1; } }
			if ( $r['lead_id'] ) { $out['leads']++; } if ( $r['order_id'] ) { $out['orders']++; $created_sids[] = $r['sid']; }
			if ( $tot ) { $out['rev'] += (float) $tot['total']; if ( 'delivered' === $tot['grp'] ) { $out['delivered']++; $out['rev_del'] += (float) $tot['total']; } }
			/* т. 16: продължителност и „само видели“ */
			if ( 'view' === $r['step'] ) { $out['bounce']++; } else { $d = max( 0, (int) strtotime( (string) $r['last_at'] ) - (int) strtotime( (string) $r['started_at'] ) ); $out['dur_sum'] += min( $d, 4 * HOUR_IN_SECONDS ); $out['dur_n']++; }
			$day = substr( (string) $r['started_at'], 0, 10 ); $ts_ = (int) strtotime( (string) $r['started_at'] );
			$utm = 'last' === $attr ? ( '' !== (string) $r['utm_last'] ? $r['utm_last'] : $r['utm'] ) : $r['utm'];
			$camp = trim( (string) $r['src'] . ( '' !== (string) $r['med'] ? ' / ' . $r['med'] : '' ) . ( '' !== (string) $r['camp'] ? ' · ' . $r['camp'] : '' ) ) ?: ( $utm ? 'само UTM продукт' : 'без UTM' );
			$refh = '' !== (string) $r['ref'] ? strtolower( (string) ( wp_parse_url( (string) $r['ref'], PHP_URL_HOST ) ?: $r['ref'] ) ) : 'директно / без referrer'; $refh = preg_replace( '/^(www|m|l|lm)\./', '', $refh ) ?: 'директно / без referrer';
			$hour = sprintf( '%02d:00', (int) substr( (string) $r['started_at'], 11, 2 ) );
			$dev = $r['device'] ?: '—'; $stp = isset( $out['stopped'][ $r['step'] ] ) ? $r['step'] : 'view'; $out['dev_stop'][ $dev ][ $stp ] = ( $out['dev_stop'][ $dev ][ $stp ] ?? 0 ) + 1; /* т. 26 */
			$ub = $utm ?: '—'; if ( ! isset( $out['by_utm_box'][ $ub ] ) ) { $out['by_utm_box'][ $ub ] = array( 'n' => 0, 'none' => 0, 'boxes' => array() ); } $out['by_utm_box'][ $ub ]['n']++; if ( $r['box'] ) { $out['by_utm_box'][ $ub ]['boxes'][ $r['box'] ] = ( $out['by_utm_box'][ $ub ]['boxes'][ $r['box'] ] ?? 0 ) + 1; } else { $out['by_utm_box'][ $ub ]['none']++; }
			$groups = array( 'by_utm' => $utm ?: '—', 'by_src' => $r['src'] ?: ( $utm ? 'utm' : 'директно' ), 'by_dev' => $dev, 'by_box' => $r['box'] ?: '—', 'by_day' => $day, 'by_camp' => $camp, 'by_ref' => $refh, 'by_hour' => $hour, 'by_variant' => self::variant_label( (string) ( $r['variant'] ?? '' ) ) );
			$ch = Channel::of( $r ); $chd = $ch['l'] . ( '' !== $ch['d'] ? ' · ' . $ch['d'] : '' ); $groups['by_channel'] = $ch['l']; $groups['by_chdet'] = $chd; if ( $ch['f'] && ! isset( $out['chdet_link'][ $chd ] ) ) { $out['chdet_link'][ $chd ] = $ch['f']; } /* 1.38.0 */
			if ( ! isset( $out['day_ch'][ $day ][ $ch['k'] ] ) ) { $out['day_ch'][ $day ][ $ch['k'] ] = array( 'n' => 0, 'created' => 0 ); } $out['day_ch'][ $day ][ $ch['k'] ]['n']++; if ( $o_ >= self::ORDER['created'] ) { $out['day_ch'][ $day ][ $ch['k'] ]['created']++; }
			if ( $weeks ) { foreach ( $weeks as $wk ) { if ( $ts_ >= (int) $wk['from'] && $ts_ < (int) $wk['to'] ) { $groups['by_week'] = 'седм. ' . (int) $wk['i'] . ' (' . wp_date( 'd.m', (int) $wk['from'] ) . '–' . wp_date( 'd.m', (int) $wk['to'] - MINUTE_IN_SECONDS ) . ') · −' . implode( ' / −', array_map( array( 'AnsaPromo\\Cart', 'fmt' ), array_values( (array) $wk['pct'] ) ) ) . '%'; break; } } if ( ! isset( $groups['by_week'] ) ) { $groups['by_week'] = 'извън седмиците'; } } /* т. 19 */
			foreach ( $groups as $grp => $key ) {
				if ( ! isset( $out[ $grp ][ $key ] ) ) { $out[ $grp ][ $key ] = $zero; }
				$out[ $grp ][ $key ]['n']++; $vg[ $grp ][ $key ][ $vk ] = 1;
				foreach ( array( 'gate', 'box', 'full', 'add', 'created', 'paid' ) as $st ) { if ( $o_ >= self::ORDER[ $st ] ) { $out[ $grp ][ $key ][ $st ]++; } }
				if ( $tot ) { $out[ $grp ][ $key ]['rev'] += (float) $tot['total']; if ( 'delivered' === $tot['grp'] ) { $out[ $grp ][ $key ]['delivered']++; $out[ $grp ][ $key ]['rev_del'] += (float) $tot['total']; } }
			}
			/* т. 24: кампания × ден за графиката */
			if ( ! isset( $out['camp_day'][ $camp ][ $day ] ) ) { $out['camp_day'][ $camp ][ $day ] = array( 'n' => 0, 'created' => 0, 'rev' => 0.0 ); } $out['camp_day'][ $camp ][ $day ]['n']++; if ( $r['order_id'] ) { $out['camp_day'][ $camp ][ $day ]['created']++; } if ( $tot ) { $out['camp_day'][ $camp ][ $day ]['rev'] += (float) $tot['total']; }
			$items = $r['items'] ? json_decode( $r['items'], true ) : null;
			if ( is_array( $items ) ) { foreach ( array_count_values( array_map( 'strval', $items ) ) as $k => $q ) { if ( ! isset( $out['items'][ $k ] ) ) { $out['items'][ $k ] = array( 'sessions' => 0, 'packs' => 0, 'ordered' => 0 ); } $out['items'][ $k ]['sessions']++; $out['items'][ $k ]['packs'] += $q; if ( $r['order_id'] ) { $out['items'][ $k ]['ordered'] += $q; } } }
		}
		$out['dur_avg'] = $out['dur_n'] ? $out['dur_sum'] / $out['dur_n'] : 0.0;
		/* т. 16: време от първото влизане до поръчката (събитията view → created на сесиите с поръчка) */
		if ( $created_sids ) {
			$lags = array();
			foreach ( array_chunk( $created_sids, 300 ) as $chunk ) { $in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) ); foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT sid, MIN(CASE WHEN ev = 'view' THEN at END) AS v, MIN(CASE WHEN ev = 'created' THEN at END) AS c FROM $te WHERE sid IN ($in) GROUP BY sid", $chunk ), ARRAY_A ) as $x ) { if ( $x['v'] && $x['c'] ) { $l = (int) strtotime( $x['c'] ) - (int) strtotime( $x['v'] ); if ( $l >= 0 ) { $lags[] = $l; } } } }
			if ( $lags ) { sort( $lags ); $out['tto_n'] = count( $lags ); $out['tto_avg'] = array_sum( $lags ) / count( $lags ); $out['tto_med'] = $lags[ (int) floor( ( count( $lags ) - 1 ) / 2 ) ]; }
		}
		/* 1.27.0: докъде е напълнена кутията — най-големият n (опаковки в кутията след всяко слагане) по сесия; за сесиите, избрали кутия, но не стигнали
		   до „пълна“: разпределение по брой опаковки (0 = избрала кутия, нищо не сложила), среден брой и % запълване спрямо packs на кутията */
		$caps = array(); foreach ( (array) $cfg['boxes'] as $b ) { $caps[ $b['id'] ] = max( 1, (int) $b['packs'] ); }
		$wn = is_array( $range ) ? 'WHERE ' . self::where_range( 'e.at', $range ) : ( $since ? $wpdb->prepare( 'WHERE e.at >= %s', $since ) : 'WHERE 1=1' );
		$maxn = array(); foreach ( (array) $wpdb->get_results( "SELECT e.sid, MAX(e.n) AS m FROM $te e $wn AND e.ev IN ('item','full','order','add') GROUP BY e.sid", ARRAY_A ) as $x ) { $maxn[ $x['sid'] ] = (int) $x['m']; }
		foreach ( $tracked as $r ) {
			if ( '' === (string) $r['box'] ) { continue; } $bx = (string) $r['box']; $cap = $caps[ $bx ] ?? 0;
			if ( ! isset( $out['fill'][ $bx ] ) ) { $out['fill'][ $bx ] = array( 'packs' => $cap, 'sessions' => 0, 'full' => 0, 'ordered' => 0, 'stopped' => 0, 'dist' => array(), 'sum' => 0, 'over' => array() ); }
			$f0 = &$out['fill'][ $bx ]; $f0['sessions']++; $o_ = self::ORDER[ $r['step'] ] ?? 1;
			$it = $r['items'] ? json_decode( $r['items'], true ) : null; $n = max( (int) ( $maxn[ $r['sid'] ] ?? 0 ), is_array( $it ) ? count( $it ) : 0 );
			if ( $o_ >= self::ORDER['full'] ) { $f0['full']++; if ( $r['order_id'] ) { $f0['ordered']++; } if ( $cap && $n > $cap ) { $f0['over'][ $n ] = ( $f0['over'][ $n ] ?? 0 ) + 1; } } /* 1.28.0: над минимума (голямата расте) */
			else { if ( $cap ) { $n = min( $n, $cap ); } $f0['stopped']++; $f0['dist'][ $n ] = ( $f0['dist'][ $n ] ?? 0 ) + 1; $f0['sum'] += $n; }
			unset( $f0 );
		}
		foreach ( $out['fill'] as $bx => &$f0 ) { ksort( $f0['dist'] ); ksort( $f0['over'] ); $f0['avg'] = $f0['stopped'] ? (float) $f0['sum'] / $f0['stopped'] : 0.0; $f0['avg_pct'] = $f0['packs'] ? 100 * $f0['avg'] / $f0['packs'] : 0.0; } unset( $f0 );
		/* „цъкнал“ продукт — събитията item (и без да е напълнил) */
		$we = is_array( $range ) ? 'WHERE ' . self::where_range( 'at', $range ) . $wpdb->prepare( ' AND ev = %s', 'item' ) : ( $since ? $wpdb->prepare( 'WHERE at >= %s AND ev = %s', $since, 'item' ) : $wpdb->prepare( 'WHERE ev = %s', 'item' ) );
		foreach ( (array) $wpdb->get_results( "SELECT k, COUNT(DISTINCT sid) AS s, COUNT(*) AS c FROM $te $we GROUP BY k", ARRAY_A ) as $r ) { if ( ! isset( $out['items'][ $r['k'] ] ) ) { $out['items'][ $r['k'] ] = array( 'sessions' => 0, 'packs' => 0, 'ordered' => 0 ); } $out['items'][ $r['k'] ]['clicked'] = (int) $r['s']; $out['items'][ $r['k'] ]['clicks'] = (int) $r['c']; }
		/* 1.24.2: Shrine — 4-тата опция (3+2) по продукт: видели картата · избрали · „Виж повече“ · в количката · поръчали (сесия със sh_add и поръчка) */
		$out['shrine'] = array();
		$ws = is_array( $range ) ? 'WHERE ' . self::where_range( 'e.at', $range ) : ( $since ? $wpdb->prepare( 'WHERE e.at >= %s', $since ) : 'WHERE 1=1' );
		$shin = "'" . implode( "','", self::SHRINE ) . "'";
		foreach ( (array) $wpdb->get_results( "SELECT e.k, e.ev, COUNT(DISTINCT e.sid) AS s FROM $te e $ws AND e.ev IN ($shin) GROUP BY e.k, e.ev", ARRAY_A ) as $r ) {
			$kk = (string) $r['k']; if ( ! isset( $out['shrine'][ $kk ] ) ) { $out['shrine'][ $kk ] = array( 'view' => 0, 'pick' => 0, 'more' => 0, 'add' => 0, 'created' => 0 ); }
			$out['shrine'][ $kk ][ substr( $r['ev'], 3 ) ] = (int) $r['s'];
		}
		foreach ( (array) $wpdb->get_results( "SELECT e.k, COUNT(DISTINCT e.sid) AS s FROM $te e INNER JOIN $ts s ON s.sid = e.sid $ws AND e.ev = 'sh_add' AND s.order_id > 0 GROUP BY e.k", ARRAY_A ) as $r ) {
			$kk = (string) $r['k']; if ( isset( $out['shrine'][ $kk ] ) ) { $out['shrine'][ $kk ]['created'] = (int) $r['s']; }
		}
		uasort( $out['shrine'], function ( $a, $b ) { return $b['view'] <=> $a['view']; } );
		$out['visitors'] = count( $vis ); foreach ( $vg as $grp => $keys ) { foreach ( $keys as $key => $set ) { $out[ $grp ][ $key ]['v'] = count( $set ); } }
		foreach ( self::FUNNEL as $st ) { $out['vfunnel'][ $st ] = count( $vf[ $st ] ?? array() ); $out['vstopped'][ $st ] = count( $vs[ $st ] ?? array() ); }
		ksort( $out['day_ch'] ); foreach ( array( 'by_utm', 'by_src', 'by_dev', 'by_box', 'by_camp', 'by_ref', 'by_variant', 'by_channel', 'by_chdet' ) as $g ) { uasort( $out[ $g ], function ( $a, $b ) { return $b['n'] <=> $a['n']; } ); }
		uasort( $out['by_utm_box'], function ( $a, $b ) { return $b['n'] <=> $a['n']; } );
		uasort( $out['camp_day'], function ( $a, $b ) { return array_sum( array_column( $b, 'n' ) ) <=> array_sum( array_column( $a, 'n' ) ); } ); $out['camp_day'] = array_slice( $out['camp_day'], 0, 8, true );
		ksort( $out['by_day'] ); ksort( $out['by_hour'] ); ksort( $out['by_week'], SORT_NATURAL ); uasort( $out['items'], function ( $a, $b ) { return ( $b['sessions'] + ( $b['clicked'] ?? 0 ) ) <=> ( $a['sessions'] + ( $a['clicked'] ?? 0 ) ); } );
		return $out;
	}

	/** 1.21.0: WHERE за списъка на сесиите (псевдоним s): from/to (Y-m-d) · step + mode (min = стигнали поне до, exact = спрели точно на) · box · ord (yes/no — има ли поръчка) · dev (mobile/desktop). Всичко се валидира тук. */
	public static function sessions_where( $q ) {
		global $wpdb; $q = is_array( $q ) ? $q : array(); $w = array();
		if ( ! empty( $q['from'] ) || ! empty( $q['to'] ) ) { $w[] = self::where_range( 's.started_at', self::range( (string) ( $q['from'] ?? '' ), (string) ( $q['to'] ?? '' ) ) ); }
		$step = sanitize_key( (string) ( $q['step'] ?? '' ) );
		if ( in_array( $step, self::FUNNEL, true ) ) {
			if ( 'exact' === ( $q['mode'] ?? 'min' ) ) { $w[] = $wpdb->prepare( 's.step = %s', $step ); }
			else { $in = array(); foreach ( self::FUNNEL as $st ) { if ( self::ORDER[ $st ] >= self::ORDER[ $step ] ) { $in[] = $wpdb->prepare( '%s', $st ); } } $w[] = 's.step IN (' . implode( ',', $in ) . ')'; }
		}
		$box = self::tok( $q['box'] ?? '', 8, '/[^a-z]/' ); if ( '' !== $box ) { $w[] = $wpdb->prepare( 's.box = %s', $box ); }
		$ord = (string) ( $q['ord'] ?? '' ); if ( 'yes' === $ord ) { $w[] = '(s.order_id IS NOT NULL AND s.order_id > 0)'; } elseif ( 'no' === $ord ) { $w[] = '(s.order_id IS NULL OR s.order_id = 0)'; }
		$dev = (string) ( $q['dev'] ?? '' ); if ( in_array( $dev, array( 'mobile', 'desktop' ), true ) ) { $w[] = $wpdb->prepare( 's.device = %s', $dev ); }
		if ( ! empty( $q['filter'] ) || array_key_exists( 'internal', $q ) ) { $w = array_merge( $w, self::filter_where( array( 'internal' => ! empty( $q['internal'] ), 'attr' => $q['attr'] ?? 'first', 'filter' => $q['filter'] ?? array() ), 's' ) ); } /* 1.28.0: вътрешният трафик и филтрите на екрана */
		return $w ? 'WHERE ' . implode( ' AND ', $w ) : '';
	}
	/** 1.21.0: колко сесии (n) и уникални посетители (v) отговарят на филтъра. */
	public static function sessions_count( $q ) {
		global $wpdb; $ts = Schema::table( 'sessions' );
		$r = $wpdb->get_row( "SELECT COUNT(*) AS n, COUNT(DISTINCT CASE WHEN s.ip IS NULL OR s.ip = '' THEN CONCAT('sid:', s.sid) ELSE s.ip END) AS v FROM $ts s " . self::sessions_where( $q ), ARRAY_A );
		return array( 'n' => (int) ( $r['n'] ?? 0 ), 'v' => (int) ( $r['v'] ?? 0 ) );
	}

	/** Последните сесии за фийда: кой (лийд имейл), откъде, кутия, продукти, стъпки с време, поръчка на живо.
	 *  1.21.0: $q — филтрите на sessions_where() + offset + sort (last = по последна активност, фийдът; started = по начало, историята). */
	public static function feed( $limit = 40, $q = array() ) {
		global $wpdb; $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' ); $tl = Schema::table( 'leads' ); $q = is_array( $q ) ? $q : array();
		$w = self::sessions_where( $q ); $ob = 'started' === ( $q['sort'] ?? 'last' ) ? 's.started_at DESC, s.id DESC' : 's.last_at DESC';
		$rows = $wpdb->get_results( "SELECT s.*, l.email AS lead_email FROM $ts s LEFT JOIN $tl l ON l.id = s.lead_id $w ORDER BY $ob LIMIT " . max( 1, min( 200, (int) $limit ) ) . ' OFFSET ' . max( 0, (int) ( $q['offset'] ?? 0 ) ), ARRAY_A );
		if ( ! $rows ) { return array(); }
		$sids = array_map( function ( $r ) { return $r['sid']; }, $rows );
		$in = implode( ',', array_fill( 0, count( $sids ), '%s' ) );
		$evs = $wpdb->get_results( $wpdb->prepare( "SELECT sid, at, ev, box, k, n FROM $te WHERE sid IN ($in) ORDER BY id ASC", $sids ), ARRAY_A );
		$by = array(); foreach ( (array) $evs as $e ) { $by[ $e['sid'] ][] = $e; }
		/* 1.20.4: същият посетител (IP) — колко сесии има общо и анонимен етикет (хеш, не адресът) */
		$ips = array_values( array_unique( array_filter( array_map( function ( $r ) { return (string) $r['ip']; }, $rows ) ) ) ); $visits = array();
		if ( $ips ) { $inp = implode( ',', array_fill( 0, count( $ips ), '%s' ) ); foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ip, COUNT(*) AS c FROM $ts WHERE ip IN ($inp) GROUP BY ip", $ips ), ARRAY_A ) as $v ) { $visits[ $v['ip'] ] = (int) $v['c']; } }
		$names = self::product_names(); $boxes = self::box_names(); $carts = self::carts_by_email(); /* 1.28.0 (т. 27) */
		$out = array();
		foreach ( $rows as $r ) {
			$o = $r['order_id'] ? wc_get_order( (int) $r['order_id'] ) : null;
			$ip = (string) $r['ip']; $vlabel = '' !== $ip ? substr( md5( $ip . '|' . wp_salt( 'nonce' ) ), 0, 4 ) : ''; $vn = '' !== $ip ? (int) ( $visits[ $ip ] ?? 1 ) : 1;
			$items = $r['items'] ? json_decode( $r['items'], true ) : array(); $items = is_array( $items ) ? $items : array();
			$ic = array(); foreach ( array_count_values( array_map( 'strval', $items ) ) as $k => $q2 ) { $ic[] = ( $q2 > 1 ? $q2 . '× ' : '' ) . ( $names[ $k ] ?? $k ); }
			$timeline = array(); $seen = array();
			foreach ( (array) ( $by[ $r['sid'] ] ?? array() ) as $e ) {
				$key = $e['ev'] . '|' . $e['k']; if ( isset( $seen[ $key ] ) && 'item' !== $e['ev'] ) { continue; } $seen[ $key ] = 1;
				$timeline[] = array( 'ev' => $e['ev'], 't' => substr( $e['at'], 11, 5 ), 'l' => self::SHORT[ $e['ev'] ] ?? $e['ev'], 'x' => 'item' === $e['ev'] ? ( $names[ $e['k'] ] ?? $e['k'] ) : ( 'box' === $e['ev'] ? ( $boxes[ $e['box'] ] ?? $e['box'] ) : '' ) );
			}
			$out[] = array(
				'id' => (int) $r['id'], 'sid' => $r['sid'], 'nosess' => self::is_order_sid( $r['sid'] ), 'visitor' => $vlabel, 'visits' => $vn, 'started' => $r['started_at'], 'last' => $r['last_at'], 'ago' => human_time_diff( strtotime( $r['last_at'] ), current_time( 'timestamp' ) ),
				'utm' => $r['utm'], 'utm_name' => $r['utm'] ? ( $names[ $r['utm'] ] ?? $r['utm'] ) : '', 'src' => trim( $r['src'] . ( $r['med'] ? ' / ' . $r['med'] : '' ) . ( $r['camp'] ? ' · ' . $r['camp'] : '' ) ), 'ref' => $r['ref'] ? (string) wp_parse_url( $r['ref'], PHP_URL_HOST ) : '', 'device' => $r['device'],
				'email' => $r['lead_email'] ? (string) $r['lead_email'] : ( $o ? (string) $o->get_billing_email() : '' ), 'name' => $o ? trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) : '',
				'box' => $r['box'], 'box_name' => $boxes[ $r['box'] ] ?? $r['box'], 'items' => $ic, 'step' => $r['step'], 'step_label' => self::SHORT[ $r['step'] ] ?? $r['step'], 'timeline' => $timeline,
				'order' => $o ? array( 'id' => $o->get_id(), 'number' => $o->get_order_number(), 'status' => wc_get_order_status_name( $o->get_status() ), 'total' => $o->get_total(), 'edit' => $o->get_edit_order_url() ) : null,
				'utm_last' => (string) ( $r['utm_last'] ?? '' ), 'internal' => ! empty( $r['internal'] ), 'camp' => trim( (string) $r['src'] . ( $r['med'] ? ' / ' . $r['med'] : '' ) . ( $r['camp'] ? ' · ' . $r['camp'] : '' ) ),
				'ch' => Channel::of( $r ), /* 1.38.0: каналът */
				'cart' => '', /* 1.28.0 (т. 27): изоставена количка във FunnelKit за същия имейл, без поръчка */
			);
			$em = strtolower( (string) end( $out )['email'] ); if ( ! $o && '' !== $em && isset( $carts[ $em ] ) ) { $out[ count( $out ) - 1 ]['cart'] = $carts[ $em ]; }
		}
		return $out;
	}
	private static function product_names() { static $n = null; if ( null === $n ) { $n = array(); foreach ( (array) Config::get_published()['products'] as $p ) { $n[ $p['key'] ] = $p['name'] ?: $p['key']; } } return $n; }
	private static function box_names() { static $n = null; if ( null === $n ) { $n = array(); foreach ( (array) Config::get_published()['boxes'] as $b ) { $n[ $b['id'] ] = $b['ic'] . ' ' . $b['name']; } } return $n; }

	public static function ajax_feed() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'auth' ), 403 ); }
		wp_send_json_success( self::feed_payload( (int) ( $_GET['days'] ?? 30 ), (int) ( $_GET['limit'] ?? 40 ) ) );
	}
	public static function kpi( $r ) {
		$f = $r['funnel'];
		return array( 'sessions' => $r['sessions'], 'visitors' => (int) ( $r['visitors'] ?? 0 ), 'per' => $r['visitors'] ? number_format( $r['sessions'] / $r['visitors'], 1, ',', '' ) : '—', 'gate' => $f['gate'], 'box' => $f['box'], 'full' => $f['full'], 'add' => $f['add'], 'created' => $f['created'], 'paid' => $f['paid'], 'leads' => $r['leads'], 'orders' => $r['orders'], 'conv' => self::pct( $f['created'], $r['sessions'] ) );
	}

	/* ── 1.23.0 (човекът: „професионален dashboard … колко от Поръчали са Взети (Delivered), реализиран и нереализиран оборот“) ──
	   Поръчките от WooCommerce (промо, без чернови) за период: ВЗЕТИ = „получена“ (tombola.statuses — само Delivered, решение от 1.9.0) → реализиран оборот;
	   отказани / неуспешни / възстановени → загубен оборот; всичко останало (чака, обработва се, пътува, „изпълнена“) → нереализиран — още може да бъде взет. */
	const LOST_STATUSES = array( 'cancelled', 'failed', 'refunded', 'trash', 'checkout-draft' );
	/* ── 1.24.26 (човекът: „трябва да има projected delivery rate… пратка, престояла повече от 5 дни в офис и не е delivered, най-вероятно няма да се вземе;
	   току-що изпратени и стояли 1 ден не трябва да влизат в изчисленията“) ──
	   Прогнозата се смята САМО върху РЕШЕНИТЕ поръчки: взети (Delivered) + отказани/неуспешни + „застояли“ (в път, но от ≥ N дни в текущия си статус —
	   най-вероятно няма да се вземат). Пресните (в път < N дни) са неизвестни и НЕ влизат. proj = взети ÷ решени. Очаквани накрая = взети + пресни × proj
	   (брой и оборот). Възрастта е от влизането в текущия статус: meta _ansa_promo_status_at (пише се при всяка смяна на статус от 1.24.26), иначе
	   date_modified, иначе date_created. N по подразбиране 5 дни — чиповете „в път ≥ N дни“ на дашборда (option ansa_promo_ins_mature). */
	const MATURE_DAYS = 5; const MATURE_OPTIONS = array( 3, 5, 7, 10, 14 ); const STATUS_AT_META = '_ansa_promo_status_at';
	public static function mature_days() { $n = (int) get_option( 'ansa_promo_ins_mature', self::MATURE_DAYS ); return in_array( $n, self::MATURE_OPTIONS, true ) ? $n : self::MATURE_DAYS; }
	public static function set_mature_days( $n ) { $n = (int) $n; if ( in_array( $n, self::MATURE_OPTIONS, true ) ) { update_option( 'ansa_promo_ins_mature', $n, false ); } return self::mature_days(); }
	/** Кога поръчката е влязла в текущия си статус (unix timestamp, UTC). */
	public static function status_since( $o ) {
		$m = (string) $o->get_meta( self::STATUS_AT_META ); if ( '' !== $m ) { $ts = ctype_digit( $m ) ? (int) $m : (int) strtotime( $m ); if ( $ts > 0 ) { return $ts; } }
		foreach ( array( 'get_date_modified', 'get_date_created' ) as $f ) { if ( method_exists( $o, $f ) ) { $d = $o->$f(); if ( $d ) { return (int) $d->getTimestamp(); } } }
		return 0;
	}
	public static function status_age_days( $o, $now = null ) { $since = self::status_since( $o ); if ( ! $since ) { return 0.0; } $now = $now ?: time(); return max( 0.0, ( $now - $since ) / DAY_IN_SECONDS ); }
	public static function money( $v ) { return number_format( (float) $v, 2, ',', ' ' ) . ' €'; }
	/** Периодът за $days (1 = днес, 7, 30, 90; 0 = null = всичко). */
	public static function range_for_days( $days ) { $days = (int) $days; if ( $days <= 0 ) { return null; } return self::range( substr( self::since( $days ), 0, 10 ), current_time( 'Y-m-d' ) ); }
	/** 1.28.0 (т. 15): поръчките се отнасят към ДАТАТА НА СЕСИЯТА (не към датата на поръчката в WooCommerce) — така „сесии с поръчка“ и „поръчки“ за
	 *  период са едни и същи поръчки. Поръчка без проследена сесия има сесия order-<id> от датата на поръчката (backfill), т.е. пак влиза. $o: internal,
	 *  attr, filter (както sessions_report) — оборотът следва филтъра. Връща и by_id (id => total/grp/status/day/tracked) и by_day (за графиката). */
	public static function orders_stats( $rg = null, $mature = null, $now = null, $o = array() ) {
		global $wpdb; $mature = (int) $mature ?: self::mature_days(); $now = (int) $now ?: time(); $o = is_array( $o ) ? $o : array();
		$z = array( 'n' => 0, 'total' => 0.0 ); $out = array( 'n' => 0, 'total' => 0.0, 'delivered' => $z, 'open' => $z, 'lost' => $z, 'by_status' => array(), 'avg' => 0.0, 'delivered_pct' => '—',
			/* 1.24.26: stale = в път ≥ N дни (вероятно загубени), young = пресни (не влизат); decided = взети + загубени + stale; proj = взети ÷ decided */
			'mature_days' => $mature, 'stale' => $z, 'young' => $z, 'decided' => 0, 'proj_raw' => null, 'proj_pct' => '—', 'proj_n' => 0, 'proj_total' => 0.0, 'proj_final_pct' => '—',
			'by_id' => array(), 'by_day' => array(), 'untracked' => $z, 'phone' => array( 'n' => 0, 'total' => 0.0, 'pct' => 0, 'gift' => 0 ) );
		if ( ! function_exists( 'wc_get_order' ) ) { return $out; }
		$ts = Schema::table( 'sessions' ); $w = array( 'order_id IS NOT NULL AND order_id > 0' ); if ( is_array( $rg ) ) { $w[] = self::where_range( 'started_at', $rg ); } $w = array_merge( $w, self::filter_where( $o ) );
		$cfg = Config::get_published(); $seen = array();
		foreach ( (array) $wpdb->get_results( "SELECT order_id, started_at, sid FROM $ts WHERE " . implode( ' AND ', $w ) . ' ORDER BY started_at ASC', ARRAY_A ) as $sr ) {
			$oid = (int) $sr['order_id']; if ( isset( $seen[ $oid ] ) ) { continue; } $seen[ $oid ] = 1;
			$ord = wc_get_order( $oid ); if ( ! $ord ) { continue; }
			$m = (array) $ord->get_meta( '_ansa_promo' ); if ( ! empty( $m['draft'] ) || ! $ord->get_meta( '_ansa_promo_id' ) ) { continue; }
			$st = (string) $ord->get_status(); $t = (float) $ord->get_total(); $day = substr( (string) $sr['started_at'], 0, 10 ); $tracked = ! self::is_order_sid( $sr['sid'] );
			$grp = Tombola::delivered( $ord, $cfg ) ? 'delivered' : ( in_array( $st, self::LOST_STATUSES, true ) ? 'lost' : 'open' );
			$out['n']++; $out['total'] += $t; $out[ $grp ]['n']++; $out[ $grp ]['total'] += $t;
			if ( ! $tracked ) { $out['untracked']['n']++; $out['untracked']['total'] += $t; } if ( Phone_Orders::is_phone_sid( $sr['sid'] ) ) { $out['phone']['n']++; $out['phone']['total'] += $t; $pb = (string) ( $m['phone_order']['bonus'] ?? '' ); if ( 'pct' === $pb ) { $out['phone']['pct']++; } elseif ( 'gift' === $pb ) { $out['phone']['gift']++; } } /* 1.29.0; 1.30.0: бонусите */
			if ( ! isset( $out['by_status'][ $st ] ) ) { $out['by_status'][ $st ] = array( 'n' => 0, 'total' => 0.0, 'name' => wc_get_order_status_name( $st ), 'grp' => $grp, 'stale' => 0, 'young' => 0 ); }
			$out['by_status'][ $st ]['n']++; $out['by_status'][ $st ]['total'] += $t;
			if ( 'open' === $grp ) { $sub = self::status_age_days( $ord, $now ) >= $mature ? 'stale' : 'young'; $out[ $sub ]['n']++; $out[ $sub ]['total'] += $t; $out['by_status'][ $st ][ $sub ]++; }
			$out['by_id'][ $oid ] = array( 'total' => $t, 'grp' => $grp, 'status' => $st, 'day' => $day, 'tracked' => $tracked );
			if ( ! isset( $out['by_day'][ $day ] ) ) { $out['by_day'][ $day ] = array( 'n' => 0, 'total' => 0.0, 'delivered' => 0, 'rev_del' => 0.0 ); } $out['by_day'][ $day ]['n']++; $out['by_day'][ $day ]['total'] += $t; if ( 'delivered' === $grp ) { $out['by_day'][ $day ]['delivered']++; $out['by_day'][ $day ]['rev_del'] += $t; }
		}
		ksort( $out['by_day'] );
		$out['avg'] = $out['n'] ? $out['total'] / $out['n'] : 0.0; $out['delivered_pct'] = self::pct( $out['delivered']['n'], $out['n'] );
		$dec = $out['delivered']['n'] + $out['lost']['n'] + $out['stale']['n']; $out['decided'] = $dec;
		if ( $dec > 0 ) { $p = $out['delivered']['n'] / $dec; $out['proj_raw'] = $p; $out['proj_pct'] = self::pct( $out['delivered']['n'], $dec ); $out['proj_n'] = $out['delivered']['n'] + (int) round( $out['young']['n'] * $p ); $out['proj_total'] = $out['delivered']['total'] + $out['young']['total'] * $p; $out['proj_final_pct'] = self::pct( $out['proj_n'], $out['n'] ); }
		uasort( $out['by_status'], function ( $a, $b ) { return $b['n'] <=> $a['n']; } );
		return $out;
	}
	/* ── 1.28.0 (т. 3, 10): речникът — всяка плочка/колона с „?“ и секция „Как се смята“; източникът е в бадж: „сесии“ или „WooCommerce“ ── */
	const GLOSSARY = array(
		'sessions' => 'Сесия = едно влизане в промо страницата (бисквитка за деня / таб на браузъра). Същият човек утре е нова сесия. Поръчки без проследен път (стар кеш, преди 16.10) НЕ са тук — виж „Поръчки без проследен път“.',
		'visitors' => 'Уникален посетител = различна посетителска бисквитка (30 дни). Без бисквитка — по IP; без IP — самата сесия. Офис с общ IP вече не е „един човек“.',
		'leads' => 'Сесии, в които човекът е написал имейл в попъпа.',
		'bounce' => 'Сесии, които са видели страницата и нищо повече (без попъп, без кутия) — „само видели“.',
		'dur' => 'Средно време от първото до последното действие в сесията (само сесии с поне две действия; таван 4 ч).',
		'gate' => 'Сесии, минали през имейл-попъпа (с имейл или „Пропусни“).',
		'box' => 'Сесии, избрали кутия (последната избрана).',
		'full' => 'Сесии, напълнили кутията до минимума ѝ (1 / 3 / 5 опаковки).',
		'add' => 'Сесии, натиснали „Към плащане“ — кутията е в количката на WooCommerce.',
		'created' => 'Сесии, в които е направена поръчка (сесия с проследен път). Процентите са спрямо сесиите и спрямо предишната стъпка.',
		'conv' => 'Сесии с поръчка ÷ всички сесии. Под 30 сесии числото е „малка извадка“ — не вади изводи от него.',
		'tto' => 'Средно и средно по средата (медиана) време от първото влизане до поръчката, за сесиите с поръчка.',
		'o_n' => 'Поръчки от WooCommerce (всички статуси, без чернови), отнесени към датата на СЕСИЯТА си — същите поръчки като „Сесии с поръчка“ + тези без проследен път.',
		'o_untracked' => 'Поръчки, чиято сесия не е проследена (стар promo.js от кеша, преди 16.10, или по телефон): броят се в Поръчки и Оборот, не и във фунията.',
		'o_phone' => 'Поръчки, направени от оператор по телефона от бутоните „✏️ Редактирай поръчка“ / „✅ Завърши поръчка“ в Колички и Сесии. Сесията на оператора е вътрешна; поръчката е истинска (източник „телефон“).',
		'o_delivered' => 'Взети = статус „получена“ (Delivered) в WooCommerce.',
		'o_open' => 'В път / чакат = всички статуси между поръчка и получаване; „застояли“ = в този статус от ≥ N дни (вероятно няма да се вземат).',
		'o_lost' => 'Отказани, неуспешни, възстановени, кошче.',
		'o_proj' => 'Прогноза = взети ÷ (взети + отказани + застояли). Пресните в път не влизат.',
		'rev_real' => 'Сумата на взетите поръчки, с ДДС и доставка, както е в WooCommerce.',
		'rev_open' => 'Сумата на поръчките в път / чакащи — още може да бъде взета.',
		'rev_lost' => 'Сумата на отказаните / неуспешните.',
		'rev_proj' => 'Взети + пресните в път × прогнозата.',
		'avg' => 'Средна стойност на поръчка (AOV) = общата сума ÷ броя поръчки.',
		'sh_view' => 'Сесии на продуктови страници (ansa™ Shrine), видели промо картата „5 = 3+2“.',
		'sh_pick' => 'Сесии, избрали картата 3+2 на продуктовата страница.',
		'sh_add' => 'Сесии, сложили Голямата кутия в количката от продуктова страница.',
		'sh_created' => 'Сесии с поръчка, минали през картата 3+2.',
		'delta' => 'Δ = промяната спрямо предишния период със същата дължина (точно преди избрания).',
		'small' => 'Под 30 сесии в реда: „малка извадка“ — конверсията е случайност, не тенденция.',
		'camp' => 'Кампания = utm_source / utm_medium · utm_campaign от линка на рекламата. UTM (продукт) = стойността на utm_content (кой продукт е в рекламата) — различни неща.',
		'attr' => 'Атрибуция: „първи UTM“ = с какво е влязъл човекът в сесията; „последен UTM“ = с какъв линк е бил последно (ако е влязъл пак с друга реклама в същата сесия).',
		'internal' => 'Вътрешен трафик = IP от списъка в настройките, логнати админи, тестовете на Probe. По подразбиране не влиза в нищо.',
		'spend' => 'Разходът е ръчно въведен (💸 Разход) по UTM или кампания и период; за периода се взема пропорционално по дни. CPA = разход ÷ поръчки; ROAS = оборот ÷ разход; цена на сесия = разход ÷ сесии.',
	);
	public static function q( $k ) { return isset( self::GLOSSARY[ $k ] ) ? '<span class="ap-q" title="' . esc_attr( self::GLOSSARY[ $k ] ) . '">?</span>' : ''; }
	public static function src_badge( $src ) { return '<i class="ap-src ap-src--' . ( 'wc' === $src ? 'wc' : 's' ) . '" title="източник на числото">' . ( 'wc' === $src ? 'WooCommerce' : 'сесии' ) . '</i>'; }
	public static function dur_fmt( $sec ) { $sec = (int) round( $sec ); if ( $sec <= 0 ) { return '—'; } if ( $sec < 60 ) { return $sec . ' с'; } if ( $sec < 3600 ) { return floor( $sec / 60 ) . ' мин ' . ( $sec % 60 ) . ' с'; } if ( $sec < DAY_IN_SECONDS ) { return floor( $sec / 3600 ) . ' ч ' . floor( ( $sec % 3600 ) / 60 ) . ' мин'; } return number_format( $sec / DAY_IN_SECONDS, 1, ',', '' ) . ' дни'; }

	/** Плочките на дашборда: k => [group, label, value, sub (HTML), tone, hero, delta (HTML), spark (SVG), source]. Същите в екраните; живото опресняване праща value/sub по k.
	 *  1.28.0: $ctx['prev'] = ['r' => sessions_report, 'os' => orders_stats] на предишния период → Δ (т. 21); $ctx['spark'] = [k => [стойности по дни]] (т. 7); имената са едни (т. 3). */
	public static function dashboard_data( $r, $os, $ctx = array() ) {
		$f = $r['funnel']; $k = self::kpi( $r ); $n = (int) $r['sessions']; $of = function ( $x ) use ( $n ) { return esc_html( self::pct( $x, $n ) ) . ' от сесиите'; };
		$per = (string) ( $ctx['period'] ?? '' ); $sfx = $per ? ' · ' . esc_html( $per ) : '';
		$z = array( 'n' => 0, 'total' => 0.0 ); $st = $os['stale'] ?? $z; $yg = $os['young'] ?? $z; $md = (int) ( $os['mature_days'] ?? self::MATURE_DAYS ); /* 1.24.26 */
		$un = $os['untracked'] ?? $z; $oo = (int) ( $r['orders_only'] ?? 0 ); $small = $n > 0 && $n < Insights_Reports::MIN_ROWS;
		$t = array(
			'sessions'    => array( 'traffic', 'Сесии', (string) $n, ( ! empty( $ctx['first'] ) ? 'броят се от ' . esc_html( $ctx['first'] ) : 'влизания с проследен път' ) . $sfx, '', false ),
			'visitors'    => array( 'traffic', 'Уникални посетители', (string) $k['visitors'], 'по бисквитка · <b data-k="per">' . esc_html( $k['per'] ) . '</b> сесии на посетител', 'good', false ),
			'leads'       => array( 'traffic', 'Имейли от попъпа', (string) $k['leads'], $of( $k['leads'] ), '', false ),
			'bounce'      => array( 'traffic', 'Само видели (bounce)', esc_html( self::pct( (int) ( $r['bounce'] ?? 0 ), $n ) ), '<b data-k="bounce_n">' . (int) ( $r['bounce'] ?? 0 ) . '</b> сесии без попъп и без кутия', 'warn', false ),
			'dur'         => array( 'traffic', 'Средна продължителност', self::dur_fmt( (float) ( $r['dur_avg'] ?? 0 ) ), 'сесии с ≥ 2 действия: <b data-k="dur_n">' . (int) ( $r['dur_n'] ?? 0 ) . '</b>', '', false ),
			'gate'        => array( 'funnel', 'Минали попъпа', (string) $f['gate'], $of( $f['gate'] ), '', false ),
			'box'         => array( 'funnel', 'Избрали кутия', (string) $f['box'], $of( $f['box'] ) . ' · ' . esc_html( self::pct( $f['box'], $f['gate'] ) ) . ' от попъпа', '', false ),
			'full'        => array( 'funnel', 'Напълнили кутията', (string) $f['full'], $of( $f['full'] ) . ' · ' . esc_html( self::pct( $f['full'], $f['box'] ) ) . ' от избралите', '', false ),
			'add'         => array( 'funnel', 'Към плащане', (string) $f['add'], $of( $f['add'] ) . ' · ' . esc_html( self::pct( $f['add'], $f['full'] ) ) . ' от напълнилите', '', false ),
			'created'     => array( 'funnel', 'Сесии с поръчка', (string) $f['created'], $of( $f['created'] ) . ' · ' . esc_html( self::pct( $f['created'], $f['add'] ) ) . ' от плащане', 'good', false ),
			'conv'        => array( 'funnel', 'Конверсия сесия → поръчка', (string) $k['conv'], ( $small ? '<b class="ap-small">малка извадка</b> · ' : '' ) . 'напълнили, но не поръчали: <b data-k="full_no">' . (int) max( 0, $f['full'] - $f['created'] ) . '</b>', 'info', false ),
			'tto'         => array( 'funnel', 'Време до поръчка', self::dur_fmt( (float) ( $r['tto_med'] ?? 0 ) ), 'медиана · средно <b data-k="tto_avg">' . esc_html( self::dur_fmt( (float) ( $r['tto_avg'] ?? 0 ) ) ) . '</b> · <b data-k="tto_n">' . (int) ( $r['tto_n'] ?? 0 ) . '</b> поръчки', '', false ),
			'o_n'         => array( 'orders', 'Поръчки', (string) $os['n'], 'WooCommerce, по датата на сесията · <b data-k="o_total">' . esc_html( self::money( $os['total'] ) ) . '</b>', 'info', false ),
			'o_untracked' => array( 'orders', 'Поръчки без проследен път', (string) $un['n'], 'не са във фунията · ' . esc_html( self::money( $un['total'] ) ) . ( $oo !== (int) $un['n'] ? ' · сесии order-№: <b data-k="oo">' . $oo . '</b>' : '' ), 'warn', false ),
			'o_phone'     => array( 'orders', '📞 Поръчки по телефон', (string) ( $os['phone']['n'] ?? 0 ), 'от обаждане · ' . esc_html( self::money( $os['phone']['total'] ?? 0 ) ) . ' · с −%: <b data-k="o_phone_pct">' . (int) ( $os['phone']['pct'] ?? 0 ) . '</b> · с подарък: <b data-k="o_phone_gift">' . (int) ( $os['phone']['gift'] ?? 0 ) . '</b>', 'info', false ),
			'o_delivered' => array( 'orders', 'Взети (Delivered)', (string) $os['delivered']['n'], '<b data-k="o_delivered_pct">' . esc_html( $os['delivered_pct'] ) . '</b> от поръчките · ' . esc_html( self::money( $os['delivered']['total'] ) ), 'good', false ),
			'o_open'      => array( 'orders', 'В път / чакат', (string) $os['open']['n'], 'още не са взети · ' . esc_html( self::money( $os['open']['total'] ) ) . ' · от тях ≥ ' . $md . ' дни: <b data-k="o_stale">' . (int) $st['n'] . '</b> · пресни: <b data-k="o_young">' . (int) $yg['n'] . '</b>', 'warn', false ),
			'o_lost'      => array( 'orders', 'Отказани / неуспешни', (string) $os['lost']['n'], 'отказани, неуспешни, възстановени · ' . esc_html( self::money( $os['lost']['total'] ) ), 'bad', false ),
			/* 1.24.26: прогнозата — само върху решените; пресните (< N дни в път) не влизат */
			'o_proj'      => array( 'orders', 'Прогноза взети (projected)', (string) ( $os['proj_pct'] ?? '—' ), 'върху решените <b data-k="o_decided">' . (int) ( $os['decided'] ?? 0 ) . '</b>: взети ' . (int) $os['delivered']['n'] . ' + отказани ' . (int) $os['lost']['n'] . ' + в път ≥ ' . $md . ' дни <b data-k="o_stale2">' . (int) $st['n'] . '</b> · пресните <b data-k="o_young2">' . (int) $yg['n'] . '</b> не влизат', 'info', false ),
			'rev_real'    => array( 'revenue', 'Реализиран оборот', self::money( $os['delivered']['total'] ), 'взетите поръчки (Delivered) · <b data-k="o_delivered2">' . (int) $os['delivered']['n'] . '</b> бр.', 'good', true ),
			'rev_open'    => array( 'revenue', 'Нереализиран оборот', self::money( $os['open']['total'] ), 'поръчани, още не взети · <b data-k="o_open2">' . (int) $os['open']['n'] . '</b> бр.', 'warn', false ),
			'rev_lost'    => array( 'revenue', 'Загубен оборот', self::money( $os['lost']['total'] ), 'отказани / неуспешни · <b data-k="o_lost2">' . (int) $os['lost']['n'] . '</b> бр.', 'bad', false ),
			'rev_proj'    => array( 'revenue', 'Прогнозен оборот', self::money( $os['proj_total'] ?? 0 ), 'взети + пресните × прогнозата · очаквани накрая ≈ <b data-k="o_proj_n">' . (int) ( $os['proj_n'] ?? 0 ) . '</b> от ' . (int) $os['n'] . ' (' . esc_html( (string) ( $os['proj_final_pct'] ?? '—' ) ) . ') · под риск (в път ≥ ' . $md . ' дни): <b data-k="rev_stale">' . esc_html( self::money( $st['total'] ) ) . '</b>', 'info', false ),
			'avg'         => array( 'revenue', 'Средна количка (AOV)', self::money( $os['avg'] ), 'на поръчка · общо <b data-k="o_total2">' . esc_html( self::money( $os['total'] ) ) . '</b>', '', false ),
		);
		/* 1.24.2: Shrine · 3+2 — сборове по всички продукти */
		$sh = array( 'view' => 0, 'pick' => 0, 'more' => 0, 'add' => 0, 'created' => 0 ); foreach ( (array) ( $r['shrine'] ?? array() ) as $v ) { foreach ( $sh as $kk => $_ ) { $sh[ $kk ] += (int) ( $v[ $kk ] ?? 0 ); } }
		$t['sh_view']    = array( 'shrine', 'Видели картата 3+2', (string) $sh['view'], 'сесии на продуктови страници с промо картата', '', false );
		$t['sh_pick']    = array( 'shrine', 'Избрали 3+2', (string) $sh['pick'], esc_html( self::pct( $sh['pick'], $sh['view'] ) ) . ' от видeлите · „Виж повече“: <b data-k="sh_more">' . (int) $sh['more'] . '</b>', '', false );
		$t['sh_add']     = array( 'shrine', 'Сложили кутията в количката', (string) $sh['add'], esc_html( self::pct( $sh['add'], $sh['view'] ) ) . ' от видeлите', 'info', false );
		$t['sh_created'] = array( 'shrine', 'Поръчали през 3+2', (string) $sh['created'], esc_html( self::pct( $sh['created'], $sh['view'] ) ) . ' от видeлите · ' . esc_html( self::pct( $sh['created'], $sh['add'] ) ) . ' от количката', 'good', false );
		/* източникът, Δ и sparkline */
		$wc = array( 'o_n', 'o_untracked', 'o_phone', 'o_delivered', 'o_open', 'o_lost', 'o_proj', 'rev_real', 'rev_open', 'rev_lost', 'rev_proj', 'avg' ); $inverse = array( 'bounce', 'o_lost', 'rev_lost', 'tto' );
		$prev = isset( $ctx['prev']['r'], $ctx['prev']['os'] ) ? self::dashboard_data( $ctx['prev']['r'], $ctx['prev']['os'] ) : null;
		foreach ( $t as $kk => &$tile ) {
			$tile[6] = $prev && isset( $prev[ $kk ] ) ? self::delta_html( $tile[2], $prev[ $kk ][2], in_array( $kk, $inverse, true ) ) : '';
			$tile[7] = isset( $ctx['spark'][ $kk ] ) ? self::spark( $ctx['spark'][ $kk ] ) : '';
			$tile[8] = in_array( $kk, $wc, true ) ? 'wc' : 's';
		} unset( $tile );
		return $t;
	}
	const GROUPS = array( 'traffic' => '👥 Трафик', 'funnel' => '🔻 Фуния по сесии', 'shrine' => '🛍️ Shrine · 3+2', 'orders' => '🛒 Поръчки (WooCommerce)', 'revenue' => '💶 Оборот' );
	const GROUP_HINTS = array( 'traffic' => 'сесия = едно влизане; посетител = различна бисквитка (30 дни); вътрешният трафик и ботовете не влизат', 'funnel' => 'докъде стига всяка сесия, веднъж на стъпка', 'shrine' => 'промо картата „5 = 3+2“ на продуктовите страници (ansa™ Shrine)', 'orders' => 'WooCommerce, отнесени към датата на сесията; взети = статус „получена“ (Delivered); в път = всичко между поръчка и получаване; прогноза = взети ÷ (взети + отказани + в път ≥ N дни) — пресните не влизат', 'revenue' => 'сумите на поръчките с ДДС и доставка, както са в WooCommerce' );
	private static function tile( $label, $value, $sub = '', $tone = '', $k = '', $hero = false, $delta = '', $spark = '', $src = '' ) {
		return '<div class="ap-st' . ( $tone ? ' ap-st--' . $tone : '' ) . ( $hero ? ' ap-st--hero' : '' ) . '"><span class="ap-st__l">' . esc_html( $label ) . ( $k ? self::q( $k ) : '' ) . ( $src ? self::src_badge( $src ) : '' ) . '</span>'
			. '<span class="ap-st__row"><b class="ap-st__v"' . ( $k ? ' data-k="' . esc_attr( $k ) . '"' : '' ) . '>' . esc_html( $value ) . '</b>' . ( $delta ? '<span class="ap-st__d"' . ( $k ? ' data-kd="' . esc_attr( $k ) . '"' : '' ) . '>' . $delta . '</span>' : '' ) . ( $spark ? '<span class="ap-st__sp">' . $spark . '</span>' : '' ) . '</span>'
			. ( '' !== $sub ? '<small class="ap-st__s"' . ( $k ? ' data-ks="' . esc_attr( $k ) . '"' : '' ) . '>' . $sub . '</small>' : '' ) . '</div>';
	}
	private static function group_html( $title, $tiles, $hint = '', $id = '' ) { return '<div class="ap-stg"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '><div class="ap-stg__h"><span class="ap-stg__t">' . $title . '</span>' . ( $hint ? '<span class="ap-stg__hint">' . $hint . '</span>' : '' ) . '</div><div class="ap-stg__g">' . $tiles . '</div></div>'; }
	/** Целият дашборд (4 групи) от dashboard_data(). */
	public static function dashboard_html( $data, $id = 'apInsDash' ) {
		$h = '<div class="ap-dash" id="' . esc_attr( $id ) . '">';
		foreach ( self::GROUPS as $g => $title ) { $tiles = ''; foreach ( $data as $k => $t ) { if ( $t[0] === $g ) { $tiles .= self::tile( $t[1], $t[2], $t[3], $t[4], $k, $t[5], $t[6] ?? '', $t[7] ?? '', $t[8] ?? '' ); } } $h .= self::group_html( $title, $tiles, ( self::GROUP_HINTS[ $g ] ?? '' ) . ( 'orders' === $g ? self::mature_picker() : '' ) ); }
		return $h . '</div>';
	}
	/** 1.24.26: чиповете „застояла = в път ≥ N дни“ (3/5/7/10/14) — пазят се в option и важат за трите екрана и живото опресняване. */
	public static function mature_picker() {
		$cur = self::mature_days(); $keep = array(); foreach ( array( 'tab', 'view', 'days', 'from', 'to', 'step', 'mode', 'box', 'ord', 'dev' ) as $k ) { if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) { $keep[ $k ] = sanitize_text_field( (string) wp_unslash( $_GET[ $k ] ) ); } } $keep['tab'] = 'insights';
		$h = ' · <span class="ap-mature" title="от колко дни в текущия статус пратката в път се брои за застояла (най-вероятно няма да се вземе) — такива влизат в прогнозата като загубени; по-пресните не влизат">застояла = в път ≥ ';
		foreach ( self::MATURE_OPTIONS as $n ) { $h .= '<a class="ap-chip ap-chip--sm' . ( $n === $cur ? ' on' : '' ) . '" href="' . esc_url( self::url( array_merge( $keep, array( 'mature' => $n ) ) ) ) . '">' . (int) $n . '</a>'; }
		return $h . ' дни</span>';
	}
	/** За живото опресняване: k => value и k => sub (HTML) — плюс вложените data-k в sub-овете (per, full_no, o_total…), които се сменят с innerHTML на sub-а. */
	public static function dashboard_flat( $data ) { $v = array(); $sub = array(); $d = array(); foreach ( $data as $k => $t ) { $v[ $k ] = $t[2]; $sub[ $k ] = $t[3]; $d[ $k ] = $t[6] ?? ''; } return array( 'kpi' => $v, 'kpi_sub' => $sub, 'kpi_delta' => $d ); }

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
	/** 1.17.0: CSV на сесиите за период. 1.21.0: $q (масив) = от–до + филтрите на историята (sessions_where) вместо $days. */
	public static function csv_sessions( $days = 30, $q = null ) {
		global $wpdb; $ts = Schema::table( 'sessions' ); $tl = Schema::table( 'leads' );
		if ( is_array( $q ) ) { $w = self::sessions_where( $q ); } else { $since = self::since( $days ); $w = $since ? $wpdb->prepare( 'WHERE s.started_at >= %s', $since ) : ''; }
		$rows = $wpdb->get_results( "SELECT s.sid, s.started_at, s.last_at, s.utm, s.src, s.med, s.camp, s.ref, s.device, l.email, s.box, s.items, s.step, s.order_id, s.ip FROM $ts s LEFT JOIN $tl l ON l.id = s.lead_id $w ORDER BY s.started_at", ARRAY_A );
		$fh = fopen( 'php://temp', 'w+' ); fputs( $fh, "\xEF\xBB\xBF" ); fputcsv( $fh, array( 'sid', 'започната', 'последно', 'utm', 'source', 'medium', 'campaign', 'referrer', 'устройство', 'имейл', 'кутия', 'продукти', 'стъпка', 'поръчка', 'посетител' ), ';' );
		foreach ( (array) $rows as $r ) { $it = $r['items'] ? json_decode( $r['items'], true ) : array(); $r['items'] = is_array( $it ) ? implode( ' ', $it ) : ''; $r['ip'] = '' !== (string) $r['ip'] ? substr( md5( $r['ip'] . '|' . wp_salt( 'nonce' ) ), 0, 8 ) : ''; fputcsv( $fh, $r, ';' ); } /* 1.20.4: посетител = хеш на IP (същият човек = същият код), не адресът */
		rewind( $fh ); $csv = stream_get_contents( $fh ); fclose( $fh ); return $csv;
	}
	public static function pct( $a, $b ) { return $b > 0 ? number_format( 100 * $a / $b, 1, ',', '' ) . '%' : '—'; }

	/* ── екранът 📈 Insights (в рамката на приложението: карти .ap-sec) ──
	   1.21.0 (човекът): три екрана — 📊 Преглед (период: днес/7/30/90/всичко, фуния, продукти, разбивки), 🟢 На живо (ОТДЕЛЕН таб: последните сесии
	   с опресняване + KPI за днес), 📜 История (от–до, по подразбиране от старта 16.10.2026: KPI, „загубени“, докъде са стигнали и къде са спрели,
	   по дни, списък на сесиите с филтри стъпка/спрели точно/кутия/поръчка/устройство и страници, CSV на точно този филтър). */
	/** 1.28.0 (т. 1, 2): екраните — 📊 Преглед (анализът за периода), 🟢 На живо, 🧾 Сесии (списъкът + докъде стигат и къде спират; старото „История“), 🛒 Колички, 💸 Разход, 📌 Отчети. */
	public static function views() { return array( 'overview' => '📊 Преглед', 'live' => '🟢 На живо', 'sessions' => '🧾 Сесии', 'carts' => '🛒 Изоставени колички', 'spend' => '💸 Разход', 'snap' => '📌 Отчети' ); }
	public static function current_view() { $v = sanitize_key( (string) ( $_GET['view'] ?? 'overview' ) ); if ( 'history' === $v ) { $v = 'sessions'; } return isset( self::views()[ $v ] ) ? $v : 'overview'; }
	public static function render( $days = 30 ) {
		self::$period = null; self::$period_explicit = false; /* 1.28.0: периодът се чете наново за всеки рендер */
		$view = self::current_view();
		if ( isset( $_GET['mature'] ) && ( self::$portal || current_user_can( Plugin::CAP ) ) ) { self::set_mature_days( (int) $_GET['mature'] ); } /* 1.24.26: прагът „застояла“ */
		self::backfill_orders(); /* 1.21.1: поръчки без сесия → сесия order-<id> (най-много веднъж на 10 мин) */
		self::styles();
		/* 1.28.0 (т. 1): без чиповете на изгледите — навигацията е една (менюто); тук е само периодът */
		if ( 'live' === $view ) { self::render_live(); } elseif ( 'sessions' === $view ) { self::render_sessions(); } elseif ( 'carts' === $view ) { echo self::carts_html( $days ); } elseif ( 'spend' === $view ) { self::render_spend(); } elseif ( 'snap' === $view ) { self::render_snaps(); } else { self::render_overview( $days ); }
		echo self::scripts();
	}
	/** Лентата с периода (чипове + от–до) и действията (CSV, 📌). $extra = HTML след формата. */
	private static function period_bar( $extra = '' ) {
		$p = self::period(); $chips = '';
		foreach ( self::quick_ranges() as $k => $v ) { $chips .= '<a class="ap-chip' . ( (string) $k === $p['key'] ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'from' => $v[0], 'to' => $v[1] ) ) ) . '">' . esc_html( $v[2] ) . '</a>'; }
		$today = current_time( 'Y-m-d' ); $view = self::current_view();
		$keep = ''; foreach ( self::FILTER_KEYS as $k ) { if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) { $keep .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( sanitize_text_field( (string) wp_unslash( $_GET[ $k ] ) ) ) . '">'; } }
		return '<div class="ap-actions ap-period" style="padding:0 0 12px"><div class="ap-chips">' . $chips . '</div>'
			. '<form method="get" action="' . esc_url( self::$portal ? Insights_Portal::url() : admin_url( 'admin.php' ) ) . '" class="ap-ins-range">' . ( self::$portal ? '' : '<input type="hidden" name="page" value="' . esc_attr( Plugin::MENU_SLUG ) . '"><input type="hidden" name="tab" value="insights">' ) . '<input type="hidden" name="view" value="' . esc_attr( $view ) . '">' . $keep
			. '<label>от <input type="date" name="from" value="' . esc_attr( $p['from'] ) . '" max="' . esc_attr( $today ) . '"></label><label>до <input type="date" name="to" value="' . esc_attr( $p['to'] ) . '" max="' . esc_attr( $today ) . '"></label><button class="button">Покажи</button></form><span class="sp"></span>' . $extra . '</div>';
	}
	/** Лентата с активните филтри (т. 22) + атрибуция (т. 12) + вътрешен трафик (т. 17). */
	private static function filter_bar( $f, $names, $boxes ) {
		$h = '<div class="ap-actions ap-fbar" style="padding:0 0 12px"><span class="ap-fbar__l">филтър' . self::q( 'camp' ) . '</span><div class="ap-chips">';
		$any = false;
		foreach ( array( 'utm' => '🏷️ UTM', 'src' => 'източник', 'med' => 'medium', 'camp' => 'кампания', 'dev' => 'устройство', 'box' => 'кутия' ) as $k => $l ) {
			if ( '' === $f[ $k ] ) { continue; } $any = true; $v = 'utm' === $k ? ( $names[ $f[ $k ] ] ?? $f[ $k ] ) : ( 'box' === $k ? ( $boxes[ $f[ $k ] ] ?? $f[ $k ] ) : $f[ $k ] );
			$h .= '<a class="ap-chip on" href="' . esc_url( self::url( array( 'f_' . $k => null, 'pg' => null ) ) ) . '" title="махни филтъра">' . esc_html( $l . ': ' . $v ) . ' ✕</a>';
		}
		if ( ! $any ) { $h .= '<small>няма — цъкни ред в таблица по UTM / кампания / устройство / кутия, за да видиш целия екран само за него</small>'; }
		else { $h .= '<a class="ap-chip" href="' . esc_url( self::url( array( 'f_utm' => null, 'f_src' => null, 'f_med' => null, 'f_camp' => null, 'f_dev' => null, 'f_box' => null, 'pg' => null ) ) ) . '">изчисти всички</a>'; }
		$h .= '</div><span class="sp"></span><div class="ap-chips"><span class="ap-fbar__l">атрибуция' . self::q( 'attr' ) . '</span><a class="ap-chip' . ( 'first' === $f['attr'] ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'attr' => null ) ) ) . '">първи UTM</a><a class="ap-chip' . ( 'last' === $f['attr'] ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'attr' => 'last' ) ) ) . '">последен UTM</a>'
			. '<span class="ap-fbar__l" style="margin-left:10px">вътрешен трафик' . self::q( 'internal' ) . '</span><a class="ap-chip' . ( $f['internal'] ? '' : ' on' ) . '" href="' . esc_url( self::url( array( 'internal' => null ) ) ) . '">скрит</a><a class="ap-chip' . ( $f['internal'] ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'internal' => '1' ) ) ) . '">включен</a></div></div>';
		return $h;
	}
	/** Опциите за sessions_report/orders_stats от филтрите на екрана. */
	public static function report_opts( $f, $totals = null ) { $o = array( 'attr' => $f['attr'], 'internal' => ! empty( $f['internal'] ), 'filter' => array( 'utm' => $f['utm'], 'src' => $f['src'], 'med' => $f['med'], 'camp' => $f['camp'], 'dev' => $f['dev'], 'box' => $f['box'] ) ); if ( null !== $totals ) { $o['totals'] = $totals; } return $o; }
	/** Всички данни за екрана „Преглед“ за период + филтри (и за 📌 замразяване, и за API, и за седмичния отчет). */
	public static function overview_data( $rg, $f = null ) {
		$f = $f ?: self::filters(); $o = self::report_opts( $f );
		$os = self::orders_stats( $rg, null, null, $o ); $r = self::sessions_report( 0, $rg, self::report_opts( $f, $os['by_id'] ) );
		$prg = self::prev_range( $rg ); $pos = self::orders_stats( $prg, null, null, $o ); $pr = self::sessions_report( 0, $prg, self::report_opts( $f, $pos['by_id'] ) );
		$spend = self::spend_for( $rg );
		return array( 'rg' => $rg, 'prg' => $prg, 'f' => $f, 'r' => $r, 'os' => $os, 'pr' => $pr, 'pos' => $pos, 'spend' => $spend, 'names' => self::product_names(), 'boxes' => self::box_names(), 'at' => current_time( 'mysql' ), 'cohorts' => self::cohorts( $rg, $o ) );
	}
	/** Фунията с ленти; $vf — уникални посетители на стъпка. 1.28.0 (т. 4): стесняващи се ленти, между стъпките червеното „−N (x %) загубени тук“. */
	private static function funnel_html( $f, $vf = null ) {
		$max = max( 1, (int) $f['view'] ); $h = '<div class="ap-funnel">'; $prev = null; $prev_st = '';
		foreach ( self::FUNNEL as $st ) {
			$n = (int) $f[ $st ]; $w = max( 2, round( 100 * $n / $max ) );
			if ( null !== $prev && $prev > 0 ) { $lost = $prev - $n; $h .= '<div class="ap-fn__drop' . ( $lost > 0 ? ' has' : '' ) . '"><span>' . ( $lost > 0 ? '▼ −' . $lost . ' <small>(' . esc_html( self::pct( $lost, $prev ) ) . ' от „' . esc_html( self::LABELS[ $prev_st ] ) . '“ спират тук)</small>' : '· без загуба' ) . '</span></div>'; }
			$h .= '<div class="ap-fn"><span class="ap-fn__l">' . esc_html( self::LABELS[ $st ] ) . self::q( $st ) . '</span><span class="ap-fn__bar"><i style="width:' . $w . '%"></i></span><b class="ap-fn__n">' . $n . '</b><small>' . esc_html( self::pct( $n, $max ) ) . ' от видели' . ( null !== $prev ? ' · ' . esc_html( self::pct( $n, $prev ) ) . ' от предишната' : '' ) . ( is_array( $vf ) ? ' · 👤 ' . (int) $vf[ $st ] : '' ) . '</small></div>';
			$prev = $n; $prev_st = $st;
		}
		return $h . '</div>';
	}
	/** 1.24.2: таблицата „Shrine · 3+2“ — по продукт: видели картата · избрали · „Виж повече“ · в количката · поръчали. */
	public static function shrine_table_html( $rows, $names ) {
		$h = '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🛍️ Shrine · офертата „5 = 3+2“ на продуктовите страници</span></div><div class="ap-sec__hint">Промо картата (4-тата опция) в ansa™ Shrine: колко сесии са я видели, избрали, отворили „Виж повече“, сложили Голямата кутия в количката и поръчали. Сесия = същата бисквитка като на промо страницата — човек, минал и оттам, е една сесия.</div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>продукт</th><th>видели картата</th><th>избрали</th><th>„Виж повече“</th><th>в количката</th><th>поръчали</th><th>конверсия (поръчали / видели)</th></tr></thead><tbody>';
		foreach ( (array) $rows as $k => $v ) { $h .= '<tr><td><b>' . esc_html( $names[ $k ] ?? $k ) . '</b> <small>' . esc_html( $k ) . '</small></td><td>' . (int) $v['view'] . '</td><td>' . (int) $v['pick'] . '</td><td>' . (int) $v['more'] . '</td><td>' . (int) $v['add'] . '</td><td><b>' . (int) $v['created'] . '</b></td><td>' . esc_html( self::pct( $v['created'], $v['view'] ) ) . '</td></tr>'; }
		if ( ! $rows ) { $h .= '<tr><td colspan="7"><small>още няма данни за периода — събитията идват от Shrine ≥ 6.26.8, когато промо картата е включена</small></td></tr>'; }
		return $h . '</tbody></table></div></div>';
	}
	/** Колоните на разбивките: key => [етикет, група (traffic|funnel|orders|revenue|spend), формат]. Групите се включват с чиповете над таблицата (т. 5). */
	const COLS = array(
		'n' => array( 'сесии', 'base', 'int' ), 'v' => array( 'посетители', 'traffic', 'int' ), 'gate' => array( 'попъп', 'funnel', 'int' ), 'box' => array( 'кутия', 'funnel', 'int' ), 'full' => array( 'пълна', 'funnel', 'int' ), 'add' => array( 'плащане', 'funnel', 'int' ),
		'created' => array( 'поръчки', 'base', 'int' ), 'paid' => array( 'платени', 'orders', 'int' ), 'delivered' => array( 'взети', 'orders', 'int' ), 'conv' => array( 'конверсия', 'base', 'pct' ),
		'rev' => array( 'оборот', 'revenue', 'money' ), 'rev_del' => array( 'взети €', 'revenue', 'money' ), 'aov' => array( 'AOV', 'revenue', 'money' ),
		'spend' => array( 'разход', 'spend', 'money' ), 'cps' => array( '€ / сесия', 'spend', 'money' ), 'cpa' => array( 'CPA', 'spend', 'money' ), 'roas' => array( 'ROAS', 'spend', 'x' ),
	);
	const COL_GROUPS = array( 'traffic' => '👥 трафик', 'funnel' => '🔻 фуния', 'orders' => '🛒 поръчки', 'revenue' => '💶 оборот', 'spend' => '💸 разход' );
	/** Изчислените колони на ред (conv, aov, cps, cpa, roas). $spend = сумата разход за реда (null = няма). */
	public static function row_calc( $v, $spend = null ) {
		$v = array_merge( array( 'n' => 0, 'v' => 0, 'gate' => 0, 'box' => 0, 'full' => 0, 'add' => 0, 'created' => 0, 'paid' => 0, 'delivered' => 0, 'rev' => 0.0, 'rev_del' => 0.0 ), (array) $v );
		$v['conv_raw'] = $v['n'] > 0 ? (float) $v['created'] / $v['n'] : null; $v['conv'] = self::pct( $v['created'], $v['n'] ); $v['aov'] = $v['created'] > 0 ? (float) $v['rev'] / $v['created'] : 0.0;
		$v['spend'] = null === $spend ? null : (float) $spend; $v['cps'] = null !== $spend && $v['n'] > 0 ? (float) $spend / $v['n'] : null; $v['cpa'] = null !== $spend && $v['created'] > 0 ? (float) $spend / $v['created'] : null; $v['roas'] = null !== $spend && $spend > 0 ? (float) $v['rev'] / $spend : null;
		$v['small'] = $v['n'] < Insights_Reports::MIN_ROWS;
		return $v;
	}
	private static function fmt_col( $v, $c ) {
		$fmt = self::COLS[ $c ][2]; $x = $v[ $c ] ?? null;
		if ( 'pct' === $c || 'conv' === $c ) { return esc_html( $v['conv'] ); }
		if ( null === $x ) { return '<small>—</small>'; }
		if ( 'money' === $fmt ) { return esc_html( self::money( $x ) ); } if ( 'x' === $fmt ) { return esc_html( number_format( (float) $x, 2, ',', '' ) . '×' ); }
		return (string) (int) $x;
	}
	/** Таблица-разбивка (по UTM / източник / кутия / устройство / дни / кампания…). 1.28.0: $opt = [ 'spend' => [key => сума], 'link' => fn(key) → href за филтър (т. 22), 'id', 'hint', 'glossary' ]. */
	private static function table_html( $title, $rows, $first, $fmt = null, $opt = array() ) {
		$opt = is_array( $opt ) ? $opt : array(); $id = $opt['id'] ?? 'apT' . substr( md5( $title ), 0, 6 ); $spend = isset( $opt['spend'] ) && is_array( $opt['spend'] ) ? $opt['spend'] : null;
		$calc = array(); $maxc = 0.0; foreach ( (array) $rows as $k => $v ) { $c = self::row_calc( $v, null !== $spend ? ( $spend[ $k ] ?? null ) : null ); $calc[ $k ] = $c; if ( ! $c['small'] && null !== $c['conv_raw'] ) { $maxc = max( $maxc, $c['conv_raw'] ); } }
		$has_spend = null !== $spend && (bool) array_filter( $spend );
		$chips = ''; foreach ( self::COL_GROUPS as $g => $l ) { if ( 'spend' === $g && ! $has_spend ) { continue; } $chips .= '<button type="button" class="ap-chip ap-chip--sm ap-colg' . ( 'traffic' === $g ? ' on' : '' ) . '" data-g="' . esc_attr( $g ) . '">' . esc_html( $l ) . '</button>'; }
		$h = '<div class="ap-sec ap-sec--table" id="' . esc_attr( $id ) . '"><div class="ap-sec__h"><span class="ap-sec__t">' . $title . ( ! empty( $opt['glossary'] ) ? self::q( $opt['glossary'] ) : '' ) . '</span><span class="ap-chips ap-colgs">' . $chips . '</span></div>' . ( ! empty( $opt['hint'] ) ? '<div class="ap-sec__hint">' . $opt['hint'] . '</div>' : '' ) . '<div class="ap-sec__b"><table class="widefat striped ap-sort g-traffic"><thead><tr><th>' . esc_html( $first ) . '</th>';
		foreach ( self::COLS as $c => $d ) { if ( 'spend' === $d[1] && ! $has_spend ) { continue; } $h .= '<th data-g="' . esc_attr( $d[1] ) . '" data-sort="' . esc_attr( $c ) . '" title="' . ( 'conv' === $c ? esc_attr( self::GLOSSARY['small'] ) : '' ) . '">' . esc_html( $d[0] ) . ( 'conv' === $c ? ' <i class="ap-sorti">↕</i>' : '' ) . '</th>'; }
		$h .= '</tr></thead><tbody>';
		foreach ( $calc as $k => $v ) {
			$label = esc_html( $fmt ? $fmt( $k ) : $k ); $href = isset( $opt['link'] ) && is_callable( $opt['link'] ) ? $opt['link']( $k ) : '';
			$h .= '<tr><td data-v="' . esc_attr( (string) $k ) . '"><b>' . ( $href ? '<a href="' . esc_url( $href ) . '" title="филтрирай целия екран по този ред">' . $label . '</a>' : $label ) . '</b></td>';
			foreach ( self::COLS as $c => $d ) {
				if ( 'spend' === $d[1] && ! $has_spend ) { continue; }
				$raw = 'conv' === $c ? ( $v['conv_raw'] ?? -1 ) : ( $v[ $c ] ?? -1 ); $style = ''; $cls = '';
				if ( 'conv' === $c ) { if ( $v['small'] ) { $cls = ' class="ap-small-c"'; } elseif ( $maxc > 0 && null !== $v['conv_raw'] && $v['conv_raw'] > 0 ) { $style = ' style="background:rgba(59,178,115,' . number_format( 0.08 + 0.42 * $v['conv_raw'] / $maxc, 2, '.', '' ) . ')"'; } }
				$h .= '<td data-g="' . esc_attr( $d[1] ) . '" data-v="' . esc_attr( (string) ( null === $raw ? -1 : $raw ) ) . '"' . $cls . $style . '>' . ( 'v' === $c ? '<b>' . (int) $v['v'] . '</b>' : self::fmt_col( $v, $c ) ) . ( 'conv' === $c && $v['small'] ? ' <small title="' . esc_attr( self::GLOSSARY['small'] ) . '">малка извадка</small>' : '' ) . '</td>';
			}
			$h .= '</tr>';
		}
		if ( ! $rows ) { $h .= '<tr><td colspan="12"><small>още няма данни за периода</small></td></tr>'; }
		return $h . '</tbody></table></div></div>';
	}
	/** 1.27.0: разбивките за рекламата — „🧺 Докъде са напълнили кутията“, „🏷️ × 📦 UTM × кутия“, „📣 По кампания“, „↩ По referrer“, „🕐 По час“. В Преглед и в История. */
	public static function extra_tables_html( $r, $names, $boxes ) {
		$h = '';
		/* докъде са напълнили */
		$fill = (array) ( $r['fill'] ?? array() ); $maxp = 0; foreach ( $fill as $f ) { $maxp = max( $maxp, (int) $f['packs'], $f['dist'] ? max( array_keys( $f['dist'] ) ) : 0 ); }
		$h .= '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🧺 Докъде са напълнили кутията</span></div><div class="ap-sec__hint">Сесиите, избрали кутия. „Напълнили“ = стигнали до „пълна“ (и колко от тях поръчали). „Спрели по пътя“ = избрали кутия, но не я напълнили — колко опаковки са имали в нея, когато са я изоставили (0 = нищо не са сложили). Средно запълване = опаковки ÷ размера на кутията.</div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>кутия</th><th>избрали</th><th>напълнили</th><th>поръчали</th><th>спрели по пътя</th>';
		for ( $i = 0; $i <= $maxp; $i++ ) { $h .= '<th title="спрели с ' . $i . ' опаковки в кутията">' . $i . ' оп.</th>'; }
		$h .= '<th>средно запълване</th><th title="напълнили над минимума на кутията (голямата расте): опаковки → сесии">над минимума</th></tr></thead><tbody>';
		foreach ( $fill as $bx => $f ) {
			$h .= '<tr><td><b>' . esc_html( $boxes[ $bx ] ?? $bx ) . '</b> <small>' . (int) $f['packs'] . ' оп.</small></td><td>' . (int) $f['sessions'] . '</td><td><b>' . (int) $f['full'] . '</b> <small>' . esc_html( self::pct( $f['full'], $f['sessions'] ) ) . '</small></td><td>' . (int) $f['ordered'] . ' <small>' . esc_html( self::pct( $f['ordered'], $f['sessions'] ) ) . '</small></td><td>' . (int) $f['stopped'] . ' <small>' . esc_html( self::pct( $f['stopped'], $f['sessions'] ) ) . '</small></td>';
			for ( $i = 0; $i <= $maxp; $i++ ) { $c = (int) ( $f['dist'][ $i ] ?? 0 ); $h .= '<td>' . ( $c ? '<b>' . $c . '</b> <small>' . esc_html( self::pct( $c, $f['stopped'] ) ) . '</small>' : '<small>—</small>' ) . '</td>'; }
			$ov = array(); foreach ( (array) ( $f['over'] ?? array() ) as $nn => $c ) { $ov[] = $nn . ' оп. × ' . $c; }
			$h .= '<td>' . ( $f['stopped'] ? esc_html( number_format( $f['avg'], 1, ',', '' ) . ' оп. · ' . number_format( $f['avg_pct'], 0 ) . '%' ) : '—' ) . '</td><td><small>' . ( $ov ? esc_html( implode( ', ', $ov ) ) : '—' ) . '</small></td></tr>';
		}
		if ( ! $fill ) { $h .= '<tr><td colspan="' . ( 8 + $maxp ) . '"><small>още няма сесии с избрана кутия за периода</small></td></tr>'; }
		$h .= '</tbody></table></div></div>';
		/* UTM × кутия */
		$bids = array_keys( $boxes ); foreach ( (array) ( $r['by_utm_box'] ?? array() ) as $v ) { foreach ( array_keys( (array) $v['boxes'] ) as $b ) { if ( ! in_array( $b, $bids, true ) ) { $bids[] = $b; } } }
		$h .= '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🏷️ × 📦 Коя кутия избират по UTM</span></div><div class="ap-sec__hint">За всеки UTM (продуктът в рекламата): колко сесии и коя кутия са избрали (последната избрана в сесията). „без кутия“ = не са стигнали до избор.</div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>UTM</th><th>сесии</th>';
		foreach ( $bids as $b ) { $h .= '<th>' . esc_html( $boxes[ $b ] ?? $b ) . '</th>'; } $h .= '<th>без кутия</th></tr></thead><tbody>';
		foreach ( (array) ( $r['by_utm_box'] ?? array() ) as $k => $v ) {
			$h .= '<tr><td><b>' . esc_html( ( $names[ $k ] ?? $k ) . ( isset( $names[ $k ] ) ? ' (' . $k . ')' : '' ) ) . '</b></td><td>' . (int) $v['n'] . '</td>';
			foreach ( $bids as $b ) { $c = (int) ( $v['boxes'][ $b ] ?? 0 ); $h .= '<td>' . ( $c ? '<b>' . $c . '</b> <small>' . esc_html( self::pct( $c, $v['n'] ) ) . '</small>' : '<small>—</small>' ) . '</td>'; }
			$h .= '<td>' . (int) $v['none'] . ' <small>' . esc_html( self::pct( $v['none'], $v['n'] ) ) . '</small></td></tr>';
		}
		if ( empty( $r['by_utm_box'] ) ) { $h .= '<tr><td colspan="' . ( 3 + count( $bids ) ) . '"><small>още няма данни за периода</small></td></tr>'; }
		$h .= '</tbody></table></div></div>';
		/* кампании · referrer · час */
		$h .= self::table_html( '📣 По кампания (utm_source / utm_medium · utm_campaign)', (array) ( $r['by_camp'] ?? array() ), 'кампания' );
		$h .= self::table_html( '↩ По referrer (откъде идва кликът)', (array) ( $r['by_ref'] ?? array() ), 'сайт' );
		$h .= self::table_html( '🕐 По час на влизане (часът на сайта)', (array) ( $r['by_hour'] ?? array() ), 'час' );
		return $h;
	}
	private static function feed_table_html( $id, $rows_html ) {
		return '<table class="widefat striped" id="' . esc_attr( $id ) . '"><thead><tr><th>кога</th><th>кой</th><th>UTM</th><th>кампания</th><th>устр.</th><th>кутия · продукти</th><th>пътят</th><th>поръчка</th></tr></thead><tbody>' . $rows_html . '</tbody></table>';
	}
	/** 1.27.0: базовият URL за fetch (завършва с ? или &) и токенът — в режим портал сочат към портала (Insights_Portal), иначе admin-ajax + nonce. */
	private static function ajax_vars() {
		if ( self::$portal ) { $u = Insights_Portal::url(); $u .= ( false === strpos( $u, '?' ) ? '?' : '&' ); return 'var ajax=' . wp_json_encode( $u ) . ',nonce=' . wp_json_encode( Insights_Portal::csrf() ) . ';'; }
		return 'var ajax=' . wp_json_encode( admin_url( 'admin-ajax.php' ) . '?' ) . ',nonce=' . wp_json_encode( wp_create_nonce( self::NONCE ) ) . ';';
	}
	/* ── 1.27.0: режим „портал“ — екранът се рендира на отделния адрес за екипа по реклами (Insights_Portal); линковете, формите и ajax-ът сочат там ── */
	public static $portal = false;
	/** Линк към екрана: в админа admin.php?page=…&tab=insights&…, в портала {портал}/?… (tab отпада). */
	public static function url( $args = array() ) {
		/* 1.28.0: периодът и филтрите вървят с линковете (ако не са подменени); null в $args = махни */
		$keep = array(); $g = $_GET;
		if ( ! isset( $args['from'] ) && ! isset( $args['to'] ) && ! isset( $args['days'] ) && null !== self::$period && self::$period_explicit ) { $keep['from'] = self::$period['from']; $keep['to'] = self::$period['to']; }
		foreach ( array_merge( array( 'view' ), self::FILTER_KEYS ) as $k ) { if ( isset( $g[ $k ] ) && '' !== $g[ $k ] && ! array_key_exists( $k, $args ) ) { $keep[ $k ] = sanitize_text_field( (string) wp_unslash( $g[ $k ] ) ); } }
		if ( isset( $keep['view'] ) && 'history' === $keep['view'] ) { $keep['view'] = 'sessions'; }
		$args = array_merge( $keep, $args ); foreach ( $args as $k => $v ) { if ( null === $v || '' === $v ) { unset( $args[ $k ] ); } }
		if ( isset( $args['days'] ) ) { unset( $args['from'], $args['to'] ); }
		if ( ! self::$portal && ! isset( $args['tab'] ) ) { $args = array( 'tab' => 'insights' ) + $args; }
		return self::$portal ? Insights_Portal::url( $args ) : Admin::url( $args );
	}
	/** Отваря формата за CSV: в админа admin-post (Admin::form_open_public), в портала GET към портала с ?csv=sessions|stats. */
	public static function export_form_open( $which ) {
		if ( self::$portal ) { echo '<form method="get" action="' . esc_url( Insights_Portal::url() ) . '" style="display:inline"><input type="hidden" name="csv" value="' . esc_attr( $which ) . '">'; return; }
		if ( in_array( $which, array( 'sessions', 'stats' ), true ) ) { Admin::form_open_public( 'export_' . $which, 'insights' ); return; }
		Admin::form_open_public( 'export_breakdown', 'insights' ); echo '<input type="hidden" name="which" value="' . esc_attr( $which ) . '">';
	}
	/** Данните за живото опресняване (ajax_feed в админа, ?action=ansa_promo_insights_feed в портала). */
	public static function feed_payload( $days, $limit ) {
		$feed = self::feed( (int) $limit ); $flat = self::dashboard_flat( self::live_dashboard() ); /* 1.23.0: всички плочки; 1.28.0: днес с Δ спрямо вчера */
		return array_merge( array( 'feed' => $feed, 'rows_html' => self::feed_rows( $feed ), 'at' => date_i18n( 'H:i:s' ) ), $flat );
	}

	/* ── 📊 Преглед (1.28.0: един екран за периода — плочки с Δ и sparkline, фуния с отпаданията, тренд, разбивки с филтър, кохорти, разход, речник) ── */
	private static function render_overview( $days ) {
		$rg = self::period(); $d = self::overview_data( $rg );
		ob_start();
		foreach ( array( 'sessions' => '⬇ CSV сесии', 'utm' => '⬇ CSV по UTM', 'camp' => '⬇ CSV по кампания', 'day' => '⬇ CSV по дни' ) as $w => $l ) { self::export_form_open( $w ); foreach ( array( 'from' => $rg['from'], 'to' => $rg['to'], 'attr' => $d['f']['attr'], 'internal' => $d['f']['internal'] ? '1' : '' ) as $hk => $hv ) { echo '<input type="hidden" name="' . esc_attr( $hk ) . '" value="' . esc_attr( $hv ) . '">'; } echo '<button class="button">' . esc_html( $l ) . '</button></form> '; }
		self::action_form_open( 'snap_add' ); echo '<input type="hidden" name="from" value="' . esc_attr( $rg['from'] ) . '"><input type="hidden" name="to" value="' . esc_attr( $rg['to'] ) . '"><button class="button" title="записва точно тези числа; по-късните промени на поръчките не ги пипат">📌 Замрази отчета</button></form>';
		$actions = ob_get_clean();
		echo self::period_bar( $actions );
		echo self::filter_bar( $d['f'], $d['names'], $d['boxes'] );
		echo self::overview_html( $d );
		if ( ! self::$portal ) { echo self::diagnostics_html() . self::diagnostics_js() . self::legacy_html( $rg ); }
	}
	/** Съдържанието на Прегледа от данните (overview_data) — и за замразените отчети (📌). */
	public static function overview_html( $d, $frozen = false ) {
		$r = $d['r']; $os = $d['os']; $rg = $d['rg']; $f = $d['f']; $names = $d['names']; $boxes = $d['boxes']; $fn = $r['funnel']; $spend = $d['spend'];
		/* sparkline по дни (т. 7) */
		$days = array(); $t0 = strtotime( $rg['from'] ); $t1 = strtotime( $rg['to'] ); for ( $t = $t0; $t <= $t1; $t += DAY_IN_SECONDS ) { $days[] = gmdate( 'Y-m-d', $t ); }
		$sp = array( 'sessions' => array(), 'created' => array(), 'o_n' => array(), 'rev_real' => array(), 'conv' => array() );
		foreach ( $days as $day ) { $bd = $r['by_day'][ $day ] ?? null; $od = $os['by_day'][ $day ] ?? null; $sp['sessions'][] = $bd ? $bd['n'] : 0; $sp['created'][] = $bd ? $bd['created'] : 0; $sp['o_n'][] = $od ? $od['n'] : 0; $sp['rev_real'][] = $od ? $od['rev_del'] : 0; $sp['conv'][] = $bd && $bd['n'] ? 100 * $bd['created'] / $bd['n'] : 0; }
		$h = '';
		if ( $frozen ) { $h .= '<div class="ap-msg warn">📌 Замразен отчет от ' . esc_html( $d['at'] ) . ' за ' . esc_html( self::period_label( $rg ) ) . ( self::filters_active( $f ) ? ' · с филтър' : '' ) . ' — числата са точно каквито са били тогава.</div>'; }
		$h .= self::dashboard_html( self::dashboard_data( $r, $os, array( 'period' => self::period_label( $rg ), 'prev' => array( 'r' => $d['pr'], 'os' => $d['pos'] ), 'spark' => count( $days ) > 1 ? $sp : array() ) ) );
		$h .= '<div class="ap-sec__hint ap-prev">Δ = спрямо предишния период ' . esc_html( self::period_label( $d['prg'] ) ) . self::q( 'delta' ) . ( ( $r['internal_n'] ?? 0 ) || ( $d['pr']['internal_n'] ?? 0 ) ? ' · вътрешен трафик: ' . ( $f['internal'] ? 'включен' : 'скрит' ) : '' ) . '</div>';
		/* фуния + загубени (т. 4) */
		$lost = array(
			array( $fn['gate'] - $fn['box'], 'Минали попъпа, но не избрали кутия', array( 'step' => 'gate', 'mode' => 'exact' ), $fn['gate'] ),
			array( $fn['box'] - $fn['full'], 'Избрали кутия, но не я напълнили', array( 'step' => 'box', 'mode' => 'exact' ), $fn['box'] ),
			array( $fn['full'] - $fn['created'], 'Напълнили кутията, но НЕ поръчали', array( 'step' => 'full', 'mode' => 'min', 'ord' => 'no' ), $fn['full'] ),
			array( $fn['add'] - $fn['created'], 'Стигнали до плащане, но НЕ поръчали', array( 'step' => 'add', 'mode' => 'min', 'ord' => 'no' ), $fn['add'] ),
		);
		$tiles = ''; foreach ( $lost as $l ) { $tiles .= self::tile( $l[1], (string) (int) max( 0, $l[0] ), esc_html( self::pct( max( 0, $l[0] ), $l[3] ) ) . ' от стигналите дотам' . ( $frozen ? '' : ' · <a href="' . esc_url( self::url( array_merge( array( 'view' => 'sessions', 'pg' => null ), $l[2] ) ) ) . '#apInsList">кои →</a>' ), 'warn' ); }
		$h .= '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🔻 Фунията по сесии</span></div><div class="ap-sec__hint">Всяка сесия се брои веднъж на стъпка — докъде е стигнала. Червеното между стъпките е колко спират точно там. Кои са те — в <a href="' . esc_url( self::url( array( 'view' => 'sessions' ) ) ) . '">🧾 Сесии</a>.</div>' . self::funnel_html( $fn, $r['vfunnel'] ) . '</div>';
		$h .= '<div class="ap-dash">' . self::group_html( '🛑 Загубени по пътя', $tiles, 'сесии, спрели преди следващата стъпка; „кои →“ отваря списъка' ) . '</div>';
		/* тренд (т. 24) */
		if ( count( $days ) > 1 ) { $h .= self::trend_html( $days, $r, $os ); }
		/* 1.38.0: дни × канал + по канал + по канал · детайл */
		$h .= self::day_channel_html( $days, $r, $frozen );
		$ch_link = $frozen ? null : function ( $v ) use ( $r ) { $f = $r['chdet_link'][ $v ] ?? null; if ( ! $f ) { return ''; } $f['pg'] = null; return self::url( $f ); };
		$h .= self::table_html( '📣 Откъде идват (канал)', (array) ( $r['by_channel'] ?? array() ), 'канал', null, array( 'id' => 'apTCh', 'hint' => 'Meta реклама = utm_source facebook/fb/ig/an или utm_medium paid; Имейл = utm_medium email / newsletter; Попъп на сайта = utm_medium popup (името на попъпа е utm_campaign); Сайтът (линк с UTM) = utm_source home/site/shop…; без UTM — по referrer-а.' ) );
		$h .= self::table_html( '📣 Откъде идват · детайл (кампания / имейл / попъп / страница)', (array) ( $r['by_chdet'] ?? array() ), 'канал · детайл', null, array( 'id' => 'apTChDet', 'link' => $ch_link, 'hint' => 'Редовете с UTM са линкове — филтрират целия екран по тази кампания.' ) );
		/* разбивки (т. 5, 6, 20, 22, 23) */
		$lk = $frozen ? null : function ( $key, $k ) { return function ( $v ) use ( $key, $k ) { return '—' === $v ? '' : self::url( array( $key => (string) $v, 'pg' => null ) ); }; };
		$camp_link = $frozen ? null : function ( $v ) use ( $r ) { if ( in_array( $v, array( 'без UTM', 'само UTM продукт' ), true ) ) { return ''; } $p = self::camp_parts( $v ); return self::url( array( 'f_src' => $p[0], 'f_med' => $p[1], 'f_camp' => $p[2], 'pg' => null ) ); };
		$utm_rows = $r['by_utm']; $utm_fmt = function ( $k ) use ( $names ) { return ( $names[ $k ] ?? $k ) . ( isset( $names[ $k ] ) ? ' (' . $k . ')' : '' ); };
		$h .= self::table_html( '🏷️ По UTM (продуктът в рекламата · ' . ( 'last' === $f['attr'] ? 'последен' : 'първи' ) . ' UTM)', $utm_rows, 'UTM', $utm_fmt, array( 'id' => 'apTUtm', 'spend' => $spend['utm'], 'link' => $lk ? $lk( 'f_utm', 'utm' ) : null, 'glossary' => 'camp', 'hint' => 'UTM = utm_content (кой продукт е в рекламата). За кампаниите (utm_source / medium / campaign) — таблицата по-долу. Цъкни реда — целият екран се филтрира по него.' ) );
		$h .= self::table_html( '📣 По кампания (utm_source / utm_medium · utm_campaign)', $r['by_camp'], 'кампания', null, array( 'id' => 'apTCamp', 'spend' => $spend['camp'], 'link' => $camp_link, 'glossary' => 'camp' ) );
		if ( $spend['utm'] || $spend['camp'] ) { $h .= '<div class="ap-sec__hint">💸 Разход, CPA, ROAS — от въведеното в <a href="' . esc_url( self::url( array( 'view' => 'spend' ) ) ) . '">💸 Разход</a>' . self::q( 'spend' ) . '; включи групата „разход“ над таблицата.</div>'; }
		$h .= self::table_html( '📦 По кутия', $r['by_box'], 'кутия', function ( $k ) use ( $boxes ) { return $boxes[ $k ] ?? $k; }, array( 'id' => 'apTBox', 'link' => $lk ? $lk( 'f_box', 'box' ) : null ) );
		$h .= self::table_html( '📱 По устройство', $r['by_dev'], 'устройство', null, array( 'id' => 'apTDev', 'link' => $lk ? $lk( 'f_dev', 'dev' ) : null ) );
		if ( count( $r['by_variant'] ) > 1 || isset( $r['by_variant']['потокът без кутии (нов дизайн)'] ) ) { $h .= self::table_html( '🧪 A/B: по вариант на страницата', $r['by_variant'], 'вариант', null, array( 'id' => 'apTVar', 'hint' => 'A = сегашната страница с трите кутии; B = страницата с <code>[ansa_promo variant="flow"]</code> — без избор на кутия, отстъпката и подаръците се отключват с броя опаковки (1.34.0). Сравнявай „поръчали“ (created) и средната количка, не само кликовете; B позволява 2 и 4 опаковки, затова гледай и „По кутия“.' ) ); }
		$h .= self::table_html( '📣 По източник (utm_source / директно)', $r['by_src'], 'източник', null, array( 'id' => 'apTSrc', 'link' => $lk ? $lk( 'f_src', 'src' ) : null ) );
		$h .= self::table_html( '📅 По дни', array_reverse( $r['by_day'], true ), 'ден', null, array( 'id' => 'apTDay' ) );
		if ( $r['by_week'] ) { $h .= self::table_html( '🗓️ По седмица на сезона (отстъпката)', $r['by_week'], 'седмица', null, array( 'id' => 'apTWeek', 'hint' => 'Седмиците на сезона с процента отстъпка за малка / средна / голяма кутия — как се държи интересът, докато отстъпката пада.' ) ); }
		$h .= self::table_html( '🕐 По час на влизане (часът на сайта)', $r['by_hour'], 'час', null, array( 'id' => 'apTHour' ) );
		$h .= self::table_html( '↩ По referrer (откъде идва кликът)', $r['by_ref'], 'сайт', null, array( 'id' => 'apTRef' ) );
		/* продукти, кутии, Shrine, матрица, кохорти */
		$h .= self::products_html( $r, $names ) . self::extra_tables_html( $r, $names, $boxes ) . self::shrine_table_html( $r['shrine'] ?? array(), $names ) . self::dev_matrix_html( $r ) . self::cohorts_html( $d['cohorts'] ?? array() );
		$h .= self::status_table_html( $os, self::period_label( $rg ) );
		$h .= self::glossary_html();
		return $h;
	}
	/** 🧴 Продуктите — цъкнати и в кутиите. */
	private static function products_html( $r, $names ) {
		$h = '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🧴 Продуктите — цъкнати и в кутиите</span></div><div class="ap-sec__b"><table class="widefat striped ap-sort"><thead><tr><th>продукт</th><th data-sort="1">цъкнали (сесии)</th><th data-sort="1">цъквания</th><th data-sort="1">в пълни кутии (сесии)</th><th data-sort="1">опаковки</th><th data-sort="1">поръчани опаковки</th></tr></thead><tbody>';
		foreach ( $r['items'] as $kk => $v ) { $h .= '<tr><td><b>' . esc_html( $names[ $kk ] ?? $kk ) . '</b> <small>' . esc_html( $kk ) . '</small></td><td data-v="' . (int) ( $v['clicked'] ?? 0 ) . '">' . (int) ( $v['clicked'] ?? 0 ) . '</td><td data-v="' . (int) ( $v['clicks'] ?? 0 ) . '">' . (int) ( $v['clicks'] ?? 0 ) . '</td><td data-v="' . (int) $v['sessions'] . '">' . (int) $v['sessions'] . '</td><td data-v="' . (int) $v['packs'] . '">' . (int) $v['packs'] . '</td><td data-v="' . (int) $v['ordered'] . '">' . (int) $v['ordered'] . '</td></tr>'; }
		if ( ! $r['items'] ) { $h .= '<tr><td colspan="6"><small>още няма данни за периода</small></td></tr>'; }
		return $h . '</tbody></table></div></div>';
	}
	/** 1.28.0 (т. 10): старият rollup — само в админа. */
	private static function legacy_html( $rg ) {
		$legacy = self::report( self::range_days( $rg ) );
		$h = '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🗂️ Старият дневен rollup (до 1.16.0 — само броячи, без сесии; само в админа)</span></div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>стъпка</th><th>общо</th><th>% от видели</th></tr></thead><tbody>';
		foreach ( self::STEPS as $st ) { $h .= '<tr><td>' . esc_html( self::LABELS[ $st ] ) . ' <small>' . esc_html( $st ) . '</small></td><td><b>' . (int) $legacy['by_step'][ $st ] . '</b></td><td>' . esc_html( self::pct( $legacy['by_step'][ $st ], $legacy['by_step']['view'] ) ) . '</td></tr>'; }
		return $h . '</tbody></table></div></div>';
	}
	/** 1.17.1: диагностика — защо е 0 (само в админа). */
	private static function diagnostics_html() {
		$dg = self::diagnostics(); $bad = ! $dg['tables'] || ! $dg['live'];
		return '<div class="ap-sec' . ( $bad ? ' ap-sec--danger' : '' ) . '"><div class="ap-sec__h"><span class="ap-sec__t">🩺 Работи ли отчитането?</span><button type="button" class="ap-btn ap-btn--secondary ap-btn--sm" id="apInsTest">▶ Тест на записа</button></div><div class="ap-sec__b"><div class="ap-env" style="padding:0">'
			. '<div class="ap-env__r ' . ( $dg['tables'] ? 'ok' : 'bad' ) . '"><span class="ap-env__dot ' . ( $dg['tables'] ? 'ok' : 'bad' ) . '"></span><span class="ap-env__l">Таблици</span><span class="ap-env__v">' . ( $dg['tables'] ? 'sessions + events са налице' : 'ЛИПСВАТ — деактивирай/активирай плъгина (Schema::install)' ) . ' · DB ver ' . esc_html( $dg['db_ver'] ) . ( $dg['db_error'] ? ' · грешка: ' . esc_html( $dg['db_error'] ) : '' ) . '</span></div>'
			. '<div class="ap-env__r ' . ( $dg['events'] ? 'ok' : 'warn' ) . '"><span class="ap-env__dot ' . ( $dg['events'] ? 'ok' : 'warn' ) . '"></span><span class="ap-env__l">Записани досега</span><span class="ap-env__v">' . (int) $dg['sessions'] . ' сесии · ' . (int) $dg['events'] . ' събития · последно: ' . esc_html( $dg['last_event'] ?: 'още нищо' ) . ' · стар rollup днес (view): ' . (int) $dg['legacy_today'] . '</span></div>'
			. '<div class="ap-env__r ' . ( $dg['orders_wc'] === $dg['orders_sess'] ? 'ok' : 'warn' ) . '"><span class="ap-env__dot ' . ( $dg['orders_wc'] === $dg['orders_sess'] ? 'ok' : 'warn' ) . '"></span><span class="ap-env__l">Поръчките</span><span class="ap-env__v">' . (int) $dg['orders_wc'] . ' промо поръчки в WooCommerce (без чернови) · ' . (int) $dg['orders_sess'] . ' със сесия в Insights' . ( $dg['orders_wc'] === $dg['orders_sess'] ? ' — съвпадат' : ' — <b>не съвпадат</b>: поръчки преди 16.10 следобед или от стар JS (кеш) нямат сесия; „🔄 Сверка“ им прави сесия order-№ от датата на поръчката (става и само при отваряне на Insights, на 10 мин)' ) . ' <button type="button" class="ap-btn ap-btn--secondary ap-btn--sm" id="apInsBackfill" style="margin-left:8px">🔄 Сверка с поръчките</button></span></div>'
			. '<div class="ap-env__r ' . ( $dg['live'] ? 'ok' : 'bad' ) . '"><span class="ap-env__dot ' . ( $dg['live'] ? 'ok' : 'bad' ) . '"></span><span class="ap-env__l">Играта</span><span class="ap-env__v">' . ( $dg['live'] ? 'ВКЛЮЧЕНА — публичните посещения се броят' : ( $dg['enabled'] ? 'изтекла — не се брои нищо' : 'ИЗКЛЮЧЕНА — публичната страница е „скоро“, не се брои нищо' ) ) . ( $dg['preview_pw'] ? ' · прегледът с парола е включен: екипът с паролата вижда черновата и НЕ се брои' : '' ) . '</span></div>'
			. '<div class="ap-env__r info"><span class="ap-env__dot info"></span><span class="ap-env__l">Кеш на страницата</span><span class="ap-env__v">' . esc_html( $dg['cache'] ) . ' — от 1.17.1 „view“ се брои и от кеширана страница (runtime ajax + бисквитка), но продуктите/кутията идват от новото promo.js: след ъпдейт изчисти кеша (🧰 Настройки → Публикуване → 🧹).</span></div>'
			. '</div><div class="ap-note" id="apInsTestOut" style="padding:8px 4px 0"></div></div></div>';
		
	}
	private static function diagnostics_js() {
		return '<script>(function(){' . self::ajax_vars() . 'var tb2=document.getElementById("apInsTest"),out=document.getElementById("apInsTestOut");if(tb2)tb2.onclick=function(){out.textContent="… тествам";fetch(ajax+"action=ansa_promo_insights_selftest&nonce="+nonce,{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(r){var d=r&&r.data||{};out.innerHTML=(d.ok?"<span class=\"good\">✓ ":"<span class=\"bad\">✕ ")+(d.msg||"грешка")+"</span>"}).catch(function(){out.textContent="мрежова грешка"})};'
			. 'var bf=document.getElementById("apInsBackfill");if(bf)bf.onclick=function(){out.textContent="… сверявам с поръчките";bf.disabled=true;fetch(ajax+"action=ansa_promo_insights_backfill&nonce="+nonce,{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(r){var d=r&&r.data||{};out.innerHTML="<span class=\"good\">✓ "+(d.msg||"готово")+"</span>";bf.disabled=false}).catch(function(){out.textContent="мрежова грешка";bf.disabled=false})};})();</script>';

	}
	/* ── 🟢 На живо (1.21.0: отделен таб; 1.28.0: кампания в отделна колона, нови редове светят, пауза при задържане, Δ спрямо вчера) ── */
	private static function render_live() {
		$n = (int) ( $_GET['n'] ?? 40 ); if ( ! in_array( $n, array( 40, 100, 200 ), true ) ) { $n = 40; }
		$chips = ''; foreach ( array( 40, 100, 200 ) as $c ) { $chips .= '<a class="ap-chip' . ( $c === $n ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'view' => 'live', 'n' => $c ) ) ) . '">последните ' . $c . '</a>'; }
		echo '<div class="ap-actions" style="padding:0 0 12px"><div class="ap-chips">' . $chips . '</div><span class="sp"></span><label class="ap-tglwrap on"><input type="checkbox" id="apInsLive" checked><span class="ap-tgl"></span>опреснява на 20 с</label><small id="apInsAt" style="margin-left:8px"></small></div>';
		echo self::dashboard_html( self::live_dashboard() );
		echo '<div class="ap-sec__hint ap-prev">Δ = спрямо вчера по същото време на деня' . self::q( 'delta' ) . '</div>';
		echo '<div class="ap-sec ap-sec--table" id="apInsFeed"><div class="ap-sec__h"><span class="ap-sec__t">🟢 На живо — последните сесии</span><small id="apInsPaused" hidden>⏸ на пауза, докато мишката е върху списъка</small></div>'
			. '<div class="ap-sec__hint">Кой е влязъл (имейлът от попъпа или от поръчката), откъде, какво е цъкнал и докъде е стигнал. Статусът на поръчката е на живо от WooCommerce. 👤xxxx е един и същ посетител: „5 сесии“ значи, че същият човек е влизал 5 пъти. Новите редове светят. Подредено по последна активност.</div><div class="ap-sec__b">' . self::feed_table_html( 'apInsFeedT', self::feed_rows( self::feed( $n ) ) ) . '</div></div>';
		echo '<script>(function(){var live=document.getElementById("apInsLive"),tb=document.querySelector("#apInsFeedT tbody"),at=document.getElementById("apInsAt"),ps=document.getElementById("apInsPaused"),feed=document.getElementById("apInsFeed"),t,hover=false;' . self::ajax_vars() . 'var limit=' . (int) $n . ';'
			. 'feed.addEventListener("mouseenter",function(){hover=true;ps.hidden=false});feed.addEventListener("mouseleave",function(){hover=false;ps.hidden=true});'
			. 'function ids(){var o={};tb.querySelectorAll("tr[data-id]").forEach(function(tr){o[tr.getAttribute("data-id")]=1});return o}'
			. 'function tick(){if(!live.checked||hover)return;fetch(ajax+"action=ansa_promo_insights_feed&nonce="+nonce+"&days=1&limit="+limit,{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(r){if(!r||!r.success)return;var had=ids();tb.innerHTML=r.data.rows_html||tb.innerHTML;tb.querySelectorAll("tr[data-id]").forEach(function(tr){if(!had[tr.getAttribute("data-id")])tr.classList.add("ap-new")});'
			. 'Object.keys(r.data.kpi||{}).forEach(function(k){var el=document.querySelector("#apInsDash .ap-st__v[data-k=\\""+k+"\\"]");if(el)el.textContent=r.data.kpi[k];var sb=document.querySelector("#apInsDash .ap-st__s[data-ks=\\""+k+"\\"]");if(sb&&r.data.kpi_sub&&r.data.kpi_sub[k]!=null)sb.innerHTML=r.data.kpi_sub[k];var dl=document.querySelector("#apInsDash .ap-st__d[data-kd=\\""+k+"\\"]");if(dl&&r.data.kpi_delta&&r.data.kpi_delta[k]!=null)dl.innerHTML=r.data.kpi_delta[k]});at.textContent="опреснено "+r.data.at}).catch(function(){})}'
			. 'function arm(){clearInterval(t);if(live.checked)t=setInterval(tick,20000)}live.addEventListener("change",function(){live.parentNode.classList.toggle("on",live.checked);arm()});arm();})();</script>';
	}
	/** Плочките за „днес“ с Δ спрямо вчера (и за живото опресняване). */
	public static function live_dashboard() {
		$today = current_time( 'Y-m-d' ); $rg = self::range( $today, $today ); $y = gmdate( 'Y-m-d', current_time( 'timestamp' ) - DAY_IN_SECONDS ); $yrg = self::range( $y, $y ); $f = self::filters(); $o = self::report_opts( $f );
		$os = self::orders_stats( $rg, null, null, $o ); $r = self::sessions_report( 0, $rg, self::report_opts( $f, $os['by_id'] ) ); $yos = self::orders_stats( $yrg, null, null, $o ); $yr = self::sessions_report( 0, $yrg, self::report_opts( $f, $yos['by_id'] ) );
		return self::dashboard_data( $r, $os, array( 'period' => 'днес', 'prev' => array( 'r' => $yr, 'os' => $yos ) ) );
	}

	/* ── 🛒 Изоставени колички (1.26.0; човекът: „А дали Ansa Promo следи abandoned carts? … направи такъв екран в Insights“) ──
	   ansa-promo не записва колички — FunnelKit Automations ги пази в {prefix}bwfan_abandonedcarts (с нашия cart item data ansa_promo
	   вътре). Екранът ги чете оттам (Cart_Sync::abandoned_rows), само промо количките: кой, кога, кутия, продукти, сума, статусът на FKA,
	   поръчката при възстановяване и дали контактът носи тага sezon-kolichka (условието на flow-а). Нищо не се дублира и не се пише. */
	public static function carts_html( $days = 30 ) {
		$rg = self::period(); $days = max( 1, (int) round( ( current_time( 'timestamp' ) - strtotime( $rg['from'] ) ) / DAY_IN_SECONDS ) + 1 ); /* 1.28.0: глобалният период (от „от“ до днес — FKA таблицата е по последна промяна) */
		$h = self::period_bar();
		$rows = Cart_Sync::abandoned_rows( $days, 300 ); if ( is_array( $rows ) ) { $rows = array_values( array_filter( $rows, function ( $x ) use ( $rg ) { return substr( (string) $x['modified'], 0, 10 ) <= $rg['to']; } ) ); }
		$steps = self::steps_by_email( array_map( function ( $x ) { return (string) $x['email']; }, (array) $rows ) );
		if ( null === $rows ) { return $h . '<div class="ap-sec"><div class="ap-sec__b"><p>Няма таблица на FunnelKit Automations (<code>bwfan_abandonedcarts</code>) — изоставените колички се записват от FKA, не от промото.</p></div></div>'; }
		$s = Cart_Sync::abandoned_stats( $rows ); $tag = Cart_Sync::tag_name(); $boxes = self::box_names();
		$tiles = self::tile( 'промо колички', $s['all'], 'с имейл в чекаута', '', '', true )
			. self::tile( 'изоставени', $s['abandoned'] + $s['skipped'], 'FKA: recoverable ' . $s['abandoned'] . ' · пропуснати ' . $s['skipped'], 'warn' )
			. self::tile( 'стойност на изоставените', self::money( $s['sum_abandoned'] ), 'без възстановените', 'warn' )
			. self::tile( 'възстановени', $s['recovered'], self::money( $s['sum_recovered'] ) . ' · ' . self::pct( $s['recovered'], max( 1, $s['all'] - $s['open'] ) ) . ' от приключилите', 'ok' )
			. self::tile( 'още в чекаута', $s['open'], 'FKA ги обявява изоставени след 15 мин', '' )
			. self::tile( 'с таг „' . ( $tag ?: '—' ) . '“', $s['tagged'], 'контактът носи тага сега (условието на flow-а)', $s['tagged'] === $s['abandoned'] + $s['skipped'] + $s['open'] ? 'ok' : '' );
		$h .= '<div class="ap-dash">' . self::group_html( '🛒 Изоставени промо колички · ' . ( $days ? 'последните ' . $days . ' дни' : 'целият период' ), $tiles, 'Данните са от FunnelKit Automations (таблицата на изоставените колички), само количките с промо кутия. Тагът е това, по което flow-ът „Cart Abandoned“ ги различава.' ) . '</div>';
		$h .= '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🛒 Количките</span></div><div class="ap-sec__hint">📞 <b>Обаждане:</b> „✏️ Редактирай поръчка“ отваря промо страницата с кутията и продуктите на човека (коригираш → „Продължи“ → „Завърши поръчката“ — чекаутът е с имейл, телефон и имена), „✅ Завърши поръчка“ води направо в чекаута. И двата първо питат: <b>отстъпка / продукт подарък / нищо</b>. Линкът важи 24 ч. Статусът е на FKA: <b>в чекаута</b> (още не са минали 15 мин) · <b>изоставена</b> (recoverable, flow-ът може да тръгне) · <b>пропусната</b> (FKA: без активна автоматизация или cool-off) · <b>изгубена</b> (след 15 дни) · <b>възстановена</b> (има поръчка). Последно променените първи.</div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>кога</th><th>кой</th><th>кутия</th><th>продукти</th><th>сума</th><th>статус</th><th>таг</th><th>сесия</th><th>поръчка</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$m = $r['meta']; $box = $m ? ( $boxes[ $m['box'] ] ?? $m['box'] ) . ( $m['packs'] ? ' · ' . $m['packs'] . ' оп.' : '' ) . ( $m['pct'] ? ' · −' . Cart::fmt( $m['pct'] ) . '%' : '' ) . ( $m['week'] ? ' · седм. ' . $m['week'] : '' ) . ( $m['draft'] ? ' · чернова' : '' ) : '—';
			$lines = array(); foreach ( $r['lines'] as $l ) { $lines[] = esc_html( $l['name'] ) . ( $l['qty'] > 1 ? ' ×' . $l['qty'] : '' ) . ( 'secret' === $l['role'] ? ' <small>сет</small>' : ( 'book' === $l['role'] ? ' <small>книга</small>' : ( '' === $l['role'] ? ' <small>друго</small>' : '' ) ) ); }
			$who = '<a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>' . ( $r['name'] || $r['phone'] ? '<br><small>' . esc_html( trim( $r['name'] . ' ' . $r['phone'] ) ) . '</small>' : '' );
			$ord = $r['order_id'] > 0 ? ( self::$portal ? '<b>#' . (int) $r['order_id'] . '</b>' : '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $r['order_id'] . '&action=edit' ) ) . '">#' . (int) $r['order_id'] . '</a>' ) : '—';
			$sess = $steps[ strtolower( (string) $r['email'] ) ] ?? ''; $sess = $sess ? '<small>' . esc_html( self::SHORT[ $sess ] ?? $sess ) . '</small>' : '<small>—</small>'; /* 1.28.0 (т. 27): докъде е стигнала сесията на същия имейл */
			if ( $r['order_id'] <= 0 ) { $ord = Phone_Orders::buttons( 'cart', (int) $r['id'], trim( $r['email'] . ' ' . $r['phone'] ) ); } /* 1.29.0: 📞 обаждане — пресъздай количката / направо чекаут */
			$h .= '<tr><td>' . esc_html( self::dt_short( $r['modified'] ) ) . '<br><small>от ' . esc_html( self::dt_short( $r['created'] ) ) . '</small></td><td>' . $who . '</td><td>' . esc_html( $box ) . '</td><td>' . implode( '<br>', $lines ) . '</td><td>' . esc_html( self::money( $r['total'] ) ) . '</td><td><span class="ap-pill' . ( $r['tone'] ? ' ap-pill--' . $r['tone'] : '' ) . '">' . esc_html( $r['status_label'] ) . '</span></td><td>' . ( $r['tagged'] ? '🏷️ да' : '<small>не</small>' ) . '</td><td>' . $sess . '</td><td>' . $ord . '</td></tr>';
		}
		if ( ! $rows ) { $h .= '<tr><td colspan="9"><small>няма промо колички за периода — появяват се, когато човек с промо кутия напише имейла си в чекаута (гост) или е логнат</small></td></tr>'; }
		return $h . '</tbody></table></div></div><style>.ap-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;background:#eef0f2;color:#50575e}.ap-pill--ok{background:#e6f6ec;color:#1a7f37}.ap-pill--warn{background:#fff3e0;color:#a86b09}.ap-pill--info{background:#e8f1fb;color:#1d5fa8}.ap-pill--muted{background:#f0f0f1;color:#8c8f94}</style>';
	}
	/** 1.28.0 (т. 27): най-далечната стъпка на сесиите с този имейл (лийд от попъпа) — [email => step]. */
	public static function steps_by_email( $emails ) {
		global $wpdb; $emails = array_values( array_unique( array_filter( array_map( 'strtolower', (array) $emails ) ) ) ); if ( ! $emails ) { return array(); } $out = array();
		foreach ( array_chunk( $emails, 200 ) as $chunk ) { $in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) ); foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT l.email, s.step FROM ' . Schema::table( 'sessions' ) . ' s INNER JOIN ' . Schema::table( 'leads' ) . " l ON l.id = s.lead_id WHERE l.email IN ($in) ORDER BY s.last_at ASC", $chunk ), ARRAY_A ) as $x ) { $e = strtolower( (string) $x['email'] ); if ( ! isset( $out[ $e ] ) || ( self::ORDER[ $x['step'] ] ?? 0 ) >= ( self::ORDER[ $out[ $e ] ] ?? 0 ) ) { $out[ $e ] = (string) $x['step']; } } }
		return $out;
	}
	private static function dt_short( $dt ) { $t = strtotime( (string) $dt ); return $t ? date_i18n( 'd.m H:i', $t ) : (string) $dt; }

	/* ── 🧾 Сесии (1.21.0 като 📜 История; 1.28.0: глобалният период и филтрите на екрана, без дублирания плочки — те са в Преглед) ── */
	private static function render_sessions() {
		$rg = self::period(); $f = self::filters(); $o = self::report_opts( $f ); $boxes = self::box_names(); $names = self::product_names();
		$r = self::sessions_report( 0, $rg, $o ); $fn = $r['funnel'];
		/* филтрите на списъка */
		$step = sanitize_key( (string) ( $_GET['step'] ?? '' ) ); if ( ! in_array( $step, self::FUNNEL, true ) ) { $step = ''; }
		$mode = 'exact' === ( $_GET['mode'] ?? '' ) ? 'exact' : 'min';
		$ord = in_array( $_GET['ord'] ?? '', array( 'yes', 'no' ), true ) ? (string) $_GET['ord'] : '';
		$per = 50; $pg = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
		$base = array( 'view' => 'sessions', 'step' => $step, 'mode' => $step ? $mode : '', 'ord' => $ord );
		$link = function ( $over = array(), $anchor = '#apInsList' ) use ( $base ) { $a = array_merge( $base, $over ); if ( empty( $a['step'] ) ) { $a['mode'] = ''; } return esc_url( self::url( $a ) ) . $anchor; };
		ob_start(); self::export_form_open( 'sessions' ); foreach ( array( 'from' => $rg['from'], 'to' => $rg['to'], 'step' => $step, 'mode' => $mode, 'box' => $f['box'], 'ord' => $ord, 'dev' => $f['dev'], 'attr' => $f['attr'], 'internal' => $f['internal'] ? '1' : '', 'f_utm' => $f['utm'], 'f_src' => $f['src'], 'f_med' => $f['med'], 'f_camp' => $f['camp'] ) as $hk => $hv ) { echo '<input type="hidden" name="' . esc_attr( $hk ) . '" value="' . esc_attr( $hv ) . '">'; } echo '<button class="button" title="сесиите за периода с текущия филтър на списъка">⬇ CSV сесии (този филтър)</button></form>'; $actions = ob_get_clean();
		echo self::period_bar( $actions ); echo self::filter_bar( $f, $names, $boxes );
		$ptxt = self::period_label( $rg );
		/* докъде са стигнали и къде са спрели */
		echo '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🛑 Докъде са стигнали и къде са спрели · ' . esc_html( $ptxt ) . '</span></div><div class="ap-sec__hint">„Спрели тук“ = най-далечната стъпка на сесията е точно тази (не е направила следващата). Цъкни числото — списъкът долу се филтрира по него. 👤 = уникални посетители.</div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>стъпка</th><th>стигнали (сесии)</th><th>👤 посетители</th><th>% от видели</th><th>продължили нататък</th><th>спрели тук (сесии)</th><th>👤 посетители</th><th>% от стигналите</th></tr></thead><tbody>';
		$n_f = count( self::FUNNEL );
		foreach ( self::FUNNEL as $i => $st ) {
			$reached = (int) $fn[ $st ]; $stopped = (int) $r['stopped'][ $st ]; $next = $i + 1 < $n_f ? (int) $fn[ self::FUNNEL[ $i + 1 ] ] : null;
			echo '<tr><td><b>' . esc_html( self::LABELS[ $st ] ) . '</b> <small>' . esc_html( $st ) . '</small></td><td><a href="' . $link( array( 'step' => $st, 'mode' => 'min', 'ord' => '', 'pg' => '' ) ) . '"><b>' . $reached . '</b></a></td><td>' . (int) $r['vfunnel'][ $st ] . '</td><td>' . esc_html( self::pct( $reached, $fn['view'] ) ) . '</td><td>' . ( null === $next ? '—' : $next ) . '</td><td><a href="' . $link( array( 'step' => $st, 'mode' => 'exact', 'ord' => '', 'pg' => '' ) ) . '"><b>' . $stopped . '</b></a>' . ( null === $next ? ' <small>(завършили)</small>' : '' ) . '</td><td>' . (int) $r['vstopped'][ $st ] . '</td><td>' . esc_html( self::pct( $stopped, $reached ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
		/* списъкът на сесиите */
		$q = array( 'from' => $rg['from'], 'to' => $rg['to'], 'step' => $step, 'mode' => $mode, 'box' => $f['box'], 'ord' => $ord, 'dev' => $f['dev'], 'sort' => 'started', 'internal' => $f['internal'], 'attr' => $f['attr'], 'filter' => $o['filter'] );
		$cnt = self::sessions_count( $q ); $pages = max( 1, (int) ceil( $cnt['n'] / $per ) ); if ( $pg > $pages ) { $pg = $pages; } $q['offset'] = ( $pg - 1 ) * $per;
		$list = self::feed( $per, $q );
		$chip_row = function ( $label, $opts, $key, $cur ) use ( $link ) { $h = '<div class="ap-ins-f"><span class="ap-ins-f__l">' . esc_html( $label ) . '</span><div class="ap-chips">'; foreach ( $opts as $v => $l ) { $h .= '<a class="ap-chip' . ( (string) $v === (string) $cur ? ' on' : '' ) . '" href="' . $link( array( $key => (string) $v, 'pg' => '' ) ) . '">' . esc_html( $l ) . '</a>'; } return $h . '</div></div>'; };
		$step_opts = array( '' => 'всички' ); foreach ( self::FUNNEL as $st ) { $step_opts[ $st ] = self::LABELS[ $st ]; }
		$box_opts = array( '' => 'всички' ); foreach ( $boxes as $bid => $bn ) { $box_opts[ $bid ] = $bn; }
		echo '<div class="ap-sec ap-sec--table" id="apInsList"><div class="ap-sec__h"><span class="ap-sec__t">🧾 Сесиите · ' . esc_html( $ptxt ) . '</span><small><b>' . (int) $cnt['n'] . '</b> сесии · <b>' . (int) $cnt['v'] . '</b> посетители по този филтър</small></div>'
			. '<div class="ap-sec__hint">Същите колони като 🟢 На живо, но за периода и по филтър; подредено по начало на сесията. „стигнали поне до“ = стъпката или по-нататък; „спрели точно на“ = най-далечната им стъпка е тази. „⬇ CSV сесии (този филтър)“ горе сваля точно тези редове.</div>'
			. $chip_row( 'стъпка', $step_opts, 'step', $step )
			. ( $step ? $chip_row( 'как', array( 'min' => 'стигнали поне до „' . self::LABELS[ $step ] . '“', 'exact' => 'спрели точно на „' . self::LABELS[ $step ] . '“' ), 'mode', $mode ) : '' )
			. $chip_row( 'поръчка', array( '' => 'всички', 'yes' => 'с поръчка', 'no' => 'без поръчка' ), 'ord', $ord )
			. ( $boxes ? $chip_row( 'кутия', $box_opts, 'f_box', $f['box'] ) : '' )
			. $chip_row( 'устройство', array( '' => 'всички', 'mobile' => '📱 телефон', 'desktop' => '🖥️ десктоп' ), 'f_dev', $f['dev'] )
			. '<div class="ap-sec__b">' . self::feed_table_html( 'apInsListT', $list ? self::feed_rows( $list ) : '<tr><td colspan="8"><small>Няма сесии за този период и филтър.</small></td></tr>' ) . '</div>';
		if ( $pages > 1 ) {
			echo '<div class="ap-pg">' . ( $pg > 1 ? '<a class="ap-chip" href="' . $link( array( 'pg' => $pg - 1 ) ) . '">← по-нови</a>' : '' ) . '<span>стр. ' . (int) $pg . ' от ' . (int) $pages . ' · по ' . (int) $per . '</span>' . ( $pg < $pages ? '<a class="ap-chip" href="' . $link( array( 'pg' => $pg + 1 ) ) . '">по-стари →</a>' : '' ) . '</div>';
		}
		echo '</div>';
	}

	/* ── 1.28.0 (т. 24): тренд по дни — SVG линия с избор на метрика и наслагване на до 2 кампании ── */
	/** 1.38.0 (човекът: „поглед по дни — колко сесии, колко успешни, откъде са дошли“): дни × канал; каналът е от Channel::of (UTM, иначе referrer). */
	private static function day_channel_html( $days, $r, $frozen = false ) {
		$dc = (array) ( $r['day_ch'] ?? array() ); if ( ! $dc && ! $days ) { return ''; }
		$tot = array(); foreach ( $dc as $day => $chs ) { foreach ( (array) $chs as $k => $v ) { if ( ! isset( $tot[ $k ] ) ) { $tot[ $k ] = array( 'n' => 0, 'created' => 0 ); } $tot[ $k ]['n'] += (int) $v['n']; $tot[ $k ]['created'] += (int) $v['created']; } }
		$keys = array(); foreach ( array_keys( Channel::ORDER ) as $k ) { if ( ! empty( $tot[ $k ]['n'] ) ) { $keys[] = $k; } }
		$cell = function ( $n, $c ) { if ( ! $n ) { return '<td><small>—</small></td>'; } return '<td data-v="' . (int) $n . '"><b>' . (int) $n . '</b>' . ( $c ? ' <small class="ap-dc-o" title="поръчали">· ' . (int) $c . ' 🛒</small>' : '' ) . '</td>'; };
		$h = '<div class="ap-sec ap-sec--table" id="apTDayCh"><div class="ap-sec__h"><span class="ap-sec__t">📅 По дни × откъде идват</span></div>'
			. '<div class="ap-sec__hint">Всеки ден: сесии · поръчали (🛒) · конверсия, и по канал. Каналът е от UTM-а на сесията; ако сесията няма UTM, но е дошла от подстраница на сайта, която носи UTM (кацане на /kozmetika от реклама → клик към промото), UTM-ът се чете от referrer-а. „Попъп на сайта“ се познава по <code>utm_medium=popup</code> в линка на попъпа, а името на попъпа е <code>utm_campaign</code>; попъп без UTM излиза като „Сайтът (линк без UTM)“ със страницата, на която е показан. Цъкни деня, за да видиш целия екран (и списъка със сесиите) само за него.</div>'
			. '<div class="ap-sec__b"><table class="widefat striped ap-dc"><thead><tr><th>ден</th><th>сесии</th><th>поръчали</th><th>конверсия</th>';
		foreach ( $keys as $k ) { $h .= '<th title="' . esc_attr( Channel::label( $k ) ) . '">' . esc_html( Channel::label( $k ) ) . '</th>'; }
		$h .= '</tr></thead><tbody>';
		$sum_n = 0; $sum_c = 0;
		foreach ( array_reverse( $days ) as $day ) {
			$bd = $r['by_day'][ $day ] ?? null; $n = $bd ? (int) $bd['n'] : 0; $c = $bd ? (int) $bd['created'] : 0; $sum_n += $n; $sum_c += $c;
			$lbl = esc_html( wp_date( 'd.m (D)', (int) strtotime( $day . ' 12:00:00' ) ) );
			$h .= '<tr><td><b>' . ( $frozen ? $lbl : '<a href="' . esc_url( self::url( array( 'from' => $day, 'to' => $day, 'days' => null, 'pg' => null ) ) ) . '" title="целият екран само за този ден">' . $lbl . '</a>' ) . '</b></td><td><b>' . $n . '</b></td><td>' . ( $c ? '<b>' . $c . '</b>' : '<small>—</small>' ) . '</td><td>' . esc_html( self::pct( $c, $n ) ) . '</td>';
			foreach ( $keys as $k ) { $v = $dc[ $day ][ $k ] ?? null; $h .= $cell( $v ? (int) $v['n'] : 0, $v ? (int) $v['created'] : 0 ); }
			$h .= '</tr>';
		}
		$h .= '<tr class="ap-dc-total"><td><b>общо</b></td><td><b>' . $sum_n . '</b></td><td><b>' . $sum_c . '</b></td><td>' . esc_html( self::pct( $sum_c, $sum_n ) ) . '</td>';
		foreach ( $keys as $k ) { $h .= $cell( (int) $tot[ $k ]['n'], (int) $tot[ $k ]['created'] ); }
		$h .= '</tr></tbody></table></div></div>';
		return $h;
	}
	private static function trend_html( $days, $r, $os ) {
		$ser = array( 'sessions' => array(), 'created' => array(), 'conv' => array(), 'o_n' => array(), 'rev' => array() ); $camps = array();
		foreach ( $days as $day ) { $bd = $r['by_day'][ $day ] ?? null; $od = $os['by_day'][ $day ] ?? null; $ser['sessions'][] = $bd ? (int) $bd['n'] : 0; $ser['created'][] = $bd ? (int) $bd['created'] : 0; $ser['conv'][] = $bd && $bd['n'] ? round( 100 * $bd['created'] / $bd['n'], 1 ) : 0; $ser['o_n'][] = $od ? (int) $od['n'] : 0; $ser['rev'][] = $od ? round( (float) $od['total'], 2 ) : 0; }
		foreach ( (array) ( $r['camp_day'] ?? array() ) as $c => $byday ) { $camps[ $c ] = array( 'sessions' => array(), 'created' => array(), 'conv' => array(), 'o_n' => array(), 'rev' => array() ); foreach ( $days as $day ) { $x = $byday[ $day ] ?? null; $camps[ $c ]['sessions'][] = $x ? (int) $x['n'] : 0; $camps[ $c ]['created'][] = $x ? (int) $x['created'] : 0; $camps[ $c ]['conv'][] = $x && $x['n'] ? round( 100 * $x['created'] / $x['n'], 1 ) : 0; $camps[ $c ]['o_n'][] = $x ? (int) $x['created'] : 0; $camps[ $c ]['rev'][] = $x ? round( (float) $x['rev'], 2 ) : 0; } }
		$labels = array_map( function ( $d ) { return date_i18n( 'd.m', strtotime( $d ) ); }, $days );
		$opts = ''; foreach ( array_keys( $camps ) as $c ) { $opts .= '<option value="' . esc_attr( $c ) . '">' . esc_html( $c ) . '</option>'; }
		$h = '<div class="ap-sec ap-trend" id="apTrend"><div class="ap-sec__h"><span class="ap-sec__t">📈 Тренд по дни</span><span class="ap-chips">';
		foreach ( array( 'sessions' => 'сесии', 'created' => 'поръчки', 'conv' => 'конверсия %', 'o_n' => 'поръчки (WC)', 'rev' => 'оборот €' ) as $k => $l ) { $h .= '<button type="button" class="ap-chip ap-chip--sm' . ( 'sessions' === $k ? ' on' : '' ) . '" data-m="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</button>'; }
		$h .= '</span></div>' . ( $camps ? '<div class="ap-sec__hint ap-trend__c">наслагай кампании: <select class="ap-trend__sel" data-i="1"><option value="">—</option>' . $opts . '</select> <select class="ap-trend__sel" data-i="2"><option value="">—</option>' . $opts . '</select> <small>(топ 8 по сесии)</small></div>' : '' )
			. '<div class="ap-sec__b"><svg class="ap-trend__svg" viewBox="0 0 800 220" preserveAspectRatio="none" aria-label="тренд"></svg><div class="ap-trend__legend"></div></div>'
			. '<script type="application/json" class="ap-trend__data">' . wp_json_encode( array( 'labels' => $labels, 'series' => $ser, 'camps' => $camps ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script></div>';
		return $h;
	}
	/** „facebook / cpc · esen-2026“ → [src, med, camp]. */
	public static function camp_parts( $key ) { $src = ''; $med = ''; $camp = ''; $k = (string) $key; if ( false !== strpos( $k, ' · ' ) ) { list( $k, $camp ) = explode( ' · ', $k, 2 ); } if ( false !== strpos( $k, ' / ' ) ) { list( $src, $med ) = explode( ' / ', $k, 2 ); } else { $src = $k; } return array( trim( $src ), trim( $med ), trim( $camp ) ); }

	/* ── 1.28.0 (т. 26): устройство × стъпка на отпадане ── */
	private static function dev_matrix_html( $r ) {
		$m = (array) ( $r['dev_stop'] ?? array() ); if ( ! $m ) { return ''; }
		$h = '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">📱 × 🛑 Къде спират по устройство</span></div><div class="ap-sec__hint">За всяко устройство: колко сесии са спрели точно на всяка стъпка (най-далечната им) и какъв дял са от сесиите на устройството. Виж къде телефонът губи повече от десктопа.</div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>устройство</th><th>сесии</th>';
		foreach ( self::FUNNEL as $st ) { $h .= '<th>' . esc_html( self::LABELS[ $st ] ) . '</th>'; } $h .= '</tr></thead><tbody>';
		$icon = array( 'mobile' => '📱 телефон', 'desktop' => '🖥️ десктоп' );
		foreach ( $m as $dev => $steps ) { $n = array_sum( $steps ); $h .= '<tr><td><b>' . esc_html( $icon[ $dev ] ?? $dev ) . '</b></td><td>' . (int) $n . '</td>'; foreach ( self::FUNNEL as $st ) { $c = (int) ( $steps[ $st ] ?? 0 ); $h .= '<td' . ( $c && $n && $c / $n >= 0.25 ? ' class="ap-hot"' : '' ) . '>' . ( $c ? '<b>' . $c . '</b> <small>' . esc_html( self::pct( $c, $n ) ) . '</small>' : '<small>—</small>' ) . '</td>'; } $h .= '</tr>'; }
		return $h . '</tbody></table></div></div>';
	}

	/* ── 1.28.0 (т. 25): кохорти по седмица на първото влизане → поръчка до 1 / 3 / 7 / 14 дни ── */
	public static function cohorts( $rg, $o = array() ) {
		global $wpdb; $ts = Schema::table( 'sessions' ); $o = is_array( $o ) ? $o : array();
		$w = array( $wpdb->prepare( 'started_at >= %s', $rg['from'] . ' 00:00:00' ), $wpdb->prepare( 'sid NOT LIKE %s', self::ORDER_SID . '%' ) ); $w = array_merge( $w, self::filter_where( $o ) );
		$rows = (array) $wpdb->get_results( "SELECT sid, vid, ip, started_at, order_id FROM $ts WHERE " . implode( ' AND ', $w ) . ' ORDER BY started_at ASC', ARRAY_A );
		$vis = array();
		foreach ( $rows as $r ) { $vk = '' !== (string) $r['vid'] ? 'v:' . $r['vid'] : ( '' !== (string) $r['ip'] ? (string) $r['ip'] : 'sid:' . $r['sid'] ); $t = (int) strtotime( (string) $r['started_at'] ); if ( ! isset( $vis[ $vk ] ) ) { $vis[ $vk ] = array( 'first' => $t, 'orders' => array() ); } if ( $r['order_id'] ) { $vis[ $vk ]['orders'][] = $t; } }
		$to = strtotime( $rg['to'] . ' 23:59:59' ); $out = array(); $lags = array();
		foreach ( $vis as $v ) {
			if ( $v['first'] > $to ) { continue; }
			$wk = gmdate( 'Y-m-d', $v['first'] - ( ( (int) gmdate( 'N', $v['first'] ) - 1 ) * DAY_IN_SECONDS ) ); /* понеделникът на седмицата */
			if ( ! isset( $out[ $wk ] ) ) { $out[ $wk ] = array( 'visitors' => 0, 'd1' => 0, 'd3' => 0, 'd7' => 0, 'd14' => 0, 'any' => 0 ); }
			$out[ $wk ]['visitors']++;
			if ( $v['orders'] ) { $lag = min( $v['orders'] ) - $v['first']; $lags[] = $lag; $out[ $wk ]['any']++; foreach ( array( 1, 3, 7, 14 ) as $dd ) { if ( $lag <= $dd * DAY_IN_SECONDS ) { $out[ $wk ][ 'd' . $dd ]++; } } }
		}
		ksort( $out ); sort( $lags );
		return array( 'weeks' => $out, 'lag_med' => $lags ? $lags[ (int) floor( ( count( $lags ) - 1 ) / 2 ) ] : 0, 'lag_n' => count( $lags ) );
	}
	private static function cohorts_html( $c ) {
		$w = (array) ( $c['weeks'] ?? array() ); if ( ! $w ) { return ''; }
		$h = '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🧬 Кохорти — колко бързо „узрява“ една кампания</span><small>медиана от първото влизане до поръчката: <b>' . esc_html( self::dur_fmt( (float) ( $c['lag_med'] ?? 0 ) ) ) . '</b> (' . (int) ( $c['lag_n'] ?? 0 ) . ' поръчки)</small></div><div class="ap-sec__hint">Хората по седмицата на ПЪРВОТО им влизане (от началото на периода нататък) и колко от тях поръчват до 1 / 3 / 7 / 14 дни след него — показва колко дни след пускането на реклама идват поръчките. Последните седмици още не са „узрели“.</div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>първо влизане (седмица от)</th><th>посетители</th><th>поръчали ≤ 1 ден</th><th>≤ 3 дни</th><th>≤ 7 дни</th><th>≤ 14 дни</th><th>общо поръчали</th><th>конверсия на посетител</th></tr></thead><tbody>';
		foreach ( $w as $wk => $v ) { $h .= '<tr><td><b>' . esc_html( date_i18n( 'd.m.Y', strtotime( $wk ) ) ) . '</b></td><td>' . (int) $v['visitors'] . '</td>'; foreach ( array( 'd1', 'd3', 'd7', 'd14', 'any' ) as $k ) { $h .= '<td>' . (int) $v[ $k ] . ' <small>' . esc_html( self::pct( $v[ $k ], $v['visitors'] ) ) . '</small></td>'; } $h .= '<td>' . esc_html( self::pct( $v['any'], $v['visitors'] ) ) . ( $v['visitors'] < Insights_Reports::MIN_ROWS ? ' <small>малка извадка</small>' : '' ) . '</td></tr>'; }
		return $h . '</tbody></table></div></div>';
	}

	/* ── 1.28.0 (т. 3): речникът ── */
	private static function glossary_html() {
		$h = '<details class="ap-sec ap-gloss"><summary class="ap-sec__h"><span class="ap-sec__t">📖 Как се смята всяко число</span><small>цъкни</small></summary><div class="ap-sec__b"><dl>';
		$lbl = array( 'sessions' => 'Сесии', 'visitors' => 'Уникални посетители', 'leads' => 'Имейли от попъпа', 'bounce' => 'Само видели (bounce)', 'dur' => 'Средна продължителност', 'gate' => 'Минали попъпа', 'box' => 'Избрали кутия', 'full' => 'Напълнили кутията', 'add' => 'Към плащане', 'created' => 'Сесии с поръчка', 'conv' => 'Конверсия', 'tto' => 'Време до поръчка', 'o_n' => 'Поръчки', 'o_untracked' => 'Поръчки без проследен път', 'o_delivered' => 'Взети', 'o_open' => 'В път / чакат', 'o_lost' => 'Отказани', 'o_proj' => 'Прогноза', 'rev_real' => 'Реализиран оборот', 'rev_open' => 'Нереализиран оборот', 'rev_lost' => 'Загубен оборот', 'rev_proj' => 'Прогнозен оборот', 'avg' => 'AOV', 'delta' => 'Δ', 'small' => 'Малка извадка', 'camp' => 'UTM и кампания', 'attr' => 'Атрибуция', 'internal' => 'Вътрешен трафик', 'spend' => 'Разход, CPA, ROAS', 'sh_view' => 'Shrine 3+2' );
		foreach ( $lbl as $k => $l ) { if ( isset( self::GLOSSARY[ $k ] ) ) { $h .= '<dt>' . esc_html( $l ) . '</dt><dd>' . esc_html( self::GLOSSARY[ $k ] ) . '</dd>'; } }
		return $h . '</dl></div></details>';
	}

	/* ── 1.28.0 (т. 23): разходът по кампании (ръчно) — option, редове {id, type utm|camp, key, from, to, amount, note} ── */
	const OPT_SPEND = 'ansa_promo_ins_spend';
	public static function spend_rows() { $r = get_option( self::OPT_SPEND, array() ); return is_array( $r ) ? array_values( $r ) : array(); }
	public static function spend_add( $d ) {
		$type = 'camp' === ( $d['type'] ?? '' ) ? 'camp' : 'utm'; $key = trim( wp_strip_all_tags( (string) ( $d['key'] ?? '' ) ) ); $rg = self::range( (string) ( $d['from'] ?? '' ), (string) ( $d['to'] ?? '' ) );
		$amount = (float) str_replace( ',', '.', (string) ( $d['amount'] ?? '0' ) ); $note = substr( trim( wp_strip_all_tags( (string) ( $d['note'] ?? '' ) ) ), 0, 120 );
		if ( '' === $key || $amount <= 0 ) { return array( false, 'Разход: трябва ключ (UTM или кампания) и сума > 0.' ); }
		$rows = self::spend_rows(); $rows[] = array( 'id' => substr( md5( uniqid( '', true ) ), 0, 8 ), 'type' => $type, 'key' => substr( $key, 0, 160 ), 'from' => $rg['from'], 'to' => $rg['to'], 'amount' => round( $amount, 2 ), 'note' => $note, 'at' => current_time( 'mysql' ) );
		update_option( self::OPT_SPEND, $rows, false ); return array( true, 'Разход: записан ' . self::money( $amount ) . ' за „' . $key . '“ (' . self::period_label( $rg ) . ').' );
	}
	public static function spend_del( $id ) { $rows = array_values( array_filter( self::spend_rows(), function ( $r ) use ( $id ) { return $r['id'] !== $id; } ) ); update_option( self::OPT_SPEND, $rows, false ); return array( true, 'Разход: редът е изтрит.' ); }
	/** Разходът за период по ключ: [utm => [key => €], camp => [key => €]] — пропорционално по припокриващите се дни. */
	public static function spend_for( $rg ) {
		$out = array( 'utm' => array(), 'camp' => array() ); $a = strtotime( $rg['from'] ); $b = strtotime( $rg['to'] );
		foreach ( self::spend_rows() as $r ) {
			$ra = strtotime( $r['from'] ); $rb = strtotime( $r['to'] ); $days = max( 1, (int) round( ( $rb - $ra ) / DAY_IN_SECONDS ) + 1 );
			$oa = max( $a, $ra ); $ob = min( $b, $rb ); if ( $ob < $oa ) { continue; } $ov = (int) round( ( $ob - $oa ) / DAY_IN_SECONDS ) + 1;
			$out[ $r['type'] ][ $r['key'] ] = ( $out[ $r['type'] ][ $r['key'] ] ?? 0 ) + (float) $r['amount'] * $ov / $days;
		}
		return $out;
	}
	private static function render_spend() {
		$rg = self::period(); $sp = self::spend_for( $rg ); $r = self::sessions_report( 0, $rg, self::report_opts( self::filters() ) ); $names = self::product_names();
		echo self::period_bar();
		$known = array(); foreach ( array_keys( $r['by_utm'] ) as $k ) { if ( '—' !== $k ) { $known['utm'][] = $k; } } foreach ( array_keys( $r['by_camp'] ) as $k ) { if ( ! in_array( $k, array( 'без UTM', 'само UTM продукт' ), true ) ) { $known['camp'][] = $k; } }
		echo '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">💸 Разход по кампании' . self::q( 'spend' ) . '</span></div><div class="ap-sec__hint">Въведи какво е похарчено за период по UTM (продуктът в рекламата) или по кампания (utm_source / medium · campaign, както е в таблицата). В Прегледа се появяват разход, € / сесия, CPA и ROAS за реда (групата „разход“ над таблицата). Период, който частично попада в избрания, се взема пропорционално по дни.</div><div class="ap-sec__b">';
		self::action_form_open( 'spend_add' );
		echo '<p><label>за <select name="type"><option value="utm">UTM (продукт)</option><option value="camp">кампания</option></select></label> <label>ключ <input type="text" name="key" list="apSpendKeys" style="width:260px" placeholder="sakura · facebook / cpc · esen-2026" required></label><datalist id="apSpendKeys">';
		foreach ( (array) ( $known['utm'] ?? array() ) as $k ) { echo '<option value="' . esc_attr( $k ) . '">' . esc_html( ( $names[ $k ] ?? $k ) . ' (UTM)' ) . '</option>'; } foreach ( (array) ( $known['camp'] ?? array() ) as $k ) { echo '<option value="' . esc_attr( $k ) . '">кампания</option>'; }
		echo '</datalist> <label>от <input type="date" name="from" value="' . esc_attr( $rg['from'] ) . '" required></label> <label>до <input type="date" name="to" value="' . esc_attr( $rg['to'] ) . '" required></label> <label>сума € <input type="text" inputmode="decimal" name="amount" style="width:90px" placeholder="250" required></label> <label>бележка <input type="text" name="note" style="width:160px" placeholder="FB кампания октомври"></label> <button class="button button-primary">＋ Добави</button></p></form>';
		echo '</div></div>';
		$rows = self::spend_rows(); usort( $rows, function ( $a, $b ) { return strcmp( $b['from'], $a['from'] ); } );
		echo '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">🧾 Въведените разходи</span><small>' . count( $rows ) . ' реда · за ' . esc_html( self::period_label( $rg ) ) . ': ' . esc_html( self::money( array_sum( $sp['utm'] ) + array_sum( $sp['camp'] ) ) ) . '</small></div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>за</th><th>ключ</th><th>период</th><th>сума</th><th>в избрания период</th><th>бележка</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $x ) { $in = $sp[ $x['type'] ][ $x['key'] ] ?? 0; echo '<tr><td>' . ( 'camp' === $x['type'] ? 'кампания' : 'UTM' ) . '</td><td><b>' . esc_html( 'utm' === $x['type'] ? ( $names[ $x['key'] ] ?? $x['key'] ) : $x['key'] ) . '</b></td><td>' . esc_html( self::period_label( array( 'from' => $x['from'], 'to' => $x['to'] ) ) ) . '</td><td>' . esc_html( self::money( $x['amount'] ) ) . '</td><td>' . esc_html( self::money( $in ) ) . '</td><td><small>' . esc_html( $x['note'] ) . '</small></td><td>'; self::action_form_open( 'spend_del', ' onsubmit="return confirm(\'Да изтрия ли този разход?\')"' ); echo '<input type="hidden" name="id" value="' . esc_attr( $x['id'] ) . '"><button class="button button-small">✕</button></form></td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="7"><small>още няма въведен разход</small></td></tr>'; }
		echo '</tbody></table></div></div>';
		/* възвръщаемост за периода */
		$roi = array(); foreach ( array( 'utm' => $r['by_utm'], 'camp' => $r['by_camp'] ) as $type => $rows2 ) { foreach ( $sp[ $type ] as $key => $amt ) { $v = $rows2[ $key ] ?? array(); $roi[ ( 'utm' === $type ? '🏷️ ' . ( $names[ $key ] ?? $key ) : '📣 ' . $key ) ] = self::row_calc( $v, $amt ); } }
		if ( $roi ) {
			echo '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">📊 Възвръщаемост · ' . esc_html( self::period_label( $rg ) ) . '</span></div><div class="ap-sec__hint">Оборотът е на всички поръчки от реда (с ДДС и доставка). ROAS = оборот ÷ разход; CPA = разход ÷ поръчки. Под 30 сесии — малка извадка.</div><div class="ap-sec__b"><table class="widefat striped ap-sort"><thead><tr><th>ключ</th><th data-sort="1">разход</th><th data-sort="1">сесии</th><th data-sort="1">€ / сесия</th><th data-sort="1">поръчки</th><th data-sort="1">конверсия</th><th data-sort="1">CPA</th><th data-sort="1">оборот</th><th data-sort="1">ROAS</th></tr></thead><tbody>';
			foreach ( $roi as $k => $v ) { echo '<tr><td><b>' . esc_html( $k ) . '</b></td><td data-v="' . esc_attr( (string) $v['spend'] ) . '">' . esc_html( self::money( $v['spend'] ) ) . '</td><td data-v="' . (int) $v['n'] . '">' . (int) $v['n'] . '</td><td data-v="' . esc_attr( (string) ( $v['cps'] ?? -1 ) ) . '">' . ( null === $v['cps'] ? '—' : esc_html( self::money( $v['cps'] ) ) ) . '</td><td data-v="' . (int) $v['created'] . '">' . (int) $v['created'] . '</td><td data-v="' . esc_attr( (string) ( $v['conv_raw'] ?? -1 ) ) . '">' . esc_html( $v['conv'] ) . ( $v['small'] ? ' <small>малка извадка</small>' : '' ) . '</td><td data-v="' . esc_attr( (string) ( $v['cpa'] ?? -1 ) ) . '">' . ( null === $v['cpa'] ? '—' : esc_html( self::money( $v['cpa'] ) ) ) . '</td><td data-v="' . esc_attr( (string) $v['rev'] ) . '">' . esc_html( self::money( $v['rev'] ) ) . '</td><td data-v="' . esc_attr( (string) ( $v['roas'] ?? -1 ) ) . '"><b>' . ( null === $v['roas'] ? '—' : esc_html( number_format( $v['roas'], 2, ',', '' ) . '×' ) ) . '</b></td></tr>'; }
			echo '</tbody></table></div></div>';
		}
	}

	/* ── 1.28.0 (т. 30): замразени отчети — option-и ansa_promo_ins_snap_<id> + индекс ── */
	const OPT_SNAPS = 'ansa_promo_ins_snaps';
	public static function snap_list() { $l = get_option( self::OPT_SNAPS, array() ); return is_array( $l ) ? $l : array(); }
	public static function snap_add( $from, $to, $f = null ) {
		$rg = self::range( (string) $from, (string) $to ); $f = $f ?: self::filters(); $d = self::overview_data( $rg, $f ); $id = gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 4 );
		update_option( 'ansa_promo_ins_snap_' . $id, $d, false );
		$l = self::snap_list(); array_unshift( $l, array( 'id' => $id, 'at' => $d['at'], 'from' => $rg['from'], 'to' => $rg['to'], 'filter' => self::filters_active( $f ), 'sessions' => (int) $d['r']['sessions'], 'orders' => (int) $d['os']['n'], 'rev' => (float) $d['os']['delivered']['total'] ) );
		$l = array_slice( $l, 0, 60 ); foreach ( array_slice( $l, 60 ) as $old ) { delete_option( 'ansa_promo_ins_snap_' . $old['id'] ); } update_option( self::OPT_SNAPS, $l, false );
		return array( true, '📌 Отчетът за ' . self::period_label( $rg ) . ' е замразен (' . $id . ').', $id );
	}
	public static function snap_get( $id ) { $id = preg_replace( '/[^0-9a-f\-]/', '', (string) $id ); $d = get_option( 'ansa_promo_ins_snap_' . $id, null ); return is_array( $d ) ? $d : null; }
	public static function snap_del( $id ) { $id = preg_replace( '/[^0-9a-f\-]/', '', (string) $id ); delete_option( 'ansa_promo_ins_snap_' . $id ); update_option( self::OPT_SNAPS, array_values( array_filter( self::snap_list(), function ( $x ) use ( $id ) { return $x['id'] !== $id; } ) ), false ); return array( true, 'Замразеният отчет е изтрит.' ); }
	private static function render_snaps() {
		$id = isset( $_GET['snap'] ) ? (string) $_GET['snap'] : '';
		if ( '' !== $id ) { $d = self::snap_get( $id ); if ( $d ) { echo '<div class="ap-actions" style="padding:0 0 12px"><a class="ap-chip" href="' . esc_url( self::url( array( 'view' => 'snap', 'snap' => null ) ) ) . '">← всички замразени</a></div>'; echo self::overview_html( $d, true ); return; } echo '<div class="ap-msg bad">Няма такъв замразен отчет.</div>'; }
		$l = self::snap_list();
		echo '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">📌 Замразени отчети</span><small>' . count( $l ) . '</small></div><div class="ap-sec__hint">Снимка на Прегледа за период в момента на замразяването — числата не се променят при по-късни смени на статуси на поръчки или сверки. Прави се от бутона „📌 Замрази отчета“ в Преглед. Линкът може да се прати на колега с достъп.</div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>замразен на</th><th>период</th><th>филтър</th><th>сесии</th><th>поръчки</th><th>реализиран оборот</th><th></th></tr></thead><tbody>';
		foreach ( $l as $x ) { echo '<tr><td><a href="' . esc_url( self::url( array( 'view' => 'snap', 'snap' => $x['id'] ) ) ) . '"><b>' . esc_html( $x['at'] ) . '</b></a></td><td>' . esc_html( self::period_label( array( 'from' => $x['from'], 'to' => $x['to'] ) ) ) . '</td><td>' . ( ! empty( $x['filter'] ) ? 'да' : '—' ) . '</td><td>' . (int) $x['sessions'] . '</td><td>' . (int) $x['orders'] . '</td><td>' . esc_html( self::money( $x['rev'] ) ) . '</td><td>'; self::action_form_open( 'snap_del', ' onsubmit="return confirm(\'Да изтрия ли замразения отчет?\')"' ); echo '<input type="hidden" name="id" value="' . esc_attr( $x['id'] ) . '"><button class="button button-small">✕</button></form></td></tr>'; }
		if ( ! $l ) { echo '<tr><td colspan="7"><small>още няма замразени отчети</small></td></tr>'; }
		echo '</tbody></table></div></div>';
	}

	/* ── 1.28.0: действията (разход, замразяване) — от админа (admin-post) и от портала (POST с CSRF) през едно място ── */
	public static function action_form_open( $do, $extra = '' ) {
		if ( self::$portal ) { echo '<form method="post" action="' . esc_url( Insights_Portal::url() ) . '" style="display:inline"' . $extra . '><input type="hidden" name="ap_action" value="' . esc_attr( $do ) . '"><input type="hidden" name="csrf" value="' . esc_attr( Insights_Portal::csrf() ) . '"><input type="hidden" name="view" value="' . esc_attr( self::current_view() ) . '">'; return; }
		Admin::form_open_public( 'ins_' . $do, 'insights', $extra ); echo '<input type="hidden" name="view" value="' . esc_attr( self::current_view() ) . '">';
	}
	/** Изпълнява действие; връща [ok, съобщение, (id)]. */
	public static function handle_action( $do, $d ) {
		switch ( $do ) {
			case 'spend_add': return self::spend_add( $d );
			case 'spend_del': return self::spend_del( (string) ( $d['id'] ?? '' ) );
			case 'snap_add': return self::snap_add( (string) ( $d['from'] ?? '' ), (string) ( $d['to'] ?? '' ) );
			case 'snap_del': return self::snap_del( (string) ( $d['id'] ?? '' ) );
		}
		return array( false, 'Непознато действие.' );
	}

	/* ── 1.28.0 (т. 30): CSV по разбивка и JSON за Looker Studio ── */
	public static function csv_breakdown( $which, $rg, $f = null ) {
		$f = $f ?: self::filters(); $o = self::report_opts( $f ); $os = self::orders_stats( $rg, null, null, $o ); $r = self::sessions_report( 0, $rg, self::report_opts( $f, $os['by_id'] ) ); $sp = self::spend_for( $rg ); $names = self::product_names();
		$map = array( 'utm' => array( $r['by_utm'], 'utm', $sp['utm'] ), 'camp' => array( $r['by_camp'], 'кампания', $sp['camp'] ), 'day' => array( $r['by_day'], 'ден', null ), 'box' => array( $r['by_box'], 'кутия', null ), 'dev' => array( $r['by_dev'], 'устройство', null ), 'src' => array( $r['by_src'], 'източник', null ), 'hour' => array( $r['by_hour'], 'час', null ), 'ref' => array( $r['by_ref'], 'referrer', null ), 'week' => array( $r['by_week'], 'седмица', null ) );
		list( $rows, $first, $spend ) = $map[ $which ] ?? $map['utm'];
		$fh = fopen( 'php://temp', 'w+' ); fputs( $fh, "\xEF\xBB\xBF" ); $cols = array_keys( self::COLS ); fputcsv( $fh, array_merge( array( $first, 'период' ), array_map( function ( $c ) { return self::COLS[ $c ][0]; }, $cols ) ), ';' );
		foreach ( (array) $rows as $k => $v ) { $c = self::row_calc( $v, null !== $spend ? ( $spend[ $k ] ?? null ) : null ); $line = array( 'utm' === $which ? ( $names[ $k ] ?? $k ) : $k, $rg['from'] . ' – ' . $rg['to'] ); foreach ( $cols as $col ) { $x = 'conv' === $col ? ( null === $c['conv_raw'] ? '' : round( 100 * $c['conv_raw'], 2 ) ) : ( $c[ $col ] ?? '' ); $line[] = is_float( $x ) ? number_format( $x, 2, '.', '' ) : $x; } fputcsv( $fh, $line, ';' ); }
		rewind( $fh ); $csv = stream_get_contents( $fh ); fclose( $fh ); return $csv;
	}
	public static function api_payload( $rg, $f = null ) {
		$f = $f ?: self::filters(); $o = self::report_opts( $f ); $os = self::orders_stats( $rg, null, null, $o ); $r = self::sessions_report( 0, $rg, self::report_opts( $f, $os['by_id'] ) ); $sp = self::spend_for( $rg );
		$flat = self::dashboard_flat( self::dashboard_data( $r, $os, array( 'period' => self::period_label( $rg ) ) ) );
		$tab = function ( $rows, $spend = null ) { $out = array(); foreach ( (array) $rows as $k => $v ) { $c = self::row_calc( $v, null !== $spend ? ( $spend[ $k ] ?? null ) : null ); $out[] = array( 'key' => (string) $k, 'sessions' => $c['n'], 'visitors' => $c['v'], 'gate' => $c['gate'], 'box' => $c['box'], 'full' => $c['full'], 'add' => $c['add'], 'orders' => $c['created'], 'paid' => $c['paid'], 'delivered' => $c['delivered'], 'conv' => null === $c['conv_raw'] ? null : round( 100 * $c['conv_raw'], 2 ), 'revenue' => round( $c['rev'], 2 ), 'revenue_delivered' => round( $c['rev_del'], 2 ), 'aov' => round( $c['aov'], 2 ), 'spend' => $c['spend'], 'cpa' => $c['cpa'], 'roas' => $c['roas'], 'small_sample' => $c['small'] ); } return $out; };
		return array( 'generated_at' => current_time( 'mysql' ), 'from' => $rg['from'], 'to' => $rg['to'], 'attribution' => $f['attr'], 'internal_included' => (bool) $f['internal'], 'kpi' => $flat['kpi'], 'funnel' => $r['funnel'], 'stopped' => $r['stopped'], 'by_utm' => $tab( $r['by_utm'], $sp['utm'] ), 'by_campaign' => $tab( $r['by_camp'], $sp['camp'] ), 'by_day' => $tab( $r['by_day'] ), 'by_box' => $tab( $r['by_box'] ), 'by_device' => $tab( $r['by_dev'] ), 'by_hour' => $tab( $r['by_hour'] ), 'orders' => array( 'n' => $os['n'], 'total' => round( $os['total'], 2 ), 'delivered' => $os['delivered'], 'open' => $os['open'], 'lost' => $os['lost'], 'untracked' => $os['untracked'] ) );
	}

	/** 1.28.0: общият JS на екраните — сортиране на таблици, групи колони, тренд графика. Веднъж на заявка. */
	public static function scripts() {
		static $done = false; if ( $done ) { return ''; } $done = true;
		return '<script>(function(){'
			/* 1.30.1: 📞 „✏️ Редактирай поръчка“ / „✅ Завърши поръчка“ — и двата първо питат „Отстъпка / Продукт подарък / Нищо“; изборът отива в линка
			   (&bonus= за чекаута, &ansa_bonus= за промо страницата). Без JS линковете работят без бонус. */
			. 'var OPC=' . json_encode( Phone_Orders::chooser_cfg(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';'
			. 'function H(s){return String(s).replace(/[&<>"]/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c]})}'
			. 'document.addEventListener("click",function(e){var a=e.target&&e.target.closest?e.target.closest("a[data-op]"):null;if(!a)return;e.preventDefault();var old=document.getElementById("apOpPick");if(old)old.remove();'
			. 'var go=a.getAttribute("data-op")==="go",w=document.createElement("div");w.id="apOpPick";w.setAttribute("role","dialog");'
			. 'var h="<div class=\\"ap-oppick__c\\"><b>📞 Бонус за обаждането?</b><small>"+(go?"количката на човека отива направо в чекаута":"отваря промо страницата за корекции")+"</small>";'
			. 'if(OPC.pct)h+="<button type=\\"button\\" data-b=\\"pct\\">💶 "+H(OPC.pct)+"</button>";if(OPC.gift)h+="<button type=\\"button\\" data-b=\\"gift\\">"+H(OPC.gift)+"</button>";'
			. 'h+="<button type=\\"button\\" data-b=\\"\\">"+H(OPC.none)+"</button><button type=\\"button\\" class=\\"x\\" data-b=\\"x\\">отказ</button></div>";w.innerHTML=h;document.body.appendChild(w);'
			. 'function kd(ev){if(ev.key==="Escape")close()}function close(){w.remove();document.removeEventListener("keydown",kd)}document.addEventListener("keydown",kd);var f=w.querySelector("button");if(f)f.focus();'
			. 'w.addEventListener("click",function(ev){if(ev.target===w){close();return}var b=ev.target.closest?ev.target.closest("[data-b]"):null;if(!b)return;var v=b.getAttribute("data-b");close();if(v==="x")return;'
			. 'var u=new URL(a.getAttribute("href"),location.href);if(v)u.searchParams.set(go?"bonus":"ansa_bonus",v);var l=document.createElement("a");l.href=u.toString();l.target="_blank";l.rel="noopener";document.body.appendChild(l);l.click();l.remove()})});'
			/* сортиране */
			. 'document.querySelectorAll("table.ap-sort").forEach(function(t){t.querySelectorAll("thead th").forEach(function(th,i){if(i===0||!th.hasAttribute("data-sort"))return;th.classList.add("ap-sortable");th.addEventListener("click",function(){var tb=t.tBodies[0],rows=Array.prototype.slice.call(tb.rows),asc=th.classList.contains("desc");t.querySelectorAll("thead th").forEach(function(x){x.classList.remove("asc","desc")});th.classList.add(asc?"asc":"desc");rows.sort(function(a,b){var av=parseFloat((a.cells[i]||{}).getAttribute&&a.cells[i].getAttribute("data-v")||"-1"),bv=parseFloat((b.cells[i]||{}).getAttribute&&b.cells[i].getAttribute("data-v")||"-1");return asc?av-bv:bv-av});rows.forEach(function(r){tb.appendChild(r)})})})});'
			/* групи колони */
			. 'document.querySelectorAll(".ap-colgs").forEach(function(box){var sec=box.closest(".ap-sec"),t=sec&&sec.querySelector("table");if(!t)return;box.querySelectorAll(".ap-colg").forEach(function(b){b.addEventListener("click",function(){var g=b.getAttribute("data-g");b.classList.toggle("on");t.classList.toggle("g-"+g,b.classList.contains("on"))})})});'
			/* тренд */
			. 'var tr=document.getElementById("apTrend");if(tr){var data=JSON.parse(tr.querySelector(".ap-trend__data").textContent),svg=tr.querySelector("svg"),leg=tr.querySelector(".ap-trend__legend"),m="sessions",sel=["",""],colors=["#f47a49","#2469a8","#3bb273"];'
			. 'function draw(){var W=800,H=220,P={l:44,r:10,t:10,b:26},ls=data.labels,n=ls.length,lines=[{n:"общо",v:data.series[m]}];sel.forEach(function(c){if(c&&data.camps[c])lines.push({n:c,v:data.camps[c][m]})});var mx=0;lines.forEach(function(l){l.v.forEach(function(v){if(v>mx)mx=v})});mx=mx||1;var x=function(i){return P.l+(n>1?i*(W-P.l-P.r)/(n-1):0)},y=function(v){return P.t+(H-P.t-P.b)*(1-v/mx)},s="";'
			. 'for(var g=0;g<=4;g++){var gy=P.t+(H-P.t-P.b)*g/4,gv=mx*(1-g/4);s+="<line x1=\""+P.l+"\" y1=\""+gy+"\" x2=\""+(W-P.r)+"\" y2=\""+gy+"\" stroke=\"#f6ddd5\" stroke-width=\"1\"/><text x=\""+(P.l-6)+"\" y=\""+(gy+4)+"\" font-size=\"10\" text-anchor=\"end\" fill=\"#a08579\">"+(gv>=100?Math.round(gv):Math.round(gv*10)/10)+"</text>"}'
			. 'var step=Math.max(1,Math.ceil(n/12));for(var i=0;i<n;i+=step){s+="<text x=\""+x(i)+"\" y=\""+(H-8)+"\" font-size=\"10\" text-anchor=\"middle\" fill=\"#a08579\">"+ls[i]+"</text>"}'
			. 'lines.forEach(function(l,li){var pts=l.v.map(function(v,i){return x(i)+","+y(v)}).join(" ");s+="<polyline fill=\"none\" stroke=\""+colors[li%3]+"\" stroke-width=\"2.2\" stroke-linejoin=\"round\" points=\""+pts+"\"/>";l.v.forEach(function(v,i){s+="<circle cx=\""+x(i)+"\" cy=\""+y(v)+"\" r=\"2.6\" fill=\""+colors[li%3]+"\"><title>"+ls[i]+": "+v+" ("+l.n+")</title></circle>"})});svg.innerHTML=s;'
			. 'leg.innerHTML=lines.map(function(l,li){return "<span><i style=\"background:"+colors[li%3]+"\"></i>"+l.n+"</span>"}).join("")}'
			. 'tr.querySelectorAll("[data-m]").forEach(function(b){b.addEventListener("click",function(){m=b.getAttribute("data-m");tr.querySelectorAll("[data-m]").forEach(function(x){x.classList.toggle("on",x===b)});draw()})});tr.querySelectorAll(".ap-trend__sel").forEach(function(se){se.addEventListener("change",function(){sel[parseInt(se.getAttribute("data-i"),10)-1]=se.value;draw()})});draw();}'
			. '})();</script>';
	}

	/** 1.23.0: поръчките по статус (WooCommerce) — брой, сума, група (взети / в път / загубени). */
	public static function status_table_html( $os, $period = '' ) {
		$gl = array( 'delivered' => '✅ взети', 'open' => '🚚 в път / чакат', 'lost' => '✕ загубени' );
		$h = '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">📦 Поръчките по статус' . ( $period ? ' · ' . esc_html( $period ) : '' ) . '</span><small>' . (int) $os['n'] . ' поръчки · ' . esc_html( self::money( $os['total'] ) ) . '</small></div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>статус (WooCommerce)</th><th>поръчки</th><th>% от всички</th><th>сума</th><th>група</th><th title="в път: от колко са от ≥ N дни в този статус (застояли — вероятно няма да се вземат) и колко са пресни">застояли ≥ ' . (int) ( $os['mature_days'] ?? self::MATURE_DAYS ) . ' дни · пресни</th></tr></thead><tbody>';
		foreach ( $os['by_status'] as $st => $v ) { $h .= '<tr><td><b>' . esc_html( $v['name'] ) . '</b> <small>' . esc_html( $st ) . '</small></td><td>' . (int) $v['n'] . '</td><td>' . esc_html( self::pct( $v['n'], $os['n'] ) ) . '</td><td>' . esc_html( self::money( $v['total'] ) ) . '</td><td>' . esc_html( $gl[ $v['grp'] ] ?? $v['grp'] ) . '</td><td>' . ( 'open' === $v['grp'] ? '<b>' . (int) ( $v['stale'] ?? 0 ) . '</b> · ' . (int) ( $v['young'] ?? 0 ) : '—' ) . '</td></tr>'; }
		if ( ! $os['by_status'] ) { $h .= '<tr><td colspan="6"><small>няма промо поръчки за периода</small></td></tr>'; }
		return $h . '</tbody></table></div></div>';
	}
	/** Стиловете на екрана (веднъж). */
	private static function styles() {
		static $done = false; if ( $done ) { return; } $done = true;
		echo '<style>.ap-ins-views .ap-chip{font-size:12.5px;padding:8px 16px}'
			/* 1.23.0: дашбордът — групи карти с плочки; лентата отляво носи тона (добро / внимание / лошо / инфо), числата са в мастило */
			. '.ap-dash{display:grid;gap:14px;margin:0 0 16px}.ap-stg{background:#fff;border-radius:20px;box-shadow:0 8px 24px rgba(224,96,44,.09);padding:14px 20px 16px}.ap-stg__h{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin:0 0 10px}.ap-stg__t{font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#a08579;font-weight:800}.ap-stg__hint{font-size:11px;font-weight:700;color:#a08579}'
			. '.ap-stg__g{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}.ap-st{position:relative;background:#fdebe9;border-radius:14px;padding:12px 14px 12px 18px;min-height:88px;display:flex;flex-direction:column;gap:5px;overflow:hidden}.ap-st::before{content:"";position:absolute;left:0;top:12px;bottom:12px;width:4px;border-radius:0 4px 4px 0;background:#f47a49}'
			. '.ap-st--good::before{background:#3bb273}.ap-st--warn::before{background:#f8a41d}.ap-st--bad::before{background:#d64545}.ap-st--info::before{background:#2469a8}.ap-st__l{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#a08579}.ap-st__v{display:block;font-size:26px;font-weight:900;line-height:1.1;color:#43302a;font-variant-numeric:normal}.ap-st--hero{grid-column:span 2}.ap-st--hero .ap-st__v{font-size:48px}'
			. '.ap-st__s{font-size:11px;font-weight:700;color:#a08579;line-height:1.35}.ap-st__s b{color:#43302a;font-weight:900}.ap-st__s a{color:#e0602c;font-weight:900;text-decoration:none}@media(max-width:600px){.ap-st--hero{grid-column:span 1}.ap-st--hero .ap-st__v{font-size:34px}}'
			. '.ap-funnel{padding:4px 20px 6px;display:grid;gap:7px}.ap-fn{display:grid;grid-template-columns:190px 1fr 60px 260px;gap:10px;align-items:center;font-size:12.5px}.ap-fn__l{font-weight:800}.ap-fn__bar{height:16px;background:#fdebe9;border-radius:999px;overflow:hidden}.ap-fn__bar i{display:block;height:100%;background:linear-gradient(90deg,#f8a41d,#f47a49);border-radius:999px}.ap-fn__n{font-weight:900;text-align:right}.ap-fn small{font-weight:700;color:#a08579}.ap-tl{display:flex;gap:4px;flex-wrap:wrap}.ap-tl span{font-size:10.5px;font-weight:800;background:#fdebe9;border-radius:999px;padding:1px 7px;white-space:nowrap}.ap-tl span.hi{background:#def3e7;color:#1f7a4d}.ap-tl span.ord{background:#fdeecd;color:#a86b09}'
			. '.ap-ins-range{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.ap-ins-range label{font-size:12px;font-weight:800;color:#a08579}.ap-ins-range input[type=date]{border:0;border-radius:999px;padding:5px 12px;background:#fff;box-shadow:0 4px 10px rgba(0,0,0,.06);font:inherit;font-size:12px;font-weight:800;color:#3b2a24;margin-left:4px}'
			. '.ap-kpi.warn b{color:#a86b09}.ap-kpi span a{text-transform:none;letter-spacing:0;font-weight:900;color:#d9603a;text-decoration:none}.ap-ins-f{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:3px 20px}.ap-ins-f__l{font-size:10.5px;font-weight:800;color:#a08579;text-transform:uppercase;letter-spacing:.06em;min-width:84px}.ap-pg{display:flex;gap:10px;align-items:center;justify-content:center;padding:10px 20px 4px;font-size:12px;font-weight:800;color:#a08579}'
			. '@media(max-width:900px){.ap-fn{grid-template-columns:1fr 60px}.ap-fn__bar,.ap-fn small{grid-column:1/-1}}'
			/* 1.28.0: Δ, sparkline, речник „?“, бадж на източника, групи колони, сортиране, малка извадка, матрица, нови редове, тренд, кохорти, телефон */
			. '.ap-st__row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.ap-st__d{font-size:11px;font-weight:900}.ap-d{display:inline-block;padding:1px 7px;border-radius:999px;background:#eef0f2;color:#50575e;font-size:10.5px;font-weight:900;white-space:nowrap}.ap-d.up{background:#def3e7;color:#1f7a4d}.ap-d.down{background:#fadddd;color:#d64545}'
			. '.ap-st__sp{margin-left:auto;color:#e0602c;opacity:.75;line-height:0}.ap-spark{display:block}.ap-q{display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:#fde4d9;color:#e0602c;font-size:9.5px;font-weight:900;margin-left:5px;cursor:help;vertical-align:1px;text-transform:none;letter-spacing:0}'
			. '.ap-src{display:inline-block;margin-left:6px;font-style:normal;font-size:9px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;padding:1px 6px;border-radius:999px;background:#ddeefc;color:#2469a8;vertical-align:1px}.ap-src--s{background:#fdeecd;color:#a86b09}.ap-small{color:#a86b09}.ap-small-c small{color:#a86b09;font-weight:800;white-space:nowrap}'
			. '.ap-fbar__l{font-size:10.5px;font-weight:800;color:#a08579;text-transform:uppercase;letter-spacing:.06em;margin-right:6px}.ap-prev{padding:0 4px 12px}'
			. '.ap-colgs{margin-left:auto}table.ap-sort th[data-g],table.ap-sort td[data-g]{display:none}table.ap-sort th[data-g=base],table.ap-sort td[data-g=base]{display:table-cell}table.g-traffic th[data-g=traffic],table.g-traffic td[data-g=traffic],table.g-funnel th[data-g=funnel],table.g-funnel td[data-g=funnel],table.g-orders th[data-g=orders],table.g-orders td[data-g=orders],table.g-revenue th[data-g=revenue],table.g-revenue td[data-g=revenue],table.g-spend th[data-g=spend],table.g-spend td[data-g=spend]{display:table-cell}'
			. 'th.ap-sortable{cursor:pointer;user-select:none}th.ap-sortable.desc::after{content:" ▼"}th.ap-sortable.asc::after{content:" ▲"}.ap-sorti{font-style:normal;opacity:.5}.ap-hot{background:#fadddd!important}'
			. '.ap-fn__drop{padding:0 0 0 200px;font-size:11.5px;font-weight:800;color:#a08579;margin:-3px 0}.ap-fn__drop.has{color:#d64545}.ap-fn__drop small{font-weight:700;color:#a08579}'
			. '#apInsFeedT tr.ap-new td{animation:apNew 6s ease-out}@keyframes apNew{0%{background:#fdeecd}100%{background:transparent}}.ap-cart{font-size:10.5px;font-weight:800;color:#a86b09}'
			/* 1.30.1: 📞 изборът „Отстъпка / Продукт подарък / Нищо“ */
			. '#apOpPick{position:fixed;inset:0;z-index:2147483000;background:rgba(67,48,42,.45);display:flex;align-items:center;justify-content:center;padding:16px}.ap-oppick__c{background:#fff;border-radius:20px;box-shadow:0 24px 60px rgba(67,48,42,.3);padding:22px 24px;display:grid;gap:8px;min-width:280px;max-width:420px}.ap-oppick__c b{font-size:16px;color:#43302a}.ap-oppick__c small{color:#a08579;font-weight:700;margin-bottom:4px}.ap-oppick__c button{border:0;border-radius:999px;padding:11px 16px;font:800 13px Nunito,sans-serif;cursor:pointer;background:#fdebe9;color:#43302a;text-align:left}.ap-oppick__c button:hover,.ap-oppick__c button:focus-visible{background:#f47a49;color:#fff;outline:none}.ap-oppick__c button.x{background:transparent;color:#a08579;text-align:center}.ap-oppick__c button.x:hover{background:#fdebe9;color:#43302a}'
			. '.ap-trend__svg{width:100%;height:220px;display:block}.ap-trend__legend{display:flex;gap:14px;flex-wrap:wrap;padding:6px 0 0;font-size:11.5px;font-weight:800;color:#a08579}.ap-trend__legend i{display:inline-block;width:12px;height:4px;border-radius:2px;margin-right:5px;vertical-align:middle}.ap-trend__c select{font-size:12px;font-weight:700;border-radius:999px;border:0;background:#fdebe9;padding:4px 10px;margin-right:6px}'
			. '.ap-gloss summary{cursor:pointer;list-style:none}.ap-gloss summary::-webkit-details-marker{display:none}.ap-gloss dl{columns:2;column-gap:28px;font-size:12.5px}.ap-gloss dt{font-weight:900;break-after:avoid}.ap-gloss dd{margin:0 0 10px;color:#6b5f58;font-weight:600}'
			. '@media(max-width:900px){.ap-gloss dl{columns:1}.ap-fn__drop{padding-left:0}}'
			. '@media(max-width:600px){.ap-stg__g{grid-template-columns:repeat(2,1fr)}.ap-st__v{font-size:21px}.ap-st--hero .ap-st__v{font-size:28px}.ap-sec--table .ap-sec__b table{min-width:560px}.ap-sec--table .ap-sec__b table th:first-child,.ap-sec--table .ap-sec__b table td:first-child{position:sticky;left:0;background:#fff;z-index:1;box-shadow:2px 0 0 #fdebe9}.ap-sec--table .ap-sec__b table.striped tbody tr:nth-child(odd) td:first-child{background:#fff8f5}}</style>';
	}
	/** 1.28.0 (т. 27): изоставените колички от FunnelKit по имейл (само без поръчка) — за фийда и списъка. Веднъж на заявка. */
	public static function carts_by_email() {
		static $m = null; if ( null !== $m ) { return $m; } $m = array();
		try { $rows = class_exists( __NAMESPACE__ . '\\Cart_Sync' ) ? Cart_Sync::abandoned_rows( 30, 300 ) : null; } catch ( \Throwable $e ) { $rows = null; }
		foreach ( (array) $rows as $r ) { if ( ! empty( $r['email'] ) && empty( $r['order_id'] ) ) { $m[ strtolower( (string) $r['email'] ) ] = (string) ( $r['status_label'] ?? 'количка' ); } }
		return $m;
	}
	/** Редовете на фийда (HTML) — и за ajax опресняването. */
	public static function feed_rows( $feed ) {
		if ( ! $feed ) { return '<tr><td colspan="8"><small>Още няма сесии — стъпките се записват, когато играта е публикувана и някой отвори страницата (черновата и редакторът не броят).</small></td></tr>'; }
		$h = ''; $names = self::product_names();
		foreach ( $feed as $s ) {
			/* 1.20.4: същият посетител (IP) се познава по етикета 👤xxxx; „5 сесии“ = колко пъти е влизал общо (не е 5 различни човека) */
			$vis = ! empty( $s['visitor'] ) ? '<small class="ap-vis" title="един и същ посетител (IP) — не е адресът, а етикет">👤' . esc_html( $s['visitor'] ) . ( ( $s['visits'] ?? 1 ) > 1 ? ' · <b>' . (int) $s['visits'] . ' сесии</b>' : ' · 1-ва сесия' ) . '</small>' : '';
			$who = ( $s['email'] ? '<b>' . esc_html( $s['email'] ) . '</b>' . ( $s['name'] ? '<br><small>' . esc_html( $s['name'] ) . '</small>' : '' ) : '<small>анонимна</small>' ) . ( $vis ? '<br>' . $vis : '' );
			$ul = ! empty( $s['utm_last'] ) && $s['utm_last'] !== $s['utm'] ? '<br><small title="последен UTM в сесията">→ ' . esc_html( $names[ $s['utm_last'] ] ?? $s['utm_last'] ) . '</small>' : '';
			$from = ( $s['utm_name'] ? '🏷️ ' . esc_html( $s['utm_name'] ) . $ul : '<small>—</small>' ) . ( ! empty( $s['internal'] ) ? '<br><small class="ap-small">вътрешен</small>' : '' );
			$chx = is_array( $s['ch'] ?? null ) ? $s['ch'] : null; $campc = ( $chx ? '<small><b>' . esc_html( $chx['l'] ) . '</b>' . ( '' !== $chx['d'] ? ' · ' . esc_html( $chx['d'] ) : '' ) . '</small><br>' : '' ) . ( ! empty( $s['camp'] ) ? '<small>' . esc_html( $s['camp'] ) . '</small>' : ( $chx ? '' : '<small>директно</small>' ) ) . ( $s['ref'] ? '<br><small>↩ ' . esc_html( $s['ref'] ) . '</small>' : '' );
			$tl = '<div class="ap-tl">' . ( ! empty( $s['nosess'] ) ? ( Phone_Orders::is_phone_sid( $s['sid'] ) ? '<small title="поръчка, направена от оператор по телефона">📞 поръчка по телефон</small> ' : '<small title="поръчка преди 16.10 следобед или от стар promo.js (кеш): пътят до нея не е записан">🧾 само поръчката, сесията не е проследена</small> ' ) : '' ); foreach ( $s['timeline'] as $e ) { $cls = in_array( $e['ev'], array( 'created', 'paid', 'activated' ), true ) ? ' hi' : ( in_array( $e['ev'], array( 'add', 'order' ), true ) ? ' ord' : '' ); $tl .= '<span class="' . $cls . '" title="' . esc_attr( $e['t'] ) . '">' . esc_html( $e['l'] . ( $e['x'] ? ' ' . $e['x'] : '' ) ) . '</span>'; } $tl .= '</div>';
			$onum = '#' . esc_html( $s['order']['number'] ?? '' ); $ord = $s['order'] ? ( self::$portal ? '<b>' . $onum . '</b>' : '<a href="' . esc_url( $s['order']['edit'] ) . '">' . $onum . '</a>' ) . '<br><small>' . esc_html( $s['order']['status'] ) . ' · ' . esc_html( number_format( (float) $s['order']['total'], 2, ',', '' ) ) . ' €</small>' : '<small>' . esc_html( $s['step_label'] ) . '</small>' . ( ! empty( $s['cart'] ) ? '<br><span class="ap-cart" title="изоставена количка във FunnelKit за същия имейл">🛒 количка: ' . esc_html( $s['cart'] ) . '</span>' : '' ); /* 1.28.0: в портала без линк към wp-admin */
			if ( ! $s['order'] && '' !== (string) $s['email'] && ( self::ORDER[ $s['step'] ] ?? 0 ) >= self::ORDER['box'] && empty( $s['nosess'] ) ) { $ord .= '<br>' . Phone_Orders::buttons( 'sess', (int) $s['id'], $s['email'] ); } /* 1.29.0: 📞 обаждане */
			$h .= '<tr data-id="' . (int) $s['id'] . '" data-vis="' . esc_attr( (string) ( $s['visitor'] ?? '' ) ) . '"><td><b>' . esc_html( substr( $s['last'], 11, 5 ) ) . '</b><br><small>' . esc_html( substr( $s['started'], 0, 10 ) ) . ' · преди ' . esc_html( $s['ago'] ) . '</small></td><td>' . $who . '</td><td>' . $from . '</td><td>' . $campc . '</td><td>' . ( 'mobile' === $s['device'] ? '📱' : ( 'desktop' === $s['device'] ? '🖥️' : '—' ) ) . '</td><td>' . ( $s['box'] ? '<b>' . esc_html( $s['box_name'] ) . '</b>' : '<small>—</small>' ) . ( $s['items'] ? '<br><small>' . esc_html( implode( ', ', $s['items'] ) ) . '</small>' : '' ) . '</td><td>' . $tl . '</td><td>' . $ord . '</td></tr>';
		}
		return $h;
	}
}
