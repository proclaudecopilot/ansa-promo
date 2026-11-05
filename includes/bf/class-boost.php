<?php
/**
 * ansa™ Промо — Черен петък 2026 · 1.46.0: допълнителните инсентиви в ранния достъп (човекът, 05.11):
 *
 *  1. Ваучерите за списъка. Записалите се от студената страница се делят на две: (а) с неизползван ваучер → „Имаш късмет. Взехме решение
 *     и възобновихме ваучера ти“ (същият код, валиден до края на ранния достъп / промото); (б) с използван ваучер → „Ти си истински ВИП.
 *     Даваме ти още 10 € ваучер“ (нов код). Плъгинът издава/възобновява ваучерите и слага таг във FunnelKit (bf-revived / bf-vip) —
 *     имейлите са автоматизации „Tag Added“ с merge tag-овете {{ansa_promo_bf_voucher}} / {{ansa_promo_bf_voucher_exp}} /
 *     {{ansa_promo_bf_early_url}} (личен линк: токенът + подписан имейл → ваучерът влиза сам в количката). Ваучерът може да се активира и
 *     с кода от имейла (ajax ansa_bf_claim) — за друго устройство.
 *  2. Попъпът „Ти си късметлия“: при exit intent или дълго стоене на стъпка 2 (подаръците) — „Попадна в случайното раздаване на подаръци.
 *     ПОДАРЪК: 1 бр Колагенова маска“ → ред на 0 € (роля lucky) към поръчката.
 *
 * @package AnsaPromo
 */
namespace AnsaPromo\BF;

use AnsaPromo\Catalog;
use AnsaPromo\Lead_Sync;
use AnsaPromo\Merge;
use AnsaPromo\Schema;

defined( 'ABSPATH' ) || exit;

final class Boost {
	const HOOK     = 'ansa_promo_bf_hourly';
	const OPT_DONE = 'ansa_promo_bf_listv_done';
	const OPT_LOG  = 'ansa_promo_bf_listv_log';
	const LOCK     = 'ansa_promo_bf_listv_lock';

	public static function register() {
		add_action( 'wp_ajax_ansa_bf_claim', array( __CLASS__, 'ajax_claim' ) );
		add_action( 'wp_ajax_nopriv_ansa_bf_claim', array( __CLASS__, 'ajax_claim' ) );
		add_action( self::HOOK, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}
	public static function schedule() { if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK ); } }
	public static function cron() { try { self::maybe_auto(); } catch ( \Throwable $e ) {} }

	/* ═══ ВАУЧЕРИТЕ ЗА СПИСЪКА ═══ */

	/** Крайният срок на ваучерите по настройката (ts, UTC). */
	public static function until_ts( $cfg ) { $pt = Config::phase_ts( $cfg ); $k = (string) ( $cfg['listv']['until'] ?? 'early_end' ); return (int) ( $pt[ $k ] ?? $pt['early_end'] ); }
	/** Издадени ли са вече (за това публикувано промо / срок). */
	public static function done_key( $cfg ) { return $cfg['id'] . '|' . self::until_ts( $cfg ); }
	public static function is_done( $cfg = null ) { $cfg = $cfg ?: Config::get_published(); return get_option( self::OPT_DONE ) === self::done_key( $cfg ); }
	public static function log() { $l = get_option( self::OPT_LOG ); return is_array( $l ) ? $l : null; }

	/** Автоматично: щом ранният достъп започне (или публичният, ако е пропуснат) — веднъж. Вика се от крона и от ajax runtime-а (евтино: една опция). */
	public static function maybe_auto() {
		$cfg = Config::get_published();
		if ( empty( $cfg['enabled'] ) || empty( $cfg['listv']['enabled'] ) || empty( $cfg['listv']['auto'] ) ) { return false; }
		if ( ! in_array( Config::phase_now( $cfg ), array( 'early', 'public' ), true ) ) { return false; }
		if ( self::is_done( $cfg ) ) { return false; }
		return (bool) self::issue( $cfg );
	}

	/** Прегледът преди издаване: колко имейла, колко с използван ваучер, колко вече обработени. */
	public static function preview( $cfg = null ) {
		global $wpdb; $cfg = $cfg ?: Config::get_published(); $t = Schema::table( 'leads' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT email, MAX(voucher_order_id IS NOT NULL OR converted_order_id IS NOT NULL) AS used, MAX(bonus IN ('revived','vip')) AS done FROM $t WHERE promo = %s AND kind IN ('cold','listv') AND email <> '' GROUP BY email", Leads::PROMO ), ARRAY_A );
		$o = array( 'emails' => 0, 'used' => 0, 'unused' => 0, 'done' => 0 );
		foreach ( (array) $rows as $r ) { $o['emails']++; if ( ! empty( $r['done'] ) ) { $o['done']++; continue; } if ( ! empty( $r['used'] ) ) { $o['used']++; } else { $o['unused']++; } }
		return $o;
	}

	/** Издава/възобновява ваучерите и слага таговете. Връща [revived, vip, skipped, errors[]] или false при заключване. */
	public static function issue( $cfg = null, $limit = 2000 ) {
		global $wpdb; $cfg = $cfg ?: Config::get_published(); $t = Schema::table( 'leads' );
		if ( get_transient( self::LOCK ) ) { return false; } set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		$until = self::until_ts( $cfg ); $amount = (float) $cfg['listv']['amount']; $exp_sql = gmdate( 'Y-m-d H:i:s', $until );
		$res = array( 'revived' => 0, 'vip' => 0, 'skipped' => 0, 'errors' => array(), 'at' => current_time( 'mysql' ), 'until' => $until );
		$emails = $wpdb->get_col( $wpdb->prepare( "SELECT email FROM $t WHERE promo = %s AND kind IN ('cold','listv') AND email <> '' GROUP BY email HAVING MAX(bonus IN ('revived','vip')) = 0 ORDER BY MIN(id) ASC LIMIT %d", Leads::PROMO, (int) $limit ) );
		$fka = ! empty( $cfg['leads']['fka'] ) && Lead_Sync::available();
		foreach ( (array) $emails as $email ) {
			try {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE promo = %s AND email = %s AND kind IN ('cold','listv') ORDER BY id DESC", Leads::PROMO, $email ), ARRAY_A );
				if ( ! $rows ) { $res['skipped']++; continue; }
				$used = false; $last = $rows[0]; $cand = null;
				foreach ( $rows as $r ) { if ( ! empty( $r['voucher_order_id'] ) || ! empty( $r['converted_order_id'] ) ) { $used = true; } if ( ! $cand && '' !== (string) $r['voucher'] && empty( $r['voucher_order_id'] ) ) { $cand = $r; } }
				if ( ! $used && $cand ) {
					/* (а) неизползван → същият код, нов срок */
					$wpdb->update( $t, array( 'voucher_exp' => $exp_sql, 'bonus' => 'revived' ), array( 'id' => (int) $cand['id'] ), array( '%s', '%s' ), array( '%d' ) );
					$lead_id = (int) $cand['id']; $tag = (string) $cfg['listv']['tag_revived']; $res['revived']++;
				} else {
					/* (б) използван (или без ваучер) → нов код */
					$code = Leads::new_voucher_code( $cfg, (string) $cfg['listv']['prefix'] . (int) round( $amount ) . '-' );
					$ok = $wpdb->insert( $t, array( 'email' => $email, 'utm' => (string) $last['utm'], 'box_seen' => 'bf', 'consent' => (int) $last['consent'], 'ip' => '', 'created_at' => current_time( 'mysql' ), 'promo' => Leads::PROMO, 'kind' => 'listv', 'bonus' => 'vip', 'ref_code' => '', 'voucher' => $code, 'voucher_exp' => $exp_sql ), array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
					if ( ! $ok ) { $res['errors'][] = $email . ': insert'; continue; }
					$lead_id = (int) $wpdb->insert_id; $tag = (string) $cfg['listv']['tag_vip']; $res['vip']++;
				}
				if ( $fka && '' !== $tag ) {
					$c = Lead_Sync::contact( $email, true ); $tid = Merge::tag_id( $tag, true );
					$how = ( $c && $tid > 0 ) ? Lead_Sync::attach( $c, $email, 'tags', $tid, $tag ) : '';
					\AnsaPromo\Leads::mark_fka( $lead_id, $c ? (int) $c->get_id() : 0, '' !== $how ? 'таг #' . $tid . ' ' . $tag . ' · ' . $how : 'retry: тагът ' . $tag . ' не се записа' );
					if ( '' === $how ) { $res['errors'][] = $email . ': ' . $tag; }
				}
			} catch ( \Throwable $e ) { $res['errors'][] = $email . ': ' . $e->getMessage(); }
		}
		update_option( self::OPT_LOG, $res, false );
		if ( count( (array) $emails ) < $limit ) { update_option( self::OPT_DONE, self::done_key( $cfg ), false ); }
		delete_transient( self::LOCK );
		do_action( 'ansa_promo_bf_listv_issued', $res, $cfg );
		return $res;
	}

	/** Живият ваучер на имейла (най-новият неизползван с бъдещ срок) или null. */
	public static function voucher_for( $email ) {
		global $wpdb; $email = strtolower( sanitize_email( (string) $email ) ); if ( ! is_email( $email ) ) { return null; }
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . " WHERE promo = %s AND email = %s AND voucher <> '' AND voucher_order_id IS NULL AND voucher_exp IS NOT NULL AND voucher_exp > %s ORDER BY voucher_exp DESC, id DESC LIMIT 1", Leads::PROMO, $email, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		return is_array( $r ) ? $r : null;
	}
	/** Сумата на ваучера по реда: ВИП/възобновен = listv.amount, студен = cold.voucher. */
	public static function amount_for( $row, $cfg ) { return in_array( (string) ( $row['bonus'] ?? '' ), array( 'revived', 'vip' ), true ) ? (float) $cfg['listv']['amount'] : (float) $cfg['cold']['voucher']; }
	/** Слага ваучера на реда в контекста на сесията (както при отключването). */
	public static function apply_row( $row, $cfg ) {
		$ctx = Cart::ctx(); $exp = strtotime( (string) $row['voucher_exp'] . ' UTC' );
		$ctx['voucher'] = array( 'code' => (string) $row['voucher'], 'amount' => self::amount_for( $row, $cfg ), 'exp' => (int) $exp, 'lead' => (int) $row['id'] );
		if ( empty( $ctx['email'] ) ) { $ctx['email'] = (string) $row['email']; } if ( empty( $ctx['lead'] ) ) { $ctx['lead'] = (int) $row['id']; }
		Cart::set_ctx( $ctx ); return $ctx;
	}

	/* ── личният линк: ?u=<base64url(email)>.<hmac> ── */
	public static function utoken( $email ) { $email = strtolower( trim( (string) $email ) ); if ( ! is_email( $email ) ) { return ''; } return rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' ) . '.' . substr( hash_hmac( 'sha256', 'bf-u|' . $email, wp_salt( 'auth' ) ), 0, 12 ); }
	public static function email_from_utoken( $u ) {
		$u = (string) $u; if ( '' === $u || false === strpos( $u, '.' ) ) { return ''; } list( $b, $h ) = explode( '.', $u, 2 );
		$email = strtolower( (string) base64_decode( strtr( $b, '-_', '+/' ) ) ); if ( ! is_email( $email ) ) { return ''; }
		return hash_equals( substr( hash_hmac( 'sha256', 'bf-u|' . $email, wp_salt( 'auth' ) ), 0, 12 ), preg_replace( '/[^a-f0-9]/', '', $h ) ) ? $email : '';
	}
	/** Личният линк за имейла: токенът на ранния достъп + подписаният имейл (+ UTM). */
	public static function personal_url( $email, $cfg = null, $utm = array() ) {
		$cfg = $cfg ?: Config::get_published(); $url = Config::early_url( $cfg ); $t = self::utoken( $email ); if ( '' !== $t ) { $url = add_query_arg( 'u', $t, $url ); }
		$q = array_merge( array( 'utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'bf2026', 'utm_content' => 'bf_early' ), is_array( $utm ) ? $utm : array() );
		$q = array_filter( array_map( 'sanitize_text_field', $q ), 'strlen' ); return $q ? add_query_arg( $q, $url ) : $url;
	}
	/** От ajax runtime-а: ?u= → имейлът е разпознат → ваучерът му влиза в сесията. Връща имейла или ''. */
	public static function identify( $u, $cfg ) {
		$email = self::email_from_utoken( $u ); if ( '' === $email ) { return ''; }
		$ctx = Cart::ctx(); $cur = is_array( $ctx['voucher'] ?? null ) ? $ctx['voucher'] : null;
		$row = self::voucher_for( $email );
		if ( $row && ( ! $cur || (string) $cur['code'] !== (string) $row['voucher'] || ! Leads::voucher_valid( (string) $cur['code'] ) ) ) { self::apply_row( $row, $cfg ); }
		elseif ( empty( $ctx['email'] ) ) { $ctx['email'] = $email; Cart::set_ctx( $ctx ); }
		return $email;
	}

	/** ajax ansa_bf_claim: кодът от имейла → ваучерът в сесията. */
	public static function ajax_claim() {
		if ( ! check_ajax_referer( Page::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'nonce' ), 403 ); }
		if ( \AnsaPromo\Leads::rate_limited( 'bf_claim', 12 ) ) { wp_send_json_error( array( 'code' => 'rate' ), 429 ); }
		$draft = Page::is_draft_request() || ( ! empty( $_POST['draft'] ) && current_user_can( \AnsaPromo\Plugin::CAP ) ); $cfg = $draft ? Config::get_draft() : Config::get_published();
		$code = strtoupper( preg_replace( '/[^a-z0-9\-]/i', '', (string) wp_unslash( $_POST['code'] ?? '' ) ) );
		$row = '' !== $code ? Leads::voucher_row( $code ) : null;
		if ( ! $row || ! Leads::voucher_valid( $code ) ) { wp_send_json_error( array( 'code' => 'code', 'msg' => Copy::render( Copy::effective( $cfg['copy'] )['err.code'], array() ) ), 400 ); }
		self::apply_row( $row, $cfg );
		nocache_headers(); wp_send_json_success( array( 'ctx' => Cart::ctx_public() ) );
	}

	/* ═══ ПОПЪПЪТ „ТИ СИ КЪСМЕТЛИЯ“ ═══ */

	/** Продуктът-подарък: [id, vid, name, price, img, stock] или null. */
	public static function lucky_product( $cfg ) {
		$l = $cfg['lucky']; $pid = '' !== (string) $l['sku'] ? ( Catalog::find_by_sku( $l['sku'] ) ?: (int) $l['product_id'] ) : (int) $l['product_id'];
		if ( ! $pid ) { return null; } $wc = Catalog::wc_line( $pid ); if ( ! $wc['ok'] ) { return null; }
		return array( 'id' => (int) ( $wc['product_id'] ?: $pid ), 'vid' => (int) $wc['variation_id'], 'name' => $wc['wc_name'], 'price' => (float) $wc['price'], 'img' => $wc['img'] ?: ANSA_PROMO_URL . 'assets/img/bf/mask.jpg', 'stock' => (bool) $wc['stock'] );
	}
	public static function lucky_phases( $cfg ) { return array_values( array_filter( array_map( 'trim', explode( ',', (string) $cfg['lucky']['phases'] ) ) ) ); }
	/** За runtime JSON-а. */
	public static function lucky_runtime( $cfg ) {
		$l = $cfg['lucky']; $p = ! empty( $l['enabled'] ) ? self::lucky_product( $cfg ) : null;
		return array( 'enabled' => ! empty( $l['enabled'] ) && $p && $p['stock'], 'dwell' => (int) $l['dwell'], 'exit' => ! empty( $l['exit'] ), 'phases' => self::lucky_phases( $cfg ), 'name' => $p ? $p['name'] : '', 'price' => $p ? $p['price'] : 0, 'img' => $p ? $p['img'] : '' );
	}

	/* ═══ MERGE TAGS за FunnelKit ({{ansa_promo_bf_voucher}} …) ═══ */
	const TAGS = array( 'bf_voucher' => 'BF10-K7M2', 'bf_voucher_exp' => '27.11.2026 10:00', 'bf_early_url' => 'https://ansa.bg/cheren-petak-2026/?ea=…&u=…', 'bf_url' => 'https://ansa.bg/cheren-petak-2026/' );
	/** Стойността за имейла на текущия FKA контакт (или attr email= за тест от админ). */
	public static function merge( $key, $attr = array() ) {
		$attr = is_array( $attr ) ? $attr : array(); $cfg = Config::get_published();
		if ( 'bf_url' === $key ) { return esc_url_raw( add_query_arg( array( 'utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'bf2026', 'utm_content' => sanitize_text_field( (string) ( $attr['utm_content'] ?? 'bf_public' ) ) ), Page::url( $cfg ) ) ); }
		$email = '';
		if ( ! empty( $attr['email'] ) && function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) && is_email( (string) $attr['email'] ) ) { $email = (string) $attr['email']; }
		if ( '' === $email && method_exists( Merge::class, 'fka_email' ) ) { $email = (string) Merge::fka_email(); }
		if ( '' === $email && method_exists( Merge::class, 'fka_email_deep' ) ) { $email = (string) Merge::fka_email_deep(); }
		if ( 'bf_early_url' === $key ) { return esc_url_raw( '' !== $email ? self::personal_url( $email, $cfg, array( 'utm_content' => (string) ( $attr['utm_content'] ?? 'bf_early' ) ) ) : add_query_arg( array( 'utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'bf2026', 'utm_content' => 'bf_early' ), Config::early_url( $cfg ) ) ); }
		$row = '' !== $email ? self::voucher_for( $email ) : null; if ( ! $row ) { return (string) ( $attr['fallback'] ?? '' ); }
		if ( 'bf_voucher' === $key ) { return (string) $row['voucher']; }
		if ( 'bf_voucher_exp' === $key ) { return Config::fmt_dt( strtotime( (string) $row['voucher_exp'] . ' UTC' ) ); }
		return '';
	}
}
