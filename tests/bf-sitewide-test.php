<?php
/**
 * Офлайн тест на 1.58.0 — „Черен петък навсякъде“ (BF\Sitewide): кога е включено (по датите и force), кои продукти участват, хапчето в
 * менюто, парчетата за мега менюто и магазина, лентата/лепкавата лента/лентата за продуктовата страница, кронът за кеша.
 * php tests/bf-sitewide-test.php → „OK n проверки“ или списък с грешки (exit 1).
 * php tests/bf-sitewide-test.php --dump → JSON с готовия HTML/CSS (за tests/harness/sitewide-page.php и за проверка върху живата страница).
 */
require __DIR__ . '/harness/wcfake.php';
set_error_handler( function ( $no, $msg, $file, $line ) { if ( ! ( error_reporting() & $no ) ) { return false; } throw new ErrorException( $msg, 0, $no, $file, $line ); } );
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $t = is_scalar( $info ) ? (string) $info : (string) json_encode( $info, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ); $fail[] = $name . ( '' !== $t ? ' — ' . mb_substr( $t, 0, 500 ) : '' ); } };
require __DIR__ . '/harness/bf-boot.php';
if ( ! function_exists( 'wp_unschedule_event' ) ) { function wp_unschedule_event( $ts, $hook ) { $GLOBALS['unscheduled'][] = $hook; return true; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
use AnsaPromo\BF\Config; use AnsaPromo\BF\Sitewide; use AnsaPromo\Mega_Menu;
$tz = new DateTimeZone( 'Europe/Sofia' ); $ts = function ( $s ) use ( $tz ) { return ( new DateTimeImmutable( $s, $tz ) )->getTimestamp(); };
$purges = function () { return count( array_keys( (array) ( $GLOBALS['hook_log'] ?? array() ), 'ansa_promo_bf_sitewide_purged', true ) ); };

/* ── готовият HTML/CSS за браузъра ── */
if ( in_array( '--dump', $argv, true ) ) {
	$GLOBALS['home'] = 'https://www.ansa.bg'; bf_cfg( array(), '' ); Sitewide::reset();
	add_filter( 'ansa_promo_bf_sitewide_state', function () { return 'on'; } );
	echo json_encode( array(
		'css' => Sitewide::css(), 'footer' => Sitewide::footer_html( '', true ), 'footer_pdp' => Sitewide::footer_html( 'greens', true ), 'pill' => Sitewide::pill_html(),
		'promo' => Sitewide::menu_promo_html(), 'mpromo' => Sitewide::menu_mpromo_html(), 'banner' => Sitewide::shop_banner_html(), 'badge' => Sitewide::card_badge_html( 101 ),
		'window' => Sitewide::window(),
	), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	exit( 0 );
}

/* ── кога е включено: по датите (25.11 07:00 → 30.11 10:00 по часа на сайта) ── */
$c = bf_cfg( array(), '' ); Sitewide::reset();
$ok( 'три дни преди публичния старт → нищо', '' === Sitewide::state( $c, $ts( '2026-11-22 12:00' ) ) );
$ok( '36 ч преди старта (23.11 19:00) → заредено', 'armed' === Sitewide::state( $c, $ts( '2026-11-23 19:00' ) ) && '' === Sitewide::state( $c, $ts( '2026-11-23 18:59' ) ) );
$ok( 'в паузата на 24.11 → заредено (в страницата, но скрито)', 'armed' === Sitewide::state( $c, $ts( '2026-11-24 12:00' ) ) );
$ok( 'точно в 25.11 07:00 → включено', 'on' === Sitewide::state( $c, $ts( '2026-11-25 07:00' ) ) && 'armed' === Sitewide::state( $c, $ts( '2026-11-25 06:59' ) ) );
$ok( '30.11 09:59 → още включено; 30.11 10:00 → нищо', 'on' === Sitewide::state( $c, $ts( '2026-11-30 09:59' ) ) && '' === Sitewide::state( $c, $ts( '2026-11-30 10:00' ) ) );
$ok( 'в ранния достъп (22.11) → нищо: записаните имат линк, сайтът още е на Сезона', '' === Sitewide::state( $c, $ts( '2026-11-22 12:00' ) ) );
$off = $c; $off['enabled'] = false;
$ok( 'изключено промо → нищо, и в публичната фаза', '' === Sitewide::state( $off, $ts( '2026-11-26 12:00' ) ) );
$f = $c; $f['force'] = 'public'; $ok( 'force=public → включено независимо от датата', 'on' === Sitewide::state( $f, $ts( '2026-11-01 12:00' ) ) );
$f['force'] = 'over'; $ok( 'force=over → нищо, и в публичната фаза', '' === Sitewide::state( $f, $ts( '2026-11-26 12:00' ) ) );
$f['force'] = 'early'; $ok( 'force=early → нищо (без „заредено“ при подменена фаза)', '' === Sitewide::state( $f, $ts( '2026-11-24 12:00' ) ) );
$w = Sitewide::window( $c ); $ok( 'прозорецът = [25.11 07:00, 30.11 10:00]', $w === array( $ts( '2026-11-25 07:00' ), $ts( '2026-11-30 10:00' ) ), $w );
$f['force'] = 'public'; $w = Sitewide::window( $f ); $ok( 'force=public → началото е 0 (вече тече), краят остава', 0 === $w[0] && $ts( '2026-11-30 10:00' ) === $w[1], $w );

/* ── кронът за кеша: 36 ч преди старта, стартът, краят ── */
$ok( 'следващата граница на 20.11 = 23.11 19:00 (зареждането)', $ts( '2026-11-23 19:00' ) === Sitewide::next_flip( $c, $ts( '2026-11-20 12:00' ) ) );
$ok( 'на 24.11 → 25.11 07:00 (стартът)', $ts( '2026-11-25 07:00' ) === Sitewide::next_flip( $c, $ts( '2026-11-24 12:00' ) ) );
$ok( 'на 26.11 → 30.11 10:00 (краят)', $ts( '2026-11-30 10:00' ) === Sitewide::next_flip( $c, $ts( '2026-11-26 12:00' ) ) );
$ok( 'след края → няма', 0 === Sitewide::next_flip( $c, $ts( '2026-12-01 12:00' ) ) );
$ok( 'изключено промо или подменена фаза → няма', 0 === Sitewide::next_flip( $off, $ts( '2026-11-24 12:00' ) ) && 0 === Sitewide::next_flip( $f, $ts( '2026-11-24 12:00' ) ) );

/* ── включено (публичната фаза): продуктите, хапчето, парчетата ── */
$c = bf_cfg( array(), 'public' ); Sitewide::reset();
$ok( 'включено', 'on' === Sitewide::state() && Sitewide::on() );
$ok( 'участват купуваните продукти; „само подарък“ (KeraTonica) не', array( 101 => 'greens', 102 => 'berber', 103 => 'sakura' ) === Sitewide::buy_ids(), Sitewide::buy_ids() );
$ok( 'ключът по продукт', 'greens' === Sitewide::key_for( 101 ) && '' === Sitewide::key_for( 201 ) && '' === Sitewide::key_for( 900 ) );
$ok( 'адресът: страницата на Черния петък; с продукт → ?p=ключ', 'https://www.ansa.bg/cheren-petak-2026/' === Sitewide::url() && 'https://www.ansa.bg/cheren-petak-2026/?p=greens' === Sitewide::url( 'greens' ), Sitewide::url( 'greens' ) );
$ok( 'ключът в адреса се чисти', 'https://www.ansa.bg/cheren-petak-2026/?p=greens' === Sitewide::url( 'Gre"ens<' ), Sitewide::url( 'Gre"ens<' ) );
$ok( 'body клас abf-on', in_array( 'abf-on', Sitewide::body_class( array( 'home' ) ), true ) );

$menu = '<li id="menu-item-15392" class="menu-item"><a>Продукти</a></li><li id="menu-item-15391" class="menu-item"><a>Отзиви</a></li>';
$out = Sitewide::nav_item( $menu, null );
$ok( 'хапчето е след „Продукти“ и преди „Отзиви“', false !== strpos( $out, 'abf-li' ) && strpos( $out, 'menu-item-15392' ) < strpos( $out, 'abf-li' ) && strpos( $out, 'abf-li' ) < strpos( $out, 'menu-item-15391' ), $out );
$ok( 'хапчето води към Черния петък и казва „Черен петък 1 + 1“', false !== strpos( $out, 'href="https://www.ansa.bg/cheren-petak-2026/"' ) && false !== strpos( $out, 'Черен петък' ) && false !== strpos( $out, '1 + 1' ) );
$ok( 'хапчето ползва разположението на хапчето на Сезона (amm-season amm-season-link)', false !== strpos( $out, 'amm-season abf-li' ) && false !== strpos( $out, 'amm-season-link abf-pill' ) );
$ok( 'не се слага втори път', $out === Sitewide::nav_item( $out, null ) );
$ok( 'друго меню (без „Продукти“ #15392) остава както е', '<li>Футър</li>' === Sitewide::nav_item( '<li>Футър</li>', null ) );

$promo = Sitewide::menu_promo_html(); $mp = Sitewide::menu_mpromo_html(); $ban = Sitewide::shop_banner_html();
$ok( 'картата в мега менюто: класовете на менюто + abf-promo, брояч', false !== strpos( $promo, 'class="amm-promo abf-promo abf-only"' ) && false !== strpos( $promo, 'data-abf-cd="words"' ) && false !== strpos( $promo, 'Плащаш 1, получаваш 2' ) );
$ok( 'картата в мобилното меню', false !== strpos( $mp, 'class="amm-mpromo abf-promo abf-only"' ) && false !== strpos( $mp, 'data-abf-cd="mini"' ) );
$ok( 'банерът в магазина', false !== strpos( $ban, 'class="as-season abf-shop abf-only"' ) && false !== strpos( $ban, 'data-abf-cd="words"' ) && false !== strpos( $ban, 'подаръкът е на 0 €' ) );
$ok( 'етикетът върху картата: само за участващ продукт', false !== strpos( Sitewide::card_badge_html( 102 ), 'abf-cardbadge' ) && '' === Sitewide::card_badge_html( 201 ) && '' === Sitewide::card_badge_html( 900 ) );
$ok( 'Mega_Menu::bf() подава парчетата и не гърми при непознат метод', $promo === Mega_Menu::bf( 'menu_promo_html' ) && Sitewide::card_badge_html( 101 ) === Mega_Menu::bf( 'card_badge_html', 101 ) && '' === Mega_Menu::bf( 'nyama_takav' ) && 'on' === Mega_Menu::bf( 'state' ) );

$foot = Sitewide::footer_html( '', true );
$ok( 'долу: шаблонът на лентата, лепкавата лента, скриптът', false !== strpos( $foot, '<template id="abf-bar-t">' ) && false !== strpos( $foot, 'class="abf abf-stick abf-only"' ) && false !== strpos( $foot, '<script id="abf-js" data-loading-method="immediate">' ) );
$ok( 'без продуктова страница няма лента за продукта', false === strpos( $foot, 'abf-pdp' ) || false === strpos( $foot, 'class="abf abf-pdp' ) );
$ok( 'скриптът носи началото (0 при force) и края', false !== strpos( $foot, 'var S=0,E=' . $ts( '2026-11-30 10:00' ) . ',' ), substr( $foot, strpos( $foot, 'var S=' ), 40 ) );
$fp = Sitewide::footer_html( 'greens', false );
$ok( 'продуктова страница: лентата води към ?p=greens; без лепкава лента, когато не е позволена', false !== strpos( $fp, 'class="abf abf-pdp abf-only" href="https://www.ansa.bg/cheren-petak-2026/?p=greens"' ) && false === strpos( $fp, 'class="abf abf-stick' ), $fp );
$all = Sitewide::pill_html() . $promo . $mp . $ban . $foot . $fp . Sitewide::card_badge_html( 101 );
$ok( 'навсякъде „Черен петък“ на кирилица (без „Black Friday“)', false === stripos( $all, 'black friday' ) && false === stripos( Sitewide::css(), 'black friday' ) );
$ok( 'без utm_ в линковете (вътрешен линк не бива да презаписва произхода на поръчката)', false === strpos( $all, 'utm_' ) );
$css = Sitewide::css();
$ok( 'CSS: без body.abf-on нищо от Черния петък не се вижда', 0 === strpos( $css, 'body:not(.abf-on) .abf-only{display:none!important}' ) );
$ok( 'CSS: Сезонът се скрива в менюто, мега менюто, мобилното меню и магазина', false !== strpos( $css, 'body.abf-on li.amm-season:not(.abf-li),body.abf-on a.amm-promo:not(.abf-promo),body.abf-on a.amm-mpromo:not(.abf-promo),body.abf-on a.as-season:not(.abf-shop){display:none!important}' ) );
$ok( 'CSS: без </style> и без незатворени скоби', false === stripos( $css, '</style' ) && substr_count( $css, '{' ) === substr_count( $css, '}' ), substr_count( $css, '{' ) . '/' . substr_count( $css, '}' ) );

/* ── 1.58.1: страниците за плащане — нищо от Черния петък (лентата се качваше върху хедъра на чекаута) ── */
$_SERVER['REQUEST_URI'] = '/checkout/?x=1';
$ok( 'чекаут: тихо — body без abf-on', Sitewide::quiet() && ! in_array( 'abf-on', Sitewide::body_class( array() ), true ) );
ob_start(); Sitewide::footer(); $o = ob_get_clean(); $ok( 'чекаут: подът не печата лента, лепкава лента и скрипт', '' === $o, $o );
ob_start(); Sitewide::head(); $o = ob_get_clean(); $ok( 'чекаут: CSS-ът остава (той крие .abf-only без класа на body)', false !== strpos( $o, 'body:not(.abf-on) .abf-only{display:none!important}' ) );
$_SERVER['REQUEST_URI'] = '/checkout/order-received/123/'; $ok( '„благодарим“ също е тихо', Sitewide::quiet() );
$_SERVER['REQUEST_URI'] = '/shop/'; Sitewide::reset();
$ok( 'магазинът не е тих', ! Sitewide::quiet() && in_array( 'abf-on', Sitewide::body_class( array() ), true ) );
ob_start(); Sitewide::footer(); $o = ob_get_clean(); $ok( 'магазинът: подът печата', false !== strpos( $o, 'abf-bar-t' ) );
$ok( 'скриптът слага лентата само на мястото на въртележката (без резервно място в хедъра)', false === strpos( Sitewide::footer_html(), 'abf-barfree' ) && false === strpos( Sitewide::footer_html(), '#site-header' ) );
unset( $_SERVER['REQUEST_URI'] );

/* ── „заредено“: в страницата, но без класа на body ── */
$c = bf_cfg( array(), '' ); Sitewide::reset(); add_filter( 'ansa_promo_bf_now', function () use ( $ts ) { return $ts( '2026-11-24 12:00' ); } );
$ok( 'заредено: парчетата са в страницата', 'armed' === Sitewide::state() && '' !== Sitewide::menu_promo_html() && false !== strpos( Sitewide::nav_item( $menu, null ), 'abf-li' ) );
$ok( 'заредено: body няма abf-on (скриптът го слага в началото)', ! in_array( 'abf-on', Sitewide::body_class( array() ), true ) );
$ok( 'заредено: скриптът носи истинското начало', false !== strpos( Sitewide::footer_html(), 'var S=' . $ts( '2026-11-25 07:00' ) . ',E=' . $ts( '2026-11-30 10:00' ) . ',' ) );

/* ── изключено: нищо никъде ── */
$GLOBALS['hooks']['ansa_promo_bf_now'] = array(); add_filter( 'ansa_promo_bf_now', function () use ( $ts ) { return $ts( '2026-12-02 12:00' ); } );
Sitewide::reset();
$ok( 'след края: нищо в менюто, магазина и body', '' === Sitewide::state() && $menu === Sitewide::nav_item( $menu, null ) && '' === Sitewide::menu_promo_html() && '' === Sitewide::menu_mpromo_html() && '' === Sitewide::shop_banner_html() && '' === Sitewide::card_badge_html( 101 ) && array( 'home' ) === Sitewide::body_class( array( 'home' ) ) );
ob_start(); Sitewide::head(); Sitewide::footer(); $o = ob_get_clean(); $ok( 'след края: head и footer не печатат нищо', '' === $o, $o );
$GLOBALS['hooks']['ansa_promo_bf_now'] = array();

/* ── публикуване: кешът на страниците се чисти само при промяна на това, което лентата показва ── */
bf_cfg( array(), 'public' ); Sitewide::reset(); Sitewide::on_published(); $p0 = $purges();
Config::save_draft( array_merge( Config::get_published(), array( 'name' => 'Черен петък 2026 (ново име)' ) ) ); Config::publish(); bf_prods(); Sitewide::reset(); Sitewide::on_published();
$ok( 'публикуване без промяна по лентата → кешът не се чисти', $purges() === $p0, $purges() - $p0 );
$d = Config::get_published(); $d['phases']['public_end'] = '2026-12-01 10:00'; Config::save_draft( $d ); Config::publish(); bf_prods(); Sitewide::reset(); Sitewide::on_published();
$ok( 'сменен край → кешът се чисти веднъж', $purges() === $p0 + 1, $purges() - $p0 );
Sitewide::on_flip(); $ok( 'кронът на границата чисти кеша', $purges() === $p0 + 2 );

if ( $fail ) { echo "ГРЕШКИ:\n- " . implode( "\n- ", $fail ) . "\n"; exit( 1 ); }
echo 'OK ' . $n . " проверки\n";
