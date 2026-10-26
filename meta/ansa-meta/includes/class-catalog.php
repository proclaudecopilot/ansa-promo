<?php
/**
 * ansa™ Meta — custom_data за събитията: продукт, категория, търсене, количка, поръчка.
 * content_ids са в избрания формат (ID / SKU / wc_post_id_ID), за да съвпадат с каталога в Meta.
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Catalog {

	public static function currency() { return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'EUR'; }

	public static function content_id( $product ) {
		if ( ! is_object( $product ) ) return '';
		$id = (int) $product->get_id();
		switch ( (string) Settings::get( 'id_format', 'id' ) ) {
			case 'sku':        $sku = (string) $product->get_sku(); if ( '' === $sku && $product->get_parent_id() ) { $p = wc_get_product( $product->get_parent_id() ); $sku = $p ? (string) $p->get_sku() : ''; } return '' !== $sku ? $sku : (string) $id;
			case 'wc_post_id': return 'wc_post_id_' . $id;
			default:           return (string) $id;
		}
	}

	/** Цената, както я вижда клиентът (с ДДС, както е настроен магазинът). */
	public static function price( $product ) {
		if ( ! is_object( $product ) ) return 0.0;
		try { $p = function_exists( 'wc_get_price_to_display' ) ? wc_get_price_to_display( $product ) : $product->get_price(); } catch ( \Throwable $e ) { $p = $product->get_price(); }
		return round( (float) $p, 2 );
	}

	public static function category_of( $product ) {
		if ( ! is_object( $product ) ) return '';
		$pid = $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
		$terms = get_the_terms( $pid, 'product_cat' );
		if ( ! is_array( $terms ) || ! $terms ) return '';
		$names = array();
		foreach ( $terms as $t ) { if ( is_object( $t ) && ! empty( $t->name ) ) $names[] = (string) $t->name; }
		return implode( ' > ', array_slice( $names, 0, 3 ) );
	}

	public static function product_data( $product, $qty = 1 ) {
		if ( ! is_object( $product ) ) return array();
		$qty = max( 1, (int) $qty ); $id = self::content_id( $product ); $price = self::price( $product );
		$name = (string) $product->get_name();
		$d = array(
			'content_ids'      => array( $id ),
			'content_type'     => 'product',
			'content_name'     => mb_substr( $name, 0, 200 ),
			'content_category' => self::category_of( $product ),
			'value'            => round( $price * $qty, 2 ),
			'currency'         => self::currency(),
			'contents'         => array( array( 'id' => $id, 'quantity' => $qty, 'item_price' => $price ) ),
		);
		if ( $product->get_parent_id() ) $d['product_group_id'] = (string) $product->get_parent_id();
		return apply_filters( 'ansa_meta_product_data', $d, $product, $qty );
	}

	public static function category_data( $term ) {
		if ( ! is_object( $term ) || empty( $term->term_id ) ) return array();
		$ids = array();
		try {
			$prods = wc_get_products( array( 'status' => 'publish', 'limit' => 10, 'category' => array( $term->slug ), 'return' => 'objects', 'orderby' => 'menu_order', 'order' => 'ASC' ) );
			foreach ( (array) $prods as $p ) { $ids[] = self::content_id( $p ); }
		} catch ( \Throwable $e ) {}
		return array( 'content_category' => (string) $term->name, 'content_ids' => $ids, 'content_type' => 'product' );
	}

	public static function search_data( $q ) {
		global $wp_query; $ids = array();
		if ( isset( $wp_query ) && ! empty( $wp_query->posts ) ) {
			foreach ( array_slice( (array) $wp_query->posts, 0, 10 ) as $post ) {
				if ( ! empty( $post->post_type ) && 'product' === $post->post_type ) { $p = wc_get_product( $post->ID ); if ( $p ) $ids[] = self::content_id( $p ); }
			}
		}
		return array( 'search_string' => mb_substr( (string) $q, 0, 200 ), 'content_ids' => $ids, 'content_type' => 'product' );
	}

	/** Количката — за InitiateCheckout / AddPaymentInfo / кутията на промото. */
	public static function cart_data() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) return array();
		$cart = WC()->cart; $ids = array(); $contents = array(); $n = 0; $cat = '';
		foreach ( (array) $cart->get_cart() as $item ) {
			$p = $item['data'] ?? null; if ( ! is_object( $p ) ) continue;
			$qty = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$line = (float) ( $item['line_subtotal'] ?? 0 ) + (float) ( $item['line_subtotal_tax'] ?? 0 );
			$unit = $line > 0 ? round( $line / $qty, 2 ) : self::price( $p );
			$id = self::content_id( $p ); $ids[] = $id; $n += $qty;
			$contents[] = array( 'id' => $id, 'quantity' => $qty, 'item_price' => $unit );
			if ( '' === $cat ) $cat = self::category_of( $p );
		}
		if ( ! $ids ) return array();
		$d = array(
			'content_ids'  => array_values( array_unique( $ids ) ),
			'content_type' => 'product',
			'contents'     => $contents,
			'num_items'    => $n,
			'value'        => round( (float) $cart->get_total( 'edit' ), 2 ),
			'currency'     => self::currency(),
		);
		if ( '' !== $cat ) $d['content_category'] = $cat;
		return apply_filters( 'ansa_meta_cart_data', $d, $cart );
	}

	/** Поръчката — за Purchase (сървър + браузър, едни и същи числа). */
	public static function order_data( $order ) {
		if ( ! is_object( $order ) ) return array();
		$ids = array(); $contents = array(); $n = 0; $names = array();
		foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
			$p = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			$qty = max( 1, (int) $item->get_quantity() );
			$line = (float) $item->get_total() + (float) $item->get_total_tax();
			$id = $p ? self::content_id( $p ) : (string) $item->get_product_id();
			$ids[] = $id; $n += $qty; $names[] = (string) $item->get_name();
			$contents[] = array( 'id' => $id, 'quantity' => $qty, 'item_price' => round( $line / $qty, 2 ) );
		}
		$d = array(
			'content_ids'  => array_values( array_unique( $ids ) ),
			'content_type' => 'product',
			'contents'     => $contents,
			'content_name' => mb_substr( implode( ', ', array_slice( $names, 0, 5 ) ), 0, 200 ),
			'num_items'    => $n,
			'value'        => round( (float) $order->get_total(), 2 ),
			'currency'     => (string) $order->get_currency(),
			'order_id'     => (string) $order->get_order_number(),
		);
		$coupons = method_exists( $order, 'get_coupon_codes' ) ? (array) $order->get_coupon_codes() : array();
		if ( $coupons ) $d['coupon'] = implode( ',', $coupons );
		$pm = (string) $order->get_payment_method(); if ( '' !== $pm ) $d['payment_method'] = $pm;
		$ship = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(); if ( $ship > 0 ) $d['shipping'] = round( $ship, 2 );
		if ( $order->get_shipping_method() ) $d['delivery_category'] = 'home_delivery';
		return apply_filters( 'ansa_meta_order_data', $d, $order );
	}
}
