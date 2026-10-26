<?php
/**
 * ansa™ Промо 1.28.0 — Insights: вътрешен трафик, ботове, седмичен отчет по имейл, аларми, API за Looker Studio.
 *
 *  - Вътрешен трафик (т. 17): списък IP (точни, префикси „10.0.*“ / „2001:db8:“, CIDR v4) + имейли (probe@example.com) + логнатите админи +
 *    User-Agent на Probe → сесията получава internal=1 още при записа; отчетите ги изключват (чип „вкл. вътрешен трафик“). При запис на
 *    списъка старите сесии от тези IP се маркират назад (apply_internal_flags).
 *  - Ботове (т. 18): UA с bot/crawler/headless/… или празен UA не прави сесия изобщо.
 *  - Седмичен отчет (т. 28): крон на час (ansa_promo_hourly) → в избрания ден и час праща HTML имейл: KPI с Δ спрямо предишната седмица,
 *    фунията, топ 5 / дъно 5 кампании (≥ 30 сесии). „Изпрати сега“ от настройките.
 *  - Аларми (т. 29): на час — (а) 0 сесии за последните N часа, при положение че предишните 7 дни са давали средно ≥ M сесии на N часа (т.е.
 *    кампанията е спряла/страницата е паднала); (б) конверсията за последните 24 ч е паднала с ≥ X % спрямо предишните 7 дни при ≥ S сесии.
 *    Имейл + Slack webhook; всеки тип най-много веднъж на 6 часа.
 *  - API (т. 30): GET {портал}/?json=1&token=…&from=…&to=… → JSON (KPI, фуния, по UTM/кампания/ден) за Looker Studio / Sheets; токенът е отделен
 *    (64 hex), само за четене, лимит 60/мин/IP.
 *
 * @package AnsaPromo
 * @since 1.28.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Insights_Reports {

	const OPT    = 'ansa_promo_ins_reports';
	const OPT_TS = 'ansa_promo_ins_reports_ts'; /* last weekly / alert timestamps */
	const HOOK   = 'ansa_promo_hourly';
	const BOT_RE = '/bot|crawl|spider|slurp|headless|phantom|lighthouse|pingdom|gtmetrix|facebookexternalhit|wget\/|curl\/|python-requests|go-http-client|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|yandex|bingpreview|dataprovider|screaming frog/i';
	const ALERT_THROTTLE = 6 * HOUR_IN_SECONDS;
	const MIN_ROWS = 30; /* „малка извадка“ под толкова сесии */

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}
	public static function schedule() { if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::HOOK ); } }

	/* ── настройки ── */
	public static function defaults() {
		return array(
			'internal_ips' => '', 'internal_emails' => "probe@example.com",
			'weekly' => array( 'enabled' => false, 'to' => '', 'dow' => 1, 'hour' => 8 ),
			'alerts' => array( 'enabled' => false, 'to' => '', 'slack' => '', 'zero_hours' => 2, 'zero_min' => 5, 'drop_pct' => 40, 'min_sessions' => 50 ),
			'api_token' => '',
		);
	}
	public static function settings() {
		$d = self::defaults(); $s = get_option( self::OPT, array() ); $s = is_array( $s ) ? $s : array();
		$out = array_merge( $d, $s ); foreach ( array( 'weekly', 'alerts' ) as $k ) { $out[ $k ] = array_merge( $d[ $k ], is_array( $s[ $k ] ?? null ) ? $s[ $k ] : array() ); }
		$out['internal_ips'] = (string) $out['internal_ips']; $out['internal_emails'] = (string) $out['internal_emails']; $out['api_token'] = (string) $out['api_token'];
		$out['weekly']['enabled'] = ! empty( $out['weekly']['enabled'] ); $out['weekly']['dow'] = max( 0, min( 6, (int) $out['weekly']['dow'] ) ); $out['weekly']['hour'] = max( 0, min( 23, (int) $out['weekly']['hour'] ) );
		$out['alerts']['enabled'] = ! empty( $out['alerts']['enabled'] ); $out['alerts']['zero_hours'] = max( 1, min( 24, (int) $out['alerts']['zero_hours'] ) ); $out['alerts']['zero_min'] = max( 1, (int) $out['alerts']['zero_min'] );
		$out['alerts']['drop_pct'] = max( 5, min( 95, (int) $out['alerts']['drop_pct'] ) ); $out['alerts']['min_sessions'] = max( 10, (int) $out['alerts']['min_sessions'] );
		return $out;
	}
	/** Запис от 🧰 Настройки. Връща съобщение. */
	public static function save( $d ) {
		$s = self::settings();
		$s['internal_ips'] = self::norm_lines( (string) ( $d['internal_ips'] ?? '' ) ); $s['internal_emails'] = strtolower( self::norm_lines( (string) ( $d['internal_emails'] ?? '' ) ) );
		$s['weekly'] = array( 'enabled' => ! empty( $d['weekly_enabled'] ), 'to' => self::norm_emails( (string) ( $d['weekly_to'] ?? '' ) ), 'dow' => (int) ( $d['weekly_dow'] ?? 1 ), 'hour' => (int) ( $d['weekly_hour'] ?? 8 ) );
		$s['alerts'] = array( 'enabled' => ! empty( $d['alerts_enabled'] ), 'to' => self::norm_emails( (string) ( $d['alerts_to'] ?? '' ) ), 'slack' => esc_url_raw( (string) ( $d['alerts_slack'] ?? '' ) ), 'zero_hours' => (int) ( $d['alerts_zero_hours'] ?? 2 ), 'zero_min' => (int) ( $d['alerts_zero_min'] ?? 5 ), 'drop_pct' => (int) ( $d['alerts_drop_pct'] ?? 40 ), 'min_sessions' => (int) ( $d['alerts_min_sessions'] ?? 50 ) );
		update_option( self::OPT, $s, false );
		$n = self::apply_internal_flags();
		return 'Insights отчети: записано. Вътрешен трафик: ' . count( self::ip_list() ) . ' IP правила · ' . $n . ' стари сесии маркирани.';
	}
	private static function norm_lines( $t ) { $l = array(); foreach ( preg_split( '/[\r\n,;]+/', (string) $t ) as $x ) { $x = trim( wp_strip_all_tags( $x ) ); if ( '' !== $x ) { $l[] = $x; } } return implode( "\n", array_values( array_unique( $l ) ) ); }
	public static function norm_emails( $t ) { $l = array(); foreach ( preg_split( '/[\s,;]+/', (string) $t ) as $x ) { $x = sanitize_email( $x ); if ( is_email( $x ) ) { $l[] = strtolower( $x ); } } return implode( ', ', array_values( array_unique( $l ) ) ); }
	public static function emails( $csv ) { return array_values( array_filter( array_map( 'trim', explode( ',', (string) $csv ) ) ) ); }

	/* ── вътрешен трафик и ботове ── */
	public static function is_bot( $ua ) { $ua = trim( (string) $ua ); return '' === $ua || (bool) preg_match( self::BOT_RE, $ua ); }
	/** Правилата: [ [type => exact|prefix|cidr, v, bits] ]. */
	public static function ip_list( $text = null ) {
		$text = null === $text ? self::settings()['internal_ips'] : (string) $text; $out = array();
		foreach ( preg_split( '/[\r\n,;\s]+/', $text ) as $e ) {
			$e = trim( $e ); if ( '' === $e || '#' === $e[0] ) { continue; }
			if ( preg_match( '#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $e, $m ) && (int) $m[2] <= 32 ) { $out[] = array( 'cidr', ip2long( $m[1] ), (int) $m[2] ); continue; }
			if ( substr( $e, -1 ) === '*' || substr( $e, -1 ) === '.' || substr( $e, -1 ) === ':' ) { $out[] = array( 'prefix', strtolower( rtrim( $e, '*' ) ), 0 ); continue; }
			$out[] = array( 'exact', strtolower( $e ), 0 );
		}
		return $out;
	}
	public static function is_internal_ip( $ip, $list = null ) {
		$ip = strtolower( trim( (string) $ip ) ); if ( '' === $ip ) { return false; } $list = null === $list ? self::ip_list() : $list;
		foreach ( $list as $r ) {
			if ( 'exact' === $r[0] && $r[1] === $ip ) { return true; }
			if ( 'prefix' === $r[0] && '' !== $r[1] && 0 === strpos( $ip, $r[1] ) ) { return true; }
			if ( 'cidr' === $r[0] && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) { $mask = $r[2] ? -1 << ( 32 - $r[2] ) : 0; if ( ( ip2long( $ip ) & $mask ) === ( $r[1] & $mask ) ) { return true; } }
		}
		return false;
	}
	public static function is_internal_email( $email ) { $e = strtolower( trim( (string) $email ) ); if ( '' === $e ) { return false; } foreach ( preg_split( '/[\r\n,;\s]+/', self::settings()['internal_emails'] ) as $x ) { $x = strtolower( trim( $x ) ); if ( '' === $x ) { continue; } if ( $x === $e || ( '@' === $x[0] && substr( $e, -strlen( $x ) ) === $x ) ) { return true; } } return false; }
	/** Текущата заявка е вътрешна: IP от списъка, логнат админ на играта, или UA на Probe/проверка. */
	public static function is_internal_request() {
		if ( self::is_internal_ip( Leads::ip() ) ) { return true; }
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && current_user_can( Plugin::CAP ) ) { return true; }
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ); return false !== strpos( $ua, 'ansa™ Probe' ) || false !== strpos( $ua, 'ansa-check' );
	}
	/** Маркира назад сесиите от вътрешните IP и имейли. Връща колко са обновени. */
	public static function apply_internal_flags() {
		global $wpdb; $ts = Schema::table( 'sessions' ); $list = self::ip_list(); $n = 0;
		if ( $list ) {
			$ips = (array) $wpdb->get_col( "SELECT DISTINCT ip FROM $ts WHERE internal = 0 AND ip IS NOT NULL AND ip <> ''" ); $hit = array();
			foreach ( $ips as $ip ) { if ( self::is_internal_ip( $ip, $list ) ) { $hit[] = $ip; } }
			foreach ( array_chunk( $hit, 200 ) as $chunk ) { $in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) ); $n += (int) $wpdb->query( $wpdb->prepare( "UPDATE $ts SET internal = 1 WHERE ip IN ($in)", $chunk ) ); }
		}
		return $n;
	}

	/* ── API токен (Looker Studio / Sheets) ── */
	public static function api_token() { return self::settings()['api_token']; }
	public static function regenerate_api_token() { $s = self::settings(); $s['api_token'] = bin2hex( random_bytes( 32 ) ); update_option( self::OPT, $s, false ); return $s['api_token']; }
	public static function clear_api_token() { $s = self::settings(); $s['api_token'] = ''; update_option( self::OPT, $s, false ); }
	public static function api_token_ok( $given ) { $t = self::api_token(); $given = (string) $given; return '' !== $t && strlen( $given ) === strlen( $t ) && hash_equals( $t, $given ); }

	/* ── крон ── */
	public static function cron() { try { self::weekly_maybe(); } catch ( \Throwable $e ) {} try { self::alerts_check(); } catch ( \Throwable $e ) {} }
	private static function ts_get( $k ) { $t = get_option( self::OPT_TS, array() ); return is_array( $t ) ? (int) ( $t[ $k ] ?? 0 ) : 0; }
	private static function ts_set( $k, $v ) { $t = get_option( self::OPT_TS, array() ); $t = is_array( $t ) ? $t : array(); $t[ $k ] = (int) $v; update_option( self::OPT_TS, $t, false ); }

	/** Седмичният отчет е „дължим“: включен, има получатели, днес е денят, часът е стигнат и не е пращан от 6 дни. $now = WP timestamp (местно). */
	public static function weekly_due( $now = null, $s = null ) {
		$s = $s ?: self::settings(); $w = $s['weekly']; $now = null === $now ? (int) current_time( 'timestamp' ) : (int) $now;
		if ( ! $w['enabled'] || '' === $w['to'] ) { return false; }
		if ( (int) gmdate( 'w', $now ) !== (int) $w['dow'] || (int) gmdate( 'G', $now ) < (int) $w['hour'] ) { return false; }
		return $now - self::ts_get( 'weekly' ) > 6 * DAY_IN_SECONDS;
	}
	public static function weekly_maybe() { if ( self::weekly_due() ) { self::send_weekly(); } }
	/** Периодът на отчета: последните 7 пълни дни (вчера назад). */
	public static function weekly_range( $now = null ) { $now = null === $now ? (int) current_time( 'timestamp' ) : (int) $now; return Insights::range( gmdate( 'Y-m-d', $now - 7 * DAY_IN_SECONDS ), gmdate( 'Y-m-d', $now - DAY_IN_SECONDS ) ); }
	/** HTML на седмичния отчет (и за „Изпрати сега“). */
	public static function weekly_html( $rg = null ) {
		$rg = $rg ?: self::weekly_range(); $prg = Insights::prev_range( $rg );
		$os = Insights::orders_stats( $rg ); $r = Insights::sessions_report( 0, $rg, array( 'totals' => $os['by_id'] ) );
		$pos = Insights::orders_stats( $prg ); $pr = Insights::sessions_report( 0, $prg, array( 'totals' => $pos['by_id'] ) );
		$cur = Insights::dashboard_flat( Insights::dashboard_data( $r, $os, array( 'period' => Insights::period_label( $rg ) ) ) )['kpi']; $prev = Insights::dashboard_flat( Insights::dashboard_data( $pr, $pos ) )['kpi'];
		$st = 'font-family:Nunito,Arial,sans-serif;font-size:14px;color:#43302a'; $th = 'text-align:left;padding:6px 10px;font-size:11px;text-transform:uppercase;color:#a08579;border-bottom:2px solid #fdebe9'; $td = 'padding:7px 10px;border-bottom:1px solid #fdebe9';
		$row = function ( $label, $k ) use ( $cur, $prev, $td ) { $d = Insights::delta_text( $cur[ $k ] ?? '', $prev[ $k ] ?? '' ); return '<tr><td style="' . $td . '">' . esc_html( $label ) . '</td><td style="' . $td . ';text-align:right"><b>' . esc_html( (string) ( $cur[ $k ] ?? '—' ) ) . '</b></td><td style="' . $td . ';text-align:right;color:#a08579">' . esc_html( (string) ( $prev[ $k ] ?? '—' ) ) . '</td><td style="' . $td . ';text-align:right">' . esc_html( $d ) . '</td></tr>'; };
		$h = '<div style="' . $st . '"><h2 style="color:#e0602c;margin:0 0 4px">📈 Сезонът на ansa™ — седмичен отчет</h2><p style="margin:0 0 14px;color:#a08579">' . esc_html( Insights::period_label( $rg ) ) . ' · спрямо ' . esc_html( Insights::period_label( $prg ) ) . '</p>';
		$h .= '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;min-width:420px"><tr><th style="' . $th . '">показател</th><th style="' . $th . ';text-align:right">тази седмица</th><th style="' . $th . ';text-align:right">предишната</th><th style="' . $th . ';text-align:right">Δ</th></tr>';
		foreach ( array( 'sessions' => 'Сесии', 'visitors' => 'Посетители', 'gate' => 'Минали попъпа', 'box' => 'Избрали кутия', 'full' => 'Напълнили кутията', 'add' => 'Към плащане', 'created' => 'Сесии с поръчка', 'conv' => 'Конверсия сесия → поръчка', 'o_n' => 'Поръчки', 'o_delivered' => 'Взети', 'rev_real' => 'Реализиран оборот', 'rev_open' => 'Нереализиран оборот', 'avg' => 'Средна количка' ) as $k => $l ) { $h .= $row( $l, $k ); }
		$h .= '</table>';
		$camps = array(); foreach ( (array) $r['by_camp'] as $k => $v ) { if ( $v['n'] >= self::MIN_ROWS ) { $camps[ $k ] = $v; } }
		if ( $camps ) {
			uasort( $camps, function ( $a, $b ) { return ( $b['created'] / max( 1, $b['n'] ) ) <=> ( $a['created'] / max( 1, $a['n'] ) ); } );
			$ct = function ( $title, $rows ) use ( $th, $td ) { $h = '<h3 style="margin:18px 0 6px">' . $title . '</h3><table cellspacing="0" cellpadding="0" style="border-collapse:collapse;min-width:420px"><tr><th style="' . $th . '">кампания</th><th style="' . $th . ';text-align:right">сесии</th><th style="' . $th . ';text-align:right">поръчки</th><th style="' . $th . ';text-align:right">конверсия</th><th style="' . $th . ';text-align:right">оборот</th></tr>'; foreach ( $rows as $k => $v ) { $h .= '<tr><td style="' . $td . '">' . esc_html( $k ) . '</td><td style="' . $td . ';text-align:right">' . (int) $v['n'] . '</td><td style="' . $td . ';text-align:right">' . (int) $v['created'] . '</td><td style="' . $td . ';text-align:right"><b>' . esc_html( Insights::pct( $v['created'], $v['n'] ) ) . '</b></td><td style="' . $td . ';text-align:right">' . esc_html( Insights::money( $v['rev'] ?? 0 ) ) . '</td></tr>'; } return $h . '</table>'; };
			$h .= $ct( '🏆 Топ 5 кампании (по конверсия, ≥ ' . self::MIN_ROWS . ' сесии)', array_slice( $camps, 0, 5, true ) );
			if ( count( $camps ) > 5 ) { $h .= $ct( '🪫 Дъно 5 кампании', array_slice( array_reverse( $camps, true ), 0, 5, true ) ); }
		} else { $h .= '<p style="color:#a08579">Няма кампания с поне ' . self::MIN_ROWS . ' сесии за седмицата.</p>'; }
		if ( Insights_Portal::enabled() ) { $h .= '<p style="margin-top:18px"><a href="' . esc_url( Insights_Portal::url( array( 'view' => 'overview', 'from' => $rg['from'], 'to' => $rg['to'] ) ) ) . '" style="color:#e0602c;font-weight:bold">Отвори Insights за периода →</a></p>'; }
		return $h . '</div>';
	}
	/** Праща седмичния отчет. Връща текст за админа. */
	public static function send_weekly( $to = null ) {
		$s = self::settings(); $to = null === $to ? self::emails( $s['weekly']['to'] ) : (array) $to;
		if ( ! $to ) { return 'Седмичен отчет: няма получатели.'; }
		$rg = self::weekly_range(); $html = self::weekly_html( $rg );
		$ok = self::mail( $to, '📈 Сезонът на ansa™ — седмичен отчет ' . Insights::period_label( $rg ), $html );
		if ( $ok ) { self::ts_set( 'weekly', (int) current_time( 'timestamp' ) ); }
		return 'Седмичен отчет: ' . ( $ok ? 'изпратен до ' . implode( ', ', $to ) : 'wp_mail върна грешка' ) . ' (' . Insights::period_label( $rg ) . ').';
	}
	public static function mail( $to, $subject, $html ) { return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) ); }

	/* ── аларми ── */
	/** Какви аларми има сега: [ [type, text] ]. $now = unix (UTC) timestamp. Чиста функция — за тестове и Probe. */
	public static function alerts_evaluate( $now = null, $s = null ) {
		global $wpdb; $s = $s ?: self::settings(); $a = $s['alerts']; $now = null === $now ? time() : (int) $now; $ts = Schema::table( 'sessions' ); $out = array();
		$fmt = function ( $t ) { return gmdate( 'Y-m-d H:i:s', $t + ( (int) current_time( 'timestamp' ) - time() ) ); }; /* местно време на сайта, както е в таблицата */
		$count = function ( $from, $to ) use ( $wpdb, $ts, $fmt ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ts WHERE internal = 0 AND sid NOT LIKE %s AND started_at >= %s AND started_at < %s", Insights::ORDER_SID . '%', $fmt( $from ), $fmt( $to ) ) ); };
		$orders = function ( $from, $to ) use ( $wpdb, $ts, $fmt ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $ts WHERE internal = 0 AND sid NOT LIKE %s AND order_id > 0 AND started_at >= %s AND started_at < %s", Insights::ORDER_SID . '%', $fmt( $from ), $fmt( $to ) ) ); };
		/* (а) нула сесии */
		$zh = (int) $a['zero_hours'] * HOUR_IN_SECONDS; $recent = $count( $now - $zh, $now ); $base = $count( $now - 7 * DAY_IN_SECONDS - $zh, $now - $zh ); $per = $base / max( 1, ( 7 * DAY_IN_SECONDS ) / $zh );
		if ( 0 === $recent && $per >= (int) $a['zero_min'] ) { $out[] = array( 'zero', sprintf( '0 сесии за последните %d ч., а предишните 7 дни даваха средно %.1f сесии на %d ч. Спряла кампания или паднала страница?', (int) $a['zero_hours'], $per, (int) $a['zero_hours'] ) ); }
		/* (б) спад на конверсията */
		$n24 = $count( $now - DAY_IN_SECONDS, $now ); $o24 = $orders( $now - DAY_IN_SECONDS, $now ); $n7 = $count( $now - 8 * DAY_IN_SECONDS, $now - DAY_IN_SECONDS ); $o7 = $orders( $now - 8 * DAY_IN_SECONDS, $now - DAY_IN_SECONDS );
		if ( $n24 >= (int) $a['min_sessions'] && $n7 >= (int) $a['min_sessions'] && $o7 > 0 ) {
			$c24 = $o24 / $n24; $c7 = $o7 / $n7; $drop = $c7 > 0 ? 100 * ( 1 - $c24 / $c7 ) : 0;
			if ( $drop >= (int) $a['drop_pct'] ) { $out[] = array( 'drop', sprintf( 'Конверсията за последните 24 ч. е %s (%d поръчки от %d сесии) — с %.0f %% под предишните 7 дни (%s).', Insights::pct( $o24, $n24 ), $o24, $n24, $drop, Insights::pct( $o7, $n7 ) ) ); }
		}
		return $out;
	}
	public static function alerts_check( $now = null ) {
		$s = self::settings(); if ( ! $s['alerts']['enabled'] ) { return array(); } $now = null === $now ? time() : (int) $now;
		$sent = array();
		foreach ( self::alerts_evaluate( $now, $s ) as $al ) {
			if ( $now - self::ts_get( 'alert_' . $al[0] ) < self::ALERT_THROTTLE ) { continue; }
			self::notify( '⚠ Сезонът на ansa™: ' . ( 'zero' === $al[0] ? 'няма трафик' : 'спад на конверсията' ), $al[1], $s );
			self::ts_set( 'alert_' . $al[0], $now ); $sent[] = $al;
		}
		return $sent;
	}
	public static function notify( $subject, $text, $s = null ) {
		$s = $s ?: self::settings(); $ok = false;
		$to = self::emails( $s['alerts']['to'] ); if ( $to ) { $ok = self::mail( $to, $subject, '<p style="font-family:Arial,sans-serif;font-size:14px">' . esc_html( $text ) . '</p>' . ( Insights_Portal::enabled() ? '<p><a href="' . esc_url( Insights_Portal::url( array( 'view' => 'live' ) ) ) . '">Insights → На живо</a></p>' : '' ) ) || $ok; }
		if ( '' !== $s['alerts']['slack'] ) { $r = wp_remote_post( $s['alerts']['slack'], array( 'timeout' => 10, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'text' => $subject . "\n" . $text ) ) ) ); $ok = ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) || $ok; }
		return $ok;
	}

	/* ── Doctor ── */
	public static function status() {
		$s = self::settings(); $p = array();
		$p[] = 'вътрешни IP: ' . count( self::ip_list() ) . ' правила';
		$p[] = $s['weekly']['enabled'] && '' !== $s['weekly']['to'] ? 'седмичен отчет: ' . array( 'нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб' )[ $s['weekly']['dow'] ] . ' ' . $s['weekly']['hour'] . ':00 до ' . $s['weekly']['to'] . ( self::ts_get( 'weekly' ) ? ' · последно ' . wp_date( 'd.m H:i', self::ts_get( 'weekly' ) ) : ' · още не е пращан' ) : 'седмичен отчет: изключен';
		$p[] = $s['alerts']['enabled'] && ( '' !== $s['alerts']['to'] || '' !== $s['alerts']['slack'] ) ? 'аларми: включени (0 сесии за ' . $s['alerts']['zero_hours'] . ' ч · спад ≥ ' . $s['alerts']['drop_pct'] . ' %)' : 'аларми: изключени';
		$p[] = '' !== $s['api_token'] ? 'API: токен има' : 'API: без токен';
		$p[] = wp_next_scheduled( self::HOOK ) ? 'крон на час: насрочен' : 'крон на час: НЕ е насрочен';
		return array( implode( ' · ', $p ), wp_next_scheduled( self::HOOK ) ? 'ok' : 'warn' );
	}
}
