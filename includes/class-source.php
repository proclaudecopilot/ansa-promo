<?php
/**
 * Insights 4 (1.42.0, човекът: „Откъде са дошли (Фейсбук реклама — коя / Гугъл / от попъп в сайта — от кой продукт / имейл)… Трябва да пазим две
 * различни сведения за произхода: кое доведе човека до сайта и кое го доведе до лендъра“):
 * произходът на една сесия от СУРОВИЯ query string на адреса + referrer-а. Чист PHP (без WP), тества се офлайн (tests/insights-source-test.php).
 *
 *  - params()   — разпознатите параметри: utm_* · utm_id · ad_id / adset_id / campaign_id · ad_name / adset_name / campaign_name · placement ·
 *                 fbclid / gclid / ttclid / msclkid (само дали ги има) · ap_pop / ap_from / ap_entry (попъпът на сайта, Shrine ≥ 6.33).
 *  - classify() — каналът + „пътят“ до конкретния източник (до 3 нива):
 *                 Meta реклама: кампания → рекламна група → реклама (име или ID; плейсмънтът отделно) · Google платено / органично ·
 *                 Попъп в сайта: страница/продукт → попъп · Имейл: изпращане → линк · Друг вътрешен линк: страница/място → кампания ·
 *                 Facebook/Instagram органично · Друг сайт · Друг UTM · По телефон · Директно / неустановен произход.
 *                 Без UTM на самия адрес, но с UTM в referrer-а (кацане на подстраница от реклама → клик към лендъра) → UTM-ът от referrer-а.
 *  - of()       — за ред от {prefix}ansa_promo_sessions: 'entry' (входът към лендъра: entry_qs/entry_ref) и 'ext' (външният източник: touch_qs/
 *                 touch_ref, за стари сесии — колоните src/med/camp/ref). Два изгледа към едни и същи сесии; не се събират.
 *
 * Препоръчан адрес за рекламите в Meta (Ads Manager → URL parameters):
 *   utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.name}}&utm_id={{campaign.id}}&adset_name={{adset.name}}&adset_id={{adset.id}}
 *   &ad_name={{ad.name}}&ad_id={{ad.id}}&placement={{placement}}&utm_content=<продукт>
 *
 * @package AnsaPromo
 * @since 1.42.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Source {

	/** Каналите в реда на таблицата „Откъде дойдоха и какво направиха“. */
	const ORDER = array(
		'meta'        => 'Facebook / Instagram реклама',
		'google_paid' => 'Google — платено',
		'google_org'  => 'Google — органично',
		'popup'       => 'Попъп в сайта',
		'email'       => 'Имейл',
		'internal'    => 'Друг вътрешен линк',
		'meta_org'    => 'Facebook / Instagram — без реклама',
		'other_utm'   => 'Друг UTM',
		'other_ref'   => 'Друг сайт',
		'phone'       => 'По телефон',
		'direct'      => 'Директно / неустановен произход',
	);
	const META_SRC   = array( 'facebook', 'fb', 'ig', 'instagram', 'an', 'meta', 'audience_network', 'msg', 'messenger', 'threads', 'th', 'fbig' );
	const SITE_SRC   = array( 'home', 'site', 'shop', 'menu', 'footer', 'blog', 'website', 'web', 'ansa', 'ansa.bg', 'header', 'banner', 'nav', 'megamenu', 'mega-menu', 'product', 'pdp', 'cart', 'checkout', 'thankyou', 'account' );
	const EMAIL_SRC  = array( 'newsletter', 'email', 'e-mail', 'klaviyo', 'mailchimp', 'brevo', 'sendinblue', 'sendgrid', 'mail', 'fka', 'funnelkit', 'automation', 'flow' );
	const EMAIL_MED  = array( 'email', 'e-mail', 'mail', 'newsletter', 'automation', 'flow' );
	const PAID_MED   = array( 'paid', 'cpc', 'ppc', 'ads', 'paid_social', 'social', 'paidsocial', 'cpm', 'display' );
	const LEN_NAME   = 96;

	public static function label( $k ) { return self::ORDER[ $k ] ?? (string) $k; }

	/** Параметрите от query string (или цял адрес): малки ключове, decode-нати стойности, без контролни знаци. */
	public static function params( $qs ) {
		$qs = (string) $qs; if ( '' === $qs ) { return array(); }
		if ( false !== strpos( $qs, '://' ) || 0 === strpos( $qs, '/' ) ) { $qs = (string) ( parse_url( $qs, PHP_URL_QUERY ) ?: '' ); }
		$qs = ltrim( $qs, '?' ); $qs = str_replace( '&amp;', '&', $qs ); if ( '' === $qs ) { return array(); }
		parse_str( $qs, $raw ); $out = array();
		foreach ( (array) $raw as $k => $v ) {
			if ( ! is_scalar( $v ) ) { continue; }
			$k = strtolower( trim( (string) $k ) ); $v = (string) $v;
			/* 1.42.1: Meta понякога кодира параметрите на рекламата два пъти (utm_campaign=Season%2B%257C…) — декодираме, докато има %XX; отрязаните
			   (191 знака referrer) последователности оставят невалиден UTF-8 → чистим го, иначе preg с /u връща null и името се губи */
			for ( $i = 0; $i < 2 && preg_match( '/%[0-9a-f]{2}/i', $v ); $i++ ) { $v = urldecode( $v ); }
			$v = self::utf8( preg_replace( '/%[0-9a-f]?$/i', '', $v ) ); $v = trim( preg_replace( '/[\x00-\x1f\x7f]/', '', $v ) ); /* отрязано „%D“ накрая пада */
			if ( '' === $k ) { continue; }
			$out[ $k ] = $v;
		}
		return $out;
	}
	private static function g( $p, $keys, $len = 128 ) { foreach ( (array) $keys as $k ) { if ( isset( $p[ $k ] ) && '' !== $p[ $k ] ) { return self::cut( $p[ $k ], $len ); } } return ''; }
	private static function cut( $v, $len ) { $v = self::utf8( (string) $v ); $r = preg_replace( '/\s+/u', ' ', $v ); $v = trim( null === $r ? $v : $r ); return function_exists( 'mb_substr' ) ? mb_substr( $v, 0, $len ) : substr( $v, 0, $len ); }
	/** Само валиден UTF-8 (невалидните байтове падат); чист PHP — без mb, ако го няма. */
	public static function utf8( $v ) {
		$v = (string) $v; if ( '' === $v || preg_match( '//u', $v ) ) { return $v; }
		$c = false;
		if ( function_exists( 'mb_convert_encoding' ) && function_exists( 'mb_substitute_character' ) ) { $old = mb_substitute_character(); mb_substitute_character( 'none' ); $c = @mb_convert_encoding( $v, 'UTF-8', 'UTF-8' ); mb_substitute_character( $old ); }
		if ( ! is_string( $c ) && function_exists( 'iconv' ) ) { $c = @iconv( 'UTF-8', 'UTF-8//IGNORE', $v ); }
		return is_string( $c ) && preg_match( '//u', $c ) ? $c : preg_replace( '/[\x80-\xff]+/', '', $v );
	}
	private static function has( $p, $k ) { return isset( $p[ $k ] ); }

	/** Хостът на referrer-а без www/m/l/lm и пътят му (без завършваща наклонена черта). */
	public static function ref_parts( $ref ) {
		$ref = trim( (string) $ref ); if ( '' === $ref ) { return array( '', '' ); }
		$h = strtolower( (string) ( parse_url( $ref, PHP_URL_HOST ) ?: '' ) ); $h = preg_replace( '/^(www|m|l|lm|web|business|touch)\./', '', $h );
		$p = (string) ( parse_url( $ref, PHP_URL_PATH ) ?: '/' ); $p = rawurldecode( $p ); $p = rtrim( $p, '/' ) ?: '/';
		return array( $h, $p );
	}
	public static function is_site_host( $host, $home = 'ansa.bg' ) { $host = (string) $host; $home = preg_replace( '/^www\./', '', strtolower( (string) $home ) ); return '' !== $host && ( $host === $home || '.' . $home === substr( $host, -strlen( $home ) - 1 ) ); }
	public static function is_meta_host( $host ) { return (bool) preg_match( '/(^|\.)(facebook|instagram|fb|messenger|threads|fbcdn)\.(com|net)$/', (string) $host ); }
	public static function is_google_host( $host ) { return (bool) preg_match( '/(^|\.)google\./', (string) $host ); }

	/** Нормализираният източник (facebook/Facebook/fb → facebook). */
	public static function norm_src( $s ) {
		$s = strtolower( trim( (string) $s ) );
		if ( in_array( $s, self::META_SRC, true ) ) { return 'facebook'; }
		if ( false !== strpos( $s, 'google' ) ) { return 'google'; }
		return $s;
	}

	/**
	 * Класификацията: [ 'k' => канал, 'l' => етикет, 'path' => [ниво 1, ниво 2, ниво 3] (празните нива се махат), 'product' => utm_content,
	 * 'placement' => плейсмънт, 'ids' => [campaign, adset, ad], 'from_ref' => UTM-ът е дошъл от referrer-а ].
	 * $home = домейнът на сайта (за „вътрешен линк“).
	 */
	public static function classify( $qs, $ref, $home = 'ansa.bg' ) {
		$p = self::params( $qs ); $from_ref = false;
		list( $host, $path ) = self::ref_parts( $ref );
		$marks = function ( $p ) { return self::g( $p, array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'ad_id', 'adset_id', 'campaign_id', 'ap_pop', 'ap_entry' ) ) !== '' || self::has( $p, 'fbclid' ) || self::has( $p, 'gclid' ) || self::has( $p, 'ttclid' ) || self::has( $p, 'msclkid' ); };
		if ( ! $marks( $p ) && '' !== $ref && false !== stripos( (string) $ref, 'utm_' ) ) { /* кацане на подстраница с UTM → клик към лендъра: UTM-ът от referrer-а */
			$pr = self::params( $ref ); if ( $marks( $pr ) ) { $p = array_merge( $pr, array_filter( $p, function ( $v, $k ) { return 0 !== strpos( $k, 'utm_' ); }, ARRAY_FILTER_USE_BOTH ) ); $from_ref = true; }
		}
		$src = self::g( $p, 'utm_source', 64 ); $s = self::norm_src( $src ); $med = self::g( $p, 'utm_medium', 64 ); $m = strtolower( $med ); $camp = self::g( $p, 'utm_campaign', self::LEN_NAME );
		$product = strtolower( self::g( $p, 'utm_content', 64 ) );
		$ids = array( self::g( $p, array( 'campaign_id', 'utm_id', 'cid' ), 40 ), self::g( $p, array( 'adset_id', 'ad_set_id', 'adgroup_id', 'utm_term_id' ), 40 ), self::g( $p, array( 'ad_id', 'adid', 'creative_id' ), 40 ) );
		$names = array( self::g( $p, array( 'campaign_name', 'utm_campaign' ), self::LEN_NAME ), self::g( $p, array( 'adset_name', 'ad_set_name', 'adgroup', 'adgroup_name' ), self::LEN_NAME ), self::g( $p, array( 'ad_name', 'creative', 'utm_term' ), self::LEN_NAME ) );
		$placement = self::g( $p, array( 'placement', 'utm_placement' ), 64 );
		if ( '' === $placement && preg_match( '/^(facebook|instagram|threads|audience|messenger)[_ ]?(mobile|desktop|right|instream|stories|reels|feed|explore|search|marketplace|video|others|column|profile)/i', $med ) ) { $placement = $med; }
		$base = array( 'product' => $product, 'placement' => $placement, 'ids' => $ids, 'from_ref' => $from_ref, 'src' => $s, 'med' => $m, 'camp' => $camp );
		$mk = function ( $k, $path ) use ( $base ) { $path = array_values( array_filter( array_map( 'strval', (array) $path ), 'strlen' ) ); return array_merge( array( 'k' => $k, 'l' => self::ORDER[ $k ], 'path' => $path ), $base ); };
		$site = self::is_site_host( $host, $home );
		$page = ''; if ( $site ) { $page = $path; }
		$lvl = function ( $name, $id, $fallback = '' ) { if ( '' !== $name ) { return $name; } if ( '' !== $id ) { return '#' . $id; } return $fallback; };
		/* попъпът на сайта: ap_pop (Shrine ≥ 6.33) или utm_medium=popup — страница/продукт → попъп */
		$pop = self::g( $p, 'ap_pop', 64 ); $entry = strtolower( self::g( $p, 'ap_entry', 16 ) );
		if ( '' !== $pop || 'popup' === $entry || false !== strpos( $m, 'popup' ) || false !== strpos( $m, 'pop-up' ) || 'popup' === $s ) {
			$from = self::g( $p, 'ap_from', 128 ); if ( '' === $from ) { $from = $page; }
			$pname = '' !== $pop ? $pop : ( '' !== $camp ? $camp : ( '' !== $med && 'popup' !== $m ? $med : 'попъп' ) );
			return $mk( 'popup', array( '' !== $from ? $from : 'неизвестна страница', $pname ) );
		}
		if ( 'phone' === $s ) { return $mk( 'phone', array( $camp ) ); }
		$has_fb = self::has( $p, 'fbclid' );
		$is_meta = 'facebook' === $s || false !== strpos( $m, 'facebook' ) || false !== strpos( $m, 'instagram' ) || ( $has_fb && '' === $s );
		if ( ! $is_meta && '' !== $src && 'google' !== $s && in_array( $m, self::PAID_MED, true ) && ! in_array( $s, self::SITE_SRC, true ) && ! in_array( $s, self::EMAIL_SRC, true ) ) { $is_meta = true; } /* GREENSREDSSCALINGmaxroas / paid — кампания в utm_source */
		if ( ! $is_meta && '' === $src && self::is_meta_host( $host ) && ( '' !== $camp || '' !== $ids[0] || '' !== $ids[2] ) ) { $is_meta = true; }
		if ( $is_meta ) {
			/* без име/ID на кампания: кампанията понякога седи в utm_source (GREENSREDSSCALINGmaxroas / Facebook_Mobile_Feed) */
			$c1 = $lvl( $names[0], $ids[0], '' !== $src && ! in_array( strtolower( $src ), self::META_SRC, true ) ? $src : 'без име на кампания' );
			return $mk( 'meta', array( $c1, $lvl( $names[1], $ids[1] ), $lvl( $names[2], $ids[2] ) ) );
		}
		if ( self::has( $p, 'gclid' ) || ( 'google' === $s && ( in_array( $m, self::PAID_MED, true ) || '' !== $camp ) ) ) { return $mk( 'google_paid', array( $lvl( $names[0], $ids[0], 'без име на кампания' ), $lvl( $names[1], $ids[1] ), $lvl( $names[2], $ids[2] ) ) ); }
		if ( in_array( $m, self::EMAIL_MED, true ) || false !== strpos( $m, 'mail' ) || in_array( $s, self::EMAIL_SRC, true ) || 'flow' === substr( $s, -4 ) ) {
			return $mk( 'email', array( '' !== $camp ? $camp : ( '' !== $src ? $src : 'имейл' ), self::g( $p, array( 'utm_content', 'utm_term', 'link' ), 64 ) ) );
		}
		if ( in_array( $s, self::SITE_SRC, true ) ) { return $mk( 'internal', array( trim( $src . ( '' !== $med ? ' / ' . $med : '' ) ), $camp ) ); }
		if ( '' === $src && '' === $med && '' === $camp && '' === $ids[0] ) {
			if ( '' === $host ) { return $mk( 'direct', array() ); }
			if ( $site ) { return $mk( 'internal', array( $path ) ); }
			if ( self::is_meta_host( $host ) ) { return $mk( 'meta_org', array( $host ) ); }
			if ( self::is_google_host( $host ) ) { return $mk( 'google_org', array( 'търсене' ) ); }
			return $mk( 'other_ref', array( $host ) );
		}
		if ( 'google' === $s ) { return $mk( 'google_org', array( '' !== $camp ? $camp : 'търсене' ) ); }
		return $mk( 'other_utm', array( trim( $src . ( '' !== $med ? ' / ' . $med : '' ) ), $camp ) );
	}

	/** Pseudo query string от старите колони на сесията (src/med/camp) — за редове отпреди 1.42.0. */
	public static function legacy_qs( $r ) {
		$q = array(); foreach ( array( 'src' => 'utm_source', 'med' => 'utm_medium', 'camp' => 'utm_campaign', 'utm' => 'utm_content' ) as $c => $k ) { $v = trim( (string) ( $r[ $c ] ?? '' ) ); if ( '' !== $v ) { $q[ $k ] = $v; } }
		return $q ? http_build_query( $q ) : '';
	}

	/**
	 * За ред от sessions: [ 'entry' => classify, 'ext' => classify ]. Входът към лендъра е от entry_qs/entry_ref (1.42.0); без тях — външният.
	 * Външният източник: touch_qs/touch_ref (първото докосване на сайта в този таб), иначе старите колони src/med/camp/ref.
	 */
	public static function of( $r, $home = 'ansa.bg' ) {
		$tq = (string) ( $r['touch_qs'] ?? '' ); $tr = (string) ( $r['touch_ref'] ?? '' );
		if ( '' === $tq && '' === $tr ) { $tq = self::legacy_qs( $r ); $tr = (string) ( $r['ref'] ?? '' ); }
		$ext = self::classify( $tq, $tr, $home );
		$eq = (string) ( $r['entry_qs'] ?? '' ); $er = (string) ( $r['entry_ref'] ?? '' );
		if ( '' === $eq && '' === $er ) { $entry = $ext; $entry['legacy'] = true; }
		else {
			$entry = self::classify( $eq, $er, $home ); $entry['legacy'] = false;
			/* входът е „директен“, а знаем външния източник → входът е самият външен източник (човекът е кацнал направо на лендъра) */
			if ( 'direct' === $entry['k'] && 'direct' !== $ext['k'] ) { $entry = $ext; $entry['legacy'] = false; }
		}
		/* външният източник е „вътрешен линк“ (нямаме по-ранно докосване), а входът е външен → външният е входът */
		if ( in_array( $ext['k'], array( 'internal', 'direct', 'popup' ), true ) && ! in_array( $entry['k'], array( 'internal', 'direct', 'popup' ), true ) ) { $ext = $entry; }
		return array( 'entry' => $entry, 'ext' => $ext );
	}
}
