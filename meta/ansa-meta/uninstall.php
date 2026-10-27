<?php
/** ansa™ Meta — при изтриване: настройки, таблици, крон. Order meta (_ansa_meta_*) остава — само бележки по поръчките. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
global $wpdb;
delete_option( 'ansa_meta' ); delete_option( 'ansa_meta_db_ver' ); delete_option( 'ansa_meta_ver_seen' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ansa_meta_queue' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ansa_meta_log' );
$ts = wp_next_scheduled( 'ansa_meta_flush' ); while ( $ts ) { wp_unschedule_event( $ts, 'ansa_meta_flush' ); $ts = wp_next_scheduled( 'ansa_meta_flush' ); }
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ansa_meta_%' OR option_name LIKE '_transient_timeout_ansa_meta_%'" );
