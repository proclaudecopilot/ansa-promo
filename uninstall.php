<?php
/**
 * ansa™ Промо — деинсталация (Plugins → Delete).
 *
 * Трие опциите, transient-ите и крона на плъгина. Таблиците (билети, leads, Insights) и order meta-та на поръчките ОСТАВАТ —
 * те са бизнес данни (кой билет на коя поръчка). За пълно изтриване: define( 'ANSA_PROMO_UNINSTALL_DROP', true ) в wp-config.php
 * преди изтриването. proclaudecopilot GitHub Updater (mu-plugin) е общ за плъгините на ansa и не се пипа.
 *
 * @package AnsaPromo
 * @since 1.4.1
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'ansa_promo_store', 'ansa_promo_ver_seen', 'ansa_promo_db_ver', 'ansa_promo_activated_at', 'ansa_promo_seed_report', 'ansa_promo_runtime_stamp', 'ansa_promo_ticket_secret', 'ansa_promo_rw_v2', 'ansa_promo_name_v2', 'ansa_promo_sku_v1', 'ansa_promo_cat_v1', 'ansa_promo_pct_s20', 'ansa_promo_wctext_v2' ) as $o ) { delete_option( $o ); }
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ansa_promo_%' OR option_name LIKE '_transient_timeout_ansa_promo_%'" );
wp_clear_scheduled_hook( 'ansa_promo_daily' );
if ( defined( 'ANSA_PROMO_UNINSTALL_DROP' ) && ANSA_PROMO_UNINSTALL_DROP ) {
	foreach ( array( 'tickets', 'leads', 'stats' ) as $t ) { $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ansa_promo_' . $t ); }
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_ansa_promo%'" );
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders_meta'" ) ) { $wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE '_ansa_promo%'" ); }
}
