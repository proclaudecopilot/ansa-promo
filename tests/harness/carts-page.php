<?php
/**
 * 🛒 Изоставени колички в портала (📈 Insights URL) като статична страница — за Playwright скрийншоти и проверки на дизайна.
 * Без WordPress: $wpdb е SQLite в паметта, количките на FunnelKit, работата на екипа и поръчките са измислени (но правдоподобни).
 * php tests/harness/carts-page.php [query] > /tmp/carts.html ; node tests/harness/carts-check.js /tmp/carts.html [shots-dir]
 *   query — като в адреса: "work=all", "q=ivanka", "cust=regular", "kind=season&work=done" …; "admin=1" = рамката на wp-admin; "profile=<ID>" = JSON-ът на панела „👤 Профил“
 */
require dirname( __DIR__ ) . '/wpstub.php';
use AnsaPromo\Insights; use AnsaPromo\Insights_Portal; use AnsaPromo\Cart; use AnsaPromo\Cart_Work;

$GLOBALS['opts'][ \AnsaPromo\Plugin::OPTION ] = array( 'draft' => null, 'published' => array( 'page_id' => 42, 'name' => 'Сезонът на ansa™', 'enabled' => true, 'tombola' => array( 'statuses' => array( 'completed' ) ) ), 'published_at' => time(), 'version' => 1, 'history' => array() ); /* промо страницата има ID → „✏️ Редактирай“ се показва за количките от Сезона */
define( 'ARRAY_A', 'ARRAY_A' ); define( 'ANSA_PROMO_DB_VER', '11' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $u ) { return (string) $u; } function esc_textarea( $s ) { return esc_html( $s ); }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; } function add_query_arg( $a, $u ) { return $u . ( strpos( $u, '?' ) === false ? '?' : '&' ) . http_build_query( $a ); }
function date_i18n( $f, $t = null ) { return date( $f, $t ?? time() ); } function wp_create_nonce( $a ) { return 'n0nce'; } function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="n0nce">'; }
function human_time_diff( $a, $b ) { return 'малко'; } function wp_salt( $s = '' ) { return 'salt'; } function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function current_user_can( $c ) { return false; } function wp_date( $f, $t = null ) { return date( $f, $t ?? time() ); } function is_admin() { return false; } function is_ssl() { return true; } function home_url( $p = '/' ) { return 'https://ansa.test' . $p; }
function is_user_logged_in() { return false; } function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; } function wp_next_scheduled() { return 1; } function wp_unslash( $v ) { return $v; }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; } function wp_timezone() { return new DateTimeZone( date_default_timezone_get() ); }
function wc_get_order_status_name( $s ) { return array( 'completed' => 'Доставена', 'processing' => 'Обработва се', 'cancelled' => 'Отказана', 'shipped' => 'Изпратена' )[ $s ] ?? $s; } function wc_get_order_statuses() { return array( 'wc-processing' => 'Pr', 'wc-completed' => 'C' ); } function wc_get_product_id_by_sku( $sku ) { return 0; }
class HFakeDate { private $d; function __construct( $d ) { $this->d = $d; } function date( $f ) { return date( $f, strtotime( $this->d ) ); } }
class HFakeItem { private $n; private $q; function __construct( $n, $q ) { $this->n = $n; $this->q = $q; } function get_name() { return $this->n; } function get_quantity() { return $this->q; } }
class HFakeOrder { public $id; public $email; public $date; public $total; public $st; public $items; function __construct( $id, $email, $date, $total, $st = 'completed', $items = array() ) { $this->id = $id; $this->email = $email; $this->date = $date; $this->total = $total; $this->st = $st; $this->items = $items; } function get_id() { return $this->id; } function get_order_number() { return (string) $this->id; } function get_status() { return $this->st; } function get_total() { return $this->total; } function get_date_created() { return new HFakeDate( $this->date ); } function get_billing_email() { return $this->email; } function get_billing_first_name() { return ''; } function get_billing_last_name() { return ''; } function get_billing_phone() { return ''; } function get_meta( $k ) { return ''; } function get_items() { $o = array(); foreach ( $this->items as $n => $q ) { $o[] = new HFakeItem( $n, $q ); } return $o; } }
$GLOBALS['orders'] = array();
function wc_get_order( $id ) { return $GLOBALS['orders'][ (int) $id ] ?? null; }
function wc_get_orders( $a ) { $out = array(); foreach ( $GLOBALS['orders'] as $id => $o ) { if ( ! empty( $a['billing_email'] ) && strtolower( $a['billing_email'] ) !== $o->email ) { continue; } $out[ $id ] = $o; } uasort( $out, function ( $a, $b ) { return strcmp( $b->date, $a->date ); } ); if ( ! empty( $a['limit'] ) && $a['limit'] > 0 ) { $out = array_slice( $out, 0, (int) $a['limit'], true ); } return 'ids' === ( $a['return'] ?? '' ) ? array_keys( $out ) : array_values( $out ); }
class HFakeProduct { private $n; function __construct( $n ) { $this->n = $n; } function get_name() { return $this->n; } }
$GLOBALS['pnames'] = array( 501 => 'ansa™ Daily Greens', 502 => 'ansa™ Витамин D3 + K2', 503 => 'ansa™ Магнезий Бисглицинат', 504 => 'ansa™ Колаген Sakura', 505 => 'ansa™ Омега-3', 9 => 'Книга „Сезонът на ansa“', 20 => 'Таен козметичен сет', 601 => 'ansa™ Daily Greens — оферта Черен петък', 602 => 'ansa™ – CollaMask - Попиваща колагенова маска', 701 => 'ansa™ Пробиотик 30 капсули', 702 => 'ansa™ EyeFresh пачове' );
function wc_get_product( $id ) { return isset( $GLOBALS['pnames'][ (int) $id ] ) ? new HFakeProduct( $GLOBALS['pnames'][ (int) $id ] ) : null; }

class HFakeWpdb {
	public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0; private $db;
	function __construct() { $this->db = new PDO( 'sqlite::memory:' ); $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT ); $this->db->sqliteCreateFunction( 'CONCAT', function () { return implode( '', func_get_args() ); } ); }
	function get_charset_collate() { return ''; } function esc_like( $s ) { return $s; }
	function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; } $i = 0; return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ] ?? ''; return 'd' === $m[0][1] ? (string) (int) $v : ( 'f' === $m[0][1] ? (string) (float) $v : "'" . str_replace( "'", "''", (string) $v ) . "'" ); }, $q ); }
	function query( $q ) { return $this->db->exec( $q ); }
	function get_results( $q, $t = ARRAY_A ) { if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $q, $m ) ) { $q = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $m[1] . "'"; } $st = $this->db->query( $q ); if ( ! $st ) { $this->last_error = implode( ' ', $this->db->errorInfo() ); fwrite( STDERR, $this->last_error . ' ← ' . $q . "\n" ); return array(); } return $st->fetchAll( PDO::FETCH_ASSOC ); }
	function get_row( $q, $t = ARRAY_A ) { $r = $this->get_results( $q ); return $r[0] ?? null; }
	function get_col( $q ) { $out = array(); foreach ( $this->get_results( $q ) as $r ) { $out[] = array_values( $r )[0]; } return $out; }
	function get_var( $q ) { $r = $this->get_row( $q ); return $r ? array_values( $r )[0] : null; }
	function insert( $tb, $d, $f = null ) { $c = array_keys( $d ); $st = $this->db->prepare( "INSERT INTO $tb (" . implode( ',', $c ) . ') VALUES (' . implode( ',', array_map( function ( $k ) { return ':' . $k; }, $c ) ) . ')' ); $ok = $st && $st->execute( $d ); $this->insert_id = (int) $this->db->lastInsertId(); return $ok ? 1 : false; }
	function update( $tb, $d, $w ) { $set = implode( ',', array_map( function ( $k ) { return "$k = :s_$k"; }, array_keys( $d ) ) ); $wh = implode( ' AND ', array_map( function ( $k ) { return "$k = :w_$k"; }, array_keys( $w ) ) ); $p = array(); foreach ( $d as $k => $v ) { $p[ "s_$k" ] = $v; } foreach ( $w as $k => $v ) { $p[ "w_$k" ] = $v; } $st = $this->db->prepare( "UPDATE $tb SET $set WHERE $wh" ); return $st && $st->execute( $p ) ? $st->rowCount() : false; }
}
$GLOBALS['wpdb'] = $wpdb = new HFakeWpdb();
$wpdb->query( 'CREATE TABLE wp_ansa_promo_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, sid TEXT NOT NULL, started_at TEXT NOT NULL, last_at TEXT NOT NULL, utm TEXT NOT NULL DEFAULT "", utm_last TEXT NOT NULL DEFAULT "", src TEXT NOT NULL DEFAULT "", med TEXT NOT NULL DEFAULT "", camp TEXT NOT NULL DEFAULT "", ref TEXT NOT NULL DEFAULT "", device TEXT NOT NULL DEFAULT "", vid TEXT NOT NULL DEFAULT "", internal INTEGER NOT NULL DEFAULT 0, variant TEXT NOT NULL DEFAULT "", landers TEXT NOT NULL DEFAULT "", lander_at TEXT NULL, entry_qs TEXT NOT NULL DEFAULT "", entry_ref TEXT NOT NULL DEFAULT "", touch_qs TEXT NOT NULL DEFAULT "", touch_ref TEXT NOT NULL DEFAULT "", lead_id INTEGER NULL, box TEXT NOT NULL DEFAULT "", items TEXT NULL, step TEXT NOT NULL DEFAULT "view", order_id INTEGER NULL, ip TEXT NULL)' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_events (id INTEGER PRIMARY KEY AUTOINCREMENT, sid TEXT NOT NULL, at TEXT NOT NULL, ev TEXT NOT NULL, box TEXT NOT NULL DEFAULT "", k TEXT NOT NULL DEFAULT "", n INTEGER NOT NULL DEFAULT 0, utm TEXT NOT NULL DEFAULT "", variant TEXT NOT NULL DEFAULT "")' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_leads (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)' );
$wpdb->query( 'CREATE TABLE wp_bwfan_abandonedcarts (ID INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, status INTEGER, order_id INTEGER, created_time TEXT, last_modified TEXT, total REAL, currency TEXT, items TEXT, checkout_data TEXT)' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_cart_work (id INTEGER PRIMARY KEY AUTOINCREMENT, src TEXT NOT NULL DEFAULT "cart", src_id INTEGER NOT NULL, email TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "new", gesture TEXT NOT NULL DEFAULT "", note TEXT NULL, callback_at TEXT NULL, order_id INTEGER NULL, by_user TEXT NOT NULL DEFAULT "", log TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)' );
set_transient( 'ansa_promo_ins_backfill', 1 );

/* ── количките (FunnelKit) ── */
$M = Cart::META; $now = time(); $ago = function ( $min ) use ( $now ) { return date( 'Y-m-d H:i:s', $now - $min * 60 ); };
$season = function ( $box, $packs, $pct, $lines, $extra = array() ) use ( $M ) { $o = array(); foreach ( $lines as $i => $l ) { $o[ 'k' . $i ] = array( 'product_id' => $l[0], 'variation_id' => 0, 'quantity' => $l[1], 'line_total' => 20 * $l[1], $M => array_merge( array( 'role' => $l[2] ?? 'base', 'key' => 'p' . $l[0], 'box' => $box, 'packs' => $packs, 'pct' => $pct, 'week' => 3, 'promo' => 'orange_q4_2026' ), $extra ) ); } return serialize( $o ); };
$chk = function ( $first, $last, $phone ) { return json_encode( array( 'fields' => array( 'billing_first_name' => $first, 'billing_last_name' => $last, 'billing_phone' => $phone ) ) ); };
$C = function ( $email, $status, $order, $created, $mod, $total, $items, $cd ) use ( $wpdb, $ago ) { $wpdb->insert( 'wp_bwfan_abandonedcarts', array( 'email' => $email, 'status' => $status, 'order_id' => $order, 'created_time' => $ago( $created ), 'last_modified' => $ago( $mod ), 'total' => $total, 'currency' => 'EUR', 'items' => $items, 'checkout_data' => $cd ) ); return (int) $wpdb->insert_id; };
$bf = serialize( array( 'k1' => array( 'product_id' => 601, 'quantity' => 1, 'line_total' => 35, 'ansa_promo_bf' => array( 'role' => 'base', 'key' => 'greens', 'promo' => 'bf_2026' ) ), 'k2' => array( 'product_id' => 602, 'quantity' => 1, 'line_total' => 0, 'ansa_promo_bf' => array( 'role' => 'gift', 'key' => 'mask' ) ) ) );
$shop = serialize( array( 'k1' => array( 'product_id' => 701, 'quantity' => 2, 'line_total' => 50 ), 'k2' => array( 'product_id' => 702, 'quantity' => 1, 'line_total' => 18 ) ) );
$shop1 = serialize( array( 'k1' => array( 'product_id' => 505, 'quantity' => 1, 'line_total' => 27.9 ) ) );
$id = array();
$id['a'] = $C( 'ivanka.petrova@abv.bg', 1, 0, 95, 70, 148.5, $season( 'l', 5, 40, array( array( 504, 5 ), array( 20, 1, 'secret' ), array( 9, 1, 'book' ) ) ), $chk( 'Иванка', 'Петрова', '0888 123 456' ) );
$id['b'] = $C( 'maria.georgieva@gmail.com', 1, 0, 60 * 26, 60 * 25, 89.4, $season( 'm', 3, 30, array( array( 501, 2 ), array( 502, 1 ), array( 9, 1, 'book' ) ) ), $chk( 'Мария', 'Георгиева', '+359 887 654 321' ) );
$id['c'] = $C( 'deti_67@abv.bg', 1, 0, 60 * 50, 60 * 49, 35, $bf, $chk( 'Дети', 'Стоянова', '0877111222' ) );
$id['d'] = $C( 'elena.dimitrova@yahoo.com', 3, 0, 60 * 30, 60 * 29, 68, $shop, $chk( 'Елена', 'Димитрова', '0899 555 010' ) );
$id['e'] = $C( 'petya.n@mail.bg', 5, 0, 9, 4, 59.6, $season( 's', 2, 20, array( array( 503, 2 ) ), array( 'flow' => 1 ) ), $chk( 'Петя', 'Николова', '' ) );
$id['f'] = $C( 'tsvetelina.koleva@gmail.com', 1, 0, 60 * 75, 60 * 74, 119.2, $season( 'm', 4, 30, array( array( 501, 1 ), array( 503, 1 ), array( 505, 2 ), array( 20, 1, 'secret' ) ) ), $chk( 'Цветелина', 'Колева', '0878 200 300' ) );
$id['g'] = $C( 'nadya_ilieva@abv.bg', 1, 0, 60 * 98, 60 * 97, 27.9, $shop1, $chk( 'Надя', 'Илиева', '0885 909 808' ) );
$id['h'] = $C( 'radostina.v@gmail.com', 1, 0, 60 * 122, 60 * 121, 148.5, $season( 'l', 5, 40, array( array( 504, 3 ), array( 502, 2 ), array( 602, 1, 'pgift' ) ) ), $chk( 'Радостина', 'Василева', '0896 121 212' ) );
$id['i'] = $C( 'silviya.todorova@abv.bg', 4, 9101, 60 * 54, 60 * 20, 89.4, $season( 'm', 3, 30, array( array( 501, 3 ) ) ), $chk( 'Силвия', 'Тодорова', '0888 404 040' ) );
$id['j'] = $C( 'gergana.m@icloud.com', 2, 0, 60 * 140, 60 * 46, 68, $shop, $chk( 'Гергана', 'Маринова', '0879 333 444' ) );
$id['k'] = $C( 'no.phone@example.com', 1, 0, 60 * 5, 60 * 4, 59.6, $season( 's', 2, 20, array( array( 502, 2 ) ) ), '' );
$id['l'] = $C( 'viktoria.angelova@gmail.com', 2, 0, 60 * 170, 60 * 169, 247.5, $season( 'l', 5, 40, array( array( 504, 5 ), array( 501, 2 ), array( 20, 1, 'secret' ), array( 9, 1, 'book' ) ) ), $chk( 'Виктория', 'Ангелова', '0887 700 700' ) );

/* ── сесии в промото (лийд от попъпа): излизат при търсене и в 🧾 Сесии ── */
$wpdb->insert( 'wp_ansa_promo_leads', array( 'email' => 'maria.georgieva@gmail.com' ) ); $wpdb->insert( 'wp_ansa_promo_leads', array( 'email' => 'samo.sesia@abv.bg' ) );
foreach ( array( array( 'hsessM001', 1, 'full', 60 * 27, '["dg","dg","vit"]' ), array( 'hsessS001', 2, 'box', 60 * 3, '["dg"]' ) ) as $x ) { $wpdb->insert( 'wp_ansa_promo_sessions', array( 'sid' => $x[0], 'started_at' => $ago( $x[3] + 12 ), 'last_at' => $ago( $x[3] ), 'step' => $x[2], 'ip' => '10.0.0.' . $x[1], 'device' => 'mobile', 'box' => 'm', 'lead_id' => $x[1], 'items' => $x[4], 'src' => 'facebook', 'med' => 'paid', 'camp' => 'sezon-sakura' ) ); }
/* ── предишните поръчки (редовен / нов) ── */
foreach ( array(
	array( 8001, 'maria.georgieva@gmail.com', '-40 days', 96.4, 'completed', array( 'ansa™ Daily Greens' => 2, 'ansa™ Витамин D3 + K2' => 1 ) ),
	array( 8002, 'maria.georgieva@gmail.com', '-120 days', 54, 'completed', array( 'ansa™ Магнезий Бисглицинат' => 2 ) ),
	array( 8003, 'maria.georgieva@gmail.com', '-200 days', 160, 'completed', array( 'ansa™ Daily Greens' => 3, 'ansa™ Омега-3' => 2, 'ansa™ – CollaMask - Попиваща колагенова маска' => 1 ) ),
	array( 8007, 'maria.georgieva@gmail.com', '-150 days', 48, 'cancelled', array( 'ansa™ Пробиотик 30 капсули' => 2 ) ),
	array( 8004, 'tsvetelina.koleva@gmail.com', '-15 days', 71.5, 'processing', array( 'ansa™ Колаген Sakura' => 1, 'ansa™ EyeFresh пачове' => 1 ) ),
	array( 8005, 'viktoria.angelova@gmail.com', '-60 days', 310, 'completed', array( 'ansa™ Колаген Sakura' => 5, 'Таен козметичен сет' => 1 ) ),
	array( 8006, 'viktoria.angelova@gmail.com', '-90 days', 120, 'completed', array( 'ansa™ Колаген Sakura' => 2, 'ansa™ Daily Greens' => 1 ) ),
	array( 9101, 'silviya.todorova@abv.bg', '-20 hours', 89.4, 'processing', array( 'ansa™ Daily Greens' => 3 ) ),
) as $o ) { $GLOBALS['orders'][ $o[0] ] = new HFakeOrder( $o[0], $o[1], date( 'Y-m-d H:i:s', strtotime( $o[2] ) ), $o[3], $o[4], $o[5] ); }

/* ── работата на екипа ── */
Cart_Work::save( array( 'src' => 'cart', 'id' => $id['b'], 'status' => 'noanswer', 'note' => 'звънях 2 пъти, не вдига' ), 'maria' );
Cart_Work::save( array( 'src' => 'cart', 'id' => $id['b'], 'status' => 'callback', 'cb' => date( 'Y-m-d', $now + 86400 ) . 'T10:30', 'note' => 'на работа е — да звъннем утре сутринта', 'gesture' => 'gift' ), 'maria' );
Cart_Work::save( array( 'src' => 'cart', 'id' => $id['f'], 'status' => 'noanswer', 'note' => '' ), 'adminpromo' );
Cart_Work::save( array( 'src' => 'cart', 'id' => $id['h'], 'status' => 'promised', 'note' => 'ще поръча довечера, пита за срока на доставка', 'gesture' => 'ship' ), 'maria' );
Cart_Work::save( array( 'src' => 'cart', 'id' => $id['i'], 'status' => 'recovered', 'order' => '9101', 'gesture' => 'gift', 'note' => 'поръча с маската' ), 'maria' );
Cart_Work::save( array( 'src' => 'cart', 'id' => $id['j'], 'status' => 'declined', 'note' => 'вече е купила от аптека' ), 'adminpromo' );
Cart_Work::save( array( 'src' => 'cart', 'id' => $id['l'], 'status' => 'callback', 'cb' => date( 'Y-m-d H:i', $now - 3 * 3600 ), 'note' => 'помоли да звъннем след обяд' ), 'maria' );

/* ── заявката и сесията на портала ── */
parse_str( (string) ( $argv[1] ?? '' ), $qs ); $_GET = array_merge( array( 'view' => 'carts' ), $qs );
$_SERVER['HTTP_USER_AGENT'] = 'harness'; $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https'; $_SERVER['REQUEST_METHOD'] = 'GET';
$_COOKIE[ Insights_Portal::COOKIE ] = Insights_Portal::token( Insights_Portal::settings()['user'] );
if ( ! empty( $qs['admin'] ) ) { /* admin=1: същият екран в рамката на wp-admin (формите към admin-post, линк към поръчката) */
	unset( $_GET['admin'], $_COOKIE[ Insights_Portal::COOKIE ] ); ob_start(); Insights::render( 30 ); $inner = ob_get_clean(); $a = 'file://' . dirname( __DIR__, 2 ) . '/assets/';
	echo '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="' . $a . 'fonts/nunito.css"><link rel="stylesheet" href="' . $a . 'admin-app.css"></head><body><div id="ansa-promo-app" class="ap-tab-insights"><aside class="ap-nav"><div class="ap-brand"><span class="dot"></span>ansa™ Промо<small>wp-admin</small></div></aside><div class="ap-page ap-page--insights"><div class="ap-top"><h1>📈 Insights<small>wp-admin</small></h1></div><div class="ap-body"><div class="ap-body-inner ap-body-inner--wide">' . $inner . '</div></div></div></div></body></html>';
	return;
}
Insights::$portal = true;
if ( ! empty( $qs['profile'] ) ) { echo json_encode( array( 'success' => true, 'data' => Insights::customer_payload( 'cart', (int) $qs['profile'] ) ), JSON_UNESCAPED_UNICODE ); return; } /* profile=<ID на количка>: отговорът на ajax-а за панела „👤 Профил“ */
if ( 'sessions' === $_GET['view'] ) { $_GET += array( 'from' => date( 'Y-m-d', $now - 5 * 86400 ), 'to' => date( 'Y-m-d' ) ); }
$m = new ReflectionMethod( Insights_Portal::class, 'page_html' ); $m->setAccessible( true ); $html = $m->invoke( null );
if ( ! empty( $qs['msg'] ) ) { /* съобщението след действие идва от адреса — вече е в страницата */ }
$assets = 'file://' . dirname( __DIR__, 2 ) . '/assets/';
echo str_replace( ANSA_PROMO_URL . 'assets/', $assets, $html );
