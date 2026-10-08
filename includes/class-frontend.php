<?php
/**
 * ansa™ Промо — фронт.
 *
 * [ansa_promo] → скелет (templates/page.php) + inline runtime JSON (window.AnsaPromoRuntime) + promo.css/js.
 * ?ansa_promo=draft (само manage_woocommerce) показва черновата; &ansa_editor=1 включва хотспотовете за редактора.
 * Публикуваната страница е кешируема; цените в runtime JSON-а се сверяват от promo.js през ajax ansa_promo_runtime.
 * Shortcode-ът се регистрира на init (20) — печели над пенсионирания модул на Shrine дори без guard.
 *
 * @package AnsaPromo
 * @since 1.0.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	const NONCE = 'ansa_promo_front';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_shortcode' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'headers' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'hints' ), 10, 2 );
		add_action( 'wp_ajax_ansa_promo_runtime', array( __CLASS__, 'ajax_runtime' ) );
		add_action( 'wp_ajax_nopriv_ansa_promo_runtime', array( __CLASS__, 'ajax_runtime' ) );
		/* v1.0.3: темплейт „цял екран“ за страници + скриване на елементи на темата */
		add_filter( 'theme_page_templates', array( __CLASS__, 'page_templates' ), 10, 4 );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_head', array( __CLASS__, 'hide_css' ), 99 );
	}

	const TEMPLATE = 'ansa-promo-fullscreen.php';
	public static function page_templates( $templates, $theme = null, $post = null, $post_type = 'page' ) {
		if ( 'page' === $post_type ) { $templates[ self::TEMPLATE ] = 'ansa™ Промо — цял екран'; }
		return $templates;
	}
	public static function is_fullscreen() { return is_singular( 'page' ) && get_page_template_slug( get_queried_object_id() ) === self::TEMPLATE; }
	public static function template_include( $template ) {
		return self::is_fullscreen() ? ANSA_PROMO_PATH . 'templates/fullscreen.php' : $template;
	}
	public static function body_class( $classes ) {
		if ( self::is_promo_page() ) { $classes[] = 'ansa-promo-page'; }
		return $classes;
	}
	/** Скрива хедър/футър/плаващи бутони на темата на промо страницата (настройка theme.hide + селектори). */
	public static function hide_css() {
		if ( ! self::is_promo_page() ) { return; }
		$cfg = self::cfg();
		if ( empty( $cfg['theme']['hide'] ) ) { return; }
		$sel = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $cfg['theme']['selectors'] ) ) ) );
		if ( ! $sel ) { return; }
		echo '<style id="ansa-promo-hide">' . esc_html( implode( ',', $sel ) ) . '{display:none!important}body.ansa-promo-page{padding-top:0!important}</style>' . "\n";
	}

	public static function add_shortcode() { add_shortcode( Plugin::SHORTCODE, array( __CLASS__, 'shortcode' ) ); }

	public static function is_draft_request() { return ( isset( $_GET['ansa_promo'] ) && 'draft' === $_GET['ansa_promo'] && current_user_can( Plugin::CAP ) ) || self::preview_ok(); }

	/* ── v1.0.37: преглед с парола ──
	   Докато играта не е пусната (published.enabled = false / изтекла), страницата показва форма за парола. Правилна парола (от 🧰 Инструменти,
	   по подразбиране SD(A0%as09) → бисквитка за 30 дни с HMAC на паролата → посетителят вижда ЧЕРНОВАТА без да влиза в сайта.
	   Работи и с линк ?ansa_pw=<парола> (записва бисквитката и маха параметъра). Смяна на паролата обезсилва всички бисквитки. */
	const PW_COOKIE = 'ansa_promo_pw';
	private static $pw_ok = null; private static $pw_err = false;
	public static function preview_cfg() { $d = Config::get_draft(); return is_array( $d['preview'] ?? null ) ? $d['preview'] : array( 'enabled' => false, 'password' => '' ); }
	public static function preview_token( $pw ) { return hash_hmac( 'sha256', (string) $pw, wp_salt( 'auth' ) ); }
	/* v1.0.38: защитата е безусловна — щом е включена, всеки без парола вижда формата, дори играта да е пусната (човекът: „да стане достъпно с парола“);
	   админите (manage_woocommerce) минават без парола и виждат черновата */
	public static function preview_available() { $p = self::preview_cfg(); return ! empty( $p['enabled'] ) && '' !== (string) $p['password']; }
	public static function preview_ok() {
		if ( null !== self::$pw_ok ) { return self::$pw_ok; }
		self::$pw_ok = false;
		if ( ! self::preview_available() ) { return false; }
		if ( current_user_can( Plugin::CAP ) ) { self::$pw_ok = true; return true; }
		$c = isset( $_COOKIE[ self::PW_COOKIE ] ) ? (string) $_COOKIE[ self::PW_COOKIE ] : '';
		self::$pw_ok = '' !== $c && hash_equals( self::preview_token( self::preview_cfg()['password'] ), $c );
		return self::$pw_ok;
	}
	/** Линкът за екипа: страницата + ?ansa_pw=<парола>. */
	public static function preview_link() {
		$d = Config::get_draft(); $url = ! empty( $d['page_id'] ) ? get_permalink( (int) $d['page_id'] ) : '';
		return $url ? add_query_arg( 'ansa_pw', rawurlencode( (string) $d['preview']['password'] ), $url ) : '';
	}
	public static function is_editor_request() { return self::is_draft_request() && ! empty( $_GET['ansa_editor'] ); }
	public static function cfg() { return self::is_draft_request() ? Config::get_draft() : Config::get_published(); }

	/** Страницата с shortcode-а ли е това? */
	public static function is_promo_page() {
		if ( ! is_singular() ) { return false; }
		$post = get_post(); if ( ! $post ) { return false; }
		if ( has_shortcode( (string) $post->post_content, Plugin::SHORTCODE ) ) { return true; }
		/* v1.0.24: страница, сглобена с Elementor, държи shortcode-а в _elementor_data, не в post_content — иначе body класът и
		   скриването на елементите на темата (fkcart и т.н.) не се прилагаха, макар играта да се рендира */
		$el = (string) get_post_meta( $post->ID, '_elementor_data', true );
		return '' !== $el && false !== strpos( $el, '[' . Plugin::SHORTCODE );
	}

	public static function headers() {
		if ( ! self::is_promo_page() ) { return; }
		/* v1.0.37: въведена парола (форма или ?ansa_pw=) → бисквитка + redirect без параметъра */
		$given = isset( $_POST['ansa_pw'] ) ? (string) wp_unslash( $_POST['ansa_pw'] ) : ( isset( $_GET['ansa_pw'] ) ? (string) wp_unslash( $_GET['ansa_pw'] ) : null );
		if ( null !== $given && self::preview_available() ) {
			if ( hash_equals( (string) self::preview_cfg()['password'], $given ) ) {
				setcookie( self::PW_COOKIE, self::preview_token( $given ), time() + 30 * DAY_IN_SECONDS, '/', '', is_ssl(), true );
				$to = remove_query_arg( 'ansa_pw' ); if ( ! $to ) { $to = get_permalink(); }
				wp_safe_redirect( $to ); exit;
			}
			self::$pw_err = true;
		}
		/* v1.0.38: при включена защита страницата никога не се кешира — иначе кеш плъгинът връща формата (или играта) на всички */
		if ( self::is_draft_request() || self::preview_available() ) { nocache_headers(); if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } }
	}
	/** Формата за парола (вместо „скоро“), когато прегледът е включен. */
	private static function gate_html( $cfg ) {
		$name = esc_html( (string) ( $cfg['name'] ?: 'Сезонът на ansa™' ) );
		return '<div class="ansa-promo ansa-promo-off ansa-promo-pwgate" id="ansaPromo" data-ver="' . esc_attr( ANSA_PROMO_VER ) . '">'
			. '<style>.ansa-promo-pwgate{position:fixed;inset:0;z-index:2147483646;isolation:isolate;overflow:auto;-webkit-overflow-scrolling:touch;pointer-events:auto;display:flex;align-items:center;justify-content:center;padding:32px 16px;box-sizing:border-box;background:#faf3ec;font-family:Nunito,-apple-system,BlinkMacSystemFont,sans-serif;color:#1a1a2e}'
			/* v1.0.48: формата е собствен слой на цял екран над всичко (тема, попъпи, плаващи бутони) и се мести в края на <body>, за да не е
			   в обвивка с transform (там position:fixed не е спрямо екрана). promo.css/js не се зареждат на нея (maybe_enqueue). */
			/* v1.0.47: promo.css се зарежда и тук (maybe_enqueue) и .ansa-promo::before (фонът, position:absolute; inset:0; z-index:0) покриваше формата —
			   не можеше да се цъкне в полето. Псевдо-елементът е махнат за формата, а тя е над всичко (position:relative; z-index:1). */
			. '.ansa-promo-pwgate::before{display:none!important;content:none!important}.ansa-pw{position:relative;z-index:1}'
			. '.ansa-pw{width:100%;max-width:420px;background:#fff;border:1.5px solid rgba(232,114,42,.25);border-radius:22px;padding:28px 24px;box-shadow:0 14px 40px rgba(232,114,42,.12);text-align:center}'
			. '.ansa-pw .b{font-weight:900;color:#e8722a;font-size:22px}.ansa-pw h2{margin:6px 0 4px;font-size:24px;font-weight:900;letter-spacing:-.02em}.ansa-pw p{margin:0 0 16px;font-size:14px;color:#6b5f58;font-weight:600}'
			. '.ansa-pw input{width:100%;box-sizing:border-box;font:inherit;font-size:16px;padding:13px 14px;border:1.5px solid rgba(232,114,42,.35);border-radius:12px;margin-bottom:10px;background:#fff}'
			. '.ansa-pw button{width:100%;font:inherit;font-size:16px;font-weight:900;color:#fff;border:none;border-radius:14px;min-height:50px;background:linear-gradient(135deg,#ef8c4f,#e8722a 50%,#e56b50);cursor:pointer}'
			. '.ansa-pw .err{color:#b91c1c;font-weight:800;margin:10px 0 0;font-size:14px}.ansa-pw small{display:block;margin-top:14px;font-size:12px;color:#a88a75;font-weight:600}</style>'
			. '<form class="ansa-pw" method="post" action="' . esc_url( remove_query_arg( 'ansa_pw' ) ) . '"><div class="b">ansa™</div><h2>' . $name . '</h2><p>Предварителен преглед. Въведи паролата, която получи от екипа на ansa.</p>'
			. '<input type="password" name="ansa_pw" placeholder="Парола" autocomplete="current-password" required autofocus><button type="submit">Влез →</button>'
			. ( self::$pw_err ? '<p class="err">Грешна парола — опитай пак.</p>' : '' )
			. '<small>Страницата не е публична. Играта ще стартира скоро.</small></form></div>'
			. '<script>(function(){var g=document.getElementById("ansaPromo");if(g&&g.parentNode!==document.body){document.body.appendChild(g)}var i=g&&g.querySelector("input");if(i){try{i.focus()}catch(e){}}})();</script>';
	}
	public static function robots( $robots ) {
		if ( self::is_promo_page() && ( self::is_draft_request() || ! Config::is_live( Config::get_published() ) ) ) { $robots['noindex'] = true; $robots['nofollow'] = true; }
		return $robots;
	}
	public static function hints( $urls, $relation ) {
		if ( 'preconnect' === $relation && self::is_promo_page() ) { $urls[] = array( 'href' => 'https://fonts.googleapis.com', 'crossorigin' => false ); $urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => true ); }
		return $urls;
	}

	/** v1.0.48: формата за парола е на екрана (защитата е включена и няма валидна бисквитка/админ). */
	public static function gate_shown() { return self::preview_available() && ! self::is_draft_request(); }
	/* v1.0.48: на формата за парола НЕ се зареждат promo.css/promo.js (нямат работа там и фонът .ansa-promo::before покриваше полето) — само шрифтът */
	public static function maybe_enqueue() {
		if ( ! self::is_promo_page() ) { return; }
		if ( self::gate_shown() ) { wp_enqueue_style( 'ansa-promo-nunito', 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;900&display=swap', array(), null ); return; }
		self::enqueue();
	}

	public static function enqueue() {
		static $done = false; if ( $done ) { return; } $done = true;
		wp_enqueue_style( 'ansa-promo-nunito', 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap', array(), null );
		wp_enqueue_style( 'ansa-promo', ANSA_PROMO_URL . 'assets/promo.css', array( 'ansa-promo-nunito' ), ANSA_PROMO_VER );
		wp_enqueue_style( 'ansa-promo-extra', ANSA_PROMO_URL . 'assets/promo-extra.css', array( 'ansa-promo' ), ANSA_PROMO_VER );
		wp_enqueue_script( 'ansa-promo', ANSA_PROMO_URL . 'assets/promo.js', array(), ANSA_PROMO_VER, true );
		wp_add_inline_script( 'ansa-promo', 'window.AnsaPromoRuntime=' . wp_json_encode( self::runtime( self::cfg(), self::is_draft_request() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
	}

	/** Продуктите с живи WC данни. */
	public static function products( $cfg ) {
		$prods = array();
		foreach ( $cfg['products'] as $p ) {
			if ( empty( $p['enabled'] ) ) { continue; }
			/* v1.0.26: SKU на вариацията „1 опаковка“ печели над product_id (по име се хващаха грешни продукти — сетове) */
			$pid = '' !== $p['sku'] ? ( Catalog::find_by_sku( $p['sku'] ) ?: (int) $p['product_id'] ) : (int) $p['product_id'];
			$wc = Catalog::wc_line( $pid );
			if ( ! $wc['ok'] ) { continue; }
			$img = $p['img'] ? (string) wp_get_attachment_image_url( (int) $p['img'], 'medium' ) : '';
			/* v1.0.25: „за какво е“ (ds) и описанието (desc) идват от WooCommerce като на продуктовата страница — подзаглавието на Shrine
			   или първото изречение на краткото описание за ds, цялото кратко описание (до 260 знака) за desc; полетата от 📦 Продукти
			   са резерва при празен WC или при изключена отметка „текстовете от WooCommerce“ */
			/* v1.0.38 (човекът: „ръчно да може да се мапне“): полетата в 📦 Продукти печелят, когато са попълнени; WC пълни само празните */
			list( $wn, $wds, $wdesc ) = self::wc_texts( $wc );
			$name = '' !== $p['name'] ? $p['name'] : ( $wn ?: $wc['wc_name'] );
			$ds   = '' !== $p['ds'] ? $p['ds'] : ( ! empty( $p['wc_text'] ) ? $wds : '' );
			$desc = '' !== $p['desc'] ? $p['desc'] : ( ! empty( $p['wc_text'] ) ? $wdesc : '' );
			$prods[ $p['key'] ] = array(
				'key' => $p['key'], 'id' => (int) ( $wc['product_id'] ?: $p['product_id'] ), 'vid' => (int) $wc['variation_id'], 'ph' => $p['ph'], 'img' => $img ?: $wc['img'],
				'name' => $name, 'gname' => $p['gname'], 'gsub' => $p['gsub'],
				'ds' => $ds, 'desc' => $desc, 'ing' => $p['ing'], 'who' => $p['who'], 'rating' => $p['rating'], 'pack' => $p['pack'],
				'reviews' => array_values( array_filter( array_map( function ( $l ) { $x = array_map( 'trim', explode( '|', $l ) ); return count( $x ) >= 3 ? array( 'n' => $x[0], 's' => max( 1, min( 5, (int) $x[1] ) ), 't' => $x[2] ) : null; }, preg_split( '/\r?\n/', (string) $p['reviews'] ) ) ) ),
				'cat' => $p['cat'], 'theme' => $p['theme'], 'price' => (float) $wc['price'], 'stock' => (bool) $wc['stock'],
			);
		}
		return $prods;
	}

	/** v1.0.38: какво дава WooCommerce за [име, „за какво е“, описание] — марката без „ansa™“ и без слогана; заглавието на Shrine като ds. */
	public static function wc_texts( $wc ) {
		$split = function ( $t ) { $p = preg_split( '/\s+[|\-–—:]\s+/u', (string) $t, 2 ); return array( trim( (string) ( $p[0] ?? '' ) ), trim( (string) ( $p[1] ?? '' ) ) ); };
		$brand = function ( $t ) { return trim( (string) preg_replace( '/^\s*ansa\s*(™|\x{2122}|tm)?(\s+|$)/iu', '', (string) $t ) ); };
		$name = '';
		if ( '' !== (string) $wc['base_name'] ) { list( $b0, $b1 ) = $split( $wc['base_name'] ); $nm = $brand( $b0 ); if ( '' === $nm ) { $nm = $brand( $split( $b1 )[0] ); } $name = $nm; }
		$desc = '' !== (string) $wc['short'] ? self::clip( $wc['short'], 260 ) : '';
		list( $t1, $t2 ) = '' !== (string) $wc['title'] ? $split( $wc['title'] ) : array( '', '' ); $t1 = $brand( $t1 );
		if ( '' !== $t1 && mb_strtolower( $t1 ) !== mb_strtolower( $name ) ) { $ds = self::clip( $t1, 90 ); }
		elseif ( '' !== $t2 ) { $ds = self::clip( $t2, 90 ); }
		elseif ( '' !== (string) $wc['subtitle'] ) { $ds = self::clip( $wc['subtitle'], 90 ); }
		elseif ( '' !== (string) $wc['short'] ) { $ds = self::clip( self::first_sentence( $wc['short'] ), 90 ); }
		else { $ds = ''; }
		return array( $name, $ds, $desc );
	}

	/** Първото изречение на текст (до . ! ? или нов ред). */
	public static function first_sentence( $t ) {
		$t = trim( (string) $t ); if ( '' === $t ) { return ''; }
		if ( preg_match( '/^(.{12,}?[.!?])(\s|$)/su', $t, $m ) ) { return trim( rtrim( $m[1], '.' ) ); }
		return $t;
	}
	/** Отрязва до n знака по дума, с многоточие. */
	public static function clip( $t, $n ) {
		$t = trim( (string) $t ); if ( mb_strlen( $t ) <= $n ) { return $t; }
		$c = mb_substr( $t, 0, $n ); $sp = mb_strrpos( $c, ' ' ); if ( $sp !== false && $sp > $n * 0.6 ) { $c = mb_substr( $c, 0, $sp ); }
		return rtrim( $c, ' ,;:—-' ) . '…';
	}

	/** Снимките на плочките в имейл-попъпа като URL-и: attachment id → medium_large, URL → както е, празно → примерната снимка от assets/img/gate/. */
	public static function gate_images( $cfg ) {
		$out = array();
		foreach ( Config::gate_image_slots() as $k => $label ) {
			$v = (string) ( $cfg['gate']['images'][ $k ] ?? '' ); $url = '';
			if ( ctype_digit( $v ) && (int) $v > 0 ) { $url = (string) wp_get_attachment_image_url( (int) $v, 'medium_large' ); }
			elseif ( '' !== $v ) { $url = $v; }
			$out[ $k ] = $url ?: self::gate_dummy( $k );
		}
		return $out;
	}
	public static function gate_dummy( $k ) { return ANSA_PROMO_URL . 'assets/img/gate/' . $k . '.svg'; }
	public static function gate_image_is_dummy( $cfg, $k ) { return '' === (string) ( $cfg['gate']['images'][ $k ] ?? '' ) || ( ctype_digit( (string) $cfg['gate']['images'][ $k ] ) && ! wp_get_attachment_image_url( (int) $cfg['gate']['images'][ $k ], 'medium_large' ) ); }

	/** Всичко, което promo.js трябва да знае. Никакви цени не идват от клиента. */
	public static function runtime( $cfg, $draft = false ) {
		$editor = self::is_editor_request();
		$key = Catalog::runtime_key( Config::version() );
		$prods = ( ! $draft ) ? get_transient( $key ) : false;
		if ( ! is_array( $prods ) ) { $prods = self::products( $cfg ); if ( ! $draft ) { set_transient( $key, $prods, Catalog::RUNTIME_TTL ); } }
		$deadline = $cfg['deadline'] ? date_i18n( 'd.m.Y', Config::deadline_ts( $cfg ) ?: time() ) : '';
		$utm = '';
		if ( ! empty( $_GET[ $cfg['utm_param'] ] ) ) { $utm = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) wp_unslash( $_GET[ $cfg['utm_param'] ] ) ) ); }
		if ( ! isset( $prods[ $utm ] ) ) { $utm = ''; }
		$fit = array(); foreach ( (array) $cfg['fit'] as $k => $ks ) { if ( isset( $prods[ $k ] ) ) { $fit[ $k ] = array_values( array_filter( (array) $ks, function ( $x ) use ( $prods ) { return isset( $prods[ $x ] ); } ) ); } }
		$problems = array(); foreach ( (array) $cfg['problems'] as $x ) { if ( isset( $prods[ $x['key'] ] ) ) { $problems[] = $x; } }
		return array(
			'ver'       => ANSA_PROMO_VER,
			'id'        => $cfg['id'],
			'name'      => $cfg['name'],
			'version'   => Config::version(),
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE ),
			'live'      => Config::is_live( $cfg ),
			'draft'     => (bool) $draft,
			'editor'    => $editor,
			'cart'      => false, /* Фаза 3 → true: „Завърши поръчката“ вика ansa_promo_add */
			'refresh'   => true,
			'checkout'  => array( 'cod' => ! empty( $cfg['checkout']['cod_gateway'] ) ),
			'products'  => (object) $prods,
			'order'     => array_keys( $prods ),
			'fit'       => (object) $fit,
			'problems'  => $problems,
			'boxes'     => $cfg['boxes'],
			'box_order' => $cfg['order'],
			'rewards'   => $cfg['rewards'],
			'secret'    => $cfg['secret'],
			'ship'      => (float) $cfg['ship']['paid'],
			'timer'     => $cfg['timer'],
			'gate'      => array_merge( $cfg['gate'], array( 'images' => self::gate_images( $cfg ) ) ),
			'bgn'       => $cfg['bgn'],
			'utm'       => $utm,
			'deadline'  => $deadline,
			'copy'      => Copy::effective( $cfg['copy'] ),
			'registry'  => $editor ? array( 'groups' => Copy::groups(), 'keys' => Copy::registry(), 'vars' => Copy::vars() ) : null,
		);
	}

	/** Свеж runtime за кеширана страница (само публикуваното; без registry). */
	public static function ajax_runtime() {
		$cfg = Config::get_published();
		$r = self::runtime( $cfg, false );
		unset( $r['registry'] );
		wp_send_json_success( $r );
	}

	public static function shortcode( $atts = array(), $content = '' ) {
		$cfg = self::cfg();
		$draft = self::is_draft_request();
		if ( self::gate_shown() ) { return self::gate_html( $cfg ); } /* v1.0.38: формата за парола е пред всичко, докато защитата е включена */
		if ( ! $draft && ! Config::is_live( $cfg ) ) {
			$html = '<div class="ansa-promo ansa-promo-off" id="ansaPromo" data-ver="' . esc_attr( ANSA_PROMO_VER ) . '"><p class="ansa-promo-soon" style="text-align:center;font:700 22px/1.3 Nunito,system-ui,sans-serif;padding:48px 16px;margin:0">ansa™ Промо — скоро.</p>';
			if ( current_user_can( Plugin::CAP ) ) {
				$html .= '<p class="ansa-promo-admin-note" style="text-align:center;font:500 13px/1.5 system-ui,sans-serif;opacity:.6;margin:0 0 24px">ansa™ Промо ' . esc_html( ANSA_PROMO_VER ) . ' · играта е ' . ( empty( $cfg['enabled'] ) ? 'изключена' : 'изтекла' ) . ' (виждаш това само като админ) · <a href="' . esc_url( add_query_arg( 'ansa_promo', 'draft' ) ) . '">виж черновата</a></p>';
			}
			return $html . '</div>';
		}
		self::enqueue();
		$editor = self::is_editor_request();
		ob_start();
		include ANSA_PROMO_PATH . 'templates/page.php';
		return ob_get_clean();
	}
}
