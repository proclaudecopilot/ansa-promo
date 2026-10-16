<?php
/**
 * Офлайн тест на Favorite (1.20.0) — {{ansa_promo_fav}}: фалшиви WC поръчки/продукти, без WordPress.
 * php tests/favorite-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Favorite; use AnsaPromo\Merge; use AnsaPromo\Config;

/* ── WP/WC стъбове ── */
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; }
function email_exists( $e ) { return 'user@example.com' === $e ? 7 : false; }
function home_url( $p = '' ) { return 'http://x' . $p; }
function wc_get_order_statuses() { return array( 'wc-pending' => 'P', 'wc-processing' => 'Pr', 'wc-on-hold' => 'H', 'wc-completed' => 'C', 'wc-cancelled' => 'X', 'wc-refunded' => 'R', 'wc-failed' => 'F', 'wc-delivered' => 'D', 'wc-at-office' => 'O', 'wc-shipped' => 'S' ); }
function shortcode_atts( $d, $a ) { return array_merge( $d, array_intersect_key( (array) $a, $d ) ); }
function add_shortcode() {} function current_user_can( $c ) { return ! empty( $GLOBALS['is_admin'] ); } function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); }
/* FunnelKit Automations: данните за merge таговете */
class BWFAN_Merge_Tag_Loader { public static $data = array(); public static function get_data( $k = '' ) { if ( '' === $k ) { return self::$data; } return self::$data[ $k ] ?? ''; } }
class FakeFBContact { private $e; function __construct( $e ) { $this->e = $e; } function get_email() { return $this->e; } function get_id() { return 5; } }
class FakeCRMContact { public $contact; function __construct( $e ) { $this->contact = new FakeFBContact( $e ); } }
class WooFunnels_Contact { private $e = ''; function __construct( $w = '', $e = '', $p = '', $cid = '', $u = '' ) { if ( 42 === (int) $cid ) { $this->e = 'maria@example.com'; } } function get_id() { return '' !== $this->e ? 42 : 0; } function get_email() { return $this->e; } }
class FakeProduct { public $id; public $parent; public $type; public $name; public $sku; public $attrs; public $children;
	function __construct( $id, $type, $name, $parent = 0, $attrs = array(), $sku = '', $children = array() ) { $this->id = $id; $this->type = $type; $this->name = $name; $this->parent = $parent; $this->attrs = $attrs; $this->sku = $sku; $this->children = $children; }
	function is_type( $t ) { return $this->type === $t; } function get_id() { return $this->id; } function get_parent_id() { return $this->parent; } function get_name() { return $this->name; } function get_sku() { return $this->sku; } function get_variation_attributes() { return $this->attrs; } function get_children() { return $this->children; } function get_type() { return $this->type; } }
$GLOBALS['products'] = array(
	3994 => new FakeProduct( 3994, 'variable', 'ansa™ - Daily Greens | Най-продаваната', 0, array(), '', array( 3995, 3997 ) ),
	3995 => new FakeProduct( 3995, 'variation', 'ansa™ - Daily Greens - 1 кутия', 3994, array( 'attribute_pa_broi' => '1 кутия' ) ),
	3997 => new FakeProduct( 3997, 'variation', 'ansa™ - Daily Greens - 3 кутии', 3994, array( 'attribute_pa_broi' => '3 кутии (2+1 ПОДАРЪК)' ), 'dgr001;dgr001;dgr001;dgr001;dgr001;dgr001' ),
	28726 => new FakeProduct( 28726, 'variable', 'Аnsa™ Sakura SkinTonic', 0 ),
	28727 => new FakeProduct( 28727, 'variation', 'Аnsa™ Sakura SkinTonic - 1 кутия', 28726, array( 'attribute_pa_broi' => '1 кутия' ) ),
	28729 => new FakeProduct( 28729, 'variation', 'Аnsa™ Sakura SkinTonic - 5 кутии', 28726, array( 'attribute_pa_broi' => '' ), 'SKST;SKST;SKST;SKST;SKST' ), /* без число в атрибута → SKU */
	15836 => new FakeProduct( 15836, 'variable', 'YoniBiotic', 0 ),
	15838 => new FakeProduct( 15838, 'variation', 'YoniBiotic - 2 броя', 15836, array( 'attribute_pa_broi' => '2 броя' ) ),
	3870 => new FakeProduct( 3870, 'simple', 'Омега 3', 0 ),
	74324 => new FakeProduct( 74324, 'simple', 'Тайна оферта: Daily Greens + YoniBiotic', 0 ),
);
function wc_get_product( $id ) { return $GLOBALS['products'][ (int) $id ] ?? false; }
function wc_get_product_id_by_sku( $sku ) { return 'SKST' === $sku ? 28727 : 0; }
/* get_permalink: wpstub → 'http://x/promo/' за всяко id */
class FakeMeta { private $d; function __construct( $d ) { $this->d = $d; } function get_data() { return $this->d; } }
class FakeItem { private $pid; private $vid; private $qty; private $total;
	function __construct( $pid, $vid, $qty, $total ) { $this->pid = $pid; $this->vid = $vid; $this->qty = $qty; $this->total = $total; }
	function get_product_id() { return $this->pid; } function get_variation_id() { return $this->vid; } function get_quantity() { return $this->qty; } function get_total() { return $this->total; } function get_meta_data() { return array( new FakeMeta( array( 'key' => 'pa_broi', 'value' => 'x' ) ) ); } }
class FakeDate { private $ts; function __construct( $ts ) { $this->ts = $ts; } function getTimestamp() { return $this->ts; } }
class FakeOrder { public $id; public $email; public $status; public $items; public $ts; public $cid;
	function __construct( $id, $email, $status, $items, $ts, $cid = 0 ) { $this->id = $id; $this->email = $email; $this->status = $status; $this->items = $items; $this->ts = $ts; $this->cid = $cid; }
	function get_id() { return $this->id; } function get_billing_email() { return $this->email; } function get_status() { return $this->status; } function get_items( $t = '' ) { return $this->items; } function get_date_created() { return new FakeDate( $this->ts ); } function get_customer_id() { return $this->cid; } }
$GLOBALS['orders'] = array(); $GLOBALS['q'] = array();
function wc_get_orders( $a ) {
	$GLOBALS['q'][] = $a; $out = array();
	foreach ( $GLOBALS['orders'] as $o ) {
		if ( ! in_array( 'wc-' . $o->status, (array) $a['status'], true ) ) { continue; }
		if ( isset( $a['billing_email'] ) && strtolower( $o->email ) !== strtolower( $a['billing_email'] ) ) { continue; }
		if ( isset( $a['customer_id'] ) && (int) $o->cid !== (int) $a['customer_id'] ) { continue; }
		$out[] = $o;
	}
	usort( $out, function ( $x, $y ) { return $y->ts <=> $x->ts; } );
	return array_slice( $out, 0, (int) $a['limit'] );
}
function wc_get_order( $id ) { foreach ( $GLOBALS['orders'] as $o ) { if ( $o->id === (int) $id ) { return $o; } } return false; }
function wp_date( $f, $ts ) { return date( $f, $ts ); }
class FakeWPDB { public $options = 'wp_options'; public $prefix = 'wp_'; public $deleted = 0; function query( $q ) { $this->deleted++; $GLOBALS['tr'] = array(); return 1; } }
$GLOBALS['wpdb'] = new FakeWPDB(); $wpdb = $GLOBALS['wpdb'];

$fails = array(); $n = 0;
function ok( $name, $cond, $msg = '' ) { global $fails, $n; $n++; if ( ! $cond ) { $fails[] = $name . ( $msg ? ' — ' . $msg : '' ); } }
function fresh( $email, $uid = 0 ) { $GLOBALS['tr'] = array(); return Favorite::compute( $email, $uid, true ); }

/* ── картата ── */
$err = array(); $rows = Favorite::parse_map( "3994 = Daily Greens\n28729, SKST = Sakura SkinTonik\n# коментар\n15836 15838 = YoniBiotic\nбез равно\n= празно име\n99999 = Няма такъв\n74324 = Daily Greens", $err );
ok( 'parse: 4 валидни реда', 4 === count( $rows ), count( $rows ) );
ok( 'parse: вариация → родител (28729 → 28726), SKU → родител, без дубли', array( 28726 ) === $rows[1]['ids'] && 'Sakura SkinTonik' === $rows[1]['name'], json_encode( $rows[1] ) );
ok( 'parse: интервал като разделител, вариация 15838 → 15836', array( 15836 ) === $rows[2]['ids'] );
ok( 'parse: грешките се докладват (без =, без ID, непознат ID)', 3 === count( $err ), implode( ' | ', $err ) );
Favorite::save( "3994 = Daily Greens\n28726 = Sakura SkinTonik\n15836 = YoniBiotic\n74324 = Daily Greens", 'packs', 'cancelled, refunded, failed, pending, trash', 'любимата ти добавка', 100 );
$s = Favorite::settings(); $map = Favorite::map( $s );
ok( 'map: 74324 (сет) е псевдоним на Daily Greens', 'Daily Greens' === $map[74324]['name'] && 0 === $map[74324]['order'] );
ok( 'count_statuses: без мъртвите, с delivered/at-office/shipped', in_array( 'wc-delivered', Favorite::count_statuses( $s ), true ) && in_array( 'wc-at-office', Favorite::count_statuses( $s ), true ) && ! in_array( 'wc-cancelled', Favorite::count_statuses( $s ), true ) && ! in_array( 'wc-pending', Favorite::count_statuses( $s ), true ) );

/* ── опаковките във вариацията ── */
ok( 'packs: „3 кутии (2+1 ПОДАРЪК)“ → 3 (атрибутът, не SKU-то с 6 части)', 3 === Favorite::packs_of( new FakeItem( 3994, 3997, 1, 10 ) ) );
ok( 'packs: празен атрибут → SKU „SKST;×5“ → 5', 5 === Favorite::packs_of( new FakeItem( 28726, 28729, 1, 10 ) ) );
ok( 'packs: прост продукт → 1', 1 === Favorite::packs_of( new FakeItem( 3870, 0, 4, 10 ) ) );

/* ── сметката ── */
$T = 1760000000;
$GLOBALS['orders'] = array(
	new FakeOrder( 1, 'Maria@Example.com', 'delivered', array( new FakeItem( 3994, 3997, 1, 60.0 ), new FakeItem( 3870, 0, 3, 45.0 ) ), $T - 30 * 86400 ),        /* 3 опаковки Greens + 3 Омега (извън картата) */
	new FakeOrder( 2, 'maria@example.com', 'processing', array( new FakeItem( 28726, 28727, 2, 70.0 ) ), $T - 10 * 86400 ),                                           /* 2 Sakura */
	new FakeOrder( 3, 'maria@example.com', 'cancelled', array( new FakeItem( 28726, 28729, 3, 300.0 ) ), $T - 5 * 86400 ),                                            /* отменена: не брои */
	new FakeOrder( 4, 'maria@example.com', 'at-office', array( new FakeItem( 74324, 0, 1, 50.0 ) ), $T - 2 * 86400 ),                                                 /* сетът → +1 Greens */
);
$r = fresh( 'maria@example.com' );
ok( 'packs: Greens 4 (3 + сетът) > Sakura 2; Омега не участва', $r && 'Daily Greens' === $r['name'] && 4.0 == $r['score'] && array( 'Daily Greens' => 4.0, 'Sakura SkinTonik' => 2.0 ) == $r['breakdown'], json_encode( $r ) );
ok( 'резултат: pid = последно купеният за името (сетът 74324), url, 2 поръчки с него от 3 живи, last = поръчка 4', $r && 74324 === $r['pid'] && 'http://x/promo/' === $r['url'] && 2 === $r['orders'] && 3 === $r['all_orders'] && $T - 2 * 86400 === $r['last'], json_encode( $r ) );
ok( 'имейлът е без значение на регистъра', $r && fresh( 'MARIA@example.com' ) == $r );
ok( 'value(fav) / value(fav_url)', 'Daily Greens' === Favorite::value( 'fav', 'maria@example.com' ) && 'http://x/promo/' === Favorite::value( 'fav_url', 'maria@example.com' ) );
/* кеш: втори compute без fresh не прави заявки */
$qn = count( $GLOBALS['q'] ); Favorite::compute( 'maria@example.com' ); ok( 'кеш: без нова заявка', $qn === count( $GLOBALS['q'] ) );
/* метрики */
$SKIP = implode( ', ', Favorite::SKIP );
Favorite::save( "3994 = Daily Greens\n28726 = Sakura SkinTonik\n15836 = YoniBiotic\n74324 = Daily Greens", 'qty', $SKIP, '', 100 );
$r = fresh( 'maria@example.com' ); ok( 'qty: Greens 2 (1 + 1) = Sakura 2 → равенство → по-скорошният (Greens, поръчка 4)', $r && 'Daily Greens' === $r['name'] && 2.0 == $r['score'] && 2.0 == $r['breakdown']['Sakura SkinTonik'], json_encode( $r ) );
Favorite::save( "3994 = Daily Greens\n28726 = Sakura SkinTonik\n15836 = YoniBiotic", 'qty', $SKIP, '', 100 ); /* без сета */
$r = fresh( 'maria@example.com' ); ok( 'qty без псевдонима: Sakura 2 > Greens 1', $r && 'Sakura SkinTonik' === $r['name'], json_encode( $r ) );
Favorite::save( "3994 = Daily Greens\n28726 = Sakura SkinTonik", 'spent', $SKIP, '', 100 );
$r = fresh( 'maria@example.com' ); ok( 'spent: Sakura 70 > Greens 60 (отменените 300 не броят)', $r && 'Sakura SkinTonik' === $r['name'] && 70.0 == $r['score'], json_encode( $r ) );
Favorite::save( "3994 = Daily Greens\n28726 = Sakura SkinTonik\n74324 = Daily Greens", 'orders', $SKIP, '', 100 );
$r = fresh( 'maria@example.com' ); ok( 'orders: Greens в 2 поръчки > Sakura в 1', $r && 'Daily Greens' === $r['name'] && 2.0 == $r['score'], json_encode( $r ) );
/* skip статусите са настройка */
Favorite::save( "3994 = Daily Greens\n28726 = Sakura SkinTonik", 'packs', 'pending', '', 100 );
$r = fresh( 'maria@example.com' ); ok( 'ако cancelled не е в „не броят“: Sakura 2 + 3×5 = 17 > Greens 3', $r && 'Sakura SkinTonik' === $r['name'] && 17.0 == $r['score'], json_encode( $r ) );
/* fallback */
Favorite::save( "3994 = Daily Greens", 'packs', 'cancelled', 'любимата ти добавка', 100 );
ok( 'няма поръчки → fallback от настройките; fav_url празно', 'любимата ти добавка' === Favorite::value( 'fav', 'nobody@example.com' ) && '' === Favorite::value( 'fav_url', 'nobody@example.com' ) && null === fresh( 'nobody@example.com' ) );
$GLOBALS['orders'][] = new FakeOrder( 5, 'omega@example.com', 'delivered', array( new FakeItem( 3870, 0, 2, 30.0 ) ), $T );
ok( 'поръчки само извън картата → null / fallback', null === fresh( 'omega@example.com' ) && 'любимата ти добавка' === Favorite::value( 'fav', 'omega@example.com' ) );
/* по WP акаунт: поръчка с друг billing имейл, но customer_id на user@example.com (id 7) */
$GLOBALS['orders'][] = new FakeOrder( 6, 'other@example.com', 'delivered', array( new FakeItem( 3994, 3995, 2, 40.0 ) ), $T, 7 );
$GLOBALS['orders'][] = new FakeOrder( 7, 'user@example.com', 'delivered', array( new FakeItem( 3994, 3995, 1, 20.0 ), new FakeItem( 28726, 28727, 1, 20.0 ) ), $T - 86400, 7 );
Favorite::save( "3994 = Daily Greens\n28726 = Sakura SkinTonik", 'packs', $SKIP, '', 100 );
$r = fresh( 'user@example.com' ); ok( 'акаунтът: поръчка 6 (друг имейл, същият customer) се брои веднъж → Greens 3', $r && 'Daily Greens' === $r['name'] && 3.0 == $r['score'] && 2 === $r['all_orders'], json_encode( $r ) );
ok( 'orders_for: без дубли', 2 === count( Favorite::orders_for( 'user@example.com' ) ) );

/* ── Merge::shortcode / FKA пътят ── */
ok( 'Merge::TAGS има fav/fav_url (шорткодовете се регистрират от същия списък)', isset( Merge::TAGS['fav'] ) && isset( Merge::TAGS['fav_url'] ) );
ok( 'shortcode: без контакт/поръчка → fallback на тага', 'X' === Merge::shortcode( 'fav', array( 'fallback' => 'X' ) ) && '' === Merge::shortcode( 'fav', array() ) );
ok( 'shortcode: order_id → имейлът на поръчката', 'Daily Greens' === Merge::shortcode( 'fav', array( 'order_id' => 7 ) ) && 'http://x/promo/' === Merge::shortcode( 'fav_url', array( 'order_id' => 7 ) ) );
$GLOBALS['is_admin'] = false; ok( 'shortcode: email="" се пренебрегва без права', '' === Merge::shortcode( 'fav', array( 'email' => 'maria@example.com' ) ) );
$GLOBALS['is_admin'] = true; ok( 'shortcode: email="" с права', 'Daily Greens' === Merge::shortcode( 'fav', array( 'email' => 'maria@example.com' ) ) );
ok( 'dummy: първото име от картата + линкът му', 'Daily Greens' === Favorite::dummy( 'fav' ) && 'http://x/promo/' === Favorite::dummy( 'fav_url' ) && 'Daily Greens' === Merge::dummy( 'fav' ) );
/* статус / защита */
Favorite::save( "3994 = Daily Greens\nabc = Х", 'packs', $SKIP, '', 100 ); $st = Favorite::status();
ok( 'status: брои продуктите и показва грешката', false !== strpos( $st[0], '1 продукта' ) && false !== strpos( $st[0], '⚠' ) && 'warn' === $st[1], $st[0] );
ok( 'save → flush на кеша', $wpdb->deleted > 0 );
Favorite::save( '', 'nope', '', '', 5 ); $s = Favorite::settings();
ok( 'settings: непозната метрика → packs, limit ≥ 10, празна карта → по подразбиране от черновата (без WC продукти → празна)', 'packs' === $s['metric'] && 10 === $s['limit'] && ! $s['custom'] );

/* ── 1.20.1: пътищата през данните на FKA + дневникът ── */
Favorite::save( "3994 = Daily Greens\n28726 = Sakura SkinTonik", 'packs', $SKIP, 'любимата ти добавка', 100 ); Favorite::log_clear(); $GLOBALS['is_admin'] = false;
BWFAN_Merge_Tag_Loader::$data = array( 'email' => 'Maria@Example.com' );
ok( 'FKA data[email] → Daily Greens', 'Daily Greens' === Merge::shortcode( 'fav', array( 'fallback' => 'X' ) ) );
BWFAN_Merge_Tag_Loader::$data = array( 'contact' => new FakeCRMContact( 'maria@example.com' ) );
ok( 'FKA data[contact] (BWFCRM → ->contact->get_email) → Daily Greens', 'Daily Greens' === Merge::shortcode( 'fav', array() ) );
BWFAN_Merge_Tag_Loader::$data = array( 'cid' => 42 );
ok( 'FKA data[cid] → WooFunnels_Contact по id → имейл', 'Daily Greens' === Merge::shortcode( 'fav', array() ) );
BWFAN_Merge_Tag_Loader::$data = array( 'something' => array( 'billing_email' => 'maria@example.com' ) );
ok( 'FKA: имейл дълбоко в данните (array[billing_email])', 'Daily Greens' === Merge::shortcode( 'fav', array() ) );
BWFAN_Merge_Tag_Loader::$data = array( 'wc_order' => wc_get_order( 7 ) );
ok( 'FKA data[wc_order] → billing имейлът на поръчката', 'Daily Greens' === Merge::shortcode( 'fav', array() ) );
/* поръчката на event-а е още pending → брои се (Sakura 3 в нея > Greens 1 от старата) */
$GLOBALS['orders'][] = new FakeOrder( 8, 'new@example.com', 'pending', array( new FakeItem( 28726, 28727, 3, 60.0 ) ), $T );
$GLOBALS['orders'][] = new FakeOrder( 9, 'new@example.com', 'delivered', array( new FakeItem( 3994, 3995, 1, 20.0 ) ), $T - 86400 );
BWFAN_Merge_Tag_Loader::$data = array( 'email' => 'new@example.com', 'order_id' => 8 );
ok( 'поръчката на event-а (pending) се брои → Sakura 3 > Greens 1', 'Sakura SkinTonik' === Merge::shortcode( 'fav', array() ), Merge::shortcode( 'fav', array() ) );
ok( 'без event: pending не брои → Greens', 'Daily Greens' === Favorite::value( 'fav', 'new@example.com' ) );
BWFAN_Merge_Tag_Loader::$data = array( 'email' => 'x@example.com', 'order_id' => 8 );
ok( 'event поръчка с друг billing имейл пак се брои (без дубли)', 'Sakura SkinTonik' === Merge::shortcode( 'fav', array() ) );
BWFAN_Merge_Tag_Loader::$data = array( 'is_preview' => true );
ok( 'is_preview → dummy, не fallback', 'Daily Greens' === Merge::shortcode( 'fav', array( 'fallback' => 'X' ) ) );
BWFAN_Merge_Tag_Loader::$data = array( 'foo' => 'bar' );
ok( 'без имейл никъде → fallback на тага', 'X' === Merge::shortcode( 'fav', array( 'fallback' => 'X' ) ) );
$log = Favorite::log_rows();
ok( 'дневник: записва всяко извикване, най-новото първо, имейлът е маскиран', count( $log ) >= 9 && 'няма имейл' === $log[0]['src'] && 'foo:\'bar\'' === $log[0]['data'] && 'm***@example.com' === $log[ count( $log ) - 1 ]['email'] && false !== strpos( $log[ count( $log ) - 1 ]['src'], 'email' ) && 'email:string' === $log[ count( $log ) - 1 ]['data'], json_encode( array( $log[0], $log[ count( $log ) - 1 ] ), JSON_UNESCAPED_UNICODE ) );
ok( 'дневник: поръчката на event-а и резултатът', '8 · pending' === $log[2]['order'] && 'Sakura SkinTonik' === $log[2]['result'] && '' === $log[0]['result'] && 'X' === $log[0]['fallback'], json_encode( $log[2], JSON_UNESCAPED_UNICODE ) );
for ( $i = 0; $i < 40; $i++ ) { Favorite::log( array( 'key' => 'fav', 'email' => '' ) ); } ok( 'дневник: най-много 30 реда', 30 === count( Favorite::log_rows() ) );
Favorite::log_clear(); ok( 'дневник: изчистване', array() === Favorite::log_rows() );

if ( $fails ) { echo "FAIL:\n - " . implode( "\n - ", $fails ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
