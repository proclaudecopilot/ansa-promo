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

/* ── 1.56.0: веднъж на заявка; srcset; кампанията по прозорци (Сезонът скрит до 01.12, Черният петък 25–30.11) ── */
$ok( '1.56.0: products() е запомнен за заявката (същият масив)', Mega_Menu::products() === $P );
$ok( '1.56.0: снимките са с decoding=async и остават loading=lazy в HTML', false === strpos( $h, '<img' ) || ( false !== strpos( $h, 'loading="lazy" decoding="async"' ) ) );
$ok( '1.56.0: без blur зад менюто', false === strpos( $h, 'backdrop-filter' ) && false !== strpos( $h, '.amm-backdrop{' ) );
$ok( '1.56.0: JS зарежда снимките предварително и превключва прозорците', false !== strpos( $h, "loading='eager'" ) && false !== strpos( $h, "querySelectorAll('.amm-w')" ) );
function wp_get_attachment_image_srcset( $id, $size = '' ) { return 123 === (int) $id ? 'http://x/a-768x768.webp 768w, http://x/a-600x600.webp 600w' : false; }
$GLOBALS['opts']['ansa_mega_menu_images'] = array( 'greens' => '123', 'sakura' => 'https://cdn.x/s.webp' );
$ok( '1.56.0: srcset от attachment-а в опцията', 'http://x/a-768x768.webp 768w, http://x/a-600x600.webp 600w' === Mega_Menu::image_srcset( 'greens', 100 ) );
$ok( '1.56.0: URL в опцията → без srcset; без снимка → без srcset', '' === Mega_Menu::image_srcset( 'sakura', 200 ) && '' === Mega_Menu::image_srcset( 'meno', 500 ) );
unset( $GLOBALS['opts']['ansa_mega_menu_images'] );
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $k, $v = null, $u = null ) { return (string) $u; } }
spl_autoload_register( function ( $c ) { if ( 0 !== strpos( $c, 'AnsaPromo\\BF\\' ) ) { return; } $f = ANSA_PROMO_PATH . 'includes/bf/class-' . strtolower( str_replace( '_', '-', substr( $c, 13 ) ) ) . '.php'; if ( is_file( $f ) ) { require_once $f; } } );
$tz = new DateTimeZone( 'Europe/Sofia' ); $ts = function ( $x ) use ( $tz ) { return ( new DateTimeImmutable( $x, $tz ) )->getTimestamp(); };
$bf = AnsaPromo\BF\Config::normalize( array( 'enabled' => true ) ); /* фазите по подразбиране: публичен 25.11 07:00 → 30.11 10:00 */
$GLOBALS['opts'][ AnsaPromo\BF\Config::OPTION ] = array( 'draft' => null, 'published' => $bf, 'published_at' => 1, 'version' => 1, 'history' => array() ); AnsaPromo\BF\Config::reset_memory();
$ok( 'site_ts: 01.12.2026 00:00 по часа на сайта', $ts( '2026-12-01 00:00' ) === Mega_Menu::site_ts( Mega_Menu::SEASON_BACK ) );
$W = Mega_Menu::windows( $ts( '2026-11-23 13:00' ) );
$ok( '23.11: Черният петък е бъдещ прозорец 25.11 07:00 → 30.11 10:00', isset( $W['bf'] ) && $ts( '2026-11-25 07:00' ) === $W['bf']['from'] && $ts( '2026-11-30 10:00' ) === $W['bf']['until'] && ! Mega_Menu::window_on( $W['bf'], $ts( '2026-11-23 13:00' ) ), json_encode( $W ) );
$ok( '23.11: Сезонът е бъдещ прозорец от 01.12 и не се показва', isset( $W['season'] ) && $ts( '2026-12-01 00:00' ) === $W['season']['from'] && ! Mega_Menu::window_on( $W['season'], $ts( '2026-11-23 13:00' ) ), json_encode( $W ) );
$ok( '26.11: Черният петък се показва, Сезонът не', Mega_Menu::window_on( $W['bf'], $ts( '2026-11-26 12:00' ) ) && ! Mega_Menu::window_on( $W['season'], $ts( '2026-11-26 12:00' ) ) );
$ok( '30.11 09:59 да / 10:00 не', Mega_Menu::window_on( $W['bf'], $ts( '2026-11-30 09:59' ) ) && ! Mega_Menu::window_on( $W['bf'], $ts( '2026-11-30 10:00' ) ) );
$W2 = Mega_Menu::windows( $ts( '2026-11-30 12:00' ) ); $ok( '30.11 12:00: Черният петък вече не се печата; Сезонът още е скрит', ! isset( $W2['bf'] ) && isset( $W2['season'] ) && ! Mega_Menu::window_on( $W2['season'], $ts( '2026-11-30 12:00' ) ) );
$ok( '01.12 00:00: Сезонът се връща', Mega_Menu::window_on( $W2['season'], $ts( '2026-12-01 00:00' ) ) && ! Mega_Menu::window_on( $W2['season'], $ts( '2026-11-30 23:59' ) ) );
/* шорткодът и хапчето в три момента */
$render = function ( $when ) use ( $ts ) { Mega_Menu::$clock = $ts( $when ); $r = new ReflectionProperty( 'AnsaPromo\\Mega_Menu', 'assets_done' ); $r->setAccessible( true ); $r->setValue( null, false ); return array( Mega_Menu::shortcode(), Mega_Menu::season_item( '<li id="menu-item-15392" class="x"></li><li id="menu-item-15391" class="y"></li>', null ) ); };
$vis = function ( $html, $needle ) { return (bool) preg_match( '~<(?:a|li) class="[^"]*amm-w[^"]*" data-amm-from="\d+" data-amm-until="\d+"(?! hidden)[^>]*>(?:(?!</a>|</li>).)*' . preg_quote( $needle, '~' ) . '~su', $html ); };
list( $h1, $n1 ) = $render( '2026-11-23 13:00' );
$ok( '23.11 меню: нито Сезонът, нито Черният петък се виждат (и двете са hidden)', ! $vis( $h1, 'Сезонът на ANSA' ) && ! $vis( $h1, 'Черен петък 2026' ) && ! $vis( $n1, 'Сезонът' ) && ! $vis( $n1, 'Черен петък' ) && false !== strpos( $h1, 'hidden' ), $n1 );
$ok( '23.11 меню: двете са в HTML за кеша (с прозорците си)', 2 === substr_count( $n1, 'amm-w"' ) && 2 === substr_count( $h1, 'class="amm-promo amm-w"' ) && 2 === substr_count( $h1, 'class="amm-mpromo amm-w"' ) );
$ok( 'хапчето стои между „Продукти“ и „Отзиви“', (bool) preg_match( '~menu-item-15392.*amm-bf.*amm-season amm-w.*menu-item-15391~s', $n1 ) );
list( $h2, $n2 ) = $render( '2026-11-26 12:00' );
$ok( '26.11 меню: Черният петък се вижда (хапче, карта, телефон), Сезонът не', $vis( $n2, 'Черен петък' ) && $vis( $h2, 'Черен петък 2026 · до 30.11' ) && ! $vis( $n2, 'Сезонът' ) && ! $vis( $h2, 'Сезонът на ANSA' ), $n2 );
$ok( '26.11: линкът е страницата на Черния петък', false !== strpos( $n2, 'href="http://x/cheren-petak-2026/"' ) );
list( $h3, $n3 ) = $render( '2026-12-01 00:05' );
$ok( '01.12 меню: Сезонът се вижда, Черният петък го няма в HTML', $vis( $n3, 'Сезонът' ) && $vis( $h3, 'Сезонът на ANSA' ) && false === strpos( $h3, 'Черен петък 2026' ) && false === strpos( $n3, 'amm-bf' ), $n3 );
/* force и изключен Черен петък */
$bf['enabled'] = false; $GLOBALS['opts'][ AnsaPromo\BF\Config::OPTION ]['published'] = $bf; AnsaPromo\BF\Config::reset_memory();
$ok( 'изключен Черен петък → няма прозорец', ! isset( Mega_Menu::windows( $ts( '2026-11-26 12:00' ) )['bf'] ) );
$bf['enabled'] = true; $bf['force'] = 'public'; $GLOBALS['opts'][ AnsaPromo\BF\Config::OPTION ]['published'] = $bf; AnsaPromo\BF\Config::reset_memory();
$Wf = Mega_Menu::windows( $ts( '2026-11-23 13:00' ) ); $ok( 'force=public → показва се веднага', isset( $Wf['bf'] ) && Mega_Menu::window_on( $Wf['bf'], $ts( '2026-11-23 13:00' ) ) );
$bf['force'] = 'over'; $GLOBALS['opts'][ AnsaPromo\BF\Config::OPTION ]['published'] = $bf; AnsaPromo\BF\Config::reset_memory();
$ok( 'force=over → няма прозорец', ! isset( Mega_Menu::windows( $ts( '2026-11-26 12:00' ) )['bf'] ) );
Mega_Menu::$clock = null;

if ( $fail ) { echo "ГРЕШКИ:\n- " . implode( "\n- ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
