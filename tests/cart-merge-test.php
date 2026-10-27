<?php
/**
 * Офлайн тест на Cart::perform_add с 'merge' (1.24.0, за 4-тата опция в Shrine) — фалшив WC (количка, сесия, продукти), без WordPress:
 * php tests/cart-merge-test.php → „OK n проверки“ или списък с грешки (exit 1).
 *
 * Сценарии: (1) merge в празна количка → група с 5× A + книга, 5 билета; (2) чужд платен ред + merge с 5× B → същата група,
 * A5 + B5, една книга, 10 опаковки, билетите по tickets_for (tix_extra), чуждият ред остава и е на регулярна цена;
 * (3) двойно докосване (същият състав до 10 с) → идемпотентно; (4) add без merge (промо страницата) изчиства количката;
 * (5) малка кутия + merge 5 → голяма: старите редове минават на „l“/−40%, книгата се добавя; (6) таван max_packs.
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Cart; use AnsaPromo\Config;

require __DIR__ . '/wcstub.php'; /* 1.34.0: стъбовете са общи с cart-flow-test.php */

$merge = function ( $pid, $packs = 5 ) { return Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, $packs, $pid ), 'secret' => array(), 'pay' => 'card', 'email' => '', 'cosm' => true, 'utm' => 'x', 'lead' => 0, 'sid' => 's1', 'merge' => true ) ); };

/* (1) merge в празна количка */
$r = $merge( 100 );
ok( ! empty( $r['ok'] ), '(1) perform_add merge: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
ok( ! empty( $r['merged'] ) && 5 === (int) $r['count'] && 5 === (int) $r['tickets'] && 'l' === $r['box'], '(1) merged/count/tickets/box: ' . json_encode( $r ) );
ok( isset( $r['fragments'] ) && isset( $r['cart_hash'] ), '(1) отговорът носи фрагменти' );
$g = grp(); ok( $g && Cart::group_valid( $g ) && base_counts( $g ) === array( 100 => 5 ) && $g['book'] && 'l' === $g['box'], '(1) групата: 5× Greens + книга: ' . json_encode( $g ? base_counts( $g ) : null ) );
$g1 = $g['group'];
WC()->cart->calculate_totals();
foreach ( $g['base'] as $it ) { ok( abs( WC()->cart->get_cart_item( $it['key'] )['data']->get_price() - 43.90 * 0.6 ) < 0.01, '(1) цената на реда е −40%' ); }
ok( WC()->cart->get_cart_item( $g['book'] )['data']->get_price() == 0, '(1) книгата е €0' );
ok( abs( WC()->cart->get_total() - 131.70 ) < 0.01, '(1) общо €131,70, е ' . WC()->cart->get_total() );
$ctx = Cart::ctx(); ok( $ctx['group'] === $g1 && 'x' === $ctx['utm'] && 's1' === $ctx['sid'], '(1) ctx: group/utm/sid' );

/* (2) чужд платен ред + merge с 5× Sakura → същата група, 10 опаковки, една книга, чуждият остава на регулярна цена */
$fk = WC()->cart->add_to_cart( 700, 2 );
sleep( 0 );
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 200 ), 'secret' => array(), 'pay' => 'card', 'email' => '', 'cosm' => true, 'utm' => '', 'lead' => 0, 'sid' => '', 'merge' => true ) );
ok( ! empty( $r['ok'] ) && ! empty( $r['merged'] ), '(2) merge на втора кутия: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp();
ok( $g['group'] === $g1, '(2) същата група' );
ok( base_counts( $g ) === array( 100 => 5, 200 => 5 ), '(2) A5 + B5: ' . json_encode( base_counts( $g ) ) );
ok( 10 === (int) $g['sum'] && 10 === (int) $r['count'], '(2) 10 опаковки' );
$books = 0; foreach ( WC()->cart->get_cart() as $it ) { if ( ! empty( $it[ Cart::META ] ) && 'book' === $it[ Cart::META ]['role'] ) { $books++; } } ok( 1 === $books, '(2) една книга, са ' . $books );
$bl = Config::box( Config::get_published(), 'l' ); ok( (int) $r['tickets'] === Config::tickets_for( $bl, 10 ), '(2) билетите са по tickets_for (' . Config::tickets_for( $bl, 10 ) . ' при tix_extra ' . $bl['tix_extra'] . ')' );
ok( in_array( $fk, foreign_keys(), true ), '(2) чуждият платен ред остава' );
WC()->cart->calculate_totals();
ok( WC()->cart->get_cart_item( $fk )['data']->get_price() == 25.00, '(2) чуждият ред е на регулярна цена' );
ok( abs( WC()->cart->get_total() - ( 43.90 * 5 * 0.6 + 49.90 * 5 * 0.6 + 50 ) ) < 0.01, '(2) общо = двете кутии −40% + чуждия: ' . WC()->cart->get_total() );
$ctx = Cart::ctx(); ok( 'x' === $ctx['utm'] && 's1' === $ctx['sid'], '(2) ctx пази utm/sid от първото добавяне' );
$meta = Cart::build_meta( true ); ok( $meta && 10 === (int) $meta['count'] && 'l' === $meta['box'] && (int) $meta['tickets']['allowance'] === Config::tickets_for( $bl, 10 ) && $meta['book'] && $meta['cosm'] && 'reserved' === $meta['cosm']['status'], '(2) order meta: една кутия, сет веднъж, книга, билети: ' . json_encode( $meta ) );

/* (3) двойно докосване: същият състав до 10 с → идемпотентно */
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 200 ), 'secret' => array(), 'pay' => 'card', 'merge' => true ) );
ok( ! empty( $r['ok'] ) && ! empty( $r['idempotent'] ) && 10 === (int) $r['count'], '(3) идемпотентно: ' . json_encode( $r ) );
ok( base_counts( grp() ) === array( 100 => 5, 200 => 5 ), '(3) нищо не е добавено повторно' );

/* (3b) трета кутия (друг продукт) → 15 */
$r = $merge( 300 );
ok( ! empty( $r['ok'] ) && 15 === (int) $r['count'] && base_counts( grp() ) === array( 100 => 5, 200 => 5, 300 => 5 ), '(3b) трета кутия → 15 опаковки: ' . json_encode( $r ) );

/* (4) add без merge (промо страницата) → количката се замества, чуждият ред пада */
$r = Cart::perform_add( array( 'box' => 'm', 'items' => array( 100, 100, 200 ), 'secret' => array(), 'pay' => 'card' ) );
ok( ! empty( $r['ok'] ) && empty( $r['merged'] ), '(4) обикновен add: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp(); ok( $g['group'] !== $g1 && 'm' === $g['box'] && base_counts( $g ) === array( 100 => 2, 200 => 1 ) && ! foreign_keys(), '(4) нова група „m“, без чужди редове' );

/* (5) малка кутия (1) + merge 5 → голяма: старите редове минават на l / −40%, книгата се добавя */
WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null );
$r = Cart::perform_add( array( 'box' => 's', 'items' => array( 300 ), 'secret' => array(), 'pay' => 'card' ) );
ok( ! empty( $r['ok'] ) && 's' === grp()['box'] && ! grp()['book'], '(5) малка кутия без книга' );
$r = $merge( 100 );
ok( ! empty( $r['ok'] ) && 'l' === $r['box'] && 6 === (int) $r['count'], '(5) s + 5 → l с 6: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp(); ok( 'l' === $g['box'] && 40.0 === (float) $g['pct'] && $g['book'] && base_counts( $g ) === array( 100 => 5, 300 => 1 ), '(5) групата е l/−40% с книга: ' . json_encode( array( $g['box'], $g['pct'], (bool) $g['book'], base_counts( $g ) ) ) );
foreach ( $g['base'] as $it ) { ok( 'l' === $it[ Cart::META ]['box'] && 40.0 === (float) $it[ Cart::META ]['ppct'], '(5) редът ' . $it['product_id'] . ' е на l/40' ); }
WC()->cart->calculate_totals();
ok( abs( WC()->cart->get_total() - ( 43.90 * 5 * 0.6 + 39.90 * 0.6 ) ) < 0.01, '(5) общо: ' . WC()->cart->get_total() );

/* (6) таван max_packs (20): 6 + 5 + 5 = 16 ок, + 5 = 21 → грешка max, нищо не се добавя */
ok( ! empty( $merge( 200 )['ok'] ) && ! empty( $merge( 300 )['ok'] ) && 16 === (int) grp()['sum'], '(6) 16 опаковки' );
$r = $merge( 200 );
ok( empty( $r['ok'] ) && 'max' === $r['code'] && 16 === (int) grp()['sum'], '(6) 21 > max_packs → max, количката непокътната: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );

/* (7) 1.24.1: ppct от Shrine — само нагоре: 45 печели над 40, 30 не; при вдигане на кутията редът пази вдигнатия процент */
WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null );
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 100 ), 'pay' => 'card', 'merge' => true, 'ppct' => 45 ) );
$g = grp(); $pp = array(); foreach ( $g['base'] as $it ) { $pp[ (int) $it['product_id'] ] = (float) $it[ Cart::META ]['ppct']; }
ok( ! empty( $r['ok'] ) && 45.0 === ( $pp[100] ?? 0 ), '(7) ppct 45 е записан в реда: ' . json_encode( $pp ) );
WC()->cart->calculate_totals();
ok( abs( WC()->cart->get_total() - round( 43.90 * 0.55, 2 ) * 5 ) < 0.01, '(7) цената е −45% (закръглена на опаковка): ' . WC()->cart->get_total() );
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 200 ), 'pay' => 'card', 'merge' => true, 'ppct' => 30 ) );
$g = grp(); $pp = array(); foreach ( $g['base'] as $it ) { $pp[ (int) $it['product_id'] ] = (float) $it[ Cart::META ]['ppct']; }
ok( ! empty( $r['ok'] ) && 40.0 === ( $pp[200] ?? 0 ) && 45.0 === ( $pp[100] ?? 0 ), '(7) ppct 30 не сваля под конфига (40), старият ред пази 45: ' . json_encode( $pp ) );
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 100 ), 'pay' => 'card', 'merge' => true, 'ppct' => 50 ) );
$g = grp(); $pp = array(); foreach ( $g['base'] as $it ) { $pp[ (int) $it['product_id'] ] = (float) $it[ Cart::META ]['ppct']; }
ok( ! empty( $r['ok'] ) && 50.0 === ( $pp[100] ?? 0 ) && 15 === (int) $g['sum'], '(7) същият продукт пак → количеството расте и процентът се вдига на 50: ' . json_encode( $pp ) );
$meta = Cart::build_meta( true ); ok( $meta && 15 === (int) $meta['count'] && (int) $meta['tickets']['allowance'] === Config::tickets_for( $bl, 15 ), '(7) order meta при 15 опаковки' );

/* (8) 1.28.1: по-малко опаковки от кутията → кутията се смалява, не блокира (голяма 5 → 3 = средна −30%; → 1 = малка −15%, книгата пада) */
WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null );
$r = Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 100 ), 'pay' => 'card', 'merge' => true, 'ppct' => 45 ) );
$g = grp(); $bk = key( $g['base'] );
WC()->cart->set_quantity( $bk, 3 ); WC()->cart->calculate_totals();
$g = grp(); ok( Cart::group_valid( $g ) && 'm' === $g['box'] && 3 === (int) $g['sum'] && 3 === (int) $g['packs'], '(8) 3 опаковки → средна кутия, валидна: ' . json_encode( array( $g['box'], $g['sum'], $g['packs'] ) ) );
ok( abs( WC()->cart->get_cart_item( $bk )['data']->get_price() - round( 43.90 * 0.7, 2 ) ) < 0.01, '(8) цената е −30% (ppct 45 на голямата не се пренася): ' . WC()->cart->get_cart_item( $bk )['data']->get_price() );
ok( $g['book'] && WC()->cart->get_cart_item( $g['book'] )['data']->get_price() == 0, '(8) средната има книга → остава, €0' );
$ctx = Cart::ctx(); ok( ! empty( $ctx['shrunk'] ) && 'm' === $ctx['shrunk']['box'] && 3 === (int) $ctx['shrunk']['qty'] && 'm' === $ctx['box'], '(8) ctx.shrunk за бележката: ' . json_encode( $ctx['shrunk'] ?? null, JSON_UNESCAPED_UNICODE ) );
WC()->cart->set_quantity( $bk, 1 ); WC()->cart->calculate_totals();
$g = grp(); ok( Cart::group_valid( $g ) && 's' === $g['box'] && ! $g['book'], '(8) 1 опаковка → малка кутия, книгата пада: ' . json_encode( array( $g['box'], $g['book'] ) ) );
ok( abs( WC()->cart->get_cart_item( $bk )['data']->get_price() - round( 43.90 * 0.85, 2 ) ) < 0.01, '(8) цената е −15%' );
WC()->cart->set_quantity( $bk, 0 ); WC()->cart->remove_cart_item( $bk ); WC()->cart->calculate_totals();
ok( null === grp() || ! Cart::group_valid( grp() ), '(8) без базови редове → няма валидна група (co.broken както досега)' );
/* (9) 1.28.1: етикетът на реда — „- 1 брой“ пада, броят влиза в тага */
WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null );
Cart::perform_add( array( 'box' => 'l', 'items' => array_fill( 0, 5, 100 ), 'pay' => 'card', 'merge' => true ) );
$g = grp(); $bk = key( $g['base'] ); $it = WC()->cart->get_cart_item( $bk );
$lbl = Cart::cart_label( 'ansa™ – Оригиналният ДихидроБерберин - AMPK активатор - №1 в БГ - 1 брой', $it, $bk );
ok( false === strpos( $lbl, '1 брой' ) && 0 === strpos( $lbl, 'ansa™ – Оригиналният ДихидроБерберин - AMPK активатор - №1 в БГ<br>' ), '(9) „- 1 брой“ пада от името: ' . $lbl );
ok( false !== strpos( $lbl, '5 опаковки' ) && false !== strpos( $lbl, '−40%' ), '(9) тагът носи 5 опаковки · −40%: ' . $lbl );
$lbl2 = Cart::cart_label( '<a href="#">ansa™ Greens - 1 кутия</a>', $it, $bk );
ok( 0 === strpos( $lbl2, '<a href="#">ansa™ Greens</a><br>' ), '(9) и в линк: ' . $lbl2 );

/* (10) 1.33.0: /kozmetichen-set-promo „приемам офертата“ → Sakura_Page::direct_add — Голямата кутия пълна с 5× Sakura, сетът, книгата, −40%, чекаут адрес; непознат продукт/кутия → null */
WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null );
$r = \AnsaPromo\Sakura_Page::direct_add( Config::get_published(), 'sakura', 'l', 'sx1', 'sakura' );
ok( ! empty( $r['ok'] ) && empty( $r['merged'] ) && 'l' === $r['box'] && 'http://x/checkout/' === $r['checkoutUrl'], '(10) direct_add: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp(); ok( Cart::group_valid( $g ) && 'l' === $g['box'] && base_counts( $g ) === array( 200 => 5 ) && $g['book'] && ! foreign_keys(), '(10) 5× Sakura в Голямата + книга: ' . json_encode( $g ? base_counts( $g ) : null ) );
WC()->cart->calculate_totals(); ok( abs( WC()->cart->get_total() - 49.90 * 5 * 0.6 ) < 0.01, '(10) общо = 5 × 49,90 −40%: ' . WC()->cart->get_total() );
$ctx = Cart::ctx(); ok( 'sakura' === $ctx['utm'] && 'sx1' === $ctx['sid'] && $ctx['cosm'] && 'card' === $ctx['pay'] && '' === $ctx['email'], '(10) ctx: utm sakura, sid, сетът, карта, без имейл: ' . json_encode( $ctx ) );
$meta = Cart::build_meta( true ); ok( $meta && 5 === (int) $meta['count'] && $meta['cosm'] && 'reserved' === $meta['cosm']['status'] && 5 === (int) $meta['tickets']['allowance'], '(10) order meta: 5 опаковки, сетът, 5 билета: ' . json_encode( $meta ) );
$r2 = \AnsaPromo\Sakura_Page::direct_add( Config::get_published(), 'sakura', 'l', 'sx1', 'sakura' ); ok( ! empty( $r2['ok'] ) && ! empty( $r2['idempotent'] ) && base_counts( grp() ) === array( 200 => 5 ), '(10) второ кацане → идемпотентно' );
ok( null === \AnsaPromo\Sakura_Page::direct_add( Config::get_published(), 'nosuch', 'l' ) && null === \AnsaPromo\Sakura_Page::direct_add( Config::get_published(), 'sakura', 'xl' ), '(10) непознат продукт/кутия → null' );
$r3 = \AnsaPromo\Sakura_Page::direct_add( Config::get_published(), '', '' ); ok( ! empty( $r3['ok'] ) && 'l' === $r3['box'] && base_counts( grp() ) === array( 200 => 5 ), '(10) без параметри = Sakura в Голямата' );
ok( 0 === strpos( Cart::cart_label( 'ansa™ Greens', $it, $bk ), 'ansa™ Greens<br>' ), '(9) име без суфикс остава' );

if ( $errors ) { echo "ГРЕШКИ:\n- " . implode( "\n- ", $errors ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
