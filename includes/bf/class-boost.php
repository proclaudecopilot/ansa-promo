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
		/* 1.54.4: линкът от имейла за изоставена количка (FunnelKit) връща и ваучера — преди FKA да възстанови количката */
		add_action( 'woocommerce_init', array( __CLASS__, 'recover_request' ), 20 );
		add_action( 'wp_loaded', array( __CLASS__, 'recover_request' ), 0 );
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
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'leads' ) . " WHERE promo = %s AND email = %s AND kind <> 'aff' AND voucher <> '' AND voucher_order_id IS NULL AND voucher_exp IS NOT NULL AND voucher_exp > %s ORDER BY voucher_exp DESC, id DESC LIMIT 1", Leads::PROMO, $email, gmdate( 'Y-m-d H:i:s' ) ), ARRAY_A );
		return is_array( $r ) ? $r : null;
	}
	/** Сумата на ваучера по реда: ВИП/възобновен = listv.amount, студен = cold.voucher. */
	public static function amount_for( $row, $cfg ) { return in_array( (string) ( $row['bonus'] ?? '' ), array( 'revived', 'vip' ), true ) ? (float) $cfg['listv']['amount'] : (float) $cfg['cold']['voucher']; }
	/**
	 * Слага ваучера на реда в контекста на сесията (както при отключването).
	 * 1.54.7: $with_email = false при въведен код (ajax_claim) — кодът не доказва чий е имейлът. Досега имейлът на реда
	 * влизаше в сесията и се връщаше в отговора (ctx.email) и в полето за имейл на чекаута: налучкан код = чужд имейл.
	 * При подписания личен линк (?u=, identify) имейлът е доказан и остава.
	 */
	public static function apply_row( $row, $cfg, $with_email = true ) {
		if ( 'aff' === (string) ( $row['kind'] ?? '' ) ) { return Aff::apply_row( $row, $cfg ); } /* 1.57.0: партньорският ваучер има свое място в контекста (affv), не портфейла */
		$ctx = Cart::ctx(); $exp = strtotime( (string) $row['voucher_exp'] . ' UTC' );
		$ctx['voucher'] = array( 'code' => (string) $row['voucher'], 'amount' => self::amount_for( $row, $cfg ), 'exp' => (int) $exp, 'lead' => (int) $row['id'] );
		if ( $with_email && empty( $ctx['email'] ) ) { $ctx['email'] = (string) $row['email']; } if ( empty( $ctx['lead'] ) ) { $ctx['lead'] = (int) $row['id']; }
		Cart::set_ctx( $ctx ); return $ctx;
	}

	/* 1.54.7: общ таван на грешните кодове (от всички адреси) — кодът е къс и лимитът по IP не спира налучкване от много адреси.
	   CLAIM_FAILS грешни за CLAIM_WINDOW секунди → въвеждането на код спира до изтичането; личният линк от имейла работи. */
	const CLAIM_FAILS = 100; const CLAIM_WINDOW = 600; const CLAIM_KEY = 'ansa_bf_claim_fails';
	public static function claim_locked() { return (int) get_transient( self::CLAIM_KEY ) >= (int) apply_filters( 'ansa_promo_bf_claim_fails', self::CLAIM_FAILS ); }
	public static function claim_failed() { $n = (int) get_transient( self::CLAIM_KEY ) + 1; set_transient( self::CLAIM_KEY, $n, self::CLAIM_WINDOW ); return $n; }

	/* ═══ ВРЪЩАНЕ ОТ ИМЕЙЛА ЗА ИЗОСТАВЕНА КОЛИЧКА (1.54.4) ═══
	 * Ваучерът е отрицателна такса, вързана за WC сесията (ctx.voucher). Линкът на FunnelKit Automations (/checkout/?bwfan-ab-id=<token>&bwfan-uid=<uid>)
	 * възстановява само редовете и купоните: в нова сесия (друг браузър, изтекла сесия) или след срока на ваучера чекаутът излизаше БЕЗ „Ваучер 10 €“,
	 * а имейлът показва количката С него (FKA пази таксите в снимката на количката) — клиентът вижда „Общо 5,90“ в писмото и 15,90 в чекаута.
	 * Тук: token → количката на FKA → имейлът → ваучерът на лийда → в сесията. Жив ваучер се връща винаги. Изтекъл се възобновява за
	 * cold.recover_minutes от клика, ако: не е ползван, имейлът няма поръчка по промото, промото е включено и не е свършило, количката не е
	 * станала поръчка и не е по-стара от RECOVER_MAX_AGE (1.54.6: без „кодът е в снимката“ — FKA я презаписва без ваучера). bonus не се пипа (issue() на 20.11 пак го възобновява). */
	const AB_COOKIE       = 'ansa_bf_ab';
	const OPT_RECOVER     = 'ansa_promo_bf_recover_log';
	const RECOVER_MAX_AGE = 259200; /* 3 дни */
	private static $recover_done = false;
	private static $recover_why  = ''; /* 1.54.5: защо (не) е върнат ваучерът — за header-а X-Ansa-Bf-Recover и лога в диагностиката */

	private static function ab_token( $t ) { if ( ! is_scalar( $t ) ) { return ''; } $t = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $t ); return ( strlen( $t ) >= 8 && strlen( $t ) <= 64 ) ? $t : ''; }
	public static function recover_log() { $l = get_option( self::OPT_RECOVER ); return is_array( $l ) && ( ! empty( $l['n'] ) || ! empty( $l['last'] ) ) ? $l : null; }
	/** 1.54.5: последните 20 опита: [t, why, email (маскиран), tok (първите 4 знака)] — най-новият е първи. */
	private static function recover_note( $why, $email = '', $token = '', $ok = false, $revived = false ) {
		self::$recover_why = (string) $why;
		$l = get_option( self::OPT_RECOVER ); $l = is_array( $l ) ? $l : array(); $l['n'] = (int) ( $l['n'] ?? 0 ) + ( $ok ? 1 : 0 ); $l['revived'] = (int) ( $l['revived'] ?? 0 ) + ( $revived ? 1 : 0 ); if ( $ok ) { $l['at'] = current_time( 'mysql' ); }
		$last = is_array( $l['last'] ?? null ) ? $l['last'] : array(); array_unshift( $last, array( 't' => current_time( 'mysql' ), 'why' => (string) $why, 'email' => '' !== (string) $email ? \AnsaPromo\Cart_Sync::mask( $email ) : '', 'tok' => substr( (string) $token, 0, 4 ) ) ); $l['last'] = array_slice( $last, 0, 20 );
		update_option( self::OPT_RECOVER, $l, false );
	}
	public static function recover_why_label( $why ) { $m = array( 'ok-live' => 'върнат (жив)', 'ok-revived' => 'възобновен', 'in-session' => 'вече е в сесията', 'no-cart' => 'няма такава количка във FunnelKit', 'no-email' => 'количката е без имейл', 'no-voucher' => 'имейлът няма неизползван ваучер', 'off' => 'възобновяването е изключено / промото не върви', 'order' => 'количката вече е поръчка', 'old' => 'количката е по-стара от 3 дни', 'converted' => 'имейлът вече има поръчка по промото', 'db' => 'записът не мина', 'no-session' => 'няма WC сесия' ); return $m[ $why ] ?? (string) $why; }
	/** woocommerce_init / wp_loaded: ?bwfan-ab-id= (или нашата бисквитка от предната заявка) → ваучерът в сесията. Веднъж на заявка. */
	public static function recover_request() {
		if ( self::$recover_done ) { return; }
		if ( is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) { return; }
		if ( ! function_exists( 'WC' ) || ! WC()->session ) { if ( isset( $_GET['bwfan-ab-id'] ) && function_exists( 'doing_action' ) && doing_action( 'wp_loaded' ) && ! headers_sent() ) { header( 'X-Ansa-Bf-Recover: no-session' ); } return; }
		$get = isset( $_GET['bwfan-ab-id'] ) ? self::ab_token( wp_unslash( $_GET['bwfan-ab-id'] ) ) : '';
		$token = '' !== $get ? $get : ( isset( $_COOKIE[ self::AB_COOKIE ] ) ? self::ab_token( wp_unslash( $_COOKIE[ self::AB_COOKIE ] ) ) : '' );
		if ( '' === $token ) { return; }
		self::$recover_done = true;
		try {
			$ctx = Cart::ctx(); $cur = is_array( $ctx['voucher'] ?? null ) ? $ctx['voucher'] : null; $applied = false;
			if ( ! $cur || ! Leads::voucher_valid( (string) $cur['code'] ) ) { $applied = (bool) self::recover( $token, isset( $_GET['bwfan-uid'] ) && is_scalar( $_GET['bwfan-uid'] ) ? (string) wp_unslash( $_GET['bwfan-uid'] ) : '' ); }
			else { self::$recover_why = 'in-session'; }
			/* 1.54.5: причината — само код, без данни; вижда я който държи линка (за проверка отвън) */
			if ( '' !== $get && '' !== self::$recover_why && ! headers_sent() ) { header( 'X-Ansa-Bf-Recover: ' . preg_replace( '/[^a-z\-]/', '', self::$recover_why ) ); }
			/* Бисквитката пази токена за ЕДНА следваща заявка: ако възстановяването на FKA подмени сесията, ваучерът се връща пак (и спира дотам). */
			if ( '' !== $get && $applied ) { self::ab_cookie( $token, time() + HOUR_IN_SECONDS ); }
			elseif ( '' === $get ) { self::ab_cookie( '', time() - DAY_IN_SECONDS ); }
		} catch ( \Throwable $e ) {}
	}
	private static function ab_cookie( $v, $exp ) { if ( headers_sent() ) { return; } if ( function_exists( 'wc_setcookie' ) ) { wc_setcookie( self::AB_COOKIE, $v, $exp, function_exists( 'wc_site_is_https' ) && wc_site_is_https(), true ); } if ( '' === $v ) { unset( $_COOKIE[ self::AB_COOKIE ] ); } }
	/** Количката на FKA по токена от линка; ако я няма (или колоната е друга) — последната количка на контакта с този uid. Ред (ARRAY_A) или null. */
	public static function fka_cart( $token, $uid = '' ) {
		global $wpdb; $t = \AnsaPromo\Cart_Sync::table(); $token = self::ab_token( $token ); $r = null;
		$sup = $wpdb->suppress_errors( true );
		if ( '' !== $token ) { $r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s ORDER BY ID DESC LIMIT 1", $token ), ARRAY_A ); }
		if ( ! is_array( $r ) ) {
			$uid = preg_replace( '/[^a-f0-9]/i', '', (string) $uid ); $e = strlen( $uid ) >= 16 ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$wpdb->prefix}bwf_contact WHERE uid = %s LIMIT 1", $uid ) ) : '';
			$r = is_email( $e ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s ORDER BY ID DESC LIMIT 1", $e ), ARRAY_A ) : null;
		}
		$wpdb->suppress_errors( $sup );
		return is_array( $r ) ? $r : null;
	}
	/** token (+uid) → ваучерът на клиента в контекста на сесията. Връща [code, revived, exp, email] или null (няма какво да се върне). */
	public static function recover( $token, $uid = '' ) {
		$cart = self::fka_cart( $token, $uid ); if ( ! $cart ) { self::recover_note( 'no-cart', '', $token ); return null; }
		$email = strtolower( sanitize_email( (string) ( $cart['email'] ?? '' ) ) ); if ( ! is_email( $email ) ) { self::recover_note( 'no-email', '', $token ); return null; }
		$cfg = Config::get_published(); $row = self::voucher_for( $email ); $revived = false;
		if ( ! $row ) { $why = ''; $row = self::revive_for_cart( $email, $cart, $cfg, $why ); $revived = (bool) $row; if ( ! $row ) { self::recover_note( $why, $email, $token ); return null; } }
		self::apply_row( $row, $cfg );
		self::recover_note( $revived ? 'ok-revived' : 'ok-live', $email, $token, true, $revived );
		$out = array( 'code' => (string) $row['voucher'], 'revived' => $revived, 'exp' => (int) strtotime( (string) $row['voucher_exp'] . ' UTC' ), 'email' => $email );
		do_action( 'ansa_promo_bf_voucher_recovered', $out, $row, $cart );
		return $out;
	}
	/** Изтеклият неизползван ваучер на имейла → срокът му става клик + cold.recover_minutes. Връща обновения ред или null ($why = причината). */
	private static function revive_for_cart( $email, $cart, $cfg, &$why = '' ) {
		global $wpdb; $min = (int) ( $cfg['cold']['recover_minutes'] ?? 0 );
		if ( $min <= 0 || empty( $cfg['enabled'] ) || 'over' === Config::phase_now( $cfg ) ) { $why = 'off'; return null; }
		if ( (int) ( $cart['order_id'] ?? 0 ) > 0 ) { $why = 'order'; return null; } /* количката вече е поръчка */
		$mod = strtotime( (string) ( $cart['last_modified'] ?? '' ) . ' UTC' ); if ( $mod && $mod < time() - (int) apply_filters( 'ansa_promo_bf_recover_max_age', self::RECOVER_MAX_AGE, $cart ) ) { $why = 'old'; return null; }
		$t = Schema::table( 'leads' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE promo = %s AND email = %s AND converted_order_id IS NOT NULL", Leads::PROMO, $email ) ) > 0 ) { $why = 'converted'; return null; }
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE promo = %s AND email = %s AND kind <> 'aff' AND voucher <> '' AND voucher_order_id IS NULL ORDER BY id DESC LIMIT 10", Leads::PROMO, $email ), ARRAY_A );
		if ( ! $rows ) { $why = 'no-voucher'; return null; }
		/* 1.54.6: без условието „кодът е в снимката“ — FKA презаписва снимката при всяко отваряне на количката без ваучера (на живо: not-in-email
		   за линк, чието писмо го е показало). При няколко кода на имейла: този от снимката, иначе най-новият. */
		$snap = implode( ' ', array_filter( $cart, 'is_scalar' ) ); $row = $rows[0];
		foreach ( $rows as $r ) { if ( '' !== (string) $r['voucher'] && false !== stripos( $snap, (string) $r['voucher'] ) ) { $row = $r; break; } }
		$code = (string) $row['voucher'];
		$exp = max( (int) strtotime( (string) $row['voucher_exp'] . ' UTC' ), time() + $min * MINUTE_IN_SECONDS ); $sql = gmdate( 'Y-m-d H:i:s', $exp );
		if ( false === $wpdb->update( $t, array( 'voucher_exp' => $sql ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) ) ) { $why = 'db'; return null; }
		$row['voucher_exp'] = $sql; Leads::voucher_valid( $code, true );
		return $row;
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
		Aff::restore( $email, $cfg ); /* 1.57.0: записана през партньорски линк → партньорът, подаръкът и ваучерът ѝ се връщат в сесията */
		return $email;
	}

	/** ajax ansa_bf_claim: кодът от имейла → ваучерът в сесията. */
	public static function ajax_claim() {
		if ( ! check_ajax_referer( Page::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'nonce' ), 403 ); }
		if ( \AnsaPromo\Leads::rate_limited( 'bf_claim', 12 ) || self::claim_locked() ) { wp_send_json_error( array( 'code' => 'rate' ), 429 ); }
		$draft = Page::is_draft_request() || ( ! empty( $_POST['draft'] ) && current_user_can( \AnsaPromo\Plugin::CAP ) ); $cfg = $draft ? Config::get_draft() : Config::get_published();
		$code = strtoupper( preg_replace( '/[^a-z0-9\-]/i', '', (string) wp_unslash( $_POST['code'] ?? '' ) ) );
		$row = '' !== $code ? Leads::voucher_row( $code ) : null;
		if ( ! $row || ! Leads::voucher_valid( $code ) ) { self::claim_failed(); wp_send_json_error( array( 'code' => 'code', 'msg' => Copy::render( Copy::effective( $cfg['copy'] )['err.code'], array() ) ), 400 ); }
		self::apply_row( $row, $cfg, false );
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
	const TAGS = array( 'bf_voucher' => 'BF10-K7M2', 'bf_voucher_exp' => '23.11.2026 23:59', 'bf_aff_voucher' => 'BFP5-K7M2QX', 'bf_aff_voucher_exp' => '22.02.2027', 'bf_early_url' => 'https://ansa.bg/cheren-petak-2026/?ea=…&u=…', 'bf_url' => 'https://ansa.bg/cheren-petak-2026/' );
	/** Стойността за имейла на текущия FKA контакт (или attr email= за тест от админ). */
	public static function merge( $key, $attr = array() ) {
		$attr = is_array( $attr ) ? $attr : array(); $cfg = Config::get_published();
		$email = '';
		if ( ! empty( $attr['email'] ) && function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) && is_email( (string) $attr['email'] ) ) { $email = (string) $attr['email']; }
		if ( '' === $email && method_exists( Merge::class, 'fka_email' ) ) { $email = (string) Merge::fka_email(); }
		if ( '' === $email && method_exists( Merge::class, 'fka_email_deep' ) ) { $email = (string) Merge::fka_email_deep(); }
		/* 1.57.1: записаните през партньорски линк получават в имейла адрес с ?ref=КОД и подписания си имейл (?u=) — на всяко устройство страницата е
		   на партньора, подаръкът и ваучерът са в количката, а поръчката носи кода му (комисионата). Без промяна в автоматизациите на FunnelKit. */
		$aff_ref = '' !== $email ? Aff::lead_ref( $email ) : '';
		if ( 'bf_url' === $key ) {
			$q = array( 'utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'bf2026', 'utm_content' => sanitize_text_field( (string) ( $attr['utm_content'] ?? 'bf_public' ) ) );
			if ( '' !== $aff_ref ) { $q = array( 'ref' => $aff_ref, 'u' => self::utoken( $email ) ) + $q; }
			return esc_url_raw( add_query_arg( $q, Page::url( $cfg ) ) );
		}
		if ( 'bf_early_url' === $key && '' !== $aff_ref ) { return esc_url_raw( add_query_arg( 'ref', $aff_ref, self::personal_url( $email, $cfg, array( 'utm_content' => (string) ( $attr['utm_content'] ?? 'bf_early' ) ) ) ) ); }
		if ( 'bf_early_url' === $key ) { return esc_url_raw( '' !== $email ? self::personal_url( $email, $cfg, array( 'utm_content' => (string) ( $attr['utm_content'] ?? 'bf_early' ) ) ) : add_query_arg( array( 'utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'bf2026', 'utm_content' => 'bf_early' ), Config::early_url( $cfg ) ) ); }
		if ( 'bf_aff_voucher' === $key || 'bf_aff_voucher_exp' === $key ) { $val = Aff::merge( $key, $email ); return '' !== $val ? $val : (string) ( $attr['fallback'] ?? '' ); } /* 1.57.0 */
		$row = '' !== $email ? self::voucher_for( $email ) : null; if ( ! $row ) { return (string) ( $attr['fallback'] ?? '' ); }
		if ( 'bf_voucher' === $key ) { return (string) $row['voucher']; }
		if ( 'bf_voucher_exp' === $key ) { return Config::fmt_dt( strtotime( (string) $row['voucher_exp'] . ' UTC' ) ); }
		return '';
	}
}
