<?php
/**
 * ansa™ Промо — визуалният редактор (Фаза 2, план §5): страница „✏️ Редактор“ (пълен екран, 3 колони) + ajax.
 *
 *  - Канвасът е истинската страница: iframe с ?ansa_promo=draft&ansa_editor=1 (черновата + регистърът); promo.js в режим
 *    редактор дава хотспотове (data-ck) и приема postMessage {copy|cfg|scenario}. Никакъв втори рендер.
 *  - Данни: editor_get → {cfg: чернова, registry, checks, history, gateways, products}; editor_save → цялата чернова (normalize,
 *    връща записаното — UI-ят не дрейфва); editor_publish → Doctor (bad спира) → Config::publish; discard; restore.
 *  - Doctor::checks() е publish gate-ът (план §7).
 *
 * @package AnsaPromo
 * @since 1.4.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Editor {

	const SLUG  = 'ansa-promo-editor';
	const NONCE = 'ansa_promo_editor';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) ); /* 1.17.0 */
		foreach ( array( 'get', 'save', 'publish', 'discard', 'restore', 'doctor' ) as $a ) { add_action( 'wp_ajax_ansa_promo_editor_' . $a, array( __CLASS__, 'ajax_' . $a ) ); }
	}
	public static function menu() { add_submenu_page( Plugin::MENU_SLUG, 'ansa™ Промо — редактор', '✏️ Редактор', Plugin::CAP, self::SLUG, array( __CLASS__, 'page' ) ); }
	/** 1.17.0: редакторът е таб в приложението (🎁 ansa Промо → ✏️ Редактор); старият URL ?page=ansa-promo-editor редиректва натам. */
	public static function url() { return Admin::url( array( 'tab' => 'editor' ) ); }
	public static function maybe_redirect() { if ( self::SLUG === sanitize_key( (string) ( $_GET['page'] ?? '' ) ) && ! isset( $_GET['embed'] ) ) { wp_safe_redirect( self::url() ); exit; } }

	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) { return; }
		self::enqueue_now();
	}
	public static function enqueue_now() {
		if ( function_exists( 'wp_enqueue_media' ) ) { wp_enqueue_media(); } /* 1.24.21: снимките на секциите (📄 Страници) се избират от медийната библиотека */
		wp_enqueue_style( 'ansa-promo-editor', ANSA_PROMO_URL . 'assets/editor.css', array(), ANSA_PROMO_VER );
		wp_enqueue_script( 'ansa-promo-editor', ANSA_PROMO_URL . 'assets/editor.js', array(), ANSA_PROMO_VER, true );
		wp_add_inline_script( 'ansa-promo-editor', 'window.AnsaPromoEditor=' . wp_json_encode( self::boot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
	}

	/** Всичко, което editor.js трябва да знае при зареждане. */
	public static function boot() {
		$draft = Config::get_draft();
		$keys = array(); foreach ( Copy::registry() as $g => $ks ) { foreach ( $ks as $k => $v ) { $keys[ $k ] = array( 'g' => $g, 'd' => $v, 'v' => Copy::placeholders( $v ) ); } }
		$gw = array(); if ( function_exists( 'WC' ) && WC()->payment_gateways() ) { foreach ( WC()->payment_gateways()->payment_gateways() as $id => $x ) { $gw[ $id ] = $x->get_title() . ( 'yes' === $x->enabled ? '' : ' (изкл.)' ); } }
		$prods = array(); foreach ( $draft['products'] as $p ) { $prods[] = array( 'key' => $p['key'], 'name' => $p['name'] ?: $p['key'], 'enabled' => ! empty( $p['enabled'] ), 'pid' => (int) $p['product_id'] ); }
		return array(
			'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE ), 'ver' => ANSA_PROMO_VER,
			'page' => ! empty( $draft['page_id'] ) ? add_query_arg( array( 'ansa_promo' => 'draft', 'ansa_editor' => '1' ), get_permalink( (int) $draft['page_id'] ) ) : '',
			'page_url' => ! empty( $draft['page_id'] ) ? get_permalink( (int) $draft['page_id'] ) : '', 'admin' => Admin::url(), 'products_tab' => Admin::url( array( 'tab' => 'products' ) ),
			'registry' => array( 'groups' => Copy::groups(), 'keys' => $keys, 'vars' => Copy::vars(), 'screens' => self::group_screens() ),
			'cfg' => self::out( $draft ), 'state' => self::state(), 'gateways' => $gw, 'products' => $prods,
			/* 1.24.21: 📄 Страници — секциите на /info и /kozmetika (етикети, снимков слот), снимките от имейл-попъпа като URL (снимката по подразбиране на секцията) */
			'pages' => Config::page_sections(), 'gate_images' => Frontend::gate_images( $draft ), 'gate_slots' => Config::gate_image_slots(),
			'rewards' => array( 'tix' => 'Билет', 'tix3' => '3 билета', 'tix5' => '5 билета', 'ship' => 'Безплатна доставка', 'book' => 'Книга', 'cosm50' => 'Сет −50%', 'cosm1' => 'Сетът за €1' ),
		);
	}
	/** Коя група къде се вижда на канваса (сценарий); '' = извън канваса (чекаут/имейл). */
	public static function group_screens() {
		return array( 'gate' => array( 'gate' ), 'cosm' => array( 'cosm' ), 'li' => array( 'li' ), 'head' => array( 'boxes' ), 'how' => array( 'how' ), 'boxes' => array( 'boxes' ), 'fill' => array( 'fill' ), 'celeb' => array( 'celeb' ), 'builder' => array( 'builder' ), 'picker' => array( 'picker', 'product' ), 'sure' => array( 'sure', 'switch' ), 'rewards' => array( 'boxes', 'celeb', 'how' ), 'secret' => array( 'secret' ), 'order' => array(), 'checkout' => array(), 'msgs' => array( 'builder' ), 'emails' => array() );
	}
	/** Празни асоциативни масиви (copy, fit) стават {} в JSON, не [] — иначе JS ги вижда като Array и губи ключовете. */
	public static function out( $cfg ) { foreach ( (array) ( $cfg['pages'] ?? array() ) as $pg => $p ) { $cfg['pages'][ $pg ]['images'] = (object) ( $p['images'] ?? array() ); } /* 1.24.21 */ $cfg['copy'] = (object) ( $cfg['copy'] ?? array() ); $cfg['fit'] = (object) ( $cfg['fit'] ?? array() ); $cfg['campaigns'] = (object) ( $cfg['campaigns'] ?? array() ); /* 1.24.14 */ $cfg['copy_utm'] = (object) array_map( function ( $m ) { return (object) $m; }, (array) ( $cfg['copy_utm'] ?? array() ) ); /* 1.24.19 */ $cfg['gate']['images'] = (object) ( $cfg['gate']['images'] ?? array() ); $cfg['rewards']['icons'] = (object) ( $cfg['rewards']['icons'] ?? array() ); return $cfg; }
	public static function state() {
		/* 1.23.7: published_copy — публикуваните текстове (ключ → текст), за да вижда редакторът кое е на живо и кое е само чернова */
		$draft = Config::get_draft(); $pi = array(); foreach ( array_keys( Config::page_sections() ) as $pg ) { $pi[ $pg ] = (object) Frontend::pages_images( $draft, $pg ); } /* 1.24.21: своите снимки на секциите като URL (attachment id → URL) */
		return array( 'pages_images' => $pi, 'version' => Config::version(), 'published_at' => Config::published_at(), 'has_draft' => Config::has_draft(), 'draft_at' => (int) ( get_option( Plugin::OPTION )['draft_at'] ?? 0 ), 'history' => Config::history(), 'checks' => Doctor::checks( Config::get_draft() ), 'live' => Config::is_live( Config::get_published() ), 'published_copy_utm' => (object) array_map( function ( $m ) { return (object) $m; }, (array) ( Config::get_published()['copy_utm'] ?? array() ) ), /* 1.24.19 */ 'published_copy' => (object) ( Config::get_published()['copy'] ?? array() ) );
	}

	private static function auth() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'auth', 'msg' => 'Нямаш права или сесията изтече — презареди.' ), 403 ); }
	}
	public static function ajax_get() { self::auth(); wp_send_json_success( array( 'cfg' => self::out( Config::get_draft() ), 'state' => self::state() ) ); }
	public static function ajax_save() {
		self::auth();
		$d = json_decode( (string) wp_unslash( $_POST['cfg'] ?? '' ), true );
		if ( ! is_array( $d ) ) { wp_send_json_error( array( 'code' => 'json', 'msg' => 'Невалиден JSON.' ), 400 ); }
		/* Само текстовете: ключове извън регистъра се игнорират; празно = дефолтът (Copy::diff) */
		$cur = Config::get_draft();
		if ( ! empty( $_POST['only_copy'] ) ) { $cur['copy'] = is_array( $d['copy'] ?? null ) ? $d['copy'] : array(); $d = $cur; }
		$bad = array(); foreach ( (array) ( $d['copy'] ?? array() ) as $k => $v ) { foreach ( Copy::validate( (string) $v ) as $e ) { $bad[ $k ] = $e; } }
		$stored = Config::save_draft( $d );
		wp_send_json_success( array( 'cfg' => self::out( $stored ), 'state' => self::state(), 'invalid' => (object) $bad ) );
	}
	public static function ajax_doctor() { self::auth(); wp_send_json_success( array( 'checks' => Doctor::checks( Config::get_draft() ) ) ); }
	public static function ajax_publish() {
		self::auth();
		/* 1.23.7 (човекът: „Публикувай навярно само запазва“): редакторът праща текущата конфигурация с публикуването — черновата се записва и се
		   публикува в ЕДНА заявка, за да не може автозаписът (или неуспял запис) да публикува старата чернова. */
		if ( isset( $_POST['cfg'] ) ) {
			$d = json_decode( (string) wp_unslash( $_POST['cfg'] ), true );
			if ( ! is_array( $d ) ) { wp_send_json_error( array( 'code' => 'json', 'msg' => 'Невалиден JSON.' ), 400 ); }
			Config::save_draft( $d );
		}
		$checks = Doctor::checks( Config::get_draft() ); $bad = array_values( array_filter( $checks, function ( $c ) { return 'bad' === $c['level']; } ) );
		if ( $bad && empty( $_POST['force'] ) ) { wp_send_json_error( array( 'code' => 'doctor', 'msg' => 'Doctor спира публикуването.', 'checks' => $checks ) ); }
		$ok = Config::publish();
		wp_send_json_success( array( 'published' => $ok, 'cfg' => self::out( Config::get_draft() ), 'state' => self::state() ) );
	}
	public static function ajax_discard() { self::auth(); Config::discard_draft(); wp_send_json_success( array( 'cfg' => self::out( Config::get_draft() ), 'state' => self::state() ) ); }
	public static function ajax_restore() {
		self::auth(); $v = (int) ( $_POST['version'] ?? 0 ); $r = Config::restore( $v );
		if ( null === $r ) { wp_send_json_error( array( 'code' => 'version', 'msg' => 'Няма такава версия.' ), 404 ); }
		wp_send_json_success( array( 'cfg' => self::out( $r ), 'state' => self::state() ) );
	}

	public static function page() {
		if ( ! current_user_can( Plugin::CAP ) ) { wp_die( 'Нямаш права.' ); }
		$draft = Config::get_draft();
		echo '<div id="apEd" class="ap-ed" data-ver="' . esc_attr( ANSA_PROMO_VER ) . '">';
		if ( empty( $draft['page_id'] ) ) { echo '<div class="ap-ed-nopage"><h2>✏️ Редактор</h2><p>Първо избери страницата с <code>[ansa_promo]</code> в <a href="' . esc_url( Admin::url( array( 'tab' => 'tools' ) ) ) . '">🧰 Инструменти</a> — канвасът е самата страница.</p></div>'; }
		echo '<div class="ap-ed-top" id="apEdTop"></div><div class="ap-ed-body"><aside class="ap-ed-rail" id="apEdRail"></aside><main class="ap-ed-canvas" id="apEdCanvas"></main><aside class="ap-ed-insp" id="apEdInsp"></aside></div><div class="ap-ed-toast" id="apEdToast" hidden></div></div>';
	}
}
