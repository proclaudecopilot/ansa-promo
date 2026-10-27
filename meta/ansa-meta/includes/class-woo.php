<?php
/**
 * ansa™ Meta — сървърните събития от WooCommerce и ansa™ Промо:
 *   AddToCart (woocommerce_add_to_cart; кутията на промото = едно събитие през ansa_promo_added) · Purchase (при създаване на поръчката /
 *   при плащане / смяна на статус / „Благодаря“ — което дойде първо, веднъж, с event_id-то от поръчката) · Lead (попъпът на промото) ·
 *   CompleteRegistration. Контекстът на браузъра (fbp, fbc, IP, UA, vid, URL) се записва в поръчката още при създаването ѝ,
 *   за да го има и когато Purchase тръгне от крон/админ по-късно.
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Woo {
	const META_EVENT = '_ansa_meta_event_id';
	const META_CTX   = '_ansa_meta_ctx';
	const META_SENT  = '_ansa_meta_purchase_sent';
	const META_TY    = '_ansa_meta_thankyou';
	const OFFLINE_GATEWAYS = array( 'cod', 'bacs', 'cheque' );

	public static function init() {
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'create_order' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'create_order_api' ), 10, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'order_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'order_processed_api' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 20, 4 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 4, 1 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'registered' ), 20, 1 );
		/* ansa™ Промо */
		add_action( 'ansa_promo_added', array( __CLASS__, 'promo_added' ), 10, 4 );
		add_action( 'ansa_promo_lead_captured', array( __CLASS__, 'promo_lead' ), 10, 4 );
		add_action( 'wp_ajax_ansa_promo_add', array( __CLASS__, 'ajax_cookies' ), 0 );
		add_action( 'wp_ajax_nopriv_ansa_promo_add', array( __CLASS__, 'ajax_cookies' ), 0 );
		add_action( 'wp_ajax_ansa_promo_lead', array( __CLASS__, 'ajax_cookies' ), 0 );
		add_action( 'wp_ajax_nopriv_ansa_promo_lead', array( __CLASS__, 'ajax_cookies' ), 0 );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'ajax_cookies' ), 0 );
		/* WPForms: контактна форма с имейл/телефон → Lead */
		add_action( 'wpforms_process_complete', array( __CLASS__, 'wpforms' ), 10, 4 );
		/* GDPR: при изтриване на лични данни на поръчка (WooCommerce → Privacy) пада и нашият контекст (IP, UA, fbp/fbc) */
		add_filter( 'woocommerce_privacy_remove_order_personal_data_meta', function ( $meta ) { $meta[ self::META_CTX ] = 'text'; return $meta; } );
	}

	/** WPForms → Lead с полетата email / phone / name; имейлът се помни в сесията. */
	public static function wpforms( $fields, $entry, $form_data, $entry_id ) {
		if ( ! Plugin::enabled() || Identity::excluded() ) return;
		$plain = Identity::current();
		foreach ( (array) $fields as $f ) {
			if ( ! is_array( $f ) ) continue;
			$t = (string) ( $f['type'] ?? '' ); $v = $f['value'] ?? '';
			if ( 'email' === $t && is_string( $v ) && '' !== trim( $v ) ) { $plain['em'] = trim( $v ); }
			elseif ( 'phone' === $t && is_string( $v ) && '' !== trim( $v ) ) { $plain['ph'] = trim( $v ); }
			elseif ( 'name' === $t ) {
				if ( ! empty( $f['first'] ) ) $plain['fn'] = (string) $f['first'];
				if ( ! empty( $f['last'] ) ) $plain['ln'] = (string) $f['last'];
				if ( empty( $f['first'] ) && is_string( $v ) && '' !== trim( $v ) ) { $parts = preg_split( '/\s+/', trim( $v ), 2 ); $plain['fn'] = $parts[0]; if ( isset( $parts[1] ) ) $plain['ln'] = $parts[1]; }
			}
		}
		if ( empty( $plain['em'] ) && empty( $plain['ph'] ) ) return;
		if ( ! empty( $plain['em'] ) ) Identity::session_set( Identity::SESSION_EMAIL, Identity::norm_email( $plain['em'] ) );
		$data = array( 'content_name' => (string) ( $form_data['settings']['form_title'] ?? 'WPForms' ), 'content_category' => 'form' );
		$id = Capi::track( 'Lead', $data, $plain, array( 'source' => 'server' ) );
		if ( '' !== $id ) Frontend::push_flash( 'Lead', $id, $data );
	}

	public static function ajax_cookies() { Identity::allow_cookies( true ); }

	/* ───────── AddToCart ───────── */
	public static function add_to_cart( $key, $product_id, $qty, $variation_id, $variation, $cart_item_data ) {
		if ( ! Plugin::enabled() || Identity::excluded() ) return;
		if ( is_array( $cart_item_data ) && isset( $cart_item_data['ansa_promo'] ) ) return; // кутията на промото → едно събитие в promo_added()
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) return;
		$product = wc_get_product( $variation_id ? (int) $variation_id : (int) $product_id ); if ( ! $product ) return;
		$data = Catalog::product_data( $product, (int) $qty );
		if ( empty( $data['value'] ) ) return; // подаръци/безплатни редове (Shrine Cart_Gift) не са AddToCart
		$twin = self::browser_twin_expected();
		$id = Capi::track( 'AddToCart', $data, Identity::current(), array( 'source' => 'server' ) );
		if ( '' !== $id && $twin ) Frontend::push_flash( 'AddToCart', $id, $data );
	}

	/** Очаква ли се същото събитие и от браузъра (WC ajax add-to-cart → фрагменти; форма → следващата страница)? */
	private static function browser_twin_expected() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
		if ( isset( $_REQUEST['wc-ajax'] ) ) return false !== strpos( (string) $_REQUEST['wc-ajax'], 'add_to_cart' );
		if ( isset( $_REQUEST['add-to-cart'] ) ) return true;
		return false;
	}

	/** ansa™ Промо: цялата кутия като едно AddToCart (стойността и редовете от количката). Операторските заявки (📞) са вътрешни. */
	public static function promo_added( $group, $box, $ctx, $cfg ) {
		if ( ! Plugin::enabled() || Identity::excluded() ) return;
		if ( is_array( $ctx ) && ! empty( $ctx['op'] ) ) return;
		$data = Catalog::cart_data(); if ( ! $data ) return;
		$data['content_category'] = 'ansa promo';
		$data['promo_box'] = (string) $box;
		Capi::track( 'AddToCart', $data, Identity::current(), array( 'source' => 'promo' ) );
	}

	/** ansa™ Промо: имейлът от попъпа → Lead + го помним в сесията за следващите събития на същия човек. */
	public static function promo_lead( $lead_id, $email, $consent, $utm ) {
		if ( ! Plugin::enabled() || Identity::excluded() ) return;
		$email = Identity::norm_email( $email ); if ( '' === $email ) return;
		Identity::session_set( Identity::SESSION_EMAIL, $email );
		$plain = Identity::current(); $plain['em'] = $email;
		Capi::track( 'Lead', array( 'content_name' => 'ansa promo popup', 'content_category' => (string) $utm, 'status' => $consent ? 'consent' : 'no-consent' ), $plain, array( 'source' => 'promo' ) );
	}

	/* ───────── Purchase ───────── */
	/** event_id + контекстът на браузъра — още при създаването на поръчката (преди save на WC). */
	public static function create_order( $order, $data = null ) { self::stamp( $order, false ); }
	public static function create_order_api( $order ) { self::stamp( $order, false ); }

	public static function stamp( $order, $save = true ) {
		if ( ! is_object( $order ) || $order->get_meta( self::META_EVENT ) ) return;
		Identity::allow_cookies( true );
		$order->update_meta_data( self::META_EVENT, Capi::uuid() );
		$order->update_meta_data( self::META_CTX, wp_json_encode( Identity::ctx() ) );
		if ( $save ) $order->save();
	}

	public static function order_processed( $order_id, $posted, $order ) {
		$order = is_object( $order ) ? $order : wc_get_order( (int) $order_id ); if ( ! $order ) return;
		self::stamp( $order, true );
		$gw = (string) $order->get_payment_method();
		if ( 'order' === Settings::get( 'purchase_on' ) || in_array( $gw, (array) apply_filters( 'ansa_meta_offline_gateways', self::OFFLINE_GATEWAYS ), true ) || ! $order->needs_payment() ) {
			self::purchase( $order, 'checkout' );
		}
	}
	public static function order_processed_api( $order ) { if ( is_object( $order ) ) self::order_processed( $order->get_id(), null, $order ); }
	public static function payment_complete( $order_id ) { $o = wc_get_order( (int) $order_id ); if ( $o ) self::purchase( $o, 'payment' ); }
	public static function status_changed( $order_id, $from, $to, $order ) {
		if ( ! in_array( (string) $to, array( 'processing', 'completed', 'on-hold' ), true ) ) return;
		$order = is_object( $order ) ? $order : wc_get_order( (int) $order_id ); if ( $order ) self::purchase( $order, 'status:' . $to );
	}
	public static function thankyou( $order_id ) {
		$o = wc_get_order( (int) $order_id ); if ( ! $o ) return;
		if ( in_array( $o->get_status(), array( 'failed', 'cancelled', 'pending' ), true ) ) return;
		self::purchase( $o, 'thankyou' );
	}

	/** Веднъж на поръчка (order meta _ansa_meta_purchase_sent), с event_id-то на поръчката; времето = създаването ѝ. */
	public static function purchase( $order, $why = '', $force = false ) {
		if ( ! Plugin::enabled() || ! is_object( $order ) ) return '';
		if ( ! $force && $order->get_meta( self::META_SENT ) ) return '';
		if ( (bool) apply_filters( 'ansa_meta_skip_order', 'probe@example.com' === strtolower( (string) $order->get_billing_email() ), $order ) ) return '';
		self::stamp( $order, false );
		$plain = Identity::from_order( $order );
		$data  = Catalog::order_data( $order );
		$ltv = Catalog::ltv( $order ); if ( $ltv > (float) ( $data['value'] ?? 0 ) ) $data['predicted_ltv'] = $ltv; // връщащ се клиент → стойност за value optimization
		$ctx   = json_decode( (string) $order->get_meta( self::META_CTX ), true ); if ( ! is_array( $ctx ) ) $ctx = array();
		$created = $order->get_date_created(); $time = $created ? (int) $created->getTimestamp() : time();
		$opts = array( 'event_id' => (string) $order->get_meta( self::META_EVENT ), 'time' => $time, 'url' => (string) ( $ctx['url'] ?? wc_get_checkout_url() ), 'source' => $force ? 'manual' : 'server', 'now' => (bool) $force );
		$id = Capi::track( 'Purchase', $data, $plain, $opts );
		if ( '' !== $id ) { $order->update_meta_data( self::META_SENT, time() . '|' . $why ); $order->save(); }
		return $id;
	}

	/* ───────── CompleteRegistration ───────── */
	public static function registered( $user_id ) {
		if ( ! Plugin::enabled() || Identity::excluded() ) return;
		$plain = array_merge( Identity::current(), Identity::from_user( $user_id ) );
		$data  = array( 'content_name' => 'customer', 'status' => 'registered' );
		$id = Capi::track( 'CompleteRegistration', $data, $plain, array( 'source' => 'server' ) );
		if ( '' !== $id && ! wp_doing_ajax() ) Frontend::push_flash( 'CompleteRegistration', $id, $data );
	}
}
