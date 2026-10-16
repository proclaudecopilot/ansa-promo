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
 * {prefix}ansa_promo_events   — Insights 2: всяко събитие на сесията (view, gate, box, item, full, order, add, created, paid, activated).
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
  src varchar(64) NOT NULL DEFAULT '',
  med varchar(64) NOT NULL DEFAULT '',
  camp varchar(128) NOT NULL DEFAULT '',
  ref varchar(191) NOT NULL DEFAULT '',
  device varchar(8) NOT NULL DEFAULT '',
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
  KEY order_id (order_id)
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
  PRIMARY KEY  (id),
  KEY sid (sid),
  KEY at (at),
  KEY ev (ev)
) $collate;",
		);
	}

	public static function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::sql() as $sql ) { dbDelta( $sql ); }
		update_option( self::OPT_DB_VER, ANSA_PROMO_DB_VER, false );
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
