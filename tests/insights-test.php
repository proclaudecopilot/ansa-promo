<?php
/**
 * Офлайн тест на Insights 1.21.0 — период (range), sessions_report с от–до (funnel/stopped/посетители), sessions_where/sessions_count,
 * feed с филтри, CSV по филтър и трите екрана (📊 Преглед · 🟢 На живо · 📜 История) без WordPress: $wpdb е SQLite в паметта.
 * php tests/insights-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Insights; use AnsaPromo\Schema;

/* ── WP/WC стъбове ── */
define( 'ARRAY_A', 'ARRAY_A' ); define( 'ANSA_PROMO_DB_VER', '5' );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $u ) { return (string) $u; }
function admin_url( $p = '' ) { return 'http://x/wp-admin/' . $p; } function add_query_arg( $a, $u ) { return $u . ( strpos( $u, '?' ) === false ? '?' : '&' ) . http_build_query( $a ); }
function date_i18n( $f, $t = null ) { return date( $f, $t ?? time() ); } function wp_create_nonce( $a ) { return 'n0nce'; } function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="n0nce">'; }
function human_time_diff( $a, $b ) { return 'малко'; } function wp_salt( $s = '' ) { return 'salt'; } function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function current_user_can( $c ) { return true; } function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); } function is_admin() { return false; } function is_ssl() { return true; } function home_url( $p = '/' ) { return 'https://ansa.test' . $p; } function is_user_logged_in() { return false; } function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; } function get_user_meta() { return ''; } function update_user_meta() { return true; } function wp_next_scheduled() { return 1; } function wc_get_order_status_name( $s ) { return 'статус ' . $s; } function wp_unslash( $v ) { return $v; }
class FakeDate { private $d; function __construct( $d ) { $this->d = $d; } function date( $f ) { return date( $f, strtotime( $this->d ) ); } function getTimestamp() { return strtotime( $this->d ); } }
class FakeOrder { private $id; public $meta; public $date; public $ua; public $ip; public $status; public $total; function __construct( $id, $meta = array(), $date = '2026-10-16 10:00:00', $ua = '', $ip = '9.9.9.9', $status = 'processing', $total = 99.5 ) { $this->id = $id; $this->meta = $meta; $this->date = $date; $this->ua = $ua; $this->ip = $ip; $this->status = $status; $this->total = $total; } function get_id() { return $this->id; } function get_order_number() { return 'P' . $this->id; } function get_status() { return $this->status; } function get_total() { return $this->total; } function get_edit_order_url() { return 'http://x/order/' . $this->id; } function get_billing_email() { return 'buyer' . $this->id . '@example.com'; } function get_billing_first_name() { return 'Мария'; } function get_billing_last_name() { return 'П.'; }
	public $extra = array(); function update_meta_data( $k, $v ) { $this->extra[ $k ] = $v; } function save_meta_data() {} /* 1.24.26 */
	function get_meta( $k ) { return '_ansa_promo' === $k ? $this->meta : ( '_ansa_promo_id' === $k ? 'promo' : ( $this->extra[ $k ] ?? '' ) ); } function get_date_created() { return new FakeDate( $this->date ); } function get_customer_user_agent() { return $this->ua; } function get_customer_ip_address() { return $this->ip; } }
$GLOBALS['orders'] = array();
function wc_get_order( $id ) { return $GLOBALS['orders'][ (int) $id ] ?? ( $id > 0 ? new FakeOrder( (int) $id ) : null ); }
function wc_get_order_statuses() { return array( 'wc-pending' => 'P', 'wc-processing' => 'Pr', 'wc-completed' => 'C', 'wc-cancelled' => 'X' ); }
/* wc_get_orders: само ids; date_created „ts...ts“ по датата на поръчката */
function wc_get_orders( $a ) { $out = array(); $rg = null; if ( ! empty( $a['date_created'] ) ) { $rg = array_map( 'intval', explode( '...', $a['date_created'] ) ); } foreach ( $GLOBALS['orders'] as $id => $o ) { $t = strtotime( $o->date ); if ( $rg && ( $t < $rg[0] || $t > $rg[1] ) ) { continue; } $out[ $id ] = $o; } krsort( $out ); return 'ids' === ( $a['return'] ?? '' ) ? array_keys( $out ) : array_values( $out ); }
function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; } function wp_timezone() { return new DateTimeZone( date_default_timezone_get() ); }
/* $wpdb → SQLite */
class FakeWpdb {
	public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0; private $db;
	function __construct() { $this->db = new PDO( 'sqlite::memory:' ); $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT ); $this->db->sqliteCreateFunction( 'CONCAT', function () { return implode( '', func_get_args() ); } ); }
	function get_charset_collate() { return ''; } function esc_like( $s ) { return $s; }
	function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; } $i = 0; return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ] ?? ''; return 'd' === $m[0][1] ? (string) (int) $v : ( 'f' === $m[0][1] ? (string) (float) $v : "'" . str_replace( "'", "''", (string) $v ) . "'" ); }, $q ); }
	function query( $q ) { $r = $this->db->exec( $q ); if ( false === $r ) { $this->last_error = implode( ' ', $this->db->errorInfo() ); } return $r; }
	function get_results( $q, $t = ARRAY_A ) { if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $q, $m ) ) { $q = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $m[1] . "'"; } $st = $this->db->query( $q ); if ( ! $st ) { $this->last_error = implode( ' ', $this->db->errorInfo() ); $GLOBALS['sql_errors'][] = $this->last_error . ' ← ' . $q; return array(); } return $st->fetchAll( PDO::FETCH_ASSOC ); }
	function get_row( $q, $t = ARRAY_A ) { $r = $this->get_results( $q ); return $r[0] ?? null; }
	function get_col( $q ) { $out = array(); foreach ( $this->get_results( $q ) as $r ) { $out[] = array_values( $r )[0]; } return $out; }
	function get_var( $q ) { $r = $this->get_row( $q ); return $r ? array_values( $r )[0] : null; }
	function insert( $tb, $d, $f = null ) { $c = array_keys( $d ); $st = $this->db->prepare( "INSERT INTO $tb (" . implode( ',', $c ) . ') VALUES (' . implode( ',', array_map( function ( $k ) { return ':' . $k; }, $c ) ) . ')' ); $ok = $st && $st->execute( $d ); $this->insert_id = (int) $this->db->lastInsertId(); return $ok ? 1 : false; }
	function update( $tb, $d, $w ) { $set = implode( ',', array_map( function ( $k ) { return "$k = :s_$k"; }, array_keys( $d ) ) ); $wh = implode( ' AND ', array_map( function ( $k ) { return "$k = :w_$k"; }, array_keys( $w ) ) ); $p = array(); foreach ( $d as $k => $v ) { $p[ "s_$k" ] = $v; } foreach ( $w as $k => $v ) { $p[ "w_$k" ] = $v; } $st = $this->db->prepare( "UPDATE $tb SET $set WHERE $wh" ); return $st && $st->execute( $p ) ? $st->rowCount() : false; }
	function delete( $tb, $w ) { $wh = implode( ' AND ', array_map( function ( $k ) { return "$k = :$k"; }, array_keys( $w ) ) ); $st = $this->db->prepare( "DELETE FROM $tb WHERE $wh" ); return $st && $st->execute( $w ) ? $st->rowCount() : false; }
}
$GLOBALS['wpdb'] = $wpdb = new FakeWpdb(); $GLOBALS['sql_errors'] = array();
$wpdb->query( 'CREATE TABLE wp_ansa_promo_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, sid TEXT NOT NULL, started_at TEXT NOT NULL, last_at TEXT NOT NULL, utm TEXT NOT NULL DEFAULT "", utm_last TEXT NOT NULL DEFAULT "", src TEXT NOT NULL DEFAULT "", med TEXT NOT NULL DEFAULT "", camp TEXT NOT NULL DEFAULT "", ref TEXT NOT NULL DEFAULT "", device TEXT NOT NULL DEFAULT "", vid TEXT NOT NULL DEFAULT "", internal INTEGER NOT NULL DEFAULT 0, lead_id INTEGER NULL, box TEXT NOT NULL DEFAULT "", items TEXT NULL, step TEXT NOT NULL DEFAULT "view", order_id INTEGER NULL, ip TEXT NULL)' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_events (id INTEGER PRIMARY KEY AUTOINCREMENT, sid TEXT NOT NULL, at TEXT NOT NULL, ev TEXT NOT NULL, box TEXT NOT NULL DEFAULT "", k TEXT NOT NULL DEFAULT "", n INTEGER NOT NULL DEFAULT 0, utm TEXT NOT NULL DEFAULT "")' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_leads (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT NOT NULL, step TEXT NOT NULL, box TEXT NOT NULL DEFAULT "", utm TEXT NOT NULL DEFAULT "", n INTEGER NOT NULL DEFAULT 0)' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, code TEXT NOT NULL, tickets INTEGER NOT NULL DEFAULT 1, box TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "issued", created_at TEXT NOT NULL, activated_at TEXT NULL, portal_user TEXT NULL, ip TEXT NULL, ref_code TEXT NULL, bonus INTEGER NOT NULL DEFAULT 0)' );
$wpdb->insert( 'wp_ansa_promo_leads', array( 'email' => 'lead@example.com' ) );
/* поръчките на F, G, I (и K от 15.10) — със sid, вече имат сесия */
$GLOBALS['orders'][11] = new FakeOrder( 11, array( 'sid' => 'sessF0001', 'box' => 'm' ), '2026-10-16 10:04:00', '', '9.9.9.9', 'delivered', 120 ); $GLOBALS['orders'][12] = new FakeOrder( 12, array( 'sid' => 'sessG0001', 'box' => 'l' ), '2026-10-16 10:04:00' );
$GLOBALS['orders'][13] = new FakeOrder( 13, array( 'sid' => 'sessI0001', 'box' => 'm' ), '2026-10-17 10:04:00', '', '9.9.9.9', 'cancelled', 80 ); $GLOBALS['orders'][10] = new FakeOrder( 10, array( 'sid' => 'sessK0001', 'box' => 'm' ), '2026-10-15 10:04:00' );
set_transient( 'ansa_promo_ins_backfill', 1 ); /* екраните по-долу да не правят сверката — тя се тества отделно накрая */

/* ── данни: 16.10 (старт) и 17.10 ──
   16.10: A view · B gate · C box (items) · D full (items, без поръчка) · E add (без поръчка) · F created (поръчка 11) · G paid (поръчка 12); IP: A,B = 1.1.1.1 (един човек), D,E = 2.2.2.2, други свои
   17.10: H full (без поръчка, mobile) · I created (поръчка 13) · J view без IP
   15.10: K paid — ПРЕДИ старта, не влиза в историята по подразбиране */
$S = function ( $sid, $day, $step, $ip, $extra = array() ) use ( $wpdb ) { $wpdb->insert( 'wp_ansa_promo_sessions', array_merge( array( 'sid' => $sid, 'started_at' => "$day 10:00:00", 'last_at' => "$day 10:05:00", 'step' => $step, 'ip' => $ip, 'device' => 'desktop', 'box' => 'm' ), $extra ) ); };
$S( 'sessA0001', '2026-10-16', 'view', '1.1.1.1', array( 'box' => '' ) ); $S( 'sessB0001', '2026-10-16', 'gate', '1.1.1.1', array( 'lead_id' => 1, 'box' => '' ) );
$S( 'sessC0001', '2026-10-16', 'box', '3.3.3.3', array( 'items' => '["dg","dg"]' ) ); $S( 'sessD0001', '2026-10-16', 'full', '2.2.2.2', array( 'items' => '["dg","vit"]' ) );
$S( 'sessE0001', '2026-10-16', 'add', '2.2.2.2' ); $S( 'sessF0001', '2026-10-16', 'created', '4.4.4.4', array( 'order_id' => 11 ) ); $S( 'sessG0001', '2026-10-16', 'paid', '5.5.5.5', array( 'order_id' => 12, 'box' => 'l' ) );
$S( 'sessH0001', '2026-10-17', 'full', '6.6.6.6', array( 'device' => 'mobile' ) ); $S( 'sessI0001', '2026-10-17', 'created', '7.7.7.7', array( 'order_id' => 13 ) ); $S( 'sessJ0001', '2026-10-17', 'view', '' );
$S( 'sessK0001', '2026-10-15', 'paid', '8.8.8.8', array( 'order_id' => 10 ) );
foreach ( array( array( 'sessC0001', 'dg' ), array( 'sessC0001', 'dg' ), array( 'sessD0001', 'dg' ), array( 'sessD0001', 'vit' ), array( 'sessK0001', 'dg' ) ) as $e ) { $wpdb->insert( 'wp_ansa_promo_events', array( 'sid' => $e[0], 'at' => ( 'sessK0001' === $e[0] ? '2026-10-15' : '2026-10-16' ) . ' 10:01:00', 'ev' => 'item', 'k' => $e[1], 'n' => 1 ) ); }
$wpdb->insert( 'wp_ansa_promo_events', array( 'sid' => 'sessG0001', 'at' => '2026-10-16 10:02:00', 'ev' => 'box', 'box' => 'l' ) );

/* ── проверки ── */
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };
set_error_handler( function ( $no, $msg, $file, $line ) { throw new ErrorException( $msg, 0, $no, $file, $line ); } );
$today = date( 'Y-m-d' );

/* range() */
$rg = Insights::range( '', '' ); $ok( 'range по подразбиране: от старта до днес', '2026-10-16' === $rg['from'] && $today === $rg['to'], json_encode( $rg ) );
$rg = Insights::range( '2026-10-20', '2026-10-17' ); $ok( 'range: разменени дати се оправят', '2026-10-17' === $rg['from'] && '2026-10-20' === $rg['to'] );
$rg = Insights::range( '2026-13-40', "x' OR 1=1" ); $ok( 'range: невалидни → по подразбиране', '2026-10-16' === $rg['from'] && $today === $rg['to'] );

/* sessions_report с период */
$r = Insights::sessions_report( 0, array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) ); $f = $r['funnel'];
$ok( 'история: 10 сесии (K от 15.10 не влиза)', 10 === $r['sessions'], (string) $r['sessions'] );
$ok( 'история: уникални посетители 8 (A+B = 1, D+E = 1, J без IP = свой)', 8 === $r['visitors'], (string) $r['visitors'] );
$ok( 'фуния: view 10 · gate 8 · box 7 · full 6 · order 4 · add 4 · created 3 · paid 1 · activated 0', array( 10, 8, 7, 6, 4, 4, 3, 1, 0 ) === array_values( $f ), json_encode( $f ) );
$ok( 'спрели: view 2 · gate 1 · box 1 · full 2 · order 0 · add 1 · created 2 · paid 1', array( 2, 1, 1, 2, 0, 1, 2, 1, 0 ) === array_values( $r['stopped'] ), json_encode( $r['stopped'] ) );
$ok( 'посетители по стъпка: full 5 (D,E един човек), view 8', 5 === $r['vfunnel']['full'] && 8 === $r['vfunnel']['view'], json_encode( $r['vfunnel'] ) );
$ok( 'напълнили, но не поръчали = full − created = 3 (D, E, H)', 3 === $f['full'] - $f['created'] );
$ok( 'по дни: 16.10 → 7 сесии, 17.10 → 3', 7 === $r['by_day']['2026-10-16']['n'] && 3 === $r['by_day']['2026-10-17']['n'] );
$ok( 'продуктите за периода: dg цъкнат от 2 сесии (K от 15.10 не влиза)', 2 === ( $r['items']['dg']['clicked'] ?? 0 ), json_encode( $r['items']['dg'] ?? null ) );
$r1 = Insights::sessions_report( 0, array( 'from' => '2026-10-17', 'to' => '2026-10-17' ) ); $ok( 'само 17.10: 3 сесии, created 1', 3 === $r1['sessions'] && 1 === $r1['funnel']['created'] );
$ok( 'range печели над days', 3 === Insights::sessions_report( 1, array( 'from' => '2026-10-17', 'to' => '2026-10-17' ) )['sessions'] );
$rall = Insights::sessions_report( 0 ); $ok( 'без период: всичко (11, и K)', 11 === $rall['sessions'] && isset( $rall['stopped'] ) );

/* sessions_where / sessions_count / feed */
$q = array( 'from' => '2026-10-16', 'to' => '2026-10-17', 'step' => 'full', 'mode' => 'min', 'ord' => 'no' );
$c = Insights::sessions_count( $q ); $ok( 'филтър „стигнали поне до пълна, без поръчка“: 3 сесии, 2 посетители', 3 === $c['n'] && 2 === $c['v'], json_encode( $c ) );
$ids = array_map( function ( $s ) { return $s['sid']; }, Insights::feed( 50, $q + array( 'sort' => 'started' ) ) ); sort( $ids );
$ok( 'feed по филтъра: D, E, H', array( 'sessD0001', 'sessE0001', 'sessH0001' ) === $ids, json_encode( $ids ) );
$c = Insights::sessions_count( array( 'from' => '2026-10-16', 'to' => '2026-10-17', 'step' => 'full', 'mode' => 'exact' ) ); $ok( '„спрели точно на пълна“: 2 (D, H)', 2 === $c['n'], json_encode( $c ) );
$c = Insights::sessions_count( array( 'from' => '2026-10-16', 'to' => '2026-10-17', 'ord' => 'yes' ) ); $ok( '„с поръчка“: 3 (F, G, I)', 3 === $c['n'], json_encode( $c ) );
$c = Insights::sessions_count( array( 'from' => '2026-10-16', 'to' => '2026-10-17', 'dev' => 'mobile' ) ); $ok( '„телефон“: 1 (H)', 1 === $c['n'] );
$c = Insights::sessions_count( array( 'from' => '2026-10-16', 'to' => '2026-10-17', 'box' => 'l' ) ); $ok( 'кутия l: 1 (G)', 1 === $c['n'] );
$c = Insights::sessions_count( array( 'from' => '2026-10-16', 'to' => '2026-10-17', 'step' => "full' OR 1=1 --", 'box' => "l' OR 1", 'ord' => 'hack' ) ); $ok( 'мръсни филтри: стъпка/ord се игнорират, box остава само с букви (l → G) — заявката не се чупи', 1 === $c['n'] && ! $GLOBALS['sql_errors'], json_encode( $c ) );
$ok( 'sessions_where без филтри е празен', '' === Insights::sessions_where( array() ) );
$pg = Insights::feed( 2, array( 'from' => '2026-10-16', 'to' => '2026-10-17', 'sort' => 'started', 'offset' => 2 ) ); $ok( 'страници: limit 2 offset 2 → H (17.10) и G (16.10), по начало', 2 === count( $pg ) && 'sessH0001' === $pg[0]['sid'] && 'sessG0001' === $pg[1]['sid'], json_encode( array_map( function ( $s ) { return $s['sid'] . ' ' . $s['started']; }, $pg ) ) );
$fd = Insights::feed( 40 ); $ok( 'фийдът без филтри: всичко (11), по последна активност', 11 === count( $fd ) );
$g = array_values( array_filter( $fd, function ( $s ) { return 'sessG0001' === $s['sid']; } ) )[0]; $ok( 'сесия с поръчка: номер, статус, имейл от поръчката', 'P12' === $g['order']['number'] && 'buyer12@example.com' === $g['email'] && 'paid' === $g['step'] );
$b = array_values( array_filter( $fd, function ( $s ) { return 'sessB0001' === $s['sid']; } ) )[0]; $ok( 'лийдът от попъпа: имейлът от leads, 2 сесии на посетителя (A+B)', 'lead@example.com' === $b['email'] && 2 === $b['visits'] );

/* CSV по филтър */
$csv = Insights::csv_sessions( 30, $q ); $lines = array_filter( explode( "\n", trim( $csv ) ) );
$ok( 'CSV по филтъра: заглавие + 3 реда', 4 === count( $lines ), (string) count( $lines ) );
$ok( 'CSV: посетителят е хеш, не IP', false === strpos( $csv, '2.2.2.2' ) && false !== strpos( $csv, 'sessD0001' ) );
$csv30 = Insights::csv_sessions( 0 ); $ok( 'CSV без филтър (старият път): всички 11', 12 === count( array_filter( explode( "\n", trim( $csv30 ) ) ) ) );

/* екраните */
$render = function ( $get ) { $_GET = $get; ob_start(); Insights::render( isset( $get['days'] ) ? (int) $get['days'] : 30 ); return ob_get_clean(); };
$h = $render( array( 'view' => 'history', 'from' => '2026-10-16', 'to' => $today ) ); /* 1.28.0: history = алиас на 🧾 Сесии; периодът е глобален и изричен */
$ok( 'стиловете на екрана се печатат при първия рендер (веднъж на заявка)', 1 === substr_count( $h, '.ap-funnel{' ) && 1 === substr_count( $h, '.ap-ins-f{' ) );
$ok( 'сесии: формата от–до с периода, чиповете на периода, без чипове на изгледите (навигацията е една)', false !== strpos( $h, '🛑 Докъде са стигнали и къде са спрели' ) && false !== strpos( $h, 'name="from" value="2026-10-16"' ) && false !== strpos( $h, 'name="to" value="' . $today . '"' ) && false === strpos( $h, '">📊 Преглед</a>' ) && false !== strpos( $h, '>от старта<' ) );
$h2 = $render( array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) ); $ok( 'преглед: „Поръчки“ = 3 (11, 12, 13; 10 е от 15.10) и „Сесии с поръчка“ = 3 — едни и същи поръчки, по датата на сесията', (bool) preg_match( '/data-k="o_n">3<\/b>/u', $h2 ) && (bool) preg_match( '/data-k="created">3<\/b>/u', $h2 ) && false === strpos( $h2, '⚠ в WooCommerce' ) && false !== strpos( $h2, 'по датата на сесията' ) );
$ok( 'преглед: поръчки по група — взети 1 (11, 120 €), в път 1, отказани 1 (13, 80 €); hero „Реализиран оборот“ 120,00 €', (bool) preg_match( '/data-k="o_delivered">1<\/b>/u', $h2 ) && (bool) preg_match( '/data-k="o_delivered_pct">33,3%<\/b> от поръчките · 120,00 €/u', $h2 ) && (bool) preg_match( '/ap-st--hero"><span class="ap-st__l">Реализиран оборот<span class="ap-q"/u', $h2 ) && (bool) preg_match( '/data-k="rev_real">120,00 €<\/b>/u', $h2 ) && (bool) preg_match( '/data-k="rev_open">99,50 €<\/b>/u', $h2 ) );
$ok( 'преглед: петте групи + таблицата по статус + речникът + баджът на източника', false !== strpos( $h2, '👥 Трафик' ) && false !== strpos( $h2, '🔻 Фуния по сесии' ) && false !== strpos( $h2, '🛒 Поръчки (WooCommerce)' ) && false !== strpos( $h2, '💶 Оборот' ) && false !== strpos( $h2, '📦 Поръчките по статус' ) && (bool) preg_match( '/статус cancelled<\/b> <small>cancelled<\/small><\/td><td>1<\/td><td>33,3%<\/td><td>80,00 €<\/td><td>✕ загубени/u', $h2 ) && false !== strpos( $h2, '📖 Как се смята всяко число' ) && false !== strpos( $h2, 'class="ap-src ap-src--wc"' ) );
$ok( 'преглед: плочка „Напълнили кутията, но НЕ поръчали“ = 3 с линк към списъка в 🧾 Сесии', (bool) preg_match( '/Напълнили кутията, но НЕ поръчали<\/span><span class="ap-st__row"><b class="ap-st__v">3<\/b><\/span><small class="ap-st__s">[^<]*<a href="[^"]*view=sessions[^"]*step=full[^"]*ord=no[^"]*#apInsList">кои →/u', $h2 ) && false !== strpos( $h2, '🛑 Загубени по пътя' ) );
$ok( 'сесии: таблицата „докъде са стигнали и къде са спрели“', (bool) preg_match( '/Напълнили кутията<\/b>.*?mode=min[^"]*"><b>6<\/b>.*?mode=exact[^"]*"><b>2<\/b>/us', $h ) );
$ok( 'сесии: списъкът — 10 сесии, филтрите, без страници (≤ 50)', (bool) preg_match( '/<b>10<\/b> сесии · <b>8<\/b> посетители/u', $h ) && false === strpos( $h, 'спрели точно на „' ) && false !== strpos( $h, 'без поръчка' ) && false === strpos( $h, 'class="ap-pg"' ) );
$ok( 'сесии: CSV формата носи периода и атрибуцията', false !== strpos( $h, 'name="do" value="export_sessions"' ) && false !== strpos( $h, 'name="from" value="2026-10-16"' ) && false !== strpos( $h, 'name="attr" value="first"' ) );
$ok( 'сесии: K от 15.10 не е в списъка, D е', false === strpos( $h, 'buyer10@example.com' ) && false !== strpos( $h, 'buyer11@example.com' ) && false !== strpos( $h, 'lead@example.com' ) );
$h = $render( array( 'view' => 'history', 'from' => '2026-10-16', 'to' => '2026-10-17', 'step' => 'full', 'mode' => 'min', 'ord' => 'no' ) );
$ok( 'история с филтър: 3 сесии · 2 посетители, чиповете са „on“, режимът се вижда', (bool) preg_match( '/<b>3<\/b> сесии · <b>2<\/b> посетители/u', $h ) && false !== strpos( $h, 'спрели точно на „Напълнили кутията“' ) && (bool) preg_match( '/<a class="ap-chip on" href="[^"]*ord=no[^"]*">без поръчка</u', $h ) );
$ok( 'сесии с филтър: само D, E, H в списъка (редовете носят id и посетител)', 3 === preg_match_all( '/<tr data-id="\d+" data-vis="[^"]*"><td><b>10:05<\/b>/u', $h ) );
$h = $render( array( 'view' => 'history', 'from' => '2026-10-17', 'to' => '2026-10-17', 'pg' => '99' ) );
$ok( 'сесии: страница извън обхвата се връща на последната, 3 сесии за 17.10; линковете пазят изгледа и периода', (bool) preg_match( '/<b>3<\/b> сесии/u', $h ) && false !== strpos( $h, 'name="from" value="2026-10-17"' ) && false !== strpos( $h, 'tab=insights&from=2026-10-17&to=2026-10-17&view=sessions&step=' ) );
$h = $render( array( 'view' => 'live', 'n' => '100' ) );
$ok( 'на живо: отделен екран с фийда, превключвателя и дашборда за днес (всички плочки с data-k, sub-овете с data-ks); без диагностиката', false !== strpos( $h, '🟢 На живо — последните сесии' ) && false !== strpos( $h, 'id="apInsLive"' ) && false !== strpos( $h, 'var limit=100;' ) && false !== strpos( $h, '&days=1&limit=' ) && false === strpos( $h, '🩺 Работи ли отчитането' ) && false !== strpos( $h, 'id="apInsDash"' ) && false !== strpos( $h, 'data-k="rev_real"' ) && false !== strpos( $h, 'data-ks="o_delivered"' ) && false !== strpos( $h, 'kpi_sub' ) );
$h = $render( array( 'days' => '0' ) );
$ok( 'преглед: фунията с отпаданията, диагностиката (админ), продуктите, разбивките, трендът; фийдът НЕ е тук', false !== strpos( $h, '🔻 Фунията по сесии' ) && false !== strpos( $h, 'ap-fn__drop' ) && false !== strpos( $h, '🩺 Работи ли отчитането' ) && false !== strpos( $h, '📅 По дни' ) && false === strpos( $h, 'id="apInsFeed"' ) && false !== strpos( $h, 'view=sessions' ) && false !== strpos( $h, 'id="apTrend"' ) && false !== strpos( $h, '📱 × 🛑 Къде спират по устройство' ) );
$h = $render( array( 'view' => 'hack' ) ); $ok( 'непознат view → преглед', false !== strpos( $h, '🔻 Фунията по сесии' ) );
/* ── 1.21.1: сверка с поръчките (човекът: „има 43 поръчки, а Insights отчита 16“) ── */
$GLOBALS['orders'][20] = new FakeOrder( 20, array( 'sid' => '', 'box' => 'm', 'utm' => 'dg', 'lead_id' => 1, 'count' => 3 ), '2026-10-16 09:00:00', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', '20.20.20.20', 'delivered', 150 ); /* преди 1.17.0 — без sid, с билет */
$wpdb->insert( 'wp_ansa_promo_tickets', array( 'order_id' => 20, 'code' => 'T20', 'tickets' => 3, 'box' => 'm', 'status' => 'issued', 'created_at' => '2026-10-16 12:00:00' ) );
$GLOBALS['orders'][21] = new FakeOrder( 21, array( 'sid' => '', 'box' => 'l' ), '2026-10-15 08:00:00', 'Mozilla/5.0 (Windows NT 10.0)', '21.21.21.21', 'delivered', 200 ); /* 15.10 — преди старта, активиран билет */
$wpdb->insert( 'wp_ansa_promo_tickets', array( 'order_id' => 21, 'code' => 'T21', 'tickets' => 5, 'box' => 'l', 'status' => 'activated', 'created_at' => '2026-10-15 12:00:00', 'activated_at' => '2026-10-16 18:00:00' ) );
$GLOBALS['orders'][22] = new FakeOrder( 22, array( 'sid' => 'sessE0001', 'box' => 'm' ), '2026-10-16 10:06:00' ); /* има sid и сесия (E на „плащане“), но on_order не е минал — свързва се */
$GLOBALS['orders'][23] = new FakeOrder( 23, array( 'sid' => '', 'draft' => true ), '2026-10-16 10:06:00' ); /* чернова — не брои */
$ok( 'сверка без force при пресен transient — не прави нищо', 0 === Insights::backfill_orders() );
$bn = Insights::backfill_orders( true ); $ok( 'сверка: 3 поръчки получават/свързват сесия (20, 21, 22; черновата 23 не)', 3 === $bn, (string) $bn );
$s20 = $wpdb->get_row( "SELECT * FROM wp_ansa_promo_sessions WHERE sid = 'order-20'" ); $ok( 'order-20: от датата на поръчката, телефон, IP, стъпка „платена“, кутия/utm/лийд от поръчката', $s20 && '2026-10-16 09:00:00' === $s20['started_at'] && 'mobile' === $s20['device'] && '20.20.20.20' === $s20['ip'] && 'paid' === $s20['step'] && 'm' === $s20['box'] && 'dg' === $s20['utm'] && 1 === (int) $s20['lead_id'] && 20 === (int) $s20['order_id'], json_encode( $s20 ) );
$s21 = $wpdb->get_row( "SELECT * FROM wp_ansa_promo_sessions WHERE sid = 'order-21'" ); $e21 = $wpdb->get_results( "SELECT ev, at FROM wp_ansa_promo_events WHERE sid = 'order-21' ORDER BY id" );
$ok( 'order-21: активиран, 3 събития с датите на поръчката/билета/активирането', $s21 && 'activated' === $s21['step'] && 'desktop' === $s21['device'] && array( array( 'ev' => 'created', 'at' => '2026-10-15 08:00:00' ), array( 'ev' => 'paid', 'at' => '2026-10-15 12:00:00' ), array( 'ev' => 'activated', 'at' => '2026-10-16 18:00:00' ) ) === $e21, json_encode( $e21 ) );
$sE = $wpdb->get_row( "SELECT step, order_id, started_at FROM wp_ansa_promo_sessions WHERE sid = 'sessE0001'" ); $ok( 'E: съществуващата сесия се свързва с поръчка 22 и стига до „поръчка“, началото ѝ остава', 22 === (int) $sE['order_id'] && 'created' === $sE['step'] && '2026-10-16 10:00:00' === $sE['started_at'], json_encode( $sE ) );
$ok( 'втора сверка не дублира', 0 === Insights::backfill_orders( true ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_ansa_promo_sessions WHERE sid = 'order-20'" ) );
$r = Insights::sessions_report( 0, array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) ); $ok( 'след сверката: поръчали 4 с проследен път (F, G, I, E) + 1 само поръчка (order-20, не е във фунията — т. 11), сесии 10 + 1', 4 === $r['funnel']['created'] && 10 === $r['sessions'] && 1 === $r['orders_only'], json_encode( $r['funnel'] ) );
$ok( 'поръчките в WooCommerce за 16–17.10 са също 5 (11, 12, 13, 20, 22; 21 е от 15.10, 23 е чернова)', 5 === Insights::wc_orders_count( array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) ) );
$d = Insights::diagnostics(); $ok( 'диагностика: 7 промо поръчки в WooCommerce (без черновата 23), 7 със сесия', 7 === $d['orders_wc'] && 7 === $d['orders_sess'], json_encode( array( $d['orders_wc'], $d['orders_sess'] ) ) );
/* занапред: on_order без sid */
$GLOBALS['orders'][30] = new FakeOrder( 30, array( 'sid' => '', 'box' => 'l', 'count' => 6 ), '2026-10-17 11:00:00', 'Mozilla/5.0 (Android 14; Mobile)' );
Insights::on_order( $GLOBALS['orders'][30], $GLOBALS['orders'][30]->meta ); $s30 = $wpdb->get_row( "SELECT step, order_id, device, box FROM wp_ansa_promo_sessions WHERE sid = 'order-30'" );
$ok( 'on_order без sid → сесия order-30 на „поръчка“, телефон по UA, кутията', $s30 && 'created' === $s30['step'] && 30 === (int) $s30['order_id'] && 'mobile' === $s30['device'] && 'l' === $s30['box'], json_encode( $s30 ) );
Insights::on_ticket( array( 'box' => 'l', 'tickets' => 6 ), $GLOBALS['orders'][30] ); $ok( 'on_ticket без sid → същата сесия стига до „платена“', 'paid' === $wpdb->get_var( "SELECT step FROM wp_ansa_promo_sessions WHERE sid = 'order-30'" ) );
$fd = Insights::feed( 50 ); $o20 = array_values( array_filter( $fd, function ( $s ) { return 'order-20' === $s['sid']; } ) )[0] ?? null; $ok( 'фийдът маркира сесията само от поръчката', $o20 && ! empty( $o20['nosess'] ) && 'P20' === $o20['order']['number'] && false !== strpos( Insights::feed_rows( array( $o20 ) ), 'сесията не е проследена' ) );
$os = Insights::orders_stats( array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) );
$ok( 'orders_stats за 16–17.10: 6 поръчки (без черновата), 648,50 € · взети 2 (11, 20) = 270 € · в път 3 (12, 22, 30) = 298,50 € · загубени 1 (13) = 80 €', 6 === $os['n'] && abs( $os['total'] - 648.5 ) < 0.001 && 2 === $os['delivered']['n'] && abs( $os['delivered']['total'] - 270 ) < 0.001 && 3 === $os['open']['n'] && abs( $os['open']['total'] - 298.5 ) < 0.001 && 1 === $os['lost']['n'] && abs( $os['lost']['total'] - 80 ) < 0.001 && '33,3%' === $os['delivered_pct'] && abs( $os['avg'] - 108.0833 ) < 0.001, json_encode( array( $os['n'], $os['total'], $os['delivered'], $os['open'], $os['lost'] ) ) );
$ok( 'orders_stats по статус: processing 3, delivered 2, cancelled 1 (подредени по брой), групите', array( 'processing', 'delivered', 'cancelled' ) === array_keys( $os['by_status'] ) && 'open' === $os['by_status']['processing']['grp'] && 'delivered' === $os['by_status']['delivered']['grp'] && 'lost' === $os['by_status']['cancelled']['grp'] );
$ok( 'orders_stats без период: всички 8 (и 10 от 15.10, и 21)', 8 === Insights::orders_stats( null )['n'] );
$ok( 'money: хиляди с интервал, запетая, €', '1 234,50 €' === Insights::money( 1234.5 ) && '0,00 €' === Insights::money( 0 ) );
$flat = Insights::dashboard_flat( Insights::dashboard_data( Insights::sessions_report( 0, array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) ), $os, array( 'period' => 'тест' ) ) );
$ok( 'dashboard_flat за живото опресняване: стойности и sub-ове по ключ', '6' === $flat['kpi']['o_n'] && '270,00 €' === $flat['kpi']['rev_real'] && '2' === $flat['kpi']['o_delivered'] && false !== strpos( $flat['kpi_sub']['o_delivered'], '33,3%' ) && false !== strpos( $flat['kpi_sub']['sessions'], 'тест' ) && 28 === count( $flat['kpi'] ) && isset( $flat['kpi_delta'] ) && '2' === $flat['kpi']['o_untracked'], json_encode( array_keys( $flat['kpi'] ) ) ); /* 1.24.2: + 4 плочки Shrine · 3+2; 1.28.0: + bounce, dur, tto, o_untracked (order-20, order-30); 1.29.0: + o_phone */
/* 1.24.26: прогнозата за взети — само върху решените: взети + загубени + застояли (в път ≥ N дни в текущия статус); пресните не влизат */
$rgT = array( 'from' => '2026-10-16', 'to' => '2026-10-17' );
$p1 = Insights::orders_stats( $rgT, 5, strtotime( '2026-10-18 12:00:00' ) );
$ok( 'прогноза (всички в път са пресни, 1–2 дни): застояли 0, пресни 3, решени 3 (2 взети + 1 загубена) → 66,7%; очаквани накрая 2 + 3×0,667 = 4 от 6, оборот 270 + 298,50×2/3', 0 === $p1['stale']['n'] && 3 === $p1['young']['n'] && 3 === $p1['decided'] && '66,7%' === $p1['proj_pct'] && 4 === $p1['proj_n'] && '66,7%' === $p1['proj_final_pct'] && abs( $p1['proj_total'] - ( 270 + 298.5 * 2 / 3 ) ) < 0.01, json_encode( array( $p1['stale'], $p1['young'], $p1['decided'], $p1['proj_pct'], $p1['proj_n'], $p1['proj_total'] ) ) );
$p2 = Insights::orders_stats( $rgT, 5, strtotime( '2026-10-25 12:00:00' ) );
$ok( 'прогноза (всички в път са от ≥ 8 дни): застояли 3 (вероятно загубени), пресни 0, решени 6 → 33,3%; очаквани накрая 2 от 6, оборот 270', 3 === $p2['stale']['n'] && 0 === $p2['young']['n'] && 6 === $p2['decided'] && '33,3%' === $p2['proj_pct'] && 2 === $p2['proj_n'] && abs( $p2['proj_total'] - 270 ) < 0.01 && 3 === $p2['by_status']['processing']['stale'], json_encode( array( $p2['stale'], $p2['decided'], $p2['proj_pct'], $p2['proj_n'] ) ) );
$p3 = Insights::orders_stats( $rgT, 3, strtotime( '2026-10-20 00:00:00' ) );
$ok( 'праг 3 дни на 20.10 00:00: 12 и 22 (от 16.10) са застояли, 30 (17.10 11:00) е пресна → решени 5, 40,0%; таблицата по статус: processing застояли 2 · пресни 1', 2 === $p3['stale']['n'] && 1 === $p3['young']['n'] && 5 === $p3['decided'] && '40,0%' === $p3['proj_pct'] && 2 === $p3['proj_n'] && 2 === $p3['by_status']['processing']['stale'] && 1 === $p3['by_status']['processing']['young'] && 3 === $p3['mature_days'], json_encode( array( $p3['stale'], $p3['young'], $p3['decided'], $p3['proj_pct'] ) ) );
$ok( 'без решени поръчки прогнозата е „—“', '—' === Insights::orders_stats( array( 'from' => '2030-01-01', 'to' => '2030-01-02' ) )['proj_pct'] );
$o12 = $GLOBALS['orders'][12]; Insights::on_status( 12, 'processing', 'shipped' ); $ok( 'on_status пише кога поръчката е влязла в текущия статус (unix) и възрастта се смята от него', ctype_digit( (string) $o12->get_meta( '_ansa_promo_status_at' ) ) && Insights::status_age_days( $o12, (int) $o12->get_meta( '_ansa_promo_status_at' ) + 2 * DAY_IN_SECONDS ) > 1.99 && Insights::status_age_days( $o12, (int) $o12->get_meta( '_ansa_promo_status_at' ) + 2 * DAY_IN_SECONDS ) < 2.01 ); $o12->extra = array();
$fl = Insights::dashboard_flat( Insights::dashboard_data( Insights::sessions_report( 0, $rgT ), $p1, array( 'period' => 'тест' ) ) );
$ok( 'плочките: „Прогноза взети“ 66,7% със sub върху решените; „Прогнозен оборот“ с очаквани накрая 4 от 6; „В път“ показва застояли/пресни', '66,7%' === $fl['kpi']['o_proj'] && false !== strpos( $fl['kpi_sub']['o_proj'], 'data-k="o_decided">3<' ) && false !== strpos( $fl['kpi_sub']['rev_proj'], 'data-k="o_proj_n">4</b> от 6' ) && false !== strpos( $fl['kpi_sub']['o_open'], 'data-k="o_young">3<' ) && '469,00 €' === $fl['kpi']['rev_proj'], json_encode( array( $fl['kpi']['o_proj'], $fl['kpi']['rev_proj'], $fl['kpi_sub']['rev_proj'] ) ) );
$h = $render( array( 'days' => '0', 'mature' => '7' ) ); $ok( '?mature=7 се пази (option) и чиповете „застояла = в път ≥ N дни“ са в групата Поръчки със 7 избрано; таблицата има колона „застояли ≥ 7 дни“', 7 === Insights::mature_days() && false !== strpos( $h, 'застояла = в път ≥' ) && (bool) preg_match( '/ap-chip--sm on" href="[^"]*mature=7"/u', $h ) && false !== strpos( $h, 'застояли ≥ 7 дни · пресни' ) && false !== strpos( $h, 'data-k="o_proj"' ) );
Insights::set_mature_days( 5 ); $ok( 'невалиден праг не се пази', 5 === Insights::set_mature_days( 4 ) );
$h = $render( array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) ); $ok( 'преглед след сверката: сесии с поръчка 4, поръчки 6 (+ 2 без проследен път: order-20, order-30), hero 270,00 €', (bool) preg_match( '/data-k="created">4<\/b>/u', $h ) && (bool) preg_match( '/data-k="o_n">6<\/b>/u', $h ) && (bool) preg_match( '/data-k="o_untracked">2<\/b>/u', $h ) && (bool) preg_match( '/data-k="rev_real">270,00 €<\/b>/u', $h ) ); $h = $render( array( 'view' => 'sessions', 'from' => '2026-10-16', 'to' => '2026-10-17' ) ); $ok( 'сесии: списъкът маркира сесията само от поръчката', false !== strpos( $h, '🧾 само поръчката' ) );
$h = $render( array( 'days' => '0' ) ); $ok( 'преглед: дашбордът за целия период (8 поръчки, взети 3: 11, 20, 21) + таблицата по статус; ?days=0 = от първия ден', (bool) preg_match( '/data-k="o_n">8<\/b>/u', $h ) && (bool) preg_match( '/data-k="o_delivered">3<\/b>/u', $h ) && false !== strpos( $h, '📦 Поръчките по статус · 15.10.2026 – ' ) && false !== strpos( $h, 'class="ap-chip on" href="http://x/wp-admin/admin.php?page=ansa-promo&tab=insights&from=2026-10-15&to=' ) );
$ok( 'преглед: редът „Поръчките“ в 🩺 с бутона за сверка', false !== strpos( $h, 'промо поръчки в WooCommerce' ) && false !== strpos( $h, 'id="apInsBackfill"' ) && false !== strpos( $h, 'ansa_promo_insights_backfill' ) );
/* 1.24.2: Shrine — 4-тата опция (3+2): sh_view/sh_pick/sh_more от клиента, sh_add от сървъра; сесията стига до „full“; таблица и плочки */
$_SERVER['REMOTE_ADDR'] = '9.9.9.9';
Insights::track( array( 'sid' => 'shrineS0001', 'ev' => 'sh_view', 'box' => 'l', 'k' => 'dg', 'utm' => '', 'src' => 'facebook', 'dev' => 'mobile' ) );
Insights::track( array( 'sid' => 'shrineS0001', 'ev' => 'sh_view', 'box' => 'l', 'k' => 'dg' ) ); /* дубликат — не се брои пак */
Insights::track( array( 'sid' => 'shrineS0001', 'ev' => 'sh_view', 'box' => 'l', 'k' => 'vit' ) ); /* друг продукт — брои се */
Insights::track( array( 'sid' => 'shrineS0001', 'ev' => 'sh_pick', 'box' => 'l', 'k' => 'dg' ) );
Insights::track( array( 'sid' => 'shrineS0001', 'ev' => 'sh_more', 'box' => 'l', 'k' => 'dg' ) );
Insights::track( array( 'sid' => 'shrineS0001', 'ev' => 'sh_add', 'box' => 'l', 'k' => 'dg', 'items' => array( 'dg', 'dg', 'dg', 'dg', 'dg' ) ) );
Insights::track( array( 'sid' => 'shrineS0002', 'ev' => 'sh_view', 'box' => 'l', 'k' => 'dg' ) );
$srow = $wpdb->get_row( "SELECT step, box, device, src FROM wp_ansa_promo_sessions WHERE sid = 'shrineS0001'", ARRAY_A );
$ok( 'shrine: сесията е на стъпка full (кутията е в количката), кутия l, mobile/facebook', $srow && 'full' === $srow['step'] && 'l' === $srow['box'] && 'mobile' === $srow['device'] && 'facebook' === $srow['src'], json_encode( $srow ) );
$ok( 'shrine: sh_view е веднъж на продукт на сесия', 2 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_ansa_promo_events WHERE sid = 'shrineS0001' AND ev = 'sh_view'" ) );
$wpdb->query( "UPDATE wp_ansa_promo_sessions SET order_id = 77 WHERE sid = 'shrineS0001'" );
$rs = Insights::sessions_report( 0 );
$ok( 'shrine: отчетът по продукт — dg: видели 2, избрали 1, виж повече 1, в количката 1, поръчали 1; vit: видели 1', isset( $rs['shrine']['dg'] ) && array( 'view' => 2, 'pick' => 1, 'more' => 1, 'add' => 1, 'created' => 1 ) === $rs['shrine']['dg'] && 1 === (int) ( $rs['shrine']['vit']['view'] ?? 0 ) && 0 === (int) ( $rs['shrine']['vit']['add'] ?? 0 ), json_encode( $rs['shrine'] ?? null ) );
$ok( 'shrine: сесиите от Shrine са във фунията (full)', $rs['funnel']['full'] >= 1 );
$h = $render( array( 'days' => '0' ) ); $ok( 'преглед: таблицата „Shrine · 3+2“ и плочките', false !== strpos( $h, 'Shrine · офертата „5 = 3+2“' ) && false !== strpos( $h, 'Видели картата 3+2' ) && false !== strpos( $h, '🛍️ Shrine · 3+2' ) );
$fd = Insights::feed( 50 ); $shs = array_values( array_filter( $fd, function ( $s ) { return 'shrineS0001' === $s['sid']; } ) )[0] ?? null;
$ok( 'фийдът: пътят на Shrine сесията с чиповете 3+2', $shs && count( $shs['timeline'] ) >= 4 && false !== strpos( wp_json_encode( $shs['timeline'] ), '3+2' ), $shs ? wp_json_encode( $shs['timeline'], JSON_UNESCAPED_UNICODE ) : 'няма' );

/* ── 1.27.0: докъде са напълнили · UTM × кутия · кампании · referrer · часове (за екипа по реклами) ── */
$wpdb->query( "UPDATE wp_ansa_promo_sessions SET src = 'facebook', med = 'cpc', camp = 'esen-2026', ref = 'https://l.facebook.com/l.php?u=x' WHERE sid IN ('sessA0001','sessB0001','sessC0001')" );
$wpdb->query( "UPDATE wp_ansa_promo_sessions SET src = 'google', med = 'cpc', camp = 'brand', ref = 'https://www.google.com/' WHERE sid = 'sessD0001'" );
$wpdb->query( "UPDATE wp_ansa_promo_sessions SET utm = 'dg' WHERE sid IN ('sessC0001','sessD0001','sessG0001')" );
$wpdb->insert( 'wp_ansa_promo_events', array( 'sid' => 'sessE0001', 'at' => '2026-10-16 10:03:00', 'ev' => 'item', 'k' => 'vit', 'n' => 3 ) );
$r = Insights::sessions_report( 0, array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) );
$fm = $r['fill']['m'] ?? null; $fl = $r['fill']['l'] ?? null;
$ok( 'fill: средна кутия — избрали = напълнили + спрели, 2 спрели (C с 2 оп. от items, J с 0), средно 1,0 оп. = 33 %', $fm && 3 === $fm['packs'] && $fm['sessions'] === $fm['full'] + $fm['stopped'] && $fm['full'] >= 5 && $fm['ordered'] >= 2 && $fm['ordered'] <= $fm['full'] && 2 === $fm['stopped'] && array( 0 => 1, 2 => 1 ) === $fm['dist'] && 1.0 === $fm['avg'] && 33 === (int) round( $fm['avg_pct'] ), json_encode( $fm ) );
$ok( 'fill: голяма кутия — G: 1 избрала, 1 напълнила, 1 поръчала, никой не е спрял', $fl && 5 === $fl['packs'] && 1 === $fl['sessions'] && 1 === $fl['full'] && 1 === $fl['ordered'] && 0 === $fl['stopped'] && array() === $fl['dist'], json_encode( $fl ) );
$ok( 'fill: сесия без кутия (A, B) не влиза', ! isset( $r['fill'][''] ) && ! isset( $r['fill']['—'] ) );
$ub = $r['by_utm_box']; $ok( 'by_utm_box: dg → голяма 1 (G), средна ≥ 2 (C, D), без кутия 0, сесии = кутии + без; „—“ → 2 без кутия (A, B)', isset( $ub['dg'] ) && 1 === $ub['dg']['boxes']['l'] && $ub['dg']['boxes']['m'] >= 2 && 0 === $ub['dg']['none'] && $ub['dg']['n'] === array_sum( $ub['dg']['boxes'] ) + $ub['dg']['none'] && 2 === $ub['—']['none'] && $ub['—']['n'] === array_sum( $ub['—']['boxes'] ) + 2, json_encode( $ub ) );
$ok( 'by_camp: facebook / cpc · esen-2026 = 3 (попъп 2, кутия 1); google / cpc · brand = 1 пълна; има „без UTM“', 3 === $r['by_camp']['facebook / cpc · esen-2026']['n'] && 2 === $r['by_camp']['facebook / cpc · esen-2026']['gate'] && 1 === $r['by_camp']['facebook / cpc · esen-2026']['box'] && 1 === $r['by_camp']['google / cpc · brand']['full'] && isset( $r['by_camp']['без UTM'] ) && $r['by_camp']['без UTM']['n'] >= 5, json_encode( array_map( function ( $x ) { return $x['n']; }, $r['by_camp'] ) ) );
$ok( 'by_camp: сесия само с UTM продукт, без utm_source → „само UTM продукт“ (G)', isset( $r['by_camp']['само UTM продукт'] ) && $r['by_camp']['само UTM продукт']['n'] >= 1 && $r['by_camp']['само UTM продукт']['paid'] >= 1, json_encode( array_keys( $r['by_camp'] ) ) );
$ok( 'by_ref: l.facebook.com → facebook.com 3, www.google.com → google.com 1, останалите директно', 3 === $r['by_ref']['facebook.com']['n'] && 1 === $r['by_ref']['google.com']['n'] && $r['by_ref']['директно / без referrer']['n'] >= 6 && 3 === count( $r['by_ref'] ), json_encode( array_map( function ( $x ) { return $x['n']; }, $r['by_ref'] ) ) );
$ok( 'by_hour: 10-те сесии от сценария са в 10:00 (с ≥ 3 поръчки), часовете са подредени', 10 === $r['by_hour']['10:00']['n'] && $r['by_hour']['10:00']['created'] >= 3 && array_keys( $r['by_hour'] ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $r['by_hour'] ) ), json_encode( $r['by_hour'] ) );
$h = $render( array( 'days' => '0' ) );
$ok( 'преглед: новите таблици са на екрана', false !== strpos( $h, 'Докъде са напълнили кутията' ) && false !== strpos( $h, 'Коя кутия избират по UTM' ) && false !== strpos( $h, 'По кампания (utm_source' ) && false !== strpos( $h, 'По referrer' ) && false !== strpos( $h, 'По час на влизане' ) && false !== strpos( $h, 'facebook / cpc · esen-2026' ) );
$ok( 'преглед: редът на средната кутия — 2 оп. колоната и средното запълване', (bool) preg_match( '/🎁 Средна кутия.*?средно|33%/su', $h ) && false !== strpos( $h, '1,0 оп. · 33%' ) );
$h = $render( array( 'view' => 'sessions', 'from' => '2026-10-16', 'to' => '2026-10-17' ) );
$ok( 'сесии: без дублиране на таблиците от Прегледа — само списъкът и докъде/къде спират', false === strpos( $h, 'Докъде са напълнили кутията' ) && false === strpos( $h, 'По кампания (utm_source' ) && false !== strpos( $h, '🛑 Докъде са стигнали' ) );
/* режим портал: диагностиката и admin-post формите изчезват, линковете сочат към портала */
Insights::$portal = true; $h = $render( array( 'days' => '0' ) );
$ok( 'портал: без „Работи ли отчитането?“, без стария rollup, без admin-post, линковете са към портала', false === strpos( $h, 'Работи ли отчитането' ) && false === strpos( $h, 'Старият дневен rollup' ) && false === strpos( $h, 'admin-post.php' ) && false !== strpos( $h, 'https://ansa.test/promo-insights/?from=2026-10-15&to=' ) && false !== strpos( $h, 'view=sessions' ) && false !== strpos( $h, 'name="csv" value="sessions"' ) && false !== strpos( $h, 'name="csv" value="camp"' ) && false !== strpos( $h, 'Докъде са напълнили кутията' ) && false !== strpos( $h, 'name="ap_action" value="snap_add"' ) );
$h = $render( array( 'view' => 'live' ) );
$ok( 'портал · на живо: fetch-ът е към портала с action=…feed', false !== strpos( $h, 'var ajax="https:\\/\\/ansa.test\\/promo-insights\\/?"' ) && false !== strpos( $h, 'ajax+"action=ansa_promo_insights_feed' ) && false === strpos( $h, 'admin-ajax' ), substr( $h, strpos( $h, 'var ajax=' ), 80 ) );
$h = $render( array( 'view' => 'history', 'from' => '2026-10-16', 'to' => '2026-10-17' ) );
$ok( 'портал · сесии: формата от–до е към портала без page/tab', false !== strpos( $h, 'action="https://ansa.test/promo-insights/" class="ap-ins-range"><input type="hidden" name="view" value="sessions">' ) && false === strpos( $h, 'name="page"' ) );
Insights::$portal = false; $h = $render( array( 'view' => 'live' ) );
$ok( 'админ · на живо: fetch-ът е към admin-ajax', false !== strpos( $h, 'admin-ajax.php?"' ) && false !== strpos( $h, 'ajax+"action=ansa_promo_insights_feed' ) );
$fp = Insights::feed_payload( 1, 5 ); $ok( 'feed_payload: feed · rows_html · at · kpi · kpi_sub', isset( $fp['feed'], $fp['rows_html'], $fp['at'], $fp['kpi'], $fp['kpi_sub'] ) && isset( $fp['kpi']['sessions'] ) );

/* ── 1.28.0: 30-те подобрения — изчисленията ── */
$R = '\AnsaPromo\Insights_Reports'; function esc_textarea( $s ) { return esc_html( $s ); } function wc_get_product_id_by_sku( $sku ) { return 0; }
$rg2 = array( 'from' => '2026-10-16', 'to' => '2026-10-17' );
/* т. 12: първи и последен UTM */
$_SERVER['REMOTE_ADDR'] = '9.1.1.1'; $rgT = array( 'from' => $today, 'to' => $today ); $base = Insights::sessions_report( 0, $rgT ); $b0 = (int) $base['sessions']; $v0 = (int) $base['visitors']; $bb0 = (int) $base['bounce']; $bd0 = (int) $base['dur_n'];
Insights::track( array( 'sid' => 'sessATTR01', 'ev' => 'view', 'utm' => 'dg', 'dev' => 'desktop', 'vid' => 'vAAA1xxxx' ) ); Insights::track( array( 'sid' => 'sessATTR01', 'ev' => 'box', 'utm' => 'vit', 'box' => 'm' ) );
$ra = $wpdb->get_row( "SELECT utm, utm_last, vid FROM wp_ansa_promo_sessions WHERE sid = 'sessATTR01'" );
$ok( 'track: utm = първият (dg), utm_last = последният (vit), vid се пази', 'dg' === $ra['utm'] && 'vit' === $ra['utm_last'] && 'vAAA1xxxx' === $ra['vid'], json_encode( $ra ) );
$ok( 'атрибуция: по първи UTM сесията е в „dg“, по последен — във „vit“', isset( Insights::sessions_report( 0, $rgT, array( 'attr' => 'first' ) )['by_utm']['dg'] ) && isset( Insights::sessions_report( 0, $rgT, array( 'attr' => 'last' ) )['by_utm']['vit'] ) && ! isset( Insights::sessions_report( 0, $rgT, array( 'attr' => 'last' ) )['by_utm']['dg'] ) );
/* т. 13: посетител по бисквитка, не по IP */
$_SERVER['REMOTE_ADDR'] = '5.5.5.1'; Insights::track( array( 'sid' => 'sessVID001', 'ev' => 'view', 'vid' => 'vSAMExxxx', 'dev' => 'mobile' ) ); $_SERVER['REMOTE_ADDR'] = '5.5.5.2'; Insights::track( array( 'sid' => 'sessVID002', 'ev' => 'view', 'vid' => 'vSAMExxxx', 'dev' => 'mobile' ) );
$rt = Insights::sessions_report( 0, $rgT );
$ok( 'посетители днес: +3 сесии (ATTR + 2 × vSAME от различни IP) = +2 посетителя', $b0 + 3 === $rt['sessions'] && $v0 + 2 === $rt['visitors'], json_encode( array( $rt['sessions'], $rt['visitors'], $b0, $v0 ) ) );
$ok( 'т. 16: само видели +2 (VID001, VID002), сесии с ≥ 2 действия +1', $bb0 + 2 === $rt['bounce'] && $bd0 + 1 === $rt['dur_n'], json_encode( array( $rt['bounce'], $rt['dur_n'] ) ) );
/* т. 17: вътрешен трафик */
Insights::track( array( 'sid' => 'sessINT001', 'ev' => 'view', 'internal' => true, 'dev' => 'desktop' ) ); Insights::track( array( 'sid' => 'sessINT001', 'ev' => 'gate' ) );
$rt = Insights::sessions_report( 0, $rgT ); $rti = Insights::sessions_report( 0, $rgT, array( 'internal' => true ) );
$ok( 'вътрешният трафик не влиза по подразбиране, с опцията влиза (+1) и се брои (internal_n 1)', $b0 + 3 === $rt['sessions'] && $b0 + 4 === $rti['sessions'] && 1 === $rti['internal_n'] && 1 === (int) $wpdb->get_var( "SELECT internal FROM wp_ansa_promo_sessions WHERE sid = 'sessINT001'" ), json_encode( array( $rt['sessions'], $rti['sessions'] ) ) );
$ok( 'is_internal_ip: точно, префикс, CIDR', $R::is_internal_ip( '1.2.3.4', $R::ip_list( "1.2.3.4\n10.0.*\n192.168.1.0/24" ) ) && $R::is_internal_ip( '10.0.7.7', $R::ip_list( "10.0.*" ) ) && $R::is_internal_ip( '192.168.1.200', $R::ip_list( "192.168.1.0/24" ) ) && ! $R::is_internal_ip( '192.168.2.1', $R::ip_list( "192.168.1.0/24" ) ) && ! $R::is_internal_ip( '1.2.3.5', $R::ip_list( "1.2.3.4" ) ) );
update_option( $R::OPT, array( 'internal_ips' => "9.1.1.1", 'internal_emails' => 'probe@example.com' ) ); $nI = $R::apply_internal_flags(); $ok( 'apply_internal_flags: старите сесии от вътрешно IP се маркират (ATTR от 9.1.1.1)', 1 === $nI && 1 === (int) $wpdb->get_var( "SELECT internal FROM wp_ansa_promo_sessions WHERE sid = 'sessATTR01'" ), (string) $nI ); update_option( $R::OPT, array() );
$ok( 'is_internal_email: probe@example.com и @домейн', $R::is_internal_email( 'Probe@Example.com' ) && ! $R::is_internal_email( 'klient@abv.bg' ) );
/* т. 18: ботове */
$ok( 'is_bot: Googlebot, headless, празен UA = бот; Chrome не е', $R::is_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ) && $R::is_bot( 'HeadlessChrome/120' ) && $R::is_bot( '' ) && ! $R::is_bot( 'Mozilla/5.0 (iPhone) Safari' ) );
/* т. 21: Δ */
$ok( 'delta_html: ▲ +20 % (up), ▼ −20 % (down), обратно за „по-малко е по-добре“, „ново“ при 0, „=“ при равни', false !== strpos( Insights::delta_html( '120', '100' ), 'ap-d up"' ) && false !== strpos( Insights::delta_html( '120', '100' ), '>▲ +20 %<' ) && false !== strpos( Insights::delta_html( '80', '100' ), 'ap-d down"' ) && false !== strpos( Insights::delta_html( '80', '100' ), '>▼ −20 %<' ) && false !== strpos( Insights::delta_html( '80', '100', true ), 'ap-d up"' ) && false !== strpos( Insights::delta_html( '5', '0' ), 'ново' ) && false !== strpos( Insights::delta_html( '100', '100' ), '>=<' ) && '' === Insights::delta_html( '—', '5' ), Insights::delta_html( '120', '100' ) );
$ok( 'num: „1 234,50 €“ → 1234.5, „12,5%“ → 12.5', 1234.5 === Insights::num( '1 234,50 €' ) && 12.5 === Insights::num( '12,5%' ) );
$ok( 'spark: SVG за ≥ 2 точки, нищо за 1', false !== strpos( Insights::spark( array( 1, 3, 2 ) ), '<polyline' ) && '' === Insights::spark( array( 1 ) ) );
/* т. 2: периодът */
$rp = new ReflectionProperty( Insights::class, 'period' ); $rp->setAccessible( true ); $rp->setValue( null, null ); $_GET = array( 'days' => '7' ); $pp = Insights::period();
$ok( 'period: ?days=7 → 7 дни до днес, key 7, label, days', $pp['to'] === $today && 7 === $pp['days'] && '7' === $pp['key'] && false !== strpos( $pp['label'], ' – ' ), json_encode( $pp ) );
$pv = Insights::prev_range( $pp ); $ok( 'prev_range: предишните 7 дни, точно преди', 7 === Insights::range_days( $pv ) && $pv['to'] === gmdate( 'Y-m-d', strtotime( $pp['from'] ) - 86400 ), json_encode( $pv ) );
$rp->setValue( null, null ); $_GET = array( 'from' => $today, 'to' => $today ); $ok( 'period: днес → label „днес“, key today', 'днес' === Insights::period()['label'] && 'today' === Insights::period()['key'] ); $rp->setValue( null, null ); $_GET = array();
/* т. 15/19/20: оборотът по измерение (totals от orders_stats по датата на сесията) */
$os2 = Insights::orders_stats( $rg2 ); $r2 = Insights::sessions_report( 0, $rg2, array( 'totals' => $os2['by_id'] ) );
$ok( 'by_box m: оборот 299,50 (11 + 12? не — 11, 22, 13), взети 1, взети € 120; l: 99,50 (12)', abs( $r2['by_box']['m']['rev'] - 299.5 ) < 0.01 && 1 === $r2['by_box']['m']['delivered'] && abs( $r2['by_box']['m']['rev_del'] - 120 ) < 0.01 && abs( $r2['by_box']['l']['rev'] - 99.5 ) < 0.01, json_encode( array( $r2['by_box']['m'], $r2['by_box']['l'] ) ) );
$ok( 'общо за периода: оборот 399 на проследените (11, 12, 13, 22), без проследен път 2 (20, 30)', abs( $r2['rev'] - 399 ) < 0.01 && 2 === $r2['orders_only'] && abs( $r2['orders_only_rev'] - 249.5 ) < 0.01, json_encode( array( $r2['rev'], $r2['orders_only'], $r2['orders_only_rev'] ) ) );
/* т. 26: устройство × стъпка */
$ok( 'dev_stop: телефонът спира на „пълна“ 1 (H); десктопът има спрели на view', 1 === ( $r2['dev_stop']['mobile']['full'] ?? 0 ) && ( $r2['dev_stop']['desktop']['view'] ?? 0 ) >= 1, json_encode( $r2['dev_stop'] ) );
/* т. 22: филтър */
$rf = Insights::sessions_report( 0, $rg2, array( 'filter' => array( 'box' => 'l' ) ) ); $ok( 'филтър кутия l: 1 сесия (G), 1 поръчка', 1 === $rf['sessions'] && 1 === $rf['funnel']['created'] );
$rf = Insights::sessions_report( 0, $rg2, array( 'filter' => array( 'src' => 'facebook', 'med' => 'cpc', 'camp' => 'esen-2026' ) ) ); $ok( 'филтър кампания facebook / cpc · esen-2026: 3 сесии', 3 === $rf['sessions'], (string) $rf['sessions'] );
$ok( 'camp_parts: „facebook / cpc · esen-2026“ → [facebook, cpc, esen-2026]; „google“ → [google, „“, „“]', array( 'facebook', 'cpc', 'esen-2026' ) === Insights::camp_parts( 'facebook / cpc · esen-2026' ) && array( 'google', '', '' ) === Insights::camp_parts( 'google' ) );
/* т. 25: кохорти */
$co = Insights::cohorts( $rg2 ); $wk = $co['weeks']['2026-10-12'] ?? null;
$ok( 'кохорти: седмицата от 12.10 — 8 посетители, 4 поръчали (E, F, G, I), всички до 1 ден, медиана 0', $wk && 8 === $wk['visitors'] && 4 === $wk['any'] && 4 === $wk['d1'] && 0 === $co['lag_med'] && 4 === $co['lag_n'], json_encode( $co ) );
/* т. 23: разход */
list( $okS, $mS ) = Insights::spend_add( array( 'type' => 'utm', 'key' => 'dg', 'from' => '2026-10-16', 'to' => '2026-10-17', 'amount' => '100', 'note' => 'тест' ) );
$ok( 'spend_add: записан, 1 ред', $okS && 1 === count( Insights::spend_rows() ), $mS );
$sp = Insights::spend_for( $rg2 ); $ok( 'spend_for: целият период 100 €, половината 50 €, извън 0', abs( $sp['utm']['dg'] - 100 ) < 0.01 && abs( Insights::spend_for( array( 'from' => '2026-10-16', 'to' => '2026-10-16' ) )['utm']['dg'] - 50 ) < 0.01 && empty( Insights::spend_for( array( 'from' => '2026-10-18', 'to' => '2026-10-19' ) )['utm'] ), json_encode( $sp ) );
$rc = Insights::row_calc( array( 'n' => 10, 'created' => 4, 'rev' => 200 ), 100 ); $ok( 'row_calc: конверсия 40 %, AOV 50, CPA 25, ROAS 2×, € / сесия 10, малка извадка', '40,0%' === $rc['conv'] && 50.0 === $rc['aov'] && 25.0 === $rc['cpa'] && 2.0 === $rc['roas'] && 10.0 === $rc['cps'] && $rc['small'] );
$rc = Insights::row_calc( array( 'n' => 40, 'created' => 0, 'rev' => 0 ), 100 ); $ok( 'row_calc: без поръчки CPA е null, ROAS 0, не е малка извадка', null === $rc['cpa'] && 0.0 === $rc['roas'] && ! $rc['small'] );
$ok( 'spend_add: без ключ/сума → грешка', false === Insights::spend_add( array( 'type' => 'utm', 'key' => '', 'amount' => '5' ) )[0] && false === Insights::spend_add( array( 'type' => 'utm', 'key' => 'x', 'amount' => '0' ) )[0] );
/* т. 30: CSV по разбивка, API, замразени отчети */
$csvU = Insights::csv_breakdown( 'utm', $rg2 ); $ok( 'csv_breakdown utm: заглавие с колоните, ред за dg с периода и разхода', false !== strpos( $csvU, "utm;период;сесии;посетители" ) && (bool) preg_match( '/^"?dg"?;"2026-10-16 – 2026-10-17";/mu', $csvU ) && false !== strpos( $csvU, '100.00' ), substr( $csvU, 0, 300 ) );
$api = Insights::api_payload( $rg2 ); $ok( 'api_payload: kpi, funnel, by_utm/by_campaign/by_day, orders; редовете са с conv и spend', isset( $api['kpi']['sessions'], $api['funnel']['view'], $api['by_utm'][0]['conv'], $api['by_campaign'], $api['by_day'], $api['orders']['n'] ) && 'first' === $api['attribution'] && (bool) array_filter( $api['by_utm'], function ( $x ) { return 'dg' === $x['key'] && 100.0 === $x['spend']; } ), json_encode( array_keys( $api ) ) );
$ok( 'api_payload е валиден JSON', false !== json_encode( $api ) );
list( $okN, $mN, $idN ) = Insights::snap_add( '2026-10-16', '2026-10-17' ); $sn = Insights::snap_get( $idN );
$ok( 'snap_add: замразен отчет с данните, в индекса', $okN && $sn && 1 === count( Insights::snap_list() ) && 6 === (int) $sn['os']['n'] && isset( $sn['r']['by_utm'] ) && Insights::snap_list()[0]['id'] === $idN, $mN );
$hs = Insights::overview_html( $sn, true ); $ok( 'замразеният отчет се рендира от данните с бележка и без линкове за филтър', false !== strpos( $hs, '📌 Замразен отчет от' ) && false !== strpos( $hs, 'data-k="o_n">6<' ) && false === strpos( $hs, 'f_utm=' ) );
Insights::snap_del( $idN ); $ok( 'snap_del', array() === Insights::snap_list() && null === Insights::snap_get( $idN ) );
$ok( 'handle_action: непознато → грешка', false === Insights::handle_action( 'hack', array() )[0] );
/* Insights_Reports: седмичен отчет и аларми (без изпращане) */
$ok( 'weekly_due: изключен → не; включен с получател в деня и часа → да; пращан преди < 6 дни → не', ! $R::weekly_due( strtotime( '2026-10-26 09:00:00' ), array( 'weekly' => array( 'enabled' => false, 'to' => 'a@b.bg', 'dow' => 1, 'hour' => 8 ) ) ) && $R::weekly_due( strtotime( '2026-10-26 09:00:00' ), array( 'weekly' => array( 'enabled' => true, 'to' => 'a@b.bg', 'dow' => 1, 'hour' => 8 ) ) ) && ! $R::weekly_due( strtotime( '2026-10-26 07:00:00' ), array( 'weekly' => array( 'enabled' => true, 'to' => 'a@b.bg', 'dow' => 1, 'hour' => 8 ) ) ) && ! $R::weekly_due( strtotime( '2026-10-27 09:00:00' ), array( 'weekly' => array( 'enabled' => true, 'to' => 'a@b.bg', 'dow' => 1, 'hour' => 8 ) ) ) );
$wh = $R::weekly_html( $rg2 ); $ok( 'weekly_html: заглавие, таблицата с Δ, период', false !== strpos( $wh, 'седмичен отчет' ) && false !== strpos( $wh, 'Сесии с поръчка' ) && false !== strpos( $wh, '16.10.2026 – 17.10.2026' ) && false !== strpos( $wh, 'Δ' ) );
$al = $R::alerts_evaluate( time(), $R::settings() ); $ok( 'alerts_evaluate: връща списък без изключение (сега няма трафик → няма „0 сесии“ аларма, защото няма и база)', is_array( $al ) && ! array_filter( $al, function ( $x ) { return 'zero' === $x[0]; } ), json_encode( $al ) );
$ok( 'alerts_check: изключени → нищо', array() === $R::alerts_check() );
$tk = $R::regenerate_api_token(); $ok( 'API токен: 64 hex, проверява се, друг/празен не минава; изтриване', preg_match( '/^[a-f0-9]{64}$/', $tk ) && $R::api_token_ok( $tk ) && ! $R::api_token_ok( strrev( $tk ) ) && ! $R::api_token_ok( '' ) ); $R::clear_api_token(); $ok( 'след изтриване никой токен не минава', ! $R::api_token_ok( $tk ) );
$ok( 'norm_emails', 'a@b.bg, c@d.bg' === $R::norm_emails( "A@b.bg; junk ,c@d.bg\na@b.bg" ) );
$ok( 'status на отчетите е текст + ниво', is_string( $R::status()[0] ) && in_array( $R::status()[1], array( 'ok', 'warn' ), true ) );
/* екраните 💸 и 📌 */
$h = $render( array( 'view' => 'spend' ) ); $ok( 'екран 💸 Разход: формата и таблицата', false !== strpos( $h, '💸 Разход по кампании' ) && false !== strpos( $h, 'name="do" value="ins_spend_add"' ) && false !== strpos( $h, '🧾 Въведените разходи' ) );
$h = $render( array( 'view' => 'snap' ) ); $ok( 'екран 📌 Отчети: списъкът', false !== strpos( $h, '📌 Замразени отчети' ) );
Insights::$portal = true; $h = $render( array( 'view' => 'spend' ) ); $ok( 'портал · 💸: формата е POST към портала с ap_action и csrf', false !== strpos( $h, 'name="ap_action" value="spend_add"' ) && false !== strpos( $h, 'name="csrf"' ) && false === strpos( $h, 'admin-post' ) ); Insights::$portal = false;
$h = $render( array( 'from' => '2026-10-16', 'to' => '2026-10-17', 'f_box' => 'l' ) ); $ok( 'преглед с филтър кутия l: лентата показва филтъра, плочката Сесии = 1, линк „изчисти“', false !== strpos( $h, 'кутия: 👑 Голяма кутия ✕' ) && (bool) preg_match( '/data-k="sessions">1<\/b>/u', $h ) && false !== strpos( $h, '>изчисти всички<' ) );
$h = $render( array( 'from' => '2026-10-16', 'to' => '2026-10-17', 'attr' => 'last' ) ); $ok( 'преглед с атрибуция „последен UTM“: чипът е on и таблицата го казва', false !== strpos( $h, 'class="ap-chip on" href="http://x/wp-admin/admin.php?page=ansa-promo&tab=insights&from=2026-10-16&to=2026-10-17&attr=last">последен UTM' ) && false !== strpos( $h, '· последен UTM)' ) );

/* ── 1.29.0: 📞 поръчки по телефон ── */
$PO = '\AnsaPromo\Phone_Orders';
/* промо страницата за линковете: page_id в публикувания конфиг (кешът на Config се нулира) */
$st = get_option( 'ansa_promo_store', array() ); $st = is_array( $st ) ? $st : array(); $st['published'] = array_merge( is_array( $st['published'] ?? null ) ? $st['published'] : array(), array( 'page_id' => 5 ) ); update_option( 'ansa_promo_store', $st ); $cm = new ReflectionProperty( \AnsaPromo\Config::class, 'memory' ); $cm->setAccessible( true ); $cm->setValue( null, null );
$tk = $PO::issue( 'cart', 77, 'Мария (reklama)', 1800000000 ); $vp = $PO::verify( $tk, 1800000100 );
$ok( 'phone: токенът се издава и проверява (cart:77, оператор, 24 ч)', is_array( $vp ) && 'cart' === $vp['t'] && 77 === $vp['id'] && 'Мария (reklama)' === $vp['by'] && 1800000000 + 86400 === $vp['exp'], json_encode( $vp ) );
$ok( 'phone: изтекъл / подправен / боклук → не', null === $PO::verify( $tk, 1800000000 + 86401 ) && null === $PO::verify( substr( $tk, 0, -1 ) . 'x', 1800000100 ) && null === $PO::verify( '', 1800000100 ) && null === $PO::verify( 'a.b', 1800000100 ) );
$ok( 'phone: sess токен и sid-ът phone-<id> се разпознава като „без проследен път“', 'sess' === $PO::verify( $PO::issue( 'sess', 5 ) )['t'] && $PO::is_phone_sid( 'phone-12' ) && Insights::is_order_sid( 'phone-12' ) && ! $PO::is_phone_sid( 'sessA0001' ) );
$ok( 'phone: линковете водят към промо страницата с ?ansa_op= и &go=1; бонусът (1.30.1) е &ansa_bonus= / &bonus=, боклукът не влиза', false !== strpos( $PO::recreate_url( $tk ), 'http://x/promo/?ansa_op=' ) && false !== strpos( $PO::checkout_url( $tk ), '&go=1' ) && false === strpos( $PO::checkout_url( $tk ), 'bonus' ) && false !== strpos( $PO::recreate_url( $tk, 'pct' ), '&ansa_bonus=pct' ) && false !== strpos( $PO::checkout_url( $tk, 'gift' ), '&go=1&bonus=gift' ) && false === strpos( $PO::checkout_url( $tk, 'hack' ), 'bonus' ) );
$bt = $PO::buttons( 'sess', 3, 'lead@example.com' ); $ok( 'phone: двата бутона „✏️ Редактирай поръчка“ (data-op=edit) / „✅ Завърши поръчка“ (data-op=go), без бонус в самите линкове', false !== strpos( $bt, '✏️ Редактирай поръчка' ) && false !== strpos( $bt, '✅ Завърши поръчка' ) && false !== strpos( $bt, 'data-op="edit"' ) && false !== strpos( $bt, 'data-op="go"' ) && 2 === substr_count( $bt, 'ansa_op=' ) && false === strpos( $bt, 'bonus' ) );
$cc = $PO::chooser_cfg(); $ok( 'phone: chooser_cfg — „−5 % отстъпка“ / без подарък (няма WooCommerce) / „Нищо“', '−5 % отстъпка' === $cc['pct'] && '' === $cc['gift'] && 'Нищо' === $cc['none'], json_encode( $cc, JSON_UNESCAPED_UNICODE ) );
/* load за сесия: кутията, имейлът от попъпа (продуктите в теста няма — конфигът е без продукти) */
$sidB = (int) $wpdb->get_var( "SELECT id FROM wp_ansa_promo_sessions WHERE sid = 'sessD0001'" ); $ld = $PO::load( array( 't' => 'sess', 'id' => $sidB, 'by' => 'оп' ) );
$ok( 'phone: load(sess D) → кутия m, имейлът от поръчката/лийда, label със старта', $ld && $ld['ok'] && 'm' === $ld['box'] && false !== strpos( $ld['label'], '2026-10-16' ) && 'sess:' . $sidB === $ld['src'] && 'оп' === $ld['by'], json_encode( $ld ) );
$ok( 'phone: load за несъществуваща сесия → ok false с бележка', false === $PO::load( array( 't' => 'sess', 'id' => 999999, 'by' => '' ) )['ok'] );
/* load за количка от FKA: таблицата в SQLite */
$wpdb->query( 'CREATE TABLE wp_bwfan_abandonedcarts (ID INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, status INTEGER, order_id INTEGER, created_time TEXT, last_modified TEXT, total REAL, currency TEXT, items TEXT, checkout_data TEXT)' );
$items = serialize( array( 'k1' => array( 'product_id' => 501, 'variation_id' => 0, 'quantity' => 2, 'line_total' => 40, \AnsaPromo\Cart::META => array( 'role' => 'base', 'key' => 'dg', 'box' => 'm', 'packs' => 3, 'pct' => 30, 'promo' => 'orange_q4_2026' ) ), 'k2' => array( 'product_id' => 502, 'quantity' => 1, 'line_total' => 20, \AnsaPromo\Cart::META => array( 'role' => 'base', 'key' => 'vit' ) ), 'k3' => array( 'product_id' => 9, 'quantity' => 1, \AnsaPromo\Cart::META => array( 'role' => 'book' ) ) ) );
$wpdb->insert( 'wp_bwfan_abandonedcarts', array( 'email' => 'Ivanka@Example.com', 'status' => 1, 'order_id' => 0, 'created_time' => '2026-10-20 10:00:00', 'last_modified' => '2026-10-20 10:20:00', 'total' => 60, 'currency' => 'EUR', 'items' => $items, 'checkout_data' => json_encode( array( 'fields' => array( 'billing_first_name' => 'Иванка', 'billing_last_name' => 'Петрова', 'billing_phone' => '0888123456', 'bwfan_user_consent' => 1 ) ) ) ) );
$cid = (int) $wpdb->insert_id; $cr = \AnsaPromo\Cart_Sync::cart_by_id( $cid );
$ok( 'cart_by_id: имейл, имена, телефон, кутия m, 2 base реда + книгата', $cr && 'ivanka@example.com' === $cr['email'] && 'Иванка' === $cr['first'] && 'Петрова' === $cr['last'] && '0888123456' === $cr['phone'] && 'm' === $cr['meta']['box'] && 3 === count( $cr['lines'] ) && 'dg' === $cr['lines'][0]['key'] && 2 === $cr['lines'][0]['qty'], json_encode( $cr ) );
$lc = $PO::load( array( 't' => 'cart', 'id' => $cid, 'by' => 'оп' ) );
$ok( 'phone: load(cart) → имейл, телефон, имена, кутия m, label с #id (продуктите са извън конфига на теста → празни)', $lc && $lc['ok'] && 'ivanka@example.com' === $lc['email'] && '0888123456' === $lc['phone'] && 'Иванка Петрова' === $lc['name'] && 'm' === $lc['box'] && false !== strpos( $lc['label'], '#' . $cid ) && 'cart:' . $cid === $lc['src'], json_encode( $lc ) );
$ok( 'phone: runtime_payload с невалиден токен → ok false с бележка', false === $PO::runtime_payload( 'bad.token' )['ok'] );
/* поръчка по телефон → сесия phone-<id>, източник „телефон“, не е вътрешна; плочката и фийдът */
$GLOBALS['orders'][40] = new FakeOrder( 40, array( 'sid' => 'sessOPER01', 'box' => 'm', 'count' => 3, 'phone_order' => array( 'by' => 'Мария', 'src' => 'cart:' . $cid, 'label' => 'количка #' . $cid ) ), '2026-10-17 15:00:00', 'Mozilla/5.0', '9.9.9.9', 'processing', 150 );
Insights::on_order( $GLOBALS['orders'][40], $GLOBALS['orders'][40]->meta ); $s40 = $wpdb->get_row( "SELECT sid, src, med, camp, internal, started_at FROM wp_ansa_promo_sessions WHERE order_id = 40" );
$ok( 'phone: поръчката прави сесия phone-40 (не сесията на оператора), src phone / call · операторът, не е вътрешна, с датата на поръчката', $s40 && 'phone-40' === $s40['sid'] && 'phone' === $s40['src'] && 'call' === $s40['med'] && 'Мария' === $s40['camp'] && 0 === (int) $s40['internal'] && '2026-10-17 15:00:00' === $s40['started_at'], json_encode( $s40 ) );
$os4 = Insights::orders_stats( $rg2 ); $ok( 'orders_stats: плочката „по телефон“ 1 · 150 €; влиза и в без проследен път (3)', 1 === $os4['phone']['n'] && abs( $os4['phone']['total'] - 150 ) < 0.01 && 3 === $os4['untracked']['n'], json_encode( array( $os4['phone'], $os4['untracked'] ) ) );
$fl4 = Insights::dashboard_flat( Insights::dashboard_data( Insights::sessions_report( 0, $rg2 ), $os4 ) ); $ok( 'плочка o_phone = 1', '1' === $fl4['kpi']['o_phone'] );
$wpdb->query( "UPDATE wp_ansa_promo_sessions SET lead_id = 1 WHERE sid = 'sessD0001'" ); /* D с имейл от попъпа — за бутона */
$fd4 = Insights::feed( 60, array( 'from' => '2026-10-17', 'to' => '2026-10-17' ) ); $p40 = array_values( array_filter( $fd4, function ( $s ) { return 'phone-40' === $s['sid']; } ) )[0] ?? null;
$ok( 'фийдът: „📞 поръчка по телефон“ на phone-40; „✏️ Редактирай поръчка“ за сесия с имейл и кутия без поръчка (D), не и за поръчалите (F)', $p40 && false !== strpos( Insights::feed_rows( array( $p40 ) ), '📞 поръчка по телефон' ) && false !== strpos( Insights::feed_rows( array_values( array_filter( Insights::feed( 60 ), function ( $s ) { return 'sessD0001' === $s['sid']; } ) ) ), '✏️ Редактирай поръчка' ) && false === strpos( Insights::feed_rows( array_values( array_filter( Insights::feed( 60 ), function ( $s ) { return 'sessF0001' === $s['sid']; } ) ) ), '✏️ Редактирай поръчка' ) );
$_COOKIE[ $PO::COOKIE ] = '1'; $ok( 'операторът (бисквитка ansa_promo_op) е вътрешен трафик', $R::is_internal_request() ); unset( $_COOKIE[ $PO::COOKIE ] ); $ok( 'без бисквитката не е', ! $R::is_internal_request() );
$ok( 'operator(): без вход — празно', '' === $PO::operator() );
/* 1.30.0: бонусът за обаждането */
$ps = $PO::settings(); $ok( 'phone: настройки по подразбиране — −5 %, подарък PATCH01', 5.0 === $ps['pct'] && 'PATCH01' === $ps['gift_sku'] && 0 === $ps['gift_id'] );
$PO::save( array( 'pct' => '7,5', 'gift_sku' => ' PATCH01 EyeFresh BG ', 'gift_id' => '0' ) ); $ps = $PO::settings(); $ok( 'phone: save — 7,5 %, SKU изчистен; таван 50', 7.5 === $ps['pct'] && 'PATCH01 EyeFresh BG' === $ps['gift_sku'] && 50.0 === ( $PO::save( array( 'pct' => '99' ) ) ? $PO::settings()['pct'] : 0 ) );
$PO::save( array( 'pct' => '5', 'gift_sku' => 'PATCH01' ) );
$ok( 'phone: norm_bonus', 'pct' === $PO::norm_bonus( 'pct' ) && 'gift' === $PO::norm_bonus( 'gift' ) && '' === $PO::norm_bonus( 'x' ) );
$ok( 'phone: без WooCommerce няма подарък; bonus_cfg носи процента', null === $PO::gift_product() && 5.0 === $PO::bonus_cfg()['pct'] && null === $PO::bonus_cfg()['gift'] );
$ok( 'phone: bonus_label', '📞 Отстъпка по телефона −5 %' === $PO::bonus_label( 'pct' ) && false !== strpos( $PO::bonus_label( 'gift' ), '🎁' ) && '' === $PO::bonus_label( '' ) );
$GLOBALS['orders'][41] = new FakeOrder( 41, array( 'sid' => '', 'box' => 'l', 'count' => 5, 'phone_order' => array( 'by' => 'Мария', 'src' => 'sess:1', 'label' => 'сесия', 'bonus' => 'pct', 'bonus_pct' => 5, 'bonus_amount' => 7.37 ) ), '2026-10-17 16:00:00', '', '9.9.9.9', 'processing', 140 );
$GLOBALS['orders'][42] = new FakeOrder( 42, array( 'sid' => '', 'box' => 'm', 'count' => 3, 'phone_order' => array( 'by' => 'Мария', 'src' => 'cart:9', 'label' => 'количка', 'bonus' => 'gift', 'gift' => 'EyeFresh BG' ) ), '2026-10-17 16:10:00', '', '9.9.9.9', 'processing', 90 );
Insights::on_order( $GLOBALS['orders'][41], $GLOBALS['orders'][41]->meta ); Insights::on_order( $GLOBALS['orders'][42], $GLOBALS['orders'][42]->meta );
$os5 = Insights::orders_stats( $rg2 ); $ok( 'orders_stats: по телефон 3 (150 + 140 + 90), с −%: 1, с подарък: 1', 3 === $os5['phone']['n'] && abs( $os5['phone']['total'] - 380 ) < 0.01 && 1 === $os5['phone']['pct'] && 1 === $os5['phone']['gift'], json_encode( $os5['phone'] ) );
$fl5 = Insights::dashboard_flat( Insights::dashboard_data( Insights::sessions_report( 0, $rg2 ), $os5 ) ); $ok( 'плочката: 3, sub с −% 1 и подарък 1', '3' === $fl5['kpi']['o_phone'] && false !== strpos( $fl5['kpi_sub']['o_phone'], 'data-k="o_phone_pct">1<' ) && false !== strpos( $fl5['kpi_sub']['o_phone'], 'data-k="o_phone_gift">1<' ) );
/* бележката в поръчката */
class NoteOrder extends FakeOrder { public $notes = array(); function add_order_note( $n ) { $this->notes[] = $n; } }
$no = new NoteOrder( 43, array( 'phone_order' => array( 'by' => 'Мария', 'label' => 'количка #5', 'bonus' => 'pct', 'bonus_pct' => 5, 'bonus_amount' => 7.37 ) ) ); $PO::on_order( $no, $no->meta );
$ok( 'бележката: оператор, източник, бонус −5 % (7,37 €)', 1 === count( $no->notes ) && false !== strpos( $no->notes[0], 'от „Мария“' ) && false !== strpos( $no->notes[0], 'количка #5' ) && false !== strpos( $no->notes[0], '−5 % (7,37 €)' ), json_encode( $no->notes, JSON_UNESCAPED_UNICODE ) );
$no2 = new NoteOrder( 44, array( 'phone_order' => array( 'by' => 'Иван', 'bonus' => 'gift', 'gift' => 'EyeFresh BG' ) ) ); $PO::on_order( $no2, $no2->meta ); $ok( 'бележката: подарък EyeFresh BG', false !== strpos( $no2->notes[0], 'подарък EyeFresh BG' ) );
$no3 = new NoteOrder( 45, array( 'box' => 'm' ) ); $PO::on_order( $no3, $no3->meta ); $ok( 'без phone_order няма бележка', array() === $no3->notes );

$ok( 'без SQL грешки по пътя', ! $GLOBALS['sql_errors'], implode( ' | ', array_slice( $GLOBALS['sql_errors'], 0, 3 ) ) );

if ( $fail ) { echo "FAIL\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
