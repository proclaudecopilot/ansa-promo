<?php
/**
 * ansa™ Промо — награди (Фаза 4): билети, QR, REST за портала, ваучер/книга по имейл, void, админ.
 *
 *  - Издаване: при processing/completed (веднъж; guard `_ansa_promo_ticket_id`) за валидна промо поръчка с allowance > 0 →
 *    ред в {prefix}ansa_promo_tickets {order_id, code, tickets, box, status=issued}. Кодът е 12 знака (8 случайни + 4 HMAC
 *    контролни, азбука без 0/O/1/I), показва се като XXXX-XXXX-XXXX; невалиден код се отхвърля без DB.
 *  - QR: съдържанието е URL към портала (tickets.portal_url) или към промо страницата с ?ticket=КОД. PNG/SVG през
 *    GET /wp-json/ansa-promo/v1/order/{id}/qr.png (manage_woocommerce или подписан ?key=) — за складовия стикер; URL-ът
 *    е и в order meta `_ansa_promo_qr_url`, за да го печата плъгинът за опаковъчния лист. Бележка към поръчката с кода.
 *  - Портал: GET /ansa-promo/v1/ticket/{code} → {valid,count,box,status,activated}; POST …/activate {email|phone} →
 *    activated (веднъж), hook ansa_promo_ticket_activated. Rate limit 30/мин/IP. CORS за origin-а на портала.
 *  - completed: ваучер за сета (ако е запазен; `_ansa_promo_cosm_status = voucher_sent`, код `_ansa_promo_voucher`) и книгата
 *    (ако кутията я има и има rewards.book.url, а WC не я дава като download); по избор имейл с билетите (tickets.email).
 *  - cancelled/refunded → void; връщане към processing → issued. Дневен крон: void за отменени поръчки (застраховка).
 *
 * @package AnsaPromo
 * @since 1.2.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Tickets {

	const NS       = 'ansa-promo/v1';
	const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	const OPT_SECRET = 'ansa_promo_ticket_secret';

	public static function register() {
		foreach ( array( 'processing', 'completed' ) as $st ) { add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'on_paid' ), 20 ); }
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_completed' ), 30 );
		foreach ( array( 'cancelled', 'refunded' ) as $st ) { add_action( 'woocommerce_order_status_' . $st, array( __CLASS__, 'on_void' ), 20 ); }
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'on_delete' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'allowed_http_origins', array( __CLASS__, 'cors' ) );
		add_action( 'ansa_promo_daily', array( __CLASS__, 'cron' ) );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ), 10, 2 );
			add_action( 'admin_post_ansa_promo_ticket_print', array( __CLASS__, 'print_page' ) );
			add_action( 'admin_post_ansa_promo_ticket_issue', array( __CLASS__, 'issue_from_admin' ) );
		}
	}

	/* ── кодове ── */
	public static function secret() {
		$cfg = Config::get_published(); if ( ! empty( $cfg['tickets']['secret'] ) ) { return (string) $cfg['tickets']['secret']; }
		$s = (string) get_option( self::OPT_SECRET, '' );
		if ( '' === $s ) { $s = bin2hex( random_bytes( 24 ) ); update_option( self::OPT_SECRET, $s, false ); }
		return $s;
	}
	private static function check( $body ) {
		$h = hash_hmac( 'sha256', strtoupper( $body ), self::secret(), true ); $n = ( ord( $h[0] ) << 16 ) | ( ord( $h[1] ) << 8 ) | ord( $h[2] ); $out = '';
		for ( $i = 0; $i < 4; $i++ ) { $out .= self::ALPHABET[ ( $n >> ( 5 * $i ) ) & 31 ]; }
		return $out;
	}
	public static function generate() {
		$body = ''; $max = strlen( self::ALPHABET ) - 1;
		for ( $i = 0; $i < 8; $i++ ) { $body .= self::ALPHABET[ random_int( 0, $max ) ]; }
		return $body . self::check( $body );
	}
	/** Нормализира (главни, без тирета/интервали, 0→O 1→I не се „поправят“) и проверява контролата. Връща кода или ''. */
	public static function normalize( $code ) {
		$c = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
		if ( 12 !== strlen( $c ) ) { return ''; }
		return hash_equals( self::check( substr( $c, 0, 8 ) ), substr( $c, 8 ) ) ? $c : '';
	}
	public static function pretty( $code ) { return implode( '-', str_split( (string) $code, 4 ) ); }

	/* ── данни ── */
	public static function by_code( $code ) { global $wpdb; $c = self::normalize( $code ); if ( '' === $c ) { return null; } return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'tickets' ) . ' WHERE code = %s', $c ), ARRAY_A ); }
	public static function for_order( $order_id ) { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'tickets' ) . ' WHERE order_id = %d ORDER BY id DESC LIMIT 1', (int) $order_id ), ARRAY_A ); }
	public static function set_status( $id, $status ) { global $wpdb; delete_transient( 'ansa_promo_cnt_l' ); return (bool) $wpdb->update( Schema::table( 'tickets' ), array( 'status' => $status ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) ); }
	public static function count( $status = '' ) { global $wpdb; return (int) ( $status ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'tickets' ) . ' WHERE status = %s', $status ) ) : $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'tickets' ) ) ); }
	/** v1.7.0: билетите (не анулирани) за дадена кутия — „Първите N са резервирани“ на страницата „Как работи“; transient 5 мин, нулира се при издаване/void. */
	public static function count_box( $box ) { $k = 'ansa_promo_cnt_' . sanitize_key( $box ); $n = get_transient( $k ); if ( false === $n ) { global $wpdb; $n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'tickets' ) . " WHERE box = %s AND status <> 'void'", (string) $box ) ); set_transient( $k, $n, 5 * MINUTE_IN_SECONDS ); } return (int) $n; }
	public static function sum( $status = '' ) { global $wpdb; return (int) ( $status ? $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(tickets),0) FROM ' . Schema::table( 'tickets' ) . ' WHERE status = %s', $status ) ) : $wpdb->get_var( 'SELECT COALESCE(SUM(tickets),0) FROM ' . Schema::table( 'tickets' ) ) ); }

	/** URL-ът в QR кода: порталът (?t=) или промо страницата (?ticket=). */
	public static function ticket_url( $code ) {
		$cfg = Config::get_published();
		$base = ! empty( $cfg['tickets']['portal_url'] ) ? $cfg['tickets']['portal_url'] : Cart::promo_url( $cfg );
		return apply_filters( 'ansa_promo_ticket_url', add_query_arg( ! empty( $cfg['tickets']['portal_url'] ) ? 't' : 'ticket', self::pretty( $code ), $base ), $code, $cfg );
	}
	public static function qr_key( $order_id ) { return substr( hash_hmac( 'sha256', 'qr|' . (int) $order_id, self::secret() ), 0, 20 ); }
	public static function qr_url( $order_id, $fmt = 'png' ) { return add_query_arg( 'key', self::qr_key( $order_id ), rest_url( self::NS . '/order/' . (int) $order_id . '/qr.' . $fmt ) ); }

	/* ── издаване / void ── */
	public static function on_paid( $order_id ) { self::issue( $order_id ); }
	/** Издава (или възстановява) билета за поръчката. Връща реда или null. */
	public static function issue( $order_id, $force = false ) {
		global $wpdb;
		$order = wc_get_order( $order_id ); if ( ! $order ) { return null; }
		$m = $order->get_meta( '_ansa_promo' ); if ( ! is_array( $m ) || empty( $m['valid'] ) ) { return null; }
		$allow = (int) ( $m['tickets']['allowance'] ?? 0 ) + ( ! empty( $m['tickets']['partner'] ) ? (int) ( $m['tickets']['bonus'] ?? 0 ) : 0 ) + (int) ( $m['tickets']['ref_bonus'] ?? 0 ); /* 1.6.0: + подаръкът от препоръка */
		if ( $allow <= 0 && ! $force ) { return null; }
		$row = self::for_order( $order_id );
		if ( $row ) {
			if ( 'void' === $row['status'] ) { self::set_status( $row['id'], 'issued' ); $row['status'] = 'issued'; $order->add_order_note( 'ansa™ Промо: билетът ' . self::pretty( $row['code'] ) . ' е възстановен (поръчката пак е активна).' ); }
			return $row;
		}
		$code = self::generate(); $tries = 0;
		while ( $tries++ < 5 && ! $wpdb->insert( Schema::table( 'tickets' ), array( 'order_id' => (int) $order_id, 'code' => $code, 'tickets' => max( 1, $allow ), 'box' => (string) $m['box'], 'status' => 'issued', 'created_at' => current_time( 'mysql' ) ), array( '%d', '%s', '%d', '%s', '%s', '%s' ) ) ) { $code = self::generate(); }
		if ( ! $wpdb->insert_id ) { return null; }
		$id = (int) $wpdb->insert_id; delete_transient( 'ansa_promo_cnt_l' ); /* v1.7.0: броячът „Първите N са резервирани“ */
		$order->update_meta_data( '_ansa_promo_ticket_id', $id ); $order->update_meta_data( '_ansa_promo_ticket_code', $code ); $order->update_meta_data( '_ansa_promo_qr_url', self::qr_url( $order_id ) );
		if ( ! empty( $m['cosm'] ) && 'reserved' === ( $m['cosm']['status'] ?? '' ) && ! $order->get_meta( '_ansa_promo_voucher' ) ) { $order->update_meta_data( '_ansa_promo_voucher', 'V' . substr( self::generate(), 0, 7 ) ); }
		$order->save();
		$order->add_order_note( sprintf( 'ansa™ Промо: издаден билет %s · %d билет(а) · QR за стикера: %s', self::pretty( $code ), max( 1, $allow ), self::qr_url( $order_id ) ) );
		$row = self::for_order( $order_id );
		do_action( 'ansa_promo_ticket_issued', $row, $order );
		$cfg = Config::get_published();
		if ( ! empty( $cfg['tickets']['email'] ) ) { self::send( $order, 'ticket', array( 'code' => self::pretty( $code ), 'tickets' => max( 1, $allow ) ) ); }
		return $row;
	}
	public static function on_void( $order_id ) {
		$row = self::for_order( $order_id ); if ( ! $row || 'void' === $row['status'] ) { return; }
		self::set_status( $row['id'], 'void' ); $o = wc_get_order( $order_id ); if ( $o ) { $o->add_order_note( 'ansa™ Промо: билетът ' . self::pretty( $row['code'] ) . ' е анулиран (поръчката е отменена/възстановена).' ); }
		do_action( 'ansa_promo_ticket_void', $row );
	}
	public static function on_delete( $id ) {
		global $wpdb; $id = is_object( $id ) ? $id->get_id() : (int) $id;
		if ( ! $id ) { return; }
		if ( 'before_delete_post' === current_action() && 'shop_order' !== get_post_type( $id ) ) { return; }
		$wpdb->delete( Schema::table( 'tickets' ), array( 'order_id' => $id ), array( '%d' ) );
	}
	public static function cron() {
		global $wpdb;
		$t = Schema::table( 'tickets' ); $ids = $wpdb->get_col( "SELECT order_id FROM $t WHERE status <> 'void'" );
		foreach ( (array) $ids as $oid ) { $o = wc_get_order( (int) $oid ); if ( $o && in_array( $o->get_status(), array( 'cancelled', 'refunded', 'trash' ), true ) ) { self::on_void( (int) $oid ); } }
	}

	/* ── completed: ваучер + книга ── */
	public static function on_completed( $order_id ) {
		$order = wc_get_order( $order_id ); if ( ! $order ) { return; }
		$m = $order->get_meta( '_ansa_promo' ); if ( ! is_array( $m ) || empty( $m['valid'] ) ) { return; }
		$cfg = Config::get_published();
		if ( ! empty( $m['cosm'] ) && 'reserved' === ( $m['cosm']['status'] ?? '' ) && 'voucher_sent' !== $order->get_meta( '_ansa_promo_cosm_status' ) ) {
			if ( ! $order->get_meta( '_ansa_promo_voucher' ) ) { $order->update_meta_data( '_ansa_promo_voucher', 'V' . substr( self::generate(), 0, 7 ) ); }
			if ( self::send( $order, 'voucher', array( 'voucher' => $order->get_meta( '_ansa_promo_voucher' ) ) ) ) { $order->update_meta_data( '_ansa_promo_cosm_status', 'voucher_sent' ); $order->add_order_note( 'ansa™ Промо: ваучерът за сета е изпратен (' . $order->get_meta( '_ansa_promo_voucher' ) . ').' ); }
		}
		if ( ! empty( $m['book'] ) && ! $order->get_meta( '_ansa_promo_book_sent' ) ) {
			$bp = ! empty( $m['book']['product_id'] ) ? wc_get_product( (int) $m['book']['product_id'] ) : null;
			$wc_gives = $bp && $bp->is_downloadable() && $bp->get_downloads();
			if ( ! $wc_gives && ! empty( $cfg['rewards']['book']['url'] ) && self::send( $order, 'book', array( 'book_url' => $cfg['rewards']['book']['url'] ) ) ) { $order->update_meta_data( '_ansa_promo_book_sent', current_time( 'mysql' ) ); $order->add_order_note( 'ansa™ Промо: линкът към книгата е изпратен.' ); }
			elseif ( $wc_gives ) { $order->update_meta_data( '_ansa_promo_book_sent', 'wc-download' ); }
		}
		$order->save();
	}

	/**
	 * 1.8.2: колко билета носи поръчката — ЕДНАТА сметка за merge tags, „благодаря“, имейлите и томболата.
	 * Издаден билет → tickets + bonus (препоръки) от реда; преди издаване → allowance + бонус Partner + подарък от препоръка
	 * от order meta. 0 = извън играта, непълна кутия (valid=0), отменена/възстановена/неуспешна поръчка или void билет.
	 */
	public static function count_for_order( $order ) {
		if ( ! is_object( $order ) ) { $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order ) : null; }
		if ( ! $order || ! method_exists( $order, 'get_meta' ) ) { return 0; }
		$m = $order->get_meta( '_ansa_promo' ); if ( ! is_array( $m ) || empty( $m['valid'] ) ) { return 0; }
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed', 'trash' ), true ) ) { return 0; }
		$row = self::for_order( $order->get_id() );
		if ( $row ) { return 'void' === $row['status'] ? 0 : max( 0, (int) $row['tickets'] + (int) ( $row['bonus'] ?? 0 ) ); }
		return max( 0, (int) ( $m['tickets']['allowance'] ?? 0 ) + ( ! empty( $m['tickets']['partner'] ) ? (int) ( $m['tickets']['bonus'] ?? 0 ) : 0 ) + (int) ( $m['tickets']['ref_bonus'] ?? 0 ) );
	}

	/** Конфигът, кутията и плейсхолдърите на поръчката за имейл текстовете (em.*): [cfg, box, vars] или null извън играта. */
	public static function email_vars( $order, $extra = array() ) {
		$m = $order->get_meta( '_ansa_promo' ); if ( ! is_array( $m ) ) { return null; }
		$cfg = ! empty( $m['draft'] ) ? Config::get_draft() : Config::get_published(); $box = Config::box( $cfg, (string) ( $m['box'] ?? '' ) ); if ( ! $box ) { return null; }
		$v = Cart::vars( $cfg, $box ); $row = self::for_order( $order->get_id() );
		$v = array_merge( $v, array( 'customer' => $order->get_billing_first_name() ?: $order->get_billing_last_name(), 'order' => $order->get_order_number(), 'code' => $row ? self::pretty( $row['code'] ) : '', 'tickets' => self::count_for_order( $order ), 'voucher' => (string) $order->get_meta( '_ansa_promo_voucher' ), 'book_url' => (string) ( $cfg['rewards']['book']['url'] ?? '' ), 'portal_url' => Merge::portal_url( $cfg ), 'tombola_url' => Tombola::enabled( $cfg ) ? Tombola::url( $cfg ) : '' ), $extra );
		$v['bileta'] = 1 === (int) $v['tickets'] ? 'билет' : 'билета';
		return array( 'cfg' => $cfg, 'box' => $box, 'vars' => $v );
	}

	/** Имейл от регистъра (em.<kind>.subject/h/body) през WC mailer-а. Връща true при подаден wp_mail. */
	public static function send( $order, $kind, $extra = array() ) {
		$ev = self::email_vars( $order, $extra ); if ( ! $ev ) { return false; }
		$cfg = $ev['cfg']; $v = $ev['vars']; $copy = Copy::effective( $cfg['copy'] );
		$subject = wp_strip_all_tags( Copy::render( $copy[ 'em.' . $kind . '.subject' ] ?? '', $v ) ); $heading = wp_strip_all_tags( Copy::render( $copy[ 'em.' . $kind . '.h' ] ?? '', $v ) ); $body = wpautop( Copy::render( $copy[ 'em.' . $kind . '.body' ] ?? '', $v ) );
		$to = $order->get_billing_email(); if ( ! $to || '' === $subject ) { return false; }
		$mailer = WC()->mailer(); $html = $mailer->wrap_message( $heading, $body );
		$html = apply_filters( 'ansa_promo_email_html', $html, $kind, $order, $v );
		return (bool) $mailer->send( $to, $subject, $html, "Content-Type: text/html\r\n" );
	}

	/* ── REST ── */
	public static function routes() {
		register_rest_route( self::NS, '/ticket/(?P<code>[A-Za-z0-9\-]{12,16})', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'r_ticket' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/ticket/(?P<code>[A-Za-z0-9\-]{12,16})/activate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'r_activate' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( self::NS, '/order/(?P<id>\d+)/qr\.(?P<fmt>png|svg)', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'r_qr' ), 'permission_callback' => array( __CLASS__, 'qr_auth' ) ) );
	}
	public static function cors( $origins ) {
		$cfg = Config::get_published(); $u = $cfg['tickets']['portal_url'] ?? '';
		if ( $u ) { $p = wp_parse_url( $u ); if ( ! empty( $p['host'] ) ) { $origins[] = ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . ( ! empty( $p['port'] ) ? ':' . $p['port'] : '' ); } }
		return $origins;
	}
	private static function shape( $row ) {
		return array( 'valid' => in_array( $row['status'], array( 'issued', 'activated' ), true ), 'code' => self::pretty( $row['code'] ), 'count' => (int) $row['tickets'], 'box' => $row['box'], 'status' => $row['status'], 'activated' => 'activated' === $row['status'], 'activated_at' => $row['activated_at'], 'issued_at' => $row['created_at'] );
	}
	public static function r_ticket( $req ) {
		if ( Leads::rate_limited( 'ticket', 30 ) ) { return new \WP_Error( 'rate', 'too many requests', array( 'status' => 429 ) ); }
		$row = self::by_code( (string) $req['code'] );
		if ( ! $row ) { return new \WP_Error( 'invalid', 'invalid ticket', array( 'status' => 404, 'valid' => false ) ); }
		return self::shape( $row );
	}
	public static function r_activate( $req ) {
		global $wpdb;
		if ( Leads::rate_limited( 'ticket', 30 ) ) { return new \WP_Error( 'rate', 'too many requests', array( 'status' => 429 ) ); }
		$row = self::by_code( (string) $req['code'] );
		if ( ! $row ) { return new \WP_Error( 'invalid', 'invalid ticket', array( 'status' => 404, 'valid' => false ) ); }
		if ( 'void' === $row['status'] ) { return new \WP_Error( 'void', 'ticket void', array( 'status' => 410, 'valid' => false ) ); }
		$user = sanitize_text_field( (string) ( $req->get_param( 'email' ) ?: $req->get_param( 'phone' ) ?: $req->get_param( 'user' ) ) );
		if ( '' === $user ) { return new \WP_Error( 'user', 'email or phone required', array( 'status' => 400 ) ); }
		if ( 'activated' === $row['status'] ) { $out = self::shape( $row ); $out['already'] = true; return $out; }
		$row = self::activate( $row, $user ) ?: $row;
		$out = self::shape( $row ); $out['already'] = false; return $out;
	}
	/** Активира билет (issued → activated) от името на $user (имейл/телефон); hook ansa_promo_ticket_activated. Връща свежия ред или null. */
	public static function activate( $row, $user ) {
		global $wpdb;
		if ( ! $row || 'issued' !== $row['status'] ) { return $row ?: null; }
		$wpdb->update( Schema::table( 'tickets' ), array( 'status' => 'activated', 'activated_at' => current_time( 'mysql' ), 'portal_user' => substr( (string) $user, 0, 191 ), 'ip' => Leads::ip() ), array( 'id' => (int) $row['id'] ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
		$row = self::for_order( $row['order_id'] ); if ( ! $row ) { return null; }
		$o = wc_get_order( (int) $row['order_id'] ); if ( $o ) { $o->add_order_note( 'ansa™ Промо: билетът ' . self::pretty( $row['code'] ) . ' е активиран от ' . $user . '.' ); }
		do_action( 'ansa_promo_ticket_activated', $row, $user );
		return $row;
	}
	public static function qr_auth( $req ) {
		if ( current_user_can( Plugin::CAP ) ) { return true; }
		$key = (string) $req->get_param( 'key' );
		return '' !== $key && hash_equals( self::qr_key( (int) $req['id'] ), $key );
	}
	public static function r_qr( $req ) {
		$oid = (int) $req['id']; $row = self::for_order( $oid );
		if ( ! $row && $req->get_param( 'issue' ) && current_user_can( Plugin::CAP ) ) { $row = self::issue( $oid ); }
		if ( ! $row ) { return new \WP_Error( 'none', 'no ticket for this order', array( 'status' => 404 ) ); }
		$url = self::ticket_url( $row['code'] ); $scale = max( 2, min( 20, (int) ( $req->get_param( 'scale' ) ?: 8 ) ) );
		$png = 'png' === $req['fmt'] ? QR::png( $url, $scale ) : '';
		$body = $png ?: QR::svg( $url, $scale ); $type = $png ? 'image/png' : 'image/svg+xml';
		if ( '' === $body ) { return new \WP_Error( 'qr', 'cannot render', array( 'status' => 500 ) ); }
		nocache_headers(); header( 'Content-Type: ' . $type ); header( 'Content-Length: ' . strlen( $body ) ); header( 'Content-Disposition: inline; filename="ansa-ticket-' . $oid . '.' . ( $png ? 'png' : 'svg' ) . '"' );
		echo $body; exit;
	}

	/* ── админ: списък, CSV, meta box, стикер ── */
	/** Промо поръчките (най-новите първо) с билета им. */
	public static function orders( $args = array() ) {
		$a = array_merge( array( 'limit' => 100, 'paged' => 1, 'status' => array_keys( wc_get_order_statuses() ) ), $args );
		$q = array( 'limit' => $a['limit'], 'paged' => $a['paged'], 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects', 'status' => $a['status'], 'meta_key' => '_ansa_promo_id', 'meta_compare' => 'EXISTS' );
		$out = array();
		foreach ( wc_get_orders( $q ) as $o ) {
			$m = (array) $o->get_meta( '_ansa_promo' ); $t = self::for_order( $o->get_id() );
			$out[] = array( 'id' => $o->get_id(), 'number' => $o->get_order_number(), 'date' => $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd.m.Y H:i' ) : '', 'status' => $o->get_status(), 'email' => $o->get_billing_email(), 'name' => $o->get_formatted_billing_full_name(), 'total' => $o->get_total(), 'gateway' => $o->get_payment_method(),
				'box' => (string) ( $m['box'] ?? '' ), 'packs' => (int) ( $m['packs'] ?? 0 ), 'pct' => $m['pct'] ?? '', 'valid' => ! empty( $m['valid'] ), 'allowance' => (int) ( $m['tickets']['allowance'] ?? 0 ), 'partner' => ! empty( $m['tickets']['partner'] ), 'utm' => (string) ( $m['utm'] ?? '' ),
				'cosm' => $m['cosm'] ? (string) $o->get_meta( '_ansa_promo_cosm_status' ) : '', 'voucher' => (string) $o->get_meta( '_ansa_promo_voucher' ), 'book' => ! empty( $m['book'] ), 'book_sent' => (string) $o->get_meta( '_ansa_promo_book_sent' ),
				'ref_gift' => (int) ( $m['tickets']['ref_bonus'] ?? 0 ), 'ref_from' => (string) ( $m['tickets']['ref_code'] ?? '' ),
				'ticket' => $t ? array( 'id' => (int) $t['id'], 'code' => self::pretty( $t['code'] ), 'tickets' => (int) $t['tickets'], 'bonus' => (int) ( $t['bonus'] ?? 0 ), 'ref_code' => (string) ( $t['ref_code'] ?? '' ), 'status' => $t['status'], 'activated_at' => $t['activated_at'], 'portal_user' => $t['portal_user'] ) : null, 'edit' => $o->get_edit_order_url() );
		}
		return $out;
	}
	public static function csv( $rows ) {
		$fh = fopen( 'php://temp', 'w+' ); fputs( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, array( 'order', 'date', 'status', 'email', 'name', 'total', 'gateway', 'box', 'packs', 'pct', 'valid', 'tickets', 'referral_bonus', 'code', 'ticket_status', 'activated_at', 'portal_user', 'partner', 'ref_code', 'ref_from', 'utm', 'set', 'voucher', 'book', 'book_sent' ), ';' );
		foreach ( $rows as $r ) { fputcsv( $fh, array( $r['number'], $r['date'], $r['status'], $r['email'], $r['name'], $r['total'], $r['gateway'], $r['box'], $r['packs'], $r['pct'], $r['valid'] ? 1 : 0, $r['ticket'] ? $r['ticket']['tickets'] : $r['allowance'], $r['ticket'] ? $r['ticket']['bonus'] : 0, $r['ticket'] ? $r['ticket']['code'] : '', $r['ticket'] ? $r['ticket']['status'] : '', $r['ticket'] ? $r['ticket']['activated_at'] : '', $r['ticket'] ? $r['ticket']['portal_user'] : '', $r['partner'] ? 1 : 0, $r['ticket'] ? $r['ticket']['ref_code'] : '', $r['ref_from'], $r['utm'], $r['cosm'], $r['voucher'], $r['book'] ? 1 : 0, $r['book_sent'] ), ';' ); }
		rewind( $fh ); $csv = stream_get_contents( $fh ); fclose( $fh ); return $csv;
	}
	public static function metabox( $type, $post_or_order ) {
		$screen = class_exists( '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		if ( $type !== $screen && 'shop_order' !== $type ) { return; }
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order );
		if ( ! $order || ! $order->get_meta( '_ansa_promo_id' ) ) { return; }
		add_meta_box( 'ansa-promo-order', '🎁 ansa™ Промо', array( __CLASS__, 'metabox_html' ), $type, 'side', 'high' );
	}
	public static function metabox_html( $post_or_order ) {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order ); if ( ! $order ) { return; }
		$m = (array) $order->get_meta( '_ansa_promo' ); $t = self::for_order( $order->get_id() ); $cfg = Config::get_published(); $box = Config::box( $cfg, (string) ( $m['box'] ?? '' ) );
		echo '<p><b>' . esc_html( $box ? $box['ic'] . ' ' . $box['name'] : (string) ( $m['box'] ?? '' ) ) . '</b> · ' . (int) ( $m['packs'] ?? 0 ) . ' опак. · −' . esc_html( (string) ( $m['pct'] ?? '' ) ) . '%' . ( empty( $m['valid'] ) ? ' · <span style="color:#cf222e">НЕПЪЛНА кутия</span>' : '' ) . '</p>';
		if ( $t ) {
			echo '<p>Билет: <code style="font-size:14px">' . esc_html( self::pretty( $t['code'] ) ) . '</code><br>' . (int) $t['tickets'] . ' билет(а) · статус <b>' . esc_html( $t['status'] ) . '</b>' . ( $t['activated_at'] ? '<br>активиран ' . esc_html( $t['activated_at'] ) . ' от ' . esc_html( (string) $t['portal_user'] ) : '' ) . '</p>';
			if ( ! empty( $t['ref_code'] ) || (int) ( $t['bonus'] ?? 0 ) > 0 ) { echo '<p>🎲 Томбола: код за препоръка <code>' . esc_html( (string) $t['ref_code'] ) . '</code> · препоръки: ' . count( Tombola::referrals( (string) $t['ref_code'], 'credited' ) ) . ' · бонус <b>' . (int) ( $t['bonus'] ?? 0 ) . '</b> билет(а) → общо <b>' . ( (int) $t['tickets'] + (int) ( $t['bonus'] ?? 0 ) ) . '</b></p>'; }
			echo '<p style="text-align:center"><img src="' . esc_attr( QR::data_uri( self::ticket_url( $t['code'] ), 4, 2 ) ) . '" alt="QR" style="width:140px;height:140px;image-rendering:pixelated"></p>';
			echo '<p><a class="button button-primary" target="_blank" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ansa_promo_ticket_print&order=' . $order->get_id() ), 'ansa_promo_ticket_print' ) ) . '">🖨 Стикер за пратката</a> <a class="button" target="_blank" href="' . esc_url( self::qr_url( $order->get_id() ) ) . '">PNG</a></p>';
		} else {
			echo '<p>Няма издаден билет' . ( empty( $m['valid'] ) ? ' (кутията е непълна).' : ' — издава се при „Обработва се“/„Изпълнена“.' ) . '</p>';
			if ( ! empty( $m['valid'] ) ) { echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ansa_promo_ticket_issue&order=' . $order->get_id() ), 'ansa_promo_ticket_issue' ) ) . '">🎟 Издай билет сега</a></p>'; }
		}
		if ( ! empty( $m['cosm'] ) ) { echo '<p>Сет: <b>' . esc_html( (string) $order->get_meta( '_ansa_promo_cosm_status' ) ) . '</b>' . ( $order->get_meta( '_ansa_promo_voucher' ) ? ' · ваучер <code>' . esc_html( $order->get_meta( '_ansa_promo_voucher' ) ) . '</code>' : '' ) . '</p>'; }
		if ( ! empty( $m['book'] ) ) { echo '<p>Книга: ' . ( $order->get_meta( '_ansa_promo_book_sent' ) ? 'изпратена (' . esc_html( $order->get_meta( '_ansa_promo_book_sent' ) ) . ')' : 'при „Изпълнена“' ) . '</p>'; }
		if ( ! empty( $m['tickets']['ref_code'] ) ) { echo '<p>По препоръка: код <code>' . esc_html( (string) $m['tickets']['ref_code'] ) . '</code> (поръчка #' . (int) ( $m['tickets']['ref_order'] ?? 0 ) . ') · подарък +' . (int) ( $m['tickets']['ref_bonus'] ?? 0 ) . ( ! empty( $m['tickets']['ref_self'] ) ? ' · <span style="color:#9a6700">същият клиент</span>' : '' ) . '</p>'; }
		if ( ! empty( $m['utm'] ) ) { echo '<p>UTM: <code>' . esc_html( $m['utm'] ) . '</code></p>'; }
		if ( Tombola::enabled( $cfg ) ) { echo '<p><small>Линк за SMS при доставка: <code>' . esc_html( Tombola::url( $cfg ) ) . '</code></small></p>'; }
	}
	public static function issue_from_admin() {
		if ( ! current_user_can( Plugin::CAP ) ) { wp_die( 'Няма достъп.' ); } check_admin_referer( 'ansa_promo_ticket_issue' );
		$oid = (int) ( $_GET['order'] ?? 0 ); self::issue( $oid, true );
		$o = wc_get_order( $oid ); wp_safe_redirect( $o ? $o->get_edit_order_url() : admin_url() ); exit;
	}
	/** Стикерът за пратката: QR + код + кутия, готов за печат. */
	public static function print_page() {
		if ( ! current_user_can( Plugin::CAP ) ) { wp_die( 'Няма достъп.' ); } check_admin_referer( 'ansa_promo_ticket_print' );
		$oid = (int) ( $_GET['order'] ?? 0 ); $order = wc_get_order( $oid ); $t = $order ? self::for_order( $oid ) : null;
		if ( ! $order || ! $t ) { wp_die( 'Няма билет за тази поръчка.' ); }
		$cfg = Config::get_published(); $box = Config::box( $cfg, $t['box'] ); $copy = Copy::effective( $cfg['copy'] );
		$v = $box ? Cart::vars( $cfg, $box ) : array(); $v['tickets'] = (int) $t['tickets']; $v['bileta'] = 1 === (int) $t['tickets'] ? 'билет' : 'билета'; $v['code'] = self::pretty( $t['code'] ); $v['portal_url'] = $cfg['tickets']['portal_url'] ?: ( Tombola::enabled( $cfg ) ? Tombola::url( $cfg ) : Cart::promo_url( $cfg ) );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="bg"><head><meta charset="utf-8"><title>Стикер · поръчка #' . esc_html( $order->get_order_number() ) . '</title><style>body{font-family:Nunito,system-ui,sans-serif;margin:0;padding:24px;color:#1a1a2e}.st{width:100mm;border:1.5px dashed #c2410c;border-radius:12px;padding:10mm;text-align:center;page-break-inside:avoid}.st img{width:60mm;height:60mm;image-rendering:pixelated}.st h1{font-size:18px;margin:6px 0 2px}.st .code{font:900 22px/1.2 Menlo,Consolas,monospace;letter-spacing:1px;margin:6px 0}.st p{margin:4px 0;font-size:12px}.st small{font-size:10px;color:#555}@media print{body{padding:0}.np{display:none}}</style></head><body>';
		echo '<p class="np"><button onclick="window.print()">🖨 Печат</button> · поръчка #' . esc_html( $order->get_order_number() ) . ' · ' . esc_html( $order->get_formatted_billing_full_name() ) . '</p>';
		echo '<div class="st"><h1>' . wp_kses_post( Copy::render( $copy['st.h'] ?? '🎟️ Твоите билети за томболата', $v ) ) . '</h1><p>' . wp_kses_post( Copy::render( $copy['st.p'] ?? '{{tickets}} {{bileta}} · {{box_ic}} {{box}}', $v ) ) . '</p>';
		echo '<img src="' . esc_attr( QR::data_uri( self::ticket_url( $t['code'] ), 6, 2 ) ) . '" alt="QR"><div class="code">' . esc_html( self::pretty( $t['code'] ) ) . '</div>';
		echo '<p>' . wp_kses_post( Copy::render( $copy['st.how'] ?? 'Сканирай QR кода или въведи кода на {{portal_url}}, за да регистрираш билетите си.', $v ) ) . '</p><small>' . esc_html( Copy::render( $copy['st.foot'] ?? 'Поръчка #{{order}} · {{name}}', array_merge( $v, array( 'order' => $order->get_order_number(), 'name' => $cfg['name'] ) ) ) ) . '</small></div></body></html>';
		exit;
	}
}
