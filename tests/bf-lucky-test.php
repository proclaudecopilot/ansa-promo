<?php
/**
 * Офлайн тест на 1.58.3 — маската от „Ти си късметлия“ стига до количката и до поръчката през СЪЩИЯ вход като браузъра (ajax ansa_bf_add
 * с полето lucky=1, което bf.js праща след „Вземи подаръка“). До 1.58.2 ajax_add не предаваше полето на perform_add и маската не влизаше
 * в нито една количка (клиенти: „добавя се колагенова маска и после я няма в поръчката“).
 * php tests/bf-lucky-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/harness/wcfake.php';
set_error_handler( function ( $no, $msg, $file, $line ) { if ( ! ( error_reporting() & $no ) ) { return false; } throw new ErrorException( $msg, 0, $no, $file, $line ); } );
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $t = is_scalar( $info ) ? (string) $info : (string) json_encode( $info, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ); $fail[] = $name . ( '' !== $t ? ' — ' . mb_substr( $t, 0, 500 ) : '' ); } };
require __DIR__ . '/harness/bf-boot.php';
use AnsaPromo\BF\Cart; use AnsaPromo\BF\Config;

$GLOBALS['products'][11161] = new WC_Product_Fake( 11161, 'CollaMask 1 маска', 5.00 ); /* подаръкът от раздаването (lucky.product_id по подразбиране) */
/** Както bf.js: POST към ansa_bf_add. $lucky = '1' / '0' / null (полето липсва). */
function add( $items, $gifts, $lucky = null, $mode = 'promo' ) { $post = array( 'mode' => $mode, 'items' => json_encode( $items ), 'gifts' => json_encode( $gifts ), 'pay' => 'card', 'variant' => 'direct' ); if ( null !== $lucky ) { $post['lucky'] = $lucky; } return ajax( array( Cart::class, 'ajax_add' ), $post ); }
function roles() { $o = array(); foreach ( WC()->cart->get_cart() as $it ) { $m = $it[ Cart::META ] ?? array(); $o[] = ( $m['role'] ?? '?' ) . ':' . $it['product_id'] . 'x' . $it['quantity']; } sort( $o ); return $o; }
function lucky_line() { foreach ( WC()->cart->get_cart() as $it ) { if ( 'lucky' === ( $it[ Cart::META ]['role'] ?? '' ) ) { return $it; } } return null; }
$G = array( array( 'id' => 101, 'q' => 2 ) ); $F = array( array( 'id' => 201, 'q' => 1 ), array( 'id' => 103, 'q' => 1 ) );

/* ── 1. приела е подаръка: маската е в количката на 0 € и в поръчката ── */
bf_cfg(); new_visitor();
$r = add( $G, $F, '1' );
$ok( 'lucky=1: количката е направена', ! empty( $r['success'] ), $r );
$ok( 'lucky=1: маската е ред с роля lucky (2 платени + 2 подаръка + маската)', array( 'base:101x2', 'gift:103x1', 'gift:201x1', 'lucky:11161x1' ) === roles(), roles() );
$ok( 'отговорът казва, че маската е вътре', true === ( $r['data']['lucky'] ?? null ), $r['data'] ?? null );
WC()->cart->calculate_totals(); $ll = lucky_line();
$ok( 'маската е на 0 €', $ll && 0.0 === (float) $ll['data']->get_price(), $ll ? $ll['data']->get_price() : 'няма ред' );
$ok( 'общата сума = двете платени опаковки (79,80)', abs( (float) WC()->cart->get_total( 'edit' ) - 79.80 ) < 0.01 || abs( (float) WC()->cart->get_subtotal() - 79.80 ) < 0.01, WC()->cart->get_total( 'edit' ) );
$ok( 'сесията помни подаръка', ! empty( Cart::ctx()['lucky'] ) );
$order = checkout( 'lucky@example.com' ); $lines = array();
foreach ( $order->get_items() as $li ) { $lines[] = ( $li->meta['_ansa_bf_role'] ?? '?' ) . ':' . $li->pid . 'x' . $li->qty; } sort( $lines );
$ok( 'поръчката носи маската като ред с роля lucky', in_array( 'lucky:11161x1', $lines, true ), $lines );
$m = $order->get_meta( '_ansa_promo_bf' ); $ok( 'мета на поръчката: lucky = 1', is_array( $m ) && ! empty( $m['lucky'] ), $m );

/* ── 2. втора количка в същата сесия (върнала се е и е сменила продуктите): маската остава, дори полето да липсва ── */
bf_cfg(); new_visitor(); add( $G, $F, '1' );
$r = add( array( array( 'id' => 102, 'q' => 1 ) ), array( array( 'id' => 201, 'q' => 1 ) ) );
$ok( 'пренаправена количка без полето: маската остава (помни я сесията)', in_array( 'lucky:11161x1', roles(), true ) && true === ( $r['data']['lucky'] ?? null ), roles() );

/* ── 3. не е приела / отказала е ── */
bf_cfg(); new_visitor(); $r = add( $G, $F, '0' );
$ok( 'lucky=0: без маска', ! lucky_line() && false === ( $r['data']['lucky'] ?? null ) && empty( Cart::ctx()['lucky'] ), roles() );
bf_cfg(); new_visitor(); $r = add( $G, $F );
$ok( 'без полето: без маска', ! lucky_line() && false === ( $r['data']['lucky'] ?? null ), roles() );

/* ── 4. предпазителите остават ── */
bf_cfg( array( 'lucky' => array( 'enabled' => false ) ) ); new_visitor(); $r = add( $G, $F, '1' );
$ok( 'изключено раздаване: без маска, количката си е наред', ! empty( $r['success'] ) && ! lucky_line() && false === ( $r['data']['lucky'] ?? null ), roles() );
bf_cfg( array( 'lucky' => array( 'phases' => 'early' ) ) ); new_visitor(); add( $G, $F, '1' );
$ok( 'фазата не е в настройката (само ранен достъп, а тече публичният): без маска', ! lucky_line(), roles() );
bf_cfg(); new_visitor(); $GLOBALS['products'][11161]->stock = 0; $GLOBALS['products'][11161]->in_stock = false; $r = add( $G, $F, '1' );
$ok( 'маската е изчерпана: количката се прави без нея и отговорът го казва', ! empty( $r['success'] ) && ! lucky_line() && false === ( $r['data']['lucky'] ?? null ), array( roles(), $r['data'] ?? null ) );

if ( $fail ) { echo "ГРЕШКИ:\n- " . implode( "\n- ", $fail ) . "\n"; exit( 1 ); }
echo 'OK ' . $n . " проверки\n";
