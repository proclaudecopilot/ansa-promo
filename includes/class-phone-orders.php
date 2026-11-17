<?php
/**
 * ansa™ Промо 1.29.0 — 📞 Обаждания: поръчка по телефон от изоставена количка или сесия (човекът: „Трябва да почнем да звъним на
 * изоставените — но да можем с 1 бутон да пресъздадем количката им (голяма/малка етц кутия), ако трябва да се нанесат корекции, и да
 * отидем на чекаут (да пренапълни имейл, телефон и две имена, ако са запазени, и само да се избере офис)“).
 *
 *  - От 📈 Insights (🛒 Колички, 🧾 Сесии, 🟢 На живо — в админа и в портала) всеки ред с имейл и без поръчка има два бутона:
 *      ✏️ Редактирай поръчка — отваря промо страницата в браузъра на оператора с ВЕЧЕ избраната кутия и продуктите в нея (екранът за редакция),
 *                     имейлът е попълнен, попъп няма; операторът коригира, ако трябва, и натиска „Към плащане“ → чекаутът е с имейл,
 *                     телефон и имена на клиента — остава офисът/адресът;
 *      ✅ Завърши поръчка — направо: количката се прави на сървъра и браузърът отива в чекаута с попълнените данни (без корекции).
 *    1.30.1: и двата първо питат „Отстъпка / Продукт подарък / Нищо“ (скриптът на Insights; &ansa_bonus= / &bonus=).
 *  - Линковете носят подписан токен (HMAC, 24 ч; само източник + оператор, без лични данни в адреса); промо страницата ги разпознава по
 *    ?ansa_op= (query string → без кеш). Данните идват от сървъра: количката от таблицата на FunnelKit (кутия, продукти, имейл, телефон,
 *    имена от checkout_data) или сесията на Insights (кутия, продукти, имейлът от попъпа) + последната поръчка на WooCommerce с този имейл
 *    (телефон, имена, ако има).
 *  - Операторът не е клиент: бисквитка ansa_promo_op (12 ч) → сесията му в Insights е вътрешна; самата поръчка е истинска — с meta
 *    phone_order {by, src, ...} и бележка в поръчката; в Insights тя е сесия phone-<id> (източник „телефон“), извън фунията, в плочката
 *    „📞 Поръчки по телефон“.
 *
 * @package AnsaPromo
 * @since 1.29.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Phone_Orders {

	const COOKIE = 'ansa_promo_op';
	const PARAM  = 'ansa_op';
	const TTL    = DAY_IN_SECONDS;
	const SID    = 'phone-';

	public static function register() {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'fees' ), 30 ); /* 1.30.0: −X % по телефона като отрицателна такса */
		add_action( 'template_redirect', array( __CLASS__, 'maybe_go' ), 1 ); /* ✅ Завърши поръчка: ?ansa_op=…&go=1 на промо страницата */
		add_action( 'ansa_promo_order_created', array( __CLASS__, 'on_order' ), 30, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'checkout_value' ), 20, 2 );
		/* 1.52.0 (скриптът за обаждане): 🚚 безплатна доставка като жест; 🎁 маската и за колички извън Сезона (ред на €0 с белег); поръчката от всякаква количка → 📋 възстановена */
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'free_shipping' ), 50, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'price_gift' ), 30 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'gift_price_html' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'gift_line_meta' ), 20, 3 );
		/* 1.54.2: мостът към „ANSA Checkout“ (новият чекаут в три стъпки) — той не чете клиента от WC()->customer, а при празни полета ги пълни от паметта на браузъра */
		add_action( 'wp_head', array( __CLASS__, 'checkout_bridge_head' ), 3 ); /* 1.54.3: от <head> — там промото доказано печата на страницата на чекаута (touch_script) */
		add_action( 'wp_footer', array( __CLASS__, 'checkout_bridge_footer' ), 1 ); /* резерва, ако шаблонът няма wp_head */
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_any_order' ), 25 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_any_order' ), 25 );
	}
	const GIFT_KEY = 'ansa_promo_phone_gift'; /* 1.52.0: белегът на реда подарък в количка извън Сезона */

	/* ── 1.30.0: бонусът за обаждането (човекът: „5 % върху поръчката, ако се съгласят по телефона, или продукт подарък — PATCH01 EyeFresh BG“) ──
	   Операторът избира на страницата: без · −X % на поръчката (отрицателна такса „📞 Отстъпка по телефона“ върху промо редовете, след
	   промо цените) · 🎁 продукт подарък (ред с META role=gift на €0 — като книгата: заключен, не брои за опаковка, пада при непълна кутия). */
	const OPT = 'ansa_promo_phone';
	public static function defaults() { return array( 'pct' => 5, 'gift_sku' => '', 'gift_id' => 0 ); } /* 1.52.0: без SKU → маската на Черния петък (CollaMask) */
	public static function settings() { $s = array_merge( self::defaults(), (array) get_option( self::OPT, array() ) ); $s['pct'] = (float) max( 0, min( 50, (float) $s['pct'] ) ); $s['gift_sku'] = trim( wp_strip_all_tags( (string) $s['gift_sku'] ) ); $s['gift_id'] = (int) $s['gift_id']; return $s; }
	public static function save( $d ) {
		$s = self::settings(); $s['pct'] = (float) max( 0, min( 50, (float) str_replace( ',', '.', (string) ( $d['pct'] ?? 5 ) ) ) ); $s['gift_sku'] = trim( wp_strip_all_tags( (string) ( $d['gift_sku'] ?? '' ) ) ); $s['gift_id'] = (int) ( $d['gift_id'] ?? 0 );
		update_option( self::OPT, $s, false ); self::$gift = false; $g = self::gift_product();
		return '📞 Обаждания: записано — отстъпка −' . Cart::fmt( $s['pct'] ) . ' %, подарък: ' . ( $g ? $g['name'] . ' (#' . $g['id'] . ')' : 'НЕ Е НАМЕРЕН продукт „' . ( $s['gift_sku'] ?: $s['gift_id'] ) . '“' ) . '.';
	}
	private static $gift = false;
	/** Продуктът подарък: по ID, по SKU, по име (search). [id, name, price] или null. */
	public static function gift_product() {
		if ( false !== self::$gift ) { return self::$gift; } self::$gift = null; $s = self::settings(); if ( ! function_exists( 'wc_get_product' ) ) { return null; }
		$id = (int) $s['gift_id']; if ( ! $id && 0 === strpos( $s['gift_sku'], 'PATCH01' ) && class_exists( __NAMESPACE__ . '\\BF\\Page' ) ) { $s['gift_sku'] = ''; } /* 1.52.1: старият подарък по подразбиране (EyeFresh) отстъпва на маската по скрипта */
		if ( ! $id && '' !== $s['gift_sku'] ) { $id = Catalog::find_by_sku( $s['gift_sku'] ); }
		if ( ! $id && '' !== $s['gift_sku'] ) { $r = Catalog::search( preg_replace( '/^[A-Z0-9]+\s+/', '', $s['gift_sku'] ) ?: $s['gift_sku'], 1 ); if ( $r ) { $id = (int) $r[0]['id']; } }
		if ( ! $id && class_exists( __NAMESPACE__ . '\\BF\\Page' ) ) { try { $mk = BF\Page::mask( BF\Config::get_published() ); if ( $mk ) { $id = (int) ( $mk['vid'] ?: $mk['id'] ); } } catch ( \Throwable $e ) {} } /* 1.52.0: скриптът — „колагенова маска като подарък“ */
		if ( $id ) { $p = wc_get_product( $id ); if ( $p && $p->is_purchasable() ) { self::$gift = array( 'id' => (int) $p->get_id(), 'pid' => (int) ( $p->get_parent_id() ?: $p->get_id() ), 'name' => (string) $p->get_name(), 'price' => (float) $p->get_price(), 'stock' => (bool) $p->is_in_stock() ); } }
		return self::$gift;
	}
	public static function norm_bonus( $v ) { $v = (string) $v; return in_array( $v, array( 'pct', 'gift', 'ship' ), true ) ? $v : ''; } /* 1.52.0: + ship = 🚚 безплатна доставка */
	public static function bonus_short( $b ) { return 'gift' === $b ? '🎁 маска' : ( 'ship' === $b ? '🚚 безплатна доставка' : ( 'pct' === $b ? '💶 отстъпка' : 'без жест' ) ); }
	/** За promo.js: какви бонуси може да предложи операторът. */
	public static function bonus_cfg() { $s = self::settings(); $g = self::gift_product(); return array( 'pct' => (float) $s['pct'], 'gift' => $g && $g['stock'] ? array( 'id' => $g['id'], 'name' => $g['name'] ) : null ); }
	public static function bonus_label( $bonus, $pct = null ) { $s = self::settings(); if ( 'ship' === $bonus ) { return '🚚 Безплатна доставка по телефона'; } if ( 'pct' === $bonus ) { return '📞 Отстъпка по телефона −' . Cart::fmt( null === $pct ? $s['pct'] : $pct ) . ' %'; } if ( 'gift' === $bonus ) { $g = self::gift_product(); return '🎁 Подарък за обаждането' . ( $g ? ': ' . $g['name'] : '' ); } return ''; }
	/** Редът подарък в количката (role=gift, €0 през Cart::apply_pricing). */
	public static function add_gift( $meta ) {
		$g = self::gift_product(); if ( ! $g || ! function_exists( 'WC' ) || ! WC()->cart ) { return false; }
		foreach ( WC()->cart->get_cart() as $it ) { if ( ! empty( $it[ Cart::META ]['role'] ) && 'gift' === $it[ Cart::META ]['role'] ) { return true; } }
		$vid = (int) $g['id'] !== (int) ( $g['pid'] ?? $g['id'] ) ? (int) $g['id'] : 0;
		return (bool) WC()->cart->add_to_cart( (int) ( $g['pid'] ?? $g['id'] ), 1, $vid, array(), array( Cart::META => array_merge( (array) $meta, array( 'role' => 'gift', 'key' => 'gift' ) ) ) );
	}
	/** 1.52.0: подаръкът в количка извън Сезона (магазин / Черен петък) — обикновен ред с белег GIFT_KEY, цената му става €0 в price_gift(). */
	public static function add_gift_generic() {
		$g = self::gift_product(); if ( ! $g || ! function_exists( 'WC' ) || ! WC()->cart ) { return false; }
		foreach ( WC()->cart->get_cart() as $it ) { if ( ! empty( $it[ self::GIFT_KEY ] ) ) { return true; } }
		$vid = (int) $g['id'] !== (int) ( $g['pid'] ?? $g['id'] ) ? (int) $g['id'] : 0;
		return (bool) WC()->cart->add_to_cart( (int) ( $g['pid'] ?? $g['id'] ), 1, $vid, array(), array( self::GIFT_KEY => 1 ) );
	}
	public static function price_gift( $cart ) { if ( ! $cart instanceof \WC_Cart ) { return; } foreach ( $cart->get_cart() as $it ) { if ( ! empty( $it[ self::GIFT_KEY ] ) && isset( $it['data'] ) && is_object( $it['data'] ) ) { $it['data']->set_price( 0 ); } } }
	public static function gift_price_html( $html, $item ) { return ! empty( $item[ self::GIFT_KEY ] ) ? '<span class="ansa-promo-gift">🎁 подарък</span>' : $html; }
	public static function gift_line_meta( $item, $key, $values ) { if ( ! empty( $values[ self::GIFT_KEY ] ) ) { $item->add_meta_data( '🎁', 'подарък за обаждането (ansa™ Промо)', true ); } }
	/** 1.52.0: 🚚 безплатна доставка — всички методи на €0, когато жестът на обаждането е „ship“. */
	public static function free_shipping( $rates, $package ) {
		$ctx = Cart::ctx(); $op = isset( $ctx['op'] ) && is_array( $ctx['op'] ) ? $ctx['op'] : null; if ( ! $op || 'ship' !== (string) ( $op['bonus'] ?? '' ) || ! is_array( $rates ) ) { return $rates; }
		foreach ( $rates as $k => $r ) { if ( ! is_object( $r ) ) { continue; } $r->cost = 0; $tx = (array) $r->taxes; foreach ( $tx as $i => $v ) { $tx[ $i ] = 0; } $r->taxes = $tx; $r->label = $r->label . ' · 🚚 безплатна (по телефона)'; $rates[ $k ] = $r; }
		return $rates;
	}
	/** −X % като отрицателна такса върху промо редовете (с ДДС), само при валидна кутия и бонус „pct“ в контекста на оператора. */
	public static function fees( $cart ) {
		if ( ! $cart instanceof \WC_Cart ) { return; } $ctx = Cart::ctx(); $op = isset( $ctx['op'] ) && is_array( $ctx['op'] ) ? $ctx['op'] : null;
		if ( ! $op || 'pct' !== (string) ( $op['bonus'] ?? '' ) ) { return; } $pct = (float) ( $op['bonus_pct'] ?? self::settings()['pct'] ); if ( $pct <= 0 ) { return; }
		$g = Cart::group( $cart ); if ( ! Cart::group_valid( $g ) ) { return; }
		$base = 0.0; foreach ( $cart->get_cart() as $it ) { if ( ! empty( $it[ Cart::META ] ) ) { $base += (float) ( $it['line_total'] ?? 0 ) + (float) ( $it['line_tax'] ?? 0 ); } }
		if ( $base <= 0 ) { return; } $amount = round( $base * $pct / 100, 2 );
		$cart->add_fee( self::bonus_label( 'pct', $pct ), -$amount, false );
	}
	/** Сумата на бонуса за бележката/мета (след calculate_totals). */
	public static function bonus_amount() { if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return 0.0; } foreach ( WC()->cart->get_fees() as $f ) { if ( 0 === strpos( (string) $f->name, '📞' ) ) { return abs( (float) $f->amount ); } } return 0.0; }

	/* ── токен ── */
	private static function key() { return hash_hmac( 'sha256', 'ansa_promo_phone_orders', wp_salt( 'auth' ) ); }
	private static function b64e( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); }
	private static function b64d( $s ) { return base64_decode( strtr( (string) $s, '-_', '+/' ) ); }
	/** Издава токен за източник: cart:<FKA id> или sess:<Insights session id>. $by = операторът. */
	public static function issue( $type, $id, $by = '', $now = null ) {
		$now = $now ?: time(); $type = 'cart' === $type ? 'cart' : 'sess';
		$p = self::b64e( wp_json_encode( array( 't' => $type, 'id' => (int) $id, 'by' => substr( preg_replace( '/[^\w\.\-@ ()]/u', '', (string) $by ), 0, 40 ), 'iat' => (int) $now, 'exp' => (int) $now + self::TTL ) ) );
		return $p . '.' . substr( hash_hmac( 'sha256', $p, self::key() ), 0, 40 );
	}
	public static function verify( $tok, $now = null ) {
		$tok = (string) $tok; $now = $now ?: time(); if ( '' === $tok || strlen( $tok ) > 400 || 1 !== substr_count( $tok, '.' ) ) { return null; }
		list( $p, $sig ) = explode( '.', $tok, 2 );
		if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $p ) || ! preg_match( '/^[a-f0-9]{40}$/', $sig ) || ! hash_equals( substr( hash_hmac( 'sha256', $p, self::key() ), 0, 40 ), $sig ) ) { return null; }
		$d = json_decode( self::b64d( $p ), true ); if ( ! is_array( $d ) || (int) ( $d['exp'] ?? 0 ) <= $now || ! in_array( $d['t'] ?? '', array( 'cart', 'sess' ), true ) || (int) ( $d['id'] ?? 0 ) <= 0 ) { return null; }
		return array( 't' => (string) $d['t'], 'id' => (int) $d['id'], 'by' => (string) ( $d['by'] ?? '' ), 'exp' => (int) $d['exp'] );
	}
	/** Кой оператор издава линка: потребителят на портала или WP логинът. */
	public static function operator() {
		if ( class_exists( __NAMESPACE__ . '\\Insights_Portal' ) ) { $c = Insights_Portal::current(); if ( $c ) { return (string) $c['name']; } }
		if ( function_exists( 'wp_get_current_user' ) && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) { return (string) wp_get_current_user()->user_login; }
		return '';
	}
	public static function promo_url() { $pub = Config::get_published(); return ! empty( $pub['page_id'] ) ? (string) get_permalink( (int) $pub['page_id'] ) : ''; }
	/** Линкът „Редактирай поръчка“: промо страницата с ?ansa_op= (+ ansa_bonus=pct|gift — предварително избран бонус, 1.30.1). */
	public static function recreate_url( $tok, $bonus = '' ) { $u = self::promo_url(); if ( ! $u ) { return ''; } $q = array( self::PARAM => rawurlencode( $tok ) ); if ( '' !== self::norm_bonus( $bonus ) ) { $q['ansa_bonus'] = $bonus; } return add_query_arg( $q, $u ); }
	/** Линкът „Възстанови количката“: количката на сървъра → чекаутът (+ bonus=gift|ship|pct). 1.52.0: за количка извън Сезона — началната страница (template_redirect хваща навсякъде). */
	public static function checkout_url( $tok, $bonus = '', $kind = 'season' ) { $u = 'season' === $kind ? self::promo_url() : home_url( '/' ); if ( ! $u ) { $u = home_url( '/' ); } $q = array( self::PARAM => rawurlencode( $tok ), 'go' => '1' ); if ( '' !== self::norm_bonus( $bonus ) ) { $q['bonus'] = $bonus; } return add_query_arg( $q, $u ); }
	/** Двата бутона за ред в Insights (1.30.1, човекът: „Редактирай поръчка“ и „Завърши поръчка“ — и двата питат „Отстъпка / Продукт подарък / Нищо“).
	 *  Скриптът на Insights (data-op) показва избора и добавя &bonus= / &ansa_bonus=; без JS линковете работят без бонус. $info — за title. */
	public static function buttons( $type, $id, $info = '', $kind = 'season', $has_mask = false ) {
		$tok = self::issue( $type, $id, self::operator() ); $r = self::recreate_url( $tok ); $go = self::checkout_url( $tok, '', $kind ); if ( ! $go ) { return ''; }
		$mk = $has_mask ? ' data-mask="1"' : ''; /* 1.52.0: вече има маска → изборът предлага безплатна доставка */
		/* 1.53.0: истински бутони — основният (плътен) е „Възстанови количката“, вторият (с контур) е „Редактирай“; и двата отварят нов таб (↗ идва от CSS) */
		$edit = 'season' === $kind && $r ? '<a class="ap-abtn ap-abtn--line" data-op="edit"' . $mk . ' href="' . esc_url( $r ) . '" target="_blank" rel="noopener" title="отваря промо страницата с кутията и продуктите на човека — коригирай и „Завърши поръчката“' . ( $info ? ' · ' . esc_attr( $info ) : '' ) . '">✏️ Редактирай</a>' : '';
		return '<span class="ap-op"><a class="ap-abtn ap-abtn--go" data-op="go"' . $mk . ' href="' . esc_url( $go ) . '" target="_blank" rel="noopener" title="количката на човека се прави наново на сървъра и браузърът отива в чекаута с имейл, телефон и имена — остава офисът/адресът; първо пита за жеста по скрипта">✅ Възстанови количката</a>' . $edit . '</span>';
	}
	/** За скрипта на Insights: текстовете на избора „Отстъпка / Продукт подарък / Нищо“. */
	public static function chooser_cfg() { $c = self::bonus_cfg(); return array( 'pct' => '', 'gift' => $c['gift'] ? '🎁 Колагенова маска като подарък' : '', 'ship' => '🚚 Безплатна доставка', 'none' => 'Без жест (Вид 1 — готов за поръчка)' ); } /* 1.52.0: скриптът — жестът е само за Вид 2: маска, ако няма; иначе безплатна доставка; без отстъпки */
	/** 1.52.0: количката вече съдържа маската (подарък или купена)? $lines = редовете на Cart_Sync::parse_items. */
	public static function has_mask( $lines ) {
		$g = self::gift_product(); $ids = $g ? array_filter( array( (int) $g['id'], (int) ( $g['pid'] ?? 0 ) ) ) : array();
		foreach ( (array) $lines as $l ) { if ( 'mask' === (string) ( $l['key'] ?? '' ) || 'pgift' === (string) ( $l['role'] ?? '' ) ) { return true; } if ( $ids && ( in_array( (int) ( $l['vid'] ?? 0 ), $ids, true ) || in_array( (int) ( $l['pid'] ?? 0 ), $ids, true ) ) ) { return true; }
			$n = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) ( $l['name'] ?? '' ) ) : strtolower( (string) ( $l['name'] ?? '' ) ); if ( false !== strpos( $n, 'collamask' ) || false !== strpos( $n, 'колагенова маска' ) ) { return true; } } /* 1.52.1: и по името — купена маска в количка от магазина */
		return false;
	}
	/** 1.52.0 (скриптът, „1. Преди да звъннеш — има ли и други поръчки, редовен ли е?“): профилът на клиента по billing имейл —
	 *  [n, last (Y-m-d), total, regular]; една заявка за много имейли (HPOS таблицата), резерва wc_get_orders. $skip = ID на поръчка, която не брои. */
	private static $cust = array();
	public static function customers( $emails ) {
		global $wpdb; $emails = array_values( array_unique( array_filter( array_map( function ( $e ) { return strtolower( trim( (string) $e ) ); }, (array) $emails ) ) ) );
		$todo = array_values( array_filter( $emails, function ( $e ) { return ! isset( self::$cust[ $e ] ); } ) ); foreach ( $todo as $e ) { self::$cust[ $e ] = array( 'n' => 0, 'last' => '', 'total' => 0.0, 'regular' => false ); }
		$bad = array( 'wc-cancelled', 'wc-failed', 'wc-refunded', 'wc-pending', 'wc-checkout-draft', 'trash', 'auto-draft' );
		if ( $todo ) {
			$hpos = false; try { $hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(); } catch ( \Throwable $e ) {}
			$t = $wpdb->prefix . 'wc_orders';
			if ( $hpos && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
				foreach ( array_chunk( $todo, 300 ) as $chunk ) { $in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) ); $nb = implode( ',', array_fill( 0, count( $bad ), '%s' ) );
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(billing_email) AS e, COUNT(*) AS n, MAX(date_created_gmt) AS last, SUM(total_amount) AS total FROM {$t} WHERE type = 'shop_order' AND LOWER(billing_email) IN ($in) AND status NOT IN ($nb) GROUP BY LOWER(billing_email)", array_merge( $chunk, $bad ) ), ARRAY_A ) as $r ) { self::$cust[ $r['e'] ] = array( 'n' => (int) $r['n'], 'last' => substr( (string) $r['last'], 0, 10 ), 'total' => (float) $r['total'], 'regular' => (int) $r['n'] >= 1 ); } }
			} elseif ( ! $hpos && self::posts_orders_ok() ) {
				/* 1.54.0: HPOS изключен (сайтът) → една заявка по posts + postmeta за всички имейли (беше wc_get_orders за първите 80 имейла, останалите излизаха „нов“ —
				   с филтъра „редовни / нови“ това трябва да е вярно за всеки ред) */
				$tp = isset( $wpdb->posts ) ? $wpdb->posts : $wpdb->prefix . 'posts'; $tm = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta';
				foreach ( array_chunk( $todo, 300 ) as $chunk ) { $in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) ); $nb = implode( ',', array_fill( 0, count( $bad ), '%s' ) );
					foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(pm.meta_value) AS e, COUNT(*) AS n, MAX(p.post_date_gmt) AS last, SUM(CAST(pt.meta_value AS DECIMAL(20,2))) AS total FROM {$tp} p INNER JOIN {$tm} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_email' LEFT JOIN {$tm} pt ON pt.post_id = p.ID AND pt.meta_key = '_order_total' WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ($nb) AND LOWER(pm.meta_value) IN ($in) GROUP BY LOWER(pm.meta_value)", array_merge( $bad, $chunk ) ), ARRAY_A ) as $r ) { self::$cust[ $r['e'] ] = array( 'n' => (int) $r['n'], 'last' => substr( (string) $r['last'], 0, 10 ), 'total' => (float) $r['total'], 'regular' => (int) $r['n'] >= 1 ); } }
			} elseif ( function_exists( 'wc_get_orders' ) ) {
				foreach ( array_slice( $todo, 0, 80 ) as $e ) { try { $os = (array) wc_get_orders( array( 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC', 'billing_email' => $e, 'return' => 'objects' ) ); } catch ( \Throwable $x ) { $os = array(); } $n = 0; $tot = 0.0; $last = '';
					foreach ( $os as $o ) { if ( in_array( 'wc-' . $o->get_status(), $bad, true ) || in_array( $o->get_status(), $bad, true ) ) { continue; } $n++; $tot += (float) $o->get_total(); $d = $o->get_date_created(); $ds = $d ? $d->date( 'Y-m-d' ) : ''; if ( $ds > $last ) { $last = $ds; } }
					self::$cust[ $e ] = array( 'n' => $n, 'last' => $last, 'total' => $tot, 'regular' => $n >= 1 ); }
			}
		}
		$out = array(); foreach ( $emails as $e ) { $out[ $e ] = self::$cust[ $e ]; } return $out;
	}
	/** 1.54.0: има ли таблиците posts + postmeta (поръчките без HPOS). */
	private static function posts_orders_ok() { global $wpdb; static $ok = false; if ( ! $ok ) { $tp = isset( $wpdb->posts ) ? $wpdb->posts : $wpdb->prefix . 'posts'; $tm = isset( $wpdb->postmeta ) ? $wpdb->postmeta : $wpdb->prefix . 'postmeta'; $ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tp ) ) === $tp && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tm ) ) === $tm; } return $ok; }
	/** 1.54.0 (човекът: „за стари клиенти … колко поръчки са имали и кога им е последната поръчка и с какво, нещо като профил на клиента“):
	 *  последните поръчки на клиента по billing имейл, с продуктите — [orders => [[id, number, date, status, status_name, grp (delivered|open|lost), total, items [[name, qty]]]],
	 *  delivered, open, lost (брой), top (най-купуваното: име => брой), more (има и по-стари)]. Зарежда се при клик (една заявка), не за всеки ред. */
	public static function profile( $email, $limit = 8 ) {
		$email = strtolower( trim( (string) $email ) ); $out = array( 'email' => $email, 'orders' => array(), 'delivered' => 0, 'open' => 0, 'lost' => 0, 'top' => array(), 'more' => false );
		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) { return $out; }
		try { $os = (array) wc_get_orders( array( 'limit' => (int) $limit + 1, 'orderby' => 'date', 'order' => 'DESC', 'billing_email' => $email, 'return' => 'objects' ) ); } catch ( \Throwable $e ) { $os = array(); }
		foreach ( $os as $o ) {
			if ( ! is_object( $o ) || ! method_exists( $o, 'get_status' ) ) { continue; } $st = (string) $o->get_status(); if ( in_array( $st, array( 'checkout-draft', 'trash', 'auto-draft' ), true ) ) { continue; }
			if ( method_exists( $o, 'get_billing_email' ) && strtolower( (string) $o->get_billing_email() ) !== $email ) { continue; } /* точната проверка — заявката може да е по-широка */
			if ( count( $out['orders'] ) >= $limit ) { $out['more'] = true; break; }
			$grp = class_exists( __NAMESPACE__ . '\\Tombola' ) && Tombola::delivered( $o ) ? 'delivered' : ( in_array( $st, Insights::LOST_STATUSES, true ) ? 'lost' : 'open' ); $out[ $grp ]++;
			$items = array(); if ( method_exists( $o, 'get_items' ) ) { foreach ( (array) $o->get_items() as $it ) { if ( ! is_object( $it ) || ! method_exists( $it, 'get_name' ) ) { continue; } $nm = (string) $it->get_name(); $q = max( 1, (int) ( method_exists( $it, 'get_quantity' ) ? $it->get_quantity() : 1 ) ); $items[] = array( 'name' => $nm, 'qty' => $q ); if ( 'lost' !== $grp ) { $out['top'][ $nm ] = ( $out['top'][ $nm ] ?? 0 ) + $q; } } }
			$d = method_exists( $o, 'get_date_created' ) ? $o->get_date_created() : null;
			$out['orders'][] = array( 'id' => (int) $o->get_id(), 'number' => method_exists( $o, 'get_order_number' ) ? (string) $o->get_order_number() : (string) $o->get_id(), 'date' => $d ? $d->date( 'Y-m-d' ) : '', 'status' => $st, 'status_name' => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $st ) : $st, 'grp' => $grp, 'total' => (float) $o->get_total(), 'items' => $items );
		}
		arsort( $out['top'] ); $out['top'] = array_slice( $out['top'], 0, 4, true );
		return $out;
	}
	/** 1.54.0: профилът като HTML (в изскачащия панел на 🛒). $link = линк към поръчката в wp-admin (в портала — само номерът). */
	public static function profile_html( $email, $name = '', $link = false ) {
		$p = self::profile( $email ); $c = self::customer( $email ); $n = count( $p['orders'] );
		$h = '<div class="apc-prof__h"><b>' . esc_html( '' !== trim( (string) $name ) ? $name : $p['email'] ) . '</b>' . ( '' !== trim( (string) $name ) ? '<small>' . esc_html( $p['email'] ) . '</small>' : '' ) . '</div>';
		if ( ! $n ) { return $h . '<p class="apc-prof__none">Няма предишни поръчки с този имейл — нов клиент (Сценарий Б: учтив, кратък).</p>'; }
		/* „последна“ = последната истинска поръчка (не отказана) — тя е откроена най-горе; останалите са по дата, с отказаните */
		$li = 0; foreach ( $p['orders'] as $i => $o ) { if ( 'lost' !== $o['grp'] ) { $li = $i; break; } }
		$last = $p['orders'][ $li ]; $rest = $p['orders']; unset( $rest[ $li ] );
		$h .= '<div class="apc-prof__nums"><span><b>' . (int) $c['n'] . '</b>' . ( 1 === (int) $c['n'] ? 'поръчка' : 'поръчки' ) . '</span><span><b>' . esc_html( Insights::money( $c['total'] ) ) . '</b>общо</span><span><b>' . esc_html( $last['date'] ? date_i18n( 'd.m.Y', strtotime( $last['date'] ) ) : '—' ) . '</b>последна</span></div>';
		$h .= '<div class="apc-prof__grp"><span class="ap-pill ap-pill--ok" title="получени пратки">✅ взети ' . (int) $p['delivered'] . '</span> <span class="ap-pill ap-pill--info" title="между поръчка и получаване">🚚 в път ' . (int) $p['open'] . '</span> <span class="ap-pill' . ( $p['lost'] ? ' ap-pill--bad' : ' ap-pill--muted' ) . '" title="отказани, върнати или неуспешни">✕ отказани ' . (int) $p['lost'] . '</span>' . ( $p['more'] ? ' <small>от последните ' . $n . '</small>' : '' ) . '</div>';
		$tone = array( 'delivered' => 'ok', 'open' => 'info', 'lost' => 'bad' );
		$one = function ( $o, $big ) use ( $link, $tone ) {
			$num = '#' . esc_html( $o['number'] ); if ( $link && function_exists( 'admin_url' ) ) { $num = '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $o['id'] . '&action=edit' ) ) . '" target="_blank" rel="noopener">' . $num . '</a>'; }
			$items = array(); foreach ( $o['items'] as $it ) { $items[] = esc_html( $it['name'] ) . ( $it['qty'] > 1 ? ' <b>×' . (int) $it['qty'] . '</b>' : '' ); }
			return '<div class="apc-prof__o' . ( $big ? ' is-last' : '' ) . '"><div class="apc-prof__oh"><span><b>' . ( $o['date'] ? esc_html( date_i18n( 'd.m.Y', strtotime( $o['date'] ) ) ) : '—' ) . '</b> · ' . $num . '</span><span class="ap-pill ap-pill--' . $tone[ $o['grp'] ] . '">' . esc_html( $o['status_name'] ) . '</span><b class="apc-prof__sum">' . esc_html( Insights::money( $o['total'] ) ) . '</b></div>'
				. ( $items ? ( $big ? '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>' : '<p>' . implode( ' · ', $items ) . '</p>' ) : '' ) . '</div>';
		};
		$h .= '<div class="apc-prof__l">Последна поръчка</div>' . $one( $last, true );
		if ( $rest ) { $h .= '<div class="apc-prof__l">Други поръчки</div>'; foreach ( $rest as $o ) { $h .= $one( $o, false ); } }
		if ( $p['more'] ) { $h .= '<p class="apc-prof__more">…и по-стари поръчки (показани са последните ' . $n . ').</p>'; }
		if ( $n > 1 && $p['top'] ) { $t = array(); foreach ( $p['top'] as $nm => $q ) { $t[] = esc_html( $nm ) . ' <b>×' . (int) $q . '</b>'; } $h .= '<div class="apc-prof__l">Най-често купува</div><p class="apc-prof__top">' . implode( ' · ', $t ) . '</p>'; }
		return $h;
	}
	public static function customer( $email ) { $m = self::customers( array( $email ) ); return $m[ strtolower( trim( (string) $email ) ) ] ?? array( 'n' => 0, 'last' => '', 'total' => 0.0, 'regular' => false ); }
	/** HTML баджът по скрипта: 🟢 редовен (Сценарий А) / 🆕 нов (Сценарий Б). */
	public static function customer_html( $c ) {
		if ( ! $c || empty( $c['regular'] ) ) { return '<span class="ap-pill ap-pill--info" title="няма предишни поръчки — Сценарий Б: учтив, кратък">🆕 нов · Б</span>'; }
		return '<span class="ap-pill ap-pill--ok" title="има предишни поръчки — Сценарий А: лек, приятелски; първо мнението за продуктите">🟢 редовен · А</span> <small>' . (int) $c['n'] . ( 1 === (int) $c['n'] ? ' поръчка' : ' поръчки' ) . ( $c['last'] ? ' · последна ' . esc_html( date_i18n( 'd.m.y', strtotime( $c['last'] ) ) ) : '' ) . ( $c['total'] > 0 ? ' · ' . esc_html( Insights::money( $c['total'] ) ) : '' ) . '</small>';
	}
	/** „днес / вчера / онзи ден / преди N дни“ за количката (скриптът: „поръчка от [днес / вчера / онзи ден]“). */
	public static function age_label( $dt ) { $t = strtotime( (string) $dt ); if ( ! $t ) { return ''; } $d = (int) floor( ( strtotime( current_time( 'Y-m-d' ) ) - strtotime( date( 'Y-m-d', $t ) ) ) / DAY_IN_SECONDS ); return $d <= 0 ? 'днес' : ( 1 === $d ? 'вчера' : ( 2 === $d ? 'онзи ден' : 'преди ' . $d . ' дни' ) ); }

	/* ── данните на източника ── */
	/** [ok, box, items (ключове, по 1 на опаковка), email, phone, first, last, name, label, pay, note] или null. */
	public static function load( $op ) {
		if ( ! $op ) { return null; } $cfg = Config::get_published(); $prods = Frontend::products( $cfg ); $bykey = array(); $bypid = array();
		foreach ( $prods as $k => $p ) { $bykey[ $k ] = $p; $bypid[ (int) $p['id'] ] = $k; if ( ! empty( $p['vid'] ) ) { $bypid[ (int) $p['vid'] ] = $k; } }
		$out = array( 'ok' => false, 'kind' => 'season', 'lines' => array(), 'box' => '', 'items' => array(), 'email' => '', 'phone' => '', 'first' => '', 'last' => '', 'name' => '', 'label' => '', 'pay' => 'cod', 'src' => $op['t'] . ':' . $op['id'], 'by' => $op['by'], 'note' => '', 'variant' => '' ); /* 1.49.0: variant = '' (кутиите, точен брой) или flow (потокът — кутията е по броя); 1.52.0: kind + lines за колички извън Сезона */
		if ( 'cart' === $op['t'] ) {
			$row = Cart_Sync::cart_by_id( $op['id'], 'all' ); if ( ! $row ) { $out['note'] = 'Количката не е намерена във FunnelKit.'; return $out; }
			$out['kind'] = (string) ( $row['kind'] ?? 'season' ); $out['lines'] = (array) $row['lines'];
			if ( 'season' !== $out['kind'] ) { /* магазин / Черен петък: редовете както са (продукт, вариация, брой), без кутия */
				$out['email'] = (string) $row['email']; $out['phone'] = (string) $row['phone']; $out['first'] = (string) ( $row['first'] ?? '' ); $out['last'] = (string) ( $row['last'] ?? '' ); $out['label'] = 'количка #' . (int) $row['id'] . ' · ' . $row['status_label'] . ' · ' . Cart_Sync::kind_label( $out['kind'] );
				if ( $row['order_id'] > 0 ) { $out['note'] = 'Тази количка вече е поръчка #' . (int) $row['order_id'] . '.'; }
				self::fill_from_orders( $out ); $out['name'] = trim( $out['first'] . ' ' . $out['last'] ); $out['ok'] = (bool) $out['lines']; if ( ! $out['ok'] ) { $out['note'] = 'Количката е празна.'; }
				return $out;
			}
			$out['box'] = (string) ( $row['meta']['box'] ?? '' ); $out['variant'] = ! empty( $row['meta']['flow'] ) ? 'flow' : ''; $out['email'] = (string) $row['email']; $out['phone'] = (string) $row['phone']; $out['first'] = (string) ( $row['first'] ?? '' ); $out['last'] = (string) ( $row['last'] ?? '' ); $out['label'] = 'количка #' . (int) $row['id'] . ' · ' . $row['status_label'];
			foreach ( $row['lines'] as $l ) { if ( ! in_array( (string) $l['role'], array( 'base', '' ), true ) ) { continue; } $k = (string) $l['key']; if ( '' === $k || ! isset( $bykey[ $k ] ) ) { $k = $bypid[ (int) ( $l['vid'] ?: $l['pid'] ) ] ?? ''; } if ( '' === $k ) { continue; } for ( $i = 0; $i < max( 1, (int) $l['qty'] ); $i++ ) { $out['items'][] = $k; } }
			if ( $row['order_id'] > 0 ) { $out['note'] = 'Тази количка вече е поръчка #' . (int) $row['order_id'] . '.'; }
		} else {
			global $wpdb; $s = $wpdb->get_row( $wpdb->prepare( 'SELECT s.*, l.email AS lead_email FROM ' . Schema::table( 'sessions' ) . ' s LEFT JOIN ' . Schema::table( 'leads' ) . ' l ON l.id = s.lead_id WHERE s.id = %d', (int) $op['id'] ), ARRAY_A );
			if ( ! $s ) { $out['note'] = 'Сесията не е намерена.'; return $out; }
			$out['box'] = (string) $s['box']; $out['variant'] = 'flow' === (string) ( $s['variant'] ?? '' ) ? 'flow' : ''; $out['email'] = (string) ( $s['lead_email'] ?? '' ); $out['label'] = 'сесия от ' . substr( (string) $s['started_at'], 0, 16 ) . ( $s['utm'] ? ' · ' . $s['utm'] : '' );
			$items = $s['items'] ? json_decode( (string) $s['items'], true ) : array(); foreach ( (array) $items as $k ) { $k = (string) $k; if ( isset( $bykey[ $k ] ) ) { $out['items'][] = $k; } }
			if ( ! empty( $s['order_id'] ) ) { $out['note'] = 'Тази сесия вече е поръчка #' . (int) $s['order_id'] . '.'; }
		}
		self::fill_from_orders( $out );
		$out['name'] = trim( $out['first'] . ' ' . $out['last'] );
		/* кутията: по избраната, иначе по броя опаковки; продуктите само наличните */
		$out['items'] = array_values( array_filter( $out['items'], function ( $k ) use ( $bykey ) { return isset( $bykey[ $k ] ) && ( $bykey[ $k ]['price'] ?? 0 ) > 0 && ! empty( $bykey[ $k ]['stock'] ); } ) );
		if ( '' === $out['box'] || ! Config::box( $cfg, $out['box'] ) ) { $b = Config::box_for_count( $cfg, count( $out['items'] ) ); $out['box'] = $b ? (string) $b['id'] : ( $cfg['boxes'][0]['id'] ?? 's' ); }
		$out['variant'] = self::fit_variant( $cfg, $out['variant'], $out['box'], count( $out['items'] ) ); if ( 'flow' === $out['variant'] ) { $b = Config::flow_box( $cfg, null, count( $out['items'] ) ); if ( $b ) { $out['box'] = (string) $b['id']; } } else { $out['box'] = self::fit_box( $cfg, $out['box'], count( $out['items'] ) ); }
		$out['pay'] = ! empty( $cfg['checkout']['cod_gateway'] ) ? 'cod' : 'card';
		$out['ok'] = true;
		return $out;
	}

	/** Телефон и имена от последната поръчка на WooCommerce с този имейл, ако липсват. */
	private static function fill_from_orders( &$out ) {
		if ( '' !== $out['email'] && ( '' === $out['phone'] || '' === $out['first'] ) && function_exists( 'wc_get_orders' ) ) {
			try { $prev = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'billing_email' => $out['email'], 'return' => 'objects' ) ); } catch ( \Throwable $e ) { $prev = array(); }
			try { if ( $prev ) { $o = $prev[0]; if ( '' === $out['phone'] ) { $out['phone'] = (string) $o->get_billing_phone(); } if ( '' === $out['first'] ) { $out['first'] = (string) $o->get_billing_first_name(); $out['last'] = (string) $o->get_billing_last_name(); } } } catch ( \Throwable $e ) {}
		}
	}
	/** 1.49.0 (човекът: „не ще да ми дава кода за отстъпка за спасяване на колички“ — „Завърши поръчка“ спираше с „Кутията трябва да е с точно
	 *  1 опаковка“): количката от новия дизайн „поток“ (2 опаковки в малка кутия, −20 % от стълбицата) се пресъздаваше по правилата на кутиите.
	 *  Дизайнът идва от записа (flow в реда на FunnelKit / variant на сесията); без белег (стар запис) броят решава — пасва ли точно в кутия
	 *  от стария дизайн, остава там, иначе е потокът (там всеки брой е позволен). Връща '' или 'flow'. */
	public static function fit_variant( $cfg, $variant, $box_id, $n ) {
		if ( 'flow' === (string) $variant ) { return 'flow'; } $n = (int) $n; if ( $n <= 0 ) { return ''; }
		$b = Config::box( $cfg, (string) $box_id ); if ( $b && ( ! empty( $b['grow'] ) ? $n >= (int) $b['packs'] : $n === (int) $b['packs'] ) ) { return ''; }
		$alt = Config::box_for_count( $cfg, $n ); return $alt && ( ! empty( $alt['grow'] ) || $n === (int) $alt['packs'] ) ? '' : 'flow';
	}
	/** Старият дизайн: кутията по броя, ако избраната не го побира (малка/средна = точно packs, растящата = поне packs). */
	public static function fit_box( $cfg, $box_id, $n ) {
		$n = (int) $n; $b = Config::box( $cfg, (string) $box_id ); if ( $n <= 0 || ! $b ) { return (string) $box_id; }
		if ( ! empty( $b['grow'] ) ? $n >= (int) $b['packs'] : $n === (int) $b['packs'] ) { return (string) $box_id; }
		$alt = Config::box_for_count( $cfg, $n ); return $alt ? (string) $alt['id'] : (string) $box_id;
	}
	/** Страницата с този дизайн (page_id / ab.page_id — която носи [ansa_promo variant="flow"] за потока, другата за кутиите); 0 = няма. */
	public static function page_for( $variant ) {
		$pub = Config::get_published(); $ids = array_unique( array_filter( array( (int) ( $pub['page_id'] ?? 0 ), (int) ( $pub['ab']['page_id'] ?? 0 ) ) ) );
		foreach ( $ids as $id ) { if ( ( 'flow' === (string) $variant ) === Frontend::post_is_flow( $id ) ) { return $id; } }
		return 0;
	}

	/* ── операторът на промо страницата ── */
	/** Бисквитката на оператора (сесията му в Insights е вътрешна). */
	public static function mark_operator() { if ( ! headers_sent() && empty( $_COOKIE[ self::COOKIE ] ) ) { @setcookie( self::COOKIE, '1', array( 'expires' => time() + 12 * HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ); $_COOKIE[ self::COOKIE ] = '1'; } }
	public static function is_operator() { return ! empty( $_COOKIE[ self::COOKIE ] ); }
	/** За runtime ajax-а: данните за promo.js при ?op=<токен>. */
	public static function runtime_payload( $tok ) {
		$op = self::verify( $tok ); if ( ! $op ) { return array( 'ok' => false, 'note' => 'Линкът е изтекъл или невалиден — направи нов от Insights.' ); }
		self::mark_operator(); $d = self::load( $op ); if ( ! $d ) { return array( 'ok' => false, 'note' => 'няма данни' ); }
		return array( 'ok' => $d['ok'], 'box' => $d['box'], 'items' => $d['items'], 'email' => $d['email'], 'phone' => $d['phone'], 'name' => $d['name'], 'label' => $d['label'], 'pay' => $d['pay'], 'note' => $d['note'], 'by' => $d['by'], 'bonus' => self::bonus_cfg(), 'variant' => $d['variant'] ); /* 1.49.0: variant — дизайнът на количката */ /* 1.30.0: −X % / подарък */
	}
	/** Попълва клиента на WooCommerce в сесията на оператора (чекаутът на FunnelKit чете оттам). */
	public static function prefill_customer( $d ) {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) { return; }
		try {
			$c = WC()->customer;
			if ( '' !== $d['email'] ) { $c->set_billing_email( $d['email'] ); }
			if ( '' !== $d['phone'] ) { $c->set_billing_phone( $d['phone'] ); }
			if ( '' !== $d['first'] ) { $c->set_billing_first_name( $d['first'] ); $c->set_shipping_first_name( $d['first'] ); }
			if ( '' !== $d['last'] ) { $c->set_billing_last_name( $d['last'] ); $c->set_shipping_last_name( $d['last'] ); }
			$c->save();
		} catch ( \Throwable $e ) {}
	}
	/* ── 1.54.2 (човекът: „като натисна възстанови поръчката … попълва някакви дъми данни, вместо данните на клиента“) ──
	   Чекаутът вече е „ANSA Checkout“ (plugins/ansa-checkout, скрипт с handle ansa-co): държи получателя в браузъра (#nm „Име и фамилия“, #ph, #em) и НЕ чете
	   WC()->customer / woocommerce_checkout_get_value. При празни полета ги пълни от localStorage (ansa_co_me — „последната поръчка на това устройство“), т.е.
	   операторът виждаше СВОИТЕ запомнени данни. Мостът пише данните на клиента в трите полета ПРЕДИ скрипта на чекаута (тогава той не посяга към паметта),
	   а ако все пак е закъснял — след „ansa:ready“ ги връща и отваря стъпка 1. Докато е отворен такъв чекаут, клиентът НЕ се запомня на устройството на оператора. */
	/** Данните за моста: [name, phone, email] от контекста на обаждането (ctx.op) — или null, ако няма такъв. 1.54.3: без условие is_checkout() — скриптът е
	 *  безвреден извън чекаута (няма полета — нищо не прави), а чекаутът може да е на страница, която WooCommerce не брои за „checkout“. */
	public static function bridge_data() {
		if ( ( function_exists( 'is_admin' ) && is_admin() ) || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) { return null; }
		$ctx = Cart::ctx(); $op = isset( $ctx['op'] ) && is_array( $ctx['op'] ) ? $ctx['op'] : null; if ( ! $op ) { return null; }
		$d = array( 'name' => trim( (string) ( $op['first'] ?? '' ) . ' ' . (string) ( $op['last'] ?? '' ) ), 'phone' => self::bridge_phone( (string) ( $op['phone'] ?? '' ) ), 'email' => trim( (string) ( $op['email'] ?? '' ) ) );
		return '' === $d['name'] . $d['phone'] . $d['email'] ? null : $d;
	}
	/** Телефонът за полето с префикс +359: „+359 888 123 456“ / „00359888123456“ / „888123456“ → „0888 123 456“; друг номер — както е. */
	public static function bridge_phone( $v ) {
		$d = preg_replace( '/\D+/', '', (string) $v ); if ( 0 === strpos( $d, '00359' ) ) { $d = substr( $d, 5 ); } elseif ( 0 === strpos( $d, '359' ) && strlen( $d ) >= 12 ) { $d = substr( $d, 3 ); } $d = ltrim( $d, '0' );
		return 9 === strlen( $d ) ? '0' . substr( $d, 0, 3 ) . ' ' . substr( $d, 3, 3 ) . ' ' . substr( $d, 6 ) : trim( (string) $v );
	}
	/** Скриптът на моста (без <script>). Безопасен за повторно пускане (window.__apOpFill) и безвреден на страница без полетата на чекаута.
	 *  1.54.3 (човекът: „Пак излиза Koko Koko“, оператор, влязъл с WP акаунта си): мостът от 1.54.2 разчиташе на wp_add_inline_script върху скрипта на чекаута
	 *  и на is_checkout() — на живо не се е отпечатал. Сега се печата от <head> (и пак в началото на футъра) и не зависи от реда на скриптовете:
	 *  ① докато чекаутът стартира, паметта на устройството (ansa_co_me) е невидима за него и не се презаписва; ② профилът, който сървърът подава за влязъл
	 *  потребител (ansaCO.state.customer), се подменя с клиента в момента, в който се зададе; ③ ако полетата вече са в страницата — пишат се веднага;
	 *  ④ след „ansa:ready“ (и пак след 1,5 сек) при разлика клиентът се връща и се отваря стъпка 1, освен ако операторът вече е писал в полетата;
	 *  ⑤ операторът вижда надпис „📞 Обаждане · клиент: …“ — ако го няма, мостът не е на страницата. $d = null → само надпис, че в сесията няма клиент. */
	public static function bridge_js( $d ) {
		$has = is_array( $d ); $d = $has ? $d : array( 'name' => '', 'phone' => '', 'email' => '' );
		return '(function(){if(window.__apOpFill)return;window.__apOpFill=1;var D=' . wp_json_encode( array( 'nm' => (string) $d['name'], 'ph' => (string) $d['phone'], 'em' => (string) $d['email'] ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ',HAS=' . ( $has ? 'true' : 'false' ) . ',V=' . wp_json_encode( (string) ANSA_PROMO_VER ) . ',K=["nm","ph","em"],'
			. 't=D.nm.replace(/\\s+/g," ").trim(),i=t.indexOf(" "),C={fn:i<0?t:t.slice(0,i),ln:i<0?"":t.slice(i+1),ph:D.ph,em:D.em},typed=false;'
			. 'function el(k){return document.getElementById(k)}'
			/* ⑤ надписът за оператора (само на страница с полетата на чекаута) */
			. 'function note(){if(!K.every(el)||el("apOpNote"))return;var n=document.createElement("div");n.id="apOpNote";n.setAttribute("role","status");n.style.cssText="position:fixed;left:12px;bottom:"+(window.innerWidth<768?"96px":"12px")+";z-index:2147483000;max-width:min(340px,calc(100vw - 24px));padding:9px 34px 9px 12px;border-radius:12px;background:#43302a;color:#fff;font:700 12.5px/1.4 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.25)";'
			. 'n.textContent=HAS?"📞 Обаждане · клиент: "+[D.nm,D.ph,D.em].filter(Boolean).join(" · "):"📞 Обаждане · в сесията няма данни за клиента — попълни ги ръчно";n.title="ansa™ Промо "+V;'
			. 'var x=document.createElement("button");x.type="button";x.textContent="✕";x.setAttribute("aria-label","скрий");x.style.cssText="position:absolute;right:6px;top:5px;border:0;background:transparent;color:#fff;font:700 13px/1 system-ui;cursor:pointer;padding:5px";x.onclick=function(){n.remove()};n.appendChild(x);document.body.appendChild(n)}'
			. 'if(!HAS){if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",note);else note();return}'
			/* ① паметта на устройството: чекаутът нито я чете, нито я презаписва, докато е отворена поръчка по телефона */
			. 'try{var sp=Storage.prototype,si=sp.setItem,gi=sp.getItem;sp.setItem=function(k){if(k==="ansa_co_me")return;return si.apply(this,arguments)};sp.getItem=function(k){if(k==="ansa_co_me")return null;return gi.apply(this,arguments)}}catch(e){}'
			/* ② профилът от сървъра (влязъл потребител) → клиентът */
			. 'function patch(o){try{if(o&&o.state&&o.state.customer){var c=o.state.customer;["fn","ln","ph","em"].forEach(function(k){c[k]=C[k]})}}catch(e){}}'
			. 'if(window.ansaCO)patch(window.ansaCO);else{try{var cv;Object.defineProperty(window,"ansaCO",{configurable:true,enumerable:true,get:function(){return cv},set:function(v){cv=v;patch(v)}})}catch(e){}}'
			. 'function same(){return K.every(function(k){var e=el(k);return e&&(!D[k]||e.value===D[k])})}'
			. 'function fill(ev){if(!K.every(el))return false;K.forEach(function(k){var e=el(k);if(D[k]){e.value=D[k];if(ev){try{e.dispatchEvent(new Event("input",{bubbles:true}));e.dispatchEvent(new Event("change",{bubbles:true}))}catch(x){}}}});var n=el("meNote");if(n)n.classList.add("hidden");return true}'
			. 'document.addEventListener("input",function(e){if(e.isTrusted&&e.target&&K.indexOf(e.target.id)>-1)typed=true},true);'
			/* ③ ако полетата вече са тук и чекаутът още не е стартирал: само стойностите (той сам ги проверява при старта) */
			. 'if(!window.ansaCheckout)fill(false);'
			/* ④ след старта му */
			. 'function fix(){note();if(typed||!K.every(el)||same())return;fill(true);try{if(window.ansaCheckout&&window.ansaCheckout.goStep)window.ansaCheckout.goStep(1)}catch(x){}}'
			. 'function late(){setTimeout(fix,0);setTimeout(fix,1500)}'
			. 'document.addEventListener("ansa:ready",late);if(window.ansaCheckout||document.readyState!=="loading")late();else document.addEventListener("DOMContentLoaded",late);'
			. '})();';
	}
	private static $bridge_done = false;
	/** Печата моста веднъж на заявка: с клиента от ctx.op; без него — само за оператор (бисквитката) в чекаута, като надпис „няма данни за клиента“. */
	private static function bridge_print() {
		if ( self::$bridge_done ) { return; } $d = self::bridge_data();
		if ( ! $d && ! ( self::is_operator() && function_exists( 'is_checkout' ) && is_checkout() && ! ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) ) { return; }
		self::$bridge_done = true;
		echo '<script id="ansa-promo-op-bridge">' . self::bridge_js( $d ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	public static function checkout_bridge_head() { self::bridge_print(); }
	public static function checkout_bridge_footer() { self::bridge_print(); }
	/** Резерва за чекаута: полетата от ctx.op (ако WC()->customer не ги е дал). */
	public static function checkout_value( $value, $input ) {
		if ( $value ) { return $value; } $ctx = Cart::ctx(); $op = isset( $ctx['op'] ) && is_array( $ctx['op'] ) ? $ctx['op'] : null; if ( ! $op ) { return $value; }
		$map = array( 'billing_email' => 'email', 'billing_phone' => 'phone', 'billing_first_name' => 'first', 'billing_last_name' => 'last', 'shipping_first_name' => 'first', 'shipping_last_name' => 'last' );
		return isset( $map[ $input ] ) && ! empty( $op[ $map[ $input ] ] ) ? $op[ $map[ $input ] ] : $value;
	}
	/** Контекстът за Cart::perform_add при валиден токен: [by, src, email, phone, first, last] или null. Попълва и клиента. */
	public static function ctx_for( $tok, $bonus = '' ) {
		$op = self::verify( $tok ); if ( ! $op ) { return null; } $d = self::load( $op ); if ( ! $d || ! $d['ok'] ) { return null; }
		self::mark_operator(); self::prefill_customer( $d ); $bonus = self::norm_bonus( $bonus ); if ( 'gift' === $bonus && ! self::gift_product() ) { $bonus = ''; }
		return array( 'by' => $d['by'], 'src' => $d['src'], 'email' => $d['email'], 'phone' => $d['phone'], 'first' => $d['first'], 'last' => $d['last'], 'label' => $d['label'], 'bonus' => $bonus, 'bonus_pct' => (float) self::settings()['pct'] );
	}
	/** ✅ Завърши поръчка: ?ansa_op=<токен>&go=1[&bonus=pct|gift] → количката се прави тук и браузърът отива в чекаута. */
	public static function maybe_go() {
		if ( empty( $_GET[ self::PARAM ] ) ) { return; }
		if ( empty( $_GET['go'] ) ) { self::maybe_switch_page(); return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } nocache_headers();
		$tok = (string) wp_unslash( $_GET[ self::PARAM ] ); $op = self::verify( $tok ); $d = $op ? self::load( $op ) : null;
		if ( ! $d || ! $d['ok'] ) { wp_die( esc_html( $d['note'] ?? 'Линкът е изтекъл или невалиден — направи нов от Insights.' ), '📞 Обаждане', array( 'response' => 410, 'back_link' => true ) ); }
		if ( 'season' !== (string) ( $d['kind'] ?? 'season' ) ) { self::go_generic( $tok, $d, self::norm_bonus( (string) ( $_GET['bonus'] ?? '' ) ) ); } /* 1.52.0: магазин / Черен петък */
		$cfg = Config::get_published(); $prods = Frontend::products( $cfg ); $ids = array(); foreach ( $d['items'] as $k ) { if ( isset( $prods[ $k ] ) ) { $ids[] = (int) $prods[ $k ]['id']; } }
		/* 1.49.0: дизайнът на количката (load → fit_variant): потокът праща variant=flow — кутията е по броя (2 и 4 опаковки минават); кутиите — точният брой */
		$r = Cart::perform_add( array( 'trusted' => true, 'variant' => $d['variant'], 'box' => $d['box'], 'items' => $ids, 'pay' => $d['pay'], 'email' => $d['email'], 'cosm' => true, 'sid' => Insights::request_sid( '' ), 'op' => $tok, 'op_bonus' => self::norm_bonus( (string) ( $_GET['bonus'] ?? '' ) ) ) ); /* 1.30.1: &bonus=pct|gift от избора в Insights */
		if ( empty( $r['ok'] ) ) { wp_die( esc_html( '✅ Завърши поръчка: ' . ( $r['msg'] ?? 'количката не може да се направи' ) . ' — отвори „✏️ Редактирай поръчка“ и коригирай.' ), '📞 Обаждане', array( 'response' => 409, 'back_link' => true ) ); }
		wp_safe_redirect( (string) $r['checkoutUrl'], 303 ); exit;
	}
	/** 1.52.0: количка извън Сезона (магазин / Черен петък) → същите редове в WC количката (продукт, вариация, брой; редовете на Черния петък
	 *  с тяхната мета, за да важат цените на офертата), жестът (🎁 маска на €0 / 🚚 безплатна доставка), клиентът попълнен → чекаутът. */
	public static function go_generic( $tok, $d, $bonus ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { wp_die( 'WooCommerce', '📞 Обаждане', array( 'response' => 500 ) ); }
		if ( WC()->session && ! WC()->session->has_session() ) { WC()->session->set_customer_session_cookie( true ); }
		WC()->cart->empty_cart(); $added = 0; $miss = array();
		foreach ( (array) $d['lines'] as $l ) {
			$pid = (int) ( $l['pid'] ?? 0 ); $vid = (int) ( $l['vid'] ?? 0 ); $qty = max( 1, (int) ( $l['qty'] ?? 1 ) ); if ( $pid <= 0 ) { continue; }
			$data = array(); if ( 'bf' === $d['kind'] && ! empty( $l['bf'] ) && is_array( $l['bf'] ) ) { $data[ Cart_Sync::BF_META ] = $l['bf']; }
			if ( ! empty( $l['gift'] ) || in_array( (string) ( $l['role'] ?? '' ), array( 'gift', 'pgift', 'lucky' ), true ) ) { if ( 'bf' !== $d['kind'] ) { continue; } } /* подаръците на Черния петък носят метата си (€0 през BF\Cart), другаде няма какво да се пресъздава */
			$p = wc_get_product( $vid ?: $pid ); if ( ! $p || ! $p->is_purchasable() || ! $p->is_in_stock() ) { $miss[] = (string) ( $l['name'] ?? ( '#' . ( $vid ?: $pid ) ) ); continue; }
			$attrs = $vid && $p->is_type( 'variation' ) ? (array) $p->get_variation_attributes() : array();
			try { $k = WC()->cart->add_to_cart( $pid, $qty, $vid, $attrs, $data ); } catch ( \Throwable $e ) { $k = false; }
			if ( $k ) { $added++; } else { $miss[] = (string) ( $l['name'] ?? ( '#' . ( $vid ?: $pid ) ) ); }
		}
		if ( ! $added ) { wp_die( esc_html( '✅ Възстанови количката: нито един продукт не може да се добави (' . implode( ', ', $miss ) . ').' ), '📞 Обаждане', array( 'response' => 409, 'back_link' => true ) ); }
		if ( 'gift' === $bonus && ! self::gift_product() ) { $bonus = ''; }
		if ( 'gift' === $bonus ) { self::add_gift_generic(); }
		self::mark_operator(); self::prefill_customer( $d );
		$ctx = Cart::ctx(); $ctx['op'] = array( 'by' => $d['by'], 'src' => $d['src'], 'email' => $d['email'], 'phone' => $d['phone'], 'first' => $d['first'], 'last' => $d['last'], 'label' => $d['label'], 'bonus' => $bonus, 'bonus_pct' => 0.0, 'kind' => $d['kind'], 'miss' => $miss ); if ( WC()->session ) { WC()->session->set( Cart::CTX, $ctx ); }
		WC()->cart->calculate_totals();
		wp_safe_redirect( (string) wc_get_checkout_url(), 303 ); exit;
	}
	/** 1.49.0: ✏️ Редактирай поръчка отваря страницата, чийто дизайн е на количката — количка от потока на страницата с кутиите (или обратно)
	 *  би показала друг екран и друг брой. Ако текущата страница е с другия дизайн и има страница с правилния, браузърът отива там със същия адрес. */
	public static function maybe_switch_page() {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() || ! Frontend::is_promo_page() ) { return; }
		$op = self::verify( (string) wp_unslash( $_GET[ self::PARAM ] ) ); $d = $op ? self::load( $op ) : null; if ( ! $d || ! $d['ok'] ) { return; }
		$want = (string) $d['variant']; if ( $want === Frontend::page_variant() ) { return; }
		$pid = self::page_for( $want ); if ( ! $pid || $pid === (int) get_queried_object_id() || 'publish' !== get_post_status( $pid ) ) { return; }
		$url = get_permalink( $pid ); if ( ! $url ) { return; }
		$q = array( self::PARAM => rawurlencode( (string) wp_unslash( $_GET[ self::PARAM ] ) ) ); $b = self::norm_bonus( (string) ( $_GET['ansa_bonus'] ?? '' ) ); if ( '' !== $b ) { $q['ansa_bonus'] = $b; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } nocache_headers();
		wp_safe_redirect( add_query_arg( $q, $url ), 302 ); exit;
	}
	/** Поръчката: бележка в WooCommerce. */
	public static function on_order( $order, $m ) {
		if ( empty( $m['phone_order'] ) || ! is_array( $m['phone_order'] ) ) { return; } $po = $m['phone_order'];
		$bn = (string) ( $po['bonus'] ?? '' ); $bt = 'pct' === $bn ? ' · бонус: −' . Cart::fmt( (float) ( $po['bonus_pct'] ?? 0 ) ) . ' %' . ( ! empty( $po['bonus_amount'] ) ? ' (' . Insights::money( $po['bonus_amount'] ) . ')' : '' ) : ( 'gift' === $bn ? ' · бонус: подарък ' . (string) ( $po['gift'] ?? '' ) : '' );
		try { $order->add_order_note( '📞 Поръчка по телефон' . ( ! empty( $po['by'] ) ? ' от „' . $po['by'] . '“' : '' ) . ( ! empty( $po['label'] ) ? ' · от ' . $po['label'] : '' ) . $bt . ' (ansa™ Промо, Insights → Обаждания).' ); } catch ( \Throwable $e ) {}
		self::mark_recovered( $po, $order ); /* 1.52.0 */
	}
	/** 1.52.0: поръчката от количка извън Сезона (магазин / Черен петък) с контекст на оператора: мета, бележка, 📋 възстановена (+ FunnelKit). */
	public static function on_any_order( $order_id ) {
		$ctx = Cart::ctx(); $op = isset( $ctx['op'] ) && is_array( $ctx['op'] ) ? $ctx['op'] : null; if ( ! $op || '' === (string) ( $op['src'] ?? '' ) ) { return; }
		$order = is_object( $order_id ) ? $order_id : wc_get_order( (int) $order_id ); if ( ! $order ) { return; }
		if ( is_array( $order->get_meta( '_ansa_promo' ) ) && ! empty( $order->get_meta( '_ansa_promo' )['phone_order'] ) ) { return; } /* Сезонът минава през on_order */
		$bn = (string) ( $op['bonus'] ?? '' ); $po = array( 'by' => (string) ( $op['by'] ?? '' ), 'src' => (string) $op['src'], 'label' => (string) ( $op['label'] ?? '' ), 'bonus' => $bn, 'kind' => (string) ( $op['kind'] ?? '' ), 'gift' => 'gift' === $bn && ( $g = self::gift_product() ) ? $g['name'] : '' );
		try { $order->update_meta_data( '_ansa_promo_phone', $po ); $order->save_meta_data(); $order->add_order_note( '📞 Поръчка по телефон' . ( $po['by'] ? ' от „' . $po['by'] . '“' : '' ) . ( $po['label'] ? ' · от ' . $po['label'] : '' ) . ( '' !== $bn ? ' · жест: ' . self::bonus_short( $bn ) : '' ) . ( ! empty( $op['miss'] ) ? ' · НЕ влязоха: ' . implode( ', ', (array) $op['miss'] ) : '' ) . ' (ansa™ Промо, Insights → Обаждания).' ); } catch ( \Throwable $e ) {}
		self::mark_recovered( $po, $order );
	}
	/** 📋 Cart_Work → възстановена с поръчката и жеста (и във FunnelKit през Cart_Work::save). */
	public static function mark_recovered( $po, $order ) {
		if ( ! class_exists( __NAMESPACE__ . '\\Cart_Work' ) || ! preg_match( '/^(cart|sess):(\d+)$/', (string) ( $po['src'] ?? '' ), $m ) ) { return; }
		try { Cart_Work::save( array( 'src' => $m[1], 'id' => (int) $m[2], 'status' => 'recovered', 'order' => (string) $order->get_id(), 'gesture' => (string) ( $po['bonus'] ?? '' ) ), (string) ( $po['by'] ?? '' ) ); } catch ( \Throwable $e ) {}
	}
	public static function is_phone_sid( $sid ) { return 0 === strpos( (string) $sid, self::SID ); }
	public static function sid_for( $order_id ) { return self::SID . (int) $order_id; }
}
