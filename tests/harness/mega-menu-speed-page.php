<?php
/**
 * 1.56.0: страница с мега менюто, както стои на сайта — панелът е скрит в <li> на навигацията и се отваря при посочване; снимките са от
 * http://img.test/ (Playwright ги брои), Черният петък е включен с фазите по подразбиране. MM_NOW = часът на сървъра при „кеширането“.
 * MM_NOW='2026-11-23 13:00' php tests/harness/mega-menu-speed-page.php > /tmp/mms.html ; node tests/harness/mega-menu-speed-check.js /tmp/mms.html
 */
require dirname( __DIR__ ) . '/wcstub.php';
use AnsaPromo\Mega_Menu; use AnsaPromo\Config;
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function get_post_thumbnail_id( $id ) { return 0; }
class MMProduct extends FakeProduct { public $slug = '', $visible = true, $instock = true; public $vprices = array(); function get_variation_prices( $d = false ) { return array( 'price' => $this->vprices ); } function get_slug() { return $this->slug; } function is_visible() { return $this->visible; } function is_in_stock() { return $this->instock; } }
function mmp( $id, $price, $name, $slug, $visible = true, $type = 'simple' ) { $p = new MMProduct( $id, $price, $type, 0, array(), $name ); $p->slug = $slug; $p->visible = $visible; $GLOBALS['products'][ $id ] = $p; if ( 'variable' === $type ) { $p->children = array( $id + 1 ); $v = new MMProduct( $id + 1, $price, 'variation', $id, array(), $name . ' - 1 кутия' ); $GLOBALS['products'][ $id + 1 ] = $v; } }
$GLOBALS['products'] = array();
mmp( 100, 49.50, 'ansa Sakura', 'sakura-skintonic', true, 'variable' ); mmp( 200, 43.90, 'ansa Daily Greens', 'daily-greens', true, 'variable' ); mmp( 300, 39.90, 'ansa MenoStop', 'menostop', true, 'variable' );
mmp( 400, 59.00, 'ansa Dihydroberberine', 'dihidro-berberin', true, 'variable' ); mmp( 500, 39.90, 'ansa Red Routine', 'red-routine', true, 'variable' ); mmp( 600, 34.90, 'ansa Yonibiotic', 'ansa-yonibiotic', true, 'variable' );
mmp( 700, 33.00, 'ansa ThyroZen', 'ansathyroid', true, 'variable' ); mmp( 800, 53.92, 'ansa EyeFresh пачове', 'eye-fresh', true, 'variable' ); $GLOBALS['products'][800]->vprices = array( 801 => '15.90', 802 => '27.90', 803 => '35.90' ); /* 1.43.2: „от“ = 1 кутия */
mmp( 900, 39.90, 'ansa™ – Utera+ За здрава репродуктивна система', 'utera-plus' ); mmp( 910, 4.50, 'ansa™ – CollaMask - Попиваща колагенова маска', 'collamask' );
mmp( 920, 27.90, 'ansa™ - Ашваганда + Магнезий + Вит. В6', 'ashwagandha' ); mmp( 930, 23.90, 'ansa™ – Омега 3 - Пречистено рибено масло', 'omega-3' ); mmp( 940, 19.90, 'ansa™ – D3K2 с Ретинол', 'd3k2', false );
function wc_get_products( $a = array() ) { $o = array(); foreach ( $GLOBALS['products'] as $p ) { if ( $p instanceof MMProduct && 'variation' !== $p->type ) { $o[] = clone $p; } } return $o; }
function get_page_by_path( $slug, $o = OBJECT, $t = 'post' ) { foreach ( $GLOBALS['products'] as $p ) { if ( $p instanceof MMProduct && $p->slug === $slug && 'variation' !== $p->type ) { return (object) array( 'ID' => $p->id ); } } return null; }
$store = $GLOBALS['opts']['ansa_promo_store']; $def = $store['published'];
$def['products'] = array();
foreach ( array( 'sakura' => 100, 'greens' => 200, 'meno' => 300, 'berber' => 400, 'red' => 500, 'yoni' => 600, 'thyro' => 700, 'eyefresh-hidrogelni' => 800 ) as $k => $id ) { $def['products'][] = array( 'key' => $k, 'product_id' => $id, 'enabled' => true ); }
$def = Config::normalize( $def ); $store['published'] = $def; $GLOBALS['opts']['ansa_promo_store'] = $store;
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $k, $v = null, $u = null ) { return (string) $u; } }
spl_autoload_register( function ( $c ) { if ( 0 !== strpos( $c, 'AnsaPromo\\BF\\' ) ) { return; } $f = ANSA_PROMO_PATH . 'includes/bf/class-' . strtolower( str_replace( '_', '-', substr( $c, 13 ) ) ) . '.php'; if ( is_file( $f ) ) { require_once $f; } } );
$imgs = array(); foreach ( array( 'sakura', 'greens', 'meno', 'berber', 'red', 'yoni', 'thyro', 'eyefresh-hidrogelni' ) as $k ) { $imgs[ $k ] = 'http://img.test/' . $k . '.png'; }
$GLOBALS['opts']['ansa_mega_menu_images'] = $imgs;
if ( class_exists( 'AnsaPromo\\BF\\Config' ) ) { $bf = AnsaPromo\BF\Config::normalize( array( 'enabled' => true ) ); $GLOBALS['opts'][ AnsaPromo\BF\Config::OPTION ] = array( 'draft' => null, 'published' => $bf, 'published_at' => 1, 'version' => 1, 'history' => array() ); AnsaPromo\BF\Config::reset_memory(); }
$now = getenv( 'MM_NOW' ) ?: '2026-11-23 13:00';
if ( property_exists( 'AnsaPromo\\Mega_Menu', 'clock' ) ) { Mega_Menu::$clock = ( new DateTimeImmutable( $now, new DateTimeZone( 'Europe/Sofia' ) ) )->getTimestamp(); }
ob_start(); Mega_Menu::season_css(); $css = ob_get_clean();
$nav = Mega_Menu::season_item( '<li id="menu-item-15392" class="menu-item nav-item elementskit-megamenu-has"><a href="#" class="ekit-menu-nav-link">Продукти</a><div class="elementskit-megamenu-panel">' . Mega_Menu::shortcode() . '</div></li><li id="menu-item-15391" class="menu-item nav-item"><a href="#" class="ekit-menu-nav-link">Отзиви</a></li>', null );
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{margin:0;font-family:system-ui;min-height:3000px}header{height:150px;background:#fff;border-bottom:2px solid #ef6b4d;position:relative;z-index:99}ul.elementskit-navbar-nav{list-style:none;margin:0;padding:50px 40px;display:flex;gap:20px}.elementskit-megamenu-panel{display:none;position:absolute;top:150px;left:0;right:0;background:#fffaf7}li.elementskit-megamenu-has:hover>.elementskit-megamenu-panel,li.open>.elementskit-megamenu-panel{display:block}</style>' . $css . '</head><body><header id="site-header"><ul class="elementskit-navbar-nav">' . $nav . '</ul></header><main><p>страница</p></main></body></html>';
