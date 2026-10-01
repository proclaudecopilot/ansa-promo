<?php
/**
 * ansa™ Промо · Snippet 1 — self-check на Фаза 1 (конфиг · регистър · продукти · страница).
 *
 * Code Snippets → Add New → постави всичко БЕЗ първия ред „<?php“ → Save & Activate (Admin only е достатъчно).
 * Отчетът: /wp-admin/ (Табло, Плъгини, екран „ansa Промо“) — поле за копиране; или /wp-admin/?ansa_promo_report=1 (чист текст).
 * Чете, не променя нищо (само две GET заявки към собствения сайт като анонимен). След отчета — деактивирай снипета.
 */

add_action( 'admin_init', function () {
	if ( ! isset( $_GET['ansa_promo_report'] ) || ! current_user_can( 'manage_options' ) ) return;
	nocache_headers(); header( 'Content-Type: text/plain; charset=utf-8' );
	echo ansa_promo_snippet1_report(); exit;
} );
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) return;
	$s = get_current_screen();
	if ( ! $s || ! in_array( $s->id, array( 'dashboard', 'plugins', 'toplevel_page_ansa-promo' ), true ) ) return;
	echo '<div class="notice notice-info"><p><b>ansa™ Промо · Snippet 1</b> — копирай всичко от полето и го прати в чата (после деактивирай снипета):</p>'
		. '<textarea readonly onclick="this.select()" style="width:100%;height:340px;font:12px/1.4 Menlo,Consolas,monospace;white-space:pre">' . esc_textarea( ansa_promo_snippet1_report() ) . '</textarea></div>';
} );

if ( ! function_exists( 'ansa_promo_snippet1_report' ) ) {
function ansa_promo_snippet1_report() {
	static $cache = null; if ( null !== $cache ) return $cache;
	$L = array();
	$p = function ( $k, $v ) use ( &$L ) { $L[] = str_pad( $k, 34 ) . ' ' . ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v ); };
	$h = function ( $t ) use ( &$L ) { $L[] = ''; $L[] = '── ' . $t . ' ' . str_repeat( '─', max( 0, 60 - mb_strlen( $t ) ) ); };
	$yn = function ( $b ) { return $b ? 'yes' : 'no'; };
	$L[] = '=== ansa™ Промо · Snippet 1 · ' . current_time( 'Y-m-d H:i:s' ) . ' · плъгин ' . ( defined( 'ANSA_PROMO_VER' ) ? ANSA_PROMO_VER : '—' ) . ' ===';
	if ( ! class_exists( 'AnsaPromo\\Config' ) ) { $L[] = 'AnsaPromo\\Config ЛИПСВА — плъгинът не е зареден или е под 1.0.2.'; return $cache = implode( "\n", $L ); }

	$h( 'Конфиг' );
	$store = get_option( 'ansa_promo_store' );
	$p( 'option ansa_promo_store', is_array( $store ) ? 'има · keys: ' . implode( ',', array_keys( $store ) ) : 'НЯМА' );
	$p( 'version / published_at', AnsaPromo\Config::version() . ' / ' . ( AnsaPromo\Config::published_at() ? date_i18n( 'Y-m-d H:i', AnsaPromo\Config::published_at() ) : '—' ) . ' · history=' . count( AnsaPromo\Config::history() ) );
	$p( 'has_draft / has_published', $yn( AnsaPromo\Config::has_draft() ) . ' / ' . $yn( AnsaPromo\Config::has_published() ) );
	$seed = get_option( 'ansa_promo_seed_report' ); $p( 'seed report', is_array( $seed ) ? $seed['at'] . ' · ' . implode( ', ', array_map( function ( $k, $v ) { return $k . '→' . $v; }, array_keys( $seed['products'] ), $seed['products'] ) ) : '—' );
	$draft = AnsaPromo\Config::get_draft(); $pub = AnsaPromo\Config::get_published();
	$p( 'draft: id/enabled/deadline', $draft['id'] . ' / ' . $yn( $draft['enabled'] ) . ' / ' . $draft['deadline'] . ' (ts ' . AnsaPromo\Config::deadline_ts( $draft ) . ')' );
	$p( 'draft: page_id / utm_param', $draft['page_id'] . ' / ' . $draft['utm_param'] );
	$p( 'draft: gate / timer / ship', json_encode( $draft['gate'] ) . ' / ' . json_encode( $draft['timer'] ) . ' / ' . $draft['ship']['paid'] );
	$p( 'draft: boxes', implode( ' | ', array_map( function ( $b ) { return $b['id'] . ':' . $b['packs'] . 'x −' . $b['pct'] . '% 🎟' . $b['tickets'] . ' rw=' . implode( '+', $b['rw'] ) . ' cosm=' . var_export( $b['cosm_pay'], true ); }, $draft['boxes'] ) ) );
	$p( 'draft: order / bgn / checkout', json_encode( $draft['order'] ) . ' / ' . json_encode( $draft['bgn'] ) . ' / ' . json_encode( $draft['checkout'] ) );
	$p( 'draft: rewards', json_encode( array( 'yacht' => $draft['rewards']['yacht'], 'book' => $draft['rewards']['book'], 'cosm' => $draft['rewards']['cosm'] ) ) );
	$p( 'draft: fit / problems', count( $draft['fit'] ) . ' / ' . count( $draft['problems'] ) );
	$p( 'published: live / products', $yn( AnsaPromo\Config::is_live( $pub ) ) . ' / ' . count( $pub['products'] ) );
	$p( 'normalize idempotent', $yn( AnsaPromo\Config::normalize( $draft ) === $draft ) );

	$h( 'Продукти (чернова → WC)' );
	foreach ( $draft['products'] as $pr ) {
		$wc = $pr['product_id'] ? AnsaPromo\Catalog::wc_line( $pr['product_id'] ) : null;
		$p( $pr['key'] . ' „' . $pr['name'] . '“', ! $pr['product_id'] ? 'БЕЗ product_id' : ( '#' . $pr['product_id'] . ' ' . $wc['wc_name'] . ' · ' . ( $wc['ok'] ? 'OK' : 'НЕ: ' . $wc['note'] ) . ' · ' . ( $wc['variation_id'] ? 'var#' . $wc['variation_id'] : 'simple' ) . ' · €' . $wc['price'] . ( null !== $wc['sale'] ? ' (sale €' . $wc['sale'] . ')' : '' ) . ' · stock=' . $yn( $wc['stock'] ) . ' · img=' . ( $wc['img'] ? 'yes' : 'NO' ) . ( $wc['note'] && $wc['ok'] ? ' · ' . $wc['note'] : '' ) ) );
	}
	$live = AnsaPromo\Frontend::products( $draft );
	$p( 'живи продукти в runtime', count( $live ) . ' (' . implode( ',', array_keys( $live ) ) . ')' );
	foreach ( array( 'book', 'cosm' ) as $rk ) { $id = (int) $draft['rewards'][ $rk ]['product_id']; $p( 'reward ' . $rk . ' product', $id ? '#' . $id . ' ' . ( function_exists( 'wc_get_product' ) && wc_get_product( $id ) ? wc_get_product( $id )->get_name() : 'НЕ СЪЩЕСТВУВА' ) : 'не е зададен (Фаза 4)' ); }

	$h( 'Копи-регистър' );
	$reg = AnsaPromo\Copy::defaults(); $p( 'ключове', count( $reg ) . ' · групи ' . count( AnsaPromo\Copy::groups() ) . ' · override-и: ' . count( $draft['copy'] ) );
	$bad = array(); foreach ( AnsaPromo\Copy::effective( $draft['copy'] ) as $k => $v ) { $e = AnsaPromo\Copy::validate( $v ); if ( $e ) $bad[] = $k . ': ' . implode( ';', $e ); }
	$p( 'невалидни плейсхолдъри', $bad ? implode( ' | ', $bad ) : 'няма' );
	$cyr = array(); foreach ( $reg as $k => $v ) { if ( preg_match( '/Козм\. сет|за €8 800|скреч|картонче/u', $v ) ) $cyr[] = $k; } $p( 'забранени фрази (D11/D4)', $cyr ? implode( ',', $cyr ) : 'няма' );
	$js = ANSA_PROMO_PATH . 'assets/promo.js'; $src = is_file( $js ) ? (string) file_get_contents( $js ) : '';
	preg_match_all( "/\\b(?:T|ck)\\('([a-z0-9_.]+)'/", $src, $m ); $used = array_unique( $m[1] );
	$dyn = array(); foreach ( array( 'tix', 'tix3', 'tix5', 'ship', 'book', 'cosm50', 'cosm1' ) as $r ) foreach ( array( 'sh', 't', 's' ) as $sfx ) $dyn[] = "rw.$r.$sfx"; foreach ( array( 's', 'm', 'l' ) as $b ) { $dyn[] = "fill.hero.$b"; $dyn[] = "cb.sub.$b"; $dyn[] = "gate.bx.$b.name"; $dyn[] = "gate.bx.$b.em"; $dyn[] = "dn.stay.$b"; } foreach ( array( 'tix', 'cosm', 'book', 'ship' ) as $r ) $dyn[] = "rw.info.$r";
	$used = array_merge( $used, $dyn ); $miss = array_diff( $used, array_keys( $reg ) );
	$p( 'promo.js ключове', count( $used ) . ' · липсващи в регистъра: ' . ( $miss ? implode( ',', $miss ) : 'няма' ) . ' · promo.js ' . strlen( $src ) . 'B' );
	preg_match_all( '/[А-Яа-я]{3,}/u', preg_replace( '#/\*.*?\*/#s', '', $src ), $cm ); $cyrjs = array_unique( $cm[0] );
	$p( 'кирилица в promo.js (извън коментари)', count( $cyrjs ) . ' думи: ' . implode( ',', array_slice( $cyrjs, 0, 12 ) ) . ' (очаквани: опаковка/и, продукт/а, шанс/а, със/с, във/в, дели/делят, затвори, махни, добави, любимец/ци)' );

	$h( 'Runtime JSON' );
	$rt = AnsaPromo\Frontend::runtime( $draft, true ); $json = wp_json_encode( $rt, JSON_UNESCAPED_UNICODE );
	$p( 'размер (чернова)', strlen( $json ) . 'B · products=' . count( (array) $rt['products'] ) . ' · copy=' . count( $rt['copy'] ) . ' · live=' . $yn( $rt['live'] ) . ' · cart=' . $yn( $rt['cart'] ) . ' · nonce=' . $yn( '' !== $rt['nonce'] ) );
	$p( 'runtime transient', $yn( false !== get_transient( AnsaPromo\Catalog::runtime_key( AnsaPromo\Config::version() ) ) ) );

	$h( 'Страницата' );
	$pages = AnsaPromo\Doctor::pages_with_shortcode( true );
	$p( 'страници с [ansa_promo]', $pages ? implode( ' | ', array_map( function ( $x ) { return '#' . $x['id'] . ' ' . $x['status'] . ' ' . $x['url']; }, $pages ) ) : 'НЯМА — създай една' );
	$p( 'shortcode owner', AnsaPromo\Plugin::shortcode_owner() );
	$pid = $draft['page_id'] ?: ( $pages ? $pages[0]['id'] : 0 );
	if ( $pid ) {
		$url = get_permalink( $pid );
		$r = wp_remote_get( add_query_arg( 'ansa_promo_nocache', time(), $url ), array( 'timeout' => 20, 'sslverify' => false, 'cookies' => array() ) );
		if ( is_wp_error( $r ) ) $p( 'anonymous fetch', 'грешка: ' . $r->get_error_message() );
		else { $b = (string) wp_remote_retrieve_body( $r );
			$p( 'anonymous fetch', 'HTTP ' . wp_remote_retrieve_response_code( $r ) . ' · ' . strlen( $b ) . 'B · root=' . $yn( false !== strpos( $b, 'id="ansaPromo"' ) ) . ' · soon=' . $yn( false !== strpos( $b, 'ansa-promo-soon' ) ) . ' · runtime JSON=' . $yn( false !== strpos( $b, 'AnsaPromoRuntime' ) ) . ' · promo.js=' . $yn( false !== strpos( $b, 'assets/promo.js' ) ) . ' · promo.css=' . $yn( false !== strpos( $b, 'assets/promo.css' ) ) . ' · noindex=' . $yn( (bool) preg_match( '/<meta name=.robots. content=.[^>]*noindex/i', $b ) ) . ' · {{=' . $yn( false !== strpos( $b, '{{' ) && ! preg_match( '/AnsaPromoRuntime=/', $b ) ) . ' · trp=' . $yn( false !== strpos( $b, 'trp-' ) ) . ' · x-cache=' . ( wp_remote_retrieve_header( $r, 'x-flying-press-cache' ) ?: wp_remote_retrieve_header( $r, 'cf-cache-status' ) ?: '—' ) );
			if ( preg_match( '/<script[^>]*src="([^"]*assets\/promo\.js[^"]*)"/', $b, $sm ) ) { $r2 = wp_remote_get( html_entity_decode( $sm[1] ), array( 'timeout' => 15, 'sslverify' => false ) ); $p( 'promo.js over HTTP', is_wp_error( $r2 ) ? 'грешка' : 'HTTP ' . wp_remote_retrieve_response_code( $r2 ) . ' · ' . strlen( (string) wp_remote_retrieve_body( $r2 ) ) . 'B · content-type ' . wp_remote_retrieve_header( $r2, 'content-type' ) ); }
		}
		$p( 'draft url (отвори като админ)', add_query_arg( array( 'ansa_promo' => 'draft', $draft['utm_param'] => 'sakura' ), $url ) );
	}

	$h( 'Ajax' );
	$r = wp_remote_get( admin_url( 'admin-ajax.php?action=ansa_promo_runtime' ), array( 'timeout' => 15, 'sslverify' => false, 'cookies' => array() ) );
	$d = ! is_wp_error( $r ) ? json_decode( (string) wp_remote_retrieve_body( $r ), true ) : null;
	$p( 'ansa_promo_runtime (anon)', is_wp_error( $r ) ? 'грешка: ' . $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r ) . ' · success=' . $yn( ! empty( $d['success'] ) ) . ' · products=' . ( isset( $d['data']['products'] ) ? count( (array) $d['data']['products'] ) : '—' ) . ' · registry absent=' . $yn( ! isset( $d['data']['registry'] ) || null === $d['data']['registry'] ) );
	$r = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 15, 'sslverify' => false, 'cookies' => array(), 'body' => array( 'action' => 'ansa_promo_lead', 'nonce' => 'bad', 'email' => 'snippet1@example.com' ) ) );
	$p( 'ansa_promo_lead с лош nonce', is_wp_error( $r ) ? 'грешка' : 'HTTP ' . wp_remote_retrieve_response_code( $r ) . ' (очаквано 403) · ' . mb_substr( (string) wp_remote_retrieve_body( $r ), 0, 80 ) );
	$p( 'leads в таблицата', AnsaPromo\Leads::count() );
	$p( 'cron ansa_promo_daily', wp_next_scheduled( 'ansa_promo_daily' ) ? date_i18n( 'Y-m-d H:i', wp_next_scheduled( 'ansa_promo_daily' ) ) : 'НЕ Е НАСРОЧЕН' );

	$L[] = ''; $L[] = '=== край ===';
	return $cache = implode( "\n", $L );
}
}
