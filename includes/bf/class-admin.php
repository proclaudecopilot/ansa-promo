<?php
/**
 * ansa™ Промо — Черен петък 2026: екранът 🖤 Черен петък в админ приложението (таб bf: Състояние · Настройки · Текстове · Лийдове · Поръчки).
 *
 * Същият модел като Сезона: чернова ↔ публикувано, 💾 Запази / 🚀 Публикувай / Откажи. Формите са admin-post `ansa_bf` (свой nonce).
 *
 * @package AnsaPromo
 * @since 1.45.0
 */
namespace AnsaPromo\BF;

use AnsaPromo\Catalog;
use AnsaPromo\Lead_Sync;
use AnsaPromo\Merge;
use AnsaPromo\Plugin;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const NONCE = 'ansa_bf_admin';
	const TAB   = 'bf';

	public static function register() { add_action( 'admin_post_ansa_bf', array( __CLASS__, 'handle' ) ); }
	public static function views() { return array( 'status' => '🩺 Състояние', 'settings' => '⚙️ Настройки', 'copy' => '✏️ Текстове', 'leads' => '📨 Лийдове', 'orders' => '🧾 Поръчки' ); }
	public static function url( $view = 'status', $extra = array() ) { return add_query_arg( array_merge( array( 'tab' => self::TAB, 'view' => $view ), $extra ), admin_url( 'admin.php?page=' . Plugin::MENU_SLUG ) ); }
	public static function current_view() { $v = sanitize_key( (string) ( $_GET['view'] ?? 'status' ) ); return isset( self::views()[ $v ] ) ? $v : 'status'; }
	private static function form_open( $do, $view = 'status', $extra = '' ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . $extra . '>'; wp_nonce_field( self::NONCE ); echo '<input type="hidden" name="action" value="ansa_bf"><input type="hidden" name="view" value="' . esc_attr( $view ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">'; }
	private static function fmt( $n ) { return Cart::fmt( $n ); }

	/* ── продуктите като текст: key | sku | id | име | подзаглавие | категория | флагове | снимка ── */
	public static function products_text( $cfg ) {
		$o = array( '# key | SKU (вариацията „1 опаковка“) | WC id | име | подзаглавие | категория | флагове: gift (само подарък) new (НОВО) fav (най-продаван) off (скрит) | снимка (attachment id или URL)' );
		foreach ( (array) $cfg['products'] as $p ) { $fl = array(); if ( ! empty( $p['gift_only'] ) ) { $fl[] = 'gift'; } if ( ! empty( $p['is_new'] ) ) { $fl[] = 'new'; } if ( ! empty( $p['fav'] ) ) { $fl[] = 'fav'; } if ( empty( $p['enabled'] ) ) { $fl[] = 'off'; } $o[] = implode( ' | ', array( $p['key'], $p['sku'], $p['product_id'] ?: '', $p['name'], $p['sub'], $p['cat'], implode( ' ', $fl ), $p['img'] ) ); }
		return implode( "\n", $o );
	}
	public static function parse_products( $txt, $old ) {
		$byk = array(); foreach ( (array) $old as $p ) { $byk[ $p['key'] ] = $p; } $out = array();
		foreach ( preg_split( '/\r?\n/', (string) $txt ) as $ln ) {
			$ln = trim( $ln ); if ( '' === $ln || '#' === $ln[0] ) { continue; }
			$c = array_map( 'trim', explode( '|', $ln ) ); $key = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) ( $c[0] ?? '' ) ) ); if ( '' === $key ) { continue; }
			$fl = preg_split( '/[\s,]+/', strtolower( (string) ( $c[6] ?? '' ) ) );
			$p = isset( $byk[ $key ] ) ? $byk[ $key ] : Config::product_defaults();
			$p['key'] = $key; $p['sku'] = (string) ( $c[1] ?? '' ); $p['product_id'] = (int) ( $c[2] ?? 0 ); $p['name'] = (string) ( $c[3] ?? '' ); $p['sub'] = (string) ( $c[4] ?? '' ); $p['cat'] = (string) ( $c[5] ?? '' );
			$p['gift_only'] = in_array( 'gift', $fl, true ); $p['is_new'] = in_array( 'new', $fl, true ); $p['fav'] = in_array( 'fav', $fl, true ); $p['enabled'] = ! in_array( 'off', $fl, true ); $p['img'] = (string) ( $c[7] ?? '' );
			$out[] = $p;
		}
		return $out;
	}

	/* ── admin-post ── */
	public static function handle() {
		if ( ! current_user_can( Plugin::CAP ) ) { wp_die( 'Нямаш права.' ); }
		check_admin_referer( self::NONCE );
		$do = sanitize_key( (string) ( $_POST['do'] ?? '' ) ); $view = sanitize_key( (string) ( $_POST['view'] ?? 'status' ) ); $msg = '';
		$u = function ( $k, $d = '' ) { return isset( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : $d; };
		$dt = function ( $v ) { $v = trim( str_replace( 'T', ' ', (string) $v ) ); return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $v ) ? substr( $v, 0, 16 ) : ''; };
		switch ( $do ) {
			case 'settings':
				$cfg = Config::get_draft();
				$cfg['enabled'] = ! empty( $_POST['enabled'] ); $cfg['name'] = sanitize_text_field( $u( 'name' ) ); $cfg['slug'] = sanitize_title( $u( 'slug' ) ); $cfg['force'] = sanitize_key( $u( 'force' ) ); $cfg['logo'] = sanitize_text_field( $u( 'logo' ) );
				foreach ( array( 'early_start', 'early_end', 'public_start', 'public_end' ) as $k ) { $v = $dt( $u( 'ph_' . $k ) ); if ( '' !== $v ) { $cfg['phases'][ $k ] = $v; } }
				$cfg['products'] = self::parse_products( $u( 'products' ), $cfg['products'] );
				$cfg['cold'] = array( 'enabled' => ! empty( $_POST['cold_enabled'] ), 'voucher' => (float) str_replace( ',', '.', $u( 'cold_voucher', '10' ) ), 'minutes' => (int) $u( 'cold_minutes', '30' ), 'landing_minutes' => (int) $u( 'cold_landing', '17' ), 'prefix' => $u( 'cold_prefix', 'BF' ) );
				$cfg['seats'] = array( 'enabled' => ! empty( $_POST['seats_enabled'] ), 'start' => (int) $u( 'seats_start', '312' ), 'max' => (int) $u( 'seats_max', '440' ), 'k' => (float) str_replace( ',', '.', $u( 'seats_k', '9' ) ), 'from' => sanitize_text_field( $u( 'seats_from' ) ) );
				$cfg['aff'] = array( 'enabled' => ! empty( $_POST['aff_enabled'] ), 'voucher' => (float) str_replace( ',', '.', $u( 'aff_voucher', '5' ) ), 'mask_sku' => sanitize_text_field( $u( 'aff_mask_sku' ) ), 'mask_product_id' => (int) $u( 'aff_mask_id', '0' ), 'cookie_days' => (int) $u( 'aff_cookie', '7' ) );
				$cfg['early']['cookie_days'] = (int) $u( 'early_cookie', '7' );
				$cfg['ship'] = array( 'free_from' => (int) $u( 'ship_free_from', '2' ), 'paid' => (float) str_replace( ',', '.', $u( 'ship_paid', '2.55' ) ) );
				$cfg['checkout'] = array( 'card_gateway' => sanitize_key( $u( 'card_gateway' ) ), 'cod_gateway' => sanitize_key( $u( 'cod_gateway' ) ), 'block_coupons' => ! empty( $_POST['block_coupons'] ) );
				$cfg['leads'] = array( 'fka' => ! empty( $_POST['leads_fka'] ), 'list' => sanitize_text_field( $u( 'leads_list' ) ), 'tag_cold' => sanitize_text_field( $u( 'leads_tag_cold' ) ), 'tag_aff' => sanitize_text_field( $u( 'leads_tag_aff' ) ), 'consent_only' => ! empty( $_POST['leads_consent_only'] ), 'untag_on_order' => ! empty( $_POST['leads_untag'] ) );
				$cfg['orders'] = array( 'fka_tag' => sanitize_text_field( $u( 'orders_tag' ) ) ); $cfg['preview'] = array( 'password' => trim( wp_strip_all_tags( $u( 'preview_password' ) ) ) );
				Config::save_draft( $cfg ); delete_option( 'ansa_promo_bf_fka_ensured' ); $msg = 'Черновата на Черния петък е записана. Публикувай, за да е на живо.'; break;
			case 'copy':
				$cfg = Config::get_draft(); $cfg['copy'] = Copy::parse_lines( $u( 'copy' ) ); Config::save_draft( $cfg );
				$bad = array(); foreach ( $cfg['copy'] as $k => $v ) { $e = Copy::validate( $v ); if ( $e ) { $bad[] = $k . ': ' . implode( '; ', $e ); } }
				$msg = 'Текстовете са записани в черновата (' . count( $cfg['copy'] ) . ' променени).' . ( $bad ? ' ⚠ ' . implode( ' | ', $bad ) : '' ); break;
			case 'publish': $msg = Config::publish() ? 'Черен петък: публикувано — версия ' . Config::version() . '.' : 'Няма какво да се публикува.'; break;
			case 'discard': Config::discard_draft(); $msg = 'Черновата е отказана.'; break;
			case 'purge': Config::purge(); $msg = 'Кешът на страницата и продуктите е изчистен.'; break;
			case 'token': $cfg = Config::get_draft(); $cfg['early']['token'] = Config::new_token(); Config::save_draft( $cfg ); $msg = 'Нов токен за ранния достъп (в черновата) — старите линкове спират да работят след публикуване.'; break;
			case 'restore': $msg = Config::restore( (int) ( $_POST['version'] ?? 0 ) ) ? 'Версия ' . (int) $_POST['version'] . ' е върната като чернова.' : 'Няма такава версия.'; break;
			case 'import': $msg = Config::import( $u( 'json' ) ) ? 'JSON-ът е внесен като чернова.' : 'Невалиден JSON.'; break;
			case 'leads_sync': Leads::ensure_terms(); $r = Leads::backfill(); $msg = 'Лийдове → FunnelKit: ' . $r['ok'] . ' синхронизирани, ' . $r['retry'] . ' за повторен опит, ' . $r['skip'] . ' пропуснати.'; break;
			case 'export_leads': nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="ansa-bf-leads-' . gmdate( 'Ymd-Hi' ) . '.csv"' ); echo Leads::csv(); exit;
		}
		set_transient( 'ansa_promo_admin_msg_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( self::url( $view ) ); exit;
	}

	/* ── рендер (вика се от AnsaPromo\Admin::page за таб bf) ── */
	public static function render( $msg = '' ) {
		$view = self::current_view(); $draft = Config::get_draft(); $pub = Config::get_published(); $phase = Config::phase_now( $pub );
		echo '<div class="ap-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 14px">';
		echo '<a class="ap-btn ap-btn--secondary ap-btn--sm" href="' . esc_url( Page::url( $pub ) ) . '" target="_blank" rel="noopener">страницата ↗</a><a class="ap-btn ap-btn--secondary ap-btn--sm" href="' . esc_url( Page::preview_link( $draft ) ) . '" target="_blank" rel="noopener">черновата ↗</a>';
		self::form_open( 'publish', $view ); echo '<button class="ap-btn ap-btn--primary ap-btn--sm">🚀 Публикувай</button></form>';
		if ( Config::has_draft() ) { self::form_open( 'discard', $view ); echo '<button class="ap-btn ap-btn--secondary ap-btn--sm" onclick="return confirm(\'Да откажа ли непубликуваните промени?\')">Откажи черновата</button></form>'; }
		self::form_open( 'purge', $view ); echo '<button class="ap-btn ap-btn--secondary ap-btn--sm">🧹 Изчисти кеша</button></form>';
		echo '<span style="margin-left:auto;font-size:12px;color:#6b5f58">фаза сега: <b>' . esc_html( Config::phase_label( $phase ) ) . '</b>' . ( '' !== $pub['force'] ? ' (принудително)' : '' ) . ' · публикувано v' . (int) Config::version() . ( Config::has_draft() ? ' · <span style="color:#9a6700">чернова чака</span>' : '' ) . '</span></div>';
		if ( 'status' === $view ) { self::v_status( $draft, $pub ); } elseif ( 'settings' === $view ) { self::v_settings( $draft ); } elseif ( 'copy' === $view ) { self::v_copy( $draft ); } elseif ( 'leads' === $view ) { self::v_leads(); } else { self::v_orders(); }
	}

	private static function v_status( $draft, $pub ) {
		$prods = Page::products( $pub, true ); $ls = Leads::stats(); $orders = self::orders( 500 ); $rev = 0; $paid = 0; $gifts = 0; foreach ( $orders as $o ) { $rev += (float) $o->get_total(); $paid += (int) $o->get_meta( '_ansa_bf_paid' ); $gifts += (int) $o->get_meta( '_ansa_bf_gifts' ); }
		$phase = Config::phase_now( $pub ); $pt = Config::phase_ts( $pub );
		echo '<div class="ap-kpis" style="padding:0 0 14px">';
		echo '<div class="ap-kpi ' . ( ! empty( $pub['enabled'] ) ? 'good' : 'bad' ) . '"><b>' . ( ! empty( $pub['enabled'] ) ? 'ВКЛЮЧЕН' : 'изключен' ) . '</b><span>страницата</span></div>';
		echo '<div class="ap-kpi"><b>' . esc_html( Config::phase_label( $phase ) ) . '</b><span>фаза · край ' . esc_html( Config::fmt_dt( Config::phase_end( $pub, $phase ) ) ) . '</span></div>';
		echo '<div class="ap-kpi ' . ( count( $prods ) ? 'good' : 'bad' ) . '"><b>' . count( $prods ) . ' / ' . count( $pub['products'] ) . '</b><span>продукта на живо</span></div>';
		echo '<div class="ap-kpi"><b>' . (int) $ls['total'] . '</b><span>лийдове · студени ' . (int) $ls['cold'] . ' · партньор ' . (int) $ls['aff'] . '</span></div>';
		echo '<div class="ap-kpi"><b>' . count( $orders ) . '</b><span>поръчки · ' . esc_html( self::fmt( $rev ) ) . ' € · ' . $paid . ' платени + ' . $gifts . ' подаръка</span></div>';
		echo '</div>';
		$rows = array(); $add = function ( $l, $v, $lv = 'info' ) use ( &$rows ) { $rows[] = array( $l, (string) $v, $lv ); };
		$add( 'Адрес', Page::url( $pub ) . ' (slug „' . $pub['slug'] . '“)', 'ok' );
		$conf = get_page_by_path( $pub['slug'] ); $add( 'Конфликт със страница на WP', $conf ? 'ИМА страница #' . $conf->ID . ' със същия slug — маршрутът на плъгина я засенчва' : 'няма', $conf ? 'warn' : 'ok' );
		foreach ( array( 'early_start' => 'Ранен достъп от', 'early_end' => 'Ранен достъп до', 'public_start' => 'Публичен от', 'public_end' => 'Публичен до' ) as $k => $l ) { $add( $l, $pub['phases'][ $k ] . ' (' . Config::fmt_dt( $pt[ $k ] ) . ')', $pt[ $k ] ? 'ok' : 'bad' ); }
		$add( 'Принудителна фаза', '' !== $pub['force'] ? $pub['force'] : 'не (по датите)', '' !== $pub['force'] ? 'warn' : 'ok' );
		foreach ( (array) $pub['products'] as $p ) { $pid = '' !== $p['sku'] ? ( Catalog::find_by_sku( $p['sku'] ) ?: (int) $p['product_id'] ) : (int) $p['product_id']; $wc = $pid ? Catalog::wc_line( $pid ) : array( 'ok' => false, 'note' => 'няма SKU/ID', 'wc_name' => '', 'price' => 0, 'stock' => false, 'variation_id' => 0, 'img' => '' ); $add( ( $p['gift_only'] ? '🎁 ' : '📦 ' ) . $p['key'] . ( $p['is_new'] ? ' (ново)' : '' ), ! $wc['ok'] ? 'НЕ: ' . $wc['note'] : '#' . $pid . ' ' . $wc['wc_name'] . ' · ' . ( $wc['variation_id'] ? 'var#' . $wc['variation_id'] : 'simple' ) . ' · €' . $wc['price'] . ' · stock=' . ( $wc['stock'] ? 'yes' : 'NO' ) . ' · img=' . ( $wc['img'] || '' !== $p['img'] ? 'yes' : 'NO' ) . ( empty( $p['enabled'] ) ? ' · скрит' : '' ), ! $wc['ok'] ? 'bad' : ( $wc['stock'] ? 'ok' : 'warn' ) ); }
		$mk = Page::mask( $pub ); $add( 'Маската (CollaMask)', $mk ? '#' . $mk['id'] . ' ' . $mk['name'] . ' · €' . $mk['price'] . ' · stock=' . ( $mk['stock'] ? 'yes' : 'NO' ) : 'НЕ Е НАМЕРЕНА (SKU „' . $pub['aff']['mask_sku'] . '“ / id ' . (int) $pub['aff']['mask_product_id'] . ')', $mk ? 'ok' : 'bad' );
		$add( 'ansa Partner (?ref=)', class_exists( 'AP_Coupons' ) ? 'активен — името на партньора се взима от кода' : 'НЕ Е АКТИВЕН — партньорският вариант няма да познае кода', class_exists( 'AP_Coupons' ) ? 'ok' : 'warn' );
		$add( 'Студен ваучер', ( $pub['cold']['enabled'] ? $pub['cold']['voucher'] . ' € за ' . $pub['cold']['minutes'] . ' мин. (таймер на страницата ' . $pub['cold']['landing_minutes'] . ' мин.)' : 'изключен' ) . ' · ползвани: ' . (int) $ls['voucher_used'], 'info' );
		$add( 'Брояч „записани“', $pub['seats']['enabled'] ? Config::seats_now( $pub ) . ' / ' . $pub['seats']['max'] . ' (старт ' . $pub['seats']['start'] . ' на ' . $pub['seats']['from'] . ', k=' . $pub['seats']['k'] . ')' : 'изключен', 'info' );
		$add( 'Линк за ранния достъп (имейл)', Config::early_url( $pub ), 'ok' );
		$add( 'FunnelKit лийдове', Lead_Sync::available() ? ( $pub['leads']['fka'] ? 'лист „' . $pub['leads']['list'] . '“' . ( ( $id = Merge::list_id( $pub['leads']['list'] ) ) > 0 ? ' #' . $id : ' (не е създаден)' ) . ' · тагове „' . $pub['leads']['tag_cold'] . '“ / „' . $pub['leads']['tag_aff'] . '“ · поръчка „' . $pub['orders']['fka_tag'] . '“ · синхронизирани ' . (int) $ls['synced'] . ' · чакат ' . (int) $ls['pending'] : 'изключено' ) : 'FunnelKit контакти липсват', Lead_Sync::available() ? 'ok' : 'warn' );
		$add( 'Преглед с парола', '' !== $pub['preview']['password'] ? 'включен · ' . Page::preview_link( $draft ) : 'без парола (само админи виждат черновата с ?ansa_bf=draft)', 'info' );
		echo '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🩺 Състояние на Черния петък</span></div><div class="ap-env">';
		foreach ( $rows as $r ) { echo '<div class="ap-env__r ' . esc_attr( $r[2] ) . '"><span class="ap-env__dot ' . esc_attr( $r[2] ) . '"></span><span class="ap-env__l">' . esc_html( $r[0] ) . '</span><span class="ap-env__v">' . esc_html( $r[1] ) . '</span></div>'; }
		echo '</div></div>';
		$urls = Page::urls( $pub );
		echo '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🔗 Адресите по източник</span></div><div class="ap-sec__b"><p class="description">Един адрес, различни маркери: вариантът се решава в браузъра от маркерите и бисквитките, фазата — от сървъра по датите. Страницата се кешира безопасно.</p><table class="widefat striped"><tbody>';
		foreach ( array( 'cold' => 'Студен трафик (Meta реклами за записване) — ваучер ' . $pub['cold']['voucher'] . ' €', 'early' => 'Ранен достъп (стартовият имейл до списъка — личният линк с токена)', 'email' => 'Имейли без токен (7 дни / 2 дни преди — само разглеждане до старта)', 'public' => 'Публичен Black Friday (Meta реклами, публичен имейл)', 'aff' => 'Партньорски линк (ansa Partner: КОД = кодът на партньора; подарък по избор в публичната промоция)' ) as $k => $l ) { echo '<tr><td style="width:46%">' . esc_html( $l ) . '</td><td><code style="user-select:all">' . esc_html( $urls[ $k ] ) . '</code></td></tr>'; }
		echo '</tbody></table></div></div>';
		if ( Config::history() ) { echo '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">📜 История</span></div><div class="ap-sec__b">'; foreach ( Config::history() as $h ) { self::form_open( 'restore', 'status' ); echo '<input type="hidden" name="version" value="' . (int) $h['version'] . '"><button class="ap-btn ap-btn--secondary ap-btn--sm" style="margin:0 6px 6px 0">v' . (int) $h['version'] . ' · ' . esc_html( date_i18n( 'd.m H:i', $h['at'] ) ) . ' → чернова</button></form>'; } echo '</div></div>'; }
	}

	private static function v_settings( $d ) {
		self::form_open( 'settings', 'settings', ' style="display:block" data-ap-main' );
		$ph = $d['phases']; $dtv = function ( $v ) { return str_replace( ' ', 'T', (string) $v ); };
		echo '<section class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🎯 Страница и фази</span></div><div class="ap-sec__b">';
		echo '<p><label><input type="checkbox" name="enabled" value="1"' . checked( $d['enabled'], true, false ) . '> <b>страницата е включена</b> — иначе показва „скоро“ (черновата се вижда винаги с паролата / <code>?ansa_bf=draft</code>)</label></p>';
		echo '<p class="ap-fl"><b>Име</b></p><input type="text" name="name" value="' . esc_attr( $d['name'] ) . '" class="regular-text"> <p class="ap-fl"><b>Адрес (slug на корена на сайта)</b></p>' . esc_html( home_url( '/' ) ) . '<input type="text" name="slug" value="' . esc_attr( $d['slug'] ) . '" style="width:220px">/';
		echo '<p class="ap-fl"><b>Фазите</b> <span class="description">по часа на сайта (' . esc_html( wp_timezone_string() ) . ')</span></p><table class="widefat" style="max-width:720px"><tbody>';
		foreach ( array( 'early_start' => 'Ранен достъп от (48 ч. за списъка — линкът с токена)', 'early_end' => 'Ранен достъп до', 'public_start' => 'Публичен Black Friday от (за всички)', 'public_end' => 'Публичен до' ) as $k => $l ) { echo '<tr><td>' . esc_html( $l ) . '</td><td><input type="datetime-local" name="ph_' . $k . '" value="' . esc_attr( $dtv( $ph[ $k ] ) ) . '"></td></tr>'; }
		echo '</tbody></table><p class="description">Преди ранния достъп страницата е „подготовка“: студената страница с ваучера (записване). Между края на ранния достъп и публичния старт е пак „подготовка“.</p>';
		echo '<p class="ap-fl"><b>Принудителна фаза</b></p><select name="force"><option value=""' . selected( $d['force'], '', false ) . '>— по датите —</option>'; foreach ( Config::PHASES as $pz ) { echo '<option value="' . $pz . '"' . selected( $d['force'], $pz, false ) . '>' . esc_html( Config::phase_label( $pz ) ) . '</option>'; } echo '</select> <span class="description">за тест или ако трябва да се отвори/затвори по-рано; важи след публикуване</span>';
		echo '<p class="ap-fl"><b>Лого (светло, за тъмната секция)</b></p><input type="text" name="logo" value="' . esc_attr( (string) $d['logo'] ) . '" class="regular-text" placeholder="attachment id или URL"> <span class="description">празно = бялото лого от плъгина</span>';
		echo '<p class="ap-fl"><b>Парола за преглед на черновата</b></p><input type="text" name="preview_password" value="' . esc_attr( (string) $d['preview']['password'] ) . '" class="regular-text"> <span class="description">линк за екипа: ' . ( '' !== $d['preview']['password'] ? '<code>' . esc_html( Page::preview_link( $d ) ) . '</code>' : 'задай парола и запази' ) . '</span>';
		echo '</div></section>';
		echo '<section class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">📦 Продукти</span></div><div class="ap-sec__b"><p class="description">По един ред на продукт. <b>gift</b> = само подарък (новите 4 — не се продават отделно през промото, стоят първи в стъпка 2), <b>new</b> = значка НОВО, <b>fav</b> = в „Най-продавани“, <b>off</b> = скрит. SKU печели над WC id; празно име/подзаглавие = от WooCommerce. Цената е винаги редовната на вариацията „1 опаковка“ — от WooCommerce, на живо.</p>';
		echo '<textarea name="products" class="ap-json" spellcheck="false" style="min-height:260px;font-size:12px">' . esc_textarea( self::products_text( $d ) ) . '</textarea></div></section>';
		echo '<section class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🎟️ Студен трафик (подготовка) и броячът</span></div><div class="ap-sec__b">';
		echo '<p><label><input type="checkbox" name="cold_enabled" value="1"' . checked( $d['cold']['enabled'], true, false ) . '> студената страница с ваучер е включена (иначе преди старта се показва „скоро“)</label></p>';
		echo '<p>Ваучер <input type="text" name="cold_voucher" value="' . esc_attr( self::fmt( $d['cold']['voucher'] ) ) . '" style="width:70px"> € · важи <input type="number" name="cold_minutes" value="' . (int) $d['cold']['minutes'] . '" style="width:70px" min="1"> мин. след отключване · таймерът на страницата <input type="number" name="cold_landing" value="' . (int) $d['cold']['landing_minutes'] . '" style="width:70px" min="1"> мин. · префикс на кода <input type="text" name="cold_prefix" value="' . esc_attr( $d['cold']['prefix'] ) . '" style="width:70px"></p>';
		echo '<p class="description">Ваучерът е отрицателна такса в количката (важи в целия магазин, еднократно, докато е жив). Записалият се получава и тага за студените лийдове — оттам тръгват имейлите за ранния достъп.</p>';
		echo '<p><label><input type="checkbox" name="seats_enabled" value="1"' . checked( $d['seats']['enabled'], true, false ) . '> броячът „N / max записани жени“</label> · старт <input type="number" name="seats_start" value="' . (int) $d['seats']['start'] . '" style="width:80px"> · максимум <input type="number" name="seats_max" value="' . (int) $d['seats']['max'] . '" style="width:80px"> · k <input type="text" name="seats_k" value="' . esc_attr( self::fmt( $d['seats']['k'] ) ) . '" style="width:60px"> · ден 0 <input type="date" name="seats_from" value="' . esc_attr( $d['seats']['from'] ) . '"> <span class="description">n = max − (max − старт)·e^(−дни/k): 312 → 348 (3 д.) → 374 (6 д.) → 406 (12 д.) → 412 (14 д.), никога max · сега: ' . (int) Config::seats_now( $d ) . '</span></p>';
		echo '</div></section>';
		echo '<section class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🔗 Партньорски линк (ansa Partner)</span></div><div class="ap-sec__b">';
		echo '<p><label><input type="checkbox" name="aff_enabled" value="1"' . checked( $d['aff']['enabled'], true, false ) . '> <code>?ref=КОД</code> показва „…и подарък от {име}“: ваучер <input type="text" name="aff_voucher" value="' . esc_attr( self::fmt( $d['aff']['voucher'] ) ) . '" style="width:60px"> € или маската (SKU <input type="text" name="aff_mask_sku" value="' . esc_attr( $d['aff']['mask_sku'] ) . '" style="width:90px"> / WC id <input type="number" name="aff_mask_id" value="' . (int) $d['aff']['mask_product_id'] . '" style="width:90px">) · бисквитка <input type="number" name="aff_cookie" value="' . (int) $d['aff']['cookie_days'] . '" style="width:60px"> дни</label></p>';
		echo '<p class="description">Подаръкът важи само в публичната промоция. Атрибуцията и комисионата са на ansa Partner (кодът минава през количката без отстъпка, както в Сезона).</p></div></section>';
		echo '<section class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">⏳ Ранен достъп · 🚚 Доставка · 💳 Чекаут</span></div><div class="ap-sec__b">';
		echo '<p>Токен в линка от имейла: <code>' . esc_html( $d['early']['token'] ) . '</code> → <code style="user-select:all">' . esc_html( Config::early_url( $d ) ) . '</code> · бисквитка <input type="number" name="early_cookie" value="' . (int) $d['early']['cookie_days'] . '" style="width:60px"> дни</p>';
		echo '<p>Безплатна доставка от <input type="number" name="ship_free_from" value="' . (int) $d['ship']['free_from'] . '" style="width:60px"> платени опаковки (0 = никога) · платена доставка <input type="text" name="ship_paid" value="' . esc_attr( self::fmt( $d['ship']['paid'] ) ) . '" style="width:70px"> € (само за показване)</p>';
		echo '<p>Гейтуей карта <input type="text" name="card_gateway" value="' . esc_attr( $d['checkout']['card_gateway'] ) . '" style="width:150px"> · наложен платеж <input type="text" name="cod_gateway" value="' . esc_attr( $d['checkout']['cod_gateway'] ) . '" style="width:120px"> · <label><input type="checkbox" name="block_coupons" value="1"' . checked( $d['checkout']['block_coupons'], true, false ) . '> купоните са блокирани в промо количката (партньорските минават без отстъпка)</label></p>';
		echo '</div></section>';
		echo '<section class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">📨 Лийдове и поръчки → FunnelKit</span></div><div class="ap-sec__b"><p><label><input type="checkbox" name="leads_fka" value="1"' . checked( $d['leads']['fka'], true, false ) . '> имейлът става контакт във FunnelKit с лист + таг по вид</label></p>';
		echo '<p>Лист <input type="text" name="leads_list" value="' . esc_attr( $d['leads']['list'] ) . '" class="regular-text"> · таг студени <input type="text" name="leads_tag_cold" value="' . esc_attr( $d['leads']['tag_cold'] ) . '" style="width:140px"> · таг партньорски <input type="text" name="leads_tag_aff" value="' . esc_attr( $d['leads']['tag_aff'] ) . '" style="width:140px"> · таг на поръчката <input type="text" name="orders_tag" value="' . esc_attr( $d['orders']['fka_tag'] ) . '" style="width:170px"></p>';
		echo '<p><label><input type="checkbox" name="leads_consent_only" value="1"' . checked( $d['leads']['consent_only'], true, false ) . '> само със съгласие</label> &nbsp; <label><input type="checkbox" name="leads_untag" value="1"' . checked( $d['leads']['untag_on_order'], true, false ) . '> при поръчка таговете на лийда падат</label></p>';
		echo '<p class="description">Автоматизациите във FKA: „Tag Added“ <code>' . esc_html( $d['leads']['tag_cold'] ) . '</code> → имейлите 7 дни / 2 дни преди и стартовият с линка <code>' . esc_html( Config::early_url( $d ) ) . '</code> (шорткод <code>[ansa_bf_early_url]</code>); „Tag Added“ <code>' . esc_html( $d['orders']['fka_tag'] ) . '</code> → благодарствен flow. Състояние: ' . ( Lead_Sync::available() ? 'FunnelKit контакти: има' : 'FunnelKit контакти липсват' ) . '</p></div></section>';
		echo '<div class="ap-actions" style="padding:10px 0 20px"><button class="ap-btn ap-btn--primary" data-ap-save>💾 Запази черновата</button></div></form>';
		echo '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🔑 Токен · { } JSON</span></div><div class="ap-sec__b">'; self::form_open( 'token', 'settings' ); echo '<button class="ap-btn ap-btn--secondary ap-btn--sm" onclick="return confirm(\'Нов токен — старите линкове в изпратените имейли ще спрат да работят след публикуване. Сигурен ли си?\')">🔑 Нов токен за ранния достъп</button></form>';
		self::form_open( 'import', 'settings', ' style="display:block;margin-top:12px"' ); echo '<textarea name="json" class="ap-json" spellcheck="false" style="min-height:180px;font-size:11px">' . esc_textarea( Config::export() ) . '</textarea><div class="ap-actions" style="padding:8px 0 0"><button class="ap-btn ap-btn--secondary ap-btn--sm">⬆️ Внеси JSON като чернова</button></div></form></div></div>';
	}

	private static function v_copy( $d ) {
		$eff = Copy::effective( $d['copy'] ); $lines = array();
		foreach ( Copy::registry() as $g => $keys ) { $lines[] = '# ── ' . Copy::groups()[ $g ] . ' ──'; foreach ( $keys as $k => $v ) { $lines[] = $k . ' = ' . str_replace( "\n", '\\n', $eff[ $k ] ); } $lines[] = ''; }
		self::form_open( 'copy', 'copy', ' style="display:block" data-ap-main' );
		echo '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">✏️ Текстовете</span></div><div class="ap-sec__b"><p class="description">Един ред = <code>ключ = текст</code>. Непроменените остават по подразбиране (записват се само различните). Плейсхолдъри: <code>{{' . esc_html( implode( '}} {{', Copy::vars() ) ) . '}}</code>. HTML: &lt;b&gt; &lt;em&gt; &lt;s&gt;. Променени сега: ' . count( $d['copy'] ) . '.</p>';
		echo '<textarea name="copy" class="ap-json" spellcheck="false" style="min-height:560px;font-size:12px">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea><div class="ap-actions" style="padding:10px 0 0"><button class="ap-btn ap-btn--primary" data-ap-save>💾 Запази текстовете</button></div></div></div></form>';
	}

	private static function v_leads() {
		$ls = Leads::stats(); $rows = Leads::rows( 300 );
		echo '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">📨 Лийдове на Черния петък</span><span>'; self::form_open( 'leads_sync', 'leads' ); echo '<button class="ap-btn ap-btn--secondary ap-btn--sm">📨 Синхронизирай ' . (int) $ls['pending'] . ' чакащи</button></form> '; self::form_open( 'export_leads', 'leads' ); echo '<button class="ap-btn ap-btn--secondary ap-btn--sm">⬇️ CSV</button></form></span></div><div class="ap-sec__b">';
		echo '<p class="description">Общо ' . (int) $ls['total'] . ' · студени ' . (int) $ls['cold'] . ' · партньорски ' . (int) $ls['aff'] . ' · конвертирани ' . (int) $ls['converted'] . ' · ваучери ползвани ' . (int) $ls['voucher_used'] . ' · във FunnelKit ' . (int) $ls['synced'] . '</p>';
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>#</th><th>Кога</th><th>Имейл</th><th>Вид</th><th>Подарък</th><th>Партньор</th><th>UTM</th><th>Ваучер</th><th>Изтича (UTC)</th><th>Ползван в</th><th>Поръчка</th><th>FunnelKit</th></tr></thead><tbody>';
		foreach ( $rows as $r ) { echo '<tr><td>' . (int) $r['id'] . '</td><td>' . esc_html( $r['created_at'] ) . '</td><td>' . esc_html( $r['email'] ) . '</td><td>' . esc_html( $r['kind'] ) . '</td><td>' . esc_html( $r['bonus'] ) . '</td><td>' . esc_html( $r['ref_code'] ) . '</td><td>' . esc_html( $r['utm'] ) . '</td><td>' . esc_html( $r['voucher'] ) . '</td><td>' . esc_html( (string) $r['voucher_exp'] ) . '</td><td>' . ( $r['voucher_order_id'] ? '#' . (int) $r['voucher_order_id'] : '' ) . '</td><td>' . ( $r['converted_order_id'] ? '#' . (int) $r['converted_order_id'] : '' ) . '</td><td>' . esc_html( $r['fka'] ) . '</td></tr>'; }
		if ( ! $rows ) { echo '<tr><td colspan="12">Още няма лийдове.</td></tr>'; }
		echo '</tbody></table></div></div></div>';
	}

	public static function orders( $limit = 200 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) { return array(); }
		try { $o = wc_get_orders( array( 'limit' => (int) $limit, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => array( array( 'key' => '_ansa_bf_promo', 'compare' => 'EXISTS' ) ) ) ); return is_array( $o ) ? $o : array(); } catch ( \Throwable $e ) { return array(); }
	}
	private static function v_orders() {
		$orders = self::orders( 300 );
		echo '<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🧾 Поръчки на Черния петък</span></div><div class="ap-sec__b"><div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Поръчка</th><th>Кога</th><th>Статус</th><th>Клиент</th><th>Режим · фаза · вариант</th><th>Платени</th><th>Подаръци</th><th>Ваучер</th><th>Партньор</th><th>Спестени</th><th>Общо</th><th>FKA</th></tr></thead><tbody>';
		foreach ( $orders as $o ) { $m = $o->get_meta( '_ansa_promo_bf' ); $m = is_array( $m ) ? $m : array(); $gifts = array(); foreach ( (array) ( $m['gifts'] ?? array() ) as $g ) { $gifts[] = $g['name'] . ( (int) $g['qty'] > 1 ? ' ×' . (int) $g['qty'] : '' ); } if ( ! empty( $m['pgift'] ) ) { $gifts[] = 'маска'; }
			echo '<tr><td><a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . (int) $o->get_id() . '</a></td><td>' . esc_html( $o->get_date_created() ? $o->get_date_created()->date_i18n( 'd.m H:i' ) : '' ) . '</td><td>' . esc_html( wc_get_order_status_name( $o->get_status() ) ) . '</td><td>' . esc_html( $o->get_formatted_billing_full_name() ) . '<br><small>' . esc_html( $o->get_billing_email() ) . '</small></td><td>' . esc_html( ( $m['mode'] ?? '' ) . ' · ' . ( $m['phase'] ?? '' ) . ' · ' . ( $m['variant'] ?? '' ) ) . '</td><td>' . (int) ( $m['paid'] ?? 0 ) . '</td><td>' . esc_html( implode( ', ', $gifts ) ) . '</td><td>' . esc_html( ! empty( $m['voucher'] ) ? $m['voucher']['code'] . ' (−' . self::fmt( $m['voucher']['amount'] ) . ' €)' : '' ) . '</td><td>' . esc_html( ! empty( $m['aff'] ) ? $m['aff']['code'] . ' · ' . $m['aff']['gift'] : '' ) . '</td><td>' . esc_html( self::fmt( $m['savings']['total'] ?? 0 ) ) . ' €</td><td>' . esc_html( self::fmt( $o->get_total() ) ) . ' €</td><td>' . esc_html( (string) $o->get_meta( '_ansa_bf_fka' ) ) . '</td></tr>'; }
		if ( ! $orders ) { echo '<tr><td colspan="12">Още няма поръчки от Черния петък.</td></tr>'; }
		echo '</tbody></table></div></div></div>';
	}
}
