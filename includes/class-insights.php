<?php
/**
 * ansa™ Промо — Insights (Фаза 5): фунията по стъпки, кутии и UTM.
 *
 * Без суров лог — само дневен rollup в {prefix}ansa_promo_stats (day, step, box, utm, n). Клиентските стъпки идват през
 * ajax beacon `ansa_promo_beacon` (nonce + rate limit; promo.js праща всяка стъпка веднъж на сесия): view → gate → box → full →
 * order → add. Сървърните: created (поръчка), paid (билет издаден), activated (портал). Админ: таб 📈 Insights — фуния по
 * кутия и по UTM за период; CSV. Pixel Manager/GA4/Metorik не се дублират — това е само фунията на играта.
 *
 * @package AnsaPromo
 * @since 1.3.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Insights {

	const STEPS   = array( 'view', 'gate', 'box', 'full', 'order', 'add', 'created', 'paid', 'activated' );
	const CLIENT  = array( 'view', 'gate', 'box', 'full', 'order', 'add' );
	const LABELS  = array( 'view' => 'Видели страницата', 'gate' => 'Минали попъпа', 'box' => 'Избрали кутия', 'full' => 'Напълнили кутията', 'order' => 'Екран „Поръчка“', 'add' => 'Към плащане', 'created' => 'Поръчка', 'paid' => 'Платена (билет)', 'activated' => 'Активиран билет' );

	public static function register() {
		add_action( 'wp_ajax_ansa_promo_beacon', array( __CLASS__, 'ajax_beacon' ) );
		add_action( 'wp_ajax_nopriv_ansa_promo_beacon', array( __CLASS__, 'ajax_beacon' ) );
		add_action( 'ansa_promo_order_created', array( __CLASS__, 'on_order' ), 10, 2 );
		add_action( 'ansa_promo_ticket_issued', array( __CLASS__, 'on_ticket' ), 10, 2 );
		add_action( 'ansa_promo_ticket_activated', array( __CLASS__, 'on_activated' ), 10, 2 );
	}

	/** +1 за (днес, step, box, utm). Портативно (UPDATE → INSERT), без ON DUPLICATE KEY. */
	public static function hit( $step, $box = '', $utm = '' ) {
		global $wpdb;
		if ( ! in_array( $step, self::STEPS, true ) ) { return false; }
		$box = substr( preg_replace( '/[^a-z]/', '', (string) $box ), 0, 8 ); $utm = substr( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $utm ), 0, 64 );
		$t = Schema::table( 'stats' ); $day = current_time( 'Y-m-d' );
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $t SET n = n + 1 WHERE day = %s AND step = %s AND box = %s AND utm = %s", $day, $step, $box, $utm ) );
		if ( ! $n ) {
			$ok = $wpdb->insert( $t, array( 'day' => $day, 'step' => $step, 'box' => $box, 'utm' => $utm, 'n' => 1 ), array( '%s', '%s', '%s', '%s', '%d' ) );
			if ( ! $ok ) { $wpdb->query( $wpdb->prepare( "UPDATE $t SET n = n + 1 WHERE day = %s AND step = %s AND box = %s AND utm = %s", $day, $step, $box, $utm ) ); }
		}
		return true;
	}

	public static function ajax_beacon() {
		if ( ! check_ajax_referer( Frontend::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'nonce' ), 403 ); }
		if ( Leads::rate_limited( 'beacon', 60 ) ) { wp_send_json_error( array( 'code' => 'rate' ), 429 ); }
		$step = sanitize_key( (string) ( $_POST['step'] ?? '' ) );
		if ( ! in_array( $step, self::CLIENT, true ) ) { wp_send_json_error( array( 'code' => 'step' ), 400 ); }
		if ( ! empty( $_POST['draft'] ) || ! empty( $_POST['editor'] ) ) { wp_send_json_success( array( 'skipped' => true ) ); } /* черновата/редакторът не броят */
		self::hit( $step, (string) ( $_POST['box'] ?? '' ), (string) ( $_POST['utm'] ?? '' ) );
		wp_send_json_success( array( 'ok' => true ) );
	}
	public static function on_order( $order, $m ) { if ( empty( $m['draft'] ) ) { self::hit( 'created', (string) ( $m['box'] ?? '' ), (string) ( $m['utm'] ?? '' ) ); } }
	public static function on_ticket( $row, $order ) { $m = (array) $order->get_meta( '_ansa_promo' ); if ( empty( $m['draft'] ) ) { self::hit( 'paid', (string) ( $row['box'] ?? '' ), (string) ( $m['utm'] ?? '' ) ); } }
	public static function on_activated( $row, $user ) { $o = wc_get_order( (int) $row['order_id'] ); $m = $o ? (array) $o->get_meta( '_ansa_promo' ) : array(); self::hit( 'activated', (string) ( $row['box'] ?? '' ), (string) ( $m['utm'] ?? '' ) ); }

	/** Сумите за период: ['by_step' => step => n, 'by_box' => box => [step => n], 'by_utm' => utm => [step => n], 'days' => day => [step => n]] */
	public static function report( $days = 30 ) {
		global $wpdb; $t = Schema::table( 'stats' );
		$where = $days > 0 ? $wpdb->prepare( 'WHERE day >= %s', gmdate( 'Y-m-d', strtotime( '-' . ( (int) $days - 1 ) . ' days', current_time( 'timestamp' ) ) ) ) : '';
		$rows = $wpdb->get_results( "SELECT day, step, box, utm, n FROM $t $where", ARRAY_A );
		$out = array( 'by_step' => array_fill_keys( self::STEPS, 0 ), 'by_box' => array(), 'by_utm' => array(), 'days' => array(), 'rows' => count( (array) $rows ) );
		foreach ( (array) $rows as $r ) {
			$n = (int) $r['n']; $out['by_step'][ $r['step'] ] = ( $out['by_step'][ $r['step'] ] ?? 0 ) + $n;
			$b = $r['box'] ?: '—'; $u = $r['utm'] ?: '—';
			$out['by_box'][ $b ][ $r['step'] ] = ( $out['by_box'][ $b ][ $r['step'] ] ?? 0 ) + $n;
			$out['by_utm'][ $u ][ $r['step'] ] = ( $out['by_utm'][ $u ][ $r['step'] ] ?? 0 ) + $n;
			$out['days'][ $r['day'] ][ $r['step'] ] = ( $out['days'][ $r['day'] ][ $r['step'] ] ?? 0 ) + $n;
		}
		ksort( $out['days'] ); uasort( $out['by_utm'], function ( $a, $b ) { return ( $b['view'] ?? 0 ) <=> ( $a['view'] ?? 0 ); } );
		return $out;
	}
	public static function csv( $days = 30 ) {
		global $wpdb; $t = Schema::table( 'stats' );
		$where = $days > 0 ? $wpdb->prepare( 'WHERE day >= %s', gmdate( 'Y-m-d', strtotime( '-' . ( (int) $days - 1 ) . ' days', current_time( 'timestamp' ) ) ) ) : '';
		$rows = $wpdb->get_results( "SELECT day, step, box, utm, n FROM $t $where ORDER BY day, step, box, utm", ARRAY_A );
		$fh = fopen( 'php://temp', 'w+' ); fputs( $fh, "\xEF\xBB\xBF" ); fputcsv( $fh, array( 'day', 'step', 'box', 'utm', 'n' ), ';' );
		foreach ( (array) $rows as $r ) { fputcsv( $fh, $r, ';' ); }
		rewind( $fh ); $csv = stream_get_contents( $fh ); fclose( $fh ); return $csv;
	}
	public static function pct( $a, $b ) { return $b > 0 ? number_format( 100 * $a / $b, 1, ',', '' ) . '%' : '—'; }

	/** HTML за таб 📈 Insights. */
	public static function render( $days ) {
		$r = self::report( $days ); $cfg = Config::get_published(); $boxes = array(); foreach ( $cfg['boxes'] as $b ) { $boxes[ $b['id'] ] = $b['ic'] . ' ' . $b['name']; }
		$steps = self::STEPS; $v = $r['by_step']['view'];
		echo '<p style="margin-top:14px">Период: '; foreach ( array( 7 => '7 дни', 30 => '30 дни', 90 => '90 дни', 0 => 'всичко' ) as $d => $l ) { echo '<a href="' . esc_url( Admin::url( array( 'tab' => 'insights', 'days' => $d ) ) ) . '"' . ( $d === (int) $days ? ' style="font-weight:700"' : '' ) . '>' . esc_html( $l ) . '</a> '; }
		echo ' · ' . (int) $r['rows'] . ' реда в rollup-а &nbsp; '; Admin::form_open_public( 'export_stats', 'insights' ); echo '<input type="hidden" name="days" value="' . (int) $days . '"><button class="button">⬇ CSV</button></form></p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>стъпка</th><th>общо</th><th>% от видели</th>'; foreach ( $boxes as $id => $l ) { echo '<th>' . esc_html( $l ) . '</th>'; } echo '<th>без кутия</th></tr></thead><tbody>';
		foreach ( $steps as $s ) {
			echo '<tr><td>' . esc_html( self::LABELS[ $s ] ) . ' <small style="color:#787c82">' . esc_html( $s ) . '</small></td><td><b>' . (int) $r['by_step'][ $s ] . '</b></td><td>' . esc_html( self::pct( $r['by_step'][ $s ], $v ) ) . '</td>';
			foreach ( $boxes as $id => $l ) { echo '<td>' . (int) ( $r['by_box'][ $id ][ $s ] ?? 0 ) . '</td>'; }
			echo '<td>' . (int) ( $r['by_box']['—'][ $s ] ?? 0 ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h3 style="margin-top:18px">По UTM (кацане)</h3><table class="widefat striped" style="max-width:1100px"><thead><tr><th>UTM</th>'; foreach ( array( 'view', 'gate', 'box', 'full', 'add', 'created', 'paid' ) as $s ) { echo '<th>' . esc_html( $s ) . '</th>'; } echo '<th>конверсия (paid/view)</th></tr></thead><tbody>';
		foreach ( $r['by_utm'] as $u => $st ) { echo '<tr><td><code>' . esc_html( $u ) . '</code></td>'; foreach ( array( 'view', 'gate', 'box', 'full', 'add', 'created', 'paid' ) as $s ) { echo '<td>' . (int) ( $st[ $s ] ?? 0 ) . '</td>'; } echo '<td>' . esc_html( self::pct( $st['paid'] ?? 0, $st['view'] ?? 0 ) ) . '</td></tr>'; }
		if ( ! $r['by_utm'] ) { echo '<tr><td colspan="9">Още няма данни — стъпките се броят, когато играта е публикувана (черновата и редакторът не броят).</td></tr>'; }
		echo '</tbody></table>';
		echo '<h3 style="margin-top:18px">По дни</h3><table class="widefat striped" style="max-width:900px"><thead><tr><th>ден</th>'; foreach ( array( 'view', 'box', 'full', 'add', 'created', 'paid' ) as $s ) { echo '<th>' . esc_html( $s ) . '</th>'; } echo '</tr></thead><tbody>';
		foreach ( array_reverse( $r['days'], true ) as $d => $st ) { echo '<tr><td>' . esc_html( $d ) . '</td>'; foreach ( array( 'view', 'box', 'full', 'add', 'created', 'paid' ) as $s ) { echo '<td>' . (int) ( $st[ $s ] ?? 0 ) . '</td>'; } echo '</tr>'; }
		echo '</tbody></table>';
	}
}
