<?php
/**
 * ansa™ Meta — фронтът: зарежда assets/meta.js с конфигурацията за страницата (Pixel ID, събитията за тази страница,
 * Advanced Matching, „flash“ събитията от сесията — напр. AddToCart след форма, Purchase на „Благодаря“ с event_id-то на поръчката).
 *
 * Кеш (FlyingPress): продуктовите/категорийните страници се кешират → event_id-тата се правят в браузъра, а сървърната
 * половина на PageView/ViewContent/… минава през REST (class-rest.php), който не се кешира. Лични данни в HTML-а има само
 * на некеширани страници (логнат, чекаут, „Благодаря“, акаунт).
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Frontend {
	const FLASH = 'ansa_meta_flash'; // WC session: събития за браузъра при следващото зареждане
	const STD   = array( 'PageView', 'ViewContent', 'Search', 'AddToCart', 'AddToWishlist', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase', 'Lead', 'CompleteRegistration', 'Contact', 'CustomizeProduct', 'Donate', 'FindLocation', 'Schedule', 'StartTrial', 'SubmitApplication', 'Subscribe' );

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'tag' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'fragments' ) );
	}

	public static function active() {
		if ( ! Plugin::enabled() || is_admin() ) return false;
		if ( Identity::excluded() ) return false;
		return (bool) apply_filters( 'ansa_meta_frontend_active', true );
	}

	public static function enqueue() {
		if ( ! self::active() ) return;
		wp_register_script( 'ansa-meta', ANSA_META_URL . 'assets/meta.js', array(), ANSA_META_VER, true );
		wp_add_inline_script( 'ansa-meta', 'window.ansaMeta = ' . wp_json_encode( self::config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ';', 'before' );
		wp_enqueue_script( 'ansa-meta' );
	}

	/** Да не се отлага от оптимизаторите (FlyingPress/WP Rocket/Perfmatters четат тези атрибути или списъка за изключване). */
	public static function tag( $tag, $handle ) {
		if ( 'ansa-meta' !== $handle ) return $tag;
		return str_replace( '<script ', '<script data-no-optimize="1" data-no-defer="1" data-no-delay="1" data-no-minify="1" data-cfasync="false" nowprocket ', $tag );
	}

	public static function config() {
		$events = self::page_events();
		$cfg = array(
			'pixel'    => Settings::pixel_id(),
			'browser'  => (bool) Settings::get( 'browser' ),
			'rest'     => esc_url_raw( rest_url( Rest::NS . '/event' ) ),
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'mirrorPV' => (bool) Settings::get( 'mirror_pageview' ),
			'consent'  => array( 'cookie' => (string) Settings::get( 'consent_cookie', '' ), 'value' => (string) Settings::get( 'consent_value', '' ) ),
			'currency' => Catalog::currency(),
			'events'   => $events['events'],
			'flash'    => self::flash(),
			'adv'      => $events['adv'],
			'checkout' => $events['checkout'],
			'cart'     => $events['cart'],
			'std'      => self::STD,
			'debug'    => (bool) Settings::get( 'debug' ),
			'ver'      => ANSA_META_VER,
		);
		return apply_filters( 'ansa_meta_js_config', $cfg );
	}

	/** Събитията за тази страница (+ Advanced Matching, ако страницата не е кеширана). */
	public static function page_events() {
		$ev = array(); $adv = null; $checkout = false; $cart = null;
		$personal = is_user_logged_in() || ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_account_page' ) && is_account_page() );
		if ( Settings::event_on( 'PageView' ) ) $ev[] = array( 'name' => 'PageView', 'data' => new \stdClass() );
		try {
			if ( function_exists( 'is_product' ) && is_product() && Settings::event_on( 'ViewContent' ) ) {
				$p = wc_get_product( get_queried_object_id() );
				if ( $p ) $ev[] = array( 'name' => 'ViewContent', 'data' => Catalog::product_data( $p ) );
			} elseif ( function_exists( 'is_product_category' ) && is_product_category() && Settings::event_on( 'ViewCategory' ) ) {
				$ev[] = array( 'name' => 'ViewCategory', 'data' => Catalog::category_data( get_queried_object() ) );
			} elseif ( is_search() && Settings::event_on( 'Search' ) ) {
				$ev[] = array( 'name' => 'Search', 'data' => Catalog::search_data( get_search_query() ) );
			}
			$order = self::thankyou_order();
			if ( $order ) {
				$personal = true; $adv = Identity::pixel_am( Identity::from_order( $order ) );
				if ( Settings::event_on( 'Purchase' ) && ! $order->get_meta( Woo::META_TY ) ) {
					Woo::stamp( $order, true );
					$ev[] = array( 'name' => 'Purchase', 'id' => (string) $order->get_meta( Woo::META_EVENT ), 'data' => Catalog::order_data( $order ), 'mirror' => false );
					$order->update_meta_data( Woo::META_TY, time() ); $order->save();
				}
			} elseif ( self::is_checkout_page() ) {
				$checkout = true; $cart = Catalog::cart_data() ?: new \stdClass();
				if ( Settings::event_on( 'InitiateCheckout' ) ) $ev[] = array( 'name' => 'InitiateCheckout', 'data' => $cart );
			}
		} catch ( \Throwable $e ) {}
		if ( null === $adv && $personal ) $adv = Identity::pixel_am( Identity::current() );
		return array( 'events' => $ev, 'adv' => $adv ?: new \stdClass(), 'checkout' => $checkout, 'cart' => $cart );
	}

	public static function is_checkout_page() {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) return false;
		if ( function_exists( 'is_checkout' ) && is_checkout() ) return true;
		return is_singular( 'wfacp_checkout' ); // FunnelKit Checkout страници
	}

	/** Поръчката на „Благодаря“: WC endpoint-ът или ?key=wc_order_… (FunnelKit thank-you страници). */
	public static function thankyou_order() {
		global $wp;
		$id = 0; $key = isset( $_GET['key'] ) ? (string) wp_unslash( $_GET['key'] ) : '';
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() && ! empty( $wp->query_vars['order-received'] ) ) $id = absint( $wp->query_vars['order-received'] );
		if ( ! $id && '' !== $key && 0 === strpos( $key, 'wc_order_' ) && function_exists( 'wc_get_order_id_by_order_key' ) ) $id = (int) wc_get_order_id_by_order_key( $key );
		if ( ! $id ) return null;
		$order = wc_get_order( $id ); if ( ! $order ) return null;
		if ( '' !== $key && $order->get_order_key() !== $key ) return null;
		if ( '' === $key && ! ( is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id() ) ) return null;
		if ( in_array( $order->get_status(), array( 'failed', 'cancelled' ), true ) ) return null;
		return $order;
	}

	/* ───────── flash: събития за браузъра при следващото зареждане (AddToCart след форма, CompleteRegistration …) ───────── */
	public static function push_flash( $name, $id, array $data ) {
		$list = (array) Identity::session_get( self::FLASH, array() );
		$list[] = array( 'name' => (string) $name, 'id' => (string) $id, 'data' => $data ?: new \stdClass(), 'ts' => time() );
		Identity::session_set( self::FLASH, array_slice( $list, -20 ) );
	}

	public static function flash() {
		$list = (array) Identity::session_get( self::FLASH, array() );
		if ( ! $list ) return array();
		Identity::session_set( self::FLASH, array() );
		$now = time(); $out = array();
		foreach ( $list as $e ) { if ( is_array( $e ) && ! empty( $e['name'] ) && (int) ( $e['ts'] ?? 0 ) > $now - HOUR_IN_SECONDS ) $out[] = array( 'name' => $e['name'], 'id' => (string) ( $e['id'] ?? '' ), 'data' => $e['data'] ?? new \stdClass() ); }
		return $out;
	}

	/** WC ajax add-to-cart → събитията пътуват с фрагментите; meta.js ги чете в `added_to_cart`. */
	public static function fragments( $f ) {
		if ( ! is_array( $f ) ) $f = array();
		if ( ! self::active() ) return $f;
		if ( ! isset( $_REQUEST['wc-ajax'] ) || false === strpos( (string) $_REQUEST['wc-ajax'], 'add_to_cart' ) ) return $f; // get_refreshed_fragments / промото: флашът остава за страницата
		$fl = self::flash();
		if ( $fl ) $f['ansa_meta_events'] = $fl;
		return $f;
	}
}
