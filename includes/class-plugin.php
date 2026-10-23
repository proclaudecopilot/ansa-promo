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
		Config::maybe_migrate_wctext();
		Config::maybe_migrate_popup(); /* 1.24.29: сийдовите съставки/за кого/рейтинг/отзиви → празни (еднократно) */
		Config::maybe_migrate_delivered(); /* 1.9.0: „получена“ = само Delivered */
		Catalog::register();
		Frontend::register();
		Leads::register();
		Lead_Sync::register(); /* v1.11.0: лийдовете от имейл-попъпа → FunnelKit лист + таг */
		Cart_Sync::register(); /* v1.25.0: изоставената промо количка → таг на контакта (FKA Cart Abandoned → условие Contact Tags) */
		Cart::register();
		Tickets::register();
		Insights::register();
		Partner::register();
		Tombola::register();
		Book::register(); /* v1.9.0: книгата с рецепти — /kniga */
		Info::register(); /* v1.8.0: {промо}/info */
		Listicle::register(); /* 1.24.20: {промо}/kozmetika — страницата „Комплектът за 1 €“ */
		Merge::register(); /* v1.8.2: merge tags за имейлите (FunnelKit Automations + шорткодове) */
		Probe::register();
		if ( is_admin() ) { Admin::register(); Editor::register(); Products_UI::register(); /* 1.15.0: 📦 Продукти */ }
		add_action( 'init', array( __CLASS__, 'cron' ) );
		add_action( Config::FLIP_HOOK, array( 'AnsaPromo\\Config', 'on_flip' ) ); /* v1.19.0: смяната на седмицата чисти кеша */
		add_action( Config::AFTER_HOOK, array( 'AnsaPromo\\Config', 'after_publish_slow' ) ); /* 1.24.33: purge-овете след публикуване, когато заявката не може да се затвори рано */
		add_action( 'ansa_promo_daily', array( 'AnsaPromo\\Config', 'schedule_flip' ) );
		add_action( 'init', array( 'AnsaPromo\\Seed', 'maybe_seed' ), 30 );
		add_action( 'init', array( 'AnsaPromo\\Config', 'maybe_migrate_yoni' ), 31 ); /* 1.14.0: Yonibiotic → WC #15836 (еднократно, след сийда) */
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

	public static function deactivate() { wp_clear_scheduled_hook( 'ansa_promo_daily' ); wp_clear_scheduled_hook( Config::FLIP_HOOK ); wp_clear_scheduled_hook( Cart_Sync::HOOK ); /* 1.25.0 */ }

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
