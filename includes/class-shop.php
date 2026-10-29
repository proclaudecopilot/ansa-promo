<?php
/**
 * 1.44.0 (човекът: „https://www.ansa.bg/shop/ — цялата тази страница трябва да е красива и добре направена“):
 * страницата /shop/ (WooCommerce shop page, is_shop()) вече е на плъгина, не стандартният архив на темата (12 на страница, изчерпани
 * сетове с „Черен петък“ снимки най-отгоре, „Uncategorized“, отрязани бутони „Поръч“, цени „€ 27,90–€ 65,90с ДДС“).
 *  - template_include → includes/shop/template-shop.php (header/footer на темата, Elementor Pro header/footer);
 *  - продуктите, нуждите, снимките, имената, описанията и „от“ цените са същите като в мега менюто (Mega_Menu::products — промото +
 *    видимите налични продукти от магазина + изброените по slug; без ваучери, изчерпани сетове и скрити продукти) + рейтинг от WC;
 *  - hero с заглавие, доверие и картата на Сезона (докато е активен; иначе здравословният тест), чипове по нужда (филтър без презареждане,
 *    `#meno` в адреса → предизбран), решетка 4/3/2 колони, отдолу тестът и отзивите;
 *  - стил: assets/shop.css (Nunito, същите токени като менюто); JS е инлайн (филтърът).
 * Търсенето (?s=&post_type=product) и категориите остават на темата.
 *
 * @package AnsaPromo
 * @since 1.44.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Shop {
	const LEAD  = 'Натурални добавки и козметика, направени в България — от жени за жени. Избери по нужда или започни с теста за 1 минута.';
	const TRUST = array( '💗 15 300+ доволни жени', '🌿 Направено в България', '🚚 Доставка за 1–2 работни дни', '💳 С карта или наложен платеж' );

	public static function register() {
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}
	/** Само магазинът (/shop/), не търсене, не категория/таг, не админ. */
	public static function is_shop_page() {
		try { return function_exists( 'is_shop' ) && is_shop() && ! is_search() && ! is_admin(); } catch ( \Throwable $e ) { return false; }
	}
	public static function template_include( $tpl ) {
		if ( ! self::is_shop_page() ) { return $tpl; }
		$own = ANSA_PROMO_PATH . 'includes/shop/template-shop.php';
		return is_file( $own ) ? $own : $tpl;
	}
	public static function enqueue() {
		if ( ! self::is_shop_page() ) { return; }
		wp_enqueue_style( 'ansa-promo-nunito', 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap', array(), null );
		wp_enqueue_style( 'ansa-promo-shop', ANSA_PROMO_URL . 'assets/shop.css', array( 'ansa-promo-nunito' ), ANSA_PROMO_VER );
	}
	public static function body_class( $c ) { if ( self::is_shop_page() ) { $c[] = 'ansa-shop-page'; } return $c; }

	/** Продуктите за страницата: от менюто + рейтинг/брой отзиви от WC. */
	public static function products() {
		$P = Mega_Menu::products();
		foreach ( $P as $k => $p ) {
			$r = 0.0; $n = 0;
			try { $wc = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $p['id'] ) : null; if ( $wc ) { $r = (float) $wc->get_average_rating(); $n = (int) $wc->get_review_count(); } } catch ( \Throwable $e ) {}
			$P[ $k ]['rating'] = $r; $P[ $k ]['reviews'] = $n;
			$P[ $k ]['name'] = Mega_Menu::SHORT[ $k ] ?? $p['name'];
		}
		return $P;
	}
	private static function money( $v ) { return '€ ' . number_format( (float) $v, 2, ',', ' ' ); }
	private static function plural( $n ) { return 1 === (int) $n ? '1 продукт' : (int) $n . ' продукта'; }
	private static function rating( $r ) { return str_replace( '.', ',', number_format( (float) $r, 1, '.', '' ) ); }

	public static function render() {
		$P = self::products();
		$h = function ( $s ) { return esc_html( $s ); };
		$L = array(); foreach ( Mega_Menu::LINKS as $k => $v ) { $L[ $k ] = esc_url( home_url( $v ) ); }
		$season = Mega_Menu::season_on(); $pct = Mega_Menu::season_pct();
		$promo = esc_url( Cart::promo_url() );
		$counts = array( 'all' => count( $P ) ); foreach ( Mega_Menu::NEEDS as $k => $n ) { $counts[ $k ] = count( array_filter( Mega_Menu::list_of( $k ), function ( $x ) use ( $P ) { return isset( $P[ $x ] ); } ) ); }

		$trust = ''; foreach ( self::TRUST as $t ) { $trust .= '<li>' . $h( $t ) . '</li>'; }
		$aside = $season
			? '<a class="as-season" href="' . $promo . '"><span class="as-ey">Сезонът на ANSA · нашият Черен петък</span><strong>До −' . (int) $pct . '% + козметичен сет за 1 €</strong><span class="as-p">Отстъпката расте с всяка опаковка. 5+ опаковки = −' . (int) $pct . '%, сетът и книгата с рецепти.</span><span class="as-cta">Избери продуктите →</span><i class="as-leaf" aria-hidden="true">🍂</i></a>'
			: '<a class="as-season as-season--quiz" href="' . $L['quiz'] . '"><span class="as-ey">Здравословният помощник</span><strong>Не знаеш откъде да започнеш?</strong><span class="as-p">Няколко въпроса, 1 минута, и ти казваме кой продукт е за теб.</span><span class="as-cta">Започни теста →</span><i class="as-leaf" aria-hidden="true">🧭</i></a>';

		$chips = '<button class="as-chip on" data-n="all" type="button"><span class="as-ic">🌿</span>Всички<small>' . (int) $counts['all'] . '</small></button>';
		foreach ( Mega_Menu::NEEDS as $k => $n ) { if ( ! $counts[ $k ] ) { continue; } $chips .= '<button class="as-chip" data-n="' . esc_attr( $k ) . '" data-l="' . esc_attr( $n[0] ) . '" type="button"><span class="as-ic">' . $n[1] . '</span>' . $h( $n[0] ) . '<small>' . (int) $counts[ $k ] . '</small></button>'; }

		$cards = '';
		foreach ( $P as $k => $p ) {
			$needs = Mega_Menu::needs_of( $k ); $new = in_array( 'new', $needs, true ) ? '<i class="as-new">ново</i>' : '';
			$rating = $p['reviews'] > 0 && $p['rating'] > 0 ? '<span class="as-rating"><i>★</i> ' . $h( self::rating( $p['rating'] ) ) . ' · ' . (int) $p['reviews'] . ( 1 === (int) $p['reviews'] ? ' отзив' : ' отзива' ) . '</span>' : '<span class="as-rating as-rating--none"></span>';
			$cards .= '<a class="as-card" data-c="' . esc_attr( implode( ' ', $needs ) ) . '" href="' . esc_url( $p['url'] ) . '">'
				. '<span class="as-pic">' . $new . ( $p['img'] ? '<img src="' . esc_url( $p['img'] ) . '" alt="' . esc_attr( $p['name'] ) . '" loading="lazy">' : '' ) . '</span>'
				. '<span class="as-cat">' . $h( Mega_Menu::need_of( $k ) ) . '</span>'
				. '<b class="as-name">' . $h( $p['name'] ) . '</b>'
				. '<small class="as-ds">' . $h( $p['ds'] ) . '</small>'
				. $rating
				. '<span class="as-bottom"><span class="as-price"><i>от</i>' . $h( self::money( $p['price'] ) ) . '</span><span class="as-btn">Виж →</span></span>'
				. '</a>';
		}

		$out = '<div class="ansa-shop" data-ver="' . esc_attr( ANSA_PROMO_VER ) . '">'
			. '<section class="as-hero"><div class="as-wrap"><div class="as-hero-main"><span class="as-eyebrow">ansa™ · магазин</span><h1>Всички продукти</h1><p class="as-lead">' . $h( self::LEAD ) . '</p><ul class="as-trust">' . $trust . '</ul></div>' . $aside . '</div></section>'
			. '<div class="as-chips-wrap"><div class="as-wrap"><div class="as-chips" role="group" aria-label="Продукти по нужда">' . $chips . '</div></div></div>'
			. '<section class="as-grid-wrap"><div class="as-wrap"><div class="as-gt"><h2 class="as-title">Всички продукти</h2><span class="as-cnt">' . $h( self::plural( $counts['all'] ) ) . '</span></div><div class="as-grid">' . $cards . '</div></div></section>'
			. '<section class="as-more"><div class="as-wrap as-more-grid">'
			. '<a class="as-tile" href="' . $L['quiz'] . '"><span class="as-tic">🧭</span><span><b>Не знаеш откъде да започнеш?</b><small>Няколко въпроса, 1 минута, и ти казваме кой продукт е за теб.</small></span><em>Започни теста →</em></a>'
			. '<a class="as-tile" href="' . $L['reviews'] . '"><span class="as-tic">💬</span><span><b>Какво казват жените</b><small>15 300+ доволни клиентки — истински отзиви за всеки продукт.</small></span><em>Виж отзивите →</em></a>'
			. '</div></section>'
			. '</div>';
		return $out . self::script();
	}
	private static function script() {
		return '<script id="as-js">(function(){var r=document.querySelector(".ansa-shop");if(!r)return;var chips=r.querySelectorAll(".as-chip"),cards=r.querySelectorAll(".as-card"),t=r.querySelector(".as-title"),c=r.querySelector(".as-cnt");'
			. 'function pick(n,push){var k=0;for(var i=0;i<cards.length;i++){var h=n!=="all"&&(" "+cards[i].getAttribute("data-c")+" ").indexOf(" "+n+" ")<0;cards[i].hidden=h;if(!h)k++}var l="Всички продукти";for(var j=0;j<chips.length;j++){var on=chips[j].getAttribute("data-n")===n;chips[j].classList.toggle("on",on);if(on&&n!=="all")l=chips[j].getAttribute("data-l")||l}if(t)t.textContent=l;if(c)c.textContent=k===1?"1 продукт":k+" продукта";if(push&&history.replaceState)history.replaceState(null,"",n==="all"?location.pathname+location.search:"#"+n)}'
			. 'for(var j=0;j<chips.length;j++){(function(b){b.addEventListener("click",function(e){e.preventDefault();pick(b.getAttribute("data-n"),true)})})(chips[j])}'
			. 'function fromHash(){var h=(location.hash||"").replace("#","");pick(h&&r.querySelector(".as-chip[data-n=\\""+h+"\\"]")?h:"all",false)}window.addEventListener("hashchange",fromHash);fromHash()})();</script>';
	}
}
