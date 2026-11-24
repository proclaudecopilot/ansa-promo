<?php
/**
 * Общата подготовка за tests/bf-partner-test.php и tests/harness/bf-server.php: заместители на тежките класове на плъгина, таблиците,
 * ansa Partner (истинският му код, ако репото е до това), продуктите и bf_cfg() — публикуван конфиг на Черния петък.
 * Очаква wcfake.php да е зареден. Задава $REAL (има ли истински Partner), $RT (AP_Coupons_Runtime), $pp (пътят му), $ENGINE.
 */
/* ── заместители на тежките класове на плъгина (каталог, Insights, FunnelKit) ── */
eval( 'namespace AnsaPromo;
final class Schema { static function table( $n ) { return "wp_ansa_promo_" . $n; } }
final class Plugin { const CAP = "manage_woocommerce"; }
final class Insights { static function hit() {} static function request_sid( $s ) { return (string) $s; } static function period() { return array(); } }
final class Leads { static function rate_limited() { return false; } static function ip() { return "127.0.0.1"; } static function mark_fka() {} }
final class Lead_Sync { static function available() { return false; } static function sync() { return "off"; } }
final class Merge { static function tag_id() { return 0; } static function term_id() { return 0; } }
final class Catalog {
	static function find_by_sku( $s ) { return 0; }
	static function wc_line( $id ) { $p = \wc_get_product( $id ); if ( ! $p ) { return array( "ok" => false ); } return array( "ok" => true, "price" => $p->get_regular_price(), "img" => "", "wc_name" => $p->get_name(), "stock" => $p->is_in_stock(), "variation_id" => 0, "product_id" => $id ); }
}' );
spl_autoload_register( function ( $class ) {
	if ( 0 !== strpos( $class, 'AnsaPromo\\' ) ) { return; }
	$parts = explode( '\\', substr( $class, 10 ) ); $name = array_pop( $parts ); $dir = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
	$file = ANSA_PROMO_PATH . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php'; if ( is_file( $file ) ) { require_once $file; }
} );
use AnsaPromo\BF\Aff; use AnsaPromo\BF\Boost; use AnsaPromo\BF\Cart; use AnsaPromo\BF\Config; use AnsaPromo\BF\Leads; use AnsaPromo\BF\Page;

/* ── базата ── */
$wpdb->query( "CREATE TABLE IF NOT EXISTS wp_ansa_promo_leads (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT, utm TEXT DEFAULT '', box_seen TEXT DEFAULT '', consent INTEGER DEFAULT 1, ip TEXT DEFAULT '', created_at TEXT, converted_order_id INTEGER NULL, promo TEXT DEFAULT '', kind TEXT DEFAULT '', bonus TEXT DEFAULT '', ref_code TEXT DEFAULT '', voucher TEXT DEFAULT '', voucher_exp TEXT NULL, voucher_order_id INTEGER NULL, fka TEXT DEFAULT '', contact_id INTEGER DEFAULT 0)" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS wp_ansa_partner_partners (partner_id INTEGER PRIMARY KEY, user_id INTEGER DEFAULT 0, display_name TEXT, email TEXT DEFAULT '', phone TEXT DEFAULT '', status TEXT DEFAULT 'active', rate REAL DEFAULT 15, game_reward TEXT DEFAULT 'cash', category TEXT DEFAULT 'affiliate', corp_discount REAL NULL, created_at TEXT DEFAULT '2025-01-01 00:00:00')" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS wp_ansa_partner_coupons (id INTEGER PRIMARY KEY AUTOINCREMENT, partner_id INTEGER, coupon_code TEXT, wc_coupon_id INTEGER DEFAULT 0, active INTEGER DEFAULT 1, kind TEXT DEFAULT 'std', rate REAL NULL, promo_rate REAL NULL, bonus_tickets INTEGER NULL, auto_paused INTEGER DEFAULT 0, created_at TEXT DEFAULT '2025-01-01 00:00:00')" );
$wpdb->query( "CREATE TABLE IF NOT EXISTS wp_ansa_partner_commissions (commission_id INTEGER PRIMARY KEY AUTOINCREMENT, order_partner_key TEXT UNIQUE, order_id INTEGER, partner_id INTEGER, coupon_code TEXT, type TEXT, base_amount REAL, rate_applied REAL, amount REAL, reward TEXT, currency TEXT, status TEXT, products TEXT, note TEXT, created_at TEXT, updated_at TEXT)" );

/* ── ansa Partner: истинският код, ако репото е тук ── */
$pp = getenv( 'ANSA_PARTNER_PATH' ) ?: dirname( ANSA_PROMO_PATH ) . '/affiliate-portal-ansa/'; $pp = rtrim( $pp, '/' ) . '/';
$REAL = is_file( $pp . 'includes/class-ap-coupons.php' ) && is_file( $pp . 'includes/class-ap-attribution.php' );
class AP_Schema { static function table( $n ) { return 'wp_ansa_partner_' . $n; } }
class AP_Game { static function config() { return array( 'raffle_enabled' => 0, 'to' => '' ); } static function visible() { return false; } }
class AP_Manager { const CAP = 'manage_woocommerce'; }
function ansa_partner_safe_mode() { return false; } function ansa_partner_settings() { return array( 'allowed_with_partner' => "ansacart05", 'block_self_referrals' => 1, 'include_vat' => 0, 'legacy_link_prefixes' => '' ); }
function ansa_partner_feed_push() {} function ansa_partner_log_shield() {} function ansa_partner_excluded_product_ids() { return array(); } function ansa_partner_won_statuses() { return array( 'completed' ); } function ansa_partner_status_is_won( $s ) { return 'completed' === $s; }
function get_user_by() { return false; } function get_userdata() { return false; } function wc_format_decimal( $n, $d = 2 ) { return number_format( (float) $n, $d, '.', '' ); } function add_rewrite_rule() {}
$ENGINE = null;
if ( $REAL ) {
	require_once $pp . 'includes/class-ap-partners.php'; require_once $pp . 'includes/class-ap-coupons.php'; require_once $pp . 'includes/class-ap-attribution.php';
	$RT = new AP_Coupons_Runtime(); new AP_Attribution();
} else {
	/* без репото на Partner: само това, което страницата пита (кой е партньорът на кода) */
	class AP_Partners { static function get( $id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM wp_ansa_partner_partners WHERE partner_id = %d', (int) $id ) ); } }
	class AP_Coupons { static function get_mapping_by_code( $c ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM wp_ansa_partner_coupons WHERE coupon_code = %s', wc_format_coupon_code( $c ) ) ); } }
	$RT = null;
}
/** Партньор + кодовете му (ред в Partner и WooCommerce купон). */
function partner( $id, $name, $codes, $status = 'active' ) {
	global $wpdb; $wpdb->insert( 'wp_ansa_partner_partners', array( 'partner_id' => $id, 'display_name' => $name, 'status' => $status, 'email' => 'p' . $id . '@partner.test' ) );
	foreach ( $codes as $code => $kind ) { $wid = make_coupon( $code, 'percent', 'game' === $kind ? 0 : 5 ); $wpdb->insert( 'wp_ansa_partner_coupons', array( 'partner_id' => $id, 'coupon_code' => wc_format_coupon_code( $code ), 'wc_coupon_id' => $wid, 'kind' => $kind ) ); }
}
if ( ! (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_ansa_partner_partners' ) ) {
partner( 1, 'Мария Иванова', array( 'ANSSID5ANSA' => 'std', 'ANSIANSAEA' => 'game' ) );
partner( 2, 'Ива Петрова', array( 'IVA5ANSA' => 'std' ) );
partner( 3, 'Спряна Партньорка', array( 'STOP5ANSA' => 'std' ), 'paused' );
make_coupon( 'SUMMER10', 'percent', 10 );
}

/* ── продуктите и конфигът на Черния петък ── */
foreach ( array( 101 => array( 'Daily Greens', 39.90 ), 102 => array( 'ДихидроБерберин', 49.90 ), 103 => array( 'Sakura SkinTonic', 44.90 ), 201 => array( 'KeraTonica', 34.90 ), 11163 => array( 'CollaMask 10 маски', 29.90 ), 900 => array( 'Редовен продукт', 25.00 ) ) as $id => $d ) { $GLOBALS['products'][ $id ] = new WC_Product_Fake( $id, $d[0], $d[1] ); }
/** Продуктите на страницата (transient-ът, който Page::products чете — без каталога на WooCommerce). */
function bf_prods() {
	$prods = array(); foreach ( array( 'greens' => 101, 'berber' => 102, 'sakura' => 103, 'kera' => 201 ) as $key => $id ) { $p = $GLOBALS['products'][ $id ]; $prods[ $key ] = array( 'key' => $key, 'id' => $id, 'vid' => 0, 'name' => $p->name, 'sub' => 'хранителна добавка', 'cat' => '', 'desc' => '', 'ing' => '', 'how' => '', 'who' => '', 'img' => '', 'price' => $p->regular, 'stock' => true, 'giftOnly' => 201 === $id, 'isNew' => 201 === $id, 'fav' => false ); }
	set_transient( Config::runtime_key(), $prods, 300 );
}
/** Публикува конфиг с дадените промени (force = фазата). */
function bf_cfg( $over = array(), $force = 'public' ) {
	$c = Config::defaults(); $c['enabled'] = true; $c['force'] = $force;
	foreach ( $over as $k => $v ) { $c[ $k ] = is_array( $v ) ? array_merge( $c[ $k ], $v ) : $v; }
	Config::save_draft( $c ); Config::publish();
	bf_prods(); $GLOBALS['transients'] = array_filter( $GLOBALS['transients'], function ( $k ) { return 0 !== strpos( $k, 'ansa_bf_partner_' ); }, ARRAY_FILTER_USE_KEY );
	return Config::get_published();
}
AnsaPromo\Partner::register(); Cart::register(); Leads::register(); Boost::register(); Aff::register();

