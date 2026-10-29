<?php
/**
 * ansa™ Промо — таблици.
 *
 * {prefix}ansa_promo_tickets — един ред на поръчка: код за QR, брой билети, статус.
 * {prefix}ansa_promo_leads   — имейлът от gate-а, UTM, коя кутия е видял, consent, конверсия; DB ver 4 (1.11.0): contact_id,
 *                               fka (резултатът от синхронизацията с FunnelKit: „лист #1 · crm; таг #2 · crm“ / retry: … / no-consent), fka_at.
 * {prefix}ansa_promo_stats   — дневен rollup на фунията (day, step, box, utm, n) — Insights (DB ver 2).
 * {prefix}ansa_promo_sessions — Insights 2 (DB ver 5, 1.17.0): една сесия на посетител (sid от браузъра): кога, откъде (utm/src/med/camp/ref),
 *                               устройство, лийд (имейлът от попъпа), кутия, продуктите в кутията, най-далечната стъпка, поръчката.
 *                               DB ver 6 (1.28.0): utm_last (последният UTM; utm = първият), vid (посетителска бисквитка, 30 дни), internal (вътрешен трафик/бот).
 *                               DB ver 7 (1.34.0): variant ('' = A кутиите, flow = B потокът без кутии) — и в events
 *                               DB ver 8 (1.42.0): landers (кои лендъри е отворила: new,old,sx), lander_at (първото отваряне на лендър), entry_qs/entry_ref
 *                               (адресът и referrer-ът при отварянето на лендъра — „входът към лендъра“), touch_qs/touch_ref (първото докосване на сайта в
 *                               този таб — „външният източник“); Source::of ги чете.
 * {prefix}ansa_promo_events   — Insights 2: всяко събитие на сесията (view, gate, box, item, full, order, add, created, paid, activated).
 *                               DB ver 8: view.k = лендърът (new / old / sx / sub = подстраница); created/paid/activated.k = номерът на поръчката
 *                               (една сесия може да има повече от една поръчка).
 * {prefix}ansa_promo_referrals — препоръките на томболата: кой код (tickets.ref_code) доведе коя поръчка, статус (DB ver 3;
 *                               tickets получава ref_code + bonus).
 *
 * Създават се при активация и при смяна на ANSA_PROMO_DB_VER (ъпдейт през updater-а НЕ
 * пуска activation hook, затова maybe_upgrade() на всеки boot).
 *
 * @package AnsaPromo
 * @since 1.0.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Schema {

	const OPT_DB_VER = 'ansa_promo_db_ver';

	public static function table( $which ) {
		global $wpdb;
		return $wpdb->prefix . 'ansa_promo_' . $which;
	}

	public static function tables() {
		return array( 'tickets' => self::table( 'tickets' ), 'leads' => self::table( 'leads' ), 'stats' => self::table( 'stats' ), 'referrals' => self::table( 'referrals' ), 'sessions' => self::table( 'sessions' ), 'events' => self::table( 'events' ) );
	}

	/** SQL за dbDelta (формат: два интервала преди PRIMARY KEY, KEY на отделен ред). */
	public static function sql() {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$t = self::tables();
		return array(
			"CREATE TABLE {$t['tickets']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  code varchar(16) NOT NULL,
  tickets tinyint(3) unsigned NOT NULL DEFAULT 1,
  box varchar(8) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'issued',
  created_at datetime NOT NULL,
  activated_at datetime NULL,
  portal_user varchar(191) NULL,
  ip varchar(45) NULL,
  ref_code varchar(16) NULL,
  bonus smallint(5) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  UNIQUE KEY ref_code (ref_code),
  KEY order_id (order_id),
  KEY status (status)
) $collate;",
			"CREATE TABLE {$t['referrals']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ref_code varchar(16) NOT NULL,
  referrer_order_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL,
  email varchar(191) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL DEFAULT 'credited',
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY ref_code (ref_code),
  KEY referrer_order_id (referrer_order_id)
) $collate;",
			"CREATE TABLE {$t['leads']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(191) NOT NULL,
  utm varchar(64) NOT NULL DEFAULT '',
  box_seen varchar(8) NOT NULL DEFAULT '',
  consent tinyint(1) NOT NULL DEFAULT 0,
  ip varchar(45) NULL,
  created_at datetime NOT NULL,
  converted_order_id bigint(20) unsigned NULL,
  contact_id bigint(20) unsigned NULL,
  fka varchar(191) NOT NULL DEFAULT '',
  fka_at datetime NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY fka (fka),
  KEY created_at (created_at),
  KEY converted_order_id (converted_order_id)
) $collate;",
			"CREATE TABLE {$t['stats']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  step varchar(16) NOT NULL,
  box varchar(8) NOT NULL DEFAULT '',
  utm varchar(64) NOT NULL DEFAULT '',
  n int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY cell (day,step,box,utm),
  KEY day (day)
) $collate;",
			"CREATE TABLE {$t['sessions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sid varchar(40) NOT NULL,
  started_at datetime NOT NULL,
  last_at datetime NOT NULL,
  utm varchar(64) NOT NULL DEFAULT '',
  utm_last varchar(64) NOT NULL DEFAULT '',
  src varchar(64) NOT NULL DEFAULT '',
  med varchar(64) NOT NULL DEFAULT '',
  camp varchar(128) NOT NULL DEFAULT '',
  ref varchar(191) NOT NULL DEFAULT '',
  device varchar(8) NOT NULL DEFAULT '',
  vid varchar(40) NOT NULL DEFAULT '',
  internal tinyint(1) NOT NULL DEFAULT 0,
  variant varchar(8) NOT NULL DEFAULT '',
  landers varchar(24) NOT NULL DEFAULT '',
  lander_at datetime NULL,
  entry_qs varchar(700) NOT NULL DEFAULT '',
  entry_ref varchar(191) NOT NULL DEFAULT '',
  touch_qs varchar(700) NOT NULL DEFAULT '',
  touch_ref varchar(191) NOT NULL DEFAULT '',
  lead_id bigint(20) unsigned NULL,
  box varchar(8) NOT NULL DEFAULT '',
  items text NULL,
  step varchar(16) NOT NULL DEFAULT 'view',
  order_id bigint(20) unsigned NULL,
  ip varchar(45) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY sid (sid),
  KEY started_at (started_at),
  KEY last_at (last_at),
  KEY step (step),
  KEY order_id (order_id),
  KEY vid (vid),
  KEY internal (internal),
  KEY landers (landers),
  KEY lander_at (lander_at)
) $collate;",
			"CREATE TABLE {$t['events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  sid varchar(40) NOT NULL,
  at datetime NOT NULL,
  ev varchar(16) NOT NULL,
  box varchar(8) NOT NULL DEFAULT '',
  k varchar(64) NOT NULL DEFAULT '',
  n smallint(5) unsigned NOT NULL DEFAULT 0,
  utm varchar(64) NOT NULL DEFAULT '',
  variant varchar(8) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY sid (sid),
  KEY at (at),
  KEY ev (ev),
  KEY ev_k (ev,k)
) $collate;",
		);
	}

	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$was = (int) get_option( self::OPT_DB_VER, 0 );
		foreach ( self::sql() as $sql ) { dbDelta( $sql ); }
		if ( $was > 0 && $was < 8 ) { self::migrate_v8(); }
		if ( $was > 0 && $was < 9 ) { self::migrate_v9(); }
		update_option( self::OPT_DB_VER, ANSA_PROMO_DB_VER, false );
	}

	/**
	 * 1.42.0: старите редове получават новите ключове, за да работи „Резултати на лендъра“ и назад във времето:
	 *  - view.k = лендърът: variant flow → new; variant '' → old, освен сесиите, които са само гледали (step = view) и чийто адрес (ref от runtime
	 *    ajax-а = самата страница) е подстраница (/info, /kozmetika, /tombola, /kniga → sub) или /kozmetichen-set-promo (→ sx). Преди 1.42.0 runtime
	 *    ajax-ът броеше „view“ и на тези страници; евристиката е консервативна — сесия, която е направила още нещо, остава „old“.
	 *  - created/paid/activated.k = номерът на поръчката (от sessions.order_id).
	 *  - sessions.landers / lander_at — от view събитията.
	 * Само MySQL (на живия сайт); грешка не спира ъпдейта.
	 */
	/**
	 * 1.43.1 (DB ver 9): лендърът е адресът, не дизайнът. До смяната (1.37.0, публикувана 28.10 13:43) главният адрес показваше кутиите (variant ''),
	 * а страницата B — потока (flow); 1.42.0 сложи view.k по дизайна и главният адрес „започваше“ от 28.10. Тук view.k се преизчислява по адрес:
	 *  1) по време: преди смяната flow → old (страницата B) и '' → new (главната); след нея flow → new, '' → old. Моментът на смяната се чете от
	 *     данните: първото „view“ с variant flow в сесия, чийто адрес (ref от runtime ajax-а = самата страница) е главният; резерва — 28.10 13:43.
	 *  2) сесиите с известен адрес (ref = главната / B страницата, без подстраница): първото им лендър-view е по този адрес.
	 *  3) sessions.landers / lander_at наново. Моментът се пази в option ansa_promo_lander_swap (екранът го показва).
	 */
	public static function migrate_v9() {
		global $wpdb; $ts = self::table( 'sessions' ); $te = self::table( 'events' );
		$main = 's.ref REGEXP \'ansa\\\\.bg/sezonut-na-ansa/?(\\\\?|$)\''; $b = 's.ref REGEXP \'ansa\\\\.bg/sezonut-na-ansa-b/?(\\\\?|$)\''; /* в SQL литерала \\ → \ → регексът вижда \. и \? */
		try {
			$swap = (string) $wpdb->get_var( "SELECT MIN(e.at) FROM $te e INNER JOIN $ts s ON s.sid = e.sid WHERE e.ev = 'view' AND e.variant = 'flow' AND $main" );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $swap ) || $swap < '2026-10-27 00:00:00' || $swap > '2026-10-30 00:00:00' ) { $swap = '2026-10-28 13:43:00'; }
			update_option( 'ansa_promo_lander_swap', $swap, false );
			$wpdb->query( $wpdb->prepare( "UPDATE $te SET k = CASE WHEN at < %s THEN IF(variant = 'flow', 'old', 'new') ELSE IF(variant = 'flow', 'new', 'old') END WHERE ev = 'view' AND k IN ('new','old')", $swap ) );
			$wpdb->query( "UPDATE $te e INNER JOIN (SELECT sid, MIN(id) AS mid FROM $te WHERE ev = 'view' AND k IN ('new','old') GROUP BY sid) f ON f.sid = e.sid AND f.mid = e.id INNER JOIN $ts s ON s.sid = e.sid SET e.k = CASE WHEN $b THEN 'old' WHEN $main THEN 'new' ELSE e.k END" );
			$wpdb->query( "UPDATE $ts SET landers = '', lander_at = NULL WHERE landers <> '' OR lander_at IS NOT NULL" );
			foreach ( array( 'new', 'old', 'sx' ) as $l ) { $wpdb->query( $wpdb->prepare( "UPDATE $ts s SET s.landers = TRIM(BOTH ',' FROM CONCAT(s.landers, ',', %s)) WHERE EXISTS (SELECT 1 FROM $te e WHERE e.sid = s.sid AND e.ev = 'view' AND e.k = %s)", $l, $l ) ); }
			$wpdb->query( "UPDATE $ts s INNER JOIN (SELECT sid, MIN(at) AS a FROM $te WHERE ev = 'view' AND k IN ('new','old','sx') GROUP BY sid) x ON x.sid = s.sid SET s.lander_at = x.a" );
		} catch ( \Throwable $e ) {}
	}

	public static function migrate_v8() {
		global $wpdb; $ts = self::table( 'sessions' ); $te = self::table( 'events' );
		try {
			$wpdb->query( "UPDATE $te SET k = 'new' WHERE ev = 'view' AND k = '' AND variant = 'flow'" );
			$wpdb->query( "UPDATE $te e INNER JOIN $ts s ON s.sid = e.sid SET e.k = 'sub' WHERE e.ev = 'view' AND e.k = '' AND s.step = 'view' AND (s.ref LIKE '%/sezonut-na-ansa/info%' OR s.ref LIKE '%/sezonut-na-ansa/kozmetika%' OR s.ref LIKE '%/sezonut-na-ansa/tombola%' OR s.ref LIKE '%/kniga%')" );
			$wpdb->query( "UPDATE $te e INNER JOIN $ts s ON s.sid = e.sid SET e.k = 'sx' WHERE e.ev = 'view' AND e.k = '' AND s.step = 'view' AND s.ref LIKE '%/kozmetichen-set-promo%'" );
			$wpdb->query( "UPDATE $te SET k = 'old' WHERE ev = 'view' AND k = ''" );
			$wpdb->query( "UPDATE $te e INNER JOIN $ts s ON s.sid = e.sid SET e.k = s.order_id WHERE e.ev IN ('created','paid','activated') AND e.k = '' AND s.order_id > 0" );
			foreach ( array( 'new', 'old', 'sx' ) as $l ) { $wpdb->query( $wpdb->prepare( "UPDATE $ts s SET s.landers = TRIM(BOTH ',' FROM CONCAT(s.landers, ',', %s)) WHERE s.landers NOT LIKE %s AND EXISTS (SELECT 1 FROM $te e WHERE e.sid = s.sid AND e.ev = 'view' AND e.k = %s)", $l, '%' . $l . '%', $l ) ); }
			$wpdb->query( "UPDATE $ts s INNER JOIN (SELECT sid, MIN(at) AS a FROM $te WHERE ev = 'view' AND k IN ('new','old','sx') GROUP BY sid) x ON x.sid = s.sid SET s.lander_at = x.a WHERE s.lander_at IS NULL" );
		} catch ( \Throwable $e ) {}
	}

	public static function maybe_upgrade() {
		if ( (string) get_option( self::OPT_DB_VER, '' ) === (string) ANSA_PROMO_DB_VER && self::all_exist() ) { return; }
		self::install();
	}

	public static function exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	public static function all_exist() {
		foreach ( self::tables() as $t ) { if ( ! self::exists( $t ) ) return false; }
		return true;
	}

	/** име → съществува ли (за Doctor/снипета). */
	public static function status() {
		$out = array();
		foreach ( self::tables() as $k => $t ) { $out[ $t ] = self::exists( $t ); }
		return $out;
	}
}
