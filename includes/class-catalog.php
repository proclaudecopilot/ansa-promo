<?php
/**
 * ansa™ Промо — WC адаптери.
 *
 * wc_line(product_id): кой ред се продава („1 брой“). Правило от Shrine: при variable родител се взема вариацията,
 * чийто атрибут съдържа първото число 1 (rawurldecode, никога sanitize_text_field върху ключове); иначе най-малкото число;
 * иначе първата активна. Цената е regular (sale се носи отделно), наличност = in_stock && purchasable, снимка = вариация → родител.
 * v1.26.2: редът е винаги ЕДНА опаковка — SKU/ID към вариация с повече („3 кутии“) се заменя с „1 опаковка“ на родителя (single_line),
 * иначе кутията с 5 опаковки ставаше 5 × 3 кутии; packs_of() казва колко опаковки е един ред.
 * Runtime JSON-ът на публикуваната версия се кешира в transient за 5 мин и се purge-ва при промяна на продукт/публикуване.
 *
 * @package AnsaPromo
 * @since 1.0.2
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	const RUNTIME_TTL = 5 * MINUTE_IN_SECONDS;

	public static function register() {
		foreach ( array( 'woocommerce_update_product', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_update_product_variation', 'save_post_product' ) as $h ) {
			add_action( $h, array( __CLASS__, 'purge_runtime' ) );
		}
	}

	/** v1.19.0: ключът носи и седмицата на сезона — продуктовите проценти в кеша са на седмицата (0 = без седмици). */
	public static function runtime_key( $version, $week = 0 ) { return 'ansa_promo_runtime_' . (int) $version . ( $week ? '_w' . (int) $week : '' ); }
	public static function purge_runtime() {
		$nw = 0; try { $nw = Config::weekly_on( Config::get_published() ) ? Config::season_count( Config::get_published() ) : 0; } catch ( \Throwable $e ) {}
		for ( $v = max( 0, Config::version() - 1 ); $v <= Config::version() + 1; $v++ ) { for ( $w = 0; $w <= $nw + 1; $w++ ) { delete_transient( self::runtime_key( $v, $w ) ); } }
		update_option( 'ansa_promo_runtime_stamp', time(), false );
	}

	/** v1.26.2: колко опаковки е един WC ред (0 = не личи): първото число (1–2 цифри) в атрибутите на вариацията (rawurldecode),
	 *  иначе в опашката на името ѝ след името на родителя („ansa Omega 3 - 2 кутии“ → 2; цифрата в името на самия продукт не се брои). */
	private static function qty_of( $v, $parent = null ) {
		if ( ! $v || ! is_object( $v ) || ! method_exists( $v, 'is_type' ) || ! $v->is_type( 'variation' ) ) { return 0; }
		foreach ( (array) $v->get_variation_attributes() as $av ) { if ( preg_match( '/(\d{1,2})/u', rawurldecode( (string) $av ), $m ) ) { return (int) $m[1]; } }
		$name = (string) $v->get_name();
		if ( null === $parent && $v->get_parent_id() ) { $parent = wc_get_product( (int) $v->get_parent_id() ); }
		$pn = $parent ? (string) $parent->get_name() : '';
		if ( '' !== $pn && 0 === mb_strpos( $name, $pn ) ) { $name = mb_substr( $name, mb_strlen( $pn ) ); }
		return preg_match( '/(\d{1,2})/u', $name, $m2 ) ? (int) $m2[1] : 0;
	}
	/** v1.26.2: колко опаковки е един ред — 1 за прост продукт и за вариация без число; „5 кутии (3 и 2 подарък)“ → 5. */
	public static function packs_of( $product, $parent = null ) { return max( 1, self::qty_of( $product, $parent ) ); }

	/** Вариацията „1 опаковка“ на variable родител: първото число 1 в атрибутите; иначе най-малкото число; иначе първата активна.
	 *  Връща [вариация, брой (99 = не личи)] или null без активни вариации. */
	private static function pick_single( $parent ) {
		$best = null; $best_q = 99;
		foreach ( (array) $parent->get_children() as $vid ) {
			$v = wc_get_product( $vid ); if ( ! $v || 'publish' !== $v->get_status() ) { continue; }
			$q = self::qty_of( $v, $parent );
			if ( 1 === $q ) { return array( $v, 1 ); }
			if ( $q > 0 && $q < $best_q ) { $best = $v; $best_q = $q; }
			if ( ! $best && 0 === $q ) { $best = $v; }
		}
		return $best ? array( $best, $best_q ) : null;
	}

	/**
	 * v1.26.2 (човекът: „в UI добавям 3 плюс 2 подарък, а в количката се добавят 5 пъти по 3 кутии“): редът на промото е ВИНАГИ една
	 * опаковка — кутията с 5 опаковки е 5 × „1 кутия“. SKU/ID в 📦 Продукти, който сочи към вариация с повече опаковки („3 кутии“), даваше
	 * 5 × „3 кутии“ на −40% (eyeFresh: €161,75 за 15 кутии вместо €54). Вариация с q > 1 → вариацията „1 опаковка“ на родителя, ако има
	 * такава; иначе редът остава (продукт само в по-големи опаковки или „30/60 капсули“ без „1“). Връща [ред, бележка|''].
	 */
	public static function single_line( $product ) {
		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variation' ) ) { return array( $product, '' ); }
		$parent = $product->get_parent_id() ? wc_get_product( (int) $product->get_parent_id() ) : null;
		$q = self::qty_of( $product, $parent );
		if ( $q <= 1 ) { return array( $product, '' ); }
		$one = $parent && $parent->is_type( 'variable' ) ? self::pick_single( $parent ) : null;
		if ( ! $one || 1 !== (int) $one[1] || (int) $one[0]->get_id() === (int) $product->get_id() ) { return array( $product, '' ); }
		return array( $one[0], 'SKU/ID сочи към „' . $product->get_name() . '“ (' . $q . ' опаковки) — редът е „' . $one[0]->get_name() . '“' );
	}

	/** Цена/снимка/наличност от WC за даден (родителски или вариационен) продукт. */
	public static function wc_line( $product_id ) {
		$out = array( 'ok' => false, 'price' => 0.0, 'sale' => null, 'img' => '', 'wc_name' => '', 'stock' => false, 'variation_id' => 0, 'product_id' => (int) $product_id, 'note' => '', 'short' => '', 'title' => '', 'subtitle' => '', 'base_name' => '' );
		if ( ! function_exists( 'wc_get_product' ) ) { $out['note'] = 'WooCommerce липсва'; return $out; }
		$p = $product_id ? wc_get_product( (int) $product_id ) : null;
		if ( ! $p ) { $out['note'] = 'няма такъв продукт'; return $out; }
		$out['wc_name'] = $p->get_name();
		$src = $p;
		if ( $p->is_type( 'variation' ) ) {
			$out['product_id'] = (int) $p->get_parent_id();
			/* v1.26.2: SKU/ID към „3 кутии“ → редът е „1 кутия“ на родителя (виж single_line); бележката излиза в 📦 Продукти */
			list( $src, $note ) = self::single_line( $p );
			$out['variation_id'] = $src->get_id(); $out['wc_name'] = $src->get_name();
			if ( '' !== $note ) { $out['note'] = $note; }
		} elseif ( $p->is_type( 'variable' ) ) {
			$one = self::pick_single( $p );
			if ( ! $one ) { $out['note'] = 'variable без активни вариации'; return $out; }
			$src = $one[0]; $out['variation_id'] = $src->get_id();
			if ( 1 !== $one[1] ) { $out['note'] = 'няма вариация „1 брой“ — взета е ' . $src->get_name(); }
		}
		$reg = (float) $src->get_regular_price(); $sale = $src->get_sale_price();
		$out['price'] = $reg > 0 ? $reg : (float) $src->get_price();
		$out['sale']  = ( '' !== $sale && null !== $sale ) ? (float) $sale : null;
		$out['stock'] = $src->is_in_stock() && $src->is_purchasable();
		$img_id = $src->get_image_id() ?: $p->get_image_id();
		if ( ! $img_id && $p->is_type( 'variation' ) ) { $parent = wc_get_product( $p->get_parent_id() ); if ( $parent ) { $img_id = $parent->get_image_id(); } }
		$out['img'] = $img_id ? (string) wp_get_attachment_image_url( (int) $img_id, 'medium' ) : '';
		/* v1.0.25: „истинските“ текстове на продукта — краткото описание на WC (това показва и продуктовата страница на Shrine)
		   и заглавието/подзаглавието, ако Shrine ги е презаписал (_ansa_title_override / _ansa_title_main / _ansa_title_subtitle) */
		$base = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
		if ( $base ) {
			$out['base_name'] = trim( wp_strip_all_tags( (string) $base->get_name() ) );
			$short = trim( wp_strip_all_tags( (string) $base->get_short_description() ) );
			if ( '' === $short ) { $short = trim( wp_strip_all_tags( (string) $src->get_description() ) ); }
			$out['short'] = preg_replace( '/\s+/u', ' ', $short );
			if ( 'yes' === get_post_meta( $base->get_id(), '_ansa_title_override', true ) ) {
				$out['title']    = trim( (string) get_post_meta( $base->get_id(), '_ansa_title_main', true ) );
				$out['subtitle'] = trim( (string) get_post_meta( $base->get_id(), '_ansa_title_subtitle', true ) );
			}
		}
		$out['ok']    = $out['price'] > 0;
		if ( ! $out['ok'] ) { $out['note'] = 'цена 0'; }
		return $out;
	}

	/** v1.0.26: продукт/вариация по SKU (вариацията „1 опаковка“) — 0, ако няма. */
	public static function find_by_sku( $sku ) {
		$sku = trim( (string) $sku ); if ( '' === $sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) { return 0; }
		return (int) wc_get_product_id_by_sku( $sku );
	}

	/** Търсене на продукт по име (за seed и за ⚙️ Двигател). Връща [{id, name, type, price}] */
	public static function search( $term, $limit = 8 ) {
		if ( ! function_exists( 'wc_get_products' ) ) { return array(); }
		$ids = wc_get_products( array( 'status' => 'publish', 'limit' => $limit, 's' => $term, 'return' => 'ids', 'orderby' => 'relevance' ) );
		$out = array();
		foreach ( (array) $ids as $id ) { $p = wc_get_product( $id ); if ( ! $p ) { continue; } $out[] = array( 'id' => (int) $id, 'name' => $p->get_name(), 'type' => $p->get_type(), 'price' => (float) $p->get_price() ); }
		return $out;
	}

	/** Най-добро съвпадение по име: точно → започва с → съдържа. 0 ако нищо не изглежда сигурно. */
	public static function find_by_name( $name ) {
		$name = trim( (string) $name ); if ( '' === $name ) { return 0; }
		$cands = self::search( $name, 10 );
		$n = mb_strtolower( $name );
		foreach ( $cands as $c ) { if ( mb_strtolower( $c['name'] ) === $n ) { return $c['id']; } }
		foreach ( $cands as $c ) { if ( 0 === mb_strpos( mb_strtolower( $c['name'] ), $n ) ) { return $c['id']; } }
		foreach ( $cands as $c ) { if ( false !== mb_strpos( mb_strtolower( $c['name'] ), $n ) ) { return $c['id']; } }
		return 0;
	}
}
