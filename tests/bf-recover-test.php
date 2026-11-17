<?php
/**
 * Офлайн тест на 1.54.4 — BF\Boost::recover_request(): линкът от имейла за изоставена количка (FunnelKit: ?bwfan-ab-id=<token>&bwfan-uid=<uid>)
 * връща ваучера на клиента в сесията (нова сесия, изтекъл срок), без WordPress: $wpdb е SQLite в паметта, WC сесията е масив.
 * php tests/bf-recover-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
define( 'ARRAY_A', 'ARRAY_A' );
function is_admin() { return ! empty( $GLOBALS['is_admin'] ); } function wp_unslash( $v ) { return $v; } function wp_salt( $s = '' ) { return 'salt'; }
function wc_setcookie( $n, $v, $exp = 0 ) { $GLOBALS['cookies_set'][ $n ] = array( $v, $exp ); if ( '' !== $v && $exp > time() ) { $_COOKIE[ $n ] = $v; } }
function add_filter() {} function wp_next_scheduled() { return 1; } function wp_schedule_event() {}
spl_autoload_register( function ( $c ) { if ( 0 !== strpos( $c, 'AnsaPromo\\BF\\' ) ) { return; } $f = ANSA_PROMO_PATH . 'includes/bf/class-' . strtolower( str_replace( '_', '-', substr( $c, 13 ) ) ) . '.php'; if ( is_file( $f ) ) { require_once $f; } }, true, true );
use AnsaPromo\BF\Boost; use AnsaPromo\BF\Cart; use AnsaPromo\BF\Config; use AnsaPromo\BF\Leads;

class FakeSession { public $d = array(); public $has = false; function get( $k, $def = null ) { return $this->d[ $k ] ?? $def; } function set( $k, $v ) { $this->d[ $k ] = $v; } function has_session() { return $this->has; } function set_customer_session_cookie( $b ) { $this->has = true; } }
class FakeWC { public $session; public $cart = null; }
class FakeCart { public $items; public $fees = array(); function __construct( $i ) { $this->items = $i; } function get_cart() { return $this->items; } function add_fee( $name, $amount, $taxable = false ) { $this->fees[] = array( $name, $amount ); } }
$GLOBALS['wc'] = new FakeWC(); $GLOBALS['wc']->session = new FakeSession();
function WC() { return $GLOBALS['wc']; }
class FakeWpdb {
	public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0; public $errors = array(); private $db; private $quiet = false;
	function __construct() { $this->db = new PDO( 'sqlite::memory:' ); $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT ); }
	function suppress_errors( $s = true ) { $o = $this->quiet; $this->quiet = (bool) $s; return $o; }
	function prepare( $q, ...$a ) { $i = 0; return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $a ) { $v = $a[ $i++ ] ?? ''; return 'd' === $m[0][1] ? (string) (int) $v : "'" . str_replace( "'", "''", (string) $v ) . "'"; }, $q ); }
	function query( $q ) { return $this->db->exec( $q ); }
	function get_results( $q, $t = ARRAY_A ) { $st = $this->db->query( $q ); if ( ! $st ) { if ( ! $this->quiet ) { $this->errors[] = implode( ' ', $this->db->errorInfo() ) . ' ← ' . $q; } return array(); } return $st->fetchAll( PDO::FETCH_ASSOC ); }
	function get_row( $q, $t = ARRAY_A ) { $r = $this->get_results( $q ); return $r[0] ?? null; }
	function get_var( $q ) { $r = $this->get_row( $q ); return $r ? array_values( $r )[0] : null; }
	function insert( $tb, $d, $f = null ) { $c = array_keys( $d ); $st = $this->db->prepare( "INSERT INTO $tb (" . implode( ',', $c ) . ') VALUES (' . implode( ',', array_map( function ( $k ) { return ':' . $k; }, $c ) ) . ')' ); $ok = $st && $st->execute( $d ); $this->insert_id = (int) $this->db->lastInsertId(); return $ok ? 1 : false; }
	function update( $tb, $d, $w, $f = null, $wf = null ) { $set = implode( ',', array_map( function ( $k ) { return "$k = :s_$k"; }, array_keys( $d ) ) ); $wh = implode( ' AND ', array_map( function ( $k ) { return "$k = :w_$k"; }, array_keys( $w ) ) ); $p = array(); foreach ( $d as $k => $v ) { $p[ "s_$k" ] = $v; } foreach ( $w as $k => $v ) { $p[ "w_$k" ] = $v; } $st = $this->db->prepare( "UPDATE $tb SET $set WHERE $wh" ); return $st && $st->execute( $p ) ? $st->rowCount() : false; }
}
$wpdb = $GLOBALS['wpdb'] = new FakeWpdb();
$wpdb->query( "CREATE TABLE wp_ansa_promo_leads (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, utm TEXT DEFAULT '', created_at TEXT, converted_order_id INTEGER NULL, promo TEXT DEFAULT '', kind TEXT DEFAULT '', bonus TEXT DEFAULT '', voucher TEXT DEFAULT '', voucher_exp TEXT NULL, voucher_order_id INTEGER NULL)" );
$wpdb->query( 'CREATE TABLE wp_bwfan_abandonedcarts (ID INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, status INTEGER, order_id INTEGER DEFAULT 0, created_time TEXT, last_modified TEXT, items TEXT, coupons TEXT, fees TEXT, token TEXT, cookie_tracking_id TEXT, checkout_data TEXT)' );
$wpdb->query( 'CREATE TABLE wp_bwf_contact (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, uid TEXT)' );

$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };
set_error_handler( function ( $no, $msg, $file, $line ) { throw new ErrorException( $msg, 0, $no, $file, $line ); } );
$utc = function ( $off ) { return gmdate( 'Y-m-d H:i:s', time() + $off ); };
$lead = function ( $email, $code, $exp_off, $extra = array() ) use ( $wpdb, $utc ) { $wpdb->insert( 'wp_ansa_promo_leads', array_merge( array( 'email' => $email, 'created_at' => $utc( -3600 ), 'promo' => 'bf', 'kind' => 'cold', 'voucher' => $code, 'voucher_exp' => $utc( $exp_off ) ), $extra ) ); return (int) $wpdb->insert_id; };
$fee = function ( $code ) { return serialize( array( 'x' => (object) array( 'id' => 'v', 'name' => 'Ваучер 10 € · ' . $code, 'amount' => -8.3333, 'total' => -8.33, 'tax' => -1.67 ) ) ); };
$cart = function ( $email, $token, $fees = '', $extra = array() ) use ( $wpdb, $utc ) { $wpdb->insert( 'wp_bwfan_abandonedcarts', array_merge( array( 'email' => $email, 'status' => 1, 'order_id' => 0, 'created_time' => $utc( -2400 ), 'last_modified' => $utc( -2400 ), 'items' => 'a:0:{}', 'coupons' => '', 'fees' => $fees, 'token' => $token, 'cookie_tracking_id' => 'trk', 'checkout_data' => '' ), $extra ) ); return (int) $wpdb->insert_id; };
$exp_of = function ( $code ) use ( $wpdb ) { return (int) strtotime( $wpdb->get_var( "SELECT voucher_exp FROM wp_ansa_promo_leads WHERE voucher = '$code'" ) . ' UTC' ); };
/* нова заявка: чиста/запазена сесия, GET, бисквитки; „веднъж на заявка“ (Boost::$recover_done) се нулира с рефлексия */
$req = function ( $get = array(), $fresh_session = true, $keep_cookie = false ) { $_GET = $get; $GLOBALS['cookies_set'] = array(); if ( ! $keep_cookie ) { unset( $_COOKIE[ Boost::AB_COOKIE ] ); } if ( $fresh_session ) { $GLOBALS['wc']->session = new FakeSession(); } $p = new ReflectionProperty( Boost::class, 'recover_done' ); $p->setAccessible( true ); $p->setValue( null, false ); Boost::recover_request(); return Cart::ctx(); };

$pub = Config::normalize( Config::defaults() ); $pub['enabled'] = true; $pub['force'] = 'prep';
$save = function ( $cfg ) { $GLOBALS['opts'][ Config::OPTION ] = array( 'draft' => null, 'published' => $cfg, 'published_at' => 0, 'version' => 1, 'history' => array() ); $m = new ReflectionProperty( Config::class, 'memory' ); $m->setAccessible( true ); $m->setValue( null, null ); /* кешът на заявката */ };
$save( $pub );
$ok( 'конфиг: cold.recover_minutes = 30 по подразбиране и оцелява normalize', 30 === Config::get_published()['cold']['recover_minutes'] && true === Config::get_published()['enabled'] );
$c0 = $pub; $c0['cold']['recover_minutes'] = 0; $ok( 'recover_minutes = 0 се пази (не пада на 30)', 0 === Config::normalize( $c0 )['cold']['recover_minutes'] );
$c9 = $pub; $c9['cold']['recover_minutes'] = 99999; $ok( 'recover_minutes е ограничен до 1440', 1440 === Config::normalize( $c9 )['cold']['recover_minutes'] );

/* 1) случаят от писмото: ваучерът е изтекъл преди 8 минути, нова сесия, писмото го е показало → възобновен за 30 мин. и в сесията */
$lead( 'merian@example.com', 'BF10-ENUY', -480 ); $cart( 'merian@example.com', '1BQieTDDxdBCpJtHIllL1GZ2PyxnXaFf', $fee( 'BF10-ENUY' ) );
$ok( 'преди клика ваучерът е изтекъл', ! Leads::voucher_valid( 'BF10-ENUY', true ) );
$ctx = $req( array( 'bwfan-ab-id' => '1BQieTDDxdBCpJtHIllL1GZ2PyxnXaFf', 'automation-id' => '63', 'bwfan-uid' => '40c8aa28a3700b86d542ec0609644631' ) );
$ok( 'изтекъл + в снимката: ваучерът е в сесията', 'BF10-ENUY' === ( $ctx['voucher']['code'] ?? '' ) && 10.0 === (float) $ctx['voucher']['amount'], json_encode( $ctx ) );
$ok( 'срокът е клик + 30 мин. (в базата и в сесията)', abs( $exp_of( 'BF10-ENUY' ) - ( time() + 1800 ) ) <= 2 && (int) $ctx['voucher']['exp'] === $exp_of( 'BF10-ENUY' ) );
$ok( 'ваучерът пак е жив (таксата ще се наложи)', Leads::voucher_valid( 'BF10-ENUY' ) );
$ok( 'имейлът и лийдът са в контекста', 'merian@example.com' === ( $ctx['email'] ?? '' ) && (int) ( $ctx['lead'] ?? 0 ) > 0 );
$ok( 'сесията има бисквитка (гост без сесия)', true === $GLOBALS['wc']->session->has );
$ok( 'бисквитката с токена е сложена за 1 час', '1BQieTDDxdBCpJtHIllL1GZ2PyxnXaFf' === ( $GLOBALS['cookies_set'][ Boost::AB_COOKIE ][0] ?? '' ) && $GLOBALS['cookies_set'][ Boost::AB_COOKIE ][1] > time() + 3000 );
$ok( 'bonus не е пипан (issue() на 20.11 пак ще го възобнови)', '' === (string) $wpdb->get_var( "SELECT bonus FROM wp_ansa_promo_leads WHERE voucher = 'BF10-ENUY'" ) );
/* таксата в количката: същото, което чекаутът ще покаже — „Ваучер 10 € · BF10-ENUY“ −10 (без ДДС стъб → бруто) */
$fc = new FakeCart( array( 'k1' => array( 'product_id' => 7603, 'quantity' => 1, 'line_subtotal' => 15.9, 'line_subtotal_tax' => 0, 'ansa_promo_bf' => array( 'promo' => 'bf', 'group' => 'bf_x', 'mode' => 'wallet', 'role' => 'base', 'key' => 'eyefresh' ) ) ) );
Cart::fees( $fc ); $ok( 'Cart::fees: редът „Ваучер 10 € · BF10-ENUY“ −10,00', 1 === count( $fc->fees ) && 'Ваучер 10 € · BF10-ENUY' === $fc->fees[0][0] && -10.0 === (float) $fc->fees[0][1], json_encode( $fc->fees, JSON_UNESCAPED_UNICODE ) );
$lg = Boost::recover_log(); $ok( 'броячът: 1 върнат, 1 възобновен', $lg && 1 === (int) $lg['n'] && 1 === (int) $lg['revived'] );

/* 2) следващата заявка (без параметъра, сесията е оцеляла): бисквитката се чисти, нищо не се променя */
$exp1 = $exp_of( 'BF10-ENUY' ); $ok( 'бисквитката е при браузъра', isset( $_COOKIE[ Boost::AB_COOKIE ] ) ); $ctx = $req( array(), false, true );
$ok( 'следваща заявка: ваучерът си стои, бисквитката е изчистена', 'BF10-ENUY' === ( $ctx['voucher']['code'] ?? '' ) && '' === ( $GLOBALS['cookies_set'][ Boost::AB_COOKIE ][0] ?? 'x' ) && ! isset( $_COOKIE[ Boost::AB_COOKIE ] ) && $exp1 === $exp_of( 'BF10-ENUY' ) );
/* 2б) FKA е подменил сесията при възстановяването: бисквитката връща ваучера още веднъж и спира */
$_COOKIE[ Boost::AB_COOKIE ] = '1BQieTDDxdBCpJtHIllL1GZ2PyxnXaFf'; $ctx = $req( array(), true, true );
$ok( 'подменена сесия + бисквитка: ваучерът пак е в сесията, бисквитката е изчистена', 'BF10-ENUY' === ( $ctx['voucher']['code'] ?? '' ) && ! isset( $_COOKIE[ Boost::AB_COOKIE ] ) );
$ctx = $req( array(), true ); $ok( 'без параметър и без бисквитка: нищо', empty( $ctx ) );

/* 3) жив ваучер, нова сесия (друг браузър) → влиза без промяна на срока; работи и при recover_minutes = 0 и без кода в снимката */
$save( $c0 ); $lead( 'live@example.com', 'BF10-LIVE', 600 ); $cart( 'live@example.com', 'tokenLIVE0001', '' ); $e0 = $exp_of( 'BF10-LIVE' );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenLIVE0001' ) );
$ok( 'жив ваучер: в сесията, срокът не е пипан', 'BF10-LIVE' === ( $ctx['voucher']['code'] ?? '' ) && $e0 === $exp_of( 'BF10-LIVE' ) );
/* 4) recover_minutes = 0 → изтеклият не се възобновява */
$lead( 'off@example.com', 'BF10-OFF1', -60 ); $cart( 'off@example.com', 'tokenOFF00001', $fee( 'BF10-OFF1' ) );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenOFF00001' ) ); $ok( 'recover_minutes = 0: изтеклият остава изтекъл', empty( $ctx['voucher'] ) && ! Leads::voucher_valid( 'BF10-OFF1', true ) && empty( $GLOBALS['cookies_set'][ Boost::AB_COOKIE ] ) );
$save( $pub );
/* 5) писмото НЕ е показало ваучера (няма го в снимката) → не се възобновява */
$lead( 'nosnap@example.com', 'BF10-NOSN', -60 ); $cart( 'nosnap@example.com', 'tokenNOSNAP01', '' );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenNOSNAP01' ) ); $ok( 'изтекъл, но не е в снимката: не се възобновява', empty( $ctx['voucher'] ) && ! Leads::voucher_valid( 'BF10-NOSN', true ) );
/* 6) използван ваучер / имейл с поръчка по промото → не */
$lead( 'used@example.com', 'BF10-USED', -60, array( 'voucher_order_id' => 501 ) ); $cart( 'used@example.com', 'tokenUSED0001', $fee( 'BF10-USED' ) );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenUSED0001' ) ); $ok( 'използван ваучер: не се връща', empty( $ctx['voucher'] ) && ! Leads::voucher_valid( 'BF10-USED', true ) );
$lead( 'conv@example.com', 'BF10-CONV', -60, array( 'converted_order_id' => 502 ) ); $cart( 'conv@example.com', 'tokenCONV0001', $fee( 'BF10-CONV' ) );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenCONV0001' ) ); $ok( 'имейлът вече има поръчка по промото: не се възобновява', empty( $ctx['voucher'] ) );
/* 7) количката вече е поръчка / твърде стара / промото е свършило или изключено */
$lead( 'ord@example.com', 'BF10-ORD1', -60 ); $cart( 'ord@example.com', 'tokenORD00001', $fee( 'BF10-ORD1' ), array( 'order_id' => 77 ) );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenORD00001' ) ); $ok( 'количката е станала поръчка: не', empty( $ctx['voucher'] ) );
$lead( 'old@example.com', 'BF10-OLD1', -60 ); $cart( 'old@example.com', 'tokenOLD00001', $fee( 'BF10-OLD1' ), array( 'last_modified' => $utc( -4 * 86400 ) ) );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenOLD00001' ) ); $ok( 'количка на 4 дни: не се възобновява', empty( $ctx['voucher'] ) );
$lead( 'over@example.com', 'BF10-OVER', -60 ); $cart( 'over@example.com', 'tokenOVER0001', $fee( 'BF10-OVER' ) );
$cv = $pub; $cv['force'] = 'over'; $save( $cv ); $ctx = $req( array( 'bwfan-ab-id' => 'tokenOVER0001' ) ); $ok( 'промото е свършило: не', empty( $ctx['voucher'] ) );
$cd = $pub; $cd['enabled'] = false; $save( $cd ); $ctx = $req( array( 'bwfan-ab-id' => 'tokenOVER0001' ) ); $ok( 'промото е изключено: не', empty( $ctx['voucher'] ) );
$save( $pub ); $ctx = $req( array( 'bwfan-ab-id' => 'tokenOVER0001' ) ); $ok( 'същата количка при включено промо: да', 'BF10-OVER' === ( $ctx['voucher']['code'] ?? '' ) );
/* 8) два кода на един имейл: връща се този от снимката, не най-новият */
$lead( 'two@example.com', 'BF10-AAAA', -900 ); $lead( 'two@example.com', 'BF10-BBBB', -300 ); $cart( 'two@example.com', 'tokenTWO00001', $fee( 'BF10-AAAA' ) );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenTWO00001' ) ); $ok( 'два кода: възобновен е този от писмото', 'BF10-AAAA' === ( $ctx['voucher']['code'] ?? '' ) && ! Leads::voucher_valid( 'BF10-BBBB', true ) );
/* 9) чужд / непознат / счупен токен; uid без количка */
$ctx = $req( array( 'bwfan-ab-id' => 'nosuchtoken123' ) ); $ok( 'непознат токен: нищо, без бисквитка', empty( $ctx ) && empty( $GLOBALS['cookies_set'] ) );
$ctx = $req( array( 'bwfan-ab-id' => "x' OR '1'='1" ) ); $ok( 'инжекция в токена: нищо', empty( $ctx ) );
$ctx = $req( array( 'bwfan-ab-id' => array( 'a' ) ) ); $ok( 'токен масив: нищо', empty( $ctx ) );
/* 10) токенът не намира ред (друга колона/изтрит), но uid-ът на контакта → последната му количка */
$lead( 'uid@example.com', 'BF10-UID1', -60 ); $cart( 'uid@example.com', 'tokenUID00001', $fee( 'BF10-UID1' ) ); $wpdb->insert( 'wp_bwf_contact', array( 'email' => 'uid@example.com', 'uid' => 'abcdef0123456789abcdef0123456789' ) );
$ctx = $req( array( 'bwfan-ab-id' => 'someOtherToken1', 'bwfan-uid' => 'abcdef0123456789abcdef0123456789' ) ); $ok( 'по uid на контакта: ваучерът е върнат', 'BF10-UID1' === ( $ctx['voucher']['code'] ?? '' ) );
/* 11) същият браузър, ваучерът в сесията е жив → без заявки за възобновяване, без бисквитка */
$GLOBALS['wc']->session = new FakeSession(); Cart::set_ctx( array( 'voucher' => array( 'code' => 'BF10-LIVE', 'amount' => 10.0, 'exp' => $e0, 'lead' => 2 ), 'email' => 'live@example.com' ) ); $n0 = (int) Boost::recover_log()['n'];
$ctx = $req( array( 'bwfan-ab-id' => 'tokenLIVE0001' ), false ); $ok( 'жив ваучер в сесията: нищо не се прави', 'BF10-LIVE' === $ctx['voucher']['code'] && $n0 === (int) Boost::recover_log()['n'] && empty( $GLOBALS['cookies_set'] ) );
/* 12) същият браузър, ваучерът в сесията е изтекъл → възобновява се същият код */
$lead( 'same@example.com', 'BF10-SAME', -120 ); $cart( 'same@example.com', 'tokenSAME0001', $fee( 'BF10-SAME' ) );
$GLOBALS['wc']->session = new FakeSession(); Cart::set_ctx( array( 'voucher' => array( 'code' => 'BF10-SAME', 'amount' => 10.0, 'exp' => time() - 120, 'lead' => 9 ), 'email' => 'same@example.com', 'mode' => 'wallet' ) );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenSAME0001' ), false ); $ok( 'изтекъл в същата сесия: пак е жив, exp е обновен, режимът е запазен', Leads::voucher_valid( 'BF10-SAME' ) && $ctx['voucher']['exp'] > time() + 1700 && 'wallet' === $ctx['mode'] );
/* 13) в админа не се пипа; липсваща таблица на FKA не гърми и не пише грешки */
$GLOBALS['is_admin'] = true; $ctx = $req( array( 'bwfan-ab-id' => 'tokenNOSNAP01' ) ); $ok( 'в wp-admin: нищо', empty( $ctx ) ); $GLOBALS['is_admin'] = false;
$wpdb->query( 'DROP TABLE wp_bwfan_abandonedcarts' ); $wpdb->query( 'DROP TABLE wp_bwf_contact' );
$ctx = $req( array( 'bwfan-ab-id' => 'tokenNOSNAP01', 'bwfan-uid' => 'abcdef0123456789abcdef0123456789' ) ); $ok( 'без таблиците на FunnelKit: нищо, без SQL грешки', empty( $ctx ) && array() === $wpdb->errors, implode( ' | ', $wpdb->errors ) );

if ( $fail ) { echo "FAIL (" . count( $fail ) . " от $n):\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
