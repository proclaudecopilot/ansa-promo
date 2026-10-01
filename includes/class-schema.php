<?php
/**
 * ansa™ Промо — таблици.
 *
 * {prefix}ansa_promo_tickets — един ред на поръчка: код за QR, брой билети, статус.
 * {prefix}ansa_promo_leads   — имейлът от gate-а, UTM, коя кутия е видял, consent, конверсия.
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
		return array( 'tickets' => self::table( 'tickets' ), 'leads' => self::table( 'leads' ) );
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
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY order_id (order_id),
  KEY status (status)
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
  PRIMARY KEY  (id),
  KEY email (email),
  KEY created_at (created_at),
  KEY converted_order_id (converted_order_id)
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
