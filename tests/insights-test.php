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
function current_user_can( $c ) { return true; } function wc_get_order_status_name( $s ) { return 'статус ' . $s; } function wp_unslash( $v ) { return $v; }
class FakeDate { private $d; function __construct( $d ) { $this->d = $d; } function date( $f ) { return date( $f, strtotime( $this->d ) ); } function getTimestamp() { return strtotime( $this->d ); } }
class FakeOrder { private $id; public $meta; public $date; public $ua; public $ip; function __construct( $id, $meta = array(), $date = '2026-10-16 10:00:00', $ua = '', $ip = '9.9.9.9' ) { $this->id = $id; $this->meta = $meta; $this->date = $date; $this->ua = $ua; $this->ip = $ip; } function get_id() { return $this->id; } function get_order_number() { return 'P' . $this->id; } function get_status() { return 'processing'; } function get_total() { return 99.5; } function get_edit_order_url() { return 'http://x/order/' . $this->id; } function get_billing_email() { return 'buyer' . $this->id . '@example.com'; } function get_billing_first_name() { return 'Мария'; } function get_billing_last_name() { return 'П.'; }
	function get_meta( $k ) { return '_ansa_promo' === $k ? $this->meta : ( '_ansa_promo_id' === $k ? 'promo' : '' ); } function get_date_created() { return new FakeDate( $this->date ); } function get_customer_user_agent() { return $this->ua; } function get_customer_ip_address() { return $this->ip; } }
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
	function get_var( $q ) { $r = $this->get_row( $q ); return $r ? array_values( $r )[0] : null; }
	function insert( $tb, $d, $f = null ) { $c = array_keys( $d ); $st = $this->db->prepare( "INSERT INTO $tb (" . implode( ',', $c ) . ') VALUES (' . implode( ',', array_map( function ( $k ) { return ':' . $k; }, $c ) ) . ')' ); $ok = $st && $st->execute( $d ); $this->insert_id = (int) $this->db->lastInsertId(); return $ok ? 1 : false; }
	function update( $tb, $d, $w ) { $set = implode( ',', array_map( function ( $k ) { return "$k = :s_$k"; }, array_keys( $d ) ) ); $wh = implode( ' AND ', array_map( function ( $k ) { return "$k = :w_$k"; }, array_keys( $w ) ) ); $p = array(); foreach ( $d as $k => $v ) { $p[ "s_$k" ] = $v; } foreach ( $w as $k => $v ) { $p[ "w_$k" ] = $v; } $st = $this->db->prepare( "UPDATE $tb SET $set WHERE $wh" ); return $st && $st->execute( $p ) ? $st->rowCount() : false; }
	function delete( $tb, $w ) { $wh = implode( ' AND ', array_map( function ( $k ) { return "$k = :$k"; }, array_keys( $w ) ) ); $st = $this->db->prepare( "DELETE FROM $tb WHERE $wh" ); return $st && $st->execute( $w ) ? $st->rowCount() : false; }
}
$GLOBALS['wpdb'] = $wpdb = new FakeWpdb(); $GLOBALS['sql_errors'] = array();
$wpdb->query( 'CREATE TABLE wp_ansa_promo_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, sid TEXT NOT NULL, started_at TEXT NOT NULL, last_at TEXT NOT NULL, utm TEXT NOT NULL DEFAULT "", src TEXT NOT NULL DEFAULT "", med TEXT NOT NULL DEFAULT "", camp TEXT NOT NULL DEFAULT "", ref TEXT NOT NULL DEFAULT "", device TEXT NOT NULL DEFAULT "", lead_id INTEGER NULL, box TEXT NOT NULL DEFAULT "", items TEXT NULL, step TEXT NOT NULL DEFAULT "view", order_id INTEGER NULL, ip TEXT NULL)' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_events (id INTEGER PRIMARY KEY AUTOINCREMENT, sid TEXT NOT NULL, at TEXT NOT NULL, ev TEXT NOT NULL, box TEXT NOT NULL DEFAULT "", k TEXT NOT NULL DEFAULT "", n INTEGER NOT NULL DEFAULT 0, utm TEXT NOT NULL DEFAULT "")' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_leads (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT NOT NULL, step TEXT NOT NULL, box TEXT NOT NULL DEFAULT "", utm TEXT NOT NULL DEFAULT "", n INTEGER NOT NULL DEFAULT 0)' );
$wpdb->query( 'CREATE TABLE wp_ansa_promo_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, code TEXT NOT NULL, tickets INTEGER NOT NULL DEFAULT 1, box TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "issued", created_at TEXT NOT NULL, activated_at TEXT NULL, portal_user TEXT NULL, ip TEXT NULL, ref_code TEXT NULL, bonus INTEGER NOT NULL DEFAULT 0)' );
$wpdb->insert( 'wp_ansa_promo_leads', array( 'email' => 'lead@example.com' ) );
/* поръчките на F, G, I (и K от 15.10) — със sid, вече имат сесия */
$GLOBALS['orders'][11] = new FakeOrder( 11, array( 'sid' => 'sessF0001', 'box' => 'm' ), '2026-10-16 10:04:00' ); $GLOBALS['orders'][12] = new FakeOrder( 12, array( 'sid' => 'sessG0001', 'box' => 'l' ), '2026-10-16 10:04:00' );
$GLOBALS['orders'][13] = new FakeOrder( 13, array( 'sid' => 'sessI0001', 'box' => 'm' ), '2026-10-17 10:04:00' ); $GLOBALS['orders'][10] = new FakeOrder( 10, array( 'sid' => 'sessK0001', 'box' => 'm' ), '2026-10-15 10:04:00' );
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
$h = $render( array( 'view' => 'history' ) );
$ok( 'стиловете на екрана се печатат при първия рендер (веднъж на заявка)', 1 === substr_count( $h, '.ap-funnel{' ) && 1 === substr_count( $h, '.ap-ins-f{' ) );
$ok( 'история: трите подтаба + формата от–до', false !== strpos( $h, '📜 История' ) && false !== strpos( $h, 'name="from" value="2026-10-16"' ) && false !== strpos( $h, 'name="to" value="' . $today . '"' ) );
$ok( 'история: KPI „поръчки в WooCommerce за периода“ = 3 (11, 12, 13; 10 е от 15.10) и съвпада с „поръчали (сесии)“', (bool) preg_match( '/<b>3<\/b><span>поръчки в WooCommerce за периода — съвпадат/u', $h ) && (bool) preg_match( '/<b>3<\/b><span>поръчали \(сесии\)/u', $h ) );
$ok( 'история: KPI „напълнили кутията, но НЕ поръчали“ = 3 с линк към списъка', (bool) preg_match( '/<b>3<\/b><span>напълнили кутията, но НЕ поръчали · <a href="[^"]*step=full[^"]*ord=no[^"]*#apInsList/u', $h ) );
$ok( 'история: таблицата „докъде са стигнали и къде са спрели“', false !== strpos( $h, '🛑 Докъде са стигнали и къде са спрели' ) && (bool) preg_match( '/Напълнили кутията<\/b>.*?mode=min[^"]*"><b>6<\/b>.*?mode=exact[^"]*"><b>2<\/b>/us', $h ) );
$ok( 'история: списъкът — 10 сесии, филтрите, без страници (≤ 50)', (bool) preg_match( '/<b>10<\/b> сесии · <b>8<\/b> посетители/u', $h ) && false === strpos( $h, 'спрели точно на „' ) && false !== strpos( $h, 'без поръчка' ) && false === strpos( $h, 'class="ap-pg"' ) );
$ok( 'история: CSV формата носи периода', false !== strpos( $h, 'name="do" value="export_sessions"' ) && false !== strpos( $h, 'name="from" value="2026-10-16"' ) );
$ok( 'история: K от 15.10 не е в списъка, D е', false === strpos( $h, 'buyer10@example.com' ) && false !== strpos( $h, 'buyer11@example.com' ) && false !== strpos( $h, 'lead@example.com' ) );
$h = $render( array( 'view' => 'history', 'from' => '2026-10-16', 'to' => '2026-10-17', 'step' => 'full', 'mode' => 'min', 'ord' => 'no' ) );
$ok( 'история с филтър: 3 сесии · 2 посетители, чиповете са „on“, режимът се вижда', (bool) preg_match( '/<b>3<\/b> сесии · <b>2<\/b> посетители/u', $h ) && false !== strpos( $h, 'спрели точно на „Напълнили кутията“' ) && (bool) preg_match( '/<a class="ap-chip on" href="[^"]*ord=no[^"]*">без поръчка</u', $h ) );
$ok( 'история с филтър: само D, E, H в списъка', 3 === preg_match_all( '/<tr><td><b>10:05<\/b>/u', $h ) );
$h = $render( array( 'view' => 'history', 'from' => '2026-10-17', 'to' => '2026-10-17', 'pg' => '99' ) );
$ok( 'история: страница извън обхвата се връща на последната, 3 сесии за 17.10', (bool) preg_match( '/<b>3<\/b> сесии/u', $h ) && false !== strpos( $h, 'class="ap-chip on" href="http://x/wp-admin/admin.php?page=ansa-promo&tab=insights&view=history&from=' ) );
$h = $render( array( 'view' => 'live', 'n' => '100' ) );
$ok( 'на живо: отделен екран с фийда, превключвателя и KPI за днес; без диагностиката', false !== strpos( $h, '🟢 На живо — последните сесии' ) && false !== strpos( $h, 'id="apInsLive"' ) && false !== strpos( $h, 'var limit=100;' ) && false !== strpos( $h, '&days=1&limit=' ) && false === strpos( $h, '🩺 Работи ли отчитането' ) );
$h = $render( array( 'days' => '0' ) );
$ok( 'преглед: фунията, диагностиката, продуктите, разбивките; фийдът НЕ е тук', false !== strpos( $h, '🔻 Фунията по сесии' ) && false !== strpos( $h, '🩺 Работи ли отчитането' ) && false !== strpos( $h, '📅 По дни' ) && false === strpos( $h, 'id="apInsFeed"' ) && false !== strpos( $h, 'view=history' ) );
$h = $render( array( 'view' => 'hack' ) ); $ok( 'непознат view → преглед', false !== strpos( $h, '🔻 Фунията по сесии' ) );
/* ── 1.21.1: сверка с поръчките (човекът: „има 43 поръчки, а Insights отчита 16“) ── */
$GLOBALS['orders'][20] = new FakeOrder( 20, array( 'sid' => '', 'box' => 'm', 'utm' => 'dg', 'lead_id' => 1, 'count' => 3 ), '2026-10-16 09:00:00', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)', '20.20.20.20' ); /* преди 1.17.0 — без sid, с билет */
$wpdb->insert( 'wp_ansa_promo_tickets', array( 'order_id' => 20, 'code' => 'T20', 'tickets' => 3, 'box' => 'm', 'status' => 'issued', 'created_at' => '2026-10-16 12:00:00' ) );
$GLOBALS['orders'][21] = new FakeOrder( 21, array( 'sid' => '', 'box' => 'l' ), '2026-10-15 08:00:00', 'Mozilla/5.0 (Windows NT 10.0)', '21.21.21.21' ); /* 15.10 — преди старта, активиран билет */
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
$r = Insights::sessions_report( 0, array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) ); $ok( 'историята след сверката: поръчали 5 (F, G, I + order-20 + E), сесии 11', 5 === $r['funnel']['created'] && 11 === $r['sessions'], json_encode( $r['funnel'] ) );
$ok( 'поръчките в WooCommerce за 16–17.10 са също 5 (11, 12, 13, 20, 22; 21 е от 15.10, 23 е чернова)', 5 === Insights::wc_orders_count( array( 'from' => '2026-10-16', 'to' => '2026-10-17' ) ) );
$d = Insights::diagnostics(); $ok( 'диагностика: 7 промо поръчки в WooCommerce (без черновата 23), 7 със сесия', 7 === $d['orders_wc'] && 7 === $d['orders_sess'], json_encode( array( $d['orders_wc'], $d['orders_sess'] ) ) );
/* занапред: on_order без sid */
$GLOBALS['orders'][30] = new FakeOrder( 30, array( 'sid' => '', 'box' => 'l', 'count' => 6 ), '2026-10-17 11:00:00', 'Mozilla/5.0 (Android 14; Mobile)' );
Insights::on_order( $GLOBALS['orders'][30], $GLOBALS['orders'][30]->meta ); $s30 = $wpdb->get_row( "SELECT step, order_id, device, box FROM wp_ansa_promo_sessions WHERE sid = 'order-30'" );
$ok( 'on_order без sid → сесия order-30 на „поръчка“, телефон по UA, кутията', $s30 && 'created' === $s30['step'] && 30 === (int) $s30['order_id'] && 'mobile' === $s30['device'] && 'l' === $s30['box'], json_encode( $s30 ) );
Insights::on_ticket( array( 'box' => 'l', 'tickets' => 6 ), $GLOBALS['orders'][30] ); $ok( 'on_ticket без sid → същата сесия стига до „платена“', 'paid' === $wpdb->get_var( "SELECT step FROM wp_ansa_promo_sessions WHERE sid = 'order-30'" ) );
$fd = Insights::feed( 50 ); $o20 = array_values( array_filter( $fd, function ( $s ) { return 'order-20' === $s['sid']; } ) )[0] ?? null; $ok( 'фийдът маркира сесията само от поръчката', $o20 && ! empty( $o20['nosess'] ) && 'P20' === $o20['order']['number'] && false !== strpos( Insights::feed_rows( array( $o20 ) ), 'сесията не е проследена' ) );
$h = $render( array( 'view' => 'history' ) ); $ok( 'история след сверката: поръчали (сесии) 6 = поръчки в WooCommerce 6 (+ order-30) — съвпадат', (bool) preg_match( '/<b>6<\/b><span>поръчали \(сесии\)/u', $h ) && (bool) preg_match( '/<b>6<\/b><span>поръчки в WooCommerce за периода — съвпадат/u', $h ) && false !== strpos( $h, '🧾 само поръчката' ) );
$h = $render( array( 'days' => '0' ) ); $ok( 'преглед: редът „Поръчките“ в 🩺 с бутона за сверка', false !== strpos( $h, 'промо поръчки в WooCommerce' ) && false !== strpos( $h, 'id="apInsBackfill"' ) && false !== strpos( $h, 'ansa_promo_insights_backfill' ) );
$ok( 'без SQL грешки по пътя', ! $GLOBALS['sql_errors'], implode( ' | ', array_slice( $GLOBALS['sql_errors'], 0, 3 ) ) );

if ( $fail ) { echo "FAIL\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
