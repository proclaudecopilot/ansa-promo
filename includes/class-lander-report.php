<?php
/**
 * ansa™ Промо 1.42.0 — 🎯 „Резултати на лендъра“ (Insights 4; човекът: „Трябва да се пренапише Insights… основният отчет трябва да е
 * „Резултати на лендъра“, с новия лендър избран по подразбиране. Всички числа надолу трябва да се отнасят до хората, които действително са
 * отворили него… Брой сесии в лендъра · откъде са дошли (Фейсбук реклама — коя / Гугъл / от попъп в сайта — от кой продукт / имейл) · колко от
 * тях са конвертирали“).
 *
 * Лендърите (по адрес, не по букви A/B): new = ansa.bg/sezonut-na-ansa (новият дизайн, основен; томболата НЕ е в неговата фуния) ·
 * old = ansa.bg/sezonut-na-ansa-b (старият с кутиите; томболата е отделен отчет след покупката) · sx = ansa.bg/kozmetichen-set-promo (адверториалът).
 *
 * Правилата (без двойно броене):
 *  - Сесия в лендъра = сесия с отчетено отваряне на ТОЧНО този адрес (събитие view с k = лендърът) в периода; презареждането не прави ново
 *    отваряне (едно view на лендър на сесия). Продуктова страница / Shrine картата / подстраниците (/info, /kozmetika) НЕ са сесии в лендъра.
 *  - Денят на сесията = първото отваряне на лендъра. „Как конвертираха сесиите от периода“ (кохорта) и „колко поръчки са създадени в периода“
 *    (по датата на поръчката) са две различни числа и се показват отделно.
 *  - Всяко действие (добавен продукт, checkout, поръчка) се приписва на ПОСЛЕДНИЯ отворен лендър преди него. Поръчка → лендърът, отворен последно
 *    преди започването на checkout. Сесия, минала през двата лендъра, присъства и в двата отчета; „и двата“ се показва отделно.
 *  - Сесии с поръчка ≠ брой поръчки (една сесия може да поръча два пъти; created.k = номерът на поръчката).
 *  - Стойността е с ДДС и доставка, както е в WooCommerce; ДДС и доставката са показани отделно.
 *  - Поръчки без проследима връзка (без нито едно отваряне на лендър) се показват отделно, без да им се измислят посещения.
 *  - Сравнението е със същия часов отрязък: период до днес → предишният период до същия час.
 *  - Произходът е в два режима (Source::of): „Вход към лендъра“ (какво го отвори: реклама / попъп от коя страница / имейл / вътрешен линк) и
 *    „Външен източник“ (какво доведе човека на сайта). Два изгледа към едни и същи сесии — не се събират.
 *
 * Екранът води през пет въпроса: Какъв е резултатът? → Какво се промени? → Къде се прекъсва покупката? → При кои посетители? → Какво проверяваме след това?
 *
 * @package AnsaPromo
 * @since 1.42.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Lander_Report {

	const LANDERS = array(
		/* 1.43.1: лендърът е АДРЕСЪТ — на главния до 28.10 13:43 беше старият дизайн (кутиите), на B дотогава — новият; смяната е в option ansa_promo_lander_swap */
		'new' => array( 'label' => 'Основен — /sezonut-na-ansa', 'short' => 'основен', 'path' => '/sezonut-na-ansa', 'variant' => 'flow', 'tombola' => false, 'funnel' => array( 'view', 'item', 'add', 'created' ), 'design' => 'новият дизайн (потокът без кутии)' ),
		'old' => array( 'label' => 'Стар — /sezonut-na-ansa-b', 'short' => 'стар', 'path' => '/sezonut-na-ansa-b', 'variant' => '', 'tombola' => true, 'funnel' => array( 'view', 'box', 'item', 'full', 'add', 'created' ), 'design' => 'старият дизайн (кутиите) с томболата' ),
		'sx'  => array( 'label' => 'Сет — /kozmetichen-set-promo', 'short' => 'сет', 'path' => '/kozmetichen-set-promo', 'variant' => '', 'tombola' => false, 'funnel' => array( 'view', 'add', 'created' ), 'design' => 'адверториалът за козметичния сет' ),
	);
	const DEFAULT_LANDER = 'new';
	const STEPS = array( 'view' => 'Отворили лендъра', 'box' => 'Избрали кутия', 'item' => 'Добавили продукт', 'full' => 'Напълнили кутията', 'add' => 'Отворили checkout', 'created' => 'Създали поръчка' );
	const STEP_SESS = array( 'view' => 'view', 'box' => 'box', 'item' => 'box', 'full' => 'full', 'add' => 'add', 'created' => 'created' ); /* стъпка → sessions.step за „кои →“ */
	const MIN_ROWS = 30;
	const ZERO = array( 'n' => 0, 'added' => 0, 'add' => 0, 'created' => 0, 'orders' => 0, 'value' => 0.0 );

	public static function lander_key( $v ) { $v = sanitize_key( (string) $v ); return isset( self::LANDERS[ $v ] ) ? $v : self::DEFAULT_LANDER; }
	public static function current() { return self::lander_key( $_GET['lander'] ?? self::DEFAULT_LANDER ); }
	public static function mode() { return 'ext' === ( $_GET['mode'] ?? '' ) ? 'ext' : 'entry'; }
	public static function url( $args = array() ) { return Insights::url( $args ); }

	/* ── данни ── */

	/** Краят на периода като datetime: до днес → до сегашния час (еднакъв часови отрязък при сравнение). */
	public static function cutoff( $rg ) { return $rg['to'] === current_time( 'Y-m-d' ) ? current_time( 'H:i:s' ) : ''; }

	/**
	 * Всичко за един лендър и период. $o: internal (bool — включи вътрешния трафик), cutoff ('H:i:s' — краят на последния ден).
	 */
	public static function build( $lander, $rg, $o = array() ) {
		global $wpdb; $ts = Schema::table( 'sessions' ); $te = Schema::table( 'events' );
		$lander = self::lander_key( $lander ); $L = self::LANDERS[ $lander ]; $cut = (string) ( $o['cutoff'] ?? '' ); $cut = preg_match( '/^\d{2}:\d{2}:\d{2}$/', $cut ) ? $cut : '23:59:59';
		$from = $rg['from'] . ' 00:00:00'; $to = $rg['to'] . ' ' . $cut; $home = (string) ( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ?: 'ansa.bg' );
		$out = array( 'lander' => $lander, 'rg' => $rg, 'cutoff' => $cut, 'from' => $from, 'to' => $to, 'sessions' => 0, 'visitors' => 0, 'both' => 0, 'internal_n' => 0, 'legacy_entry' => 0,
			'steps' => array_fill_keys( array_keys( self::STEPS ), 0 ), 'created_sessions' => 0, 'orders_n' => 0, 'value' => 0.0, 'tax' => 0.0, 'ship' => 0.0, 'aov' => 0.0,
			'orders' => array(), 'status' => array( 'delivered' => array( 'n' => 0, 'total' => 0.0 ), 'open' => array( 'n' => 0, 'total' => 0.0 ), 'lost' => array( 'n' => 0, 'total' => 0.0 ) ),
			'tree' => array( 'entry' => array(), 'ext' => array() ), 'by_day' => array(), 'by_dev' => array(), 'by_product' => array(), 'by_hour' => array(),
			'buy' => array( 'packs' => array(), 'same' => 0, 'mixed' => 0, 'cosm_yes' => 0, 'cosm_no' => 0, 'book' => 0, 'reach5' => 0, 'n' => 0 ), 'tombola' => array( 'orders' => 0, 'paid' => 0, 'activated' => 0, 'tickets' => 0 ),
			'period_orders' => array( 'n' => 0, 'total' => 0.0, 'untracked_n' => 0, 'untracked_total' => 0.0 ), 'wc' => function_exists( 'wc_get_order' ) );
		/* 1) кохортата: сесиите с отваряне на лендъра в периода (първото отваряне = денят на сесията) */
		/* 1.43.1: връзката с „📊 Подробно“ — всички проследени сесии за периода (както ги брои Подробно, по начало на сесията) и колко от тях са отворили някой лендър */
		$wi = empty( $o['internal'] ) ? ' AND internal = 0' : ''; $tot = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(landers <> '') AS l FROM $ts WHERE started_at >= %s AND started_at <= %s AND sid NOT LIKE %s AND sid NOT LIKE %s$wi", $from, $to, 'order-%', 'phone-%' ), ARRAY_A );
		$out['all_sessions'] = (int) ( $tot['n'] ?? 0 ); $out['any_lander'] = (int) ( $tot['l'] ?? 0 ); $out['swap'] = (string) get_option( 'ansa_promo_lander_swap', '' );
		$cohort = array(); foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT sid, MIN(at) AS first_at FROM $te WHERE ev = 'view' AND k = %s AND at >= %s AND at <= %s GROUP BY sid", $lander, $from, $to ), ARRAY_A ) as $r ) { $cohort[ $r['sid'] ] = (string) $r['first_at']; }
		if ( ! $cohort ) { self::period_orders( $out, $lander, $from, $to, array() ); return $out; }
		/* 2) сесиите и 3) събитията им */
		$sess = array(); $evs = array();
		foreach ( array_chunk( array_keys( $cohort ), 400 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT sid, src, med, camp, ref, device, vid, ip, internal, step, order_id, items, box, utm, landers, entry_qs, entry_ref, touch_qs, touch_ref, started_at FROM $ts WHERE sid IN ($in)", $chunk ), ARRAY_A ) as $r ) { $sess[ $r['sid'] ] = $r; }
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT sid, ev, k, at, n, box, variant FROM $te WHERE sid IN ($in) ORDER BY at ASC, id ASC", $chunk ), ARRAY_A ) as $r ) { $evs[ $r['sid'] ][] = $r; }
		}
		$vis = array(); $order_ids = array(); $per = array(); /* per sid: steps, orders, src */
		foreach ( $cohort as $sid => $first_at ) {
			$r = $sess[ $sid ] ?? null; if ( ! $r ) { continue; }
			if ( ! empty( $r['internal'] ) ) { $out['internal_n']++; if ( empty( $o['internal'] ) ) { continue; } }
			$st = array_fill_keys( array_keys( self::STEPS ), false ); $st['view'] = true; $oids = array(); $cur = ''; $maxn = 0;
			foreach ( (array) ( $evs[ $sid ] ?? array() ) as $e ) {
				if ( 'view' === $e['ev'] ) { if ( isset( self::LANDERS[ $e['k'] ] ) ) { $cur = $e['k']; } continue; }
				if ( '' === $cur ) { $cur = 'flow' === (string) $e['variant'] ? 'new' : ( 'sx' === $lander ? '' : 'old' ); } /* събитие преди първото view (кеш/състезание на бийкъните) — по варианта */
				if ( $cur !== $lander ) { continue; }
				$ev = $e['ev'];
				if ( 'item' === $ev || 'sh_add' === $ev ) { $st['item'] = true; }
				if ( 'box' === $ev ) { $st['box'] = true; }
				if ( 'full' === $ev ) { $st['full'] = true; }
				if ( 'add' === $ev || 'order' === $ev ) { $st['add'] = true; }
				if ( 'created' === $ev ) { $st['created'] = true; $oid = (int) $e['k']; if ( ! $oid ) { $oid = (int) $r['order_id']; } if ( $oid ) { $oids[ $oid ] = (string) $e['at']; } }
				if ( in_array( $ev, array( 'item', 'full', 'order', 'add' ), true ) ) { $maxn = max( $maxn, (int) $e['n'] ); }
			}
			if ( 'new' === $lander && $st['box'] && ! $st['item'] ) { $st['item'] = true; } /* в новия дизайн „box“ идва само след сложен продукт */
			if ( $st['created'] && ! $st['add'] ) { $st['add'] = true; } if ( $st['add'] && 'old' === $lander && ! $st['full'] ) { $st['full'] = true; }
			if ( ( $st['full'] || $st['add'] ) && ! $st['item'] && 'sx' !== $lander ) { $st['item'] = true; } if ( 'old' === $lander && $st['item'] && ! $st['box'] ) { $st['box'] = true; }
			$out['sessions']++; $vk = '' !== (string) $r['vid'] ? 'v:' . $r['vid'] : ( '' !== (string) $r['ip'] ? 'ip:' . $r['ip'] : 'sid:' . $sid ); $vis[ $vk ] = 1;
			$ls = array_filter( explode( ',', (string) $r['landers'] ) ); if ( count( $ls ) > 1 ) { $out['both']++; }
			foreach ( $st as $k => $v ) { if ( $v ) { $out['steps'][ $k ]++; } }
			if ( $oids ) { $out['created_sessions']++; foreach ( $oids as $oid => $at ) { $order_ids[ $oid ] = $sid; } }
			$src = Source::of( $r, $home ); if ( ! empty( $src['entry']['legacy'] ) ) { $out['legacy_entry']++; }
			$per[ $sid ] = array( 'st' => $st, 'oids' => array_keys( $oids ), 'src' => $src, 'day' => substr( $first_at, 0, 10 ), 'hour' => sprintf( '%02d:00', (int) substr( $first_at, 11, 2 ) ), 'dev' => $r['device'] ?: '—', 'product' => $src['entry']['product'] ?: ( (string) $r['utm'] ?: '' ), 'items' => $r['items'] ? json_decode( (string) $r['items'], true ) : null, 'maxn' => $maxn );
		}
		$out['visitors'] = count( $vis );
		/* 4) поръчките (WooCommerce) */
		$cfg = Config::get_published(); $tot = array();
		foreach ( $order_ids as $oid => $sid ) { $od = self::order_row( $oid, $cfg ); if ( ! $od ) { continue; } $od['sid'] = $sid; $out['orders'][ $oid ] = $od; $tot[ $oid ] = $od; }
		foreach ( $out['orders'] as $oid => $od ) {
			$out['orders_n']++; $out['value'] += $od['total']; $out['tax'] += $od['tax']; $out['ship'] += $od['ship']; $out['status'][ $od['grp'] ]['n']++; $out['status'][ $od['grp'] ]['total'] += $od['total'];
			/* какво купуват */
			$b = &$out['buy']; $b['n']++; $pk = (int) $od['count']; $key = $pk >= 5 ? '5+' : (string) max( 1, $pk ); $b['packs'][ $key ] = ( $b['packs'][ $key ] ?? 0 ) + 1; if ( $pk >= 5 ) { $b['reach5']++; }
			if ( 'reserved' === $od['cosm'] ) { $b['cosm_yes']++; } elseif ( '' !== $od['cosm'] ) { $b['cosm_no']++; } if ( $od['book'] ) { $b['book']++; }
			$it = $per[ $od['sid'] ]['items'] ?? null; if ( is_array( $it ) && $it ) { if ( count( array_unique( array_map( 'strval', $it ) ) ) > 1 ) { $b['mixed']++; } else { $b['same']++; } } unset( $b );
			/* томболата: билет (paid) · активиран */
			$out['tombola']['orders']++; if ( $od['ticket'] ) { if ( 'void' !== $od['ticket']['status'] ) { $out['tombola']['paid']++; $out['tombola']['tickets'] += (int) $od['ticket']['tickets']; } if ( 'activated' === $od['ticket']['status'] ) { $out['tombola']['activated']++; } }
		}
		$out['aov'] = $out['orders_n'] ? $out['value'] / $out['orders_n'] : 0.0;
		/* 5) разбивките: произход (две дървета), ден, устройство, продукт, час */
		$add = function ( &$m, $p ) use ( $tot ) { $m['n']++; if ( $p['st']['item'] ) { $m['added']++; } if ( $p['st']['add'] ) { $m['add']++; } if ( $p['oids'] ) { $m['created']++; } foreach ( $p['oids'] as $oid ) { if ( isset( $tot[ $oid ] ) ) { $m['orders']++; $m['value'] += $tot[ $oid ]['total']; } } };
		$names = self::product_names();
		foreach ( $per as $sid => $p ) {
			foreach ( array( 'entry', 'ext' ) as $mode ) {
				$c = $p['src'][ $mode ]; $k = $c['k']; $path = $c['path'];
				if ( ! isset( $out['tree'][ $mode ][ $k ] ) ) { $out['tree'][ $mode ][ $k ] = array( 'l' => Source::label( $k ), 'm' => self::ZERO, 'ch' => array() ); }
				$node = &$out['tree'][ $mode ][ $k ]; $add( $node['m'], $p );
				foreach ( array_slice( $path, 0, 3 ) as $lvl ) { if ( ! isset( $node['ch'][ $lvl ] ) ) { $node['ch'][ $lvl ] = array( 'm' => self::ZERO, 'ch' => array() ); } $node = &$node['ch'][ $lvl ]; $add( $node['m'], $p ); }
				unset( $node );
			}
			foreach ( array( 'by_day' => $p['day'], 'by_dev' => $p['dev'], 'by_hour' => $p['hour'], 'by_product' => '' !== $p['product'] ? ( $names[ $p['product'] ] ?? $p['product'] ) : 'без продукт в адреса' ) as $g => $key ) { if ( ! isset( $out[ $g ][ $key ] ) ) { $out[ $g ][ $key ] = self::ZERO; } $add( $out[ $g ][ $key ], $p ); }
		}
		foreach ( array( 'entry', 'ext' ) as $mode ) { self::sort_tree( $out['tree'][ $mode ] ); }
		ksort( $out['by_day'] ); ksort( $out['by_hour'] ); uasort( $out['by_dev'], function ( $a, $b ) { return $b['n'] <=> $a['n']; } ); uasort( $out['by_product'], function ( $a, $b ) { return $b['n'] <=> $a['n']; } );
		ksort( $out['buy']['packs'], SORT_NATURAL );
		/* 6) поръчките, СЪЗДАДЕНИ в периода (по датата на поръчката) — отделна справка */
		self::period_orders( $out, $lander, $from, $to, $order_ids );
		return $out;
	}
	private static function sort_tree( &$nodes ) { uasort( $nodes, function ( $a, $b ) { return $b['m']['n'] <=> $a['m']['n']; } ); foreach ( $nodes as &$n ) { if ( ! empty( $n['ch'] ) ) { self::sort_tree( $n['ch'] ); } } unset( $n ); }

	/** Поръчка от WooCommerce като ред: total/tax/ship/status/grp/count/cosm/book/box/variant/ticket. null без поръчка. */
	public static function order_row( $oid, $cfg = null ) {
		if ( ! function_exists( 'wc_get_order' ) ) { return null; }
		$ord = wc_get_order( (int) $oid ); if ( ! $ord ) { return null; }
		$m = (array) $ord->get_meta( '_ansa_promo' ); if ( ! empty( $m['draft'] ) ) { return null; }
		$st = (string) $ord->get_status(); $grp = Tombola::delivered( $ord, $cfg ) ? 'delivered' : ( in_array( $st, Insights::LOST_STATUSES, true ) ? 'lost' : 'open' );
		$t = method_exists( $ord, 'get_total_tax' ) ? (float) $ord->get_total_tax() : 0.0; $sh = method_exists( $ord, 'get_shipping_total' ) ? (float) $ord->get_shipping_total() : 0.0;
		$dc = method_exists( $ord, 'get_date_created' ) ? $ord->get_date_created() : null;
		return array( 'id' => (int) $oid, 'total' => (float) $ord->get_total(), 'tax' => $t, 'ship' => $sh, 'status' => $st, 'grp' => $grp, 'count' => (int) ( $m['count'] ?? $m['packs'] ?? 0 ), 'cosm' => (string) ( $m['cosm']['status'] ?? '' ), 'book' => ! empty( $m['book']['product_id'] ), 'box' => (string) ( $m['box'] ?? '' ), 'variant' => (string) ( $m['variant'] ?? '' ), 'at' => $dc ? $dc->date( 'Y-m-d H:i:s' ) : '', 'ticket' => class_exists( __NAMESPACE__ . '\\Tickets' ) ? Tickets::for_order( (int) $oid ) : null );
	}

	/** Поръчките, създадени в периода и приписани на лендъра (последното отваряне преди поръчката); без нито едно отваряне на лендър → „без проследима връзка“ (по варианта на поръчката, само new/old). */
	private static function period_orders( &$out, $lander, $from, $to, $cohort_orders ) {
		global $wpdb; $te = Schema::table( 'events' ); $ts = Schema::table( 'sessions' ); $cfg = Config::get_published();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT e.sid, e.k, e.at, e.variant, s.order_id, s.internal FROM $te e INNER JOIN $ts s ON s.sid = e.sid WHERE e.ev = 'created' AND e.at >= %s AND e.at <= %s", $from, $to ), ARRAY_A );
		if ( ! $rows ) { return; }
		$sids = array_unique( array_map( function ( $r ) { return $r['sid']; }, $rows ) ); $views = array();
		foreach ( array_chunk( $sids, 400 ) as $chunk ) { $in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) ); foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT sid, k, at FROM $te WHERE ev = 'view' AND sid IN ($in) ORDER BY at ASC", $chunk ), ARRAY_A ) as $v ) { if ( isset( self::LANDERS[ $v['k'] ] ) ) { $views[ $v['sid'] ][] = $v; } } }
		$seen = array();
		foreach ( $rows as $r ) {
			if ( ! empty( $r['internal'] ) ) { continue; }
			$oid = (int) $r['k'] ?: (int) $r['order_id']; if ( ! $oid || isset( $seen[ $oid ] ) ) { continue; } $seen[ $oid ] = 1;
			$last = ''; foreach ( (array) ( $views[ $r['sid'] ] ?? array() ) as $v ) { if ( $v['at'] <= $r['at'] ) { $last = $v['k']; } }
			$untracked = '' === $last; if ( $untracked ) { $last = 'flow' === (string) $r['variant'] ? 'new' : 'old'; }
			if ( $last !== $lander ) { continue; }
			$od = isset( $out['orders'][ $oid ] ) ? $out['orders'][ $oid ] : self::order_row( $oid, $cfg ); if ( ! $od ) { continue; }
			$out['period_orders']['n']++; $out['period_orders']['total'] += $od['total'];
			if ( $untracked ) { $out['period_orders']['untracked_n']++; $out['period_orders']['untracked_total'] += $od['total']; }
		}
	}
	private static function product_names() { $n = array(); try { foreach ( (array) Config::get_published()['products'] as $p ) { $n[ $p['key'] ] = $p['name'] ?: $p['key']; } } catch ( \Throwable $e ) {} return $n; }

	/** Сигналите „Какво проверяваме след това“ — до 3, с доказателство и следващо действие. */
	public static function signals( $d, $prev = null ) {
		$s = array(); $n = (int) $d['sessions']; $L = self::LANDERS[ $d['lander'] ];
		if ( $n > 0 && $n < self::MIN_ROWS ) { $s[] = array( 'info', 'Малка извадка', $n . ' сесии в лендъра за периода — конверсията е случайност, не тенденция.', 'Разшири периода или изчакай ≥ 30 сесии, преди да сравняваш източници.' ); }
		/* най-голямото прекъсване */
		$steps = $L['funnel']; $worst = null;
		for ( $i = 1; $i < count( $steps ); $i++ ) { $a = (int) $d['steps'][ $steps[ $i - 1 ] ]; $b = (int) $d['steps'][ $steps[ $i ] ]; if ( $a >= self::MIN_ROWS ) { $loss = 1 - $b / $a; if ( null === $worst || $loss > $worst[0] ) { $worst = array( $loss, $steps[ $i - 1 ], $steps[ $i ], $a, $b ); } } }
		if ( $worst && $worst[0] >= 0.5 ) { $s[] = array( 'warn', 'Най-голямото прекъсване: „' . self::STEPS[ $worst[1] ] . '“ → „' . self::STEPS[ $worst[2] ] . '“', $worst[3] . ' → ' . $worst[4] . ' сесии (' . Insights::pct( $worst[3] - $worst[4], $worst[3] ) . ' не продължават).', 'Отвори списъка „кои →“ на тази стъпка и прегледай 10 сесии: устройство, източник, какво са добавили.' ); }
		/* източник с достатъчно сесии и без поръчка */
		$dead = array(); foreach ( $d['tree']['entry'] as $k => $node ) { foreach ( $node['ch'] as $name => $c ) { if ( $c['m']['n'] >= self::MIN_ROWS && 0 === $c['m']['created'] ) { $dead[] = array( $c['m']['n'], $node['l'] . ' › ' . $name ); } } }
		if ( $dead ) { usort( $dead, function ( $a, $b ) { return $b[0] <=> $a[0]; } ); $s[] = array( 'bad', 'Източник без нито една поръчка', $dead[0][1] . ': ' . $dead[0][0] . ' сесии, 0 поръчки' . ( count( $dead ) > 1 ? ' (още ' . ( count( $dead ) - 1 ) . ' такива)' : '' ) . '.', 'Провери съответствието реклама → лендър (продукт, обещание, цена) и спри или пренасочи източника.' ); }
		/* реклами без имена / ID */
		$meta = $d['tree']['entry']['meta'] ?? ( $d['tree']['ext']['meta'] ?? null );
		if ( $meta && ( isset( $meta['ch']['без име на кампания'] ) || ! array_filter( array_keys( $meta['ch'] ), function ( $k ) { return 0 !== strpos( $k, '#' ); } ) ) ) { $s[] = array( 'warn', 'Рекламите пристигат без име на кампания / реклама', (int) ( $meta['ch']['без име на кампания']['m']['n'] ?? $meta['m']['n'] ) . ' сесии от Meta не могат да се разгънат до конкретна реклама.', 'Сложи в адреса на рекламите utm_campaign={{campaign.name}}&utm_id={{campaign.id}}&adset_name={{adset.name}}&adset_id={{adset.id}}&ad_name={{ad.name}}&ad_id={{ad.id}}&placement={{placement}}.' ); }
		/* промяна спрямо предишния период */
		if ( $prev && $prev['sessions'] >= self::MIN_ROWS && $n >= self::MIN_ROWS ) {
			$c0 = $prev['created_sessions'] / $prev['sessions']; $c1 = $d['created_sessions'] / $n;
			if ( $c0 > 0 && ( $c1 / $c0 <= 0.6 || $c1 / $c0 >= 1.6 ) ) { $s[] = array( $c1 < $c0 ? 'bad' : 'good', 'Конверсията се промени ' . ( $c1 < $c0 ? 'надолу' : 'нагоре' ), Insights::pct( $d['created_sessions'], $n ) . ' сега срещу ' . Insights::pct( $prev['created_sessions'], $prev['sessions'] ) . ' в предишния период (същият часови отрязък).', 'Виж „Какво се промени“ — дали е сменен съставът на източниците (таблицата по вход), или конверсията пада във всички.' ); }
		}
		if ( $d['period_orders']['untracked_n'] > 0 ) { $s[] = array( 'info', 'Поръчки без проследима връзка', $d['period_orders']['untracked_n'] . ' поръчки в периода нямат нито едно отчетено отваряне на лендър (' . Insights::money( $d['period_orders']['untracked_total'] ) . ').', 'Обикновено стар кеш или блокиран JS; не се измислят посещения за тях — стоят отделно.' ); }
		if ( $d['legacy_entry'] > 0 && $d['legacy_entry'] >= $n * 0.5 ) { $s[] = array( 'info', 'Входът към лендъра се отчита от 1.42.0', $d['legacy_entry'] . ' от ' . $n . ' сесии са отпреди — за тях „вход“ = „външен източник“.', 'Изчакай данните след ъпдейта за таблицата по вход (попъп → от коя страница).' ); }
		return array_slice( $s, 0, 3 );
	}

	/* ── екранът ── */

	public static function render() {
		$lander = self::current(); $L = self::LANDERS[ $lander ]; $rg = Insights::period(); $f = Insights::filters(); $mode = self::mode();
		$cut = self::cutoff( $rg ); $o = array( 'internal' => ! empty( $f['internal'] ), 'cutoff' => $cut ?: '' );
		$d = self::build( $lander, $rg, $o ); $prg = Insights::prev_range( $rg ); $p = self::build( $lander, $prg, $o );
		self::styles();
		/* лендърът */
		$chips = ''; foreach ( self::LANDERS as $k => $x ) { $chips .= '<a class="ap-chip ap-ld' . ( $k === $lander ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'lander' => $k, 'mode' => null ) ) ) . '"><b>' . esc_html( $x['label'] ) . '</b><small>ansa.bg' . esc_html( $x['path'] ) . '</small></a>'; }
		echo '<div class="ap-actions ap-ldbar" style="padding:0 0 10px"><span class="ap-fbar__l">Лендър</span><div class="ap-chips ap-ldchips">' . $chips . '</div></div>';
		echo Insights::period_bar( '<a class="ap-chip' . ( $f['internal'] ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'internal' => $f['internal'] ? null : '1' ) ) ) . '" title="вътрешен трафик (офис IP, админи, Probe)">' . ( $f['internal'] ? 'вътрешният трафик е включен ✕' : '+ вътрешен трафик' ) . '</a>' );
		echo self::html( $d, $p, $mode, $f );
	}

	/** Цялото съдържание от данните (build) — и за портала. */
	public static function html( $d, $p, $mode = 'entry', $f = null ) {
		$lander = $d['lander']; $L = self::LANDERS[ $lander ]; $rg = $d['rg']; $n = (int) $d['sessions']; $cut = $d['cutoff']; $small = $n > 0 && $n < self::MIN_ROWS;
		$cl = '23:59:59' !== $cut ? ' до ' . substr( $cut, 0, 5 ) : ''; $plabel = Insights::period_label( $rg ) . $cl; $pplabel = Insights::period_label( $p['rg'] ) . $cl;
		$conv = Insights::pct( $d['created_sessions'], $n ); $pconv = Insights::pct( $p['created_sessions'], (int) $p['sessions'] );
		$h = '<div class="ap-ld">';
		/* контекст */
		$swap = ! empty( $d['swap'] ) ? date_i18n( 'd.m H:i', strtotime( $d['swap'] ) ) : '28.10 13:43';
		$h .= '<div class="ap-ld__ctx"><b>' . esc_html( $L['label'] ) . '</b> · ansa.bg' . esc_html( $L['path'] ) . ' · <b>' . esc_html( $plabel ) . '</b> · сравнение с ' . esc_html( $pplabel ) . ( '23:59:59' !== $cut ? ' (същият часови отрязък)' : '' ) . ' · данни към ' . esc_html( current_time( 'd.m H:i' ) ) . ( $L['tombola'] ? ' · томболата е отделен отчет най-долу' : ' · томболата не е в тази фуния' ) . '</div>'
			. '<div class="ap-ld__ctx ap-ld__ctx2">Лендърът е адресът: ' . ( 'new' === $lander ? 'до ' . esc_html( $swap ) . ' тук беше старият дизайн (кутиите), оттогава — новият (потокът)' : ( 'old' === $lander ? 'страницата съществува от 27.10; до ' . esc_html( $swap ) . ' показваше новия дизайн, оттогава — старият (кутиите) с томболата' : esc_html( $L['design'] ) ) ) . '. '
			. 'За периода 📊 Подробно брои <b>' . (int) $d['all_sessions'] . '</b> сесии с какъвто и да е бийкън (и продуктови страници със Shrine картата, и подстраници); от тях <b>' . (int) $d['any_lander'] . '</b> са отворили някой лендър, а <b>' . $n . '</b> — този' . ( $d['both'] ? ' (' . (int) $d['both'] . ' са били и на друг)' : '' ) . '.</div>';
		/* 1. резултатът — четири числа */
		$tiles = Insights::tile( 'Сесии в лендъра', (string) $n, 'отворили ansa.bg' . esc_html( $L['path'] ) . ' · <b>' . (int) $d['visitors'] . '</b> уникални посетители' . ( $d['both'] ? ' · <b>' . (int) $d['both'] . '</b> са били и на друг лендър' : '' ), '', '', true, Insights::delta_html( (string) $n, (string) $p['sessions'] ) )
			. Insights::tile( 'Сесии с поръчка', (string) $d['created_sessions'], 'от тези сесии · приписване: последният отворен лендър преди checkout', 'good', '', true, Insights::delta_html( (string) $d['created_sessions'], (string) $p['created_sessions'] ) )
			. Insights::tile( 'Конверсия', $conv, ( $small ? '<b class="ap-small">малка извадка</b> · ' : '' ) . (int) $d['created_sessions'] . ' сесии с поръчка ÷ ' . $n . ' сесии в лендъра', 'info', '', true, Insights::delta_html( $conv, $pconv ) )
			. Insights::tile( 'Стойност на поръчките', Insights::money( $d['value'] ), 'с ДДС и доставка · ДДС <b>' . esc_html( Insights::money( $d['tax'] ) ) . '</b> · доставка <b>' . esc_html( Insights::money( $d['ship'] ) ) . '</b>', '', '', true, Insights::delta_html( Insights::money( $d['value'] ), Insights::money( $p['value'] ) ) );
		$h .= '<div class="ap-dash">' . Insights::group_html( '1 · Какъв е резултатът?', $tiles, 'само хората, отворили този адрес; Δ спрямо ' . esc_html( $pplabel ), 'apLdKpi' ) . '</div>';
		/* допълнителните числа */
		$sub = Insights::tile( 'Брой поръчки', (string) $d['orders_n'], 'различно от „сесии с поръчка“ — една сесия може да поръча два пъти', '', '', false, Insights::delta_html( (string) $d['orders_n'], (string) $p['orders_n'] ) )
			. Insights::tile( 'Средна поръчка', Insights::money( $d['aov'] ), 'стойност ÷ брой поръчки', '', '', false, Insights::delta_html( Insights::money( $d['aov'] ), Insights::money( $p['aov'] ) ) )
			. Insights::tile( 'Поръчки, създадени в периода', (string) $d['period_orders']['n'], 'по датата на поръчката (не по сесията) · ' . esc_html( Insights::money( $d['period_orders']['total'] ) ) . ( $d['period_orders']['untracked_n'] ? ' · без проследима връзка: <b>' . (int) $d['period_orders']['untracked_n'] . '</b>' : '' ), 'info' )
			. Insights::tile( 'Статус на поръчките', (int) $d['status']['delivered']['n'] . ' взети', 'в път / чакат <b>' . (int) $d['status']['open']['n'] . '</b> · отказани <b>' . (int) $d['status']['lost']['n'] . '</b> · взети ' . esc_html( Insights::money( $d['status']['delivered']['total'] ) ), $d['status']['lost']['n'] ? 'warn' : '' );
		$h .= '<div class="ap-dash">' . Insights::group_html( 'Още числа', $sub, 'покупката може да стане по-късно от сесията — затова двете справки са отделни' ) . '</div>';
		/* обяснението */
		$h .= '<div class="ap-sec ap-ld__txt"><p>' . self::summary_text( $d, $p, $plabel, $pplabel ) . '</p></div>';
		/* 2. какво се промени */
		$h .= self::change_html( $d, $p, $plabel, $pplabel );
		/* 3. откъде дойдоха и какво направиха */
		$h .= self::source_html( $d, $mode );
		/* 4. къде се прекъсва покупката */
		$h .= self::funnel_html( $d );
		/* 5. при кои посетители */
		$h .= self::breakdown_html( $d );
		/* офертата / какво купуват + томболата */
		$h .= self::buy_html( $d );
		if ( $L['tombola'] ) { $h .= self::tombola_html( $d ); }
		/* 6. какво проверяваме след това */
		$h .= self::signals_html( self::signals( $d, $p ) );
		$h .= self::glossary_html( $d );
		return $h . '</div>' . self::scripts();
	}

	private static function summary_text( $d, $p, $plabel, $pplabel ) {
		$n = (int) $d['sessions']; if ( ! $n ) { return 'За ' . esc_html( $plabel ) . ' няма отчетени отваряния на този лендър. Ако е нов адрес, данните тръгват с първите посещения след ъпдейта.'; }
		$t = 'За ' . esc_html( $plabel ) . ' лендърът е отворен в <b>' . $n . '</b> сесии; <b>' . (int) $d['created_sessions'] . '</b> от тях стигнаха до поръчка (' . esc_html( Insights::pct( $d['created_sessions'], $n ) ) . '), общо <b>' . (int) $d['orders_n'] . '</b> поръчки за <b>' . esc_html( Insights::money( $d['value'] ) ) . '</b> с ДДС и доставка.';
		if ( $d['orders_n'] ) { $t .= ' Взети са ' . (int) $d['status']['delivered']['n'] . ', в път / чакат ' . (int) $d['status']['open']['n'] . ( $d['status']['lost']['n'] ? ', отказани ' . (int) $d['status']['lost']['n'] : '' ) . '.'; }
		if ( $p['sessions'] >= self::MIN_ROWS && $n >= self::MIN_ROWS ) { $t .= ' Спрямо ' . esc_html( $pplabel ) . ': сесии ' . esc_html( Insights::delta_text( (string) $n, (string) $p['sessions'] ) ) . ', конверсия ' . esc_html( Insights::delta_text( Insights::pct( $d['created_sessions'], $n ), Insights::pct( $p['created_sessions'], $p['sessions'] ) ) ) . ', средна поръчка ' . esc_html( Insights::delta_text( Insights::money( $d['aov'] ), Insights::money( $p['aov'] ) ) ) . '.'; }
		elseif ( $n < self::MIN_ROWS ) { $t .= ' Под ' . self::MIN_ROWS . ' сесии — процентите са ориентировъчни.'; }
		$top = null; foreach ( $d['tree']['entry'] as $k => $node ) { if ( null === $top || $node['m']['n'] > $top[1] ) { $top = array( $node['l'], $node['m']['n'], $node['m']['created'] ); } }
		if ( $top ) { $t .= ' Най-много сесии идват от „' . esc_html( $top[0] ) . '“ — ' . (int) $top[1] . ' (' . (int) $top[2] . ' с поръчка).'; }
		return $t;
	}

	private static function change_html( $d, $p, $plabel, $pplabel ) {
		$rows = array(
			array( 'Сесии в лендъра', (string) $d['sessions'], (string) $p['sessions'], false ),
			array( 'Добавили продукт', (string) $d['steps']['item'], (string) $p['steps']['item'], false ),
			array( 'Отворили checkout', (string) $d['steps']['add'], (string) $p['steps']['add'], false ),
			array( 'Сесии с поръчка', (string) $d['created_sessions'], (string) $p['created_sessions'], false ),
			array( 'Конверсия', Insights::pct( $d['created_sessions'], $d['sessions'] ), Insights::pct( $p['created_sessions'], $p['sessions'] ), false ),
			array( 'Стойност', Insights::money( $d['value'] ), Insights::money( $p['value'] ), false ),
			array( 'Средна поръчка', Insights::money( $d['aov'] ), Insights::money( $p['aov'] ), false ),
		);
		$h = '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">2 · Какво се промени?</span></div><div class="ap-sec__hint">' . esc_html( $plabel ) . ' спрямо ' . esc_html( $pplabel ) . ' — еднакъв брой дни' . ( '23:59:59' !== $d['cutoff'] ? ' и еднакъв часови отрязък' : '' ) . '. Под ' . self::MIN_ROWS . ' сесии в единия период разликата е шум.</div><div class="ap-sec__b ap-ld__cols"><table class="widefat striped"><thead><tr><th>показател</th><th>сега</th><th>преди</th><th>Δ</th></tr></thead><tbody>';
		foreach ( $rows as $r ) { $h .= '<tr><td><b>' . esc_html( $r[0] ) . '</b></td><td><b>' . esc_html( $r[1] ) . '</b></td><td>' . esc_html( $r[2] ) . '</td><td>' . Insights::delta_html( $r[1], $r[2] ) . '</td></tr>'; }
		$h .= '</tbody></table>';
		/* графиката по дни: сесии и сесии с поръчка */
		$days = array(); $t0 = strtotime( $d['rg']['from'] ); $t1 = strtotime( $d['rg']['to'] ); for ( $t = $t0; $t <= $t1; $t += DAY_IN_SECONDS ) { $days[] = gmdate( 'Y-m-d', $t ); }
		if ( count( $days ) > 1 ) {
			$s1 = array(); $s2 = array(); foreach ( $days as $day ) { $m = $d['by_day'][ $day ] ?? self::ZERO; $s1[] = (int) $m['n']; $s2[] = (int) $m['created']; }
			$h .= '<div class="ap-ld__chart"><div class="ap-ld__chart-l"><span><i style="background:#f47a49"></i>сесии в лендъра по дни</span><span><i style="background:#3bb273"></i>сесии с поръчка</span></div>' . self::bars( $days, $s1, $s2 ) . '</div>';
		}
		return $h . '</div></div>';
	}
	/** SVG колонки: сесии (сиво-оранжево) и сесии с поръчка (зелено) по дни. */
	private static function bars( $labels, $a, $b ) {
		$n = count( $labels ); $W = 820; $H = 150; $P = array( 'l' => 34, 'r' => 6, 't' => 8, 'b' => 22 ); $max = max( 1, max( $a ) ); $bw = ( $W - $P['l'] - $P['r'] ) / $n;
		$s = '<svg class="ap-ld__svg" viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" role="img">';
		for ( $g = 0; $g <= 3; $g++ ) { $gy = $P['t'] + ( $H - $P['t'] - $P['b'] ) * $g / 3; $gv = round( $max * ( 1 - $g / 3 ) ); $s .= '<line x1="' . $P['l'] . '" y1="' . round( $gy, 1 ) . '" x2="' . ( $W - $P['r'] ) . '" y2="' . round( $gy, 1 ) . '" stroke="#f6ddd5"/><text x="' . ( $P['l'] - 5 ) . '" y="' . round( $gy + 4, 1 ) . '" font-size="10" text-anchor="end" fill="#a08579">' . $gv . '</text>'; }
		$step = max( 1, (int) ceil( $n / 14 ) );
		foreach ( $labels as $i => $lb ) {
			$x = $P['l'] + $i * $bw; $ha = ( $H - $P['t'] - $P['b'] ) * $a[ $i ] / $max; $hb = ( $H - $P['t'] - $P['b'] ) * $b[ $i ] / $max; $y0 = $H - $P['b'];
			$s .= '<rect x="' . round( $x + $bw * 0.15, 1 ) . '" y="' . round( $y0 - $ha, 1 ) . '" width="' . round( $bw * 0.7, 1 ) . '" height="' . round( $ha, 1 ) . '" fill="#f8c4ad" rx="2"><title>' . esc_html( $lb ) . ': ' . (int) $a[ $i ] . ' сесии</title></rect>';
			$s .= '<rect x="' . round( $x + $bw * 0.15, 1 ) . '" y="' . round( $y0 - $hb, 1 ) . '" width="' . round( $bw * 0.7, 1 ) . '" height="' . round( $hb, 1 ) . '" fill="#3bb273" rx="2"><title>' . esc_html( $lb ) . ': ' . (int) $b[ $i ] . ' с поръчка</title></rect>';
			if ( 0 === $i % $step ) { $s .= '<text x="' . round( $x + $bw / 2, 1 ) . '" y="' . ( $H - 6 ) . '" font-size="10" text-anchor="middle" fill="#a08579">' . esc_html( substr( $lb, 8, 2 ) . '.' . substr( $lb, 5, 2 ) ) . '</text>'; }
		}
		return $s . '</svg>';
	}

	/** Таблицата „Откъде дойдоха и какво направиха?“ с режим вход / външен източник; редовете се разгъват до 3 нива. */
	private static function source_html( $d, $mode ) {
		$tree = $d['tree'][ $mode ]; $n = max( 1, (int) $d['sessions'] );
		$h = '<div class="ap-sec ap-sec--table" id="apLdSrc"><div class="ap-sec__h"><span class="ap-sec__t">3 · Откъде дойдоха и какво направиха?</span><span class="ap-chips ap-ld__modes">'
			. '<a class="ap-chip' . ( 'entry' === $mode ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'mode' => null ) ) ) . '#apLdSrc" title="какво отвори лендъра: реклама, попъп (от коя страница), имейл, вътрешен линк">Вход към лендъра</a>'
			. '<a class="ap-chip' . ( 'ext' === $mode ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'mode' => 'ext' ) ) ) . '#apLdSrc" title="какво доведе човека на сайта (първото докосване в този таб)">Външен източник</a></span></div>'
			. '<div class="ap-sec__hint">' . ( 'entry' === $mode ? 'Кое доведе човека <b>до лендъра</b>: Meta реклама (кампания → рекламна група → реклама), попъп (страница/продукт → попъп), имейл (изпращане → линк), вътрешен линк. ' : 'Кое доведе човека <b>до сайта</b> — първото докосване в този таб (напр. Facebook рекламата, след която е минал през Sakura и попъпа). ' ) . 'Двата режима са два изгледа към едни и същи сесии — не се събират. Цъкни ред, за да го разгънеш. Конверсия = сесии с поръчка ÷ сесии; под ' . self::MIN_ROWS . ' сесии е „малка извадка“.' . ( $d['legacy_entry'] ? ' <b>' . (int) $d['legacy_entry'] . '</b> сесии са отпреди 1.42.0 — за тях входът е равен на външния източник.' : '' ) . '</div>'
			. '<div class="ap-sec__b"><table class="widefat ap-ld__tree"><thead><tr><th>източник</th><th>сесии</th><th>%</th><th>добавили продукт</th><th>стигнали checkout</th><th>сесии с поръчка</th><th>конверсия</th><th>стойност</th></tr></thead><tbody>';
		$i = 0;
		foreach ( Source::ORDER as $k => $label ) {
			if ( ! isset( $tree[ $k ] ) ) { continue; } $node = $tree[ $k ]; $id = 'ld' . ( ++$i );
			$h .= self::tree_row( $label, $node['m'], $n, 0, $id, '', ! empty( $node['ch'] ) );
			$j = 0; foreach ( $node['ch'] as $name => $c ) { $id2 = $id . '-' . ( ++$j ); $h .= self::tree_row( $name, $c['m'], $n, 1, $id2, $id, ! empty( $c['ch'] ) ); $q = 0; foreach ( $c['ch'] as $name2 => $c2 ) { $id3 = $id2 . '-' . ( ++$q ); $h .= self::tree_row( $name2, $c2['m'], $n, 2, $id3, $id2, ! empty( $c2['ch'] ) ); foreach ( $c2['ch'] as $name3 => $c3 ) { $h .= self::tree_row( $name3, $c3['m'], $n, 3, '', $id3, false ); } } }
		}
		if ( ! $tree ) { $h .= '<tr><td colspan="8"><small>няма сесии за периода</small></td></tr>'; }
		return $h . '</tbody></table></div></div>';
	}
	private static function tree_row( $label, $m, $total, $lvl, $id, $parent, $has_ch ) {
		$small = $m['n'] > 0 && $m['n'] < self::MIN_ROWS; $conv = Insights::pct( $m['created'], $m['n'] );
		return '<tr class="ap-ld__r lv' . (int) $lvl . ( $parent ? ' ch' : '' ) . ( $has_ch ? ' has' : '' ) . '"' . ( $id ? ' data-id="' . esc_attr( $id ) . '"' : '' ) . ( $parent ? ' data-p="' . esc_attr( $parent ) . '" hidden' : '' ) . '>'
			. '<td><span class="ap-ld__tg">' . ( $has_ch ? '▸' : '' ) . '</span>' . ( 0 === $lvl ? '<b>' . esc_html( $label ) . '</b>' : esc_html( $label ) ) . '</td>'
			. '<td><b>' . (int) $m['n'] . '</b></td><td><small>' . esc_html( Insights::pct( $m['n'], $total ) ) . '</small></td><td>' . (int) $m['added'] . '</td><td>' . (int) $m['add'] . '</td><td><b>' . (int) $m['created'] . '</b>' . ( $m['orders'] !== $m['created'] ? ' <small>(' . (int) $m['orders'] . ' поръчки)</small>' : '' ) . '</td>'
			. '<td' . ( $small ? ' class="ap-small-c"' : '' ) . '>' . esc_html( $conv ) . ( $small ? ' <small>малка извадка</small>' : '' ) . '</td><td>' . esc_html( Insights::money( $m['value'] ) ) . '</td></tr>';
	}

	/** Фунията на този лендър: само реалните стъпки; „кои →“ отваря сесиите. */
	private static function funnel_html( $d ) {
		$L = self::LANDERS[ $d['lander'] ]; $steps = $L['funnel']; $max = max( 1, (int) $d['steps']['view'] ); $prev = null; $prev_st = '';
		$h = '<div class="ap-sec" id="apLdFunnel"><div class="ap-sec__h"><span class="ap-sec__t">4 · Къде се прекъсва покупката?</span></div><div class="ap-sec__hint">' . ( 'new' === $d['lander'] ? 'Новият поток: отворили → добавили първи продукт → отворили checkout → създали поръчка. Достигането на 5 опаковки и козметиката са анализ на офертата (по-долу), не задължителни стъпки.' : ( 'old' === $d['lander'] ? 'Старият поток с кутиите: отворили → избрали кутия → добавили продукти → напълнили минимума → checkout → поръчка. Томболата е след покупката и е отделен отчет.' : 'Адверториалът: отворили → „Вземи офертата“ (количката се пълни на сървъра и води в checkout) → поръчка.' ) ) . ' Всяка сесия се брои веднъж на стъпка; действията се приписват на последния отворен лендър. Фунията приключва с поръчката.</div><div class="ap-funnel">';
		foreach ( $steps as $st ) {
			$v = (int) $d['steps'][ $st ]; $w = max( 2, round( 100 * $v / $max ) );
			if ( null !== $prev ) { $lost = max( 0, $prev - $v ); $h .= '<div class="ap-fn__drop' . ( $lost > 0 ? ' has' : '' ) . '"><span>' . ( $lost > 0 ? '▼ −' . $lost . ' <small>(' . esc_html( Insights::pct( $lost, max( 1, $prev ) ) ) . ' от „' . esc_html( self::STEPS[ $prev_st ] ) . '“ не продължават)</small>' : '· без загуба' ) . '</span></div>'; }
			$link = self::url( array( 'view' => 'sessions', 'lander' => $d['lander'], 'step' => self::STEP_SESS[ $st ], 'mode' => 'min', 'ord' => 'created' === $st ? 'yes' : null, 'pg' => null ) ) . '#apInsList';
			$h .= '<div class="ap-fn"><span class="ap-fn__l">' . esc_html( self::STEPS[ $st ] ) . '</span><span class="ap-fn__bar"><i style="width:' . $w . '%"></i></span><b class="ap-fn__n">' . $v . '</b><small>' . esc_html( Insights::pct( $v, $max ) ) . ' от отворилите' . ( null !== $prev ? ' · ' . esc_html( Insights::pct( $v, max( 1, $prev ) ) ) . ' от предишната' : '' ) . ' · <a href="' . esc_url( $link ) . '">кои →</a></small></div>';
			$prev = $v; $prev_st = $st;
		}
		return $h . '</div></div>';
	}

	/** Една таблица с избор: устройство · продукт в адреса · ден · час. */
	private static function breakdown_html( $d ) {
		$tabs = array( 'dev' => array( '📱 Устройство', $d['by_dev'] ), 'product' => array( '🏷️ Продукт в адреса (utm_content)', $d['by_product'] ), 'day' => array( '📅 Ден на отварянето', array_reverse( $d['by_day'], true ) ), 'hour' => array( '🕐 Час на отварянето', $d['by_hour'] ) );
		$n = max( 1, (int) $d['sessions'] ); $chips = ''; $bodies = ''; $first = true;
		foreach ( $tabs as $k => $t ) {
			$chips .= '<button type="button" class="ap-chip ap-chip--sm ap-ld__tab' . ( $first ? ' on' : '' ) . '" data-t="' . esc_attr( $k ) . '">' . esc_html( $t[0] ) . '</button>';
			$bodies .= '<table class="widefat striped ap-sort ap-ld__bt" data-t="' . esc_attr( $k ) . '"' . ( $first ? '' : ' hidden' ) . '><thead><tr><th>' . esc_html( mb_substr( $t[0], 2 ) ) . '</th><th data-sort="1">сесии</th><th>%</th><th data-sort="1">добавили продукт</th><th data-sort="1">стигнали checkout</th><th data-sort="1">сесии с поръчка</th><th data-sort="1">конверсия</th><th data-sort="1">стойност</th></tr></thead><tbody>';
			foreach ( $t[1] as $key => $m ) { $small = $m['n'] > 0 && $m['n'] < self::MIN_ROWS; $cr = $m['n'] ? 100 * $m['created'] / $m['n'] : -1; $bodies .= '<tr><td><b>' . esc_html( 'day' === $k ? date_i18n( 'd.m (D)', strtotime( $key ) ) : $key ) . '</b></td><td data-v="' . (int) $m['n'] . '"><b>' . (int) $m['n'] . '</b></td><td><small>' . esc_html( Insights::pct( $m['n'], $n ) ) . '</small></td><td data-v="' . (int) $m['added'] . '">' . (int) $m['added'] . '</td><td data-v="' . (int) $m['add'] . '">' . (int) $m['add'] . '</td><td data-v="' . (int) $m['created'] . '"><b>' . (int) $m['created'] . '</b></td><td data-v="' . esc_attr( (string) $cr ) . '"' . ( $small ? ' class="ap-small-c"' : '' ) . '>' . esc_html( Insights::pct( $m['created'], $m['n'] ) ) . ( $small ? ' <small>малка извадка</small>' : '' ) . '</td><td data-v="' . esc_attr( (string) $m['value'] ) . '">' . esc_html( Insights::money( $m['value'] ) ) . '</td></tr>'; }
			if ( ! $t[1] ) { $bodies .= '<tr><td colspan="8"><small>няма данни</small></td></tr>'; }
			$bodies .= '</tbody></table>'; $first = false;
		}
		return '<div class="ap-sec ap-sec--table" id="apLdBreak"><div class="ap-sec__h"><span class="ap-sec__t">5 · При кои посетители?</span><span class="ap-chips">' . $chips . '</span></div><div class="ap-sec__hint">Същите сесии, разрязани по друг признак. Денят е денят на първото отваряне на лендъра (покупката може да е по-късно).</div><div class="ap-sec__b">' . $bodies . '</div></div>';
	}

	/** Какво купуват: опаковки 1 / 2 / 3 / 4 / 5+, еднакви или смесени, козметиката, книгата. Само поръчките на този лендър. */
	private static function buy_html( $d ) {
		$b = $d['buy']; $n = (int) $b['n']; if ( ! $n ) { return ''; }
		$tiles = '';
		foreach ( array( '1', '2', '3', '4', '5+' ) as $k ) { $v = (int) ( $b['packs'][ $k ] ?? 0 ); $tiles .= Insights::tile( $k . ( '1' === $k ? ' опаковка' : ' опаковки' ), (string) $v, esc_html( Insights::pct( $v, $n ) ) . ' от поръчките', '5+' === $k ? 'good' : '' ); }
		$tiles .= Insights::tile( 'Еднакви продукти', (string) $b['same'], 'смесени: <b>' . (int) $b['mixed'] . '</b> · по продуктите в сесията', 'info' )
			. Insights::tile( 'Козметичният сет', (string) $b['cosm_yes'], 'резервиран за 1 € · отказан: <b>' . (int) $b['cosm_no'] . '</b> · прагът (5+) достигнат в <b>' . (int) $b['reach5'] . '</b> поръчки', 'good' )
			. Insights::tile( 'Книгата с рецепти', (string) $b['book'], 'поръчки с книга', '' );
		return '<div class="ap-dash">' . Insights::group_html( '🧺 Какво купуват (поръчките на този лендър)', $tiles, 'механиката на офертата: броят опаковки, еднакви или смесени, достигнат праг за козметиката' ) . '</div>';
	}
	/** Томболата — само за стария лендър, след покупката. */
	private static function tombola_html( $d ) {
		$t = $d['tombola']; $tiles = Insights::tile( 'Поръчки', (string) $t['orders'], 'на този лендър', '' ) . Insights::tile( 'С издаден билет', (string) $t['paid'], esc_html( Insights::pct( $t['paid'], $t['orders'] ) ) . ' от поръчките · <b>' . (int) $t['tickets'] . '</b> билета общо', 'info' ) . Insights::tile( 'Активирани', (string) $t['activated'], esc_html( Insights::pct( $t['activated'], $t['paid'] ) ) . ' от билетите · активира се при получена пратка', 'good' );
		return '<div class="ap-dash">' . Insights::group_html( '🎟️ Томболата (след покупката)', $tiles, 'отделен отчет — неактивиран билет не намалява конверсията към поръчка' ) . '</div>';
	}
	private static function signals_html( $s ) {
		$h = '<div class="ap-sec" id="apLdNext"><div class="ap-sec__h"><span class="ap-sec__t">6 · Какво проверяваме след това?</span></div><div class="ap-ld__sig">';
		if ( ! $s ) { $h .= '<div class="ap-ld__s info"><b>Няма тревожни сигнали</b><span>Фунията и източниците са в нормата за периода; при повече данни тук излизат до три конкретни проверки.</span></div>'; }
		foreach ( $s as $x ) { $h .= '<div class="ap-ld__s ' . esc_attr( $x[0] ) . '"><b>' . esc_html( $x[1] ) . '</b><span>' . esc_html( $x[2] ) . '</span><em>→ ' . esc_html( $x[3] ) . '</em></div>'; }
		return $h . '</div></div>';
	}
	private static function glossary_html( $d ) {
		$g = array(
			'Сесия в лендъра' => 'Сесия с отчетено отваряне на точно този АДРЕС (таб на браузъра / бисквитка за деня), независимо кой дизайн е показвал тогава. Презареждане не прави ново отваряне. Продуктови страници, Shrine картата и подстраниците (/info, /kozmetika) не са сесии в лендъра — затова числото е по-малко от „Сесии“ в 📊 Подробно, което брои всяка сесия с бийкън.',
			'Лендър = адрес' => 'Главният адрес /sezonut-na-ansa до 28.10 показваше старите кутии, оттогава новия поток; /sezonut-na-ansa-b е от 27.10 и дотогава показваше потока. Историята е преизчислена по адрес (DB 9). Фунията на основния адрес ползва общите стъпки (отворили → добавили продукт → checkout → поръчка), които важат и за двата дизайна.',
			'Сесии с поръчка' => 'Сесии, в които след отварянето на лендъра е създадена поръчка; поръчката се приписва на последния отворен лендър преди checkout. Една сесия с две поръчки е 1 тук и 2 в „брой поръчки“.',
			'Конверсия' => 'Сесии с поръчка ÷ сесии в лендъра. Под ' . self::MIN_ROWS . ' сесии — „малка извадка“.',
			'Стойност' => 'Сумата на поръчките с ДДС и доставка, както е в WooCommerce; ДДС и доставката са показани отделно. Всички статуси без чернови; взети / в път / отказани са в „Статус на поръчките“.',
			'Вход към лендъра' => 'Адресът и referrer-ът при първото отваряне на лендъра в сесията: реклама (utm/ad_id), попъп на сайта (ap_pop + ap_from = страницата/продуктът, от който е отворен; Shrine ≥ 6.33), имейл, вътрешен линк.',
			'Външен източник' => 'Първото докосване на сайта в този таб (параметрите и referrer-ът на първата отворена страница). За сесии отпреди 1.42.0 — UTM/referrer-ът на първото събитие.',
			'Поръчки, създадени в периода' => 'По датата на поръчката, а не по сесията — покупката може да стане ден по-късно. „Без проследима връзка“ = поръчки без нито едно отчетено отваряне на лендър; за тях не се измислят посещения.',
			'Сравнение' => 'Предишен период със същата дължина, точно преди избрания. Когато периодът стига до днес, предишният се реже до същия час.',
		);
		$h = '<details class="ap-sec ap-gloss"><summary><span class="ap-sec__t">📖 Как се смята</span></summary><dl>'; foreach ( $g as $k => $v ) { $h .= '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>'; } return $h . '</dl></details>';
	}

	public static function styles() {
		static $done = false; if ( $done ) { return; } $done = true;
		echo '<style>#apLdKpi .ap-stg__g{grid-template-columns:repeat(4,1fr)}#apLdKpi .ap-st--hero{grid-column:span 1}#apLdKpi .ap-st--hero .ap-st__v{font-size:40px}@media(max-width:1100px){#apLdKpi .ap-stg__g{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){#apLdKpi .ap-stg__g{grid-template-columns:1fr 1fr}#apLdKpi .ap-st--hero .ap-st__v{font-size:28px}}.ap-ldchips .ap-chip.on small{color:#fff;opacity:.9}.ap-ldchips .ap-chip{display:inline-flex;flex-direction:column;align-items:flex-start;gap:1px;line-height:1.15;padding:7px 14px}.ap-ldchips .ap-chip small{font-size:10px;font-weight:700;opacity:.75}.ap-ld__ctx{font-size:12px;font-weight:700;color:#a08579;padding:0 4px 10px}.ap-ld__ctx b{color:#43302a}'
			. '.ap-ld__ctx2{font-weight:600;margin-top:-6px}.ap-ld__txt{padding:14px 20px;font-size:14px;line-height:1.55;color:#43302a}.ap-ld__txt p{margin:0}.ap-ld__cols table{width:100%}.ap-ld__chart{padding:12px 4px 0}.ap-ld__svg{width:100%;height:150px;display:block}.ap-ld__chart-l{display:flex;gap:14px;font-size:11.5px;font-weight:800;color:#a08579;padding:0 0 4px}.ap-ld__chart-l i{display:inline-block;width:12px;height:8px;border-radius:2px;margin-right:5px;vertical-align:middle}'
			. '.ap-ld__tree td,.ap-ld__tree th{white-space:nowrap}.ap-ld__tree tr.has td:first-child{cursor:pointer}.ap-ld__tree tr.lv1 td:first-child{padding-left:28px}.ap-ld__tree tr.lv2 td:first-child{padding-left:50px}.ap-ld__tree tr.lv3 td:first-child{padding-left:72px}.ap-ld__tree tr.ch td{background:#fffaf7;font-size:12px}.ap-ld__tree tr.lv2 td,.ap-ld__tree tr.lv3 td{background:#fff5ef}.ap-ld__tg{display:inline-block;width:14px;color:#e0602c;font-weight:900;transition:transform .15s}tr.open>td .ap-ld__tg{transform:rotate(90deg)}'
			. '.ap-ld__modes{margin-left:auto}.ap-ld__sig{display:grid;gap:10px;padding:0 20px 16px}.ap-ld__s{display:grid;gap:3px;padding:12px 14px 12px 18px;border-radius:14px;background:#fdebe9;position:relative;font-size:13px}.ap-ld__s::before{content:"";position:absolute;left:0;top:12px;bottom:12px;width:4px;border-radius:0 4px 4px 0;background:#2469a8}.ap-ld__s.warn::before{background:#f8a41d}.ap-ld__s.bad::before{background:#d64545}.ap-ld__s.good::before{background:#3bb273}.ap-ld__s b{font-size:13.5px;color:#43302a}.ap-ld__s span{color:#6b5f58;font-weight:600}.ap-ld__s em{font-style:normal;font-weight:800;color:#e0602c}'
			. '@media(max-width:900px){.ap-ld__tree tr.lv1 td:first-child{padding-left:18px}.ap-ld__tree tr.lv2 td:first-child{padding-left:30px}.ap-ld__tree tr.lv3 td:first-child{padding-left:42px}}</style>';
	}
	public static function scripts() {
		return '<script>(function(){var t=document.querySelector(".ap-ld__tree");if(t){t.addEventListener("click",function(e){var tr=e.target.closest("tr.has");if(!tr)return;var id=tr.getAttribute("data-id"),open=tr.classList.toggle("open");'
			. 'function kids(p,show){t.querySelectorAll("tr[data-p=\\""+p+"\\"]").forEach(function(r){r.hidden=!show;if(!show&&r.classList.contains("open")){r.classList.remove("open");kids(r.getAttribute("data-id"),false)}})}kids(id,open)})}'
			. 'document.querySelectorAll(".ap-ld__tab").forEach(function(b){b.addEventListener("click",function(){document.querySelectorAll(".ap-ld__tab").forEach(function(x){x.classList.toggle("on",x===b)});var k=b.getAttribute("data-t");document.querySelectorAll(".ap-ld__bt").forEach(function(tb){tb.hidden=tb.getAttribute("data-t")!==k})})});})();</script>';
	}
}
