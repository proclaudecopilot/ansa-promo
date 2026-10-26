<?php
/**
 * Plugin Name: ansa™ Meta
 * Plugin URI:  https://ansa.bg
 * Description: Meta Pixel + Conversions API за ansa.bg — всяко събитие и от браузъра, и от сървъра (общ event_id за дедупликация), с максимално пълни данни за съвпадение: имейл, телефон, имена, град, пощенски код, държава, external_id, fbp/fbc, IP, user agent. Purchase тръгва от сървъра при създаването на поръчката, независимо дали браузърът е заредил Pixel-а.
 * Version:     1.0.0
 * GitHub Plugin URI: proclaudecopilot/ansa-meta
 * Author:      ansa.bg
 * Text Domain: ansa-meta
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;
if ( defined( 'ANSA_META_VER' ) ) return; // второ копие (напр. ansa-meta-2/) — първото печели

define( 'ANSA_META_VER', '1.0.0' );
define( 'ANSA_META_DB_VER', '1' );
define( 'ANSA_META_FILE', __FILE__ );
define( 'ANSA_META_PATH', plugin_dir_path( __FILE__ ) );
define( 'ANSA_META_URL', plugin_dir_url( __FILE__ ) );

/* ── Автолоудър: AnsaMeta\Foo_Bar → includes/class-foo-bar.php ── */
spl_autoload_register( function ( $class ) {
	if ( 0 !== strpos( $class, 'AnsaMeta\\' ) ) return;
	$name = substr( $class, 9 );
	$file = ANSA_META_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
	if ( is_file( $file ) ) require_once $file;
} );

/* ── Гарантирано зареждане на новия код след ъпдейт (моделът от ansa™ Промо / Shrine):
   WP „Замени текущия с качения“ не рестартира OPcache; при смяна на версията — reset, еднократно. */
add_action( 'admin_init', function () {
	if ( get_option( 'ansa_meta_ver_seen', '' ) === ANSA_META_VER ) return;
	if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
	if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); }
	update_option( 'ansa_meta_ver_seen', ANSA_META_VER, false );
}, 1 );

/* ── HPOS: пишем order meta само през CRUD-а на WC → съвместими с custom order tables ── */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

register_activation_hook( __FILE__, array( 'AnsaMeta\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AnsaMeta\\Plugin', 'deactivate' ) );

/* ── Boot: WooCommerce е задължителен; без него — само известие ── */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><b>ansa™ Meta:</b> изисква WooCommerce. Плъгинът е зареден, но не прави нищо, докато WooCommerce не е активен.</p></div>';
		} );
		return;
	}
	\AnsaMeta\Plugin::instance()->boot();
}, 6 );
