<?php
/**
 * Офлайн тест на варианта B „потокът без кутии“ (1.34.0): Cart::perform_add с variant=flow — фалшив WC, без WordPress:
 * php tests/cart-flow-test.php → „OK n проверки“ или списък с грешки (exit 1).
 *
 * Правилата (човекът, 27.10): 2 и 4 опаковки са възможни; книгата е при 5, не при 3; 1 опаковка = 1 билет; сетът само при 5+.
 * Сценарии: (1) 2 опаковки → малка кутия −15 %, 2 билета, без книга; (2) 4 → средна −30 %, 4 билета, без книга, безплатна доставка;
 * (3) 5 → голяма −40 %, книга, 5 билета, сетът резервиран; (4) 7 → голяма, 7 билета; (5) 0 → грешка; (6) над max_packs → грешка;
 * (7) без variant (вариант A) малка кутия с 2 опаковки е пак грешка „count“ — A не се променя; (8) order meta носи variant=flow.
 */
require __DIR__ . '/wcstub.php';
use AnsaPromo\Cart; use AnsaPromo\Config;

$flow = function ( $items, $extra = array() ) { WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null ); return Cart::perform_add( array_merge( array( 'variant' => 'flow', 'box' => '', 'items' => $items, 'secret' => array(), 'pay' => 'card', 'email' => '', 'cosm' => true, 'utm' => 'sakura', 'lead' => 0, 'sid' => 's9' ), $extra ) ); };
$books = function () { $n = 0; foreach ( WC()->cart->get_cart() as $it ) { if ( ! empty( $it[ Cart::META ] ) && 'book' === $it[ Cart::META ]['role'] ) { $n++; } } return $n; };

/* (1) 2 опаковки → малка кутия, −15 %, 2 билета, без книга */
$r = $flow( array( 100, 200 ) );
ok( ! empty( $r['ok'] ) && 's' === $r['box'], '(1) 2 опаковки → малка: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp(); ok( $g && ! empty( $g['flow'] ) && Cart::group_valid( $g ) && 2 === (int) $g['sum'] && 20.0 === (float) $g['pct'] && ! $g['book'], '(1) групата е flow, 2 опаковки, −20 % (стълбицата 1.39.0), без книга' );
WC()->cart->calculate_totals();
ok( abs( WC()->cart->get_total() - ( round( 43.90 * 0.8, 2 ) + round( 49.90 * 0.8, 2 ) ) ) < 0.01, '(1) общо = двете на −20 %: ' . WC()->cart->get_total() );
$m = Cart::build_meta( true );
ok( $m && 'flow' === $m['variant'] && 2 === (int) $m['count'] && 2 === (int) $m['tickets']['allowance'] && null === $m['book'] && null === $m['cosm'] && ! $m['ship_free'], '(1) order meta: variant flow, 2 билета, без книга/сет/доставка: ' . json_encode( array( $m['variant'], $m['count'], $m['tickets']['allowance'], $m['book'], $m['cosm'], $m['ship_free'] ) ) );
$ctx = Cart::ctx(); ok( 'flow' === $ctx['variant'] && 'sakura' === $ctx['utm'] && 's9' === $ctx['sid'], '(1) ctx: variant/utm/sid' );

/* (2) 4 опаковки → средна, −30 %, 4 билета, без книга, безплатна доставка (rw на средната) */
$r = $flow( array( 100, 100, 200, 300 ) );
ok( ! empty( $r['ok'] ) && 'm' === $r['box'], '(2) 4 → средна: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp(); ok( 4 === (int) $g['sum'] && 32.0 === (float) $g['pct'] && ! $g['book'] && $g['ship'] && 0 === $books(), '(2) −32 % (стълбицата), без книга, с безплатна доставка' );
$m = Cart::build_meta( true ); ok( 4 === (int) $m['tickets']['allowance'] && null === $m['book'] && null === $m['cosm'] && $m['ship_free'], '(2) meta: 4 билета, без книга, доставка безплатна' );
WC()->cart->calculate_totals(); ok( abs( WC()->cart->get_total() - ( round( 43.90 * 0.68, 2 ) * 2 + round( 49.90 * 0.68, 2 ) + round( 39.90 * 0.68, 2 ) ) ) < 0.01, '(2) общо на −32 %: ' . WC()->cart->get_total() );
/* 1.39.0: стълбицата по брой — само новият дизайн */
$cfgp = Config::get_published();
ok( array( 2 => 20.0, 4 => 32.0 ) === $cfgp['flow']['ladder'], '(L) стълбицата по подразбиране 2=20, 4=32: ' . json_encode( $cfgp['flow']['ladder'] ) );
ok( 15.0 === Config::flow_pct( $cfgp, 1 ) && 20.0 === Config::flow_pct( $cfgp, 2 ) && 30.0 === Config::flow_pct( $cfgp, 3 ) && 32.0 === Config::flow_pct( $cfgp, 4 ) && 40.0 === Config::flow_pct( $cfgp, 5 ) && 40.0 === Config::flow_pct( $cfgp, 7 ), '(L) flow_pct 1→15, 2→20, 3→30, 4→32, 5→40, 7→40' );
ok( 15.0 === (float) Config::box_for_count( $cfgp, 2 )['pct'] && 30.0 === (float) Config::box_for_count( $cfgp, 4 )['pct'], '(L) старият дизайн (box_for_count) е непокътнат: 2 → 15, 4 → 30' );
ok( array( 2 => 20.0, 4 => 32.5 ) === Config::ladder( '2=20, 4=32.5' ) && array() === Config::ladder( '' ) && array( 3 => 31.0 ) === Config::ladder( array( 3 => '31', 99 => 5, 2 => 95 ) ), '(L) Config::ladder: текст и масив, граници' );
ok( '2=20, 4=32' === Config::ladder_text( $cfgp ), '(L) ladder_text' );
$r = $flow( array( 100, 200, 300 ) ); $g = grp(); ok( ! empty( $r['ok'] ) && 'm' === $r['box'] && 30.0 === (float) $g['pct'], '(L) 3 опаковки → средна −30 % (от кутията, не от стълбицата)' );
$r = $flow( array( 100 ) ); $g = grp(); ok( ! empty( $r['ok'] ) && 's' === $r['box'] && 15.0 === (float) $g['pct'], '(L) 1 опаковка → малка −15 %' );

/* (3) 5 опаковки → голяма, −40 %, книга, 5 билета, сетът резервиран */
$r = $flow( array( 100, 100, 100, 200, 300 ) );
ok( ! empty( $r['ok'] ) && 'l' === $r['box'], '(3) 5 → голяма: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$g = grp(); ok( 5 === (int) $g['sum'] && 40.0 === (float) $g['pct'] && $g['book'] && 1 === $books(), '(3) −40 % + книга' );
$m = Cart::build_meta( true ); ok( 5 === (int) $m['tickets']['allowance'] && $m['book'] && $m['cosm'] && 'reserved' === $m['cosm']['status'], '(3) meta: 5 билета, книга, сетът резервиран' );
WC()->cart->calculate_totals(); ok( WC()->cart->get_cart_item( $g['book'] )['data']->get_price() == 0, '(3) книгата е €0' );

/* (4) 7 опаковки → голяма, 7 билета (1 опаковка = 1 билет) */
$r = $flow( array( 100, 100, 100, 200, 200, 300, 300 ) );
$m = Cart::build_meta( true ); ok( ! empty( $r['ok'] ) && 'l' === $r['box'] && 7 === (int) $m['count'] && 7 === (int) $m['tickets']['allowance'] && $m['book'], '(4) 7 → голяма със 7 билета: ' . json_encode( array( $r['box'] ?? null, $m['count'], $m['tickets']['allowance'] ) ) );

/* (5) 0 опаковки → грешка box */
$r = $flow( array() ); ok( empty( $r['ok'] ) && 'box' === $r['code'], '(5) 0 опаковки → box: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );

/* (6) над max_packs (20) → грешка max */
$r = $flow( array_fill( 0, 21, 100 ) ); ok( empty( $r['ok'] ) && 'max' === $r['code'], '(6) 21 → max: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );

/* (7) вариант A не се променя: малка кутия с 2 опаковки е грешка count */
WC()->cart->empty_cart(); WC()->session->set( Cart::CTX, null );
$r = Cart::perform_add( array( 'box' => 's', 'items' => array( 100, 200 ), 'secret' => array(), 'pay' => 'card' ) );
ok( empty( $r['ok'] ) && 'count' === $r['code'], '(7) A: малка с 2 → count: ' . json_encode( $r, JSON_UNESCAPED_UNICODE ) );
$r = Cart::perform_add( array( 'box' => 'm', 'items' => array( 100, 100, 200 ), 'secret' => array(), 'pay' => 'card' ) );
$g = grp(); ok( ! empty( $r['ok'] ) && empty( $g['flow'] ) && $g['book'], '(7) A: средна с 3 → книга както досега' );
$m = Cart::build_meta( true ); ok( '' === $m['variant'] && 3 === (int) $m['tickets']['allowance'], '(7) A: meta variant празен, 3 билета' );

/* (8) Config::flow_box: правилата без количка */
$cfg = Config::get_published();
$fb = Config::flow_box( $cfg, null, 2 ); ok( $fb && 's' === $fb['id'] && $fb['grow'] && 1 === (int) $fb['tix_extra'] && ! in_array( 'book', $fb['rw'], true ) && 2 === Config::tickets_for( $fb, 2 ), '(8) flow_box(2) = малка, расте, +1 билет, без книга' );
$fb = Config::flow_box( $cfg, null, 4 ); ok( $fb && 'm' === $fb['id'] && ! in_array( 'book', $fb['rw'], true ) && in_array( 'ship', $fb['rw'], true ) && 4 === Config::tickets_for( $fb, 4 ), '(8) flow_box(4) = средна без книга, с доставка, 4 билета' );
$fb = Config::flow_box( $cfg, null, 9 ); ok( $fb && 'l' === $fb['id'] && in_array( 'book', $fb['rw'], true ) && null !== $fb['cosm_pay'] && 9 === Config::tickets_for( $fb, 9 ), '(8) flow_box(9) = голяма с книга и сет, 9 билета' );
ok( null === Config::flow_box( $cfg, null, 0 ), '(8) flow_box(0) = null' );
$ab = Config::normalize( array( 'ab' => array( 'page_id' => '12', 'share' => 250 ) ) )['ab']; ok( 12 === $ab['page_id'] && 100 === $ab['share'], '(8) normalize: ab.page_id/share (таван 100)' );
ok( 0 === Config::normalize( array() )['ab']['share'] && 0 === Config::normalize( array() )['ab']['page_id'], '(8) normalize: ab по подразбиране изключен' );

if ( $errors ) { echo "ГРЕШКИ (" . count( $errors ) . "/$n):\n - " . implode( "\n - ", $errors ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
