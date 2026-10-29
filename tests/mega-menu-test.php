<?php
/**
 * 1.42.2: офлайн тест на мега менюто „Продукти“ (Mega_Menu::products / shortcode) без WordPress.
 * php tests/mega-menu-test.php → „OK n проверки“ или списък с грешки (exit 1).
 * Проверява: допълнителните продукти към „Менопауза“ (EXTRA) влизат във филтъра и в групата, но картата им пази основната нужда;
 * продукт, скрит от каталога (D3K2, exclude-from-catalog), се взема по slug; продукт от промото се води под slug-а от магазина (omega → omega-3);
 * изчерпан продукт не се появява, макар да е изброен; Utera+ не е в „Менопауза“.
 */
require __DIR__ . '/wcstub.php';
use AnsaPromo\Mega_Menu; use AnsaPromo\Config;

/* ── стъбове отгоре на wcstub: slug, видимост в каталога, наличност, търсене по slug ── */
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function get_post_thumbnail_id( $id ) { return 0; }
class MMProduct extends FakeProduct {
	public $slug = '', $visible = true, $instock = true, $status = 'publish';
	public $vprices = array(); function get_variation_prices( $d = false ) { return array( 'price' => $this->vprices ); } function get_slug() { return $this->slug; } function is_visible() { return $this->visible; } function is_in_stock() { return $this->instock; } function get_status() { return $this->status; }
}
function mmp( $id, $price, $name, $slug, $visible = true, $instock = true, $type = 'simple' ) { $p = new MMProduct( $id, $price, $type, 0, array(), $name ); $p->slug = $slug; $p->visible = $visible; $p->instock = $instock; $GLOBALS['products'][ $id ] = $p; return $p; }
function mmv( $id, $price, $name, $slug, $vprices = array() ) { /* variable родител + вариация „1 кутия“ (както в промото); $vprices = цените на вариациите (1.42.4) */ $p = mmp( $id, $price, $name, $slug, true, true, 'variable' ); $p->children = array( $id + 1 ); $p->vprices = $vprices ?: array( $id + 1 => $price ); $v = new MMProduct( $id + 1, $price, 'variation', $id, array(), $name . ' - 1 кутия' ); $GLOBALS['products'][ $id + 1 ] = $v; }
$GLOBALS['products'] = array();
mmv( 100, 43.90, 'ansa Greens', 'daily-greens' ); mmv( 200, 49.90, 'ansa Sakura', 'sakura-skintonic' );
mmv( 300, 49.90, 'ansa™ – Омега 3 - Пречистено рибено масло', 'omega-3' );      /* в промото под ключ „omega“, в магазина slug omega-3 */
mmv( 400, 39.90, 'ansa™ – Red Routine – Плодова добавка за сън', 'red-routine' ); /* ключ „red“ от промото; slug-ът не е изброен → остава „red“ */
mmv( 500, 39.90, 'ansa™ MenoStop | Дневна и нощна капсула', 'menostop' );
mmp( 600, 27.90, 'ansa™ - Ашваганда + Магнезий + Вит. В6', 'ashwagandha' );
mmp( 700, 19.90, 'ansa™ – D3K2 с Ретинол, Шафран и Вечерна Иглика', 'd3k2', false, true ); /* скрит от каталога, наличен */
mmp( 800, 30.67, 'ansa™ - No Ache - добавка за стави', 'no-ache', false, false );        /* скрит и изчерпан → не влиза */
mmp( 900, 39.90, 'ansa™ – Utera+ За здрава репродуктивна система', 'utera-plus' );
mmp( 950, 0.0, 'Подарък - КОЛЕДЕН ВАУЧЕР', 'koleden-voucher', false, true );
mmv( 1000, 53.92, 'ansa™ – eyeFresh Хидрогелни Пачове за очи', 'eye-fresh', array( 1001 => '15.90', 1002 => '27.90', 1003 => '35.90' ) ); /* 1.42.4: промото/родителят казват 53,92, 1 кутия е 15,90 */
$GLOBALS['products'][600]->vprices = array(); $GLOBALS['products'][600]->price = 27.90; /* simple: get_price */
mmv( 1100, 65.90, 'ansa™ - Тест стар parent _price', 'stale-parent', array( 1101 => '27.90', 1102 => '49.90', 1103 => '65.90' ) );
function wc_get_products( $args = array() ) { $o = array(); foreach ( $GLOBALS['products'] as $p ) { if ( $p instanceof MMProduct && 'variation' !== $p->type ) { $o[] = clone $p; } } return $o; }
function get_page_by_path( $slug, $output = OBJECT, $type = 'post' ) { foreach ( $GLOBALS['products'] as $p ) { if ( $p instanceof MMProduct && $p->slug === $slug && 'variation' !== $p->type ) { return (object) array( 'ID' => $p->id ); } } return null; }

/* промо конфигът: greens, sakura, omega, red, meno (както на живо: ashwa е без product_id → не влиза от промото) */
$store = $GLOBALS['opts']['ansa_promo_store']; $def = $store['published'];
$def['products'] = array(
	array( 'key' => 'greens', 'product_id' => 100, 'enabled' => true ),
	array( 'key' => 'sakura', 'product_id' => 200, 'enabled' => true ),
	array( 'key' => 'omega',  'product_id' => 300, 'enabled' => true ),
	array( 'key' => 'red',    'product_id' => 400, 'enabled' => true ),
	array( 'key' => 'meno',   'product_id' => 500, 'enabled' => true ),
	array( 'key' => 'ashwa',  'product_id' => 0,   'enabled' => true ),
	array( 'key' => 'eyefresh-hidrogelni', 'product_id' => 1000, 'enabled' => true ),
);
$def = Config::normalize( $def ); $store['published'] = $def; $store['draft'] = $def; $GLOBALS['opts']['ansa_promo_store'] = $store;

$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };
set_error_handler( function ( $no, $msg, $file, $line ) { throw new ErrorException( $msg, 0, $no, $file, $line ); } );

/* ── products() ── */
$P = Mega_Menu::products(); $keys = array_keys( $P );
$ok( 'продуктите от промото са първи', array( 'greens', 'sakura', 'omega-3', 'red', 'meno', 'eyefresh-hidrogelni' ) === array_slice( $keys, 0, 6 ), implode( ',', $keys ) );
/* 1.42.4: „от“ цената = най-ниската вариация */
$ok( 'EyeFresh от промото: „от“ 15,90 (1 кутия), не 53,92', isset( $P['eyefresh-hidrogelni'] ) && 15.9 === $P['eyefresh-hidrogelni']['price'], json_encode( $P['eyefresh-hidrogelni']['price'] ?? null ) );
$ok( 'продукт от магазина със стар parent _price 65,90: „от“ 27,90', isset( $P['stale-parent'] ) && 27.9 === $P['stale-parent']['price'], json_encode( $P['stale-parent']['price'] ?? null ) );
$ok( 'обикновен продукт: get_price (Ашваганда 27,90)', 27.9 === $P['ashwagandha']['price'] );
$ok( 'from_price: без продукт → fallback', 5.0 === Mega_Menu::from_price( null, 5 ) );
$ok( 'omega от промото се води под slug-а omega-3 (изброен в нуждите)', isset( $P['omega-3'] ) && ! isset( $P['omega'] ) && 300 === $P['omega-3']['id'] );
$ok( 'red остава red (slug-ът red-routine не е изброен)', isset( $P['red'] ) && ! isset( $P['red-routine'] ) );
$ok( 'ashwagandha идва от магазина', isset( $P['ashwagandha'] ) && 600 === $P['ashwagandha']['id'] );
$ok( 'D3K2 влиза, макар скрит от каталога (изброен в нуждите)', isset( $P['d3k2'] ) && 700 === $P['d3k2']['id'] && 19.9 === $P['d3k2']['price'] );
$ok( 'No Ache (изброен, но изчерпан) не влиза', ! isset( $P['no-ache'] ) );
$ok( 'Utera+ влиза от каталога', isset( $P['utera-plus'] ) );
$ok( 'ваучерът не влиза', ! isset( $P['koleden-voucher'] ) );
$ok( 'D3K2 е с описанието от DS', 'Настроение, имунитет и кости' === $P['d3k2']['ds'] );
$ok( 'D3K2 не се дублира при второ извикване', count( Mega_Menu::products() ) === count( $P ) );

/* ── list_of / listed_keys ── */
$ok( 'Менопауза = MenoStop + Омега 3, Ашваганда, Red Routine, D3K2 (в този ред)', array( 'meno', 'omega-3', 'ashwagandha', 'red', 'd3k2' ) === Mega_Menu::list_of( 'meno' ), implode( ',', Mega_Menu::list_of( 'meno' ) ) );
$ok( 'Utera+ не е в Менопауза', ! in_array( 'utera-za', Mega_Menu::list_of( 'meno' ), true ) && ! in_array( 'utera-plus', Mega_Menu::list_of( 'meno' ), true ) );
$ok( 'Utera+ остава в Интимно здраве и Нови', in_array( 'utera-za', Mega_Menu::list_of( 'intim' ), true ) && in_array( 'utera-za', Mega_Menu::list_of( 'new' ), true ) );
$ok( 'listed_keys съдържа d3k2 и ashwagandha', in_array( 'd3k2', Mega_Menu::listed_keys(), true ) && in_array( 'ashwagandha', Mega_Menu::listed_keys(), true ) );

/* ── shortcode: десктоп ── */
$h = Mega_Menu::shortcode();
$card = function ( $key ) use ( $h, $P ) { /* картата по името (SHORT или името на продукта) */ $name = preg_quote( htmlspecialchars( constant( 'AnsaPromo\\Mega_Menu::SHORT' )[ $key ] ?? $P[ $key ]['name'], ENT_QUOTES, 'UTF-8' ), '~' ); return preg_match( '~<a class="amm-card" data-c="([^"]*)"[^>]*>(?:(?!</a>).)*?<span class="amm-cat">([^<]*)</span><b>' . $name . '</b>~su', $h, $m ) ? array( 'c' => $m[1], 'cat' => $m[2] ) : null; };
$ok( '1.42.3: редът със заглавието носи страниците ‹ › (скрити, докато JS не ги включи)', false !== strpos( $h, '<span class="amm-pager" hidden><button type="button" class="amm-pg amm-prev"' ) && false !== strpos( $h, 'class="amm-pg amm-next"' ) );
$ok( 'бутонът Менопауза брои 5 продукта', false !== strpos( $h, 'data-n="meno" type="button"><span class="amm-ic">🌷</span><span>Менопауза<small>5 продукта</small>' ), $h );
$c = $card( 'omega-3' ); $ok( 'картата Omega-3: филтри meno+general, категория „Общо здраве“', $c && 'meno general' === $c['c'] && 'Общо здраве' === $c['cat'], json_encode( $c, JSON_UNESCAPED_UNICODE ) );
$c = $card( 'red' );     $ok( 'картата Red Routine: филтри meno+sleep, категория „Сън и стрес“', $c && 'meno sleep' === $c['c'] && 'Сън и стрес' === $c['cat'], json_encode( $c, JSON_UNESCAPED_UNICODE ) );
$c = $card( 'ashwagandha' ); $ok( 'картата Ashwagandha: филтри meno+sleep, категория „Сън и стрес“', $c && 'meno sleep' === $c['c'] && 'Сън и стрес' === $c['cat'], json_encode( $c, JSON_UNESCAPED_UNICODE ) );
$c = $card( 'd3k2' );    $ok( 'картата D3K2: филтри meno+general, категория „Общо здраве“', $c && 'meno general' === $c['c'] && 'Общо здраве' === $c['cat'], json_encode( $c, JSON_UNESCAPED_UNICODE ) );
$c = $card( 'meno' );    $ok( 'картата MenoStop: филтър meno, категория „Менопауза“', $c && 'meno' === $c['c'] && 'Менопауза' === $c['cat'], json_encode( $c, JSON_UNESCAPED_UNICODE ) );
$c = $card( 'utera-plus' ); $ok( 'картата Utera+: „Нови“, не в meno', $c && false === strpos( ' ' . $c['c'] . ' ', ' meno ' ) && 'Нови' === $c['cat'], json_encode( $c, JSON_UNESCAPED_UNICODE ) );

/* ── shortcode: телефон (акордеонът) ── */
preg_match( '~<details><summary><span class="amm-ic">🌷</span>Менопауза<small>(\d+)</small></summary>(.*?)</details>~su', $h, $m );
$ok( 'групата Менопауза на телефон: 5', $m && '5' === $m[1] );
preg_match_all( '~<a class="amm-row"[^>]*>.*?<b>([^<]*)</b>~su', $m[2] ?? '', $rows );
$ok( 'редовете: MenoStop, Omega-3, Ashwagandha, Red Routine, D3K2', 5 === count( $rows[1] ?? array() ) && 'Omega-3' === ( $rows[1][1] ?? '' ) && 'Ashwagandha + Mg + B6' === ( $rows[1][2] ?? '' ) && 'D3K2' === ( $rows[1][4] ?? '' ) && false !== mb_stripos( (string) ( $rows[1][0] ?? '' ), 'meno' ) && false !== mb_stripos( (string) ( $rows[1][3] ?? '' ), 'red' ), implode( ' | ', $rows[1] ?? array() ) );
$ok( 'Utera+ не е в групата Менопауза', false === strpos( $m[2] ?? '', 'Utera' ) );
preg_match( '~<details><summary><span class="amm-ic">💛</span>Общо здраве<small>(\d+)</small></summary>(.*?)</details>~su', $h, $g );
$ok( 'Общо здраве: Omega-3 и D3K2 (No Ache е изчерпан)', $g && '2' === $g[1] && false !== strpos( $g[2], '>D3K2<' ) && false !== strpos( $g[2], '>Omega-3<' ) && false === strpos( $g[2], 'No Ache' ), $g[1] ?? '' );

if ( $fail ) { echo "ГРЕШКИ:\n- " . implode( "\n- ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
