<?php
/**
 * ansa™ Промо — админ.
 *
 * Фаза 0: меню „🎁 ansa Промо“ (top-level) с екран „Състояние“ (Doctor::env()).
 * Фаза 1: 🧰 Инструменти — страница, игра вкл./срок, чернова ↔ публикувано, история, JSON export/import, seed.
 * Фаза 2 носи редактора (региони · канвас · инспектор · ⚙️ Двигател).
 * v1.15.0: таб 📦 Продукти е отделно приложение (Products_UI, стил ansa™ Shrine) — списък, търсачка в WC, отстъпки, проблем/FIT.
 *
 * @package AnsaPromo
 * @since 1.0.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const NONCE = 'ansa_promo_admin';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ANSA_PROMO_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_ansa_promo_tools', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_ansa_promo_admin_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** Медийната библиотека за „Снимки на наградите“ (само на нашата страница). */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, Plugin::MENU_SLUG ) ) { return; }
		if ( function_exists( 'wp_enqueue_media' ) ) { wp_enqueue_media(); }
		/* v1.16.0: целият админ е едно приложение (Partner стил като Shrine) — рамка + карти; формите/action-ите са същите */
		/* 1.17.0: Nunito локално (assets/fonts) — Google Fonts беше блокиран/бавен и админът падаше на системния шрифт */
		wp_enqueue_style( 'ansa-promo-nunito-admin', ANSA_PROMO_URL . 'assets/fonts/nunito.css', array(), ANSA_PROMO_VER );
		wp_enqueue_style( 'ansa-promo-app', ANSA_PROMO_URL . 'assets/admin-app.css', array( 'ansa-promo-nunito-admin' ), ANSA_PROMO_VER );
		wp_enqueue_script( 'ansa-promo-app', ANSA_PROMO_URL . 'assets/admin-app.js', array(), ANSA_PROMO_VER, true );
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? '' ) );
		if ( 'products' === $tab ) { Products_UI::enqueue(); } /* v1.15.0 */
		if ( 'editor' === $tab ) { Editor::enqueue_now(); } /* v1.17.0: редакторът е таб в приложението */
	}

	/** v1.16.0: екраните на приложението — [key => [икона, име, подзаглавие]]. */
	public static function screens() {
		return array(
			'status'   => array( '🩺', 'Състояние',  'Какво е живо, какво чака — Doctor на играта' ),
			'products' => array( '📦', 'Продукти',   'Кое е в играта, свързване с WooCommerce, отстъпки, проблем и FIT' ),
			'tools'    => array( '🧰', 'Настройки',  'Страница, игра, попъп, награди, билети, томбола, книга · публикуване' ),
			'editor'   => array( '✏️', 'Редактор',   'Текстовете на страницата върху живия канвас · ⚙️ Двигател' ),
			'orders'   => array( '🎟️', 'Поръчки',    'Промо поръчките, билетите, FunnelKit таговете' ),
			'tombola'  => array( '🎲', 'Томбола',    'Участници, билети, препоръки, теглене' ),
			'insights' => array( '📈', 'Insights',   '📊 Преглед · 🟢 На живо · 🧾 Сесии · 🛒 Колички · 💸 Разход · 📌 Отчети' ),
			'json'     => array( '{ }', 'JSON',      'Черновата като JSON — износ и внос' ),
		);
	}
	/** v1.16.0: секциите на Настройки за подменюто (id => име). */
	public static function tool_sections() {
		return array( 's-page' => 'Страница и игра', 's-season' => '📅 Седмици на сезона', 's-gate' => 'Имейл-попъп', 's-leads' => 'Лийдове → FunnelKit', 's-images' => 'Снимки на наградите', 's-cosm' => '💄 Козметичните продукти', 's-bgn' => 'Лева в поръчката', 's-preview' => 'Преглед с парола', 's-insurl' => '📈 Insights URL', 's-insrep' => '📬 Insights отчети', 's-phone' => '📞 Обаждания', 's-tickets' => 'Билети и награди', 's-info' => 'Как работи', 's-li' => '💄 Комплектът за 1 €', 's-tombola' => 'Томбола', 's-book' => 'Книга /kniga', 's-demo' => 'Демо данни', 's-theme' => 'Цял екран', 's-publish' => 'Публикуване', 's-linkproxy' => 'Линковете от SendGrid', 's-favorite' => '⭐ Любим продукт (имейли)', 's-products' => 'Продуктите в черновата' );
	}

	/** v1.19.0: 📅 Седмици на сезона — включване, старт, гратис, таблицата N × 3 с проценти (празно = процентът на кутията), „Запълни линейно“,
	 *  „Преглед като седмица N“ (черновата с ?week=N). Датите се смятат от старта и срока; 1.22.0: краят на всяка седмица може да се зададе (колона „до“) —
	 *  следващата тръгва на другия ден; празно = 7 дни. Таблицата се преизчислява след 💾 Запази. */
	private static function season_section( $draft, $draft_url = '' ) {
		$sn = $draft['season']; $rows = Config::season_weeks( $draft ); $now = time(); $cur = Config::weekly_on( $draft ) ? Config::week_for( $draft, $now ) : null;
		$base = array(); foreach ( $draft['boxes'] as $b ) { $base[ $b['id'] ] = $b; }
		$fmt = function ( $v ) { return null === $v ? '' : str_replace( '.', ',', rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' ) ); };
		$dow = array( 'нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб' );
		echo '<section class="ap-sec" id="s-season"><div class="ap-sec__h"><span class="ap-sec__t">📅 Седмици на сезона</span>' . ( $draft_url && $rows ? '<a class="ap-btn ap-btn--secondary ap-btn--sm" href="' . esc_url( add_query_arg( 'week', $cur && $cur['next'] ? (int) $cur['next']['i'] : 1, $draft_url ) ) . '" target="_blank" rel="noopener">👁 Преглед като седмица ' . ( $cur && $cur['next'] ? (int) $cur['next']['i'] : 1 ) . '</a>' : '' ) . '</div><div class="ap-sec__b">';
		echo '<p><label><input type="checkbox" name="season_weekly" value="1"' . checked( ! empty( $sn['weekly'] ), true, false ) . '> <b>отстъпките падат по седмици</b> — всяка кутия има свой процент за всяка седмица; количката, страницата, „Как работи“ и /info четат оттук</label></p>';
		echo '<p class="description">Седмиците тръгват от старта (00:00 по часа на сайта) и свършват на срока на играта (последната може да е по-къса). По подразбиране са по 7 дни; в колоната <b>до</b> може да сложиш друг последен ден на седмицата (включително) — следващата тръгва на другия ден, а празно = 7 дни; броят седмици и датите се преизчисляват след 💾 Запази. Празна клетка за процент = процентът на кутията. Цената от добавянето в количката се пази толкова часа след смяната, после по-ниската отстъпка влиза с бележка за клиента. Override-ите по продукт (📦 Продукти) не се пипат. Прегледът: <code>?ansa_promo=draft&amp;week=N</code>.</p>';
		echo '<p class="ap-fl"><b>Старт на сезона</b></p><input type="date" name="season_start" value="' . esc_attr( $sn['start'] ) . '"> <span class="description">краят е „Край“ от 🎯 Страница и игра (' . esc_html( $draft['deadline'] ?: 'няма' ) . ')</span>';
		echo '<p class="ap-fl"><b>Гратис след смяната</b></p><input type="number" name="season_grace" min="0" max="168" step="1" value="' . (int) $sn['grace_hours'] . '" style="width:80px"> часа';
		echo '<p class="ap-fl"><b>Стълбата на отстъпките</b></p>';
		echo '<div class="ap-season-fill" style="display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;background:#faf7f2;border:1px dashed rgba(26,26,46,.12);border-radius:10px;padding:10px 12px;margin:0 0 10px"><b>Запълни линейно:</b>';
		foreach ( $base as $bid => $b ) { echo '<span style="white-space:nowrap">' . esc_html( $b['ic'] ) . ' от −<input type="text" inputmode="decimal" data-sf="' . esc_attr( $bid ) . '" value="' . esc_attr( $fmt( $b['pct'] ) ) . '" style="width:52px;text-align:center"> → −<input type="text" inputmode="decimal" data-st="' . esc_attr( $bid ) . '" value="" placeholder="?" style="width:52px;text-align:center">%</span>'; }
		echo '<button type="button" class="ap-btn ap-btn--secondary ap-btn--sm" id="apSeasonFill">Запълни ' . count( $rows ) . '-те седмици</button><span class="description">после всяко число може да се пипне ръчно</span></div>';
		echo '<div style="overflow-x:auto"><table class="widefat striped" id="apSeasonT" style="min-width:640px"><thead><tr><th style="width:36px">#</th><th>Седмица</th><th>до <small style="font-weight:600;color:#6b5f58">(посл. ден; празно = 7 дни)</small></th><th>Статус</th>';
		foreach ( $base as $b ) { echo '<th>' . esc_html( $b['ic'] . ' ' . $b['name'] ) . '<br><small style="font-weight:600;color:#6b5f58">кутията: −' . esc_html( $fmt( $b['pct'] ) ) . '%</small></th>'; }
		echo '</tr></thead><tbody>';
		if ( ! $rows ) { echo '<tr><td colspan="7">Няма седмици — въведи старт и край.</td></tr>'; }
		foreach ( $rows as $r ) {
			$i = (int) $r['i'] - 1; $is_cur = $cur && (int) $cur['i'] === (int) $r['i']; $past = $now >= $r['to']; $next = $cur && $cur['next'] && (int) $cur['next']['i'] === (int) $r['i'];
			$st = $is_cur && $now < $r['from'] ? '<span style="color:#c2410c;font-weight:800">стартира ' . esc_html( wp_date( 'd.m', (int) $r['from'] ) ) . '</span>' : ( $is_cur ? '<span style="background:#e8722a;color:#fff;border-radius:999px;padding:2px 8px;font-weight:900;font-size:11px">текуща</span>' : ( $past ? '<span style="color:#6b5f58">минала</span>' : ( $next ? '<span style="color:#c2410c;font-weight:800">следваща</span>' : ( (int) $r['i'] === count( $rows ) ? 'последна' : 'предстояща' ) ) ) );
			$days = max( 1, (int) round( ( (int) $r['to'] - (int) $r['from'] ) / DAY_IN_SECONDS ) );
			$lbl = $dow[ (int) wp_date( 'w', (int) $r['from'] ) ] . ' ' . wp_date( 'd.m', (int) $r['from'] ) . ' – ' . $dow[ (int) wp_date( 'w', (int) $r['to'] - MINUTE_IN_SECONDS ) ] . ' ' . wp_date( 'd.m', (int) $r['to'] - MINUTE_IN_SECONDS ) . ( 7 !== $days ? ' · ' . $days . ' ' . ( 1 === $days ? 'ден' : 'дни' ) : '' );
			$to_v = (string) ( $sn['weeks'][ $i ]['to'] ?? '' ); /* 1.22.0: краят по избор; в полето стои само въведеното, датата по подразбиране е в „Седмица“ */
			echo '<tr' . ( $is_cur ? ' style="background:rgba(232,114,42,.07)"' : ( $past ? ' style="color:#6b5f58"' : '' ) ) . '><td><b>' . (int) $r['i'] . '</b></td><td style="white-space:nowrap">' . esc_html( $lbl ) . '</td><td style="white-space:nowrap"><input type="date" name="season_to[' . $i . ']" value="' . esc_attr( $to_v ) . '" min="' . esc_attr( wp_date( 'Y-m-d', (int) $r['from'] ) ) . '"' . ( $draft['deadline'] ? ' max="' . esc_attr( substr( (string) $draft['deadline'], 0, 10 ) ) . '"' : '' ) . ' title="последният ден на седмицата (включително); празно = 7 дни">' . ( ! empty( $r['to_bad'] ) ? ' <span style="color:#cf222e" title="въведеният край е преди началото на седмицата — ползва се 1 ден">⚠</span>' : '' ) . '</td><td>' . $st . '</td>';
			foreach ( $base as $bid => $b ) { $v = isset( $sn['weeks'][ $i ][ $bid ] ) ? $sn['weeks'][ $i ][ $bid ] : null; echo '<td>−<input type="text" inputmode="decimal" name="season_pct[' . $i . '][' . esc_attr( $bid ) . ']" data-sp="' . esc_attr( $bid ) . '" value="' . esc_attr( $fmt( $v ) ) . '" placeholder="' . esc_attr( $fmt( $b['pct'] ) ) . '" style="width:64px;text-align:right">%</td>'; }
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<script>(function(){var b=document.getElementById("apSeasonFill");if(!b)return;b.addEventListener("click",function(){var rows=document.querySelectorAll("#apSeasonT tbody tr"),n=rows.length;["s","m","l"].forEach(function(id){var f=document.querySelector("[data-sf=\"+id+\"]"),t=document.querySelector("[data-st=\"+id+\"]");if(!f||!t)return;var a=parseFloat(String(f.value).replace(",",".")),z=parseFloat(String(t.value).replace(",","."));if(isNaN(a))return;if(isNaN(z))z=a;rows.forEach(function(tr,i){var inp=tr.querySelector("[data-sp=\"+id+\"]");if(!inp)return;var v=n>1?a+(z-a)*i/(n-1):a;v=Math.round(v*2)/2;inp.value=String(v).replace(".",",");inp.dispatchEvent(new Event("input",{bubbles:true}))})})})})();</script>';
		echo '</div></section>';
	}

	/** Търсене на WC продукт по име (таб Продукти). */
	public static function ajax_search() {
		if ( ! current_user_can( Plugin::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) { wp_send_json_error( array( 'code' => 'auth' ), 403 ); }
		$q = sanitize_text_field( (string) wp_unslash( $_GET['q'] ?? '' ) );
		$out = array();
		if ( ctype_digit( $q ) ) { $wc = Catalog::wc_line( (int) $q ); if ( $wc['wc_name'] ) { $out[] = array( 'id' => (int) $q, 'name' => $wc['wc_name'], 'type' => $wc['variation_id'] ? 'variable' : 'simple', 'price' => $wc['price'], 'img' => $wc['img'] ); } }
		foreach ( Catalog::search( $q, 10 ) as $r ) { $wc = Catalog::wc_line( $r['id'] ); $r['img'] = $wc['img']; $r['price'] = $wc['price']; $r['note'] = $wc['note']; $out[] = $r; }
		wp_send_json_success( $out );
	}

	public static function menu() {
		add_menu_page( 'ansa™ Промо', '🎁 ansa Промо', Plugin::CAP, Plugin::MENU_SLUG, array( __CLASS__, 'page' ), 'dashicons-tickets-alt', 56 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Plugin::MENU_SLUG ) ) . '">Промо</a>' );
		return $links;
	}

	public static function url( $args = array() ) { return add_query_arg( $args, admin_url( 'admin.php?page=' . Plugin::MENU_SLUG ) ); }

	public static function form_open_public( $do, $tab = 'tools', $extra = '' ) { self::form_open( $do, $tab, $extra ); }
	private static function form_open( $do, $tab = 'tools', $extra = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . $extra . '>';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="ansa_promo_tools"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
	}

	/** admin-post: формите на 🧰 Инструменти. */
	public static function handle() {
		if ( ! current_user_can( Plugin::CAP ) ) { wp_die( 'Нямаш права.' ); }
		check_admin_referer( self::NONCE );
		$do = sanitize_key( (string) ( $_POST['do'] ?? '' ) ); $msg = '';
		switch ( $do ) {
			case 'settings':
				$cfg = Config::get_draft();
				$cfg['page_id'] = (int) ( $_POST['page_id'] ?? 0 );
				$cfg['enabled'] = ! empty( $_POST['enabled'] );
				$cfg['deadline'] = sanitize_text_field( (string) wp_unslash( $_POST['deadline'] ?? '' ) );
				/* v1.19.0: седмиците на сезона — season_pct[i][s|m|l]; празно = процентът на кутията; запетая = точка */
				$cfg['season']['weekly'] = ! empty( $_POST['season_weekly'] ); $cfg['season']['start'] = sanitize_text_field( (string) wp_unslash( $_POST['season_start'] ?? '' ) ); $cfg['season']['grace_hours'] = (int) ( $_POST['season_grace'] ?? 24 );
				$wk = (array) ( $_POST['season_pct'] ?? array() ); $cfg['season']['weeks'] = array(); $nmax = -1; foreach ( $wk as $i => $row ) { $nmax = max( $nmax, (int) $i ); }
				$wto = (array) ( $_POST['season_to'] ?? array() ); foreach ( $wto as $i => $v ) { $nmax = max( $nmax, (int) $i ); } /* 1.22.0: краят на седмицата по избор */
				for ( $i = 0; $i <= $nmax; $i++ ) { $row = array(); foreach ( array( 's', 'm', 'l' ) as $bid ) { $v = isset( $wk[ $i ][ $bid ] ) ? str_replace( ',', '.', trim( (string) wp_unslash( $wk[ $i ][ $bid ] ) ) ) : ''; $row[ $bid ] = ( '' === $v || ! is_numeric( $v ) ) ? null : (float) $v; } $row['to'] = sanitize_text_field( (string) wp_unslash( $wto[ $i ] ?? '' ) ); $cfg['season']['weeks'][] = $row; }
				$cfg['utm_param'] = sanitize_text_field( (string) wp_unslash( $_POST['utm_param'] ?? 'utm_content' ) );
				$cfg['gate']['enabled'] = ! empty( $_POST['gate_enabled'] ); $cfg['gate']['required'] = ! empty( $_POST['gate_required'] );
				$gi = (array) ( $_POST['gate_img'] ?? array() ); $cfg['gate']['images'] = array();
				foreach ( Config::gate_image_slots() as $k => $label ) { $cfg['gate']['images'][ $k ] = sanitize_text_field( (string) wp_unslash( $gi[ $k ] ?? '' ) ); }
				/* 1.24.22: снимките на петте козметични продукта (/kozmetika → „Какво се крие в кутията“) + замъглени */
				$ii = (array) ( $_POST['li_item_img'] ?? array() ); $ib = (array) ( $_POST['li_item_blur'] ?? array() ); $cfg['li']['items'] = array();
				for ( $i = 0; $i < 5; $i++ ) { $cfg['li']['items'][] = array( 'img' => sanitize_text_field( (string) wp_unslash( $ii[ $i ] ?? '' ) ), 'blur' => ! empty( $ib[ $i ] ) ); }
				$cfg['bgn']['show'] = ! empty( $_POST['bgn_show'] );
				$cfg['theme']['hide'] = ! empty( $_POST['theme_hide'] ); $cfg['theme']['selectors'] = (string) wp_unslash( $_POST['theme_selectors'] ?? '' );
				$cfg['preview']['enabled'] = ! empty( $_POST['preview_enabled'] ); $cfg['preview']['password'] = trim( wp_strip_all_tags( (string) wp_unslash( $_POST['preview_password'] ?? '' ) ) );
				/* v1.2.0: билети и награди */
				$cfg['tickets']['portal_url'] = esc_url_raw( (string) wp_unslash( $_POST['portal_url'] ?? '' ) ); $cfg['tickets']['email'] = ! empty( $_POST['ticket_email'] ); $cfg['tickets']['partner_bonus'] = (int) ( $_POST['partner_bonus'] ?? 1 ); $cfg['tickets']['fka_tag'] = trim( wp_strip_all_tags( (string) wp_unslash( $_POST['fka_tag'] ?? '' ) ) ); $bt = (array) ( $_POST['fka_tag_box'] ?? array() ); $cfg['tickets']['fka_tag_box'] = array(); foreach ( array( 's', 'm', 'l' ) as $bid ) { $cfg['tickets']['fka_tag_box'][ $bid ] = trim( wp_strip_all_tags( (string) wp_unslash( $bt[ $bid ] ?? '' ) ) ); }
				$cfg['rewards']['book']['product_id'] = (int) ( $_POST['book_product_id'] ?? 0 ); $cfg['rewards']['book']['url'] = esc_url_raw( (string) wp_unslash( $_POST['book_url'] ?? '' ) ); $cfg['rewards']['cosm']['product_id'] = (int) ( $_POST['cosm_product_id'] ?? 0 );
				/* v1.8.0: страницата „Как работи“ */
				$cfg['info']['enabled'] = ! empty( $_POST['info_enabled'] ); $cfg['info']['slug'] = sanitize_title( (string) wp_unslash( $_POST['info_slug'] ?? 'info' ) );
				/* 1.24.20: страницата „Комплектът за 1 €“ */
				$cfg['li']['enabled'] = ! empty( $_POST['li_enabled'] ); $cfg['li']['slug'] = sanitize_title( (string) wp_unslash( $_POST['li_slug'] ?? 'kozmetika' ) );
				/* 1.31.0: страницата „Sakura ×5“ (на корена на сайта) */
				$cfg['sx'] = array( 'enabled' => ! empty( $_POST['sx_enabled'] ), 'slug' => sanitize_title( (string) wp_unslash( $_POST['sx_slug'] ?? 'kozmetichen-set-promo' ) ), 'days' => (int) ( $_POST['sx_days'] ?? 30 ) );
				/* v1.6.0: томбола */
				$cfg['tombola']['enabled'] = ! empty( $_POST['tb_enabled'] ); $cfg['tombola']['slug'] = sanitize_title( (string) wp_unslash( $_POST['tb_slug'] ?? 'tombola' ) ); $cfg['tombola']['draw'] = sanitize_text_field( (string) wp_unslash( $_POST['tb_draw'] ?? '' ) );
				$cfg['tombola']['statuses'] = (string) wp_unslash( $_POST['tb_statuses'] ?? '' ); $cfg['tombola']['ref_param'] = sanitize_key( (string) wp_unslash( $_POST['tb_ref_param'] ?? 'r' ) );
				$cfg['tombola']['ref_mult'] = (int) ( $_POST['tb_ref_mult'] ?? 2 ); $cfg['tombola']['ref_max'] = (int) ( $_POST['tb_ref_max'] ?? 10 ); $cfg['tombola']['ref_gift'] = (int) ( $_POST['tb_ref_gift'] ?? 1 );
				/* v1.9.0: книгата /kniga + демо данни; PDF-ът се качва в скрита папка (Book::store_upload) */
				$cfg['book']['enabled'] = ! empty( $_POST['bk_enabled'] ); $cfg['book']['slug'] = sanitize_title( (string) wp_unslash( $_POST['bk_slug'] ?? 'kniga' ) ); $cfg['book']['url'] = esc_url_raw( (string) wp_unslash( $_POST['bk_url'] ?? '' ) ); $cfg['book']['remember'] = (int) ( $_POST['bk_remember'] ?? 365 );
				$cfg['demo']['phone'] = sanitize_text_field( (string) wp_unslash( $_POST['demo_phone'] ?? '' ) ); $cfg['demo']['email'] = sanitize_email( (string) wp_unslash( $_POST['demo_email'] ?? '' ) );
				/* v1.11.0: лийдовете от попъпа → FunnelKit */
				$cfg['leads'] = array( 'fka' => ! empty( $_POST['leads_fka'] ), 'list' => trim( wp_strip_all_tags( (string) wp_unslash( $_POST['leads_list'] ?? '' ) ) ), 'tag' => trim( wp_strip_all_tags( (string) wp_unslash( $_POST['leads_tag'] ?? '' ) ) ), 'consent_only' => ! empty( $_POST['leads_consent_only'] ), 'untag_on_order' => ! empty( $_POST['leads_untag'] ), 'cart_tag' => trim( wp_strip_all_tags( (string) wp_unslash( $_POST['leads_cart_tag'] ?? '' ) ) ) /* 1.25.0 */ );
				delete_option( 'ansa_promo_fka_leads_ensured' ); /* новите имена се създават на следващото зареждане на админа */
				$warn = '';
				if ( ! empty( $_FILES['bk_pdf']['name'] ) ) { $up = Book::store_upload( $_FILES['bk_pdf'] ); if ( is_wp_error( $up ) ) { $warn = ' ⚠ PDF: ' . $up->get_error_message(); } else { if ( $cfg['book']['file'] && $cfg['book']['file'] !== $up ) { Book::delete_file( $cfg['book']['file'] ); } $cfg['book']['file'] = $up; $warn = ' PDF-ът е качен (' . Book::file_info( $cfg )['size'] . ') — публикувай, за да е на живо.'; } }
				elseif ( ! empty( $_POST['bk_pick'] ) ) { $ad = Book::adopt( (string) wp_unslash( $_POST['bk_pick'] ) ); if ( $ad ) { if ( $cfg['book']['file'] && $cfg['book']['file'] !== $ad ) { Book::delete_file( $cfg['book']['file'] ); } $cfg['book']['file'] = $ad; $warn = ' Избран е ' . basename( $ad ) . ' (' . Book::file_info( $cfg )['size'] . ') — публикувай, за да е на живо.'; } else { $warn = ' ⚠ Файлът не е намерен в папката на книгата.'; } }
				elseif ( ! empty( $_POST['bk_pdf_remove'] ) && $cfg['book']['file'] ) { Book::delete_file( $cfg['book']['file'] ); $cfg['book']['file'] = ''; }
				Config::save_draft( $cfg ); $msg = 'Черновата е записана.' . $warn; break;
			case 'export_tombola':
				nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="ansa-promo-tombola-' . gmdate( 'Ymd-Hi' ) . '.csv"' );
				echo Tombola::csv( Tombola::entries() ); exit;
			case 'draw':
				$win = Tombola::draw( Tombola::entries(), sanitize_text_field( (string) wp_unslash( $_POST['label'] ?? '' ) ) );
				$msg = $win ? sprintf( '🎲 Изтеглен: поръчка #%s · %s · %s · %d билет(а). Записано в дневника на тегленията.', $win['number'], $win['name'], $win['email'], $win['total'] ) : 'Няма участници с билети.'; break;
			case 'export_stats':
				nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="ansa-promo-insights-' . gmdate( 'Ymd-Hi' ) . '.csv"' );
				echo Insights::csv( (int) ( $_POST['days'] ?? 30 ) ); exit;
			case 'export_sessions': /* 1.17.0 */
				nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="ansa-promo-sessions-' . gmdate( 'Ymd-Hi' ) . '.csv"' );
				/* 1.21.0: от 📜 История идва период + филтърът на списъка (from/to/step/mode/box/ord/dev) — CSV на точно тези редове; от 📊 Преглед — days както досега */
				$q = ! empty( $_POST['from'] ) || ! empty( $_POST['to'] ) ? array_map( 'sanitize_text_field', array_intersect_key( (array) wp_unslash( $_POST ), array_flip( array( 'from', 'to', 'step', 'mode', 'box', 'ord', 'dev' ) ) ) ) : null;
				if ( is_array( $q ) ) { foreach ( Insights::FILTER_KEYS as $fk ) { if ( isset( $_POST[ $fk ] ) ) { $_GET[ $fk ] = sanitize_text_field( (string) wp_unslash( $_POST[ $fk ] ) ); } } $f = Insights::filters(); $q['internal'] = $f['internal']; $q['attr'] = $f['attr']; $q['filter'] = Insights::report_opts( $f )['filter']; } /* 1.28.0: филтрите на екрана */
				echo Insights::csv_sessions( (int) ( $_POST['days'] ?? 30 ), $q ); exit;
			case 'export_orders':
				$rows = Tickets::orders( array( 'limit' => 2000 ) );
				nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="ansa-promo-orders-' . gmdate( 'Ymd-Hi' ) . '.csv"' );
				echo Tickets::csv( $rows ); exit;
			case 'publish':
				$msg = Config::publish() ? 'Публикувано — версия ' . Config::version() . '.' : 'Няма какво да се публикува.'; break;
			case 'discard':
				Config::discard_draft(); $msg = 'Черновата е отказана.'; break;
			case 'restore':
				$msg = Config::restore( (int) ( $_POST['version'] ?? 0 ) ) ? 'Версия ' . (int) $_POST['version'] . ' е върната като чернова.' : 'Няма такава версия.'; break;
			case 'import':
				$msg = Config::import( (string) wp_unslash( $_POST['json'] ?? '' ) ) ? 'JSON-ът е внесен като чернова.' : 'Невалиден JSON.'; break;
			case 'seed':
				list( $cfg, $report ) = Seed::build( true );
				$cur = Config::get_draft(); $cfg['page_id'] = $cur['page_id']; $cfg['copy'] = $cur['copy']; $cfg['checkout'] = $cur['checkout']; $cfg['enabled'] = $cur['enabled']; $cfg['deadline'] = $cur['deadline'];
				Config::save_draft( $cfg ); update_option( 'ansa_promo_seed_report', array( 'at' => current_time( 'mysql' ), 'products' => $report ), false );
				$msg = 'Черновата е направена наново от мокъпа: ' . implode( ', ', array_map( function ( $k, $v ) { return $k . '→' . $v; }, array_keys( $report ), $report ) ); break;
			case 'leads_sync': /* 1.11.0: лийдовете от попъпа → FunnelKit лист + таг */
				Lead_Sync::ensure_terms(); $r = Lead_Sync::backfill(); $msg = 'Лийдове → FunnelKit: ' . $r['ok'] . ' синхронизирани, ' . $r['retry'] . ' за повторен опит, ' . $r['skip'] . ' пропуснати (без съгласие/изключено). ' . Lead_Sync::status( Config::get_published() ); break;
			case 'fka_backfill': /* 1.8.4: тагът на старите промо поръчки */
				$r = Merge::backfill(); $msg = 'FunnelKit тагове (общият + по кутия): през FKA (event „Tag Added“) на ' . $r['crm'] . ' поръчки, направо в контакта на ' . $r['db'] . ', вече го имаха ' . $r['има'] . ', за повторен опит ' . $r['retry'] . ( $r['other'] ? ', пропуснати ' . $r['other'] . ' (тагът е изключен или FunnelKit контакти липсват)' : '' ) . '.'; break;
			case 'link_proxy': /* 1.13.1: HTTPS прокси за SendGrid линковете — отделна опция, без публикуване */
				Link_Proxy::save( ! empty( $_POST['lp_enabled'] ), (string) wp_unslash( $_POST['lp_host'] ?? '' ), (int) ( $_POST['lp_reset_days'] ?? 0 ) );
				$st = Link_Proxy::status(); $msg = 'SendGrid линкове: записано. ' . $st[0]; break;
			case 'favorite': /* 1.20.0: {{ansa_promo_fav}} — картата с кратките имена; отделна опция, без публикуване */
				Favorite::save( (string) wp_unslash( $_POST['fav_map'] ?? '' ), (string) wp_unslash( $_POST['fav_metric'] ?? 'packs' ), (string) wp_unslash( $_POST['fav_skip'] ?? '' ), (string) wp_unslash( $_POST['fav_fallback'] ?? '' ), (int) ( $_POST['fav_limit'] ?? 100 ) );
				$fs = Favorite::status(); $msg = 'Любим продукт: записано. ' . $fs[0]; break;
			case 'favorite_log_clear': /* 1.20.1 */
				Favorite::log_clear(); $msg = 'Любим продукт: дневникът е изчистен.'; break;
			case 'favorite_test': /* 1.20.0: какво ще получи този имейл */
				$em = sanitize_email( (string) wp_unslash( $_POST['fav_email'] ?? '' ) );
				if ( ! is_email( $em ) ) { $msg = 'Любим продукт: въведи имейл.'; break; }
				$r = Favorite::compute( $em, 0, true );
				$msg = $r ? sprintf( '⭐ %s → {{ansa_promo_fav}} = „%s“ (%s %s в %d поръчки от %d; последно %s) · всички: %s · {{ansa_promo_fav_url}} = %s', $em, $r['name'], Cart::fmt( $r['score'] ), 'spent' === $r['metric'] ? '€' : ( 'orders' === $r['metric'] ? 'поръчки' : ( 'qty' === $r['metric'] ? 'бр.' : 'опаковки' ) ), $r['orders'], $r['all_orders'], $r['last'] ? wp_date( 'd.m.Y', $r['last'] ) : '—', implode( ', ', array_map( function ( $k, $v ) { return $k . ' ' . Cart::fmt( $v ); }, array_keys( $r['breakdown'] ), $r['breakdown'] ) ), $r['url'] )
					: sprintf( '⭐ %s → няма поръчки с продукт от картата (%d поръчки общо) → {{ansa_promo_fav}} = „%s“ (текстът „когато няма“ / fallback на тага).', $em, count( Favorite::orders_for( $em ) ), Favorite::settings()['fallback'] );
				break;
			case 'ins_spend_add': case 'ins_spend_del': case 'ins_snap_add': case 'ins_snap_del': /* 1.28.0: разход, замразени отчети */
				$r = Insights::handle_action( substr( $do, 4 ), array_map( 'sanitize_text_field', array_map( 'wp_unslash', array_intersect_key( $_POST, array_flip( array( 'type', 'key', 'from', 'to', 'amount', 'note', 'id' ) ) ) ) ) ); $msg = $r[1];
				set_transient( 'ansa_promo_admin_msg_' . get_current_user_id(), $msg, 60 );
				wp_safe_redirect( self::url( 'ins_snap_add' === $do && ! empty( $r[2] ) ? array( 'tab' => 'insights', 'view' => 'snap', 'snap' => $r[2] ) : array( 'tab' => 'insights', 'view' => sanitize_key( (string) ( $_POST['view'] ?? 'overview' ) ) ) ) ); exit;
			case 'export_breakdown': /* 1.28.0: CSV по UTM / кампания / дни / … */
				$which = sanitize_key( (string) ( $_POST['which'] ?? 'utm' ) ); $_GET['attr'] = (string) ( $_POST['attr'] ?? '' ); $_GET['internal'] = (string) ( $_POST['internal'] ?? '' );
				nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="ansa-promo-' . $which . '-' . gmdate( 'Ymd-Hi' ) . '.csv"' );
				echo Insights::csv_breakdown( $which, Insights::range( (string) ( $_POST['from'] ?? '' ), (string) ( $_POST['to'] ?? '' ) ) ); exit;
			case 'insights_reports': /* 1.28.0: вътрешен трафик, седмичен отчет, аларми */
				$msg = Insights_Reports::save( array_map( function ( $v ) { return is_string( $v ) ? wp_unslash( $v ) : $v; }, $_POST ) ); break;
			case 'insights_report_send': $msg = Insights_Reports::send_weekly( ! empty( $_POST['to_me'] ) ? array( wp_get_current_user()->user_email ) : null ); break;
			case 'insights_alerts_test': $al = Insights_Reports::alerts_evaluate(); $msg = 'Аларми сега: ' . ( $al ? implode( ' · ', array_column( $al, 1 ) ) : 'няма (трафикът и конверсията са в норма)' ); break;
			case 'insights_api_token': $t = Insights_Reports::regenerate_api_token(); $msg = 'Нов API токен: ' . $t . ' — копирай го сега; старият вече не важи.'; break;
			case 'insights_api_token_clear': Insights_Reports::clear_api_token(); $msg = 'API токенът е изтрит — JSON адресът не работи.'; break;
			case 'phone_orders': $msg = Phone_Orders::save( array( 'pct' => (string) wp_unslash( $_POST['ph_pct'] ?? '5' ), 'gift_sku' => (string) wp_unslash( $_POST['ph_gift_sku'] ?? '' ), 'gift_id' => (int) ( $_POST['ph_gift_id'] ?? 0 ) ) ); break; /* 1.30.0 */
			case 'insights_portal': /* 1.27.0: отделният адрес на Insights за екипа по реклами — отделна опция, без публикуване */
				list( $ok, $txt ) = Insights_Portal::save( array( 'enabled' => ! empty( $_POST['ip_enabled'] ), 'slug' => (string) wp_unslash( $_POST['ip_slug'] ?? '' ), 'user' => (string) wp_unslash( $_POST['ip_user'] ?? '' ), 'ttl' => (int) ( $_POST['ip_ttl'] ?? 12 ), 'https_only' => ! empty( $_POST['ip_https'] ), 'password' => (string) wp_unslash( $_POST['ip_pw'] ?? '' ), 'password2' => (string) wp_unslash( $_POST['ip_pw2'] ?? '' ) ) );
				$msg = ( $ok ? '' : 'Грешка: ' ) . $txt; break;
			case 'insights_portal_logout_all': Insights_Portal::logout_all(); $msg = 'Insights URL: всички сесии са излезли — екипът трябва да влезе отново.'; break;
			case 'insights_portal_unlock': Insights_Portal::unlock_all(); $msg = 'Insights URL: блокираните IP адреси са отключени.'; break;
			case 'insights_portal_log_clear': Insights_Portal::log_clear(); $msg = 'Insights URL: дневникът на опитите е изчистен.'; break;
			case 'purge':
				Catalog::purge_runtime(); $c = Config::get_published(); Config::purge_page( $c['page_id'] ); $msg = 'Кешът на runtime-а и страницата е изчистен.'; break;
		}
		set_transient( 'ansa_promo_admin_msg_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( self::url( array( 'tab' => sanitize_key( (string) ( $_POST['tab'] ?? 'tools' ) ) ) ) ); exit;
	}

	public static function page() {
		if ( ! current_user_can( Plugin::CAP ) ) { wp_die( 'Нямаш права.' ); }
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? 'status' ) );
		$msg = get_transient( 'ansa_promo_admin_msg_' . get_current_user_id() ); if ( $msg ) { delete_transient( 'ansa_promo_admin_msg_' . get_current_user_id() ); }
		$col  = array( 'ok' => '#1a7f37', 'warn' => '#9a6700', 'bad' => '#cf222e', 'info' => '#57606a' );
		$draft = Config::get_draft(); $pub = Config::get_published();
		$page_url = $draft['page_id'] ? get_permalink( $draft['page_id'] ) : '';
		$tabs = array( 'status' => 'Състояние', 'tools' => '🧰 Инструменти', 'products' => '📦 Продукти', 'orders' => '🎟️ Поръчки', 'tombola' => '🎲 Томбола', 'insights' => '📈 Insights', 'json' => 'JSON' );
		?>
		<?php $scr = self::screens(); $cur = $scr[ $tab ] ?? $scr['status']; $draft_url = $page_url ? add_query_arg( 'ansa_promo', 'draft', $page_url ) : ''; ?>
		<script>window.AnsaPromoApp=<?php echo wp_json_encode( array( 'msg' => (string) $msg, 'tab' => $tab ) ); ?>;</script>
		<div id="ansa-promo-app" class="ap-tab-<?php echo esc_attr( $tab ); ?>">
			<aside class="ap-nav">
				<div class="ap-brand"><span class="dot"></span>ansa™ Промо<small>Admin · v<?php echo esc_html( ANSA_PROMO_VER ); ?></small></div>
				<div class="ap-state">
					играта: <?php echo Config::is_live( $pub ) ? '<span class="ok">ВКЛЮЧЕНА</span>' : ( ! empty( $pub['enabled'] ) ? '<span class="warn">изтекла</span>' : '<span class="off">изключена</span>' ); ?><?php echo $pub['deadline'] ? ' · до <b>' . esc_html( $pub['deadline'] ) . '</b>' : ''; ?><br>
					<?php $pw = Config::week_for( $pub, time() ); if ( $pw ) : /* v1.19.0 */ ?>седмица: <b><?php echo (int) $pw['i']; ?>/<?php echo (int) $pw['total']; ?></b> · −<?php echo esc_html( implode( ' / −', array_map( array( 'AnsaPromo\\Cart', 'fmt' ), array_values( $pw['pct'] ) ) ) ); ?>%<?php echo $pw['next'] ? ' · смяна <b>' . esc_html( wp_date( 'd.m H:i', (int) $pw['next']['from'] ) ) . '</b>' : ' · последна'; ?><br><?php endif; ?>
					публикувано: <b>v<?php echo (int) Config::version(); ?></b><?php echo Config::has_published() ? ' · ' . esc_html( date_i18n( 'd.m H:i', Config::published_at() ) ) : ''; ?><br>
					чернова: <?php echo Config::has_draft() ? '<span class="warn">има непубликувани промени</span>' : 'като публикуваното'; ?>
					<?php if ( $page_url ) : ?><div class="ap-state__links"><a href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener">страницата ↗</a><a href="<?php echo esc_url( $draft_url ); ?>" target="_blank" rel="noopener">черновата ↗</a></div><?php endif; ?>
				</div>
				<div class="ap-nav__list">
					<div class="ap-nav__label">Играта</div>
					<?php foreach ( array( 'status', 'products', 'tools' ) as $k ) : ?><a class="ap-nav__it<?php echo $k === $tab ? ' on' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => $k ) ) ); ?>"><span class="ic"><?php echo $scr[ $k ][0]; ?></span><?php echo esc_html( $scr[ $k ][1] ); ?><?php if ( 'products' === $k ) : ?><span class="n"><?php echo count( $draft['products'] ); ?></span><?php endif; ?></a>
					<?php if ( 'tools' === $k && 'tools' === $tab ) : ?><div class="ap-nav__sub"><?php foreach ( self::tool_sections() as $sid => $sl ) : ?><a href="#<?php echo esc_attr( $sid ); ?>"><?php echo esc_html( $sl ); ?></a><?php endforeach; ?></div><?php endif; ?>
					<?php endforeach; ?>
					<a class="ap-nav__it<?php echo 'editor' === $tab ? ' on' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => 'editor' ) ) ); ?>"><span class="ic">✏️</span>Редактор</a>
					<div class="ap-nav__label">Сезонът</div>
					<?php foreach ( array( 'orders', 'tombola', 'insights' ) as $k ) : ?><a class="ap-nav__it<?php echo $k === $tab ? ' on' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => $k ) ) ); ?>"><span class="ic"><?php echo $scr[ $k ][0]; ?></span><?php echo esc_html( $scr[ $k ][1] ); ?></a>
					<?php if ( 'insights' === $k && 'insights' === $tab ) : ?><div class="ap-nav__sub"><?php $iv = Insights::current_view(); foreach ( Insights::views() as $vk => $vl ) : ?><a class="<?php echo $vk === $iv ? 'on' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => 'insights', 'view' => $vk ) ) ); ?>"><?php echo esc_html( $vl ); ?></a><?php endforeach; ?></div><?php endif; /* 1.21.0: подменю Преглед · На живо · История */ ?>
					<?php endforeach; ?>
					<div class="ap-nav__label">Още</div>
					<a class="ap-nav__it<?php echo 'json' === $tab ? ' on' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => 'json' ) ) ); ?>"><span class="ic">{ }</span>JSON</a>
					<a class="ap-nav__it" href="<?php echo esc_url( admin_url( 'admin.php?page=pcc-github' ) ); ?>"><span class="ic">⬆️</span>GitHub ъпдейти<span class="ext">↗</span></a>
				</div>
				<a class="ap-nav__foot" href="<?php echo esc_url( admin_url() ); ?>">↩ Обратно към WP админа</a>
			</aside>
			<div class="ap-page ap-page--<?php echo esc_attr( $tab ); ?>">
				<?php if ( 'editor' !== $tab ) : ?><div class="ap-top"><h1><?php echo $cur[0] . ' ' . esc_html( $cur[1] ); ?><small><?php echo esc_html( $cur[2] ); ?></small></h1>
					<?php if ( 'products' !== $tab ) : ?><div class="ap-top__actions">
						<?php if ( 'tools' === $tab ) : ?><button type="button" class="ap-btn ap-btn--secondary" data-ap-save data-ap-save-top>💾 Запази черновата</button><?php self::form_open( 'publish' ); ?><button class="ap-btn ap-btn--primary">🚀 Публикувай</button></form><?php endif; ?>
						<?php if ( 'status' === $tab ) : ?><?php self::form_open( 'purge' ); ?><button class="ap-btn ap-btn--secondary">🧹 Изчисти кеша</button></form><a class="ap-btn ap-btn--primary" href="<?php echo esc_url( Editor::url() ); ?>">✏️ Редактор</a><?php endif; ?>
					</div><?php endif; ?>
				</div><?php endif; ?>
				<div class="ap-body"><div class="ap-body-inner<?php echo in_array( $tab, array( 'orders', 'tombola', 'insights', 'editor' ), true ) ? ' ap-body-inner--wide' : ''; ?>">
			<?php if ( $msg && ! in_array( $tab, array( 'products', 'editor' ), true ) ) : ?><div class="ap-msg"><?php echo esc_html( $msg ); ?><button type="button" aria-label="затвори">✕</button></div><?php endif; ?>
			<?php if ( 'status' === $tab ) : ?>
				<?php $env = Doctor::env(); $nbad = count( array_filter( $env, function ( $r ) { return 'bad' === $r['level']; } ) ); $nwarn = count( array_filter( $env, function ( $r ) { return 'warn' === $r['level']; } ) ); $live_n = count( Frontend::products( $pub ) ); ?>
				<div class="ap-kpis" style="padding:0 0 14px">
					<div class="ap-kpi <?php echo Config::is_live( $pub ) ? 'good' : 'bad'; ?>"><b><?php echo Config::is_live( $pub ) ? 'ВКЛЮЧЕНА' : ( ! empty( $pub['enabled'] ) ? 'изтекла' : 'изключена' ); ?></b><span>играта</span></div>
					<div class="ap-kpi"><b>v<?php echo (int) Config::version(); ?></b><span>публикувано<?php echo Config::has_published() ? ' · ' . esc_html( date_i18n( 'd.m H:i', Config::published_at() ) ) : ''; ?></span></div>
					<div class="ap-kpi <?php echo Config::has_draft() ? 'bad' : 'good'; ?>"><b><?php echo Config::has_draft() ? 'чака' : 'чиста'; ?></b><span>чернова</span></div>
					<div class="ap-kpi <?php echo $live_n ? 'good' : 'bad'; ?>"><b><?php echo (int) $live_n; ?> / <?php echo count( $pub['products'] ); ?></b><span>продукта на живо</span></div>
					<div class="ap-kpi <?php echo $nbad ? 'bad' : ( $nwarn ? '' : 'good' ); ?>"><b><?php echo $nbad ? '⛔ ' . $nbad : ( $nwarn ? '⚠ ' . $nwarn : '✓' ); ?></b><span>Doctor</span></div>
				</div>
				<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🩺 Състояние на играта</span><?php if ( $page_url ) : ?><a class="ap-btn ap-btn--secondary ap-btn--sm" href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener">страницата ↗</a> <a class="ap-btn ap-btn--secondary ap-btn--sm" href="<?php echo esc_url( $draft_url ); ?>" target="_blank" rel="noopener">черновата ↗</a><?php endif; ?></div>
					<div class="ap-env">
					<?php foreach ( $env as $r ) : $lv = in_array( $r['level'], array( 'ok', 'warn', 'bad', 'info' ), true ) ? $r['level'] : 'info'; ?>
						<div class="ap-env__r <?php echo esc_attr( $lv ); ?>"><span class="ap-env__dot <?php echo esc_attr( $lv ); ?>"></span><span class="ap-env__l"><?php echo esc_html( $r['label'] ); ?></span><span class="ap-env__v"><?php echo esc_html( $r['value'] ); ?></span></div>
					<?php endforeach; ?>
					</div>
				</div>
			<?php elseif ( 'tools' === $tab ) : ?>
				<?php self::form_open( 'settings', 'tools', ' style="display:block" enctype="multipart/form-data" data-ap-main' ); ?>
						<section class="ap-sec" id="s-page"><div class="ap-sec__h"><span class="ap-sec__t">🎯 Страница и игра</span></div><div class="ap-sec__b"><p class="ap-fl"><b>Страница с [ansa_promo]</b></p><select name="page_id"><option value="0">— избери —</option>
							<?php foreach ( Doctor::pages_with_shortcode( true ) as $pg ) : ?><option value="<?php echo (int) $pg['id']; ?>"<?php selected( $pg['id'], $draft['page_id'] ); ?>>#<?php echo (int) $pg['id']; ?> <?php echo esc_html( $pg['title'] ); ?> (<?php echo esc_html( $pg['status'] ); ?>)</option><?php endforeach; ?>
						</select><p class="description">Само страници, в чието съдържание има <code>[ansa_promo]</code>.</p>
						<p class="ap-fl"><b>Играта е включена</b></p><label><input type="checkbox" name="enabled" value="1"<?php checked( $draft['enabled'] ); ?>> публичната страница показва играта (иначе „скоро“; черновата се вижда винаги с <code>?ansa_promo=draft</code>)</label>
						<p class="ap-fl"><b>Край</b></p><input type="text" name="deadline" value="<?php echo esc_attr( $draft['deadline'] ); ?>" class="regular-text" placeholder="2026-12-31 23:59">
						<p class="ap-fl"><b>UTM параметър</b></p><input type="text" name="utm_param" value="<?php echo esc_attr( $draft['utm_param'] ); ?>" class="regular-text"> <span class="description">кацане: <code>?<?php echo esc_html( $draft['utm_param'] ); ?>=sakura</code></span>
						</div></section><?php self::season_section( $draft, $draft_url ); ?><section class="ap-sec" id="s-gate"><div class="ap-sec__h"><span class="ap-sec__t">Имейл-попъп</span></div><div class="ap-sec__b"><label><input type="checkbox" name="gate_enabled" value="1"<?php checked( $draft['gate']['enabled'] ); ?>> включен</label> &nbsp; <label><input type="checkbox" name="gate_required" value="1"<?php checked( $draft['gate']['required'] ); ?>> задължителен (без „Продължи без имейл“)</label>
							<p class="description">Промяната важи след <b>🚀 Публикувай</b>. Кешираната страница се оправя сама до секунда след отваряне (свежият конфиг идва по ajax и затваря попъпа), но изчисти кеша (Публикуване → 🧹), за да не трепва. <b>Кацане от имейл е без попъп</b> винаги: линк с <code>utm_medium=email</code> или <code>utm_source</code>, съдържащ mail/sendgrid/newsletter/fka, или <code>?gate=0</code> — имейлът вече е известен.</p>
						</div></section><section class="ap-sec" id="s-leads"><div class="ap-sec__h"><span class="ap-sec__t">📨 Лийдовете → FunnelKit</span></div><div class="ap-sec__b"><?php $ld = $draft['leads']; $ls = Leads::stats(); ?>
							<p><label><input type="checkbox" name="leads_fka" value="1"<?php checked( ! empty( $ld['fka'] ) ); ?>> имейлът от попъпа става контакт във FunnelKit (и за гост, без WP акаунт) и получава лист + таг — тригърът за автоматизацията „не е завършила поръчката“</label></p>
							<p><label>Лист: <input type="text" name="leads_list" class="regular-text" value="<?php echo esc_attr( (string) $ld['list'] ); ?>" placeholder="Сезонът на ansa — лийдове"></label> &nbsp; <label>Таг: <input type="text" name="leads_tag" value="<?php echo esc_attr( (string) $ld['tag'] ); ?>" placeholder="sezon-lead" style="width:160px"></label> <span class="description">празно = без лист / без таг; създават се сами във FKA (Contacts → Lists / Tags)</span></p>
							<p><label><input type="checkbox" name="leads_consent_only" value="1"<?php checked( ! empty( $ld['consent_only'] ) ); ?>> само с отметнато съгласие в попъпа</label> &nbsp; <label><input type="checkbox" name="leads_untag" value="1"<?php checked( ! empty( $ld['untag_on_order'] ) ); ?>> при промо поръчка тагът пада от контакта (листът остава) — автоматизацията спира за тези, които са поръчали</label></p>
							<p class="description">Автоматизацията във FKA: тригър <b>„Tag Added“</b> (тагът по-горе) или <b>„Contact Added to List“</b> → Delay (напр. 2 часа) → условие <b>Contact Tags — has</b> тага (т.е. още не е поръчала; при поръчка тагът пада) → Send Email → … Поръчалите получават и таговете по-горе („Сезонът на Анса“ + по кутия) от <code>Merge::tag_order</code>. Състояние: <?php echo esc_html( Lead_Sync::status( $draft ) ); ?></p>
							<p class="ap-fl"><b>🛒 Изоставена промо количка</b> <small>(1.25.0)</small></p>
							<p><label>Таг на контакта, докато количката му е от Сезона: <input type="text" name="leads_cart_tag" value="<?php echo esc_attr( (string) ( $ld['cart_tag'] ?? '' ) ); ?>" placeholder="sezon-kolichka" style="width:160px"></label> <span class="description">празно = изключено; създава се сам във FKA</span></p>
							<p class="description">FunnelKit не различава промо количката от обикновената (същите продукти, същият чекаут), затова този таг е на контакта <b>докато в количката му има промо кутия</b> — слага се, когато FKA запише количката (имейл в чекаута / логнат клиент), пада, когато количката стане обикновена или човекът поръча; крон на 10 мин сверява с таблицата на FKA. <b>Във FKA:</b> Automations → event <b>Cart Abandoned</b> → условие <b>Contact Tags — has</b> <code><?php echo esc_html( (string) ( $ld['cart_tag'] ?: 'sezon-kolichka' ) ); ?></code> = flow-ът за Сезона; в общите Cart Recovery flow-ове добави <b>Contact Tags — does not have</b> същия таг, за да не се дублират писмата. Състояние: <?php echo esc_html( Cart_Sync::status( $draft ) ); ?></p>
						
						</div></section><section class="ap-sec" id="s-images"><div class="ap-sec__h"><span class="ap-sec__t">Снимки на наградите</span></div><div class="ap-sec__b">
							<p class="description" style="margin:0 0 8px">Снимките на наградите в имейл-попъпа, в картите на кутиите, в „Честито“ и в „Напълни кутията си“ (яхта · сет · книга · отстъпка · доставка) и на трите кутии в попъпа. Attachment ID от медийната библиотека или пълен URL. <b>Празно = примерната снимка</b> (сивият етикет „примерна снимка“ на картинката изчезва, щом сложиш своя). Наградите се показват квадратно (1:1), кутиите в попъпа — хоризонтално (16:6) — слагай снимки с мотив в центъра, ≥ 800px.</p>
							<style>.ap-gi{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}.ap-gi figure{margin:0;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px}.ap-gi figure img{display:block;width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:6px;background:#f6f7f7}.ap-gi figcaption{font-size:11px;color:#50575e;margin:6px 0 4px}.ap-gi .ap-gi-row{display:flex;gap:4px}.ap-gi .ap-gi-row input{flex:1;min-width:0}.ap-gi .ap-gi-dummy{font-size:10px;color:#9a6700}</style>
							<div class="ap-gi" id="apGateImgs">
							<?php foreach ( Config::gate_image_slots() as $k => $label ) : $cur = (string) ( $draft['gate']['images'][ $k ] ?? '' ); $url = Frontend::gate_images( $draft )[ $k ]; $dummy = Frontend::gate_image_is_dummy( $draft, $k ); ?>
								<figure data-k="<?php echo esc_attr( $k ); ?>" data-dummy="<?php echo esc_url( Frontend::gate_dummy( $k ) ); ?>">
									<img src="<?php echo esc_url( $url ); ?>" alt="">
									<figcaption><?php echo esc_html( $label ); ?> <span class="ap-gi-dummy"<?php echo $dummy ? '' : ' hidden'; ?>>· примерна</span></figcaption>
									<div class="ap-gi-row"><input type="text" name="gate_img[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $cur ); ?>" placeholder="ID или URL"><button type="button" class="button ap-gi-pick" title="избери от медийната библиотека">📁</button><button type="button" class="button ap-gi-clear" title="изчисти → примерна снимка">✕</button></div>
								</figure>
							<?php endforeach; ?>
							</div>
						
						</div></section><section class="ap-sec" id="s-cosm"><div class="ap-sec__h"><span class="ap-sec__t">💄 Козметичните продукти (/kozmetika)</span></div><div class="ap-sec__b">
							<p class="description" style="margin:0 0 8px">Снимките на петте продукта в секцията <b>„Какво се крие в кутията“</b> на страницата <?php $lu = Listicle::url( $draft ); ?><a href="<?php echo esc_url( $lu ); ?>" target="_blank"><?php echo esc_html( $lu ); ?></a>. Attachment ID или URL; <b>празно = емоджи</b>. „Замъглена“ размива снимката на страницата (изненадите) — махни отметката, ако качиш вече замъглена снимка. Имената и описанията се редактират в ✏️ Редактор (текстове <code>li.pr.N</code>); редът на секциите — в ✏️ Редактор → 📄 Страници.</p>
							<div class="ap-gi" id="apCosmImgs">
							<?php $cc = Copy::effective( $draft['copy'] ); foreach ( Frontend::li_items( $draft ) as $i => $it ) : $cur = (string) ( $draft['li']['items'][ $i ]['img'] ?? '' ); $ph = Frontend::gate_dummy( 'cosm' ); ?>
								<figure data-k="li<?php echo (int) $i; ?>" data-dummy="<?php echo esc_url( $ph ); ?>">
									<img src="<?php echo esc_url( $it['img'] ?: $ph ); ?>" alt="">
									<figcaption><?php echo (int) ( $i + 1 ); ?> · <?php echo esc_html( (string) ( $cc[ 'li.pr.' . ( $i + 1 ) . '.t' ] ?? '' ) ); ?> <span class="ap-gi-dummy"<?php echo $it['img'] ? ' hidden' : ''; ?>>· без снимка (емоджи)</span></figcaption>
									<div class="ap-gi-row"><input type="text" name="li_item_img[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $cur ); ?>" placeholder="ID или URL"><button type="button" class="button ap-gi-pick" title="избери от медийната библиотека">📁</button><button type="button" class="button ap-gi-clear" title="изчисти → емоджи">✕</button></div>
									<label style="display:block;font-size:11px;margin-top:4px"><input type="checkbox" name="li_item_blur[<?php echo (int) $i; ?>]" value="1"<?php checked( ! empty( $it['blur'] ) ); ?>> замъглена (изненада)</label>
								</figure>
							<?php endforeach; ?>
							</div>
							<script>
							/* 1.24.23 (човекът: „не ми дава да натисна бутона“): скриптът е СЛЕД двете карти — иначе #apCosmImgs още го няма в DOM-а и бутоните му остават без обработчик */
							(function(){
								['apGateImgs','apCosmImgs'].forEach(function(rid){var root=document.getElementById(rid);if(!root)return; /* 1.24.22: и козметичните продукти */
								root.querySelectorAll('figure').forEach(function(f){
									var inp=f.querySelector('input'),img=f.querySelector('img'),dm=f.querySelector('.ap-gi-dummy');
									function show(url,isDummy){img.src=url;dm.hidden=!isDummy}
									f.querySelector('.ap-gi-clear').onclick=function(){inp.value='';show(f.dataset.dummy,true)};
									inp.addEventListener('change',function(){var v=inp.value.trim();if(!v){show(f.dataset.dummy,true)}else if(/^https?:\/\//.test(v)){show(v,false)}});
									f.querySelector('.ap-gi-pick').onclick=function(){
										if(!window.wp||!wp.media){alert('Медийната библиотека не е заредена — въведи attachment ID или URL.');return}
										var fr=wp.media({title:'Снимка за попъпа',library:{type:'image'},multiple:false,button:{text:'Използвай'}});
										fr.on('select',function(){var a=fr.state().get('selection').first().toJSON();inp.value=a.id;var sz=a.sizes&&(a.sizes.medium_large||a.sizes.medium||a.sizes.full);show(sz?sz.url:a.url,false)});
										fr.open()};
								})});
							})();
							</script>
						
						</div></section><section class="ap-sec" id="s-bgn"><div class="ap-sec__h"><span class="ap-sec__t">Лева в поръчката</span></div><div class="ap-sec__b"><label><input type="checkbox" name="bgn_show" value="1"<?php checked( $draft['bgn']['show'] ); ?>> показвай „(… лв.)“ до крайната сума</label>
						</div></section><section class="ap-sec" id="s-preview"><div class="ap-sec__h"><span class="ap-sec__t">Преглед с парола</span></div><div class="ap-sec__b">
							<label><input type="checkbox" name="preview_enabled" value="1"<?php checked( ! empty( $draft['preview']['enabled'] ) ); ?>> докато играта не е пусната, страницата показва форма за парола; с правилна парола се вижда <b>черновата</b> (за екипа и избрани клиенти, без вход в сайта)</label>
							<p><input type="text" name="preview_password" class="regular-text code" value="<?php echo esc_attr( $draft['preview']['password'] ); ?>" placeholder="парола"> <span class="description">паролата се помни в браузъра 30 дни; смяната ѝ изхвърля всички</span></p>
							<?php $pl = Frontend::preview_link(); if ( $pl ) : ?><p class="description">Линк за екипа (отваря и записва паролата): <code><?php echo esc_html( $pl ); ?></code></p><?php else : ?><p class="description">Избери страницата по-горе, за да видиш линка за екипа.</p><?php endif; ?>
						
						</div></section><section class="ap-sec" id="s-tickets"><div class="ap-sec__h"><span class="ap-sec__t">🎟️ Билети и награди</span></div><div class="ap-sec__b">
							<p><label>Портал за билетите (URL в QR кода; празно = промо страницата с <code>?ticket=КОД</code>)<br><input type="url" name="portal_url" class="regular-text code" value="<?php echo esc_attr( $draft['tickets']['portal_url'] ); ?>" placeholder="https://portal.ansa.bg/"></label></p>
							<p><label><input type="checkbox" name="ticket_email" value="1"<?php checked( ! empty( $draft['tickets']['email'] ) ); ?>> и имейл с кода при издаване на билета (QR кодът е в пратката така или иначе)</label> &nbsp; <label>бонус билети за стандартен партньорски код (ansa Partner): <input type="number" name="partner_bonus" min="0" max="5" value="<?php echo (int) $draft['tickets']['partner_bonus']; ?>" style="width:60px"></label> <small>кодът за играта на партньора носи своя брой (Partner → картата на партньора)</small></p>
							<p><label>FunnelKit Automations — таг на контакта при поръчка от промото (празно = без таг): <input type="text" name="fka_tag" class="regular-text" value="<?php echo esc_attr( (string) ( $draft['tickets']['fka_tag'] ?? '' ) ); ?>" placeholder="Сезонът на Анса"></label><br><span class="description">Тригърът за имейла за потвърждение: в Automations → event „Tag Added“ с този таг (или „Order Created“ + правило „Contact Tags“). Merge: <code>{{ansa_promo_tickets_text}}</code>, <code>{{ansa_promo_tickets}}</code>, <code>{{ansa_promo_bileta}}</code>, <code>{{ansa_promo_box}}</code>.</span></p>
							<p>Таг по кутия (1.8.8; сегментът за различните имейли; празно = без): <?php $btd = (array) ( $draft['tickets']['fka_tag_box'] ?? array() ); foreach ( array( 's' => 'Малка', 'm' => 'Средна', 'l' => 'Голяма' ) as $bid => $bl ) : ?><label style="margin-right:10px"><?php echo esc_html( $bl ); ?>: <input type="text" name="fka_tag_box[<?php echo esc_attr( $bid ); ?>]" value="<?php echo esc_attr( (string) ( $btd[ $bid ] ?? '' ) ); ?>" style="width:150px"></label><?php endforeach; ?></p>
							<p><label>Книга — WC продукт ID (влиза в количката на €0): <input type="number" name="book_product_id" value="<?php echo (int) $draft['rewards']['book']['product_id']; ?>" style="width:90px"></label> &nbsp; <label>линк към PDF-а (имейл при „Изпълнена“, ако продуктът не е downloadable): <input type="url" name="book_url" class="regular-text code" value="<?php echo esc_attr( $draft['rewards']['book']['url'] ); ?>"></label></p>
							<p><label>Козметичен сет — WC продукт ID (само за справка/ваучера): <input type="number" name="cosm_product_id" value="<?php echo (int) $draft['rewards']['cosm']['product_id']; ?>" style="width:90px"></label></p>
							<p class="description">Билетът се издава при „Обработва се“/„Изпълнена“; QR за стикера е в поръчката (meta box „🎁 ansa™ Промо“ → „Стикер за пратката“). Ваучерът за сета и книгата тръгват при „Изпълнена“. Текстовете са в регистъра, група „14 · Имейли · стикер“.</p>
						
						</div></section><section class="ap-sec" id="s-info"><div class="ap-sec__h"><span class="ap-sec__t">ℹ️ Как работи</span></div><div class="ap-sec__b">
							<?php $inf = $draft['info']; $inf_url = Info::url( $draft ); ?>
							<p><label><input type="checkbox" name="info_enabled" value="1"<?php checked( ! empty( $inf['enabled'] ) ); ?>> самостоятелната страница „Как работи“ е включена</label> &nbsp; <label>път: <code><?php echo esc_html( trim( (string) wp_parse_url( Cart::promo_url( $draft ), PHP_URL_PATH ), '/' ) ); ?>/</code><input type="text" name="info_slug" value="<?php echo esc_attr( $inf['slug'] ); ?>" style="width:110px" class="code"></label> → <a href="<?php echo esc_url( $inf_url ); ?>" target="_blank"><?php echo esc_html( $inf_url ); ?></a> &nbsp; <a href="<?php echo esc_url( add_query_arg( 'ansa_promo', 'draft', $inf_url ) ); ?>" target="_blank">чернова</a></p>
							<p class="description">Същото съдържание като попъпа „Как работи играта?“ (група „2а · Как работи“ в редактора; <code>info.title</code>/<code>info.desc</code> са заглавието и описанието за Google/споделяне), индексируема — за трафик от реклами и социални мрежи. Бутоните ѝ водят към промо страницата с избраната кутия (<code>?box=s|m|l</code>) или продукт. При включена парола или изключена игра показва „скоро“.</p>
						
						</div></section><section class="ap-sec" id="s-li"><div class="ap-sec__h"><span class="ap-sec__t">💄 Комплектът за 1 € (listicle за рекламата)</span></div><div class="ap-sec__b">
							<?php $lic = $draft['li']; $li_url = Listicle::url( $draft ); ?>
							<p><label><input type="checkbox" name="li_enabled" value="1"<?php checked( ! empty( $lic['enabled'] ) ); ?>> страницата „Комплектът за 1 €“ е включена</label> &nbsp; <label>път: <code><?php echo esc_html( trim( (string) wp_parse_url( Cart::promo_url( $draft ), PHP_URL_PATH ), '/' ) ); ?>/</code><input type="text" name="li_slug" value="<?php echo esc_attr( $lic['slug'] ); ?>" class="small-text"><code>/</code></label> → <a href="<?php echo esc_url( $li_url ); ?>" target="_blank"><?php echo esc_html( $li_url ); ?></a></p>
							<p class="description">Страницата за трафик от рекламата за козметичния комплект (текстът на човека, 21.10). Всеки бутон води към промо страницата с <code>?utm_content=cosm</code> — кампанията „Козметика“ поема (без имейл-попъп, попъпът „Козметиката за 1 евро ще бъде твоя“, Голямата кутия). Текстовете: група „2б · Страница „Комплектът за 1 €““ в редактора (екран „Страница „Комплектът за 1 €““); <code>li.title</code>/<code>li.desc</code> са заглавието и описанието за Google. Петте карти с продуктите от комплекта (<code>li.set.N.t</code>/<code>.d</code>) са празни до разкриването — попълнете ги на 15 ноември и те заместват текста „Показваме първите три…“. Броячът „Останаха X“ излиза, когато остават под 40 % от <code>cosm.limit</code>.</p>
						</div></section><section class="ap-sec" id="s-sx"><div class="ap-sec__h"><span class="ap-sec__t">🌺 Страница „Sakura ×5“ (адверториалът за рекламата за комплекта)</span></div><div class="ap-sec__b">
							<?php $sxc = $draft['sx']; $sx_url = Sakura_Page::url( $draft ); ?>
							<p><label><input type="checkbox" name="sx_enabled" value="1"<?php checked( ! empty( $sxc['enabled'] ) ); ?>> страницата „Sakura ×5“ е включена</label> &nbsp; <label>път: <code><?php echo esc_html( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) ); ?>/</code><input type="text" name="sx_slug" value="<?php echo esc_attr( $sxc['slug'] ); ?>" class="regular-text code" style="width:220px"><code>/</code></label> → <a href="<?php echo esc_url( $sx_url ); ?>" target="_blank"><?php echo esc_html( $sx_url ); ?></a> &nbsp; <label>дни в една опаковка Sakura: <input type="number" name="sx_days" value="<?php echo (int) $sxc['days']; ?>" min="1" max="365" class="small-text"></label></p>
							<p class="description">Адверториалът на човека (26.10): „Знаем, че си дошла за козметиката“ → защо кремът не стига → Sakura → офертата (пет Sakura с −<?php echo esc_html( Cart::fmt( ( Config::box( $draft, 'l' ) ?: array( 'pct' => 40 ) )['pct'] ) ); ?> % + комплектът за 1 €) → три стъпки → въпроси. На корена на сайта (не под промо страницата). Всеки бутон води към промо страницата с <code>?utm_content=sakura&amp;box=l</code> — Sakura е „твоят продукт“, Голямата кутия се избира сама. Текстовете: група „2в · Страница „Sakura ×5““ в ✏️ Редактор (екран „Страница „Sakura ×5““; празно поле = скрит текст); редът на секциите и снимките (hero, кутията) — ✏️ Редактор → 📄 Страници → „Sakura ×5“. Цените се смятат от цената на Sakura в WooCommerce и Голямата кутия (<code>{{sx_full}}</code>, <code>{{sx_pay}}</code>, <code>{{sx_total}}</code>…); „дни в една опаковка“ дава <code>{{sx_days}}</code> (× 5) и „под 1 € на ден“. Цитатите в „Какво казват жените“ са празни, докато не ги попълните — секцията не се показва на живо без тях. Снимките на петте козметични продукта са тези от 💄 Козметичните продукти по-горе.</p>
						</div></section><section class="ap-sec" id="s-tombola"><div class="ap-sec__h"><span class="ap-sec__t">🎲 Томбола</span></div><div class="ap-sec__b">
							<?php $tb = $draft['tombola']; $tb_url = Tombola::url( $draft ); ?>
							<p><label><input type="checkbox" name="tb_enabled" value="1"<?php checked( ! empty( $tb['enabled'] ) ); ?>> страницата за проверка на билетите е включена</label> &nbsp; <label>път: <code><?php echo esc_html( trim( (string) wp_parse_url( Cart::promo_url( $draft ), PHP_URL_PATH ), '/' ) ); ?>/</code><input type="text" name="tb_slug" value="<?php echo esc_attr( $tb['slug'] ); ?>" style="width:110px" class="code"></label> → <a href="<?php echo esc_url( $tb_url ); ?>" target="_blank"><?php echo esc_html( $tb_url ); ?></a> <small>(това е линкът за SMS-а при доставка; работи и <code>[ansa_promo_tombola]</code> на отделна страница)</small></p>
							<p><label>Теглене на: <input type="text" name="tb_draw" value="<?php echo esc_attr( $tb['draw'] ); ?>" style="width:120px" placeholder="2027-01-25"></label> &nbsp; <label><b>„получена“ поръчка</b> = статуси: <input type="text" name="tb_statuses" value="<?php echo esc_attr( implode( ', ', (array) $tb['statuses'] ) ); ?>" class="regular-text code"></label> <small>без „wc-“; налични: <?php echo esc_html( implode( ', ', array_map( function ( $s ) { return preg_replace( '/^wc-/', '', $s ); }, array_keys( wc_get_order_statuses() ) ) ) ); ?></small></p>
							<p><label>Препоръка — параметър в линка: <code>?</code><input type="text" name="tb_ref_param" value="<?php echo esc_attr( $tb['ref_param'] ); ?>" style="width:50px" class="code"><code>=КОД</code></label> &nbsp; <label>множител ×<input type="number" name="tb_ref_mult" min="1" max="10" value="<?php echo (int) $tb['ref_mult']; ?>" style="width:55px"></label> &nbsp; <label>най-много броени препоръки: <input type="number" name="tb_ref_max" min="0" max="1000" value="<?php echo (int) $tb['ref_max']; ?>" style="width:65px"></label> <small>(0 = без лимит)</small> &nbsp; <label>подарък за новия клиент: <input type="number" name="tb_ref_gift" min="0" max="10" value="<?php echo (int) $tb['ref_gift']; ?>" style="width:55px"> билет(а)</label></p>
							<p class="description"><b>„Получена“ важи за всичко: томболата, ваучера за сета и книгата</b> — само тези статуси (по подразбиране само <code>delivered</code>; „Изпълнена“/<code>completed</code> НЕ брои). Ваучерът и имейлът за книгата тръгват, когато поръчката влезе в такъв статус. Клиентът въвежда телефон + имейл от поръчката; при „получена“ поръчка вижда билетите си (активират се), датата на тегленето, ваучера за сета и личния си линк <code><?php echo esc_html( Cart::promo_url( $draft ) ); ?>?<?php echo esc_html( $tb['ref_param'] ); ?>=КОД</code>. Приятел, който поръча през линка, получава +подаръка в поръчката си; първата платена препоръка умножава билетите на препоръчалия ×<?php echo (int) $tb['ref_mult']; ?>, всяка следваща добавя още толкова (до лимита). Същият имейл/телефон не се брои. Текстовете са в регистъра, група „15 · Томбола“. <code>?ref=</code> е зает от ansa Partner.</p>
						
						</div></section><section class="ap-sec" id="s-book"><div class="ap-sec__h"><span class="ap-sec__t">📖 Книга /kniga</span></div><div class="ap-sec__b">
							<?php $bk = $draft['book']; $bk_url = Book::url( $draft ); $bi = Book::file_info( $draft ); ?>
							<p><label><input type="checkbox" name="bk_enabled" value="1"<?php checked( ! empty( $bk['enabled'] ) ); ?>> страницата на книгата е включена</label> &nbsp; <label>път: <code><?php echo esc_html( untrailingslashit( home_url( '/' ) ) ); ?>/</code><input type="text" name="bk_slug" value="<?php echo esc_attr( $bk['slug'] ); ?>" style="width:110px" class="code"></label> → <a href="<?php echo esc_url( $bk_url ); ?>" target="_blank"><?php echo esc_html( $bk_url ); ?></a> &nbsp; <a href="<?php echo esc_url( add_query_arg( 'ansa_promo', 'draft', $bk_url ) ); ?>" target="_blank">чернова</a> <small>(работи и <code>[ansa_promo_book]</code> на отделна страница)</small></p>
							<p><b>PDF:</b> <?php if ( 'file' === $bi['source'] ) : ?><code><?php echo esc_html( $bi['name'] ); ?></code> · <?php echo esc_html( $bi['size'] ); ?> · качен <?php echo esc_html( $bi['mtime'] ); ?> &nbsp; <label><input type="checkbox" name="bk_pdf_remove" value="1"> изтрий</label><?php elseif ( $bk['file'] ) : ?><span style="color:#b91c1c">файлът <code><?php echo esc_html( $bk['file'] ); ?></code> липсва на диска — качи го пак</span><?php else : ?><span style="color:#b91c1c">още няма качен PDF</span><?php endif; ?>
								&nbsp; <label>качи/смени: <input type="file" name="bk_pdf" accept="application/pdf,.pdf"></label> <small>(до <b><?php echo esc_html( size_format( wp_max_upload_size() ) ); ?></b> според PHP на хостинга; пази се в <code>uploads/ansa-promo-book/…</code> извън Медията, без публичен линк)</small></p>
							<?php $found = array_filter( Book::scan(), function ( $f ) use ( $bk ) { return $f['rel'] !== $bk['file']; } ); ?>
							<p><b>По-голям файл (над лимита):</b> качи го по FTP/SFTP или File Manager в <code><?php echo esc_html( Book::base_dir() ); ?>/</code> и го избери тук:
								<select name="bk_pick"><option value="">— <?php echo $found ? count( $found ) . ' намерени' : 'още няма качен по FTP'; ?> —</option><?php foreach ( $found as $f ) : ?><option value="<?php echo esc_attr( $f['rel'] ); ?>"><?php echo esc_html( $f['name'] . ' · ' . $f['size'] ); ?></option><?php endforeach; ?></select>
								<small>(плъгинът го мести в случайна подпапка с <code>.htaccess</code> deny; сервирането през <code>/kniga/pdf</code> е на части по 1 MB и няма лимит за размер)</small></p>
							<p><label>или външен линк към PDF: <input type="url" name="bk_url" value="<?php echo esc_attr( $bk['url'] ); ?>" class="regular-text code" placeholder="https://…/kniga.pdf"></label> <small>(ползва се само ако няма качен файл; такъв линк е публичен за всеки, който го знае)</small></p>
							<p>Отключва се при <b>„получена“ поръчка</b> — статусите от 🎲 Томбола (<code><?php echo esc_html( implode( ', ', (array) $draft['tombola']['statuses'] ) ); ?></code>); „Изпълнена“ не брои. &nbsp; <label>браузърът помни: <input type="number" name="bk_remember" min="1" max="3650" value="<?php echo (int) $bk['remember']; ?>" style="width:70px"> дни</label></p>
							<p class="description">Клиентът отваря <code><?php echo esc_html( $bk_url ); ?></code>, въвежда телефона и имейла от поръчката и книгата се зарежда (на десктоп вградена + „Отвори“/„Свали“; на телефона — в нов таб). Поръчката трябва да носи книгата (средна/голяма кутия или ред с продукта на книгата) и да е получена (Delivered). Браузърът получава подписана бисквитка и следващия път книгата се отваря без въвеждане („Забрави този браузър“ я трие). Влезлият админ я вижда направо. PDF-ът се сервира само през <code>/<?php echo esc_html( $bk['slug'] ); ?>/pdf</code>. Имейлът при „Изпълнена“ (<code>em.book.*</code>) и merge тагът <code>{{ansa_promo_book_url}}</code> водят тук. Текстовете са в група „16 · Книгата“ в ✏️ Редактор.</p>
						
						</div></section><section class="ap-sec" id="s-demo"><div class="ap-sec__h"><span class="ap-sec__t">🧪 Демо данни</span></div><div class="ap-sec__b">
							<p><label>телефон: <input type="text" name="demo_phone" value="<?php echo esc_attr( $draft['demo']['phone'] ); ?>" style="width:140px" class="code"></label> &nbsp; <label>имейл: <input type="email" name="demo_email" value="<?php echo esc_attr( $draft['demo']['email'] ); ?>" class="regular-text code"></label></p>
							<p class="description">С тези данни формата на книгата отваря PDF-а, а формата на томболата показва примерен резултат — без поръчка и без запис в базата. Само за екипа; празно поле = изключено.</p>
						
						</div></section><section class="ap-sec" id="s-theme"><div class="ap-sec__h"><span class="ap-sec__t">Цял екран</span></div><div class="ap-sec__b">
							<p>1) На страницата: Page Attributes → Template → <b>„ansa™ Промо — цял екран“</b> — без хедър и футър на темата (препоръчано).</p>
							<label><input type="checkbox" name="theme_hide" value="1"<?php checked( $draft['theme']['hide'] ); ?>> 2) Скрий и тези елементи на темата/плъгините на промо страницата (един селектор на ред; работи и без темплейта):</label><br>
							<textarea name="theme_selectors" rows="6" class="large-text code"><?php echo esc_textarea( $draft['theme']['selectors'] ); ?></textarea>
						
					</div></section>
					<div class="ap-actions"><button class="ap-btn ap-btn--primary" data-ap-save>💾 Запази черновата</button><span class="sp"></span><small>Ctrl+S · записва черновата; публикуваш от бутона горе или от „Публикуване“</small></div>
				</form>
				<section class="ap-sec" id="s-publish"><div class="ap-sec__h"><span class="ap-sec__t">🚀 Публикуване</span></div><div class="ap-sec__b">
				<p>Чернова: <?php echo Config::has_draft() ? '<b>има промени, които не са публикувани</b>' : 'няма промени спрямо публикуваното'; ?>. Публикувано: версия <?php echo (int) Config::version(); ?>.</p>
				<div class="ap-actions" style="padding:0"><?php self::form_open( 'publish' ); ?><button class="button button-primary">🚀 Публикувай черновата</button></form>
				<?php self::form_open( 'discard', 'tools', ' onsubmit="return confirm(\'Да отхвърля ли черновата?\')"' ); ?><button class="button">↶ Откажи черновата</button></form>
				<?php self::form_open( 'purge' ); ?><button class="button">🧹 Изчисти кеша на страницата</button></form></div>
				<?php $hist = Config::history(); if ( $hist ) : ?>
					<h3>🕘 История на публикуванията</h3>
					<ul>
					<?php foreach ( $hist as $h ) : ?>
						<li>версия <?php echo (int) $h['version']; ?> · <?php echo esc_html( date_i18n( 'd.m.Y H:i', $h['at'] ) ); ?>
							<?php self::form_open( 'restore' ); ?><input type="hidden" name="version" value="<?php echo (int) $h['version']; ?>"><button class="button button-small">върни като чернова</button></form></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				</div></section>
				<section class="ap-sec" id="s-linkproxy"><div class="ap-sec__h"><span class="ap-sec__t">🔗 Линковете от SendGrid (HTTPS за url2581.ansa.bg)</span></div><div class="ap-sec__b">
				<?php $lp = Link_Proxy::settings(); $lps = Link_Proxy::status(); ?>
				<p>SendGrid пренаписва линковете в имейлите към <code>http://url2581.ansa.bg/ls/click?…</code> и няма сертификат за домейна — браузърите, които вдигат линка към HTTPS, показват „Връзката ви не е поверителна“. Тук домейнът се обслужва от <b>този</b> сайт по HTTPS и всяка заявка се препраща към SendGrid (кликът се брои, клиентът стига до истинския адрес). Нужно е в хостинга <b>url2581.ansa.bg</b> да е добавен като допълнителен домейн на WordPress (без пренасочване), с Let's Encrypt, и DNS записът да е <b>A към IP-то на сайта</b> вместо CNAME към sendgrid.net. Вторият запис на SendGrid (<code>2581.ansa.bg</code>) остава CNAME.</p>
				<p class="description">Състояние: <?php echo esc_html( $lps[0] ); ?> · проверка: <a href="https://<?php echo esc_attr( $lp['host'] ?: 'url2581.ansa.bg' ); ?>/__ansa_promo_ping" target="_blank" rel="noopener">https://<?php echo esc_html( $lp['host'] ?: 'url2581.ansa.bg' ); ?>/__ansa_promo_ping</a> трябва да покаже „ok <?php echo esc_html( ANSA_PROMO_VER ); ?>“ без предупреждение.</p>
				<?php self::form_open( 'link_proxy', 'tools', ' style="display:block"' ); ?>
					<p><label><input type="checkbox" name="lp_enabled" value="1"<?php checked( $lp['enabled'] ); ?><?php disabled( ! empty( $lp['const'] ) ); ?>> <b>Включено</b></label>
					&nbsp; <label>домейн: <input type="text" name="lp_host" value="<?php echo esc_attr( $lp['host'] ); ?>" class="regular-text code" placeholder="url2581.ansa.bg"<?php disabled( ! empty( $lp['const'] ) ); ?>></label>
					&nbsp; <label>линкът за парола важи <input type="number" name="lp_reset_days" value="<?php echo (int) $lp['reset_days']; ?>" min="0" max="60" style="width:70px"> дни</label> <small>0 = стандартно (1 ден); 7 = и ключовете от вече изпратените писма работят още една седмица</small></p>
					<p><button class="button button-primary">💾 Запази</button><?php if ( ! empty( $lp['const'] ) ) : ?> <small>домейнът е зададен с константата <code>ANSA_PROMO_LINK_PROXY_HOST</code> в wp-config.php</small><?php endif; ?></p>
				</form>
				</div></section>
				<section class="ap-sec" id="s-insurl"><div class="ap-sec__h"><span class="ap-sec__t">📈 Insights URL — отделният адрес за екипа по реклами</span><?php if ( Insights_Portal::enabled() ) : ?><a class="ap-btn ap-btn--secondary ap-btn--sm" href="<?php echo esc_url( Insights_Portal::url() ); ?>" target="_blank" rel="noopener">отвори ↗</a><?php endif; ?></div><div class="ap-sec__b">
				<?php $ip = Insights_Portal::settings(); $ips = Insights_Portal::status(); $ipl = Insights_Portal::locks(); $iplog = Insights_Portal::log_rows(); ?>
				<p>Същият 📈 Insights (Преглед · На живо · История · Изоставени колички), но на <b>отделен адрес без wp-admin</b>: екипът по реклами влиза с <b>потребител и парола</b>, не с WP акаунт. Паролата се пази само като bcrypt хеш; сесията е подписана бисквитка (HttpOnly, SameSite=Strict, Secure) за <?php echo (int) $ip['ttl']; ?> ч, вързана към браузъра. Срещу brute force: 5 грешни опита от един IP за 15 мин → 15 мин блок (10 → 1 час, 20 → 24 часа); 25 грешни от всички IP за 15 мин → 15 мин блок за всички; всичко се записва в дневника долу. Съобщението за грешка е еднакво за грешно име и грешна парола. Смяна на паролата или „Изход от всички“ изхвърля всички сесии.</p>
				<p class="description">Адрес: <?php echo Insights_Portal::enabled() ? '<a href="' . esc_url( Insights_Portal::url() ) . '" target="_blank" rel="noopener"><code>' . esc_html( Insights_Portal::url() ) . '</code></a>' : '<i>изключен</i>'; ?> · <?php echo esc_html( $ips[0] ); ?><?php if ( ! $ip['changed_at'] ) : ?> · <b style="color:#cf222e">Паролата е още началната — смени я тук.</b><?php endif; ?></p>
				<?php self::form_open( 'insights_portal', 'tools', ' style="display:block" autocomplete="off"' ); ?>
					<p><label><input type="checkbox" name="ip_enabled" value="1"<?php checked( $ip['enabled'] ); ?>> <b>Включен</b></label>
					&nbsp; <label>адрес: <code><?php echo esc_html( home_url( '/' ) ); ?></code><input type="text" name="ip_slug" value="<?php echo esc_attr( $ip['slug'] ); ?>" class="code" style="width:200px" placeholder="promo-insights">/</label>
					&nbsp; <label><input type="checkbox" name="ip_https" value="1"<?php checked( $ip['https_only'] ); ?>> само по HTTPS</label>
					&nbsp; <label>сесията трае <select name="ip_ttl" style="width:auto"><?php foreach ( Insights_Portal::TTL_OPTIONS as $h ) : ?><option value="<?php echo (int) $h; ?>"<?php selected( $ip['ttl'], $h ); ?>><?php echo (int) $h; ?> ч</option><?php endforeach; ?></select></label></p>
					<p><label>потребител: <input type="text" name="ip_user" value="<?php echo esc_attr( $ip['user'] ); ?>" class="code" style="width:200px" autocomplete="off" autocapitalize="none" spellcheck="false"></label> <small>малки латински букви, цифри, . _ - (3–32); смяната изхвърля сесиите</small></p>
					<p><label>нова парола: <input type="password" name="ip_pw" value="" class="code" style="width:260px" autocomplete="new-password" placeholder="поне <?php echo (int) Insights_Portal::MIN_PW; ?> знака"></label> &nbsp; <label>още веднъж: <input type="password" name="ip_pw2" value="" class="code" style="width:260px" autocomplete="new-password"></label> <small>празно = без смяна</small></p>
					<p><button class="button button-primary">💾 Запази</button></p>
				</form>
				<p class="ap-fl"><b>Сесии и блокове</b></p>
				<p><?php self::form_open( 'insights_portal_logout_all', 'tools', ' onsubmit="return confirm(\'Да изхвърля ли всички влезли в Insights URL?\')"' ); ?><button class="button">🚪 Изход от всички устройства</button></form>
				<?php self::form_open( 'insights_portal_unlock' ); ?><button class="button"<?php disabled( ! $ipl['ips'] && ! $ipl['all_until'] ); ?>>🔓 Отключи блокираните IP (<?php echo count( $ipl['ips'] ); ?>)</button></form>
				<?php if ( $ipl['all_until'] ) : ?><span class="ap-pill ap-pill--bad">общ блок до <?php echo esc_html( wp_date( 'H:i', $ipl['all_until'] ) ); ?></span><?php endif; ?>
				<?php foreach ( $ipl['ips'] as $bl ) : ?><span class="ap-pill ap-pill--warn" title="<?php echo (int) $bl['n']; ?> грешни опита"><?php echo esc_html( $bl['ip'] ); ?> до <?php echo esc_html( wp_date( 'd.m H:i', $bl['until'] ) ); ?></span> <?php endforeach; ?></p>
				<h4 style="margin:14px 0 4px">🧾 Последни опити за вход (<?php echo count( $iplog ); ?>)</h4>
				<?php if ( $iplog ) : ?>
				<table class="widefat striped ap-table" style="font-size:12px"><thead><tr><th>кога (UTC)</th><th>резултат</th><th>потребител</th><th>IP</th><th>браузър</th></tr></thead><tbody>
				<?php foreach ( array_slice( $iplog, 0, 30 ) as $lr ) : $bad = in_array( $lr['res'], array( 'bad', 'locked', 'csrf', 'https' ), true ); ?><tr<?php echo $bad ? ' style="color:#cf222e"' : ''; ?>><td><?php echo esc_html( $lr['at'] ); ?></td><td><?php echo esc_html( Insights_Portal::RES_LABELS[ $lr['res'] ] ?? $lr['res'] ); ?></td><td><code><?php echo esc_html( $lr['user'] ?: '—' ); ?></code></td><td><?php echo esc_html( $lr['ip'] ); ?></td><td><small><?php echo esc_html( $lr['ua'] ); ?></small></td></tr><?php endforeach; ?>
				</tbody></table>
				<?php self::form_open( 'insights_portal_log_clear' ); ?><button class="button button-small" style="margin-top:6px">🧹 Изчисти дневника</button></form>
				<?php else : ?><p class="description">Още никой не е опитвал да влезе.</p><?php endif; ?>
				</div></section>
				<section class="ap-sec" id="s-insrep"><div class="ap-sec__h"><span class="ap-sec__t">📬 Insights — вътрешен трафик · седмичен отчет · аларми · API</span></div><div class="ap-sec__b">
				<?php $ir = Insights_Reports::settings(); $irs = Insights_Reports::status(); $dows = array( 'нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб' ); ?>
				<p class="description">Състояние: <?php echo esc_html( $irs[0] ); ?></p>
				<?php self::form_open( 'insights_reports', 'tools', ' style="display:block"' ); ?>
					<p class="ap-fl"><b>Вътрешен трафик (не влиза в Insights)</b></p>
					<p><label>IP адреси на екипа — по един на ред; „10.0.*“ или „2001:db8:“ за префикс, „1.2.3.0/24“ за мрежа:<br><textarea name="internal_ips" rows="3" class="code" style="width:min(100%,420px)"><?php echo esc_textarea( $ir['internal_ips'] ); ?></textarea></label></p>
					<p><label>имейли на тестови поръчки (цял имейл или „@домейн“):<br><textarea name="internal_emails" rows="2" class="code" style="width:min(100%,420px)"><?php echo esc_textarea( $ir['internal_emails'] ); ?></textarea></label> <small>логнатите админи и Probe са вътрешни винаги; ботовете (UA bot/crawler/headless…) изобщо не правят сесия</small></p>
					<p class="ap-fl"><b>Седмичен отчет по имейл</b></p>
					<p><label><input type="checkbox" name="weekly_enabled" value="1"<?php checked( $ir['weekly']['enabled'] ); ?>> включен</label> &nbsp; <label>до: <input type="text" name="weekly_to" value="<?php echo esc_attr( $ir['weekly']['to'] ); ?>" class="regular-text" placeholder="reklama@ansa.bg, ivan@ansa.bg"></label>
					&nbsp; <label>в <select name="weekly_dow" style="width:auto"><?php foreach ( $dows as $i => $dn ) : ?><option value="<?php echo (int) $i; ?>"<?php selected( $ir['weekly']['dow'], $i ); ?>><?php echo esc_html( $dn ); ?></option><?php endforeach; ?></select></label> <label>от <select name="weekly_hour" style="width:auto"><?php for ( $hh = 0; $hh < 24; $hh++ ) : ?><option value="<?php echo (int) $hh; ?>"<?php selected( $ir['weekly']['hour'], $hh ); ?>><?php echo (int) $hh; ?>:00</option><?php endfor; ?></select> ч</label> <small>KPI с Δ спрямо предишната седмица, фунията, топ 5 / дъно 5 кампании (≥ 30 сесии)</small></p>
					<p class="ap-fl"><b>Аларми</b></p>
					<p><label><input type="checkbox" name="alerts_enabled" value="1"<?php checked( $ir['alerts']['enabled'] ); ?>> включени</label> &nbsp; <label>имейл: <input type="text" name="alerts_to" value="<?php echo esc_attr( $ir['alerts']['to'] ); ?>" class="regular-text" placeholder="reklama@ansa.bg"></label> &nbsp; <label>Slack webhook: <input type="url" name="alerts_slack" value="<?php echo esc_attr( $ir['alerts']['slack'] ); ?>" class="regular-text code" placeholder="https://hooks.slack.com/services/…"></label></p>
					<p><label>0 сесии за <input type="number" name="alerts_zero_hours" value="<?php echo (int) $ir['alerts']['zero_hours']; ?>" min="1" max="24" style="width:60px"> ч</label>, когато предишните 7 дни са давали средно ≥ <input type="number" name="alerts_zero_min" value="<?php echo (int) $ir['alerts']['zero_min']; ?>" min="1" style="width:60px"> сесии за толкова часа &nbsp;·&nbsp; <label>конверсията за 24 ч пада с ≥ <input type="number" name="alerts_drop_pct" value="<?php echo (int) $ir['alerts']['drop_pct']; ?>" min="5" max="95" style="width:60px"> %</label> спрямо предишните 7 дни при ≥ <input type="number" name="alerts_min_sessions" value="<?php echo (int) $ir['alerts']['min_sessions']; ?>" min="10" style="width:70px"> сесии <small>всеки тип аларма най-много веднъж на 6 ч; крон на час</small></p>
					<p><button class="button button-primary">💾 Запази</button></p>
				</form>
				<p><?php self::form_open( 'insights_report_send' ); ?><button class="button">📧 Изпрати седмичния отчет сега</button></form> <?php self::form_open( 'insights_report_send' ); ?><input type="hidden" name="to_me" value="1"><button class="button">📧 Прати ми го на мен (тест)</button></form> <?php self::form_open( 'insights_alerts_test' ); ?><button class="button">🔔 Провери алармите сега</button></form></p>
				<p class="ap-fl"><b>API за Looker Studio / Google Sheets</b></p>
				<p class="description">GET <code><?php echo esc_html( Insights_Portal::url() ); ?>?json=1&amp;token=…&amp;from=YYYY-MM-DD&amp;to=YYYY-MM-DD</code> връща JSON с KPI, фунията и разбивките по UTM / кампания / ден / кутия / устройство / час (или <code>Authorization: Bearer …</code>). Само четене, 60 заявки/мин/IP. <?php echo '' !== $ir['api_token'] ? 'Токен: <code style="user-select:all">' . esc_html( $ir['api_token'] ) . '</code>' : '<b>Няма токен — генерирай.</b>'; ?></p>
				<p><?php self::form_open( 'insights_api_token', 'tools', ' onsubmit="return confirm(\'Нов токен? Старият спира да работи.\')"' ); ?><button class="button">🔑 <?php echo '' !== $ir['api_token'] ? 'Нов токен' : 'Генерирай токен'; ?></button></form> <?php if ( '' !== $ir['api_token'] ) : ?><?php self::form_open( 'insights_api_token_clear' ); ?><button class="button">✕ Изтрий токена</button></form><?php endif; ?></p>
				</div></section>
				<section class="ap-sec" id="s-phone"><div class="ap-sec__h"><span class="ap-sec__t">📞 Обаждания — бонус по телефона</span></div><div class="ap-sec__b">
				<?php $ph = Phone_Orders::settings(); $phg = Phone_Orders::gift_product(); ?>
				<p>От Insights (🛒 Колички, 🧾 Сесии) операторът натиска „✏️ Редактирай поръчка“ или „✅ Завърши поръчка“ — и двата първо питат <b>„Отстъпка / Продукт подарък / Нищо“</b> (1.30.1). В лентата „📞 Обаждане“ долу на промо страницата бонусът може да се смени: <b>−X % на поръчката</b> (отрицателна такса „📞 Отстъпка по телефона“ в чекаута, върху промо редовете след отстъпката на кутията) или <b>🎁 продукт подарък</b> (ред на €0, заключен като книгата). Бонусът влиза в бележката на поръчката и в плочката „📞 Поръчки по телефон“.</p>
				<?php self::form_open( 'phone_orders', 'tools', ' style="display:block"' ); ?>
					<p><label>отстъпка по телефона: −<input type="text" inputmode="decimal" name="ph_pct" value="<?php echo esc_attr( Cart::fmt( $ph['pct'] ) ); ?>" style="width:60px;text-align:right"> %</label>
					&nbsp; <label>подарък — SKU или име: <input type="text" name="ph_gift_sku" value="<?php echo esc_attr( $ph['gift_sku'] ); ?>" class="code" style="width:200px" placeholder="PATCH01"></label> <label>или WC продукт ID: <input type="number" name="ph_gift_id" value="<?php echo (int) $ph['gift_id']; ?>" style="width:90px" min="0"></label>
					<span class="description"><?php echo $phg ? '→ <b>' . esc_html( $phg['name'] ) . '</b> (#' . (int) $phg['id'] . ' · ' . esc_html( Insights::money( $phg['price'] ) ) . ( $phg['stock'] ? '' : ' · <b style="color:#cf222e">НЕ Е НАЛИЧЕН</b>' ) . ')' : '<b style="color:#cf222e">продуктът не е намерен</b> — провери SKU / ID'; ?></span></p>
					<p><button class="button button-primary">💾 Запази</button></p>
				</form>
				</div></section>
				<section class="ap-sec" id="s-favorite"><div class="ap-sec__h"><span class="ap-sec__t">⭐ Любим продукт за имейлите — {{ansa_promo_fav}}</span></div><div class="ap-sec__b">
				<?php $fv = Favorite::settings(); $fvs = Favorite::status(); $fve = array(); $fvr = Favorite::parse_map( $fv['map'], $fve ); ?>
				<p>„Здравей, Мария — твоят любим продукт <b>Daily Greens</b> е вече наличен.“ Merge тагът <code>{{ansa_promo_fav}}</code> (FunnelKit Automations → Merge tags → Order) намира <b>най-купувания продукт на човека</b> по всичките му поръчки с този имейл и го изписва с <b>краткото име</b> от картата по-долу, не с името на WooCommerce продукта. Продукти, които не са в картата, не участват. <code>{{ansa_promo_fav_url}}</code> е линкът към продуктовата му страница. Във FKA: <code>{{ansa_promo_fav fallback="любимата ти добавка"}}</code>. Същото като шорткод: <code>[ansa_promo_fav]</code>.</p>
				<p class="description">Състояние: <?php echo esc_html( $fvs[0] ); ?></p>
				<?php self::form_open( 'favorite', 'tools', ' style="display:block"' ); ?>
					<p><label><b>Картата</b> — на ред: <code>ID на продукта = Кратко име</code>; няколко ID-та (или SKU) на един ред се броят заедно (напр. сетове към основния продукт); вариация се свежда до продукта; <code>#</code> = коментар. Празно = продуктите от черновата с имената по подразбиране.<br>
					<textarea name="fav_map" rows="<?php echo max( 6, min( 14, substr_count( $fv['map'], "\n" ) + 2 ) ); ?>" class="large-text code" placeholder="3994 = Daily Greens&#10;28726 = Sakura SkinTonik&#10;15836 = YoniBiotic&#10;22307 = MenoStop&#10;11546 = Dihydroberberine&#10;1298 = Thyrozen"><?php echo esc_textarea( $fv['custom'] ? $fv['map'] : '' ); ?></textarea></label>
					<?php if ( ! $fv['custom'] && '' !== $fv['map'] ) : ?><span class="description">сега (по подразбиране): <code><?php echo esc_html( str_replace( "\n", ' · ', $fv['map'] ) ); ?></code></span><?php endif; ?>
					<?php if ( $fve ) : ?><br><span style="color:#cf222e">⚠ <?php echo esc_html( implode( '; ', $fve ) ); ?></span><?php endif; ?></p>
					<?php if ( $fvr && function_exists( 'wc_get_product' ) ) : ?><table class="widefat striped ap-table" style="max-width:760px"><thead><tr><th>кратко име</th><th>WC продукт(и)</th></tr></thead><tbody>
					<?php foreach ( $fvr as $row ) : ?><tr><td><b><?php echo esc_html( $row['name'] ); ?></b></td><td><?php $cells = array(); foreach ( $row['ids'] as $pid ) { $pr = wc_get_product( $pid ); $cells[] = '#' . (int) $pid . ' ' . ( $pr ? esc_html( $pr->get_name() ) : '<span style="color:#cf222e">няма такъв продукт</span>' ); } echo implode( '<br>', $cells ); ?></td></tr><?php endforeach; ?>
					</tbody></table><?php endif; ?>
					<p><label>Най-купуван = <select name="fav_metric"><?php foreach ( Favorite::METRICS as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"<?php selected( $fv['metric'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label> <span class="description">„опаковки“: 1 × „3 кутии“ = 3, 2 × „1 брой“ = 2; при равенство печели по-скоро купеният</span></p>
					<p><label>Не броят поръчки със статус: <input type="text" name="fav_skip" value="<?php echo esc_attr( $fv['skip'] ); ?>" class="regular-text code"></label> <span class="description">всички останали статуси (delivered, at-office, shipped, processing, completed, on-hold…) са покупка</span></p>
					<p><label>Текст, когато няма любим (без поръчки с тези продукти): <input type="text" name="fav_fallback" value="<?php echo esc_attr( $fv['fallback'] ); ?>" class="regular-text" placeholder="любимата ти добавка"></label> &nbsp; <label>до <input type="number" name="fav_limit" value="<?php echo (int) $fv['limit']; ?>" min="10" max="500" style="width:70px"> поръчки назад</label></p>
					<p><button class="button button-primary">💾 Запази</button> <span class="description">не се публикува — важи веднага; кешът на сметките (10 мин на имейл) се чисти</span></p>
				</form>
				<?php self::form_open( 'favorite_test', 'tools', ' style="display:block;margin-top:6px"' ); ?>
					<p><label>🔎 Провери имейл: <input type="email" name="fav_email" class="regular-text" placeholder="klient@example.com" required></label> <button class="button">Какво ще получи</button> <span class="description">смята наново, без кеша; показва всички продукти от картата с резултата им</span></p>
				</form>
				<?php $fvl = Favorite::log_rows(); ?>
				<h4 style="margin:14px 0 4px">🧾 Последни извиквания на тага (<?php echo count( $fvl ); ?>)</h4>
				<p class="description" style="margin-top:0">Всеки път, когато FunnelKit или шорткод поиска <code>{{ansa_promo_fav}}</code>: откъде е взет имейлът, какви данни е подал FunnelKit, резултатът. Ако пише „няма имейл“ — FunnelKit не е подал контакт/поръчка за това писмо (напр. тестов имейл без контакт); колоната „данни от FKA“ показва какво е имало.</p>
				<?php if ( $fvl ) : ?>
				<table class="widefat striped ap-table" style="font-size:12px"><thead><tr><th>кога</th><th>таг</th><th>имейлът е от</th><th>имейл</th><th>поръчка на event-а</th><th>данни от FKA</th><th>резултат</th></tr></thead><tbody>
				<?php foreach ( array_slice( $fvl, 0, 15 ) as $lr ) : ?><tr><td><?php echo esc_html( $lr['at'] . ' · ' . $lr['ctx'] ); ?></td><td><code><?php echo esc_html( $lr['key'] ); ?></code></td><td><?php echo esc_html( $lr['src'] ); ?></td><td><?php echo esc_html( $lr['email'] ?: '—' ); ?><?php echo $lr['uid'] ? ' · user ' . (int) $lr['uid'] : ''; ?></td><td><?php echo esc_html( $lr['order'] ?: '—' ); ?></td><td style="max-width:260px;word-break:break-all"><?php echo esc_html( $lr['data'] ); ?></td><td><?php echo '' !== (string) $lr['result'] ? '<b>' . esc_html( $lr['result'] ) . '</b>' : '<span style="color:#9a6700">празно → fallback' . ( '' !== (string) $lr['fallback'] ? ' „' . esc_html( $lr['fallback'] ) . '“' : '' ) . '</span>'; ?></td></tr><?php endforeach; ?>
				</tbody></table>
				<?php self::form_open( 'favorite_log_clear' ); ?><button class="button button-small" style="margin-top:6px">🧹 Изчисти дневника</button></form>
				<?php else : ?><p class="description">Още нищо — изпрати си тестов имейл с тага от FunnelKit и презареди.</p><?php endif; ?>
				</div></section>
				<section class="ap-sec" id="s-products"><div class="ap-sec__h"><span class="ap-sec__t">📦 Продуктите в черновата</span></div><div class="ap-sec__b">
				<table class="widefat striped ap-table"><thead><tr><th>ключ (UTM)</th><th>име</th><th>WC продукт</th><th>ред · цена · наличност</th></tr></thead><tbody>
				<?php foreach ( $draft['products'] as $p ) : $wc = $p['product_id'] ? Catalog::wc_line( $p['product_id'] ) : null; ?>
					<tr><td><code><?php echo esc_html( $p['key'] ); ?></code></td><td><?php echo esc_html( $p['ph'] . ' ' . $p['name'] ); ?></td>
						<td><?php echo $p['product_id'] ? '#' . (int) $p['product_id'] . ' ' . esc_html( $wc['wc_name'] ) : '<span style="color:#cf222e">няма — изключен от страницата</span>'; ?></td>
						<td><?php echo $wc ? ( $wc['ok'] ? ( $wc['variation_id'] ? 'вариация #' . (int) $wc['variation_id'] : 'simple' ) . ' · €' . esc_html( number_format( $wc['price'], 2, ',', '' ) ) . ' · ' . ( $wc['stock'] ? 'наличен' : '<span style="color:#cf222e">НЕ е наличен</span>' ) . ( $wc['note'] ? ' · ' . esc_html( $wc['note'] ) : '' ) : '<span style="color:#cf222e">' . esc_html( $wc['note'] ) . '</span>' ) : '—'; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="description">Свързването ключ ↔ WC продукт е в таб <b>📦 Продукти</b>: SKU на вариацията „1 опаковка“ (печели) или WC ID чрез търсене. Там са и името, „за какво е“ и описанието — попълнените полета печелят над WooCommerce, празните се пълнят от WC.</p>
				<?php self::form_open( 'seed', 'tools', ' onsubmit="return confirm(\'Това презаписва продуктите, FIT и проблемите в черновата с тези от мокъпа. Страницата, текстовете, срокът и чекаутът остават. Продължавам?\')"' ); ?><button class="button">🌱 Направи продуктите наново от мокъпа (търси ги в WC по име)</button></form>
				</div></section>
			<?php elseif ( 'products' === $tab ) : ?>
				<?php Products_UI::render(); /* v1.15.0: приложението в стила на Shrine (assets/products-admin.js) */ ?>
			<?php elseif ( 'editor' === $tab ) : ?>
				<?php Editor::page(); /* v1.17.0: редакторът живее в приложението (assets/editor.js/.css, вграден) */ ?>
			<?php elseif ( 'orders' === $tab ) : ?>
				<?php $rows = Tickets::orders( array( 'limit' => 200 ) ); $f = sanitize_key( (string) ( $_GET['f'] ?? '' ) ); if ( $f ) { $rows = array_values( array_filter( $rows, function ( $r ) use ( $f ) { $ts = $r['ticket'] ? $r['ticket']['status'] : 'none'; return 'invalid' === $f ? ! $r['valid'] : $ts === $f; } ) ); } ?>
				<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🎟️ Промо поръчки</span></div><div class="ap-sec__b">
				<p>Промо поръчки: <b><?php echo count( $rows ); ?></b> (последните 200) · билети: издадени <b><?php echo (int) Tickets::count( 'issued' ); ?></b> (<?php echo (int) Tickets::sum( 'issued' ); ?> бр.), активирани <b><?php echo (int) Tickets::count( 'activated' ); ?></b> (<?php echo (int) Tickets::sum( 'activated' ); ?> бр.), анулирани <?php echo (int) Tickets::count( 'void' ); ?>
				</p><div class="ap-chips" style="margin:6px 0 10px"><?php foreach ( array( '' => 'всички', 'issued' => 'издаден', 'activated' => 'активиран', 'void' => 'анулиран', 'none' => 'без билет', 'invalid' => 'непълна кутия' ) as $k => $l ) : ?><a class="ap-chip<?php echo $k === $f ? ' on' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => 'orders', 'f' => $k ) ) ); ?>"><?php echo esc_html( $l ); ?></a><?php endforeach; ?></div>
				<div class="ap-actions" style="padding:0"><?php self::form_open( 'export_orders', 'orders' ); ?><button class="button">⬇ CSV</button></form>
					<?php $lp = Leads::stats()['pending']; ?>&nbsp; <?php self::form_open( 'leads_sync', 'orders', ' onsubmit="return confirm(\'Да пратя ли ' . (int) $lp . ' лийда от имейл-попъпа във FunnelKit (контакт + лист + таг)? Ако автоматизацията по тага/листа е активна, имейлите ще тръгнат към тях.\')"' ); ?><button class="button"<?php disabled( 0 === $lp ); ?> title="лийдовете, които още не са минали през FunnelKit (или са за повторен опит)">📨 Лийдове → FunnelKit: <?php echo (int) $lp; ?> чакат</button></form>
					<?php $unt = count( Merge::untagged() ); ?>&nbsp; <?php self::form_open( 'fka_backfill', 'orders', ' onsubmit="return confirm(\'Да сложа ли FunnelKit таговете (общия + по кутия) на ' . (int) $unt . ' промо поръчки, на които липсват? Ако автоматизацията „Tag Added“ е активна, имейлът ще тръгне към тези клиенти.\')"' ); ?><button class="button"<?php disabled( 0 === $unt ); ?> title="поръчките отпреди 1.8.2 нямат тага — това го слага на контактите им">🏷️ FKA таг на <?php echo (int) $unt; ?> стари поръчки</button></form></div>
				</div></div>
				<div class="ap-sec ap-sec--table"><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>поръчка</th><th>дата</th><th>статус</th><th>клиент</th><th>кутия</th><th>билети</th><th>код</th><th>билет</th><th>FKA таг</th><th>сет</th><th>книга</th><th>UTM</th><th>сума</th></tr></thead><tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr><td><a href="<?php echo esc_url( $r['edit'] ); ?>">#<?php echo esc_html( $r['number'] ); ?></a><?php echo $r['partner'] ? ' 🤝' : ''; ?></td><td><?php echo esc_html( $r['date'] ); ?></td><td><?php echo esc_html( wc_get_order_status_name( $r['status'] ) ); ?></td><td><?php echo esc_html( $r['name'] ); ?><br><small><?php echo esc_html( $r['email'] ); ?></small></td>
						<td><?php echo esc_html( $r['box'] . ' · ' . $r['packs'] . ' · −' . $r['pct'] . '%' ); ?><?php echo $r['valid'] ? '' : ' <span style="color:#cf222e">непълна</span>'; ?></td><td><?php echo $r['ticket'] ? (int) $r['ticket']['tickets'] . ( $r['ticket']['bonus'] ? ' <b title="бонус от препоръки">+' . (int) $r['ticket']['bonus'] . '</b>' : '' ) : (int) $r['allowance'] . ' <small>(очаква)</small>'; ?><?php echo $r['ref_gift'] ? ' <small title="по препоръка ' . esc_attr( $r['ref_from'] ) . '">🎁</small>' : ''; ?></td>
						<td><?php echo $r['ticket'] ? '<code>' . esc_html( $r['ticket']['code'] ) . '</code> <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ansa_promo_ticket_print&order=' . (int) $r['id'] ), 'ansa_promo_ticket_print' ) ) . '" target="_blank" title="стикер">🖨</a>' : '—'; ?></td>
						<td><?php echo $r['ticket'] ? esc_html( $r['ticket']['status'] ) . ( $r['ticket']['activated_at'] ? '<br><small>' . esc_html( $r['ticket']['activated_at'] . ' · ' . $r['ticket']['portal_user'] ) . '</small>' : '' ) : '—'; ?></td>
						<td><?php $ft = (string) ( $r['fka_tag'] ?? '' ); echo '' === $ft ? '—' : ( 0 === strpos( $ft, 'retry' ) ? '<span style="color:#9a6700" title="' . esc_attr( $ft ) . '">⏳ retry</span>' : '<span title="' . esc_attr( $ft ) . '">✓ ' . esc_html( preg_replace( '/^.*· /u', '', $ft ) ) . '</span>' ); ?></td>
						<td><?php echo esc_html( $r['cosm'] ?: '—' ); ?><?php echo $r['voucher'] ? '<br><code>' . esc_html( $r['voucher'] ) . '</code>' : ''; ?></td><td><?php echo $r['book'] ? ( $r['book_sent'] ? '✓ ' . esc_html( $r['book_sent'] ) : 'при изпълнена' ) : '—'; ?></td><td><?php echo esc_html( $r['utm'] ); ?></td><td><?php echo wp_kses_post( wc_price( $r['total'] ) ); ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?><tr><td colspan="13">Още няма промо поръчки.</td></tr><?php endif; ?>
				</tbody></table></div></div>
			<?php elseif ( 'tombola' === $tab ) : ?>
				<?php $ent = Tombola::entries( $pub ); $tot = 0; $act = 0; $refs = 0; $bon = 0; foreach ( $ent as $e ) { $tot += $e['total']; $bon += $e['bonus']; $refs += $e['refs']; if ( 'activated' === $e['status'] ) { $act++; } } $boxes = array(); foreach ( $pub['boxes'] as $b ) { $boxes[ $b['id'] ] = $b['ic'] . ' ' . $b['name']; } $draws = (array) get_option( 'ansa_promo_tombola_draws', array() ); ?>
				<div class="ap-kpis" style="padding:0 0 14px">
					<div class="ap-kpi"><b><?php echo count( $ent ); ?></b><span>участници</span></div><div class="ap-kpi"><b><?php echo (int) $tot; ?></b><span>билети общо</span></div><div class="ap-kpi"><b><?php echo (int) $bon; ?></b><span>бонус от препоръки</span></div><div class="ap-kpi"><b><?php echo (int) $act; ?></b><span>проверили се</span></div><div class="ap-kpi <?php echo Tombola::enabled( $pub ) ? 'good' : 'bad'; ?>"><b><?php echo Tombola::enabled( $pub ) ? 'включена' : 'ИЗКЛЮЧЕНА'; ?></b><span>страницата</span></div>
				</div>
				<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🎲 Томболата</span></div><div class="ap-sec__b">
				<p>Страница: <a href="<?php echo esc_url( Tombola::url( $pub ) ); ?>" target="_blank"><?php echo esc_html( Tombola::url( $pub ) ); ?></a> · теглене <b><?php echo esc_html( Tombola::draw_label( $pub ) ); ?></b> · „получена“ = <?php echo esc_html( implode( ', ', (array) $pub['tombola']['statuses'] ) ); ?><?php echo Tombola::enabled( $pub ) ? '' : ' · <b style="color:#cf222e">ИЗКЛЮЧЕНА</b>'; ?></p>
				<p class="description">Участници (получени поръчки с билет): <b><?php echo count( $ent ); ?></b> · билети общо <b><?php echo (int) $tot; ?></b> (от тях <?php echo (int) $bon; ?> бонус от препоръки) · проверили се на страницата: <b><?php echo (int) $act; ?></b> · броени препоръки: <b><?php echo (int) $refs; ?></b> (всички редове: <?php echo (int) Tombola::count_referrals(); ?> · self <?php echo (int) Tombola::count_referrals( 'self' ); ?> · dup <?php echo (int) Tombola::count_referrals( 'dup' ); ?> · void <?php echo (int) Tombola::count_referrals( 'void' ); ?>)
				</p><div class="ap-actions" style="padding:0"><?php self::form_open( 'export_tombola', 'tombola' ); ?><button class="button">⬇ CSV на участниците</button></form>
					&nbsp; <?php self::form_open( 'draw', 'tombola', ' onsubmit="return confirm(\'Да изтегля ли случаен участник (тежест = билетите му)? Резултатът се записва в дневника.\')"' ); ?><input type="text" name="label" placeholder="етикет, напр. „Яхта – финал“" style="width:200px"> <button class="button button-primary">🎲 Изтегли печеливш</button></form></div>
				</div></div>
				<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">🔎 Защо не намира поръчка?</span></div><div class="ap-sec__b">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:0 0 10px" class="ap-actions" ><input type="hidden" name="page" value="<?php echo esc_attr( Plugin::MENU_SLUG ); ?>"><input type="hidden" name="tab" value="tombola"><input type="email" name="diag_email" value="<?php echo esc_attr( (string) ( $_GET['diag_email'] ?? '' ) ); ?>" placeholder="имейл от поръчката" class="regular-text"> <input type="text" name="diag_phone" value="<?php echo esc_attr( (string) ( $_GET['diag_phone'] ?? '' ) ); ?>" placeholder="телефон" style="width:160px"> <button class="button">Провери като клиента</button> <span class="description">същата проверка като на страницата, но с обяснение за всяка поръчка с този имейл (не активира билети)</span></form>
				<?php if ( ! empty( $_GET['diag_email'] ) ) : $dg = Tombola::diagnose( sanitize_email( (string) wp_unslash( $_GET['diag_email'] ) ), sanitize_text_field( (string) wp_unslash( $_GET['diag_phone'] ?? '' ) ), $pub ); ?>
					<p>Резултат за клиента: <b><?php echo ! empty( $dg['result']['ok'] ) ? '✅ намира — ' . (int) $dg['result']['total'] . ' билет(а) от ' . count( $dg['result']['orders'] ) . ' поръчки' : '❌ ' . esc_html( (string) ( $dg['result']['code'] ?? '' ) ); ?></b> · поръчки с този имейл: <?php echo count( $dg['orders'] ); ?></p>
					<table class="widefat striped" style="max-width:1200px"><thead><tr><th>поръчка</th><th>статус</th><th>телефон (billing / shipping)</th><th>промо</th><th>билет</th><th>защо (не)</th></tr></thead><tbody>
					<?php foreach ( $dg['orders'] as $d ) : ?><tr><td><a href="<?php echo esc_url( $d['edit'] ); ?>">#<?php echo esc_html( $d['number'] ); ?></a><br><small><?php echo esc_html( $d['date'] ); ?></small></td><td><?php echo esc_html( $d['status'] ); ?><?php echo $d['delivered'] ? ' ✓' : ''; ?></td><td><?php echo esc_html( $d['billing_phone'] ); ?><?php echo $d['shipping_phone'] && $d['shipping_phone'] !== $d['billing_phone'] ? ' / ' . esc_html( $d['shipping_phone'] ) : ''; ?><?php echo $d['phone_ok'] ? ' ✓' : ''; ?></td><td><?php echo $d['promo'] ? ( $d['valid'] ? '✓ ' : 'непълна · ' ) . esc_html( $d['box'] ) . ' · ' . (int) $d['allowance'] . ' бил.' . ( $d['draft'] ? ' · чернова' : '' ) : '—'; ?></td><td><?php echo esc_html( $d['ticket'] ?: '—' ); ?></td><td><?php echo $d['ok'] ? '<b style="color:#1a7f37">✅ хваща се</b>' : '<span style="color:#cf222e">' . esc_html( implode( ' · ', $d['why'] ) ) . '</span>'; ?></td></tr><?php endforeach; ?>
					<?php if ( ! $dg['orders'] ) : ?><tr><td colspan="6">Няма нито една поръчка с billing имейл <code><?php echo esc_html( (string) $_GET['diag_email'] ); ?></code> (нито по клиент с този имейл). Провери имейла в самата поръчка.</td></tr><?php endif; ?>
					</tbody></table>
				<?php endif; ?>
				</div></div>
				<?php if ( $draws ) : ?><div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">📜 Дневник на тегленията</span></div><div class="ap-sec__b"><ul><?php foreach ( $draws as $d ) : ?><li><?php echo esc_html( $d['at'] ); ?> · <?php echo esc_html( $d['label'] ?: '—' ); ?> → <b>поръчка #<?php echo esc_html( $d['order'] ); ?></b> · <?php echo esc_html( $d['email'] ); ?> · <?php echo (int) $d['tickets']; ?> билет(а) · жребий <?php echo (int) $d['pick']; ?> от <?php echo (int) $d['pool']; ?> (<?php echo (int) $d['entries']; ?> участници)</li><?php endforeach; ?></ul></div></div><?php endif; ?>
				<div class="ap-sec ap-sec--table"><div class="ap-sec__h"><span class="ap-sec__t">👥 Участници</span></div><div class="ap-sec__b"><table class="widefat striped"><thead><tr><th>поръчка</th><th>клиент</th><th>телефон</th><th>кутия</th><th>билети</th><th>бонус (препоръки)</th><th>общо</th><th>билет</th><th>код за препоръка</th><th>препоръки</th><th>по препоръка</th><th>ваучер</th></tr></thead><tbody>
				<?php foreach ( $ent as $e ) : ?>
					<tr><td><a href="<?php echo esc_url( $e['edit'] ); ?>">#<?php echo esc_html( $e['number'] ); ?></a></td><td><?php echo esc_html( $e['name'] ); ?><br><small><?php echo esc_html( $e['email'] ); ?></small></td><td><?php echo esc_html( $e['phone'] ); ?></td><td><?php echo esc_html( $boxes[ $e['box'] ] ?? $e['box'] ); ?></td><td><?php echo (int) $e['tickets']; ?></td><td><?php echo (int) $e['bonus']; ?></td><td><b><?php echo (int) $e['total']; ?></b></td><td><code><?php echo esc_html( $e['code'] ); ?></code><br><small><?php echo esc_html( $e['status'] . ( $e['activated'] ? ' · ' . $e['activated'] : '' ) ); ?></small></td><td><code><?php echo esc_html( $e['ref_code'] ?: '—' ); ?></code></td><td><?php echo (int) $e['refs']; ?></td><td><?php echo $e['gift'] ? '🎁 +' . (int) $e['gift'] : '—'; ?></td><td><?php echo esc_html( $e['voucher'] ?: '—' ); ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $ent ) : ?><tr><td colspan="12">Още няма получени промо поръчки с билет (статуси: <?php echo esc_html( implode( ', ', (array) $pub['tombola']['statuses'] ) ); ?>).</td></tr><?php endif; ?>
				</tbody></table></div></div>
			<?php elseif ( 'insights' === $tab ) : ?>
				<?php if ( Insights_Portal::enabled() ) : /* 1.27.0 */ ?><p class="description" style="margin:0 0 10px">📈 Отделният адрес за екипа по реклами (вход с парола, без wp-admin): <a href="<?php echo esc_url( Insights_Portal::url() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( Insights_Portal::url() ); ?></code></a> · настройки в <a href="<?php echo esc_url( self::url( array( 'tab' => 'tools' ) ) ); ?>#s-insurl">🧰 → 📈 Insights URL</a></p><?php endif; ?>
				<?php Insights::render( isset( $_GET['days'] ) ? (int) $_GET['days'] : 30 ); /* 1.17.0: Insights 2 — сесии, фуния; 1.21.0: ?view=overview|live|history (📊 Преглед · 🟢 На живо · 📜 История); рендира своите карти */ ?>
			<?php elseif ( 'json' === $tab ) : ?>
				<div class="ap-sec"><div class="ap-sec__h"><span class="ap-sec__t">{ } Черновата като JSON</span></div><div class="ap-sec__b">
				<?php self::form_open( 'import', 'json', ' style="display:block"' ); ?>
					<p class="description">Редактирай и „Внеси“ — записва се като чернова (минава през normalize), публикуваш от 🧰 Настройки → Публикуване.</p>
					<textarea name="json" class="ap-json" spellcheck="false"><?php echo esc_textarea( Config::export() ); ?></textarea>
					<div class="ap-actions" style="padding:10px 0 0"><button class="button button-primary">⬆️ Внеси като чернова</button></div>
				</form></div></div>
			<?php endif; ?>
				</div></div>
			</div>
		</div>
		<?php
	}
}
