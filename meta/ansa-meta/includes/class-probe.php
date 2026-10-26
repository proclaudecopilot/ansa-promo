<?php
/**
 * ansa™ Meta — suite за ansa™ Probe (filter ansa_probe_suites): meta-env — Състоянието като проверки + REST ping.
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Probe {
	public static function init() { add_filter( 'ansa_probe_suites', array( __CLASS__, 'suites' ) ); }

	public static function suites( $s ) {
		$s[] = array( 'id' => 'meta-env', 'label' => 'ansa™ Meta · Pixel + Conversions API (настройки · таблици · крон · опашка · 24 ч · REST)', 'plugin' => 'ansa-meta', 'callback' => array( __CLASS__, 'suite_env' ) );
		return $s;
	}

	public static function suite_env( $t, $params ) {
		foreach ( Doctor::rows() as $r ) {
			$v = wp_strip_all_tags( $r['value'] );
			if ( 'bad' === $r['level'] ) { $t->ok( $r['label'], false, $v ); }
			elseif ( 'warn' === $r['level'] ) { $t->note( '⚠ ' . $r['label'], $v ); }
			else { $t->note( $r['label'], $v ); }
		}
		$t->ok( 'включен и конфигуриран', Plugin::enabled(), Plugin::enabled() ? 'Pixel ' . Settings::pixel_id() : 'няма Pixel ID/token' );
		$res = wp_remote_get( rest_url( Rest::NS . '/ping' ), array( 'timeout' => 15, 'sslverify' => false ) );
		$body = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$t->ok( 'REST ping', is_array( $body ) && ! empty( $body['ok'] ), is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
		return array( 'queue' => Queue::count(), 'stats' => Capi::stats( 24 ) );
	}
}
