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

	public static function is_draft_request() { return isset( $_GET['ansa_promo'] ) && 'draft' === $_GET['ansa_promo'] && current_user_can( Plugin::CAP ); }
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
		if ( self::is_draft_request() ) { nocache_headers(); }
	}
	public static function robots( $robots ) {
		if ( self::is_promo_page() && ( self::is_draft_request() || ! Config::is_live( Config::get_published() ) ) ) { $robots['noindex'] = true; $robots['nofollow'] = true; }
		return $robots;
	}
	public static function hints( $urls, $relation ) {
		if ( 'preconnect' === $relation && self::is_promo_page() ) { $urls[] = array( 'href' => 'https://fonts.googleapis.com', 'crossorigin' => false ); $urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => true ); }
		return $urls;
	}

	public static function maybe_enqueue() { if ( self::is_promo_page() ) { self::enqueue(); } }

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
			$wc = Catalog::wc_line( $p['product_id'] );
			if ( ! $wc['ok'] ) { continue; }
			$img = $p['img'] ? (string) wp_get_attachment_image_url( (int) $p['img'], 'medium' ) : '';
			$prods[ $p['key'] ] = array(
				'key' => $p['key'], 'id' => (int) ( $wc['product_id'] ?: $p['product_id'] ), 'vid' => (int) $wc['variation_id'], 'ph' => $p['ph'], 'img' => $img ?: $wc['img'],
				'name' => '' !== $p['name'] ? $p['name'] : $wc['wc_name'], 'gname' => $p['gname'], 'gsub' => $p['gsub'],
				'ds' => $p['ds'], 'desc' => $p['desc'], 'ing' => $p['ing'], 'who' => $p['who'], 'rating' => $p['rating'], 'pack' => $p['pack'],
				'reviews' => array_values( array_filter( array_map( function ( $l ) { $x = array_map( 'trim', explode( '|', $l ) ); return count( $x ) >= 3 ? array( 'n' => $x[0], 's' => max( 1, min( 5, (int) $x[1] ) ), 't' => $x[2] ) : null; }, preg_split( '/\r?\n/', (string) $p['reviews'] ) ) ) ),
				'cat' => $p['cat'], 'theme' => $p['theme'], 'price' => (float) $wc['price'], 'stock' => (bool) $wc['stock'],
			);
		}
		return $prods;
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
