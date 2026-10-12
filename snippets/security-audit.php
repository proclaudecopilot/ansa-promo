<?php
/**
 * ansa™ · Snippet SEC — одит на сигурността след XSS-опити в поръчки (payload в shipping first_name → patchstackk.com / imgcdn1.com).
 *
 * Code Snippets → Add New → постави всичко БЕЗ първия ред „<?php“ → Save & Activate (Admin only е достатъчно).
 * Отчетът излиза на две места:
 *   1) /wp-admin/ (Табло, Плъгини) — известие с поле; кликни в полето → Ctrl+A/Ctrl+C → прати ми го.
 *   2) /wp-admin/?ansa_sec_report=1 — същият отчет като чист текст (препоръчително: няма лимит на дължината).
 *      Допълнителни думи за търсене: /wp-admin/?ansa_sec_report=1&ioc=дума1,дума2
 * САМО ЧЕТЕ — не трие, не променя поръчки, потребители или файлове. Две заявки към api.wordpress.org (checksums на ядрото + налични обновления).
 * Може да отнеме 20–60 сек (сканира uploads/, плъгините и ядрото). След като пратиш отчета — деактивирай снипета.
 *
 * Какво проверява:
 *   A. Среда: WP/WC/PHP, HPOS, реален IP зад proxy/Cloudflare, регистрация на потребители, DISALLOW_FILE_*.
 *   B. Поръчки с payload: всички поръчки (HPOS и/или postmeta), в чиито адресни полета/бележка/имейл има XSS/JS следи —
 *      номер, статус, created_via, дата, IP, UA, поле, парче от payload-а; обобщение по IP, статус, ден; дали нещо е минало
 *      ОТВЪД checkout-draft (= реална опасност, някой е гледал поръчката в уязвим екран).
 *   C. IOC в базата: домейните/маркерите на атаката + общи backdoor-следи в options, posts (widgets/blocks/custom HTML),
 *      postmeta, usermeta, Code Snippets / WPCode (persistence след успешен XSS).
 *   D. Потребители: всички с manage_options/shop_manager (регистрация, имейл), нови за 60 дни, application passwords,
 *      роли с чужди capabilities (subscriber с manage_options и т.н.).
 *   E. WooCommerce: REST API ключове (кой/права/последен достъп), webhooks (delivery_url!), guest checkout, Store API плъгини.
 *   F. Файлове: PHP в uploads/, .htaccess/.user.ini/php.ini, промени през последните 14 дни в plugins/mu-plugins/theme/root,
 *      checksums на ядрото (променени/липсващи/ЧУЖДИ php файлове в wp-admin и wp-includes).
 *   G. Cron: всички hook-ове, непознатите маркирани.
 *   H. Плъгини: активни + налични обновления; кой плъгин пипа името на получателя (shipping/billing name) — кандидати за
 *      уязвимия екран; кой записва _debug_log_source_pending_deletion / place-order-debug.
 */

add_action( 'admin_init', function () {
	if ( ! isset( $_GET['ansa_sec_report'] ) || ! current_user_can( 'manage_options' ) ) return;
	nocache_headers(); header( 'Content-Type: text/plain; charset=utf-8' );
	echo ansa_sec_audit_report(); exit;
} );
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) return;
	$s = get_current_screen();
	if ( ! $s || ! in_array( $s->id, array( 'dashboard', 'plugins' ), true ) ) return;
	echo '<div class="notice notice-warning"><p><b>ansa™ · Snippet SEC</b> — копирай всичко от полето и го прати в чата (после деактивирай снипета). Пълен текст: <a href="' . esc_url( admin_url( '?ansa_sec_report=1' ) ) . '" target="_blank">?ansa_sec_report=1</a></p>'
		. '<textarea readonly onclick="this.select()" style="width:100%;height:340px;font:12px/1.4 Menlo,Consolas,monospace;white-space:pre">' . esc_textarea( ansa_sec_audit_report() ) . '</textarea></div>';
} );

if ( ! function_exists( 'ansa_sec_audit_report' ) ) {
function ansa_sec_audit_report() {
	global $wpdb, $wp_version;
	static $cache = null; if ( null !== $cache ) return $cache;
	@set_time_limit( 180 );
	$T0 = microtime( true );
	$deadline = function ( $sec ) use ( $T0 ) { return microtime( true ) - $T0 > $sec; };
	$L = array();
	$p = function ( $k, $v ) use ( &$L ) { $L[] = str_pad( mb_substr( (string) $k, 0, 40 ), 40 ) . ' ' . ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v ); };
	$h = function ( $t ) use ( &$L ) { $L[] = ''; $L[] = '── ' . $t . ' ' . str_repeat( '─', max( 0, 70 - mb_strlen( $t ) ) ); };
	$yn = function ( $b ) { return $b ? 'yes' : 'no'; };
	$one = function ( $s, $n = 160 ) { return mb_substr( preg_replace( '/\s+/', ' ', (string) $s ), 0, $n ); };
	$ago = function ( $ts ) { return $ts ? human_time_diff( (int) $ts ) . ' ago (' . date_i18n( 'Y-m-d H:i', (int) $ts ) . ')' : '—'; };
	$flag = function ( $b ) { return $b ? '  <<< ПРОВЕРИ' : ''; };
	if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( ! function_exists( 'get_core_checksums' ) ) require_once ABSPATH . 'wp-admin/includes/update.php';

	// ── Маркери на атаката (IOC) ───────────────────────────────────────────────
	$ioc_attack  = array( 'patchstackk', 'imgcdn1', 'document.createElement(\'script\')', 'document.createElement("script")', '/fz/x.js' );
	$ioc_generic = array( 'eval(base64_decode', 'eval(gzinflate', 'eval(str_rot13', 'eval(atob', 'atob(', 'String.fromCharCode', 'document.write(unescape', '<script src="http', "<script src='http", 'wp_set_current_user(1', 'FilesMan', 'c99', 'r57', '$_POST["pass"]', 'move_uploaded_file(', 'wp_insert_user(' );
	$extra = array();
	if ( ! empty( $_GET['ioc'] ) ) foreach ( explode( ',', sanitize_text_field( wp_unslash( $_GET['ioc'] ) ) ) as $x ) if ( '' !== trim( $x ) ) $extra[] = trim( $x );
	$ioc_db = array_merge( $ioc_attack, $extra );

	$L[] = '=== ansa™ · Snippet SEC · ' . current_time( 'Y-m-d H:i:s' ) . ' (' . wp_timezone_string() . ') · ' . home_url( '/' ) . ' ===';
	$L[] = 'IOC (атака): ' . implode( ' | ', $ioc_attack ) . ( $extra ? ' · extra: ' . implode( ' | ', $extra ) : '' );

	// ── A. Среда ──────────────────────────────────────────────────────────────
	$h( 'A. Среда' );
	$p( 'WP / PHP / MySQL', $wp_version . ' / ' . PHP_VERSION . ' / ' . $wpdb->db_version() );
	$p( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : 'ЛИПСВА' );
	$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$p( 'HPOS / sync', ( $hpos ? 'ON' : 'OFF' ) . ' / ' . get_option( 'woocommerce_custom_orders_table_data_sync_enabled', '—' ) );
	$th = wp_get_theme(); $p( 'theme', $th->get( 'Name' ) . ' ' . $th->get( 'Version' ) . ( $th->parent() ? ' (child of ' . $th->parent()->get( 'Name' ) . ')' : '' ) );
	$p( 'REMOTE_ADDR на тази заявка', ( $_SERVER['REMOTE_ADDR'] ?? '—' ) . ' · CF-Connecting-IP=' . ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '—' ) . ' · X-Forwarded-For=' . ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '—' ) . ' · CF-Ray=' . $yn( isset( $_SERVER['HTTP_CF_RAY'] ) ) );
	$p( '(ако REMOTE_ADDR е IP на Cloudflare, IP-то в поръчките също е proxy → блокирай от Cloudflare, не от WP)', '' );
	$p( 'users_can_register / default_role', get_option( 'users_can_register' ) . ' / ' . get_option( 'default_role' ) );
	$p( 'admin_email', get_option( 'admin_email' ) );
	$p( 'DISALLOW_FILE_EDIT / _MODS', $yn( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) . ' / ' . $yn( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) );
	$p( 'WP_DEBUG / WP_DEBUG_LOG', $yn( defined( 'WP_DEBUG' ) && WP_DEBUG ) . ' / ' . ( defined( 'WP_DEBUG_LOG' ) ? var_export( WP_DEBUG_LOG, true ) : 'no' ) );
	$sec = array();
	foreach ( array( 'WORDFENCE_VERSION' => 'Wordfence', 'PATCHSTACK_VERSION' => 'Patchstack', 'SOLID_SECURITY_VERSION' => 'SolidWP', 'ITSEC_VERSION' => 'iThemes', 'SUCURISCAN_VERSION' => 'Sucuri', 'MALCARE_VERSION' => 'MalCare', 'WPMUDEV_DEFENDER_VERSION' => 'Defender', 'DEFENDER_VERSION' => 'Defender', 'AIOS_VERSION' => 'AIOS', 'CLOUDFLARE_PLUGIN_DIR' => 'Cloudflare plugin' ) as $c => $n ) if ( defined( $c ) ) $sec[] = $n;
	$p( 'security плъгини', $sec ? implode( ', ', $sec ) : '— (няма засечен)' );
	$p( 'WC guest checkout / login reminder', get_option( 'woocommerce_enable_guest_checkout', '—' ) . ' / ' . get_option( 'woocommerce_enable_checkout_login_reminder', '—' ) );
	$p( 'WC Blocks checkout', $yn( function_exists( 'wc_get_page_id' ) && function_exists( 'has_block' ) && wc_get_page_id( 'checkout' ) > 0 && has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) ) ) );

	// ── B. Поръчки с payload ──────────────────────────────────────────────────
	$h( 'B. Поръчки с XSS/JS следи в адресни полета' );
	$like = array( '<', 'script', 'document.', 'javascript:', 'onerror', 'onload=', "');", '");', 'eval(', 'alert(', 'src=', 'patchstackk', 'imgcdn1' );
	$esc_like = function ( $s ) use ( $wpdb ) { return '%' . $wpdb->esc_like( $s ) . '%'; };
	$hits = array(); // id => array( 'fields' => [field=>value], ... )
	$tbl_orders = $wpdb->prefix . 'wc_orders'; $tbl_addr = $wpdb->prefix . 'wc_order_addresses'; $tbl_opd = $wpdb->prefix . 'wc_order_operational_data';
	$has_hpos_tables = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_orders ) ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_addr ) );
	$p( 'HPOS таблици съществуват', $yn( $has_hpos_tables ) );
	if ( $has_hpos_tables ) {
		$cols = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'email', 'phone' );
		$w = array(); foreach ( $cols as $c ) foreach ( $like as $s ) $w[] = $wpdb->prepare( "a.$c LIKE %s", $esc_like( $s ) );
		$rows = $wpdb->get_results( "SELECT a.order_id, a.address_type, a.first_name, a.last_name, a.company, a.address_1, a.address_2, a.city, a.state, a.postcode, a.email, a.phone FROM $tbl_addr a WHERE " . implode( ' OR ', $w ) . ' ORDER BY a.order_id DESC LIMIT 500', ARRAY_A );
		foreach ( (array) $rows as $r ) foreach ( $cols as $c ) foreach ( $like as $s ) if ( '' !== (string) $r[ $c ] && false !== stripos( (string) $r[ $c ], $s ) ) { $hits[ (int) $r['order_id'] ]['fields'][ $r['address_type'] . '.' . $c ] = $r[ $c ]; break; }
		$w2 = array(); foreach ( $like as $s ) $w2[] = $wpdb->prepare( 'o.customer_note LIKE %s', $esc_like( $s ) );
		$rows = $wpdb->get_results( "SELECT o.id, o.customer_note FROM $tbl_orders o WHERE o.customer_note <> '' AND (" . implode( ' OR ', $w2 ) . ') ORDER BY o.id DESC LIMIT 200', ARRAY_A );
		foreach ( (array) $rows as $r ) $hits[ (int) $r['id'] ]['fields']['customer_note'] = $r['customer_note'];
	}
	// postmeta (legacy / sync) — винаги, защото при sync двете са пълни, а при OFF това е единственото място
	$keys = array( '_billing_first_name', '_billing_last_name', '_billing_company', '_billing_address_1', '_billing_address_2', '_billing_city', '_billing_email', '_billing_phone', '_shipping_first_name', '_shipping_last_name', '_shipping_company', '_shipping_address_1', '_shipping_address_2', '_shipping_city', '_shipping_phone' );
	$w3 = array(); foreach ( $like as $s ) $w3[] = $wpdb->prepare( 'pm.meta_value LIKE %s', $esc_like( $s ) );
	$rows = $wpdb->get_results( "SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.meta_key IN ('" . implode( "','", $keys ) . "') AND (" . implode( ' OR ', $w3 ) . ') ORDER BY pm.post_id DESC LIMIT 500', ARRAY_A );
	foreach ( (array) $rows as $r ) $hits[ (int) $r['post_id'] ]['fields'][ 'meta' . $r['meta_key'] ] = $r['meta_value'];
	$rows = $wpdb->get_results( "SELECT ID, post_excerpt FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_excerpt <> '' AND (" . str_replace( 'pm.meta_value', 'post_excerpt', implode( ' OR ', $w3 ) ) . ') ORDER BY ID DESC LIMIT 200', ARRAY_A );
	foreach ( (array) $rows as $r ) $hits[ (int) $r['ID'] ]['fields']['post_excerpt(note)'] = $r['post_excerpt'];
	// филтър на фалшиви попадения: „src=“, „<“ и „');“ в нормални адреси са редки, но все пак показваме всичко и маркираме тежките
	$heavy = function ( $v ) { return (bool) preg_match( '/<script|document\.|javascript:|onerror|onload=|eval\(|alert\(|createElement|patchstackk|imgcdn1|\.js[\'"]/i', (string) $v ); };
	krsort( $hits );
	$p( 'поръчки с подозрителни полета', count( $hits ) );
	$by_ip = array(); $by_status = array(); $by_day = array(); $by_ua = array(); $beyond_draft = array(); $n = 0;
	foreach ( $hits as $oid => $hh ) {
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( $oid ) : null;
		$st = $o ? $o->get_status() : '?'; $via = $o ? $o->get_created_via() : '?'; $ip = $o ? $o->get_customer_ip_address() : '?'; $ua = $o ? $o->get_customer_user_agent() : '?';
		$dt = $o && $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i:s' ) : '?';
		$is_heavy = false; foreach ( $hh['fields'] as $v ) if ( $heavy( $v ) ) { $is_heavy = true; break; }
		$by_ip[ $ip ] = ( $by_ip[ $ip ] ?? 0 ) + 1; $by_status[ $st ] = ( $by_status[ $st ] ?? 0 ) + 1; $by_day[ substr( $dt, 0, 10 ) ] = ( $by_day[ substr( $dt, 0, 10 ) ] ?? 0 ) + 1; $by_ua[ $one( $ua, 80 ) ] = ( $by_ua[ $one( $ua, 80 ) ] ?? 0 ) + 1;
		if ( $is_heavy && ! in_array( $st, array( 'checkout-draft', 'trash', 'auto-draft' ), true ) ) $beyond_draft[] = '#' . $oid . ' (' . $st . ')';
		if ( $n++ < 60 ) {
			$p( '#' . $oid . ( $is_heavy ? ' [JS]' : ' [слабо]' ), 'status=' . $st . ' via=' . $via . ' ' . $dt . ' ip=' . $ip . ' email=' . ( $o ? $o->get_billing_email() : '?' ) . ( $o && $o->get_customer_id() ? ' USER=' . $o->get_customer_id() : '' ) . ' total=' . ( $o ? $o->get_total() : '?' ) . ' items=' . ( $o ? count( $o->get_items() ) : '?' ) );
			foreach ( $hh['fields'] as $f => $v ) $p( '    ' . $f, $one( $v, 200 ) );
			if ( $o ) { $pl = array(); foreach ( $o->get_meta_data() as $m ) if ( in_array( $m->key, array( '_debug_log_source_pending_deletion', 'trp_language', '_wc_other/metorik/opt-in', '_cart_hash' ), true ) ) $pl[] = $m->key . '=' . $one( $m->value, 40 ); if ( $pl ) $p( '    meta', implode( ' · ', $pl ) ); }
		}
	}
	if ( $n > 60 ) $p( '…', 'показани 60 от ' . $n );
	arsort( $by_ip ); arsort( $by_status ); krsort( $by_day ); arsort( $by_ua );
	$p( 'по IP', implode( ' · ', array_map( function ( $k, $v ) { return "$k×$v"; }, array_keys( $by_ip ), $by_ip ) ) ?: '—' );
	$p( 'по статус', implode( ' · ', array_map( function ( $k, $v ) { return "$k×$v"; }, array_keys( $by_status ), $by_status ) ) ?: '—' );
	$p( 'по ден', implode( ' · ', array_map( function ( $k, $v ) { return "$k×$v"; }, array_keys( array_slice( $by_day, 0, 20, true ) ), array_slice( $by_day, 0, 20, true ) ) ) ?: '—' );
	foreach ( array_slice( $by_ua, 0, 5, true ) as $k => $v ) $p( 'UA ×' . $v, $k );
	$p( 'JS-payload ОТВЪД checkout-draft', $beyond_draft ? implode( ', ', $beyond_draft ) . $flag( true ) : 'няма (само чернови = никой не ги е „отворил“ като реална поръчка)' );
	// Всички поръчки от същите IP-та (и без payload) + обем чернови
	$top_ips = array_slice( array_keys( $by_ip ), 0, 5 );
	foreach ( $top_ips as $ip ) {
		if ( '?' === $ip || '' === $ip ) continue;
		$cnt = $has_hpos_tables ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tbl_orders WHERE ip_address=%s", $ip ) ) : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_customer_ip_address' AND meta_value=%s", $ip ) );
		$first = $has_hpos_tables ? $wpdb->get_var( $wpdb->prepare( "SELECT MIN(date_created_gmt) FROM $tbl_orders WHERE ip_address=%s", $ip ) ) : '?';
		$lastd = $has_hpos_tables ? $wpdb->get_var( $wpdb->prepare( "SELECT MAX(date_created_gmt) FROM $tbl_orders WHERE ip_address=%s", $ip ) ) : '?';
		$p( 'всички поръчки от ' . $ip, $cnt . ' · първа ' . $first . ' · последна ' . $lastd . ' (GMT)' );
	}
	if ( $has_hpos_tables ) {
		$rows = $wpdb->get_results( "SELECT DATE(date_created_gmt) d, status, created_via, COUNT(*) c FROM $tbl_orders WHERE date_created_gmt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY) AND status IN ('wc-checkout-draft','checkout-draft') GROUP BY d, status, created_via ORDER BY d DESC", ARRAY_A );
		$agg = array(); foreach ( (array) $rows as $r ) $agg[ $r['d'] ][] = $r['created_via'] . '×' . $r['c'];
		foreach ( $agg as $d => $v ) $p( 'checkout-draft ' . $d, implode( ' · ', $v ) );
		$rows = $wpdb->get_results( "SELECT created_via, COUNT(*) c FROM $tbl_orders WHERE date_created_gmt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY) GROUP BY created_via ORDER BY c DESC", ARRAY_A );
		$p( 'created_via (14 дни, всички статуси)', implode( ' · ', array_map( function ( $r ) { return $r['created_via'] . '×' . $r['c']; }, (array) $rows ) ) ?: '—' );
	}

	// ── C. IOC в базата ───────────────────────────────────────────────────────
	$h( 'C. IOC в базата данни' );
	$terms = array_merge( $ioc_db, $ioc_generic );
	$search = function ( $sql_tpl, $terms, $label ) use ( $wpdb, &$p, $esc_like, $one, $deadline ) {
		$found = 0;
		foreach ( $terms as $t ) {
			if ( $deadline( 150 ) ) { $p( $label, 'ПРЕКЪСНАТО (време)' ); return; }
			$rows = $wpdb->get_results( $wpdb->prepare( $sql_tpl, $esc_like( $t ) ), ARRAY_A );
			foreach ( (array) $rows as $r ) { $found++; $vals = array_values( $r ); $p( $label . ' „' . $t . '“', implode( ' · ', array_map( function ( $v ) use ( $one ) { return $one( $v, 90 ); }, $vals ) ) . '  <<< ПРОВЕРИ' ); }
		}
		if ( ! $found ) $p( $label, 'чисто (' . count( $terms ) . ' маркера)' );
	};
	$search( "SELECT option_id, option_name, LEFT(option_value, 200) v FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 20", $terms, 'options' );
	$search( "SELECT ID, post_type, post_status, post_title, post_modified FROM {$wpdb->posts} WHERE post_type NOT IN ('shop_order','shop_order_placehold','revision') AND (post_content LIKE %s) LIMIT 20", $terms, 'posts' );
	$search( "SELECT post_id, meta_key, LEFT(meta_value, 160) v FROM {$wpdb->postmeta} WHERE meta_key NOT LIKE '\\_billing\\_%' AND meta_key NOT LIKE '\\_shipping\\_%' AND meta_value LIKE %s LIMIT 20", $ioc_db, 'postmeta (без адреси)' );
	$search( "SELECT user_id, meta_key, LEFT(meta_value, 160) v FROM {$wpdb->usermeta} WHERE meta_value LIKE %s LIMIT 20", $terms, 'usermeta' );
	$search( "SELECT comment_ID, comment_post_ID, comment_type, LEFT(comment_content, 160) v FROM {$wpdb->comments} WHERE comment_content LIKE %s LIMIT 20", $ioc_db, 'comments/order notes' );
	// Code Snippets plugin
	$tbl_sn = $wpdb->prefix . 'snippets';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_sn ) ) ) {
		$rows = $wpdb->get_results( "SELECT id, name, scope, active, modified, LENGTH(code) len, code FROM $tbl_sn ORDER BY modified DESC", ARRAY_A );
		$p( 'Code Snippets (общо/активни)', count( (array) $rows ) . '/' . count( array_filter( (array) $rows, function ( $r ) { return ! empty( $r['active'] ); } ) ) );
		foreach ( (array) $rows as $r ) {
			$bad = array(); foreach ( $terms as $t ) if ( false !== stripos( $r['code'], $t ) ) $bad[] = $t;
			$recent = strtotime( $r['modified'] ) > time() - 30 * DAY_IN_SECONDS;
			if ( $r['active'] || $recent || $bad ) $p( '  snippet #' . $r['id'] . ( $r['active'] ? ' ON' : ' off' ), $one( $r['name'], 50 ) . ' · ' . $r['scope'] . ' · mod ' . $r['modified'] . ' · ' . $r['len'] . 'B' . ( $bad ? ' · IOC: ' . implode( ',', $bad ) . $flag( true ) : '' ) . ( $recent && ! $bad ? ' (променен <30 дни)' : '' ) );
		}
	} else $p( 'Code Snippets таблица', 'няма' );
	$wpc = $wpdb->get_results( "SELECT ID, post_title, post_status, post_modified FROM {$wpdb->posts} WHERE post_type='wpcode' ORDER BY post_modified DESC LIMIT 20", ARRAY_A );
	if ( $wpc ) foreach ( $wpc as $r ) $p( '  WPCode #' . $r['ID'], $r['post_title'] . ' · ' . $r['post_status'] . ' · ' . $r['post_modified'] ); else $p( 'WPCode snippets', 'няма' );
	// widgets / custom HTML / custom CSS / blocks с <script>
	$search( "SELECT ID, post_type, post_status, post_title, post_modified FROM {$wpdb->posts} WHERE post_type IN ('wp_block','custom_css','wp_template','wp_template_part','wp_global_styles','elementor_library') AND post_content LIKE %s LIMIT 20", array( '<script' ), 'blocks/templates с <script' );
	$search( "SELECT option_id, option_name, LEFT(option_value, 200) v FROM {$wpdb->options} WHERE option_name IN ('widget_custom_html','widget_block','widget_text') AND option_value LIKE %s LIMIT 20", array( '<script', 'http' ), 'widgets с script/http' );
	$p( 'options с external <script src', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE '%<script%src=%http%'" ) . ' реда' );

	// ── D. Потребители ────────────────────────────────────────────────────────
	$h( 'D. Потребители и роли' );
	$roles = get_option( $wpdb->get_blog_prefix() . 'user_roles', array() );
	foreach ( (array) $roles as $rk => $rv ) {
		$caps = (array) ( $rv['capabilities'] ?? array() ); $danger = array_intersect( array( 'manage_options', 'edit_plugins', 'install_plugins', 'activate_plugins', 'edit_files', 'edit_users', 'create_users', 'promote_users', 'unfiltered_html', 'edit_themes', 'update_core' ), array_keys( array_filter( $caps ) ) );
		if ( in_array( $rk, array( 'administrator' ), true ) ) continue;
		if ( $danger ) $p( 'роля ' . $rk . ' има опасни caps', implode( ',', $danger ) . ( in_array( $rk, array( 'shop_manager', 'editor' ), true ) && ! array_diff( $danger, array( 'unfiltered_html', 'edit_users', 'create_users', 'promote_users' ) ) ? '' : $flag( true ) ) );
	}
	$p( 'роли', implode( ', ', array_keys( (array) $roles ) ) );
	$us = get_users( array( 'role__in' => array( 'administrator', 'shop_manager', 'editor' ), 'orderby' => 'registered', 'order' => 'DESC', 'number' => 100 ) );
	$p( 'admin/shop_manager/editor', count( $us ) );
	foreach ( $us as $u ) {
		$ap = get_user_meta( $u->ID, '_application_passwords', true ); $ap = is_array( $ap ) ? count( $ap ) : 0;
		$ll = get_user_meta( $u->ID, 'wfls_last_login', true ) ?: get_user_meta( $u->ID, 'last_login', true ) ?: get_user_meta( $u->ID, 'wc_last_active', true );
		$sess = get_user_meta( $u->ID, 'session_tokens', true ); $sess = is_array( $sess ) ? count( $sess ) : 0;
		$recent = strtotime( $u->user_registered ) > time() - 60 * DAY_IN_SECONDS;
		$p( '  #' . $u->ID . ' ' . $u->user_login, implode( ',', $u->roles ) . ' · ' . $u->user_email . ' · reg ' . $u->user_registered . ' · app-pw=' . $ap . ' · sessions=' . $sess . ( $ll ? ' · last ' . ( is_numeric( $ll ) ? date_i18n( 'Y-m-d H:i', (int) $ll ) : $ll ) : '' ) . ( $recent ? $flag( true ) . ' (нов <60 дни)' : '' ) . ( $ap ? $flag( true ) . ' (application passwords!)' : '' ) );
	}
	$new = get_users( array( 'date_query' => array( array( 'after' => '30 days ago' ) ), 'orderby' => 'registered', 'order' => 'DESC', 'number' => 50 ) );
	$p( 'нови потребители (30 дни)', count( $new ) );
	foreach ( $new as $u ) $p( '  #' . $u->ID . ' ' . $u->user_login, implode( ',', $u->roles ) . ' · ' . $u->user_email . ' · ' . $u->user_registered );
	$apw = $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key='_application_passwords'", ARRAY_A );
	foreach ( (array) $apw as $r ) { $arr = maybe_unserialize( $r['meta_value'] ); if ( ! is_array( $arr ) || ! $arr ) continue; foreach ( $arr as $a ) $p( '  app-pw user#' . $r['user_id'], ( $a['name'] ?? '?' ) . ' · created ' . $ago( $a['created'] ?? 0 ) . ' · last_used ' . $ago( $a['last_used'] ?? 0 ) . ' · last_ip ' . ( $a['last_ip'] ?? '—' ) ); }
	$p( 'потребители без роля (orphan caps)', (string) count( get_users( array( 'role' => '', 'number' => 1000, 'fields' => 'ID', 'meta_query' => array( array( 'key' => $wpdb->get_blog_prefix() . 'capabilities', 'compare' => 'NOT EXISTS' ) ) ) ) ) );

	// ── E. WooCommerce REST ключове / webhooks ───────────────────────────────
	$h( 'E. WooCommerce API ключове · webhooks · Store API' );
	$tbl_keys = $wpdb->prefix . 'woocommerce_api_keys';
	$keys = $wpdb->get_results( "SELECT key_id, user_id, description, permissions, truncated_key, last_access FROM $tbl_keys ORDER BY key_id DESC", ARRAY_A );
	$p( 'REST API ключове', count( (array) $keys ) );
	foreach ( (array) $keys as $k ) { $u = get_user_by( 'id', $k['user_id'] ); $p( '  key#' . $k['key_id'] . ' …' . $k['truncated_key'], $one( $k['description'], 40 ) . ' · ' . $k['permissions'] . ' · user ' . ( $u ? $u->user_login . '(' . implode( ',', $u->roles ) . ')' : '#' . $k['user_id'] . ' ЛИПСВА' ) . ' · last ' . ( $k['last_access'] ?: 'никога' ) ); }
	$tbl_wh = $wpdb->prefix . 'wc_webhooks';
	$whs = $wpdb->get_results( "SELECT webhook_id, status, name, user_id, delivery_url, topic, date_created FROM $tbl_wh ORDER BY webhook_id DESC", ARRAY_A );
	$p( 'webhooks', count( (array) $whs ) );
	foreach ( (array) $whs as $w ) { $host = (string) wp_parse_url( $w['delivery_url'], PHP_URL_HOST ); $own = $host && ( false !== strpos( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ); $p( '  wh#' . $w['webhook_id'] . ' ' . $w['status'], $one( $w['name'], 30 ) . ' · ' . $w['topic'] . ' · ' . $w['delivery_url'] . ' · user#' . $w['user_id'] . ' · ' . $w['date_created'] . ( $own ? '' : ' (външен URL — потвърди, че е ваш)' ) ); }
	$tbl_log = $wpdb->prefix . 'woocommerce_log';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_log ) ) ) {
		$rows = $wpdb->get_results( "SELECT source, COUNT(*) c, MAX(timestamp) t FROM $tbl_log WHERE timestamp > DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY source ORDER BY c DESC LIMIT 15", ARRAY_A );
		foreach ( (array) $rows as $r ) $p( '  wc_log ' . $one( $r['source'], 36 ), $r['c'] . ' · last ' . $r['t'] );
	}
	$ld = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : ( WP_CONTENT_DIR . '/uploads/wc-logs/' );
	if ( is_dir( $ld ) ) { $lf = glob( $ld . '*.log' ) ?: array(); usort( $lf, function ( $a, $b ) { return filemtime( $b ) - filemtime( $a ); } ); $p( 'wc-logs файлове', count( $lf ) . ' · ' . implode( ', ', array_map( function ( $f ) { return basename( $f ) . '(' . size_format( filesize( $f ) ) . ')'; }, array_slice( $lf, 0, 8 ) ) ) ); foreach ( array_slice( $lf, 0, 40 ) as $f ) if ( false !== strpos( basename( $f ), 'place-order' ) || false !== strpos( basename( $f ), 'debug' ) ) $p( '  log ' . basename( $f ), size_format( filesize( $f ) ) . ' · ' . $ago( filemtime( $f ) ) ); }
	global $wp_filter;
	foreach ( array( 'woocommerce_store_api_checkout_update_order_from_request', 'woocommerce_store_api_checkout_order_processed', 'woocommerce_store_api_validate_add_to_cart', 'woocommerce_checkout_create_order', 'woocommerce_new_order', 'rest_pre_dispatch', 'rest_authentication_errors' ) as $hk ) {
		$cbs = array();
		if ( isset( $wp_filter[ $hk ] ) ) foreach ( $wp_filter[ $hk ]->callbacks as $pr => $arr ) foreach ( $arr as $cb ) { $f = $cb['function']; $cbs[] = ( is_array( $f ) ? ( is_object( $f[0] ) ? get_class( $f[0] ) : $f[0] ) . '::' . $f[1] : ( is_string( $f ) ? $f : 'closure' ) ) . '@' . $pr; }
		$p( 'hook ' . $hk, $cbs ? $one( implode( ', ', $cbs ), 400 ) : '—' );
	}

	// ── F. Файлове ────────────────────────────────────────────────────────────
	$h( 'F. Файлова система' );
	$up = wp_upload_dir(); $updir = $up['basedir'];
	$php_in_up = array(); $cnt_files = 0; $trunc = false;
	try {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $updir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $f ) {
			if ( ++$cnt_files > 300000 || $deadline( 70 ) ) { $trunc = true; break; }
			$n = $f->getFilename(); $ext = strtolower( pathinfo( $n, PATHINFO_EXTENSION ) );
			if ( $f->isFile() && ( in_array( $ext, array( 'php', 'phtml', 'php5', 'php7', 'phar', 'inc', 'ico' ), true ) || in_array( $n, array( '.htaccess', '.user.ini', 'php.ini', 'web.config' ), true ) || preg_match( '/\.php\./i', $n ) ) ) {
				if ( 'ico' === $ext && filesize( $f->getPathname() ) < 200000 && false === strpos( (string) @file_get_contents( $f->getPathname(), false, null, 0, 2000 ), '<?php' ) ) continue;
				$php_in_up[] = str_replace( $updir, '', $f->getPathname() ) . ' (' . size_format( $f->getSize() ) . ', ' . $ago( $f->getMTime() ) . ')';
			}
		}
	} catch ( Throwable $e ) { $p( 'uploads scan', 'грешка: ' . $e->getMessage() ); }
	$p( 'uploads сканирани файлове', $cnt_files . ( $trunc ? ' (ПРЕКЪСНАТО по лимит)' : '' ) );
	$p( 'PHP/.htaccess/.user.ini в uploads', $php_in_up ? count( $php_in_up ) . $flag( true ) : 'няма' );
	foreach ( array_slice( $php_in_up, 0, 40 ) as $x ) $p( '  ', $x );
	foreach ( array( ABSPATH . '.htaccess', ABSPATH . '.user.ini', ABSPATH . 'php.ini', ABSPATH . 'wp-config.php', WP_CONTENT_DIR . '/.htaccess', ABSPATH . 'wp-admin/.htaccess', ABSPATH . 'wp-includes/.htaccess', WP_CONTENT_DIR . '/plugins/.htaccess', WP_CONTENT_DIR . '/debug.log' ) as $f ) {
		if ( ! file_exists( $f ) ) continue;
		$c = (string) @file_get_contents( $f, false, null, 0, 200000 );
		$bad = array(); foreach ( array( 'auto_prepend_file', 'auto_append_file', 'php_value', 'AddHandler', 'AddType application/x-httpd', 'SetHandler', 'RewriteRule .*http', 'base64_decode', 'eval(', 'patchstackk', 'imgcdn1' ) as $t ) if ( false !== stripos( $c, $t ) ) $bad[] = $t;
		$p( str_replace( ABSPATH, '/', $f ), size_format( filesize( $f ) ) . ' · ' . $ago( filemtime( $f ) ) . ( $bad ? ' · съдържа: ' . implode( ',', $bad ) . ( 'wp-config.php' === basename( $f ) && ! array_diff( $bad, array( 'php_value' ) ) ? '' : $flag( true ) ) : '' ) );
	}
	$p( 'php ini auto_prepend_file', ini_get( 'auto_prepend_file' ) ?: '—' );
	// Скорошни промени (14 дни) в plugins / mu-plugins / theme / root
	$recent_files = array(); $since = time() - 14 * DAY_IN_SECONDS; $scanned = 0; $trunc2 = false;
	$walk = function ( $dir, $maxdepth ) use ( &$recent_files, $since, &$scanned, &$trunc2, $deadline ) {
		if ( ! is_dir( $dir ) ) return;
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ); $it->setMaxDepth( $maxdepth );
			foreach ( $it as $f ) { if ( ++$scanned > 400000 || $deadline( 110 ) ) { $trunc2 = true; return; } if ( $f->isFile() && $f->getMTime() > $since && preg_match( '/\.(php|js|htaccess|ini|phtml)$/i', $f->getFilename() ) ) $recent_files[ $f->getPathname() ] = $f->getMTime(); }
		} catch ( Throwable $e ) {}
	};
	$walk( WP_PLUGIN_DIR, 12 ); $walk( WPMU_PLUGIN_DIR, 4 ); $walk( get_theme_root(), 8 ); $walk( ABSPATH, 0 );
	arsort( $recent_files );
	$p( 'файлове .php/.js променени <14 дни', count( $recent_files ) . ' (сканирани ' . $scanned . ( $trunc2 ? ', ПРЕКЪСНАТО' : '' ) . ')' );
	$by_plugin = array(); foreach ( $recent_files as $f => $t ) { $rel = str_replace( ABSPATH, '/', $f ); $parts = explode( '/', trim( $rel, '/' ) ); $grp = count( $parts ) > 3 && 'wp-content' === $parts[0] ? $parts[1] . '/' . $parts[2] : $rel; if ( ! isset( $by_plugin[ $grp ] ) ) $by_plugin[ $grp ] = array( 0, $t, $rel ); $by_plugin[ $grp ][0]++; }
	foreach ( array_slice( $by_plugin, 0, 40, true ) as $g => $v ) $p( '  ' . $g, $v[0] . ' файла · последен ' . $ago( $v[1] ) . ( 1 === $v[0] ? ' · ' . $v[2] : '' ) );
	$mu = get_mu_plugins(); foreach ( $mu as $file => $d ) $p( 'mu-plugin ' . $file, ( $d['Name'] ?: '(без header)' ) . ' ' . $d['Version'] . ' · ' . $ago( filemtime( WPMU_PLUGIN_DIR . '/' . $file ) ) . ( $d['Name'] ? '' : $flag( true ) ) );
	$mu_dirs = glob( WPMU_PLUGIN_DIR . '/*', GLOB_ONLYDIR ) ?: array(); if ( $mu_dirs ) $p( 'mu-plugins папки', implode( ', ', array_map( 'basename', $mu_dirs ) ) );
	$dp = glob( WP_PLUGIN_DIR . '/*' ) ?: array(); $all = get_plugins(); $unk = array();
	foreach ( $dp as $d ) { $b = basename( $d ); $known = false; foreach ( array_keys( $all ) as $pf ) if ( 0 === strpos( $pf, $b . '/' ) || $pf === $b ) { $known = true; break; } if ( ! $known && 'index.php' !== $b ) $unk[] = $b . ' (' . $ago( filemtime( $d ) ) . ')'; }
	$p( 'в plugins/ без plugin header', $unk ? implode( ', ', $unk ) . $flag( true ) : '—' );
	// Checksums на ядрото
	$cs = get_core_checksums( $wp_version, get_locale() );
	if ( ! $cs && 'en_US' !== get_locale() ) $cs = get_core_checksums( $wp_version, 'en_US' );
	if ( ! is_array( $cs ) ) $p( 'core checksums', 'не са достъпни (api.wordpress.org / версия ' . $wp_version . ')' );
	else {
		$mod = array(); $miss = array(); $checked = 0;
		foreach ( $cs as $file => $md5 ) {
			if ( 0 === strpos( $file, 'wp-content/' ) ) continue;
			if ( $deadline( 150 ) ) { $p( 'core checksums', 'ПРЕКЪСНАТО (време) след ' . $checked ); break; }
			$checked++; $path = ABSPATH . $file;
			if ( ! file_exists( $path ) ) { $miss[] = $file; continue; }
			if ( md5_file( $path ) !== $md5 ) $mod[] = $file . ' (' . $ago( filemtime( $path ) ) . ')';
		}
		$p( 'core файлове проверени', $checked . ' · променени=' . count( $mod ) . ' · липсващи=' . count( $miss ) );
		foreach ( array_slice( $mod, 0, 30 ) as $x ) $p( '  ПРОМЕНЕН', $x . $flag( ! in_array( basename( $x ), array( 'wp-config-sample.php', 'readme.html', 'license.txt' ), true ) ) );
		foreach ( array_slice( $miss, 0, 10 ) as $x ) $p( '  липсва', $x );
		// чужди php файлове в wp-admin / wp-includes / root
		$extra = array();
		foreach ( array( 'wp-admin', 'wp-includes' ) as $d ) {
			try { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH . $d, FilesystemIterator::SKIP_DOTS ) ); foreach ( $it as $f ) { if ( $deadline( 160 ) ) break 2; $rel = str_replace( ABSPATH, '', $f->getPathname() ); if ( $f->isFile() && preg_match( '/\.(php|phtml|ico|htaccess)$/i', $f->getFilename() ) && ! isset( $cs[ $rel ] ) ) $extra[] = $rel . ' (' . size_format( $f->getSize() ) . ', ' . $ago( $f->getMTime() ) . ')'; } } catch ( Throwable $e ) {}
		}
		foreach ( glob( ABSPATH . '*.php' ) ?: array() as $f ) { $rel = basename( $f ); if ( ! isset( $cs[ $rel ] ) && 'wp-config.php' !== $rel ) $extra[] = $rel . ' (' . size_format( filesize( $f ) ) . ', ' . $ago( filemtime( $f ) ) . ')'; }
		$p( 'ЧУЖДИ файлове в wp-admin/wp-includes/root', $extra ? count( $extra ) . $flag( true ) : 'няма' );
		foreach ( array_slice( $extra, 0, 40 ) as $x ) $p( '  ', $x );
	}

	// ── G. Cron ───────────────────────────────────────────────────────────────
	$h( 'G. WP-Cron' );
	$cron = _get_cron_array(); $hooks = array(); $known_pref = array( 'wp_', 'woocommerce_', 'wc_', 'wc-admin', 'action_scheduler', 'delete_expired', 'recovery_mode', 'upgrader_', 'ansa_', 'ap_', 'logador', 'wfls', 'wordfence', 'jetpack', 'trp_', 'rocket_', 'flying', 'litespeed', 'metorik', 'wfacp', 'wffn', 'bwfan', 'wfocu', 'fkcart', 'elementor', 'rank_math', 'wpseo', 'aioseo', 'yith', 'mailpoet', 'updraft', 'akismet', 'cf_', 'do_pings', 'publish_future', 'ao_', 'autoptimize', 'ws_', 'wp_privacy', 'speedy', 'econt', 'woo_' );
	foreach ( (array) $cron as $ts => $ev ) { if ( ! is_array( $ev ) ) continue; foreach ( $ev as $hk => $x ) { if ( ! isset( $hooks[ $hk ] ) ) $hooks[ $hk ] = array( 0, $ts ); $hooks[ $hk ][0]++; } }
	ksort( $hooks );
	$p( 'cron hooks', count( $hooks ) . ' · DISABLE_WP_CRON=' . $yn( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) );
	foreach ( $hooks as $hk => $v ) { $kn = false; foreach ( $known_pref as $pf ) if ( 0 === stripos( $hk, $pf ) ) { $kn = true; break; } $has_cb = has_action( $hk ); $p( '  ' . ( $kn ? '' : '? ' ) . $hk, '×' . $v[0] . ' next ' . date_i18n( 'Y-m-d H:i', $v[1] ) . ( $has_cb ? '' : ' (без callback)' ) . ( $kn ? '' : '  (непознат префикс — кажи кой плъгин е)' ) ); }
	$asq = $wpdb->prefix . 'actionscheduler_actions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $asq ) ) ) { $rows = $wpdb->get_results( "SELECT hook, COUNT(*) c FROM $asq WHERE status='pending' GROUP BY hook ORDER BY c DESC LIMIT 15", ARRAY_A ); $p( 'Action Scheduler pending', implode( ' · ', array_map( function ( $r ) { return $r['hook'] . '×' . $r['c']; }, (array) $rows ) ) ?: '—' ); }

	// ── H. Плъгини ────────────────────────────────────────────────────────────
	$h( 'H. Плъгини · обновления · кой пипа името на получателя' );
	wp_update_plugins(); $upd = get_site_transient( 'update_plugins' ); $upd = isset( $upd->response ) ? (array) $upd->response : array();
	$active = (array) get_option( 'active_plugins', array() );
	foreach ( $all as $file => $d ) { $a = in_array( $file, $active, true ); if ( ! $a && empty( $upd[ $file ] ) ) continue; $p( ( $a ? '' : '(off) ' ) . $file, $d['Name'] . ' ' . $d['Version'] . ( isset( $upd[ $file ] ) ? ' → НАЛИЧНО ' . $upd[ $file ]->new_version : '' ) . ' · ' . $ago( filemtime( WP_PLUGIN_DIR . '/' . $file ) ) ); }
	$p( 'неактивни плъгини (общо)', count( $all ) - count( array_intersect( array_keys( $all ), $active ) ) . ' — деинсталирай ненужните' );
	$p( 'auto-updates', implode( ', ', (array) get_site_option( 'auto_update_plugins', array() ) ) ?: 'няма' );
	// grep в активните плъгини + mu + тема
	$pat_ioc  = array( '_debug_log_source_pending_deletion', 'place-order-debug', 'patchstackk', 'imgcdn1' );
	$pat_name = array( 'get_shipping_first_name', 'get_formatted_shipping_full_name', 'get_formatted_billing_full_name', 'get_billing_first_name', 'shipping_first_name', 'billing_first_name' );
	$g_ioc = array(); $g_name = array(); $gfiles = 0; $gtrunc = false;
	$dirs = array();
	foreach ( $active as $file ) $dirs[] = WP_PLUGIN_DIR . '/' . ( false !== strpos( $file, '/' ) ? dirname( $file ) : $file );
	$dirs[] = WPMU_PLUGIN_DIR; $dirs[] = get_stylesheet_directory(); if ( get_template_directory() !== get_stylesheet_directory() ) $dirs[] = get_template_directory();
	foreach ( array_unique( $dirs ) as $d ) {
		if ( ! is_dir( $d ) && ! is_file( $d ) ) continue;
		$grp = basename( $d );
		try {
			$it = is_file( $d ) ? array( new SplFileInfo( $d ) ) : new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $deadline( 175 ) ) { $gtrunc = true; break 2; }
				if ( ! $f->isFile() || ! preg_match( '/\.(php|js)$/i', $f->getFilename() ) || $f->getSize() > 1500000 ) continue;
				$gfiles++; $c = (string) @file_get_contents( $f->getPathname() ); if ( '' === $c ) continue;
				foreach ( $pat_ioc as $t ) if ( false !== strpos( $c, $t ) ) $g_ioc[] = str_replace( WP_CONTENT_DIR, '', $f->getPathname() ) . ' ← ' . $t;
				foreach ( $pat_name as $t ) if ( false !== strpos( $c, $t ) ) { $g_name[ $grp ] = ( $g_name[ $grp ] ?? 0 ) + 1; break; }
			}
		} catch ( Throwable $e ) {}
	}
	$p( 'grep файлове', $gfiles . ( $gtrunc ? ' (ПРЕКЪСНАТО по време)' : '' ) );
	$p( 'кой пише _debug_log_source / place-order-debug / IOC', $g_ioc ? '' : '— (не е намерено в активните плъгини)' );
	foreach ( array_slice( $g_ioc, 0, 30 ) as $x ) $p( '  ', $x );
	arsort( $g_name );
	$p( 'плъгини, които четат името на получателя', 'файла: ' . ( implode( ' · ', array_map( function ( $k, $v ) { return "$k×$v"; }, array_keys( $g_name ), $g_name ) ) ?: '—' ) );
	$p( '(кандидати за уязвим екран: куриерски/фактурни/CRM/export плъгини извън woocommerce)', '' );

	$L[] = '';
	$L[] = '=== край · ' . round( microtime( true ) - $T0, 1 ) . 's · памет ' . size_format( memory_get_peak_usage( true ) ) . ' ===';
	return $cache = implode( "\n", $L );
}
}
