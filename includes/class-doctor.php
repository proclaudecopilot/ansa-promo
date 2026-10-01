<?php
/**
 * ansa™ Промо — Doctor.
 *
 * Фаза 0: env() — състоянието на средата (същите редове печата и snippets/phase-0.php,
 * който обаче е самостоятелен, за да работи и ако плъгинът не се зареди).
 * Фаза 2 добавя checks() — publish gate-а (§7 от плана).
 *
 * @package AnsaPromo
 * @since 1.0.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Doctor {

	/** Списък от [label, value, level ok|warn|bad|info]. */
	public static function env() {
		global $wpdb, $wp_version;
		$rows = array();
		$add  = function ( $label, $value, $level = 'info' ) use ( &$rows ) { $rows[] = array( 'label' => $label, 'value' => (string) $value, 'level' => $level ); };

		$add( 'ansa™ Промо', ANSA_PROMO_VER . ' · ANSA_PROMO_PLUGIN_VER=' . ( defined( 'ANSA_PROMO_PLUGIN_VER' ) ? ANSA_PROMO_PLUGIN_VER : '—' ), 'ok' );
		$add( 'WordPress / PHP', $wp_version . ' / ' . PHP_VERSION, 'info' );
		$add( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : 'липсва', defined( 'WC_VERSION' ) ? 'ok' : 'bad' );

		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$add( 'HPOS', $hpos ? 'включен (custom order tables)' : 'изключен (posts)', 'info' );

		$sv = Plugin::shrine_version();
		if ( '' === $sv ) {
			$add( 'ansa™ Shrine', 'не е активен', 'warn' );
		} else {
			$loaded = Plugin::shrine_promo_module_loaded();
			$add( 'ansa™ Shrine', $sv . ( version_compare( $sv, '6.19.22', '>=' ) ? ' (има guard)' : ' (БЕЗ guard — трябва ≥ 6.19.22)' ), version_compare( $sv, '6.19.22', '>=' ) ? 'ok' : 'bad' );
			$add( 'Промо модулът на Shrine', $loaded ? 'ЗАРЕДЕН — конфликт' : 'не е зареден (guard-ът работи)', $loaded ? 'bad' : 'ok' );
		}

		$owner = Plugin::shortcode_owner();
		$add( 'Shortcode [ansa_promo]', $owner ?: 'не е регистриран', ( 0 === strpos( $owner, 'AnsaPromo\\' ) ) ? 'ok' : 'bad' );

		foreach ( Schema::status() as $t => $ok ) { $add( 'Таблица ' . $t, $ok ? 'има' : 'ЛИПСВА', $ok ? 'ok' : 'bad' ); }
		$add( 'DB схема', (string) get_option( Schema::OPT_DB_VER, '—' ) . ' (очаквана ' . ANSA_PROMO_DB_VER . ')', 'info' );

		$store = get_option( Plugin::OPTION );
		$add( 'Option ' . Plugin::OPTION, is_array( $store ) ? 'има · ключове: ' . implode( ', ', array_keys( $store ) ) . ( isset( $store['version'] ) ? ' · version=' . $store['version'] : '' ) : 'няма (Фаза 1 я създава)', 'info' );

		$add( 'LOGADOR GitHub Updater', defined( 'LOGADOR_GH_UPDATER_VERSION' ) ? LOGADOR_GH_UPDATER_VERSION . ( class_exists( 'LOGADOR_GitHub_Updater' ) && \LOGADOR_GitHub_Updater::token() ? ' · token има' : ' · БЕЗ token' ) : 'не е зареден (mu-plugin)', defined( 'LOGADOR_GH_UPDATER_VERSION' ) ? 'ok' : 'warn' );

		$pages = self::pages_with_shortcode();
		$add( 'Страници с [ansa_promo]', $pages ? implode( '; ', $pages ) : 'няма', $pages ? 'ok' : 'warn' );

		$add( 'FunnelKit', ( class_exists( 'WFACP_Core' ) ? 'Checkout ' . ( defined( 'WFACP_VERSION' ) ? WFACP_VERSION : '' ) : 'Checkout липсва' ) . ' · ' . ( class_exists( 'WFFN_Core' ) ? 'Funnel Builder ' . ( defined( 'WFFN_VERSION' ) ? WFFN_VERSION : '' ) : 'Funnel Builder липсва' ), 'info' );
		$add( 'WC checkout page', (int) wc_get_page_id( 'checkout' ) . ' · ' . wc_get_checkout_url(), 'info' );

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$g = array();
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) { $g[] = $id . ( 'yes' === $gw->enabled ? ' ✓' : ' ✗' ); }
			$add( 'Gateways', implode( ', ', $g ), 'info' );
		}

		$cache = array();
		if ( class_exists( '\\FlyingPress\\Purge' ) ) $cache[] = 'FlyingPress';
		if ( defined( 'WP_ROCKET_VERSION' ) ) $cache[] = 'WP Rocket';
		if ( defined( 'LSCWP_V' ) ) $cache[] = 'LiteSpeed';
		if ( wp_using_ext_object_cache() ) $cache[] = 'object cache';
		$add( 'Кеш', $cache ? implode( ', ', $cache ) : 'не е засечен', 'info' );

		return $rows;
	}

	public static function pages_with_shortcode() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('page','post') AND post_status IN ('publish','draft','private') AND post_content LIKE '%[ansa_promo%' ORDER BY ID DESC LIMIT 10" );
		$out = array();
		foreach ( $ids as $id ) { $out[] = '#' . $id . ' „' . get_the_title( $id ) . '“ (' . get_post_status( $id ) . ') ' . get_permalink( $id ); }
		return $out;
	}
}
