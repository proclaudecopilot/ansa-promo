<?php
/**
 * Plugin Name: ansa™ Промо
 * Plugin URI:  https://ansa.bg
 * Description: Промо играта на ansa™ (три кутии): страница [ansa_promo], визуален редактор на текстовете, количка и чекаут, дигитални билети. Самостоятелен плъгин — ansa™ Shrine остава за продуктовите страници.
 * Version:     1.0.9
 * GitHub Plugin URI: proclaudecopilot/ansa-promo
 * Author:      ansa.bg
 * Text Domain: ansa-promo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'ANSA_PROMO_VER', '1.0.9' );
/* Същата стойност под второ име: ansa™ Shrine ≥ 6.19.22 проверява точно тази константа
   в includes/promo/bootstrap.php и НЕ зарежда своя (пенсиониран) промо модул, щом я види. */
define( 'ANSA_PROMO_PLUGIN_VER', ANSA_PROMO_VER );
define( 'ANSA_PROMO_DB_VER', '1' );
define( 'ANSA_PROMO_FILE', __FILE__ );
define( 'ANSA_PROMO_PATH', plugin_dir_path( __FILE__ ) );
define( 'ANSA_PROMO_URL', plugin_dir_url( __FILE__ ) );

/* ── Автолоудър: AnsaPromo\Foo_Bar → includes/class-foo-bar.php ──────────────
   Собствен namespace, за да няма фатал с класовете Ansa_Promo_* на Shrine,
   ако двата модула някога се заредят едновременно (стар Shrine без guard). */
spl_autoload_register( function ( $class ) {
	if ( 0 !== strpos( $class, 'AnsaPromo\\' ) ) return;
	$parts = explode( '\\', substr( $class, 10 ) );
	$name  = array_pop( $parts );
	$dir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
	$file  = ANSA_PROMO_PATH . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
	if ( is_file( $file ) ) require_once $file;
} );

/* ── ГАРАНТИРАНО зареждане на новия код след ъпдейт (модел от Shrine v6.12) ──
   WP „Замени текущия с качения" НЕ рестартира OPcache. При хостинг с
   opcache.validate_timestamps=0 сървърът върти СТАРИЯ PHP до безкрай.
   При засечена смяна на версията: OPcache reset + object cache flush, еднократно. */
add_action( 'admin_init', function () {
	$seen = get_option( 'ansa_promo_ver_seen', '' );
	if ( $seen === ANSA_PROMO_VER ) return;
	if ( function_exists( 'opcache_reset' ) ) {
		@opcache_reset();
	}
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
	update_option( 'ansa_promo_ver_seen', ANSA_PROMO_VER, false );
}, 1 );

/* ── HPOS: плоски order meta ключове, съвместими с custom order tables ── */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

register_activation_hook( __FILE__, array( 'AnsaPromo\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AnsaPromo\\Plugin', 'deactivate' ) );

/* ── Boot: WooCommerce е задължителен; без него — само известие, нищо друго не се закача ── */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><b>ansa™ Промо:</b> изисква WooCommerce. Плъгинът е зареден, но не прави нищо, докато WooCommerce не е активен.</p></div>';
		} );
		return;
	}
	\AnsaPromo\Plugin::instance()->boot();
}, 5 );

/* LOGADOR GitHub Updater — общ за всички плъгини на LOGADOR: копира се в wp-content/mu-plugins, ако липсва или е по-стар. */
add_action( 'admin_init', function () {
	if ( ! current_user_can( 'activate_plugins' ) ) return;
	$src = plugin_dir_path( __FILE__ ) . 'includes/mu/logador-github-updater.php';
	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$dst = $dir . '/logador-github-updater.php';
	if ( ! is_file( $src ) ) return;
	$want = preg_match( '/\*\s*Version:\s*([0-9.]+)/', (string) file_get_contents( $src ), $m ) ? $m[1] : '0';
	$have = is_file( $dst ) && preg_match( '/\*\s*Version:\s*([0-9.]+)/', (string) file_get_contents( $dst ), $m2 ) ? $m2[1] : '0';
	if ( is_file( $dst ) && version_compare( $have, $want, '>=' ) ) return;
	if ( ! is_dir( $dir ) ) @wp_mkdir_p( $dir );
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) || ! @copy( $src, $dst ) ) {
		add_action( 'admin_notices', function () use ( $src, $dir ) {
			echo '<div class="notice notice-warning"><p><b>ansa™ Промо:</b> не мога да запиша <code>logador-github-updater.php</code> в <code>' . esc_html( $dir ) . '</code> (права). Копирай го ръчно от <code>' . esc_html( $src ) . '</code>.</p></div>';
		} );
		return;
	}
	add_action( 'admin_notices', function () use ( $want ) {
		echo '<div class="notice notice-success is-dismissible"><p><b>LOGADOR GitHub Updater ' . esc_html( $want ) . '</b> е инсталиран. Token-ът се слага веднъж в <a href="' . esc_url( admin_url( 'options-general.php?page=logador-github' ) ) . '">Settings → GitHub ъпдейти</a>.</p></div>';
	} );
} );
