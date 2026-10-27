<?php
/**
 * ansa™ Meta — таблиците: опашка за повторно изпращане и дневник на изпратеното.
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Schema {
	const OPT_DB_VER = 'ansa_meta_db_ver';

	public static function queue_table() { global $wpdb; return $wpdb->prefix . 'ansa_meta_queue'; }
	public static function log_table()   { global $wpdb; return $wpdb->prefix . 'ansa_meta_log'; }

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$q = self::queue_table(); $l = self::log_table();
		dbDelta( "CREATE TABLE $q (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_name varchar(64) NOT NULL DEFAULT '',
  event_id varchar(64) NOT NULL DEFAULT '',
  source varchar(16) NOT NULL DEFAULT '',
  payload longtext NOT NULL,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_at datetime NOT NULL,
  created_at datetime NOT NULL,
  last_error varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY next_at (next_at)
) $charset;" );
		dbDelta( "CREATE TABLE $l (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  at datetime NOT NULL,
  event_name varchar(64) NOT NULL DEFAULT '',
  event_id varchar(64) NOT NULL DEFAULT '',
  source varchar(16) NOT NULL DEFAULT '',
  ok tinyint(1) NOT NULL DEFAULT 0,
  http smallint(5) unsigned NOT NULL DEFAULT 0,
  ms smallint(5) unsigned NOT NULL DEFAULT 0,
  response varchar(500) NOT NULL DEFAULT '',
  payload mediumtext NULL,
  PRIMARY KEY  (id),
  KEY at (at),
  KEY event_name (event_name)
) $charset;" );
		update_option( self::OPT_DB_VER, ANSA_META_DB_VER, false );
	}

	public static function maybe_upgrade() {
		if ( (string) get_option( self::OPT_DB_VER, '' ) !== ANSA_META_DB_VER ) { self::install(); }
	}

	public static function status() {
		global $wpdb; $out = array();
		foreach ( array( self::queue_table(), self::log_table() ) as $t ) { $out[ $t ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ); }
		return $out;
	}

	/** Пази последните $keep реда в дневника; опашката се чисти от редове по-стари от 3 дни. */
	public static function purge( $keep ) {
		global $wpdb; $keep = max( 50, (int) $keep );
		$l = self::log_table(); $q = self::queue_table();
		$max = (int) $wpdb->get_var( "SELECT MAX(id) FROM $l" );
		if ( $max > $keep ) { $wpdb->query( $wpdb->prepare( "DELETE FROM $l WHERE id <= %d", $max - $keep ) ); }
		$wpdb->query( $wpdb->prepare( "DELETE FROM $q WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
	}

	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::queue_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::log_table() );
		delete_option( self::OPT_DB_VER );
	}
}
