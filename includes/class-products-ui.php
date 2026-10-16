<?php
/**
 * ansa™ Промо — 📦 Продукти (v1.15.0): приложение в стила на ansa™ Shrine (Partner тема).
 *
 * Човекът (16.10.2026): „Това е кошмарен UI/UX… не се разбира кое е включено в промото, кое не, няма търсачка да добавиш
 * още продукти. Искам тотално нов UI/UX с пълна функционалност както е ansa Shrine!“
 *
 * Fullscreen екран върху WP админа (таб 📦 Продукти): вляво списък с търсене, филтри (всички / в промото / изключени / проблем)
 * и drag & drop ред; вдясно редактор с табове 🔗 WooCommerce · ✏️ Текстове · 💸 Отстъпки · ❓ Проблем & FIT · 🎨 Още, Doctor чипове,
 * превключвател „В промото“, 💾 Запази (Ctrl+S) и 🚀 Публикувай (Doctor gate като в редактора). „＋ Добави продукт“ = търсачка в WC.
 *
 * Данните: ajax products_get → {products[+wc], boxes, problems, fit, live, published, state}; products_save → цялата чернова
 * (products/problems/fit през Config::normalize) и връща записаното (UI-ят не дрейфва); products_wc → жив WC ред за ID/SKU;
 * products_publish → Doctor (bad спира, force минава) → Config::publish. Търсачката е Admin::ajax_search (ansa_promo_admin_search).
 *
 * @package AnsaPromo
 * @since 1.15.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Products_UI {

	const NONCE = 'ansa_promo_products';

	public static function register() {
		foreach ( array( 'get', 'save', 'publish', 'wc' ) as $a ) { add_action( 'wp_ajax_ansa_promo_products_' . $a, array( __CLASS__, 'ajax_' . $a ) ); }
	}

	/** Скриптовете само на таб 📦 Продукти (Admin::enqueue). */
	public static function enqueue() {
		if ( function_exists( 'wp_enqueue_media' ) ) { wp_enqueue_media(); }
		wp_enqueue_style( 'ansa-promo-nunito-admin', 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;800;900&display=swap', array(), null );
		wp_enqueue_style( 'ansa-promo-products', ANSA_PROMO_URL . 'assets/products-admin.css', array( 'ansa-promo-nunito-admin' ), ANSA_PROMO_VER );
		wp_enqueue_script( 'ansa-promo-products', ANSA_PROMO_URL . 'assets/products-admin.js', array(), ANSA_PROMO_VER, true );
		$draft = Config::get_draft(); $page_url = $draft['page_id'] ? get_permalink( (int) $draft['page_id'] ) : '';
		wp_localize_script( 'ansa-promo-products', 'AnsaPromoProducts', array(
			'ajax'         => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::NONCE ),
			'search_nonce' => wp_create_nonce( Admin::NONCE ),
			'ver'          => ANSA_PROMO_VER,
			'back'         => Admin::url(),
			'tools'        => Admin::url( array( 'tab' => 'tools' ) ),
			'editor'       => Editor::url(),
			'page_url'     => $page_url,
			'draft_url'    => $page_url ? add_query_arg( 'ansa_promo', 'draft', $page_url ) : '',
			'data'         => self::payload( $draft ),
		) );
	}

	public static function render() { echo '<div id="ansa-promo-products" class="app-loading"><div class="app-loading__t">📦 Зареждам продуктите…</div></div>'; }

	private static function auth() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'auth', 'msg' => 'Нямаш права или сесията изтече — презареди.' ), 403 ); }
	}

	/** Всичко, което екранът показва. */
	public static function payload( $cfg = null ) {
		$cfg = $cfg ?: Config::get_draft(); $pub = Config::get_published();
		$live = Frontend::products( $cfg );
		$published = array(); foreach ( (array) $pub['products'] as $p ) { $published[ $p['key'] ] = ! empty( $p['enabled'] ); }
		$live_pub = array_keys( Frontend::products( $pub ) );
		$products = array();
		foreach ( (array) $cfg['products'] as $p ) {
			$p['wc'] = self::wc_info( (int) $p['product_id'], (string) $p['sku'] );
			$p['img_url'] = $p['img'] ? (string) wp_get_attachment_image_url( (int) $p['img'], 'medium' ) : '';
			$p['pct'] = (object) $p['pct'];
			$products[] = $p;
		}
		$boxes = array(); foreach ( (array) $cfg['boxes'] as $b ) { $boxes[] = array( 'id' => $b['id'], 'ic' => $b['ic'], 'name' => $b['name'], 'pct' => (float) $b['pct'], 'packs' => (int) $b['packs'] ); }
		return array(
			'products'  => $products,
			'boxes'     => $boxes,
			'problems'  => array_values( (array) $cfg['problems'] ),
			'fit'       => (object) $cfg['fit'],
			'live'      => array_keys( $live ),
			'published' => (object) $published,
			'live_pub'  => $live_pub,
			'utm_param' => (string) $cfg['utm_param'],
			'state'     => array( 'version' => Config::version(), 'has_draft' => Config::has_draft(), 'published_at' => Config::published_at(), 'live' => Config::is_live( $pub ), 'enabled' => ! empty( $pub['enabled'] ) ),
		);
	}

	/** Живият WC ред за продукт (SKU печели над ID) + текстовете, които WC би дал. */
	public static function wc_info( $pid, $sku ) {
		$sku = trim( (string) $sku ); $by_sku = '' !== $sku ? Catalog::find_by_sku( $sku ) : 0;
		$rpid = '' !== $sku ? ( $by_sku ?: (int) $pid ) : (int) $pid;
		$out = array( 'pid' => (int) $rpid, 'ok' => false, 'note' => 'не е свързан', 'name' => '', 'base' => '', 'price' => 0.0, 'sale' => null, 'stock' => false, 'vid' => 0, 'img' => '', 'texts' => array( 'name' => '', 'ds' => '', 'desc' => '' ), 'sku_found' => '' !== $sku ? (bool) $by_sku : null, 'edit' => '', 'url' => '', 'parent' => 0 );
		if ( ! $rpid ) { return $out; }
		$wc = Catalog::wc_line( $rpid );
		list( $wn, $wds, $wdesc ) = Frontend::wc_texts( $wc );
		$out = array_merge( $out, array(
			'ok' => (bool) $wc['ok'], 'note' => (string) $wc['note'], 'name' => (string) $wc['wc_name'], 'base' => (string) $wc['base_name'], 'price' => (float) $wc['price'], 'sale' => $wc['sale'],
			'stock' => (bool) $wc['stock'], 'vid' => (int) $wc['variation_id'], 'img' => (string) $wc['img'], 'texts' => array( 'name' => $wn, 'ds' => $wds, 'desc' => $wdesc ), 'parent' => (int) $wc['product_id'],
			'edit' => $wc['product_id'] ? (string) get_edit_post_link( (int) $wc['product_id'], 'raw' ) : '', 'url' => $wc['product_id'] ? (string) get_permalink( (int) $wc['product_id'] ) : '',
		) );
		if ( ! $wc['wc_name'] ) { $out['note'] = 'няма такъв продукт (#' . (int) $rpid . ')'; }
		return $out;
	}

	public static function ajax_get() { self::auth(); wp_send_json_success( self::payload() ); }

	public static function ajax_wc() {
		self::auth();
		$pid = (int) ( $_POST['pid'] ?? 0 ); $sku = sanitize_text_field( (string) wp_unslash( $_POST['sku'] ?? '' ) ); $img = (int) ( $_POST['img'] ?? 0 );
		wp_send_json_success( array( 'wc' => self::wc_info( $pid, $sku ), 'img_url' => $img ? (string) wp_get_attachment_image_url( $img, 'medium' ) : '' ) );
	}

	/** Записва products / problems / fit в черновата (останалото от черновата остава). Връща записаното. */
	public static function ajax_save() {
		self::auth();
		$d = json_decode( (string) wp_unslash( $_POST['data'] ?? '' ), true );
		if ( ! is_array( $d ) || ! is_array( $d['products'] ?? null ) ) { wp_send_json_error( array( 'code' => 'json', 'msg' => 'Невалидни данни.' ), 400 ); }
		$cfg = Config::get_draft();
		$prods = array();
		foreach ( $d['products'] as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			unset( $p['wc'], $p['img_url'] );
			$p['pct'] = is_array( $p['pct'] ?? null ) ? $p['pct'] : ( is_object( $p['pct'] ?? null ) ? (array) $p['pct'] : array() );
			$p['theme'] = array_values( (array) ( $p['theme'] ?? array() ) );
			$prods[] = $p;
		}
		$cfg['products'] = $prods;
		if ( isset( $d['problems'] ) && is_array( $d['problems'] ) ) { $cfg['problems'] = array_values( $d['problems'] ); }
		if ( isset( $d['fit'] ) && ( is_array( $d['fit'] ) || is_object( $d['fit'] ) ) ) { $cfg['fit'] = (array) $d['fit']; }
		$stored = Config::save_draft( $cfg );
		wp_send_json_success( self::payload( $stored ) );
	}

	public static function ajax_publish() {
		self::auth();
		$checks = Doctor::checks( Config::get_draft() ); $bad = array_values( array_filter( $checks, function ( $c ) { return 'bad' === $c['level']; } ) );
		if ( $bad && empty( $_POST['force'] ) ) { wp_send_json_error( array( 'code' => 'doctor', 'msg' => 'Doctor спира публикуването.', 'checks' => $checks ) ); }
		$ok = Config::publish();
		wp_send_json_success( array_merge( self::payload(), array( 'published' => $ok, 'checks' => $checks ) ) );
	}
}
