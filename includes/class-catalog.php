<?php
/**
 * ansa™ Промо — WC адаптери.
 *
 * wc_line(product_id): кой ред се продава („1 брой“). Правило от Shrine: при variable родител се взема вариацията,
 * чийто атрибут съдържа първото число 1 (rawurldecode, никога sanitize_text_field върху ключове); иначе най-малкото число;
 * иначе първата активна. Цената е regular (sale се носи отделно), наличност = in_stock && purchasable, снимка = вариация → родител.
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

	public static function runtime_key( $version ) { return 'ansa_promo_runtime_' . (int) $version; }
	public static function purge_runtime() {
		for ( $v = max( 0, Config::version() - 1 ); $v <= Config::version() + 1; $v++ ) { delete_transient( self::runtime_key( $v ) ); }
		update_option( 'ansa_promo_runtime_stamp', time(), false );
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
			$out['variation_id'] = $p->get_id(); $out['product_id'] = (int) $p->get_parent_id();
		} elseif ( $p->is_type( 'variable' ) ) {
			$best = null; $best_q = 99;
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid ); if ( ! $v || 'publish' !== $v->get_status() ) { continue; }
				$q = 0;
				foreach ( $v->get_variation_attributes() as $av ) { if ( preg_match( '/(\d{1,2})/u', rawurldecode( (string) $av ), $m ) ) { $q = (int) $m[1]; break; } }
				if ( ! $q && preg_match( '/(\d{1,2})/u', $v->get_name(), $m2 ) ) { $q = (int) $m2[1]; }
				if ( 1 === $q ) { $best = $v; $best_q = 1; break; }
				if ( $q > 0 && $q < $best_q ) { $best = $v; $best_q = $q; }
				if ( ! $best && 0 === $q ) { $best = $v; }
			}
			if ( ! $best ) { $out['note'] = 'variable без активни вариации'; return $out; }
			$src = $best; $out['variation_id'] = $best->get_id();
			if ( 1 !== $best_q ) { $out['note'] = 'няма вариация „1 брой“ — взета е ' . $best->get_name(); }
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
