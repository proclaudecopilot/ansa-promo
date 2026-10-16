<?php
/**
 * ansa™ Промо 1.20.0 — „любимият продукт“ на клиента за имейлите: {{ansa_promo_fav}} / {{ansa_promo_fav_url}}.
 *
 * Човекът: „Здравей, ИМЕ — Твоят любим продукт (Daily Greens / Sakura SkinTonik / YoniBiotic / MenoStop / Dihydroberberine /
 * Thyrozen) е вече наличен.“ Merge тагът намира най-купувания продукт на човека по всичките му поръчки и го изписва с
 * КРАТКОТО име от картата (не с името на WC продукта).
 *
 *  - Картата (🧰 Настройки → ⭐ Любим продукт): ред = „ID на WC продукта[, още ID…] = Кратко име“. Няколко ID-та на един ред
 *    се броят като един продукт (сетове/стари варианти). Вариациите се свеждат до родителя. ID може да е и SKU.
 *    Продукти извън картата НЕ участват (Омега, Ашваганда, ваучери…) — имейлът казва само имената, които човекът е избрал.
 *    Празна карта = продуктите от публикуваната чернова с имената от SHORT (или името от 📦 Продукти).
 *  - Метриката: опаковки (количество × опаковките във вариацията „3 кутии“ = 3; по подразбиране) · редове (количество) ·
 *    сума (платеното по реда) · поръчки (в колко поръчки е). Равенство → по-скоро купеният.
 *  - Кои поръчки: всички статуси освен „мъртвите“ (cancelled, refunded, failed, pending, trash, draft). Търси се по billing
 *    имейл (до `limit` поръчки) и по WP акаунта с този имейл.
 *  - Във FunnelKit Automations: имейлът е на контакта (broadcast / Tag Added / …), при WC event — на поръчката. Без поръчки
 *    или без продукт от картата → fallback="…" на тага (или полето „Текст, когато няма“).
 *  - Шорткодове: [ansa_promo_fav fallback="…"], [ansa_promo_fav_url]; с email="…" само за `manage_woocommerce`.
 *  - Кеш: 10 минути на имейл (transient) — един имейл с двата тага смята веднъж; тестерът в админа смята наново.
 *
 * @package AnsaPromo
 *  - 1.20.1: дневник на извикванията (⭐ Любим продукт → „Последни извиквания“, option ansa_promo_fav_log): откъде е имейлът, какви данни е
 *    дал FKA, резултатът; поръчката на event-а се брои дори pending; имейл и по contact id / из всички данни на FKA.
 *
 * @since 1.20.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Favorite {

	const OPT  = 'ansa_promo_favorite';
	/** Ключовете на merge таговете (Merge::TAGS → тук). */
	const TAGS = array( 'fav', 'fav_url' );
	/** Кратките имена по подразбиране по ключа на продукта в черновата (човекът ги даде, 16.10.2026). */
	const SHORT = array( 'greens' => 'Daily Greens', 'sakura' => 'Sakura SkinTonik', 'yoni' => 'YoniBiotic', 'meno' => 'MenoStop', 'berber' => 'Dihydroberberine', 'thyro' => 'Thyrozen' );
	/** Статуси, които не са покупка. */
	const SKIP = array( 'cancelled', 'refunded', 'failed', 'pending', 'trash', 'checkout-draft', 'draft', 'auto-draft' );
	const METRICS = array( 'packs' => 'опаковки (количество × опаковките във вариацията)', 'qty' => 'количество по редовете', 'spent' => 'платена сума', 'orders' => 'брой поръчки с продукта' );
	const TTL = 600;

	public static function defaults() { return array( 'map' => '', 'metric' => 'packs', 'skip' => implode( ', ', self::SKIP ), 'fallback' => '', 'limit' => 100 ); }

	/** Настройките (картата празна → по подразбиране от черновата, виж map_text_default). */
	public static function settings() {
		$s = array_merge( self::defaults(), array_filter( (array) get_option( self::OPT, array() ), function ( $v ) { return null !== $v; } ) );
		$s['metric'] = isset( self::METRICS[ $s['metric'] ] ) ? $s['metric'] : 'packs';
		$s['limit']  = max( 10, min( 500, (int) $s['limit'] ) );
		$s['custom'] = '' !== trim( (string) $s['map'] );
		if ( ! $s['custom'] ) { $s['map'] = self::map_text_default(); }
		return $s;
	}

	public static function save( $map, $metric, $skip, $fallback, $limit ) {
		update_option( self::OPT, array( 'map' => self::clean_text( $map ), 'metric' => isset( self::METRICS[ (string) $metric ] ) ? (string) $metric : 'packs', 'skip' => self::clean_text( $skip ), 'fallback' => trim( wp_strip_all_tags( (string) $fallback ) ), 'limit' => max( 10, min( 500, (int) $limit ) ) ), false );
		self::flush();
	}
	private static function clean_text( $t ) { return trim( implode( "\n", array_map( 'trim', explode( "\n", wp_strip_all_tags( str_replace( "\r", '', (string) $t ) ) ) ) ) ); }

	/** Картата по подразбиране: продуктите от публикуваната чернова (резерва — черновата) с кратките имена. */
	public static function map_text_default() {
		$lines = array(); $seen = array();
		foreach ( array( Config::get_published(), Config::get_draft() ) as $cfg ) {
			foreach ( (array) ( $cfg['products'] ?? array() ) as $p ) {
				if ( ! is_array( $p ) ) { continue; }
				$key = (string) ( $p['key'] ?? '' ); $pid = (int) ( $p['product_id'] ?? 0 );
				if ( '' !== (string) ( $p['sku'] ?? '' ) && function_exists( 'wc_get_product_id_by_sku' ) ) { $pid = (int) wc_get_product_id_by_sku( (string) $p['sku'] ) ?: $pid; }
				$pid = self::parent_id( $pid ); if ( $pid <= 0 || isset( $seen[ $pid ] ) ) { continue; }
				$name = self::SHORT[ $key ] ?? trim( (string) ( $p['name'] ?? '' ) ); if ( '' === $name ) { continue; }
				$seen[ $pid ] = true; $lines[] = $pid . ' = ' . $name;
			}
			if ( $lines ) { break; }
		}
		return implode( "\n", $lines );
	}

	/**
	 * Разбор на картата: „ID[, ID…] = Кратко име“ → [ { ids: [pid…], name } ]; ред без „=“ или без ID се пропуска (в $errors).
	 * ID: число (продукт или вариация → родител) или SKU. Коментар: ред, започващ с #.
	 */
	public static function parse_map( $text, &$errors = array() ) {
		$rows = array(); $errors = array();
		foreach ( explode( "\n", str_replace( "\r", '', (string) $text ) ) as $n => $line ) {
			$line = trim( $line ); if ( '' === $line || '#' === $line[0] ) { continue; }
			$pos = strpos( $line, '=' ); if ( false === $pos ) { $errors[] = 'ред ' . ( $n + 1 ) . ': няма „=“ (' . $line . ')'; continue; }
			$name = trim( substr( $line, $pos + 1 ) ); $left = trim( substr( $line, 0, $pos ) );
			if ( '' === $name ) { $errors[] = 'ред ' . ( $n + 1 ) . ': празно име'; continue; }
			if ( '' === $left ) { $errors[] = 'ред ' . ( $n + 1 ) . ': няма ID преди „=“ (' . $line . ')'; continue; }
			$ids = array();
			foreach ( preg_split( '/[\s,;|]+/', $left ) as $tok ) {
				$tok = trim( $tok ); if ( '' === $tok ) { continue; }
				$pid = is_numeric( $tok ) ? (int) $tok : ( function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $tok ) : 0 );
				$pid = self::parent_id( $pid );
				if ( $pid > 0 ) { $ids[] = $pid; } else { $errors[] = 'ред ' . ( $n + 1 ) . ': „' . $tok . '“ не е продукт'; }
			}
			$ids = array_values( array_unique( $ids ) ); if ( ! $ids ) { continue; }
			$rows[] = array( 'ids' => $ids, 'name' => $name );
		}
		return $rows;
	}

	/** pid на родителя (вариация → parent; продукт → същият; непознат → 0 без WC или самото число, ако WC липсва). */
	public static function parent_id( $pid ) {
		$pid = (int) $pid; if ( $pid <= 0 ) { return 0; }
		if ( ! function_exists( 'wc_get_product' ) ) { return $pid; }
		$p = wc_get_product( $pid ); if ( ! $p ) { return 0; }
		return $p->is_type( 'variation' ) ? (int) $p->get_parent_id() : (int) $p->get_id();
	}

	/** Картата като pid → [ name, order ] (order = редът в картата, за равенство). */
	public static function map( $s = null ) {
		static $cache = null; $own = null === $s; if ( $own && null !== $cache ) { return $cache; }
		$s = $s ?: self::settings(); $out = array();
		$first = array(); /* редът на първата поява на името — за равенство */
		foreach ( self::parse_map( $s['map'] ) as $i => $row ) { if ( ! isset( $first[ $row['name'] ] ) ) { $first[ $row['name'] ] = $i; } foreach ( $row['ids'] as $pid ) { if ( ! isset( $out[ $pid ] ) ) { $out[ $pid ] = array( 'name' => $row['name'], 'order' => $first[ $row['name'] ] ); } } }
		if ( $own ) { $cache = $out; }
		return $out;
	}

	/** Статусите, които не броят (без „wc-“). */
	public static function skip_statuses( $s = null ) {
		$s = $s ?: self::settings();
		$out = array(); foreach ( preg_split( '/[\s,;]+/', (string) $s['skip'] ) as $st ) { $st = strtolower( trim( $st ) ); if ( '' !== $st ) { $out[] = 0 === strpos( $st, 'wc-' ) ? substr( $st, 3 ) : $st; } }
		return array_values( array_unique( $out ) );
	}
	/** Статусите, които броят (с „wc-“, за wc_get_orders). */
	public static function count_statuses( $s = null ) {
		$skip = self::skip_statuses( $s ); $all = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array( 'wc-processing', 'wc-completed' );
		$out = array(); foreach ( $all as $st ) { $k = 0 === strpos( $st, 'wc-' ) ? substr( $st, 3 ) : $st; if ( ! in_array( $k, $skip, true ) ) { $out[] = $st; } }
		return $out;
	}

	/** Опаковките в един ред: вариацията „3 кутии (2+1 ПОДАРЪК)“ → 3; иначе SKU „BRBR;BRBR;BRBR“ → 3; иначе 1. */
	public static function packs_of( $item ) {
		$vid = (int) $item->get_variation_id(); if ( $vid <= 0 ) { return 1; }
		static $cache = array(); if ( isset( $cache[ $vid ] ) ) { return $cache[ $vid ]; }
		$n = 0;
		$v = function_exists( 'wc_get_product' ) ? wc_get_product( $vid ) : null;
		if ( $v ) {
			foreach ( (array) $v->get_variation_attributes() as $av ) { if ( preg_match( '/^\s*(\d{1,2})\b/u', html_entity_decode( rawurldecode( (string) $av ) ), $m ) ) { $n = (int) $m[1]; break; } }
			if ( ! $n && preg_match( '/(\d{1,2})\s*(кути|бро|опаков|pack|box)/iu', html_entity_decode( (string) $v->get_name() ), $m2 ) ) { $n = (int) $m2[1]; }
			if ( ! $n ) { $sku = trim( (string) $v->get_sku() ); if ( false !== strpos( $sku, ';' ) ) { $n = count( array_filter( array_map( 'trim', explode( ';', $sku ) ) ) ); } }
		}
		if ( ! $n ) { foreach ( (array) $item->get_meta_data() as $md ) { $d = $md->get_data(); if ( preg_match( '/^\s*(\d{1,2})\s*(кути|бро|опаков)/iu', (string) ( $d['value'] ?? '' ), $m3 ) ) { $n = (int) $m3[1]; break; } } }
		return $cache[ $vid ] = max( 1, $n );
	}

	/** Поръчките на човека: по billing имейл + по WP акаунта (customer_id), без „мъртвите“ статуси, без дубли; най-новите първи. */
	public static function orders_for( $email, $user_id = 0, $s = null ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return array(); }
		$s = $s ?: self::settings(); $email = strtolower( trim( (string) $email ) );
		$base = array( 'limit' => (int) $s['limit'], 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'status' => self::count_statuses( $s ), 'type' => 'shop_order' );
		$out = array(); $seen = array();
		$add = function ( $list ) use ( &$out, &$seen ) { foreach ( (array) $list as $o ) { if ( ! is_object( $o ) || ! method_exists( $o, 'get_id' ) || isset( $seen[ $o->get_id() ] ) ) { continue; } $seen[ $o->get_id() ] = true; $out[] = $o; } };
		if ( '' !== $email && is_email( $email ) ) { $add( wc_get_orders( array_merge( $base, array( 'billing_email' => $email ) ) ) ); }
		$uid = (int) $user_id; if ( $uid <= 0 && '' !== $email && function_exists( 'email_exists' ) ) { $uid = (int) email_exists( $email ); }
		if ( $uid > 0 ) { $add( wc_get_orders( array_merge( $base, array( 'customer_id' => $uid ) ) ) ); }
		return $out;
	}

	/**
	 * Най-купуваният продукт от картата за този имейл.
	 * → null (няма поръчки / нищо от картата) или { pid, name, url, score, metric, orders (с продукта), all_orders, last (ts), breakdown: name → score }.
	 */
	public static function compute( $email, $user_id = 0, $fresh = false, $extra_order = null ) {
		$email = strtolower( trim( (string) $email ) ); $uid = (int) $user_id;
		if ( '' === $email && $uid <= 0 && ! $extra_order ) { return null; }
		/* 1.20.1: поръчката на самия event (Order Created и т.н.) се брои, дори да е още pending — човекът току-що я е направил */
		$xo = ( is_object( $extra_order ) && method_exists( $extra_order, 'get_items' ) && ! in_array( $extra_order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) ? $extra_order : null;
		$ck = 'ansa_promo_fav_' . md5( $email . '|' . $uid . '|' . ( $xo ? $xo->get_id() : 0 ) . '|' . md5( wp_json_encode( get_option( self::OPT, array() ) ) ) );
		static $mem = array();
		if ( ! $fresh ) { if ( array_key_exists( $ck, $mem ) ) { return $mem[ $ck ]; } $c = get_transient( $ck ); if ( is_array( $c ) && array_key_exists( 'r', $c ) ) { return $mem[ $ck ] = $c['r']; } }
		$s = self::settings(); $map = self::map( $s ); $r = null;
		if ( $map ) {
			$score = array(); $last = array(); $in = array(); $pidof = array(); $pidts = array(); $orders = self::orders_for( $email, $uid, $s );
			if ( $xo ) { $has = false; foreach ( $orders as $o ) { if ( $o->get_id() === $xo->get_id() ) { $has = true; break; } } if ( ! $has ) { array_unshift( $orders, $xo ); } }
			$n_all = count( $orders );
			foreach ( $orders as $o ) {
				$ts = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0; $seen_here = array();
				foreach ( $o->get_items( 'line_item' ) as $it ) {
					$pid = (int) $it->get_product_id(); if ( $pid <= 0 && $it->get_variation_id() ) { $pid = self::parent_id( (int) $it->get_variation_id() ); }
					if ( ! isset( $map[ $pid ] ) ) { continue; }
					$name = $map[ $pid ]['name']; $q = (float) $it->get_quantity(); if ( $q <= 0 ) { continue; }
					switch ( $s['metric'] ) {
						case 'qty':    $v = $q; break;
						case 'spent':  $v = (float) $it->get_total(); break;
						case 'orders': $v = isset( $seen_here[ $name ] ) ? 0 : 1; break;
						default:       $v = $q * self::packs_of( $it );
					}
					$seen_here[ $name ] = true;
					$score[ $name ] = ( $score[ $name ] ?? 0 ) + $v; $last[ $name ] = max( $last[ $name ] ?? 0, $ts );
					if ( ! isset( $in[ $name ][ $o->get_id() ] ) ) { $in[ $name ][ $o->get_id() ] = true; }
					if ( ! isset( $pidof[ $name ] ) || $ts >= ( $pidts[ $name ] ?? -1 ) ) { $pidof[ $name ] = $pid; $pidts[ $name ] = $ts; }
				}
			}
			if ( $score ) {
				$order_in_map = array(); foreach ( $map as $pid => $m ) { if ( ! isset( $order_in_map[ $m['name'] ] ) ) { $order_in_map[ $m['name'] ] = $m['order']; } }
				uksort( $score, function ( $a, $b ) use ( $score, $last, $order_in_map ) {
					if ( $score[ $a ] != $score[ $b ] ) { return $score[ $b ] <=> $score[ $a ]; }
					if ( ( $last[ $a ] ?? 0 ) !== ( $last[ $b ] ?? 0 ) ) { return ( $last[ $b ] ?? 0 ) <=> ( $last[ $a ] ?? 0 ); }
					return ( $order_in_map[ $a ] ?? 999 ) <=> ( $order_in_map[ $b ] ?? 999 );
				} );
				$name = (string) array_key_first( $score ); $pid = (int) $pidof[ $name ];
				$r = array( 'pid' => $pid, 'name' => $name, 'url' => function_exists( 'get_permalink' ) ? (string) get_permalink( $pid ) : '', 'score' => round( $score[ $name ], 2 ), 'metric' => $s['metric'], 'orders' => count( $in[ $name ] ), 'all_orders' => $n_all, 'last' => (int) $last[ $name ], 'breakdown' => array_map( function ( $v ) { return round( $v, 2 ); }, $score ) );
			} elseif ( $n_all ) { $r = null; }
		}
		$r = apply_filters( 'ansa_promo_favorite', $r, $email, $uid );
		set_transient( $ck, array( 'r' => $r ), self::TTL ); $mem[ $ck ] = $r;
		return $r;
	}

	/** Стойността за тага: 'fav' → краткото име, 'fav_url' → линкът; '' когато няма (fallback-ът е в Merge::shortcode). */
	public static function value( $key, $email, $user_id = 0, $extra_order = null ) {
		$r = self::compute( $email, $user_id, false, $extra_order ); if ( ! $r ) { return 'fav' === $key ? (string) self::settings()['fallback'] : ''; }
		return 'fav_url' === $key ? (string) $r['url'] : (string) $r['name'];
	}

	/** Примерните стойности за прегледа във FKA: първият ред от картата. */
	public static function dummy( $key ) {
		$map = self::map(); $pid = $map ? (int) array_key_first( $map ) : 0;
		if ( 'fav_url' === $key ) { return $pid && function_exists( 'get_permalink' ) ? (string) get_permalink( $pid ) : home_url( '/' ); }
		return $pid ? (string) $map[ $pid ]['name'] : 'Daily Greens';
	}

	/* ── 1.20.1: дневник на извикванията (option ansa_promo_fav_log, последните 30) — „защо получи fallback“ ── */
	const LOG = 'ansa_promo_fav_log'; const LOG_N = 30;
	public static function log( $row ) {
		$rows = get_option( self::LOG, array() ); $rows = is_array( $rows ) ? $rows : array();
		$e = (string) ( $row['email'] ?? '' ); $row['email'] = '' === $e ? '' : preg_replace( '/^(.)[^@]*(@.*)$/u', '$1***$2', $e ); /* маскиран — в лога не стоят имейли на клиенти */
		$row['at'] = function_exists( 'current_time' ) ? current_time( 'mysql' ) : date( 'Y-m-d H:i:s' ); $row['ctx'] = ( defined( 'DOING_CRON' ) && DOING_CRON ? 'cron' : ( defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest' : ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ? 'ajax' : ( function_exists( 'is_admin' ) && is_admin() ? 'admin' : 'web' ) ) ) );
		array_unshift( $rows, $row ); $rows = array_slice( $rows, 0, self::LOG_N );
		update_option( self::LOG, $rows, false );
	}
	public static function log_rows() { $r = get_option( self::LOG, array() ); return is_array( $r ) ? $r : array(); }
	public static function log_clear() { delete_option( self::LOG ); }

	/** Чисти кеша на сметките (при запис на настройките). */
	public static function flush() {
		global $wpdb; if ( ! isset( $wpdb->options ) ) { return; }
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ansa_promo_fav_%' OR option_name LIKE '_transient_timeout_ansa_promo_fav_%'" );
		if ( function_exists( 'wp_cache_flush_group' ) ) { wp_cache_flush_group( 'transient' ); }
	}

	/** За Doctor / Probe: „6 продукта · Daily Greens, Sakura SkinTonik … · метрика опаковки · без: cancelled, …“. */
	public static function status() {
		$s = self::settings(); $err = array(); $rows = self::parse_map( $s['map'], $err ); $names = array_values( array_unique( array_map( function ( $r ) { return $r['name']; }, $rows ) ) );
		$txt = count( $rows ) . ' продукта' . ( $s['custom'] ? '' : ' (по подразбиране от черновата)' ) . ( $names ? ': ' . implode( ', ', array_slice( $names, 0, 8 ) ) . ( count( $names ) > 8 ? '…' : '' ) : '' ) . ' · метрика: ' . ( self::METRICS[ $s['metric'] ] ?? $s['metric'] ) . ' · не броят: ' . implode( ', ', self::skip_statuses( $s ) ) . ( '' !== $s['fallback'] ? ' · без любим: „' . $s['fallback'] . '“' : '' ) . ( $err ? ' · ⚠ ' . implode( '; ', array_slice( $err, 0, 4 ) ) : '' );
		return array( $txt, $rows ? ( $err ? 'warn' : 'ok' ) : 'warn' );
	}
}
