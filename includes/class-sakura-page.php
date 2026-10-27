<?php
/**
 * ansa™ Промо — страницата „Sakura ×5“ (1.31.0): {сайт}/kozmetichen-set-promo (на корена, не под промо страницата).
 *
 * Адверториалът на човека (26.10.2026) за трафик от рекламата за козметичния комплект: „Знаем, че си дошла за козметиката“ →
 * „Ето какво вероятно пропускаш“ → дерматолозите → „Решението не е още един крем“ → ревюта → офертата (пет Sakura {{sx_pay}} € вместо
 * {{sx_full}} € + комплектът за {{cosm_pay_l}} €) → „Защо пет, а не една“ → три стъпки → „Какво получаваш“ → яхтата → въпроси → финал.
 * Същият модел като Listicle (/kozmetika): promo.js в режим sx (runtime.mode = 'sx') рендира sxHtml() инлайн; ВСЕКИ бутон води към
 * промо страницата с ?utm_content=sakura&box=l (Sakura е „твоят продукт“, Голямата кутия се избира сама); utm_source/medium/campaign/
 * term/fbclid от адреса се предават нататък. Текстовете са група sx.* в регистъра (редактират се от канваса: екран „Страница „Sakura ×5““;
 * празно = скрит текст, както how/li). Секциите (ред · скрити · снимки) са страница 'sx' в 📄 Страници (Config::page_sections).
 * Цените идват от двигателя: {{sx_price}} (1 опаковка Sakura), {{sx_full}} (×packs на Голямата), {{sx_pay}} (с −pct_l), {{sx_total}}
 * (+ cosm_pay), {{sx_value}} (full + cosm_value), {{sx_save}}, {{sx_days}} (packs × sx.days), {{sx_day}} (на ден), {{sx_packs}}.
 * Снимките по подразбиране са в assets/img/sx/ (hero, sakura-box, tonic, day-cream, night-serum); своя снимка на секция — от 📄 Страници.
 *
 * @package AnsaPromo
 * @since 1.31.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Sakura_Page {

	const QV = 'ansa_promo_sx';
	/** Ключът на продукта в двигателя, по който се смятат {{sx_*}} (и utm_content на бутоните). */
	const PRODUCT = 'sakura';

	public static function register() {
		add_action( 'parse_request', array( __CLASS__, 'route' ) );
		add_filter( 'pre_handle_404', array( __CLASS__, 'no_404' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'render_route' ), 0 );
		add_action( 'ansa_promo_published', array( __CLASS__, 'purge' ) );
	}

	public static function cfg( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return $cfg['sx']; }
	public static function enabled( $cfg = null ) { return ! empty( self::cfg( $cfg )['enabled'] ); }
	/** Пътят (без домейн и наклонени черти) — на корена на сайта: {slug}. */
	public static function path( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return trim( (string) $cfg['sx']['slug'], '/' ) ?: 'kozmetichen-set-promo'; }
	public static function url( $cfg = null ) { return apply_filters( 'ansa_promo_sx_url', home_url( '/' . self::path( $cfg ) . '/' ), $cfg ); }
	public static function purge( $cfg = null ) {
		$url = self::url( is_array( $cfg ) ? $cfg : null );
		if ( class_exists( '\\FlyingPress\\Purge' ) ) { try { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( $url ); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_urls' ) ) ) { \FlyingPress\Purge::purge_urls( array( $url ) ); } } catch ( \Throwable $e ) {} }
		do_action( 'ansa_promo_purge_url', $url, 0 );
	}

	/** Снимките по подразбиране (assets/img/sx/) като URL — секция → URL; своя снимка от 📄 Страници печели (promo.js: sxPic). */
	public static function assets() {
		$b = ANSA_PROMO_URL . 'assets/img/sx/';
		return array( 'hero' => $b . 'hero.webp', 'box' => $b . 'sakura-box.webp', 'tonic' => $b . 'tonic.webp', 'cream' => $b . 'day-cream.webp', 'serum' => $b . 'night-serum.webp' );
	}
	/** Частта от runtime-а за promo.js (и на промо страницата — канвасът на редактора показва /kozmetichen-set-promo като попъп). */
	public static function runtime_part( $cfg ) {
		return array( 'days' => (int) ( $cfg['sx']['days'] ?? 30 ), 'product' => self::PRODUCT, 'assets' => self::assets(), 'url' => self::enabled( $cfg ) ? self::url( $cfg ) : '', 'product_url' => self::product_url( $cfg ) );
	}
	/** Продуктовата страница на Sakura в WooCommerce („Още ревюта“, „страницата на Sakura“); '' без WC/продукт → promo.js пада на /info. */
	public static function product_url( $cfg ) {
		if ( ! function_exists( 'wc_get_product' ) ) { return ''; }
		foreach ( (array) $cfg['products'] as $p ) {
			if ( ( $p['key'] ?? '' ) !== self::PRODUCT ) { continue; }
			$pid = '' !== (string) ( $p['sku'] ?? '' ) ? ( Catalog::find_by_sku( $p['sku'] ) ?: (int) ( $p['product_id'] ?? 0 ) ) : (int) ( $p['product_id'] ?? 0 );
			$pr = $pid ? wc_get_product( $pid ) : null; if ( ! $pr ) { return ''; }
			$id = $pr->is_type( 'variation' ) ? (int) $pr->get_parent_id() : (int) $pr->get_id(); return $id ? (string) get_permalink( $id ) : '';
		}
		return '';
	}

	/* ── маршрут ── */
	public static function route( $wp ) {
		if ( ! self::enabled() ) { return; }
		$req = trim( (string) ( $wp->request ?? '' ), '/' );
		if ( '' === $req ) { $uri = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); $home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ); if ( $home && 0 === strpos( $uri, $home ) ) { $uri = substr( $uri, strlen( $home ) ); } $req = trim( (string) $uri, '/' ); }
		if ( $req !== self::path() && ! isset( $_GET['ansa_sx'] ) ) { return; }
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

	/** {{sx_*}} от цената на Sakura в runtime-а (products[sakura].price) и Голямата кутия; без цена → празни (текстът ги показва като „{{sx_pay}}“ само в <title>). */
	public static function sx_vars( $cfg, $rt ) {
		$bl = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][ count( $cfg['boxes'] ) - 1 ]; $packs = (int) $bl['packs']; $days = (int) ( $cfg['sx']['days'] ?? 30 );
		$pr = is_array( $rt['products'] ?? null ) ? ( $rt['products'][ self::PRODUCT ] ?? null ) : ( ( is_object( $rt['products'] ?? null ) && isset( $rt['products']->{self::PRODUCT} ) ) ? (array) $rt['products']->{self::PRODUCT} : null );
		$price = $pr ? (float) ( $pr['price'] ?? 0 ) : 0; $pct = $pr && ! empty( $pr['pct'] ) && isset( ( (array) $pr['pct'] )['l'] ) ? (float) ( (array) $pr['pct'] )['l'] : (float) $bl['pct'];
		$full = round( $price * $packs, 2 ); $pay = round( $full * ( 1 - $pct / 100 ), 2 ); $cv = (float) $cfg['rewards']['cosm']['value']; $cp = null === $bl['cosm_pay'] ? 0 : (float) $bl['cosm_pay'];
		$f = array( __CLASS__, 'fmt' );
		return array( 'sx_price' => $f( $price ), 'sx_full' => $f( $full ), 'sx_pay' => $f( $pay ), 'sx_total' => $f( round( $pay + $cp, 2 ) ), 'sx_value' => $f( round( $full + $cv, 2 ) ), 'sx_save' => $f( round( $full + $cv - $pay - $cp, 2 ) ),
			'sx_days' => (string) ( $packs * $days ), 'sx_day' => $f( $packs * $days > 0 ? round( $pay / ( $packs * $days ), 2 ) : 0 ), 'sx_packs' => (string) $packs, 'sx_pack_days' => (string) $days, 'left' => '' );
	}
	/** 247 → „247“, 148.5 → „148,50“ (адверториалът пише цените с два знака). */
	public static function fmt( $n ) { $n = (float) $n; return str_replace( '.', ',', number_format( $n, abs( $n - round( $n ) ) < 0.005 ? 0 : 2, '.', '' ) ); }
	private static function vars( $cfg, $rt ) { $box = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][0]; $v = Cart::vars( $cfg, $box ); $v['draw'] = Tombola::draw_label( $cfg ); $v['tombola_url'] = Tombola::url( $cfg ); return array_merge( $v, self::sx_vars( $cfg, $rt ) ); }
	public static function meta( $cfg, $rt ) {
		$v = self::vars( $cfg, $rt ); $c = Copy::effective( $cfg['copy'] );
		return array( 'title' => wp_strip_all_tags( Copy::render( $c['sx.title'], $v ) ), 'desc' => wp_strip_all_tags( Copy::render( $c['sx.desc'], $v ) ) );
	}
	public static function page_html( $cfg, $draft = false ) {
		$rt = Frontend::runtime( $cfg, $draft ); unset( $rt['registry'] );
		$rt['editor'] = false; $rt['mode'] = 'sx'; $rt['promo_url'] = Cart::promo_url( $cfg ); $rt['info_url'] = Info::enabled( $cfg ) ? Info::url( $cfg ) : ''; $rt['refresh'] = ! $draft;
		$m = self::meta( $cfg, $rt ); $url = self::url( $cfg ); $ver = ANSA_PROMO_VER;
		$own = Frontend::pages_images( $cfg, 'sx' ); $og = ! empty( $own['hero'] ) ? $own['hero'] : self::assets()['hero'];
		$editor = false; ob_start(); include ANSA_PROMO_PATH . 'templates/page.php'; $body = ob_get_clean();
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. ( $draft ? '<meta name="robots" content="noindex,nofollow">' : '' )
			. '<title>' . esc_html( $m['title'] ) . '</title><meta name="description" content="' . esc_attr( $m['desc'] ) . '"><link rel="canonical" href="' . esc_url( $url ) . '">'
			. '<meta property="og:type" content="website"><meta property="og:title" content="' . esc_attr( $m['title'] ) . '"><meta property="og:description" content="' . esc_attr( $m['desc'] ) . '"><meta property="og:url" content="' . esc_url( $url ) . '">' . ( $og ? '<meta property="og:image" content="' . esc_url( $og ) . '">' : '' )
			. '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&display=swap">'
			. '<link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/promo.css?ver=' . $ver ) . '"><link rel="stylesheet" href="' . esc_url( ANSA_PROMO_URL . 'assets/promo-extra.css?ver=' . $ver ) . '">'
			. '<style>html,body{margin:0;padding:0;background:#f9f5ee}</style>' . apply_filters( 'ansa_promo_standalone_head', '', 'sx', $cfg ) . '</head>'
			. '<body class="ansa-promo-page ansa-promo-info-page ansa-promo-sx-page">' . $body
			. '<script>window.AnsaPromoRuntime=' . wp_json_encode( $rt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script><script src="' . esc_url( ANSA_PROMO_URL . 'assets/promo.js?ver=' . $ver ) . '"></script>' . apply_filters( 'ansa_promo_standalone_footer', '', 'sx', $cfg ) . '</body></html>';
	}
	private static function soon_html( $cfg ) {
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $cfg['name'] ) . '</title>'
			. '<style>html,body{margin:0;background:#f9f5ee;color:#1a1a2e;font-family:Nunito,-apple-system,BlinkMacSystemFont,sans-serif}body{min-height:100dvh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}p{font-size:20px;font-weight:800;line-height:1.4}a{color:#c2410c}</style></head>'
			. '<body><p>' . esc_html( $cfg['name'] ) . ' — скоро.<br><a href="' . esc_url( Cart::promo_url( $cfg ) ) . '" style="font-size:15px">към страницата ›</a></p></body></html>';
	}
}
