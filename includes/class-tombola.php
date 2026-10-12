<?php
/**
 * ansa™ Промо — томбола (1.6.0): страницата {промо}/tombola, проверка на билетите по телефон + имейл, препоръка на близък човек.
 *
 *  - Страница: {път на промо страницата}/tombola (напр. ansa.bg/sezonut-na-ansa/tombola) — самостоятелен HTML (без темата), кешируем;
 *    данните минават през admin-ajax. Разпознава се в parse_request по пътя (без rewrite/flush); резерва: shortcode [ansa_promo_tombola]
 *    и ?ansa_tombola=1. Клиентът въвежда телефон + имейл → ajax ansa_promo_tombola (rate limit 8/мин/IP, без nonce — страницата е кеширана).
 *  - Съвпадение: промо поръчки с този billing имейл, чийто телефон (нормализиран: цифри, +359/00359 → 0…) е същият. Поръчката трябва да е
 *    в „доставен“ статус (tombola.statuses, по подразбиране completed + delivered); иначе „пратката още пътува“. Имейл без съвпадащ телефон =
 *    „не намираме“ (нищо не се издава на половин съвпадение — brute force не разбира дали имейлът съществува).
 *  - Успех: билетите на поръчката се активират (Tickets::activate → hook ansa_promo_ticket_activated → Insights), показва се „Честито!“ с броя,
 *    разбивка (кутия · партньорски код · подарък от приятел · препоръки), датата на тегленето (tombola.draw), ваучерът за сета, ако има,
 *    и личният линк за препоръка {промо}?r=КОД (tombola.ref_param).
 *  - Препоръка: кодът е 8 знака (Tickets::ALPHABET) в tickets.ref_code, един на поръчка. Приятелят каца с ?r=КОД → JS (и на кеширана страница)
 *    → ajax ansa_promo_ref → WC session + бисквитка 30 дни. При създаване на поръчка (ansa_promo_order_created) кодът се замразява в
 *    _ansa_promo.tickets.ref_* с +tombola.ref_gift билет (Tickets::issue го добавя). Когда билетът на новата поръчка се издаде (платена) →
 *    ред в {prefix}ansa_promo_referrals (credited); анулиран билет → void; същият имейл втори път → dup; собственият имейл/телефон → self (нищо).
 *    Бонусът на препоръчалия (tickets.bonus) = min(credited, ref_max) × tickets × (ref_mult − 1) — първият приятел удвоява, всеки следващ
 *    добавя още толкова; преизчислява се при всяка промяна и се вижда при следваща проверка на страницата + в 🎲 Томбола и в CSV-то.
 *
 * @package AnsaPromo
 * @since 1.6.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Tombola {

	const SHORTCODE = 'ansa_promo_tombola';
	const QV        = 'ansa_promo_tombola';
	const SESSION   = 'ansa_promo_ref';
	const COOKIE    = 'ansa_promo_ref';
	const REF_TTL   = 30 * DAY_IN_SECONDS;
	const CODE_LEN  = 8;

	public static function register() {
		add_action( 'init', array( __CLASS__, 'add_shortcode' ), 20 );
		add_action( 'parse_request', array( __CLASS__, 'route' ) );
		add_filter( 'pre_handle_404', array( __CLASS__, 'no_404' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'render_route' ), 0 );
		add_action( 'wp_ajax_ansa_promo_tombola', array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'wp_ajax_nopriv_ansa_promo_tombola', array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'wp_ajax_ansa_promo_ref', array( __CLASS__, 'ajax_ref' ) );
		add_action( 'wp_ajax_nopriv_ansa_promo_ref', array( __CLASS__, 'ajax_ref' ) );
		add_action( 'wc_ajax_ansa_promo_ref', array( __CLASS__, 'ajax_ref' ) );
		add_action( 'wp_footer', array( __CLASS__, 'ref_js' ), 7 );
		add_action( 'ansa_promo_order_created', array( __CLASS__, 'attach_ref' ), 5, 2 );
		add_action( 'ansa_promo_ticket_issued', array( __CLASS__, 'on_issued' ), 10, 2 );
		add_action( 'ansa_promo_ticket_void', array( __CLASS__, 'on_void' ) );
		foreach ( array( 'processing', 'completed' ) as $st ) { add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'on_paid' ), 25 ); }
		add_filter( 'ansa_promo_checkout_rows', array( __CLASS__, 'checkout_row' ), 10, 3 );
		add_filter( 'ansa_promo_ticket_url', array( __CLASS__, 'ticket_url' ), 10, 3 );
		add_action( 'ansa_promo_published', array( __CLASS__, 'purge' ) );
	}

	/* ── конфиг / адреси ── */
	public static function cfg( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return $cfg['tombola']; }
	public static function enabled( $cfg = null ) { return ! empty( self::cfg( $cfg )['enabled'] ); }
	/** Пътят на страницата (без домейн и без наклонени черти): {uri на промо страницата}/{slug}; без страница → sezonut-na-ansa/{slug}. */
	public static function path( $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $t = $cfg['tombola'];
		$base = ! empty( $cfg['page_id'] ) ? (string) get_page_uri( (int) $cfg['page_id'] ) : 'sezonut-na-ansa';
		return trim( trim( $base, '/' ) . '/' . $t['slug'], '/' );
	}
	public static function url( $cfg = null ) { return apply_filters( 'ansa_promo_tombola_url', home_url( '/' . self::path( $cfg ) . '/' ), $cfg ); }
	/** Личният линк за препоръка: промо страницата + ?r=КОД (приятелят кацa там и поръчва). */
	public static function ref_link( $code, $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return add_query_arg( $cfg['tombola']['ref_param'], $code, Cart::promo_url( $cfg ) ); }
	public static function draw_label( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); $d = (string) $cfg['tombola']['draw']; $ts = $d ? strtotime( $d ) : 0; return $ts ? date_i18n( 'd.m.Y', $ts ) : $d; }
	public static function delivered( $order, $cfg = null ) { return in_array( $order->get_status(), (array) self::cfg( $cfg )['statuses'], true ); }
	public static function purge( $cfg = null ) {
		$url = self::url( $cfg ?: null );
		if ( class_exists( '\\FlyingPress\\Purge' ) ) { try { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( $url ); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_urls' ) ) ) { \FlyingPress\Purge::purge_urls( array( $url ) ); } } catch ( \Throwable $e ) {} }
		do_action( 'ansa_promo_purge_url', $url, 0 );
	}
	/** QR кодът в пратката води към страницата на томболата, когда няма отделен портал. */
	public static function ticket_url( $url, $code, $cfg ) { return ( empty( $cfg['tickets']['portal_url'] ) && self::enabled( $cfg ) ) ? self::url( $cfg ) : $url; }

	/* ── маршрут ── */
	public static function route( $wp ) {
		if ( ! self::enabled() ) { return; }
		$req = trim( (string) ( $wp->request ?? '' ), '/' );
		if ( '' === $req ) { $uri = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); $home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ); if ( $home && 0 === strpos( $uri, $home ) ) { $uri = substr( $uri, strlen( $home ) ); } $req = trim( (string) $uri, '/' ); }
		if ( $req !== self::path() && ! isset( $_GET['ansa_tombola'] ) ) { return; }
		$wp->query_vars = array( self::QV => 1 );
	}
	public static function is_route() { global $wp; return ! empty( $wp->query_vars[ self::QV ] ); }
	public static function no_404( $pre, $q ) { return self::is_route() ? true : $pre; }
	public static function render_route() {
		if ( ! self::is_route() ) { return; }
		status_header( 200 ); header( 'Content-Type: text/html; charset=utf-8' ); header( 'X-Robots-Tag: noindex' );
		echo self::page_html( Frontend::is_draft_request() ? Config::get_draft() : Config::get_published() ); exit;
	}
	public static function add_shortcode() { add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) ); }
	/** [ansa_promo_tombola] на обикновена страница — само формата (без <html>), със същите стилове. */
	public static function shortcode() { return self::widget_html( Config::get_published(), false ); }

	/* ── HTML ── */
	private static function copy( $cfg, $k, $vars = array() ) { static $c = null; if ( null === $c ) { $c = Copy::effective( $cfg['copy'] ); } return Copy::render( isset( $c[ $k ] ) ? $c[ $k ] : $k, $vars ); }
	private static function base_vars( $cfg ) { $box = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][0]; $v = Cart::vars( $cfg, $box ); $v['draw'] = self::draw_label( $cfg ); $v['mult'] = (int) $cfg['tombola']['ref_mult']; $v['tombola_url'] = self::url( $cfg ); return $v; }
	public static function page_html( $cfg ) {
		$v = self::base_vars( $cfg ); $title = wp_strip_all_tags( self::copy( $cfg, 'tb.title', $v ) );
		return '<!DOCTYPE html><html lang="bg"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title . ' · ' . $cfg['name'] ) . '</title>'
			. '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap">'
			. '<style>html,body{margin:0;padding:0;background:#faf3ec;color:#1a1a2e;font-family:Nunito,-apple-system,BlinkMacSystemFont,sans-serif;-webkit-font-smoothing:antialiased}body{min-height:100dvh;display:flex;align-items:flex-start;justify-content:center;padding:max(24px,env(safe-area-inset-top)) 16px 40px;box-sizing:border-box}</style>'
			. '</head><body class="ansa-promo-tombola-page">' . self::widget_html( $cfg, true ) . '</body></html>';
	}
	/** Картата с формата; $full = на самостоятелната страница (с „ansa™“ отгоре). */
	public static function widget_html( $cfg, $full = true ) {
		$v = self::base_vars( $cfg ); $t = $cfg['tombola'];
		$texts = array();
		foreach ( array( 'tb.wait', 'tb.cta', 'tb.again', 'tb.ref.copy', 'tb.ref.copied', 'tb.ref.share', 'tb.err.net' ) as $k ) { $texts[ $k ] = self::copy( $cfg, $k, $v ); }
		$rt = array( 'ver' => ANSA_PROMO_VER, 'ajax' => admin_url( 'admin-ajax.php' ), 'wcajax' => home_url( '/?wc-ajax=ansa_promo_ref' ), 'ref_param' => $t['ref_param'], 'texts' => $texts, 'live' => self::enabled( $cfg ) );
		$h = '<div class="ansa-tb" id="ansaTombola" data-ver="' . esc_attr( ANSA_PROMO_VER ) . '">' . self::css();
		$h .= '<div class="ansa-tb__card">' . ( $full ? '<div class="ansa-tb__brand">ansa™</div>' : '' ) . '<h1>' . wp_kses_post( self::copy( $cfg, 'tb.title', $v ) ) . '</h1><p class="ansa-tb__sub">' . wp_kses_post( self::copy( $cfg, 'tb.sub', $v ) ) . '</p>';
		$h .= '<form class="ansa-tb__form" id="ansaTbForm" novalidate><label><span>' . esc_html( self::copy( $cfg, 'tb.phone.label' ) ) . '</span><input type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="' . esc_attr( self::copy( $cfg, 'tb.phone.ph' ) ) . '" required></label>'
			. '<label><span>' . esc_html( self::copy( $cfg, 'tb.email.label' ) ) . '</span><input type="email" name="email" inputmode="email" autocomplete="email" placeholder="' . esc_attr( self::copy( $cfg, 'tb.email.ph' ) ) . '" required></label>'
			. '<button type="submit" class="ansa-tb__btn">' . esc_html( self::copy( $cfg, 'tb.cta' ) ) . '</button><p class="ansa-tb__err" id="ansaTbErr" hidden></p></form>';
		$h .= '<div class="ansa-tb__result" id="ansaTbResult" hidden></div><p class="ansa-tb__foot">' . wp_kses_post( self::copy( $cfg, 'tb.foot', $v ) ) . '</p></div>';
		$h .= '<script>window.AnsaTombola=' . wp_json_encode( $rt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script><script>' . self::js() . '</script></div>';
		return $h;
	}
	private static function css() {
		return '<style id="ansa-tb-css">.ansa-tb{width:100%;max-width:520px;margin:0 auto;font-family:Nunito,-apple-system,BlinkMacSystemFont,sans-serif;color:#1a1a2e}'
			. '.ansa-tb__card{--tbx:22px;--tbb:26px;background:#fff;border:1.5px solid rgba(232,114,42,.25);border-radius:22px;padding:26px var(--tbx) var(--tbb);box-shadow:0 14px 40px rgba(232,114,42,.12)}'
			. '.ansa-tb__brand{font-weight:900;color:#e8722a;font-size:22px}.ansa-tb h1{margin:6px 0 6px;font-size:26px;line-height:1.15;font-weight:900;letter-spacing:-.02em}.ansa-tb__sub{margin:0 0 18px;font-size:14.5px;color:#6b5f58;font-weight:600;line-height:1.45}'
			. '.ansa-tb__form label{display:block;margin-bottom:12px}.ansa-tb__form label span{display:block;font-size:12.5px;font-weight:800;color:#7a6a62;margin:0 0 5px 2px;text-transform:uppercase;letter-spacing:.04em}'
			. '.ansa-tb__form input{width:100%;box-sizing:border-box;font:inherit;font-size:17px;font-weight:700;padding:13px 14px;border:1.5px solid rgba(232,114,42,.35);border-radius:12px;background:#fff;color:#1a1a2e;outline:none}.ansa-tb__form input:focus{border-color:#e8722a;box-shadow:0 0 0 3px rgba(232,114,42,.15)}'
			. '.ansa-tb__btn{width:100%;font:inherit;font-size:17px;font-weight:900;color:#fff;border:none;border-radius:14px;min-height:52px;margin-top:4px;background:linear-gradient(135deg,#ef8c4f,#e8722a 50%,#e56b50);cursor:pointer;box-shadow:0 8px 20px rgba(232,114,42,.25)}.ansa-tb__btn[disabled]{opacity:.6;cursor:wait}'
			. '.ansa-tb__err{color:#b91c1c;font-weight:800;margin:12px 0 0;font-size:14px;line-height:1.45}.ansa-tb__foot{margin:18px 0 0;font-size:12.5px;color:#a88a75;font-weight:600;text-align:center;line-height:1.45}'
			. '.ansa-tb__ok{text-align:center}.ansa-tb__ok h2{margin:4px 0 6px;font-size:24px;font-weight:900;line-height:1.2}.ansa-tb__ok .big{font-size:46px;font-weight:900;color:#e8722a;line-height:1;margin:10px 0 2px;letter-spacing:-1px}.ansa-tb__ok .big small{font-size:18px;color:#1a1a2e;letter-spacing:0}'
			. '.ansa-tb__ok p{margin:8px 0;font-size:15px;line-height:1.45;font-weight:600}.ansa-tb__list{list-style:none;margin:14px 0 0;padding:0;text-align:left}.ansa-tb__list li{display:flex;gap:10px;align-items:center;font-size:14px;font-weight:700;padding:8px 10px;border-radius:12px;background:#fff8f1;border:1px solid #f3cdb8;margin-bottom:6px;line-height:1.35}.ansa-tb__list li i{flex:none;font-style:normal;width:30px;height:30px;border-radius:10px;background:#fff;border:1px solid #f3cdb8;display:inline-flex;align-items:center;justify-content:center;font-size:16px}'
			. '.ansa-tb__sec{border:1px solid #f3cdb8;border-radius:14px;background:#fff8f1;margin:10px 0 0;text-align:left}.ansa-tb__sec summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:8px;padding:12px 14px;font-size:15px;line-height:1.3;user-select:none}.ansa-tb__sec summary::-webkit-details-marker{display:none}.ansa-tb__sec summary b{font-weight:900}.ansa-tb__sec summary small{font-weight:700;color:#a88a75;font-size:12.5px;margin-left:auto;white-space:nowrap}.ansa-tb__sec summary i{font-style:normal;color:#e8722a;font-weight:900;transition:transform .2s}.ansa-tb__sec[open] summary i{transform:rotate(180deg)}.ansa-tb__sec>ul,.ansa-tb__sec>div{padding:0 10px 10px}.ansa-tb__sec .ansa-tb__list{margin:0}.ansa-tb__sec .ansa-tb__list li{background:#fff}.ansa-tb__draw{margin:0!important;font-size:13.5px!important;color:#4a3f3a}'
			. '.ansa-tb__vou{margin:0;padding:12px 14px;border-radius:14px;background:linear-gradient(135deg,#fff7f3,#fff1f6);border:1.5px solid #f6c9d6;font-size:14px;font-weight:700;line-height:1.45;text-align:left}.ansa-tb__vou b{color:#e8722a;font-size:16px}'
			. '.ansa-tb__ref{position:sticky;bottom:0;z-index:5;margin:16px calc(-1*var(--tbx)) calc(-1*var(--tbb));padding:0 var(--tbx) calc(14px + env(safe-area-inset-bottom));border-radius:0 0 22px 22px;background:#fff6ee;border-top:1.5px solid #f3cdb8;box-shadow:0 -10px 24px rgba(232,114,42,.14);text-align:left}'
			. '.ansa-tb__ref-h{display:flex;align-items:center;gap:8px;width:100%;background:none;border:none;padding:13px 0 10px;font:inherit;font-size:16px;font-weight:900;line-height:1.25;color:#1a1a2e;text-align:left;cursor:pointer}.ansa-tb__ref-h i{margin-left:auto;font-style:normal;color:#e8722a;transition:transform .2s}.ansa-tb__ref--closed .ansa-tb__ref-h i{transform:rotate(180deg)}.ansa-tb__ref--closed .ansa-tb__ref-b{display:none}.ansa-tb__ref--closed{padding-bottom:calc(4px + env(safe-area-inset-bottom))}'
			. '.ansa-tb__ref p{margin:0 0 10px;font-size:13.5px;font-weight:600;line-height:1.45;color:#4a3f3a}'
			. '.ansa-tb__link{display:flex;gap:6px;align-items:center}.ansa-tb__link input{flex:1;min-width:0;font:inherit;font-size:13.5px;font-weight:800;padding:11px 12px;border:1.5px solid rgba(232,114,42,.35);border-radius:12px;background:#fff;color:#1a1a2e}.ansa-tb__link button,.ansa-tb__share{font:inherit;font-size:14px;font-weight:900;border:none;border-radius:12px;padding:11px 14px;cursor:pointer;color:#fff;background:linear-gradient(135deg,#ef8c4f,#e8722a 50%,#e56b50);white-space:nowrap}.ansa-tb__share{width:100%;margin-top:8px;min-height:44px}'
			. '.ansa-tb__again{display:block;margin:16px auto 0;background:none;border:none;font:inherit;font-size:13.5px;font-weight:800;color:#a88a75;text-decoration:underline;cursor:pointer}'
			. '.ansa-tb__toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);z-index:999999;background:#3bb273;color:#fff;font:700 14px/1.45 Nunito,sans-serif;padding:12px 20px;border-radius:999px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:min(560px,92vw);text-align:center}'
			. '@media (max-width:480px){.ansa-tb__card{--tbx:16px;--tbb:22px;padding:22px var(--tbx) var(--tbb);border-radius:18px}.ansa-tb__ref{border-radius:0 0 18px 18px}.ansa-tb h1{font-size:23px}.ansa-tb__ok .big{font-size:40px}}</style>';
	}
	private static function js() {
		return '(function(){var R=window.AnsaTombola||{},T=R.texts||{},f=document.getElementById("ansaTbForm"),err=document.getElementById("ansaTbErr"),res=document.getElementById("ansaTbResult");if(!f)return;'
			. 'var btn=f.querySelector("button"),cta=btn.textContent;'
			. 'function show(e,msg){e.hidden=!msg;e.innerHTML=msg||""}'
			. 'f.addEventListener("submit",function(ev){ev.preventDefault();show(err,"");var fd=new FormData(f);fd.append("action","ansa_promo_tombola");btn.disabled=true;btn.textContent=T["tb.wait"]||"…";'
			. 'fetch(R.ajax,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(d){btn.disabled=false;btn.textContent=cta;'
			. 'if(!d||!d.success){show(err,(d&&d.data&&d.data.msg)||T["tb.err.net"]);return}'
			. 'res.innerHTML=d.data.html;f.hidden=true;res.hidden=false;try{res.scrollIntoView({behavior:"smooth",block:"start"})}catch(e){}wire()})'
			. '.catch(function(){btn.disabled=false;btn.textContent=cta;show(err,T["tb.err.net"]||"Грешка — опитай пак.")})});'
			. 'function wire(){var a=res.querySelector(".ansa-tb__again");if(a)a.onclick=function(){res.hidden=true;f.hidden=false;show(err,"")};'
			. 'var rh=res.querySelector(".ansa-tb__ref-h");if(rh)rh.onclick=function(){var b=rh.parentNode,c=b.classList.toggle("ansa-tb__ref--closed");rh.setAttribute("aria-expanded",c?"false":"true")};'
			. 'var c=res.querySelector("[data-copy]"),inp=res.querySelector(".ansa-tb__link input");if(c&&inp)c.onclick=function(){var v=inp.value;function ok(){c.textContent=T["tb.ref.copied"]||"✓";setTimeout(function(){c.textContent=T["tb.ref.copy"]||"Копирай"},2500)}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(v).then(ok,function(){inp.select();try{document.execCommand("copy")}catch(e){}ok()})}else{inp.select();try{document.execCommand("copy");ok()}catch(e){}}};'
			. 'var s=res.querySelector(".ansa-tb__share");if(s){if(!navigator.share){s.onclick=function(){var m=s.getAttribute("data-msg")||"";window.open("https://wa.me/?text="+encodeURIComponent(m),"_blank")}}else{s.onclick=function(){navigator.share({text:s.getAttribute("data-msg")||"",url:inp?inp.value:location.href}).catch(function(){})}}}}'
			/* кацане с ?r=КОД на страницата на томболата (кеширана страница → JS → ajax → сесия + бисквитка) */
			. 'if(res.innerHTML.replace(/\\s/g,""))wire();'
			. 'var m=location.search.match(new RegExp("[?&]"+(R.ref_param||"r")+"=([^&#]+)"));if(m&&R.live){var fd2=new FormData();fd2.append("action","ansa_promo_ref");fd2.append("code",decodeURIComponent(m[1]));'
			. 'var done=function(d){if(d&&d.ok&&d.message){var t=document.createElement("div");t.className="ansa-tb__toast";t.innerHTML=d.message;document.body.appendChild(t);setTimeout(function(){t.style.transition="opacity .5s";t.style.opacity="0";setTimeout(function(){t.remove()},600)},9000)}};'
			. 'fetch(R.wcajax,{method:"POST",body:fd2,credentials:"same-origin"}).then(function(r){if(!r.ok)throw 0;return r.json()}).then(done).catch(function(){fetch(R.ajax,{method:"POST",body:fd2,credentials:"same-origin"}).then(function(r){return r.json()}).then(done).catch(function(){})})}'
			. '})();';
	}

	/* ── JS за промо страницата: ?r=КОД → сесия (Partner прави същото с ?ref=) ── */
	public static function ref_js() {
		if ( is_admin() || ! self::enabled() || ! Frontend::is_promo_page() || Frontend::gate_shown() ) { return; }
		$cfg = Config::get_published(); $p = preg_quote( $cfg['tombola']['ref_param'], '/' );
		echo '<script id="ansa-promo-ref">(function(){var m=location.search.match(/[?&]' . $p . '=([^&#]+)/);if(!m)return;var fd=new FormData();fd.append("action","ansa_promo_ref");fd.append("code",decodeURIComponent(m[1]));'
			. 'function toast(msg){var el=document.createElement("div");el.style.cssText="position:fixed;left:50%;bottom:26px;transform:translateX(-50%);z-index:2147483000;background:#3bb273;color:#fff;font:700 14px/1.45 Nunito,-apple-system,sans-serif;padding:12px 20px;border-radius:999px;box-shadow:0 8px 24px rgba(0,0,0,.18);max-width:min(560px,92vw);text-align:center";el.innerHTML=msg;document.body.appendChild(el);setTimeout(function(){el.style.transition="opacity .5s";el.style.opacity="0";setTimeout(function(){el.remove()},600)},9000)}'
			. 'function done(d){if(d&&d.ok&&d.message)toast(d.message);if(d&&d.ok&&history.replaceState){try{var u=new URL(location.href);u.searchParams.delete(' . wp_json_encode( $cfg['tombola']['ref_param'] ) . ');history.replaceState(null,"",u.toString())}catch(e){}}}'
			. 'fetch("/?wc-ajax=ansa_promo_ref",{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){if(!r.ok)throw 0;return r.json()}).then(done).catch(function(){fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(done).catch(function(){})})})();</script>' . "\n";
	}

	/* ── помощници ── */
	/** Телефон → само цифри; 00359… / +359… → 0…; сравнява се по последните 9 цифри. */
	public static function norm_phone( $p ) {
		$d = preg_replace( '/\D+/', '', (string) $p );
		if ( 0 === strpos( $d, '00' ) ) { $d = substr( $d, 2 ); }
		if ( 0 === strpos( $d, '359' ) && strlen( $d ) >= 12 ) { $d = '0' . substr( $d, 3 ); }
		return (string) $d;
	}
	public static function same_phone( $a, $b ) { $a = self::norm_phone( $a ); $b = self::norm_phone( $b ); if ( '' === $a || '' === $b ) { return false; } return $a === $b || ( strlen( $a ) >= 9 && strlen( $b ) >= 9 && substr( $a, -9 ) === substr( $b, -9 ) ); }
	public static function norm_code( $c ) { $c = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $c ) ); return self::CODE_LEN === strlen( $c ) ? $c : ''; }
	private static function gen_code() { $out = ''; $max = strlen( Tickets::ALPHABET ) - 1; for ( $i = 0; $i < self::CODE_LEN; $i++ ) { $out .= Tickets::ALPHABET[ random_int( 0, $max ) ]; } return $out; }
	/** Кодът за препоръка на този билет; генерира се при първа нужда (и за стари билети). */
	public static function ensure_ref_code( $row ) {
		global $wpdb;
		if ( ! $row ) { return ''; }
		if ( ! empty( $row['ref_code'] ) ) { return (string) $row['ref_code']; }
		$tries = 0;
		while ( $tries++ < 5 ) { $c = self::gen_code(); if ( $wpdb->update( Schema::table( 'tickets' ), array( 'ref_code' => $c ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) ) ) { return $c; } }
		return '';
	}
	public static function by_ref_code( $code ) { global $wpdb; $c = self::norm_code( $code ); if ( '' === $c ) { return null; } return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'tickets' ) . ' WHERE ref_code = %s', $c ), ARRAY_A ); }
	public static function referrals( $ref_code, $status = '' ) { global $wpdb; $t = Schema::table( 'referrals' ); return $status ? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE ref_code = %s AND status = %s ORDER BY id", $ref_code, $status ), ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE ref_code = %s ORDER BY id", $ref_code ), ARRAY_A ); }
	public static function referral_of( $order_id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'referrals' ) . ' WHERE order_id = %d', (int) $order_id ), ARRAY_A ); }
	public static function count_referrals( $status = '' ) { global $wpdb; $t = Schema::table( 'referrals' ); return (int) ( $status ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = %s", $status ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM $t" ) ); }

	/** Бонусът на препоръчалия = min(credited, ref_max) × билетите му × (ref_mult − 1); записва се в tickets.bonus. */
	public static function recompute_bonus( $referrer_order_id, $cfg = null ) {
		global $wpdb;
		$row = Tickets::for_order( $referrer_order_id ); if ( ! $row || empty( $row['ref_code'] ) ) { return 0; }
		$t = self::cfg( $cfg ); $n = count( self::referrals( $row['ref_code'], 'credited' ) );
		if ( $t['ref_max'] > 0 ) { $n = min( $n, (int) $t['ref_max'] ); }
		$bonus = (int) apply_filters( 'ansa_promo_referral_bonus', $n * (int) $row['tickets'] * max( 0, (int) $t['ref_mult'] - 1 ), $n, $row, $t );
		if ( (int) $row['bonus'] !== $bonus ) { $wpdb->update( Schema::table( 'tickets' ), array( 'bonus' => $bonus ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) ); }
		return $bonus;
	}

	/* ── улавяне на ?r=КОД ── */
	public static function pending_ref() {
		$s = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::SESSION ) : null;
		$code = is_array( $s ) ? (string) ( $s['code'] ?? '' ) : ''; $t = is_array( $s ) ? (int) ( $s['t'] ?? 0 ) : 0;
		if ( '' === $code || ( $t && time() - $t > self::REF_TTL ) ) { $code = isset( $_COOKIE[ self::COOKIE ] ) ? self::norm_code( (string) $_COOKIE[ self::COOKIE ] ) : ''; }
		return $code;
	}
	/** Валиден ли е кодът за препоръка (има билет, не е анулиран)? Връща реда на билета или null. */
	public static function valid_ref( $code ) { $row = self::by_ref_code( $code ); return ( $row && 'void' !== $row['status'] ) ? $row : null; }
	public static function ajax_ref() {
		if ( ! self::enabled() ) { wp_send_json( array( 'ok' => false ) ); }
		if ( Leads::rate_limited( 'ref', 20 ) ) { wp_send_json( array( 'ok' => false ), 429 ); }
		$code = self::norm_code( (string) wp_unslash( $_POST['code'] ?? '' ) ); $row = $code ? self::valid_ref( $code ) : null;
		if ( ! $row ) { wp_send_json( array( 'ok' => false ) ); }
		if ( function_exists( 'WC' ) && WC()->session ) { if ( ! WC()->session->has_session() ) { WC()->session->set_customer_session_cookie( true ); } WC()->session->set( self::SESSION, array( 'code' => $code, 't' => time() ) ); }
		setcookie( self::COOKIE, $code, time() + self::REF_TTL, '/', '', is_ssl(), true );
		$cfg = Config::get_published(); $gift = (int) $cfg['tombola']['ref_gift'];
		wp_send_json( array( 'ok' => true, 'message' => wp_kses_post( self::copy( $cfg, 'tb.ref.toast', array_merge( self::base_vars( $cfg ), array( 'bonus' => $gift, 'bonus_bileta' => 1 === $gift ? 'билет' : 'билета' ) ) ) ) ) );
	}
	/** В чекаута: редът „+1 билет — подарък от приятел“, докато има уловен код. */
	public static function checkout_row( $rows, $cfg, $v ) {
		if ( ! self::enabled( $cfg ) ) { return $rows; }
		$code = self::pending_ref(); if ( '' === $code || ! self::valid_ref( $code ) ) { return $rows; }
		$gift = (int) $cfg['tombola']['ref_gift']; if ( $gift <= 0 ) { return $rows; }
		return $rows . '<li><i>🎁</i> ' . wp_kses_post( self::copy( $cfg, 'tb.ref.co', array_merge( $v, array( 'bonus' => $gift, 'bonus_bileta' => 1 === $gift ? 'билет' : 'билета' ) ) ) ) . '</li>';
	}
	/** При създаване на поръчката: уловеният код се замразява в _ansa_promo.tickets.ref_* (+ref_gift билет); собствен имейл/телефон → self (без бонус). */
	public static function attach_ref( $order, $m ) {
		if ( ! is_array( $m ) || empty( $m['valid'] ) || ! empty( $m['tickets']['ref_code'] ) ) { return; }
		$cfg = ! empty( $m['draft'] ) ? Config::get_draft() : Config::get_published(); if ( ! self::enabled( $cfg ) ) { return; }
		$code = self::pending_ref(); if ( '' === $code ) { return; }
		$row = self::valid_ref( $code ); if ( ! $row ) { return; }
		$ref = wc_get_order( (int) $row['order_id'] ); if ( ! $ref || $ref->get_id() === $order->get_id() ) { return; }
		$self = strtolower( trim( (string) $ref->get_billing_email() ) ) === strtolower( trim( (string) $order->get_billing_email() ) ) || self::same_phone( $ref->get_billing_phone(), $order->get_billing_phone() );
		$m['tickets']['ref_code'] = $code; $m['tickets']['ref_order'] = (int) $row['order_id']; $m['tickets']['ref_bonus'] = $self ? 0 : (int) $cfg['tombola']['ref_gift']; $m['tickets']['ref_self'] = $self;
		$order->update_meta_data( '_ansa_promo', $m ); $order->update_meta_data( '_ansa_promo_ref', $code ); $order->save();
		$order->add_order_note( $self ? sprintf( 'ansa™ Промо: линк за препоръка %s от поръчка #%s — същият клиент (имейл/телефон), без бонус.', $code, $ref->get_order_number() ) : sprintf( 'ansa™ Промо: поръчката е по препоръка (%s, поръчка #%s) · +%d билет(а) подарък.', $code, $ref->get_order_number(), (int) $m['tickets']['ref_bonus'] ) );
		if ( function_exists( 'WC' ) && WC()->session ) { WC()->session->set( self::SESSION, null ); }
		setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, '/', '', is_ssl(), true );
	}
	/** Билетът на препоръчаната поръчка е издаден (платена) → ред в referrals (credited / dup / self) + бонусът на препоръчалия. */
	public static function on_issued( $row, $order ) {
		global $wpdb;
		self::ensure_ref_code( $row );
		$m = (array) $order->get_meta( '_ansa_promo' ); $code = (string) ( $m['tickets']['ref_code'] ?? '' ); if ( '' === $code ) { return; }
		$ref_row = self::by_ref_code( $code ); if ( ! $ref_row ) { return; }
		$email = strtolower( trim( (string) $order->get_billing_email() ) ); $t = Schema::table( 'referrals' );
		$status = 'credited';
		if ( ! empty( $m['tickets']['ref_self'] ) ) { $status = 'self'; }
		elseif ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE ref_code = %s AND email = %s AND order_id <> %d AND status = 'credited'", $code, $email, $order->get_id() ) ) ) { $status = 'dup'; }
		$ex = self::referral_of( $order->get_id() );
		if ( $ex ) { $wpdb->update( $t, array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $ex['id'] ), array( '%s', '%s' ), array( '%d' ) ); }
		else { $wpdb->insert( $t, array( 'ref_code' => $code, 'referrer_order_id' => (int) $ref_row['order_id'], 'order_id' => $order->get_id(), 'email' => substr( $email, 0, 191 ), 'status' => $status, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ), array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' ) ); }
		$bonus = self::recompute_bonus( (int) $ref_row['order_id'] );
		$ref_o = wc_get_order( (int) $ref_row['order_id'] );
		if ( $ref_o && 'credited' === $status ) { $ref_o->add_order_note( sprintf( 'ansa™ Промо: препоръка — поръчка #%s е платена по линка %s · бонусът за томболата е вече %d билет(а).', $order->get_order_number(), $code, $bonus ) ); }
		do_action( 'ansa_promo_referral', $status, $row, $ref_row, $order );
	}
	public static function on_void( $row ) {
		global $wpdb; $r = self::referral_of( (int) $row['order_id'] ); if ( ! $r || 'void' === $r['status'] ) { return; }
		$wpdb->update( Schema::table( 'referrals' ), array( 'status' => 'void', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $r['id'] ), array( '%s', '%s' ), array( '%d' ) );
		self::recompute_bonus( (int) $r['referrer_order_id'] );
	}
	/** Възстановен билет (void → issued; Tickets::issue не пуска hook-а) → препоръката пак се брои. */
	public static function on_paid( $order_id ) {
		$r = self::referral_of( (int) $order_id ); if ( ! $r || 'void' !== $r['status'] ) { return; }
		$row = Tickets::for_order( (int) $order_id ); $o = wc_get_order( (int) $order_id ); if ( $row && $o && 'void' !== $row['status'] ) { self::on_issued( $row, $o ); }
	}

	/** Всички поръчки с този billing имейл (до 50; резерва: по клиента, ако нищо не излезе по billing_email). Само с нашия имейл — сравнението е в PHP. */
	public static function orders_by_email( $email ) {
		$email = strtolower( trim( (string) $email ) ); if ( '' === $email ) { return array(); }
		$base = array( 'limit' => 50, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'status' => array_keys( wc_get_order_statuses() ) );
		$orders = wc_get_orders( array_merge( $base, array( 'billing_email' => $email ) ) );
		if ( ! $orders ) { $orders = wc_get_orders( array_merge( $base, array( 'customer' => $email ) ) ); }
		$out = array();
		foreach ( (array) $orders as $o ) { if ( is_object( $o ) && strtolower( trim( (string) $o->get_billing_email() ) ) === $email ) { $out[] = $o; } }
		return $out;
	}
	/** Диагностика за админа/Probe: защо (не) се хваща всяка поръчка с този имейл → [orders => [ {id, number, status, phones, promo, valid, draft, ticket, phone_ok, delivered, why[]} ], result]. */
	public static function diagnose( $email, $phone, $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $rows = array();
		foreach ( self::orders_by_email( $email ) as $o ) {
			$m = $o->get_meta( '_ansa_promo' ); $t = Tickets::for_order( $o->get_id() ); $why = array();
			$bp = (string) $o->get_billing_phone(); $sp = (string) $o->get_shipping_phone();
			$phone_ok = '' !== self::norm_phone( $phone ) && ( self::same_phone( $bp, $phone ) || self::same_phone( $sp, $phone ) );
			$deliv = self::delivered( $o, $cfg );
			if ( ! is_array( $m ) ) { $why[] = 'не е промо поръчка (няма _ansa_promo — поръчката не е минала през играта)'; }
			elseif ( empty( $m['valid'] ) ) { $why[] = 'кутията е била непълна при поръчката (valid=0) — без билети'; }
			if ( ! $phone_ok ) { $why[] = 'телефонът не съвпада: в поръчката „' . $bp . '“' . ( $sp && $sp !== $bp ? ' / „' . $sp . '“' : '' ) . ' (нормализирано ' . self::norm_phone( $bp ) . '), въведен „' . $phone . '“ (' . self::norm_phone( $phone ) . ')'; }
			if ( ! $deliv ) { $why[] = 'статусът „' . $o->get_status() . '“ не е сред „получените“ (' . implode( ', ', (array) $cfg['tombola']['statuses'] ) . ')'; }
			if ( $t && 'void' === $t['status'] ) { $why[] = 'билетът е анулиран'; }
			if ( ! $t && is_array( $m ) && ! empty( $m['valid'] ) ) { $why[] = 'още няма издаден билет (издава се при проверката, ако кутията носи билети: ' . (int) ( $m['tickets']['allowance'] ?? 0 ) . ')'; }
			$rows[] = array( 'id' => $o->get_id(), 'number' => $o->get_order_number(), 'status' => $o->get_status(), 'date' => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd.m.Y H:i' ) : '', 'billing_phone' => $bp, 'shipping_phone' => $sp, 'promo' => is_array( $m ), 'valid' => is_array( $m ) && ! empty( $m['valid'] ), 'draft' => is_array( $m ) && ! empty( $m['draft'] ), 'box' => is_array( $m ) ? (string) ( $m['box'] ?? '' ) : '', 'allowance' => is_array( $m ) ? (int) ( $m['tickets']['allowance'] ?? 0 ) : 0, 'ticket' => $t ? Tickets::pretty( $t['code'] ) . ' · ' . $t['status'] . ' · ' . (int) $t['tickets'] . ' бил.' : '', 'phone_ok' => $phone_ok, 'delivered' => $deliv, 'ok' => ! $why, 'why' => $why, 'edit' => $o->get_edit_order_url() );
		}
		return array( 'orders' => $rows, 'result' => self::lookup( $email, $phone, $cfg, false ) );
	}

	/* ── проверката ── */
	public static function ajax_lookup() {
		$cfg = Config::get_published(); $v = self::base_vars( $cfg );
		$err = function ( $k, $status = 400 ) use ( $cfg, $v ) { wp_send_json_error( array( 'code' => $k, 'msg' => wp_kses_post( self::copy( $cfg, 'tb.err.' . $k, $v ) ) ), $status ); };
		if ( ! self::enabled( $cfg ) ) { $err( 'off', 403 ); }
		if ( Leads::rate_limited( 'tombola', 8 ) ) { $err( 'rate', 429 ); }
		$email = sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) ); $phone = sanitize_text_field( (string) wp_unslash( $_POST['phone'] ?? '' ) );
		if ( ! is_email( $email ) || strlen( self::norm_phone( $phone ) ) < 7 ) { $err( 'input' ); }
		$r = self::lookup( $email, $phone, $cfg );
		if ( empty( $r['ok'] ) ) { $err( $r['code'] ); }
		wp_send_json_success( array( 'html' => self::result_html( $r, $cfg ), 'tickets' => $r['total'], 'orders' => count( $r['orders'] ) ) );
	}
	/**
	 * Без HTTP: промо поръчките с този имейл и телефон → [ok, code] или [ok, total, orders[], voucher[], ref_code, ref_link, refs, ref_bonus].
	 * Активира билетите (status activated, portal_user = имейлът) на доставените поръчки.
	 */
	public static function lookup( $email, $phone, $cfg = null, $activate = true ) {
		$cfg = $cfg ?: Config::get_published(); $t = $cfg['tombola'];
		if ( ! function_exists( 'wc_get_orders' ) ) { return array( 'ok' => false, 'code' => 'off' ); }
		$match = array();
		foreach ( self::orders_by_email( $email ) as $o ) {
			if ( ! self::same_phone( $o->get_billing_phone(), $phone ) && ! self::same_phone( $o->get_shipping_phone(), $phone ) ) { continue; }
			/* 1.6.1: поръчка от черновата (преглед с парола) е истинска поръчка — брои се */
			$m = $o->get_meta( '_ansa_promo' ); if ( ! is_array( $m ) || empty( $m['valid'] ) ) { continue; }
			$match[] = $o;
		}
		if ( ! $match ) { return array( 'ok' => false, 'code' => 'notfound' ); }
		$deliv = array_values( array_filter( $match, function ( $o ) use ( $cfg ) { return self::delivered( $o, $cfg ); } ) );
		if ( ! $deliv ) {
			$travel = array_filter( $match, function ( $o ) { return ! in_array( $o->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ); } );
			return array( 'ok' => false, 'code' => $travel ? 'notyet' : 'notfound' );
		}
		$out = array( 'ok' => true, 'total' => 0, 'orders' => array(), 'voucher' => array(), 'ref_code' => '', 'ref_link' => '', 'refs' => 0, 'ref_bonus' => 0, 'email' => $email );
		foreach ( $deliv as $o ) {
			$m = (array) $o->get_meta( '_ansa_promo' ); $row = Tickets::for_order( $o->get_id() );
			if ( ! $row ) { $row = Tickets::issue( $o->get_id() ); }
			if ( ! $row || 'void' === $row['status'] ) { continue; }
			if ( $activate && 'activated' !== $row['status'] ) { $row = Tickets::activate( $row, $email ) ?: $row; }
			$code = self::ensure_ref_code( $row ); $row['ref_code'] = $code;
			$bonus = self::recompute_bonus( $o->get_id(), $cfg ); $refs = count( self::referrals( $code, 'credited' ) ); if ( $t['ref_max'] > 0 ) { $refs = min( $refs, (int) $t['ref_max'] ); }
			$box = Config::box( $cfg, (string) $row['box'] );
			$out['orders'][] = array( 'id' => $o->get_id(), 'number' => $o->get_order_number(), 'box' => $box ? $box['name'] : (string) $row['box'], 'box_ic' => $box ? $box['ic'] : '', 'tickets' => (int) $row['tickets'], 'allowance' => (int) ( $m['tickets']['allowance'] ?? 0 ), 'partner' => ! empty( $m['tickets']['partner'] ) ? (int) ( $m['tickets']['bonus'] ?? 0 ) : 0, 'gift' => (int) ( $m['tickets']['ref_bonus'] ?? 0 ), 'bonus' => $bonus, 'refs' => $refs, 'code' => Tickets::pretty( $row['code'] ), 'ref_code' => $code );
			$out['total'] += (int) $row['tickets'] + $bonus; $out['refs'] += $refs; $out['ref_bonus'] += $bonus;
			if ( '' === $out['ref_code'] && $code ) { $out['ref_code'] = $code; $out['ref_link'] = self::ref_link( $code, $cfg ); }
			if ( ! empty( $m['cosm'] ) && 'reserved' === ( $m['cosm']['status'] ?? '' ) ) { $out['voucher'][] = array( 'order' => $o->get_order_number(), 'pay' => (float) $m['cosm']['pay'], 'code' => (string) $o->get_meta( '_ansa_promo_voucher' ) ); }
		}
		if ( ! $out['orders'] ) { return array( 'ok' => false, 'code' => 'notfound' ); }
		return $out;
	}
	/** „Честито!“ — HTML на резултата. */
	public static function result_html( $r, $cfg ) {
		$v = self::base_vars( $cfg ); $v['tickets'] = (int) $r['total']; $v['bileta'] = 1 === $v['tickets'] ? 'билет' : 'билета'; $v['ref_link'] = esc_url( $r['ref_link'] ); $v['ref_code'] = $r['ref_code'];
		$bil = function ( $n ) { return 1 === (int) $n ? 'билет' : 'билета'; };
		$no = count( $r['orders'] );
		$h = '<div class="ansa-tb__ok"><h2>' . wp_kses_post( self::copy( $cfg, 'tb.ok.h', $v ) ) . '</h2><div class="big">' . (int) $r['total'] . ' <small>' . esc_html( $v['bileta'] ) . '</small></div><p>' . wp_kses_post( self::copy( $cfg, 'tb.ok.p', $v ) ) . '</p>';
		/* секции с разгъни/свий (<details>): откъде са билетите (отворена), ваучерът (свита — кодът е в заглавието) */
		$h .= '<details class="ansa-tb__sec" open><summary><b>' . wp_kses_post( self::copy( $cfg, 'tb.sec.orders', $v ) ) . '</b><small>' . $no . ' ' . ( 1 === $no ? 'поръчка' : 'поръчки' ) . '</small><i>▾</i></summary><ul class="ansa-tb__list">';
		foreach ( $r['orders'] as $o ) {
			$h .= '<li><i>📦</i> ' . wp_kses_post( self::copy( $cfg, 'tb.ok.order', array_merge( $v, array( 'order' => $o['number'], 'box' => $o['box'], 'box_ic' => $o['box_ic'], 'tickets' => $o['allowance'] ?: $o['tickets'], 'bileta' => $bil( $o['allowance'] ?: $o['tickets'] ) ) ) ) ) . '</li>';
			if ( $o['partner'] > 0 ) { $h .= '<li><i>🤝</i> ' . wp_kses_post( self::copy( $cfg, 'tb.ok.partner', array_merge( $v, array( 'bonus' => $o['partner'], 'bonus_bileta' => $bil( $o['partner'] ) ) ) ) ) . '</li>'; }
			if ( $o['gift'] > 0 ) { $h .= '<li><i>🎁</i> ' . wp_kses_post( self::copy( $cfg, 'tb.ok.gift', array_merge( $v, array( 'bonus' => $o['gift'], 'bonus_bileta' => $bil( $o['gift'] ) ) ) ) ) . '</li>'; }
			if ( $o['bonus'] > 0 ) { $h .= '<li><i>💞</i> ' . wp_kses_post( self::copy( $cfg, 'tb.ok.refs', array_merge( $v, array( 'bonus' => $o['bonus'], 'bonus_bileta' => $bil( $o['bonus'] ), 'n' => $o['refs'], 'n_word' => 1 === (int) $o['refs'] ? 'близък човек' : 'близки хора' ) ) ) ) . '</li>'; }
		}
		$h .= '</ul></details>';
		if ( $r['voucher'] ) {
			$codes = implode( ', ', array_filter( array_map( function ( $x ) { return $x['code']; }, $r['voucher'] ) ) );
			$h .= '<details class="ansa-tb__sec"><summary><b>' . wp_kses_post( self::copy( $cfg, 'tb.sec.voucher', $v ) ) . '</b><small>' . esc_html( $codes ) . '</small><i>▾</i></summary><div>';
			foreach ( $r['voucher'] as $vo ) { $vv = array_merge( $v, array( 'cosm_pay' => str_replace( '.', ',', rtrim( rtrim( number_format( $vo['pay'], 2, '.', '' ), '0' ), '.' ) ), 'voucher' => $vo['code'], 'order' => $vo['order'] ) ); $h .= '<div class="ansa-tb__vou">' . wp_kses_post( self::copy( $cfg, $vo['code'] ? 'tb.voucher' : 'tb.voucher.soon', $vv ) ) . '</div>'; }
			$h .= '</div></details>';
		}
		$h .= '<details class="ansa-tb__sec"><summary><b>' . wp_kses_post( self::copy( $cfg, 'tb.sec.draw', $v ) ) . '</b><small>' . esc_html( $v['draw'] ) . '</small><i>▾</i></summary><div><p class="ansa-tb__draw">' . wp_kses_post( self::copy( $cfg, 'tb.foot', $v ) ) . '</p></div></details>';
		$h .= '<button type="button" class="ansa-tb__again">' . esc_html( self::copy( $cfg, 'tb.again' ) ) . '</button></div>';
		/* „Препоръчай на близък човек“ — залепен в дъното на екрана (sticky), със свиване до заглавието */
		if ( $r['ref_link'] && (int) $cfg['tombola']['ref_mult'] > 1 ) {
			$msg = wp_strip_all_tags( self::copy( $cfg, 'tb.ref.msg', $v ) );
			$h .= '<div class="ansa-tb__ref"><button type="button" class="ansa-tb__ref-h" aria-expanded="true"><span>' . wp_kses_post( self::copy( $cfg, 'tb.ref.h', $v ) ) . '</span><i>▾</i></button><div class="ansa-tb__ref-b"><p>' . wp_kses_post( self::copy( $cfg, 'tb.ref.p', $v ) ) . '</p>'
				. '<div class="ansa-tb__link"><input type="text" readonly value="' . esc_attr( $r['ref_link'] ) . '" onclick="this.select()"><button type="button" data-copy>' . esc_html( self::copy( $cfg, 'tb.ref.copy' ) ) . '</button></div>'
				. '<button type="button" class="ansa-tb__share" data-msg="' . esc_attr( $msg ) . '">' . esc_html( self::copy( $cfg, 'tb.ref.share' ) ) . '</button></div></div>';
		}
		return apply_filters( 'ansa_promo_tombola_result_html', $h, $r, $cfg );
	}

	/* ── админ: участниците (за 🎲 Томбола и CSV) ── */
	/** Доставените промо поръчки с билет: [order, number, name, email, phone, box, tickets, bonus, total, status, activated, ref_code, refs, voucher]. */
	public static function entries( $cfg = null, $limit = 5000 ) {
		$cfg = $cfg ?: Config::get_published(); $out = array();
		$q = array( 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'status' => array_map( function ( $s ) { return 'wc-' . $s; }, (array) $cfg['tombola']['statuses'] ), 'meta_key' => '_ansa_promo_id', 'meta_compare' => 'EXISTS' );
		foreach ( wc_get_orders( $q ) as $o ) {
			$m = (array) $o->get_meta( '_ansa_promo' ); if ( empty( $m['valid'] ) ) { continue; }
			$row = Tickets::for_order( $o->get_id() ); if ( ! $row || 'void' === $row['status'] ) { continue; }
			$refs = $row['ref_code'] ? count( self::referrals( $row['ref_code'], 'credited' ) ) : 0;
			$out[] = array( 'id' => $o->get_id(), 'number' => $o->get_order_number(), 'name' => $o->get_formatted_billing_full_name(), 'email' => $o->get_billing_email(), 'phone' => $o->get_billing_phone(), 'box' => (string) $row['box'], 'tickets' => (int) $row['tickets'], 'bonus' => (int) $row['bonus'], 'total' => (int) $row['tickets'] + (int) $row['bonus'], 'status' => $row['status'], 'activated' => (string) $row['activated_at'], 'code' => Tickets::pretty( $row['code'] ), 'ref_code' => (string) $row['ref_code'], 'refs' => $refs, 'gift' => (int) ( $m['tickets']['ref_bonus'] ?? 0 ), 'voucher' => (string) $o->get_meta( '_ansa_promo_voucher' ), 'edit' => $o->get_edit_order_url() );
		}
		return $out;
	}
	public static function csv( $rows ) {
		$fh = fopen( 'php://temp', 'w+' ); fputs( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, array( 'order', 'name', 'email', 'phone', 'box', 'tickets', 'referral_bonus', 'total', 'ticket_status', 'activated_at', 'code', 'ref_code', 'referrals', 'gift_from_friend', 'voucher' ), ';' );
		foreach ( $rows as $r ) { fputcsv( $fh, array( $r['number'], $r['name'], $r['email'], $r['phone'], $r['box'], $r['tickets'], $r['bonus'], $r['total'], $r['status'], $r['activated'], $r['code'], $r['ref_code'], $r['refs'], $r['gift'], $r['voucher'] ), ';' ); }
		rewind( $fh ); $csv = stream_get_contents( $fh ); fclose( $fh ); return $csv;
	}
	/** Теглене: случаен участник, тежест = total билети (random_int). Връща реда или null. Резултатът се пази в option ansa_promo_tombola_draws. */
	public static function draw( $rows, $label = '' ) {
		$sum = 0; foreach ( $rows as $r ) { $sum += max( 0, (int) $r['total'] ); }
		if ( $sum <= 0 ) { return null; }
		$pick = random_int( 1, $sum ); $acc = 0; $win = null;
		foreach ( $rows as $r ) { $acc += max( 0, (int) $r['total'] ); if ( $pick <= $acc ) { $win = $r; break; } }
		if ( $win ) { $log = (array) get_option( 'ansa_promo_tombola_draws', array() ); array_unshift( $log, array( 'at' => current_time( 'mysql' ), 'label' => $label, 'order' => $win['number'], 'order_id' => $win['id'], 'email' => $win['email'], 'tickets' => $win['total'], 'pool' => $sum, 'entries' => count( $rows ), 'pick' => $pick, 'by' => get_current_user_id() ) ); update_option( 'ansa_promo_tombola_draws', array_slice( $log, 0, 50 ), false ); }
		return $win;
	}
}
