<?php
/**
 * ansa™ Промо 1.27.0 — Insights URL: отделният адрес на 📈 Insights за екипа по реклами (човекът: „Insights URL, за да може рекламистите
 * ни да следят всички метрики по фунията … dedicated, скрит с парола … max secure, да не може да се bruteforce-не“).
 *
 *  - Адрес: {сайт}/promo-insights/ (слъгът се сменя от 🧰 Настройки → 📈 Insights URL). Самостоятелна страница без темата и без wp-admin —
 *    екипът няма WP акаунт. Разпознава се в parse_request по пътя (както /kniga); НЕ се кешира (DONOTCACHEPAGE още на plugins_loaded + nocache;
 *    дашбордът е винаги на адрес с ?view=…, голият адрес носи само формата за вход — 1.27.1), noindex.
 *  - Вход: потребител + парола (начално adminpromo / паролата, дадена от човека — в кода стои САМО bcrypt хешът ѝ, DEFAULT_HASH). Сменят се от
 *    настройките; option ansa_promo_ins_portal е ИЗВЪН draft/published (не влиза в JSON износа, не се публикува). Паролата се пази като
 *    password_hash (bcrypt, cost 12), проверява се с password_verify; потребителят — с hash_equals; при грешно име паролата пак се проверява
 *    (еднакво време, без да се познава кое е грешно). Минимум 12 знака при смяна.
 *  - Срещу brute force: неуспешните опити се броят по IP — и по CF-Connecting-IP, и по REMOTE_ADDR, за да не се заобикаля с подправен хедър (5 → 15 мин блок, 10 → 1 час, 20 → 24 часа; опитите по време на блока също се броят за IP-то) и общо за всички IP (25 за
 *    15 мин → 15 мин блок за всички — спира и разпределен опит от много адреси). Блокът е в option (не в transient — object cache може да го
 *    загуби). Докато трае, формата не проверява паролата изобщо. Всеки опит (успех/грешка/блок) е в дневника (последните 100, с IP) в
 *    настройките; оттам се отключва и се чисти. Съобщението за грешка е едно и също за грешно име и грешна парола.
 *  - Сесия: подписана бисквитка (HMAC-SHA256 с ключ от wp_salt('auth') + хеша на паролата + поколение gen) с изтичане (ttl часа, по
 *    подразбиране 12), вързана към браузъра (хеш на User-Agent). Смяна на паролата или „Изход от всички устройства“ вдига gen → всички
 *    сесии падат. Бисквитката е HttpOnly, SameSite=Strict, Secure, само за пътя на портала. Формата за вход носи CSRF токен (двойна
 *    бисквитка + скрито поле) и проверява Origin/Sec-Fetch-Site.
 *  - Само HTTPS (настройка, по подразбиране включена): по http:// формата не приема парола (is_ssl, X-Forwarded-Proto, CF-Visitor).
 *  - Заглавия: Content-Security-Policy, X-Frame-Options DENY, nosniff, Referrer-Policy same-origin, X-Robots-Tag noindex.
 *  - Влезлият WP админ (manage_woocommerce) вижда портала направо, без вход.
 *  - Самият екран е Insights::render() в режим портал (Insights::$portal): линковете/формите/ajax-ът сочат към портала (?view=, ?csv=,
 *    ?action=ansa_promo_insights_feed), диагностиката „🩺 Работи ли отчитането?“ (с бутони, които пишат) не се показва.
 *
 * @package AnsaPromo
 * @since 1.27.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Insights_Portal {

	const OPT         = 'ansa_promo_ins_portal';
	const OPT_FAIL    = 'ansa_promo_ins_portal_fail';
	const OPT_LOG     = 'ansa_promo_ins_portal_log';
	const QV          = 'ansa_promo_insights';
	const COOKIE      = 'ansa_promo_ins';
	const CSRF_COOKIE = 'ansa_promo_ins_csrf';
	const DEFAULT_SLUG = 'promo-insights';
	const DEFAULT_USER = 'adminpromo';
	/** bcrypt (cost 12) на началната парола, дадена от човека — самата парола НЕ е в кода. Смени я от настройките при първото влизане. */
	const DEFAULT_HASH = '$2y$12$30nTL2R5g2CKMNMVaDI7qugD5ZJoJelS0if5Q9b4Kugt0g92D5Ycq';
	const MIN_PW      = 12;
	const TTL_DEFAULT = 12; const TTL_OPTIONS = array( 2, 4, 8, 12, 24 );
	/* лимитите */
	const WINDOW      = 15 * MINUTE_IN_SECONDS;  /* прозорецът за броене на неуспехите */
	const IP_RESET    = DAY_IN_SECONDS;          /* броячът на IP се нулира след 24 ч без опити */
	const IP_LOCKS    = array( 20 => DAY_IN_SECONDS, 10 => HOUR_IN_SECONDS, 5 => 15 * MINUTE_IN_SECONDS ); /* n неуспеха → блок */
	const GLOBAL_MAX  = 25; const GLOBAL_LOCK = 15 * MINUTE_IN_SECONDS;
	const LOG_MAX     = 100;

	private static $cur = null; private static $cur_done = false;

	public static function register() {
		/* 1.27.1: FlyingPress кешира страницата, щом DONOTCACHEPAGE се появи чак на template_redirect (кешира формата за вход със стар CSRF токен и без
		   бисквитка → „Формата е изтекла“). Затова константата се дефинира ТУК — на plugins_loaded, по пътя на заявката, преди който и да е кеш да реши. */
		if ( self::request_matches() ) { if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); } if ( ! defined( 'DONOTCACHEOBJECT' ) ) { define( 'DONOTCACHEOBJECT', true ); } }
		add_action( 'parse_request', array( __CLASS__, 'route' ) );
		add_filter( 'pre_handle_404', array( __CLASS__, 'no_404' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'render_route' ), 0 );
	}

	/* ── настройки (option извън draft/published) ── */
	public static function defaults() { return array( 'enabled' => true, 'slug' => self::DEFAULT_SLUG, 'user' => self::DEFAULT_USER, 'hash' => self::DEFAULT_HASH, 'gen' => 1, 'ttl' => self::TTL_DEFAULT, 'https_only' => true, 'changed_at' => 0, 'created_at' => 0 ); }
	public static function settings() {
		$raw = get_option( self::OPT, null );
		$s = array_merge( self::defaults(), is_array( $raw ) ? $raw : array() );
		$s['enabled'] = ! empty( $s['enabled'] ); $s['https_only'] = ! empty( $s['https_only'] ); $s['slug'] = self::norm_slug( (string) $s['slug'] ); $s['user'] = self::norm_user( (string) $s['user'] ) ?: self::DEFAULT_USER;
		$s['gen'] = max( 1, (int) $s['gen'] ); $s['ttl'] = in_array( (int) $s['ttl'], self::TTL_OPTIONS, true ) ? (int) $s['ttl'] : self::TTL_DEFAULT; $s['hash'] = (string) $s['hash'] ?: self::DEFAULT_HASH;
		$s['changed_at'] = (int) $s['changed_at']; $s['created_at'] = (int) $s['created_at'];
		if ( ! is_array( $raw ) ) { $s['created_at'] = time(); update_option( self::OPT, $s, true ); } /* първо зареждане: началният акаунт се записва веднъж */
		return $s;
	}
	private static function write( $s ) { update_option( self::OPT, $s, true ); self::$cur_done = false; }
	public static function norm_slug( $v ) { $v = trim( preg_replace( '/[^a-z0-9\-\/]+/', '-', strtolower( trim( (string) $v ) ) ), "-/ \t\n\r" ); $v = preg_replace( '#/+#', '/', $v ); return '' !== $v && strlen( $v ) <= 64 && 'wp-admin' !== $v && 'wp-login.php' !== $v ? $v : self::DEFAULT_SLUG; }
	public static function norm_user( $v ) { $v = strtolower( trim( (string) $v ) ); return preg_match( '/^[a-z0-9._\-]{3,32}$/', $v ) ? $v : ''; }
	/** 🧰 Настройки → 📈 Insights URL → 💾 Запази. Връща [ok, съобщение]. Нова парола само ако е подадена. */
	public static function save( $d ) {
		$s = self::settings(); $msg = array();
		$s['enabled'] = ! empty( $d['enabled'] ); $s['https_only'] = ! empty( $d['https_only'] );
		$slug = self::norm_slug( (string) ( $d['slug'] ?? '' ) ); if ( $slug !== $s['slug'] ) { $s['slug'] = $slug; $msg[] = 'адресът е ' . self::url_for( $slug ); }
		$user = self::norm_user( (string) ( $d['user'] ?? '' ) );
		if ( '' === $user && '' !== trim( (string) ( $d['user'] ?? '' ) ) ) { return array( false, 'Потребителското име трябва да е 3–32 знака: малки латински букви, цифри, точка, тире, долна черта.' ); }
		if ( '' !== $user && $user !== $s['user'] ) { $s['user'] = $user; $s['gen']++; $msg[] = 'потребителят е „' . $user . '“ (старите сесии са излезли)'; }
		$ttl = (int) ( $d['ttl'] ?? $s['ttl'] ); if ( in_array( $ttl, self::TTL_OPTIONS, true ) ) { $s['ttl'] = $ttl; }
		$pw = (string) ( $d['password'] ?? '' ); $pw2 = (string) ( $d['password2'] ?? '' );
		if ( '' !== $pw || '' !== $pw2 ) {
			$chk = self::password_policy( $pw, $pw2, $s['user'] ); if ( true !== $chk ) { return array( false, $chk ); }
			$s['hash'] = password_hash( $pw, PASSWORD_BCRYPT, array( 'cost' => 12 ) ); $s['gen']++; $s['changed_at'] = time(); $msg[] = 'паролата е сменена (всички сесии са излезли)';
			self::log( 'pwchange', $s['user'], Leads::ip() );
		}
		$old_slug = self::settings()['slug']; self::write( $s ); self::purge(); if ( $old_slug !== $s['slug'] ) { try { if ( class_exists( '\\FlyingPress\\Purge' ) && is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( self::url_for( $old_slug ) ); } } catch ( \Throwable $e ) {} }
		return array( true, 'Insights URL: записано' . ( $msg ? ' — ' . implode( '; ', $msg ) : '' ) . '.' );
	}
	/** Политиката за паролата: ≥ 12 знака, не е потребителското име, двете полета съвпадат. true или текст на грешката. */
	public static function password_policy( $pw, $pw2, $user = '' ) {
		if ( strlen( $pw ) < self::MIN_PW ) { return 'Паролата трябва да е поне ' . self::MIN_PW . ' знака.'; }
		if ( strlen( $pw ) > 256 ) { return 'Паролата е прекалено дълга (до 256 знака).'; }
		if ( $pw !== $pw2 ) { return 'Двете полета за парола не съвпадат.'; }
		if ( '' !== $user && false !== stripos( $pw, $user ) ) { return 'Паролата не бива да съдържа потребителското име.'; }
		if ( preg_match( '/^(.)\1*$/', $pw ) || preg_match( '/^(?:0123456789|1234567890|qwertyuiop|password)/i', $pw ) ) { return 'Паролата е прекалено проста.'; }
		return true;
	}
	/** „Изход от всички устройства“ — поколението се вдига, всяка издадена бисквитка пада. */
	public static function logout_all() { $s = self::settings(); $s['gen']++; self::write( $s ); delete_option( self::OPT_REVOKED ); self::log( 'logout_all', $s['user'], Leads::ip() ); }
	public static function enabled() { return self::settings()['enabled']; }
	public static function path() { return self::settings()['slug']; }
	public static function url( $args = array() ) { $u = self::url_for( self::path() ); unset( $args['tab'], $args['page'] ); $args = array_filter( (array) $args, function ( $v ) { return null !== $v && '' !== (string) $v; } ); return $args ? add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $u ) : $u; }
	public static function url_for( $slug ) { return home_url( '/' . trim( (string) $slug, '/' ) . '/' ); }
	/** Пътят за бисквитките — без крайна наклонена черта, за да важи и за /promo-insights, и за /promo-insights/… */
	private static function cookie_path() { $p = (string) wp_parse_url( self::url_for( self::path() ), PHP_URL_PATH ); return rtrim( $p, '/' ) ?: '/'; }

	/* ── HTTPS ── */
	public static function is_https() {
		if ( function_exists( 'is_ssl' ) && is_ssl() ) { return true; }
		if ( 'https' === strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) ) ) { return true; }
		$cf = (string) ( $_SERVER['HTTP_CF_VISITOR'] ?? '' ); if ( '' !== $cf && false !== strpos( $cf, '"https"' ) ) { return true; }
		return false;
	}
	public static function https_ok() { return ! self::settings()['https_only'] || self::is_https(); }

	/* ── сесията (подписана бисквитка) ── */
	private static function key( $s = null ) { $s = $s ?: self::settings(); return hash_hmac( 'sha256', 'ansa_promo_ins_portal|' . $s['hash'] . '|' . (int) $s['gen'], wp_salt( 'auth' ) ); }
	public static function ua_hash( $ua = null ) { $ua = null === $ua ? (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) : (string) $ua; return substr( hash( 'sha256', $ua ), 0, 12 ); }
	private static function b64e( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); }
	private static function b64d( $s ) { return base64_decode( strtr( (string) $s, '-_', '+/' ) ); }
	/** Токен: base64url(json{u,iat,exp,g,ua}).HMAC. $ttl в часове (по подразбиране от настройките). */
	public static function token( $user, $now = null, $ttl = null, $ua = null, $s = null ) {
		$s = $s ?: self::settings(); $now = $now ?: time(); $ttl = $ttl ?: $s['ttl'];
		$p = self::b64e( wp_json_encode( array( 'u' => (string) $user, 'iat' => (int) $now, 'exp' => (int) $now + (int) $ttl * HOUR_IN_SECONDS, 'g' => (int) $s['gen'], 'ua' => self::ua_hash( $ua ) ) ) );
		return $p . '.' . hash_hmac( 'sha256', $p, self::key( $s ) );
	}
	/** 1.27.2: изходът анулира токена и на сървъра (не само трие бисквитката) — option с хешовете на анулираните до изтичането им. */
	const OPT_REVOKED = 'ansa_promo_ins_portal_revoked';
	public static function revoke( $tok, $now = null ) {
		$now = $now ?: time(); $tok = (string) $tok; if ( '' === $tok ) { return; }
		$r = get_option( self::OPT_REVOKED, array() ); $r = is_array( $r ) ? $r : array();
		foreach ( $r as $h => $exp ) { if ( (int) $exp <= $now ) { unset( $r[ $h ] ); } }
		$d = json_decode( self::b64d( (string) strtok( $tok, '.' ) ), true ); $exp = is_array( $d ) ? (int) ( $d['exp'] ?? 0 ) : 0;
		$r[ hash( 'sha256', $tok ) ] = $exp > $now ? $exp : $now + DAY_IN_SECONDS;
		update_option( self::OPT_REVOKED, $r, false );
	}
	public static function revoked( $tok, $now = null ) { $now = $now ?: time(); $r = get_option( self::OPT_REVOKED, array() ); $h = hash( 'sha256', (string) $tok ); return is_array( $r ) && isset( $r[ $h ] ) && (int) $r[ $h ] > $now; }
	/** Проверява токена: подпис, срок, поколение, потребител, браузър, не е анулиран. Връща payload-а или null. */
	public static function verify( $tok, $now = null, $ua = null, $s = null ) {
		$s = $s ?: self::settings(); $now = $now ?: time(); $tok = (string) $tok;
		if ( '' === $tok || strlen( $tok ) > 600 || 1 !== substr_count( $tok, '.' ) ) { return null; }
		list( $p, $sig ) = explode( '.', $tok, 2 );
		if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $p ) || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) || ! hash_equals( hash_hmac( 'sha256', $p, self::key( $s ) ), $sig ) ) { return null; }
		$d = json_decode( self::b64d( $p ), true ); if ( ! is_array( $d ) ) { return null; }
		if ( (int) ( $d['exp'] ?? 0 ) <= $now || (int) ( $d['iat'] ?? 0 ) > $now + MINUTE_IN_SECONDS ) { return null; }
		if ( (int) ( $d['g'] ?? 0 ) !== (int) $s['gen'] || (string) ( $d['u'] ?? '' ) !== (string) $s['user'] ) { return null; }
		if ( ! hash_equals( self::ua_hash( $ua ), (string) ( $d['ua'] ?? '' ) ) ) { return null; }
		if ( self::revoked( $tok, $now ) ) { return null; } /* 1.27.2 */
		return $d;
	}
	/** Кой е вътре сега: [via => admin|session, name, exp] или null. */
	public static function current() {
		if ( self::$cur_done ) { return self::$cur; } self::$cur_done = true; self::$cur = null;
		if ( ! self::enabled() ) { return null; }
		if ( is_user_logged_in() && current_user_can( Plugin::CAP ) ) { $u = wp_get_current_user(); self::$cur = array( 'via' => 'admin', 'name' => $u->user_login, 'exp' => 0 ); return self::$cur; }
		$d = self::verify( (string) ( $_COOKIE[ self::COOKIE ] ?? '' ) );
		if ( $d ) { self::$cur = array( 'via' => 'session', 'name' => (string) $d['u'], 'exp' => (int) $d['exp'] ); }
		return self::$cur;
	}
	public static function authed() { return null !== self::current(); }
	/** CSRF токен за формите на влезлия (изход, живото опресняване): HMAC на сесийната бисквитка. */
	public static function csrf() { $c = self::current(); if ( ! $c ) { return ''; } return hash_hmac( 'sha256', 'csrf|' . ( 'admin' === $c['via'] ? 'admin:' . get_current_user_id() : (string) ( $_COOKIE[ self::COOKIE ] ?? '' ) ), self::key() ); }
	public static function csrf_ok( $v ) { $t = self::csrf(); return '' !== $t && is_string( $v ) && hash_equals( $t, $v ); }
	private static function set_cookie( $name, $value, $exp ) {
		if ( headers_sent() ) { return; }
		$secure = self::settings()['https_only'] || self::is_https();
		/* 1.27.1: CSRF бисквитката е без HttpOnly — скриптът на формата я чете (или я създава, ако страницата е дошла от кеш без Set-Cookie) и попълва скритото поле */
		@setcookie( $name, $value, array( 'expires' => $exp, 'path' => self::cookie_path(), 'secure' => $secure, 'httponly' => self::CSRF_COOKIE !== $name, 'samesite' => 'Strict' ) );
		if ( '' === $value ) { unset( $_COOKIE[ $name ] ); } else { $_COOKIE[ $name ] = $value; }
	}
	private static function login_cookie( $user ) { $s = self::settings(); self::set_cookie( self::COOKIE, self::token( $user, null, null, null, $s ), time() + $s['ttl'] * HOUR_IN_SECONDS ); self::set_cookie( self::CSRF_COOKIE, '', time() - DAY_IN_SECONDS ); self::$cur_done = false; }
	private static function logout_cookie() { self::revoke( (string) ( $_COOKIE[ self::COOKIE ] ?? '' ) ); self::set_cookie( self::COOKIE, '', time() - DAY_IN_SECONDS ); self::$cur_done = false; }

	/* ── лимитите срещу brute force ── */
	private static function fails() { $f = get_option( self::OPT_FAIL, array() ); $f = is_array( $f ) ? $f : array(); $f += array( 'ip' => array(), 'all' => array( 'n' => 0, 'first' => 0, 'until' => 0 ) ); $f['ip'] = is_array( $f['ip'] ) ? $f['ip'] : array(); return $f; }
	private static function ipk( $ip ) { return substr( md5( 'ip|' . (string) $ip ), 0, 16 ); }
	/** Ключовете на клиента: IP-то според Cloudflare (CF-Connecting-IP, ако го има) И REMOTE_ADDR — подправен CF хедър без Cloudflare не заобикаля блока по IP. */
	public static function client_ips() { $a = array_values( array_unique( array_filter( array( Leads::ip(), substr( preg_replace( '/[^0-9a-fA-F:.]/', '', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 0, 45 ) ) ) ) ); return $a ?: array( '' ); }
	/** Колко секунди още е блокиран този IP (или кой да е от списъка, или всички). 0 = свободно. */
	public static function locked( $ip, $now = null ) {
		$now = $now ?: time(); $f = self::fails(); $u = (int) ( $f['all']['until'] ?? 0 );
		foreach ( (array) $ip as $one ) { $u = max( $u, (int) ( $f['ip'][ self::ipk( $one ) ]['until'] ?? 0 ) ); }
		return $u > $now ? $u - $now : 0;
	}
	/** Записва неуспех; връща за колко секунди е блокиран сега (0 = още не). */
	public static function record_fail( $ip, $now = null, $global = true ) {
		$now = $now ?: time(); $f = self::fails();
		foreach ( (array) $ip as $one ) { $k = self::ipk( $one );
		$e = isset( $f['ip'][ $k ] ) && is_array( $f['ip'][ $k ] ) ? $f['ip'][ $k ] : array( 'n' => 0, 'first' => $now, 'until' => 0, 'last' => 0 );
		if ( $now - (int) $e['first'] > self::IP_RESET ) { $e = array( 'n' => 0, 'first' => $now, 'until' => 0, 'last' => 0 ); }
		$e['n'] = (int) $e['n'] + 1; $e['last'] = $now; $e['ip'] = substr( (string) $one, 0, 45 );
		if ( isset( self::IP_LOCKS[ $e['n'] ] ) ) { $e['until'] = max( (int) $e['until'], $now + self::IP_LOCKS[ $e['n'] ] ); } /* блокът се слага при 5-ия, 10-ия, 20-ия опит — не се удължава с всяко чукане между тях */
		$f['ip'][ $k ] = $e; }
		if ( $global ) { /* опит по време на блок брои само за IP-то (ескалира неговия блок), не и за общия — иначе един адрес би заключил всички */
			$a = $f['all']; if ( $now - (int) $a['first'] > self::WINDOW ) { $a = array( 'n' => 0, 'first' => $now, 'until' => (int) $a['until'] ); }
			$a['n'] = (int) $a['n'] + 1; if ( $a['n'] >= self::GLOBAL_MAX ) { $a['until'] = max( (int) $a['until'], $now + self::GLOBAL_LOCK ); $a['n'] = 0; $a['first'] = $now; }
			$f['all'] = $a;
		}
		/* хигиена: стари записи без блок */
		if ( count( $f['ip'] ) > 200 ) { foreach ( $f['ip'] as $kk => $ee ) { if ( (int) ( $ee['until'] ?? 0 ) < $now && $now - (int) ( $ee['last'] ?? 0 ) > self::IP_RESET ) { unset( $f['ip'][ $kk ] ); } } }
		update_option( self::OPT_FAIL, $f, false );
		return self::locked( $ip, $now );
	}
	public static function clear_ip( $ip ) { $f = self::fails(); foreach ( (array) $ip as $one ) { unset( $f['ip'][ self::ipk( $one ) ] ); } update_option( self::OPT_FAIL, $f, false ); }
	public static function unlock_all() { delete_option( self::OPT_FAIL ); self::log( 'unlock', self::settings()['user'], Leads::ip() ); }
	/** Блокираните в момента: [ [ip, until, n], … ] + общият блок. */
	public static function locks( $now = null ) {
		$now = $now ?: time(); $f = self::fails(); $out = array( 'ips' => array(), 'all_until' => (int) $f['all']['until'] > $now ? (int) $f['all']['until'] : 0, 'recent' => 0 );
		foreach ( $f['ip'] as $e ) { if ( (int) ( $e['until'] ?? 0 ) > $now ) { $out['ips'][] = array( 'ip' => (string) ( $e['ip'] ?? '?' ), 'until' => (int) $e['until'], 'n' => (int) $e['n'] ); } if ( $now - (int) ( $e['last'] ?? 0 ) <= self::IP_RESET ) { $out['recent'] += (int) $e['n']; } }
		return $out;
	}

	/* ── дневникът на опитите ── */
	public static function log( $res, $user, $ip, $now = null ) {
		$rows = get_option( self::OPT_LOG, array() ); $rows = is_array( $rows ) ? $rows : array();
		array_unshift( $rows, array( 'at' => $now ? gmdate( 'Y-m-d H:i:s', $now ) : current_time( 'mysql' ), 'ip' => substr( (string) $ip, 0, 45 ), 'user' => substr( preg_replace( '/[^\x20-\x7E]/', '?', (string) $user ), 0, 32 ), 'res' => (string) $res, 'ua' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 80 ) ) );
		update_option( self::OPT_LOG, array_slice( $rows, 0, self::LOG_MAX ), false );
	}
	public static function log_rows() { $r = get_option( self::OPT_LOG, array() ); return is_array( $r ) ? $r : array(); }
	public static function log_clear() { delete_option( self::OPT_LOG ); }
	const RES_LABELS = array( 'ok' => '✓ вход', 'bad' => '✕ грешни данни', 'locked' => '⛔ блокиран опит', 'csrf' => '⚠ невалидна форма (CSRF)', 'https' => '⚠ отказан по http', 'pwchange' => '🔑 нова парола', 'logout_all' => '🚪 изход от всички', 'unlock' => '🔓 отключени', 'logout' => '↩ изход' );

	/** Опит за вход. Връща [ok, code (ok|bad|locked|disabled|https), wait сек]. $ip (низ или списък) / $now — за тестовете; на живо: client_ips() (CF IP + REMOTE_ADDR). */
	public static function attempt( $user, $pw, $ip = null, $now = null, $check_https = true ) {
		$s = self::settings(); $now = $now ?: time(); $ips = null === $ip ? self::client_ips() : array_values( (array) $ip ); $ip = (string) $ips[0];
		if ( ! $s['enabled'] ) { return array( 'ok' => false, 'code' => 'disabled', 'wait' => 0 ); }
		if ( $check_https && ! self::https_ok() ) { self::log( 'https', $user, $ip, $now ); return array( 'ok' => false, 'code' => 'https', 'wait' => 0 ); }
		$wait = self::locked( $ips, $now ); if ( $wait > 0 ) { $wait = max( $wait, self::record_fail( $ips, $now, false ) ); self::log( 'locked', $user, $ip, $now ); return array( 'ok' => false, 'code' => 'locked', 'wait' => $wait ); } /* чукането по време на блок удължава блока на IP-то (5 → 10 → 20 опита) */
		$given = strtolower( trim( (string) $user ) ); $pw = (string) $pw;
		$u_ok = hash_equals( $s['user'], $given );
		$p_ok = password_verify( $pw, $s['hash'] ) && '' !== $pw; /* винаги се проверява — и при грешно име (същото време) */
		if ( $u_ok && $p_ok ) {
			self::clear_ip( $ips ); self::log( 'ok', $given, $ip, $now );
			if ( password_needs_rehash( $s['hash'], PASSWORD_BCRYPT, array( 'cost' => 12 ) ) ) { $s['hash'] = password_hash( $pw, PASSWORD_BCRYPT, array( 'cost' => 12 ) ); self::write( $s ); }
			return array( 'ok' => true, 'code' => 'ok', 'wait' => 0 );
		}
		$wait = self::record_fail( $ips, $now ); self::log( 'bad', $given, $ip, $now );
		return array( 'ok' => false, 'code' => $wait > 0 ? 'locked' : 'bad', 'wait' => $wait );
	}

	/* ── Doctor ── */
	public static function status() {
		$s = self::settings(); if ( ! $s['enabled'] ) { return array( 'изключен — екипът влиза само през wp-admin', 'info' ); }
		$l = self::locks(); $log = self::log_rows(); $last = ''; foreach ( $log as $r ) { if ( 'ok' === $r['res'] ) { $last = $r['at'] . ' от ' . $r['ip']; break; } }
		$txt = self::url() . ' · потребител „' . $s['user'] . '“ · ' . ( $s['changed_at'] ? 'паролата е сменена ' . wp_date( 'd.m.Y', $s['changed_at'] ) : 'НАЧАЛНАТА парола — смени я' ) . ' · сесия ' . $s['ttl'] . ' ч · ' . ( $s['https_only'] ? 'само HTTPS' : 'и по HTTP (⚠)' )
			. ( $l['ips'] ? ' · блокирани IP: ' . count( $l['ips'] ) : '' ) . ( $l['all_until'] ? ' · ОБЩ БЛОК до ' . wp_date( 'H:i', $l['all_until'] ) : '' ) . ( $last ? ' · последен вход ' . $last : ' · още никой не е влизал' );
		$fp = self::cache_bypass_state(); if ( null !== $fp ) { $txt .= $fp ? ' · FlyingPress: изключен от кеша' : ' · ⚠ FlyingPress НЕ го изключва от кеша — отвори 🧰 → 📈 Insights URL → 💾 Запази (вписва се сам) или добави „' . self::cache_bypass_pattern() . '“ в Exclude pages'; }
		return array( $txt, ! $s['changed_at'] || ! $s['https_only'] || false === $fp ? 'warn' : 'ok' );
	}

	/* ── маршрут ── */
	public static function route( $wp ) {
		if ( ! self::enabled() ) { return; }
		$req = trim( (string) ( $wp->request ?? '' ), '/' );
		if ( '' === $req ) { $uri = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); $home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ); if ( $home && 0 === strpos( $uri, $home ) ) { $uri = substr( $uri, strlen( $home ) ); } $req = trim( (string) $uri, '/' ); }
		if ( self::matches( $req ) ) { $wp->query_vars = array( self::QV => '1' ); }
	}
	/** Пътят на текущата заявка (REQUEST_URI без домейна/поддиректорията на WP) съвпада ли с портала — без WP (plugins_loaded). */
	public static function request_matches( $uri = null ) {
		if ( ! self::enabled() ) { return false; }
		$uri = (string) wp_parse_url( null === $uri ? (string) ( $_SERVER['REQUEST_URI'] ?? '' ) : (string) $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ); if ( $home && '/' !== $home && 0 === strpos( $uri, $home ) ) { $uri = substr( $uri, strlen( $home ) ); }
		return self::matches( $uri );
	}
	/** 1.27.1: чисти URL-а на портала от кеша на страниците (FlyingPress + hook за другите) — при нова версия, при запис на настройките, от Probe. */
	public static function purge() {
		$url = self::url_for( self::settings()['slug'] ); try { self::ensure_cache_bypass(); } catch ( \Throwable $e ) {} /* 1.27.2 */
		if ( class_exists( '\\FlyingPress\\Purge' ) ) { try { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( $url ); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_urls' ) ) ) { \FlyingPress\Purge::purge_urls( array( $url ) ); } } catch ( \Throwable $e ) {} }
		do_action( 'ansa_promo_purge_url', $url, 0 );
		return $url;
	}
	/** 1.27.2: FlyingPress не гледа DONOTCACHEPAGE — кешира по своя списък. Порталът се вписва сам в неговите „Exclude pages“ (cache_bypass_urls)
	 *  и „Bypass cookies“ (cache_bypass_cookies: сесийната бисквитка), през FlyingPress\Config::update_config, ако го има, иначе в опцията. Връща
	 *  null = няма FlyingPress, true = вписан (или вече беше), false = не успя. */
	const FP_OPT = 'flying_press_config';
	public static function cache_bypass_state() {
		$cfg = get_option( self::FP_OPT, null ); if ( ! is_array( $cfg ) ) { return null; }
		$u = isset( $cfg['cache_bypass_urls'] ) && is_array( $cfg['cache_bypass_urls'] ) ? $cfg['cache_bypass_urls'] : array(); $c = isset( $cfg['cache_bypass_cookies'] ) && is_array( $cfg['cache_bypass_cookies'] ) ? $cfg['cache_bypass_cookies'] : array();
		return in_array( self::cache_bypass_pattern(), $u, true ) && in_array( self::COOKIE, $c, true );
	}
	public static function cache_bypass_pattern( $slug = null ) { return '/' . trim( (string) ( null === $slug ? self::path() : $slug ), '/' ) . '*'; }
	public static function ensure_cache_bypass() {
		$cfg = get_option( self::FP_OPT, null ); if ( ! is_array( $cfg ) ) { return null; }
		$changed = false;
		foreach ( array( 'cache_bypass_urls' => self::cache_bypass_pattern(), 'cache_bypass_cookies' => self::COOKIE ) as $k => $v ) {
			$list = isset( $cfg[ $k ] ) && is_array( $cfg[ $k ] ) ? array_values( $cfg[ $k ] ) : array();
			if ( ! in_array( $v, $list, true ) ) { $list[] = $v; $cfg[ $k ] = $list; $changed = true; }
		}
		if ( ! $changed ) { return true; }
		try { if ( class_exists( '\\FlyingPress\\Config' ) && is_callable( array( '\\FlyingPress\\Config', 'update_config' ) ) ) { \FlyingPress\Config::update_config( $cfg ); } else { update_option( self::FP_OPT, $cfg ); } }
		catch ( \Throwable $e ) { update_option( self::FP_OPT, $cfg ); }
		return true === self::cache_bypass_state();
	}
	/** Съвпада ли пътят (без наклонени черти в краищата) с портала. */
	public static function matches( $req, $slug = null ) { $slug = null === $slug ? self::path() : $slug; return strtolower( trim( (string) $req, '/' ) ) === strtolower( trim( (string) $slug, '/' ) ); }
	public static function is_route() { global $wp; return ! empty( $wp->query_vars[ self::QV ] ); }
	public static function no_404( $pre, $q ) { return self::is_route() ? true : $pre; }

	public static function headers() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' ); header( 'X-Frame-Options: DENY' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Referrer-Policy: same-origin' );
		header( "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'" );
		header( 'Cross-Origin-Opener-Policy: same-origin' ); header( 'Vary: Cookie' );
	}
	private static function same_origin() {
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$o = (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' ); if ( '' !== $o && 'null' !== $o && strtolower( (string) wp_parse_url( $o, PHP_URL_HOST ) ) !== $home ) { return false; }
		$sf = strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) ); if ( '' !== $sf && ! in_array( $sf, array( 'same-origin', 'none' ), true ) ) { return false; }
		if ( '' === $o ) { $r = (string) ( $_SERVER['HTTP_REFERER'] ?? '' ); if ( '' !== $r && strtolower( (string) wp_parse_url( $r, PHP_URL_HOST ) ) !== $home ) { return false; } }
		return true;
	}
	private static function redirect_self( $args = array() ) { wp_safe_redirect( self::url( $args ), 303 ); exit; }

	public static function render_route() {
		if ( ! self::is_route() ) { return; }
		self::headers();
		$post = 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		/* изход */
		if ( $post && isset( $_POST['ap_logout'] ) ) {
			if ( self::authed() && self::csrf_ok( (string) ( $_POST['csrf'] ?? '' ) ) ) { self::log( 'logout', self::current()['name'], Leads::ip() ); self::logout_cookie(); }
			self::redirect_self();
		}
		/* вход */
		if ( $post && isset( $_POST['ap_login'] ) ) {
			if ( self::authed() ) { self::redirect_self(); }
			$cc = (string) ( $_COOKIE[ self::CSRF_COOKIE ] ?? '' ); $cf = (string) ( $_POST['csrf'] ?? '' );
			if ( ! self::same_origin() || '' === $cc || '' === $cf || ! preg_match( '/^[a-f0-9]{32}$/', $cc ) || ! hash_equals( $cc, $cf ) ) { self::log( 'csrf', (string) ( $_POST['user'] ?? '' ), Leads::ip() ); self::login_page( 'csrf' ); }
			$r = self::attempt( (string) wp_unslash( $_POST['user'] ?? '' ), (string) wp_unslash( $_POST['pw'] ?? '' ) );
			if ( $r['ok'] ) { self::login_cookie( self::settings()['user'] ); $v = sanitize_key( (string) ( $_POST['view'] ?? '' ) ); self::redirect_self( array( 'view' => isset( Insights::views()[ $v ] ) ? $v : 'overview' ) ); }
			self::login_page( $r['code'], (int) $r['wait'] );
		}
		if ( ! self::authed() ) { self::login_page( '' ); }
		/* вътре: CSV · JSON фийд · екранът */
		Insights::$portal = true;
		/* 1.27.1: дашбордът никога не се сервира на голия адрес — винаги с ?view=… (адрес с query string не се кешира от FlyingPress/CDN дори ако DONOTCACHEPAGE бъде подминат); голият адрес носи само формата за вход */
		if ( ! isset( $_GET['csv'] ) && ! isset( $_GET['action'] ) && ! isset( $_GET['view'] ) ) { self::redirect_self( array( 'view' => 'overview' ) ); }
		if ( isset( $_GET['csv'] ) ) { self::csv( (string) $_GET['csv'] ); }
		if ( isset( $_GET['action'] ) && 'ansa_promo_insights_feed' === $_GET['action'] ) {
			if ( ! self::csrf_ok( (string) ( $_GET['nonce'] ?? '' ) ) ) { wp_send_json_error( array( 'code' => 'auth' ), 403 ); }
			wp_send_json_success( Insights::feed_payload( (int) ( $_GET['days'] ?? 1 ), (int) ( $_GET['limit'] ?? 40 ) ) );
		}
		status_header( 200 ); header( 'Content-Type: text/html; charset=utf-8' );
		echo self::page_html(); exit;
	}
	private static function csv( $which ) {
		nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' );
		if ( 'stats' === $which ) { header( 'Content-Disposition: attachment; filename="ansa-promo-insights-' . gmdate( 'Ymd-Hi' ) . '.csv"' ); echo Insights::csv( (int) ( $_GET['days'] ?? 30 ) ); exit; }
		header( 'Content-Disposition: attachment; filename="ansa-promo-sessions-' . gmdate( 'Ymd-Hi' ) . '.csv"' );
		$q = ! empty( $_GET['from'] ) || ! empty( $_GET['to'] ) ? array_map( 'sanitize_text_field', array_intersect_key( (array) wp_unslash( $_GET ), array_flip( array( 'from', 'to', 'step', 'mode', 'box', 'ord', 'dev' ) ) ) ) : null;
		echo Insights::csv_sessions( (int) ( $_GET['days'] ?? 30 ), $q ); exit;
	}

	/* ── HTML ── */
	private static function head( $title ) {
		$v = rawurlencode( ANSA_PROMO_VER );
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="same-origin"><title>' . esc_html( $title ) . '</title>'
			. '<link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/fonts/nunito.css?ver=' . $v ) . '"><link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/admin-app.css?ver=' . $v ) . '"><link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/insights-portal.css?ver=' . $v ) . '"></head>';
	}
	/** Формата за вход (и съобщението). Спира заявката. */
	private static function login_page( $code, $wait = 0 ) {
		$csrf = (string) ( $_COOKIE[ self::CSRF_COOKIE ] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $csrf ) ) { $csrf = bin2hex( random_bytes( 16 ) ); self::set_cookie( self::CSRF_COOKIE, $csrf, time() + HOUR_IN_SECONDS ); }
		$https = self::https_ok(); $lock = self::locked( Leads::ip() );
		$msgs = array( 'bad' => 'Грешно потребителско име или парола.', 'locked' => 'Прекалено много опити — входът е спрян временно.', 'csrf' => 'Формата е изтекла. Опитай пак.', 'https' => 'Входът работи само по HTTPS.', 'disabled' => 'Порталът е изключен.' );
		$err = $msgs[ $code ] ?? ''; $wait = max( (int) $wait, $lock );
		if ( $wait > 0 && 'locked' !== $code ) { $err = $msgs['locked']; }
		if ( ! $https ) { $err = $msgs['https']; }
		$mins = $wait > 0 ? max( 1, (int) ceil( $wait / 60 ) ) : 0;
		status_header( 'locked' === $code || $wait > 0 ? 429 : ( '' !== $code ? 401 : 200 ) ); header( 'Content-Type: text/html; charset=utf-8' );
		echo self::head( 'Insights · вход' ) . '<body class="ap-portal-login"><main class="ap-login"><div class="ap-login__card">'
			. '<div class="ap-brand"><span class="dot"></span>ansa™ Промо<small>Insights · екип реклама</small></div>'
			. '<h1>Вход</h1>'
			. ( '' !== $err ? '<div class="ap-login__err" role="alert">' . esc_html( $err ) . ( $mins ? ' Опитай след ' . $mins . ' мин.' : '' ) . '</div>' : '' )
			. '<form method="post" action="' . esc_url( self::url() ) . '" autocomplete="on"' . ( $https && ! $wait ? '' : ' class="is-off"' ) . '>'
			. '<input type="hidden" name="ap_login" value="1"><input type="hidden" name="csrf" value="' . esc_attr( $csrf ) . '">' . ( isset( $_GET['view'] ) ? '<input type="hidden" name="view" value="' . esc_attr( sanitize_key( (string) $_GET['view'] ) ) . '">' : '' )
			. '<label>Потребител<input type="text" name="user" autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="64"' . ( $https && ! $wait ? '' : ' disabled' ) . '></label>'
			. '<label>Парола<input type="password" name="pw" autocomplete="current-password" required maxlength="256"' . ( $https && ! $wait ? '' : ' disabled' ) . '></label>'
			. '<button class="ap-btn ap-btn--primary" type="submit"' . ( $https && ! $wait ? '' : ' disabled' ) . '>Влез →</button>'
			. '</form><p class="ap-login__foot">Само за екипа на ansa™. Всеки опит за вход се записва.</p></div></main>'
			/* 1.27.1: ако страницата е дошла от кеш (стар токен, без Set-Cookie) — скриптът взема бисквитката или прави нова и попълва скритото поле; така и кеширана форма влиза */
			. '<script>(function(){var n=' . wp_json_encode( self::CSRF_COOKIE ) . ',p=' . wp_json_encode( self::cookie_path() ) . ',f=document.querySelector("input[name=csrf]"),m=document.cookie.match(new RegExp("(?:^|; )"+n+"=([a-f0-9]{32})")),v=m?m[1]:"";'
			. 'if(!v){try{var a=new Uint8Array(16);crypto.getRandomValues(a);v=Array.prototype.map.call(a,function(b){return("0"+b.toString(16)).slice(-2)}).join("")}catch(e){v=""}if(v)document.cookie=n+"="+v+"; path="+p+"; max-age=3600; secure; samesite=strict"}'
			. 'if(f&&v)f.value=v})();</script></body></html>';
		exit;
	}
	/** Екранът: рамката на приложението (меню + топ бар) с Insights::render() вътре. */
	private static function page_html() {
		$c = self::current(); $pub = Config::get_published(); $view = Insights::current_view(); $csrf = self::csrf();
		ob_start(); Insights::render( isset( $_GET['days'] ) ? (int) $_GET['days'] : 30 ); $inner = ob_get_clean();
		$h = self::head( '📈 Insights · ' . wp_strip_all_tags( (string) $pub['name'] ) ) . '<body class="ap-portal-body"><div id="ansa-promo-app" class="ap-tab-insights ap-portal">'
			. '<aside class="ap-nav"><div class="ap-brand"><span class="dot"></span>ansa™ Промо<small>Insights · екип реклама</small></div>'
			. '<div class="ap-state">играта: ' . ( Config::is_live( $pub ) ? '<span class="ok">ВКЛЮЧЕНА</span>' : ( ! empty( $pub['enabled'] ) ? '<span class="warn">изтекла</span>' : '<span class="off">изключена</span>' ) ) . ( $pub['deadline'] ? ' · до <b>' . esc_html( $pub['deadline'] ) . '</b>' : '' ) . '<br>';
		$pw = Config::week_for( $pub, time() ); if ( $pw ) { $h .= 'седмица: <b>' . (int) $pw['i'] . '/' . (int) $pw['total'] . '</b> · −' . esc_html( implode( ' / −', array_map( array( 'AnsaPromo\\Cart', 'fmt' ), array_values( $pw['pct'] ) ) ) ) . '%<br>'; }
		$h .= 'влязъл: <b>' . esc_html( $c['name'] ) . '</b>' . ( $c['exp'] ? ' · до ' . esc_html( wp_date( 'H:i', $c['exp'] ) ) : ' · WP админ' ) . '</div>'
			. '<div class="ap-nav__list"><div class="ap-nav__label">Insights</div>';
		foreach ( Insights::views() as $vk => $vl ) { $h .= '<a class="ap-nav__it' . ( $vk === $view ? ' on' : '' ) . '" href="' . esc_url( self::url( array( 'view' => $vk ) ) ) . '"><span class="ic">' . esc_html( mb_substr( $vl, 0, 2 ) ) . '</span>' . esc_html( trim( mb_substr( $vl, 2 ) ) ) . '</a>'; }
		$h .= '</div><form method="post" action="' . esc_url( self::url() ) . '" class="ap-nav__foot ap-portal__logout"><input type="hidden" name="ap_logout" value="1"><input type="hidden" name="csrf" value="' . esc_attr( $csrf ) . '"><button type="submit">↩ Изход</button></form></aside>'
			. '<div class="ap-page ap-page--insights"><div class="ap-top"><h1>📈 Insights<small>' . esc_html( (string) $pub['name'] ) . ' · фунията, кутиите, UTM-ите, поръчките — на живо</small></h1>'
			. '<div class="ap-top__actions"><form method="post" action="' . esc_url( self::url() ) . '"><input type="hidden" name="ap_logout" value="1"><input type="hidden" name="csrf" value="' . esc_attr( $csrf ) . '"><button class="ap-btn ap-btn--secondary ap-btn--sm" type="submit">↩ Изход</button></form></div></div>'
			. '<div class="ap-body"><div class="ap-body-inner ap-body-inner--wide">' . $inner . '</div></div></div></div></body></html>';
		return $h;
	}
}
