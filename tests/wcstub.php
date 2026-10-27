<?php
/* 1.34.0: общите WC стъбове и конфигът за офлайн тестовете на количката (cart-merge-test.php, cart-flow-test.php) — изнесени от cart-merge-test.php без промяна. */
require_once __DIR__ . '/wpstub.php';
use AnsaPromo\Cart; use AnsaPromo\Config;

/* ── WP/WC стъбове ── */
function absint( $v ) { return abs( (int) $v ); } function is_admin() { return false; } function current_user_can( $c ) { return false; }
function wc_get_checkout_url() { return 'http://x/checkout/'; } function wc_price( $n ) { return '€' . number_format( (float) $n, 2 ); }
function get_post_meta( $id, $k = '', $single = false ) { return ''; } function wp_get_attachment_image_url( $id, $s = '' ) { return ''; }
function wp_unslash( $v ) { return $v; } function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_url( $u ) { return (string) $u; }
function wp_kses_post( $s ) { return (string) $s; } function home_url( $p = '' ) { return 'http://x' . $p; } function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function wp_create_nonce( $a ) { return 'n'; } function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function wp_date( $f, $t = null ) { return date( $f, $t ?? time() ); } function get_post_status( $id ) { return 'publish'; }

class FakeProduct {
	public $id, $parent, $type, $reg, $price, $children, $name;
	function __construct( $id, $reg, $type = 'simple', $parent = 0, $children = array(), $name = '' ) { $this->id = $id; $this->reg = $reg; $this->price = $reg; $this->type = $type; $this->parent = $parent; $this->children = $children; $this->name = $name ?: 'Product ' . $id; }
	function get_id() { return $this->id; } function get_parent_id() { return $this->parent; } function is_type( $t ) { return $this->type === $t; }
	function get_name() { return $this->name; } function get_status() { return 'publish'; } function get_children() { return $this->children; }
	function get_regular_price() { return $this->reg; } function get_sale_price() { return ''; } function get_price() { return $this->price; } function set_price( $p ) { $this->price = $p; }
	function is_in_stock() { return true; } function is_purchasable() { return true; } function has_enough_stock( $q ) { return true; }
	function get_image_id() { return 0; } function get_short_description() { return 'Кратко описание.'; } function get_description() { return ''; }
	function get_variation_attributes() { return $this->type === 'variation' ? array( 'attribute_pa_kolichestvo' => '1 кутия' ) : array(); } function get_attributes() { return array(); }
}
$GLOBALS['products'] = array();
function wc_get_product( $id ) { $id = (int) $id; if ( isset( $GLOBALS['products'][ $id ] ) ) { return clone $GLOBALS['products'][ $id ]; } return null; }
function addp( $id, $reg, $name ) { /* variable родител + вариация „1 кутия“ */ $GLOBALS['products'][ $id ] = new FakeProduct( $id, $reg, 'variable', 0, array( $id + 1 ), $name ); $GLOBALS['products'][ $id + 1 ] = new FakeProduct( $id + 1, $reg, 'variation', $id, array(), $name . ' - 1 кутия' ); }
addp( 100, 43.90, 'ansa Greens' ); addp( 200, 49.90, 'ansa Sakura' ); addp( 300, 39.90, 'ansa Omega' ); $GLOBALS['products'][900] = new FakeProduct( 900, 19.00, 'simple', 0, array(), 'Книга' ); $GLOBALS['products'][700] = new FakeProduct( 700, 25.00, 'simple', 0, array(), 'Чужд продукт' );

class FakeSession { private $d = array(); function get( $k, $d = null ) { return $this->d[ $k ] ?? $d; } function set( $k, $v ) { $this->d[ $k ] = $v; } }
class FakeCart {
	public $cart_contents = array();
	function add_to_cart( $pid, $q = 1, $vid = 0, $attrs = array(), $data = array() ) {
		$src = wc_get_product( $vid ?: $pid ); if ( ! $src ) { return false; }
		$key = md5( $pid . '|' . $vid . '|' . json_encode( $data ) );
		if ( isset( $this->cart_contents[ $key ] ) ) { $this->cart_contents[ $key ]['quantity'] += $q; return $key; }
		$this->cart_contents[ $key ] = array_merge( $data, array( 'key' => $key, 'product_id' => (int) $pid, 'variation_id' => (int) $vid, 'variation' => $attrs, 'quantity' => (int) $q, 'data' => $src, 'line_subtotal' => $src->get_price() * $q, 'line_total' => $src->get_price() * $q ) );
		return $key;
	}
	function get_cart() { return $this->cart_contents; } function get_cart_item( $k ) { return $this->cart_contents[ $k ] ?? null; }
	function remove_cart_item( $k ) { unset( $this->cart_contents[ $k ] ); return true; } function set_quantity( $k, $q, $refresh = true ) { if ( isset( $this->cart_contents[ $k ] ) ) { $this->cart_contents[ $k ]['quantity'] = (int) $q; } return true; }
	function empty_cart() { $this->cart_contents = array(); }
	function calculate_totals() { Cart::apply_pricing( $this ); foreach ( $this->cart_contents as $k => $it ) { $this->cart_contents[ $k ]['line_subtotal'] = $it['data']->get_price() * $it['quantity']; $this->cart_contents[ $k ]['line_total'] = $this->cart_contents[ $k ]['line_subtotal']; } }
	function set_session() {} function get_total( $c = '' ) { $t = 0; foreach ( $this->cart_contents as $it ) { $t += $it['line_total']; } return round( $t, 2 ); } function get_cart_hash() { return md5( json_encode( array_keys( $this->cart_contents ) ) ); }
}
class FakeWC { public $cart; public $session; function __construct() { $this->cart = new FakeCart(); $this->session = new FakeSession(); } }
$GLOBALS['WC'] = new FakeWC();
function WC() { return $GLOBALS['WC']; }

/* ── публикуван конфиг: 3 продукта, кутиите по подразбиране (l: 5 на −40%, grow, tix_extra 1), книга 900, срок догодина ── */
$def = Config::defaults();
$def['enabled'] = true; $def['deadline'] = date( 'Y-m-d 23:59', strtotime( '+60 days' ) ); $def['page_id'] = 0;
$def['products'] = array(
	array( 'key' => 'greens', 'product_id' => 100, 'enabled' => true ),
	array( 'key' => 'sakura', 'product_id' => 200, 'enabled' => true ),
	array( 'key' => 'omega',  'product_id' => 300, 'enabled' => true ),
);
$def['rewards']['book']['product_id'] = 900;
$def['season'] = array(); /* без седмичен сезон — процентите на кутиите са директни */
$GLOBALS['opts']['ansa_promo_store'] = array( 'draft' => $def, 'published' => $def, 'published_at' => time(), 'version' => 3, 'history' => array() );

$errors = array(); $n = 0;
function ok( $cond, $msg ) { global $errors, $n; $n++; if ( ! $cond ) { $errors[] = $msg; } }
function grp() { return Cart::group( WC()->cart ); }
function base_counts( $g ) { $o = array(); foreach ( $g['base'] as $it ) { $o[ (int) $it['product_id'] ] = ( $o[ (int) $it['product_id'] ] ?? 0 ) + (int) $it['quantity']; } ksort( $o ); return $o; }
function foreign_keys() { $o = array(); foreach ( WC()->cart->get_cart() as $k => $it ) { if ( empty( $it[ Cart::META ] ) ) { $o[] = $k; } } return $o; }
