<?php
/**
 * ansa™ Промо — merge tags за имейлите (1.8.2): „Имаш X билета. Ще се активират, щом вземеш пратката си.“
 *
 *  - FunnelKit Automations (имейлът за потвърждение на поръчката): {{ansa_promo_tickets}} (броят), {{ansa_promo_bileta}}
 *    („билет“/„билета“), {{ansa_promo_tickets_text}} (готовото изречение от регистъра `em.fk.tickets`; ПРАЗНО за
 *    поръчка извън играта → стои в общия имейл без условие), {{ansa_promo_box}} (името на кутията). Регистрират се в
 *    групата „Order“ (wc_order) на FKA → менюто Merge tags на имейла; работят за всеки WC event с поръчка (Order
 *    Created / Processing / Completed …) и приемат fallback="…". Класовете са в includes/fka/merge-tags.php и се
 *    зареждат само ако BWFAN_Merge_Tag съществува и е съвместим (Reflection gate → никога фатал заради чужд API).
 *  - Шорткодове със същите имена за WC имейли / други плъгини: [ansa_promo_tickets order_id="123"], без order_id
 *    вземат поръчката от данните на FKA.
 *  - Броят е Tickets::count_for_order() — същата сметка като томболата, „благодаря“ страницата и имейлите на плъгина.
 *
 * @package AnsaPromo
 * @since 1.8.2
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Merge {

	/** key → примерна стойност за преглед на имейла в FKA. */
	const TAGS = array( 'tickets' => '5', 'bileta' => 'билета', 'tickets_text' => '', 'box' => 'Голяма кутия' );

	/** Състоянието на FKA регистрацията за Doctor/Probe: '' = още не е опитвано. */
	private static $status = '';

	public static function register() {
		foreach ( array_keys( self::TAGS ) as $k ) { add_shortcode( 'ansa_promo_' . $k, function ( $attr ) use ( $k ) { return self::shortcode( $k, $attr ); } ); }
		add_action( 'bwfan_merge_tags_loaded', array( __CLASS__, 'fka' ) );
		add_action( 'init', array( __CLASS__, 'fka' ), 99 ); /* резерва: ако тази версия на FKA няма hook-а или го е пуснала преди boot-а */
		if ( did_action( 'bwfan_merge_tags_loaded' ) ) { self::fka(); }
	}

	/* ── данни ── */

	/** Поръчката за тага: order_id атрибут → данните на FKA (wc_order / order_id) → null. */
	public static function order( $attr = array() ) {
		$attr = is_array( $attr ) ? $attr : array();
		if ( ! function_exists( 'wc_get_order' ) ) { return null; }
		if ( ! empty( $attr['order_id'] ) ) { $o = wc_get_order( (int) $attr['order_id'] ); return $o ?: null; }
		foreach ( array( 'wc_order', 'order', 'order_id', 'wc_order_id' ) as $k ) {
			$d = self::fka_data( $k );
			if ( $d instanceof \WC_Order ) { return $d; }
			if ( is_numeric( $d ) && (int) $d > 0 ) { $o = wc_get_order( (int) $d ); if ( $o ) { return $o; } }
		}
		return null;
	}

	/** Стойностите за поръчката: [tickets, bileta, tickets_text, box]; tickets=0 и tickets_text='' извън играта. */
	public static function values( $order ) {
		$out = array( 'tickets' => 0, 'bileta' => 'билета', 'tickets_text' => '', 'box' => '' );
		if ( ! $order instanceof \WC_Order ) { return $out; }
		$n = Tickets::count_for_order( $order );
		$out['tickets'] = $n; $out['bileta'] = 1 === $n ? 'билет' : 'билета';
		$ev = Tickets::email_vars( $order );
		if ( $ev ) {
			$out['box'] = (string) $ev['vars']['box'];
			if ( $n > 0 ) { $copy = Copy::effective( $ev['cfg']['copy'] ); $out['tickets_text'] = Copy::render( $copy['em.fk.tickets'] ?? '', $ev['vars'] ); }
		}
		return apply_filters( 'ansa_promo_merge_values', $out, $order );
	}

	/** Примерните стойности за прегледа на имейла в FKA (без поръчка). */
	public static function dummy( $key ) {
		if ( 'tickets_text' !== $key ) { return (string) ( self::TAGS[ $key ] ?? '' ); }
		$cfg = Config::get_published(); $box = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][0]; $v = Cart::vars( $cfg, $box );
		$v = array_merge( $v, array( 'customer' => 'Мария', 'order' => '12345', 'code' => 'XXXX-XXXX-XXXX', 'tickets' => (int) $box['tickets'], 'bileta' => 1 === (int) $box['tickets'] ? 'билет' : 'билета', 'portal_url' => self::portal_url( $cfg ), 'tombola_url' => Tombola::enabled( $cfg ) ? Tombola::url( $cfg ) : '' ) );
		return Copy::render( Copy::effective( $cfg['copy'] )['em.fk.tickets'] ?? '', $v );
	}

	/** Адресът, на който се активират билетите: tickets.portal_url, иначе томболата, иначе промо страницата. */
	public static function portal_url( $cfg ) { return $cfg['tickets']['portal_url'] ?: ( Tombola::enabled( $cfg ) ? Tombola::url( $cfg ) : Cart::promo_url( $cfg ) ); }

	/** Шорткодът [ansa_promo_<key> order_id="" fallback=""] — и ядрото на FKA таговете. */
	public static function shortcode( $key, $attr = array() ) {
		$attr = shortcode_atts( array( 'order_id' => 0, 'fallback' => '' ), is_array( $attr ) ? $attr : array() );
		if ( empty( $attr['order_id'] ) && self::is_preview() ) { return self::dummy( $key ); }
		$v = self::values( self::order( $attr ) );
		$val = (string) ( $v[ $key ] ?? '' );
		if ( 'tickets' === $key && 0 === (int) $v['tickets'] ) { $val = ''; }
		return '' !== $val ? $val : ( '' !== (string) $attr['fallback'] ? (string) $attr['fallback'] : ( 'tickets' === $key ? '0' : '' ) );
	}

	/* ── FunnelKit Automations ── */

	public static function fka_data( $key ) {
		if ( ! class_exists( 'BWFAN_Merge_Tag_Loader' ) || ! method_exists( 'BWFAN_Merge_Tag_Loader', 'get_data' ) ) { return null; }
		try { return \BWFAN_Merge_Tag_Loader::get_data( $key ); } catch ( \Throwable $e ) { return null; }
	}
	public static function is_preview() { return (bool) self::fka_data( 'is_preview' ); }
	/** За Doctor/Probe: 'ok · N тага' | 'прескочено: причина' | 'FKA липсва' | 'чака'. */
	public static function fka_status() {
		if ( ! defined( 'BWFAN_VERSION' ) && ! class_exists( 'BWFAN_Merge_Tag' ) ) { return 'FKA липсва'; }
		return self::$status ?: 'чака';
	}

	/** Зарежда и регистрира FKA merge tag класовете (веднъж). Без BWFAN_Merge_Tag или при несъвместим API — само шорткодовете. */
	public static function fka() {
		static $done = false; if ( $done ) { return; }
		if ( ! class_exists( 'BWFAN_Merge_Tag' ) ) { return; }
		$done = true;
		$why = self::fka_compatible();
		if ( '' !== $why ) { self::$status = 'прескочено: ' . $why; return; }
		require_once ANSA_PROMO_PATH . 'includes/fka/merge-tags.php';
		$n = 0; $err = '';
		foreach ( array( 'Ansa_Promo_FKA_Tickets', 'Ansa_Promo_FKA_Bileta', 'Ansa_Promo_FKA_Tickets_Text', 'Ansa_Promo_FKA_Box' ) as $c ) {
			if ( ! class_exists( $c ) ) { continue; }
			try { $c::get_instance(); $n++; self::fka_register( $c ); } catch ( \Throwable $e ) { $err = $err ?: $e->getMessage(); }
		}
		self::$status = $n ? 'ok · ' . $n . ' тага' . ( $err ? ' (без менюто: ' . $err . ')' : '' ) : 'прескочено: ' . ( $err ?: 'няма класове' );
	}

	/** BWFAN_Merge_Tag_Loader::register( …) — аргументите по име на параметъра (подписът се е менил между версиите). */
	private static function fka_register( $class ) {
		if ( ! class_exists( 'BWFAN_Merge_Tag_Loader' ) || ! method_exists( 'BWFAN_Merge_Tag_Loader', 'register' ) ) { return; }
		$rm = new \ReflectionMethod( 'BWFAN_Merge_Tag_Loader', 'register' ); if ( ! $rm->isStatic() || ! $rm->isPublic() ) { return; }
		$args = array();
		foreach ( $rm->getParameters() as $i => $p ) {
			$n = strtolower( $p->getName() );
			if ( false !== strpos( $n, 'group' ) && false !== strpos( $n, 'name' ) ) { $args[] = 'Order'; }
			elseif ( false !== strpos( $n, 'group' ) ) { $args[] = 'wc_order'; }
			elseif ( false !== strpos( $n, 'class' ) ) { $args[] = $class; }
			elseif ( 0 === $i ) { $args[] = 'wc_order'; }
			elseif ( 1 === $i ) { $args[] = $class; }
			elseif ( $p->isDefaultValueAvailable() ) { $args[] = $p->getDefaultValue(); }
			else { $args[] = null; }
		}
		\BWFAN_Merge_Tag_Loader::register( ...$args );
	}

	/**
	 * Проверява, че нашият подклас може да се декларира без фатал: няма абстрактни методи извън тези, които
	 * дефинираме; тези, които предефинираме, нямат повече параметри/типове/return type; свойствата не са типизирани.
	 * '' = съвместимо, иначе причината.
	 */
	public static function fka_compatible() {
		try {
			$r = new \ReflectionClass( 'BWFAN_Merge_Tag' );
			$mine = array( 'parse_shortcode' => 2, 'get_dummy_preview' => 2, 'get_setting_schema' => 2, 'get_view' => 2 );
			foreach ( $r->getMethods() as $m ) {
				$n = $m->getName();
				if ( $m->isAbstract() && ! isset( $mine[ $n ] ) ) { return 'абстрактен ' . $n . '()'; }
				if ( ! isset( $mine[ $n ] ) ) { continue; }
				if ( $m->isStatic() || $m->isPrivate() || $m->isFinal() ) { return $n . '() е static/private/final'; }
				if ( $m->getNumberOfParameters() > $mine[ $n ] || $m->hasReturnType() ) { return $n . '() с друг подпис'; }
				foreach ( $m->getParameters() as $p ) { if ( $p->hasType() || $p->isPassedByReference() ) { return $n . '() с типизиран параметър'; } }
			}
			$ctor = $r->getConstructor(); if ( $ctor && ( $ctor->isFinal() || $ctor->isPrivate() ) ) { return 'конструкторът е final/private'; }
			foreach ( array( 'tag_name', 'tag_description', 'support_fallback', 'priority' ) as $pn ) {
				if ( $r->hasProperty( $pn ) ) { $p = $r->getProperty( $pn ); if ( $p->isStatic() || ( method_exists( $p, 'hasType' ) && $p->hasType() ) || ( method_exists( $p, 'isReadOnly' ) && $p->isReadOnly() ) ) { return 'свойството ' . $pn . ' е static/типизирано'; } }
			}
			if ( $r->isFinal() || ! $r->isInstantiable() && ! $r->isAbstract() ) { return 'класът е final/интерфейс'; }
		} catch ( \Throwable $e ) { return $e->getMessage(); }
		return '';
	}
}
