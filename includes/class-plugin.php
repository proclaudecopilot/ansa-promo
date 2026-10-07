<?php
/**
 * ansa™ Промо — boot на плъгина.
 *
 * Фаза 0: таблици, админ екран, shortcode. Фаза 1: конфиг, копи-регистър, каталог, фронт (порт v73), leads, runtime ajax.
 *
 * @package AnsaPromo
 * @since 1.0.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Публичните имена са СЪЩИТЕ като в пенсионирания модул на Shrine — съществуваща чернова мигрира без нищо. */
	const OPTION    = 'ansa_promo_store';
	const SHORTCODE = 'ansa_promo';
	const CAP       = 'manage_woocommerce';
	const MENU_SLUG = 'ansa-promo';

	private static $inst = null;

	public static function instance() {
		if ( null === self::$inst ) { self::$inst = new self(); }
		return self::$inst;
	}

	private function __construct() {}

	/** Извиква се на plugins_loaded (5), само при наличен WooCommerce. */
	public function boot() {
		Schema::maybe_upgrade();
		Config::maybe_migrate();
		Config::maybe_migrate_name();
		Config::maybe_migrate_sku();
		Config::maybe_migrate_cat();
		Config::maybe_migrate_pct();
		Catalog::register();
		Frontend::register();
		Leads::register();
		if ( is_admin() ) { Admin::register(); }
		add_action( 'init', array( __CLASS__, 'cron' ) );
		add_action( 'init', array( 'AnsaPromo\\Seed', 'maybe_seed' ), 30 );
	}

	public static function cron() {
		if ( ! wp_next_scheduled( 'ansa_promo_daily' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ansa_promo_daily' ); }
	}

	/** register_activation_hook — таблиците + маркер кога е активиран. */
	public static function activate() {
		Schema::install();
		if ( ! get_option( 'ansa_promo_activated_at' ) ) {
			add_option( 'ansa_promo_activated_at', current_time( 'mysql' ), '', false );
		}
	}

	public static function deactivate() { wp_clear_scheduled_hook( 'ansa_promo_daily' ); }

	/* ── Shrine (съжителство) ───────────────────────────────────── */

	public static function shrine_version() {
		return defined( 'ANSA_SHRINE_VER' ) ? (string) ANSA_SHRINE_VER : '';
	}

	/** true = Shrine е заредил пенсионирания си промо модул (Shrine < 6.19.22 или guard-ът не е сработил). */
	public static function shrine_promo_module_loaded() {
		return class_exists( 'Ansa_Promo_Frontend', false ) || class_exists( 'Ansa_Promo_Config', false );
	}

	/** Кой обработва [ansa_promo] в момента — за Doctor/снипета. */
	public static function shortcode_owner() {
		global $shortcode_tags;
		if ( empty( $shortcode_tags[ self::SHORTCODE ] ) ) { return ''; }
		$cb = $shortcode_tags[ self::SHORTCODE ];
		if ( is_array( $cb ) ) { return ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) . '::' . $cb[1]; }
		if ( is_string( $cb ) ) { return $cb; }
		return is_object( $cb ) ? get_class( $cb ) : 'callable';
	}
}
