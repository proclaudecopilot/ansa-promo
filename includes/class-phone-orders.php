<?php
/**
 * ansa™ Промо 1.29.0 — 📞 Обаждания: поръчка по телефон от изоставена количка или сесия (човекът: „Трябва да почнем да звъним на
 * изоставените — но да можем с 1 бутон да пресъздадем количката им (голяма/малка етц кутия), ако трябва да се нанесат корекции, и да
 * отидем на чекаут (да пренапълни имейл, телефон и две имена, ако са запазени, и само да се избере офис)“).
 *
 *  - От 📈 Insights (🛒 Колички, 🧾 Сесии, 🟢 На живо — в админа и в портала) всеки ред с имейл и без поръчка има два бутона:
 *      📞 Пресъздай — отваря промо страницата в браузъра на оператора с ВЕЧЕ избраната кутия и продуктите в нея (екранът за редакция),
 *                     имейлът е попълнен, попъп няма; операторът коригира, ако трябва, и натиска „Към плащане“ → чекаутът е с имейл,
 *                     телефон и имена на клиента — остава офисът/адресът;
 *      ⚡ Чекаут   — направо: количката се прави на сървъра и браузърът отива в чекаута с попълнените данни (без корекции).
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
		add_action( 'template_redirect', array( __CLASS__, 'maybe_go' ), 1 ); /* ⚡ Чекаут: ?ansa_op=…&go=1 на промо страницата */
		add_action( 'ansa_promo_order_created', array( __CLASS__, 'on_order' ), 30, 2 );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'checkout_value' ), 20, 2 );
	}

	/* ── 1.30.0: бонусът за обаждането (човекът: „5 % върху поръчката, ако се съгласят по телефона, или продукт подарък — PATCH01 EyeFresh BG“) ──
	   Операторът избира на страницата: без · −X % на поръчката (отрицателна такса „📞 Отстъпка по телефона“ върху промо редовете, след
	   промо цените) · 🎁 продукт подарък (ред с META role=gift на €0 — като книгата: заключен, не брои за опаковка, пада при непълна кутия). */
	const OPT = 'ansa_promo_phone';
	public static function defaults() { return array( 'pct' => 5, 'gift_sku' => 'PATCH01', 'gift_id' => 0 ); }
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
		$id = (int) $s['gift_id']; if ( ! $id && '' !== $s['gift_sku'] ) { $id = Catalog::find_by_sku( $s['gift_sku'] ); }
		if ( ! $id && '' !== $s['gift_sku'] ) { $r = Catalog::search( preg_replace( '/^[A-Z0-9]+\s+/', '', $s['gift_sku'] ) ?: $s['gift_sku'], 1 ); if ( $r ) { $id = (int) $r[0]['id']; } }
		if ( $id ) { $p = wc_get_product( $id ); if ( $p && $p->is_purchasable() ) { self::$gift = array( 'id' => (int) $p->get_id(), 'name' => (string) $p->get_name(), 'price' => (float) $p->get_price(), 'stock' => (bool) $p->is_in_stock() ); } }
		return self::$gift;
	}
	public static function norm_bonus( $v ) { $v = (string) $v; return in_array( $v, array( 'pct', 'gift' ), true ) ? $v : ''; }
	/** За promo.js: какви бонуси може да предложи операторът. */
	public static function bonus_cfg() { $s = self::settings(); $g = self::gift_product(); return array( 'pct' => (float) $s['pct'], 'gift' => $g && $g['stock'] ? array( 'id' => $g['id'], 'name' => $g['name'] ) : null ); }
	public static function bonus_label( $bonus, $pct = null ) { $s = self::settings(); if ( 'pct' === $bonus ) { return '📞 Отстъпка по телефона −' . Cart::fmt( null === $pct ? $s['pct'] : $pct ) . ' %'; } if ( 'gift' === $bonus ) { $g = self::gift_product(); return '🎁 Подарък за обаждането' . ( $g ? ': ' . $g['name'] : '' ); } return ''; }
	/** Редът подарък в количката (role=gift, €0 през Cart::apply_pricing). */
	public static function add_gift( $meta ) {
		$g = self::gift_product(); if ( ! $g || ! function_exists( 'WC' ) || ! WC()->cart ) { return false; }
		foreach ( WC()->cart->get_cart() as $it ) { if ( ! empty( $it[ Cart::META ]['role'] ) && 'gift' === $it[ Cart::META ]['role'] ) { return true; } }
		return (bool) WC()->cart->add_to_cart( (int) $g['id'], 1, 0, array(), array( Cart::META => array_merge( (array) $meta, array( 'role' => 'gift', 'key' => 'gift' ) ) ) );
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
	public static function recreate_url( $tok ) { $u = self::promo_url(); return $u ? add_query_arg( array( self::PARAM => rawurlencode( $tok ) ), $u ) : ''; }
	public static function checkout_url( $tok ) { $u = self::promo_url(); return $u ? add_query_arg( array( self::PARAM => rawurlencode( $tok ), 'go' => '1' ), $u ) : ''; }
	/** Двата бутона за ред в Insights. $info — текст за title (имейл/телефон). */
	public static function buttons( $type, $id, $info = '' ) {
		$tok = self::issue( $type, $id, self::operator() ); $r = self::recreate_url( $tok ); if ( ! $r ) { return ''; }
		return '<span class="ap-op"><a class="ap-chip ap-chip--sm" href="' . esc_url( $r ) . '" target="_blank" rel="noopener" title="отваря промо страницата с кутията и продуктите на човека — коригирай и „Към плащане“' . ( $info ? ' · ' . esc_attr( $info ) : '' ) . '">📞 Пресъздай</a> <a class="ap-chip ap-chip--sm" href="' . esc_url( self::checkout_url( $tok ) ) . '" target="_blank" rel="noopener" title="направо в чекаута с количката и данните на човека">⚡ Чекаут</a></span>';
	}

	/* ── данните на източника ── */
	/** [ok, box, items (ключове, по 1 на опаковка), email, phone, first, last, name, label, pay, note] или null. */
	public static function load( $op ) {
		if ( ! $op ) { return null; } $cfg = Config::get_published(); $prods = Frontend::products( $cfg ); $bykey = array(); $bypid = array();
		foreach ( $prods as $k => $p ) { $bykey[ $k ] = $p; $bypid[ (int) $p['id'] ] = $k; if ( ! empty( $p['vid'] ) ) { $bypid[ (int) $p['vid'] ] = $k; } }
		$out = array( 'ok' => false, 'box' => '', 'items' => array(), 'email' => '', 'phone' => '', 'first' => '', 'last' => '', 'name' => '', 'label' => '', 'pay' => 'cod', 'src' => $op['t'] . ':' . $op['id'], 'by' => $op['by'], 'note' => '' );
		if ( 'cart' === $op['t'] ) {
			$row = Cart_Sync::cart_by_id( $op['id'] ); if ( ! $row ) { $out['note'] = 'Количката не е намерена във FunnelKit.'; return $out; }
			$out['box'] = (string) ( $row['meta']['box'] ?? '' ); $out['email'] = (string) $row['email']; $out['phone'] = (string) $row['phone']; $out['first'] = (string) ( $row['first'] ?? '' ); $out['last'] = (string) ( $row['last'] ?? '' ); $out['label'] = 'количка #' . (int) $row['id'] . ' · ' . $row['status_label'];
			foreach ( $row['lines'] as $l ) { if ( ! in_array( (string) $l['role'], array( 'base', '' ), true ) ) { continue; } $k = (string) $l['key']; if ( '' === $k || ! isset( $bykey[ $k ] ) ) { $k = $bypid[ (int) ( $l['vid'] ?: $l['pid'] ) ] ?? ''; } if ( '' === $k ) { continue; } for ( $i = 0; $i < max( 1, (int) $l['qty'] ); $i++ ) { $out['items'][] = $k; } }
			if ( $row['order_id'] > 0 ) { $out['note'] = 'Тази количка вече е поръчка #' . (int) $row['order_id'] . '.'; }
		} else {
			global $wpdb; $s = $wpdb->get_row( $wpdb->prepare( 'SELECT s.*, l.email AS lead_email FROM ' . Schema::table( 'sessions' ) . ' s LEFT JOIN ' . Schema::table( 'leads' ) . ' l ON l.id = s.lead_id WHERE s.id = %d', (int) $op['id'] ), ARRAY_A );
			if ( ! $s ) { $out['note'] = 'Сесията не е намерена.'; return $out; }
			$out['box'] = (string) $s['box']; $out['email'] = (string) ( $s['lead_email'] ?? '' ); $out['label'] = 'сесия от ' . substr( (string) $s['started_at'], 0, 16 ) . ( $s['utm'] ? ' · ' . $s['utm'] : '' );
			$items = $s['items'] ? json_decode( (string) $s['items'], true ) : array(); foreach ( (array) $items as $k ) { $k = (string) $k; if ( isset( $bykey[ $k ] ) ) { $out['items'][] = $k; } }
			if ( ! empty( $s['order_id'] ) ) { $out['note'] = 'Тази сесия вече е поръчка #' . (int) $s['order_id'] . '.'; }
		}
		/* телефон и имена от последната поръчка на WooCommerce с този имейл, ако липсват */
		if ( '' !== $out['email'] && ( '' === $out['phone'] || '' === $out['first'] ) && function_exists( 'wc_get_orders' ) ) {
			try { $prev = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'billing_email' => $out['email'], 'return' => 'objects' ) ); } catch ( \Throwable $e ) { $prev = array(); }
			if ( $prev ) { $o = $prev[0]; if ( '' === $out['phone'] ) { $out['phone'] = (string) $o->get_billing_phone(); } if ( '' === $out['first'] ) { $out['first'] = (string) $o->get_billing_first_name(); $out['last'] = (string) $o->get_billing_last_name(); } }
		}
		$out['name'] = trim( $out['first'] . ' ' . $out['last'] );
		/* кутията: по избраната, иначе по броя опаковки; продуктите само наличните */
		$out['items'] = array_values( array_filter( $out['items'], function ( $k ) use ( $bykey ) { return isset( $bykey[ $k ] ) && ( $bykey[ $k ]['price'] ?? 0 ) > 0 && ! empty( $bykey[ $k ]['stock'] ); } ) );
		if ( '' === $out['box'] || ! Config::box( $cfg, $out['box'] ) ) { $b = Config::box_for_count( $cfg, count( $out['items'] ) ); $out['box'] = $b ? (string) $b['id'] : ( $cfg['boxes'][0]['id'] ?? 's' ); }
		$out['pay'] = ! empty( $cfg['checkout']['cod_gateway'] ) ? 'cod' : 'card';
		$out['ok'] = true;
		return $out;
	}

	/* ── операторът на промо страницата ── */
	/** Бисквитката на оператора (сесията му в Insights е вътрешна). */
	public static function mark_operator() { if ( ! headers_sent() && empty( $_COOKIE[ self::COOKIE ] ) ) { @setcookie( self::COOKIE, '1', array( 'expires' => time() + 12 * HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ); $_COOKIE[ self::COOKIE ] = '1'; } }
	public static function is_operator() { return ! empty( $_COOKIE[ self::COOKIE ] ); }
	/** За runtime ajax-а: данните за promo.js при ?op=<токен>. */
	public static function runtime_payload( $tok ) {
		$op = self::verify( $tok ); if ( ! $op ) { return array( 'ok' => false, 'note' => 'Линкът е изтекъл или невалиден — направи нов от Insights.' ); }
		self::mark_operator(); $d = self::load( $op ); if ( ! $d ) { return array( 'ok' => false, 'note' => 'няма данни' ); }
		return array( 'ok' => $d['ok'], 'box' => $d['box'], 'items' => $d['items'], 'email' => $d['email'], 'phone' => $d['phone'], 'name' => $d['name'], 'label' => $d['label'], 'pay' => $d['pay'], 'note' => $d['note'], 'by' => $d['by'], 'bonus' => self::bonus_cfg() ); /* 1.30.0: −X % / подарък */
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
	/** ⚡ Чекаут: ?ansa_op=<токен>&go=1 → количката се прави тук и браузърът отива в чекаута. */
	public static function maybe_go() {
		if ( empty( $_GET[ self::PARAM ] ) || empty( $_GET['go'] ) ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } nocache_headers();
		$tok = (string) wp_unslash( $_GET[ self::PARAM ] ); $op = self::verify( $tok ); $d = $op ? self::load( $op ) : null;
		if ( ! $d || ! $d['ok'] ) { wp_die( esc_html( $d['note'] ?? 'Линкът е изтекъл или невалиден — направи нов от Insights.' ), '📞 Обаждане', array( 'response' => 410, 'back_link' => true ) ); }
		$cfg = Config::get_published(); $prods = Frontend::products( $cfg ); $ids = array(); foreach ( $d['items'] as $k ) { if ( isset( $prods[ $k ] ) ) { $ids[] = (int) $prods[ $k ]['id']; } }
		$box = Config::box( $cfg, $d['box'] ); if ( $box && count( $ids ) < (int) $box['packs'] ) { $b2 = Config::box_for_count( $cfg, count( $ids ) ); if ( $b2 ) { $d['box'] = (string) $b2['id']; } }
		$r = Cart::perform_add( array( 'trusted' => true, 'box' => $d['box'], 'items' => $ids, 'pay' => $d['pay'], 'email' => $d['email'], 'cosm' => true, 'sid' => Insights::request_sid( '' ), 'op' => $tok ) );
		if ( empty( $r['ok'] ) ) { wp_die( esc_html( '⚡ Чекаут: ' . ( $r['msg'] ?? 'количката не може да се направи' ) . ' — отвори „📞 Пресъздай“ и коригирай.' ), '📞 Обаждане', array( 'response' => 409, 'back_link' => true ) ); }
		wp_safe_redirect( (string) $r['checkoutUrl'], 303 ); exit;
	}
	/** Поръчката: бележка в WooCommerce. */
	public static function on_order( $order, $m ) {
		if ( empty( $m['phone_order'] ) || ! is_array( $m['phone_order'] ) ) { return; } $po = $m['phone_order'];
		$bn = (string) ( $po['bonus'] ?? '' ); $bt = 'pct' === $bn ? ' · бонус: −' . Cart::fmt( (float) ( $po['bonus_pct'] ?? 0 ) ) . ' %' . ( ! empty( $po['bonus_amount'] ) ? ' (' . Insights::money( $po['bonus_amount'] ) . ')' : '' ) : ( 'gift' === $bn ? ' · бонус: подарък ' . (string) ( $po['gift'] ?? '' ) : '' );
		try { $order->add_order_note( '📞 Поръчка по телефон' . ( ! empty( $po['by'] ) ? ' от „' . $po['by'] . '“' : '' ) . ( ! empty( $po['label'] ) ? ' · от ' . $po['label'] : '' ) . $bt . ' (ansa™ Промо, Insights → Обаждания).' ); } catch ( \Throwable $e ) {}
	}
	public static function is_phone_sid( $sid ) { return 0 === strpos( (string) $sid, self::SID ); }
	public static function sid_for( $order_id ) { return self::SID . (int) $order_id; }
}
