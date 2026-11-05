<?php
/**
 * ansa™ Промо — Черен петък 2026 (второто промо на плъгина): конфигурация.
 *
 * Отделен option `ansa_promo_bf_store` = { draft, published, published_at, version, history[] } — същият модел като Сезона (Config),
 * но свой: Сезонът остава както е, Черният петък има свои продукти, фази, ваучери, лийдове и текстове. Всичко минава през normalize().
 *
 * Фазите (по часа на сайта): prep (събиране на списъка: студената страница с ваучер) → early (48 ч. ранен достъп за списъка, с линк от
 * имейла ?ea=ТОКЕН) → public (72 ч. за всички) → over. `force` подменя сметката по дати (напр. да се отвори ранният достъп по-рано).
 *
 * @package AnsaPromo
 * @since 1.45.0
 */
namespace AnsaPromo\BF;

defined( 'ABSPATH' ) || exit;

final class Config {

	const OPTION  = 'ansa_promo_bf_store';
	const HISTORY = 10;
	const PHASES  = array( 'prep', 'early', 'pause', 'public', 'over' ); /* 1.48.7: pause = между края на ранния достъп и публичния старт */
	private static $memory = null;

	public static function defaults() {
		return array(
			'id'       => 'bf_2026',
			'name'     => 'Черен петък 2026',
			'enabled'  => false,
			'slug'     => 'cheren-petak-2026',
			/* фазите по часа на сайта (Y-m-d H:i); public_start по подразбиране = early_end */
			'phases'   => array( 'early_start' => '2026-11-25 10:00', 'early_end' => '2026-11-27 10:00', 'public_start' => '2026-11-27 10:00', 'public_end' => '2026-11-30 10:00' ),
			'force'    => '', /* '' = по датите · prep | early | public | over */
			/* продуктите: key (utm стойност), sku на вариацията „1 опаковка“ (печели над product_id), product_id, name/sub (празно = от WC), cat (етикет за
			   падащото меню), gift_only (само подарък — новите 4), is_new, fav (Най-продавани — първи в списъка), enabled, img (attachment id или URL) */
			'products' => self::seed_products(),
			/* студеният трафик (prep): ваучер в портфейла за N минути след отключване; landing_minutes = таймерът на страницата преди отключване */
			'cold'     => array( 'enabled' => true, 'voucher' => 10, 'minutes' => 30, 'landing_minutes' => 17, 'prefix' => 'BF' ),
			/* броячът „записани жени“: start в ден 0 (from), клони към max без да го стига: n = max − (max − start)·e^(−d/k) */
			'seats'    => array( 'enabled' => true, 'start' => 312, 'max' => 440, 'k' => 9, 'from' => '2026-11-04' ),
			/* партньорският трафик (?ref=КОД на ansa Partner): подарък по избор в публичната промоция — ваучер или маската (CollaMask „10 маски“ #11163; SKU-то на продукта е MASK02) */
			'aff'      => array( 'enabled' => true, 'voucher' => 5, 'mask_sku' => '', 'mask_product_id' => 11163, 'cookie_days' => 7 ), /* 1.45.1: CollaMask „10 маски“ (#11163, 29,90 €); SKU-то на продукта е MASK02 (вариациите: „MASK02;MASK02;…“), MASK01 няма */
			/* ранният достъп: общ токен в линка от имейла (?ea=ТОКЕН → бисквитка); генерира се сам при първия запис */
			'early'    => array( 'token' => '', 'cookie_days' => 7 ),
			/* 1.46.0: ваучерите за списъка в ранния достъп — (а) неизползван → възобновен (същият код), (б) използван → нов „ВИП“ ваучер; тагове за FunnelKit; until = early_end | public_end */
			'listv'    => array( 'enabled' => true, 'amount' => 10, 'until' => 'early_end', 'prefix' => 'BFV', 'tag_revived' => 'bf-revived', 'tag_vip' => 'bf-vip', 'auto' => true ),
			/* 1.46.0: попъпът „Ти си късметлия“ на стъпка 2 — 1 бр CollaMask (#11161 „1 маска“; на сайта няма SKU MASK01 — ако го дадеш на вариацията, намира се по SKU) */
			'lucky'    => array( 'enabled' => true, 'product_id' => 11161, 'sku' => '', 'dwell' => 45, 'exit' => true, 'phases' => 'early,public' ),
			'ship'     => array( 'free_from' => 2, 'paid' => 2.55 ),
			'checkout' => array( 'card_gateway' => 'fkwcs_stripe', 'cod_gateway' => 'cod', 'block_coupons' => true ),
			/* лийдовете → FunnelKit: един лист за всички + таг по вид (студен / партньор); при поръчка таговете падат */
			'leads'    => array( 'fka' => true, 'list' => 'Черен петък 2026 — лийдове', 'tag_cold' => 'bf-cold', 'tag_aff' => 'bf-partner', 'tag_pause' => 'bf-reminder', 'consent_only' => false, 'untag_on_order' => true ),
			'orders'   => array( 'fka_tag' => 'cheren-petak-2026' ),
			'preview'  => array( 'password' => '' ),
			'logo'     => '', /* attachment id или URL; празно = assets/img/bf/logo-white.png */
			'copy'     => array(),
		);
	}

	/** Началният списък — ключовете и SKU/ID са тези от живия сайт (03.11.2026); името и подзаглавието идват от WooCommerce, ако са празни. */
	public static function seed_products() {
		$row = function ( $key, $sku, $pid, $name, $sub, $cat, $opts = array() ) { return array_merge( self::product_defaults(), array( 'key' => $key, 'sku' => $sku, 'product_id' => $pid, 'name' => $name, 'sub' => $sub, 'cat' => $cat ), $opts ); };
		return array(
			$row( 'greens',   'dgr001', 3994,   'Daily Greens',      'чревно здраве и лекота',       'Стомах',  array( 'fav' => true ) ),
			$row( 'berber',   'BRBR',   11546,  'ДихидроБерберин',   'апетит и кръвна захар',        'Тегло',   array( 'fav' => true ) ),
			$row( 'sakura',   'SKST',   28726,  'Sakura SkinTonic',  'кожа, тен и фини линии',       'Кожа',    array( 'fav' => true ) ),
			$row( 'yoni',     '',       15836,  'YoniBiotic 3в1',    'пробиотик за жени',            'За жени', array( 'fav' => true ) ),
			$row( 'red',      'prod002', 3875,  'Red Routine',       'плодова добавка за сън',       'Сън',     array( 'fav' => true ) ),
			$row( 'meno',     'MENO',   22307,  'MenoStop',          'дневна и нощна капсула',       'За жени' ),
			$row( 'utera',    '',       58069,  'Utera+',            'репродуктивна система и ПМС',  'За жени' ),
			$row( 'thyro',    'prod003', 1298,  'ThyroZen',          'щитовидна жлеза',              'Тяло' ),
			$row( 'ashwa',    '',       3796,   'Ашваганда',         'стрес и спокойствие',          'Сън' ),
			$row( 'kera',     '',       106954, 'KeraTonica',        'коса, мигли и вежди',          'Кожа',    array( 'gift_only' => true, 'is_new' => true ) ),
			$row( 'alivia',   '',       106944, 'Alivia',            'скованост, дискомфорт',        'Тяло',    array( 'gift_only' => true, 'is_new' => true ) ),
			$row( 'veno',     '',       106949, 'VenoTonus',         'здрави кръвоносни съдове',     'Тяло',    array( 'gift_only' => true, 'is_new' => true ) ),
			$row( 'ahcc',     '',       106936, 'AHCC-Бета',         'имунитет, гъбен комплекс',     'Тяло',    array( 'gift_only' => true, 'is_new' => true ) ),
		);
	}
	public static function product_defaults() {
		return array( 'key' => '', 'sku' => '', 'product_id' => 0, 'name' => '', 'sub' => '', 'cat' => '', 'gift_only' => false, 'is_new' => false, 'fav' => false, 'enabled' => true, 'img' => '', 'desc' => '', 'ing' => '', 'how' => '', 'who' => '' ); /* desc/ing/how/who: попъпът „Прочети повече“; празно desc = краткото описание от WC */
	}

	/* ── normalize ── */
	public static function normalize( $data ) {
		$d = self::defaults();
		if ( ! is_array( $data ) ) { $data = array(); }
		$out = self::merge( $d, $data );
		$out['id']      = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $out['id'] ) ?: $d['id'];
		$out['name']    = sanitize_text_field( (string) $out['name'] ) ?: $d['name'];
		$out['enabled'] = ! empty( $out['enabled'] );
		$out['slug']    = sanitize_title( (string) $out['slug'] ) ?: $d['slug'];
		$ph = is_array( $out['phases'] ?? null ) ? $out['phases'] : array(); $out['phases'] = array();
		foreach ( $d['phases'] as $k => $v ) { $t = trim( (string) ( $ph[ $k ] ?? '' ) ); $out['phases'][ $k ] = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $t ) ? $t : $v; }
		$f = sanitize_key( (string) ( $out['force'] ?? '' ) ); $out['force'] = in_array( $f, self::PHASES, true ) ? $f : '';
		/* products */
		$prods = array(); $seen = array();
		foreach ( (array) $out['products'] as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$p = self::merge( self::product_defaults(), $p );
			$p['key'] = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $p['key'] ) ); $p['product_id'] = (int) $p['product_id']; $p['sku'] = sanitize_text_field( (string) $p['sku'] );
			if ( '' === $p['key'] && $p['product_id'] ) { $p['key'] = 'p' . $p['product_id']; }
			if ( '' === $p['key'] || isset( $seen[ $p['key'] ] ) ) { continue; }
			$seen[ $p['key'] ] = 1;
			foreach ( array( 'name', 'sub', 'cat' ) as $f2 ) { $p[ $f2 ] = sanitize_text_field( (string) $p[ $f2 ] ); }
			foreach ( array( 'desc', 'ing', 'how', 'who' ) as $f3 ) { $p[ $f3 ] = wp_kses( (string) $p[ $f3 ], array( 'b' => array(), 'strong' => array(), 'em' => array(), 'br' => array() ) ); }
			foreach ( array( 'gift_only', 'is_new', 'fav', 'enabled' ) as $b ) { $p[ $b ] = ! empty( $p[ $b ] ); }
			$im = trim( (string) $p['img'] ); $p['img'] = ctype_digit( $im ) ? (string) (int) $im : esc_url_raw( $im );
			$prods[] = $p;
		}
		$out['products'] = $prods;
		$c = is_array( $out['cold'] ?? null ) ? $out['cold'] : array(); $out['cold'] = array( 'enabled' => ! isset( $c['enabled'] ) || ! empty( $c['enabled'] ), 'voucher' => max( 0, round( (float) ( $c['voucher'] ?? 10 ), 2 ) ), 'minutes' => max( 1, min( 1440, (int) ( $c['minutes'] ?? 30 ) ) ), 'landing_minutes' => max( 1, min( 180, (int) ( $c['landing_minutes'] ?? 17 ) ) ), 'prefix' => strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) ( $c['prefix'] ?? 'BF' ) ) ) ?: 'BF' );
		$s = is_array( $out['seats'] ?? null ) ? $out['seats'] : array(); $from = trim( (string) ( $s['from'] ?? '' ) );
		$out['seats'] = array( 'enabled' => ! isset( $s['enabled'] ) || ! empty( $s['enabled'] ), 'start' => max( 0, (int) ( $s['start'] ?? 312 ) ), 'max' => max( 1, (int) ( $s['max'] ?? 440 ) ), 'k' => max( 0.5, (float) ( $s['k'] ?? 9 ) ), 'from' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : $d['seats']['from'] );
		if ( $out['seats']['start'] >= $out['seats']['max'] ) { $out['seats']['start'] = max( 0, $out['seats']['max'] - 1 ); }
		$a = is_array( $out['aff'] ?? null ) ? $out['aff'] : array(); $out['aff'] = array( 'enabled' => ! isset( $a['enabled'] ) || ! empty( $a['enabled'] ), 'voucher' => max( 0, round( (float) ( $a['voucher'] ?? 5 ), 2 ) ), 'mask_sku' => sanitize_text_field( (string) ( $a['mask_sku'] ?? '' ) ), 'mask_product_id' => (int) ( $a['mask_product_id'] ?? 0 ), 'cookie_days' => max( 1, min( 60, (int) ( $a['cookie_days'] ?? 7 ) ) ) );
		$e = is_array( $out['early'] ?? null ) ? $out['early'] : array(); $tok = preg_replace( '/[^a-z0-9]/i', '', (string) ( $e['token'] ?? '' ) );
		$lv = is_array( $out['listv'] ?? null ) ? $out['listv'] : array(); $out['listv'] = array( 'enabled' => ! isset( $lv['enabled'] ) || ! empty( $lv['enabled'] ), 'amount' => max( 0, round( (float) ( $lv['amount'] ?? 10 ), 2 ) ), 'until' => in_array( (string) ( $lv['until'] ?? '' ), array( 'early_end', 'public_end' ), true ) ? (string) $lv['until'] : 'early_end', 'prefix' => strtoupper( preg_replace( '/[^a-z0-9]/i', '', (string) ( $lv['prefix'] ?? 'BFV' ) ) ) ?: 'BFV', 'tag_revived' => mb_substr( trim( wp_strip_all_tags( (string) ( $lv['tag_revived'] ?? 'bf-revived' ) ) ), 0, 100 ), 'tag_vip' => mb_substr( trim( wp_strip_all_tags( (string) ( $lv['tag_vip'] ?? 'bf-vip' ) ) ), 0, 100 ), 'auto' => ! isset( $lv['auto'] ) || ! empty( $lv['auto'] ) );
		$lk = is_array( $out['lucky'] ?? null ) ? $out['lucky'] : array(); $ph = array_values( array_intersect( array_map( 'trim', explode( ',', (string) ( $lk['phases'] ?? 'early,public' ) ) ), array( 'early', 'public' ) ) );
		$out['lucky'] = array( 'enabled' => ! isset( $lk['enabled'] ) || ! empty( $lk['enabled'] ), 'product_id' => (int) ( $lk['product_id'] ?? 0 ), 'sku' => sanitize_text_field( (string) ( $lk['sku'] ?? '' ) ), 'dwell' => max( 5, min( 600, (int) ( $lk['dwell'] ?? 45 ) ) ), 'exit' => ! isset( $lk['exit'] ) || ! empty( $lk['exit'] ), 'phases' => implode( ',', $ph ?: array( 'early', 'public' ) ) );
		$out['early'] = array( 'token' => '' !== $tok ? $tok : self::default_token(), 'cookie_days' => max( 1, min( 60, (int) ( $e['cookie_days'] ?? 7 ) ) ) );
		$out['ship'] = array( 'free_from' => max( 0, (int) ( $out['ship']['free_from'] ?? 2 ) ), 'paid' => round( (float) ( $out['ship']['paid'] ?? 2.55 ), 2 ) );
		$out['checkout'] = array( 'card_gateway' => sanitize_key( (string) ( $out['checkout']['card_gateway'] ?? 'fkwcs_stripe' ) ), 'cod_gateway' => sanitize_key( (string) ( $out['checkout']['cod_gateway'] ?? 'cod' ) ), 'block_coupons' => ! isset( $out['checkout']['block_coupons'] ) || ! empty( $out['checkout']['block_coupons'] ) );
		$l = is_array( $out['leads'] ?? null ) ? $out['leads'] : array(); $out['leads'] = array( 'fka' => ! isset( $l['fka'] ) || ! empty( $l['fka'] ), 'consent_only' => ! empty( $l['consent_only'] ), 'untag_on_order' => ! empty( $l['untag_on_order'] ) ); /* 1.48.6: по подразбиране таговете остават */
		foreach ( array( 'list', 'tag_cold', 'tag_aff', 'tag_pause' ) as $lk ) { $out['leads'][ $lk ] = mb_substr( trim( wp_strip_all_tags( (string) ( $l[ $lk ] ?? $d['leads'][ $lk ] ) ) ), 0, 100 ); }
		$out['orders'] = array( 'fka_tag' => mb_substr( trim( wp_strip_all_tags( (string) ( $out['orders']['fka_tag'] ?? '' ) ) ), 0, 100 ) );
		$out['preview'] = array( 'password' => trim( wp_strip_all_tags( (string) ( $out['preview']['password'] ?? '' ) ) ) );
		$lg = trim( (string) ( $out['logo'] ?? '' ) ); $out['logo'] = ctype_digit( $lg ) ? (string) (int) $lg : esc_url_raw( $lg );
		$out['copy'] = Copy::diff( is_array( $out['copy'] ?? null ) ? $out['copy'] : array() );
		return $out;
	}
	/** 1.45.1: токенът по подразбиране е ЕДИН И СЪЩ при всяко четене (HMAC от salt-а на сайта), докато в store-а няма записан — иначе всяко normalize( defaults() )
	 *  даваше нов случаен токен и линкът от „Състояние“/шорткода се менеше при всяко зареждане, преди първото „Запази“. „Нов токен“ записва случаен. */
	public static function default_token() { $raw = function_exists( 'wp_salt' ) ? hash_hmac( 'sha256', 'ansa_bf_early_token', wp_salt( 'auth' ), true ) : random_bytes( 18 ); return substr( preg_replace( '/[^a-z0-9]/i', '', base64_encode( $raw ) ), 0, 20 ); }
	public static function new_token() { return substr( str_replace( array( '+', '/', '=' ), '', base64_encode( random_bytes( 18 ) ) ), 0, 20 ); }
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
	/* 1.48.6: еднократно при обновяване — „при поръчка таговете падат“ става изключено и в черновата, и в публикуваното, без нова версия (човекът: „Нека си останат“). */
	public static function keep_tags_once() {
		$key = 'ansa_promo_bf_keep_tags_v1'; if ( get_option( $key ) ) { return false; }
		$s = self::read_store(); $n = 0;
		foreach ( array( 'draft', 'published' ) as $k ) { if ( is_array( $s[ $k ] ) && ! empty( $s[ $k ]['leads']['untag_on_order'] ) ) { $s[ $k ]['leads']['untag_on_order'] = false; $n++; } }
		if ( $n ) { self::write_store( $s ); }
		update_option( $key, array( 'at' => time(), 'changed' => $n ), false ); return $n;
	}
	public static function publish() {
		$s = self::read_store();
		$src = is_array( $s['draft'] ) ? $s['draft'] : $s['published'];
		if ( ! is_array( $src ) ) { return false; }
		if ( is_array( $s['published'] ) ) { array_unshift( $s['history'], array( 'version' => (int) $s['version'], 'at' => (int) $s['published_at'], 'cfg' => $s['published'] ) ); $s['history'] = array_slice( $s['history'], 0, self::HISTORY ); }
		$s['published'] = self::normalize( $src ); $s['draft'] = null; $s['published_at'] = time(); $s['version'] = (int) $s['version'] + 1;
		self::write_store( $s );
		self::purge( $s['published'] );
		do_action( 'ansa_promo_bf_published', $s['published'] );
		return true;
	}
	public static function discard_draft() { $s = self::read_store(); $s['draft'] = null; self::write_store( $s ); }
	public static function restore( $version ) { $s = self::read_store(); foreach ( $s['history'] as $h ) { if ( (int) $h['version'] === (int) $version && is_array( $h['cfg'] ) ) { return self::save_draft( $h['cfg'] ); } } return null; }
	public static function export() { return wp_json_encode( self::get_draft(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); }
	public static function import( $json ) { $d = json_decode( (string) $json, true ); if ( ! is_array( $d ) ) { return null; } return self::save_draft( $d ); }

	/** Кешът на страницата (FlyingPress и другите, през ansa_promo_purge_url) + transient-ът на продуктите. */
	public static function purge( $cfg = null ) {
		$cfg = is_array( $cfg ) ? $cfg : self::get_published();
		delete_transient( self::runtime_key() ); delete_transient( self::runtime_key( self::version() - 1 ) ); delete_transient( self::runtime_key( self::version() + 1 ) );
		$url = Page::url( $cfg );
		if ( class_exists( '\\FlyingPress\\Purge' ) ) { try { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( $url ); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_urls' ) ) ) { \FlyingPress\Purge::purge_urls( array( $url ) ); } } catch ( \Throwable $e ) {} }
		do_action( 'ansa_promo_purge_url', $url, 0 );
	}
	public static function runtime_key( $v = null ) { return 'ansa_promo_bf_rt_' . (int) ( null === $v ? self::version() : $v ); }

	/* ── фази ── */
	private static function tz() { try { return new \DateTimeZone( wp_timezone_string() ); } catch ( \Exception $x ) { return new \DateTimeZone( 'UTC' ); } }
	/** „Y-m-d H:i“ по часа на сайта → timestamp (0 при грешка). */
	public static function ts( $s ) { $s = trim( (string) $s ); if ( '' === $s ) { return 0; } try { return ( new \DateTimeImmutable( $s, self::tz() ) )->getTimestamp(); } catch ( \Exception $x ) { return 0; } }
	public static function now() { return (int) apply_filters( 'ansa_promo_bf_now', time() ); }
	public static function phase_ts( $cfg ) { $o = array(); foreach ( (array) $cfg['phases'] as $k => $v ) { $o[ $k ] = self::ts( $v ); } return $o; }
	/** Фазата по датите (или force): prep | early | public | over. Празнина между early_end и public_start → prep (затворено). */
	public static function phase_now( $cfg, $ts = null ) {
		if ( '' !== (string) ( $cfg['force'] ?? '' ) ) { return (string) $cfg['force']; }
		$t = null === $ts ? self::now() : (int) $ts; $p = self::phase_ts( $cfg );
		if ( $p['public_end'] && $t >= $p['public_end'] ) { return 'over'; }
		if ( $p['public_start'] && $t >= $p['public_start'] ) { return 'public'; }
		if ( $p['early_start'] && $t >= $p['early_start'] && ( ! $p['early_end'] || $t < $p['early_end'] ) ) { return 'early'; }
		if ( $p['early_end'] && $t >= $p['early_end'] && $p['public_start'] && $t < $p['public_start'] ) { return 'pause'; } /* 1.48.7: дупка между двете фази */
		return 'prep';
	}
	/** Краят на текущата фаза (ts) — за таймера; 0 без край. */
	public static function phase_end( $cfg, $phase = null ) {
		$phase = $phase ?: self::phase_now( $cfg ); $p = self::phase_ts( $cfg );
		if ( 'early' === $phase ) { return (int) $p['early_end']; }
		if ( 'public' === $phase ) { return (int) $p['public_end']; }
		if ( 'prep' === $phase ) { return (int) $p['early_start']; }
		if ( 'pause' === $phase ) { return (int) $p['public_start']; }
		return 0;
	}
	public static function phase_label( $phase ) { return array( 'prep' => 'подготовка (записване)', 'early' => 'ранен достъп (48 ч.)', 'pause' => 'пауза (до публичния старт)', 'public' => 'публичен Black Friday', 'over' => 'приключил' )[ $phase ] ?? $phase; }
	/** Промото е „на живо“ за купуване: публикувано, включено и във фаза early/public. */
	public static function is_live( $cfg ) { return ! empty( $cfg['enabled'] ) && in_array( self::phase_now( $cfg ), array( 'early', 'public' ), true ); }
	public static function fmt_dt( $ts ) { return $ts ? wp_date( 'd.m.Y H:i', (int) $ts ) : '—'; }

	/* ── броячът „записани“ ── */
	public static function seats_now( $cfg, $ts = null ) {
		$s = $cfg['seats']; if ( empty( $s['enabled'] ) ) { return 0; }
		$from = self::ts( $s['from'] . ' 00:00' ); $t = null === $ts ? self::now() : (int) $ts;
		$d = max( 0, ( $t - $from ) / DAY_IN_SECONDS );
		$n = (int) floor( $s['max'] - ( $s['max'] - $s['start'] ) * exp( -$d / max( 0.5, (float) $s['k'] ) ) );
		return max( (int) $s['start'], min( (int) $s['max'] - 1, $n ) );
	}

	/* ── помощници ── */
	public static function product( $cfg, $key ) { foreach ( (array) $cfg['products'] as $p ) { if ( $p['key'] === $key ) { return $p; } } return null; }
	public static function early_url( $cfg = null ) { $cfg = $cfg ?: self::get_published(); return add_query_arg( 'ea', rawurlencode( (string) $cfg['early']['token'] ), Page::url( $cfg ) ); }
}
