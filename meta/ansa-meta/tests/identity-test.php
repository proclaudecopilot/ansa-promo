<?php
/**
 * Офлайн тест на Identity — нормализацията и хешовете по спецификацията на Meta, fbp/fbc, CIDR, изключването, данните от поръчка.
 * php tests/identity-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaMeta\Identity; use AnsaMeta\Settings; use AnsaMeta\Woo;

/* ── нормализация ── */
eq( Identity::norm_email( '  Maria.Ivanova@Example.COM ' ), 'maria.ivanova@example.com', 'em: малки букви, без интервали' );
eq( Identity::norm_email( 'не-е-имейл' ), '', 'em: невалиден → празно' );
eq( Identity::norm_phone( '0888 123 456', 'BG' ), '359888123456', 'ph: BG с водеща 0 → 359…' );
eq( Identity::norm_phone( '+359 (888) 123-456' ), '359888123456', 'ph: +359 → цифри' );
eq( Identity::norm_phone( '00359888123456' ), '359888123456', 'ph: 00 → маха се' );
eq( Identity::norm_phone( '888123456', 'bg' ), '359888123456', 'ph: без 0 и без код → 359…' );
eq( Identity::norm_phone( '(650) 555-1234', 'US' ), '16505551234', 'ph: US → 1…' );
eq( Identity::norm_phone( '12', 'BG' ), '', 'ph: твърде къс → празно' );
eq( Identity::norm_phone( '', 'BG' ), '', 'ph: празен' );
eq( Identity::norm_name( '  Мария-Елена ' ), 'марияелена', 'fn: малки букви, без пунктуация (кирилица е ок)' );
eq( Identity::norm_name( "O'Brien" ), 'obrien', 'ln: без апостроф' );
eq( Identity::norm_name( 'Jean  Luc' ), 'jean luc', 'fn: един интервал' );
eq( Identity::norm_city( 'Стара Загора' ), 'старазагора', 'ct: без интервали' );
eq( Identity::norm_city( 'Menlo Park' ), 'menlopark', 'ct: Menlo Park → menlopark (примерът на Meta)' );
eq( Identity::norm_zip( ' 1000 ' ), '1000', 'zp: BG' );
eq( Identity::norm_zip( '94025-1234' ), '94025', 'zp: US ZIP+4 → 5' );
eq( Identity::norm_zip( 'SW1A 1AA' ), 'sw1a1aa', 'zp: UK малки букви без интервал' );
eq( Identity::norm_country( 'BG' ), 'bg', 'country: малки' );
eq( Identity::norm_country( 'Bulgaria' ), '', 'country: не е ISO2 → празно' );
eq( Identity::norm_state( 'CA', 'us' ), 'ca', 'st: само US' );
eq( Identity::norm_state( 'Sofia', 'bg' ), '', 'st: не-US → празно' );
eq( Identity::hash( 'maria@example.com' ), hash( 'sha256', 'maria@example.com' ), 'hash = sha256' );
eq( Identity::hash( '' ), '', 'hash на празно = празно' );

/* ── user_data ── */
$u = Identity::user_data( array( 'em' => 'Maria@Example.com', 'ph' => '0888123456', 'fn' => 'Мария', 'ln' => 'Иванова', 'ct' => 'София', 'zp' => '1000', 'country' => 'BG', 'st' => 'Sofia-grad', 'external_id' => 'u42', 'ip' => '93.123.45.67', 'ua' => 'Mozilla/5.0', 'fbp' => 'fb.1.1700000000000.1234567890', 'fbc' => 'fb.1.1700000000000.AbCdEf123_-' ) );
eq( $u['em'], array( hash( 'sha256', 'maria@example.com' ) ), 'user_data.em е масив с хеш' );
eq( $u['ph'], array( hash( 'sha256', '359888123456' ) ), 'user_data.ph нормализиран + хеш' );
eq( $u['fn'], array( hash( 'sha256', 'мария' ) ), 'user_data.fn' );
eq( $u['ct'], array( hash( 'sha256', 'софия' ) ), 'user_data.ct' );
eq( $u['zp'], array( hash( 'sha256', '1000' ) ), 'user_data.zp' );
eq( $u['country'], array( hash( 'sha256', 'bg' ) ), 'user_data.country' );
ok( ! isset( $u['st'] ), 'user_data.st няма за BG' );
eq( $u['external_id'], array( hash( 'sha256', 'u42' ) ), 'external_id хеширан' );
eq( $u['client_ip_address'], '93.123.45.67', 'ip НЕ е хеширан' );
eq( $u['client_user_agent'], 'Mozilla/5.0', 'ua НЕ е хеширан' );
eq( $u['fbp'], 'fb.1.1700000000000.1234567890', 'fbp минава' );
eq( $u['fbc'], 'fb.1.1700000000000.AbCdEf123_-', 'fbc минава' );
$u2 = Identity::user_data( array( 'em' => 'bad', 'fbp' => 'junk', 'fbc' => 'junk', 'ip' => 'not-ip' ) );
eq( $u2, array(), 'невалидните стойности отпадат' );

/* ── pixel AM: чисти стойности, external_id хеширан като на сървъра ── */
$am = Identity::pixel_am( array( 'em' => 'Maria@Example.com', 'ph' => '0888123456', 'external_id' => 'u42', 'country' => 'BG', 'ip' => '1.2.3.4' ) );
eq( $am, array( 'em' => 'maria@example.com', 'ph' => '359888123456', 'country' => 'bg', 'external_id' => hash( 'sha256', 'u42' ) ), 'pixel_am: без ip/ua, external_id = sha256' );

/* ── fbp / fbc ── */
$_COOKIE = array();
$fbp = Identity::fbp(); ok( Identity::valid_fbp( $fbp ), 'fbp се генерира валидно', $fbp );
eq( Identity::fbp(), $fbp, 'fbp се пази в $_COOKIE за заявката' );
$_COOKIE['_fbp'] = 'fb.1.1700000000000.1234567890'; eq( Identity::fbp(), 'fb.1.1700000000000.1234567890', 'fbp от бисквитката' );
unset( $_COOKIE['_fbc'] ); eq( Identity::fbc( '' ), '', 'fbc без fbclid и без бисквитка = празно' );
$fbc = Identity::fbc( 'IwAR0abc_DEF-123' ); ok( preg_match( '/^fb\.1\.\d{13}\.IwAR0abc_DEF-123$/', $fbc ) === 1, 'fbc от fbclid', $fbc );
eq( Identity::fbc( '' ), $fbc, 'fbc остава от бисквитката' );
$fbc2 = Identity::fbc( 'NEWCLICK99' ); ok( substr( $fbc2, -10 ) === 'NEWCLICK99', 'нов fbclid → нова fbc', $fbc2 );
$_GET['fbclid'] = 'FROMQUERY1'; unset( $_COOKIE['_fbc'] ); ok( substr( Identity::fbc(), -10 ) === 'FROMQUERY1', 'fbc от ?fbclid в заявката' ); unset( $_GET['fbclid'] );
$vid = Identity::vid(); ok( preg_match( '/^[a-f0-9]{32}$/', $vid ) === 1, 'vid 32 hex' ); eq( Identity::vid(), $vid, 'vid стабилен' );

/* ── IP / CIDR / ботове ── */
$_SERVER = array( 'HTTP_X_FORWARDED_FOR' => '93.123.45.67, 10.0.0.1', 'REMOTE_ADDR' => '10.0.0.1', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)' );
eq( Identity::client_ip(), '93.123.45.67', 'ip: първият от X-Forwarded-For' );
$_SERVER['HTTP_CF_CONNECTING_IP'] = '5.6.7.8'; eq( Identity::client_ip(), '5.6.7.8', 'ip: Cloudflare печели' ); unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
ok( Identity::ip_in( '10.1.2.3', '10.0.0.0/8' ), 'CIDR /8' ); ok( ! Identity::ip_in( '11.1.2.3', '10.0.0.0/8' ), 'CIDR извън' ); ok( Identity::ip_in( '1.2.3.4', '1.2.3.4' ), 'точен IP' ); ok( ! Identity::ip_in( '', '1.2.3.4' ), 'празен ip' );
ok( Identity::is_bot( 'facebookexternalhit/1.1' ), 'бот: facebookexternalhit' ); ok( Identity::is_bot( '' ), 'бот: празен UA' ); ok( ! Identity::is_bot( 'Mozilla/5.0 (iPhone) Safari' ), 'не е бот' );

/* ── excluded(): роли, IP, съгласие ── */
$GLOBALS['opts']['ansa_meta'] = array( 'exclude_roles' => array( 'administrator' ), 'internal_ips' => "10.0.0.0/8\n1.1.1.1", 'consent_cookie' => '' );
$reset = function () { Identity::reset_excluded(); $r = new ReflectionProperty( 'AnsaMeta\\Settings', 'all' ); $r->setAccessible( true ); $r->setValue( null, null ); };
$reset(); eq( Identity::excluded(), false, 'гост, външен IP → не е изключен' );
$GLOBALS['uid'] = 5; $GLOBALS['roles'] = array( 'administrator' ); $reset(); eq( Identity::excluded(), 'role', 'админ → role' );
$GLOBALS['roles'] = array( 'customer' ); $reset(); eq( Identity::excluded(), false, 'клиент → не' );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.5.5.5'; $reset(); eq( Identity::excluded(), 'ip', 'вътрешен IP → ip' ); $_SERVER['HTTP_X_FORWARDED_FOR'] = '93.1.1.1';
$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1'; $GLOBALS['opts']['ansa_meta']['bot_filter'] = 1; $reset(); eq( Identity::excluded(), 'bot', 'бот → bot' ); $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$GLOBALS['opts']['ansa_meta']['consent_cookie'] = 'cmplz_marketing'; $GLOBALS['opts']['ansa_meta']['consent_value'] = 'allow'; $reset(); eq( Identity::excluded(), 'consent', 'без consent бисквитка → consent' );
$_COOKIE['cmplz_marketing'] = 'allow'; $reset(); eq( Identity::excluded(), false, 'с consent → не' );
$_COOKIE['cmplz_marketing'] = 'deny'; $reset(); eq( Identity::excluded(), 'consent', 'deny → consent' );
$GLOBALS['opts']['ansa_meta']['consent_cookie'] = ''; unset( $GLOBALS['uid'] ); $reset();

/* ── from_order ── */
class FakeDate { function getTimestamp() { return 1700000000; } }
class FakeOrder {
	public $meta = array(); public $saved = 0; public $status = 'processing'; public $customer_id = 0; public $pm = 'cod';
	function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } function save() { $this->saved++; }
	function get_billing_email() { return 'Maria@Example.com'; } function get_billing_phone() { return '0888 123 456'; } function get_billing_first_name() { return 'Мария'; } function get_billing_last_name() { return 'Иванова'; }
	function get_billing_city() { return 'София'; } function get_billing_state() { return ''; } function get_billing_postcode() { return '1000'; } function get_billing_country() { return 'BG'; }
	function get_shipping_phone() { return ''; } function get_shipping_first_name() { return ''; } function get_shipping_last_name() { return ''; } function get_shipping_city() { return ''; } function get_shipping_postcode() { return ''; } function get_shipping_country() { return ''; }
	function get_customer_id() { return $this->customer_id; } function get_customer_ip_address() { return '8.8.8.8'; } function get_customer_user_agent() { return 'UA-from-order'; }
	function get_status() { return $this->status; } function get_id() { return 1001; } function get_order_number() { return '1001'; } function get_date_created() { return new FakeDate(); }
	function get_items( $t = '' ) { return array(); } function get_total() { return 61.5; } function get_currency() { return 'EUR'; } function get_coupon_codes() { return array( 'PROMO10' ); }
	function get_payment_method() { return $this->pm; } function get_shipping_total() { return 2.55; } function get_shipping_tax() { return 0; } function get_shipping_method() { return 'Speedy'; }
	function needs_payment() { return 'pending' === $this->status; } function get_order_key() { return 'wc_order_abc'; }
}
$o = new FakeOrder();
$p = Identity::from_order( $o );
eq( $p['em'], 'Maria@Example.com', 'from_order: em (сурово, нормализира се в user_data)' );
eq( $p['ip'], '8.8.8.8', 'from_order: без ctx → IP от поръчката' ); eq( $p['ua'], 'UA-from-order', 'from_order: UA от поръчката' );
eq( $p['external_id'], 'e' . 'maria@example.com', 'from_order: гост без vid → external_id от имейла' );
$o->meta[ Woo::META_CTX ] = json_encode( array( 'fbp' => 'fb.1.1700000000000.555', 'fbc' => 'fb.1.1700000000000.CLICK', 'ip' => '9.9.9.9', 'ua' => 'UA-ctx', 'vid' => str_repeat( 'a', 32 ) ) );
$p = Identity::from_order( $o );
eq( $p['fbp'], 'fb.1.1700000000000.555', 'from_order: fbp от ctx' ); eq( $p['ip'], '9.9.9.9', 'from_order: IP от ctx печели' ); eq( $p['external_id'], str_repeat( 'a', 32 ), 'from_order: vid от ctx' );
$o->customer_id = 7; eq( Identity::from_order( $o )['external_id'], 'u7', 'from_order: клиент → u<id>' );
$ud = Identity::user_data( Identity::from_order( $o ) );
eq( array_keys( $ud ), array( 'em', 'ph', 'fn', 'ln', 'ct', 'zp', 'country', 'external_id', 'client_ip_address', 'client_user_agent', 'fbp', 'fbc' ), 'user_data от поръчка: всичките 12 параметъра' );

done();
