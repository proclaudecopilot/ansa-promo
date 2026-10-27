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
		if ( Settings::get( 'test_mode' ) ) $add( 'ТЕСТОВ РЕЖИМ', 'включен — всички сървърни събития носят test_event_code ' . esc_html( (string) Settings::get( 'test_code', '' ) ) . ' и НЕ влизат в отчетите/оптимизацията. Изключи го след проверката.', 'bad' );
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
		$fk = get_option( 'bwf_gen_config', array() ); $fk = is_array( $fk ) ? $fk : array();
		if ( ! empty( $fk['fb_pixel_key'] ) ) {
			$same = Settings::pixel_id() === preg_replace( '/\D+/', '', (string) $fk['fb_pixel_key'] );
			$add( 'FunnelKit Funnel Builder — Facebook Pixel', 'Pixel ' . esc_html( $fk['fb_pixel_key'] ) . ( ! empty( $fk['is_fb_page_view_global'] ) ? ' на всички страници' : '' ) . ( ! empty( $fk['is_fb_purchase_conversion_api'] ) ? ' · CAPI включен' : '' ) . ( empty( $fk['is_fb_advanced_event'] ) ? ' · без Advanced Matching' : '' ) . ( $same ? ' (същият Pixel)' : '' ) . ' — <b>изключи го</b>: FunnelKit → Settings → Tracking → Facebook Pixel → изтрий Pixel ID-то (или махни отметките на събитията), иначе всяко събитие е два пъти (техните event_id не съвпадат с нашите)', 'warn' );
		}
		$add( 'Други плъгини за Pixel', $others ? implode( ', ', $others ) . ' — <b>изключи Meta/Facebook в тях</b> (GA4 остава), иначе Pixel-ът е два пъти и събитията се броят двойно' : ( empty( $fk['fb_pixel_key'] ) ? 'няма засечени' : 'само FunnelKit (горе)' ), $others ? 'warn' : 'ok' );
		if ( is_plugin_active( 'complianz-gdpr/complianz-gpdr.php' ) || defined( 'cmplz_version' ) ) {
			$cc = (string) Settings::get( 'consent_cookie', '' );
			$add( 'Complianz (cookie consent)', '' !== $cc ? 'съгласието се чака по бисквитка <code>' . esc_html( $cc ) . '</code>' . ( Settings::get( 'consent_value' ) ? ' = <code>' . esc_html( Settings::get( 'consent_value' ) ) . '</code>' : '' ) : 'активен (opt-in за ЕС), но тук няма зададена consent бисквитка → Pixel + CAPI тръгват за всички, както FunnelKit-ът сега. За изчакване на съгласието: Настройки → Съгласие → <code>cmplz_marketing</code> съдържа <code>allow</code>', '' !== $cc ? 'ok' : 'warn' );
		}
		$cache = array(); if ( defined( 'FLYING_PRESS_VERSION' ) ) $cache[] = 'FlyingPress'; if ( defined( 'WP_ROCKET_VERSION' ) ) $cache[] = 'WP Rocket'; if ( defined( 'LSCWP_V' ) ) $cache[] = 'LiteSpeed Cache'; if ( defined( 'PERFMATTERS_VERSION' ) ) $cache[] = 'Perfmatters';
		$add( 'Кеш / оптимизатор', $cache ? implode( ', ', $cache ) . ' — ако „Delay JavaScript“ е включено, добави <code>ansa-meta</code> и <code>fbevents</code>/<code>connect.facebook.net</code> в изключенията, иначе PageView чака първото докосване' : 'не е засечен', $cache ? 'warn' : 'info' );
		$add( 'Не се броят', 'роли: ' . ( implode( ', ', (array) Settings::get( 'exclude_roles', array() ) ) ?: '—' ) . ' · вътрешни IP: ' . ( trim( str_replace( "\n", ', ', (string) Settings::get( 'internal_ips', '' ) ) ) ?: '—' ) . ' · ботове: ' . ( Settings::get( 'bot_filter' ) ? 'да' : 'не' ) . ' · съгласие: ' . ( Settings::get( 'consent_cookie' ) ?: 'без проверка' ), 'info' );
		$ping = wp_remote_get( rest_url( Rest::NS . '/ping' ), array( 'timeout' => 6, 'sslverify' => false ) ); $pb = is_wp_error( $ping ) ? null : json_decode( (string) wp_remote_retrieve_body( $ping ), true );
		$add( 'REST', esc_html( rest_url( Rest::NS . '/event' ) ) . ' — ' . ( is_array( $pb ) && ! empty( $pb['ok'] ) ? 'отговаря' : 'НЕ ОТГОВАРЯ: ' . ( is_wp_error( $ping ) ? esc_html( $ping->get_error_message() ) : 'HTTP ' . (int) wp_remote_retrieve_response_code( $ping ) ) . ' (защитна стена/кеш пред /wp-json?)' ), is_array( $pb ) && ! empty( $pb['ok'] ) ? 'ok' : 'bad' );
		if ( defined( 'ANSA_PROMO_VER' ) ) $add( 'ansa™ Промо', ANSA_PROMO_VER . ' — кутията → AddToCart (ansa_promo_added), попъпът → Lead (ansa_promo_lead_captured)', 'ok' );
		return $r;
	}
}
