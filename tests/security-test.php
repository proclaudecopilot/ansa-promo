<?php
/**
 * Офлайн тест на сигурността (1.54.3): Client_IP (CF header само от Cloudflare), Leads::ip(), кодът на ваучера
 * (6 знака), ajax claim-ът не слага чужд имейл в сесията, общият таван на грешните кодове. Без WordPress.
 * php tests/security-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
$GLOBALS['tr'] = array(); if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $t = 0 ) { $GLOBALS['tr'][ $k ] = $v; return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v ) { return $v; } }
spl_autoload_register( function ( $c ) {
	if ( 0 !== strpos( $c, 'AnsaPromo\\' ) ) { return; } $parts = explode( '\\', substr( $c, 10 ) ); $name = array_pop( $parts ); $dir = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
	$f = ANSA_PROMO_PATH . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php'; if ( is_file( $f ) ) { require_once $f; }
} );
use AnsaPromo\Client_IP;
use AnsaPromo\Leads;
use AnsaPromo\BF\Boost;
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };

/* ── IP ── */
$ok( 'без proxy: REMOTE_ADDR', '94.130.1.2' === Client_IP::get( array( 'REMOTE_ADDR' => '94.130.1.2' ) ) );
$ok( 'подправен CF header от обикновен адрес се игнорира', '94.130.1.2' === Client_IP::get( array( 'REMOTE_ADDR' => '94.130.1.2', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4' ) ) );
$ok( 'от Cloudflare (IPv4): header-ът важи', '85.1.2.3' === Client_IP::get( array( 'REMOTE_ADDR' => '172.68.10.20', 'HTTP_CF_CONNECTING_IP' => '85.1.2.3' ) ) );
$ok( 'от Cloudflare (IPv6): header-ът важи', '2a01:5a8::1' === Client_IP::get( array( 'REMOTE_ADDR' => '2606:4700:10::1', 'HTTP_CF_CONNECTING_IP' => '2a01:5a8::1' ) ) );
$ok( 'CIDR граници', Client_IP::in_cidr( '162.159.255.255', '162.158.0.0/15' ) && ! Client_IP::in_cidr( '162.160.0.0', '162.158.0.0/15' ) && ! Client_IP::in_cidr( '1.2.3.4', '2606:4700::/32' ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$a = array(); for ( $i = 0; $i < 30; $i++ ) { $_SERVER['HTTP_CF_CONNECTING_IP'] = '10.9.8.' . $i; $a[] = Leads::ip(); }
$ok( 'Leads::ip(): 30 различни подправени header-а = един и същ IP', array( '203.0.113.7' ) === array_values( array_unique( $a ) ) );
$GLOBALS['tr'] = array(); $hit = 0;
for ( $i = 0; $i < 20; $i++ ) { $_SERVER['HTTP_CF_CONNECTING_IP'] = '10.9.8.' . $i; if ( Leads::rate_limited( 'bf_claim', 12 ) ) { $hit++; } }
$ok( 'лимитът 12/мин хваща въпреки сменящия се header', 8 === $hit, 'блокирани: ' . $hit );
unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );

/* ── кодът на ваучера ── */
class FakeDb { public $prefix = 'wp_'; public function prepare( $q ) { return $q; } public function get_row( $q, $o = null ) { return null; } }
$GLOBALS['wpdb'] = new FakeDb();
$cfg = array( 'cold' => array( 'prefix' => 'BF', 'voucher' => 10 ) );
$codes = array(); for ( $i = 0; $i < 200; $i++ ) { $codes[] = AnsaPromo\BF\Leads::new_voucher_code( $cfg ); }
$ok( 'новият код: BF10- + 6 знака от азбуката без 0/O/1/I', 200 === count( preg_grep( '/^BF10-[A-HJ-NP-Z2-9]{6}$/', $codes ) ), $codes[0] );
$ok( '200 кода — без повторение', 200 === count( array_unique( $codes ) ) );
$ok( 'свой префикс (list ваучер)', 1 === preg_match( '/^BFV10-[A-HJ-NP-Z2-9]{6}$/', AnsaPromo\BF\Leads::new_voucher_code( $cfg, 'BFV10-' ) ) );

/* ── claim: без чужд имейл ── */
$row = array( 'id' => 77, 'email' => 'chujd@abv.bg', 'voucher' => 'BF10-ABCD', 'voucher_exp' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'bonus' => '' );
$bcfg = array( 'cold' => array( 'voucher' => 10 ), 'listv' => array( 'amount' => 10 ) );
$c = Boost::apply_row( $row, $bcfg, false );
$ok( 'въведен код: ваучерът влиза, имейлът на реда НЕ', 'BF10-ABCD' === $c['voucher']['code'] && 10.0 === (float) $c['voucher']['amount'] && empty( $c['email'] ) && 77 === (int) $c['lead'] );
$c = Boost::apply_row( $row, $bcfg );
$ok( 'личен линк (подписан имейл): имейлът влиза', 'chujd@abv.bg' === $c['email'] );
$src = (string) file_get_contents( ANSA_PROMO_PATH . 'includes/bf/class-boost.php' );
$ok( 'ajax_claim вика apply_row без имейл и брои грешните', false !== strpos( $src, 'self::apply_row( $row, $cfg, false );' ) && false !== strpos( $src, 'self::claim_failed(); wp_send_json_error' ) );

/* ── общият таван ── */
$GLOBALS['tr'] = array();
$ok( 'преди грешки: не е заключено', ! Boost::claim_locked() );
for ( $i = 0; $i < Boost::CLAIM_FAILS - 1; $i++ ) { Boost::claim_failed(); }
$ok( ( Boost::CLAIM_FAILS - 1 ) . ' грешни: още не', ! Boost::claim_locked() );
Boost::claim_failed();
$ok( Boost::CLAIM_FAILS . ' грешни (от които и да е адреси): въвеждането на код спира', Boost::claim_locked() );

if ( $fail ) { echo "FAIL:\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
