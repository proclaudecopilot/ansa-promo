<?php
/**
 * ansa™ Meta — 🩺 Състояние: редове {label, value, level ok|warn|bad|info} за админа и за ansa™ Probe.
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Doctor {

	public static function rows() {
		$r = array(); $add = function ( $label, $value, $level = 'info' ) use ( &$r ) { $r[] = array( 'label' => $label, 'value' => (string) $value, 'level' => $level ); };
		$pixel = Settings::pixel_id(); $tok = Settings::token();
		$add( 'ansa™ Meta', ANSA_META_VER . ' · DB ' . (string) get_option( Schema::OPT_DB_VER, '—' ) . ' · Graph API ' . Settings::api_version(), 'ok' );
		$add( 'Pixel ID', '' !== $pixel ? $pixel . ( Settings::pixel_from_constant() ? ' (константа)' : '' ) : 'ЛИПСВА', '' !== $pixel ? 'ok' : 'bad' );
		$add( 'Access token', '' !== $tok ? 'има (…' . substr( $tok, -4 ) . ', ' . strlen( $tok ) . ' знака)' . ( Settings::token_from_constant() ? ' (константа)' : '' ) : 'ЛИПСВА', '' !== $tok ? 'ok' : 'bad' );
		$add( 'Включено', Plugin::enabled() ? 'да — праща' : ( defined( 'ANSA_META_DISABLED' ) && ANSA_META_DISABLED ? 'НЕ — ANSA_META_DISABLED' : ( Settings::get( 'enabled' ) ? 'не — няма Pixel ID/token' : 'НЕ — изключено от настройките' ) ), Plugin::enabled() ? 'ok' : 'bad' );
		$add( 'Pixel в браузъра', Settings::get( 'browser' ) ? 'ansa™ Meta зарежда fbq (дедупликация по event_id)' : 'ИЗКЛЮЧЕН — само сървър; чужд Pixel ще дублира', Settings::get( 'browser' ) ? 'ok' : 'warn' );
		$add( 'Purchase', ( 'order' === Settings::get( 'purchase_on' ) ? 'при създаване на поръчката' : 'при плащане (наложен платеж веднага)' ) . ' · content_ids = ' . Settings::get( 'id_format' ), 'info' );
		$off = array(); foreach ( Settings::events_list() as $k => $l ) { if ( ! Settings::event_on( $k ) ) $off[] = $k; }
		$add( 'Събития', $off ? 'изключени: ' . implode( ', ', $off ) : 'всичките 10 включени', $off ? 'warn' : 'ok' );
		$add( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION . ' · ' . Catalog::currency() . ( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ? ' · цени с ДДС' : '' ) : 'липсва', defined( 'WC_VERSION' ) ? 'ok' : 'bad' );
		foreach ( Schema::status() as $t => $ok ) { $add( 'Таблица ' . $t, $ok ? 'има' : 'ЛИПСВА', $ok ? 'ok' : 'bad' ); }
		$next = wp_next_scheduled( Capi::CRON );
		$add( 'Крон (опашка, всяка минута)', $next ? 'следващ: ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'H:i:s' ) . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? ' · DISABLE_WP_CRON (системен крон)' : '' ) : 'НЕ Е НАСРОЧЕН', $next ? 'ok' : 'warn' );
		$add( 'Опашка за повторение', Queue::count() . ' реда', Queue::count() > 100 ? 'warn' : 'info' );
		$add( 'fastcgi_finish_request', function_exists( 'fastcgi_finish_request' ) ? 'има — пращането е след отговора към браузъра' : ( function_exists( 'litespeed_finish_request' ) ? 'litespeed_finish_request' : 'няма — пращането е с 4 с таймаут, провалите минават през опашката' ), 'info' );
		$st = Capi::stats( 24 ); $ok = 0; $fail = 0; foreach ( $st['by'] as $b ) { $ok += (int) $b['ok']; $fail += (int) $b['fail']; }
		$add( 'Последните 24 ч', $ok . ' изпратени · ' . $fail . ' грешки', $fail > $ok ? 'bad' : ( $fail ? 'warn' : 'ok' ) );
		if ( $st['last_error'] ) $add( 'Последна грешка', get_date_from_gmt( $st['last_error']['at'], 'd.m H:i' ) . ' · ' . $st['last_error']['event_name'] . ' · ' . $st['last_error']['response'], 'warn' );
		if ( $st['purchase_n'] ) { $keys = array(); foreach ( $st['purchase_keys'] as $k => $n ) $keys[] = $k . ' ' . (int) round( 100 * $n / $st['purchase_n'] ) . '%'; $add( 'Съвпадение в Purchase (' . (int) $st['purchase_n'] . ')', implode( ' · ', $keys ), 'info' ); }
		/* други плъгини с Meta Pixel */
		if ( ! function_exists( 'is_plugin_active' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$others = array();
		foreach ( array( 'woocommerce-google-adwords-conversion-tracking-tag/wgact.php' => 'Pixel Manager for WooCommerce', 'pixel-manager-pro-for-woocommerce/wgact.php' => 'Pixel Manager Pro', 'facebook-for-woocommerce/facebook-for-woocommerce.php' => 'Facebook for WooCommerce', 'pixelyoursite/facebook-pixel-master.php' => 'PixelYourSite', 'pixelyoursite-pro/pixelyoursite-pro.php' => 'PixelYourSite Pro', 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' => 'GTM4WP' ) as $file => $name ) { if ( is_plugin_active( $file ) ) $others[] = $name; }
		$add( 'Други плъгини за Pixel', $others ? implode( ', ', $others ) . ' — <b>изключи Meta/Facebook в тях</b> (GA4 остава), иначе Pixel-ът е два пъти и събитията се броят двойно' : 'няма засечени', $others ? 'warn' : 'ok' );
		$cache = array(); if ( defined( 'FLYING_PRESS_VERSION' ) ) $cache[] = 'FlyingPress'; if ( defined( 'WP_ROCKET_VERSION' ) ) $cache[] = 'WP Rocket'; if ( defined( 'LSCWP_V' ) ) $cache[] = 'LiteSpeed Cache'; if ( defined( 'PERFMATTERS_VERSION' ) ) $cache[] = 'Perfmatters';
		$add( 'Кеш / оптимизатор', $cache ? implode( ', ', $cache ) . ' — ако „Delay JavaScript“ е включено, добави <code>ansa-meta</code> и <code>fbevents</code>/<code>connect.facebook.net</code> в изключенията, иначе PageView чака първото докосване' : 'не е засечен', $cache ? 'warn' : 'info' );
		$add( 'Не се броят', 'роли: ' . ( implode( ', ', (array) Settings::get( 'exclude_roles', array() ) ) ?: '—' ) . ' · вътрешни IP: ' . ( trim( str_replace( "\n", ', ', (string) Settings::get( 'internal_ips', '' ) ) ) ?: '—' ) . ' · ботове: ' . ( Settings::get( 'bot_filter' ) ? 'да' : 'не' ) . ' · съгласие: ' . ( Settings::get( 'consent_cookie' ) ?: 'без проверка' ), 'info' );
		$add( 'REST', rest_url( Rest::NS . '/event' ), 'info' );
		if ( defined( 'ANSA_PROMO_VER' ) ) $add( 'ansa™ Промо', ANSA_PROMO_VER . ' — кутията → AddToCart (ansa_promo_added), попъпът → Lead (ansa_promo_lead_captured)', 'ok' );
		return $r;
	}
}
