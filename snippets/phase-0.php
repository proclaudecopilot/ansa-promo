<?php
/**
 * ansa™ Промо · Snippet 0 — отчет за средата (Фаза 0).
 *
 * Code Snippets → Add New → постави всичко БЕЗ първия ред „<?php“ → Save & Activate (Run everywhere или Admin only).
 * Отчетът излиза на две места:
 *   1) /wp-admin/ (Табло, Плъгини, екран „ansa Промо“) — известие с поле; кликни в полето → Ctrl+A/Ctrl+C → прати ми го.
 *   2) /wp-admin/?ansa_promo_report=1 — същият отчет като чист текст.
 * След като го пратиш — деактивирай снипета. Не променя нищо на сайта (само чете; една заявка към GitHub през updater-а).
 */

add_action( 'admin_init', function () {
	if ( ! isset( $_GET['ansa_promo_report'] ) || ! current_user_can( 'manage_options' ) ) return;
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo ansa_promo_snippet0_report();
	exit;
} );

add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) return;
	$s = get_current_screen();
	if ( ! $s || ! in_array( $s->id, array( 'dashboard', 'plugins', 'toplevel_page_ansa-promo' ), true ) ) return;
	echo '<div class="notice notice-info"><p><b>ansa™ Промо · Snippet 0</b> — копирай всичко от полето и го прати в чата (после деактивирай снипета):</p>'
		. '<textarea readonly onclick="this.select()" style="width:100%;height:340px;font:12px/1.4 Menlo,Consolas,monospace;white-space:pre">' . esc_textarea( ansa_promo_snippet0_report() ) . '</textarea></div>';
} );

if ( ! function_exists( 'ansa_promo_snippet0_report' ) ) {
function ansa_promo_snippet0_report() {
	global $wpdb, $wp_version;
	static $cache = null; if ( null !== $cache ) return $cache;
	$L = array();
	$p = function ( $k, $v ) use ( &$L ) { $L[] = str_pad( $k, 34 ) . ' ' . ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v ); };
	$h = function ( $t ) use ( &$L ) { $L[] = ''; $L[] = '── ' . $t . ' ' . str_repeat( '─', max( 0, 60 - mb_strlen( $t ) ) ); };
	$yn = function ( $b ) { return $b ? 'yes' : 'no'; };
	if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$L[] = '=== ansa™ Промо · Snippet 0 · ' . current_time( 'Y-m-d H:i:s' ) . ' (' . wp_timezone_string() . ') ===';

	$h( 'Сайт' );
	$p( 'home / site', home_url( '/' ) . ' / ' . site_url( '/' ) );
	$p( 'WP / PHP / MySQL', $wp_version . ' / ' . PHP_VERSION . ' / ' . $wpdb->db_version() );
	$p( 'locale / multisite / WP_DEBUG', get_locale() . ' / ' . $yn( is_multisite() ) . ' / ' . $yn( defined( 'WP_DEBUG' ) && WP_DEBUG ) );
	$p( 'memory_limit / WP_MEMORY_LIMIT', ini_get( 'memory_limit' ) . ' / ' . ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '—' ) );
	$p( 'opcache.validate_timestamps', function_exists( 'opcache_get_configuration' ) ? ( ( @opcache_get_configuration()['directives']['opcache.validate_timestamps'] ?? '?' ) ? '1' : '0' ) : 'no opcache' );
	$th = wp_get_theme(); $p( 'theme', $th->get( 'Name' ) . ' ' . $th->get( 'Version' ) . ( $th->parent() ? ' (child of ' . $th->parent()->get( 'Name' ) . ')' : '' ) );
	$p( 'object cache (external)', $yn( wp_using_ext_object_cache() ) );
	$p( 'page builders', implode( ', ', array_filter( array( defined( 'ELEMENTOR_VERSION' ) ? 'Elementor ' . ELEMENTOR_VERSION : '', defined( 'CT_VERSION' ) ? 'Oxygen ' . CT_VERSION : '', defined( 'BRICKS_VERSION' ) ? 'Bricks ' . BRICKS_VERSION : '', function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ? 'block theme' : '' ) ) ) ?: '—' );

	$h( 'WooCommerce' );
	if ( ! defined( 'WC_VERSION' ) ) { $p( 'WooCommerce', 'ЛИПСВА' ); }
	else {
		$p( 'version', WC_VERSION );
		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$p( 'HPOS', $hpos ? 'ON' : 'OFF' );
		$p( 'HPOS sync (compat mode)', get_option( 'woocommerce_custom_orders_table_data_sync_enabled', '—' ) );
		$p( 'currency / decimals / pos', get_woocommerce_currency() . ' / ' . get_option( 'woocommerce_price_num_decimals' ) . ' / ' . get_option( 'woocommerce_currency_pos' ) . ' / sep „' . get_option( 'woocommerce_price_decimal_sep' ) . '“' );
		$p( 'checkout page id / url', wc_get_page_id( 'checkout' ) . ' / ' . wc_get_checkout_url() );
		$p( 'cart page id', wc_get_page_id( 'cart' ) );
		$p( 'tax enabled / prices incl. tax', $yn( wc_tax_enabled() ) . ' / ' . $yn( wc_prices_include_tax() ) );
		$p( 'coupons enabled', get_option( 'woocommerce_enable_coupons', '—' ) );
		$p( 'Action Scheduler', class_exists( 'ActionScheduler' ) ? 'yes' : 'no' );
		$p( 'WC Blocks checkout', $yn( function_exists( 'has_block' ) && wc_get_page_id( 'checkout' ) > 0 && has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) ) ) );
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) $p( 'gateway ' . $id, ( 'yes' === $gw->enabled ? 'ENABLED' : 'off' ) . ' · ' . wp_strip_all_tags( $gw->get_title() ) . ' · ' . get_class( $gw ) );
		}
		$zones = class_exists( 'WC_Shipping_Zones' ) ? \WC_Shipping_Zones::get_zones() : array();
		foreach ( $zones as $z ) { $m = array(); foreach ( $z['shipping_methods'] as $sm ) $m[] = $sm->id . ':' . $sm->get_instance_id() . ( 'yes' === $sm->enabled ? '' : '(off)' ) . ' ' . wp_strip_all_tags( $sm->get_title() ); $p( 'shipping zone „' . $z['zone_name'] . '“', implode( ' | ', $m ) ?: '—' ); }
	}

	$h( 'FunnelKit' );
	$p( 'Checkout (WFACP_Core)', class_exists( 'WFACP_Core' ) ? 'yes · ' . ( defined( 'WFACP_VERSION' ) ? WFACP_VERSION : '?' ) : 'no' );
	$p( 'Funnel Builder (WFFN_Core)', class_exists( 'WFFN_Core' ) ? 'yes · ' . ( defined( 'WFFN_VERSION' ) ? WFFN_VERSION : '?' ) : 'no' );
	$p( 'Cart (FKCart)', defined( 'FKCART_VERSION' ) ? FKCART_VERSION : 'no' );
	$opts = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%wfacp%global%' OR option_name LIKE '%wffn%global%' OR option_name LIKE 'wfacp_%checkout%' ORDER BY option_name LIMIT 12" );
	foreach ( (array) $opts as $o ) $p( 'opt ' . $o->option_name, mb_substr( preg_replace( '/\s+/', ' ', (string) $o->option_value ), 0, 140 ) );
	$fk = $wpdb->get_results( "SELECT ID, post_title, post_status FROM {$wpdb->posts} WHERE post_type IN ('wfacp_checkout','wffn_landing','wffn_ty','wffn_oty') AND post_status='publish' ORDER BY ID DESC LIMIT 10" );
	foreach ( (array) $fk as $f ) $p( 'FunnelKit post #' . $f->ID, get_post_type( $f->ID ) . ' · ' . $f->post_title );

	$h( 'Кеш' );
	$c = array();
	if ( class_exists( '\\FlyingPress\\Purge' ) ) $c[] = 'FlyingPress';
	if ( defined( 'WP_ROCKET_VERSION' ) ) $c[] = 'WP Rocket ' . WP_ROCKET_VERSION;
	if ( defined( 'LSCWP_V' ) ) $c[] = 'LiteSpeed ' . LSCWP_V;
	if ( defined( 'W3TC' ) ) $c[] = 'W3TC';
	if ( defined( 'WPCACHEHOME' ) ) $c[] = 'WP Super Cache';
	if ( defined( 'CLOUDFLARE_PLUGIN_DIR' ) || class_exists( 'CF\\WordPress\\Hooks' ) ) $c[] = 'Cloudflare plugin';
	if ( isset( $_SERVER['HTTP_CF_RAY'] ) ) $c[] = 'behind Cloudflare';
	$p( 'засечено', $c ? implode( ', ', $c ) : '—' );

	$h( 'Плъгини (активни)' );
	$all = get_plugins();
	foreach ( (array) get_option( 'active_plugins', array() ) as $file ) $p( $file, ( $all[ $file ]['Name'] ?? '?' ) . ' ' . ( $all[ $file ]['Version'] ?? '' ) );
	$mu = get_mu_plugins(); foreach ( $mu as $file => $d ) $p( 'mu: ' . $file, $d['Name'] . ' ' . $d['Version'] );

	$h( 'ansa™ Shrine' );
	$p( 'ANSA_SHRINE_VER', defined( 'ANSA_SHRINE_VER' ) ? ANSA_SHRINE_VER : 'не е активен' );
	$bs = defined( 'ANSA_SHRINE_PATH' ) ? ANSA_SHRINE_PATH . 'includes/promo/bootstrap.php' : '';
	$p( 'promo/bootstrap.php има guard', $bs && is_file( $bs ) ? $yn( false !== strpos( (string) file_get_contents( $bs ), 'ANSA_PROMO_PLUGIN_VER' ) ) : 'файлът липсва' );
	$p( 'Shrine промо модул ЗАРЕДЕН', $yn( class_exists( 'Ansa_Promo_Frontend', false ) || class_exists( 'Ansa_Promo_Config', false ) || class_exists( 'Ansa_Promo_Cart', false ) ) );
	$p( 'Shrine класове', implode( ', ', array_filter( array( class_exists( 'Ansa_Shrine_Cart_Gift', false ) ? 'Cart_Gift' : '', class_exists( 'Ansa_Shrine_Box_Pricing', false ) ? 'Box_Pricing' : '', class_exists( 'Ansa_Shrine_Offers', false ) ? 'Offers' : '', function_exists( 'ansa_shrine_purge_product' ) ? 'purge_product()' : '' ) ) ) ?: '—' );
	$p( 'Shrine promo ajax закачен', $yn( has_action( 'wp_ajax_nopriv_ansa_promo_add' ) ) );

	$h( 'ansa™ Промо (плъгин)' );
	$p( 'активен (ansa-promo/ansa-promo.php)', $yn( is_plugin_active( 'ansa-promo/ansa-promo.php' ) ) );
	$p( 'header Version', isset( $all['ansa-promo/ansa-promo.php'] ) ? $all['ansa-promo/ansa-promo.php']['Version'] . ' · GitHub Plugin URI=' . ( $all['ansa-promo/ansa-promo.php']['GitHub Plugin URI'] ?? '—' ) : 'плъгинът не е инсталиран' );
	$p( 'ANSA_PROMO_VER / _PLUGIN_VER', ( defined( 'ANSA_PROMO_VER' ) ? ANSA_PROMO_VER : '—' ) . ' / ' . ( defined( 'ANSA_PROMO_PLUGIN_VER' ) ? ANSA_PROMO_PLUGIN_VER : '—' ) );
	$p( 'AnsaPromo\\Plugin зареден', $yn( class_exists( 'AnsaPromo\\Plugin' ) ) );
	$p( 'AnsaPromo\\Schema / Frontend / Admin', $yn( class_exists( 'AnsaPromo\\Schema' ) ) . ' / ' . $yn( class_exists( 'AnsaPromo\\Frontend' ) ) . ' / ' . $yn( class_exists( 'AnsaPromo\\Admin' ) ) );
	global $shortcode_tags; $cb = $shortcode_tags['ansa_promo'] ?? null;
	$p( 'shortcode [ansa_promo] →', $cb ? ( is_array( $cb ) ? ( is_object( $cb[0] ) ? get_class( $cb[0] ) : $cb[0] ) . '::' . $cb[1] : ( is_string( $cb ) ? $cb : get_class( $cb ) ) ) : 'НЕ Е РЕГИСТРИРАН' );
	$out = $cb ? do_shortcode( '[ansa_promo]' ) : '';
	$p( 'do_shortcode изход', $out ? mb_substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $out ) ), 0, 90 ) . ' · root div=' . $yn( false !== strpos( $out, 'id="ansaPromo"' ) ) : '—' );
	foreach ( array( 'ansa_promo_db_ver', 'ansa_promo_ver_seen', 'ansa_promo_activated_at' ) as $o ) $p( 'option ' . $o, get_option( $o, '—' ) );
	$store = get_option( 'ansa_promo_store' );
	if ( is_array( $store ) ) {
		$p( 'option ansa_promo_store', 'има · keys: ' . implode( ', ', array_keys( $store ) ) . ' · version=' . ( $store['version'] ?? '—' ) . ' · published_at=' . ( $store['published_at'] ?? '—' ) );
		foreach ( array( 'draft', 'published' ) as $k ) if ( isset( $store[ $k ] ) && is_array( $store[ $k ] ) ) $p( '  ' . $k, 'id=' . ( $store[ $k ]['id'] ?? '—' ) . ' enabled=' . $yn( ! empty( $store[ $k ]['enabled'] ) ) . ' page_id=' . ( $store[ $k ]['page_id'] ?? 0 ) . ' products=' . count( (array) ( $store[ $k ]['products'] ?? array() ) ) . ' problems=' . count( (array) ( $store[ $k ]['problems'] ?? array() ) ) . ' copy overrides=' . count( (array) ( $store[ $k ]['copy'] ?? array() ) ) . ' size=' . strlen( (string) wp_json_encode( $store[ $k ] ) ) . 'B' );
	} else { $p( 'option ansa_promo_store', 'няма' ); }
	foreach ( array( 'tickets', 'leads' ) as $t ) {
		$tn = $wpdb->prefix . 'ansa_promo_' . $t;
		$ex = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tn ) ) );
		$p( 'table ' . $tn, $ex ? 'има · columns: ' . implode( ',', $wpdb->get_col( "SHOW COLUMNS FROM `$tn`" ) ) . ' · rows=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$tn`" ) : 'ЛИПСВА' );
	}
	$pages = $wpdb->get_results( "SELECT ID, post_title, post_status, post_type FROM {$wpdb->posts} WHERE post_type IN ('page','post') AND post_status IN ('publish','draft','private','pending') AND post_content LIKE '%[ansa_promo%' ORDER BY ID DESC LIMIT 10" );
	if ( $pages ) foreach ( $pages as $pg ) $p( 'page с [ansa_promo] #' . $pg->ID, $pg->post_type . ' · ' . $pg->post_status . ' · „' . $pg->post_title . '“ · ' . get_permalink( $pg->ID ) ); else $p( 'page с [ansa_promo]', 'няма' );
	$pub = null; if ( $pages ) foreach ( $pages as $pg ) if ( 'publish' === $pg->post_status ) { $pub = $pg; break; }
	if ( $pub ) {
		$r = wp_remote_get( add_query_arg( 'ansa_promo_nocache', time(), get_permalink( $pub->ID ) ), array( 'timeout' => 15, 'sslverify' => false, 'cookies' => array() ) );
		if ( is_wp_error( $r ) ) $p( 'anonymous fetch', 'грешка: ' . $r->get_error_message() );
		else { $b = (string) wp_remote_retrieve_body( $r ); $p( 'anonymous fetch', 'HTTP ' . wp_remote_retrieve_response_code( $r ) . ' · ' . strlen( $b ) . 'B · root div=' . $yn( false !== strpos( $b, 'id="ansaPromo"' ) ) . ' · „скоро“=' . $yn( false !== strpos( $b, 'ansa-promo-soon' ) ) . ' · admin note leaked=' . $yn( false !== strpos( $b, 'ansa-promo-admin-note' ) ) . ' · x-cache=' . ( wp_remote_retrieve_header( $r, 'x-flying-press-cache' ) ?: wp_remote_retrieve_header( $r, 'cf-cache-status' ) ?: '—' ) ); }
	}

	$h( 'LOGADOR GitHub Updater' );
	$mud = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/logador-github-updater.php';
	$p( 'mu файл', is_file( $mud ) ? 'има · ' . ( preg_match( '/\*\s*Version:\s*([0-9.]+)/', (string) file_get_contents( $mud ), $m ) ? $m[1] : '?' ) : 'ЛИПСВА' );
	$p( 'зареден (константа)', defined( 'LOGADOR_GH_UPDATER_VERSION' ) ? LOGADOR_GH_UPDATER_VERSION : 'no' );
	if ( class_exists( 'LOGADOR_GitHub_Updater' ) ) {
		$p( 'token', \LOGADOR_GitHub_Updater::token() ? 'има (' . strlen( \LOGADOR_GitHub_Updater::token() ) . ' знака)' : 'НЯМА' );
		$tr = \LOGADOR_GitHub_Updater::tracked(); foreach ( $tr as $file => $t ) $p( 'следи ' . $file, $t['repo'] . ' · локално ' . $t['version'] );
		foreach ( array( 'proclaudecopilot/ansa-promo', 'proclaudecopilot/ansa-shrine' ) as $repo ) {
			$rel = \LOGADOR_GitHub_Updater::release( $repo, true );
			$p( 'GitHub ' . $repo, ! empty( $rel['data'] ) ? 'latest ' . $rel['data']['version'] . ' · zip=' . $yn( false !== strpos( (string) $rel['data']['package'], '.zip' ) ) . ' · ' . $rel['data']['published'] : 'грешка: ' . ( $rel['error'] ?: '?' ) );
		}
		$p( 'webhook url', \LOGADOR_GitHub_Updater::hook_url() );
	}
	$up = get_site_transient( 'update_plugins' );
	$p( 'WP вижда ъпдейт за ansa-promo', isset( $up->response['ansa-promo/ansa-promo.php'] ) ? 'yes → ' . $up->response['ansa-promo/ansa-promo.php']->new_version : 'no' );

	$L[] = ''; $L[] = '=== край ===';
	return $cache = implode( "\n", $L );
}
}
