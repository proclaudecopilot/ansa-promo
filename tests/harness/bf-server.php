<?php
/**
 * Локален сървър за проверка на Черния петък в браузър: истинският PHP на страницата, лийдовете, количката и партньорите (BF\Page,
 * BF\Leads, BF\Cart, BF\Aff + истинският ansa Partner, ако репото е до това) върху WooCommerce в паметта (wcfake.php), със състояние във файл.
 *
 *   BF_STATE=/tmp/bf php -S 127.0.0.1:8899 tests/harness/bf-server.php
 *   node tests/harness/bf-partner-browser.js        (пуска сървъра сам)
 *
 * Адреси: /cheren-petak-2026/ · /wp-admin/admin-ajax.php · /?wc-ajax=ansa_partner_ref · /p/КОД · /checkout/ (?pay=ok|fail|none&email=)
 *         /__phase?p=pause|public|… (сменя фазата) · /__state (JSON: лийдове, купони, поръчки, писма).
 */
$uri = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); $root = dirname( __DIR__, 2 ) . '/';
if ( 0 === strpos( $uri, '/wp-content/plugins/ansa-promo/' ) ) {
	$f = realpath( $root . substr( $uri, 31 ) ); if ( ! $f || 0 !== strpos( $f, realpath( $root ) ) || ! is_file( $f ) ) { http_response_code( 404 ); return; }
	$mime = array( 'css' => 'text/css', 'js' => 'application/javascript', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2' );
	header( 'Content-Type: ' . ( $mime[ strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ) ] ?? 'application/octet-stream' ) ); readfile( $f ); return;
}
$dir = rtrim( getenv( 'BF_STATE' ) ?: sys_get_temp_dir() . '/bf-server', '/' ); if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
putenv( 'WCFAKE_DB=' . $dir . '/db.sqlite' ); define( 'ANSA_PROMO_URL', '/wp-content/plugins/ansa-promo/' );
require __DIR__ . '/wcfake.php';
$GLOBALS['home'] = 'http://' . $_SERVER['HTTP_HOST'];
/* състоянието от предишните заявки */
$sf = $dir . '/state.ser'; $st = is_file( $sf ) ? unserialize( file_get_contents( $sf ) ) : array();
foreach ( array( 'coupons', 'coupon_seq', 'orders', 'order_seq', 'opts', 'transients', 'mails', 'user_meta' ) as $k ) { if ( isset( $st[ $k ] ) ) { $GLOBALS[ $k ] = $st[ $k ]; } }
$sid = preg_replace( '/[^a-z0-9]/', '', (string) ( $_COOKIE['sid'] ?? '' ) ); if ( '' === $sid ) { $sid = bin2hex( random_bytes( 8 ) ); setcookie( 'sid', $sid, 0, '/' ); }
if ( isset( $st['sessions'][ $sid ] ) ) { $GLOBALS['wc']->session = $st['sessions'][ $sid ][0]; $GLOBALS['wc']->cart = $st['sessions'][ $sid ][1]; }
require __DIR__ . '/bf-boot.php';
use AnsaPromo\BF\Config; use AnsaPromo\BF\Page; use AnsaPromo\BF\Cart;
Page::register(); /* ajax-ът ansa_bf_runtime */
if ( ! Config::has_published() ) { bf_cfg( array(), getenv( 'BF_PHASE' ) ?: 'pause' ); }
bf_prods();
$_SERVER['REMOTE_ADDR'] = '10.1.' . mt_rand( 0, 250 ) . '.' . mt_rand( 1, 250 );
$out = function ( $data ) { header( 'Content-Type: application/json; charset=utf-8' ); echo json_encode( $data, JSON_UNESCAPED_UNICODE ); };

try {
	if ( '/wp-admin/admin-ajax.php' === $uri ) { $a = sanitize_key( (string) ( $_REQUEST['action'] ?? '' ) ); do_action( 'wp_ajax_nopriv_' . $a ); $out( array( 'success' => false, 'data' => array( 'code' => 'no-action', 'action' => $a ) ) ); }
	elseif ( isset( $_GET['wc-ajax'] ) ) { do_action( 'wc_ajax_' . sanitize_key( (string) $_GET['wc-ajax'] ) ); $out( array( 'ok' => false ) ); }
	elseif ( preg_match( '#^/p/([A-Za-z0-9_\-]+)/?$#', $uri, $m ) ) { $GLOBALS['qv'] = array( 'ansa_partner_ref' => $m[1] ); do_action( 'template_redirect' ); echo 'no redirect'; }
	elseif ( '/__phase' === $uri ) { bf_cfg( array(), sanitize_key( (string) ( $_GET['p'] ?? 'public' ) ) ); bf_prods(); $out( array( 'phase' => Config::phase_now( Config::get_published() ) ) ); }
	elseif ( '/__state' === $uri ) {
		global $wpdb; $olist = array(); /* не $orders: тук сме в глобалния обхват и това е $GLOBALS['orders'] */
		foreach ( $GLOBALS['orders'] as $o ) { $lk = $o->get_meta( '_ansa_partner_attribution' ); $olist[] = array( 'id' => $o->get_id(), 'status' => $o->get_status(), 'total' => $o->get_total(), 'email' => $o->get_billing_email(), 'coupons' => $o->get_coupon_codes(), 'fees' => array_map( function ( $f ) { return array( $f->name, $f->amount ); }, $o->fees ), 'bf' => $o->get_meta( '_ansa_promo_bf' ), 'lock' => is_array( $lk ) ? array( 'partner_id' => $lk['partner_id'], 'partner' => $lk['partner'], 'code' => $lk['coupon_code'], 'rate' => $lk['rate'], 'source' => $lk['source'] ) : null, 'notes' => $o->notes ); }
		$out( array( 'leads' => $wpdb->get_results( 'SELECT id,email,kind,bonus,ref_code,voucher,voucher_exp,voucher_order_id FROM wp_ansa_promo_leads', ARRAY_A ), 'coupons' => array_map( function ( $c ) { return array( 'code' => $c['code'], 'type' => $c['type'], 'amount' => $c['amount'], 'limit' => $c['limit'], 'count' => $c['count'], 'exp' => $c['exp'] ); }, array_values( $GLOBALS['coupons'] ) ), 'orders' => $olist, 'mails' => array_map( function ( $m ) { return array( 'to' => $m[0], 'subject' => $m[1], 'html' => $m[2] ); }, $GLOBALS['mails'] ), 'ctx' => Cart::ctx() ) );
	}
	elseif ( '/checkout/' === $uri || '/checkout' === $uri ) {
		$cart = WC()->cart; $cart->calculate_totals(); $pay = sanitize_key( (string) ( $_GET['pay'] ?? '' ) ); $order = null;
		if ( '' !== $pay ) { $ctx = Cart::ctx(); $order = checkout( (string) ( $_GET['email'] ?? ( $ctx['email'] ?? 'guest@example.com' ) ), $pay ); }
		$sum = array( 'items' => array_values( array_map( function ( $it ) { return array( 'name' => $it['data']->get_name(), 'qty' => $it['quantity'], 'total' => $it['line_total'], 'role' => $it['ansa_promo_bf']['role'] ?? '' ); }, $cart->get_cart() ) ), 'coupons' => $cart->get_applied_coupons(), 'fees' => array_map( function ( $f ) { return array( $f->name, $f->amount ); }, $cart->get_fees() ), 'total' => $cart->get_total( 'edit' ), 'order' => $order ? array( 'id' => $order->get_id(), 'status' => $order->get_status(), 'total' => $order->get_total(), 'coupons' => $order->get_coupon_codes() ) : null );
		header( 'Content-Type: text/html; charset=utf-8' ); echo '<!doctype html><meta charset="utf-8"><title>Чекаут (тест)</title><body style="font:15px/1.5 system-ui;padding:20px"><h1>Чекаут (тест)</h1><pre id="cart">' . esc_html( json_encode( $sum, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ) . '</pre>';
	}
	elseif ( '/cheren-petak-2026/' === $uri || '/cheren-petak-2026' === $uri ) { header( 'Content-Type: text/html; charset=utf-8' ); echo Page::page_html( Config::get_published(), false ); }
	else { header( 'Content-Type: text/html; charset=utf-8' ); echo '<!doctype html><meta charset="utf-8"><title>Начална (тест)</title><h1>Начална страница (тест)</h1><p id="q">' . esc_html( (string) ( $_SERVER['QUERY_STRING'] ?? '' ) ) . '</p>'; }
} catch ( JsonOut $j ) { $out( $j->out ); }
catch ( RedirectExit $r ) { header( 'Location: ' . $r->getMessage(), true, 302 ); }
catch ( Throwable $e ) { http_response_code( 500 ); $out( array( 'error' => get_class( $e ) . ': ' . $e->getMessage(), 'at' => basename( $e->getFile() ) . ':' . $e->getLine() ) ); }

run_cron();
$st['sessions'][ $sid ] = array( $GLOBALS['wc']->session, $GLOBALS['wc']->cart );
foreach ( array( 'coupons', 'coupon_seq', 'orders', 'order_seq', 'opts', 'transients', 'mails', 'user_meta' ) as $k ) { $st[ $k ] = $GLOBALS[ $k ]; }
file_put_contents( $sf, serialize( $st ), LOCK_EX );
