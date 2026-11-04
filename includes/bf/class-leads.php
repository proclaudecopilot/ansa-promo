<?php
/**
 * ansa™ Промо — Черен петък 2026: лийдове (имейлът от студената страница и от партньорската), ваучерите, FunnelKit.
 *
 * Същата таблица {prefix}ansa_promo_leads (DB ver 10: promo, kind, bonus, ref_code, voucher, voucher_exp, voucher_order_id).
 *  - kind=cold: студеният трафик — ваучер {prefix}{сума}-XXXX в портфейла за N минути (Cart::fees го приспада в ЦЯЛАТА количка, еднократно).
 *  - kind=aff: партньорски линк — подаръкът по избор (bonus v5 | mask) + кодът на партньора; важи в публичната промоция.
 * FunnelKit: Lead_Sync::sync с нашия лист и таг по вид (Config leads); при поръчка таговете на лийда падат и поръчката получава orders.fka_tag.
 *
 * @package AnsaPromo
 * @since 1.45.0
 */
namespace AnsaPromo\BF;

use AnsaPromo\Insights;
use AnsaPromo\Lead_Sync;
use AnsaPromo\Merge;
use AnsaPromo\Schema;
use AnsaPromo\Leads as SeasonLeads;

defined( 'ABSPATH' ) || exit;

final class Leads {

	const PROMO = 'bf';

	public static function register() {
		add_action( 'wp_ajax_ansa_bf_lead', array( __CLASS__, 'ajax_lead' ) );
		add_action( 'wp_ajax_nopriv_ansa_bf_lead', array( __CLASS__, 'ajax_lead' ) );
		foreach ( array( 'on-hold', 'processing', 'completed' ) as $st ) { add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'on_order_id' ), 27 ); }
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_order_id' ), 27 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_terms' ), 22 );
		add_action( 'ansa_promo_bf_published', array( __CLASS__, 'ensure_terms' ), 22 );
	}

	/** Конфигът за Lead_Sync (той чете $cfg['leads']): листът + тагът по вид. */
	public static function fka_cfg( $cfg, $kind ) { $l = $cfg['leads']; return array( 'leads' => array( 'fka' => ! empty( $l['fka'] ), 'list' => (string) $l['list'], 'tag' => (string) ( 'aff' === $kind ? $l['tag_aff'] : $l['tag_cold'] ), 'consent_only' => ! empty( $l['consent_only'] ), 'untag_on_order' => ! empty( $l['untag_on_order'] ) ) ); }
	public static function ensure_terms() {
		if ( ! Lead_Sync::available() ) { return false; }
		$names = array();
		foreach ( array( Config::get_draft(), Config::get_published() ) as $c ) { if ( empty( $c['leads']['fka'] ) ) { continue; } foreach ( array( 'list' => 2, 'tag_cold' => 1, 'tag_aff' => 1 ) as $k => $type ) { $n = (string) $c['leads'][ $k ]; if ( '' !== $n ) { $names[ $type . '|' . $n ] = array( $type, $n ); } } $ot = (string) ( $c['orders']['fka_tag'] ?? '' ); if ( '' !== $ot ) { $names[ '1|' . $ot ] = array( 1, $ot ); } }
		if ( ! $names ) { return true; }
		$key = md5( implode( '|', array_keys( $names ) ) ); if ( get_option( 'ansa_promo_bf_fka_ensured' ) === $key ) { return true; }
		$all = true; foreach ( $names as $n ) { if ( Merge::term_id( $n[1], $n[0], true ) <= 0 ) { $all = false; } }
		if ( $all ) { update_option( 'ansa_promo_bf_fka_ensured', $key, false ); }
		return $all;
	}

	/* ── ajax: записване ── */
	public static function ajax_lead() {
		if ( ! check_ajax_referer( Page::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'nonce' ), 403 ); }
		if ( SeasonLeads::rate_limited( 'bf_lead', 10 ) ) { wp_send_json_error( array( 'code' => 'rate' ), 429 ); }
		$draft = Page::is_draft_request() || ( ! empty( $_POST['draft'] ) && current_user_can( \AnsaPromo\Plugin::CAP ) ); $cfg = $draft ? Config::get_draft() : Config::get_published();
		$email = strtolower( sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) ) ); if ( ! is_email( $email ) ) { wp_send_json_error( array( 'code' => 'email' ), 400 ); }
		$kind = sanitize_key( (string) ( $_POST['kind'] ?? 'cold' ) ); if ( ! in_array( $kind, array( 'cold', 'aff' ), true ) ) { $kind = 'cold'; }
		$bonus = sanitize_key( (string) ( $_POST['bonus'] ?? '' ) ); $consent = ! isset( $_POST['consent'] ) || '0' !== (string) $_POST['consent'];
		$utm = sanitize_key( (string) ( $_POST['utm'] ?? '' ) ); $ref = strtoupper( preg_replace( '/[^a-z0-9_\-]/i', '', (string) wp_unslash( $_POST['ref'] ?? '' ) ) );
		if ( ! $draft && empty( $cfg['enabled'] ) ) { wp_send_json_error( array( 'code' => 'live' ), 400 ); }
		$ctx = Cart::ctx(); $out = array();
		if ( 'cold' === $kind ) {
			if ( empty( $cfg['cold']['enabled'] ) ) { wp_send_json_error( array( 'code' => 'live' ), 400 ); }
			/* същият имейл в живата сесия → същият ваучер (не се издава втори) */
			$v = is_array( $ctx['voucher'] ?? null ) ? $ctx['voucher'] : null;
			if ( $v && self::voucher_valid( (string) $v['code'] ) && (string) ( $ctx['email'] ?? '' ) === $email ) { $out['voucher'] = array( 'code' => $v['code'], 'amount' => (float) $v['amount'], 'exp' => (int) $v['exp'] ); $out['lead'] = (int) ( $ctx['lead'] ?? 0 ); }
			else {
				$code = self::new_voucher_code( $cfg ); $exp = time() + (int) $cfg['cold']['minutes'] * MINUTE_IN_SECONDS;
				$id = self::capture( $email, 'cold', '', '', $utm, $consent, $code, $exp );
				if ( ! $id ) { wp_send_json_error( array( 'code' => 'db' ), 500 ); }
				$ctx['voucher'] = array( 'code' => $code, 'amount' => (float) $cfg['cold']['voucher'], 'exp' => $exp, 'lead' => $id );
				$out['voucher'] = array( 'code' => $code, 'amount' => (float) $cfg['cold']['voucher'], 'exp' => $exp ); $out['lead'] = $id;
				self::sync( $id, $email, $consent, $cfg, 'cold' );
			}
		} else {
			if ( empty( $cfg['aff']['enabled'] ) ) { wp_send_json_error( array( 'code' => 'live' ), 400 ); }
			$ref = $ref ?: (string) ( $ctx['ref'] ?? '' ); $p = Page::partner( $ref ); if ( ! $p ) { wp_send_json_error( array( 'code' => 'ref' ), 400 ); }
			if ( ! in_array( $bonus, array( 'v5', 'mask' ), true ) ) { $bonus = 'v5'; }
			$id = self::capture( $email, 'aff', $bonus, $p['code'], $utm, $consent, '', 0 );
			if ( ! $id ) { wp_send_json_error( array( 'code' => 'db' ), 500 ); }
			Cart::remember_ref( $p['code'] ); $ctx = Cart::ctx(); $ctx['affgift'] = $bonus; $ctx['ref'] = $p['code']; $out['lead'] = $id; $out['partner'] = array( 'code' => $p['code'], 'name' => $p['name'] );
			self::sync( $id, $email, $consent, $cfg, 'aff' );
		}
		$ctx['email'] = $email; if ( ! empty( $out['lead'] ) ) { $ctx['lead'] = (int) $out['lead']; }
		if ( 'cold' === $kind && 'early' === Config::phase_now( $cfg ) ) { $ctx['early'] = true; } /* записва се, докато ранният достъп тече → влиза направо */
		Cart::set_ctx( $ctx );
		try { Insights::hit( 'gate', 'bf', $kind ); } catch ( \Throwable $e ) {}
		$out['ctx'] = Cart::ctx_public();
		do_action( 'ansa_promo_bf_lead_captured', (int) ( $out['lead'] ?? 0 ), $email, $kind, $bonus );
		nocache_headers(); wp_send_json_success( $out );
	}
	private static function sync( $id, $email, $consent, $cfg, $kind ) { try { return Lead_Sync::sync( (int) $id, $email, (bool) $consent, self::fka_cfg( $cfg, $kind ) ); } catch ( \Throwable $e ) { return 'retry: ' . $e->getMessage(); } }

	public static function new_voucher_code( $cfg ) {
		$pre = (string) $cfg['cold']['prefix'] . (int) round( (float) $cfg['cold']['voucher'] ) . '-'; $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		for ( $i = 0; $i < 5; $i++ ) { $c = ''; for ( $j = 0; $j < 4; $j++ ) { $c .= $alpha[ random_int( 0, strlen( $alpha ) - 1 ) ]; } if ( ! self::voucher_row( $pre . $c ) ) { return $pre . $c; } }
		return $pre . strtoupper( substr( md5( uniqid( '', true ) ), 0, 5 ) );
	}
	public static function capture( $email, $kind, $bonus, $ref, $utm, $consent, $voucher, $exp ) {
		global $wpdb;
		$ok = $wpdb->insert( Schema::table( 'leads' ), array(
			'email' => $email, 'utm' => substr( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $utm ), 0, 64 ), 'box_seen' => 'bf', 'consent' => $consent ? 1 : 0, 'ip' => SeasonLeads::ip(), 'created_at' => current_time( 'mysql' ),
			'promo' => self::PROMO, 'kind' => substr( (string) $kind, 0, 16 ), 'bonus' => substr( (string) $bonus, 0, 16 ), 'ref_code' => substr( (string) $ref, 0, 32 ), 'voucher' => substr( (string) $voucher, 0, 24 ), 'voucher_exp' => $exp ? gmdate( 'Y-m-d H:i:s', (int) $exp ) : null,
		), array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* ── ваучери ── */
	public static function voucher_row( $code ) { global $wpdb; $code = strtoupper( trim( (string) $code ) ); if ( '' === $code ) { return null; } $r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . ' WHERE voucher = %s LIMIT 1', $code ), ARRAY_A ); return is_array( $r ) ? $r : null; }
	/** Жив = има го, не е ползван и не е изтекъл (voucher_exp е UTC). */
	public static function voucher_valid( $code ) {
		static $cache = array(); $code = strtoupper( trim( (string) $code ) ); if ( '' === $code ) { return false; }
		if ( isset( $cache[ $code ] ) && $cache[ $code ][0] > time() - 5 ) { return $cache[ $code ][1]; }
		$r = self::voucher_row( $code ); $ok = $r && empty( $r['voucher_order_id'] ) && ! empty( $r['voucher_exp'] ) && strtotime( $r['voucher_exp'] . ' UTC' ) > time();
		$cache[ $code ] = array( time(), (bool) $ok ); return (bool) $ok;
	}
	public static function voucher_use( $code, $order_id ) { global $wpdb; $code = strtoupper( trim( (string) $code ) ); if ( '' === $code || ! $order_id ) { return; } $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'leads' ) . ' SET voucher_order_id = %d WHERE voucher = %s AND voucher_order_id IS NULL', (int) $order_id, $code ) ); }
	public static function convert_email( $email, $order_id ) { global $wpdb; $email = strtolower( sanitize_email( (string) $email ) ); if ( ! is_email( $email ) || ! $order_id ) { return 0; } return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'leads' ) . " SET converted_order_id = %d WHERE converted_order_id IS NULL AND promo = %s AND email = %s", (int) $order_id, self::PROMO, $email ) ); }

	/* ── поръчка ── */
	public static function on_order_id( $order_id ) { $o = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null; if ( ! $o ) { return; } $m = $o->get_meta( '_ansa_promo_bf' ); if ( is_array( $m ) ) { self::on_order( $o, $m ); } }
	/** Идемпотентно (_ansa_bf_fka = ok | retry: …): лийдовете с този имейл → конвертирани, ваучерът → ползван, FKA: таговете на лийда падат, поръчката получава orders.fka_tag. */
	public static function on_order( $order, $m ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! is_array( $m ) ) { return; }
		$emails = array(); $be = strtolower( sanitize_email( (string) $order->get_billing_email() ) ); if ( is_email( $be ) ) { $emails[ $be ] = 1; } if ( ! empty( $m['lead_email'] ) && is_email( $m['lead_email'] ) ) { $emails[ strtolower( $m['lead_email'] ) ] = 1; }
		foreach ( array_keys( $emails ) as $e ) { self::convert_email( $e, $order->get_id() ); }
		if ( ! empty( $m['voucher']['code'] ) ) { self::voucher_use( (string) $m['voucher']['code'], $order->get_id() ); }
		if ( 'ok' === (string) $order->get_meta( '_ansa_bf_fka' ) ) { return; }
		$cfg = ! empty( $m['draft'] ) ? Config::get_draft() : Config::get_published(); $l = $cfg['leads'];
		if ( empty( $l['fka'] ) ) { $order->update_meta_data( '_ansa_bf_fka', 'ok' ); $order->save(); return; }
		if ( ! Lead_Sync::available() ) { $order->update_meta_data( '_ansa_bf_fka', 'retry: FunnelKit контакти липсват' ); $order->save(); return; }
		$retry = ''; $parts = array();
		try {
			foreach ( array_keys( $emails ) as $e ) {
				if ( ! empty( $l['untag_on_order'] ) ) { foreach ( array( $l['tag_cold'], $l['tag_aff'] ) as $tn ) { if ( '' === $tn ) { continue; } $tid = Merge::tag_id( $tn ); if ( $tid > 0 ) { $how = Lead_Sync::detach( $e, 'tags', $tid ); if ( 'crm' === $how || 'db' === $how ) { $parts[] = $tn . ' падна (' . $e . ')'; } } } }
				$ot = (string) ( $cfg['orders']['fka_tag'] ?? '' );
				if ( '' !== $ot ) { $c = Lead_Sync::contact( $e, true ); $tid = Merge::tag_id( $ot, true ); if ( $c && $tid > 0 ) { $how = Lead_Sync::attach( $c, $e, 'tags', $tid, $ot ); if ( '' === $how ) { $retry = 'тагът на поръчката не се записа'; } else { $parts[] = $ot . ' → ' . $e . ' (' . $how . ')'; } } }
			}
		} catch ( \Throwable $ex ) { $retry = $ex->getMessage(); }
		if ( $parts ) { $order->add_order_note( 'ansa™ Черен петък · FunnelKit: ' . implode( '; ', $parts ) ); }
		$order->update_meta_data( '_ansa_bf_fka', $retry ? 'retry: ' . $retry : 'ok' ); $order->save();
	}

	/* ── админ ── */
	public static function stats() {
		global $wpdb; $t = Schema::table( 'leads' );
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(kind = 'cold') AS cold, SUM(kind = 'aff') AS aff, SUM(converted_order_id IS NOT NULL) AS converted, SUM(voucher <> '' AND voucher_order_id IS NOT NULL) AS voucher_used, SUM(fka <> '' AND fka NOT LIKE 'retry%%' AND fka <> 'no-consent' AND fka <> 'off') AS synced, SUM(fka = '' OR fka LIKE 'retry%%') AS pending FROM $t WHERE promo = %s", self::PROMO ), ARRAY_A );
		return array_map( 'intval', is_array( $r ) ? $r : array( 'total' => 0, 'cold' => 0, 'aff' => 0, 'converted' => 0, 'voucher_used' => 0, 'synced' => 0, 'pending' => 0 ) );
	}
	public static function rows( $limit = 200 ) { global $wpdb; $r = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . ' WHERE promo = %s ORDER BY id DESC LIMIT %d', self::PROMO, (int) $limit ), ARRAY_A ); return is_array( $r ) ? $r : array(); }
	public static function unsynced( $limit = 500 ) { global $wpdb; $r = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . " WHERE promo = %s AND (fka = '' OR fka LIKE 'retry%%') ORDER BY id ASC LIMIT %d", self::PROMO, (int) $limit ), ARRAY_A ); return is_array( $r ) ? $r : array(); }
	public static function backfill() { $cfg = Config::get_published(); $r = array( 'ok' => 0, 'retry' => 0, 'skip' => 0 ); foreach ( self::unsynced() as $row ) { $k = (string) self::sync( (int) $row['id'], $row['email'], ! empty( $row['consent'] ), $cfg, (string) $row['kind'] ); if ( 0 === strpos( $k, 'retry' ) ) { $r['retry']++; } elseif ( in_array( $k, array( 'off', 'no-consent', 'no-email' ), true ) ) { $r['skip']++; } else { $r['ok']++; } } return $r; }
	public static function csv() {
		$rows = self::rows( 5000 ); $out = fopen( 'php://temp', 'w+' ); fputcsv( $out, array( 'id', 'created_at', 'email', 'kind', 'bonus', 'ref_code', 'utm', 'consent', 'voucher', 'voucher_exp', 'voucher_order_id', 'converted_order_id', 'fka' ) );
		foreach ( $rows as $r ) { fputcsv( $out, array( $r['id'], $r['created_at'], $r['email'], $r['kind'], $r['bonus'], $r['ref_code'], $r['utm'], $r['consent'], $r['voucher'], $r['voucher_exp'], $r['voucher_order_id'], $r['converted_order_id'], $r['fka'] ) ); }
		rewind( $out ); return "\xEF\xBB\xBF" . stream_get_contents( $out );
	}
}
