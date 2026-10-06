<?php
/**
 * ansa™ Промо — конфигурация (чернова / публикувано / история).
 *
 * Един option `ansa_promo_store` = { draft, published, published_at, version, history[] }.
 * Черновата се пази при всеки запис; „Публикувай“ копира черновата, вдига version, добавя в history (последните 10).
 * Фронтът чете published; админ с ?ansa_promo=draft чете draft. Всичко минава през normalize() — UI-ят никога не дрейфва.
 *
 * @package AnsaPromo
 * @since 1.0.2
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Config {

	const OPTION   = Plugin::OPTION;
	const HISTORY  = 10;
	private static $memory = null;

	public static function defaults() {
		return array(
			'id'        => 'orange_q4_2026',
			'name'      => 'ansa™ Розово Тримесечие',
			'enabled'   => false,
			'deadline'  => '2026-12-31 23:59',
			'page_id'   => 0,
			'utm_param' => 'utm_content',
			/* images: снимките на плочките в попъпа (4 награди + 3 кутии) — attachment id или URL; празно = примерната снимка от assets/img/gate/ */
			'gate'      => array( 'enabled' => true, 'required' => false, 'consent_default' => true, 'images' => self::gate_image_defaults() ),
			'timer'     => array( 'minutes' => 15, 'warn_under' => 3 ),
			'ship'      => array( 'paid' => 2.55 ),
			/* продуктите в играта — key = UTM стойност; цената, наличността и (по подразбиране) снимката идват от WC при рендиране */
			'products'  => array(),
			/* FIT: key => [key, key, key] — препоръчани към основния */
			'fit'       => array(),
			/* „С какво да ти помогнем?“ — проблем → продукт (по един продукт на проблем) */
			'problems'  => array(),
			'boxes'     => array(
				/* v1.0.13 (човекът, 06.10): Малка −15% · 1× яхта; Средна −30% · 3× яхта + книга + доставка (без сет); Голяма без промяна */
				array( 'id' => 's', 'ic' => '📦', 'name' => 'Малка кутия',  'packs' => 1, 'pct' => 15, 'tickets' => 1, 'rw' => array( 'tix' ),                 'no' => array( 'book', 'ship', 'cosm1' ), 'tag' => '',                                 'cosm_pay' => null ),
				array( 'id' => 'm', 'ic' => '🎁', 'name' => 'Средна кутия', 'packs' => 3, 'pct' => 30, 'tickets' => 3, 'rw' => array( 'tix3', 'book', 'ship' ), 'no' => array( 'cosm1' ),                 'tag' => 'Най-популярна',                       'cosm_pay' => null ),
				array( 'id' => 'l', 'ic' => '👑', 'name' => 'Голяма кутия', 'packs' => 5, 'pct' => 40, 'tickets' => 5, 'rw' => array( 'tix5', 'cosm1', 'book', 'ship' ),  'no' => array(),                           'tag' => 'Най-изгодна · спестяваш най-много', 'cosm_pay' => 1 ),
			),
			'order'     => array( 'desktop' => array( 's', 'l', 'm' ), 'mobile' => array( 'l', 'm', 's' ) ),
			'rewards'   => array(
				'yacht' => array( 'value' => 8800 ),
				'book'  => array( 'product_id' => 0, 'value' => 19 ),
				'cosm'  => array( 'product_id' => 0, 'value' => 129 ),
				'icons' => array( 'tix' => '🎟️', 'tix3' => '🎟️', 'tix5' => '🎟️', 'ship' => '🚚', 'book' => '📖', 'cosm50' => '🌸', 'cosm1' => '👑' ),
			),
			'secret'    => array( 'enabled' => false, 'pct' => 35, 'count' => 3 ),
			'checkout'  => array( 'card_gateway' => 'fkwcs_stripe', 'cod_gateway' => 'cod', 'block_coupons' => true, 'consent_default' => true ),
			'tickets'   => array( 'portal_url' => '', 'secret' => '', 'partner_bonus' => 1 ),
			'bgn'       => array( 'show' => true, 'rate' => 1.95583 ),
			/* v1.0.3: страницата на цял екран — темплейтът „ansa™ Промо — цял екран“ + скриване на елементи на темата по селектори */
			'theme'     => array( 'hide' => true, 'selectors' => self::default_hide_selectors() ),
			'copy'      => array(),
		);
	}

	/** v1.0.13: новата структура на наградите. Еднократно пренаписва кутиите s/m в черновата и публикуваното, ако още са със
	 *  старите дефолти (Малка −20% · [tix]; Средна −30% · [tix3, cosm50, book, ship]); ръчно променени кутии не се пипат. */
	public static function maybe_migrate() {
		if ( get_option( 'ansa_promo_rw_v2' ) ) { return; }
		$s = self::read_store(); $changed = false; $d = self::defaults();
		$old = array( 's' => array( 'pct' => 20.0, 'rw' => array( 'tix' ) ), 'm' => array( 'pct' => 30.0, 'rw' => array( 'tix3', 'cosm50', 'book', 'ship' ) ) );
		foreach ( array( 'draft', 'published' ) as $slot ) {
			if ( ! is_array( $s[ $slot ] ) || empty( $s[ $slot ]['boxes'] ) || ! is_array( $s[ $slot ]['boxes'] ) ) { continue; }
			foreach ( $s[ $slot ]['boxes'] as $i => $b ) {
				$id = is_array( $b ) ? (string) ( $b['id'] ?? '' ) : '';
				if ( ! isset( $old[ $id ] ) ) { continue; }
				if ( (float) ( $b['pct'] ?? 0 ) !== $old[ $id ]['pct'] || array_values( array_map( 'strval', (array) ( $b['rw'] ?? array() ) ) ) !== $old[ $id ]['rw'] ) { continue; }
				foreach ( $d['boxes'] as $db ) { if ( $db['id'] === $id ) { foreach ( array( 'pct', 'rw', 'no', 'cosm_pay' ) as $f ) { $s[ $slot ]['boxes'][ $i ][ $f ] = $db[ $f ]; } $changed = true; } }
			}
		}
		if ( $changed ) { self::write_store( $s ); Catalog::purge_runtime(); $pub = self::get_published(); if ( ! empty( $pub['page_id'] ) ) { self::purge_page( (int) $pub['page_id'] ); } }
		update_option( 'ansa_promo_rw_v2', $changed ? 'migrated' : 'noop', false );
	}

	/** Плочките със снимка (имейл-попъп + картите на кутиите): ключ → етикет за админа. */
	public static function gate_image_slots() {
		return array( 'yacht' => 'Почивка с яхта', 'cosm' => 'Козметичен сет', 'book' => 'Книга с рецепти', 'pct' => 'Отстъпка', 'ship' => 'Безплатна доставка', 'box_s' => 'Малка кутия', 'box_m' => 'Средна кутия', 'box_l' => 'Голяма кутия' );
	}
	public static function gate_image_defaults() { return array_fill_keys( array_keys( self::gate_image_slots() ), '' ); }

	/** Хедър/футър/плаващи бутони на OceanWP, Elementor, FunnelKit Cart, Claspo — скриват се на промо страницата. */
	public static function default_hide_selectors() {
		return "#site-header\n#site-header-sticky-wrapper\n.oceanwp-mobile-menu-icon\n#footer\n#site-footer\n.site-footer\n.page-header\nheader.elementor-location-header\nfooter.elementor-location-footer\n[data-elementor-type=\"header\"]\n[data-elementor-type=\"footer\"]\n.fkcart-floating-toggler\n#fkcart-floating-toggler\n.fkcart-toggler\n#scroll-top\n.claspo-widget\n#elementor-popup-modal";
	}

	public static function product_defaults() {
		return array( 'key' => '', 'product_id' => 0, 'ph' => '🌿', 'img' => 0, 'name' => '', 'gname' => '', 'gsub' => '', 'ds' => '', 'desc' => '', 'ing' => '', 'who' => '', 'rating' => '', 'reviews' => '', 'cat' => '', 'pack' => '', 'theme' => array( '#fff0f6', '#ffd1e3', '#8a1147' ), 'enabled' => true );
	}

	public static function normalize( $data ) {
		$d = self::defaults();
		if ( ! is_array( $data ) ) { return $d; }
		$out = self::merge( $d, $data );
		$out['id']        = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $out['id'] ) ?: $d['id'];
		$out['name']      = sanitize_text_field( (string) $out['name'] );
		$out['enabled']   = ! empty( $out['enabled'] );
		$out['deadline']  = trim( (string) $out['deadline'] );
		$out['page_id']   = (int) $out['page_id'];
		$out['utm_param'] = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $out['utm_param'] ) ?: 'utm_content';
		$out['gate']['enabled'] = ! empty( $out['gate']['enabled'] ); $out['gate']['required'] = ! empty( $out['gate']['required'] ); $out['gate']['consent_default'] = ! empty( $out['gate']['consent_default'] );
		$imgs = array();
		foreach ( self::gate_image_slots() as $k => $label ) { $v = trim( (string) ( is_array( $out['gate']['images'] ?? null ) ? ( $out['gate']['images'][ $k ] ?? '' ) : '' ) ); $imgs[ $k ] = ctype_digit( $v ) ? (string) (int) $v : esc_url_raw( $v ); }
		$out['gate']['images'] = $imgs;
		$out['timer']['minutes'] = max( 0, (int) $out['timer']['minutes'] ); $out['timer']['warn_under'] = max( 0, (int) $out['timer']['warn_under'] );
		$out['ship']['paid'] = round( (float) $out['ship']['paid'], 2 );
		/* products */
		$prods = array(); $seen = array();
		foreach ( (array) $out['products'] as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$p = self::merge( self::product_defaults(), $p );
			$p['key'] = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $p['key'] ) );
			$p['product_id'] = (int) $p['product_id']; $p['img'] = (int) $p['img'];
			if ( '' === $p['key'] && $p['product_id'] ) { $p['key'] = 'p' . $p['product_id']; }
			if ( '' === $p['key'] || isset( $seen[ $p['key'] ] ) ) { continue; }
			$seen[ $p['key'] ] = 1;
			foreach ( array( 'ph', 'name', 'gname', 'gsub', 'ds', 'ing', 'who', 'rating', 'cat', 'pack' ) as $f ) { $p[ $f ] = sanitize_text_field( (string) $p[ $f ] ); }
			$p['desc'] = wp_kses( (string) $p['desc'], array( 'b' => array(), 'strong' => array(), 'em' => array(), 'br' => array() ) );
			$p['reviews'] = (string) $p['reviews'];
			$p['theme'] = array_values( array_map( function ( $c ) { return preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $c ) ? $c : '#ffffff'; }, array_pad( array_slice( (array) $p['theme'], 0, 3 ), 3, '#ffffff' ) ) );
			$p['enabled'] = ! empty( $p['enabled'] );
			$prods[] = $p;
		}
		$out['products'] = $prods;
		/* fit: само валидни ключове */
		$fit = array();
		foreach ( (array) $out['fit'] as $k => $ks ) { if ( isset( $seen[ $k ] ) ) { $fit[ $k ] = array_values( array_filter( array_map( 'strval', (array) $ks ), function ( $x ) use ( $seen, $k ) { return isset( $seen[ $x ] ) && $x !== $k; } ) ); } }
		$out['fit'] = $fit;
		/* problems */
		$pr = array();
		foreach ( (array) $out['problems'] as $x ) { if ( is_array( $x ) && isset( $seen[ (string) ( $x['key'] ?? '' ) ] ) ) { $pr[] = array( 't' => sanitize_text_field( (string) ( $x['t'] ?? '' ) ), 'sub' => sanitize_text_field( (string) ( $x['sub'] ?? '' ) ), 'why' => sanitize_text_field( (string) ( $x['why'] ?? '' ) ), 'key' => (string) $x['key'] ); } }
		$out['problems'] = $pr;
		/* boxes: винаги 3, с фиксирани id */
		$bx = array(); $byid = array();
		foreach ( (array) $out['boxes'] as $b ) { if ( is_array( $b ) && isset( $b['id'] ) ) { $byid[ $b['id'] ] = $b; } }
		foreach ( $d['boxes'] as $db ) {
			$b = isset( $byid[ $db['id'] ] ) ? self::merge( $db, $byid[ $db['id'] ] ) : $db;
			$b['id'] = $db['id'];
			$b['name'] = sanitize_text_field( (string) $b['name'] ) ?: $db['name']; $b['ic'] = sanitize_text_field( (string) $b['ic'] ) ?: $db['ic']; $b['tag'] = sanitize_text_field( (string) $b['tag'] );
			$b['packs'] = max( 1, min( 12, (int) $b['packs'] ) ); $b['pct'] = max( 0, min( 90, (float) $b['pct'] ) ); $b['tickets'] = max( 0, (int) $b['tickets'] );
			$b['rw'] = array_values( array_filter( array_map( 'strval', (array) $b['rw'] ) ) ); $b['no'] = array_values( array_filter( array_map( 'strval', (array) $b['no'] ) ) );
			$b['cosm_pay'] = ( null === $b['cosm_pay'] || '' === $b['cosm_pay'] ) ? null : round( (float) $b['cosm_pay'], 2 );
			$bx[] = $b;
		}
		$out['boxes'] = $bx;
		foreach ( array( 'desktop', 'mobile' ) as $dev ) {
			$ord = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $out['order'][ $dev ] ?? array() ) ), function ( $x ) { return in_array( $x, array( 's', 'm', 'l' ), true ); } ) ) );
			$out['order'][ $dev ] = count( $ord ) === 3 ? $ord : $d['order'][ $dev ];
		}
		$out['rewards']['yacht']['value'] = (float) $out['rewards']['yacht']['value'];
		$out['rewards']['book']['product_id'] = (int) $out['rewards']['book']['product_id']; $out['rewards']['book']['value'] = (float) $out['rewards']['book']['value'];
		$out['rewards']['cosm']['product_id'] = (int) $out['rewards']['cosm']['product_id']; $out['rewards']['cosm']['value'] = (float) $out['rewards']['cosm']['value'];
		$out['secret']['pct'] = max( 0, min( 90, (float) $out['secret']['pct'] ) ); $out['secret']['count'] = max( 0, min( 6, (int) $out['secret']['count'] ) ); $out['secret']['enabled'] = ! empty( $out['secret']['enabled'] );
		$out['checkout']['card_gateway'] = sanitize_key( (string) $out['checkout']['card_gateway'] ); $out['checkout']['cod_gateway'] = sanitize_key( (string) $out['checkout']['cod_gateway'] ); $out['checkout']['block_coupons'] = ! empty( $out['checkout']['block_coupons'] ); $out['checkout']['consent_default'] = ! empty( $out['checkout']['consent_default'] );
		$out['tickets']['portal_url'] = esc_url_raw( (string) $out['tickets']['portal_url'] ); $out['tickets']['secret'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $out['tickets']['secret'] ); $out['tickets']['partner_bonus'] = max( 0, (int) $out['tickets']['partner_bonus'] );
		$out['bgn']['show'] = ! empty( $out['bgn']['show'] ); $out['bgn']['rate'] = (float) $out['bgn']['rate'] ?: 1.95583;
		$out['theme']['hide'] = ! empty( $out['theme']['hide'] ); $out['theme']['selectors'] = implode( "\n", array_filter( array_map( function ( $l ) { return trim( preg_replace( '/[{}<>]/', '', (string) $l ) ); }, preg_split( '/\r?\n/', (string) $out['theme']['selectors'] ) ) ) );
		$out['copy'] = Copy::diff( is_array( $out['copy'] ) ? $out['copy'] : array() );
		return $out;
	}

	private static function merge( $base, $over ) {
		if ( ! is_array( $over ) ) { return $base; }
		foreach ( $over as $k => $v ) {
			if ( isset( $base[ $k ] ) && is_array( $base[ $k ] ) && is_array( $v ) && self::is_assoc( $base[ $k ] ) && self::is_assoc( $v ) ) { $base[ $k ] = self::merge( $base[ $k ], $v ); }
			else { $base[ $k ] = $v; }
		}
		return $base;
	}
	private static function is_assoc( $a ) { if ( ! is_array( $a ) || array() === $a ) { return false; } return array_keys( $a ) !== range( 0, count( $a ) - 1 ); }

	/* ── store ── */
	private static function read_store() {
		if ( null !== self::$memory ) { return self::$memory; }
		$s = get_option( self::OPTION, null );
		if ( ! is_array( $s ) ) { $s = array( 'draft' => null, 'published' => null, 'published_at' => 0, 'version' => 0, 'history' => array() ); }
		if ( ! isset( $s['history'] ) || ! is_array( $s['history'] ) ) { $s['history'] = array(); }
		self::$memory = $s; return $s;
	}
	private static function write_store( $s ) { self::$memory = $s; update_option( self::OPTION, $s, false ); }
	public static function reset_memory() { self::$memory = null; }
	public static function store_exists() { return is_array( get_option( self::OPTION, null ) ); }

	public static function get_draft() { $s = self::read_store(); return self::normalize( is_array( $s['draft'] ) ? $s['draft'] : ( is_array( $s['published'] ) ? $s['published'] : array() ) ); }
	public static function get_published() { $s = self::read_store(); return self::normalize( is_array( $s['published'] ) ? $s['published'] : array() ); }
	public static function has_published() { $s = self::read_store(); return is_array( $s['published'] ); }
	public static function has_draft() { $s = self::read_store(); return is_array( $s['draft'] ); }
	public static function version() { $s = self::read_store(); return (int) $s['version']; }
	public static function published_at() { $s = self::read_store(); return (int) $s['published_at']; }
	public static function history() { $s = self::read_store(); return array_map( function ( $h ) { return array( 'version' => (int) $h['version'], 'at' => (int) $h['at'] ); }, $s['history'] ); }

	public static function save_draft( $data ) { $s = self::read_store(); $s['draft'] = self::normalize( $data ); $s['draft_at'] = time(); self::write_store( $s ); return $s['draft']; }
	public static function publish() {
		$s = self::read_store();
		$src = is_array( $s['draft'] ) ? $s['draft'] : $s['published'];
		if ( ! is_array( $src ) ) { return false; }
		if ( is_array( $s['published'] ) ) { array_unshift( $s['history'], array( 'version' => (int) $s['version'], 'at' => (int) $s['published_at'], 'cfg' => $s['published'] ) ); $s['history'] = array_slice( $s['history'], 0, self::HISTORY ); }
		$s['published'] = self::normalize( $src ); $s['draft'] = null; $s['published_at'] = time(); $s['version'] = (int) $s['version'] + 1;
		self::write_store( $s );
		self::after_publish( $s['published'] );
		return true;
	}
	public static function discard_draft() { $s = self::read_store(); $s['draft'] = null; self::write_store( $s ); }
	/** Връща историческа версия като нова чернова. */
	public static function restore( $version ) {
		$s = self::read_store();
		foreach ( $s['history'] as $h ) { if ( (int) $h['version'] === (int) $version && is_array( $h['cfg'] ) ) { return self::save_draft( $h['cfg'] ); } }
		return null;
	}

	/** JSON за export/import (черновата). */
	public static function export() { return wp_json_encode( self::get_draft(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); }
	public static function import( $json ) { $d = json_decode( (string) $json, true ); if ( ! is_array( $d ) ) { return null; } return self::save_draft( $d ); }

	private static function after_publish( $cfg ) {
		Catalog::purge_runtime();
		if ( ! empty( $cfg['page_id'] ) ) { self::purge_page( (int) $cfg['page_id'] ); }
		do_action( 'ansa_promo_published', $cfg );
	}
	/** Purge само на URL-а на страницата — никога purge_everything. */
	public static function purge_page( $page_id ) {
		$url = $page_id ? get_permalink( (int) $page_id ) : '';
		if ( ! $url ) { return; }
		if ( class_exists( '\\FlyingPress\\Purge' ) ) { try { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( $url ); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_urls' ) ) ) { \FlyingPress\Purge::purge_urls( array( $url ) ); } } catch ( \Throwable $e ) {} }
		if ( function_exists( 'rocket_clean_post' ) ) { try { rocket_clean_post( (int) $page_id ); } catch ( \Throwable $e ) {} }
		do_action( 'ansa_promo_purge_url', $url, (int) $page_id );
	}

	/* ── помощници ── */
	public static function product( $cfg, $key ) { foreach ( $cfg['products'] as $p ) { if ( $p['key'] === $key ) { return $p; } } return null; }
	public static function box( $cfg, $id ) { foreach ( $cfg['boxes'] as $b ) { if ( $b['id'] === $id ) { return $b; } } return null; }
	public static function max_pct( $cfg ) { $m = 0; foreach ( $cfg['boxes'] as $b ) { $m = max( $m, (float) $b['pct'] ); } return $m; }
	public static function deadline_ts( $cfg ) { if ( empty( $cfg['deadline'] ) ) { return 0; } $t = strtotime( $cfg['deadline'] . ' ' . wp_timezone_string() ); return $t ? (int) $t : 0; }
	public static function is_live( $cfg ) {
		if ( empty( $cfg['enabled'] ) ) { return false; }
		$t = self::deadline_ts( $cfg ); if ( $t && time() > $t ) { return false; }
		return true;
	}
}
