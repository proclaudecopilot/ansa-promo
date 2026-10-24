<?php
/**
 * Офлайн тест на Cart::perform_add с 'merge' (1.24.0, за 4-тата опция в Shrine) — фалшив WC (количка, сесия, продукти), без WordPress:
 * php tests/cart-merge-test.php → „OK n проверки“ или списък с грешки (exit 1).
 *
 * Сценарии: (1) merge в празна количка → група с 5× A + книга, 5 билета; (2) чужд платен ред + merge с 5× B → същата група,
 * A5 + B5, една книга, 10 опаковки, билетите по tickets_for (tix_extra), чуждият ред остава и е на регулярна цена;
 * (3) двойно докосване (същият състав до 10 с) → идемпотентно; (4) add без merge (промо страницата) изчиства количката;
 * (5) малка кутия + merge 5 → голяма: старите редове минават на „l“/−40%, книгата се добавя; (6) таван max_packs;
 * (8) 1.26.2: продуктът в конфига сочи към вариацията „3 кутии“ → редът е „1 кутия“ × 5 (Catalog::single_line / packs_of).
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Cart; use AnsaPromo\Config; use AnsaPromo\Catalog;

/* ── WP/WC стъбове ── */
function absint( $v ) { return abs( (int) $v ); } function is_admin() { return false; } function current_user_can( $c ) { return false; }
function wc_get_checkout_url() { return 'http://x/checkout/'; } function wc_price( $n ) { return '€' . number_format( (float) $n, 2 ); }
function get_post_meta( $id, $k = '', $single = false ) { return ''; } function wp_get_attachment_image_url( $id, $s = '' ) { return ''; }
function wp_unslash( $v ) { return $v; } function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_url( $u ) { return (string) $u; }
function wp_kses_post( $s ) { return (string) $s; } function home_url( $p = '' ) { return 'http://x' . $p; } function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function wp_create_nonce( $a ) { return 'n'; } function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function wp_date( $f, $t = null ) { return date( $f, $t ?? time() ); } function get_post_status( $id ) { return 'publish'; }

class FakeProduct {
	public $id, $parent, $type, $reg, $price, $children, $name, $attrs;
	function __construct( $id, $reg, $type = 'simple', $parent = 0, $children = array(), $name = '', $attrs = null ) { $this->id = $id; $this->reg = $reg; $this->price = $reg; $this->type = $type; $this->parent = $parent; $this->children = $children; $this->name = $name ?: 'Product ' . $id; $this->attrs = $attrs; }
	function get_id() { return $this->id; } function get_parent_id() { return $this->parent; } function is_type( $t ) { return $this->type === $t; }
	function get_name() { return $this->name; } function get_status() { return 'publish'; } function get_children() { return $this->children; }
	function get_regular_price() { return $this->reg; } function get_sale_price() { return ''; } function get_price() { return $this->price; } function set_price( $p ) { $this->price = $p; }
	function is_in_stock() { return true; } function is_purchasable() { return true; } function has_enough_stock( $q ) { return true; }
	function get_image_id() { return 0; } function get_short_description() { return 'Кратко описание.'; } function get_description() { return ''; }
	function get_variation_attributes() { if ( null !== $this->attrs ) { return $this->attrs; } return $this->type === 'variation' ? array( 'attribute_pa_kolichestvo' => '1 кутия' ) : array(); } function get_attributes() { return array(); }
}
$GLOBALS['products'] = array();
function wc_get_product( $id ) { $id = (int) $id; if ( isset( $GLOBALS['products'][ $id ] ) ) { return clone $GLOBALS['products'][ $id ]; } return null; }
function addp( $id, $reg, $name ) { /* variable родител + вариация „1 кутия“ */ $GLOBALS['products'][ $id ] = new FakeProduct( $id, $reg, 'variable', 0, array( $id + 1 ), $name ); $GLOBALS['products'][ $id + 1 ] = new FakeProduct( $id + 1, $reg, 'variation', $id, array(), $name . ' - 1 кутия' ); }
addp( 100, 43.90, 'ansa Greens' ); addp( 200, 49.90, 'ansa Sakura' ); addp( 300, 39.90, 'ansa Omega' ); $GLOBALS['products'][900] = new FakeProduct( 900, 19.00, 'simple', 0, array(), 'Книга' ); $GLOBALS['products'][700] = new FakeProduct( 700, 25.00, 'simple', 0, array(), 'Чужд продукт' );
/* 1.26.2: eyeFresh — родител 400 с вариации „1 / 3 / 5 кутии (3 и 2 подарък)“ (слъгове в атрибутите, както на сайта); в конфига продуктът сочи към „3 кутии“ (403) */
$GLOBALS['products'][400] = new FakeProduct( 400, 18.00, 'variable', 0, array( 403, 401, 405 ), 'ansa™ – eyeFresh Хидрогелни Пачове за очи' );
$GLOBALS['products'][401] = new FakeProduct( 401, 18.00, 'variation', 400, array(), 'ansa™ – eyeFresh Хидрогелни Пачове за очи - 1 кутия', array( 'attribute_pa_kolichestvo' => '1-kutiya' ) );
$GLOBALS['products'][403] = new FakeProduct( 403, 53.92, 'variation', 400, array(), 'ansa™ – eyeFresh Хидрогелни Пачове за очи - 3 кутии', array( 'attribute_pa_kolichestvo' => '3-kutii' ) );
$GLOBALS['products'][405] = new FakeProduct( 405, 90.00, 'variation', 400, array(), 'ansa™ – eyeFresh Хидрогелни Пачове за очи - 5 кутии (3 и 2 подарък)', array( 'attribute_pa_kolichestvo' => '%d0%ba%d1%83%d1%82%d0%b8%d0%b8-5-3-%d0%b8-2' ) );
/* продукт само в „30 / 60 капсули“ (няма „1“): SKU към 60 остава 60, родителят → 30; и вариация без число в атрибута при име с цифра („Omega 3“) */
$GLOBALS['products'][500] = new FakeProduct( 500, 30.00, 'variable', 0, array( 506, 503 ), 'ansa Omega 3' );
$GLOBALS['products'][503] = new FakeProduct( 503, 30.00, 'variation', 500, array(), 'ansa Omega 3 - 30 капсули', array( 'attribute_pa_razmer' => '30 капсули' ) );
$GLOBALS['products'][506] = new FakeProduct( 506, 50.00, 'variation', 500, array(), 'ansa Omega 3 - 60 капсули', array( 'attribute_pa_razmer' => '60 капсули' ) );
$GLOBALS['products'][600] = new FakeProduct( 600, 20.00, 'variable', 0, array( 601 ), 'ansa Omega 3' );
$GLOBALS['products'][601] = new FakeProduct( 601, 20.00, 'variation', 600, array(), 'ansa Omega 3 - ванилия', array( 'attribute_pa_vkus' => 'ванилия' ) );

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
	array( 'key' => 'eyefresh', 'product_id' => 403, 'enabled' => true ), /* 1.26.2: сочи към вариацията „3 кутии“ (както SKU-то на сайта) */
);
$def['rewards']['book']['product_id'] = 900;
$def['season'] = array(); /* без седмичен сезон — процентите на кутиите са директни */
$GLOBALS['opts']['ansa_promo_store'] = array( 'draft' => $def, 'published' => $def, 'published_at' => time(), 'version' => 3, 'history' => array() );

$errors = array(); $n = 0;
function ok( $cond, $msg ) { global $errors, $n; $n++; if ( ! $cond ) { $errors[] = $msg; } }
function grp() { return Cart::group( WC()->cart ); }
function base_counts( $g ) { $o = array(); foreach ( $g['base'] as $it ) { $o[ (int) $it['product_id'] ] = ( $o[ (int) $it['product_id'] ] ?? 0 ) + (int) $it['quantity']; } ksort( $o ); return $o; }
function foreign_keys() { $o = array(); foreach ( WC()->cart->get_cart() as $k => $it ) { if ( empty( $it[ Cart::META ] ) ) { $o[] = $k; } } return $o; }
$merge = function ( $pid, $packs = 5 ) { return Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, $packs, $pid ), 'secret' => array(), 'pay' => 'card', 'email' => '', 'cosm' => true, 'utm' => 'x', 'lead' => 0, 'sid' => 's1', 'merge' => true ) ); };

/* (1) merge в празна количка */
$r = $merge( 100 );
ok( ! empty( $r['ok'] ), '(1) perform_add merge: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
ok( ! empty( $r['merged'] ) && 5 === (int) $r['count'] && 5 === (int) $r['tickets'] && 'l' === $r['box'], '(1) merged/count/tickets/box: ' . json_encode( $r ) );
ok( isset( $r['fragments'] ) && isset( $r['cart_hash'] ), '(1) отговорът носи фрагменти' );
$g = grp(); ok( $g && Cart::group_valid( $g ) && base_counts( $g ) === array( 100 => 5 ) && $g['book'] && 'l' === $g['box'], '(1) групата: 5× Greens + книга: ' . json_encode( $g ? base_counts( $g ) : null ) );
$g1 = $g['group'];
WC()->cart->calculate_totals();
foreach ( $g['base'] as $it ) { ok( abs( WC()->cart->get_cart_item( $it['key'] )['data']->get_price() - 43.90 * 0.6 ) < 0.01, '(1) цената на реда е −40%' ); }
ok( WC()->cart->get_cart_item( $g['book'] )['data']->get_price() == 0, '(1) книгата е €0' );
ok( abs( WC()->cart->get_total() - 131.70 ) < 0.01, '(1) общо €131,70, е ' . WC()->cart->get_total() );
$ctx = Cart::ctx(); ok( $ctx['group'] === $g1 && 'x' === $ctx['utm'] && 's1' === $ctx['sid'], '(1) ctx: group/utm/sid' );

/* (2) чужд платен ред + merge с 5× Sakura → същата група, 10 опаковки, една книга, чуждият остава на регулярна цена */
$fk = WC()->cart->add_to_cart( 700, 2 );
sleep( 0 );
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 200 ), 'secret' => array(), 'pay' => 'card', 'email' => '', 'cosm' => true, 'utm' => '', 'lead' => 0, 'sid' => '', 'merge' => true ) );
ok( ! empty( $r['ok'] ) && ! empty( $r['merged'] ), '(2) merge на втора кутия: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp();
ok( $g['group'] === $g1, '(2) същата група' );
ok( base_counts( $g ) === array( 100 => 5, 200 => 5 ), '(2) A5 + B5: ' . json_encode( base_counts( $g ) ) );
ok( 10 === (int) $g['sum'] && 10 === (int) $r['count'], '(2) 10 опаковки' );
$books = 0; foreach ( WC()->cart->get_cart() as $it ) { if ( ! empty( $it[ Cart::META ] ) && 'book' === $it[ Cart::META ]['role'] ) { $books++; } } ok( 1 === $books, '(2) една книга, са ' . $books );
$bl = Config::box( Config::get_published(), 'l' ); ok( (int) $r['tickets'] === Config::tickets_for( $bl, 10 ), '(2) билетите са по tickets_for (' . Config::tickets_for( $bl, 10 ) . ' при tix_extra ' . $bl['tix_extra'] . ')' );
ok( in_array( $fk, foreign_keys(), true ), '(2) чуждият платен ред остава' );
WC()->cart->calculate_totals();
ok( WC()->cart->get_cart_item( $fk )['data']->get_price() == 25.00, '(2) чуждият ред е на регулярна цена' );
ok( abs( WC()->cart->get_total() - ( 43.90 * 5 * 0.6 + 49.90 * 5 * 0.6 + 50 ) ) < 0.01, '(2) общо = двете кутии −40% + чуждия: ' . WC()->cart->get_total() );
$ctx = Cart::ctx(); ok( 'x' === $ctx['utm'] && 's1' === $ctx['sid'], '(2) ctx пази utm/sid от първото добавяне' );
$meta = Cart::build_meta( true ); ok( $meta && 10 === (int) $meta['count'] && 'l' === $meta['box'] && (int) $meta['tickets']['allowance'] === Config::tickets_for( $bl, 10 ) && $meta['book'] && $meta['cosm'] && 'reserved' === $meta['cosm']['status'], '(2) order meta: една кутия, сет веднъж, книга, билети: ' . json_encode( $meta ) );

/* (3) двойно докосване: същият състав до 10 с → идемпотентно */
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 200 ), 'secret' => array(), 'pay' => 'card', 'merge' => true ) );
ok( ! empty( $r['ok'] ) && ! empty( $r['idempotent'] ) && 10 === (int) $r['count'], '(3) идемпотентно: ' . json_encode( $r ) );
ok( base_counts( grp() ) === array( 100 => 5, 200 => 5 ), '(3) нищо не е добавено повторно' );

/* (3b) трета кутия (друг продукт) → 15 */
$r = $merge( 300 );
ok( ! empty( $r['ok'] ) && 15 === (int) $r['count'] && base_counts( grp() ) === array( 100 => 5, 200 => 5, 300 => 5 ), '(3b) трета кутия → 15 опаковки: ' . json_encode( $r ) );

/* (4) add без merge (промо страницата) → количката се замества, чуждият ред пада */
$r = Cart::perform_add( array( 'box' => 'm', 'items' => array( 100, 100, 200 ), 'secret' => array(), 'pay' => 'card' ) );
ok( ! empty( $r['ok'] ) && empty( $r['merged'] ), '(4) обикновен add: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp(); ok( $g['group'] !== $g1 && 'm' === $g['box'] && base_counts( $g ) === array( 100 => 2, 200 => 1 ) && ! foreign_keys(), '(4) нова група „m“, без чужди редове' );

/* (5) малка кутия (1) + merge 5 → голяма: старите редове минават на l / −40%, книгата се добавя */
WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null );
$r = Cart::perform_add( array( 'box' => 's', 'items' => array( 300 ), 'secret' => array(), 'pay' => 'card' ) );
ok( ! empty( $r['ok'] ) && 's' === grp()['box'] && ! grp()['book'], '(5) малка кутия без книга' );
$r = $merge( 100 );
ok( ! empty( $r['ok'] ) && 'l' === $r['box'] && 6 === (int) $r['count'], '(5) s + 5 → l с 6: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp(); ok( 'l' === $g['box'] && 40.0 === (float) $g['pct'] && $g['book'] && base_counts( $g ) === array( 100 => 5, 300 => 1 ), '(5) групата е l/−40% с книга: ' . json_encode( array( $g['box'], $g['pct'], (bool) $g['book'], base_counts( $g ) ) ) );
foreach ( $g['base'] as $it ) { ok( 'l' === $it[ Cart::META ]['box'] && 40.0 === (float) $it[ Cart::META ]['ppct'], '(5) редът ' . $it['product_id'] . ' е на l/40' ); }
WC()->cart->calculate_totals();
ok( abs( WC()->cart->get_total() - ( 43.90 * 5 * 0.6 + 39.90 * 0.6 ) ) < 0.01, '(5) общо: ' . WC()->cart->get_total() );

/* (6) таван max_packs (20): 6 + 5 + 5 = 16 ок, + 5 = 21 → грешка max, нищо не се добавя */
ok( ! empty( $merge( 200 )['ok'] ) && ! empty( $merge( 300 )['ok'] ) && 16 === (int) grp()['sum'], '(6) 16 опаковки' );
$r = $merge( 200 );
ok( empty( $r['ok'] ) && 'max' === $r['code'] && 16 === (int) grp()['sum'], '(6) 21 > max_packs → max, количката непокътната: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );

/* (7) 1.24.1: ppct от Shrine — само нагоре: 45 печели над 40, 30 не; при вдигане на кутията редът пази вдигнатия процент */
WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null );
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 100 ), 'pay' => 'card', 'merge' => true, 'ppct' => 45 ) );
$g = grp(); $pp = array(); foreach ( $g['base'] as $it ) { $pp[ (int) $it['product_id'] ] = (float) $it[ Cart::META ]['ppct']; }
ok( ! empty( $r['ok'] ) && 45.0 === ( $pp[100] ?? 0 ), '(7) ppct 45 е записан в реда: ' . json_encode( $pp ) );
WC()->cart->calculate_totals();
ok( abs( WC()->cart->get_total() - round( 43.90 * 0.55, 2 ) * 5 ) < 0.01, '(7) цената е −45% (закръглена на опаковка): ' . WC()->cart->get_total() );
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 200 ), 'pay' => 'card', 'merge' => true, 'ppct' => 30 ) );
$g = grp(); $pp = array(); foreach ( $g['base'] as $it ) { $pp[ (int) $it['product_id'] ] = (float) $it[ Cart::META ]['ppct']; }
ok( ! empty( $r['ok'] ) && 40.0 === ( $pp[200] ?? 0 ) && 45.0 === ( $pp[100] ?? 0 ), '(7) ppct 30 не сваля под конфига (40), старият ред пази 45: ' . json_encode( $pp ) );
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 100 ), 'pay' => 'card', 'merge' => true, 'ppct' => 50 ) );
$g = grp(); $pp = array(); foreach ( $g['base'] as $it ) { $pp[ (int) $it['product_id'] ] = (float) $it[ Cart::META ]['ppct']; }
ok( ! empty( $r['ok'] ) && 50.0 === ( $pp[100] ?? 0 ) && 15 === (int) $g['sum'], '(7) същият продукт пак → количеството расте и процентът се вдига на 50: ' . json_encode( $pp ) );
$meta = Cart::build_meta( true ); ok( $meta && 15 === (int) $meta['count'] && (int) $meta['tickets']['allowance'] === Config::tickets_for( $bl, 15 ), '(7) order meta при 15 опаковки' );

/* (8) 1.26.2 (човекът: „в UI добавям 3 плюс 2 подарък, а в количката се добавят 5 пъти по 3 кутии“): продуктът в конфига сочи към
   вариацията „3 кутии“ (SKU/ID) → редът е „1 кутия“ × 5 на −40% = €54,00, не 5 × „3 кутии“ (€161,75 за 15 кутии) */
WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null );
$wl = Catalog::wc_line( 403 );
ok( $wl['ok'] && 401 === (int) $wl['variation_id'] && 400 === (int) $wl['product_id'] && 18.0 === (float) $wl['price'] && '' !== $wl['note'], '(8) wc_line(„3 кутии“) → вариацията „1 кутия“ с бележка: ' . json_encode( $wl, JSON_UNESCAPED_UNICODE ) );
ok( 401 === (int) Catalog::wc_line( 400 )['variation_id'] && 401 === (int) Catalog::wc_line( 401 )['variation_id'] && '' === Catalog::wc_line( 401 )['note'], '(8) wc_line(родител) и wc_line(„1 кутия“) → 401 без бележка' );
ok( 401 === (int) Catalog::wc_line( 405 )['variation_id'], '(8) wc_line(„5 кутии (3 и 2 подарък)“, url-кодиран слъг) → „1 кутия“' );
ok( 1 === Catalog::packs_of( wc_get_product( 401 ) ) && 3 === Catalog::packs_of( wc_get_product( 403 ) ) && 5 === Catalog::packs_of( wc_get_product( 405 ) ) && 1 === Catalog::packs_of( wc_get_product( 900 ) ), '(8) packs_of: 1 / 3 / 5, прост продукт = 1' );
ok( 506 === (int) Catalog::wc_line( 506 )['variation_id'] && '' === Catalog::wc_line( 506 )['note'] && 503 === (int) Catalog::wc_line( 500 )['variation_id'], '(8) „30/60 капсули“ без „1“: SKU към 60 остава 60, родителят → 30' );
ok( 1 === Catalog::packs_of( wc_get_product( 601 ) ) && 601 === (int) Catalog::wc_line( 601 )['variation_id'], '(8) цифра в името на продукта („Omega 3“) не е брой опаковки' );
$r = $merge( 400 );
ok( ! empty( $r['ok'] ) && 5 === (int) $r['count'] && 'l' === $r['box'], '(8) merge 5× eyeFresh: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp(); $vids = array(); foreach ( $g['base'] as $it ) { $vids[] = (int) $it['variation_id'] . '×' . (int) $it['quantity']; }
ok( $g && base_counts( $g ) === array( 400 => 5 ) && array( '401×5' ) === $vids, '(8) редът е вариация „1 кутия“ × 5, не „3 кутии“: ' . json_encode( $vids ) );
WC()->cart->calculate_totals();
ok( abs( WC()->cart->get_total() - 54.00 ) < 0.01, '(8) общо €54,00 (5 × 18 × 0,6 + книга €0), е ' . WC()->cart->get_total() );

if ( $errors ) { echo "ГРЕШКИ:\n- " . implode( "\n- ", $errors ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
