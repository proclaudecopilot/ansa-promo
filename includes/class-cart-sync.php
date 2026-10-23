<?php
/**
 * ansa™ Промо — изоставените промо колички → таг на контакта във FunnelKit (v1.25.0, искане на човека).
 *
 * Човекът: „Recoverable carts по никакъв начин не диференцира дали количката е свързана със Сезонът на ansa, а искаме
 * да пускаме flows за abandoned cart на Сезонът на ansa.“ FunnelKit Automations записва количката в
 * {prefix}bwfan_abandonedcarts (items = WC()->cart->get_cart_for_session(), т.е. нашият cart item data ключ `ansa_promo`
 * е вътре), но условията на автоматизацията „Cart Abandoned“ гледат продукти/суми/купони/тагове на контакта — не item data.
 * Промо количката е от същите продукти като обикновената (Greens, Berberine…) и същата чекаут страница (#5840), затова
 * нищо във FKA не я различава.
 *
 * Решението: контактът получава ТАГ (`leads.cart_tag`, по подразбиране `sezon-kolichka`) докато в количката му има промо
 * група, и го губи щом количката стане обикновена или поръча. Във FKA: Automations → Cart Abandoned → условие
 * „Contact Tags — has sezon-kolichka“ за промо flow-а; в общите cart recovery flow-ове — „does not have“.
 *
 * Къде се закача:
 *  - гост: FKA записва количката през `?wc-ajax=bwfan_insert_abandoned_cart` (bwfan-public.js, при имейл в чекаута).
 *    Слушаме `wc_ajax_bwfan_insert_abandoned_cart` с приоритет 1 (преди FKA), запомняме имейла + дали количката е промо,
 *    а самото тагване върви на `shutdown` — след като FKA е отговорил на браузъра (wp_send_json → exit → shutdown).
 *  - логнат клиент: FKA записва количката сървърно (знае имейла) — слушаме `woocommerce_cart_updated`; тагваме само
 *    при смяна на състоянието (WC session флаг), за да не пишем в контакта на всяко преизчисляване.
 *  - поръчка (`woocommerce_checkout_order_processed` + Store API) → тагът пада от billing имейла.
 *  - мрежа за всичко останало: WP-Cron `ansa_promo_cart_sync` на 10 мин — последната количка на всеки имейл от 48 ч в
 *    таблицата на FKA: промо → таг, обикновена → без таг. Хваща и случаите, в които hook-овете не са се пуснали.
 *
 * Контактът се създава, ако го няма (Lead_Sync::contact — FKA не го създава при записа на количката, а чак при поръчка),
 * тагът се слага/маха през BWFCRM_Contact (event-и „Tag Added/Removed“ на FKA), резерва направо в контакта (Lead_Sync).
 * Последните 30 действия са в option `ansa_promo_cart_sync_log` (Doctor / Probe).
 *
 * @package AnsaPromo
 * @since 1.25.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Cart_Sync {

	const HOOK     = 'ansa_promo_cart_sync';
	const OPT_LOG  = 'ansa_promo_cart_sync_log';
	const SESS_KEY = 'ansa_promo_cart_tag_state';
	const LOG_MAX  = 30;
	const SWEEP_H  = 48;

	/** Диагностика на последния опит. */
	public static $diag = '';
	/** Заявката на FKA, която чака за shutdown: [ email, promo, consent ]. */
	private static $pending = null;

	public static function register() {
		add_action( 'wc_ajax_bwfan_insert_abandoned_cart', array( __CLASS__, 'on_capture' ), 1 );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'on_cart_updated' ), 50 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_id' ), 30 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 30 );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'cron' ), 20 );
		add_action( self::HOOK, array( __CLASS__, 'sweep' ) );
	}

	/* ── конфиг ── */
	public static function tag_name( $cfg = null ) { $l = Lead_Sync::cfg( $cfg ); return trim( (string) ( $l['cart_tag'] ?? '' ) ); }
	public static function enabled( $cfg = null ) { return '' !== self::tag_name( $cfg ); }
	public static function available() { return Lead_Sync::available(); }
	/** Тагът по име; при $create се създава във FKA. */
	public static function tag_id( $create = false, $cfg = null ) { $n = self::tag_name( $cfg ); return '' === $n ? 0 : (int) Merge::tag_id( $n, $create ); }

	/* ── какво е „промо количка“ ── */
	/** WC количката (обект или масив от редове) съдържа промо ред (cart item data `ansa_promo`, роля base). */
	public static function cart_is_promo( $cart = null ) {
		if ( null === $cart ) { $cart = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart : null; }
		$items = is_object( $cart ) && method_exists( $cart, 'get_cart' ) ? (array) $cart->get_cart() : (array) $cart;
		foreach ( $items as $it ) { if ( is_array( $it ) && ! empty( $it[ Cart::META ] ) && 'base' === (string) ( $it[ Cart::META ]['role'] ?? 'base' ) ) { return true; } }
		return false;
	}
	/** Колоната items на FKA (serialize на get_cart_for_session) съдържа промо ред. */
	public static function items_is_promo( $items ) {
		$items = (string) $items; if ( '' === $items ) { return false; }
		if ( false === strpos( $items, '"' . Cart::META . '"' ) ) { return false; }
		$arr = @unserialize( $items, array( 'allowed_classes' => false ) );
		if ( is_array( $arr ) ) { return self::cart_is_promo( $arr ); }
		return (bool) preg_match( '/s:4:"role";s:4:"base"/', $items ); /* счупен serialize (обекти) → по текста */
	}

	/* ── гост: заявката на FKA ── */
	public static function on_capture() {
		if ( ! self::enabled() ) { return; }
		$email = strtolower( sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) ) ); if ( ! is_email( $email ) ) { return; }
		$f = (array) ( $_POST['checkout_fields_data'] ?? array() );
		self::$pending = array( $email, self::cart_is_promo(), ! empty( $f['bwfan_user_consent'] ) );
		add_action( 'shutdown', array( __CLASS__, 'on_shutdown' ), 5 );
	}
	public static function on_shutdown() {
		if ( ! self::$pending ) { return; }
		list( $email, $promo, $consent ) = self::$pending; self::$pending = null;
		try { self::sync_email( $email, $promo, 'capture', $consent ); } catch ( \Throwable $e ) { self::log( $email, $promo, 'capture', 'retry: ' . $e->getMessage() ); }
	}

	/* ── логнат клиент: количката се сменя ── */
	public static function on_cart_updated() {
		if ( ! self::enabled() || ! function_exists( 'WC' ) || ! WC()->session || ! is_user_logged_in() ) { return; }
		$user = wp_get_current_user(); $email = strtolower( (string) $user->user_email ); if ( ! is_email( $email ) ) { return; }
		$promo = self::cart_is_promo(); $state = $promo ? 'promo' : 'plain';
		if ( (string) WC()->session->get( self::SESS_KEY ) === $state ) { return; }
		WC()->session->set( self::SESS_KEY, $state );
		try { self::sync_email( $email, $promo, 'cart' ); } catch ( \Throwable $e ) { self::log( $email, $promo, 'cart', 'retry: ' . $e->getMessage() ); }
	}

	/* ── поръчка → тагът пада ── */
	public static function on_order_id( $order_id ) { $o = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null; if ( $o ) { self::on_order( $o ); } }
	public static function on_order( $order ) {
		if ( ! self::enabled() || ! is_object( $order ) || ! method_exists( $order, 'get_billing_email' ) ) { return ''; }
		$email = strtolower( sanitize_email( (string) $order->get_billing_email() ) ); if ( ! is_email( $email ) ) { return ''; }
		if ( function_exists( 'WC' ) && WC()->session ) { WC()->session->set( self::SESS_KEY, null ); }
		try { return self::sync_email( $email, false, 'order #' . $order->get_id() ); } catch ( \Throwable $e ) { return self::log( $email, false, 'order', 'retry: ' . $e->getMessage() ); }
	}

	/**
	 * Ядрото: имейл + дали количката му е промо → тагът е на контакта или не е. Връща четимия резултат
	 * ('таг #id · crm' | 'има' | 'падна · crm' | 'няма' | 'off' | 'no-email' | 'retry: …').
	 */
	public static function sync_email( $email, $promo, $src = '', $consent = false ) {
		$email = strtolower( sanitize_email( (string) $email ) ); if ( ! is_email( $email ) ) { return self::log( $email, $promo, $src, 'no-email' ); }
		if ( ! self::enabled() ) { return self::log( $email, $promo, $src, 'off' ); }
		if ( ! self::available() ) { return self::log( $email, $promo, $src, 'retry: FunnelKit контакти липсват' ); }
		$tid = self::tag_id( (bool) $promo ); /* тагът се създава при първата промо количка */
		if ( $tid <= 0 ) { return self::log( $email, $promo, $src, $promo ? 'retry: няма таг „' . self::tag_name() . '“ (' . ( Merge::$tag_diag ?: 'без диагностика' ) . ')' : 'няма' ); }
		if ( $promo ) {
			$contact = Lead_Sync::contact( $email, (bool) $consent );
			if ( ! $contact ) { return self::log( $email, $promo, $src, 'retry: няма контакт' ); }
			$how = Lead_Sync::attach( $contact, $email, 'tags', $tid, self::tag_name() );
			return self::log( $email, $promo, $src, '' === $how ? 'retry: тагът не се записа' : ( 'има' === $how ? 'има' : 'таг #' . $tid . ' · ' . $how ) );
		}
		if ( ! Merge::contact_has( $email, 'tags', $tid ) ) { return self::log( $email, $promo, $src, 'няма', false ); }
		$how = Lead_Sync::detach( $email, 'tags', $tid );
		return self::log( $email, $promo, $src, '' === $how ? 'retry: тагът не падна' : 'падна · ' . $how );
	}

	/* ── мрежата: таблицата на FKA ── */
	public static function schedules( $s ) { if ( ! isset( $s['ansa_promo_10min'] ) ) { $s['ansa_promo_10min'] = array( 'interval' => 600, 'display' => 'ansa™ Промо: на 10 минути' ); } return $s; }
	public static function cron() {
		if ( ! self::enabled() ) { if ( wp_next_scheduled( self::HOOK ) ) { wp_clear_scheduled_hook( self::HOOK ); } return; }
		if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 300, 'ansa_promo_10min', self::HOOK ); }
	}
	public static function table() { global $wpdb; return $wpdb->prefix . 'bwfan_abandonedcarts'; }
	/** Последната количка на всеки имейл от последните часове: [ email => [ 'promo' => bool, 'status' => int, 'id' => int ] ]; количка с поръчка (recovered) не е промо. */
	public static function recent_carts( $hours = self::SWEEP_H, $limit = 500 ) {
		global $wpdb; $t = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return null; }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, email, status, order_id, items FROM {$t} WHERE last_modified > DATE_SUB( NOW(), INTERVAL %d HOUR ) ORDER BY last_modified DESC, ID DESC LIMIT %d", (int) $hours, (int) $limit ), ARRAY_A );
		$out = array();
		foreach ( $rows as $r ) { $e = strtolower( (string) $r['email'] ); if ( '' === $e || isset( $out[ $e ] ) ) { continue; } $out[ $e ] = array( 'promo' => (int) ( $r['order_id'] ?? 0 ) <= 0 && self::items_is_promo( $r['items'] ), /* възстановена (с поръчка) → вече не е промо количка */ 'status' => (int) $r['status'], 'id' => (int) $r['ID'] ); }
		return $out;
	}
	/** Кронът: промо → таг, обикновена → без таг. → броячи. */
	public static function sweep( $hours = self::SWEEP_H ) {
		$r = array( 'carts' => 0, 'tagged' => 0, 'untagged' => 0, 'kept' => 0, 'retry' => 0 );
		if ( ! self::enabled() || ! self::available() ) { return $r; }
		$carts = self::recent_carts( $hours ); if ( null === $carts ) { return $r; }
		$tid = self::tag_id( false );
		foreach ( $carts as $email => $c ) {
			$r['carts']++;
			if ( ! $c['promo'] && ( $tid <= 0 || ! Merge::contact_has( $email, 'tags', $tid ) ) ) { continue; } /* нищо за махане — без запис */
			try { $k = self::sync_email( $email, $c['promo'], 'sweep #' . $c['id'] ); } catch ( \Throwable $e ) { $k = 'retry: ' . $e->getMessage(); self::log( $email, $c['promo'], 'sweep', $k ); }
			if ( 0 === strpos( $k, 'retry' ) ) { $r['retry']++; } elseif ( 0 === strpos( $k, 'таг #' ) ) { $r['tagged']++; } elseif ( 0 === strpos( $k, 'падна' ) ) { $r['untagged']++; } else { $r['kept']++; }
		}
		update_option( self::OPT_LOG . '_sweep', array( 'at' => current_time( 'mysql' ) ) + $r, false );
		return $r;
	}

	/* ── лог / Doctor ── */
	private static function log( $email, $promo, $src, $txt, $write = true ) {
		self::$diag = $txt;
		if ( $write ) {
			$log = get_option( self::OPT_LOG, array() ); if ( ! is_array( $log ) ) { $log = array(); }
			array_unshift( $log, array( 'at' => current_time( 'mysql' ), 'email' => self::mask( $email ), 'promo' => (bool) $promo, 'src' => (string) $src, 'r' => (string) $txt ) );
			update_option( self::OPT_LOG, array_slice( $log, 0, self::LOG_MAX ), false );
		}
		return $txt;
	}
	public static function mask( $email ) { $email = (string) $email; $at = strpos( $email, '@' ); if ( false === $at ) { return $email; } return mb_substr( $email, 0, min( 2, $at ) ) . '…' . substr( $email, $at ); }
	public static function log_rows() { $l = get_option( self::OPT_LOG, array() ); return is_array( $l ) ? $l : array(); }
	/** Редът в Doctor. */
	public static function status( $cfg = null ) {
		$n = self::tag_name( $cfg );
		if ( '' === $n ) { return 'изключено (празно име на таг)'; }
		if ( ! self::available() ) { return 'FunnelKit контакти липсват · таг „' . $n . '“'; }
		$tid = Merge::tag_id( $n ); $sw = get_option( self::OPT_LOG . '_sweep', array() ); $rows = self::log_rows();
		$out = 'таг „' . $n . '“' . ( $tid > 0 ? ' #' . $tid : ' (създава се при първата промо количка)' );
		$out .= ' · крон ' . ( wp_next_scheduled( self::HOOK ) ? 'на 10 мин' : 'НЕ Е НАСРОЧЕН' );
		if ( ! empty( $sw['at'] ) ) { $out .= ' · последна проверка ' . $sw['at'] . ': ' . (int) $sw['carts'] . ' колички, +' . (int) $sw['tagged'] . ' / −' . (int) $sw['untagged'] . ( $sw['retry'] ? ' / retry ' . (int) $sw['retry'] : '' ); }
		if ( $rows ) { $r = $rows[0]; $out .= ' · последно: ' . $r['at'] . ' ' . $r['email'] . ' (' . ( $r['promo'] ? 'промо' : 'обикновена' ) . ', ' . $r['src'] . ') → ' . $r['r']; }
		return $out;
	}
}
