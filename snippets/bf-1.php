<?php
/**
 * ansa™ Промо · Snippet BF-1 — self-check на „Черен петък 2026“ (второто промо; плъгин ≥ 1.45.0).
 *
 * Code Snippets → Add New → постави всичко БЕЗ първия ред „<?php“ → Save & Activate (Admin only е достатъчно).
 * Отчетът: /wp-admin/ (Табло, Плъгини, екрана „ansa Промо“) — поле за копиране; или /wp-admin/?ansa_bf_report=1 (чист текст).
 * Чете, не променя нищо (само GET/POST към собствения сайт като анонимен — страницата и ajax-а на фронта). След отчета — деактивирай снипета.
 */

add_action( 'admin_init', function () {
	if ( ! isset( $_GET['ansa_bf_report'] ) || ! current_user_can( 'manage_options' ) ) return;
	nocache_headers(); header( 'Content-Type: text/plain; charset=utf-8' );
	echo ansa_bf_snippet1_report(); exit;
} );
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) return;
	$s = get_current_screen();
	if ( ! $s || ! in_array( $s->id, array( 'dashboard', 'plugins', 'toplevel_page_ansa-promo' ), true ) ) return;
	echo '<div class="notice notice-info"><p><b>ansa™ Промо · Snippet BF-1 (Черен петък 2026)</b> — копирай всичко от полето и го прати в чата (после деактивирай снипета):</p>'
		. '<textarea readonly onclick="this.select()" style="width:100%;height:360px;font:12px/1.4 Menlo,Consolas,monospace;white-space:pre">' . esc_textarea( ansa_bf_snippet1_report() ) . '</textarea></div>';
} );

if ( ! function_exists( 'ansa_bf_snippet1_report' ) ) {
function ansa_bf_snippet1_report() {
	static $cache = null; if ( null !== $cache ) return $cache;
	$L = array();
	$p = function ( $k, $v ) use ( &$L ) { $L[] = str_pad( $k, 34 ) . ' ' . ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v ); };
	$h = function ( $t ) use ( &$L ) { $L[] = ''; $L[] = '── ' . $t . ' ' . str_repeat( '─', max( 0, 60 - mb_strlen( $t ) ) ); };
	$yn = function ( $b ) { return $b ? 'yes' : 'no'; };
	$L[] = '=== ansa™ Промо · Snippet BF-1 · ' . current_time( 'Y-m-d H:i:s' ) . ' · плъгин ' . ( defined( 'ANSA_PROMO_VER' ) ? ANSA_PROMO_VER : '—' ) . ' · DB ' . get_option( 'ansa_promo_db_ver', '—' ) . ' ===';
	if ( ! class_exists( 'AnsaPromo\\BF\\Config' ) ) { $L[] = 'AnsaPromo\\BF\\Config ЛИПСВА — плъгинът не е зареден или е под 1.45.0.'; return $cache = implode( "\n", $L ); }
	$C = 'AnsaPromo\\BF\\Config'; $Pg = 'AnsaPromo\\BF\\Page'; $Ld = 'AnsaPromo\\BF\\Leads'; $Cp = 'AnsaPromo\\BF\\Copy';

	$h( 'Конфиг (ansa_promo_bf_store)' );
	$store = get_option( 'ansa_promo_bf_store' );
	$p( 'option', is_array( $store ) ? 'има · keys: ' . implode( ',', array_keys( $store ) ) : 'НЯМА (още не е записвано нищо — чернова = defaults)' );
	$p( 'version / published_at', $C::version() . ' / ' . ( $C::published_at() ? date_i18n( 'Y-m-d H:i', $C::published_at() ) : '—' ) . ' · history=' . count( $C::history() ) );
	$p( 'has_draft / has_published', $yn( $C::has_draft() ) . ' / ' . $yn( $C::has_published() ) );
	$d = $C::get_draft(); $pub = $C::get_published();
	$p( 'чернова: id / enabled / slug', $d['id'] . ' / ' . $yn( $d['enabled'] ) . ' / ' . $d['slug'] );
	$p( 'чернова: фази', implode( ' · ', array_map( function ( $k, $v ) { return $k . '=' . $v; }, array_keys( $d['phases'] ), $d['phases'] ) ) . ( '' !== $d['force'] ? ' · FORCE=' . $d['force'] : '' ) );
	$p( 'чернова: фаза сега', $C::phase_now( $d ) . ' (' . $C::phase_label( $C::phase_now( $d ) ) . ') · край ' . $C::fmt_dt( $C::phase_end( $d ) ) );
	$p( 'чернова: cold', json_encode( $d['cold'] ) );
	$p( 'чернова: seats', json_encode( $d['seats'] ) . ' → сега ' . $C::seats_now( $d ) . ' · +7 дни ' . $C::seats_now( $d, $C::now() + 7 * DAY_IN_SECONDS ) . ' · +30 дни ' . $C::seats_now( $d, $C::now() + 30 * DAY_IN_SECONDS ) );
	$p( 'чернова: aff', json_encode( $d['aff'] ) );
	$p( 'чернова: early', 'token=' . ( '' !== $d['early']['token'] ? substr( $d['early']['token'], 0, 6 ) . '…(' . strlen( $d['early']['token'] ) . ')' : 'ПРАЗЕН' ) . ' · cookie ' . $d['early']['cookie_days'] . ' дни' );
	$p( 'чернова: ship / checkout', json_encode( $d['ship'] ) . ' / ' . json_encode( $d['checkout'] ) );
	$p( 'чернова: leads / orders', json_encode( $d['leads'], JSON_UNESCAPED_UNICODE ) . ' / ' . json_encode( $d['orders'] ) );
	$p( 'чернова: preview password', '' !== $d['preview']['password'] ? 'има' : 'няма' );
	$p( 'публикувано: enabled / live', $yn( $pub['enabled'] ) . ' / ' . $yn( $C::is_live( $pub ) ) . ' · фаза ' . $C::phase_now( $pub ) );
	$p( 'normalize idempotent', $yn( $C::normalize( $d ) === $d ) );

	$h( 'Продукти (чернова → WooCommerce)' );
	foreach ( $d['products'] as $pr ) {
		$pid = '' !== $pr['sku'] ? ( AnsaPromo\Catalog::find_by_sku( $pr['sku'] ) ?: (int) $pr['product_id'] ) : (int) $pr['product_id'];
		$wc = $pid ? AnsaPromo\Catalog::wc_line( $pid ) : null;
		$flags = ( $pr['gift_only'] ? ' gift' : '' ) . ( $pr['is_new'] ? ' new' : '' ) . ( $pr['fav'] ? ' fav' : '' ) . ( $pr['enabled'] ? '' : ' OFF' );
		$p( $pr['key'] . ( '' !== $pr['sku'] ? ' [' . $pr['sku'] . ']' : '' ), ! $pid ? 'БЕЗ product/sku' : ( '#' . $pid . ' ' . $wc['wc_name'] . ' · ' . ( $wc['ok'] ? 'OK' : 'НЕ: ' . $wc['note'] ) . ' · ' . $wc['price'] . ' € · stock=' . $yn( $wc['stock'] ) . ( $wc['variation_id'] ? ' · var#' . $wc['variation_id'] : '' ) ) . ' ·' . $flags );
	}
	$live = $Pg::products( $d, true );
	$p( 'живи продукти в runtime', count( $live ) . ' (' . implode( ',', array_keys( $live ) ) . ')' );
	$p( '  само подарък / нови', count( array_filter( $live, function ( $x ) { return $x['giftOnly']; } ) ) . ' / ' . count( array_filter( $live, function ( $x ) { return $x['isNew']; } ) ) . ' · без снимка: ' . count( array_filter( $live, function ( $x ) { return '' === $x['img']; } ) ) );
	$m = $Pg::mask( $d ); $p( 'маска (' . ( $d['aff']['mask_sku'] ?: '#' . $d['aff']['mask_product_id'] ) . ')', $m ? '#' . $m['id'] . ' ' . $m['name'] . ' · ' . $m['price'] . ' € · stock=' . $yn( $m['stock'] ) : 'НЕ Е НАМЕРЕНА' );
	$p( 'лого', $Pg::logo( $d ) );
	if ( class_exists( 'AnsaPromo\\BF\\Boost' ) ) {
		$Bo = 'AnsaPromo\\BF\\Boost'; $pv = $Bo::preview( $d ); $lg = $Bo::log();
		$p( 'ваучери за списъка (1.46.0)', json_encode( $d['listv'], JSON_UNESCAPED_UNICODE ) . ' · до ' . $C::fmt_dt( $Bo::until_ts( $d ) ) . ' · издадени=' . $yn( $Bo::is_done( $d ) ) . ' · ' . json_encode( $pv ) . ( $lg ? ' · последно ' . $lg['at'] . ' ' . (int) $lg['revived'] . '/' . (int) $lg['vip'] . ' грешки ' . count( $lg['errors'] ) : '' ) );
		$lp = $Bo::lucky_product( $d ); $p( 'попъпът „късметлия“', json_encode( $d['lucky'] ) . ' → ' . ( $lp ? '#' . ( $lp['vid'] ?: $lp['id'] ) . ' ' . $lp['name'] . ' · ' . $lp['price'] . ' € · stock=' . $yn( $lp['stock'] ) : 'ПРОДУКТЪТ НЕ Е НАМЕРЕН' ) );
		$p( 'merge tags', 'bf_early_url(test) → ' . $Bo::merge( 'bf_early_url', array( 'email' => 'test@example.com' ) ) . "\n" . str_repeat( ' ', 35 ) . 'bf_voucher(test) → ' . ( $Bo::merge( 'bf_voucher', array( 'email' => 'test@example.com' ) ) ?: '(няма — нормално)' ) );
	}

	$h( 'Копи-регистър' );
	$reg = $Cp::defaults(); $p( 'ключове', count( $reg ) . ' · групи ' . count( $Cp::groups() ) . ' · override-и: ' . count( $d['copy'] ) );
	$bad = array(); foreach ( (array) $d['copy'] as $ck => $cv ) { foreach ( $Cp::validate( $cv ) as $e ) { $bad[] = $ck . ': ' . $e; } } $p( 'validate(override-и)', $bad ? 'ПРОБЛЕМИ: ' . implode( '; ', $bad ) : 'OK' );
	$p( 'пример render', wp_strip_all_tags( $Cp::render( $Cp::effective( $d['copy'] )['cold.seats'], array( 'seats' => $C::seats_now( $d ), 'max' => $d['seats']['max'], 'left' => $d['seats']['max'] - $C::seats_now( $d ) ) ) ) );

	$h( 'Адреси' );
	foreach ( $Pg::urls( $d ) as $k => $u ) { $p( '  ' . $k, $u ); }
	$p( 'шорткодове', '[ansa_bf_early_url] → ' . do_shortcode( '[ansa_bf_early_url]' ) . "\n" . str_repeat( ' ', 35 ) . '[ansa_bf_url] → ' . do_shortcode( '[ansa_bf_url utm_content="test"]' ) );
	$p( 'preview линк', $Pg::preview_link( $d ) );

	$h( 'Таблици (DB ver ' . get_option( 'ansa_promo_db_ver', '—' ) . ')' );
	global $wpdb; $t = AnsaPromo\Schema::table( 'leads' );
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM $t" ); $need = array( 'promo', 'kind', 'bonus', 'ref_code', 'voucher', 'voucher_exp', 'voucher_order_id' ); $miss = array_diff( $need, (array) $cols );
	$p( 'leads: колони BF', $miss ? 'ЛИПСВАТ: ' . implode( ',', $miss ) : 'OK (' . implode( ',', $need ) . ')' );
	$idx = $wpdb->get_col( "SHOW INDEX FROM $t", 2 ); $p( 'leads: индекси', implode( ',', array_unique( (array) $idx ) ) );
	$st = $Ld::stats(); $p( 'leads BF: stats', json_encode( $st ) );
	foreach ( array_slice( $Ld::rows( 5 ), 0, 5 ) as $r ) { $p( '  #' . $r['id'], $r['created_at'] . ' · ' . $r['kind'] . ( $r['bonus'] ? '/' . $r['bonus'] : '' ) . ' · ' . substr( $r['email'], 0, 3 ) . '…@' . substr( strrchr( $r['email'], '@' ), 1 ) . ( $r['voucher'] ? ' · ' . $r['voucher'] . ' до ' . $r['voucher_exp'] . ( $r['voucher_order_id'] ? ' ИЗПОЛЗВАН #' . $r['voucher_order_id'] : '' ) : '' ) . ( $r['ref_code'] ? ' · ref ' . $r['ref_code'] : '' ) . ' · fka=' . ( $r['fka'] ?: '—' ) ); }

	$h( 'FunnelKit (лийдове → лист + таг)' );
	$p( 'Lead_Sync::available', $yn( AnsaPromo\Lead_Sync::available() ) );
	if ( AnsaPromo\Lead_Sync::available() ) {
		$p( 'лист „' . $d['leads']['list'] . '“', ( $id = AnsaPromo\Merge::list_id( $d['leads']['list'] ) ) > 0 ? 'id ' . $id : 'НЯМА (прави се при първия лийд)' );
		foreach ( array( 'tag_cold', 'tag_aff' ) as $k ) { $p( 'таг „' . $d['leads'][ $k ] . '“', ( $id = AnsaPromo\Merge::tag_id( $d['leads'][ $k ] ) ) > 0 ? 'id ' . $id : 'НЯМА (прави се при първия лийд)' ); }
		$p( 'таг на поръчката „' . $d['orders']['fka_tag'] . '“', ( $id = AnsaPromo\Merge::tag_id( $d['orders']['fka_tag'] ) ) > 0 ? 'id ' . $id : 'НЯМА (прави се при първата поръчка)' );
		$p( 'ensure_terms', $yn( $Ld::ensure_terms() ) . ' · option ansa_promo_bf_fka_ensured=' . ( get_option( 'ansa_promo_bf_fka_ensured' ) ? 'има' : '—' ) );
	}

	$h( 'ansa Partner (?ref=КОД)' );
	$p( 'AP_Coupons / AP_Partners', $yn( class_exists( 'AP_Coupons' ) ) . ' / ' . $yn( class_exists( 'AP_Partners' ) ) );
	if ( class_exists( 'AP_Coupons' ) ) {
		$rows = $wpdb->get_results( "SELECT c.coupon_code, p.display_name FROM {$wpdb->prefix}ansa_partner_coupons c JOIN {$wpdb->prefix}ansa_partner_partners p ON p.partner_id = c.partner_id WHERE c.active = 1 AND p.status = 'active' ORDER BY c.partner_id ASC LIMIT 3", ARRAY_A );
		$p( 'активни кодове', $rows ? count( $rows ) . '+ (' . implode( ', ', array_column( $rows, 'coupon_code' ) ) . ')' : 'няма' );
		foreach ( (array) $rows as $row ) { $pt = $Pg::partner( $row['coupon_code'] ); $p( '  ' . $row['coupon_code'], $pt ? 'OK → „' . $pt['name'] . '“ (partner ' . $pt['partner_id'] . ', „' . $row['display_name'] . '“)' : 'Page::partner() НЕ ГО НАМИРА' ); }
	}

	$h( 'Фронтът (анонимни заявки към сайта)' );
	$url = $Pg::url( $pub ); $r = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => false, 'headers' => array( 'User-Agent' => 'ansa-bf-snippet' ) ) );
	if ( is_wp_error( $r ) ) { $p( 'GET ' . $url, 'ГРЕШКА: ' . $r->get_error_message() ); }
	else {
		$body = (string) wp_remote_retrieve_body( $r ); $code = wp_remote_retrieve_response_code( $r );
		$p( 'GET ' . $url, $code . ' · ' . strlen( $body ) . ' B · ansaBf=' . $yn( false !== strpos( $body, 'id="ansaBf"' ) ) . ' · runtime=' . $yn( false !== strpos( $body, 'AnsaBfRuntime' ) ) . ' · soon=' . $yn( false !== strpos( $body, '— скоро.' ) ) . ' · bf.js=' . $yn( false !== strpos( $body, 'assets/bf.js' ) ) . ' · cache: ' . ( wp_remote_retrieve_header( $r, 'x-flying-press-cache' ) ?: wp_remote_retrieve_header( $r, 'cache-control' ) ?: '—' ) );
		if ( preg_match( '/window\.AnsaBfRuntime=(\{.*?\});<\/script>/s', $body, $mm ) ) { $rt = json_decode( $mm[1], true ); if ( is_array( $rt ) ) { $p( '  runtime', 'ver ' . $rt['ver'] . ' · version ' . $rt['version'] . ' · enabled=' . $yn( $rt['enabled'] ) . ' · phase ' . $rt['phase'] . ' · products ' . count( $rt['products'] ) . ' · copy ' . count( $rt['copy'] ) . ' · seats ' . json_encode( $rt['seats'] ) . ' · nonce=' . $yn( ! empty( $rt['nonce'] ) ) ); } }
	}
	$aj = wp_remote_post( admin_url( 'admin-ajax.php?action=ansa_bf_runtime' ), array( 'timeout' => 15, 'sslverify' => false, 'body' => array( 'view' => '0', 'variant' => 'direct' ), 'headers' => array( 'User-Agent' => 'ansa-bf-snippet' ) ) );
	if ( is_wp_error( $aj ) ) { $p( 'ajax ansa_bf_runtime', 'ГРЕШКА: ' . $aj->get_error_message() ); }
	else { $j = json_decode( (string) wp_remote_retrieve_body( $aj ), true ); $p( 'ajax ansa_bf_runtime', wp_remote_retrieve_response_code( $aj ) . ' · success=' . $yn( ! empty( $j['success'] ) ) . ( ! empty( $j['data'] ) ? ' · phase ' . $j['data']['phase'] . ' · early_ok=' . $yn( ! empty( $j['data']['early_ok'] ) ) . ' · ctx=' . json_encode( $j['data']['ctx'] ?? null ) : ' · ' . substr( (string) wp_remote_retrieve_body( $aj ), 0, 120 ) ) ); }
	$lead = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 15, 'sslverify' => false, 'body' => array( 'action' => 'ansa_bf_lead', 'nonce' => 'x', 'email' => 'x@y.z' ), 'headers' => array( 'User-Agent' => 'ansa-bf-snippet' ) ) );
	$p( 'ajax ansa_bf_lead без nonce', is_wp_error( $lead ) ? 'ГРЕШКА: ' . $lead->get_error_message() : wp_remote_retrieve_response_code( $lead ) . ' (очаква се 403) · ' . substr( (string) wp_remote_retrieve_body( $lead ), 0, 80 ) );
	$add = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 15, 'sslverify' => false, 'body' => array( 'action' => 'ansa_bf_add', 'nonce' => 'x', 'mode' => 'promo' ), 'headers' => array( 'User-Agent' => 'ansa-bf-snippet' ) ) );
	$p( 'ajax ansa_bf_add без nonce', is_wp_error( $add ) ? 'ГРЕШКА: ' . $add->get_error_message() : wp_remote_retrieve_response_code( $add ) . ' (очаква се 403) · ' . substr( (string) wp_remote_retrieve_body( $add ), 0, 80 ) );

	$h( 'Поръчки' );
	$orders = class_exists( 'AnsaPromo\\BF\\Admin' ) ? AnsaPromo\BF\Admin::orders( 5 ) : array();
	$p( 'последни BF поръчки', count( $orders ) );
	foreach ( $orders as $o ) { $p( '  #' . $o->get_id(), $o->get_date_created()->date( 'd.m H:i' ) . ' · ' . $o->get_status() . ' · ' . $o->get_meta( '_ansa_bf_mode' ) . '/' . $o->get_meta( '_ansa_bf_phase' ) . '/' . $o->get_meta( '_ansa_bf_variant' ) . ' · платени ' . $o->get_meta( '_ansa_bf_paid' ) . ' + подаръци ' . $o->get_meta( '_ansa_bf_gifts' ) . ' · ' . $o->get_total() . ' € · ваучер ' . ( $o->get_meta( '_ansa_bf_voucher' ) ?: '—' ) . ' · ref ' . ( $o->get_meta( '_ansa_bf_ref' ) ?: '—' ) ); }

	$h( 'Средата' );
	$p( 'Сезонът (ansa_promo_store)', is_array( get_option( 'ansa_promo_store' ) ) ? 'има · live=' . $yn( AnsaPromo\Config::is_live( AnsaPromo\Config::get_published() ) ) : 'няма' );
	$p( 'FlyingPress / FunnelKit checkout', $yn( class_exists( 'FlyingPress\\Purge' ) ) . ' / ' . $yn( class_exists( 'WFACP_Core' ) ) );
	$gws = function_exists( 'WC' ) && method_exists( WC(), 'payment_gateways' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
	$p( 'гейтуей карта / cod', $gws ? ( isset( $gws[ $d['checkout']['card_gateway'] ] ) ? 'има' : 'НЯМА ' . $d['checkout']['card_gateway'] ) . ' / ' . ( isset( $gws[ $d['checkout']['cod_gateway'] ] ) ? 'има' : 'НЯМА ' . $d['checkout']['cod_gateway'] ) . ' · всички: ' . implode( ',', array_keys( $gws ) ) : '—' );

	return $cache = implode( "\n", $L );
}
}
