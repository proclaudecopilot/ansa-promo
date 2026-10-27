<?php
/**
 * ansa™ Meta — опашката за повторно изпращане (таблица ansa_meta_queue): кронът (всяка минута) праща дължимите
 * на партида; грешка → attempts+1 и изчакване 1·2·4·…·128 мин (8 опита, ~4 ч); „невалидно“ или 8 провала → отпада, с ред в дневника.
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Queue {
	const MAX_ATTEMPTS = 8;
	const BATCH        = 200;

	public static function push( array $ev, $src, $error = '' ) {
		global $wpdb;
		try {
			$wpdb->insert( Schema::queue_table(), array(
				'event_name' => mb_substr( (string) ( $ev['event_name'] ?? '' ), 0, 64 ), 'event_id' => mb_substr( (string) ( $ev['event_id'] ?? '' ), 0, 64 ),
				'source' => mb_substr( (string) $src, 0, 16 ), 'payload' => wp_json_encode( $ev, JSON_UNESCAPED_UNICODE ), 'attempts' => 0,
				'next_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'last_error' => mb_substr( (string) $error, 0, 500 ),
			), array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		} catch ( \Throwable $e ) {}
	}

	public static function count() { global $wpdb; return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::queue_table() ); }

	/** Праща дължимите ($force = всички). Връща [sent, failed, dropped]. */
	public static function process( $force = false ) {
		global $wpdb; $t = Schema::queue_table(); $out = array( 'sent' => 0, 'failed' => 0, 'dropped' => 0 );
		if ( ! Plugin::enabled() ) return $out;
		$rows = $force
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id ASC LIMIT %d", self::BATCH ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE next_at <= %s ORDER BY id ASC LIMIT %d", gmdate( 'Y-m-d H:i:s' ), self::BATCH ), ARRAY_A );
		if ( ! $rows ) return $out;
		$batch = array(); $ids = array();
		foreach ( $rows as $r ) {
			$ev = json_decode( (string) $r['payload'], true );
			if ( ! is_array( $ev ) || empty( $ev['event_name'] ) ) { $wpdb->delete( $t, array( 'id' => (int) $r['id'] ), array( '%d' ) ); $out['dropped']++; continue; }
			$now = time(); if ( (int) ( $ev['event_time'] ?? 0 ) < $now - 6 * DAY_IN_SECONDS ) $ev['event_time'] = $now; // Meta: до 7 дни назад
			$batch[] = array( 'ev' => $ev, 'src' => 'queue:' . (string) $r['source'], 'row' => $r ); $ids[] = (int) $r['id'];
		}
		if ( ! $batch ) return $out;
		$events = array(); foreach ( $batch as $b ) { $events[] = $b['ev']; }
		$res = Capi::send( $events );
		if ( $res['ok'] ) {
			foreach ( $batch as $b ) Capi::log( $b['ev'], $b['src'], $res );
			$wpdb->query( "DELETE FROM $t WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );
			$out['sent'] = count( $batch ); return $out;
		}
		if ( ! $res['retry'] && count( $batch ) > 1 ) {
			/* нещо в партидата е невалидно — едно по едно, за да минат здравите */
			foreach ( $batch as $b ) { $r1 = Capi::send( array( $b['ev'] ) ); self::after( $b, $r1, $out ); }
			return $out;
		}
		foreach ( $batch as $b ) self::after( $b, $res, $out );
		return $out;
	}

	private static function after( array $b, array $res, array &$out ) {
		global $wpdb; $t = Schema::queue_table(); $id = (int) $b['row']['id']; $att = (int) $b['row']['attempts'] + 1;
		if ( $res['ok'] ) { Capi::log( $b['ev'], $b['src'], $res ); $wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) ); $out['sent']++; return; }
		if ( ! $res['retry'] || $att >= self::MAX_ATTEMPTS ) {
			Capi::log( $b['ev'], $b['src'], array_merge( $res, array( 'error' => ( $res['retry'] ? 'отпада след ' . $att . ' опита: ' : 'отпада: ' ) . $res['error'] ) ) );
			$wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) ); $out['dropped']++; return;
		}
		$wait = 60 * ( 2 ** min( 7, $att ) );
		$wpdb->update( $t, array( 'attempts' => $att, 'next_at' => gmdate( 'Y-m-d H:i:s', time() + $wait ), 'last_error' => mb_substr( (string) $res['error'], 0, 500 ) ), array( 'id' => $id ), array( '%d', '%s', '%s' ), array( '%d' ) );
		$out['failed']++;
	}
}
