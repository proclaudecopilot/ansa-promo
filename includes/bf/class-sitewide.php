<?php
/**
 * ansa™ Промо — „Черен петък навсякъде“ (1.58.0; човекът, 25.11.2026: „като отвориш сайта изобщо не ти крещи «Черен петък» … трябва да е
 * кристално ясно, и на десктоп, и на мобилно устройство, и в менюто, и в страниците, и в топ хедъра“ → „одобрявам всичките 7, скрий Сезона
 * до 30.11, Черен петък на кирилица“).
 *
 * Докато тече публичният Черен петък (BF\Config фаза public: public_start → public_end), на всяка страница на сайта:
 *  1. топ лентата в хедъра е черна: „ЧЕРЕН ПЕТЪК · Плащаш 1, получаваш 2 · свършва след 4д 23ч 37м 03с · Избери подаръците →“ (на мястото на
 *     въртележката със съобщения на Elementor — самата въртележка остава в страницата, само е скрита);
 *  2. хапчето в менюто е „Черен петък 1 + 1“; промо картата в мега менюто и в мобилното меню показва същото (Mega_Menu);
 *  5. на телефон: лепкава лента долу с брояч и бутон (не в количката, плащането, акаунта и страницата на Сезона);
 *  6. в магазина: черен банер с брояч и етикет „Черен петък +1 подарък“ върху участващите продукти (Shop);
 *  7. на продуктовата страница на участващ продукт: черна лента „вземи го + 1 подарък по избор“ → страницата на Черния петък с ?p=ключ.
 * (3 и 4 са началната страница — docs/home/home-bf-2026.html, HTML уиджет в Elementor.)
 * Сезонът се скрива от менюто, мега менюто и магазина, докато лентата е включена; страницата му си работи.
 *
 * Кешът на страниците (FlyingPress) пази HTML-а, затова включването е на три нива:
 *  - сървърът печата всичко, когато промото е включено и сме във фаза public — или до ARM часа преди нея („заредено“);
 *  - <body class="abf-on"> казва на CSS-а да покаже Черния петък и да скрие Сезона още при първото рисуване (само във фаза public);
 *  - малък скрипт сверява часовника на посетителя с началото/края: преди началото и след края маха класа (страница от кеша не обещава
 *    нещо, което не тече), в началото го слага сам. Еднократен крон в началото и в края чисти кеша на страниците.
 * Участващ продукт = включен в „🖤 Черен петък → Продукти“ и не е „само подарък“ (шестте основни).
 *
 * @package AnsaPromo
 * @since 1.58.0
 */
namespace AnsaPromo\BF;

defined( 'ABSPATH' ) || exit;

final class Sitewide {

	const HOOK = 'ansa_promo_bf_sitewide_flip';
	const ARM  = 36; /* часове преди public_start, в които страниците вече носят (скрития) Черен петък — за кеша */
	private static $state = null;
	private static $printed = false;

	public static function register() {
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 98 );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 5 );
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'nav_item' ), 19, 2 );
		add_action( 'init', array( __CLASS__, 'schedule' ), 40 );
		add_action( self::HOOK, array( __CLASS__, 'on_flip' ) );
		add_action( 'ansa_promo_bf_published', array( __CLASS__, 'on_published' ), 20 );
	}

	/* ── състоянието ── */
	/** 'on' (тече публичният Черен петък) · 'armed' (до ARM часа преди него: всичко е в страницата, но скрито) · '' (нищо). */
	public static function state( $cfg = null, $ts = null ) {
		$memo = null === $cfg && null === $ts;
		if ( $memo && null !== self::$state ) { return self::$state; }
		$s = '';
		try {
			$cfg = $cfg ?: Config::get_published();
			if ( ! empty( $cfg['enabled'] ) ) {
				$ph = Config::phase_now( $cfg, $ts ); $t = null === $ts ? Config::now() : (int) $ts; $p = Config::phase_ts( $cfg );
				if ( 'public' === $ph ) { $s = 'on'; }
				elseif ( '' === (string) ( $cfg['force'] ?? '' ) && $p['public_start'] && $t < $p['public_start'] && $t >= $p['public_start'] - self::ARM * HOUR_IN_SECONDS ) { $s = 'armed'; }
			}
		} catch ( \Throwable $e ) { $s = ''; }
		$s = (string) apply_filters( 'ansa_promo_bf_sitewide_state', $s, $cfg );
		if ( ! in_array( $s, array( 'on', 'armed' ), true ) ) { $s = ''; }
		if ( $memo ) { self::$state = $s; }
		return $s;
	}
	public static function on() { return 'on' === self::state(); }
	public static function reset() { self::$state = null; self::$printed = false; self::$buy = null; }
	/** [начало, край] на публичната фаза (ts). При force=public началото е 0 (вече тече). */
	public static function window( $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $p = Config::phase_ts( $cfg );
		return array( 'public' === (string) ( $cfg['force'] ?? '' ) ? 0 : (int) $p['public_start'], (int) $p['public_end'] );
	}
	public static function url( $key = '' ) {
		$u = Page::url(); $key = preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		return '' !== $key ? add_query_arg( 'p', $key, $u ) : $u;
	}

	/* ── участващите продукти ── */
	private static $buy = null;
	/** [WC product id → ключ] на продуктите, които се купуват в Черния петък (включени, не „само подарък“, налични в магазина). */
	public static function buy_ids( $cfg = null ) {
		if ( null === $cfg && null !== self::$buy ) { return self::$buy; }
		$out = array();
		try {
			$c = $cfg ?: Config::get_published();
			foreach ( (array) Page::products( $c ) as $k => $p ) { if ( empty( $p['giftOnly'] ) && ! empty( $p['id'] ) ) { $out[ (int) $p['id'] ] = (string) $k; } }
		} catch ( \Throwable $e ) { $out = array(); }
		$out = (array) apply_filters( 'ansa_promo_bf_sitewide_buy_ids', $out );
		if ( null === $cfg ) { self::$buy = $out; }
		return $out;
	}
	/** Ключът на продукта в Черния петък или '' (не участва / лентата е изключена). */
	public static function key_for( $product_id ) {
		if ( '' === self::state() ) { return ''; }
		$b = self::buy_ids(); return (string) ( $b[ (int) $product_id ] ?? '' );
	}

	/* ── къде ── */
	/** Страници без нищо от лентата: админ, feed, REST. (Страницата на Черния петък рисува своя <head> без wp_head — там така или иначе не стига.) */
	private static function skip() {
		if ( is_admin() || ( function_exists( 'is_feed' ) && is_feed() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return true; }
		return (bool) apply_filters( 'ansa_promo_bf_sitewide_skip', false );
	}
	/** Лепкавата лента долу не излиза там, където клиентката вече плаща или е в друго промо. */
	public static function sticky_allowed() {
		try {
			if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) { return false; }
			$pub = \AnsaPromo\Config::get_published();
			if ( ! empty( $pub['page_id'] ) && function_exists( 'is_page' ) && is_page( (int) $pub['page_id'] ) ) { return false; }
			$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
			if ( preg_match( '#^(sezonut-na-ansa|kozmetichen-set-promo|checkout|cart|porachka|kolichka)(/|$)#', $path ) ) { return false; }
		} catch ( \Throwable $e ) {}
		return (bool) apply_filters( 'ansa_promo_bf_sitewide_sticky', true );
	}

	/* ── изходът ── */
	public static function body_class( $c ) { if ( 'on' === self::state() && ! self::skip() && ! self::quiet() ) { $c[] = 'abf-on'; } return $c; }
	/** 1.58.1 (видяно на живо: лентата се качваше върху хедъра на чекаута — той е друг шаблон, без въртележка): на страниците за плащане
	 *  (чекаут, плащане на поръчка, „благодарим“) няма нищо от Черния петък — без класа на body и без скрипта всичко .abf-only стои скрито, Сезонът е както винаги. */
	public static function quiet() {
		try { if ( function_exists( 'is_checkout' ) && is_checkout() ) { return true; } } catch ( \Throwable $e ) {}
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' );
		return (bool) apply_filters( 'ansa_promo_bf_sitewide_quiet', (bool) preg_match( '#^(checkout|porachka)(/|$)#', $path ) );
	}

	/** Хапчето „Черен петък 1 + 1“ — на мястото на хапчето на Сезона (след „Продукти“, преди елемент 15391), само в главното меню. */
	public static function nav_item( $items, $args ) {
		if ( '' === self::state() || false === strpos( $items, 'menu-item-15392' ) || false !== strpos( $items, 'abf-li' ) ) { return $items; }
		$li = self::pill_html();
		$pos = strpos( $items, '<li id="menu-item-15391"' );
		return false === $pos ? $items . $li : substr( $items, 0, $pos ) . $li . substr( $items, $pos );
	}
	public static function pill_html() {
		return '<li class="menu-item nav-item amm-season abf-li abf-only"><a href="' . esc_url( self::url() ) . '" class="ekit-menu-nav-link amm-season-link abf-pill"><b class="abf-dot" aria-hidden="true"></b><span>Черен петък</span><i>1 + 1</i></a></li>';
	}
	/** Промо картата в мега менюто (десктоп) и в мобилното меню — Mega_Menu ги слага до тези на Сезона. */
	public static function menu_promo_html() {
		if ( '' === self::state() ) { return ''; }
		return '<a class="amm-promo abf-promo abf-only" href="' . esc_url( self::url() ) . '"><span class="amm-ey">Черен петък · тече сега</span><strong>Плащаш 1, получаваш 2</strong><span class="amm-p">Всяка платена опаковка = 1 опаковка подарък по твой избор. Свършва след <span data-abf-cd="words"></span>.</span><span class="amm-cta">Избери подаръците →</span></a>';
	}
	public static function menu_mpromo_html() {
		if ( '' === self::state() ) { return ''; }
		return '<a class="amm-mpromo abf-promo abf-only" href="' . esc_url( self::url() ) . '"><span><b>Черен петък · 1 + 1</b><small>Плащаш 1, получаваш 2 · още <span data-abf-cd="mini"></span></small></span><em>Избери →</em></a>';
	}
	/** Банерът в магазина (до заглавието) и етикетът върху картата на участващ продукт — Shop ги слага. */
	public static function shop_banner_html() {
		if ( '' === self::state() ) { return ''; }
		return '<a class="as-season abf-shop abf-only" href="' . esc_url( self::url() ) . '"><span class="as-ey">Черен петък · тече сега</span><strong>Плащаш 1, получаваш 2</strong><span class="as-p">Всяка платена опаковка = 1 опаковка подарък по твой избор. Без код, подаръкът е на 0 €.</span><span class="as-cta">Избери подаръците →</span><span class="abf-scd">⏳ свършва след <span data-abf-cd="words"></span></span></a>';
	}
	public static function card_badge_html( $product_id ) {
		return '' !== self::key_for( $product_id ) ? '<span class="abf-cardbadge abf-only">Черен петък<em>+1 подарък</em></span>' : '';
	}

	public static function head() {
		if ( '' === self::state() || self::skip() ) { return; }
		echo '<style id="abf-css">' . self::css() . '</style>' . "\n";
	}

	public static function footer() {
		if ( self::$printed || '' === self::state() || self::skip() || self::quiet() ) { return; }
		self::$printed = true;
		echo self::footer_html( self::pdp_key(), self::sticky_allowed() );
	}
	/** Ключът на текущата продуктова страница, ако продуктът участва. */
	public static function pdp_key() {
		try { if ( function_exists( 'is_product' ) && is_product() ) { return self::key_for( (int) get_queried_object_id() ); } } catch ( \Throwable $e ) {}
		return '';
	}
	/** Лентата (шаблон), лепкавата лента, лентата за продуктовата страница и скриптът. Като низ — за тестовете и за страници без wp_footer. */
	public static function footer_html( $pdp_key = '', $sticky = true ) {
		list( $start, $end ) = self::window(); $u = esc_url( self::url() );
		$h  = '<template id="abf-bar-t"><a class="abf abf-bar abf-only" href="' . $u . '"><span class="abf-tag">Черен петък</span><span class="abf-msg"><b>Плащаш 1, получаваш 2<span class="abf-x">.</span></b><span class="abf-x"> Подаръкът е по твой избор</span></span><span class="abf-sep abf-x" aria-hidden="true">|</span><span class="abf-cd"><span class="abf-x">свършва след </span><span data-abf-cd="bar"></span></span><span class="abf-go abf-x">Избери подаръците →</span><span class="abf-chev" aria-hidden="true">›</span></a></template>';
		if ( $sticky ) {
			$h .= '<a class="abf abf-stick abf-only" href="' . $u . '"><span><span class="abf-l1">ЧЕРЕН ПЕТЪК · <u>1 + 1 подарък</u></span><span class="abf-l2">свършва след <span data-abf-cd="words"></span></span></span><em>Избери →</em></a>';
		}
		if ( '' !== $pdp_key ) {
			$h .= '<a class="abf abf-pdp abf-only" href="' . esc_url( self::url( $pdp_key ) ) . '"><span class="abf-ic" aria-hidden="true">1+1</span><span class="abf-tx"><b>Черен петък: вземи го + 1 подарък по избор</b><small>Плащаш 1, получаваш 2 · още <span data-abf-cd="short"></span></small></span><em>Избери подаръка →</em></a>';
		}
		return $h . self::script( $start, $end );
	}

	/** data-loading-method="immediate": FlyingPress да не го отлага — лентата трябва да е на мястото си веднага. */
	public static function script( $start, $end ) {
		return '<script id="abf-js" data-loading-method="immediate">(function(){try{'
			. 'var S=' . (int) $start . ',E=' . (int) $end . ',d=document,b=d.body,H=d.documentElement;if(!b)return;'
			. 'function now(){return Date.now()/1000}function live(){var n=now();return n>=S&&n<E}'
			. 'function p2(n){return(n<10?"0":"")+n}'
			. 'function parts(){var s=Math.max(0,Math.floor(E-now()));return{d:Math.floor(s/86400),h:Math.floor(s%86400/3600),m:Math.floor(s%3600/60),s:s%60}}'
			. 'function dn(n){return n===1?"ден":"дни"}'
			. 'function fmt(k,t){'
			. 'if(k==="bar")return(t.d>0?"<b>"+t.d+"</b>д ":"")+"<b>"+p2(t.h)+"</b>ч <span class=\"abf-m"+(t.d>0?" abf-dm":"")+"\"><b>"+p2(t.m)+"</b>м </span><span class=\"abf-s\"><b>"+p2(t.s)+"</b>с</span>";'
			. 'if(k==="mini")return t.d>0?t.d+"д "+p2(t.h)+"ч":p2(t.h)+"ч "+p2(t.m)+"м";'
			. 'if(k==="short")return t.d>0?t.d+" "+dn(t.d)+" "+p2(t.h)+" ч":p2(t.h)+" ч "+p2(t.m)+" мин";'
			. 'return(t.d>0?t.d+" "+dn(t.d)+" ":"")+p2(t.h)+" ч "+p2(t.m)+" мин"}'
			/* лентата: на мястото на въртележката в хедъра (Elementor „slides“) */
			. 'function place(){var t=d.getElementById("abf-bar-t");if(t&&!d.querySelector(".abf-bar")){var w=d.querySelectorAll(".elementor-location-header .elementor-widget-slides"),i,h;'
			. 'for(i=0;i<w.length;i++){h=w[i].parentNode;h.classList.add("abf-barhost");h.appendChild(t.content.firstChild.cloneNode(true))}}' /* 1.58.1: само там; хедър без въртележка (чекаутът) остава без лента */
			/* продуктовата страница: преди картата с опциите за покупка (първият висок блок след заглавието) */
			. 'var p=d.querySelector(".abf-pdp:not(.abf-placed)");if(p){var ti=d.querySelector(".elementor-widget-woocommerce-product-title")||d.querySelector(".product_title"),a=null,n;'
			. 'if(ti){n=ti;a=n;for(var x=n.nextElementSibling,c=0;x&&c<8;x=x.nextElementSibling,c++){if(x.offsetHeight>=300){break}a=x}a.parentNode.insertBefore(p,a.nextSibling);p.classList.add("abf-placed")}}'
			. '}'
			. 'function tick(){var t=parts(),e=d.querySelectorAll("[data-abf-cd]"),i;for(i=0;i<e.length;i++){e[i].innerHTML=fmt(e[i].getAttribute("data-abf-cd"),t)}}'
			/* лепкавата лента: на началната чак след бутона в героя */
			. 'function stick(){var s=d.querySelector(".abf-stick");if(!s)return;var c=d.querySelector(".ansa-home .bf-cta, .ansa-home .bf .btn");if(!c){s.classList.add("abf-show");return}'
			. 'function chk(){s.classList.toggle("abf-show",c.getBoundingClientRect().bottom<0)}chk();window.addEventListener("scroll",chk,{passive:true})}'
			. 'var iv=0;function set(){var on=live();b.classList.toggle("abf-on",on);if(on){place();tick();if(!iv){iv=setInterval(function(){if(live()){tick()}else{set()}},1000);stick()}}else if(iv){clearInterval(iv);iv=0;try{window.dispatchEvent(new Event("resize"))}catch(e){}}}'
			. 'set();var w=(S-now())*1000;if(w>0&&w<2147e6)setTimeout(set,w+500);'
			. '}catch(e){}})();</script>' . "\n";
	}

	/** Стилът. Всичко е под body.abf-on (показва Черния петък, скрива Сезона); без класа .abf-only не се вижда. */
	public static function css() {
		$f = "font-family:'Nunito',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif";
		$host = 'body.abf-on .elementor-location-header .e-con:has(>.elementor-widget-slides),body.abf-on .abf-barhost';
		return 'body:not(.abf-on) .abf-only{display:none!important}'
			/* Сезонът отстъпва */
			. 'body.abf-on li.amm-season:not(.abf-li),body.abf-on a.amm-promo:not(.abf-promo),body.abf-on a.amm-mpromo:not(.abf-promo),body.abf-on a.as-season:not(.abf-shop){display:none!important}'
			/* 1. топ лентата */
			. $host . '{background:#0d0d14!important;padding:0!important;min-height:45px}'
			. 'body.abf-on .elementor-location-header .e-con:has(>.elementor-widget-slides)>.elementor-widget-slides,body.abf-on .abf-barhost>.elementor-widget-slides{display:none!important}'
			. 'body.abf-on .elementor-location-header .e-con:has(>.elementor-widget-slides):not(.abf-barhost)::after{content:"ЧЕРЕН ПЕТЪК · Плащаш 1, получаваш 2";display:flex;align-items:center;justify-content:center;width:100%;min-height:45px;color:#fff;font-size:14px;font-weight:900;' . $f . '}'
			. '.abf,.abf *{box-sizing:border-box;' . $f . '}'
			. 'a.abf-bar{display:flex;align-items:center;justify-content:center;gap:14px;width:100%;min-height:45px;background:#0d0d14;color:#fff!important;text-decoration:none!important;font-size:14.5px;font-weight:700;padding:0 14px;line-height:1.1;white-space:nowrap;position:relative;overflow:hidden}'
			. 'a.abf-bar::before{content:"";position:absolute;inset:0;background:radial-gradient(420px 120px at 50% 120%,rgba(232,114,42,.45),transparent 70%);pointer-events:none}'
			. 'a.abf-bar>*{position:relative}'
			. '.abf-bar .abf-tag{background:#e8722a;color:#fff;font-weight:900;font-size:12.5px;letter-spacing:.08em;text-transform:uppercase;border-radius:6px;padding:5px 9px}'
			. '.abf-bar b{font-weight:900;color:#fff}'
			. '.abf-bar .abf-cd{color:#ffb570;font-variant-numeric:tabular-nums;font-weight:800}'
			. '.abf-bar .abf-cd b{background:rgba(255,255,255,.12);border-radius:5px;padding:3px 5px;margin:0 1px 0 3px;font-size:14px}'
			. '.abf-bar .abf-go{background:#fff;color:#0d0d14;border-radius:999px;padding:6px 13px;font-weight:900;font-size:13px}'
			. '.abf-bar .abf-sep{opacity:.35}.abf-bar .abf-chev{display:none;font-weight:900;color:#ffb570;font-size:16px}'
			. 'a.abf-bar:hover .abf-go{background:#ffb570}'
			. '@media (max-width:900px){a.abf-bar{gap:8px;font-size:13px;padding:0 10px;justify-content:space-between}.abf-bar .abf-x,.abf-bar .abf-s,.abf-bar .abf-dm{display:none}.abf-bar .abf-chev{display:inline}.abf-bar .abf-tag{font-size:11px;padding:4px 7px;letter-spacing:.05em}.abf-bar .abf-cd b{font-size:12.5px;padding:2px 4px;margin:0 0 0 2px}}'
			. '@media (max-width:380px){.abf-bar .abf-m{display:none}a.abf-bar{font-size:12px}}'
			/* 2. хапчето (разположението в менюто идва от amm-season-css) + картите в менютата */
			. '.elementskit-navbar-nav li.abf-li>a.abf-pill{background:#0d0d14;box-shadow:0 0 0 3px rgba(232,114,42,.22);text-transform:uppercase;letter-spacing:.04em;font-weight:900}'
			. '.elementskit-navbar-nav li.abf-li>a.abf-pill:hover{background:#1d1d29}'
			. '.elementskit-navbar-nav li.abf-li>a.abf-pill i{background:#e8722a;letter-spacing:0}'
			. 'a.abf-pill .abf-dot{width:8px;height:8px;border-radius:50%;background:#ff5a36;box-shadow:0 0 0 4px rgba(255,90,54,.3);flex:0 0 auto;animation:abfp 1.8s ease-in-out infinite}'
			. '@keyframes abfp{0%,100%{box-shadow:0 0 0 3px rgba(255,90,54,.35)}50%{box-shadow:0 0 0 6px rgba(255,90,54,.08)}}'
			. 'a.amm-promo.abf-promo,a.amm-mpromo.abf-promo,a.as-season.abf-shop{background:#0d0d14;position:relative;overflow:hidden}'
			. 'a.amm-promo.abf-promo::before,a.amm-mpromo.abf-promo::before,a.as-season.abf-shop::before{content:"";position:absolute;inset:0;background:radial-gradient(300px 200px at 100% 0%,rgba(232,114,42,.6),transparent 65%);pointer-events:none}'
			. 'a.amm-promo.abf-promo>*,a.amm-mpromo.abf-promo>*,a.as-season.abf-shop>*{position:relative}'
			. 'a.abf-promo .amm-ey,a.abf-shop .as-ey{color:#ffb570}a.abf-promo .amm-cta,a.abf-shop .as-cta,a.amm-mpromo.abf-promo em{background:#e8722a}'
			. 'a.amm-mpromo.abf-promo b{font-size:15.5px;letter-spacing:.03em;text-transform:uppercase}a.amm-mpromo.abf-promo small{color:#ffb570;font-weight:800}'
			/* 6. магазинът */
			. 'a.abf-shop .abf-scd{display:block;margin-top:12px;font-size:12.5px;font-weight:800;color:#ffb570;font-variant-numeric:tabular-nums}'
			. '.abf-cardbadge{position:absolute;left:8px;right:8px;bottom:8px;z-index:3;background:#0d0d14;color:#fff;border-radius:10px;font-size:11.5px;font-weight:900;letter-spacing:.03em;text-transform:uppercase;padding:7px 9px;display:flex;justify-content:space-between;align-items:center;gap:6px;white-space:nowrap;line-height:1.1;' . $f . '}'
			. '.abf-cardbadge em{font-style:normal;background:#16a34a;border-radius:999px;padding:3px 7px;font-size:10.5px;text-transform:none;letter-spacing:0}'
			. '@media (max-width:560px){.abf-cardbadge{left:5px;right:5px;bottom:5px;font-size:9.5px;padding:5px 6px;border-radius:8px}.abf-cardbadge em{font-size:9px;padding:2px 5px}}'
			/* 7. продуктовата страница */
			. 'a.abf-pdp{display:none}'
			. 'body.abf-on a.abf-pdp.abf-placed{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;width:100%;background:#0d0d14;color:#fff!important;border-radius:16px;padding:13px 14px;margin:14px 0 6px;text-decoration:none!important;position:relative;overflow:hidden}'
			. 'a.abf-pdp::before{content:"";position:absolute;inset:0;background:radial-gradient(300px 160px at 100% 0%,rgba(232,114,42,.55),transparent 65%);pointer-events:none}'
			. 'a.abf-pdp>*{position:relative}'
			. '.abf-pdp .abf-ic{width:46px;height:46px;border-radius:12px;background:#e8722a;display:grid;place-items:center;font-size:17px;font-weight:900;color:#fff;letter-spacing:-.03em}'
			. '.abf-pdp .abf-tx b{display:block;font-size:15.5px;font-weight:900;color:#fff;line-height:1.2}'
			. '.abf-pdp .abf-tx small{display:block;font-size:12.5px;font-weight:700;color:#ffb570;margin-top:3px;line-height:1.3;font-variant-numeric:tabular-nums}'
			. '.abf-pdp em{font-style:normal;background:#fff;color:#0d0d14;border-radius:12px;padding:10px 13px;font-size:13.5px;font-weight:900;white-space:nowrap}'
			. 'a.abf-pdp:hover em{background:#ffb570}'
			. '@media (max-width:560px){body.abf-on a.abf-pdp.abf-placed{grid-template-columns:auto minmax(0,1fr);gap:10px}.abf-pdp em{grid-column:1/-1;text-align:center}}'
			/* 5. лепкавата лента долу (само телефон) */
			. 'a.abf-stick{display:none}'
			. '@media (max-width:900px){body.abf-on a.abf-stick.abf-show{position:fixed;left:0;right:0;bottom:0;z-index:99990;background:#0d0d14;color:#fff!important;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;padding:9px 12px calc(9px + env(safe-area-inset-bottom,0px));box-shadow:0 -8px 26px rgba(0,0,0,.28);text-decoration:none!important}'
			. 'body.abf-on:has(a.abf-stick.abf-show) #fkcart-floating-toggler{bottom:84px!important}body.abf-on:has(a.abf-stick.abf-show){padding-bottom:64px}}'
			. '.abf-stick .abf-l1{display:block;font-size:13.5px;font-weight:900;color:#fff;line-height:1.15}.abf-stick .abf-l1 u{text-decoration:none;color:#ff8a3d}'
			. '.abf-stick .abf-l2{display:block;font-size:11.5px;font-weight:800;color:#ffb570;margin-top:2px;font-variant-numeric:tabular-nums}'
			. '.abf-stick em{font-style:normal;background:linear-gradient(135deg,#ff9a4d,#e8722a 55%,#e2562f);color:#fff;border-radius:12px;padding:11px 15px;font-size:14px;font-weight:900;white-space:nowrap}'
			. '@media (prefers-reduced-motion:reduce){a.abf-pill .abf-dot{animation:none}}';
	}

	/* ── кешът: еднократен крон в началото и в края на публичната фаза ── */
	/** Следващата граница (начало или край) след $t, или 0. */
	public static function next_flip( $cfg = null, $t = null ) {
		try {
			$cfg = $cfg ?: Config::get_published(); if ( empty( $cfg['enabled'] ) || '' !== (string) ( $cfg['force'] ?? '' ) ) { return 0; }
			$t = null === $t ? time() : (int) $t; $p = Config::phase_ts( $cfg );
			foreach ( array( $p['public_start'] - self::ARM * HOUR_IN_SECONDS, $p['public_start'], $p['public_end'] ) as $at ) { if ( $at > 0 && $at > $t ) { return (int) $at; } }
		} catch ( \Throwable $e ) {}
		return 0;
	}
	public static function schedule() {
		if ( ! function_exists( 'wp_next_scheduled' ) || wp_next_scheduled( self::HOOK ) ) { return; }
		if ( ! Config::has_published() ) { return; }
		$at = self::next_flip(); if ( $at ) { wp_schedule_single_event( $at + 5, self::HOOK ); }
	}
	/** При публикуване: кронът се пренарежда; кешът на страниците се чисти само ако се е сменило нещо, което лентата показва (състояние, начало/край, адрес, участващи продукти). */
	const OPT_SIG = 'ansa_promo_bf_sitewide_sig';
	public static function signature() { return md5( wp_json_encode( array( self::state(), self::window(), self::url(), self::buy_ids() ) ) ); }
	public static function on_published() {
		if ( function_exists( 'wp_next_scheduled' ) ) { $n = wp_next_scheduled( self::HOOK ); if ( $n ) { wp_unschedule_event( $n, self::HOOK ); } }
		self::reset(); $sig = self::signature();
		if ( $sig !== (string) get_option( self::OPT_SIG, '' ) ) { update_option( self::OPT_SIG, $sig, false ); self::purge_all(); }
		self::schedule();
	}
	public static function on_flip() { self::reset(); update_option( self::OPT_SIG, self::signature(), false ); self::purge_all(); self::schedule(); }
	/** Целият кеш на страниците (лентата и хапчето са във всяка страница). */
	public static function purge_all() {
		try { if ( class_exists( '\\FlyingPress\\Purge' ) ) { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_pages' ) ) ) { \FlyingPress\Purge::purge_pages(); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_everything' ) ) ) { \FlyingPress\Purge::purge_everything(); } } } catch ( \Throwable $e ) {}
		do_action( 'ansa_promo_bf_sitewide_purged' );
	}
}
