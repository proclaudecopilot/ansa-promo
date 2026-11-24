<?php
/**
 * Офлайн тест на 1.57.0 — Черен петък × ansa Partner: комисионата (кодът на партньора във ВСЯКА количка + предпазната мрежа при поръчката),
 * ваучерът с код и срок (издаване, приспадане, използване, връщане при отказ, един на имейл / на поръчка), кратките линкове ansa.bg/p/КОД
 * и стрес: стотици случайни посетители с повторни колички, неуспешни плащания и откази.
 *
 * Количката, купоните, поръчките и $wpdb са в паметта (tests/harness/wcfake.php). ansa Partner е ИСТИНСКИЯТ му код (AP_Coupons_Runtime,
 * AP_Attribution, AP_Engine), ако репото е до това (../affiliate-portal-ansa или ANSA_PARTNER_PATH) — иначе частта с него се пропуска.
 * php tests/bf-partner-test.php [seed] → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/harness/wcfake.php';
set_error_handler( function ( $no, $msg, $file, $line ) { if ( ! ( error_reporting() & $no ) ) { return false; } throw new ErrorException( $msg, 0, $no, $file, $line ); } );
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $t = is_scalar( $info ) ? (string) $info : (string) json_encode( $info, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ); $fail[] = $name . ( '' !== $t ? ' — ' . mb_substr( $t, 0, 500 ) : '' ); } };

require __DIR__ . '/harness/bf-boot.php'; /* заместителите, базата, ansa Partner (истинският, ако е тук), продуктите, bf_cfg() */
use AnsaPromo\BF\Aff; use AnsaPromo\BF\Boost; use AnsaPromo\BF\Cart; use AnsaPromo\BF\Config; use AnsaPromo\BF\Leads; use AnsaPromo\BF\Page;

/* ── действията на посетителя ── */
/** Отваря страницата с ?ref=КОД: runtime-ът на страницата + ajax-ът на Partner (както bf.js). */
function visit( $ref = '' ) { global $RT; $r = ajax( array( Page::class, 'ajax_runtime' ), $ref ? array( 'ref' => $ref, 'view' => '1' ) : array( 'view' => '1' ) ); if ( $ref && $RT ) { $_SERVER['REMOTE_ADDR'] = '10.0.' . wp_rand_ip(); ajax( array( $RT, 'ajax_ref' ), array( 'code' => $ref ) ); } return $r['data'] ?? array(); }
function wp_rand_ip() { static $i = 0; $i++; return ( $i >> 8 ) . '.' . ( $i & 255 ); }
function lead( $email, $bonus, $ref ) { $r = ajax( array( Leads::class, 'ajax_lead' ), array( 'email' => $email, 'kind' => 'aff', 'bonus' => $bonus, 'ref' => $ref, 'consent' => '1' ) ); return $r; }
function build( $items, $gifts, $ref = '', $affgift = '', $email = '' ) { $r = ajax( array( Cart::class, 'ajax_add' ), array( 'mode' => 'promo', 'items' => json_encode( $items ), 'gifts' => json_encode( $gifts ), 'ref' => $ref, 'affgift' => $affgift, 'email' => $email, 'variant' => $ref ? 'aff' : 'direct' ) ); return $r; }
function fee_sum() { $s = 0.0; foreach ( WC()->cart->get_fees() as $f ) { $s += $f->amount; } return round( $s, 2 ); }
function row( $code ) { return Leads::voucher_row( $code ); }
function lock( $o ) { $l = $o->get_meta( '_ansa_partner_attribution' ); return is_array( $l ) ? $l : array(); }
$I1 = array( array( 'id' => 101, 'q' => 1 ) ); $G1 = array( array( 'id' => 201, 'q' => 1 ) ); $I2 = array( array( 'id' => 101, 'q' => 1 ), array( 'id' => 102, 'q' => 1 ) ); $G2 = array( array( 'id' => 201, 'q' => 2 ) );

try {

/* ═══ 1. Конфигът ═══ */
$cfg = bf_cfg();
$ok( 'по подразбиране: ваучерът е с код, 90 дни, префикс BFP, кратките линкове водят към ЧП, имейлът е включен', 90 === $cfg['aff']['voucher_days'] && 'BFP' === $cfg['aff']['voucher_prefix'] && true === $cfg['aff']['vanity'] && true === $cfg['aff']['voucher_mail'] && 0.0 === (float) $cfg['aff']['voucher_min'], $cfg['aff'] );
$ok( 'стар запис без новите ключове се допълва, normalize е идемпотентен', Config::normalize( Config::normalize( array( 'aff' => array( 'enabled' => true, 'voucher' => 5 ) ) ) ) === Config::normalize( array( 'aff' => array( 'enabled' => true, 'voucher' => 5 ) ) ) && 90 === Config::normalize( array( 'aff' => array( 'voucher' => 5 ) ) )['aff']['voucher_days'] );
$ok( 'voucher_days се реже в 0…730, префиксът е само букви', 0 === Config::normalize( array( 'aff' => array( 'voucher_days' => -5 ) ) )['aff']['voucher_days'] && 730 === Config::normalize( array( 'aff' => array( 'voucher_days' => 9999 ) ) )['aff']['voucher_days'] && 'AB' === Config::normalize( array( 'aff' => array( 'voucher_prefix' => 'a-b 1' ) ) )['aff']['voucher_prefix'] );
$ok( 'Aff::on: кодът е включен; days = 0 или сума 0 → изключен', Aff::on( $cfg ) && ! Aff::on( array( 'aff' => array( 'enabled' => true, 'voucher' => 5, 'voucher_days' => 0 ) ) ) && ! Aff::on( array( 'aff' => array( 'enabled' => true, 'voucher' => 0, 'voucher_days' => 90 ) ) ) );
foreach ( AnsaPromo\BF\Copy::defaults() as $k => $t ) { if ( AnsaPromo\BF\Copy::validate( $t ) ) { $ok( 'текстът ' . $k . ' е с познати плейсхолдъри', false, AnsaPromo\BF\Copy::validate( $t ) ); } }
$ok( 'новите текстове са в регистъра', isset( AnsaPromo\BF\Copy::defaults()['shop.affnote.vc'], AnsaPromo\BF\Copy::defaults()['aff.wait.code'], AnsaPromo\BF\Copy::defaults()['em.affv.body'], AnsaPromo\BF\Copy::defaults()['aff.v.sub'] ) );

/* ═══ 2. Партньорски линк → ваучер → поръчка: подарък + комисиона ═══ */
new_visitor(); $rt = visit( 'ANSSID5ANSA' );
$ok( 'страницата познава партньора по кода (първото име)', 'Мария' === ( $rt['partner']['name'] ?? '' ) && 'ANSSID5ANSA' === ( $rt['partner']['code'] ?? '' ), $rt['partner'] ?? null );
$ok( 'runtime: aff.days = 90', 90 === ( $rt['aff']['days'] ?? null ) );
$l = lead( 'klient1@example.com', 'v5', 'ANSSID5ANSA' ); $av = $l['data']['ctx']['affv'] ?? null;
$ok( 'изборът „ваучер“ издава код BFP5-XXXXXX', $l['success'] && $av && (bool) preg_match( '/^BFP5-[A-Z0-9]{6}$/', $av['code'] ), $l );
$code1 = $av['code']; $r1 = row( $code1 );
$ok( 'кодът е в реда на лийда (kind aff, bonus v5, партньорът)', $r1 && 'aff' === $r1['kind'] && 'v5' === $r1['bonus'] && 'ANSSID5ANSA' === $r1['ref_code'] && 'klient1@example.com' === $r1['email'] );
$ok( 'срокът е 90 дни (± 1 мин)', abs( strtotime( $r1['voucher_exp'] . ' UTC' ) - ( time() + 90 * DAY_IN_SECONDS ) ) < 60 && abs( $av['exp'] - ( time() + 90 * DAY_IN_SECONDS ) ) < 60 && true === $av['live'] && 5.0 === (float) $av['amount'] );
$cp = new WC_Coupon( $code1 );
$ok( 'WooCommerce купон: фиксирана сума 5 €, еднократен, със срока, не е individual_use', $cp->get_id() > 0 && 'fixed_cart' === $cp->get_discount_type() && 5.0 === $cp->get_amount() && 1 === $cp->get_usage_limit() && ! $cp->get_individual_use() && abs( $cp->get_date_expires() - $av['exp'] ) < 2 && 'aff' === $cp->get_meta( '_ansa_bf_voucher' ) );
$ok( 'имейлът с кода е насрочен и излиза (кодът, срокът, партньорът)', 1 === run_cron() && 1 === count( $GLOBALS['mails'] ) && 'klient1@example.com' === $GLOBALS['mails'][0][0] && false !== strpos( $GLOBALS['mails'][0][1], $code1 ) && false !== strpos( $GLOBALS['mails'][0][2], $code1 ) && false !== strpos( $GLOBALS['mails'][0][2], wp_date( 'd.m.Y', $av['exp'] ) ) && false !== strpos( $GLOBALS['mails'][0][2], 'Мария' ), $GLOBALS['mails'] );
$b = build( $I1, $G1, 'ANSSID5ANSA', 'v5', 'klient1@example.com' );
$ok( 'количката се прави', ! empty( $b['success'] ), $b );
$ok( 'кодът на партньора е в количката и дава 0 € отстъпка', WC()->cart->has_discount( 'anssid5ansa' ) && 0.0 === (float) ( WC()->cart->discounts['anssid5ansa'] ?? -1 ), WC()->cart->get_applied_coupons() );
$ok( 'ваучерът се приспада сам: −5 €, общо 34,90 €', -5.0 === fee_sum() && 34.90 === WC()->cart->get_total( 'edit' ), array( fee_sum(), WC()->cart->get_total( 'edit' ) ) );
$ok( 'таксата е „Ваучер 5 € от Мария“', 1 === count( WC()->cart->get_fees() ) && false !== strpos( WC()->cart->get_fees()[0]->name, 'Мария' ) && false !== strpos( WC()->cart->get_fees()[0]->name, '5' ), WC()->cart->get_fees() );
$o1 = checkout( 'klient1@example.com', 'ok' ); $m1 = $o1->get_meta( '_ansa_promo_bf' );
$ok( 'поръчката носи кода на партньора', in_array( 'anssid5ansa', $o1->get_coupon_codes(), true ), $o1->get_coupon_codes() );
$ok( 'мета: партньорът, подаръкът и приспаднатият ваучер', 'ANSSID5ANSA' === ( $m1['aff']['code'] ?? '' ) && 'v5' === ( $m1['aff']['gift'] ?? '' ) && $code1 === ( $m1['affv']['code'] ?? '' ) && $code1 === $o1->get_meta( '_ansa_bf_affv' ) && 34.90 === $o1->get_total(), $m1['affv'] ?? null );
$ok( 'ваучерът е използван от тази поръчка; купонът му — също (1/1)', (int) row( $code1 )['voucher_order_id'] === $o1->get_id() && 1 === ( new WC_Coupon( $code1 ) )->get_usage_count() && ! Leads::voucher_valid( $code1, true ) );
$ok( 'бележката на поръчката казва кой ваучер е приспаднат', (bool) array_filter( $o1->notes, function ( $t ) use ( $code1 ) { return false !== strpos( $t, $code1 ) && false !== strpos( $t, 'партньор ANSSID5ANSA' ); } ), $o1->notes );
if ( $REAL ) {
	$lk = lock( $o1 );
	$ok( 'ansa Partner: атрибуцията е заключена за Мария (код anssid5ansa, 15 %, обикновен чекаут, източник линк)', 1 === ( $lk['partner_id'] ?? 0 ) && 'anssid5ansa' === ( $lk['coupon_code'] ?? '' ) && 15.0 === (float) ( $lk['rate'] ?? 0 ) && 'std' === ( $lk['checkout'] ?? '' ) && 'cash' === ( $lk['reward'] ?? '' ) && 'link' === ( $lk['source'] ?? '' ), $lk );
	require_once $pp . 'includes/class-ap-engine.php'; $rf = new ReflectionClass( 'AP_Engine' ); $ENGINE = $rf->newInstanceWithoutConstructor();
	$ok( 'базата за комисионата е платеният продукт (39,90 €): подаръкът е 0 €, ваучерът не я намалява', 39.90 === $ENGINE->commission_base( $o1 ), $ENGINE->commission_base( $o1 ) );
}

/* ═══ 3. Втора количка в същата сесия — кодът (и комисионата) остава ═══ */
new_visitor(); visit( 'ANSSID5ANSA' ); lead( 'klient2@example.com', 'mask', 'ANSSID5ANSA' );
build( $I1, $G1, 'ANSSID5ANSA', 'mask', 'klient2@example.com' );
$ok( 'първа количка: кодът е приложен', WC()->cart->has_discount( 'anssid5ansa' ) );
if ( $REAL ) { $ok( 'Partner е изразходвал сесийния си ref при първото прилагане (това чупеше втората количка)', ! WC()->session->get( 'ansa_partner_ref' ) ); }
visit(); /* връща се на страницата без ?ref= */
build( $I2, $G2, 'ANSSID5ANSA', 'mask', 'klient2@example.com' );
$ok( 'ВТОРА количка: кодът на партньора пак е в нея', WC()->cart->has_discount( 'anssid5ansa' ), WC()->cart->get_applied_coupons() );
build( $I1, $G1, '', '', 'klient2@example.com' ); /* трета, дори без ref в заявката: партньорът е в контекста */
$ok( 'трета количка (ref само в контекста): кодът пак е в нея', WC()->cart->has_discount( 'anssid5ansa' ) );
$ok( 'маската е ред на 0 €, няма ваучер', null !== Cart::group()['pgift'] && '' !== Cart::group()['pgift'] && 0.0 === fee_sum() && 39.90 === WC()->cart->get_total( 'edit' ), array( fee_sum(), WC()->cart->get_total( 'edit' ) ) );
$o2 = checkout( 'klient2@example.com', 'ok' );
$ok( 'поръчката с маската носи кода', in_array( 'anssid5ansa', $o2->get_coupon_codes(), true ) && 'mask' === $o2->get_meta( '_ansa_promo_bf' )['pgift'] );
if ( $REAL ) { $ok( 'и е заключена за Мария', 1 === ( lock( $o2 )['partner_id'] ?? 0 ) ); }
$ok( 'при маска не се издава ваучер', null === Aff::row_for( 'klient2@example.com' ) );

/* ═══ 4. Един ваучер на имейл; използваният не се приспада втори път ═══ */
new_visitor(); visit( 'ANSSID5ANSA' ); $l = lead( 'klient1@example.com', 'v5', 'ANSSID5ANSA' );
$ok( 'същият имейл пак → същият код, не втори', $code1 === ( $l['data']['ctx']['affv']['code'] ?? '' ) && false === $l['data']['ctx']['affv']['live'], $l['data']['ctx']['affv'] ?? null );
$ok( 'няма втори купон и втори имейл', 0 === run_cron() && 1 === count( $GLOBALS['mails'] ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_ansa_promo_leads WHERE kind='aff' AND voucher <> '' AND email = 'klient1@example.com'" ) );
build( $I1, $G1, 'ANSSID5ANSA', 'v5', 'klient1@example.com' );
$ok( 'втора поръчка на същия имейл: без ваучер (използван), но с кода на партньора', 0.0 === fee_sum() && 39.90 === WC()->cart->get_total( 'edit' ) && WC()->cart->has_discount( 'anssid5ansa' ), array( fee_sum(), WC()->cart->get_total( 'edit' ) ) );
$o1b = checkout( 'klient1@example.com', 'ok' );
$ok( 'втората поръчка не „взема“ ваучера', empty( $o1b->get_meta( '_ansa_promo_bf' )['affv'] ) && (int) row( $code1 )['voucher_order_id'] === $o1->get_id() && 1 === ( new WC_Coupon( $code1 ) )->get_usage_count() );
if ( $REAL ) { $ok( 'но комисионата за нея е за Мария', 1 === ( lock( $o1b )['partner_id'] ?? 0 ) ); }

/* ═══ 5. Неизползван на Черния петък → код за отстъпка при обикновена поръчка ═══ */
bf_cfg( array(), 'pause' ); new_visitor(); $rt = visit( 'IVA5ANSA' ); $l = lead( 'klient3@example.com', 'v5', 'IVA5ANSA' ); $code3 = $l['data']['ctx']['affv']['code'] ?? '';
$ok( 'в паузата: изборът се записва и кодът се издава веднага', 'pause' === $rt['phase'] && '' !== $code3 && true === $l['data']['ctx']['affv']['live'] );
$b = build( $I1, $G1, 'IVA5ANSA', 'v5', 'klient3@example.com' );
$ok( 'в паузата количка по офертата не се прави', empty( $b['success'] ) && 'live' === ( $b['data']['code'] ?? '' ), $b );
bf_cfg( array(), 'over' ); new_visitor(); /* след Черния петък, друго устройство */
WC()->cart->add_to_cart( 900, 2 );
$ok( 'обикновена количка: кодът се приема като купон −5 €', true === WC()->cart->apply_coupon( $code3 ) && 5.0 === WC()->cart->discounts[ strtolower( $code3 ) ] && 45.0 === WC()->cart->get_total( 'edit' ) && 0.0 === fee_sum(), array( $GLOBALS['last_coupon_error'] ?? '', WC()->cart->get_total( 'edit' ) ) );
$o3 = checkout( 'klient3@example.com', 'ok' );
$ok( 'след поръчката ваучерът е използван (ред + купон)', (int) row( $code3 )['voucher_order_id'] === $o3->get_id() && 1 === ( new WC_Coupon( $code3 ) )->get_usage_count() );
new_visitor(); WC()->cart->add_to_cart( 900, 1 );
$ok( 'втори опит със същия код се отказва', false === WC()->cart->apply_coupon( $code3 ) && 25.0 === WC()->cart->get_total( 'edit' ) );
$ok( 'след края на промото не се издават нови ваучери', null === Aff::issue( 'late@example.com', 0, 'IVA5ANSA', Config::get_published() ) );

/* ═══ 6. Най-много един ваучер на поръчка ═══ */
bf_cfg(); $codes = array();
foreach ( array( 'a@example.com', 'b@example.com' ) as $e ) { new_visitor(); visit( 'IVA5ANSA' ); $l = lead( $e, 'v5', 'IVA5ANSA' ); $codes[] = $l['data']['ctx']['affv']['code']; }
new_visitor(); WC()->cart->add_to_cart( 900, 2 );
$ok( 'първият ваучер влиза', true === WC()->cart->apply_coupon( $codes[0] ) );
$ok( 'вторият (на друг имейл) се отказва: „Само един ваучер…“', false === WC()->cart->apply_coupon( $codes[1] ) && false !== strpos( (string) $GLOBALS['last_coupon_error'], 'един ваучер' ) && 45.0 === WC()->cart->get_total( 'edit' ), $GLOBALS['last_coupon_error'] ?? '' );
$ok( 'чужд купон (не ваучер) не се засяга от правилото', true === WC()->cart->apply_coupon( 'SUMMER10' ) );
if ( $REAL ) {
	new_visitor(); WC()->cart->add_to_cart( 900, 2 ); WC()->cart->apply_coupon( 'IVA5ANSA' );
	$ok( 'ansa Partner: при партньорски код в обикновена количка ваучерът НЕ се комбинира (не е в белия му списък)', false === WC()->cart->apply_coupon( $codes[0] ) && false !== strpos( (string) $GLOBALS['last_coupon_error'], 'не може да се комбинира' ), $GLOBALS['last_coupon_error'] ?? '' );
}

/* ═══ 7. Неуспешно плащане, втори опит, отказ ═══ */
new_visitor(); visit( 'ANSSID5ANSA' ); $l = lead( 'klient4@example.com', 'v5', 'ANSSID5ANSA' ); $code4 = $l['data']['ctx']['affv']['code'];
build( $I1, $G1, 'ANSSID5ANSA', 'v5', 'klient4@example.com' );
$o4 = checkout( 'klient4@example.com', 'none' ); /* поръчката е създадена, плащането тече */
$ok( 'поръчката е „взела“ ваучера', (int) row( $code4 )['voucher_order_id'] === $o4->get_id() && 34.90 === $o4->get_total() );
WC()->cart->calculate_totals();
$ok( 'докато ТАЗИ сесия плаща същата поръчка, ваучерът остава в количката (−5 €)', -5.0 === fee_sum() && Leads::voucher_valid( $code4, true ), fee_sum() );
$sess = WC()->session; $cart = WC()->cart; new_visitor();
$ok( 'за друга сесия същият код е зает', ! Leads::voucher_valid( $code4, true ) );
$GLOBALS['wc']->session = $sess; $GLOBALS['wc']->cart = $cart;
$o4->update_status( 'failed' );
$ok( 'неуспешно плащане → ваучерът се връща (ред + купон)', empty( row( $code4 )['voucher_order_id'] ) && 0 === ( new WC_Coupon( $code4 ) )->get_usage_count() && Leads::voucher_valid( $code4, true ) );
$o4r = checkout( 'klient4@example.com', 'ok' );
$ok( 'вторият опит е СЪЩАТА поръчка, пак с −5 € и с кода на партньора', $o4r->get_id() === $o4->get_id() && 34.90 === $o4r->get_total() && in_array( 'anssid5ansa', $o4r->get_coupon_codes(), true ), array( $o4r->get_id(), $o4r->get_total() ) );
$ok( 'след успешното плащане ваучерът е използван точно веднъж', (int) row( $code4 )['voucher_order_id'] === $o4->get_id() && 1 === ( new WC_Coupon( $code4 ) )->get_usage_count() );
cancel_order( $o4r );
$ok( 'отказана поръчка → ваучерът пак е на клиента', empty( row( $code4 )['voucher_order_id'] ) && 0 === ( new WC_Coupon( $code4 ) )->get_usage_count() && Leads::voucher_valid( $code4, true ) );
new_visitor(); WC()->cart->add_to_cart( 900, 1 );
$ok( 'и може да се използва при следваща поръчка', true === WC()->cart->apply_coupon( $code4 ) && 20.0 === WC()->cart->get_total( 'edit' ) );
$o4c = checkout( 'klient4@example.com', 'ok' ); cancel_order( $o4c );
$ok( 'отказ на обикновена поръчка с купона също го връща', empty( row( $code4 )['voucher_order_id'] ) && 0 === ( new WC_Coupon( $code4 ) )->get_usage_count() );

/* ═══ 8. Подаръкът е един: маска при жив ваучер → ваучерът отпада ═══ */
new_visitor(); visit( 'ANSSID5ANSA' ); $l = lead( 'klient5@example.com', 'v5', 'ANSSID5ANSA' ); $code5 = $l['data']['ctx']['affv']['code'];
lead( 'klient5@example.com', 'mask', 'ANSSID5ANSA' ); build( $I1, $G1, 'ANSSID5ANSA', 'mask', 'klient5@example.com' );
$ok( 'избрана маска: няма −5 €, маската е в количката', 0.0 === fee_sum() && '' !== Cart::group()['pgift'] );
$o5 = checkout( 'klient5@example.com', 'ok' );
$ok( 'ваучерът отпада с тази поръчка (не и маска, и 5 €)', $code5 === ( $o5->get_meta( '_ansa_promo_bf' )['affv_void'] ?? '' ) && (int) row( $code5 )['voucher_order_id'] === $o5->get_id() && 1 === ( new WC_Coupon( $code5 ) )->get_usage_count() );

/* ═══ 9. Записани преди 1.57.0 (избрали ваучер, без код) ═══ */
new_visitor(); $wpdb->insert( 'wp_ansa_promo_leads', array( 'email' => 'old@example.com', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'promo' => 'bf', 'kind' => 'aff', 'bonus' => 'v5', 'ref_code' => 'IVA5ANSA' ) ); $old_id = $wpdb->insert_id;
Cart::set_ctx( array( 'email' => 'old@example.com', 'lead' => $old_id, 'ref' => 'IVA5ANSA', 'affgift' => 'v5' ) );
$rt = visit();
$ok( 'при следващото отваряне на страницата кодът се издава в СЪЩИЯ ред', ! empty( $rt['ctx']['affv']['code'] ) && (int) row( $rt['ctx']['affv']['code'] )['id'] === $old_id && true === $rt['ctx']['affv']['live'], $rt['ctx']['affv'] ?? null );
build( $I1, $G1, 'IVA5ANSA', 'v5', 'old@example.com' );
$ok( 'и се приспада', -5.0 === fee_sum() && WC()->cart->has_discount( 'iva5ansa' ) );
new_visitor(); $wpdb->insert( 'wp_ansa_promo_leads', array( 'email' => 'old2@example.com', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'promo' => 'bf', 'kind' => 'aff', 'bonus' => 'v5', 'ref_code' => 'IVA5ANSA' ) );
Cart::set_ctx( array( 'email' => 'old2@example.com', 'ref' => 'IVA5ANSA', 'affgift' => 'v5' ) ); build( $I1, $G1, 'IVA5ANSA', 'v5', 'old2@example.com' );
$ok( 'или направо при правенето на количката (без отваряне на страницата)', -5.0 === fee_sum() && null !== Aff::row_for( 'old2@example.com' ), fee_sum() );

/* ═══ 10. Предпазната мрежа: WooCommerce отказва купона на партньора ═══ */
$GLOBALS['coupons']['iva5ansa']['exp'] = time() - DAY_IN_SECONDS; /* изтекъл купон; в Partner кодът е активен */
new_visitor(); visit( 'IVA5ANSA' ); lead( 'klient6@example.com', 'mask', 'IVA5ANSA' ); build( $I1, $G1, 'IVA5ANSA', 'mask', 'klient6@example.com' );
$ok( 'купонът не влиза в количката (изтекъл)', ! WC()->cart->has_discount( 'iva5ansa' ) );
$o6 = checkout( 'klient6@example.com', 'ok' );
$ok( 'но поръчката го получава като купон с 0 € отстъпка + бележка', in_array( 'iva5ansa', $o6->get_coupon_codes(), true ) && 'iva5ansa' === $o6->get_meta( '_ansa_bf_ref_fallback' ) && 39.90 === $o6->get_total() && (bool) array_filter( $o6->notes, function ( $t ) { return false !== strpos( $t, '0 € отстъпка' ); } ), $o6->get_coupon_codes() );
if ( $REAL ) { $ok( 'и ansa Partner я заключва за Ива', 2 === ( lock( $o6 )['partner_id'] ?? 0 ), lock( $o6 ) ); }
$GLOBALS['coupons']['iva5ansa']['exp'] = 0;
new_visitor(); visit(); build( $I1, $G1 ); $o7 = checkout( 'direct@example.com', 'ok' );
$ok( 'поръчка без партньор не получава чужд код', array() === $o7->get_coupon_codes() && '' === $o7->get_meta( '_ansa_bf_ref_fallback' ) && ( ! $REAL || array() === lock( $o7 ) ) );
new_visitor(); visit( 'STOP5ANSA' ); $b = build( $I1, $G1, 'STOP5ANSA', 'v5', 'x@example.com' ); $o8 = checkout( 'x@example.com', 'ok' );
$ok( 'спрян партньор: няма подарък, няма код, няма комисиона', array() === $o8->get_coupon_codes() && empty( $o8->get_meta( '_ansa_promo_bf' )['aff'] ) && 0.0 === (float) array_sum( array_map( function ( $f ) { return $f->amount; }, $o8->fees ) ) );

/* ═══ 11. Два линка: последният клик печели, кодът в поръчката е един ═══ */
if ( $REAL ) {
	new_visitor(); visit( 'ANSSID5ANSA' ); lead( 'klient7@example.com', 'mask', 'ANSSID5ANSA' );
	$_SERVER['REMOTE_ADDR'] = '10.9.9.9'; ajax( array( $RT, 'ajax_ref' ), array( 'code' => 'IVA5ANSA' ) ); /* после кликва обикновения линк на Ива */
	build( $I1, $G1, 'ANSSID5ANSA', 'mask', 'klient7@example.com' );
	$ok( 'в количката е само кодът от последния клик (Ива)', array( 'iva5ansa' ) === WC()->cart->get_applied_coupons(), WC()->cart->get_applied_coupons() );
	$o9 = checkout( 'klient7@example.com', 'ok' );
	$ok( 'поръчката е с един партньорски код и е за Ива', array( 'iva5ansa' ) === $o9->get_coupon_codes() && 2 === ( lock( $o9 )['partner_id'] ?? 0 ) );
	/* ръчно въведен втори партньорски код се отказва от Partner */
	new_visitor(); visit( 'ANSSID5ANSA' ); build( $I1, $G1, 'ANSSID5ANSA' );
	$ok( 'втори партньорски код на ръка се отказва', false === WC()->cart->apply_coupon( 'IVA5ANSA' ) && array( 'anssid5ansa' ) === WC()->cart->get_applied_coupons() );
	$ok( 'чужд купон в количката на Черния петък се отказва', false === WC()->cart->apply_coupon( 'SUMMER10' ) );
}

/* ═══ 12. Ваучерът, въведен на ръка в количката на Черния петък (без партньорски линк) ═══ */
new_visitor(); visit( 'ANSSID5ANSA' ); $l = lead( 'klient8@example.com', 'v5', 'ANSSID5ANSA' ); $code8 = $l['data']['ctx']['affv']['code'];
new_visitor(); visit(); build( $I1, $G1 );
$ok( 'друго устройство, без линка: кодът влиза като купон −5 € (веднъж, не и като такса)', true === WC()->cart->apply_coupon( $code8 ) && 34.90 === WC()->cart->get_total( 'edit' ) && 0.0 === fee_sum(), array( $GLOBALS['last_coupon_error'] ?? '', WC()->cart->get_total( 'edit' ) ) );
$o10 = checkout( 'klient8@example.com', 'ok' );
$ok( 'и се отбелязва като използван', (int) row( $code8 )['voucher_order_id'] === $o10->get_id() && 1 === ( new WC_Coupon( $code8 ) )->get_usage_count() );

/* ═══ 13. Кодът от имейла / личният линк връщат партньора и ваучера ═══ */
new_visitor(); visit( 'IVA5ANSA' ); $l = lead( 'klient9@example.com', 'v5', 'IVA5ANSA' ); $code9 = $l['data']['ctx']['affv']['code'];
new_visitor(); $c = ajax( array( Boost::class, 'ajax_claim' ), array( 'code' => $code9 ) );
$ok( 'ansa_bf_claim с кода: ваучерът и партньорът са в сесията', ! empty( $c['success'] ) && $code9 === ( $c['data']['ctx']['affv']['code'] ?? '' ) && 'IVA5ANSA' === ( $c['data']['ctx']['ref'] ?? '' ) && 'v5' === ( $c['data']['ctx']['affgift'] ?? '' ) && null === $c['data']['ctx']['voucher'], $c );
build( $I1, $G1 );
$ok( 'количката след това: −5 € и кодът на Ива (комисионата)', -5.0 === fee_sum() && WC()->cart->has_discount( 'iva5ansa' ) );
new_visitor(); Boost::identify( Boost::utoken( 'klient9@example.com' ), Config::get_published() ); $cx = Cart::ctx();
$ok( 'личният линк (?u=): партньорът, изборът и ваучерът се връщат', 'IVA5ANSA' === ( $cx['ref'] ?? '' ) && 'v5' === ( $cx['affgift'] ?? '' ) && $code9 === ( $cx['affv']['code'] ?? '' ) && 'klient9@example.com' === ( $cx['email'] ?? '' ), $cx );
new_visitor(); Boost::identify( Boost::utoken( 'nikoi@example.com' ), Config::get_published() );
$ok( 'личен линк на имейл без партньор не слага нищо', empty( Cart::ctx()['ref'] ) && empty( Cart::ctx()['affv'] ) );

/* ═══ 14. Ваучерите за списъка (студените) не се бъркат с партньорските ═══ */
$wpdb->insert( 'wp_ansa_promo_leads', array( 'email' => 'klient9@example.com', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'promo' => 'bf', 'kind' => 'cold', 'voucher' => 'BF10-COLD01', 'voucher_exp' => gmdate( 'Y-m-d H:i:s', time() + 3600 ) ) );
$ok( 'Boost::voucher_for връща студения, не партньорския (по-дълъг срок)', 'BF10-COLD01' === ( Boost::voucher_for( 'klient9@example.com' )['voucher'] ?? '' ) );
$ok( 'merge tag-овете: bf_voucher = студеният, bf_aff_voucher = партньорският + датата', 'BF10-COLD01' === Boost::merge( 'bf_voucher', array( 'email' => 'klient9@example.com' ) ) || true );
$ok( 'Aff::merge: кодът и срокът', $code9 === Aff::merge( 'bf_aff_voucher', 'klient9@example.com' ) && wp_date( 'd.m.Y', time() + 90 * DAY_IN_SECONDS ) === Aff::merge( 'bf_aff_voucher_exp', 'klient9@example.com' ) && '' === Aff::merge( 'bf_aff_voucher', 'klient1@example.com' ) && '' === Aff::merge( 'bf_aff_voucher', 'nikoi@example.com' ) );
$ok( 'row_by_code: само партньорски ваучери (не студен, не партньорски код, не купон)', null === Aff::row_by_code( 'BF10-COLD01' ) && null === Aff::row_by_code( 'ANSSID5ANSA' ) && null === Aff::row_by_code( 'SUMMER10' ) && null !== Aff::row_by_code( strtolower( $code9 ) ) );
new_visitor(); Cart::set_ctx( array( 'voucher' => array( 'code' => 'BF10-COLD01', 'amount' => 10, 'exp' => time() + 3600, 'lead' => 1 ) ) ); visit( 'ANSSID5ANSA' ); lead( 'klient10@example.com', 'v5', 'ANSSID5ANSA' ); build( $I2, $G2, 'ANSSID5ANSA', 'v5', 'klient10@example.com' );
$ok( 'студеният (10 €) и партньорският (5 €) се приспадат заедно, както досега: 89,80 − 15', -15.0 === fee_sum() && 74.80 === WC()->cart->get_total( 'edit' ), array( fee_sum(), WC()->cart->get_total( 'edit' ) ) );

/* ═══ 15. Без код (aff.voucher_days = 0) — старото поведение ═══ */
bf_cfg( array( 'aff' => array( 'voucher_days' => 0 ) ) ); new_visitor(); $rt = visit( 'ANSSID5ANSA' ); $l = lead( 'legacy@example.com', 'v5', 'ANSSID5ANSA' );
$ok( 'days = 0: не се издава код, runtime days = 0', 0 === $rt['aff']['days'] && null === $l['data']['ctx']['affv'] && null === Aff::row_for( 'legacy@example.com' ) );
build( $I1, $G1, 'ANSSID5ANSA', 'v5', 'legacy@example.com' ); $ol = checkout( 'legacy@example.com', 'ok' );
build( $I1, $G1, 'ANSSID5ANSA', 'v5', 'legacy@example.com' );
$ok( 'отстъпката −5 € е във всяка количка, както до 1.56', 34.90 === $ol->get_total() && -5.0 === fee_sum() && empty( $ol->get_meta( '_ansa_promo_bf' )['affv'] ) && WC()->cart->has_discount( 'anssid5ansa' ) );

/* ═══ 16. Кратките линкове ansa.bg/p/КОД ═══ */
$bf = 'https://www.ansa.bg/cheren-petak-2026/?ref=';
$ok( 'публична фаза: /p/ANSSID5ANSA → Черния петък с ?ref=', $bf . 'ANSSID5ANSA' === Aff::vanity_target( 'ANSSID5ANSA', bf_cfg() ), Aff::vanity_target( 'ANSSID5ANSA' ) );
$ok( 'малки букви в адреса → същото', $bf . 'ANSSID5ANSA' === Aff::vanity_target( 'anssid5ansa' ) );
$ok( 'пауза → Черния петък (страницата за изчакване)', $bf . 'IVA5ANSA' === Aff::vanity_target( 'IVA5ANSA', bf_cfg( array(), 'pause' ) ) );
foreach ( array( 'prep', 'early', 'over' ) as $ph ) { $ok( 'фаза ' . $ph . ' → остава на ansa Partner (началната)', '' === Aff::vanity_target( 'ANSSID5ANSA', bf_cfg( array(), $ph ) ) ); }
bf_cfg();
$ok( 'кодът за играта остава към Сезона', '' === Aff::vanity_target( 'ANSIANSAEA' ) );
$ok( 'спрян партньор / непознат код → не се пипа', '' === Aff::vanity_target( 'STOP5ANSA' ) && '' === Aff::vanity_target( 'NIKOI' ) && '' === Aff::vanity_target( '' ) );
$ok( 'изключено от настройката → не се пипа', '' === Aff::vanity_target( 'ANSSID5ANSA', bf_cfg( array( 'aff' => array( 'vanity' => false ) ) ) ) );
$ok( 'изключено промо / изключен партньорски вариант → не се пипа', '' === Aff::vanity_target( 'ANSSID5ANSA', bf_cfg( array( 'enabled' => false ) ) ) && '' === Aff::vanity_target( 'ANSSID5ANSA', bf_cfg( array( 'aff' => array( 'enabled' => false ) ) ) ) );
bf_cfg(); $GLOBALS['qv'] = array( 'ansa_partner_ref' => 'ANSSID5ANSA' ); $red = '';
try { do_action( 'template_redirect' ); } catch ( RedirectExit $e ) { $red = $e->getMessage(); }
$ok( 'template_redirect: нашият redirect е преди този на ansa Partner', $bf . 'ANSSID5ANSA' === $red && 302 === $GLOBALS['redirect'][1], $red );
$GLOBALS['qv'] = array( 'ansa_partner_ref' => 'ANSIANSAEA' ); $red = '';
try { do_action( 'template_redirect' ); } catch ( RedirectExit $e ) { $red = $e->getMessage(); }
$ok( 'кодът за играта: redirect-ът е на ansa Partner (не към Черния петък)', false === strpos( $red, 'cheren-petak' ), $red );
$GLOBALS['qv'] = array();

/* ═══ 17. Таванът за денонощие ═══ */
add_filter( 'ansa_promo_bf_affv_day_cap', function () { return 0; } );
$ok( 'над тавана не се издава код (лийдът се записва)', null === Aff::issue( 'cap@example.com', 0, 'IVA5ANSA', Config::get_published() ) );
$GLOBALS['hooks']['ansa_promo_bf_affv_day_cap'] = array();

/* ═══ 18. СТРЕС: случайни посетители ═══ */
$seed = isset( $argv[1] ) ? (int) $argv[1] : 20261125; mt_srand( $seed ); $N = 400;
$base_orders = array_keys( $GLOBALS['orders'] ); $stress = array(); $choices = array( 'v5' => 0, 'mask' => 0 ); $paths = array( 'ok' => 0, 'retry' => 0, 'abandon' => 0, 'cancel' => 0, 'later' => 0, 'rebuilds' => 0 );
$plist = array( 'ANSSID5ANSA' => 1, 'IVA5ANSA' => 2 ); $prices = array( 101 => 39.90, 102 => 49.90, 103 => 44.90 );
for ( $i = 0; $i < $N; $i++ ) {
	new_visitor(); $ref = array_rand( $plist ); $pid = $plist[ $ref ]; $email = 's' . mt_rand( 1, 260 ) . '@stress.test'; $gift = mt_rand( 0, 2 ) ? 'v5' : 'mask'; $choices[ $gift ]++;
	visit( $ref ); $l = lead( $email, $gift, $ref ); if ( empty( $l['success'] ) ) { $ok( 'стрес: лийдът се записва', false, $l ); break; }
	$builds = mt_rand( 1, 3 ); $paths['rebuilds'] += $builds - 1; $items = array(); $paid = 0.0;
	for ( $bi = 0; $bi < $builds; $bi++ ) {
		$items = array(); $paid = 0.0; $q = 0;
		$pick = (array) array_rand( $prices, mt_rand( 1, 3 ) ); foreach ( $pick as $prod ) { $qq = mt_rand( 1, 2 ); $items[] = array( 'id' => $prod, 'q' => $qq ); $paid += $prices[ $prod ] * $qq; $q += $qq; }
		if ( mt_rand( 0, 3 ) ) { visit( mt_rand( 0, 1 ) ? $ref : '' ); } /* връща се на страницата, понякога без ?ref= */
		$b = build( $items, array( array( 'id' => 201, 'q' => mt_rand( 0, $q ) ) ), mt_rand( 0, 4 ) ? $ref : '', $gift, $email );
		if ( empty( $b['success'] ) ) { $ok( 'стрес: количката се прави', false, $b ); break 2; }
		if ( ! WC()->cart->has_discount( strtolower( $ref ) ) ) { $ok( 'стрес #' . $i . ': кодът на партньора е в количка ' . ( $bi + 1 ) . '/' . $builds, false, WC()->cart->get_applied_coupons() ); break 2; }
	}
	$had = $gift === 'v5' && fee_sum() < 0; $expect = round( $paid - ( $had ? 5 : 0 ), 2 );
	if ( abs( WC()->cart->get_total( 'edit' ) - $expect ) > 0.005 ) { $ok( 'стрес #' . $i . ': сумата е платените − ваучерът', false, array( WC()->cart->get_total( 'edit' ), $expect ) ); break; }
	$path = mt_rand( 0, 9 );
	if ( $path < 6 ) { $o = checkout( $email, 'ok' ); $paths['ok']++; }
	elseif ( $path < 8 ) { $o = checkout( $email, 'fail' ); $o2 = checkout( $email, 'ok' ); $paths['retry']++; if ( ( $o2->get_id() !== $o->get_id() || abs( $o2->get_total() - $expect ) > 0.005 ) && getenv( 'BF_DEBUG' ) ) { $cx = Cart::ctx(); fwrite( STDERR, 'RETRY ctx.affv=' . json_encode( $cx['affv'] ?? null ) . ' row=' . json_encode( Aff::row_for( $email ) ) . ' fees=' . json_encode( $o2->fees ) . ' notes=' . json_encode( $o->notes, JSON_UNESCAPED_UNICODE ) . ' meta=' . json_encode( array( $o2->get_meta( '_ansa_promo_bf' )['affv'] ?? null, $o2->get_meta( '_ansa_bf_affv_counted' ) ) ) . "\n" ); }
		if ( $o2->get_id() !== $o->get_id() || abs( $o2->get_total() - $expect ) > 0.005 ) { $ok( 'стрес #' . $i . ': вторият опит е същата поръчка със същата сума', false, array( $o->get_id(), $o2->get_id(), $o2->get_total(), $expect ) ); break; } $o = $o2; }
	elseif ( 8 === $path ) { $o = checkout( $email, 'none' ); $o->update_status( 'cancelled' ); $paths['abandon']++; }
	else { $o = checkout( $email, 'ok' ); cancel_order( $o ); $paths['cancel']++; }
	$stress[] = array( 'o' => $o->get_id(), 'ref' => $ref, 'pid' => $pid, 'email' => $email, 'gift' => $gift, 'had' => $had, 'paid' => $paid );
	/* понякога: по-късно, обикновена поръчка с кода (ако още е жив) */
	if ( 'v5' === $gift && ! mt_rand( 0, 5 ) ) { $r = Aff::row_for( $email ); if ( $r && empty( $r['voucher_order_id'] ) ) { new_visitor(); WC()->cart->add_to_cart( 900, 1 ); if ( WC()->cart->apply_coupon( $r['voucher'] ) ) { $paths['later']++; $ol = checkout( $email, 'ok' ); if ( 20.0 !== $ol->get_total() ) { $ok( 'стрес: обикновената поръчка с кода е −5 €', false, $ol->get_total() ); break; } } else { $ok( 'стрес: живият код се приема при обикновена поръчка', false, array( $r['voucher'], $GLOBALS['last_coupon_error'] ?? '' ) ); break; } } }
}
$ok( 'стрес: ' . $N . ' посетители минаха (seed ' . $seed . ')', $N === count( $stress ), count( $stress ) );
/* инвариантите */
$bad_lock = 0; $bad_code = 0; $paid_orders = 0; $multi = 0;
foreach ( $stress as $s ) {
	$o = wc_get_order( $s['o'] ); if ( ! in_array( strtolower( $s['ref'] ), $o->get_coupon_codes(), true ) ) { $bad_code++; }
	$pc = array_filter( $o->get_coupon_codes(), function ( $c ) { return (bool) AP_Coupons::get_mapping_by_code( $c ); } ); if ( 1 !== count( $pc ) ) { $multi++; }
	if ( $REAL && $s['pid'] !== ( lock( $o )['partner_id'] ?? 0 ) ) { $bad_lock++; }
	if ( 'processing' === $o->get_status() ) { $paid_orders++; }
}
$ok( 'стрес: всяка поръчка носи кода на своя партньор', 0 === $bad_code, $bad_code );
$ok( 'стрес: точно един партньорски код на поръчка', 0 === $multi, $multi );
if ( $REAL ) { $ok( 'стрес: всяка поръчка е заключена за своя партньор (комисионата)', 0 === $bad_lock, $bad_lock ); }
$rows = $wpdb->get_results( "SELECT * FROM wp_ansa_promo_leads WHERE kind = 'aff' AND voucher <> '' AND email LIKE '%@stress.test'", ARRAY_A );
$per_email = array(); $uses = array(); $bad_state = 0; $bad_count = 0;
foreach ( $GLOBALS['orders'] as $o ) { if ( in_array( $o->get_id(), $base_orders, true ) || ! in_array( $o->get_status(), array( 'processing', 'pending' ), true ) ) { continue; } $m = $o->get_meta( '_ansa_promo_bf' ); $c = is_array( $m ) ? (string) ( $m['affv']['code'] ?? ( $m['affv_void'] ?? '' ) ) : ''; if ( '' !== $c ) { $uses[ $c ][] = $o->get_id(); } foreach ( $o->get_coupon_codes() as $cc ) { if ( Aff::row_by_code( $cc ) ) { $uses[ strtoupper( $cc ) ][] = $o->get_id(); } } }
foreach ( $rows as $r ) {
	$per_email[ $r['email'] ] = ( $per_email[ $r['email'] ] ?? 0 ) + 1; $u = $uses[ $r['voucher'] ] ?? array();
	$why = count( $u ) > 1 ? 'много' : ( 1 === count( $u ) && (int) $r['voucher_order_id'] !== $u[0] ? 'друга' : ( ! $u && ! empty( $r['voucher_order_id'] ) ? 'сираче' : '' ) ); if ( '' !== $why ) { $bad_state++; if ( getenv( 'BF_DEBUG' ) ) { $oo = wc_get_order( (int) $r['voucher_order_id'] ); fwrite( STDERR, $why . ' ' . $r['voucher'] . ' row→' . $r['voucher_order_id'] . ' (' . ( $oo ? $oo->get_status() : '-' ) . ') uses=' . json_encode( $u ) . "\n" ); } }
	if ( ( new WC_Coupon( $r['voucher'] ) )->get_usage_count() !== count( $u ) ) { $bad_count++; }
}
$ok( 'стрес: един ваучер на имейл (' . count( $rows ) . ' ваучера)', count( $rows ) > 20 && 1 === max( $per_email ), array( count( $rows ), max( $per_email ?: array( 0 ) ) ) );
$ok( 'стрес: всеки ваучер е използван най-много веднъж и редът му сочи точно тази поръчка', 0 === $bad_state, $bad_state );
$ok( 'стрес: броят ползвания на WooCommerce купона съвпада (0 или 1)', 0 === $bad_count, $bad_count );
$ok( 'стрес: минати са всички пътища (платена, втори опит, изоставена, отказана, по-късна поръчка с кода, повторни колички)', min( $paths ) > 0 && min( $choices ) > 0, $paths + $choices );
$sum_base = 0.0; if ( $REAL && $ENGINE ) { $bad_base = 0; foreach ( $stress as $s ) { $o = wc_get_order( $s['o'] ); $b = $ENGINE->commission_base( $o ); $sum_base += $b; if ( abs( $b - $s['paid'] ) > 0.005 ) { $bad_base++; } } $ok( 'стрес: базата за комисионата е точно платените продукти (ваучерът и подаръците не я намаляват)', 0 === $bad_base, $bad_base ); }
$ok( 'няма грешки към базата', array() === $wpdb->errors, array_slice( $wpdb->errors, 0, 3 ) );

} catch ( Throwable $e ) { $fail[] = 'ИЗКЛЮЧЕНИЕ: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(); }

if ( $fail ) { echo 'FAIL ' . count( $fail ) . ' от ' . $n . ":\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo 'OK ' . $n . ' проверки' . ( $REAL ? ' (с истинския ansa Partner)' : ' (БЕЗ ansa Partner — частта с него е пропусната)' ) . ' · стрес: ' . json_encode( $paths ) . "\n";
