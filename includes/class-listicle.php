<?php
/**
 * ansa™ Промо — страницата „Комплектът за 1 €“ (1.24.20): {uri на промо страницата}/kozmetika.
 *
 * Самостоятелна, индексируема listicle страница за трафик от рекламата за козметичния комплект (текстът на човека, 21.10.2026):
 * promo.js в режим li (runtime.mode = 'li') рендира liHtml() инлайн; ВСЕКИ бутон води към промо страницата с ?utm_content=cosm —
 * кампанията „Козметика“ поема (без имейл-попъп, попъпът „Козметиката за 1 евро ще бъде твоя“ → Искам помощ / Не искам помощ →
 * Голямата кутия). utm_source/medium/campaign от адреса на тази страница се предават на промо страницата (Insights).
 * Текстовете са група li.* в регистъра (редактират се от канваса: екран „Страница „Комплектът за 1 €““). Същият модел като Info.
 *
 * @package AnsaPromo
 * @since 1.24.20
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Listicle {

	const QV = 'ansa_promo_li';

	public static function register() {
		add_action( 'parse_request', array( __CLASS__, 'route' ) );
		add_filter( 'pre_handle_404', array( __CLASS__, 'no_404' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'render_route' ), 0 );
		add_action( 'ansa_promo_published', array( __CLASS__, 'purge' ) );
	}

	public static function cfg( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return $cfg['li']; }
	public static function enabled( $cfg = null ) { return ! empty( self::cfg( $cfg )['enabled'] ); }
	public static function path( $cfg = null ) {
		$cfg = $cfg ?: Config::get_published();
		$base = ! empty( $cfg['page_id'] ) ? (string) get_page_uri( (int) $cfg['page_id'] ) : 'sezonut-na-ansa';
		return trim( trim( $base, '/' ) . '/' . $cfg['li']['slug'], '/' );
	}
	public static function url( $cfg = null ) { return apply_filters( 'ansa_promo_li_url', home_url( '/' . self::path( $cfg ) . '/' ), $cfg ); }
	public static function purge( $cfg = null ) {
		$url = self::url( is_array( $cfg ) ? $cfg : null );
		if ( class_exists( '\\FlyingPress\\Purge' ) ) { try { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( $url ); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_urls' ) ) ) { \FlyingPress\Purge::purge_urls( array( $url ) ); } } catch ( \Throwable $e ) {} }
		do_action( 'ansa_promo_purge_url', $url, 0 );
	}

	public static function route( $wp ) {
		if ( ! self::enabled() ) { return; }
		$req = trim( (string) ( $wp->request ?? '' ), '/' );
		if ( '' === $req ) { $uri = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); $home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ); if ( $home && 0 === strpos( $uri, $home ) ) { $uri = substr( $uri, strlen( $home ) ); } $req = trim( $uri, '/' ); }
		if ( $req !== self::path() && ! isset( $_GET['ansa_li'] ) ) { return; }
		$wp->query_vars = array( self::QV => 1 );
	}
	public static function is_route() { global $wp; return ! empty( $wp->query_vars[ self::QV ] ); }
	public static function no_404( $pre, $q ) { return self::is_route() ? true : $pre; }
	public static function render_route() {
		if ( ! self::is_route() ) { return; }
		$draft = Frontend::is_draft_request(); Frontend::preview_week(); $cfg = Config::at( $draft ? Config::get_draft() : Config::get_published() );
		status_header( 200 ); header( 'Content-Type: text/html; charset=utf-8' );
		if ( Frontend::gate_shown() || ( ! $draft && ! Config::is_live( $cfg ) ) ) { header( 'X-Robots-Tag: noindex' ); nocache_headers(); echo self::soon_html( $cfg ); exit; }
		if ( $draft ) { header( 'X-Robots-Tag: noindex' ); nocache_headers(); }
		echo self::page_html( $cfg, $draft ); exit;
	}

	private static function vars( $cfg ) { $box = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][0]; $v = Cart::vars( $cfg, $box ); $v['draw'] = Tombola::draw_label( $cfg ); $v['tombola_url'] = Tombola::url( $cfg ); return $v; }
	public static function meta( $cfg ) {
		$v = self::vars( $cfg ); $c = Copy::effective( $cfg['copy'] );
		return array( 'title' => wp_strip_all_tags( Copy::render( $c['li.title'], $v ) ), 'desc' => wp_strip_all_tags( Copy::render( $c['li.desc'], $v ) ) );
	}
	public static function page_html( $cfg, $draft = false ) {
		$rt = Frontend::runtime( $cfg, $draft ); unset( $rt['registry'] );
		$rt['editor'] = false; $rt['mode'] = 'li'; $rt['promo_url'] = Cart::promo_url( $cfg ); $rt['info_url'] = Info::enabled( $cfg ) ? Info::url( $cfg ) : ''; $rt['refresh'] = ! $draft;
		$m = self::meta( $cfg ); $url = self::url( $cfg ); $ver = ANSA_PROMO_VER;
		$og = Frontend::page_image( $cfg, 'li', 'hero', 'cosm' ); /* 1.24.21: своята снимка на hero секцията, иначе комплектът от имейл-попъпа */
		$editor = false; ob_start(); include ANSA_PROMO_PATH . 'templates/page.php'; $body = ob_get_clean();
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. ( $draft ? '<meta name="robots" content="noindex,nofollow">' : '' )
			. '<title>' . esc_html( $m['title'] ) . '</title><meta name="description" content="' . esc_attr( $m['desc'] ) . '"><link rel="canonical" href="' . esc_url( $url ) . '">'
			. '<meta property="og:type" content="website"><meta property="og:title" content="' . esc_attr( $m['title'] ) . '"><meta property="og:description" content="' . esc_attr( $m['desc'] ) . '"><meta property="og:url" content="' . esc_url( $url ) . '">' . ( $og ? '<meta property="og:image" content="' . esc_url( $og ) . '">' : '' )
			. '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap">'
			. '<link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/promo.css?ver=' . $ver ) . '"><link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/promo-extra.css?ver=' . $ver ) . '">'
			. '<style>html,body{margin:0;padding:0;background:#f6e4d1}</style></head>'
			. '<body class="ansa-promo-page ansa-promo-info-page ansa-promo-li-page">' . $body
			. '<script>window.AnsaPromoRuntime=' . wp_json_encode( $rt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script><script src="' . esc_url( ANSA_PROMO_URL . 'assets/promo.js?ver=' . $ver ) . '"></script></body></html>';
	}
	private static function soon_html( $cfg ) {
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $cfg['name'] ) . '</title>'
			. '<style>html,body{margin:0;background:#f6e4d1;color:#1a1a2e;font-family:Nunito,-apple-system,BlinkMacSystemFont,sans-serif}body{min-height:100dvh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}p{font-size:20px;font-weight:800;line-height:1.4}a{color:#c2410c}</style></head>'
			. '<body><p>' . esc_html( $cfg['name'] ) . ' — скоро.<br><a href="' . esc_url( Cart::promo_url( $cfg ) ) . '" style="font-size:15px">към страницата ›</a></p></body></html>';
	}
}
