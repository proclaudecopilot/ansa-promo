<?php
/**
 * ansa™ Промо — 📋 работата по изоставените колички от 📈 Insights (1.51.0, човекът: „искам да може да се редактират от insights,
 * да може да се отбелязва като recovered за да се маха от изоставени и тн, пълната функционалност“).
 *
 * Една количка (ред на FunnelKit, src=cart) или сесия в промото (src=sess — човек с имейл от попъпа и кутия, но без имейл в чекаута,
 * т.е. без ред във FKA) има ЕДИН запис за работата: статус (за прозвъняване · не вдига · обратно обаждане · ще поръча ·
 * възстановена · отказала · грешен номер), бележка, кога да се звъни пак, поръчката, кой и кога, история на промените.
 *
 *  - ✅ възстановена: записва се и във FunnelKit (status 4 + order_id, ако има — търси се поръчка със същия имейл след количката),
 *    за да спре flow-ът „Cart Abandoned“ и да излезе от „изоставени“ и във FKA; тагът sezon-kolichka пада.
 *  - ✖ отказала: във FKA става „изгубена“ (status 2) — flow-ът не тръгва; тагът пада.
 *  - връщане към „за прозвъняване“/„обратно обаждане“/…: ако FKA е 2/4 без поръчка → пак 1 (recoverable).
 *  - по подразбиране екранът 🛒 скрива възстановените и отказалите (табове „работа“: отворени · уговорени · приключени · всички).
 *
 * Действието минава по пътя на разхода/замразяването: admin-post (ins_cart_work) и порталът (ap_action=cart_work с CSRF) →
 * Insights::handle_action → Cart_Work::save(). Таблица {prefix}ansa_promo_cart_work (DB ver 11).
 *
 * @package AnsaPromo
 * @since 1.51.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Cart_Work {

	/** Статусите: ключ → [етикет, тон, емоджи]. '' = няма запис = „за прозвъняване“. */
	const STATUSES = array(
		'new'       => array( 'за прозвъняване', '', '📞' ),
		'noanswer'  => array( 'не вдига', 'muted', '📵' ),
		'callback'  => array( 'обратно обаждане', 'info', '🔁' ),
		'promised'  => array( 'ще поръча', 'info', '🤝' ),
		'recovered' => array( 'възстановена', 'ok', '✅' ),
		'declined'  => array( 'отказала', 'warn', '✖' ),
		'wrong'     => array( 'грешен номер', 'muted', '❌' ),
	);
	/** Филтърът „работа“ на екрана 🛒. */
	const FILTERS = array( 'open' => 'отворени', 'callback' => 'уговорени', 'done' => 'приключени', 'all' => 'всички' ); /* 1.53.0: „за обаждане“ → „уговорени“ (обратно обаждане + ще поръча) — да не се бърка със „за прозвъняване“ */
	const LOG_MAX = 20;
	/** 1.52.0 (скриптът): жестът за Вид 2 — ключ → етикет. */
	const GESTURES = array( '' => 'без жест', 'gift' => '🎁 колагенова маска', 'ship' => '🚚 безплатна доставка', 'pct' => '💶 отстъпка' );
	public static function gesture_label( $g ) { return self::GESTURES[ $g ] ?? (string) $g; }

	public static function table() { global $wpdb; return $wpdb->prefix . 'ansa_promo_cart_work'; }
	public static function label( $st ) { return self::STATUSES[ $st ][0] ?? ( '' === (string) $st ? self::STATUSES['new'][0] : (string) $st ); }
	public static function tone( $st ) { return self::STATUSES[ $st ][1] ?? ''; }
	public static function icon( $st ) { return self::STATUSES[ $st ][2] ?? '📞'; }
	/** Приключена ли е работата (излиза от „изоставени“). */
	public static function is_done( $st ) { return in_array( (string) $st, array( 'recovered', 'declined' ), true ); }
	public static function table_ok() { global $wpdb; $t = self::table(); return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t; }

	/* ── четене ── */
	/** Записите за много редове наведнъж: [ src_id => row ]. */
	public static function get_many( $src, $ids ) {
		global $wpdb; $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) ); if ( ! $ids || ! self::table_ok() ) { return array(); }
		$out = array();
		foreach ( array_chunk( $ids, 300 ) as $chunk ) { $in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) ); foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE src = %s AND src_id IN ($in)", array_merge( array( $src ), $chunk ) ), ARRAY_A ) as $r ) { $out[ (int) $r['src_id'] ] = self::row( $r ); } }
		return $out;
	}
	public static function get( $src, $id ) { $m = self::get_many( $src, array( $id ) ); return $m[ (int) $id ] ?? null; }
	/** Записите по имейл (и количка, и сесия): за да се вижда работата на човека, където и да е. */
	public static function by_email( $email ) {
		global $wpdb; $email = strtolower( trim( (string) $email ) ); if ( '' === $email || ! self::table_ok() ) { return array(); }
		return array_map( array( __CLASS__, 'row' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s ORDER BY updated_at DESC', $email ), ARRAY_A ) );
	}
	private static function row( $r ) {
		$log = json_decode( (string) ( $r['log'] ?? '' ), true );
		return array( 'id' => (int) $r['id'], 'src' => (string) $r['src'], 'src_id' => (int) $r['src_id'], 'email' => (string) $r['email'], 'status' => (string) $r['status'], 'gesture' => (string) ( $r['gesture'] ?? '' ), 'note' => (string) $r['note'], 'callback_at' => (string) ( $r['callback_at'] ?? '' ), 'order_id' => (int) ( $r['order_id'] ?? 0 ), 'by' => (string) ( $r['by_user'] ?? '' ), 'updated_at' => (string) $r['updated_at'], 'created_at' => (string) $r['created_at'], 'log' => is_array( $log ) ? $log : array() );
	}

	/* ── запис ── */
	/**
	 * Записва работата. $d: src (cart|sess), id, status, note, cb (datetime-local „Y-m-dTH:i“ или „Y-m-d H:i“), order (ID на поръчка),
	 * email (резерва, ако редът не носи имейл). Връща [ok, съобщение].
	 */
	public static function save( $d, $by = '' ) {
		global $wpdb;
		if ( ! self::table_ok() ) { return array( false, 'Няма таблица за работата по количките (ansa_promo_cart_work) — деактивирай и активирай плъгина.' ); }
		$src = 'sess' === ( $d['src'] ?? '' ) ? 'sess' : 'cart'; $id = (int) ( $d['id'] ?? 0 ); if ( $id <= 0 ) { return array( false, 'Количка: липсва ID.' ); }
		$st = sanitize_key( (string) ( $d['status'] ?? 'new' ) ); if ( ! isset( self::STATUSES[ $st ] ) ) { $st = 'new'; }
		$was = self::get( $src, $id );
		$note = array_key_exists( 'note', $d ) ? substr( trim( wp_strip_all_tags( (string) $d['note'] ) ), 0, 500 ) : ( $was ? $was['note'] : '' ); /* 1.52.0: без подадена бележка — старата остава (автоматичното „възстановена“ от поръчката) */
		$gest = array_key_exists( 'gesture', $d ) ? (string) $d['gesture'] : ( $was ? $was['gesture'] : '' ); if ( ! isset( self::GESTURES[ $gest ] ) ) { $gest = ''; }
		$cb = self::parse_dt( (string) ( $d['cb'] ?? '' ) ); if ( 'callback' !== $st ) { $cb = ''; }
		$order = (int) preg_replace( '/\D+/', '', (string) ( $d['order'] ?? '' ) );
		/* редът: имейл + статус във FKA / сесията */
		$email = ''; $fka = null;
		if ( 'cart' === $src ) { $fka = self::fka_row( $id ); if ( ! $fka ) { return array( false, 'Количка #' . $id . ' не е намерена във FunnelKit.' ); } $email = strtolower( (string) $fka['email'] ); if ( $order <= 0 && (int) $fka['order_id'] > 0 ) { $order = (int) $fka['order_id']; } }
		else { $email = strtolower( trim( (string) ( $d['email'] ?? '' ) ) ); if ( '' === $email ) { $email = self::session_email( $id ); } }
		/* ✅ възстановена без посочена поръчка → търси се поръчка със същия имейл след количката */
		if ( 'recovered' === $st && $order <= 0 && '' !== $email ) { $order = self::find_order( $email, $fka ? (string) $fka['created_time'] : '' ); }
		if ( $order > 0 && function_exists( 'wc_get_order' ) ) { $o = wc_get_order( $order ); if ( ! $o ) { return array( false, 'Поръчка #' . $order . ' не съществува.' ); } if ( '' === $email && method_exists( $o, 'get_billing_email' ) ) { $email = strtolower( (string) $o->get_billing_email() ); } }
		$now = current_time( 'mysql' ); $by = substr( (string) $by, 0, 64 );
		$log = $was ? $was['log'] : array(); array_unshift( $log, array( 'at' => $now, 'by' => $by, 'st' => $st, 'note' => $note, 'cb' => $cb, 'order' => $order, 'g' => $gest ) ); $log = array_slice( $log, 0, self::LOG_MAX );
		$data = array( 'src' => $src, 'src_id' => $id, 'email' => substr( $email, 0, 191 ), 'status' => $st, 'gesture' => $gest, 'note' => $note, 'callback_at' => '' !== $cb ? $cb : null, 'order_id' => $order > 0 ? $order : null, 'by_user' => $by, 'updated_at' => $now, 'log' => wp_json_encode( $log ) );
		if ( $was ) { $r = $wpdb->update( self::table(), $data, array( 'id' => $was['id'] ) ); } else { $data['created_at'] = $now; $r = $wpdb->insert( self::table(), $data ); }
		if ( false === $r ) { return array( false, 'Количка: записът не мина (' . $wpdb->last_error . ').' ); }
		/* FunnelKit: възстановена / изгубена / пак recoverable; тагът на контакта */
		$fx = '';
		if ( $fka ) { $fx = self::sync_fka( $fka, $st, $order ); }
		if ( '' !== $email && class_exists( __NAMESPACE__ . '\\Cart_Sync' ) && Cart_Sync::enabled() && Cart_Sync::available() && ( self::is_done( $st ) || 'wrong' === $st ) ) { try { Cart_Sync::sync_email( $email, false, 'work' ); } catch ( \Throwable $e ) { /* тагът не е критичен */ } }
		$what = ( 'cart' === $src ? 'Количка #' : 'Сесия #' ) . $id . ( '' !== $email ? ' (' . $email . ')' : '' );
		return array( true, $what . ': ' . self::icon( $st ) . ' ' . self::label( $st ) . ( '' !== $gest ? ' · ' . self::gesture_label( $gest ) : '' ) . ( 'callback' === $st && '' !== $cb ? ' на ' . date_i18n( 'd.m H:i', strtotime( $cb ) ) : '' ) . ( $order > 0 ? ' · поръчка #' . $order : ( 'recovered' === $st ? ' · поръчка не е намерена по имейла — впиши я в полето' : '' ) ) . ( '' !== $note ? ' · „' . $note . '“' : '' ) . $fx );
	}
	/** Статусът във FunnelKit според работата. Връща текст за съобщението. */
	private static function sync_fka( $fka, $st, $order ) {
		global $wpdb; $t = Cart_Sync::table(); $cur = (int) $fka['status']; $set = null;
		if ( 'recovered' === $st ) { $set = array( 'status' => 4 ); if ( $order > 0 ) { $set['order_id'] = $order; } }
		elseif ( 'declined' === $st || 'wrong' === $st ) { if ( (int) $fka['order_id'] <= 0 ) { $set = array( 'status' => 2 ); } }
		elseif ( in_array( $cur, array( 2, 4 ), true ) && (int) $fka['order_id'] <= 0 ) { $set = array( 'status' => 1 ); } /* върната в работа → пак recoverable */
		if ( ! $set ) { return ''; }
		if ( isset( $set['status'] ) && $set['status'] === $cur && ! isset( $set['order_id'] ) ) { return ''; }
		$r = $wpdb->update( $t, $set, array( 'ID' => (int) $fka['ID'] ) ); /* 1.55.0: без last_modified — по него се съди дали поръчката е след количката */
		if ( false === $r ) { return ' · FunnelKit: не се записа (' . $wpdb->last_error . ')'; }
		return ' · FunnelKit: ' . ( 4 === (int) $set['status'] ? 'възстановена' : ( 2 === (int) $set['status'] ? 'изгубена' : 'пак изоставена (recoverable)' ) );
	}
	private static function fka_row( $id ) { global $wpdb; $t = Cart_Sync::table(); if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { return null; } return $wpdb->get_row( $wpdb->prepare( "SELECT ID, email, status, order_id, created_time FROM {$t} WHERE ID = %d", (int) $id ), ARRAY_A ); }
	private static function session_email( $sid ) { global $wpdb; return strtolower( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT l.email FROM ' . Schema::table( 'sessions' ) . ' s INNER JOIN ' . Schema::table( 'leads' ) . ' l ON l.id = s.lead_id WHERE s.id = %d', (int) $sid ) ) ); }
	/** Поръчка със същия billing имейл, направена след количката (най-новата). 0 = няма. */
	public static function find_order( $email, $after = '' ) {
		if ( ! function_exists( 'wc_get_orders' ) || '' === $email ) { return 0; }
		$a = array( 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'ids', 'billing_email' => $email );
		$t = $after ? strtotime( $after ) : 0; if ( $t ) { $a['date_created'] = ( $t - HOUR_IN_SECONDS ) . '...' . ( time() + DAY_IN_SECONDS ); } /* от час преди количката до сега */
		try { $ids = (array) wc_get_orders( $a ); } catch ( \Throwable $e ) { $ids = array(); }
		foreach ( $ids as $oid ) { $o = wc_get_order( (int) $oid ); if ( $o && ! in_array( $o->get_status(), array( 'cancelled', 'failed', 'refunded', 'trash' ), true ) ) { return (int) $oid; } }
		return 0;
	}
	/** „2026-11-20T14:30“ / „2026-11-20 14:30“ → mysql; празно при невалидно. */
	public static function parse_dt( $v ) { $v = trim( str_replace( 'T', ' ', (string) $v ) ); if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $v ) ) { return ''; } $t = strtotime( $v ); return $t ? date( 'Y-m-d H:i:00', $t ) : ''; }

	/* ── филтърът „работа“ ── */
	public static function filter_key( $v, $default = 'open' ) { $v = sanitize_key( (string) $v ); return isset( self::FILTERS[ $v ] ) ? $v : $default; }
	/** Минава ли редът през филтъра. $w = записът (или null). */
	public static function passes( $filter, $w, $order_id = 0 ) {
		$st = $w ? $w['status'] : ''; $done = self::is_done( $st ) || (int) $order_id > 0;
		switch ( $filter ) {
			case 'open': return ! $done;
			case 'callback': return 'callback' === $st || 'promised' === $st;
			case 'done': return $done;
		}
		return true;
	}

	/* ── екранът ── */
	/** 1.53.0: уговореното обратно обаждане е минало (просрочено) — редът отива най-горе в опашката. */
	public static function is_due( $w, $now = null ) {
		if ( ! $w || 'callback' !== $w['status'] || empty( $w['callback_at'] ) ) { return false; }
		$t = strtotime( (string) $w['callback_at'] ); return $t && $t <= ( $now ?: current_time( 'timestamp' ) );
	}
	/** 1.53.0: само състоянието на работата (етикет + кога да се звъни пак, жест, поръчка, бележка, кой и кога) — статичен текст, нищо не се цъка. */
	public static function status_html( $w, $now = null ) {
		$st = $w ? $w['status'] : ''; $tone = self::tone( $st );
		$h = '<span class="ap-pill ap-pill--st' . ( $tone ? ' ap-pill--' . $tone : '' ) . '" title="' . esc_attr( $w ? ( $w['by'] ? $w['by'] . ' · ' : '' ) . $w['updated_at'] : 'още никой не е работил по нея' ) . '">' . self::icon( $st ) . ' ' . esc_html( self::label( $st ) ) . '</span>';
		if ( ! $w ) { return $h; }
		if ( 'callback' === $st && $w['callback_at'] ) { $due = self::is_due( $w, $now ); $h .= '<span class="ap-work__cb' . ( $due ? ' is-due' : '' ) . '"' . ( $due ? ' title="уговореният час е минал — звънни първо на нея"' : '' ) . '>🔁 ' . esc_html( date_i18n( 'd.m H:i', strtotime( $w['callback_at'] ) ) ) . ( $due ? ' <b>просрочено</b>' : '' ) . '</span>'; }
		if ( '' !== $w['gesture'] ) { $h .= '<span class="ap-work__g">' . esc_html( self::gesture_label( $w['gesture'] ) ) . '</span>'; }
		if ( $w['order_id'] > 0 ) { $h .= '<span class="ap-work__o">поръчка #' . (int) $w['order_id'] . '</span>'; }
		if ( '' !== $w['note'] ) { $h .= '<span class="ap-work__n">„' . esc_html( $w['note'] ) . '“</span>'; }
		return $h . '<span class="ap-work__by">' . esc_html( ( $w['by'] ? $w['by'] . ' · ' : '' ) . date_i18n( 'd.m H:i', strtotime( $w['updated_at'] ) ) ) . '</span>';
	}
	/** 1.53.0: бутонът „Отбележи резултат“ + изскачащата форма (details, работи и без JS) и историята. $src cart|sess, $id, $email, $w = записът или null,
	 *  $ret = скрити полета за връщане (view/q/kind/work/from/to). Бързите бутони са СЛЕД избора в HTML-а (последната стойност на status печели), на екрана са първи (CSS order). */
	public static function form_html( $src, $id, $email, $w, $ret = array() ) {
		$st = $w ? $w['status'] : '';
		ob_start(); Insights::action_form_open( 'cart_work', ' class="ap-work__form"' );
		foreach ( array_merge( array( 'src' => $src, 'id' => (int) $id, 'email' => (string) $email ), $ret ) as $k => $v ) { if ( '' !== (string) $v ) { echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">'; } }
		echo '<div class="ap-work__ttl"><b>Резултат от обаждането</b>' . ( '' !== (string) $email ? '<small>' . esc_html( (string) $email ) . '</small>' : '' ) . '</div>';
		echo '<label class="ap-work__f"><span>Статус</span><select name="status">'; foreach ( self::STATUSES as $k => $v ) { echo '<option value="' . esc_attr( $k ) . '"' . ( $k === ( $st ?: 'new' ) ? ' selected' : '' ) . '>' . esc_html( $v[2] . ' ' . $v[0] ) . '</option>'; } echo '</select></label>';
		echo '<label class="ap-work__f ap-work__f--cb" title="кога да се звъни пак (при „обратно обаждане“)"><span>Звънни пак на</span><input type="datetime-local" name="cb" value="' . esc_attr( $w && $w['callback_at'] ? str_replace( ' ', 'T', substr( $w['callback_at'], 0, 16 ) ) : '' ) . '"></label>';
		echo '<label class="ap-work__f ap-work__f--order" title="номер на поръчката при „възстановена“ — празно = търси се по имейла"><span>Поръчка №</span><input type="text" name="order" value="' . esc_attr( $w && $w['order_id'] > 0 ? (string) $w['order_id'] : '' ) . '" inputmode="numeric" placeholder="празно = търси по имейла"></label>';
		echo '<label class="ap-work__f" title="жестът по скрипта — само за Вид 2 (колебае се); един на поръчка"><span>Жест</span><select name="gesture">'; foreach ( self::GESTURES as $k => $v ) { echo '<option value="' . esc_attr( $k ) . '"' . ( $k === ( $w ? $w['gesture'] : '' ) ? ' selected' : '' ) . '>' . esc_html( $v ) . '</option>'; } echo '</select></label>';
		echo '<label class="ap-work__f"><span>Бележка</span><textarea name="note" rows="2" maxlength="500" placeholder="мнение · въпроси · защо не е минала · обещано обаждане" title="записките по скрипта: мнението за продуктите, въпросите и притесненията, защо поръчката не е завършена, предложен ли е жест, обещано обратно обаждане">' . esc_html( $w ? $w['note'] : '' ) . '</textarea></label>';
		echo '<button class="ap-abtn ap-abtn--go ap-work__save" type="submit">Запази</button>';
		echo '<div class="ap-work__q"><span>С един клик</span><button class="ap-abtn ap-abtn--line" name="status" value="noanswer" title="не вдига — остава в опашката">📵 Не вдига</button><button class="ap-abtn ap-abtn--line" name="status" value="recovered" title="възстановена — поръча (и във FunnelKit)">✅ Поръча</button><button class="ap-abtn ap-abtn--line" name="status" value="declined" title="отказала (във FunnelKit: изгубена)">✖ Отказа</button></div></form>';
		$form = ob_get_clean();
		$hist = '';
		if ( $w && count( $w['log'] ) > 1 ) { $hist = '<div class="ap-work__hl">История</div><ul class="ap-work__h">'; foreach ( array_slice( $w['log'], 1, 6 ) as $l ) { $hist .= '<li><small>' . esc_html( date_i18n( 'd.m H:i', strtotime( $l['at'] ) ) . ' · ' . ( $l['by'] ?: '—' ) . ' · ' . self::label( $l['st'] ) . ( ! empty( $l['g'] ) ? ' · ' . self::gesture_label( $l['g'] ) : '' ) . ( $l['note'] ? ' · „' . $l['note'] . '“' : '' ) ) . '</small></li>'; } $hist .= '</ul>'; }
		return '<details class="ap-work__d ap-pop"><summary class="ap-abtn ap-abtn--ghost">📝 ' . ( $w ? 'Промени резултата' : 'Отбележи резултат' ) . '</summary><div class="ap-work__p">' . $form . $hist . '</div></details>'; /* 1.51.2: панелът е absolute — не разширява колоната */
	}
	/** Състоянието + формата за реда (🧾 Сесии, 🟢 На живо; на 🛒 двете са в отделни колони). */
	public static function widget( $src, $id, $email, $w, $ret = array() ) {
		return '<div class="ap-work">' . self::status_html( $w ) . self::form_html( $src, $id, $email, $w, $ret ) . '</div>';
	}
	/** 1.53.0: стиловете на 🛒 и на 📋 (assets/insights-carts.css) — печатат се веднъж от Insights::styles(), за админа и за портала. */
	public static function styles() {
		static $css = null; if ( null === $css ) { $f = ANSA_PROMO_PATH . 'assets/insights-carts.css'; $css = is_readable( $f ) ? (string) file_get_contents( $f ) : ''; $css = trim( (string) preg_replace( '~/\*.*?\*/~s', '', $css ) ); }
		return $css;
	}
	/** 1.53.0: колко реда има във всеки филтър „работа“ — за броячите в табовете. $rows = количките, $wm = записите по ID. */
	public static function filter_counts( $rows, $wm ) {
		$c = array_fill_keys( array_keys( self::FILTERS ), 0 );
		foreach ( (array) $rows as $x ) { foreach ( $c as $k => $n ) { if ( self::passes( $k, $wm[ (int) $x['id'] ] ?? null, $x['order_id'] ?? 0 ) ) { $c[ $k ]++; } } }
		return $c;
	}
	/** 1.53.0: табовете „работа“ с броячи (навигация — линкове). $due = колко уговорени обаждания са просрочени. */
	public static function filter_tabs( $cur, $counts = array(), $due = 0 ) {
		$tip = array( 'open' => 'без възстановените и отказалите — това е опашката за обаждане', 'callback' => 'с уговорка: „обратно обаждане“ и „ще поръча“', 'done' => 'възстановени, отказали и с поръчка', 'all' => 'всичко за периода' );
		$h = '<nav class="apc-tabs" aria-label="Работа по количките">';
		foreach ( self::FILTERS as $k => $v ) { $h .= '<a class="apc-tab' . ( $k === $cur ? ' on' : '' ) . '" href="' . esc_url( Insights::url( array( 'work' => $k ) ) ) . '" title="' . esc_attr( $tip[ $k ] ) . '"' . ( $k === $cur ? ' aria-current="page"' : '' ) . '>' . esc_html( $v ) . ( isset( $counts[ $k ] ) ? ' <b>' . (int) $counts[ $k ] . '</b>' : '' ) . ( 'callback' === $k && $due > 0 ? ' <i class="apc-tab__due" title="просрочени обратни обаждания">' . (int) $due . ' просрочени</i>' : '' ) . '</a>'; }
		return $h . '</nav>';
	}
}
