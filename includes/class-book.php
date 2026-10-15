<?php
/**
 * ansa™ Промо — книгата с рецепти (1.9.0): брандираната страница /kniga.
 *
 *  - Страница: {сайт}/kniga (book.slug, от корена на сайта — не под промо страницата) — самостоятелен HTML като томболата (без темата).
 *    Разпознава се в parse_request по пътя (без rewrite/flush); резерва ?ansa_kniga=1 и shortcode [ansa_promo_book]. НЕ се кешира
 *    (DONOTCACHEPAGE + nocache headers) — рендира направо „книгата ти е тук“, когато браузърът носи валидната бисквитка; ако кешът
 *    все пак е сервирал формата, JS-ът вижда бисквитката и пита ajax (mode=check) → показва книгата без въвеждане.
 *  - Достъп: телефон + имейл от поръчката → ajax ansa_promo_book (rate limit 10/мин/IP, без nonce — страницата може да е кеширана).
 *    Съвпадение: поръчки с този billing имейл, чийто телефон (Tombola::same_phone) е същият, и които носят книгата — промо поръчка с
 *    _ansa_promo.book (средна/голяма кутия) ИЛИ ред с WC продукта на книгата (rewards.book.product_id). Статусът трябва да е сред
 *    book.statuses (по подразбиране processing + completed + delivered); иначе „още се обработва“. Имейл без съвпадащ телефон = „не
 *    намираме“ (нищо не се издава на половин съвпадение).
 *  - Помни: подписана бисквитка ansa_promo_book (HMAC през wp_hash, book.remember дни, по подразбиране 365) + localStorage с телефона
 *    и имейла за предварително попълване. „Забрави този браузър“ трие и двете.
 *  - Демо данни (Config demo.phone / demo.email, 🧰 Инструменти): отварят книгата без поръчка и без DB — за екипа/админа; същите
 *    данни работят и на формата на томболата (Tombola::ajax_lookup → демо резултат). Влезлият админ (manage_woocommerce) вижда
 *    книгата направо, без форма.
 *  - PDF-ът: качва се от 🧰 Инструменти в {uploads}/ansa-promo-book/{случайна папка}/ (с .htaccess „deny“ + index.html; не е attachment
 *    в Медията, за да няма публичен линк; голям файл над PHP лимита се качва по FTP в същата папка и се избира от списъка —
 *    Book::scan()/adopt()) и се сервира само през {сайт}/kniga/pdf (inline; ?dl=1 = сваляне; Range за iOS Safari)
 *    при валидна бисквитка/админ. Алтернатива: book.url — външен линк (тогава /kniga/pdf пренасочва натам; самият линк е публичен).
 *  - Имейлът при „Изпълнена“ (em.book.*) и merge тагът {{ansa_promo_book_url}} водят към /kniga (Book::link), когато страницата е
 *    включена; иначе към стария rewards.book.url.
 *
 * @package AnsaPromo
 * @since 1.9.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Book {

	const SHORTCODE = 'ansa_promo_book';
	const QV        = 'ansa_promo_book';
	const COOKIE    = 'ansa_promo_book';
	const DIR       = 'ansa-promo-book';
	const FILENAME  = 'ansa-kniga-s-recepti.pdf';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_shortcode' ), 20 );
		add_action( 'parse_request', array( __CLASS__, 'route' ) );
		add_filter( 'pre_handle_404', array( __CLASS__, 'no_404' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'render_route' ), 0 );
		add_action( 'wp_ajax_ansa_promo_book', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_ansa_promo_book', array( __CLASS__, 'ajax' ) );
		add_action( 'ansa_promo_published', array( __CLASS__, 'purge' ) );
	}

	/* ── конфиг / адреси ── */
	public static function cfg( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return $cfg['book']; }
	public static function enabled( $cfg = null ) { return ! empty( self::cfg( $cfg )['enabled'] ); }
	/** Пътят от корена на сайта (без домейн и наклонени черти): kniga. */
	public static function path( $cfg = null ) { return trim( (string) self::cfg( $cfg )['slug'], '/' ) ?: 'kniga'; }
	public static function url( $cfg = null ) { return apply_filters( 'ansa_promo_book_url', home_url( '/' . self::path( $cfg ) . '/' ), $cfg ); }
	public static function pdf_url( $cfg = null, $download = false ) { $u = home_url( '/' . self::path( $cfg ) . '/pdf/' ); return $download ? add_query_arg( 'dl', '1', $u ) : $u; }
	/** Линкът за имейлите/merge таговете: /kniga при включена страница, иначе старият rewards.book.url ('' = няма книга за изпращане). */
	public static function link( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return self::enabled( $cfg ) ? self::url( $cfg ) : (string) ( $cfg['rewards']['book']['url'] ?? '' ); }
	/** Източникът на PDF-а: 'file' (качен в скритата папка), 'url' (външен линк) или '' (още няма). */
	public static function source( $cfg = null ) { $b = self::cfg( $cfg ); if ( ! empty( $b['file'] ) && self::file_path( $cfg ) ) { return 'file'; } return ! empty( $b['url'] ) ? 'url' : ''; }
	public static function ready( $cfg = null ) { return self::enabled( $cfg ) && '' !== self::source( $cfg ); }
	/** Абсолютният път на качения PDF или '' (няма/липсва на диска). */
	public static function file_path( $cfg = null ) {
		$rel = (string) self::cfg( $cfg )['file']; if ( '' === $rel || false !== strpos( $rel, '..' ) ) { return ''; }
		$p = self::base_dir() . '/' . ltrim( $rel, '/' );
		return is_file( $p ) ? $p : '';
	}
	public static function base_dir() { $u = wp_upload_dir( null, false ); return rtrim( (string) $u['basedir'], '/' ) . '/' . self::DIR; }
	/** Кутиите, към които книгата е подарък: „Средна кутия и Голяма кутия“. */
	public static function boxes_label( $cfg ) { $n = array(); foreach ( $cfg['boxes'] as $b ) { if ( in_array( 'book', (array) $b['rw'], true ) ) { $n[] = mb_strtolower( $b['name'] ); } } return $n ? ( count( $n ) > 1 ? implode( ', ', array_slice( $n, 0, -1 ) ) . ' и ' . end( $n ) : $n[0] ) : 'кутията'; }
	public static function purge( $cfg = null ) {
		$url = self::url( is_array( $cfg ) ? $cfg : null );
		if ( class_exists( '\\FlyingPress\\Purge' ) ) { try { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( $url ); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_urls' ) ) ) { \FlyingPress\Purge::purge_urls( array( $url ) ); } } catch ( \Throwable $e ) {} }
		do_action( 'ansa_promo_purge_url', $url, 0 );
	}

	/* ── демо данни (общи за /kniga и /tombola) ── */
	public static function is_demo( $cfg, $email, $phone ) {
		$d = isset( $cfg['demo'] ) && is_array( $cfg['demo'] ) ? $cfg['demo'] : array();
		$de = strtolower( trim( (string) ( $d['email'] ?? '' ) ) ); $dp = (string) ( $d['phone'] ?? '' );
		if ( '' === $de || '' === Tombola::norm_phone( $dp ) ) { return false; }
		return $de === strtolower( trim( (string) $email ) ) && Tombola::same_phone( $dp, $phone );
	}

	/* ── маршрут ── */
	public static function route( $wp ) {
		if ( ! self::enabled() ) { return; }
		$req = trim( (string) ( $wp->request ?? '' ), '/' );
		if ( '' === $req ) { $uri = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); $home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ); if ( $home && 0 === strpos( $uri, $home ) ) { $uri = substr( $uri, strlen( $home ) ); } $req = trim( (string) $uri, '/' ); }
		$path = self::path();
		if ( $req === $path . '/pdf' || ( $req === $path && isset( $_GET['ansa_kniga_pdf'] ) ) ) { $wp->query_vars = array( self::QV => 'pdf' ); return; }
		if ( $req === $path || isset( $_GET['ansa_kniga'] ) ) { $wp->query_vars = array( self::QV => 'page' ); }
	}
	public static function is_route() { global $wp; return ! empty( $wp->query_vars[ self::QV ] ); }
	public static function no_404( $pre, $q ) { return self::is_route() ? true : $pre; }
	public static function render_route() {
		global $wp; if ( ! self::is_route() ) { return; }
		$cfg = Frontend::is_draft_request() ? Config::get_draft() : Config::get_published();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers(); header( 'X-Robots-Tag: noindex' );
		if ( 'pdf' === $wp->query_vars[ self::QV ] ) { self::serve_pdf( $cfg ); }
		status_header( 200 ); header( 'Content-Type: text/html; charset=utf-8' );
		echo self::page_html( $cfg, self::access( $cfg ) ); exit;
	}
	public static function add_shortcode() { add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) ); }
	/** [ansa_promo_book] на обикновена страница — само картата (без <html>). */
	public static function shortcode() { $cfg = Config::get_published(); return self::widget_html( $cfg, false, self::access( $cfg ) ); }

	/* ── достъп: админ · бисквитка ── */
	/** Кой има достъп сега: [via => admin|cookie|demo, name, demo] или null. */
	public static function access( $cfg ) {
		if ( ! self::enabled( $cfg ) ) { return null; }
		if ( is_user_logged_in() && current_user_can( Plugin::CAP ) ) { $u = wp_get_current_user(); return array( 'via' => 'admin', 'name' => $u->display_name, 'demo' => false ); }
		$t = self::verify( (string) ( $_COOKIE[ self::COOKIE ] ?? '' ) );
		return $t ? array( 'via' => $t['demo'] ? 'demo' : 'cookie', 'name' => $t['name'], 'demo' => $t['demo'] ) : null;
	}
	/** Подписан токен: base64url("1|hash(имейл|телефон)|изтича|демо|име") . "." . HMAC. */
	public static function token( $email, $phone, $name = '', $demo = false, $cfg = null ) {
		$days = max( 1, (int) self::cfg( $cfg )['remember'] ); $exp = time() + $days * DAY_IN_SECONDS;
		$name = mb_substr( preg_replace( '/[|\s]+/u', ' ', (string) $name ), 0, 40 );
		$payload = implode( '|', array( '1', substr( hash( 'sha256', strtolower( trim( (string) $email ) ) . '|' . Tombola::norm_phone( $phone ) ), 0, 24 ), $exp, $demo ? '1' : '0', $name ) );
		$b = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
		return array( 'value' => $b . '.' . self::sign( $b ), 'exp' => $exp );
	}
	private static function sign( $b ) { return substr( hash_hmac( 'sha256', $b, wp_hash( 'ansa_promo_book|' . $b, 'auth' ) ), 0, 32 ); }
	/** Токенът от бисквитката → [hash, exp, demo, name] или null (сгрешен/изтекъл). */
	public static function verify( $value ) {
		$value = trim( (string) $value ); if ( '' === $value || false === strpos( $value, '.' ) ) { return null; }
		list( $b, $sig ) = explode( '.', $value, 2 );
		if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $b ) || ! hash_equals( self::sign( $b ), (string) $sig ) ) { return null; }
		$p = explode( '|', (string) base64_decode( strtr( $b, '-_', '+/' ) ), 5 );
		if ( count( $p ) < 4 || '1' !== $p[0] || (int) $p[2] < time() ) { return null; }
		return array( 'hash' => $p[1], 'exp' => (int) $p[2], 'demo' => '1' === $p[3], 'name' => (string) ( $p[4] ?? '' ) );
	}
	private static function set_cookie( $t ) {
		if ( headers_sent() ) { return; }
		$opt = array( 'expires' => $t ? (int) $t['exp'] : time() - YEAR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' );
		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) { $opt['domain'] = COOKIE_DOMAIN; }
		setcookie( self::COOKIE, $t ? $t['value'] : '', $opt );
	}

	/* ── проверката ── */
	public static function ajax() {
		$cfg = Frontend::is_draft_request() ? Config::get_draft() : Config::get_published(); $v = self::base_vars( $cfg );
		$err = function ( $k, $status = 400 ) use ( $cfg, $v ) { wp_send_json_error( array( 'code' => $k, 'msg' => wp_kses_post( self::copy( $cfg, 'bk.err.' . $k, $v ) ) ), $status ); };
		$mode = sanitize_key( (string) ( $_POST['mode'] ?? '' ) );
		if ( 'forget' === $mode ) { self::set_cookie( null ); wp_send_json_success( array( 'html' => self::form_html( $cfg ) ) ); }
		if ( ! self::enabled( $cfg ) ) { $err( 'off', 403 ); }
		if ( 'check' === $mode ) { $a = self::access( $cfg ); if ( ! $a ) { $err( 'cookie', 401 ); } wp_send_json_success( array( 'html' => self::ready_html( $cfg, $a ), 'via' => $a['via'] ) ); }
		if ( Leads::rate_limited( 'book', 10 ) ) { $err( 'rate', 429 ); }
		$email = sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) ); $phone = sanitize_text_field( (string) wp_unslash( $_POST['phone'] ?? '' ) );
		if ( ! is_email( $email ) || strlen( Tombola::norm_phone( $phone ) ) < 7 ) { $err( 'input' ); }
		if ( self::is_demo( $cfg, $email, $phone ) ) {
			$t = self::token( $email, $phone, 'Демо', true, $cfg ); self::set_cookie( $t );
			wp_send_json_success( array( 'html' => self::ready_html( $cfg, array( 'via' => 'demo', 'name' => 'Демо', 'demo' => true ) ), 'via' => 'demo' ) );
		}
		$r = self::lookup( $email, $phone, $cfg );
		if ( empty( $r['ok'] ) ) { $err( $r['code'] ); }
		$t = self::token( $email, $phone, $r['customer'], false, $cfg ); self::set_cookie( $t );
		wp_send_json_success( array( 'html' => self::ready_html( $cfg, array( 'via' => 'cookie', 'name' => $r['customer'], 'demo' => false ) ), 'via' => 'order', 'orders' => count( $r['orders'] ) ) );
	}
	/**
	 * Без HTTP: поръчките с този имейл и телефон, които носят книгата → [ok, customer, orders[{id, number, status}]] или [ok=false, code].
	 * code: notfound (нищо с този телефон+имейл) · nobook (има поръчка, но без книга) · notyet (с книга, но статусът не е сред book.statuses).
	 */
	public static function lookup( $email, $phone, $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $b = $cfg['book'];
		if ( ! function_exists( 'wc_get_orders' ) ) { return array( 'ok' => false, 'code' => 'off' ); }
		$match = array();
		foreach ( Tombola::orders_by_email( $email ) as $o ) { if ( Tombola::same_phone( $o->get_billing_phone(), $phone ) || Tombola::same_phone( $o->get_shipping_phone(), $phone ) ) { $match[] = $o; } }
		if ( ! $match ) { return array( 'ok' => false, 'code' => 'notfound' ); }
		$with = array_values( array_filter( $match, function ( $o ) use ( $cfg ) { return self::order_has_book( $o, $cfg ); } ) );
		if ( ! $with ) { return array( 'ok' => false, 'code' => 'nobook' ); }
		$ok = array_values( array_filter( $with, function ( $o ) use ( $b ) { return in_array( $o->get_status(), (array) $b['statuses'], true ); } ) );
		if ( ! $ok ) {
			$alive = array_filter( $with, function ( $o ) { return ! in_array( $o->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ); } );
			return array( 'ok' => false, 'code' => $alive ? 'notyet' : 'notfound' );
		}
		$out = array( 'ok' => true, 'customer' => '', 'orders' => array(), 'email' => $email );
		foreach ( $ok as $o ) { if ( '' === $out['customer'] ) { $out['customer'] = (string) ( $o->get_billing_first_name() ?: $o->get_billing_last_name() ); } $out['orders'][] = array( 'id' => $o->get_id(), 'number' => $o->get_order_number(), 'status' => $o->get_status() ); }
		return $out;
	}
	/** Поръчката носи книгата: промо поръчка с _ansa_promo.book (валидна кутия) или ред с WC продукта на книгата. */
	public static function order_has_book( $order, $cfg ) {
		$m = $order->get_meta( '_ansa_promo' );
		if ( is_array( $m ) && ! empty( $m['valid'] ) && ! empty( $m['book'] ) ) { return true; }
		$pid = (int) ( $cfg['rewards']['book']['product_id'] ?? 0 ); if ( $pid <= 0 ) { return false; }
		foreach ( $order->get_items( 'line_item' ) as $it ) { if ( (int) $it->get_product_id() === $pid || (int) $it->get_variation_id() === $pid ) { return true; } }
		return false;
	}
	/** Диагностика за админа/Probe: всяка поръчка с този имейл и защо (не) отключва книгата; без бисквитка. */
	public static function diagnose( $email, $phone, $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $rows = array();
		foreach ( Tombola::orders_by_email( $email ) as $o ) {
			$why = array(); $bp = (string) $o->get_billing_phone(); $sp = (string) $o->get_shipping_phone();
			$phone_ok = '' !== Tombola::norm_phone( $phone ) && ( Tombola::same_phone( $bp, $phone ) || Tombola::same_phone( $sp, $phone ) );
			if ( ! $phone_ok ) { $why[] = 'телефонът не съвпада: в поръчката „' . $bp . '“' . ( $sp && $sp !== $bp ? ' / „' . $sp . '“' : '' ) . ', въведен „' . $phone . '“'; }
			if ( ! self::order_has_book( $o, $cfg ) ) { $why[] = 'поръчката не носи книгата (няма _ansa_promo.book и няма ред с продукт #' . (int) $cfg['rewards']['book']['product_id'] . ')'; }
			if ( ! in_array( $o->get_status(), (array) $cfg['book']['statuses'], true ) ) { $why[] = 'статусът „' . $o->get_status() . '“ не е сред (' . implode( ', ', (array) $cfg['book']['statuses'] ) . ')'; }
			$rows[] = array( 'id' => $o->get_id(), 'number' => $o->get_order_number(), 'status' => $o->get_status(), 'ok' => ! $why, 'why' => $why, 'edit' => $o->get_edit_order_url() );
		}
		return array( 'orders' => $rows, 'result' => self::lookup( $email, $phone, $cfg ) );
	}

	/* ── PDF ── */
	private static function serve_pdf( $cfg ) {
		$a = self::access( $cfg );
		if ( ! $a ) { wp_safe_redirect( self::url( $cfg ), 302 ); exit; }
		$src = self::source( $cfg );
		if ( '' === $src ) { status_header( 404 ); header( 'Content-Type: text/html; charset=utf-8' ); echo self::page_html( $cfg, $a ); exit; }
		if ( 'url' === $src ) { wp_redirect( esc_url_raw( (string) $cfg['book']['url'] ), 302 ); exit; }
		$path = self::file_path( $cfg ); $size = (int) filesize( $path ); $dl = ! empty( $_GET['dl'] );
		do_action( 'ansa_promo_book_served', $a, $cfg, $dl );
		@set_time_limit( 0 ); @ini_set( 'zlib.output_compression', '0' ); while ( ob_get_level() ) { ob_end_clean(); }
		header( 'Content-Type: application/pdf' ); header( 'Content-Disposition: ' . ( $dl ? 'attachment' : 'inline' ) . '; filename="' . self::FILENAME . '"' ); header( 'Accept-Ranges: bytes' ); header( 'X-Content-Type-Options: nosniff' );
		$start = 0; $end = $size - 1;
		if ( ! empty( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m ) ) { /* iOS Safari чете PDF-а на парчета */
			if ( '' !== $m[1] ) { $start = (int) $m[1]; $end = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1; } elseif ( '' !== $m[2] ) { $start = max( 0, $size - (int) $m[2] ); }
			if ( $start > $end || $start >= $size ) { status_header( 416 ); header( 'Content-Range: bytes */' . $size ); exit; }
			status_header( 206 ); header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		} else { status_header( 200 ); }
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( 'HEAD' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { exit; }
		$fh = fopen( $path, 'rb' ); if ( ! $fh ) { exit; }
		fseek( $fh, $start ); $left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) ) { $chunk = fread( $fh, min( 1048576, $left ) ); if ( false === $chunk ) { break; } echo $chunk; flush(); $left -= strlen( $chunk ); }
		fclose( $fh ); exit;
	}
	/** 🧰 Инструменти: качва PDF-а в {uploads}/ansa-promo-book/{случайна папка}/ → относителен път за book.file или WP_Error. */
	public static function store_upload( $file ) {
		if ( empty( $file['name'] ) || ! empty( $file['error'] ) ) { return new \WP_Error( 'upload', 'Качването не успя (код ' . (int) ( $file['error'] ?? 0 ) . ') — провери upload_max_filesize/post_max_size на хостинга.' ); }
		$ft = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'pdf' => 'application/pdf' ) );
		if ( 'pdf' !== ( $ft['ext'] ?? '' ) ) { return new \WP_Error( 'type', 'Файлът трябва да е PDF.' ); }
		$dir = self::base_dir() . '/' . wp_generate_password( 20, false );
		if ( ! wp_mkdir_p( $dir ) ) { return new \WP_Error( 'dir', 'Папката ' . $dir . ' не може да се създаде.' ); }
		self::protect_dir( self::base_dir() ); self::protect_dir( $dir );
		$name = sanitize_file_name( (string) $file['name'] ) ?: 'kniga.pdf'; if ( ! preg_match( '/\.pdf$/i', $name ) ) { $name .= '.pdf'; }
		$dest = $dir . '/' . $name;
		if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) && ! @copy( $file['tmp_name'], $dest ) ) { return new \WP_Error( 'move', 'Файлът не може да се запише в ' . $dest ); }
		@chmod( $dest, 0644 );
		return basename( $dir ) . '/' . $name;
	}
	/** PDF-и, качени по FTP в {uploads}/ansa-promo-book/ (в корена ѝ или в подпапка): [[rel, name, size]] — за големи файлове над PHP лимита. */
	public static function scan() {
		$base = self::base_dir(); $out = array(); if ( ! is_dir( $base ) ) { return $out; }
		foreach ( array_merge( (array) glob( $base . '/*.[pP][dD][fF]' ), (array) glob( $base . '/*/*.[pP][dD][fF]' ) ) as $p ) { if ( is_file( $p ) ) { $out[] = array( 'rel' => ltrim( substr( $p, strlen( $base ) ), '/' ), 'name' => basename( $p ), 'size' => size_format( filesize( $p ) ) ); } }
		return $out;
	}
	/** Приема файл от scan(): ако е в корена на папката — мести го в случайна подпапка (да не е с познат адрес на nginx), слага .htaccess; → rel или ''. */
	public static function adopt( $rel ) {
		$rel = ltrim( str_replace( '..', '', (string) $rel ), '/' ); $ok = false; foreach ( self::scan() as $f ) { if ( $f['rel'] === $rel ) { $ok = true; } } if ( ! $ok ) { return ''; }
		$base = self::base_dir(); self::protect_dir( $base );
		if ( false === strpos( $rel, '/' ) ) { $dir = $base . '/' . wp_generate_password( 20, false ); if ( wp_mkdir_p( $dir ) && @rename( $base . '/' . $rel, $dir . '/' . $rel ) ) { $rel = basename( $dir ) . '/' . $rel; } }
		if ( false !== strpos( $rel, '/' ) ) { self::protect_dir( dirname( $base . '/' . $rel ) ); }
		return $rel;
	}
	private static function protect_dir( $dir ) {
		if ( ! is_file( $dir . '/.htaccess' ) ) { @file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); }
		if ( ! is_file( $dir . '/index.html' ) ) { @file_put_contents( $dir . '/index.html', '' ); }
	}
	/** Трие качен файл (относителен път) и папката му, освен ако публикуваният конфиг още го ползва. */
	public static function delete_file( $rel, $keep_published = true ) {
		$rel = (string) $rel; if ( '' === $rel || false !== strpos( $rel, '..' ) ) { return false; }
		if ( $keep_published && $rel === (string) ( Config::get_published()['book']['file'] ?? '' ) ) { return false; }
		$p = self::base_dir() . '/' . ltrim( $rel, '/' ); if ( ! is_file( $p ) ) { return false; }
		@unlink( $p ); $d = dirname( $p ); if ( $d !== self::base_dir() ) { foreach ( array( '.htaccess', 'index.html' ) as $f ) { @unlink( $d . '/' . $f ); } @rmdir( $d ); }
		return true;
	}
	/** За админа/Doctor: [source, name, size, mtime, url]. */
	public static function file_info( $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $src = self::source( $cfg ); $p = self::file_path( $cfg );
		return array( 'source' => $src, 'name' => $p ? basename( $p ) : '', 'size' => $p ? size_format( filesize( $p ) ) : '', 'mtime' => $p ? date_i18n( 'd.m.Y H:i', filemtime( $p ) ) : '', 'url' => (string) $cfg['book']['url'], 'rel' => (string) $cfg['book']['file'] );
	}

	/* ── HTML ── */
	private static function copy( $cfg, $k, $vars = array() ) { static $c = null; if ( null === $c ) { $c = Copy::effective( $cfg['copy'] ); } return Copy::render( isset( $c[ $k ] ) ? $c[ $k ] : $k, $vars ); }
	private static function base_vars( $cfg ) {
		$box = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][0]; $v = Cart::vars( $cfg, $box );
		$v['book_url'] = self::url( $cfg ); $v['book_page'] = preg_replace( '#^https?://#', '', untrailingslashit( self::url( $cfg ) ) ); $v['book_pdf'] = self::pdf_url( $cfg ); $v['book_boxes'] = self::boxes_label( $cfg ); $v['customer'] = '';
		return $v;
	}
	public static function page_html( $cfg, $access = null ) {
		$v = self::base_vars( $cfg ); $title = wp_strip_all_tags( self::copy( $cfg, 'bk.title', $v ) );
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title . ' · ' . $cfg['name'] ) . '</title>'
			. '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap">'
			. '<style>html,body{margin:0;padding:0;background:#faf3ec;color:#1a1a2e;font-family:Nunito,-apple-system,BlinkMacSystemFont,sans-serif;-webkit-font-smoothing:antialiased}body{min-height:100dvh;display:flex;align-items:flex-start;justify-content:center;padding:max(24px,env(safe-area-inset-top)) 16px 40px;box-sizing:border-box}</style>'
			. '</head><body class="ansa-promo-book-page">' . self::widget_html( $cfg, true, $access ) . '</body></html>';
	}
	/** Картата; $full = самостоятелната страница (с „ansa™“ отгоре); $access ≠ null → направо „книгата ти е тук“. */
	public static function widget_html( $cfg, $full = true, $access = null ) {
		$v = self::base_vars( $cfg );
		$texts = array(); foreach ( array( 'bk.wait', 'bk.cta', 'bk.err.net' ) as $k ) { $texts[ $k ] = self::copy( $cfg, $k, $v ); }
		$rt = array( 'ver' => ANSA_PROMO_VER, 'ajax' => admin_url( 'admin-ajax.php' ), 'cookie' => self::COOKIE, 'pdf' => self::pdf_url( $cfg ), 'texts' => $texts, 'live' => self::enabled( $cfg ), 'state' => $access ? 'ready' : 'form' );
		$h = '<div class="ansa-tb ansa-bk" id="ansaBook" data-ver="' . esc_attr( ANSA_PROMO_VER ) . '" data-state="' . esc_attr( $rt['state'] ) . '">' . self::css();
		$h .= '<div class="ansa-tb__card">' . ( $full ? '<div class="ansa-tb__brand">ansa™</div>' : '' ) . '<div id="ansaBkBody">' . ( $access ? self::ready_html( $cfg, $access ) : self::form_html( $cfg ) ) . '</div></div>';
		$h .= '<script>window.AnsaBook=' . wp_json_encode( $rt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script><script>' . self::js() . '</script></div>';
		return $h;
	}
	/** Формата (телефон + имейл). */
	public static function form_html( $cfg ) {
		$v = self::base_vars( $cfg );
		return '<h1>' . wp_kses_post( self::copy( $cfg, 'bk.title', $v ) ) . '</h1><p class="ansa-tb__sub">' . wp_kses_post( self::copy( $cfg, 'bk.sub', $v ) ) . '</p>'
			. '<form class="ansa-tb__form" id="ansaBkForm" novalidate><label><span>' . esc_html( self::copy( $cfg, 'bk.phone.label' ) ) . '</span><input type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="' . esc_attr( self::copy( $cfg, 'bk.phone.ph' ) ) . '" required></label>'
			. '<label><span>' . esc_html( self::copy( $cfg, 'bk.email.label' ) ) . '</span><input type="email" name="email" inputmode="email" autocomplete="email" placeholder="' . esc_attr( self::copy( $cfg, 'bk.email.ph' ) ) . '" required></label>'
			. '<button type="submit" class="ansa-tb__btn">' . esc_html( self::copy( $cfg, 'bk.cta' ) ) . '</button><p class="ansa-tb__err" id="ansaBkErr" hidden></p></form>'
			. '<p class="ansa-tb__foot">' . wp_kses_post( self::copy( $cfg, 'bk.foot', $v ) ) . '</p>';
	}
	/** „Книгата ти е тук“: бутон „Отвори“ (нов таб), „Свали PDF“, на десктоп и вграден преглед; „Забрави този браузър“. */
	public static function ready_html( $cfg, $access ) {
		$v = self::base_vars( $cfg ); $v['customer'] = (string) ( $access['name'] ?? '' ); $v['customer_hi'] = '' !== $v['customer'] ? ', ' . $v['customer'] : '';
		$src = self::source( $cfg ); $pdf = self::pdf_url( $cfg ); $dl = self::pdf_url( $cfg, true );
		$h = '<div class="ansa-tb__ok ansa-bk__ok"><h2>' . wp_kses_post( self::copy( $cfg, 'bk.ok.h', $v ) ) . '</h2><div class="ansa-bk__ic">📖</div>';
		if ( '' === $src ) { $h .= '<p class="ansa-tb__err" style="text-align:center">' . wp_kses_post( self::copy( $cfg, 'bk.err.nofile', $v ) ) . '</p>'; }
		else {
			$h .= '<p>' . wp_kses_post( self::copy( $cfg, 'bk.ok.p', $v ) ) . '</p>'
				. '<a class="ansa-tb__btn ansa-bk__open" href="' . esc_url( $pdf ) . '" target="_blank" rel="noopener">' . esc_html( self::copy( $cfg, 'bk.open', $v ) ) . '</a>'
				. ( 'file' === $src ? '<a class="ansa-bk__dl" href="' . esc_url( $dl ) . '">' . esc_html( self::copy( $cfg, 'bk.dl', $v ) ) . '</a>' : '' )
				. ( 'file' === $src ? '<div class="ansa-bk__embed" data-src="' . esc_url( $pdf ) . '"></div>' : '' );
		}
		if ( ! empty( $access['demo'] ) ) { $h .= '<p class="ansa-tb__demo">' . wp_kses_post( self::copy( $cfg, 'bk.demo', $v ) ) . '</p>'; }
		if ( 'admin' === ( $access['via'] ?? '' ) ) { $h .= '<p class="ansa-tb__demo">👤 Виждаш книгата като админ (без проверка на поръчка).</p>'; }
		else { $h .= '<button type="button" class="ansa-tb__again" data-forget>' . esc_html( self::copy( $cfg, 'bk.forget', $v ) ) . '</button>'; }
		$h .= '<p class="ansa-tb__foot">' . wp_kses_post( self::copy( $cfg, 'bk.foot', $v ) ) . '</p></div>';
		return apply_filters( 'ansa_promo_book_ready_html', $h, $access, $cfg );
	}
	private static function css() {
		return '<style id="ansa-bk-css">.ansa-tb{width:100%;max-width:520px;margin:0 auto;font-family:Nunito,-apple-system,BlinkMacSystemFont,sans-serif;color:#1a1a2e}'
			. '.ansa-tb__card{--tbx:22px;--tbb:26px;background:#fff;border:1.5px solid rgba(232,114,42,.25);border-radius:22px;padding:26px var(--tbx) var(--tbb);box-shadow:0 14px 40px rgba(232,114,42,.12)}'
			. '.ansa-tb__brand{font-weight:900;color:#e8722a;font-size:22px}.ansa-tb h1{margin:6px 0 6px;font-size:26px;line-height:1.15;font-weight:900;letter-spacing:-.02em}.ansa-tb__sub{margin:0 0 18px;font-size:14.5px;color:#6b5f58;font-weight:600;line-height:1.45}'
			. '.ansa-tb__form label{display:block;margin-bottom:12px}.ansa-tb__form label span{display:block;font-size:12.5px;font-weight:800;color:#7a6a62;margin:0 0 5px 2px;text-transform:uppercase;letter-spacing:.04em}'
			. '.ansa-tb__form input{width:100%;box-sizing:border-box;font:inherit;font-size:17px;font-weight:700;padding:13px 14px;border:1.5px solid rgba(232,114,42,.35);border-radius:12px;background:#fff;color:#1a1a2e;outline:none}.ansa-tb__form input:focus{border-color:#e8722a;box-shadow:0 0 0 3px rgba(232,114,42,.15)}'
			. '.ansa-tb__btn{display:flex;align-items:center;justify-content:center;width:100%;box-sizing:border-box;font:inherit;font-size:17px;font-weight:900;color:#fff;border:none;border-radius:14px;min-height:52px;margin-top:4px;background:linear-gradient(135deg,#ef8c4f,#e8722a 50%,#e56b50);cursor:pointer;box-shadow:0 8px 20px rgba(232,114,42,.25);text-decoration:none}.ansa-tb__btn[disabled]{opacity:.6;cursor:wait}'
			. '.ansa-tb__err{color:#b91c1c;font-weight:800;margin:12px 0 0;font-size:14px;line-height:1.45}.ansa-tb__foot{margin:18px 0 0;font-size:12.5px;color:#a88a75;font-weight:600;text-align:center;line-height:1.45}'
			. '.ansa-tb__ok{text-align:center}.ansa-tb__ok h2{margin:4px 0 6px;font-size:24px;font-weight:900;line-height:1.2}.ansa-tb__ok p{margin:8px 0;font-size:15px;line-height:1.45;font-weight:600}'
			. '.ansa-bk__ic{font-size:56px;line-height:1;margin:8px 0 4px}.ansa-bk__open{margin-top:14px}.ansa-bk__dl{display:inline-block;margin:12px 0 0;font-size:14px;font-weight:800;color:#e8722a;text-decoration:underline}'
			. '.ansa-bk__embed{display:none;margin:18px calc(-1*var(--tbx)) 0;border-top:1.5px solid rgba(232,114,42,.25)}.ansa-bk__embed iframe{display:block;width:100%;height:min(78vh,1000px);border:0;background:#f3ebe3}'
			. '.ansa-tb__demo{margin:12px 0 0!important;font-size:12.5px!important;font-weight:800!important;color:#7a6a62!important;background:#fff8f1;border:1px dashed #f3cdb8;border-radius:12px;padding:8px 10px}'
			. '.ansa-tb__again{display:block;margin:16px auto 0;background:none;border:none;font:inherit;font-size:13.5px;font-weight:800;color:#a88a75;text-decoration:underline;cursor:pointer}'
			. '@media (min-width:760px){.ansa-bk[data-embed] {max-width:900px}.ansa-bk[data-embed] .ansa-bk__embed{display:block}}'
			. '@media (max-width:480px){.ansa-tb__card{--tbx:16px;--tbb:22px;padding:22px var(--tbx) var(--tbb);border-radius:18px}.ansa-tb h1{font-size:23px}}</style>';
	}
	private static function js() {
		return '(function(){var R=window.AnsaBook||{},T=R.texts||{},root=document.getElementById("ansaBook"),body=document.getElementById("ansaBkBody");if(!root||!body)return;var LS="ansaBook";'
			. 'function ls(){try{return JSON.parse(localStorage.getItem(LS)||"null")||{}}catch(e){return{}}}function lsSet(v){try{if(v)localStorage.setItem(LS,JSON.stringify(v));else localStorage.removeItem(LS)}catch(e){}}'
			. 'function hasCookie(){return new RegExp("(^|; )"+R.cookie+"=[^;]").test(document.cookie)}'
			. 'function post(fd){fd.append("action","ansa_promo_book");return fetch(R.ajax,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()})}'
			. 'function swap(html,state){body.innerHTML=html;root.setAttribute("data-state",state);wire();try{root.scrollIntoView({behavior:"smooth",block:"start"})}catch(e){}}'
			. 'function wire(){var f=document.getElementById("ansaBkForm"),err=document.getElementById("ansaBkErr");'
			. 'if(f){var s=ls();if(s.phone&&!f.phone.value)f.phone.value=s.phone;if(s.email&&!f.email.value)f.email.value=s.email;var btn=f.querySelector("button"),cta=btn.textContent;'
			. 'function show(msg){err.hidden=!msg;err.innerHTML=msg||""}'
			. 'f.addEventListener("submit",function(ev){ev.preventDefault();show("");var fd=new FormData(f);btn.disabled=true;btn.textContent=T["bk.wait"]||"…";'
			. 'post(fd).then(function(d){btn.disabled=false;btn.textContent=cta;if(!d||!d.success){show((d&&d.data&&d.data.msg)||T["bk.err.net"]);return}lsSet({phone:f.phone.value,email:f.email.value});swap(d.data.html,"ready")})'
			. '.catch(function(){btn.disabled=false;btn.textContent=cta;show(T["bk.err.net"]||"Грешка — опитай пак.")})})}'
			. 'var fg=body.querySelector("[data-forget]");if(fg)fg.onclick=function(){fg.disabled=true;var fd=new FormData();fd.append("mode","forget");lsSet(null);document.cookie=R.cookie+"=; Max-Age=0; path=/";post(fd).then(function(d){if(d&&d.success)swap(d.data.html,"form");else location.reload()}).catch(function(){location.reload()})};'
			/* вграденият преглед — само на широк екран (на телефона PDF-ът се отваря в нов таб) */
			. 'var em=body.querySelector(".ansa-bk__embed");if(em&&window.matchMedia&&matchMedia("(min-width:760px)").matches){root.setAttribute("data-embed","1");if(!em.firstChild){var i=document.createElement("iframe");i.src=em.getAttribute("data-src")+"#view=FitH";i.title="PDF";i.loading="lazy";em.appendChild(i)}}else root.removeAttribute("data-embed")}'
			. 'wire();'
			/* кеширана страница с формата, а браузърът носи бисквитката → питаме сървъра и показваме книгата без въвеждане */
			. 'if(root.getAttribute("data-state")==="form"&&R.live&&hasCookie()){var fd=new FormData();fd.append("mode","check");post(fd).then(function(d){if(d&&d.success)swap(d.data.html,"ready")}).catch(function(){})}'
			. '})();';
	}
}
