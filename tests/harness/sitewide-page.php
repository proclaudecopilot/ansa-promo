<?php
/**
 * 1.58.0: статична страница с истинския изход на BF\Sitewide („Черен петък навсякъде“) за Playwright проверки — хедър като на сайта
 * (лента-въртележка на Elementor за десктоп и за телефон, менюто с хапчето на Сезона, мега менюто), банерът и картите на магазина,
 * продуктова страница (заглавие + висок блок с опциите) и подът (шаблонът на лентата, лепкавата лента, лентата за продукта, скриптът).
 * php tests/harness/sitewide-page.php [on|armed] > /tmp/sw.html ; node tests/harness/sitewide-check.js /tmp/sw-on.html /tmp/sw-armed.html [shots-dir]
 */
require __DIR__ . '/wcfake.php';
require __DIR__ . '/bf-boot.php';
if ( ! function_exists( 'wp_unschedule_event' ) ) { function wp_unschedule_event() { return true; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } }
use AnsaPromo\BF\Sitewide;
$state = ( $argv[1] ?? 'on' ) === 'armed' ? 'armed' : 'on'; $nohost = 'nohost' === ( $argv[1] ?? '' ); /* nohost: хедър без въртележка (като чекаута) */
$GLOBALS['home'] = 'https://www.ansa.bg'; bf_cfg( array(), '' ); Sitewide::reset();
add_filter( 'ansa_promo_bf_sitewide_state', function () use ( $state ) { return $state; } );

$menu = '<li id="menu-item-15392" class="menu-item nav-item"><a class="ekit-menu-nav-link" href="#">Продукти</a></li><li class="menu-item nav-item"><a class="ekit-menu-nav-link" href="#">Отзиви</a></li><li class="menu-item nav-item"><a class="ekit-menu-nav-link" href="#">Начало</a></li>';
$menu = Sitewide::nav_item( $menu, null ) . '<li class="menu-item nav-item amm-season"><a href="#" class="ekit-menu-nav-link amm-season-link"><span>Сезонът на ANSA</span><i>−40%</i></a></li>';
$slides = $nohost ? '<b>лого</b>' : '<div class="elementor-element elementor-widget elementor-widget-slides"><div class="swiper">Над 15 300 доволни жени</div></div>';
$cards = '';
foreach ( array( 101 => 'Daily Greens', 103 => 'Sakura SkinTonic', 201 => 'KeraTonica', 900 => 'Редовен продукт' ) as $id => $name ) { $cards .= '<a class="as-card" href="#"><span class="as-pic" data-id="' . $id . '"><img alt="" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=">' . Sitewide::card_badge_html( $id ) . '</span><b>' . $name . '</b></a>'; }

echo '<!doctype html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
	. '<style>body{margin:0;font-family:system-ui}.bar{background:#e8825f;padding:10px;min-height:45px;box-sizing:border-box;display:flex}.hdr-m{display:none}.nav{display:flex;gap:12px;list-style:none;margin:0;padding:14px;align-items:center}'
	. '.amm-season-link{display:inline-flex;gap:7px;background:#2b1e19;color:#fff;padding:7px 12px;border-radius:100px;font-size:12.5px}.amm-season-link i{font-style:normal;background:#ef6b4d;border-radius:100px;padding:3px 7px}'
	. '.amm-promo,.amm-mpromo,.as-season{display:block;background:#2b1e19;color:#fff;padding:16px;border-radius:16px;margin:10px;max-width:320px}.amm-cta,.as-cta{display:inline-block;background:#ef6b4d;padding:8px 12px;border-radius:10px}.amm-mpromo em{font-style:normal;background:#ef6b4d;padding:8px 12px;border-radius:10px}'
	. '.as-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:10px}.as-pic{display:block;position:relative;aspect-ratio:1;background:#eee;border-radius:14px;overflow:hidden}.as-pic img{width:100%;height:100%}'
	. '.pdp{max-width:560px;padding:16px}.offer{height:420px;background:#fff4ec;border-radius:16px}.fill{height:1600px}'
	. '@media(max-width:900px){.hdr-d{display:none}.hdr-m{display:block}.as-grid{grid-template-columns:1fr 1fr}}</style>';
ob_start(); Sitewide::head(); echo ob_get_clean();
echo '</head><body class="' . esc_attr( implode( ' ', Sitewide::body_class( array( 'single-product' ) ) ) ) . '">'
	. '<header id="site-header"><div class="elementor-location-header">'
	. '<div class="hdr-d"><div class="e-con bar" id="host-d">' . $slides . '</div><ul class="elementskit-navbar-nav nav">' . $menu . '</ul></div>'
	. '<div class="hdr-m"><div class="e-con bar" id="host-m">' . $slides . '</div></div>'
	. '</div></header>'
	. '<div class="amm-aside">' . Sitewide::menu_promo_html() . '<a class="amm-promo" href="#"><span class="amm-ey">Сезонът на ANSA</span><strong>До −40%</strong><span class="amm-cta">Избери →</span></a></div>'
	. '<div class="amm-mobile">' . Sitewide::menu_mpromo_html() . '<a class="amm-mpromo" href="#"><span><b>Сезонът на ANSA · до −40%</b></span><em>Започни →</em></a></div>'
	. '<div class="shop">' . Sitewide::shop_banner_html() . '<a class="as-season" href="#"><span class="as-ey">Сезонът на ANSA</span><strong>До −40%</strong><span class="as-cta">Избери →</span></a><div class="as-grid">' . $cards . '</div></div>'
	. '<div class="pdp"><div class="elementor-element elementor-widget elementor-widget-woocommerce-product-title"><h1 class="product_title">ansa™ Daily Greens</h1></div><div class="elementor-element sd" style="height:0"></div><div class="elementor-element atc" style="height:1px"></div><div class="elementor-element offer" id="offer"></div></div>'
	. '<div class="fill"></div><div id="fkcart-floating-toggler" style="position:fixed;right:20px;bottom:20px;width:45px;height:45px;background:#ef8c4f;border-radius:50%"></div>'
	. Sitewide::footer_html( 'greens', true )
	. '</body></html>';
