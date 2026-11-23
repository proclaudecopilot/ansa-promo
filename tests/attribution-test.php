<?php
/**
 * Офлайн тест на 📣 Attribution (1.56.0): бисквитките → канал по Source::classify, видът на поръчката, метата, отчетът и CSV-то — без WordPress.
 * php tests/attribution-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Attribution; use AnsaPromo\Source;
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return false; } }
if ( ! function_exists( 'get_gmt_from_date' ) ) { function get_gmt_from_date( $d ) { return $d; } }
if ( ! defined( 'ANSA_PROMO_VER' ) ) { define( 'ANSA_PROMO_VER', 'test' ); }
$n = 0; $fail = array(); $ok = function ( $name, $cond, $diag = '' ) use ( &$n, &$fail ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $diag ? ' — ' . $diag : '' ); } };
class AOrder { public $meta = array(); public $saved = 0; private $id; public $status; public $total; public $date; function __construct( $id, $meta = array(), $status = 'processing', $total = 50, $date = '2026-11-23 10:00:00' ) { $this->id = $id; $this->meta = $meta; $this->status = $status; $this->total = $total; $this->date = $date; } function get_id() { return $this->id; } function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } function save() { $this->saved++; } function get_status() { return $this->status; } function get_total() { return $this->total; } function get_date_created() { return new class( $this->date ) { private $d; function __construct( $d ) { $this->d = $d; } function date( $f ) { return date( $f, strtotime( $this->d ) ); } }; } }

/* бисквитките */
$meta_q = 'utm_source=facebook&utm_medium=paid&utm_campaign=BF%20Retargeting&utm_id=120&adset_name=Warm%2030d&adset_id=77&ad_name=Video%20A&ad_id=5&utm_content=greens&fbclid=abc';
$email_q = 'utm_source=newsletter&utm_medium=email&utm_campaign=BF%20start%2023.11&utm_content=cta1';
$c = json_encode( array( 'q' => $meta_q, 'r' => 'https://l.facebook.com/', 'l' => '/cheren-petak-2026/', 't' => 1700000000 ) );
$t = Attribution::touch( $c ); $ok( 'touch(): JSON → q/r/l/t', $t && $t['q'] === $meta_q && 'https://l.facebook.com/' === $t['r'] && '/cheren-petak-2026/' === $t['l'] && 1700000000 === $t['t'] );
$ok( 'touch(): и URL-кодирано (както идва от document.cookie); празно/счупено → null', Attribution::touch( rawurlencode( $c ) ) === $t && null === Attribution::touch( '' ) && null === Attribution::touch( 'x' ) && null === Attribution::touch( '{"t":1}' ) );
$cl = Attribution::classify( $t ); $ok( 'classify(): Meta реклама → кампания → група → реклама, продуктът от utm_content', $cl && 'meta' === $cl['k'] && 'greens' === $cl['product'] && 3 === count( $cl['path'] ) && false !== stripos( $cl['path'][0], 'BF Retargeting' ) && false !== stripos( $cl['path'][2], 'Video A' ), json_encode( $cl, JSON_UNESCAPED_UNICODE ) );
$ce = Attribution::classify( Attribution::touch( json_encode( array( 'q' => $email_q, 'r' => '', 'l' => '/cheren-petak-2026/', 't' => 1 ) ) ) ); $ok( 'classify(): имейл → изпращане → линк', 'email' === $ce['k'] && false !== stripos( implode( ' ', $ce['path'] ), 'BF start' ), json_encode( $ce, JSON_UNESCAPED_UNICODE ) );
$cd = Attribution::classify( Attribution::touch( json_encode( array( 'q' => '', 'r' => '', 'l' => '/', 't' => 1 ) ) ) ); $ok( 'classify(): без нищо → директно', 'direct' === $cd['k'] );
$cg = Attribution::classify( Attribution::touch( json_encode( array( 'q' => '', 'r' => 'https://www.google.com/', 'l' => '/', 't' => 1 ) ) ) ); $ok( 'classify(): referrer Google → органично', 'google_org' === $cg['k'] );
$_COOKIE = array( Attribution::C_FIRST => rawurlencode( json_encode( array( 'q' => $email_q, 'r' => '', 'l' => '/cheren-petak-2026/', 't' => 1 ) ) ), Attribution::C_LAST => rawurlencode( $c ) );
$ck = Attribution::cookies(); $ok( 'cookies(): first = имейлът, last = Meta', $ck['first']['q'] === $email_q && $ck['last']['q'] === $meta_q );
$_COOKIE = array( Attribution::C_LAST => rawurlencode( $c ) ); $ck = Attribution::cookies(); $ok( 'cookies(): само last → first = last', $ck['first']['q'] === $meta_q );
$_COOKIE = array();

/* видът и метата на поръчката */
$ok( 'kind_of: bf / season / shop / phone (мета) / phone (контекст)', 'bf' === Attribution::kind_of( new AOrder( 1, array( '_ansa_promo_bf' => array( 'x' => 1 ) ) ), null ) && 'season' === Attribution::kind_of( new AOrder( 2, array( '_ansa_promo' => array( 'box' => 'm' ) ) ), null ) && 'shop' === Attribution::kind_of( new AOrder( 3 ), null ) && 'phone' === Attribution::kind_of( new AOrder( 4, array( '_ansa_promo' => array( 'phone_order' => array( 'by' => 'x' ) ) ) ), null ) && 'phone' === Attribution::kind_of( new AOrder( 5, array( '_ansa_promo_phone' => array( 'by' => 'x' ) ) ), null ) && 'phone' === Attribution::kind_of( new AOrder( 6 ), array( 'src' => 'cart:1' ) ) );
$cc = array( 'first' => Attribution::touch( json_encode( array( 'q' => $email_q, 'r' => '', 'l' => '/cheren-petak-2026/', 't' => 1 ) ) ), 'last' => Attribution::touch( $c ) );
$o = new AOrder( 10, array( '_ansa_promo_bf' => array( 'x' => 1 ) ) ); $d = Attribution::stamp( $o, $cc, null );
$ok( 'stamp(): мета _ansa_src_ch = meta (последно), _ansa_src_chf = email (първо), kind bf, пътищата като JSON, суровото в _ansa_src', $d && 'meta' === $o->meta[ Attribution::M_CH ] && 'email' === $o->meta[ Attribution::M_CHF ] && 'bf' === $o->meta[ Attribution::M_KIND ] && false !== strpos( $o->meta[ Attribution::M_PATH ], 'BF Retargeting' ) && is_array( $o->meta[ Attribution::META ]['last'] ) && 'greens' === $o->meta[ Attribution::META ]['product'], json_encode( $o->meta, JSON_UNESCAPED_UNICODE ) );
$ok( 'stamp(): втори път → null (не презаписва)', null === Attribution::stamp( $o, $cc, null ) );
$op = new AOrder( 11 ); Attribution::stamp( $op, $cc, array( 'src' => 'cart:5' ) ); $ok( 'stamp(): поръчка по телефон → канал phone и в двете, бисквитките на оператора не броят', 'phone' === $op->meta[ Attribution::M_CH ] && 'phone' === $op->meta[ Attribution::M_CHF ] && 'phone' === $op->meta[ Attribution::M_KIND ] );
$on = new AOrder( 12 ); Attribution::stamp( $on, array( 'first' => null, 'last' => null ), null ); $ok( 'stamp(): без бисквитки → direct', 'direct' === $on->meta[ Attribution::M_CH ] && 'shop' === $on->meta[ Attribution::M_KIND ] );
$o2 = new AOrder( 13 ); $_COOKIE = array( Attribution::C_LAST => rawurlencode( $c ) ); Attribution::on_new( 13, $o2 ); $ok( 'on_new(): печата от бисквитките и save()', 'meta' === $o2->meta[ Attribution::M_CH ] && 1 === $o2->saved ); Attribution::on_new( 13, $o2 ); $ok( 'on_new(): втори път без save', 1 === $o2->saved ); $_COOKIE = array();

/* отчетът (резервата wc_get_orders) */
$GLOBALS['aorders'] = array(
	new AOrder( 21, array( Attribution::M_CH => 'meta', Attribution::M_CHF => 'email', Attribution::M_KIND => 'bf', Attribution::M_PATH => json_encode( array( 'BF Retargeting', 'Warm 30d', 'Video A' ) ), Attribution::M_PATHF => json_encode( array( 'BF start', 'cta1' ) ) ), 'processing', 100 ),
	new AOrder( 22, array( Attribution::M_CH => 'meta', Attribution::M_CHF => 'meta', Attribution::M_KIND => 'bf', Attribution::M_PATH => json_encode( array( 'BF Retargeting', 'Warm 30d', 'Video B' ) ), Attribution::M_PATHF => json_encode( array( 'BF Retargeting' ) ) ), 'cancelled', 60 ),
	new AOrder( 23, array( Attribution::M_CH => 'email', Attribution::M_CHF => 'email', Attribution::M_KIND => 'bf', Attribution::M_PATH => json_encode( array( 'BF start', 'cta1' ) ), Attribution::M_PATHF => json_encode( array( 'BF start', 'cta1' ) ) ), 'completed', 80 ),
	new AOrder( 24, array( Attribution::M_CH => 'direct', Attribution::M_CHF => 'direct', Attribution::M_KIND => 'shop', Attribution::M_PATH => '[]', Attribution::M_PATHF => '[]' ), 'processing', 40 ),
	new AOrder( 25, array( '_ansa_promo' => array( 'box' => 'm' ) ), 'processing', 120 ), /* отпреди 1.56.0 — без данни, Сезонът */
	new AOrder( 26, array( '_ansa_promo_phone' => array( 'by' => 'Яна' ) ), 'processing', 70 ), /* по телефон отпреди — каналът е phone и без мета */
	new AOrder( 27, array( Attribution::M_CH => 'direct', Attribution::M_KIND => 'shop' ), 'checkout-draft', 999 ), /* чернова — не влиза */
);
function wc_get_orders( $a ) { return $GLOBALS['aorders']; }
$rows = Attribution::orders( array( 'from' => '2026-11-23', 'to' => '2026-11-23' ) );
$ok( 'orders(): 6 реда (черновата отпада), видовете, без данни за старата, phone за старата телефонна', 6 === count( $rows ) && 'bf' === $rows[0]['kind'] && 'meta' === $rows[0]['ch'] && 'nodata' === $rows[4]['ch'] && 'season' === $rows[4]['kind'] && 'phone' === $rows[5]['ch'] && 'phone' === $rows[5]['kind'] && true === $rows[1]['lost'], json_encode( array_map( function ( $r ) { return $r['id'] . ':' . $r['kind'] . ':' . $r['ch']; }, $rows ) ) );
$r = Attribution::report( $rows, 'last' );
$ok( 'report(last): 6 поръчки · 470 € · 1 отказана · 1 без данни; meta 2 (1 отказана, 160 €), email 1, direct 1, phone 1', 6 === $r['n'] && abs( $r['total'] - 470 ) < 0.01 && 1 === $r['lost'] && 1 === $r['nodata'] && 2 === $r['by_ch']['meta']['n'] && 1 === $r['by_ch']['meta']['lost'] && abs( $r['by_ch']['meta']['total'] - 160 ) < 0.01 && 1 === $r['by_ch']['email']['n'] && 1 === $r['by_ch']['direct']['n'] && 1 === $r['by_ch']['phone']['n'] && 1 === $r['by_ch']['nodata']['n'], json_encode( $r['by_ch'] ) );
$ok( 'report(last): по вид — bf 3, shop 1, season 1, phone 1; meta kinds bf 2', 3 === $r['by_kind']['bf']['n'] && 1 === $r['by_kind']['shop']['n'] && 1 === $r['by_kind']['season']['n'] && 1 === $r['by_kind']['phone']['n'] && 2 === $r['by_ch']['meta']['kinds']['bf'] );
$ok( 'report(last): Meta кампаниите по път (кампания → група → реклама), имейлите по изпращане → линк', isset( $r['meta']['BF Retargeting → Warm 30d → Video A'] ) && isset( $r['meta']['BF Retargeting → Warm 30d → Video B'] ) && isset( $r['email']['BF start → cta1'] ) && 1 === $r['email']['BF start → cta1']['n'], json_encode( array_keys( $r['meta'] ) + array_keys( $r['email'] ), JSON_UNESCAPED_UNICODE ) );
$rf = Attribution::report( $rows, 'first' ); $ok( 'report(first): email 2 (21 и 23), meta 1', 2 === $rf['by_ch']['email']['n'] && 1 === $rf['by_ch']['meta']['n'] );
$csv = Attribution::csv( array( 'from' => '2026-11-23', 'to' => '2026-11-23' ) ); $ok( 'csv(): заглавия и 6 реда, с канал/път', 7 === substr_count( $csv, "\n" ) && false !== strpos( $csv, 'order_id;date;status;total;kind;channel_last;path_last;channel_first;path_first' ) && false !== strpos( $csv, '21;"2026-11-23 10:00:00";processing;100.00;' ) && false !== strpos( $csv, 'BF Retargeting → Warm 30d → Video A' ) && false !== strpos( $csv, 'без данни' ), substr( $csv, 0, 400 ) );
/* скриптът */
ob_start(); Attribution::script(); $js = ob_get_clean(); $ok( 'script(): пише двете бисквитки, 30 дни, SameSite, само при докосване', false !== strpos( $js, 'ansa_src_first' ) && false !== strpos( $js, 'ansa_src_last' ) && false !== strpos( $js, 'max-age=2592000' ) && false !== strpos( $js, 'SameSite=Lax' ) && false !== strpos( $js, 'if(!(marks||ext||!r))return' ) );

if ( $fail ) { echo "FAIL\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
