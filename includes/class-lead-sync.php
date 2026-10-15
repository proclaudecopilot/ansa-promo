<?php
/**
 * ansa™ Промо — лийдовете от имейл-попъпа → FunnelKit Automations (v1.11.0, искане на човека).
 *
 * Имейлът от попъпа в началото на промото (Leads::capture) досега стоеше САМО в {prefix}ansa_promo_leads. Тук всеки лийд
 * става контакт във FunnelKit (и гост без WP акаунт — WooFunnels_Contact по имейл, type lead, source ansa-promo, status 1 =
 * subscribed при съгласие) и влиза в ЛИСТ (`leads.list`, по подразбиране „Сезонът на ansa — лийдове“) и получава ТАГ
 * (`leads.tag`, по подразбиране `sezon-lead`). Листът и тагът се слагат през BWFCRM_Contact (add_lists / add_tags → FKA
 * пуска event-ите „List Added“ / „Tag Added“ → автоматизацията тръгва), резерва — направо в контакта (WooFunnels_Contact).
 *
 * При промо поръчка (ansa_promo_order_created + повторни опити при смяна на статуса) тагът ПАДА от контакта на billing
 * имейла и на имейла от попъпа (гостът може да е дал друг в чекаута), а лийдовете с този имейл стават конвертирани — така
 * автоматизацията „не е завършила поръчката“ спира (условие „Contact Tags“ / goal „Tag Removed“) или не тръгва. Листът остава
 * (той е „всички, минали през попъпа“). Поръчката получава своите тагове от Merge::tag_order (1.8.2/1.8.8).
 *
 * Резултатът е в leads.fka („лист #1 · crm; таг #2 · crm“ / „retry: …“ / „no-consent“), contact_id, fka_at. Бутонът
 * „📨 Синхронизирай N лийда“ (🧰 Инструменти) минава през неминалите/retry (Leads::unsynced). Листът и тагът се създават при
 * admin_init / „Публикувай“ (ensure_terms), за да се виждат във FKA преди първия лийд.
 *
 * Настройка във FKA (Automations → New): тригър „Tag Added“ (sezon-lead) или „Contact Added to List“ → Delay → условие
 * „Contact Tags — does not have sezon-lead“ (т.е. не е поръчала междувременно) → Send Email; или goal „Order Created“.
 *
 * @package AnsaPromo
 * @since 1.11.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Lead_Sync {

	/** Диагностика на последния опит (Doctor / Probe). */
	public static $diag = '';

	public static function register() {
		add_action( 'ansa_promo_lead_captured', array( __CLASS__, 'on_lead' ), 10, 4 );
		add_action( 'ansa_promo_order_created', array( __CLASS__, 'on_order' ), 20, 2 );
		foreach ( array( 'on-hold', 'processing', 'completed' ) as $st ) { add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'on_order_id' ), 26 ); }
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_order_id' ), 26 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_terms' ), 21 );
		add_action( 'ansa_promo_published', array( __CLASS__, 'ensure_terms' ), 21 );
	}

	/* ── конфиг ── */
	public static function cfg( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); $l = $cfg['leads'] ?? null; return is_array( $l ) ? $l : Config::defaults()['leads']; }
	public static function enabled( $cfg = null ) { $l = self::cfg( $cfg ); return ! empty( $l['fka'] ) && ( '' !== (string) $l['list'] || '' !== (string) $l['tag'] ); }
	/** FunnelKit контактите (Funnel Builder) са тук. Automations (BWFCRM_*) е по избор — без него термините отиват направо в контакта. */
	public static function available() { return function_exists( 'bwf_get_contact' ) && class_exists( 'WooFunnels_Contact' ); }

	/** Създава листа и тага по име, ако ги няма (веднъж на двойка имена; option ansa_promo_fka_leads_ensured). */
	public static function ensure_terms() {
		$names = array();
		foreach ( array( Config::get_draft(), Config::get_published() ) as $c ) { $l = self::cfg( $c ); if ( empty( $l['fka'] ) ) { continue; } if ( '' !== $l['list'] ) { $names[ 'l|' . $l['list'] ] = array( 2, $l['list'] ); } if ( '' !== $l['tag'] ) { $names[ 't|' . $l['tag'] ] = array( 1, $l['tag'] ); } }
		if ( ! $names ) { return true; }
		$key = md5( implode( '|', array_keys( $names ) ) );
		if ( get_option( 'ansa_promo_fka_leads_ensured' ) === $key ) { return true; }
		if ( ! self::available() ) { return false; }
		$all = true; foreach ( $names as $n ) { if ( Merge::term_id( $n[1], $n[0], true ) <= 0 ) { $all = false; } }
		if ( $all ) { update_option( 'ansa_promo_fka_leads_ensured', $key, false ); }
		return $all;
	}

	/* ── лийд → FunnelKit ── */
	public static function on_lead( $lead_id, $email, $consent, $utm ) { self::sync( (int) $lead_id, $email, (bool) $consent ); }

	/**
	 * Синхронизира един лийд: контакт → лист → таг (тагът не се слага на вече конвертиран лийд). Връща четимия резултат,
	 * който отива и в leads.fka: „лист #1 · crm; таг #2 · crm“ | „има“ | „off“ | „no-consent“ | „no-email“ | „retry: причина“.
	 */
	public static function sync( $lead_id, $email, $consent, $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $l = self::cfg( $cfg );
		$email = strtolower( sanitize_email( (string) $email ) ); if ( ! is_email( $email ) ) { return self::mark( $lead_id, 0, 'no-email' ); }
		if ( ! self::enabled( $cfg ) ) { return self::mark( $lead_id, 0, 'off' ); }
		if ( ! empty( $l['consent_only'] ) && ! $consent ) { return self::mark( $lead_id, 0, 'no-consent' ); }
		if ( ! self::available() ) { return self::mark( $lead_id, 0, 'retry: FunnelKit контакти липсват' ); }
		$lead = $lead_id ? Leads::get( $lead_id ) : null; $converted = $lead && ! empty( $lead['converted_order_id'] );
		try {
			$contact = self::contact( $email, (bool) $consent );
			if ( ! $contact ) { return self::mark( $lead_id, 0, 'retry: няма контакт' ); }
			$cid = (int) $contact->get_id(); $parts = array();
			if ( '' !== $l['list'] ) {
				$lid = Merge::list_id( $l['list'], true );
				if ( $lid <= 0 ) { return self::mark( $lead_id, $cid, 'retry: няма лист „' . $l['list'] . '“ (' . ( Merge::$tag_diag ?: 'без диагностика' ) . ')' ); }
				$how = self::attach( $contact, $email, 'lists', $lid, $l['list'] );
				if ( '' === $how ) { return self::mark( $lead_id, $cid, 'retry: листът не се записа' ); }
				$parts[] = 'лист #' . $lid . ' · ' . $how;
			}
			if ( '' !== $l['tag'] ) {
				if ( $converted ) { $parts[] = 'таг: конвертиран'; }
				else {
					$tid = Merge::tag_id( $l['tag'], true );
					if ( $tid <= 0 ) { return self::mark( $lead_id, $cid, 'retry: няма таг „' . $l['tag'] . '“ (' . ( Merge::$tag_diag ?: 'без диагностика' ) . ')' ); }
					$how = self::attach( $contact, $email, 'tags', $tid, $l['tag'] );
					if ( '' === $how ) { return self::mark( $lead_id, $cid, 'retry: тагът не се записа' ); }
					$parts[] = 'таг #' . $tid . ' · ' . $how;
				}
			}
			do_action( 'ansa_promo_lead_synced', $lead_id, $contact, $parts, $email );
			return self::mark( $lead_id, $cid, $parts ? implode( '; ', $parts ) : 'ok' );
		} catch ( \Throwable $e ) { return self::mark( $lead_id, 0, 'retry: ' . $e->getMessage() ); }
	}
	private static function mark( $lead_id, $cid, $txt ) { if ( $lead_id ) { Leads::mark_fka( $lead_id, $cid, $txt ); } self::$diag = $txt; return $txt; }

	/** Контактът по имейл (WP потребител → с wpid); гост без контакт се създава: type lead, source ansa-promo, status 1 при съгласие. */
	public static function contact( $email, $consent = true ) {
		$email = strtolower( sanitize_email( (string) $email ) ); if ( ! is_email( $email ) || ! self::available() ) { return null; }
		$wpid = (int) email_exists( $email );
		$c = bwf_get_contact( $wpid, $email );
		if ( $c instanceof \WooFunnels_Contact && $c->get_id() > 0 ) { return $c; }
		$c = new \WooFunnels_Contact( $wpid ?: '', $email );
		if ( $c->get_id() > 0 ) { return $c; }
		$c->set_email( $email ); if ( $wpid > 0 ) { $c->set_wpid( $wpid ); }
		$c->set_creation_date( current_time( 'mysql' ) ); $c->set_source( 'ansa-promo' ); $c->set_type( 'lead' );
		if ( $consent ) { $c->set_status( 1 ); }
		$c->save();
		$c = bwf_get_contact( $wpid, $email );
		return ( $c instanceof \WooFunnels_Contact && $c->get_id() > 0 ) ? $c : null;
	}

	/** Слага term ($kind 'lists'|'tags') на контакта: през BWFCRM_Contact (event на FKA), иначе направо. 'crm' | 'db' | 'има' | ''. */
	private static function attach( $contact, $email, $kind, $tid, $name ) {
		if ( Merge::contact_has( $email, $kind, $tid ) ) { return 'има'; }
		if ( class_exists( 'BWFCRM_Contact' ) ) {
			$mn = 'add_' . $kind;
			foreach ( array( $contact, $email, (int) $contact->get_id() ) as $ctor ) {
				foreach ( array( array( $tid ), array( array( 'id' => $tid, 'name' => $name ) ) ) as $terms ) {
					try {
						$c = new \BWFCRM_Contact( $ctor );
						if ( ! method_exists( $c, $mn ) ) { break 2; }
						$c->$mn( $terms );
						if ( method_exists( $c, 'save' ) ) { $c->save(); }
						if ( Merge::contact_has( $email, $kind, $tid ) ) { return 'crm'; }
					} catch ( \Throwable $e ) { /* следващият вариант */ }
				}
			}
		}
		try {
			$f = new \WooFunnels_Contact( '', $email ); if ( $f->get_id() <= 0 ) { return ''; }
			$cur = array_map( 'intval', (array) ( 'lists' === $kind ? $f->get_lists() : $f->get_tags() ) );
			$new = array_values( array_unique( array_merge( $cur, array( (int) $tid ) ) ) );
			if ( 'lists' === $kind ) { $f->set_lists( $new ); } else { $f->set_tags( $new ); }
			$f->save();
			if ( Merge::contact_has( $email, $kind, $tid ) ) { return 'db'; }
		} catch ( \Throwable $e ) {}
		return '';
	}

	/** Маха term от контакта: през BWFCRM_Contact (remove_tags / remove_lists → event на FKA), иначе направо. 'crm' | 'db' | 'няма' | ''. */
	private static function detach( $email, $kind, $tid ) {
		if ( ! Merge::contact_has( $email, $kind, $tid ) ) { return 'няма'; }
		if ( class_exists( 'BWFCRM_Contact' ) ) {
			$mn = 'remove_' . $kind;
			foreach ( array( $email ) as $ctor ) {
				foreach ( array( array( $tid ), array( array( 'id' => $tid ) ) ) as $terms ) {
					try {
						$c = new \BWFCRM_Contact( $ctor );
						if ( ! method_exists( $c, $mn ) ) { break 2; }
						$c->$mn( $terms );
						if ( method_exists( $c, 'save' ) ) { $c->save(); }
						if ( ! Merge::contact_has( $email, $kind, $tid ) ) { return 'crm'; }
					} catch ( \Throwable $e ) {}
				}
			}
		}
		try {
			$f = new \WooFunnels_Contact( '', $email ); if ( $f->get_id() <= 0 ) { return 'няма'; }
			$cur = array_map( 'intval', (array) ( 'lists' === $kind ? $f->get_lists() : $f->get_tags() ) );
			$new = array_values( array_diff( $cur, array( (int) $tid ) ) );
			if ( 'lists' === $kind ) { $f->set_lists( $new ); } else { $f->set_tags( $new ); }
			$f->save();
			if ( ! Merge::contact_has( $email, $kind, $tid ) ) { return 'db'; }
		} catch ( \Throwable $e ) {}
		return '';
	}

	/* ── поръчка → тагът на лийда пада ── */
	public static function on_order_id( $order_id ) {
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null; if ( ! $o ) { return; }
		$m = $o->get_meta( '_ansa_promo' ); if ( is_array( $m ) ) { self::on_order( $o, $m ); }
	}
	/**
	 * Идемпотентно (meta `_ansa_promo_lead_untag` = 'ok' / 'retry: …'): лийдовете с billing имейла и с имейла от попъпа (lead_id
	 * от контекста) стават конвертирани; тагът пада от двата контакта. Връща 'ok' | 'retry' | 'off' | 'skip'.
	 */
	public static function on_order( $order, $m ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) || ! is_array( $m ) ) { return 'skip'; }
		if ( 'ok' === (string) $order->get_meta( '_ansa_promo_lead_untag' ) ) { return 'ok'; }
		$cfg = ! empty( $m['draft'] ) ? Config::get_draft() : Config::get_published(); $l = self::cfg( $cfg );
		$emails = array();
		$be = strtolower( sanitize_email( (string) $order->get_billing_email() ) ); if ( is_email( $be ) ) { $emails[ $be ] = 1; }
		$lead = ! empty( $m['lead_id'] ) ? Leads::get( (int) $m['lead_id'] ) : null; if ( $lead && is_email( $lead['email'] ) ) { $emails[ strtolower( $lead['email'] ) ] = 1; }
		if ( ! empty( $m['lead_email'] ) && is_email( $m['lead_email'] ) ) { $emails[ strtolower( $m['lead_email'] ) ] = 1; }
		$emails = array_keys( $emails );
		foreach ( $emails as $e ) { Leads::convert_email( $e, $order->get_id() ); }
		if ( ! self::enabled( $cfg ) || empty( $l['untag_on_order'] ) || '' === $l['tag'] ) { $order->update_meta_data( '_ansa_promo_lead_untag', 'ok' ); $order->save(); return 'off'; }
		if ( ! self::available() ) { $order->update_meta_data( '_ansa_promo_lead_untag', 'retry: FunnelKit контакти липсват' ); $order->save(); return 'retry'; }
		$tid = Merge::tag_id( $l['tag'] ); if ( $tid <= 0 ) { $order->update_meta_data( '_ansa_promo_lead_untag', 'ok' ); $order->save(); return 'ok'; } /* тагът не съществува → няма какво да пада */
		$parts = array(); $retry = '';
		foreach ( $emails as $e ) {
			try { $how = self::detach( $e, 'tags', $tid ); } catch ( \Throwable $ex ) { $how = ''; $retry = $ex->getMessage(); }
			if ( '' === $how ) { $retry = $retry ?: ( 'тагът не падна от ' . $e ); continue; }
			$parts[] = $e . ' · ' . $how;
			if ( 'няма' !== $how ) { $order->add_order_note( 'ansa™ Промо: тагът на лийда „' . $l['tag'] . '“ падна от контакта ' . $e . ' (' . ( 'crm' === $how ? 'през FKA, event „Tag Removed“' : 'направо в контакта' ) . ') — поръчката е направена.' ); }
		}
		$order->update_meta_data( '_ansa_promo_lead_untag', $retry ? 'retry: ' . $retry : 'ok' ); $order->save();
		return $retry ? 'retry' : 'ok';
	}

	/* ── бутонът и Doctor ── */
	/** Синхронизира неминалите/retry лийдове (бутонът). → брой по резултат. */
	public static function backfill( $limit = 500 ) {
		$r = array( 'ok' => 0, 'retry' => 0, 'skip' => 0 );
		foreach ( Leads::unsynced( $limit ) as $row ) {
			$k = self::sync( (int) $row['id'], $row['email'], ! empty( $row['consent'] ) );
			if ( 0 === strpos( $k, 'retry' ) ) { $r['retry']++; } elseif ( in_array( $k, array( 'off', 'no-consent', 'no-email' ), true ) ) { $r['skip']++; } else { $r['ok']++; }
		}
		return $r;
	}
	/** Редът в Doctor: лист #id · таг #id · броячите. */
	public static function status( $cfg = null ) {
		$cfg = $cfg ?: Config::get_published(); $l = self::cfg( $cfg ); $s = Leads::stats();
		$cnt = 'лийдове: ' . $s['total'] . ' (със съгласие ' . $s['consent'] . ', конвертирани ' . $s['converted'] . ') · във FunnelKit: ' . $s['synced'] . ' · чакат: ' . $s['pending'];
		if ( ! self::enabled( $cfg ) ) { return 'изключено · ' . $cnt; }
		if ( ! self::available() ) { return 'FunnelKit контакти липсват · лист „' . $l['list'] . '“ · таг „' . $l['tag'] . '“ · ' . $cnt; }
		$out = array();
		if ( '' !== $l['list'] ) { $id = Merge::list_id( $l['list'] ); $out[] = 'лист „' . $l['list'] . '“' . ( $id > 0 ? ' #' . $id : ' НЕ Е СЪЗДАДЕН (' . ( Merge::$tag_diag ?: 'без диагностика' ) . ')' ); }
		if ( '' !== $l['tag'] ) { $id = Merge::tag_id( $l['tag'] ); $out[] = 'таг „' . $l['tag'] . '“' . ( $id > 0 ? ' #' . $id : ' НЕ Е СЪЗДАДЕН (' . ( Merge::$tag_diag ?: 'без диагностика' ) . ')' ); }
		return implode( ' · ', $out ) . ( empty( $l['consent_only'] ) ? ' · и без съгласие' : ' · само със съгласие' ) . ( empty( $l['untag_on_order'] ) ? '' : ' · тагът пада при поръчка' ) . ' · ' . $cnt;
	}
}
