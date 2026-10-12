<?php
/**
 * Plugin Name: LOGADOR Probe
 * Description: Диагностичен канал към сайта за екипа на LOGADOR: REST с token (среда, лог, страница, проверки на плъгините, поръчки, SELECT), „обнови сега“ и отчет, който се записва и в GitHub. Качва се веднъж; проверките на всеки плъгин пътуват със самия плъгин (filter logador_probe_suites).
 * Version:     1.0.2
 * Author:      LOGADOR
 * Requires PHP: 7.4
 */
defined( 'ABSPATH' ) || exit;
if ( defined( 'LOGADOR_PROBE_VERSION' ) ) return;
define( 'LOGADOR_PROBE_VERSION', '1.0.2' );
define( 'LOGADOR_PROBE_FILE', __FILE__ );

final class LOGADOR_Probe {
	const NS        = 'logador-probe/v1';
	const OPT_TOKEN = 'logador_probe_token';
	const OPT_GH    = 'logador_probe_github';   // { token, repo, branch }
	const OPT_LAST  = 'logador_probe_last';     // последният отчет
	const OPT_LOG   = 'logador_probe_pushlog';  // последните push-ове към GitHub
	const CRON      = 'logador_probe_after_update';

	private static $inst;
	public static function instance() { return self::$inst ?: ( self::$inst = new self() ); }

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_logador_probe_save', array( $this, 'save' ) );
		add_action( 'admin_post_logador_probe_run', array( $this, 'run_from_admin' ) );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action( self::CRON, array( $this, 'cron_report' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $l ) { array_unshift( $l, '<a href="' . esc_url( admin_url( 'tools.php?page=logador-probe' ) ) . '">Probe</a>' ); return $l; } );
	}

	/* ───────── token ───────── */
	public static function activate() { if ( '' === self::token() ) { update_option( self::OPT_TOKEN, bin2hex( random_bytes( 20 ) ), false ); } }
	public static function token() { return (string) get_option( self::OPT_TOKEN, '' ); }
	public static function base_url() { return rest_url( self::NS ); }

	/** Token от header X-Probe-Token, Authorization: Bearer или ?token=. */
	public function auth( $req ) {
		$t = self::token(); if ( '' === $t ) { return new WP_Error( 'probe_no_token', 'Няма token — активирай плъгина отново.', array( 'status' => 500 ) ); }
		$given = (string) $req->get_header( 'x-probe-token' );
		if ( '' === $given ) { $a = (string) $req->get_header( 'authorization' ); if ( preg_match( '/^Bearer\s+(.+)$/i', $a, $m ) ) { $given = trim( $m[1] ); } }
		if ( '' === $given ) { $given = (string) $req->get_param( 'token' ); }
		if ( '' === $given || ! hash_equals( $t, $given ) ) { return new WP_Error( 'probe_forbidden', 'Невалиден token.', array( 'status' => 403 ) ); }
		return true;
	}

	/* ───────── REST ───────── */
	public function routes() {
		$r = function ( $route, $cb, $methods = 'GET' ) { register_rest_route( self::NS, $route, array( 'methods' => $methods, 'callback' => array( $this, $cb ), 'permission_callback' => array( $this, 'auth' ) ) ); };
		$r( '/ping', 'r_ping' );
		$r( '/env', 'r_env' );
		$r( '/plugins', 'r_plugins' );
		$r( '/log', 'r_log' );
		$r( '/option', 'r_option' );
		$r( '/page', 'r_page' );
		$r( '/suites', 'r_suites' );
		$r( '/run', 'r_run', 'GET,POST' );
		$r( '/report', 'r_report', 'GET,POST' );
		$r( '/update', 'r_update', 'POST' );
		$r( '/install', 'r_install', 'POST' );
		$r( '/sql', 'r_sql', 'GET,POST' );
		$r( '/wc/orders', 'r_orders' );
		$r( '/wc/order', 'r_order' );
		$r( '/wc/product', 'r_product' );
		$r( '/push', 'r_push', 'POST' );
	}

	public function r_ping( $req ) { return array( 'ok' => true, 'probe' => LOGADOR_PROBE_VERSION, 'site' => home_url(), 'time' => current_time( 'mysql' ), 'wp' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'wc' => defined( 'WC_VERSION' ) ? WC_VERSION : null, 'plugins' => self::versions() ); }

	/** Версиите на плъгините на LOGADOR/ansa (бързо, за ping). */
	public static function versions() {
		$out = array();
		foreach ( array( 'ANSA_PROMO_VER' => 'ansa-promo', 'ANSA_SHRINE_VER' => 'ansa-shrine', 'ANSA_PARTNER_VERSION' => 'ansa-partner', 'LOGADOR_GH_UPDATER_VERSION' => 'logador-updater' ) as $c => $k ) { if ( defined( $c ) ) { $out[ $k ] = constant( $c ); } }
		return $out;
	}

	public function r_env( $req ) { return self::env(); }
	public static function env() {
		global $wpdb, $wp_version;
		if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		$active = (array) get_option( 'active_plugins', array() ); $mu = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();
		$plugins = array();
		foreach ( get_plugins() as $file => $h ) { $plugins[] = array( 'file' => $file, 'name' => $h['Name'], 'version' => $h['Version'], 'active' => in_array( $file, $active, true ), 'github' => (string) ( $h['GitHub Plugin URI'] ?? '' ) ); }
		$mus = array(); foreach ( $mu as $file => $h ) { $mus[] = array( 'file' => $file, 'name' => $h['Name'], 'version' => $h['Version'] ); }
		$theme = wp_get_theme();
		$env = array(
			'site' => home_url(), 'wp' => $wp_version, 'php' => PHP_VERSION, 'db' => $wpdb->db_version(), 'db_server' => method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : '', 'locale' => get_locale(), 'timezone' => wp_timezone_string(),
			'theme' => array( 'name' => $theme->get( 'Name' ), 'version' => $theme->get( 'Version' ), 'parent' => $theme->parent() ? $theme->parent()->get( 'Name' ) : '' ),
			'object_cache' => wp_using_ext_object_cache(), 'opcache' => function_exists( 'opcache_get_status' ) ? ( ( @opcache_get_status( false )['opcache_enabled'] ?? false ) ? 'on' : 'off' ) : 'n/a', 'opcache_validate' => ini_get( 'opcache.validate_timestamps' ),
			'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'debug' => array( 'WP_DEBUG' => defined( 'WP_DEBUG' ) && WP_DEBUG, 'WP_DEBUG_LOG' => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false ),
			'memory' => array( 'limit' => WP_MEMORY_LIMIT, 'max' => WP_MAX_MEMORY_LIMIT, 'php' => ini_get( 'memory_limit' ) ), 'max_execution_time' => ini_get( 'max_execution_time' ),
			'cache_plugins' => array_values( array_filter( array( class_exists( '\\FlyingPress\\Purge' ) ? 'FlyingPress' : '', defined( 'WP_ROCKET_VERSION' ) ? 'WP Rocket' : '', defined( 'LSCWP_V' ) ? 'LiteSpeed' : '', defined( 'W3TC' ) ? 'W3TC' : '' ) ) ),
			'plugins' => $plugins, 'mu_plugins' => $mus, 'versions' => self::versions(),
		);
		if ( function_exists( 'WC' ) ) {
			$gw = array(); if ( WC()->payment_gateways() ) { foreach ( WC()->payment_gateways()->payment_gateways() as $id => $g ) { $gw[ $id ] = array( 'title' => $g->get_title(), 'enabled' => 'yes' === $g->enabled ); } }
			$zones = array();
			if ( class_exists( 'WC_Shipping_Zones' ) ) { foreach ( WC_Shipping_Zones::get_zones() as $z ) { $m = array(); foreach ( $z['shipping_methods'] as $sm ) { $m[] = $sm->id . ':' . $sm->get_instance_id() . ' ' . $sm->get_title() . ( 'yes' === $sm->enabled ? '' : ' (off)' ); } $zones[] = array( 'name' => $z['zone_name'], 'locations' => array_map( function ( $l ) { return $l->code; }, (array) $z['zone_locations'] ), 'methods' => $m ); } }
			$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
			$env['wc'] = array( 'version' => WC_VERSION, 'hpos' => $hpos, 'currency' => get_woocommerce_currency(), 'decimals' => wc_get_price_decimals(), 'taxes' => wc_tax_enabled(), 'prices_include_tax' => wc_prices_include_tax(), 'coupons' => wc_coupons_enabled(),
				'pages' => array( 'cart' => (int) wc_get_page_id( 'cart' ), 'checkout' => (int) wc_get_page_id( 'checkout' ), 'checkout_url' => wc_get_checkout_url(), 'myaccount' => (int) wc_get_page_id( 'myaccount' ), 'shop' => (int) wc_get_page_id( 'shop' ) ),
				'checkout_blocks' => function_exists( 'has_block' ) && wc_get_page_id( 'checkout' ) > 0 && has_block( 'woocommerce/checkout', (int) wc_get_page_id( 'checkout' ) ), 'gateways' => $gw, 'shipping_zones' => $zones, 'guest_checkout' => get_option( 'woocommerce_enable_guest_checkout' ) );
			$env['funnelkit'] = array( 'checkout' => class_exists( 'WFACP_Core' ) ? ( defined( 'WFACP_VERSION' ) ? WFACP_VERSION : 'yes' ) : null, 'builder' => class_exists( 'WFFN_Core' ) ? ( defined( 'WFFN_VERSION' ) ? WFFN_VERSION : 'yes' ) : null, 'cart' => defined( 'FKCART_VERSION' ) ? FKCART_VERSION : null, 'automations' => defined( 'BWFAN_VERSION' ) ? BWFAN_VERSION : null, 'stripe' => defined( 'FKWCS_VERSION' ) ? FKWCS_VERSION : null,
				'global_checkout' => function_exists( 'wffn_get_global_checkout' ) ? wffn_get_global_checkout() : ( class_exists( 'WFFN_Common' ) && method_exists( 'WFFN_Common', 'get_global_checkout_id' ) ? WFFN_Common::get_global_checkout_id() : null ) );
		}
		$env['probe'] = array( 'version' => LOGADOR_PROBE_VERSION, 'github' => self::gh_state() );
		return apply_filters( 'logador_probe_env', $env );
	}

	public function r_plugins( $req ) { $e = self::env(); return array( 'plugins' => $e['plugins'], 'mu_plugins' => $e['mu_plugins'] ); }

	/** Опашката на debug.log (и php error_log, ако е файл). ?lines=200&grep=ansa */
	public function r_log( $req ) {
		$n = max( 1, min( 2000, (int) ( $req->get_param( 'lines' ) ?: 200 ) ) ); $grep = (string) $req->get_param( 'grep' );
		$files = array();
		$dl = ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log'; $files['debug.log'] = $dl;
		$el = (string) ini_get( 'error_log' ); if ( $el && $el !== $dl ) { $files['error_log'] = $el; }
		$out = array();
		foreach ( $files as $k => $f ) {
			if ( ! is_file( $f ) || ! is_readable( $f ) ) { $out[ $k ] = array( 'path' => $f, 'exists' => false ); continue; }
			$lines = self::tail( $f, $n, $grep );
			$out[ $k ] = array( 'path' => $f, 'exists' => true, 'size' => filesize( $f ), 'modified' => gmdate( 'c', filemtime( $f ) ), 'lines' => $lines );
		}
		return $out;
	}
	private static function tail( $file, $n, $grep = '' ) {
		$size = filesize( $file ); $chunk = min( $size, 512 * 1024 ); $fh = fopen( $file, 'rb' ); if ( ! $fh ) { return array(); }
		fseek( $fh, -$chunk, SEEK_END ); $data = (string) fread( $fh, $chunk ); fclose( $fh );
		$lines = preg_split( '/\r?\n/', $data ); if ( $chunk < $size ) { array_shift( $lines ); }
		$lines = array_values( array_filter( $lines, function ( $l ) use ( $grep ) { return '' !== trim( $l ) && ( '' === $grep || false !== stripos( $l, $grep ) ); } ) );
		return array_slice( $lines, -$n );
	}

	/** Една опция (без тайни, освен с &raw=1). */
	public function r_option( $req ) {
		$name = (string) $req->get_param( 'name' ); if ( '' === $name ) { return new WP_Error( 'probe_bad', 'name?', array( 'status' => 400 ) ); }
		if ( ! $req->get_param( 'raw' ) && preg_match( '/token|secret|password|key|salt|license/i', $name ) ) { return array( 'name' => $name, 'value' => '«скрито» (добави &raw=1)' ); }
		$v = get_option( $name, null );
		return array( 'name' => $name, 'exists' => null !== $v, 'type' => gettype( $v ), 'value' => $v, 'autoload' => (bool) wp_cache_get( 'alloptions', 'options' ) && isset( wp_load_alloptions()[ $name ] ) );
	}

	/** Сървърът тегли своя URL като анонимен посетител (или с ?cookie=). Връща статус, заглавки и анализ на HTML-а. */
	public function r_page( $req ) {
		$url = (string) $req->get_param( 'url' ); if ( '' === $url ) { $url = home_url( '/' ); }
		if ( 0 !== strpos( $url, 'http' ) ) { $url = home_url( $url ); }
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { return new WP_Error( 'probe_bad', 'само URL-и на този сайт', array( 'status' => 400 ) ); }
		$args = array( 'timeout' => 30, 'redirection' => 3, 'sslverify' => false, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (LOGADOR Probe) ' . ( $req->get_param( 'mobile' ) ? 'iPhone' : 'Chrome' ), 'Cache-Control' => 'no-cache' ) );
		if ( $c = (string) $req->get_param( 'cookie' ) ) { $args['headers']['Cookie'] = $c; }
		$t0 = microtime( true ); $res = wp_remote_get( $url, $args ); $ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $res ) ) { return array( 'url' => $url, 'error' => $res->get_error_message(), 'ms' => $ms ); }
		$body = (string) wp_remote_retrieve_body( $res ); $h = wp_remote_retrieve_headers( $res ); $hd = array();
		foreach ( array( 'content-type', 'cache-control', 'x-flying-press-cache', 'x-flying-press-source', 'cf-cache-status', 'x-cache', 'age', 'x-litespeed-cache', 'x-powered-by', 'server', 'x-redirect-by', 'location' ) as $k ) { if ( isset( $h[ $k ] ) ) { $hd[ $k ] = is_array( $h[ $k ] ) ? implode( ', ', $h[ $k ] ) : $h[ $k ]; } }
		$an = array( 'bytes' => strlen( $body ), 'title' => preg_match( '#<title[^>]*>(.*?)</title>#si', $body, $m ) ? trim( html_entity_decode( $m[1] ) ) : '', 'ansaPromo_root' => false !== strpos( $body, 'id="ansaPromo"' ), 'ansaPromo_runtime' => false !== strpos( $body, 'window.AnsaPromoRuntime=' ), 'pw_gate' => false !== strpos( $body, 'ansa-promo-pwgate' ), 'soon' => false !== strpos( $body, 'ansa-promo-soon' ), 'placeholders_left' => preg_match_all( '/\{\{\s*[a-z0-9_]+\s*\}\}/i', (string) preg_replace( '/<script.*?<\/script>/s', '', $body ) ), 'scripts' => array(), 'body_classes' => preg_match( '/<body[^>]*class="([^"]*)"/i', $body, $bm ) ? $bm[1] : '' );
		if ( preg_match_all( '#<script[^>]+src="([^"]+)"#i', $body, $sm ) ) { foreach ( $sm[1] as $s ) { if ( preg_match( '#ansa|promo|shrine|wfacp|fkcart|woocommerce|funnel#i', $s ) ) { $an['scripts'][] = $s; } } }
		if ( preg_match( '/window\.AnsaPromoRuntime=(\{[^\n]*\});/', $body, $rm ) ) { $rt = json_decode( $rm[1], true ); if ( is_array( $rt ) ) { $an['runtime'] = array( 'ver' => $rt['ver'] ?? '', 'version' => $rt['version'] ?? '', 'live' => $rt['live'] ?? null, 'draft' => $rt['draft'] ?? null, 'cart' => $rt['cart'] ?? null, 'products' => is_array( $rt['products'] ?? null ) ? count( $rt['products'] ) : 0, 'boxes' => count( (array) ( $rt['boxes'] ?? array() ) ), 'copy_keys' => count( (array) ( $rt['copy'] ?? array() ) ), 'json_bytes' => strlen( $rm[1] ) ); } }
		$out = array( 'url' => $url, 'status' => (int) wp_remote_retrieve_response_code( $res ), 'ms' => $ms, 'headers' => $hd, 'analysis' => $an );
		if ( $req->get_param( 'html' ) ) { $out['html'] = mb_substr( $body, 0, max( 1000, min( 400000, (int) $req->get_param( 'html' ) ) ) ); }
		if ( $g = (string) $req->get_param( 'grep' ) ) { preg_match_all( '/.{0,120}' . preg_quote( $g, '/' ) . '.{0,120}/u', $body, $gm ); $out['grep'] = array_slice( $gm[0], 0, 50 ); }
		return $out;
	}

	/* ───────── suites (проверките на плъгините) ───────── */
	/** [{ id, label, plugin, callback }] — всеки плъгин добавя своите през filter-а. */
	public static function suites() {
		$s = apply_filters( 'logador_probe_suites', array() ); $out = array();
		foreach ( (array) $s as $x ) { if ( is_array( $x ) && ! empty( $x['id'] ) && isset( $x['callback'] ) && is_callable( $x['callback'] ) ) { $out[ (string) $x['id'] ] = array( 'id' => (string) $x['id'], 'label' => (string) ( $x['label'] ?? $x['id'] ), 'plugin' => (string) ( $x['plugin'] ?? '' ), 'auto' => ! isset( $x['auto'] ) || (bool) $x['auto'], 'callback' => $x['callback'] ); } }
		return $out;
	}
	public function r_suites( $req ) { return array_values( array_map( function ( $s ) { unset( $s['callback'] ); return $s; }, self::suites() ) ); }

	/** ?suite=cart (или списък със запетая, или всички с auto=1). Параметрите на заявката се подават на suite-а. */
	public function r_run( $req ) {
		$want = array_filter( array_map( 'trim', explode( ',', (string) $req->get_param( 'suite' ) ) ) );
		return self::run( $want, $req->get_params() );
	}
	public static function run( $want = array(), $params = array() ) {
		$all = self::suites(); $res = array(); $t0 = microtime( true );
		foreach ( $all as $id => $s ) {
			if ( $want ? ! in_array( $id, $want, true ) : ! $s['auto'] ) { continue; }
			$t1 = microtime( true ); $log = array(); $ok = true; $err = '';
			$t = new LOGADOR_Probe_T( $log, $ok );
			try { $data = call_user_func( $s['callback'], $t, $params ); } catch ( Throwable $e ) { $ok = false; $err = get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(); $data = null; }
			$res[ $id ] = array( 'label' => $s['label'], 'ok' => $ok, 'ms' => (int) round( ( microtime( true ) - $t1 ) * 1000 ), 'checks' => $log, 'passed' => count( array_filter( $log, function ( $c ) { return $c['ok']; } ) ), 'failed' => count( array_filter( $log, function ( $c ) { return ! $c['ok']; } ) ), 'error' => $err, 'data' => $data );
		}
		return array( 'ok' => ! in_array( false, array_column( $res, 'ok' ), true ), 'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ), 'suites' => $res, 'missing' => array_values( array_diff( $want, array_keys( $all ) ) ) );
	}

	/** Пълният отчет: среда + всички auto suites + лог. ?push=1 го праща и в GitHub. */
	public function r_report( $req ) {
		$rep = self::report( (array) $req->get_params() );
		if ( $req->get_param( 'push' ) ) { $rep['pushed'] = self::push( $rep, (string) $req->get_param( 'label' ) ); }
		return $rep;
	}
	public static function report( $params = array() ) {
		$rep = array( 'at' => current_time( 'mysql' ), 'at_gmt' => gmdate( 'c' ), 'probe' => LOGADOR_PROBE_VERSION, 'versions' => self::versions(), 'env' => self::env(), 'run' => self::run( array(), $params ) );
		$log = ( new self() )->r_log( new WP_REST_Request( 'GET', '/' ) ); $rep['log'] = array();
		foreach ( $log as $k => $l ) { if ( ! empty( $l['exists'] ) ) { $rep['log'][ $k ] = array_slice( $l['lines'], -60 ); } }
		update_option( self::OPT_LAST, $rep, false );
		return $rep;
	}

	/* ───────── обнови сега ───────── */
	/** POST /update?plugin=ansa-promo — чисти кеша на LOGADOR updater-а и пуска стандартния WP ъпдейт за плъгина. */
	public function r_update( $req ) {
		$slug = sanitize_key( (string) ( $req->get_param( 'plugin' ) ?: 'ansa-promo' ) );
		if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		$file = ''; foreach ( get_plugins() as $f => $h ) { if ( dirname( $f ) === $slug ) { $file = $f; break; } }
		if ( '' === $file ) { return new WP_Error( 'probe_bad', 'няма плъгин ' . $slug, array( 'status' => 404 ) ); }
		$before = get_plugins()[ $file ]['Version'];
		if ( class_exists( 'LOGADOR_GitHub_Updater' ) ) { delete_site_transient( LOGADOR_GitHub_Updater::CACHE ); }
		delete_site_transient( 'update_plugins' ); wp_update_plugins();
		$t = get_site_transient( 'update_plugins' ); $avail = isset( $t->response[ $file ] ) ? $t->response[ $file ]->new_version : null;
		if ( ! $avail ) { return array( 'plugin' => $file, 'version' => $before, 'available' => null, 'updated' => false, 'msg' => 'няма по-нова версия (release-ът още не е видим или token-ът няма достъп)' ); }
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; require_once ABSPATH . 'wp-admin/includes/file.php';
		$was_active = is_plugin_active( $file );
		$skin = new WP_Ajax_Upgrader_Skin(); $up = new Plugin_Upgrader( $skin ); $r = $up->upgrade( $file );
		if ( $was_active && ! is_plugin_active( $file ) ) { activate_plugin( $file ); }
		wp_clean_plugins_cache( true ); $after = get_plugins()[ $file ]['Version'] ?? $before;
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
		return array( 'plugin' => $file, 'version' => $before, 'available' => $avail, 'updated' => true === $r && $after !== $before, 'now' => $after, 'errors' => $skin->get_errors()->get_error_messages(), 'messages' => $skin->get_upgrade_messages() );
	}

	/** POST /install?repo=owner/name&slug=ansa-promo[&tag=v1.4.1] — инсталира/презаписва плъгин от GitHub release (asset <slug>.zip) с token-а
	 *  на LOGADOR updater-а. За възстановяване, когато папката на плъгина е счупена/липсва (updater-ът работи само с налични плъгини). */
	public function r_install( $req ) {
		$repo = (string) $req->get_param( 'repo' ); $slug = sanitize_key( (string) $req->get_param( 'slug' ) ); $tag = sanitize_text_field( (string) $req->get_param( 'tag' ) );
		if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) || '' === $slug ) { return new WP_Error( 'probe_bad', 'repo=owner/name и slug са задължителни', array( 'status' => 400 ) ); }
		$token = class_exists( 'LOGADOR_GitHub_Updater' ) ? LOGADOR_GitHub_Updater::token() : ''; if ( '' === $token ) { $token = self::gh_token(); }
		$h = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'logador-probe/' . LOGADOR_PROBE_VERSION ); if ( $token ) { $h['Authorization'] = 'Bearer ' . $token; }
		$r = wp_remote_get( 'https://api.github.com/repos/' . $repo . '/releases/' . ( $tag ? 'tags/' . rawurlencode( $tag ) : 'latest' ), array( 'timeout' => 20, 'headers' => $h ) );
		if ( is_wp_error( $r ) ) { return new WP_Error( 'probe_gh', $r->get_error_message(), array( 'status' => 502 ) ); }
		$rel = json_decode( (string) wp_remote_retrieve_body( $r ), true ); $asset = null;
		foreach ( (array) ( $rel['assets'] ?? array() ) as $a ) { if ( ( $a['name'] ?? '' ) === $slug . '.zip' ) { $asset = $a; break; } }
		if ( ! $asset ) { return new WP_Error( 'probe_asset', 'няма asset ' . $slug . '.zip в release ' . ( $rel['tag_name'] ?? '?' ) . ' (HTTP ' . wp_remote_retrieve_response_code( $r ) . ')', array( 'status' => 404 ) ); }
		require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php'; require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$dl = wp_remote_get( $asset['url'], array( 'timeout' => 120, 'stream' => true, 'filename' => wp_tempnam( $slug . '.zip' ), 'headers' => array_merge( $h, array( 'Accept' => 'application/octet-stream' ) ) ) );
		if ( is_wp_error( $dl ) ) { return new WP_Error( 'probe_dl', $dl->get_error_message(), array( 'status' => 502 ) ); }
		$file = $dl['filename']; if ( 200 !== (int) wp_remote_retrieve_response_code( $dl ) || ! is_file( $file ) || filesize( $file ) < 1000 ) { @unlink( $file ); return new WP_Error( 'probe_dl', 'свалянето не успя: HTTP ' . wp_remote_retrieve_response_code( $dl ), array( 'status' => 502 ) ); }
		$was_active = false; foreach ( get_plugins() as $pf => $hh ) { if ( dirname( $pf ) === $slug && is_plugin_active( $pf ) ) { $was_active = true; } }
		$skin = new WP_Ajax_Upgrader_Skin(); $up = new Plugin_Upgrader( $skin );
		$res = $up->install( $file, array( 'overwrite_package' => true ) ); @unlink( $file ); wp_clean_plugins_cache( true );
		$main = ''; foreach ( get_plugins() as $pf => $hh ) { if ( dirname( $pf ) === $slug && ! empty( $hh['Version'] ) && ( '' === $main || basename( $pf ) === $slug . '.php' ) ) { $main = $pf; } }
		$act = null; if ( $main && ( $was_active || $req->get_param( 'activate' ) ) ) { $act = activate_plugin( $main ); }
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }
		return array( 'installed' => true === $res, 'release' => $rel['tag_name'] ?? '', 'plugin' => $main, 'version' => $main ? get_plugins()[ $main ]['Version'] : null, 'activated' => $main ? is_plugin_active( $main ) : false, 'activate_error' => is_wp_error( $act ) ? $act->get_error_message() : null, 'errors' => $skin->get_errors()->get_error_messages(), 'messages' => $skin->get_upgrade_messages() );
	}

	/* ───────── SQL (само четене) ───────── */
	public function r_sql( $req ) {
		global $wpdb;
		$q = trim( (string) $req->get_param( 'q' ) ); $q = preg_replace( '/^\s*;+|;+\s*$/', '', $q );
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $q ) || preg_match( '/;|\b(INSERT|UPDATE|DELETE|DROP|ALTER|TRUNCATE|CREATE|REPLACE|GRANT|LOAD|INTO\s+OUTFILE)\b/i', $q ) ) { return new WP_Error( 'probe_bad', 'само SELECT/SHOW/DESCRIBE/EXPLAIN, без ;', array( 'status' => 400 ) ); }
		$q = str_replace( '{prefix}', $wpdb->prefix, $q );
		if ( ! preg_match( '/\bLIMIT\b/i', $q ) && preg_match( '/^SELECT/i', $q ) ) { $q .= ' LIMIT 200'; }
		$rows = $wpdb->get_results( $q, ARRAY_A );
		return array( 'query' => $q, 'rows' => $rows, 'count' => is_array( $rows ) ? count( $rows ) : 0, 'error' => $wpdb->last_error );
	}

	/* ───────── WooCommerce ───────── */
	public function r_orders( $req ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return new WP_Error( 'probe_bad', 'няма WooCommerce', array( 'status' => 400 ) ); }
		$a = array( 'limit' => max( 1, min( 100, (int) ( $req->get_param( 'limit' ) ?: 20 ) ) ), 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' );
		if ( $s = (string) $req->get_param( 'status' ) ) { $a['status'] = array_map( 'trim', explode( ',', $s ) ); }
		if ( $req->get_param( 'promo' ) ) { $a['meta_key'] = '_ansa_promo_id'; $a['meta_compare'] = 'EXISTS'; }
		$out = array();
		foreach ( wc_get_orders( $a ) as $o ) { $out[] = array( 'id' => $o->get_id(), 'status' => $o->get_status(), 'total' => $o->get_total(), 'date' => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '', 'email' => $o->get_billing_email(), 'gateway' => $o->get_payment_method(), 'promo' => $o->get_meta( '_ansa_promo_box' ), 'tickets' => $o->get_meta( '_ansa_promo_tickets' ), 'items' => count( $o->get_items() ) ); }
		return $out;
	}
	public function r_order( $req ) {
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $req->get_param( 'id' ) ) : null;
		if ( ! $o ) { return new WP_Error( 'probe_bad', 'няма такава поръчка', array( 'status' => 404 ) ); }
		$items = array(); foreach ( $o->get_items( array( 'line_item', 'shipping', 'fee', 'coupon' ) ) as $it ) { $row = array( 'type' => $it->get_type(), 'name' => $it->get_name(), 'qty' => method_exists( $it, 'get_quantity' ) ? $it->get_quantity() : 1, 'total' => $it->get_total(), 'tax' => $it->get_total_tax(), 'meta' => array() ); foreach ( $it->get_meta_data() as $m ) { $row['meta'][ $m->key ] = $m->value; } if ( method_exists( $it, 'get_product_id' ) ) { $row['product_id'] = $it->get_product_id(); $row['variation_id'] = $it->get_variation_id(); } $items[] = $row; }
		$meta = array(); foreach ( $o->get_meta_data() as $m ) { $meta[ $m->key ] = $m->value; }
		$notes = array(); foreach ( wc_get_order_notes( array( 'order_id' => $o->get_id(), 'limit' => 20 ) ) as $n ) { $notes[] = array( 'at' => $n->date_created->date( 'Y-m-d H:i' ), 'by' => $n->added_by, 'customer' => $n->customer_note, 'text' => wp_strip_all_tags( $n->content ) ); }
		return array( 'id' => $o->get_id(), 'status' => $o->get_status(), 'created' => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : '', 'totals' => array( 'subtotal' => $o->get_subtotal(), 'shipping' => $o->get_shipping_total(), 'discount' => $o->get_discount_total(), 'tax' => $o->get_total_tax(), 'total' => $o->get_total() ), 'gateway' => $o->get_payment_method(), 'gateway_title' => $o->get_payment_method_title(), 'billing' => array( 'email' => $o->get_billing_email(), 'name' => $o->get_formatted_billing_full_name(), 'phone' => $o->get_billing_phone() ), 'shipping_method' => $o->get_shipping_method(), 'coupons' => $o->get_coupon_codes(), 'items' => $items, 'meta' => $meta, 'notes' => $notes, 'customer_note' => $o->get_customer_note() );
	}
	public function r_product( $req ) {
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $req->get_param( 'id' ) ?: ( $req->get_param( 'sku' ) ? wc_get_product_id_by_sku( (string) $req->get_param( 'sku' ) ) : 0 ) ) : null;
		if ( ! $p ) { return new WP_Error( 'probe_bad', 'няма такъв продукт', array( 'status' => 404 ) ); }
		$row = function ( $x ) { return array( 'id' => $x->get_id(), 'type' => $x->get_type(), 'name' => $x->get_name(), 'sku' => $x->get_sku(), 'status' => $x->get_status(), 'regular' => $x->get_regular_price(), 'sale' => $x->get_sale_price(), 'price' => $x->get_price(), 'stock_status' => $x->get_stock_status(), 'stock' => $x->get_stock_quantity(), 'manage_stock' => $x->get_manage_stock(), 'purchasable' => $x->is_purchasable(), 'attributes' => method_exists( $x, 'get_variation_attributes' ) && $x->is_type( 'variation' ) ? $x->get_variation_attributes() : null, 'image' => wp_get_attachment_image_url( $x->get_image_id(), 'thumbnail' ) ); };
		$out = $row( $p ); $out['children'] = array();
		if ( $p->is_type( 'variable' ) ) { foreach ( $p->get_children() as $c ) { $v = wc_get_product( $c ); if ( $v ) { $out['children'][] = $row( $v ); } } }
		return $out;
	}

	/* ───────── GitHub push ───────── */
	public static function gh() { $g = (array) get_option( self::OPT_GH, array() ); return array( 'token' => (string) ( $g['token'] ?? '' ), 'repo' => (string) ( $g['repo'] ?? 'proclaudecopilot/ansa-promo' ), 'branch' => (string) ( $g['branch'] ?? 'probe-reports' ) ); }
	/** token-ът на probe-а, иначе общият на LOGADOR updater-а (ако има права за запис). */
	public static function gh_token() { $g = self::gh(); if ( '' !== $g['token'] ) { return $g['token']; } return class_exists( 'LOGADOR_GitHub_Updater' ) ? LOGADOR_GitHub_Updater::token() : ''; }
	public static function gh_state() { $g = self::gh(); return array( 'repo' => $g['repo'], 'branch' => $g['branch'], 'own_token' => '' !== $g['token'], 'updater_token' => class_exists( 'LOGADOR_GitHub_Updater' ) && '' !== LOGADOR_GitHub_Updater::token(), 'last' => (array) get_option( self::OPT_LOG, array() ) ); }
	private static function gh_req( $method, $path, $body = null ) {
		$args = array( 'method' => $method, 'timeout' => 25, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'Authorization' => 'Bearer ' . self::gh_token(), 'User-Agent' => 'logador-probe/' . LOGADOR_PROBE_VERSION, 'X-GitHub-Api-Version' => '2022-11-28' ) );
		if ( null !== $body ) { $args['body'] = wp_json_encode( $body ); $args['headers']['Content-Type'] = 'application/json'; }
		$r = wp_remote_request( 'https://api.github.com/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $r ) ) { return array( 0, array( 'message' => $r->get_error_message() ) ); }
		return array( (int) wp_remote_retrieve_response_code( $r ), json_decode( (string) wp_remote_retrieve_body( $r ), true ) );
	}
	/** Записва отчета като файл reports/<ts>-<ver>.json в branch-а (създава го от default branch-а, ако липсва). */
	public static function push( $rep, $label = '' ) {
		$g = self::gh(); $out = array( 'ok' => false, 'at' => current_time( 'mysql' ) );
		if ( '' === self::gh_token() ) { $out['error'] = 'няма GitHub token'; self::pushlog( $out ); return $out; }
		list( $c, $b ) = self::gh_req( 'GET', 'repos/' . $g['repo'] . '/git/ref/heads/' . rawurlencode( $g['branch'] ) );
		if ( 404 === $c ) {
			list( $c2, $repo ) = self::gh_req( 'GET', 'repos/' . $g['repo'] ); $def = (string) ( $repo['default_branch'] ?? 'main' );
			list( $c3, $ref ) = self::gh_req( 'GET', 'repos/' . $g['repo'] . '/git/ref/heads/' . rawurlencode( $def ) );
			$sha = (string) ( $ref['object']['sha'] ?? '' );
			if ( '' === $sha ) { $out['error'] = 'default branch? HTTP ' . $c2 . '/' . $c3 . ' ' . ( $ref['message'] ?? '' ); self::pushlog( $out ); return $out; }
			list( $c4, $mk ) = self::gh_req( 'POST', 'repos/' . $g['repo'] . '/git/refs', array( 'ref' => 'refs/heads/' . $g['branch'], 'sha' => $sha ) );
			if ( 201 !== $c4 ) { $out['error'] = 'branch: HTTP ' . $c4 . ' ' . ( $mk['message'] ?? '' ); self::pushlog( $out ); return $out; }
		} elseif ( 200 !== $c ) { $out['error'] = 'ref: HTTP ' . $c . ' ' . ( $b['message'] ?? '' ); self::pushlog( $out ); return $out; }
		$ver = (string) ( $rep['versions']['ansa-promo'] ?? '0' ); $name = gmdate( 'Ymd-His' ) . '-v' . $ver . ( $label ? '-' . sanitize_title( $label ) : '' ) . '.json';
		$path = 'reports/' . $name; $content = wp_json_encode( $rep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		list( $c5, $put ) = self::gh_req( 'PUT', 'repos/' . $g['repo'] . '/contents/' . $path, array( 'message' => 'probe: ' . $name, 'content' => base64_encode( $content ), 'branch' => $g['branch'] ) );
		if ( ! in_array( $c5, array( 200, 201 ), true ) ) { $out['error'] = 'put: HTTP ' . $c5 . ' ' . ( $put['message'] ?? '' ); self::pushlog( $out ); return $out; }
		/* latest.json — винаги последният */
		list( $c6, $cur ) = self::gh_req( 'GET', 'repos/' . $g['repo'] . '/contents/reports/latest.json?ref=' . rawurlencode( $g['branch'] ) );
		$lb = array( 'message' => 'probe: latest ← ' . $name, 'content' => base64_encode( $content ), 'branch' => $g['branch'] ); if ( 200 === $c6 && ! empty( $cur['sha'] ) ) { $lb['sha'] = $cur['sha']; }
		self::gh_req( 'PUT', 'repos/' . $g['repo'] . '/contents/reports/latest.json', $lb );
		$out['ok'] = true; $out['path'] = $path; $out['url'] = (string) ( $put['content']['html_url'] ?? '' ); self::pushlog( $out ); return $out;
	}
	private static function pushlog( $row ) { $l = (array) get_option( self::OPT_LOG, array() ); array_unshift( $l, $row ); update_option( self::OPT_LOG, array_slice( $l, 0, 10 ), false ); }
	public function r_push( $req ) { $rep = get_option( self::OPT_LAST ); if ( ! is_array( $rep ) || $req->get_param( 'fresh' ) ) { $rep = self::report(); } return self::push( $rep, (string) $req->get_param( 'label' ) ); }

	/* ───────── след ъпдейт на плъгин → отчет (+ push) ───────── */
	public function after_upgrade( $upgrader, $extra ) {
		if ( ( $extra['type'] ?? '' ) !== 'plugin' ) { return; }
		$plugins = (array) ( $extra['plugins'] ?? ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() ) );
		foreach ( $plugins as $p ) { if ( preg_match( '#^(ansa-|logador-)#', (string) $p ) ) { wp_schedule_single_event( time() + 20, self::CRON, array( basename( dirname( $p ) ) ) ); return; } }
	}
	public function cron_report( $plugin = '' ) { if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); } $rep = self::report(); $rep['trigger'] = 'after update: ' . $plugin; update_option( self::OPT_LAST, $rep, false ); if ( '' !== self::gh_token() ) { self::push( $rep, 'after-update-' . $plugin ); } }

	/* ───────── admin ───────── */
	public function menu() { add_management_page( 'LOGADOR Probe', 'LOGADOR Probe', 'manage_options', 'logador-probe', array( $this, 'page' ) ); }
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Няма достъп.' ); } check_admin_referer( 'logador_probe_save' );
		$g = self::gh(); $g['repo'] = sanitize_text_field( wp_unslash( $_POST['repo'] ?? $g['repo'] ) ); $g['branch'] = sanitize_text_field( wp_unslash( $_POST['branch'] ?? $g['branch'] ) );
		$t = trim( (string) wp_unslash( $_POST['gh_token'] ?? '' ) ); if ( '' !== $t ) { $g['token'] = $t; } if ( ! empty( $_POST['gh_clear'] ) ) { $g['token'] = ''; }
		update_option( self::OPT_GH, $g, false );
		if ( ! empty( $_POST['regen'] ) ) { update_option( self::OPT_TOKEN, bin2hex( random_bytes( 20 ) ), false ); }
		wp_safe_redirect( admin_url( 'tools.php?page=logador-probe&saved=1' ) ); exit;
	}
	public function run_from_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Няма достъп.' ); } check_admin_referer( 'logador_probe_run' );
		$rep = self::report(); $pushed = null; if ( ! empty( $_POST['push'] ) ) { $pushed = self::push( $rep, 'manual' ); }
		wp_safe_redirect( admin_url( 'tools.php?page=logador-probe&ran=1' . ( $pushed ? '&pushed=' . ( $pushed['ok'] ? '1' : '0' ) : '' ) ) ); exit;
	}
	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Няма достъп.' ); }
		$last = get_option( self::OPT_LAST ); $g = self::gh(); $st = self::gh_state();
		echo '<div class="wrap"><h1>LOGADOR Probe <small style="font-weight:400;color:#777">v' . esc_html( LOGADOR_PROBE_VERSION ) . '</small></h1>';
		if ( isset( $_GET['saved'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>Записано.</p></div>'; }
		if ( isset( $_GET['ran'] ) ) { echo '<div class="notice notice-info is-dismissible"><p>Отчетът е готов' . ( isset( $_GET['pushed'] ) ? ( '1' === $_GET['pushed'] ? ' и е записан в GitHub.' : ', но записът в GitHub не успя — виж „Последни записи“.' ) : '.' ) . '</p></div>'; }
		echo '<p>Каналът, през който екипът на LOGADOR проверява сайта без снипети. Три неща за копиране към чата:</p>';
		echo '<table class="form-table"><tr><th>REST адрес</th><td><code>' . esc_html( self::base_url() ) . '</code></td></tr><tr><th>Token</th><td><code style="user-select:all">' . esc_html( self::token() ) . '</code><p class="description">Праща се като header <code>X-Probe-Token</code>. Който го има, вижда среда, лог, поръчки и може да пуска проверки и ъпдейти — пази го като парола.</p></td></tr>';
		echo '<tr><th>Проверки (suites)</th><td>' . ( self::suites() ? implode( ', ', array_map( function ( $s ) { return '<code>' . esc_html( $s['id'] ) . '</code> ' . esc_html( $s['label'] ) . ( $s['plugin'] ? ' <small>(' . esc_html( $s['plugin'] ) . ')</small>' : '' ); }, self::suites() ) ) : '<i>никой плъгин още не е регистрирал проверки</i>' ) . '</td></tr></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:10px 0 24px">'; wp_nonce_field( 'logador_probe_run' ); echo '<input type="hidden" name="action" value="logador_probe_run"><button class="button button-primary">▶ Пусни всички проверки сега</button> <label style="margin-left:10px"><input type="checkbox" name="push" value="1"' . ( '' !== self::gh_token() ? ' checked' : ' disabled' ) . '> и запиши отчета в GitHub</label></form>';
		echo '<h2>GitHub (отчетите)</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'logador_probe_save' ); echo '<input type="hidden" name="action" value="logador_probe_save">';
		echo '<table class="form-table"><tr><th>Репо</th><td><input type="text" name="repo" class="regular-text code" value="' . esc_attr( $g['repo'] ) . '"></td></tr><tr><th>Branch</th><td><input type="text" name="branch" class="regular-text code" value="' . esc_attr( $g['branch'] ) . '"></td></tr>';
		echo '<tr><th>GitHub token за запис</th><td><input type="password" name="gh_token" class="regular-text" autocomplete="new-password" placeholder="' . ( $st['own_token'] ? '••••• (има; остави празно = без промяна)' : ( $st['updater_token'] ? 'празно = ползва token-а на LOGADOR updater-а' : 'Fine-grained PAT с Contents: Read and write за репото' ) ) . '"> <label><input type="checkbox" name="gh_clear" value="1"> изтрий</label><p class="description">Отчетите отиват като файлове <code>reports/&lt;дата&gt;-v&lt;версия&gt;.json</code> в branch-а (създава се сам). Token-ът на updater-а е само за четене по инструкция — за запис трябва отделен token с <b>Contents: Read and write</b> само за това репо.</p></td></tr>';
		echo '<tr><th>Probe token</th><td><label><input type="checkbox" name="regen" value="1"> генерирай нов (старият спира да работи)</label></td></tr></table><p><button class="button">💾 Запази</button></p></form>';
		if ( ! empty( $st['last'] ) ) { echo '<h3>Последни записи в GitHub</h3><ul>'; foreach ( $st['last'] as $l ) { echo '<li>' . esc_html( $l['at'] ) . ' — ' . ( ! empty( $l['ok'] ) ? '✅ <a href="' . esc_url( $l['url'] ?: '#' ) . '" target="_blank">' . esc_html( $l['path'] ) . '</a>' : '❌ ' . esc_html( $l['error'] ?? '' ) ) . '</li>'; } echo '</ul>'; }
		if ( is_array( $last ) ) {
			$sum = array(); foreach ( (array) ( $last['run']['suites'] ?? array() ) as $id => $s ) { $sum[] = ( $s['ok'] ? '✅' : '❌' ) . ' ' . $id . ' ' . (int) $s['passed'] . '/' . ( (int) $s['passed'] + (int) $s['failed'] ) . ( $s['error'] ? ' — ' . $s['error'] : '' ); }
			echo '<h2>Последен отчет — ' . esc_html( $last['at'] ) . '</h2><p>' . ( $sum ? implode( '<br>', array_map( 'esc_html', $sum ) ) : 'няма проверки' ) . '</p>';
			echo '<p class="description">Копирай и прати в чата, ако екипът няма достъп до сайта или GitHub:</p><textarea readonly onclick="this.select()" style="width:100%;height:320px;font:11px/1.35 Menlo,Consolas,monospace">' . esc_textarea( wp_json_encode( $last, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ) . '</textarea>';
		}
		echo '</div>';
	}
}

/** Помощник за проверките: $t->ok( 'име', $условие, 'бележка' ), $t->eq( 'име', $очаквано, $реално ), $t->note( 'име', $стойност ). */
final class LOGADOR_Probe_T {
	private $log; private $ok;
	public function __construct( &$log, &$ok ) { $this->log = &$log; $this->ok = &$ok; }
	public function ok( $name, $cond, $msg = '' ) { $c = (bool) $cond; if ( ! $c ) { $this->ok = false; } $this->log[] = array( 'name' => (string) $name, 'ok' => $c, 'msg' => is_scalar( $msg ) || null === $msg ? (string) $msg : wp_json_encode( $msg, JSON_UNESCAPED_UNICODE ) ); return $c; }
	public function eq( $name, $want, $got, $msg = '' ) { $c = $want == $got; return $this->ok( $name, $c, ( $c ? '' : 'очаквано ' . wp_json_encode( $want, JSON_UNESCAPED_UNICODE ) . ', реално ' . wp_json_encode( $got, JSON_UNESCAPED_UNICODE ) . ( $msg ? ' · ' : '' ) ) . $msg ); }
	public function note( $name, $value ) { $this->log[] = array( 'name' => (string) $name, 'ok' => true, 'msg' => is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'note' => true ); }
}

register_activation_hook( __FILE__, array( 'LOGADOR_Probe', 'activate' ) );
add_action( 'plugins_loaded', array( 'LOGADOR_Probe', 'instance' ), 1 );
add_action( 'init', function () { if ( '' === LOGADOR_Probe::token() && current_user_can( 'manage_options' ) ) { LOGADOR_Probe::activate(); } } );
