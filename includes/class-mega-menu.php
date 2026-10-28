<?php
/**
 * 1.40.0 (човекът: „имаш ли предложение за ново, по-готино меню за сайта?“ → одобрен мокъп, после качен като mu-plugin на 28.10.2026, тук е версионираният вариант):
 * мега менюто „Продукти“ на сайта — шорткод [ansa_mega_menu] в панела на ElementsKit (съдържание #15397, менюто „mega-sitemenu“):
 *  - вляво нуждите (Храносмилане, Кожа и красота, Менопауза, Тегло, Сън и стрес, Щитовидна жлеза, Интимно здраве, Общо здраве, Нови), посочване филтрира;
 *  - в средата продуктите на промото (Frontend::products, същият списък като в промото) + останалите видими и налични продукти от магазина, със снимка, едно изречение и цена „от“;
 *  - вдясно Сезонът на ANSA (процентът на най-голямата кутия), „Не знаеш откъде да започнеш?“ (здравословният помощник) и доверие;
 *  - на телефон (≤1024px) групи по нужда с продукти и цени; на десктоп страницата отзад се замъглява (.amm-backdrop).
 *  - хапчето „Сезонът на ANSA −40%“ в навигацията (wp_nav_menu_items, след „Продукти“, преди елемент 15391), докато кампанията е активна и срокът ѝ не е минал.
 * Снимка за менюто по продукт: option ansa_mega_menu_images [ключ → attachment id или URL] (сменя се без код).
 *
 * @package AnsaPromo
 * @since 1.40.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Mega_Menu {
	const VER = ANSA_PROMO_VER;
	/** Нуждите: ключ → [етикет, емоджи, продукти (ключове от ansa-promo)]. Продукт без ред → „Нови“. */
	const NEEDS = array(
		'gut'    => array( 'Храносмилане', '🥬', array( 'greens', 'yoni' ) ),
		'skin'   => array( 'Кожа и красота', '🌸', array( 'sakura', 'eyefresh-hidrogelni', 'collamask' ) ),
		'meno'   => array( 'Менопауза', '🌷', array( 'meno', 'utera-za' ) ),
		'weight' => array( 'Тегло и метаболизъм', '🫐', array( 'berber', 'greens' ) ),
		'sleep'  => array( 'Сън и стрес', '🌙', array( 'red', 'ashwagandha' ) ),
		'thyro'  => array( 'Щитовидна жлеза', '🦋', array( 'thyro' ) ),
		'intim'  => array( 'Интимно здраве', '💗', array( 'yoni', 'utera-za' ) ),
		'general' => array( 'Общо здраве', '💛', array( 'omega-3', 'd3k2', 'no-ache' ) ),
		'new'    => array( 'Нови', '✨', array( 'utera-za', 'eyefresh-hidrogelni' ) ),
	);
	const SHORT = array( 'utera-za' => 'Utera+', 'eyefresh-hidrogelni' => 'EyeFresh пачове', 'ashwagandha' => 'Ashwagandha + Mg + B6', 'omega-3' => 'Omega-3', 'collamask' => 'CollaMask', 'd3k2' => 'D3K2', 'no-ache' => 'No Ache' );
	/** Кратко описание за продуктите извън промото (иначе първото изречение от краткото описание в магазина). */
	const DS = array( 'ashwagandha' => 'Спокойствие, сън и по-малко стрес', 'omega-3' => 'Пречистено рибено масло за сърце и мозък', 'collamask' => 'Попиваща колагенова маска за лице', 'd3k2' => 'Настроение, имунитет и кости', 'no-ache' => 'Подкрепа за стави и мускули' );
	const LINKS = array( 'shop' => '/shop/', 'promo' => '/sezonut-na-ansa/', 'quiz' => '/health-quiz-by-ansa/', 'about' => '/za-nas/', 'reviews' => '/review/' );

	/** Регистрация: шорткодът, хапчето в навигацията и стилът му. Ако старият mu-plugin (Ansa_Mega_Menu, 28.10.2026) още е на сайта, той води — без двойно регистриране. */
	public static function register() {
		if ( class_exists( 'Ansa_Mega_Menu', false ) || shortcode_exists( 'ansa_mega_menu' ) ) { return; }
		add_shortcode( 'ansa_mega_menu', array( __CLASS__, 'shortcode' ) );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'season_item' ), 20, 2 );
		add_action( 'wp_head', array( __CLASS__, 'season_css' ), 99 );
	}
	/** Хапчето „Сезонът на ANSA −40%“ стои, докато кампанията е активна и срокът ѝ (Config deadline, 31.12.2026 23:59 по часа на сайта) не е минал — после изчезва само. */
	public static function season_on() {
		try { $cfg = Config::get_published(); if ( ! Config::is_live( $cfg ) ) { return false; } $until = Config::deadline_ts( $cfg ); return ! $until || time() < $until; } catch ( \Throwable $e ) { return false; }
	}
	/** Процентът на най-голямата кутия от ansa-promo (иначе 40). */
	public static function season_pct() {
		try { if ( true ) { $b = Config::largest_box( Config::get_published() ); if ( $b && (float) $b['pct'] > 0 ) { return (int) round( (float) $b['pct'] ); } } } catch ( \Throwable $e ) {}
		return 40;
	}
	/** Вмъква хапчето след „Продукти“ (преди „Отзиви“, елемент 15391) само в главното меню. */
	public static function season_item( $items, $args ) {
		if ( ! self::season_on() || false === strpos( $items, 'menu-item-15392' ) || false !== strpos( $items, 'amm-season' ) ) { return $items; }
		$li = '<li class="menu-item nav-item amm-season"><a href="' . esc_url( Cart::promo_url() ) . '" class="ekit-menu-nav-link amm-season-link"><span>Сезонът<span class="amm-sx"> на ANSA</span></span><i>−' . self::season_pct() . '%</i></a></li>';
		$pos = strpos( $items, '<li id="menu-item-15391"' );
		return false === $pos ? $items . $li : substr( $items, 0, $pos ) . $li . substr( $items, $pos );
	}
	public static function season_css() {
		if ( ! self::season_on() ) { return; }
		echo '<style id="amm-season-css">.elementskit-navbar-nav li.amm-season{display:inline-flex;align-items:center}.elementskit-navbar-nav li.amm-season>a.amm-season-link{display:inline-flex!important;align-items:center;gap:7px;background:#2b1e19;color:#fff!important;padding:7px 12px!important;border-radius:100px;font-size:12.5px;font-weight:800;line-height:1!important;margin:0 4px;white-space:nowrap;height:auto!important}.elementskit-navbar-nav li.amm-season>a.amm-season-link:hover{background:#3d2a22;color:#fff!important}.elementskit-navbar-nav li.amm-season>a.amm-season-link i{font-style:normal;background:#ef6b4d;color:#fff;border-radius:100px;padding:3px 7px;font-size:11px;font-weight:900}@media (min-width:1025px){.elementor-widget-ekit-nav-menu.elementor-widget__width-initial{width:auto!important;max-width:none!important}.ekit-wid-con .elementskit-navbar-nav{flex-wrap:nowrap!important;white-space:nowrap;padding-left:36px}.ekit-wid-con .elementskit-navbar-nav>li{flex:0 0 auto}.elementor-widget-ekit-nav-menu,.elementor-widget-ekit-nav-menu .elementor-widget-container,.elementskit-menu-container{overflow:visible!important}.elementskit-navbar-nav>li>a.ekit-menu-nav-link{padding-left:10px!important;padding-right:10px!important}.elementskit-navbar-nav>li:first-child>a.ekit-menu-nav-link{padding-left:0!important}.elementskit-navbar-nav li.amm-season>a.amm-season-link{margin:0 0 0 2px}}
@media (min-width:1025px){#site-header .elementor-widget-ekit-nav-menu{width:auto!important;max-width:100%!important}}
@media (min-width:1025px) and (max-width:1600px){#site-header .elementor-widget-ekit-nav-menu>.elementor-widget-container{padding-left:0!important;padding-right:0!important}.ekit-wid-con .elementskit-navbar-nav{padding-left:0}.elementskit-navbar-nav>li>a.ekit-menu-nav-link{padding-left:6px!important;padding-right:6px!important;font-size:13px!important}.elementskit-navbar-nav li.amm-season>a.amm-season-link{font-size:11.5px!important;padding:6px 10px!important;gap:5px;margin:0 2px}.elementskit-navbar-nav li.amm-season>a.amm-season-link i{font-size:10px;padding:3px 6px}}
@media (min-width:1025px) and (max-width:1300px){.amm-sx{display:none}}
@media (min-width:1025px) and (max-width:1200px){.elementskit-navbar-nav>li>a.ekit-menu-nav-link{padding-left:5px!important;padding-right:5px!important;font-size:12px!important}.elementskit-navbar-nav li.amm-season>a.amm-season-link{font-size:11px!important;padding:5px 9px!important}}
@media (max-width:1024px){.elementskit-navbar-nav li.amm-season{display:none}}</style>';
	}

	/** Продуктите: от ansa-promo (същият списък като в промото), иначе WooCommerce по slug. */
	public static function products() {
		$out = array();
		if ( class_exists( __NAMESPACE__ . '\\Frontend' ) ) {
			try {
				foreach ( (array) Frontend::products( Config::get_published() ) as $p ) {
					$id = (int) ( $p['id'] ?? 0 ); if ( ! $id ) { continue; }
					$wc = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
					$price = isset( $p['price'] ) ? (float) $p['price'] : ( $wc ? (float) $wc->get_price() : 0 );
					$out[ (string) $p['key'] ] = array(
						'key' => (string) $p['key'], 'id' => $id, 'name' => (string) ( $p['name'] ?? ( $wc ? $wc->get_name() : '' ) ), 'ds' => (string) ( $p['ds'] ?? '' ),
						'img' => self::image( (string) $p['key'], $id, (string) ( $p['img'] ?? '' ) ), 'price' => $price, 'url' => get_permalink( $id ) ?: self::LINKS['shop'],
					);
				}
			} catch ( \Throwable $e ) { $out = array(); }
		}
		/* 1.2.0 (човекът: „липсват продукти“): и останалите продукти от магазина — видими в каталога, налични, с цена; ваучерите и скритите сетове не */
		if ( function_exists( 'wc_get_products' ) ) {
			$have = array(); foreach ( $out as $p ) { $have[ (int) $p['id'] ] = 1; }
			try {
				foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => 60, 'orderby' => 'menu_order', 'order' => 'ASC', 'type' => array( 'simple', 'variable' ) ) ) as $wc ) {
					$id = (int) $wc->get_id(); if ( isset( $have[ $id ] ) ) { continue; }
					if ( ! $wc->is_visible() || ! $wc->is_in_stock() || (float) $wc->get_price() <= 0 ) { continue; }
					$t = mb_strtolower( $wc->get_name() ); if ( false !== mb_strpos( $t, 'ваучер' ) || false !== mb_strpos( $t, 'voucher' ) || false !== mb_strpos( $t, 'персонал' ) ) { continue; }
					$key = (string) $wc->get_slug(); $img = wp_get_attachment_image_url( (int) $wc->get_image_id(), 'medium_large' );
					$out[ $key ] = array( 'key' => $key, 'id' => $id, 'name' => self::short_name( $wc->get_name() ), 'ds' => self::DS[ $key ] ?? self::first_sentence( wp_strip_all_tags( $wc->get_short_description() ) ), 'img' => $img ?: '', 'price' => (float) $wc->get_price(), 'url' => get_permalink( $id ) ?: self::LINKS['shop'] );
					$have[ $id ] = 1;
				}
			} catch ( \Throwable $e ) { /* магазинът не отговаря — само продуктите от промото */ }
		}
		return $out;
	}

	/** 1.1.0: снимката за менюто: option ansa_mega_menu_images [ключ → attachment id или URL] (сменя се без код) → основната снимка на продукта в магазина (medium_large) → снимката от промото. */
	public static function image( $key, $id, $fallback = '' ) {
		$ov = get_option( 'ansa_mega_menu_images', array() ); $ov = is_array( $ov ) ? $ov : array();
		$v = $ov[ $key ] ?? '';
		if ( '' !== $v ) { if ( ctype_digit( (string) $v ) ) { $u = wp_get_attachment_image_url( (int) $v, 'medium_large' ); if ( $u ) { return $u; } } elseif ( preg_match( '#^https?://#', (string) $v ) ) { return (string) $v; } }
		$tid = (int) get_post_thumbnail_id( $id ); if ( $tid ) { $u = wp_get_attachment_image_url( $tid, 'medium_large' ); if ( $u ) { return $u; } }
		return $fallback;
	}
	private static function short_name( $n ) { $n = preg_replace( '/^\s*ansa(™|\x{2122})?\s*[-–—]\s*/u', '', (string) $n ); $n = preg_split( '/\s+[-–—|]\s+/u', $n )[0]; return trim( $n ); }
	private static function first_sentence( $t ) { $t = trim( preg_replace( '/\s+/', ' ', (string) $t ) ); $parts = preg_split( '/(?<=[.!?])\s+/u', $t ); $f = trim( (string) ( $parts[0] ?? $t ), " .\n" ); return mb_strlen( $f ) > 70 ? mb_substr( $f, 0, 68 ) . '…' : $f; }
	private static function need_of( $key ) { foreach ( self::NEEDS as $k => $n ) { if ( 'new' !== $k && in_array( $key, $n[2], true ) ) { return $n[0]; } } return 'Нови'; }
	private static function needs_of( $key ) { $o = array(); foreach ( self::NEEDS as $k => $n ) { if ( in_array( $key, $n[2], true ) ) { $o[] = $k; } } return $o ?: array( 'new' ); }
	private static function money( $v ) { return '€ ' . number_format( (float) $v, 2, ',', ' ' ); }
	private static function plural( $n ) { return 1 === (int) $n ? '1 продукт' : (int) $n . ' продукта'; }

	public static function shortcode() {
		$P = self::products(); if ( ! $P ) { return ''; }
		$h = esc_url( home_url( '/' ) ); $L = array(); foreach ( self::LINKS as $k => $v ) { $L[ $k ] = esc_url( home_url( $v ) ); }
		$counts = array( 'all' => count( $P ) ); foreach ( self::NEEDS as $k => $n ) { $counts[ $k ] = count( array_filter( $n[2], function ( $x ) use ( $P ) { return isset( $P[ $x ] ); } ) ); }
		$needs = '<button class="amm-need on" data-n="all" type="button"><span class="amm-ic">🌿</span><span>Всички продукти<small>' . self::plural( $counts['all'] ) . '</small></span><i>›</i></button>';
		foreach ( self::NEEDS as $k => $n ) { if ( ! $counts[ $k ] ) { continue; } $needs .= '<button class="amm-need" data-n="' . esc_attr( $k ) . '" type="button"><span class="amm-ic">' . $n[1] . '</span><span>' . esc_html( $n[0] ) . '<small>' . self::plural( $counts[ $k ] ) . '</small></span><i>›</i></button>'; }
		$cards = '';
		foreach ( $P as $k => $p ) {
			$name = self::SHORT[ $k ] ?? $p['name']; $new = in_array( 'new', self::needs_of( $k ), true ) ? '<i class="amm-new">ново</i>' : '';
			$cards .= '<a class="amm-card" data-c="' . esc_attr( implode( ' ', self::needs_of( $k ) ) ) . '" href="' . esc_url( $p['url'] ) . '"><span class="amm-pic">' . $new . ( $p['img'] ? '<img src="' . esc_url( $p['img'] ) . '" alt="' . esc_attr( $name ) . '" loading="lazy">' : '' ) . '</span><span class="amm-cat">' . esc_html( self::need_of( $k ) ) . '</span><b>' . esc_html( $name ) . '</b><small>' . esc_html( $p['ds'] ) . '</small><span class="amm-price">от ' . esc_html( self::money( $p['price'] ) ) . '</span></a>';
		}
		/* телефон: групи по нужда */
		$acc = '';
		$first = true;
		foreach ( self::NEEDS as $k => $n ) {
			if ( 'new' === $k || ! $counts[ $k ] ) { continue; }
			$rows = '';
			foreach ( $n[2] as $x ) { if ( ! isset( $P[ $x ] ) ) { continue; } $p = $P[ $x ]; $rows .= '<a class="amm-row" href="' . esc_url( $p['url'] ) . '">' . ( $p['img'] ? '<img src="' . esc_url( $p['img'] ) . '" alt="" loading="lazy">' : '<span></span>' ) . '<span><b>' . esc_html( self::SHORT[ $x ] ?? $p['name'] ) . '</b><small>' . esc_html( $p['ds'] ) . '</small></span><em>' . esc_html( self::money( $p['price'] ) ) . '</em></a>'; }
			$acc .= '<details><summary><span class="amm-ic">' . $n[1] . '</span>' . esc_html( $n[0] ) . '<small>' . (int) $counts[ $k ] . '</small></summary><div class="amm-acc-rows">' . $rows . '</div></details>';
			$first = false;
		}
		$out = '<div class="amm" data-amm="' . self::VER . '">'
			. '<div class="amm-needs"><div class="amm-t">По нужда</div>' . $needs . '</div>'
			. '<div class="amm-grid"><div class="amm-t amm-gt"><b class="amm-title">Всички продукти</b><span class="amm-cnt">' . self::plural( $counts['all'] ) . '</span></div><div class="amm-cards all' . ( count( $P ) > 10 ? ' six' : '' ) . '">' . $cards . '</div>'
			. '<div class="amm-foot"><a href="' . $L['shop'] . '">Всички продукти</a><a href="' . $L['quiz'] . '">Как да избера</a><a href="' . $L['reviews'] . '">Отзиви</a><a href="' . $L['about'] . '">За ansa</a></div></div>'
			. '<div class="amm-aside">'
			. '<a class="amm-promo" href="' . esc_url( Cart::promo_url() ) . '"><span class="amm-ey">Сезонът на ANSA · нашият Черен петък</span><strong>До −40% + козметичен сет за 1 €</strong><span class="amm-p">Отстъпката расте с всяка опаковка. 5+ опаковки = −40%, сетът и книгата с рецепти.</span><span class="amm-cta">Избери продуктите →</span></a>'
			. '<a class="amm-quiz" href="' . $L['quiz'] . '"><span class="amm-qic">🧭</span><b>Не знаеш откъде да започнеш?</b><small>Няколко въпроса, 1 минута, и ти казваме кой продукт е за теб.</small><span>Започни теста →</span></a>'
			. '<ul class="amm-trust"><li>💗 15 300+ доволни жени</li><li>🌿 Направено в България</li><li>🚚 Доставка за 1–2 работни дни</li><li>💳 С карта или наложен платеж</li></ul>'
			. '</div>'
			. '<div class="amm-mobile">'
			. '<form class="amm-msearch" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '"><input type="search" name="s" placeholder="Търси продукт…" autocomplete="off" aria-label="Търси продукт"><input type="hidden" name="post_type" value="product"><button type="submit" aria-label="Търси">🔍</button></form>'
			. ( self::season_on() ? '<a class="amm-mpromo" href="' . esc_url( Cart::promo_url() ) . '"><span><b>Сезонът на ANSA · до −' . self::season_pct() . '%</b><small>+ козметичен сет за 1 € при 5+ опаковки</small></span><em>Започни →</em></a>' : '' )
			. '<nav class="amm-mnav" aria-label="Меню"><a href="' . esc_url( home_url( '/' ) ) . '">Начало</a><a href="' . $L['reviews'] . '">Отзиви</a><a href="' . esc_url( home_url( '/ansa-blog/' ) ) . '">Полезно</a><a href="' . $L['about'] . '">За ansa</a><a href="' . esc_url( home_url( '/my-account/' ) ) . '">Вход</a></nav>'
			. '<div class="amm-mt"><span>Продукти по нужда</span><a href="' . $L['shop'] . '">Всички →</a></div>'
			. '<div class="amm-acc">' . $acc . '</div>'
			. '<a class="amm-mall" href="' . $L['shop'] . '">Виж всички продукти →</a>'
			. '<a class="amm-mquiz" href="' . $L['quiz'] . '"><span class="amm-qic">🧭</span><span><b>Не знаеш откъде да започнеш?</b><small>Тест за 1 минута — казваме ти кой продукт е за теб.</small></span><i>›</i></a>'
			. '<div class="amm-mtrust">💗 15 300+ доволни жени · 🚚 Доставка за 1–2 дни · 💳 Карта или наложен платеж</div>'
			. '</div>'
			. '</div>';
		return self::assets() . $out;
	}

	private static $assets_done = false;
	private static function assets() {
		if ( self::$assets_done ) { return ''; } self::$assets_done = true;
		$css = <<<'CSS'
.elementskit-megamenu-panel{padding:0!important;border-radius:0!important;box-shadow:0 30px 60px #3a241626!important}
.elementskit-megamenu-panel .e-con,.elementskit-megamenu-panel .e-con-inner,.elementskit-megamenu-panel .elementor-widget-container,.elementskit-megamenu-panel .elementor-widget{padding:0!important;margin:0!important;max-width:none!important}
.amm-backdrop{position:fixed;inset:0;z-index:98;background:rgba(43,30,25,.32);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);opacity:0;pointer-events:none;transition:opacity .2s}.amm-backdrop.on{opacity:1}
@media (min-width:1025px){.amm{max-height:calc(100vh - 140px);overflow:auto}.elementskit-megamenu-panel.elementskit-dropdown-menu-full_width,.elementskit-dropdown-menu-full_width>.elementskit-megamenu-panel{background:#fffaf7}}
.amm{--amm-ink:#332723;--amm-muted:#7d6a61;--amm-coral:#ef6b4d;--amm-deep:#a93e28;--amm-line:#f0e3db;font-family:'Nunito',system-ui,-apple-system,'Segoe UI',sans-serif;color:var(--amm-ink);background:#fffaf7;display:grid;grid-template-columns:252px minmax(0,1fr) 290px;align-items:stretch;text-align:left;box-sizing:border-box;position:relative;line-height:1.3;white-space:normal;max-width:1760px;margin:0 auto}
.amm *,.amm *:before,.amm *:after{box-sizing:border-box}
.amm:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:linear-gradient(90deg,var(--amm-coral),#f6b18f)}
.amm a{text-decoration:none;color:inherit}
.amm .amm-t{font-size:10.5px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#a07d68;margin:0 14px 10px}
.amm-needs{padding:22px 8px 22px 18px;border-right:1px solid var(--amm-line);background:#fff4ec}
.amm-need{display:grid;grid-template-columns:32px 1fr 12px;align-items:center;gap:9px;width:100%;text-align:left;background:none;border:0;padding:8px 12px;border-radius:12px;font:inherit;font-size:13.5px;font-weight:800;color:#3b2a22;cursor:pointer;line-height:1.25}
.amm-need small{display:block;font-size:11px;font-weight:600;color:#9a8474;margin-top:1px}
.amm-need .amm-ic{width:32px;height:32px;border-radius:10px;background:#fff;display:grid;place-items:center;font-size:16px;box-shadow:0 1px 2px #5b3e2414}
.amm-need i{color:#c9ad9c;font-style:normal;font-weight:900}
.amm-need:hover,.amm-need.on{background:#fff;box-shadow:0 2px 8px #5b3e2412}.amm-need.on{color:var(--amm-deep)}.amm-need.on i{color:var(--amm-coral)}
.amm-grid{padding:22px 26px;display:flex;flex-direction:column;min-width:0}
.amm-gt{display:flex;align-items:baseline;gap:8px;margin-left:0}.amm-gt b{color:var(--amm-ink);text-transform:none;letter-spacing:0;font-size:15px;font-weight:900}.amm-gt .amm-cnt{text-transform:none;letter-spacing:0;font-weight:700;color:#9a8474;font-size:12px}
.amm-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.amm-cards.all{grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.amm-cards.all.six{grid-template-columns:repeat(6,minmax(0,1fr));gap:9px}.amm-cards.all.six .amm-card b{font-size:12.5px;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.15;min-height:2.3em}
.amm-card{display:flex;flex-direction:column;gap:3px;min-width:0;background:#fff;border:1px solid var(--amm-line);border-radius:16px;padding:10px;transition:box-shadow .15s,transform .15s}
.amm-card:hover{box-shadow:0 10px 24px #5b3e2418;transform:translateY(-2px)}
.amm-card[hidden]{display:none}
.amm-pic{display:block;position:relative;aspect-ratio:1/1;border-radius:11px;background:#fff;border:1px solid #f6ebe2;overflow:hidden;margin-bottom:6px}
.amm-pic img{width:100%;height:100%;object-fit:contain;display:block;background:#fff}
.amm-new{position:absolute;top:6px;left:6px;z-index:1;font-style:normal;font-size:9.5px;font-weight:900;background:#2e6953;color:#fff;border-radius:100px;padding:3px 8px;letter-spacing:.04em}
.amm-cat{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:10px;font-weight:800;color:#a07d68;letter-spacing:.04em;text-transform:uppercase}
.amm-card b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:14px;font-weight:900;letter-spacing:-.2px}.amm-cards.all .amm-card b{font-size:13px}
.amm-card small{font-size:11.5px;color:var(--amm-muted);line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.amm-cards.all .amm-card small{display:none}
.amm-price{font-size:13px;font-weight:900;color:var(--amm-deep);margin-top:4px}
.amm-foot{display:flex;gap:18px;margin-top:auto;padding-top:18px;font-size:12.5px;font-weight:800;flex-wrap:wrap}.amm-foot a{color:var(--amm-deep)}.amm-foot a:after{content:" →"}
.amm-aside{padding:22px 24px 22px 0;display:flex;flex-direction:column;gap:12px}
.amm-promo{display:block;background:linear-gradient(135deg,#2b1e19,#4a2d22);color:#fff;border-radius:18px;padding:18px}
.amm-promo .amm-ey{display:block;font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#f6b18f}
.amm-promo strong{display:block;margin:6px 0 4px;font-size:19px;line-height:1.15;font-weight:900;letter-spacing:-.4px;color:#fff}
.amm-promo .amm-p{display:block;margin:0 0 12px;font-size:12.5px;color:#e9d5c8;line-height:1.4}
.amm-promo .amm-cta{display:inline-block;background:var(--amm-coral);color:#fff;font-weight:900;font-size:13px;padding:10px 14px;border-radius:12px}
.amm-quiz{display:block;background:#fff4ec;border:1px solid #f0d6c6;border-radius:16px;padding:14px 16px 14px 52px;position:relative}
.amm-quiz .amm-qic{position:absolute;left:14px;top:14px;width:28px;height:28px;border-radius:9px;background:#fff;display:grid;place-items:center;font-size:15px}
.amm-quiz b{display:block;font-size:14px;font-weight:900}.amm-quiz small{display:block;color:var(--amm-muted);font-size:12px;margin:3px 0 8px;line-height:1.4}.amm-quiz>span:last-child{color:var(--amm-deep);font-weight:900;font-size:12.5px}
.amm-trust{list-style:none;margin:0;padding:12px 14px;background:#fff;border:1px solid var(--amm-line);border-radius:14px;display:grid;gap:7px;font-size:12px;font-weight:700;color:#5a3f30}.amm-trust li{white-space:nowrap;margin:0}
.amm-mobile{display:none}
@media (max-width:1024px){
body:has(.elementskit-menu-offcanvas-elements.active) #fkcart-floating-toggler{opacity:0!important;pointer-events:none!important}
.elementskit-megamenu-panel .elementor-widget,.elementskit-megamenu-panel .e-con{width:100%!important;flex:0 0 100%!important;min-width:0!important}
.amm{display:block;background:#fffaf7;max-width:100%;min-width:0;overflow:hidden}
.amm:before{display:none}
.amm-needs,.amm-grid,.amm-aside{display:none}
.amm-mobile{display:block;padding:8px 16px 24px}
.amm-msearch{display:flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--amm-line);border-radius:14px;padding:0 5px 0 14px;height:46px;margin:0 0 12px}
.amm-msearch input[type=search]{flex:1;min-width:0;border:0!important;background:none!important;box-shadow:none!important;font:inherit;font-size:15px;color:var(--amm-ink);outline:0;height:44px;padding:0;margin:0;-webkit-appearance:none}
.amm-msearch button{border:0;background:#fff4ec;border-radius:10px;width:36px;height:36px;font-size:15px;display:grid;place-items:center;padding:0;flex:0 0 auto;cursor:pointer}
.amm-mpromo{margin:0 0 12px;background:linear-gradient(135deg,#2b1e19,#4a2d22);color:#fff;border-radius:16px;padding:13px 14px;display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:10px}
.amm-mpromo b{display:block;font-size:14px;font-weight:900;color:#fff}.amm-mpromo small{display:block;color:#e9d5c8;font-size:11.5px;margin-top:2px;line-height:1.3}.amm-mpromo em{font-style:normal;background:var(--amm-coral);color:#fff;border-radius:12px;padding:11px 13px;font-size:13px;font-weight:900;white-space:nowrap}
.amm-mnav{display:flex;gap:8px;overflow-x:auto;scrollbar-width:none;margin:0 -16px 14px;padding:2px 16px}.amm-mnav::-webkit-scrollbar{display:none}
.amm-mnav a{flex:0 0 auto;background:#fff;border:1px solid var(--amm-line);border-radius:100px;padding:9px 14px;font-size:13.5px;font-weight:800;color:var(--amm-ink);line-height:1.2}
.amm-mt{display:flex;align-items:center;justify-content:space-between;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#a07d68;padding:2px 2px 8px}.amm-mt a{color:var(--amm-deep);letter-spacing:0;text-transform:none;font-size:13px}
.amm-acc{background:#fff;border:1px solid var(--amm-line);border-radius:16px;overflow:hidden}
.amm-acc details+details{border-top:1px solid var(--amm-line)}
.amm-acc summary{list-style:none;display:flex;align-items:center;gap:12px;padding:10px 14px;min-height:54px;font-size:15px;font-weight:900;color:var(--amm-ink);cursor:pointer;box-sizing:border-box}
.amm-acc summary::-webkit-details-marker{display:none}
.amm-acc summary .amm-ic{width:34px;height:34px;border-radius:10px;background:#fff4ec;display:grid;place-items:center;font-size:17px;flex:0 0 auto}
.amm-acc summary small{margin-left:auto;color:#9a8474;font-weight:800;font-size:12px;background:#fff4ec;border-radius:100px;padding:3px 8px;line-height:1.2}
.amm-acc summary:after{content:"";width:18px;height:18px;flex:0 0 auto;background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23c9ad9c' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") center/18px no-repeat;transition:transform .2s}
.amm-acc details[open] summary{background:#fffaf7}.amm-acc details[open] summary:after{content:"";transform:rotate(180deg)}
.amm-acc-rows{padding:0 14px 8px}
.amm-row{display:grid;grid-template-columns:46px minmax(0,1fr) auto;gap:12px;align-items:center;padding:8px 0;border-top:1px solid #f6ebe2}
.amm-row:first-child{border-top:0}
.amm-row img{width:46px;height:46px;border-radius:10px;object-fit:contain;background:#fff;border:1px solid #f6ebe2}
.amm-row>span{min-width:0}.amm-row b{display:block;font-size:14px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.amm-row small{display:block;font-size:11.5px;color:var(--amm-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.amm-row em{font-style:normal;font-size:13px;font-weight:900;color:var(--amm-deep);white-space:nowrap}
.amm-mall{display:block;margin:12px 0 0;background:var(--amm-coral);color:#fff!important;text-align:center;border-radius:14px;padding:14px;font-size:15px;font-weight:900}
.amm-mquiz{display:grid;grid-template-columns:36px minmax(0,1fr) auto;gap:12px;align-items:center;margin:12px 0 0;background:#fff4ec;border:1px solid #f0d6c6;border-radius:16px;padding:12px 14px}
.amm-mquiz .amm-qic{width:36px;height:36px;border-radius:10px;background:#fff;display:grid;place-items:center;font-size:18px}
.amm-mquiz b{display:block;font-size:14px;font-weight:900}.amm-mquiz small{display:block;font-size:12px;color:var(--amm-muted);line-height:1.35}.amm-mquiz i{font-style:normal;font-weight:900;color:var(--amm-deep);font-size:22px;line-height:1}
.amm-mtrust{margin:14px 0 0;text-align:center;font-size:11.5px;font-weight:700;color:#8a7266;line-height:1.6}
}
@media (min-width:1025px) and (max-width:1500px){.amm{grid-template-columns:214px minmax(0,1fr) 244px}.amm-needs{padding:18px 6px 18px 12px}.amm-grid{padding:18px 16px}.amm-aside{padding:18px 16px 18px 0}.amm-need{font-size:13px;padding:7px 10px}.amm-cards.all .amm-card b{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.15;min-height:2.3em}.amm-promo strong{font-size:17px}}
CSS;
		$js = <<<'JS'
(function(){function init(root){if(root.dataset.ammInit)return;root.dataset.ammInit='1';var cards=root.querySelectorAll('.amm-card'),title=root.querySelector('.amm-title'),cnt=root.querySelector('.amm-cnt'),grid=root.querySelector('.amm-cards');
function pick(n,label){var k=0;for(var i=0;i<cards.length;i++){var c=cards[i],h=n!=='all'&&(' '+c.getAttribute('data-c')+' ').indexOf(' '+n+' ')<0;c.hidden=h;if(!h)k++}if(grid)grid.classList.toggle('all',n==='all');if(title)title.textContent=label;if(cnt)cnt.textContent=k===1?'1 продукт':k+' продукта'}
var needs=root.querySelectorAll('.amm-need');for(var j=0;j<needs.length;j++){(function(b){var go=function(){for(var x=0;x<needs.length;x++)needs[x].classList.toggle('on',needs[x]===b);pick(b.getAttribute('data-n'),b.querySelector('span:nth-child(2)').childNodes[0].textContent.trim())};b.addEventListener('mouseenter',go);b.addEventListener('click',function(e){e.preventDefault();go()});b.addEventListener('focus',go)})(needs[j])}}
var bd=null;function backdrop(){if(bd)return bd;bd=document.createElement('div');bd.className='amm-backdrop';document.body.appendChild(bd);return bd}
function hookItem(root){var li=root.closest('li.elementskit-megamenu-has,li.menu-item');if(!li||li.dataset.ammBd)return;li.dataset.ammBd='1';var show=function(){if(window.innerWidth>1024)backdrop().classList.add('on')},hide=function(){if(bd)bd.classList.remove('on')};li.addEventListener('mouseenter',show);li.addEventListener('mouseleave',hide);li.addEventListener('focusin',show);li.addEventListener('focusout',function(e){if(!li.contains(e.relatedTarget))hide()})}
function all(){var r=document.querySelectorAll('.amm');for(var i=0;i<r.length;i++){init(r[i]);hookItem(r[i])}}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',all);else all();document.addEventListener('ekit-megamenu-open',all);setTimeout(all,1500)})();
JS;
		return '<style id="amm-css">' . $css . '</style><script id="amm-js">' . $js . '</script>';
	}
}
