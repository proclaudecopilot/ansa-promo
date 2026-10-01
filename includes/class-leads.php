<?php
/**
 * ansa™ Промо — leads (имейлът от gate-а).
 *
 * wp_ajax_nopriv_ansa_promo_lead → ред в {prefix}ansa_promo_leads; при поръчка (Фаза 3) converted_order_id.
 * Hook `ansa_promo_lead_captured( $lead_id, $email, $consent, $utm )` за мейлър интеграция.
 * Rate limit: 10 заявки/мин/IP (transient).
 *
 * @package AnsaPromo
 * @since 1.0.2
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Leads {

	public static function register() {
		add_action( 'wp_ajax_ansa_promo_lead', array( __CLASS__, 'ajax_lead' ) );
		add_action( 'wp_ajax_nopriv_ansa_promo_lead', array( __CLASS__, 'ajax_lead' ) );
		add_action( 'ansa_promo_daily', array( __CLASS__, 'cron_expire' ) );
	}

	public static function ip() {
		$ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : ( $_SERVER['REMOTE_ADDR'] ?? '' );
		return substr( preg_replace( '/[^0-9a-fA-F:.]/', '', (string) $ip ), 0, 45 );
	}

	/** true ако лимитът е надвишен. */
	public static function rate_limited( $bucket, $max, $window = MINUTE_IN_SECONDS ) {
		$k = 'ansa_promo_rl_' . md5( $bucket . '|' . self::ip() );
		$n = (int) get_transient( $k );
		if ( $n >= $max ) { return true; }
		set_transient( $k, $n + 1, $window );
		return false;
	}

	public static function capture( $email, $consent, $utm = '', $box_seen = '' ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) { return 0; }
		$t = Schema::table( 'leads' );
		$wpdb->insert( $t, array(
			'email'      => $email,
			'utm'        => substr( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $utm ), 0, 64 ),
			'box_seen'   => substr( preg_replace( '/[^a-z]/', '', (string) $box_seen ), 0, 8 ),
			'consent'    => $consent ? 1 : 0,
			'ip'         => self::ip(),
			'created_at' => current_time( 'mysql' ),
		), array( '%s', '%s', '%s', '%d', '%s', '%s' ) );
		$id = (int) $wpdb->insert_id;
		if ( $id ) { do_action( 'ansa_promo_lead_captured', $id, $email, (bool) $consent, (string) $utm ); }
		return $id;
	}

	public static function ajax_lead() {
		if ( ! check_ajax_referer( Frontend::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'nonce' ), 403 ); }
		if ( self::rate_limited( 'lead', 10 ) ) { wp_send_json_error( array( 'code' => 'rate' ), 429 ); }
		$email = sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! is_email( $email ) ) { wp_send_json_error( array( 'code' => 'email' ), 400 ); }
		$id = self::capture( $email, ! empty( $_POST['consent'] ) && '0' !== (string) $_POST['consent'], (string) wp_unslash( $_POST['utm'] ?? '' ), (string) wp_unslash( $_POST['box_seen'] ?? '' ) );
		if ( ! $id ) { wp_send_json_error( array( 'code' => 'db' ), 500 ); }
		wp_send_json_success( array( 'lead' => $id ) );
	}

	/** Конверсия (Фаза 3 я вика при създаване на поръчка). */
	public static function convert( $lead_id, $order_id ) {
		global $wpdb;
		if ( ! $lead_id || ! $order_id ) { return; }
		$wpdb->update( Schema::table( 'leads' ), array( 'converted_order_id' => (int) $order_id ), array( 'id' => (int) $lead_id ), array( '%d' ), array( '%d' ) );
	}

	/** GDPR хигиена: leads без конверсия, по-стари от 180 дни. */
	public static function cron_expire() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'leads' ) . ' WHERE converted_order_id IS NULL AND created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ) );
	}

	public static function count() { global $wpdb; return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'leads' ) ); }
}
