<?php
/**
 * 1.42.3: статична страница с мега менюто (13 продукта като на живо) за Playwright проверка на страниците без скрол.
 * php tests/harness/mega-menu-page.php > /tmp/mm.html ; node tests/harness/mega-menu-check.js /tmp/mm.html
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
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><style>body{margin:0;font-family:system-ui}.hdr{height:70px;background:#fff;border-bottom:2px solid #ef6b4d}.panel{position:fixed;top:70px;left:0;right:0;background:#fffaf7}</style></head><body><div class="hdr"></div><div class="panel">' . Mega_Menu::shortcode() . '</div></body></html>';
