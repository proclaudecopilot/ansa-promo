<?php
/**
 * ansa™ Промо — 📣 атрибуция на ПОРЪЧКИТЕ по източник (1.56.0, човекът: „Да, искам точна атрибуция да имаме“ — колко поръчки са от имейл,
 * колко от Meta (вкл. retargeting за Черния петък), колко са нормални/органик).
 *
 * Дотук само сесиите на Сезона носеха произход; Черният петък, магазинът и поръчките по телефон — не. Тук произходът се пази на НИВО ПОРЪЧКА,
 * за всяка поръчка в WooCommerce, независимо откъде е направена:
 *
 *  1. Скрипт в <head> на всяка страница (до touch_script) пази две бисквитки за 30 дни: `ansa_src_first` (първото докосване — само веднъж)
 *     и `ansa_src_last` (последното — при всяко ново докосване). Докосване = адрес с UTM / fbclid / gclid / ttclid / msclkid / ap_* ИЛИ
 *     външен referrer ИЛИ влизане без referrer (директно). Пази се суровото: query string (700), referrer (191), кацане (120), време.
 *  2. При създаване на поръчка (класически чекаут, Store API, мрежа woocommerce_new_order за ANSA Checkout / wc_create_order) бисквитките
 *     се четат на сървъра и се пишат в метата на поръчката: `_ansa_src` (суровото), `_ansa_src_ch` / `_ansa_src_chf` (каналът — последно /
 *     първо докосване, по Source::classify — същата логика като за сесиите: Meta реклама → кампания → рекламна група → реклама, имейл, Google,
 *     попъп, вътрешен линк, друг сайт, директно), `_ansa_src_path` / `_ansa_src_pathf` (пътят), `_ansa_src_kind` (bf / season / shop / phone).
 *     Поръчка по телефон (контекст на оператор) е канал „по телефон“ — бисквитките на оператора не се броят.
 *  3. Екран 📈 Insights → 📣 Източници: за периода — поръчки и оборот по канал (последно или първо докосване), по вид (Черен петък / Сезонът /
 *     магазин / по телефон), Meta кампании → рекламни групи → реклами, имейл изпращания, продукти (utm_content); CSV по поръчка.
 *     Поръчки отпреди 1.56.0 нямат данни → „без данни“.
 *
 * @package AnsaPromo
 * @since 1.56.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Attribution {

	const C_FIRST = 'ansa_src_first';
	const C_LAST  = 'ansa_src_last';
	const DAYS    = 30;
	const META    = '_ansa_src';
	const M_CH    = '_ansa_src_ch';
	const M_CHF   = '_ansa_src_chf';
	const M_PATH  = '_ansa_src_path';
	const M_PATHF = '_ansa_src_pathf';
	const M_KIND  = '_ansa_src_kind';
	const KINDS   = array( 'bf' => '🖤 Черен петък', 'season' => '🎁 Сезонът', 'shop' => '🛍️ Магазин', 'phone' => '📞 По телефон' );
	const NODATA  = 'nodata';

	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'script' ), 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'on_create' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'on_create' ), 20, 2 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new' ), 20, 2 );
	}

	/* ── 1. бисквитките ── */
	public static function script() {
		if ( is_admin() || ( function_exists( 'is_feed' ) && is_feed() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		echo '<script id="ansa-promo-src" data-loading-method="immediate">(function(){try{'
			. 'var q=location.search.replace(/^\\?/,""),r=document.referrer||"",h="";try{h=r?new URL(r).hostname:""}catch(e){}'
			. 'var me=location.hostname.replace(/^www\\./,""),ext=h&&h.replace(/^www\\./,"")!==me,marks=/(^|&)(utm_|fbclid=|gclid=|ttclid=|msclkid=|ap_)/i.test(q);'
			. 'if(!(marks||ext||!r))return;'
			. 'var v=encodeURIComponent(JSON.stringify({q:q.slice(0,700),r:r.slice(0,191),l:location.pathname.slice(0,120),t:Math.round(Date.now()/1000)})),a=";path=/;max-age=' . ( self::DAYS * DAY_IN_SECONDS ) . ';SameSite=Lax"+(location.protocol==="https:"?";Secure":"");'
			. 'function has(n){return document.cookie.split(";").some(function(c){return c.replace(/^\\s+/,"").indexOf(n+"=")===0})}'
			. 'if(!has("' . self::C_FIRST . '"))document.cookie="' . self::C_FIRST . '="+v+a;'
			. 'if(marks||ext)document.cookie="' . self::C_LAST . '="+v+a;'
			. '}catch(e){}})();</script>' . "\n";
	}
	/** Докосването от бисквитка (или от подаден низ): [q, r, l, t] или null. */
	public static function touch( $raw ) {
		$raw = (string) $raw; if ( '' === $raw ) { return null; }
		$d = json_decode( $raw, true ); if ( ! is_array( $d ) ) { $d = json_decode( rawurldecode( $raw ), true ); } if ( ! is_array( $d ) ) { return null; }
		$q = substr( (string) ( $d['q'] ?? '' ), 0, 700 ); $r = substr( (string) ( $d['r'] ?? '' ), 0, 191 ); $l = substr( (string) ( $d['l'] ?? '' ), 0, 120 ); $t = (int) ( $d['t'] ?? 0 );
		if ( '' === $q && '' === $r && '' === $l ) { return null; }
		return array( 'q' => $q, 'r' => $r, 'l' => $l, 't' => $t );
	}
	public static function cookies() {
		$f = self::touch( isset( $_COOKIE[ self::C_FIRST ] ) ? wp_unslash( $_COOKIE[ self::C_FIRST ] ) : '' );
		$l = self::touch( isset( $_COOKIE[ self::C_LAST ] ) ? wp_unslash( $_COOKIE[ self::C_LAST ] ) : '' );
		return array( 'first' => $f ?: $l, 'last' => $l ?: $f );
	}
	/** Каналът на едно докосване по Source::classify: [k, l, path, product, …]; null без докосване. */
	public static function classify( $t ) { if ( ! $t ) { return null; } return Source::classify( (string) $t['q'], (string) $t['r'] ); }

	/* ── 2. поръчката ── */
	/** Видът на поръчката: phone (контекст на оператор / мета) · bf · season · shop. */
	public static function kind_of( $order, $op = null ) {
		$op = null === $op ? ( class_exists( __NAMESPACE__ . '\\Phone_Orders' ) ? Phone_Orders::op_ctx() : null ) : $op;
		if ( $op ) { return 'phone'; }
		if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
			if ( is_array( $order->get_meta( '_ansa_promo_phone' ) ) ) { return 'phone'; }
			$m = $order->get_meta( '_ansa_promo' ); if ( is_array( $m ) && ! empty( $m['phone_order'] ) ) { return 'phone'; }
			if ( is_array( $order->get_meta( '_ansa_promo_bf' ) ) ) { return 'bf' ; }
			if ( is_array( $m ) && $m ) { return 'season'; }
		}
		return 'shop';
	}
	/** Пише произхода в поръчката (без save). $c = бисквитките (или подадени за тест). Връща записаното или null, ако вече има. */
	public static function stamp( $order, $c = null, $op = null ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) { return null; }
		if ( '' !== (string) $order->get_meta( self::M_CH ) ) { return null; }
		$c = is_array( $c ) ? $c : self::cookies(); $kind = self::kind_of( $order, $op );
		$cl = self::classify( $c['last'] ?? null ); $cf = self::classify( $c['first'] ?? null );
		$ch = 'phone' === $kind ? 'phone' : ( $cl ? $cl['k'] : 'direct' ); $chf = 'phone' === $kind ? 'phone' : ( $cf ? $cf['k'] : 'direct' );
		$path = $cl ? $cl['path'] : array(); $pathf = $cf ? $cf['path'] : array();
		$data = array( 'first' => $c['first'] ?? null, 'last' => $c['last'] ?? null, 'kind' => $kind, 'ch' => $ch, 'chf' => $chf, 'path' => $path, 'pathf' => $pathf, 'product' => $cl ? (string) $cl['product'] : '', 'placement' => $cl ? (string) $cl['placement'] : '', 'ids' => $cl ? $cl['ids'] : array(), 'at' => current_time( 'mysql' ), 'ver' => ANSA_PROMO_VER );
		$order->update_meta_data( self::META, $data ); $order->update_meta_data( self::M_CH, $ch ); $order->update_meta_data( self::M_CHF, $chf );
		$order->update_meta_data( self::M_PATH, wp_json_encode( $path, JSON_UNESCAPED_UNICODE ) ); $order->update_meta_data( self::M_PATHF, wp_json_encode( $pathf, JSON_UNESCAPED_UNICODE ) ); $order->update_meta_data( self::M_KIND, $kind );
		return $data;
	}
	public static function on_create( $order, $data = null ) { try { self::stamp( $order ); } catch ( \Throwable $e ) {} }
	/** Мрежата: поръчка, създадена извън чекаута (ANSA Checkout / wc_create_order) — при първия запис. */
	public static function on_new( $order_id, $order = null ) {
		$order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null ); if ( ! $order ) { return; }
		if ( ( function_exists( 'is_admin' ) && is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) ) { return; } /* ръчна поръчка от админа — няма клиентски бисквитки */
		try { if ( self::stamp( $order ) ) { $order->save(); } } catch ( \Throwable $e ) {}
	}

	/* ── 3. отчетът ── */
	/** Поръчките за периода (по дата на създаване, местно) с произхода: [[id, date, status, total, kind, ch, path, chf, pathf, lost]]. HPOS с 2 заявки; резерва wc_get_orders. */
	public static function orders( $rg, $limit = 5000 ) {
		global $wpdb; $out = array(); $bad = array( 'trash', 'auto-draft', 'checkout-draft', 'wc-checkout-draft' );
		$from = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $rg['from'] . ' 00:00:00' ) : $rg['from'] . ' 00:00:00'; $to = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $rg['to'] . ' 23:59:59' ) : $rg['to'] . ' 23:59:59';
		$hpos = false; try { $hpos = isset( $wpdb ) && is_object( $wpdb ) && class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(); } catch ( \Throwable $e ) {}
		$pre = isset( $wpdb ) && is_object( $wpdb ) ? $wpdb->prefix : 'wp_'; $t = $pre . 'wc_orders'; $tm = $pre . 'wc_orders_meta';
		if ( $hpos && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			$nb = implode( ',', array_fill( 0, count( $bad ), '%s' ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, status, total_amount, date_created_gmt FROM {$t} WHERE type = 'shop_order' AND status NOT IN ($nb) AND date_created_gmt >= %s AND date_created_gmt <= %s ORDER BY id ASC LIMIT %d", array_merge( $bad, array( $from, $to, (int) $limit ) ) ), ARRAY_A );
			if ( ! $rows ) { return $out; } $ids = array_map( 'intval', array_column( $rows, 'id' ) ); $meta = array();
			foreach ( array_chunk( $ids, 500 ) as $chunk ) { $in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) ); foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT order_id, meta_key, meta_value FROM {$tm} WHERE order_id IN ($in) AND meta_key IN ('_ansa_src_ch','_ansa_src_chf','_ansa_src_path','_ansa_src_pathf','_ansa_src_kind','_ansa_promo_bf','_ansa_promo','_ansa_promo_phone','_ansa_promo_operator')", $chunk ), ARRAY_A ) as $m ) { $meta[ (int) $m['order_id'] ][ $m['meta_key'] ] = (string) $m['meta_value']; } }
			foreach ( $rows as $r ) { $id = (int) $r['id']; $m = $meta[ $id ] ?? array(); $st = preg_replace( '/^wc-/', '', (string) $r['status'] );
				$out[] = self::row( $id, function_exists( 'get_date_from_gmt' ) ? get_date_from_gmt( (string) $r['date_created_gmt'] ) : (string) $r['date_created_gmt'], $st, (float) $r['total_amount'], $m ); }
			return $out;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) { return $out; }
		try { $os = (array) wc_get_orders( array( 'limit' => (int) $limit, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'objects', 'type' => 'shop_order', 'date_created' => strtotime( $rg['from'] . ' 00:00:00' ) . '...' . strtotime( $rg['to'] . ' 23:59:59' ) ) ); } catch ( \Throwable $e ) { $os = array(); }
		foreach ( $os as $o ) { if ( ! is_object( $o ) || ! method_exists( $o, 'get_meta' ) ) { continue; } $st = (string) $o->get_status(); if ( in_array( $st, $bad, true ) ) { continue; }
			$m = array(); foreach ( array( self::M_CH, self::M_CHF, self::M_PATH, self::M_PATHF, self::M_KIND ) as $k ) { $m[ $k ] = (string) $o->get_meta( $k ); } foreach ( array( '_ansa_promo_bf', '_ansa_promo', '_ansa_promo_phone' ) as $k ) { $v = $o->get_meta( $k ); if ( is_array( $v ) && $v ) { $m[ $k ] = '1'; } }
			$d = $o->get_date_created(); $out[] = self::row( (int) $o->get_id(), $d ? $d->date( 'Y-m-d H:i:s' ) : '', $st, (float) $o->get_total(), $m ); }
		return $out;
	}
	private static function row( $id, $date, $st, $total, $m ) {
		$kind = (string) ( $m[ self::M_KIND ] ?? '' ); if ( '' === $kind ) { $kind = ! empty( $m['_ansa_promo_phone'] ) ? 'phone' : ( ! empty( $m['_ansa_promo_bf'] ) ? 'bf' : ( ! empty( $m['_ansa_promo'] ) ? 'season' : 'shop' ) ); }
		$ch = (string) ( $m[ self::M_CH ] ?? '' ); $chf = (string) ( $m[ self::M_CHF ] ?? '' ); $nodata = '' === $ch;
		if ( $nodata && 'phone' === $kind ) { $ch = 'phone'; $chf = 'phone'; $nodata = false; }
		$p = json_decode( (string) ( $m[ self::M_PATH ] ?? '' ), true ); $pf = json_decode( (string) ( $m[ self::M_PATHF ] ?? '' ), true );
		return array( 'id' => $id, 'date' => $date, 'status' => $st, 'total' => $total, 'kind' => $kind, 'ch' => $nodata ? self::NODATA : $ch, 'path' => is_array( $p ) ? $p : array(), 'chf' => $nodata ? self::NODATA : ( '' !== $chf ? $chf : $ch ), 'pathf' => is_array( $pf ) ? $pf : array(), 'lost' => in_array( $st, Insights::LOST_STATUSES, true ) );
	}
	/** Обобщение: by_ch (канал → n, total, lost, kinds), by_kind, meta (кампания → група → реклама), email (изпращане → линк), products, n, total. $touch = last | first. */
	public static function report( $rows, $touch = 'last' ) {
		$z = function () { return array( 'n' => 0, 'total' => 0.0, 'lost' => 0, 'kinds' => array( 'bf' => 0, 'season' => 0, 'shop' => 0, 'phone' => 0 ) ); };
		$out = array( 'n' => 0, 'total' => 0.0, 'lost' => 0, 'nodata' => 0, 'by_ch' => array(), 'by_kind' => array(), 'meta' => array(), 'email' => array(), 'other' => array(), 'touch' => $touch );
		foreach ( Source::ORDER as $k => $l ) { $out['by_ch'][ $k ] = $z(); } $out['by_ch'][ self::NODATA ] = $z(); foreach ( self::KINDS as $k => $l ) { $out['by_kind'][ $k ] = $z(); }
		foreach ( (array) $rows as $r ) {
			$ch = 'first' === $touch ? $r['chf'] : $r['ch']; $path = 'first' === $touch ? $r['pathf'] : $r['path']; if ( ! isset( $out['by_ch'][ $ch ] ) ) { $ch = 'direct'; }
			$out['n']++; $out['total'] += $r['total']; if ( $r['lost'] ) { $out['lost']++; } if ( self::NODATA === $ch ) { $out['nodata']++; }
			$b = &$out['by_ch'][ $ch ]; $b['n']++; $b['total'] += $r['total']; if ( $r['lost'] ) { $b['lost']++; } if ( isset( $b['kinds'][ $r['kind'] ] ) ) { $b['kinds'][ $r['kind'] ]++; } unset( $b );
			$k = &$out['by_kind'][ $r['kind'] ]; $k['n']++; $k['total'] += $r['total']; if ( $r['lost'] ) { $k['lost']++; } unset( $k );
			$grp = 'meta' === $ch ? 'meta' : ( 'email' === $ch ? 'email' : ( in_array( $ch, array( 'google_paid', 'google_org', 'popup', 'internal', 'meta_org', 'other_utm', 'other_ref' ), true ) ? 'other' : '' ) );
			if ( '' !== $grp ) { $key = implode( ' → ', array_slice( $path, 0, 3 ) ) ?: '—'; if ( 'other' === $grp ) { $key = Source::label( $ch ) . ( $key !== '—' ? ' · ' . $key : '' ); } if ( ! isset( $out[ $grp ][ $key ] ) ) { $out[ $grp ][ $key ] = array( 'n' => 0, 'total' => 0.0, 'lost' => 0 ); } $out[ $grp ][ $key ]['n']++; $out[ $grp ][ $key ]['total'] += $r['total']; if ( $r['lost'] ) { $out[ $grp ][ $key ]['lost']++; } }
		}
		foreach ( array( 'meta', 'email', 'other' ) as $g ) { uasort( $out[ $g ], function ( $a, $b ) { return $b['n'] <=> $a['n'] ?: $b['total'] <=> $a['total']; } ); }
		return $out;
	}
	/** Екранът 📣 Източници. */
	public static function html() {
		$rg = Insights::period(); $touch = 'first' === ( $_GET['touch'] ?? '' ) ? 'first' : 'last';
		ob_start(); Insights::export_form_open( 'attrib' ); echo '<input type="hidden" name="from" value="' . esc_attr( $rg['from'] ) . '"><input type="hidden" name="to" value="' . esc_attr( $rg['to'] ) . '"><button class="button" title="всяка поръчка за периода с канала, пътя и вида">⬇ CSV поръчки с източник</button></form>'; $act = ob_get_clean();
		$h = Insights::period_bar( $act );
		$rows = self::orders( $rg ); $r = self::report( $rows, $touch ); $money = array( 'AnsaPromo\\Insights', 'money' );
		$h .= '<div class="ap-actions ap-fbar" style="padding:0 0 12px"><span class="ap-fbar__l">докосване</span><div class="ap-chips"><a class="ap-chip' . ( 'last' === $touch ? ' on' : '' ) . '" href="' . esc_url( Insights::url( array( 'touch' => null ) ) ) . '">последно (кое е довело до поръчката)</a><a class="ap-chip' . ( 'first' === $touch ? ' on' : '' ) . '" href="' . esc_url( Insights::url( array( 'touch' => 'first' ) ) ) . '">първо (откъде е дошъл човекът)</a><small>бисквитка 30 дни на всяка страница на сайта; имейлът и Meta носят UTM/fbclid, останалото е referrer или директно</small></div></div>';
		$tiles = Insights::tile( 'поръчки', $r['n'], $money( $r['total'] ) . ' · ' . $r['lost'] . ' отказани/неуспешни', '', '', true );
		foreach ( array( 'email' => 'ok', 'meta' => 'info', 'direct' => '', self::NODATA => 'muted' ) as $k => $tone ) { $b = $r['by_ch'][ $k ]; $tiles .= Insights::tile( self::NODATA === $k ? 'без данни' : Source::label( $k ), $b['n'], $money( $b['total'] ) . ' · ' . Insights::pct( $b['n'], $r['n'] ) . ( self::NODATA === $k ? ' · поръчки отпреди 1.56.0 или без бисквитка' : '' ), $tone ); }
		foreach ( self::KINDS as $k => $l ) { $b = $r['by_kind'][ $k ]; $tiles .= Insights::tile( $l, $b['n'], $money( $b['total'] ) . ' · ' . Insights::pct( $b['n'], $r['n'] ), 'bf' === $k ? 'info' : '' ); }
		$h .= '<div class="ap-dash">' . Insights::group_html( '📣 Поръчки по източник · ' . Insights::period_label( $rg ) . ' · ' . ( 'first' === $touch ? 'първо' : 'последно' ) . ' докосване', $tiles, 'Всички поръчки в WooCommerce за периода (без кошче/чернови), по дата на създаване. „Нормални“ = магазин + директно/органик. По телефон = от Insights (операторът), не по бисквитките на оператора.' ) . '</div>';
		/* каналите × видовете */
		$h .= '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">Канали</span></div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>канал</th><th>поръчки</th><th>дял</th><th>оборот</th><th>отказани</th><th>🖤 Черен петък</th><th>🎁 Сезонът</th><th>🛍️ магазин</th><th>📞 телефон</th></tr></thead><tbody>';
		$chs = $r['by_ch']; uasort( $chs, function ( $a, $b ) { return $b['n'] <=> $a['n']; } );
		foreach ( $chs as $k => $b ) { if ( ! $b['n'] ) { continue; } $h .= '<tr><td><b>' . esc_html( self::NODATA === $k ? 'без данни (преди 1.56.0)' : Source::label( $k ) ) . '</b></td><td><b>' . (int) $b['n'] . '</b></td><td>' . esc_html( Insights::pct( $b['n'], $r['n'] ) ) . '</td><td>' . esc_html( $money( $b['total'] ) ) . '</td><td>' . (int) $b['lost'] . '</td><td>' . (int) $b['kinds']['bf'] . '</td><td>' . (int) $b['kinds']['season'] . '</td><td>' . (int) $b['kinds']['shop'] . '</td><td>' . (int) $b['kinds']['phone'] . '</td></tr>'; }
		if ( ! $r['n'] ) { $h .= '<tr><td colspan="9"><small>няма поръчки за периода</small></td></tr>'; }
		$h .= '</tbody></table></div></div>';
		foreach ( array( 'meta' => array( '📣 Meta реклами · кампания → рекламна група → реклама', 'от utm_campaign / campaign_id, adset_name / adset_id, ad_name / ad_id в адреса на рекламата (препоръчаните параметри са в README); retargeting-ът за Черния петък излиза като отделна кампания' ), 'email' => array( '✉️ Имейли · изпращане → линк', 'от utm_campaign / utm_content на линка в имейла (FunnelKit)' ), 'other' => array( '🔗 Останалите канали', 'Google, попъп в сайта, вътрешни линкове, Facebook/Instagram без реклама, други сайтове' ) ) as $g => $tt ) {
			$h .= '<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">' . $tt[0] . '</span></div><div class="ap-sec__hint">' . esc_html( $tt[1] ) . '</div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>' . ( 'meta' === $g ? 'кампания → група → реклама' : ( 'email' === $g ? 'изпращане → линк' : 'канал · път' ) ) . '</th><th>поръчки</th><th>оборот</th><th>отказани</th></tr></thead><tbody>';
			foreach ( array_slice( $r[ $g ], 0, 60, true ) as $key => $b ) { $h .= '<tr><td>' . esc_html( $key ) . '</td><td><b>' . (int) $b['n'] . '</b></td><td>' . esc_html( $money( $b['total'] ) ) . '</td><td>' . (int) $b['lost'] . '</td></tr>'; }
			if ( ! $r[ $g ] ) { $h .= '<tr><td colspan="4"><small>няма</small></td></tr>'; }
			$h .= '</tbody></table></div></div>';
		}
		return $h;
	}
	/** CSV по поръчка. */
	public static function csv( $rg ) {
		$out = fopen( 'php://temp', 'w+' ); fputcsv( $out, array( 'order_id', 'date', 'status', 'total', 'kind', 'channel_last', 'path_last', 'channel_first', 'path_first' ), ';' );
		foreach ( self::orders( $rg ) as $r ) { fputcsv( $out, array( $r['id'], $r['date'], $r['status'], number_format( $r['total'], 2, '.', '' ), self::KINDS[ $r['kind'] ] ?? $r['kind'], self::NODATA === $r['ch'] ? 'без данни' : Source::label( $r['ch'] ), implode( ' → ', $r['path'] ), self::NODATA === $r['chf'] ? 'без данни' : Source::label( $r['chf'] ), implode( ' → ', $r['pathf'] ) ), ';' ); }
		rewind( $out ); return "\xEF\xBB\xBF" . stream_get_contents( $out );
	}
}
