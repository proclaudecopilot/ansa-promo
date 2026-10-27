<?php
/**
 * ansa™ Промо — страницата „Как работи“ (v1.8.0): {uri на промо страницата}/info.
 *
 * Самостоятелна, индексируема страница със СЪЩОТО съдържание като попъпа „Как работи играта?“: promo.js в режим info
 * (runtime.mode = 'info') рендира howHtml() инлайн вместо в попъп, а бутоните водят към промо страницата — „Избери … кутия“
 * с ?box=s|m|l (промо страницата избира кутията), „Добави в кутия“ с ?{utm_param}=продукт (основният продукт). Същите текстове
 * (група how.* + info.title/info.desc за <title>/description/og:), същият CSS, същите данни от двигателя.
 * Разпознава се в parse_request по пътя (без rewrite rules/flush), както томболата; резерва ?ansa_info=1. Кешируема (цените се
 * сверяват през ajax runtime); черновата — с ?ansa_promo=draft. При парола/изключена игра показва „скоро“ с noindex.
 *
 * @package AnsaPromo
 * @since 1.8.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Info {

	const QV = 'ansa_promo_info';

	public static function register() {
		add_action( 'parse_request', array( __CLASS__, 'route' ) );
		add_filter( 'pre_handle_404', array( __CLASS__, 'no_404' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'render_route' ), 0 );
		add_action( 'ansa_promo_published', array( __CLASS__, 'purge' ) );
	}

	/* ── конфиг / адреси ── */
	public static function cfg( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return $cfg['info']; }
	public static function enabled( $cfg = null ) { return ! empty( self::cfg( $cfg )['enabled'] ); }
	/** Пътят (без домейн и наклонени черти): {uri на промо страницата}/{slug}; без страница → sezonut-na-ansa/{slug}. */
	public static function path( $cfg = null ) {
		$cfg = $cfg ?: Config::get_published();
		$base = ! empty( $cfg['page_id'] ) ? (string) get_page_uri( (int) $cfg['page_id'] ) : 'sezonut-na-ansa';
		return trim( trim( $base, '/' ) . '/' . $cfg['info']['slug'], '/' );
	}
	public static function url( $cfg = null ) { return apply_filters( 'ansa_promo_info_url', home_url( '/' . self::path( $cfg ) . '/' ), $cfg ); }
	public static function purge( $cfg = null ) {
		$url = self::url( is_array( $cfg ) ? $cfg : null );
		if ( class_exists( '\\FlyingPress\\Purge' ) ) { try { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( $url ); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_urls' ) ) ) { \FlyingPress\Purge::purge_urls( array( $url ) ); } } catch ( \Throwable $e ) {} }
		do_action( 'ansa_promo_purge_url', $url, 0 );
	}

	/* ── маршрут ── */
	public static function route( $wp ) {
		if ( ! self::enabled() ) { return; }
		$req = trim( (string) ( $wp->request ?? '' ), '/' );
		if ( '' === $req ) { $uri = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); $home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ); if ( $home && 0 === strpos( $uri, $home ) ) { $uri = substr( $uri, strlen( $home ) ); } $req = trim( (string) $uri, '/' ); }
		if ( $req !== self::path() && ! isset( $_GET['ansa_info'] ) ) { return; }
		$wp->query_vars = array( self::QV => 1 );
	}
	public static function is_route() { global $wp; return ! empty( $wp->query_vars[ self::QV ] ); }
	public static function no_404( $pre, $q ) { return self::is_route() ? true : $pre; }
	public static function render_route() {
		if ( ! self::is_route() ) { return; }
		$draft = Frontend::is_draft_request(); Frontend::preview_week(); $cfg = Config::at( $draft ? Config::get_draft() : Config::get_published() ); /* v1.19.0: седмицата */
		status_header( 200 ); header( 'Content-Type: text/html; charset=utf-8' );
		if ( Frontend::gate_shown() || ( ! $draft && ! Config::is_live( $cfg ) ) ) { header( 'X-Robots-Tag: noindex' ); nocache_headers(); echo self::soon_html( $cfg ); exit; }
		if ( $draft ) { header( 'X-Robots-Tag: noindex' ); nocache_headers(); }
		echo self::page_html( $cfg, $draft ); exit;
	}

	/* ── HTML ── */
	private static function vars( $cfg ) { $box = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][0]; $v = Cart::vars( $cfg, $box ); $v['draw'] = Tombola::draw_label( $cfg ); $v['tombola_url'] = Tombola::url( $cfg ); return $v; }
	public static function meta( $cfg ) {
		$v = self::vars( $cfg ); $c = Copy::effective( $cfg['copy'] );
		return array( 'title' => wp_strip_all_tags( Copy::render( $c['info.title'], $v ) ), 'desc' => wp_strip_all_tags( Copy::render( $c['info.desc'], $v ) ) );
	}
	public static function page_html( $cfg, $draft = false ) {
		$rt = Frontend::runtime( $cfg, $draft ); unset( $rt['registry'] );
		$rt['editor'] = false; $rt['mode'] = 'info'; $rt['promo_url'] = Cart::promo_url( $cfg ); $rt['refresh'] = ! $draft;
		$m = self::meta( $cfg ); $url = self::url( $cfg ); $ver = ANSA_PROMO_VER;
		$og = Frontend::page_image( $cfg, 'how', 'hero', 'yacht' ); /* 1.24.21: своята снимка на hero секцията, иначе яхтата от имейл-попъпа */
		$editor = false; ob_start(); include ANSA_PROMO_PATH . 'templates/page.php'; $body = ob_get_clean();
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. ( $draft ? '<meta name="robots" content="noindex,nofollow">' : '' )
			. '<title>' . esc_html( $m['title'] ) . '</title><meta name="description" content="' . esc_attr( $m['desc'] ) . '"><link rel="canonical" href="' . esc_url( $url ) . '">'
			. '<meta property="og:type" content="website"><meta property="og:title" content="' . esc_attr( $m['title'] ) . '"><meta property="og:description" content="' . esc_attr( $m['desc'] ) . '"><meta property="og:url" content="' . esc_url( $url ) . '">' . ( $og ? '<meta property="og:image" content="' . esc_url( $og ) . '">' : '' )
			. '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap">'
			. '<link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/promo.css?ver=' . $ver ) . '"><link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/promo-extra.css?ver=' . $ver ) . '">'
			. '<style>html,body{margin:0;padding:0;background:#f6e4d1}</style>' . apply_filters( 'ansa_promo_standalone_head', '', 'info', $cfg ) . '</head>'
			. '<body class="ansa-promo-page ansa-promo-info-page">' . $body
			. '<script>window.AnsaPromoRuntime=' . wp_json_encode( $rt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script><script src="' . esc_url( ANSA_PROMO_URL . 'assets/promo.js?ver=' . $ver ) . '"></script>' . apply_filters( 'ansa_promo_standalone_footer', '', 'info', $cfg ) . '</body></html>';
	}
	/** Паролата е включена или играта не е на живо → „скоро“, без съдържание и без индексиране. */
	private static function soon_html( $cfg ) {
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $cfg['name'] ) . '</title>'
			. '<style>html,body{margin:0;background:#f6e4d1;color:#1a1a2e;font-family:Nunito,-apple-system,BlinkMacSystemFont,sans-serif}body{min-height:100dvh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}p{font:700 22px/1.3 inherit;margin:0}a{color:#c2410c}</style></head>'
			. '<body><p>' . esc_html( $cfg['name'] ) . ' — скоро.<br><a href="' . esc_url( Cart::promo_url( $cfg ) ) . '" style="font-size:15px">към страницата ›</a></p></body></html>';
	}
}
