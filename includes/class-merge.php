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
 *  - ТАГ (тригърът за имейла): при завършен чекаут на промо поръчка контактът на клиента във FunnelKit получава тага
 *    `tickets.fka_tag` (дефолт „Сезонът на Анса“; празно = изключено). Слага се през BWFCRM_Contact (пуска event-а
 *    „Tag Added“ на FKA → автоматизацията тръгва само за тези хора, не за стандартния чекаут), резерва — направо в
 *    контакта (WooFunnels_Contact, без event, но правилата „Contact Tags“ го виждат). Идемпотентно (meta
 *    `_ansa_promo_fka_tag`), опитва се пак при processing/completed, ако контактът още го е нямало. В автоматизация
 *    без поръчка (Tag Added) merge таговете намират последната промо поръчка по имейла на контакта.
 *
 * @package AnsaPromo
 * @since 1.8.2
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Merge {

	/** key → примерна стойност за преглед на имейла в FKA. */
	const TAGS = array( 'tickets' => '5', 'bileta' => 'билета', 'tickets_text' => '', 'box' => 'Голяма кутия', 'book_url' => 'https://ansa.bg/kniga/', 'ref_link' => 'https://ansa.bg/sezonut-na-ansa/?r=XXXXXXXX', 'ref_code' => 'XXXXXXXX', 'fav' => 'Daily Greens', 'fav_url' => 'https://ansa.bg/' ); /* 1.9.0 book_url: линкът към книгата (/kniga), празно без книга в поръчката · 1.13.0 ref_link / ref_code: личният линк за препоръка на поръчката (работи от заявката, преди пратката) · 1.20.0 fav / fav_url: най-купуваният продукт на човека с КРАТКОТО име от картата (Favorite) — по имейла на контакта, не по поръчката */

	/** Състоянието на FKA регистрацията за Doctor/Probe: '' = още не е опитвано. */
	private static $status = '';

	public static function register() {
		foreach ( array_keys( self::TAGS ) as $k ) { add_shortcode( 'ansa_promo_' . $k, function ( $attr ) use ( $k ) { return self::shortcode( $k, $attr ); } ); }
		add_action( 'bwfan_merge_tags_loaded', array( __CLASS__, 'fka' ) );
		add_action( 'init', array( __CLASS__, 'fka' ), 99 ); /* резерва: ако тази версия на FKA няма hook-а или го е пуснала преди boot-а */
		if ( did_action( 'bwfan_merge_tags_loaded' ) ) { self::fka(); }
		/* тагът: след bwf контакта (woocommerce_checkout_order_processed 10 във Funnel Builder) + повторни опити при смяна на статуса */
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'tag_order' ), 9999 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'tag_order' ), 9999 );
		foreach ( array( 'on-hold', 'processing', 'completed' ) as $st ) { add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'tag_order' ), 25 ); }
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'tag_order' ), 25 );
		/* 1.8.3: тагът се създава веднага (админът го търси в FKA при настройката на автоматизацията, преди първата поръчка) */
		add_action( 'admin_init', array( __CLASS__, 'ensure_tag' ), 20 );
		add_action( 'ansa_promo_published', array( __CLASS__, 'ensure_tag' ), 20 );
	}

	/** Създава FKA тага по име, ако го няма (веднъж на име; option ansa_promo_fka_tag_ensured). Връща id или 0. */
	public static function ensure_tag() {
		$names = array_values( array_unique( array_merge( self::all_tag_names( Config::get_draft() ), self::all_tag_names() ) ) ); if ( ! $names ) { return 0; }
		$key = md5( implode( '|', $names ) ); $first = 0;
		if ( get_option( 'ansa_promo_fka_tag_ensured' ) === $key ) { return self::tag_id( $names[0] ); }
		if ( ! function_exists( 'bwf_get_contact' ) ) { return 0; }
		$all = true; foreach ( $names as $i => $n ) { $id = self::tag_id( $n, true ); if ( 0 === $i ) { $first = $id; } if ( $id <= 0 ) { $all = false; } }
		if ( $all ) { update_option( 'ansa_promo_fka_tag_ensured', $key, false ); }
		return $first;
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
		/* автоматизация без поръчка (Tag Added, …): последната промо поръчка по имейла на контакта */
		$email = self::fka_email();
		return '' !== $email ? self::latest_promo_order( $email ) : null;
	}

	/** Имейлът на контакта от данните на FKA (email / contact обект / wp user). */
	public static function fka_email() {
		foreach ( array( 'email', 'contact_email', 'user_email' ) as $k ) { $d = self::fka_data( $k ); if ( is_string( $d ) && is_email( $d ) ) { return strtolower( trim( $d ) ); } }
		$c = self::fka_data( 'contact' ); if ( ! is_object( $c ) ) { $c = self::fka_data( 'bwf_contact' ); }
		if ( is_object( $c ) ) {
			foreach ( array( $c, ( isset( $c->contact ) && is_object( $c->contact ) ? $c->contact : null ) ) as $obj ) {
				if ( ! $obj ) { continue; }
				try {
					if ( method_exists( $obj, 'get_email' ) ) { $e = $obj->get_email(); if ( is_string( $e ) && is_email( $e ) ) { return strtolower( trim( $e ) ); } }
					if ( isset( $obj->email ) && is_string( $obj->email ) && is_email( $obj->email ) ) { return strtolower( trim( $obj->email ) ); }
				} catch ( \Throwable $e ) {}
			}
		}
		foreach ( array( 'user_id', 'wp_user_id', 'wp_id' ) as $k ) { $d = self::fka_data( $k ); if ( is_numeric( $d ) && (int) $d > 0 ) { $u = get_userdata( (int) $d ); if ( $u && is_email( $u->user_email ) ) { return strtolower( trim( $u->user_email ) ); } } }
		return '';
	}

	/** Последната поръчка от играта с този billing имейл (или null). */
	public static function latest_promo_order( $email ) {
		foreach ( Tombola::orders_by_email( $email ) as $o ) { if ( is_array( $o->get_meta( '_ansa_promo' ) ) ) { return $o; } }
		return null;
	}

	/** Стойностите за поръчката: [tickets, bileta, tickets_text, box, book_url, ref_link, ref_code]; tickets=0 и tickets_text='' извън играта. */
	public static function values( $order ) {
		$out = array( 'tickets' => 0, 'bileta' => 'билета', 'tickets_text' => '', 'box' => '', 'book_url' => '', 'ref_link' => '', 'ref_code' => '' );
		if ( ! $order instanceof \WC_Order ) { return $out; }
		$n = Tickets::count_for_order( $order );
		$out['tickets'] = $n; $out['bileta'] = 1 === $n ? 'билет' : 'билета';
		$ev = Tickets::email_vars( $order );
		if ( $ev ) {
			$out['box'] = (string) $ev['vars']['box'];
			$out['ref_link'] = (string) $ev['vars']['ref_link']; $out['ref_code'] = (string) $ev['vars']['ref_code']; /* 1.13.0: от билета или от order meta (дава се при заявката) */
			if ( Book::order_has_book( $order, $ev['cfg'] ) ) { $out['book_url'] = (string) $ev['vars']['book_url']; }
			if ( $n > 0 ) { $copy = Copy::effective( $ev['cfg']['copy'] ); $out['tickets_text'] = Copy::render( $copy['em.fk.tickets'] ?? '', $ev['vars'] ); }
		}
		return apply_filters( 'ansa_promo_merge_values', $out, $order );
	}

	/** Примерните стойности за прегледа на имейла в FKA (без поръчка). */
	public static function dummy( $key ) {
		if ( in_array( $key, Favorite::TAGS, true ) ) { return Favorite::dummy( $key ); } /* 1.20.0 */
		if ( 'book_url' === $key ) { return Book::link( Config::get_published() ) ?: self::TAGS['book_url']; }
		if ( 'ref_link' === $key ) { $cfg = Config::get_published(); return Tombola::enabled( $cfg ) ? Tombola::ref_link( self::TAGS['ref_code'], $cfg ) : self::TAGS['ref_link']; }
		if ( 'tickets_text' !== $key ) { return (string) ( self::TAGS[ $key ] ?? '' ); }
		$cfg = Config::get_published(); $box = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][0]; $v = Cart::vars( $cfg, $box );
		$v = array_merge( $v, array( 'customer' => 'Мария', 'order' => '12345', 'code' => 'XXXX-XXXX-XXXX', 'tickets' => (int) $box['tickets'], 'bileta' => 1 === (int) $box['tickets'] ? 'билет' : 'билета', 'portal_url' => self::portal_url( $cfg ), 'tombola_url' => Tombola::enabled( $cfg ) ? Tombola::url( $cfg ) : '' ) );
		return Copy::render( Copy::effective( $cfg['copy'] )['em.fk.tickets'] ?? '', $v );
	}

	/** Адресът, на който се активират билетите: tickets.portal_url, иначе томболата, иначе промо страницата. */
	public static function portal_url( $cfg ) { return $cfg['tickets']['portal_url'] ?: ( Tombola::enabled( $cfg ) ? Tombola::url( $cfg ) : Cart::promo_url( $cfg ) ); }

	/** Шорткодът [ansa_promo_<key> order_id="" fallback=""] — и ядрото на FKA таговете. */
	public static function shortcode( $key, $attr = array() ) {
		$attr = shortcode_atts( array( 'order_id' => 0, 'fallback' => '', 'email' => '' ), is_array( $attr ) ? $attr : array() );
		if ( empty( $attr['order_id'] ) && self::is_preview() ) { return self::dummy( $key ); }
		if ( in_array( $key, Favorite::TAGS, true ) ) { $val = self::favorite( $key, $attr ); return '' !== $val ? $val : (string) $attr['fallback']; } /* 1.20.0 */
		$v = self::values( self::order( $attr ) );
		$val = (string) ( $v[ $key ] ?? '' );
		if ( 'tickets' === $key && 0 === (int) $v['tickets'] ) { $val = ''; }
		return '' !== $val ? $val : ( '' !== (string) $attr['fallback'] ? (string) $attr['fallback'] : ( 'tickets' === $key ? '0' : '' ) );
	}

	/**
	 * 1.20.0: {{ansa_promo_fav}} / {{ansa_promo_fav_url}} — човекът, не поръчката: имейлът на FKA контакта (broadcast, Tag Added, …);
	 * при WC event без контакт в данните — billing имейлът на поръчката; order_id="" / email="" (само с manage_woocommerce) за тестове.
	 */
	public static function favorite( $key, $attr = array() ) {
		$attr = is_array( $attr ) ? $attr : array(); $email = ''; $uid = 0;
		if ( ! empty( $attr['email'] ) && function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) && is_email( (string) $attr['email'] ) ) { $email = (string) $attr['email']; }
		if ( '' === $email && ! empty( $attr['order_id'] ) && function_exists( 'wc_get_order' ) ) { $o = wc_get_order( (int) $attr['order_id'] ); if ( $o ) { $email = (string) $o->get_billing_email(); $uid = (int) $o->get_customer_id(); } }
		if ( '' === $email ) { $email = self::fka_email(); }
		if ( '' === $email ) { $o = self::order( array() ); if ( $o ) { $email = (string) $o->get_billing_email(); $uid = (int) $o->get_customer_id(); } }
		if ( '' === $email && $uid <= 0 ) { return ''; }
		return Favorite::value( $key, $email, $uid );
	}

	/* ── тагът „Сезонът на Анса“ на контакта във FunnelKit ── */

	public static function tag_name( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return trim( (string) ( $cfg['tickets']['fka_tag'] ?? '' ) ); }
	/** 1.8.8: тагът за кутията (tickets.fka_tag_box[s|m|l] → malka-kutiq / sredna-kutiq / golqma-kutiq); '' = без. */
	public static function box_tag_name( $cfg, $box_id ) { $bt = $cfg['tickets']['fka_tag_box'] ?? array(); return trim( (string) ( is_array( $bt ) ? ( $bt[ (string) $box_id ] ?? '' ) : '' ) ); }
	/** Всички тагове, които поръчка от тази кутия трябва да има: общият + този на кутията (без празни, без дубли). */
	public static function tag_names_for( $cfg, $box_id ) { return array_values( array_unique( array_filter( array( self::tag_name( $cfg ), self::box_tag_name( $cfg, $box_id ) ) ) ) ); }
	/** Всички имена на тагове от конфига (за ensure_tag / Doctor). */
	public static function all_tag_names( $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $n = array( self::tag_name( $cfg ) );
		foreach ( (array) ( $cfg['tickets']['fka_tag_box'] ?? array() ) as $v ) { $n[] = trim( (string) $v ); }
		return array_values( array_unique( array_filter( $n ) ) );
	}

	/**
	 * Слага таговете (общия + на кутията) на контакта на промо поръчка. Идемпотентно: meta `_ansa_promo_fka_tags` = сложените
	 * имена (масив), `_ansa_promo_fka_tag` = четим низ „таг #id · crm|db|има; …“ или „retry: …“. При липсващи FunnelKit
	 * функции / контакт / таг се опитва пак на следващия hook. Връща 'crm' | 'db' | 'има' | 'retry' | 'off' | ….
	 */
	public static function tag_order( $order_id ) {
		$order = is_object( $order_id ) ? $order_id : ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null );
		if ( ! $order || ! method_exists( $order, 'get_meta' ) ) { return 'no-order'; }
		$m = $order->get_meta( '_ansa_promo' ); if ( ! is_array( $m ) ) { return 'not-promo'; }
		$cfg = ! empty( $m['draft'] ) ? Config::get_draft() : Config::get_published();
		$names = self::tag_names_for( $cfg, (string) ( $m['box'] ?? '' ) ); if ( ! $names ) { return 'off'; }
		$done = array_values( array_filter( array_map( 'strval', (array) $order->get_meta( '_ansa_promo_fka_tags' ) ) ) );
		$todo = array_values( array_diff( $names, $done ) );
		if ( ! $todo ) { return 'има'; }
		if ( ! function_exists( 'bwf_get_contact' ) || ! class_exists( 'WooFunnels_Contact' ) ) { return 'no-fk'; }
		$email = (string) $order->get_billing_email(); if ( ! is_email( $email ) ) { return 'no-email'; }
		$prev = (string) $order->get_meta( '_ansa_promo_fka_tag' ); $parts = ( '' !== $prev && 0 !== strpos( $prev, 'retry' ) ) ? array( $prev ) : array(); $hows = array();
		$retry = function ( $why ) use ( $order, &$done, &$parts ) { $order->update_meta_data( '_ansa_promo_fka_tags', $done ); $order->update_meta_data( '_ansa_promo_fka_tag', ( $parts ? implode( '; ', $parts ) . '; ' : '' ) . 'retry: ' . $why ); $order->save(); return 'retry'; };
		try {
			$contact = bwf_get_contact( (int) $order->get_customer_id(), $email );
			if ( ! $contact instanceof \WooFunnels_Contact || $contact->get_id() <= 0 ) {
				/* контактът още го няма (hook-ът на Funnel Builder не е минал) → създаваме го като него */
				if ( function_exists( 'bwf_create_update_contact_object' ) ) { $contact = bwf_create_update_contact_object( $contact instanceof \WooFunnels_Contact ? $contact : new \WooFunnels_Contact( '', $email ), $order ); $contact->save(); }
				$contact = bwf_get_contact( (int) $order->get_customer_id(), $email );
				if ( ! $contact instanceof \WooFunnels_Contact || $contact->get_id() <= 0 ) { return $retry( 'няма контакт' ); }
			}
			foreach ( $todo as $name ) {
				$tid = self::tag_id( $name, true ); if ( $tid <= 0 ) { return $retry( 'няма таг „' . $name . '“' ); }
				$had = array_map( 'intval', (array) ( new \WooFunnels_Contact( '', $email ) )->get_tags() );
				$how = in_array( $tid, $had, true ) ? 'има' : self::tag_contact( $contact, $tid, $name, $email );
				if ( '' === $how ) { return $retry( 'не се записа „' . $name . '“' ); }
				$done[] = $name; $parts[] = $name . ' #' . $tid . ' · ' . $how; $hows[] = $how;
				if ( 'има' !== $how ) { $order->add_order_note( 'ansa™ Промо: FunnelKit таг „' . $name . '“ на контакт #' . $contact->get_id() . ' (' . ( 'crm' === $how ? 'през FKA, event „Tag Added“' : 'направо в контакта' ) . ').' ); }
				do_action( 'ansa_promo_fka_tagged', $order, $contact, $tid, $how, $name );
			}
		} catch ( \Throwable $e ) { return $retry( $e->getMessage() ); }
		$order->update_meta_data( '_ansa_promo_fka_tags', array_values( array_unique( $done ) ) ); $order->update_meta_data( '_ansa_promo_fka_tag', implode( '; ', $parts ) ); $order->save();
		return in_array( 'crm', $hows, true ) ? 'crm' : ( in_array( 'db', $hows, true ) ? 'db' : 'има' );
	}

	/** 1.8.4: промо поръчки без тага (без отменени/възстановени/неуспешни/кошче) — старите поръчки отпреди 1.8.2. */
	public static function untagged( $limit = 500 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return array(); }
		$st = array_values( array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-trash' ) ) );
		$out = array();
		foreach ( wc_get_orders( array( 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'status' => $st, 'meta_key' => '_ansa_promo_id', 'meta_compare' => 'EXISTS' ) ) as $o ) {
			$m = $o->get_meta( '_ansa_promo' ); if ( ! is_object( $o ) || ! is_array( $m ) ) { continue; }
			$cfg = ! empty( $m['draft'] ) ? Config::get_draft() : Config::get_published();
			$done = array_map( 'strval', (array) $o->get_meta( '_ansa_promo_fka_tags' ) );
			if ( array_diff( self::tag_names_for( $cfg, (string) ( $m['box'] ?? '' ) ), $done ) ) { $out[] = $o; }
		}
		return $out;
	}
	/** Слага тага на старите промо поръчки (бутонът в 🎟️ Поръчки). → брой по резултат: crm / db / има / retry / other. */
	public static function backfill( $limit = 500 ) {
		$r = array( 'crm' => 0, 'db' => 0, 'има' => 0, 'retry' => 0, 'other' => 0 );
		foreach ( self::untagged( $limit ) as $o ) { $k = (string) self::tag_order( $o ); if ( isset( $r[ $k ] ) ) { $r[ $k ]++; } else { $r['other']++; } }
		return $r;
	}

	/** Добавя тага: през BWFCRM_Contact (пуска event-а „Tag Added“), иначе направо в WooFunnels_Contact. 'crm' | 'db' | ''. */
	private static function tag_contact( $contact, $tid, $name, $email ) {
		$has = function () use ( $email, $tid ) { try { $f = new \WooFunnels_Contact( '', $email ); return $f->get_id() > 0 && in_array( $tid, array_map( 'intval', (array) $f->get_tags() ), true ); } catch ( \Throwable $e ) { return false; } };
		if ( class_exists( 'BWFCRM_Contact' ) ) {
			foreach ( array( $contact, $email, (int) $contact->get_id() ) as $ctor ) {
				foreach ( array( array( $tid ), array( array( 'id' => $tid, 'name' => $name ) ) ) as $tags ) {
					try {
						$c = new \BWFCRM_Contact( $ctor );
						if ( ! method_exists( $c, 'add_tags' ) ) { break 2; }
						$c->add_tags( $tags );
						if ( method_exists( $c, 'save' ) ) { $c->save(); }
						if ( $has() ) { return 'crm'; }
					} catch ( \Throwable $e ) { /* следващият вариант */ }
				}
			}
		}
		try { $contact->set_tags( array_values( array_unique( array_merge( array_map( 'intval', (array) $contact->get_tags() ), array( $tid ) ) ) ) ); $contact->save(); if ( $has() ) { return 'db'; } } catch ( \Throwable $e ) {}
		return '';
	}

	/** Диагностика на последния опит за тага (Doctor). */
	public static $tag_diag = '';

	/**
	 * ID на FKA тага по име. Търси: BWFCRM_Tag::get_tags → таблицата с термините (bwfan_terms / bwf_terms, type 1 = таг, 2 = лист).
	 * $create: създава го — през API на FKA (BWFCRM_Tag/BWFCRM_Term create*), през REST-а на FKA (autonami…/v1/tags), накрая
	 * направо в таблицата. 0 = няма; причината е в self::$tag_diag.
	 */
	public static function tag_id( $name, $create = false ) { return self::term_id( $name, 1, $create ); }
	/** 1.11.0: ID на FKA лист по име (type 2 в термините) — същите пътища като тага; за лийдовете от имейл-попъпа (Lead_Sync). */
	public static function list_id( $name, $create = false ) { return self::term_id( $name, 2, $create ); }
	/** Общото: $type 1 = таг, 2 = лист. */
	public static function term_id( $name, $type = 1, $create = false ) {
		$name = trim( (string) $name ); $type = 2 === (int) $type ? 2 : 1; if ( '' === $name ) { return 0; }
		static $cache = array(); $ck = $type . '|' . $name; if ( ! empty( $cache[ $ck ] ) ) { return $cache[ $ck ]; }
		$diag = array();
		$id = self::term_find( $name, $diag, $type );
		if ( $id <= 0 && $create ) {
			$id = self::term_create_api( $name, $diag, $type ); if ( $id <= 0 ) { $id = self::term_find( $name, $diag, $type ); }
			if ( $id <= 0 ) { $id = self::term_create_rest( $name, $diag, $type ); if ( $id <= 0 ) { $id = self::term_find( $name, $diag, $type ); } }
			if ( $id <= 0 ) { $id = self::term_create_db( $name, $diag, $type ); if ( $id <= 0 ) { $id = self::term_find( $name, $diag, $type ); } }
		}
		self::$tag_diag = implode( ' · ', $diag );
		if ( $id > 0 ) { $cache[ $ck ] = $id; }
		return $id;
	}
	/** 1.11.0: има ли контактът (по имейл) този term — $kind 'tags' | 'lists'. */
	public static function contact_has( $email, $kind, $tid ) {
		try { $f = new \WooFunnels_Contact( '', $email ); if ( $f->get_id() <= 0 ) { return false; } $cur = 'lists' === $kind ? $f->get_lists() : $f->get_tags(); return in_array( (int) $tid, array_map( 'intval', (array) $cur ), true ); } catch ( \Throwable $e ) { return false; }
	}

	/** Таблицата с термините на FKA ({prefix}bwfan_terms или {prefix}bwf_terms — която има колони name + type). '' = няма. */
	public static function terms_table() {
		global $wpdb; static $t = null; if ( null !== $t ) { return $t; }
		$t = '';
		foreach ( array( $wpdb->prefix . 'bwfan_terms', $wpdb->prefix . 'bwf_terms' ) as $cand ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand ) ) !== $cand ) { continue; }
			$cols = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM $cand" ) );
			if ( in_array( 'name', $cols, true ) && in_array( 'type', $cols, true ) ) { $t = $cand; break; }
		}
		return $t;
	}

	/** Класовете на FKA за типа: таг → BWFCRM_Tag, лист → BWFCRM_Lists / BWFCRM_List (+ BWFCRM_Term и за двата). */
	private static function term_classes( $type ) { return 2 === (int) $type ? array( 'BWFCRM_Lists', 'BWFCRM_List', 'BWFCRM_Term' ) : array( 'BWFCRM_Tag', 'BWFCRM_Term' ); }
	private static function term_find( $name, &$diag, $type = 1 ) {
		global $wpdb; $type = 2 === (int) $type ? 2 : 1;
		$getter = null; foreach ( self::term_classes( $type ) as $cls ) { foreach ( array( 2 === $type ? 'get_lists' : 'get_tags', 'get_terms', 'get_all' ) as $mn ) { if ( class_exists( $cls ) && method_exists( $cls, $mn ) ) { $getter = array( $cls, $mn ); break 2; } } }
		if ( $getter ) {
			try {
				$all = (array) call_user_func( $getter, array(), false, '', '' ); $diag[] = $getter[0] . '::' . $getter[1] . ': ' . count( $all );
				foreach ( $all as $t ) { $t = is_object( $t ) ? (array) $t : $t; if ( is_array( $t ) && isset( $t['name'] ) && 0 === strcasecmp( trim( (string) $t['name'] ), $name ) && ( ! isset( $t['type'] ) || (int) $t['type'] === $type ) ) { $id = (int) ( $t['ID'] ?? $t['id'] ?? 0 ); if ( $id > 0 ) { return $id; } } }
			} catch ( \Throwable $e ) { $diag[] = $getter[0] . '::' . $getter[1] . ': ' . $e->getMessage(); }
		} else { $diag[] = 'няма ' . ( 2 === $type ? 'BWFCRM_Lists::get_lists' : 'BWFCRM_Tag::get_tags' ); }
		$table = self::terms_table();
		if ( '' === $table ) { $diag[] = 'няма таблица *_terms'; return 0; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE type = %d AND name = %s ORDER BY 1 ASC LIMIT 1", $type, $name ), ARRAY_A );
		if ( ! $row ) { $diag[] = 'в ' . $table . ' няма „' . $name . '“'; return 0; }
		$row = array_change_key_case( $row, CASE_LOWER );
		return (int) ( $row['id'] ?? 0 );
	}

	/** Създаване през статичен метод на FKA (BWFCRM_Tag / BWFCRM_Term: create, create_tag, create_term, add, add_tag, insert, get_or_create). */
	private static function term_create_api( $name, &$diag, $type = 1 ) {
		$type = 2 === (int) $type ? 2 : 1;
		foreach ( self::term_classes( $type ) as $cls ) {
			if ( ! class_exists( $cls ) ) { continue; }
			foreach ( ( 2 === $type ? array( 'create', 'create_list', 'create_term', 'add', 'add_list', 'insert', 'get_or_create' ) : array( 'create', 'create_tag', 'create_term', 'add', 'add_tag', 'insert', 'get_or_create' ) ) as $mn ) {
				if ( ! method_exists( $cls, $mn ) ) { continue; }
				try {
					$rm = new \ReflectionMethod( $cls, $mn ); if ( ! $rm->isStatic() || ! $rm->isPublic() ) { continue; }
					$argsets = array( array( $name ), array( array( 'name' => $name ) ), array( $name, $type ), array( array( 'name' => $name, 'type' => $type ) ) );
					foreach ( $argsets as $args ) {
						if ( count( $args ) < $rm->getNumberOfRequiredParameters() ) { continue; }
						$r = $rm->invokeArgs( null, $args ); $diag[] = $cls . '::' . $mn . ' → ' . ( is_scalar( $r ) ? var_export( $r, true ) : gettype( $r ) );
						$id = 0; if ( is_numeric( $r ) ) { $id = (int) $r; } elseif ( is_array( $r ) ) { $r2 = array_change_key_case( $r, CASE_LOWER ); $id = (int) ( $r2['id'] ?? 0 ); } elseif ( is_object( $r ) ) { foreach ( array( 'ID', 'id' ) as $k ) { if ( isset( $r->$k ) ) { $id = (int) $r->$k; break; } } if ( ! $id && method_exists( $r, 'get_id' ) ) { $id = (int) $r->get_id(); } }
						if ( $id > 0 ) { return $id; }
						$d2 = array(); $id = self::term_find( $name, $d2, $type ); if ( $id > 0 ) { return $id; }
					}
				} catch ( \Throwable $e ) { $diag[] = $cls . '::' . $mn . ': ' . $e->getMessage(); }
			}
		}
		return 0;
	}

	/** Създаване през REST-а на FKA (POST /autonami…/v1/tags {name}) — само с права (admin_init / Doctor). */
	private static function term_create_rest( $name, &$diag, $type = 1 ) {
		if ( ! function_exists( 'rest_get_server' ) || ! current_user_can( 'manage_options' ) ) { return 0; }
		$type = 2 === (int) $type ? 2 : 1;
		try {
			foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
				if ( ! preg_match( 2 === $type ? '#^/autonami[a-z\-]*/v1/lists?$#' : '#^/autonami[a-z\-]*/v1/tags?$#', $route ) ) { continue; }
				$req = new \WP_REST_Request( 'POST', $route ); $req->set_body_params( array( 'name' => $name, ( 2 === $type ? 'list_name' : 'tag_name' ) => $name, 'type' => $type ) );
				$res = rest_do_request( $req ); $diag[] = 'REST ' . $route . ' → ' . $res->get_status();
				$d2 = array(); $id = self::term_find( $name, $d2, $type ); if ( $id > 0 ) { return $id; }
			}
		} catch ( \Throwable $e ) { $diag[] = 'REST: ' . $e->getMessage(); }
		return 0;
	}

	/** Създаване направо в таблицата: name + type=1, а задължителните колони без default се попълват според типа си. */
	private static function term_create_db( $name, &$diag, $type = 1 ) {
		global $wpdb; $table = self::terms_table(); if ( '' === $table ) { return 0; }
		$row = array( 'name' => $name, 'type' => 2 === (int) $type ? 2 : 1 ); $fmt = array( '%s', '%d' );
		foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM $table", ARRAY_A ) as $c ) {
			$f = strtolower( (string) ( $c['Field'] ?? '' ) ); $ty = strtolower( (string) ( $c['Type'] ?? '' ) );
			if ( in_array( $f, array( 'id', 'name', 'type' ), true ) || false !== stripos( (string) ( $c['Extra'] ?? '' ), 'auto_increment' ) ) { continue; }
			$req = 'NO' === strtoupper( (string) ( $c['Null'] ?? '' ) ) && null === ( $c['Default'] ?? null );
			if ( ! $req && ! in_array( $f, array( 'created_at', 'updated_at', 'created_date', 'date_created' ), true ) ) { continue; }
			if ( false !== strpos( $ty, 'int' ) || false !== strpos( $ty, 'decimal' ) || false !== strpos( $ty, 'float' ) ) { $row[ $f ] = 0; $fmt[] = '%d'; }
			elseif ( false !== strpos( $ty, 'date' ) || false !== strpos( $ty, 'time' ) ) { $row[ $f ] = current_time( 'mysql' ); $fmt[] = '%s'; }
			else { $row[ $f ] = ''; $fmt[] = '%s'; }
		}
		$ok = $wpdb->insert( $table, $row, $fmt ); $diag[] = 'insert ' . $table . ' → ' . ( $ok ? '#' . (int) $wpdb->insert_id : 'грешка: ' . $wpdb->last_error );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** За Doctor: „таг „X“ #id“ / „таг „X“ — още не е създаден“ / „таг: изключен“. */
	public static function tag_status( $cfg = null ) {
		$names = self::all_tag_names( $cfg ); if ( ! $names ) { return 'таг: изключен'; }
		if ( ! function_exists( 'bwf_get_contact' ) ) { return 'тагове „' . implode( '“, „', $names ) . '“ — FunnelKit контакти липсват'; }
		$out = array();
		foreach ( $names as $n ) { $id = self::tag_id( $n ); $out[] = '„' . $n . '“' . ( $id > 0 ? ' #' . $id : ' НЕ Е СЪЗДАДЕН (' . ( self::$tag_diag ?: 'без диагностика' ) . ')' ); }
		return 'тагове: ' . implode( ', ', $out ) . ( self::terms_table() ? ' в ' . self::terms_table() : '' );
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
		foreach ( array( 'Ansa_Promo_FKA_Tickets', 'Ansa_Promo_FKA_Bileta', 'Ansa_Promo_FKA_Tickets_Text', 'Ansa_Promo_FKA_Box', 'Ansa_Promo_FKA_Book_Url', 'Ansa_Promo_FKA_Ref_Link', 'Ansa_Promo_FKA_Ref_Code', 'Ansa_Promo_FKA_Fav', 'Ansa_Promo_FKA_Fav_Url' ) as $c ) {
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
