<?php
/**
 * ansa™ Промо — пазач на „Получена“ (1.50.0).
 *
 * Курирската интеграция (BigArena → WC REST API, ключ „BigArena Latest“) праща „В офис за получаване“ и „Delivered“
 * в ЕДНА И СЪЩА секунда, когато клиентът вземе пратката бързо (двете събития идват от един poll на Speedy). Двете заявки
 * зареждат поръчката едновременно и записват статуса една след друга — „В офис“ понякога презаписва „Получена“
 * (бележките: „Shipped → В офис“ + „Shipped → Delivered“ в една секунда; при #107643 буквално „Delivered → В офис“).
 * Поръчката остава at-office, макар да е доставена: томболата/ваучерът/книгата/комисионите не тръгват, статистиката лъже.
 * 10.11.2026: 187 такива поръчки от 09.2024 насам, 50 от началото на сезона (напр. #106576, #107643, #107005).
 *
 * (1) before_save: запис, който праща поръчка от „Получена“ (в базата, не в object cache-а) към shipped/at-office, се спира —
 *     статусът остава delivered, преходът в паметта (бележка „→ В офис“, имейл „At office“, hook-овете) не се пуска, бележка
 *     обяснява. Ръчна смяна от админа (woocommerce_order_edit_status) минава.
 * (2) sweep (на час + еднократно 2 мин след ъпдейта): поръчки в shipped/at-office с бележка „… на Delivered.“ (и без ръчна
 *     смяна след нея) се връщат на „Получена“ — до WINDOW след бележката с нормален преход (билет/ваучер/книга/комисиона
 *     тръгват; hook-овете са идемпотентни), по-старите — тих запис (само статусът + бележка, без имейли/автомации след месеци).
 *
 * @package AnsaPromo
 * @since 1.50.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Status_Guard {

	const FINAL         = 'delivered';
	const REPAIR_HOOK   = 'ansa_promo_delivered_repair';
	const OPT_REPAIR    = 'ansa_promo_delivered_repair_v1'; /* еднократната поправка след ъпдейта е минала */
	const OPT_LAST      = 'ansa_promo_status_guard_last';   /* последният крон на час, който е върнал нещо */
	const OPT_LOG       = 'ansa_promo_status_guard_log';    /* последните 50 намеси (guard / sweep) */
	const WINDOW        = 60 * DAY_IN_SECONDS;              /* до толкова след бележката „получена“ → нормален преход */
	const SYSTEM_AUTHOR = 'WooCommerce';                    /* авторът на автоматичните бележки на WC (REST, крон); човек → името му */

	private static $manual = array();

	public static function register() {
		add_action( 'woocommerce_order_edit_status', array( __CLASS__, 'on_manual' ), 10, 2 );
		add_action( 'woocommerce_before_order_object_save', array( __CLASS__, 'before_save' ), 1 );
		add_action( self::REPAIR_HOOK, array( __CLASS__, 'cron_repair_once' ) );
		add_action( Insights_Reports::HOOK, array( __CLASS__, 'cron_sweep' ) ); /* ansa_promo_hourly */
	}

	/* ── сметката ── */

	/** Курирските междинни статуси, от които „Получена“ не може да бъде върната назад автоматично. */
	public static function downgrades() {
		$d = apply_filters( 'ansa_promo_status_guard_from', array( 'shipped', 'at-office' ) );
		return array_values( array_filter( array_map( array( __CLASS__, 'bare' ), (array) $d ) ) );
	}
	public static function bare( $s ) { return preg_replace( '/^wc-/', '', (string) $s ); }
	public static function name( $status ) { return function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( self::bare( $status ) ) : self::bare( $status ); }
	public static function on_manual( $order_id, $status = '' ) { self::$manual[ (int) $order_id ] = true; }
	public static function is_manual( $order_id ) { return ! empty( self::$manual[ (int) $order_id ] ); }

	/** Базата казва „Получена“, обектът иска междинен курирски статус, не е ръчно → остава „Получена“. */
	public static function should_keep( $db_status, $new_status, $manual = false ) {
		$db = self::bare( $db_status ); $new = self::bare( $new_status );
		return ! $manual && self::FINAL === $db && self::FINAL !== $new && in_array( $new, self::downgrades(), true );
	}

	/** Бележката „получена“ е отскоро → нормален преход при връщането; иначе тих запис. */
	public static function recent( $ts, $now = null ) {
		return ( ( $now ?: time() ) - (int) $ts ) <= (int) apply_filters( 'ansa_promo_status_guard_window', self::WINDOW );
	}

	/** Шаблоните на бележката на WC за прехода към „Получена“ („… от X на Delivered.“ / „… from X to Delivered.“) — по етикета сега и буквално „Delivered“. */
	public static function note_patterns( $name = null ) {
		$names = array_values( array_unique( array_filter( array( (string) ( null === $name ? self::name( self::FINAL ) : $name ), 'Delivered' ) ) ) ); $p = array();
		foreach ( $names as $n ) { $p[] = '% на ' . $n . '.'; $p[] = '% to ' . $n . '.'; }
		return $p;
	}

	/* ── (1) пазачът при запис ── */

	/** Статусът от базата (не от object cache-а — другата заявка може да е писала току-що). */
	public static function db_status( $order_id ) {
		global $wpdb; $order_id = (int) $order_id; if ( $order_id <= 0 ) { return ''; }
		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$sql  = $hpos ? "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d" : "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d";
		return self::bare( (string) $wpdb->get_var( $wpdb->prepare( $sql, $order_id ) ) );
	}

	public static function before_save( $order ) {
		if ( ! $order instanceof \WC_Order || $order->get_id() <= 0 ) { return; }
		$new = self::bare( $order->get_status( 'edit' ) );
		if ( ! in_array( $new, self::downgrades(), true ) ) { return; } /* евтината проверка преди заявката към базата */
		$db = self::db_status( $order->get_id() );
		if ( ! self::should_keep( $db, $new, self::is_manual( $order->get_id() ) ) ) { return; }
		$order->set_status( self::FINAL );
		self::drop_transition( $order );
		$order->add_order_note( 'ansa™ Промо: статусът остава „' . self::name( self::FINAL ) . '“ — заявка за „' . self::name( $new ) . '“ дойде, след като поръчката вече е получена (курирските статуси пристигат едновременно), и беше спряна.' );
		self::log( array( 'at' => time(), 'order' => (int) $order->get_id(), 'how' => 'guard', 'from' => $new ) );
	}

	/** Преходът в паметта (бележка „→ В офис“, имейл „At office“, hook-овете) не се пуска — поръчката НЕ е сменяла статус. */
	private static function drop_transition( $order ) {
		if ( ! property_exists( $order, 'status_transition' ) ) { return false; }
		try { \Closure::bind( function () { $this->status_transition = false; }, $order, \WC_Order::class )(); return true; } catch ( \Throwable $e ) { return false; }
	}

	/* ── (2) заседналите ── */

	/**
	 * Поръчки в shipped/at-office с бележка за преход към „Получена“: [order_id => ['note_id' => …, 'ts' => unix на бележката]], най-новите първи.
	 * @param int   $limit
	 * @param int[] $only само тези поръчки (probe) — иначе всички в междинен статус.
	 */
	public static function stuck( $limit = 500, $only = array() ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return array(); }
		global $wpdb;
		$ids = $only ? array_map( 'intval', (array) $only ) : (array) wc_get_orders( array( 'status' => array_map( function ( $s ) { return 'wc-' . $s; }, self::downgrades() ), 'limit' => -1, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'DESC' ) );
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) ); if ( ! $ids ) { return array(); }
		$pat = self::note_patterns(); $like = implode( ' OR ', array_fill( 0, count( $pat ), 'comment_content LIKE %s' ) );
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$sql = "SELECT comment_post_ID AS oid, MAX(comment_ID) AS cid, MAX(comment_date_gmt) AS d FROM {$wpdb->comments} WHERE comment_type = 'order_note' AND comment_post_ID IN (" . implode( ',', $chunk ) . ") AND ($like) GROUP BY comment_post_ID";
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $pat ), ARRAY_A ) as $r ) { $out[ (int) $r['oid'] ] = array( 'note_id' => (int) $r['cid'], 'ts' => (int) strtotime( $r['d'] . ' UTC' ) ); }
		}
		krsort( $out );
		return array_slice( $out, 0, $limit, true );
	}

	/** След бележката „получена“ човек (не „WooCommerce“) е сложил ръчно текущия статус → решението е негово, не пипаме. */
	public static function manual_after( $order_id, $note_id, $status ) {
		global $wpdb; $pat = self::note_patterns( self::name( $status ) ); $like = implode( ' OR ', array_fill( 0, count( $pat ), 'comment_content LIKE %s' ) );
		$sql = "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'order_note' AND comment_post_ID = %d AND comment_ID > %d AND comment_author <> %s AND ($like)";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( (int) $order_id, (int) $note_id, self::SYSTEM_AUTHOR ), $pat ) ) ) > 0;
	}

	/** Връща заседналите на „Получена“. До WINDOW след бележката — нормален преход; по-старите — тих запис. → брой full / silent / skip + ids. */
	public static function repair( $limit = 200, $now = null, $only = array() ) {
		$r = array( 'full' => 0, 'silent' => 0, 'skip' => 0, 'ids' => array() ); $now = $now ?: time();
		foreach ( self::stuck( $limit, $only ) as $oid => $row ) {
			$o = wc_get_order( $oid ); if ( ! $o ) { $r['skip']++; continue; }
			$cur = self::bare( $o->get_status() );
			if ( ! in_array( $cur, self::downgrades(), true ) || self::manual_after( $oid, $row['note_id'], $cur ) ) { $r['skip']++; continue; }
			$why = 'ansa™ Промо: поръчката е върната на „' . self::name( self::FINAL ) . '“ — курирската интеграция прати „' . self::name( $cur ) . '“ и „' . self::name( self::FINAL ) . '“ едновременно и „' . self::name( $cur ) . '“ презаписа (бележката за получаването е от ' . wp_date( 'd.m.Y H:i', $row['ts'] ) . ').';
			if ( self::recent( $row['ts'], $now ) ) { $o->update_status( self::FINAL, $why ); $how = 'full'; }
			else { self::silent( $o, $why ); $how = 'silent'; }
			$r[ $how ]++; $r['ids'][] = $oid; self::log( array( 'at' => time(), 'order' => $oid, 'how' => $how, 'from' => $cur ) );
		}
		return $r;
	}

	/** Само статусът + бележка, без преход (hook-ове, имейли, автомации): всичко по „получена“ е минало още тогава, а поръчката е стара. */
	private static function silent( $o, $why ) {
		$o->set_status( self::FINAL ); self::drop_transition( $o ); $o->save();
		$o->add_order_note( $why . ' Без имейли и автомации — бележката е отпреди повече от ' . (int) round( (int) apply_filters( 'ansa_promo_status_guard_window', self::WINDOW ) / DAY_IN_SECONDS ) . ' дни.' );
		if ( $o->get_meta( '_ansa_promo_id' ) && class_exists( __NAMESPACE__ . '\\Tickets' ) ) { try { Tickets::on_delivered( $o->get_id() ); } catch ( \Throwable $e ) {} } /* билет/ваучер/книга, ако още не са (guard-овете им) */
	}

	/* ── крон ── */

	public static function cron_repair_once() {
		$r = self::repair( 1000 );
		update_option( self::OPT_REPAIR, array( 'at' => current_time( 'mysql' ), 'ver' => ANSA_PROMO_VER ) + $r, false );
	}
	public static function cron_sweep() {
		$r = self::repair( 200 );
		if ( $r['full'] || $r['silent'] ) { update_option( self::OPT_LAST, array( 'at' => current_time( 'mysql' ) ) + $r, false ); }
	}
	public static function log( $row ) {
		$log = (array) get_option( self::OPT_LOG, array() ); array_unshift( $log, $row );
		update_option( self::OPT_LOG, array_slice( $log, 0, 50 ), false );
	}

	/** Ред за Doctor: [текст, ниво]. */
	public static function status() {
		$stuck = self::stuck( 50 ); $rp = get_option( self::OPT_REPAIR ); $last = get_option( self::OPT_LAST );
		$p = array();
		$p[] = $stuck ? count( $stuck ) . ' поръчки стоят „в офис/изпратена“ след бележка за получаване (кронът на час ги връща): #' . implode( ', #', array_slice( array_keys( $stuck ), 0, 10 ) ) : 'няма заседнали „в офис/изпратена“ след получаване';
		$p[] = is_array( $rp ) ? 'еднократната поправка: ' . $rp['at'] . ' — ' . (int) $rp['full'] . ' с преход, ' . (int) $rp['silent'] . ' тихо, ' . (int) $rp['skip'] . ' пропуснати' : 'еднократната поправка след ъпдейта още не е минала (2 мин, wp-cron)';
		if ( is_array( $last ) ) { $p[] = 'последно връщане от крона: ' . $last['at'] . ' (' . ( (int) $last['full'] + (int) $last['silent'] ) . ')'; }
		return array( implode( ' · ', $p ), $stuck ? 'warn' : 'ok' );
	}
}
