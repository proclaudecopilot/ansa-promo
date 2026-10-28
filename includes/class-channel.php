<?php
/**
 * Insights 3 (1.38.0, човекът: „откъде са дошли — от Мета, от имейл, от попъпа през сайта — от кой попъп точно“):
 * каналът на една сесия от UTM-а и referrer-а ѝ. Чист PHP (без WP функции), за да е тестваем офлайн (tests/insights-channel-test.php).
 *
 *  - UTM-ът на сесията (utm_source / utm_medium / utm_campaign). Ако сесията няма UTM, а referrer-ът носи такъв (кацане на подстраница
 *    като /sezonut-na-ansa/kozmetika/?utm_source=facebook… и после клик към промото), UTM-ът се чете от referrer-а.
 *  - Канали: Meta реклама · Meta без UTM (профил/органично) · Имейл · Попъп на сайта · Сайтът (линк с UTM) · Сайтът (линк без UTM) ·
 *    Подстраници на промото (без UTM) · Google · Друг сайт · Друг UTM · По телефон · Директно / без referrer.
 *  - „Попъп на сайта“ = utm_medium съдържа „popup“ (или utm_source=popup); детайлът е utm_campaign = името на попъпа. Без такъв UTM
 *    попъпът се вижда като „Сайтът (линк без UTM)“ със страницата, на която е бил показан — затова линкът в попъпа трябва да носи
 *    ?utm_source=site&utm_medium=popup&utm_campaign=<име-на-попъпа>.
 *
 * @package AnsaPromo
 * @since 1.38.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Channel {

	/** Редът на колоните в „📅 По дни × откъде идват“ и етикетите. */
	const ORDER = array(
		'meta'      => 'Meta реклама',
		'meta_org'  => 'Meta без UTM (профил / органично)',
		'email'     => 'Имейл',
		'popup'     => 'Попъп на сайта',
		'site_utm'  => 'Сайтът (линк с UTM)',
		'site_ref'  => 'Сайтът (линк без UTM)',
		'promo_sub' => 'Подстраници на промото (без UTM)',
		'google'    => 'Google',
		'other_ref' => 'Друг сайт',
		'other_utm' => 'Друг UTM',
		'phone'     => 'По телефон',
		'direct'    => 'Директно / без referrer',
	);

	public static function label( $key ) { return self::ORDER[ $key ] ?? (string) $key; }

	/** UTM тройката на сесията; празен utm_source → от referrer-а, ако носи такъв. Връща [src, med, camp, from_ref]. */
	public static function utm( $r ) {
		$src = trim( (string) ( $r['src'] ?? '' ) ); $med = trim( (string) ( $r['med'] ?? '' ) ); $camp = trim( (string) ( $r['camp'] ?? '' ) ); $from_ref = false;
		if ( '' === $src && '' === $med ) {
			$ref = (string) ( $r['ref'] ?? '' ); $qs = (string) ( parse_url( $ref, PHP_URL_QUERY ) ?: '' );
			if ( '' !== $qs && false !== stripos( $qs, 'utm_' ) ) {
				parse_str( str_replace( '&amp;', '&', $qs ), $q );
				$src = self::clean( $q['utm_source'] ?? '' ); $med = self::clean( $q['utm_medium'] ?? '' ); $camp = self::clean( $q['utm_campaign'] ?? '', 128, '/[^\p{L}\p{N}_\-\.\s@|]/u' );
				$from_ref = '' !== $src || '' !== $med;
			}
		}
		return array( $src, $med, $camp, $from_ref );
	}

	private static function clean( $v, $len = 64, $re = '/[^a-z0-9_\-\.]/i' ) { return substr( preg_replace( $re, '', (string) $v ), 0, $len ); }

	/** Хостът на referrer-а без www/m/l/lm и пътят му. */
	public static function ref_parts( $ref ) {
		$ref = trim( (string) $ref ); if ( '' === $ref ) { return array( '', '' ); }
		$h = strtolower( (string) ( parse_url( $ref, PHP_URL_HOST ) ?: '' ) ); $h = preg_replace( '/^(www|m|l|lm|web|business)\./', '', $h );
		$p = (string) ( parse_url( $ref, PHP_URL_PATH ) ?: '/' ); $p = rawurldecode( $p ); if ( '' === $p ) { $p = '/'; }
		return array( $h, $p );
	}

	/**
	 * Каналът на сесия: [ 'k' => ключ от ORDER, 'l' => етикет, 'd' => детайл (кампания / имейл / попъп / страница / сайт), 'f' => филтър (f_src/f_med/f_camp) или [] ].
	 */
	public static function of( $r ) {
		list( $src, $med, $camp, $from_ref ) = self::utm( $r );
		$s = strtolower( $src ); $m = strtolower( $med ); $f = array( 'f_src' => $src, 'f_med' => $med, 'f_camp' => $camp );
		if ( $from_ref ) { $f = array(); } /* филтърът на екрана е по колоните на сесията, не по referrer-а */
		list( $host, $path ) = self::ref_parts( $r['ref'] ?? '' );
		$sm = trim( $src . ( '' !== $med ? ' / ' . $med : '' ) );
		if ( '' === $s && '' === $m ) {
			if ( '' === $host ) { return self::mk( 'direct', '', array() ); }
			if ( 'ansa.bg' === $host || '.ansa.bg' === substr( $host, -8 ) ) {
				$pp = rtrim( $path, '/' ) ?: '/';
				if ( 0 === strpos( $pp, '/sezonut-na-ansa' ) || 0 === strpos( $pp, '/kozmetichen-set-promo' ) ) { return self::mk( 'promo_sub', $pp, array() ); }
				return self::mk( 'site_ref', $pp, array() );
			}
			if ( preg_match( '/(^|\.)(facebook|instagram|fb|messenger|threads)\.(com|net)$/', $host ) ) { return self::mk( 'meta_org', $host, array() ); }
			if ( preg_match( '/(^|\.)google\./', $host ) ) { return self::mk( 'google', 'търсене', array() ); }
			return self::mk( 'other_ref', $host, array() );
		}
		if ( false !== strpos( $m, 'popup' ) || false !== strpos( $m, 'pop-up' ) || 'popup' === $s ) { return self::mk( 'popup', '' !== $camp ? $camp : ( '' !== $med && 'popup' !== $m ? $med : $sm ), $f ); }
		if ( in_array( $s, array( 'home', 'site', 'shop', 'menu', 'footer', 'blog', 'website', 'web', 'ansa', 'ansa.bg', 'header', 'banner' ), true ) ) { return self::mk( 'site_utm', $sm . ( '' !== $camp ? ' · ' . $camp : '' ), $f ); }
		if ( in_array( $m, array( 'email', 'e-mail', 'mail', 'newsletter' ), true ) || in_array( $s, array( 'newsletter', 'email', 'klaviyo', 'mailchimp', 'brevo', 'sendinblue', 'sendgrid', 'mail', 'fka', 'funnelkit' ), true ) || 'flow' === substr( $s, -4 ) || false !== strpos( $m, 'mail' ) ) { return self::mk( 'email', '' !== $camp ? $camp : $sm, $f ); }
		if ( false !== strpos( $s, 'google' ) ) { return self::mk( 'google', '' !== $camp ? $camp : $sm, $f ); }
		if ( in_array( $s, array( 'facebook', 'fb', 'ig', 'instagram', 'an', 'meta', 'audience_network', 'msg', 'messenger', 'threads' ), true ) || false !== strpos( $m, 'facebook' ) || false !== strpos( $m, 'instagram' ) || in_array( $m, array( 'paid', 'cpc', 'ppc', 'ads', 'paid_social', 'social', 'paidsocial' ), true ) ) { return self::mk( 'meta', '' !== $camp ? $camp : $sm, $f ); }
		if ( 'phone' === $s ) { return self::mk( 'phone', '', $f ); }
		return self::mk( 'other_utm', $sm . ( '' !== $camp ? ' · ' . $camp : '' ), $f );
	}

	private static function mk( $k, $d, $f ) { return array( 'k' => $k, 'l' => self::ORDER[ $k ], 'd' => (string) $d, 'f' => $f ); }
}
