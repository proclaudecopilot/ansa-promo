<?php
/**
 * WooCommerce + WordPress в паметта за офлайн тестовете на количката (tests/bf-partner-test.php):
 * истински куки (add_action / apply_filters с приоритети), $wpdb върху SQLite, WC сесия, количка с купони и такси, купони, поръчки и чекаут.
 * Моделира това, на което кодът разчита: empty_cart() маха и купоните; apply_coupon() минава през woocommerce_coupon_is_valid и
 * woocommerce_applied_coupon; чекаутът вика woocommerce_checkout_create_order → save → woocommerce_checkout_order_created.
 */
define( 'ABSPATH', __DIR__ . '/' ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 ); define( 'WEEK_IN_SECONDS', 604800 ); define( 'YEAR_IN_SECONDS', 31536000 );
define( 'ARRAY_A', 'ARRAY_A' ); define( 'OBJECT', 'OBJECT' ); define( 'DOING_AJAX', true );
define( 'ANSA_PROMO_VER', '0.0.0-test' ); define( 'ANSA_PROMO_PATH', dirname( __DIR__, 2 ) . '/' ); if ( ! defined( 'ANSA_PROMO_URL' ) ) { define( 'ANSA_PROMO_URL', 'http://x/wp-content/plugins/ansa-promo/' ); }

/* ── куки ── */
$GLOBALS['hooks'] = array(); $GLOBALS['hook_log'] = array();
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['hooks'][ $h ][ $p ][] = array( $cb, $n ); return true; }
function add_action( $h, $cb, $p = 10, $n = 1 ) { return add_filter( $h, $cb, $p, $n ); }
function remove_filter( $h, $cb, $p = 10 ) { foreach ( (array) ( $GLOBALS['hooks'][ $h ][ $p ] ?? array() ) as $i => $c ) { if ( $c[0] === $cb ) { unset( $GLOBALS['hooks'][ $h ][ $p ][ $i ] ); } } return true; }
function apply_filters( $h, $v, ...$a ) { if ( empty( $GLOBALS['hooks'][ $h ] ) ) { return $v; } ksort( $GLOBALS['hooks'][ $h ] ); foreach ( $GLOBALS['hooks'][ $h ] as $cbs ) { foreach ( $cbs as $c ) { $args = array_slice( array_merge( array( $v ), $a ), 0, max( 1, (int) $c[1] ) ); $v = call_user_func_array( $c[0], $args ); } } return $v; }
function do_action( $h, ...$a ) { $GLOBALS['hook_log'][] = $h; if ( empty( $GLOBALS['hooks'][ $h ] ) ) { return; } ksort( $GLOBALS['hooks'][ $h ] ); foreach ( $GLOBALS['hooks'][ $h ] as $cbs ) { foreach ( $cbs as $c ) { call_user_func_array( $c[0], array_slice( $a, 0, (int) $c[1] ) ); } } }
function doing_action( $h = null ) { return false; } function did_action( $h ) { return count( array_keys( $GLOBALS['hook_log'], $h, true ) ); }
function add_shortcode() {} function wp_next_scheduled() { return 1; } function wp_schedule_event() {} function wp_clear_scheduled_hook() {}
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['cron'][] = array( $hook, $args ); return true; }
function run_cron() { $q = $GLOBALS['cron'] ?? array(); $GLOBALS['cron'] = array(); foreach ( $q as $e ) { do_action( $e[0], ...$e[1] ); } return count( $q ); }

/* ── WordPress ── */
$GLOBALS['opts'] = array(); $GLOBALS['transients'] = array(); $GLOBALS['user_meta'] = array(); $GLOBALS['uid'] = 0; $GLOBALS['caps'] = false; $GLOBALS['mails'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['qv'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? apply_filters( 'option_' . $k, $GLOBALS['opts'][ $k ] ) : apply_filters( 'default_option_' . $k, $d ); } /* както WP: option_{име} / default_option_{име} */ function update_option( $k, $v, $a = true ) { $GLOBALS['opts'][ $k ] = $v; return true; } function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { $t = $GLOBALS['transients'][ $k ] ?? null; if ( ! $t || ( $t[1] && $t[1] < time() ) ) { return false; } return $t[0]; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = array( $v, $ttl ? time() + $ttl : 0 ); return true; } function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_user_meta( $u, $k, $single = false ) { return $GLOBALS['user_meta'][ $u ][ $k ] ?? ''; } function update_user_meta( $u, $k, $v ) { $GLOBALS['user_meta'][ $u ][ $k ] = $v; return true; } function delete_user_meta( $u, $k ) { unset( $GLOBALS['user_meta'][ $u ][ $k ] ); return true; }
function is_user_logged_in() { return $GLOBALS['uid'] > 0; } function get_current_user_id() { return (int) $GLOBALS['uid']; } function current_user_can( $c ) { return (bool) $GLOBALS['caps']; }
function is_admin() { return false; } function wp_doing_ajax() { return true; } function nocache_headers() {} function is_ssl() { return true; } function status_header() {}
function wp_unslash( $v ) { return $v; } function wp_salt( $s = '' ) { return 'salt'; } function absint( $n ) { return abs( (int) $n ); } function wp_timezone_string() { return 'Europe/Sofia'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); } function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_email( $e ) { return trim( (string) $e ); } function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function wp_kses( $s, $a = array() ) { return (string) $s; } function wp_kses_post( $s ) { return (string) $s; } function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } function wpautop( $s ) { return '<p>' . str_replace( "\n\n", '</p><p>', (string) $s ) . '</p>'; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_url( $s ) { return (string) $s; } function esc_url_raw( $s ) { return (string) $s; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); } function current_time( $f ) { return 'timestamp' === $f ? time() : gmdate( 'mysql' === $f ? 'Y-m-d H:i:s' : $f ); }
function wp_date( $f, $ts = null ) { return ( new DateTimeImmutable( '@' . (int) ( $ts ?? time() ) ) )->setTimezone( new DateTimeZone( wp_timezone_string() ) )->format( $f ); } function date_i18n( $f, $ts = null ) { return wp_date( $f, $ts ); }
function home_url( $p = '' ) { return ( $GLOBALS['home'] ?? 'https://www.ansa.bg' ) . $p; } function admin_url( $p = '' ) { return home_url( '/wp-admin/' . $p ); }
function add_query_arg( $k, $v = null, $u = null ) { if ( is_array( $k ) ) { $u = $v; $q = $k; } else { $q = array( $k => $v ); } $u = (string) $u; return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $q ); }
function get_query_var( $k, $d = '' ) { return $GLOBALS['qv'][ $k ] ?? $d; }
function wp_safe_redirect( $u, $s = 302 ) { $GLOBALS['redirect'] = array( $u, $s ); throw new RedirectExit( $u ); } class RedirectExit extends Exception {}
function wp_create_nonce( $a ) { return 'nonce'; } function check_ajax_referer() { return true; } function wp_mail( $to, $s, $b, $h = '' ) { $GLOBALS['mails'][] = array( $to, $s, $b ); return true; }
function wp_send_json_success( $d = null ) { throw new JsonOut( array( 'success' => true, 'data' => $d ) ); } function wp_send_json_error( $d = null, $st = 0 ) { throw new JsonOut( array( 'success' => false, 'data' => $d ) ); } function wp_send_json( $d ) { throw new JsonOut( $d ); }
class JsonOut extends Exception { public $out; function __construct( $o ) { parent::__construct( 'json' ); $this->out = $o; } }
/** Вика ajax handler и връща JSON-а му. */
function ajax( $cb, $post = array(), $get = array() ) { $_POST = $post; $_GET = $get; $_REQUEST = array_merge( $get, $post ); try { call_user_func( $cb ); } catch ( JsonOut $j ) { $_POST = $_GET = $_REQUEST = array(); return $j->out; } $_POST = $_GET = $_REQUEST = array(); return null; }

/* ── $wpdb върху SQLite ── */
class FakeWpdb {
	public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0; public $errors = array(); public $db; private $quiet = false;
	function __construct() { $this->db = new PDO( getenv( 'WCFAKE_DB' ) ? 'sqlite:' . getenv( 'WCFAKE_DB' ) : 'sqlite::memory:' ); $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT ); }
	function suppress_errors( $s = true ) { $o = $this->quiet; $this->quiet = (bool) $s; return $o; }
	function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; } $i = 0; $q = str_replace( '%%', "\x01", $q ); $q = preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ] ?? ''; return 'd' === $m[0][1] ? (string) (int) $v : ( 'f' === $m[0][1] ? (string) (float) $v : "'" . str_replace( "'", "''", (string) $v ) . "'" ); }, $q ); return str_replace( "\x01", '%', $q ); }
	private function fail( $q ) { $this->last_error = implode( ' ', $this->db->errorInfo() ); if ( ! $this->quiet ) { $this->errors[] = $this->last_error . ' ← ' . $q; } }
	function query( $q ) { $r = $this->db->exec( $q ); if ( false === $r ) { $this->fail( $q ); } return $r; }
	function get_results( $q, $t = OBJECT ) { $st = $this->db->query( $q ); if ( ! $st ) { $this->fail( $q ); return array(); } $rows = $st->fetchAll( PDO::FETCH_ASSOC ); return ARRAY_A === $t ? $rows : array_map( function ( $r ) { return (object) $r; }, $rows ); }
	function get_row( $q, $t = OBJECT ) { $r = $this->get_results( $q, $t ); return $r[0] ?? null; }
	function get_var( $q ) { $r = $this->get_results( $q, ARRAY_A ); return $r ? array_values( $r[0] )[0] : null; }
	function get_col( $q ) { return array_map( function ( $r ) { return array_values( $r )[0]; }, $this->get_results( $q, ARRAY_A ) ); }
	function insert( $tb, $d, $f = null ) { $c = array_keys( $d ); $st = $this->db->prepare( "INSERT INTO $tb (" . implode( ',', $c ) . ') VALUES (' . implode( ',', array_map( function ( $k ) { return ':' . $k; }, $c ) ) . ')' ); $ok = $st && $st->execute( $d ); if ( ! $ok ) { $this->fail( "INSERT $tb" ); return false; } $this->insert_id = (int) $this->db->lastInsertId(); return 1; }
	function update( $tb, $d, $w, $f = null, $wf = null ) { $set = implode( ',', array_map( function ( $k ) { return "$k = :s_$k"; }, array_keys( $d ) ) ); $wh = implode( ' AND ', array_map( function ( $k ) { return "$k = :w_$k"; }, array_keys( $w ) ) ); $p = array(); foreach ( $d as $k => $v ) { $p[ "s_$k" ] = $v; } foreach ( $w as $k => $v ) { $p[ "w_$k" ] = $v; } $st = $this->db->prepare( "UPDATE $tb SET $set WHERE $wh" ); if ( ! $st || ! $st->execute( $p ) ) { $this->fail( "UPDATE $tb" ); return false; } return $st->rowCount(); }
}
$wpdb = $GLOBALS['wpdb'] = new FakeWpdb();

/* ── WooCommerce: сесия, продукти ── */
class WC_Session_Fake {
	public $d = array(); public $has = false;
	function get( $k, $def = null ) { return $this->d[ $k ] ?? $def; } function set( $k, $v ) { if ( null === $v ) { unset( $this->d[ $k ] ); } else { $this->d[ $k ] = $v; } }
	function __unset( $k ) { unset( $this->d[ $k ] ); } function __get( $k ) { return $this->d[ $k ] ?? null; } function __set( $k, $v ) { $this->set( $k, $v ); } function __isset( $k ) { return isset( $this->d[ $k ] ); }
	function has_session() { return $this->has; } function set_customer_session_cookie( $b ) { $this->has = true; }
}
class WC_Product_Fake {
	public $id; public $name; public $regular; public $price; public $stock = true; public $parent = 0;
	function __construct( $id, $name, $price ) { $this->id = $id; $this->name = $name; $this->regular = $price; $this->price = $price; }
	function get_id() { return $this->id; } function get_name() { return $this->name; } function get_regular_price() { return $this->regular; } function get_price() { return $this->price; } function set_price( $p ) { $this->price = (float) $p; }
	function is_type( $t ) { return 'simple' === $t; } function is_purchasable() { return true; } function is_in_stock() { return $this->stock; } function has_enough_stock( $q ) { return $this->stock; }
	function get_variation_attributes() { return array(); } function get_parent_id() { return $this->parent; } function get_status() { return 'publish'; }
}
$GLOBALS['products'] = array();
function wc_get_product( $id ) { return isset( $GLOBALS['products'][ (int) $id ] ) ? clone $GLOBALS['products'][ (int) $id ] : null; }
function wc_format_coupon_code( $c ) { return strtolower( trim( (string) $c ) ); }
$GLOBALS['notices'] = array();
function wc_add_notice( $m, $t = 'success' ) { $GLOBALS['notices'][ $t ][] = array( 'notice' => $m ); } function wc_get_notices( $t = '' ) { return '' === $t ? $GLOBALS['notices'] : ( $GLOBALS['notices'][ $t ] ?? array() ); } function wc_clear_notices() { $GLOBALS['notices'] = array(); }
function wc_notice_count( $t = '' ) { return count( wc_get_notices( $t ) ); } function wc_print_notice() {} function wc_get_checkout_url() { return home_url( '/checkout/' ); } function wc_tax_enabled() { return false; } function wc_prices_include_tax() { return true; }
function wc_setcookie() {} function get_woocommerce_currency_symbol() { return '€'; }

/* ── купони ── */
$GLOBALS['coupons'] = array(); $GLOBALS['coupon_seq'] = 500;
function wc_get_coupon_id_by_code( $code ) { $c = $GLOBALS['coupons'][ wc_format_coupon_code( $code ) ] ?? null; return $c ? (int) $c['id'] : 0; }
class WC_Coupon {
	const WC_COUPON_SUCCESS = 200;
	public $p = array( 'id' => 0, 'code' => '', 'type' => 'percent', 'amount' => 0.0, 'limit' => 0, 'count' => 0, 'iu' => false, 'exp' => 0, 'min' => 0.0, 'desc' => '', 'meta' => array() );
	function __construct( $x = '' ) {
		if ( is_int( $x ) && $x > 0 ) { foreach ( $GLOBALS['coupons'] as $c ) { if ( (int) $c['id'] === $x ) { $this->p = $c; return; } } return; }
		if ( is_string( $x ) && '' !== $x ) { $code = wc_format_coupon_code( $x ); if ( isset( $GLOBALS['coupons'][ $code ] ) ) { $this->p = $GLOBALS['coupons'][ $code ]; } else { $this->p['code'] = $code; } }
	}
	function get_id() { return (int) $this->p['id']; } function get_code() { return $this->p['code']; } function set_code( $c ) { $this->p['code'] = wc_format_coupon_code( $c ); }
	function get_discount_type() { return $this->p['type']; } function set_discount_type( $t ) { $this->p['type'] = $t; } function get_amount() { return (float) $this->p['amount']; } function set_amount( $a ) { $this->p['amount'] = (float) $a; }
	function set_usage_limit( $n ) { $this->p['limit'] = (int) $n; } function get_usage_limit() { return (int) $this->p['limit']; } function get_usage_count() { return (int) ( $GLOBALS['coupons'][ $this->p['code'] ]['count'] ?? $this->p['count'] ); }
	function set_individual_use( $b ) { $this->p['iu'] = (bool) $b; } function get_individual_use() { return (bool) $this->p['iu']; } function set_date_expires( $ts ) { $this->p['exp'] = (int) $ts; } function get_date_expires() { return $this->p['exp']; }
	function set_minimum_amount( $a ) { $this->p['min'] = (float) $a; } function get_minimum_amount() { return (float) $this->p['min']; } function set_description( $d ) { $this->p['desc'] = (string) $d; } function get_description() { return $this->p['desc']; }
	function update_meta_data( $k, $v ) { $this->p['meta'][ $k ] = $v; } function get_meta( $k ) { return $this->p['meta'][ $k ] ?? ''; } function get_virtual() { return false; }
	function save() { if ( ! $this->p['id'] ) { $this->p['id'] = ++$GLOBALS['coupon_seq']; } $GLOBALS['coupons'][ $this->p['code'] ] = $this->p; return $this->p['id']; }
	function increase_usage_count( $by = '' ) { if ( isset( $GLOBALS['coupons'][ $this->p['code'] ] ) ) { $GLOBALS['coupons'][ $this->p['code'] ]['count']++; } } function decrease_usage_count( $by = '' ) { if ( isset( $GLOBALS['coupons'][ $this->p['code'] ] ) ) { $GLOBALS['coupons'][ $this->p['code'] ]['count'] = max( 0, $GLOBALS['coupons'][ $this->p['code'] ]['count'] - 1 ); } }
}
/** Нов WooCommerce купон (за партньорските кодове и чуждите купони в теста). */
function make_coupon( $code, $type = 'percent', $amount = 5, $extra = array() ) { $c = new WC_Coupon(); $c->set_code( $code ); $c->set_discount_type( $type ); $c->set_amount( $amount ); foreach ( $extra as $k => $v ) { $c->p[ $k ] = $v; } return $c->save(); }
class WC_Discounts_Fake { private $o; function __construct( $o ) { $this->o = $o; } function get_object() { return $this->o; } }

/* ── количка ── */
class WC_Cart {
	public $items = array(); public $coupons = array(); public $fees = array(); public $total = 0.0; public $discounts = array(); private $in_calc = false;
	function get_cart() { return $this->items; } function get_cart_item( $k ) { return $this->items[ $k ] ?? null; } function is_empty() { return ! $this->items; }
	function add_to_cart( $pid, $qty = 1, $vid = 0, $attrs = array(), $data = array() ) {
		$p = wc_get_product( $vid ?: $pid ); if ( ! $p ) { return false; }
		$key = md5( $pid . '|' . $vid . '|' . serialize( $data ) );
		if ( isset( $this->items[ $key ] ) ) { $this->items[ $key ]['quantity'] += $qty; } else { $this->items[ $key ] = array_merge( $data, array( 'key' => $key, 'product_id' => (int) $pid, 'variation_id' => (int) $vid, 'variation' => $attrs, 'quantity' => (int) $qty, 'data' => $p, 'line_subtotal' => 0.0, 'line_subtotal_tax' => 0.0, 'line_total' => 0.0, 'line_tax' => 0.0 ) ); }
		do_action( 'woocommerce_add_to_cart', $key, $pid, $qty, $vid, $attrs, $data );
		$this->calculate_totals(); return $key;
	}
	function remove_cart_item( $k ) { unset( $this->items[ $k ] ); return true; }
	function set_quantity( $k, $q, $refresh = true ) { if ( isset( $this->items[ $k ] ) ) { $this->items[ $k ]['quantity'] = (int) $q; } if ( $refresh ) { $this->calculate_totals(); } return true; }
	/** Както WC: изпразването маха и приложените купони, и поръчката, която чака плащане. */
	function empty_cart( $clear = true ) { $this->items = array(); $this->coupons = array(); $this->fees = array(); $this->discounts = array(); $this->total = 0.0; if ( WC()->session ) { WC()->session->set( 'order_awaiting_payment', null ); } do_action( 'woocommerce_cart_emptied', $clear ); }
	function get_applied_coupons() { return $this->coupons; } function has_discount( $code = '' ) { return '' === $code ? count( $this->coupons ) > 0 : in_array( wc_format_coupon_code( $code ), $this->coupons, true ); }
	private function subtotal() { $s = 0.0; foreach ( $this->items as $it ) { $s += (float) $it['data']->get_price() * (int) $it['quantity']; } return $s; }
	/** Валидността на купона както в WC_Discounts::is_coupon_valid: съществува, лимит, срок, мин. сума, после филтърът. Връща true или текста на грешката. */
	function coupon_error( $coupon ) {
		try {
			if ( ! $coupon->get_id() ) { throw new Exception( 'Купонът не съществува.' ); }
			if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) { throw new Exception( 'Лимитът за ползване на купона е достигнат.' ); }
			if ( $coupon->get_date_expires() && $coupon->get_date_expires() < time() ) { throw new Exception( 'Купонът е изтекъл.' ); }
			if ( $coupon->get_minimum_amount() > 0 && $this->subtotal() < $coupon->get_minimum_amount() ) { throw new Exception( 'Минималната сума не е достигната.' ); }
			if ( ! apply_filters( 'woocommerce_coupon_is_valid', true, $coupon, new WC_Discounts_Fake( $this ) ) ) { throw new Exception( 'Купонът не е валиден.' ); }
		} catch ( Exception $e ) { return $e->getMessage(); }
		return true;
	}
	function apply_coupon( $code ) {
		$code = wc_format_coupon_code( $code ); if ( '' === $code || $this->has_discount( $code ) ) { return false; }
		$c = new WC_Coupon( $code ); $err = $this->coupon_error( $c );
		if ( true !== $err ) { wc_add_notice( $err, 'error' ); $GLOBALS['last_coupon_error'] = $err; return false; }
		$this->coupons[] = $code; do_action( 'woocommerce_applied_coupon', $code ); $this->calculate_totals(); return true;
	}
	function remove_coupon( $code ) { $code = wc_format_coupon_code( $code ); $this->coupons = array_values( array_diff( $this->coupons, array( $code ) ) ); do_action( 'woocommerce_removed_coupon', $code ); return true; }
	function add_fee( $name, $amount, $taxable = false ) { $this->fees[] = (object) array( 'name' => $name, 'amount' => (float) $amount, 'total' => (float) $amount, 'tax' => 0.0 ); }
	function get_fees() { return $this->fees; } function get_total( $ctx = 'view' ) { return $this->total; } function get_shipping_total() { return 0.0; } function get_shipping_tax() { return 0.0; } function set_session() {}
	function calculate_totals() {
		if ( $this->in_calc ) { return; } $this->in_calc = true;
		do_action( 'woocommerce_before_calculate_totals', $this );
		foreach ( $this->items as $k => $it ) { $this->items[ $k ]['line_subtotal'] = (float) $it['data']->get_price() * (int) $it['quantity']; $this->items[ $k ]['line_total'] = $this->items[ $k ]['line_subtotal']; }
		/* купоните, чиято валидност е отпаднала (както WC::check_cart_coupons), падат */
		foreach ( $this->coupons as $code ) { $c = new WC_Coupon( $code ); if ( true !== $this->coupon_error( $c ) ) { $this->coupons = array_values( array_diff( $this->coupons, array( $code ) ) ); } }
		$this->discounts = array(); $sub = array_sum( array_column( $this->items, 'line_subtotal' ) );
		foreach ( $this->coupons as $code ) {
			$c = new WC_Coupon( $code ); $tot = 0.0;
			foreach ( $this->items as $k => $it ) {
				$base = (float) $this->items[ $k ]['line_total']; if ( $base <= 0 ) { continue; }
				$d = 'percent' === $c->get_discount_type() ? $base * $c->get_amount() / 100 : ( $sub > 0 ? min( $base, $c->get_amount() * ( (float) $it['line_subtotal'] / $sub ) ) : 0 );
				$d = (float) apply_filters( 'woocommerce_coupon_get_discount_amount', $d, $base, $this->items[ $k ], false, $c );
				$this->items[ $k ]['line_total'] = max( 0, $base - $d ); $tot += $d;
			}
			$this->discounts[ $code ] = round( $tot, 2 );
		}
		$this->fees = array(); do_action( 'woocommerce_cart_calculate_fees', $this );
		$this->total = round( array_sum( array_column( $this->items, 'line_total' ) ) + array_sum( array_map( function ( $f ) { return $f->amount; }, $this->fees ) ), 2 );
		do_action( 'woocommerce_after_calculate_totals', $this );
		$this->in_calc = false;
	}
}

/* ── поръчки ── */
class WC_Order_Item_Coupon { public $props = array( 'code' => '', 'discount' => 0, 'discount_tax' => 0 ); function set_props( $p ) { $this->props = array_merge( $this->props, $p ); } function get_code() { return $this->props['code']; } function get_discount() { return $this->props['discount']; } }
class WC_Order_Item_Product_Fake { public $pid; public $vid; public $qty; public $total; public $meta = array(); function get_product_id() { return $this->pid; } function get_variation_id() { return $this->vid; } function get_quantity() { return $this->qty; } function get_total() { return $this->total; } function get_total_tax() { return 0.0; } function add_meta_data( $k, $v, $u = false ) { $this->meta[ $k ] = $v; } function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } }
$GLOBALS['orders'] = array(); $GLOBALS['order_seq'] = 9000;
class WC_Order {
	public $id = 0; public $lines = array(); public $coupon_items = array(); public $fees = array(); public $meta = array(); public $status = 'pending'; public $email = ''; public $notes = array(); public $total = 0.0; public $created; public $customer = 0;
	function get_id() { return $this->id; } function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
	function save() { if ( ! $this->id ) { $this->id = ++$GLOBALS['order_seq']; $this->created = time(); } $GLOBALS['orders'][ $this->id ] = $this; return $this->id; } function save_meta_data() { $this->save(); }
	function get_coupon_codes() { return array_map( function ( $i ) { return $i->get_code(); }, $this->coupon_items ); }
	function add_item( $i ) { if ( $i instanceof WC_Order_Item_Coupon ) { $this->coupon_items[] = $i; } else { $this->lines[] = $i; } }
	function get_items( $t = 'line_item' ) { return 'coupon' === $t ? $this->coupon_items : $this->lines; } function get_fees() { return $this->fees; } function get_total() { return $this->total; }
	function add_order_note( $n ) { $this->notes[] = $n; return 1; } function get_billing_email() { return $this->email; } function get_customer_id() { return $this->customer; } function get_status() { return $this->status; }
	function get_date_created() { return new WC_DateTime_Fake( $this->created ?: time() ); } function get_total_refunded_for_item( $id ) { return 0.0; } function get_edit_order_url() { return ''; } function get_user_id() { return $this->customer; }
	function get_billing_phone() { return ''; } function get_billing_first_name() { return ''; } function get_billing_last_name() { return ''; }
	function update_status( $s ) { $old = $this->status; $this->status = $s; $this->save(); if ( $old !== $s ) { do_action( 'woocommerce_order_status_' . $s, $this->id, $this ); do_action( 'woocommerce_order_status_changed', $this->id, $old, $s, $this ); } }
}
class WC_DateTime_Fake { private $ts; function __construct( $ts ) { $this->ts = $ts; } function date( $f ) { return gmdate( $f, $this->ts ); } function getTimestamp() { return $this->ts; } function date_i18n( $f ) { return gmdate( $f, $this->ts ); } }
function wc_get_order( $id ) { if ( $id instanceof WC_Order ) { return $id; } return $GLOBALS['orders'][ (int) $id ] ?? false; }

/**
 * Чекаутът както в WC_Checkout::create_order: редовете и купоните от количката → woocommerce_checkout_create_order → save →
 * woocommerce_checkout_order_created → woocommerce_checkout_order_processed. Неуспешно плащане оставя количката и поръчката „чака“;
 * вторият опит обновява СЪЩАТА поръчка (order_awaiting_payment). $pay: 'ok' (платена → processing, количката се изпразва) | 'fail' | 'none'.
 */
function checkout( $email, $pay = 'ok' ) {
	$cart = WC()->cart; do_action( 'woocommerce_check_cart_items' ); $cart->calculate_totals();
	$resume = (int) WC()->session->get( 'order_awaiting_payment' ); $order = $resume && wc_get_order( $resume ) ? wc_get_order( $resume ) : new WC_Order();
	$order->lines = array(); $order->coupon_items = array(); $order->fees = array(); $order->email = $email; $order->customer = (int) $GLOBALS['uid'];
	foreach ( $cart->get_cart() as $key => $it ) { $li = new WC_Order_Item_Product_Fake(); $li->pid = $it['product_id']; $li->vid = $it['variation_id']; $li->qty = $it['quantity']; $li->total = $it['line_total']; do_action( 'woocommerce_checkout_create_order_line_item', $li, $key, $it, $order ); $order->add_item( $li ); }
	foreach ( $cart->get_applied_coupons() as $code ) { $ci = new WC_Order_Item_Coupon(); $ci->set_props( array( 'code' => $code, 'discount' => $cart->discounts[ $code ] ?? 0 ) ); $order->add_item( $ci ); }
	foreach ( $cart->get_fees() as $f ) { $order->fees[] = clone $f; } $order->total = $cart->get_total( 'edit' );
	do_action( 'woocommerce_checkout_create_order', $order, array( 'billing_email' => $email ) );
	$order->save();
	do_action( 'woocommerce_checkout_order_created', $order );
	do_action( 'woocommerce_checkout_order_processed', $order->get_id(), array(), $order );
	WC()->session->set( 'order_awaiting_payment', $order->get_id() ); /* както WC: в process_order_payment, след куките */
	if ( 'ok' === $pay ) { foreach ( $order->get_coupon_codes() as $code ) { $c = new WC_Coupon( $code ); if ( $c->get_id() ) { $c->increase_usage_count( $email ); } } $order->update_status( 'processing' ); $cart->empty_cart(); }
	elseif ( 'fail' === $pay ) { $order->update_status( 'failed' ); }
	return $order;
}
/** Отказ на поръчката: WC връща броя ползвания на купоните ѝ. */
function cancel_order( $order ) { foreach ( $order->get_coupon_codes() as $code ) { $c = new WC_Coupon( $code ); if ( $c->get_id() && 'processing' === $order->get_status() ) { $c->decrease_usage_count(); } } $order->update_status( 'cancelled' ); }

class WC_Mailer_Fake { function wrap_message( $h, $b ) { return '<h1>' . $h . '</h1>' . $b; } function send( $to, $s, $html, $h = '' ) { $GLOBALS['mails'][] = array( $to, $s, $html ); return true; } }
class WooFake { public $session; public $cart; function mailer() { return new WC_Mailer_Fake(); } }
$GLOBALS['wc'] = new WooFake(); $GLOBALS['wc']->session = new WC_Session_Fake(); $GLOBALS['wc']->cart = new WC_Cart();
function WC() { return $GLOBALS['wc']; }
/** Нов посетител: празна сесия и количка (базата и купоните остават). */
function new_visitor() { $GLOBALS['wc']->session = new WC_Session_Fake(); $GLOBALS['wc']->cart = new WC_Cart(); $GLOBALS['uid'] = 0; wc_clear_notices(); $_COOKIE = array(); }
