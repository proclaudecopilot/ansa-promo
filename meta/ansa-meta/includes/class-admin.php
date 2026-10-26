<?php
/**
 * ansa™ Meta — админ страницата: ⚙️ Настройки · 🩺 Състояние · 📜 Дневник · 🧪 Тест (меню „ansa™ Meta“, manage_woocommerce).
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Admin {
	const SLUG = 'ansa-meta';
	const CAP  = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ansa_meta_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ansa_meta_test', array( __CLASS__, 'test' ) );
		add_action( 'admin_post_ansa_meta_queue', array( __CLASS__, 'queue' ) );
		add_action( 'admin_post_ansa_meta_clear', array( __CLASS__, 'clear' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ANSA_META_FILE ), function ( $l ) { array_unshift( $l, '<a href="' . esc_url( self::url() ) . '">Настройки</a>' ); return $l; } );
	}

	public static function url( $args = array() ) { return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) ); }
	public static function menu() { add_menu_page( 'ansa™ Meta', 'ansa™ Meta', self::CAP, self::SLUG, array( __CLASS__, 'render' ), 'dashicons-chart-area', 56.3 ); }

	public static function notices() {
		if ( ! current_user_can( self::CAP ) ) return;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! Plugin::enabled() && ( ! $screen || 'toplevel_page_' . self::SLUG !== $screen->id ) && ( '' === Settings::pixel_id() || '' === Settings::token() ) ) {
			echo '<div class="notice notice-warning"><p><b>ansa™ Meta:</b> няма Pixel ID или access token — нищо не се праща към Meta. <a href="' . esc_url( self::url() ) . '">Настройки →</a></p></div>';
		}
	}

	/* ───────── actions ───────── */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) wp_die( 'Нямате права.' );
		check_admin_referer( $action );
	}
	private static function back( $tab, $msg = '', $extra = array() ) {
		wp_safe_redirect( self::url( array_merge( array( 'tab' => $tab, 'msg' => $msg ), $extra ) ) ); exit;
	}

	public static function save() {
		self::guard( 'ansa_meta_save' );
		Settings::save( (array) wp_unslash( $_POST ) );
		self::back( 'settings', 'saved' );
	}

	public static function test() {
		self::guard( 'ansa_meta_test' );
		$code = (string) wp_unslash( $_POST['test_code'] ?? '' );
		if ( '' !== $code ) { $all = Settings::all(); $all['test_code'] = $code; Settings::save( $all ); }
		$res = Capi::test( $code );
		set_transient( 'ansa_meta_test_' . get_current_user_id(), $res, 10 * MINUTE_IN_SECONDS );
		self::back( 'test', $res['ok'] ? 'test_ok' : 'test_fail' );
	}

	public static function queue() {
		self::guard( 'ansa_meta_queue' );
		$r = Queue::process( true );
		self::back( 'status', 'queue', array( 'sent' => (int) $r['sent'], 'failed' => (int) $r['failed'], 'dropped' => (int) $r['dropped'] ) );
	}

	public static function clear() {
		self::guard( 'ansa_meta_clear' );
		global $wpdb; $wpdb->query( 'TRUNCATE TABLE ' . Schema::log_table() );
		self::back( 'log', 'cleared' );
	}

	/* ───────── render ───────── */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) return;
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? 'settings' ) ); if ( ! in_array( $tab, array( 'settings', 'status', 'log', 'test' ), true ) ) $tab = 'settings';
		$msg = sanitize_key( (string) ( $_GET['msg'] ?? '' ) );
		echo '<div class="wrap am-wrap">';
		echo '<style>' . self::css() . '</style>';
		echo '<h1>ansa™ Meta <small>' . esc_html( ANSA_META_VER ) . '</small></h1>';
		echo '<p class="am-sub">Meta Pixel + Conversions API: всяко събитие от браузъра и от сървъра с общ event_id, Purchase от сървъра при създаване на поръчката, максимално пълни данни за съвпадение.</p>';
		$tabs = array( 'settings' => '⚙️ Настройки', 'status' => '🩺 Състояние', 'log' => '📜 Дневник', 'test' => '🧪 Тест' );
		echo '<nav class="nav-tab-wrapper am-tabs">';
		foreach ( $tabs as $k => $l ) echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $k ) ) ) . '">' . esc_html( $l ) . '</a>';
		echo '</nav>';
		self::message( $msg );
		switch ( $tab ) {
			case 'status': self::render_status(); break;
			case 'log':    self::render_log(); break;
			case 'test':   self::render_test(); break;
			default:       self::render_settings();
		}
		echo '</div>';
	}

	private static function message( $msg ) {
		$m = array(
			'saved' => array( 'success', 'Настройките са записани.' ), 'test_ok' => array( 'success', 'Тестовите събития стигнаха до Meta — виж ги в Events Manager → Test events.' ),
			'test_fail' => array( 'error', 'Тестът не мина — отговорът на Meta е по-долу.' ), 'cleared' => array( 'success', 'Дневникът е изчистен.' ),
			'queue' => array( 'info', sprintf( 'Опашката: изпратени %d · за повторение %d · отпаднали %d.', (int) ( $_GET['sent'] ?? 0 ), (int) ( $_GET['failed'] ?? 0 ), (int) ( $_GET['dropped'] ?? 0 ) ) ),
		);
		if ( isset( $m[ $msg ] ) ) echo '<div class="notice notice-' . esc_attr( $m[ $msg ][0] ) . ' is-dismissible"><p>' . esc_html( $m[ $msg ][1] ) . '</p></div>';
	}

	private static function render_settings() {
		$s = Settings::all(); $tok = Settings::token();
		$chk = function ( $name, $on, $label, $hint = '' ) { echo '<label class="am-chk"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $on, true, false ) . '> <span>' . wp_kses_post( $label ) . '</span></label>' . ( $hint ? '<p class="description">' . wp_kses_post( $hint ) . '</p>' : '' ); };
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ansa_meta_save' ); echo '<input type="hidden" name="action" value="ansa_meta_save">';

		echo '<div class="am-card"><h2>Връзка с Meta</h2><table class="form-table">';
		echo '<tr><th>Включено</th><td>'; $chk( 'enabled', $s['enabled'], 'Плъгинът праща събития', 'Аварийно изключване и от wp-config.php: <code>define( \'ANSA_META_DISABLED\', true );</code>' ); echo '</td></tr>';
		echo '<tr><th><label for="pixel_id">Pixel ID (Dataset ID)</label></th><td><input type="text" id="pixel_id" name="pixel_id" class="regular-text" value="' . esc_attr( $s['pixel_id'] ) . '"' . ( Settings::pixel_from_constant() ? ' readonly' : '' ) . ' inputmode="numeric"><p class="description">Events Manager → Data sources → твоят Pixel → ID-то под името му.' . ( Settings::pixel_from_constant() ? ' <b>Идва от константата ANSA_META_PIXEL_ID.</b>' : '' ) . '</p></td></tr>';
		echo '<tr><th><label for="token">Access token</label></th><td><input type="password" id="token" name="token" class="regular-text" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== $tok ? 'има token (…' . substr( $tok, -4 ) . ') — остави празно, за да го пазиш' : 'EAAB…' ) . '"' . ( Settings::token_from_constant() ? ' readonly' : '' ) . '>';
		if ( '' !== $tok && ! Settings::token_from_constant() ) echo ' <label class="am-inline"><input type="checkbox" name="token_clear" value="1"> изтрий token-а</label>';
		echo '<p class="description">Events Manager → Settings (на Pixel-а) → Conversions API → „Generate access token“ (или System User token с права ads_management). ' . ( Settings::token_from_constant() ? '<b>Идва от константата ANSA_META_TOKEN.</b>' : 'Може и в wp-config.php: <code>define( \'ANSA_META_TOKEN\', \'…\' );</code>' ) . '</p></td></tr>';
		echo '<tr><th><label for="test_code">Test event code</label></th><td><input type="text" id="test_code" name="test_code" class="regular-text" value="' . esc_attr( $s['test_code'] ) . '" placeholder="TEST12345"><p class="description">Events Manager → Test events. Ползва се само от таб 🧪 Тест; реалните събития тръгват без него.</p></td></tr>';
		echo '<tr><th><label for="api_version">Graph API версия</label></th><td><input type="text" id="api_version" name="api_version" class="small-text" value="' . esc_attr( $s['api_version'] ) . '"><p class="description">Ако Meta върне грешка 2635 (остаряла версия) — вдигни я тук (напр. v25.0). Всяка версия живее поне 2 години.</p></td></tr>';
		echo '</table></div>';

		echo '<div class="am-card"><h2>Как се праща</h2><table class="form-table">';
		echo '<tr><th>Pixel в браузъра</th><td>'; $chk( 'browser', $s['browser'], 'ansa™ Meta зарежда Meta Pixel-а (fbq) и праща събитията и от браузъра', '<b>Препоръчително включено</b> — само така event_id-тата съвпадат и Meta дедуплицира. <b>Изключи Meta/Facebook в Pixel Manager</b> (и в „Facebook for WooCommerce“, PixelYourSite, GTM), иначе Pixel-ът е два пъти и събитията се броят двойно. GA4 в Pixel Manager остава.' ); echo '</td></tr>';
		echo '<tr><th>content_ids</th><td><select name="id_format">';
		foreach ( array( 'id' => 'ID на продукта/вариацията (напр. 3995) — по подразбиране', 'sku' => 'SKU (ако е празно — ID)', 'wc_post_id' => 'wc_post_id_ID (форматът на „Facebook for WooCommerce“, ако каталогът в Meta е правен с него)' ) as $k => $l ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['id_format'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		echo '</select><p class="description">Трябва да съвпада с „Content ID“ в каталога в Meta (Commerce Manager), за да работят динамичните реклами. Без каталог — няма значение.</p></td></tr>';
		echo '<tr><th>Purchase тръгва</th><td><select name="purchase_on">';
		foreach ( array( 'order' => 'при създаване на поръчката (и за карта преди плащането — както Shopify; най-много и най-навременни данни)', 'paid' => 'при плащане (карта = след успешно плащане; наложен платеж = веднага при поръчката)' ) as $k => $l ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['purchase_on'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		echo '</select><p class="description">Във всеки случай — веднъж на поръчка, от сървъра, с event_id-то, което носи и браузърът на „Благодаря“.</p></td></tr>';
		echo '<tr><th>PageView от сървъра</th><td>'; $chk( 'mirror_pageview', $s['mirror_pageview'], 'PageView се праща и през Conversions API (най-много заявки, но най-пълни аудитории за ретаргетинг)' ); echo '</td></tr>';
		echo '<tr><th>Събития</th><td>';
		foreach ( Settings::events_list() as $k => $l ) { $chk( 'events[' . $k . ']', $s['events'][ $k ] ?? 1, '<code>' . esc_html( $k ) . '</code> ' . esc_html( substr( $l, strlen( $k ) ) ) ); }
		echo '</td></tr>';
		echo '</table></div>';

		echo '<div class="am-card"><h2>Кого не броим</h2><table class="form-table">';
		echo '<tr><th>Роли</th><td>';
		foreach ( Settings::roles() as $k => $l ) { echo '<label class="am-chk"><input type="checkbox" name="exclude_roles[]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, (array) $s['exclude_roles'], true ), true, false ) . '> <span>' . esc_html( $l ) . ' <code>' . esc_html( $k ) . '</code></span></label>'; }
		echo '<p class="description">Отметнатите роли не пращат нищо (нито Pixel, нито CAPI) — екипът не влиза в статистиката и аудиториите.</p>';
		echo '</td></tr>';
		echo '<tr><th><label for="internal_ips">Вътрешни IP</label></th><td><textarea id="internal_ips" name="internal_ips" class="large-text code" rows="3" placeholder="93.123.45.67&#10;10.0.0.0/8">' . esc_textarea( $s['internal_ips'] ) . '</textarea><p class="description">По един на ред, IPv4 с незадължителен /CIDR. Текущият ти адрес: <code>' . esc_html( Identity::client_ip() ) . '</code></p></td></tr>';
		echo '<tr><th>Ботове</th><td>'; $chk( 'bot_filter', $s['bot_filter'], 'Пропускай ботове и празен user agent (crawler, lighthouse, facebookexternalhit, …)' ); echo '</td></tr>';
		echo '<tr><th><label for="consent_cookie">Съгласие (бисквитки)</label></th><td><input type="text" id="consent_cookie" name="consent_cookie" class="regular-text" value="' . esc_attr( $s['consent_cookie'] ) . '" placeholder="напр. cmplz_marketing"> съдържа <input type="text" name="consent_value" class="regular-text" value="' . esc_attr( $s['consent_value'] ) . '" placeholder="напр. allow"><p class="description">Празно = без проверка (така работи и Pixel-ът на FunnelKit сега). На ansa.bg има Complianz в opt-in режим: за да чакаме съгласието — <code>cmplz_marketing</code> съдържа <code>allow</code> (meta.js тръгва веднага щом банерът е приет, слуша и събитията на Complianz); без нея нищо не се праща, а при даване на съгласие meta.js тръгва сам (слуша бисквитката / събитие <code>ansa-meta:consent</code>).</p></td></tr>';
		echo '<tr><th>Limited Data Use</th><td>'; $chk( 'ldu', $s['ldu'], 'data_processing_options = [LDU] (само за трафик от САЩ/Калифорния; за България — изключено)' ); echo '</td></tr>';
		echo '</table></div>';

		echo '<div class="am-card"><h2>Дневник</h2><table class="form-table">';
		echo '<tr><th><label for="log_keep">Пази</label></th><td><input type="number" id="log_keep" name="log_keep" class="small-text" min="50" max="20000" value="' . esc_attr( (int) $s['log_keep'] ) . '"> реда (най-новите)</td></tr>';
		echo '<tr><th>Debug</th><td>'; $chk( 'debug', $s['debug'], 'Записвай целия payload на всяко събитие (хеширани данни; за разследване — иначе само резюме)' ); echo '</td></tr>';
		echo '</table></div>';

		submit_button( 'Запази' );
		echo '</form>';
	}

	private static function render_status() {
		$rows = Doctor::rows();
		echo '<div class="am-card"><table class="widefat striped am-doc"><tbody>';
		foreach ( $rows as $r ) { echo '<tr class="am-' . esc_attr( $r['level'] ) . '"><th>' . esc_html( $r['label'] ) . '</th><td>' . wp_kses_post( $r['value'] ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		$st = Capi::stats( 24 );
		echo '<div class="am-card"><h2>Последните 24 часа</h2>';
		if ( $st['by'] ) {
			echo '<table class="widefat striped"><thead><tr><th>Събитие</th><th>Изпратени</th><th>Грешки</th><th>Средно ms</th></tr></thead><tbody>';
			foreach ( $st['by'] as $b ) echo '<tr><td><code>' . esc_html( $b['event_name'] ) . '</code></td><td>' . (int) $b['ok'] . '</td><td>' . ( (int) $b['fail'] ? '<b class="am-red">' . (int) $b['fail'] . '</b>' : '0' ) . '</td><td>' . (int) $b['ms'] . '</td></tr>';
			echo '</tbody></table>';
		} else { echo '<p>Още няма изпратени събития.</p>'; }
		if ( $st['purchase_n'] ) {
			echo '<h3>Данни за съвпадение в Purchase (последните ' . (int) $st['purchase_n'] . ' до 30 дни)</h3><p class="description">Колко от поръчките носят всеки параметър. Целта: em, ph, fn, ln, ct, zp, country, external_id, client_ip_address, client_user_agent, fbp — почти 100 %; fbc — само при клик от реклама.</p><div class="am-keys">';
			foreach ( $st['purchase_keys'] as $k => $n ) { $pct = (int) round( 100 * $n / max( 1, $st['purchase_n'] ) ); echo '<span class="am-key' . ( $pct >= 90 ? ' ok' : ( $pct >= 50 ? ' warn' : ' bad' ) ) . '"><code>' . esc_html( $k ) . '</code> ' . $pct . ' %</span>'; }
			echo '</div>';
		}
		echo '</div>';
		echo '<div class="am-card am-actions"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'ansa_meta_queue' ); echo '<input type="hidden" name="action" value="ansa_meta_queue"><button class="button">Изпрати опашката сега (' . (int) $st['queue'] . ')</button></form></div>';
	}

	private static function render_log() {
		global $wpdb; $l = Schema::log_table();
		$ev = sanitize_key( (string) ( $_GET['ev'] ?? '' ) ); $only = ! empty( $_GET['fail'] );
		$w = array(); if ( '' !== $ev ) $w[] = $wpdb->prepare( 'event_name = %s', $ev ); if ( $only ) $w[] = 'ok = 0';
		$where = $w ? ' WHERE ' . implode( ' AND ', $w ) : '';
		$rows = $wpdb->get_results( "SELECT * FROM $l$where ORDER BY id DESC LIMIT 150", ARRAY_A );
		$names = $wpdb->get_col( "SELECT DISTINCT event_name FROM $l ORDER BY event_name" );
		echo '<div class="am-card am-filter"><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="log">';
		echo '<select name="ev"><option value="">всички събития</option>'; foreach ( (array) $names as $n ) echo '<option value="' . esc_attr( $n ) . '"' . selected( $ev, $n, false ) . '>' . esc_html( $n ) . '</option>'; echo '</select> ';
		echo '<label><input type="checkbox" name="fail" value="1"' . checked( $only, true, false ) . '> само грешки</label> <button class="button">Филтрирай</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Изчистване на дневника?\')">'; wp_nonce_field( 'ansa_meta_clear' ); echo '<input type="hidden" name="action" value="ansa_meta_clear"><button class="button">Изчисти дневника</button></form></div>';
		echo '<div class="am-card"><table class="widefat striped am-log"><thead><tr><th>Кога</th><th>Събитие</th><th>Откъде</th><th>Статус</th><th>ms</th><th>Отговор</th><th>Данни</th></tr></thead><tbody>';
		if ( ! $rows ) echo '<tr><td colspan="7">Няма редове.</td></tr>';
		foreach ( (array) $rows as $r ) {
			$p = json_decode( (string) $r['payload'], true ); $keys = is_array( $p ) ? ( isset( $p['user'] ) ? (array) $p['user'] : array_keys( (array) ( $p['user_data'] ?? array() ) ) ) : array();
			$val = is_array( $p ) ? ( isset( $p['value'] ) ? $p['value'] : ( $p['custom_data']['value'] ?? null ) ) : null; $cur = is_array( $p ) ? ( $p['currency'] ?? ( $p['custom_data']['currency'] ?? '' ) ) : '';
			$oid = is_array( $p ) ? ( $p['order_id'] ?? ( $p['custom_data']['order_id'] ?? '' ) ) : '';
			$url = is_array( $p ) ? (string) ( $p['url'] ?? ( $p['event_source_url'] ?? '' ) ) : '';
			echo '<tr class="' . ( $r['ok'] ? '' : 'am-bad' ) . '"><td>' . esc_html( get_date_from_gmt( $r['at'], 'd.m H:i:s' ) ) . '</td><td><code>' . esc_html( $r['event_name'] ) . '</code><br><small title="event_id">' . esc_html( substr( $r['event_id'], 0, 8 ) ) . '…</small></td><td>' . esc_html( $r['source'] ) . '</td><td>' . ( $r['ok'] ? '<span class="am-ok">ok</span>' : '<b class="am-red">грешка ' . (int) $r['http'] . '</b>' ) . '</td><td>' . (int) $r['ms'] . '</td><td class="am-resp">' . esc_html( $r['response'] ) . '</td>';
			echo '<td class="am-data">' . ( null !== $val ? '<b>' . esc_html( number_format( (float) $val, 2 ) . ' ' . $cur ) . '</b> ' : '' ) . ( $oid ? '#' . esc_html( $oid ) . ' ' : '' ) . '<small>' . esc_html( implode( ' ', $keys ) ) . '</small>' . ( $url ? '<br><small class="am-url">' . esc_html( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' ) . '</small>' : '' );
			if ( Settings::get( 'debug' ) && is_array( $p ) && isset( $p['event_name'] ) ) echo '<details><summary>payload</summary><pre>' . esc_html( wp_json_encode( $p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_test() {
		$res = get_transient( 'ansa_meta_test_' . get_current_user_id() );
		echo '<div class="am-card"><h2>Тестови събития към Events Manager</h2>';
		echo '<ol><li>Events Manager → твоят Pixel → таб <b>Test events</b> → копирай кода <code>TEST…</code>.</li><li>Постави го тук и натисни „Изпрати“. Пращаме PageView + Purchase (12,34 ' . esc_html( Catalog::currency() ) . ') с пълни тестови данни за съвпадение.</li><li>В Test events до секунди се появяват като <b>Server</b> събития; при „Browser + Server“ дедупликацията се вижда при реални събития от сайта (отвори продукт в друг браузър с <code>?test_event_code=</code> не трябва — просто гледай Overview след ~20 мин).</li></ol>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'ansa_meta_test' ); echo '<input type="hidden" name="action" value="ansa_meta_test">';
		echo '<p><input type="text" name="test_code" class="regular-text" value="' . esc_attr( Settings::get( 'test_code', '' ) ) . '" placeholder="TEST12345"> <button class="button button-primary"' . ( Plugin::enabled() ? '' : ' disabled' ) . '>Изпрати</button>' . ( Plugin::enabled() ? '' : ' <span class="am-red">— първо Pixel ID и token в Настройки</span>' ) . '</p></form>';
		if ( is_array( $res ) ) {
			echo '<h3>Последен отговор</h3><p>' . ( $res['ok'] ? '<span class="am-ok">OK</span> — events_received=' . (int) $res['received'] . ( $res['trace'] ? ', fbtrace_id ' . esc_html( $res['trace'] ) : '' ) : '<b class="am-red">Грешка</b> HTTP ' . (int) $res['http'] . ': ' . esc_html( $res['error'] ) ) . ' · ' . (int) $res['ms'] . ' ms</p>';
			if ( ! empty( $res['body'] ) ) echo '<pre class="am-pre">' . esc_html( wp_json_encode( $res['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
		}
		echo '</div>';
		echo '<div class="am-card"><h2>Проверка на живо</h2><ul class="am-list"><li><b>Events Manager → Overview</b>: всяко събитие трябва да е „Browser · Server“ с <b>Deduplicated</b> (по event_id). Ако има само Browser — REST-ът не стига (виж Дневник); ако има само Server — Pixel-ът не се зарежда (Pixel в браузъра изключен, consent, delay JS).</li><li><b>Event Match Quality</b> (Overview → Purchase → детайли): целта е 8+ („Great“). Параметрите по-горе в 🩺 Състояние показват какво носи всяка поръчка.</li><li><b>Meta Pixel Helper</b> (Chrome разширение): на продуктова страница — PageView + ViewContent с eventID; на чекаут — InitiateCheckout; на „Благодаря“ — Purchase с value/currency/contents.</li><li>REST ping: <code>' . esc_html( rest_url( Rest::NS . '/ping' ) ) . '</code></li></ul></div>';
	}

	private static function css() {
		return '.am-wrap h1 small{font-size:13px;color:#777;font-weight:400;margin-left:6px}.am-sub{color:#555;max-width:900px}.am-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 18px 14px;margin:14px 0}.am-card h2{font-size:15px;margin:12px 0 4px}.am-card .form-table th{width:200px}.am-chk{display:block;margin:3px 0}.am-inline{margin-left:8px}.am-doc th{width:260px;text-align:left}.am-doc tr.am-bad th,.am-doc tr.am-bad td{color:#b32d2e;font-weight:600}.am-doc tr.am-warn th{color:#996800}.am-ok{color:#008a20;font-weight:600}.am-red{color:#b32d2e}.am-log td{vertical-align:top;font-size:12px}.am-log .am-resp{max-width:260px;word-break:break-word}.am-log .am-data small{color:#666}.am-log .am-url{color:#2271b1}.am-log tr.am-bad td{background:#fff5f5}.am-log pre{max-height:260px;overflow:auto;font-size:11px;background:#f6f7f7;padding:6px}.am-keys{display:flex;flex-wrap:wrap;gap:6px}.am-key{border:1px solid #ccc;border-radius:12px;padding:2px 10px;font-size:12px}.am-key.ok{border-color:#008a20;background:#edfaef}.am-key.warn{border-color:#dba617;background:#fff8e5}.am-key.bad{border-color:#d63638;background:#fcf0f1}.am-actions form,.am-filter form{display:inline-block;margin:8px 12px 4px 0}.am-pre{background:#f6f7f7;padding:10px;overflow:auto;max-height:300px}.am-list li{margin-bottom:6px}';
	}
}
