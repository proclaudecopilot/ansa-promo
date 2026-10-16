<?php
/**
 * ansa™ Промо — админ.
 *
 * Фаза 0: меню „🎁 ansa Промо“ (top-level) с екран „Състояние“ (Doctor::env()).
 * Фаза 1: 🧰 Инструменти — страница, игра вкл./срок, чернова ↔ публикувано, история, JSON export/import, seed.
 * Фаза 2 носи редактора (региони · канвас · инспектор · ⚙️ Двигател).
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
				$cfg['utm_param'] = sanitize_text_field( (string) wp_unslash( $_POST['utm_param'] ?? 'utm_content' ) );
				$cfg['gate']['enabled'] = ! empty( $_POST['gate_enabled'] ); $cfg['gate']['required'] = ! empty( $_POST['gate_required'] );
				$gi = (array) ( $_POST['gate_img'] ?? array() ); $cfg['gate']['images'] = array();
				foreach ( Config::gate_image_slots() as $k => $label ) { $cfg['gate']['images'][ $k ] = sanitize_text_field( (string) wp_unslash( $gi[ $k ] ?? '' ) ); }
				$cfg['bgn']['show'] = ! empty( $_POST['bgn_show'] );
				$cfg['theme']['hide'] = ! empty( $_POST['theme_hide'] ); $cfg['theme']['selectors'] = (string) wp_unslash( $_POST['theme_selectors'] ?? '' );
				$cfg['preview']['enabled'] = ! empty( $_POST['preview_enabled'] ); $cfg['preview']['password'] = trim( wp_strip_all_tags( (string) wp_unslash( $_POST['preview_password'] ?? '' ) ) );
				/* v1.2.0: билети и награди */
				$cfg['tickets']['portal_url'] = esc_url_raw( (string) wp_unslash( $_POST['portal_url'] ?? '' ) ); $cfg['tickets']['email'] = ! empty( $_POST['ticket_email'] ); $cfg['tickets']['partner_bonus'] = (int) ( $_POST['partner_bonus'] ?? 1 ); $cfg['tickets']['fka_tag'] = trim( wp_strip_all_tags( (string) wp_unslash( $_POST['fka_tag'] ?? '' ) ) ); $bt = (array) ( $_POST['fka_tag_box'] ?? array() ); $cfg['tickets']['fka_tag_box'] = array(); foreach ( array( 's', 'm', 'l' ) as $bid ) { $cfg['tickets']['fka_tag_box'][ $bid ] = trim( wp_strip_all_tags( (string) wp_unslash( $bt[ $bid ] ?? '' ) ) ); }
				$cfg['rewards']['book']['product_id'] = (int) ( $_POST['book_product_id'] ?? 0 ); $cfg['rewards']['book']['url'] = esc_url_raw( (string) wp_unslash( $_POST['book_url'] ?? '' ) ); $cfg['rewards']['cosm']['product_id'] = (int) ( $_POST['cosm_product_id'] ?? 0 );
				/* v1.8.0: страницата „Как работи“ */
				$cfg['info']['enabled'] = ! empty( $_POST['info_enabled'] ); $cfg['info']['slug'] = sanitize_title( (string) wp_unslash( $_POST['info_slug'] ?? 'info' ) );
				/* v1.6.0: томбола */
				$cfg['tombola']['enabled'] = ! empty( $_POST['tb_enabled'] ); $cfg['tombola']['slug'] = sanitize_title( (string) wp_unslash( $_POST['tb_slug'] ?? 'tombola' ) ); $cfg['tombola']['draw'] = sanitize_text_field( (string) wp_unslash( $_POST['tb_draw'] ?? '' ) );
				$cfg['tombola']['statuses'] = (string) wp_unslash( $_POST['tb_statuses'] ?? '' ); $cfg['tombola']['ref_param'] = sanitize_key( (string) wp_unslash( $_POST['tb_ref_param'] ?? 'r' ) );
				$cfg['tombola']['ref_mult'] = (int) ( $_POST['tb_ref_mult'] ?? 2 ); $cfg['tombola']['ref_max'] = (int) ( $_POST['tb_ref_max'] ?? 10 ); $cfg['tombola']['ref_gift'] = (int) ( $_POST['tb_ref_gift'] ?? 1 );
				/* v1.9.0: книгата /kniga + демо данни; PDF-ът се качва в скрита папка (Book::store_upload) */
				$cfg['book']['enabled'] = ! empty( $_POST['bk_enabled'] ); $cfg['book']['slug'] = sanitize_title( (string) wp_unslash( $_POST['bk_slug'] ?? 'kniga' ) ); $cfg['book']['url'] = esc_url_raw( (string) wp_unslash( $_POST['bk_url'] ?? '' ) ); $cfg['book']['remember'] = (int) ( $_POST['bk_remember'] ?? 365 );
				$cfg['demo']['phone'] = sanitize_text_field( (string) wp_unslash( $_POST['demo_phone'] ?? '' ) ); $cfg['demo']['email'] = sanitize_email( (string) wp_unslash( $_POST['demo_email'] ?? '' ) );
				/* v1.11.0: лийдовете от попъпа → FunnelKit */
				$cfg['leads'] = array( 'fka' => ! empty( $_POST['leads_fka'] ), 'list' => trim( wp_strip_all_tags( (string) wp_unslash( $_POST['leads_list'] ?? '' ) ) ), 'tag' => trim( wp_strip_all_tags( (string) wp_unslash( $_POST['leads_tag'] ?? '' ) ) ), 'consent_only' => ! empty( $_POST['leads_consent_only'] ), 'untag_on_order' => ! empty( $_POST['leads_untag'] ) );
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
			case 'export_orders':
				$rows = Tickets::orders( array( 'limit' => 2000 ) );
				nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="ansa-promo-orders-' . gmdate( 'Ymd-Hi' ) . '.csv"' );
				echo Tickets::csv( $rows ); exit;
			case 'products':
				$cfg = Config::get_draft(); $rows = (array) ( $_POST['p'] ?? array() ); $new = array();
				foreach ( $rows as $r ) {
					if ( ! is_array( $r ) || ! empty( $r['del'] ) ) { continue; }
					$r = wp_unslash( $r ); $key = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) ( $r['key'] ?? '' ) ) );
					if ( '' === $key && '' === trim( (string) ( $r['name'] ?? '' ) ) ) { continue; }
					$old = Config::product( $cfg, $key ) ?: Config::product_defaults();
					foreach ( array( 'key', 'name', 'ph', 'gname', 'gsub', 'ds', 'desc', 'ing', 'who', 'rating', 'reviews', 'cat', 'pack' ) as $f ) { if ( isset( $r[ $f ] ) ) { $old[ $f ] = (string) $r[ $f ]; } }
					$old['product_id'] = (int) ( $r['product_id'] ?? 0 ); $old['img'] = (int) ( $r['img'] ?? 0 ); $old['enabled'] = ! empty( $r['enabled'] ); $old['wc_text'] = ! empty( $r['wc_text'] ); $old['sku'] = sanitize_text_field( (string) ( $r['sku'] ?? '' ) );
					if ( isset( $r['theme'] ) ) { $old['theme'] = array_map( 'trim', explode( ',', (string) $r['theme'] ) ); }
					/* v1.14.0: отстъпка по кутия (празно = процентът на кутията; normalize пази само 0 < % < 100) */
					$pp = array(); foreach ( (array) ( $r['pct'] ?? array() ) as $bid => $v ) { $v = str_replace( ',', '.', trim( (string) $v ) ); if ( '' !== $v && is_numeric( $v ) ) { $pp[ sanitize_key( (string) $bid ) ] = (float) $v; } } $old['pct'] = $pp;
					$new[] = $old;
				}
				$cfg['products'] = $new; Config::save_draft( $cfg ); $msg = 'Продуктите са записани в черновата (' . count( $new ) . ').'; break;
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
		<div class="wrap">
			<h1>🎁 ansa™ Промо <small style="font-weight:400;color:#777">v<?php echo esc_html( ANSA_PROMO_VER ); ?></small></h1>
			<?php if ( $msg ) : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $k => $label ) : ?><a class="nav-tab<?php echo $k === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => $k ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
				<a class="nav-tab" href="<?php echo esc_url( Editor::url() ); ?>">✏️ Редактор</a>
			</nav>
			<?php if ( 'status' === $tab ) : ?>
				<p style="margin-top:14px">Чернова: <b><?php echo count( $draft['products'] ); ?> продукта</b> (<?php echo count( array_filter( $draft['products'], function ( $p ) { return $p['product_id'] > 0; } ) ); ?> с WC продукт) · Публикувано: <b>версия <?php echo (int) Config::version(); ?></b><?php echo Config::has_published() ? ' от ' . esc_html( date_i18n( 'd.m.Y H:i', Config::published_at() ) ) : ' (още няма)'; ?> · играта е <b><?php echo Config::is_live( $pub ) ? 'ВКЛЮЧЕНА' : 'изключена'; ?></b>
				<?php if ( $page_url ) : ?> · <a href="<?php echo esc_url( $page_url ); ?>" target="_blank">страницата</a> · <a href="<?php echo esc_url( add_query_arg( 'ansa_promo', 'draft', $page_url ) ); ?>" target="_blank">черновата</a><?php endif; ?></p>
				<table class="widefat striped" style="max-width:1100px"><tbody>
				<?php foreach ( Doctor::env() as $r ) : ?>
					<tr><th style="width:260px"><?php echo esc_html( $r['label'] ); ?></th><td style="color:<?php echo esc_attr( $col[ $r['level'] ] ?? $col['info'] ); ?>"><?php echo esc_html( $r['value'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php elseif ( 'tools' === $tab ) : ?>
				<div style="max-width:820px;margin-top:14px">
				<?php self::form_open( 'settings', 'tools', ' style="display:block" enctype="multipart/form-data"' ); ?>
					<table class="form-table">
						<tr><th>Страница с [ansa_promo]</th><td><select name="page_id"><option value="0">— избери —</option>
							<?php foreach ( Doctor::pages_with_shortcode( true ) as $pg ) : ?><option value="<?php echo (int) $pg['id']; ?>"<?php selected( $pg['id'], $draft['page_id'] ); ?>>#<?php echo (int) $pg['id']; ?> <?php echo esc_html( $pg['title'] ); ?> (<?php echo esc_html( $pg['status'] ); ?>)</option><?php endforeach; ?>
						</select><p class="description">Само страници, в чието съдържание има <code>[ansa_promo]</code>.</p></td></tr>
						<tr><th>Играта е включена</th><td><label><input type="checkbox" name="enabled" value="1"<?php checked( $draft['enabled'] ); ?>> публичната страница показва играта (иначе „скоро“; черновата се вижда винаги с <code>?ansa_promo=draft</code>)</label></td></tr>
						<tr><th>Край</th><td><input type="text" name="deadline" value="<?php echo esc_attr( $draft['deadline'] ); ?>" class="regular-text" placeholder="2026-12-31 23:59"></td></tr>
						<tr><th>UTM параметър</th><td><input type="text" name="utm_param" value="<?php echo esc_attr( $draft['utm_param'] ); ?>" class="regular-text"> <span class="description">кацане: <code>?<?php echo esc_html( $draft['utm_param'] ); ?>=sakura</code></span></td></tr>
						<tr><th>Имейл-попъп</th><td><label><input type="checkbox" name="gate_enabled" value="1"<?php checked( $draft['gate']['enabled'] ); ?>> включен</label> &nbsp; <label><input type="checkbox" name="gate_required" value="1"<?php checked( $draft['gate']['required'] ); ?>> задължителен (без „Продължи без имейл“)</label></td></tr>
						<tr><th>📨 Лийдовете → FunnelKit</th><td><?php $ld = $draft['leads']; $ls = Leads::stats(); ?>
							<p><label><input type="checkbox" name="leads_fka" value="1"<?php checked( ! empty( $ld['fka'] ) ); ?>> имейлът от попъпа става контакт във FunnelKit (и за гост, без WP акаунт) и получава лист + таг — тригърът за автоматизацията „не е завършила поръчката“</label></p>
							<p><label>Лист: <input type="text" name="leads_list" class="regular-text" value="<?php echo esc_attr( (string) $ld['list'] ); ?>" placeholder="Сезонът на ansa — лийдове"></label> &nbsp; <label>Таг: <input type="text" name="leads_tag" value="<?php echo esc_attr( (string) $ld['tag'] ); ?>" placeholder="sezon-lead" style="width:160px"></label> <span class="description">празно = без лист / без таг; създават се сами във FKA (Contacts → Lists / Tags)</span></p>
							<p><label><input type="checkbox" name="leads_consent_only" value="1"<?php checked( ! empty( $ld['consent_only'] ) ); ?>> само с отметнато съгласие в попъпа</label> &nbsp; <label><input type="checkbox" name="leads_untag" value="1"<?php checked( ! empty( $ld['untag_on_order'] ) ); ?>> при промо поръчка тагът пада от контакта (листът остава) — автоматизацията спира за тези, които са поръчали</label></p>
							<p class="description">Автоматизацията във FKA: тригър <b>„Tag Added“</b> (тагът по-горе) или <b>„Contact Added to List“</b> → Delay (напр. 2 часа) → условие <b>Contact Tags — has</b> тага (т.е. още не е поръчала; при поръчка тагът пада) → Send Email → … Поръчалите получават и таговете по-горе („Сезонът на Анса“ + по кутия) от <code>Merge::tag_order</code>. Състояние: <?php echo esc_html( Lead_Sync::status( $draft ) ); ?></p>
						</td></tr>
						<tr><th>Снимки на наградите</th><td>
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
							<script>
							(function(){
								var root=document.getElementById('apGateImgs');if(!root)return;
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
								});
							})();
							</script>
						</td></tr>
						<tr><th>Лева в поръчката</th><td><label><input type="checkbox" name="bgn_show" value="1"<?php checked( $draft['bgn']['show'] ); ?>> показвай „(… лв.)“ до крайната сума</label></td></tr>
						<tr><th>Преглед с парола</th><td>
							<label><input type="checkbox" name="preview_enabled" value="1"<?php checked( ! empty( $draft['preview']['enabled'] ) ); ?>> докато играта не е пусната, страницата показва форма за парола; с правилна парола се вижда <b>черновата</b> (за екипа и избрани клиенти, без вход в сайта)</label>
							<p><input type="text" name="preview_password" class="regular-text code" value="<?php echo esc_attr( $draft['preview']['password'] ); ?>" placeholder="парола"> <span class="description">паролата се помни в браузъра 30 дни; смяната ѝ изхвърля всички</span></p>
							<?php $pl = Frontend::preview_link(); if ( $pl ) : ?><p class="description">Линк за екипа (отваря и записва паролата): <code><?php echo esc_html( $pl ); ?></code></p><?php else : ?><p class="description">Избери страницата по-горе, за да видиш линка за екипа.</p><?php endif; ?>
						</td></tr>
						<tr><th>🎟️ Билети и награди</th><td>
							<p><label>Портал за билетите (URL в QR кода; празно = промо страницата с <code>?ticket=КОД</code>)<br><input type="url" name="portal_url" class="regular-text code" value="<?php echo esc_attr( $draft['tickets']['portal_url'] ); ?>" placeholder="https://portal.ansa.bg/"></label></p>
							<p><label><input type="checkbox" name="ticket_email" value="1"<?php checked( ! empty( $draft['tickets']['email'] ) ); ?>> и имейл с кода при издаване на билета (QR кодът е в пратката така или иначе)</label> &nbsp; <label>бонус билети за стандартен партньорски код (ansa Partner): <input type="number" name="partner_bonus" min="0" max="5" value="<?php echo (int) $draft['tickets']['partner_bonus']; ?>" style="width:60px"></label> <small>кодът за играта на партньора носи своя брой (Partner → картата на партньора)</small></p>
							<p><label>FunnelKit Automations — таг на контакта при поръчка от промото (празно = без таг): <input type="text" name="fka_tag" class="regular-text" value="<?php echo esc_attr( (string) ( $draft['tickets']['fka_tag'] ?? '' ) ); ?>" placeholder="Сезонът на Анса"></label><br><span class="description">Тригърът за имейла за потвърждение: в Automations → event „Tag Added“ с този таг (или „Order Created“ + правило „Contact Tags“). Merge: <code>{{ansa_promo_tickets_text}}</code>, <code>{{ansa_promo_tickets}}</code>, <code>{{ansa_promo_bileta}}</code>, <code>{{ansa_promo_box}}</code>.</span></p>
							<p>Таг по кутия (1.8.8; сегментът за различните имейли; празно = без): <?php $btd = (array) ( $draft['tickets']['fka_tag_box'] ?? array() ); foreach ( array( 's' => 'Малка', 'm' => 'Средна', 'l' => 'Голяма' ) as $bid => $bl ) : ?><label style="margin-right:10px"><?php echo esc_html( $bl ); ?>: <input type="text" name="fka_tag_box[<?php echo esc_attr( $bid ); ?>]" value="<?php echo esc_attr( (string) ( $btd[ $bid ] ?? '' ) ); ?>" style="width:150px"></label><?php endforeach; ?></p>
							<p><label>Книга — WC продукт ID (влиза в количката на €0): <input type="number" name="book_product_id" value="<?php echo (int) $draft['rewards']['book']['product_id']; ?>" style="width:90px"></label> &nbsp; <label>линк към PDF-а (имейл при „Изпълнена“, ако продуктът не е downloadable): <input type="url" name="book_url" class="regular-text code" value="<?php echo esc_attr( $draft['rewards']['book']['url'] ); ?>"></label></p>
							<p><label>Козметичен сет — WC продукт ID (само за справка/ваучера): <input type="number" name="cosm_product_id" value="<?php echo (int) $draft['rewards']['cosm']['product_id']; ?>" style="width:90px"></label></p>
							<p class="description">Билетът се издава при „Обработва се“/„Изпълнена“; QR за стикера е в поръчката (meta box „🎁 ansa™ Промо“ → „Стикер за пратката“). Ваучерът за сета и книгата тръгват при „Изпълнена“. Текстовете са в регистъра, група „14 · Имейли · стикер“.</p>
						</td></tr>
						<tr><th>ℹ️ Как работи</th><td>
							<?php $inf = $draft['info']; $inf_url = Info::url( $draft ); ?>
							<p><label><input type="checkbox" name="info_enabled" value="1"<?php checked( ! empty( $inf['enabled'] ) ); ?>> самостоятелната страница „Как работи“ е включена</label> &nbsp; <label>път: <code><?php echo esc_html( trim( (string) wp_parse_url( Cart::promo_url( $draft ), PHP_URL_PATH ), '/' ) ); ?>/</code><input type="text" name="info_slug" value="<?php echo esc_attr( $inf['slug'] ); ?>" style="width:110px" class="code"></label> → <a href="<?php echo esc_url( $inf_url ); ?>" target="_blank"><?php echo esc_html( $inf_url ); ?></a> &nbsp; <a href="<?php echo esc_url( add_query_arg( 'ansa_promo', 'draft', $inf_url ) ); ?>" target="_blank">чернова</a></p>
							<p class="description">Същото съдържание като попъпа „Как работи играта?“ (група „2а · Как работи“ в редактора; <code>info.title</code>/<code>info.desc</code> са заглавието и описанието за Google/споделяне), индексируема — за трафик от реклами и социални мрежи. Бутоните ѝ водят към промо страницата с избраната кутия (<code>?box=s|m|l</code>) или продукт. При включена парола или изключена игра показва „скоро“.</p>
						</td></tr>
						<tr><th>🎲 Томбола</th><td>
							<?php $tb = $draft['tombola']; $tb_url = Tombola::url( $draft ); ?>
							<p><label><input type="checkbox" name="tb_enabled" value="1"<?php checked( ! empty( $tb['enabled'] ) ); ?>> страницата за проверка на билетите е включена</label> &nbsp; <label>път: <code><?php echo esc_html( trim( (string) wp_parse_url( Cart::promo_url( $draft ), PHP_URL_PATH ), '/' ) ); ?>/</code><input type="text" name="tb_slug" value="<?php echo esc_attr( $tb['slug'] ); ?>" style="width:110px" class="code"></label> → <a href="<?php echo esc_url( $tb_url ); ?>" target="_blank"><?php echo esc_html( $tb_url ); ?></a> <small>(това е линкът за SMS-а при доставка; работи и <code>[ansa_promo_tombola]</code> на отделна страница)</small></p>
							<p><label>Теглене на: <input type="text" name="tb_draw" value="<?php echo esc_attr( $tb['draw'] ); ?>" style="width:120px" placeholder="2027-01-25"></label> &nbsp; <label><b>„получена“ поръчка</b> = статуси: <input type="text" name="tb_statuses" value="<?php echo esc_attr( implode( ', ', (array) $tb['statuses'] ) ); ?>" class="regular-text code"></label> <small>без „wc-“; налични: <?php echo esc_html( implode( ', ', array_map( function ( $s ) { return preg_replace( '/^wc-/', '', $s ); }, array_keys( wc_get_order_statuses() ) ) ) ); ?></small></p>
							<p><label>Препоръка — параметър в линка: <code>?</code><input type="text" name="tb_ref_param" value="<?php echo esc_attr( $tb['ref_param'] ); ?>" style="width:50px" class="code"><code>=КОД</code></label> &nbsp; <label>множител ×<input type="number" name="tb_ref_mult" min="1" max="10" value="<?php echo (int) $tb['ref_mult']; ?>" style="width:55px"></label> &nbsp; <label>най-много броени препоръки: <input type="number" name="tb_ref_max" min="0" max="1000" value="<?php echo (int) $tb['ref_max']; ?>" style="width:65px"></label> <small>(0 = без лимит)</small> &nbsp; <label>подарък за новия клиент: <input type="number" name="tb_ref_gift" min="0" max="10" value="<?php echo (int) $tb['ref_gift']; ?>" style="width:55px"> билет(а)</label></p>
							<p class="description"><b>„Получена“ важи за всичко: томболата, ваучера за сета и книгата</b> — само тези статуси (по подразбиране само <code>delivered</code>; „Изпълнена“/<code>completed</code> НЕ брои). Ваучерът и имейлът за книгата тръгват, когато поръчката влезе в такъв статус. Клиентът въвежда телефон + имейл от поръчката; при „получена“ поръчка вижда билетите си (активират се), датата на тегленето, ваучера за сета и личния си линк <code><?php echo esc_html( Cart::promo_url( $draft ) ); ?>?<?php echo esc_html( $tb['ref_param'] ); ?>=КОД</code>. Приятел, който поръча през линка, получава +подаръка в поръчката си; първата платена препоръка умножава билетите на препоръчалия ×<?php echo (int) $tb['ref_mult']; ?>, всяка следваща добавя още толкова (до лимита). Същият имейл/телефон не се брои. Текстовете са в регистъра, група „15 · Томбола“. <code>?ref=</code> е зает от ansa Partner.</p>
						</td></tr>
						<tr><th>📖 Книга /kniga</th><td>
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
						</td></tr>
						<tr><th>🧪 Демо данни</th><td>
							<p><label>телефон: <input type="text" name="demo_phone" value="<?php echo esc_attr( $draft['demo']['phone'] ); ?>" style="width:140px" class="code"></label> &nbsp; <label>имейл: <input type="email" name="demo_email" value="<?php echo esc_attr( $draft['demo']['email'] ); ?>" class="regular-text code"></label></p>
							<p class="description">С тези данни формата на книгата отваря PDF-а, а формата на томболата показва примерен резултат — без поръчка и без запис в базата. Само за екипа; празно поле = изключено.</p>
						</td></tr>
						<tr><th>Цял екран</th><td>
							<p>1) На страницата: Page Attributes → Template → <b>„ansa™ Промо — цял екран“</b> — без хедър и футър на темата (препоръчано).</p>
							<label><input type="checkbox" name="theme_hide" value="1"<?php checked( $draft['theme']['hide'] ); ?>> 2) Скрий и тези елементи на темата/плъгините на промо страницата (един селектор на ред; работи и без темплейта):</label><br>
							<textarea name="theme_selectors" rows="6" class="large-text code"><?php echo esc_textarea( $draft['theme']['selectors'] ); ?></textarea>
						</td></tr>
					</table>
					<p><button class="button button-primary">💾 Запази черновата</button></p>
				</form>
				<hr>
				<h2>Публикуване</h2>
				<p>Чернова: <?php echo Config::has_draft() ? '<b>има промени, които не са публикувани</b>' : 'няма промени спрямо публикуваното'; ?>. Публикувано: версия <?php echo (int) Config::version(); ?>.</p>
				<?php self::form_open( 'publish' ); ?><button class="button button-primary">🚀 Публикувай черновата</button></form>
				<?php self::form_open( 'discard', 'tools', ' onsubmit="return confirm(\'Да отхвърля ли черновата?\')"' ); ?><button class="button" style="margin-left:8px">↶ Откажи черновата</button></form>
				<?php self::form_open( 'purge' ); ?><button class="button" style="margin-left:8px">🧹 Изчисти кеша на страницата</button></form>
				<?php $hist = Config::history(); if ( $hist ) : ?>
					<h3>🕘 История на публикуванията</h3>
					<ul>
					<?php foreach ( $hist as $h ) : ?>
						<li>версия <?php echo (int) $h['version']; ?> · <?php echo esc_html( date_i18n( 'd.m.Y H:i', $h['at'] ) ); ?>
							<?php self::form_open( 'restore' ); ?><input type="hidden" name="version" value="<?php echo (int) $h['version']; ?>"><button class="button button-small">върни като чернова</button></form></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<hr>
				<h2>🔗 Линковете от SendGrid (HTTPS за url2581.ansa.bg)</h2>
				<?php $lp = Link_Proxy::settings(); $lps = Link_Proxy::status(); ?>
				<p>SendGrid пренаписва линковете в имейлите към <code>http://url2581.ansa.bg/ls/click?…</code> и няма сертификат за домейна — браузърите, които вдигат линка към HTTPS, показват „Връзката ви не е поверителна“. Тук домейнът се обслужва от <b>този</b> сайт по HTTPS и всяка заявка се препраща към SendGrid (кликът се брои, клиентът стига до истинския адрес). Нужно е в хостинга <b>url2581.ansa.bg</b> да е добавен като допълнителен домейн на WordPress (без пренасочване), с Let's Encrypt, и DNS записът да е <b>A към IP-то на сайта</b> вместо CNAME към sendgrid.net. Вторият запис на SendGrid (<code>2581.ansa.bg</code>) остава CNAME.</p>
				<p class="description">Състояние: <?php echo esc_html( $lps[0] ); ?> · проверка: <a href="https://<?php echo esc_attr( $lp['host'] ?: 'url2581.ansa.bg' ); ?>/__ansa_promo_ping" target="_blank" rel="noopener">https://<?php echo esc_html( $lp['host'] ?: 'url2581.ansa.bg' ); ?>/__ansa_promo_ping</a> трябва да покаже „ok <?php echo esc_html( ANSA_PROMO_VER ); ?>“ без предупреждение.</p>
				<?php self::form_open( 'link_proxy', 'tools', ' style="display:block"' ); ?>
					<p><label><input type="checkbox" name="lp_enabled" value="1"<?php checked( $lp['enabled'] ); ?><?php disabled( ! empty( $lp['const'] ) ); ?>> <b>Включено</b></label>
					&nbsp; <label>домейн: <input type="text" name="lp_host" value="<?php echo esc_attr( $lp['host'] ); ?>" class="regular-text code" placeholder="url2581.ansa.bg"<?php disabled( ! empty( $lp['const'] ) ); ?>></label>
					&nbsp; <label>линкът за парола важи <input type="number" name="lp_reset_days" value="<?php echo (int) $lp['reset_days']; ?>" min="0" max="60" style="width:70px"> дни</label> <small>0 = стандартно (1 ден); 7 = и ключовете от вече изпратените писма работят още една седмица</small></p>
					<p><button class="button button-primary">💾 Запази</button><?php if ( ! empty( $lp['const'] ) ) : ?> <small>домейнът е зададен с константата <code>ANSA_PROMO_LINK_PROXY_HOST</code> в wp-config.php</small><?php endif; ?></p>
				</form>
				<hr>
				<h2>Продуктите в черновата</h2>
				<table class="widefat striped"><thead><tr><th>ключ (UTM)</th><th>име</th><th>WC продукт</th><th>ред · цена · наличност</th></tr></thead><tbody>
				<?php foreach ( $draft['products'] as $p ) : $wc = $p['product_id'] ? Catalog::wc_line( $p['product_id'] ) : null; ?>
					<tr><td><code><?php echo esc_html( $p['key'] ); ?></code></td><td><?php echo esc_html( $p['ph'] . ' ' . $p['name'] ); ?></td>
						<td><?php echo $p['product_id'] ? '#' . (int) $p['product_id'] . ' ' . esc_html( $wc['wc_name'] ) : '<span style="color:#cf222e">няма — изключен от страницата</span>'; ?></td>
						<td><?php echo $wc ? ( $wc['ok'] ? ( $wc['variation_id'] ? 'вариация #' . (int) $wc['variation_id'] : 'simple' ) . ' · €' . esc_html( number_format( $wc['price'], 2, ',', '' ) ) . ' · ' . ( $wc['stock'] ? 'наличен' : '<span style="color:#cf222e">НЕ е наличен</span>' ) . ( $wc['note'] ? ' · ' . esc_html( $wc['note'] ) : '' ) : '<span style="color:#cf222e">' . esc_html( $wc['note'] ) . '</span>' ) : '—'; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="description">Свързването ключ ↔ WC продукт е в таб <b>📦 Продукти</b>: SKU на вариацията „1 опаковка“ (печели) или WC ID чрез търсене. Там са и името, „за какво е“ и описанието — попълнените полета печелят над WooCommerce, празните се пълнят от WC.</p>
				<?php self::form_open( 'seed', 'tools', ' onsubmit="return confirm(\'Това презаписва продуктите, FIT и проблемите в черновата с тези от мокъпа. Страницата, текстовете, срокът и чекаутът остават. Продължавам?\')"' ); ?><button class="button">🌱 Направи продуктите наново от мокъпа (търси ги в WC по име)</button></form>
				</div>
			<?php elseif ( 'products' === $tab ) : ?>
				<?php $plist = $draft['products']; $plist[] = array_merge( Config::product_defaults(), array( 'key' => '', 'name' => '', 'enabled' => true, '_new' => true ) ); ?>
				<?php self::form_open( 'products', 'products', ' style="display:block;margin-top:14px" id="apProducts"' ); ?>
					<p>Ключът е UTM стойността (<code>?<?php echo esc_html( $draft['utm_param'] ); ?>=ключ</code>). Снимката и цената идват от избрания WC продукт (при variable — вариацията „1 брой“); „Снимка (attachment id)“ я заменя. Последният ред е за нов продукт. Записва се в <b>черновата</b> — публикуваш от 🧰 Инструменти.</p>
					<style>.ap-prod{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;margin-bottom:10px}.ap-prod .ap-row{display:grid;grid-template-columns:70px 110px 1fr 1fr 70px;gap:8px;align-items:end}.ap-prod .ap-row2{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:8px}.ap-prod label{font-size:11px;color:#50575e;display:block}.ap-prod input[type=text],.ap-prod input[type=number],.ap-prod textarea{width:100%}.ap-prod .ap-wc{display:flex;gap:6px;align-items:center}.ap-prod .ap-wc input{width:90px}.ap-prod .ap-res{position:absolute;z-index:10;background:#fff;border:1px solid #c3c4c7;box-shadow:0 4px 14px rgba(0,0,0,.12);max-height:260px;overflow:auto;min-width:320px}.ap-prod .ap-res button{display:flex;gap:8px;align-items:center;width:100%;text-align:left;border:0;background:#fff;padding:6px 8px;cursor:pointer}.ap-prod .ap-res button:hover{background:#f0f6fc}.ap-prod .ap-res img{width:32px;height:32px;object-fit:contain}.ap-prod .ap-thumb{width:48px;height:48px;object-fit:contain;border:1px solid #dcdcde;border-radius:6px;background:#fff}.ap-prod details{margin-top:6px}.ap-prod summary{cursor:pointer;color:#2271b1;font-size:12px}</style>
					<?php foreach ( $plist as $i => $p ) : $rpid = '' !== $p['sku'] ? ( Catalog::find_by_sku( $p['sku'] ) ?: (int) $p['product_id'] ) : (int) $p['product_id']; $wc = $rpid ? Catalog::wc_line( $rpid ) : null; list( $wn, $wds, $wdesc ) = $wc ? Frontend::wc_texts( $wc ) : array( '', '', '' ); ?>
					<div class="ap-prod"<?php echo ! empty( $p['_new'] ) ? ' style="border-style:dashed"' : ''; ?>>
						<div class="ap-row">
							<div><label>вкл.</label><input type="checkbox" name="p[<?php echo $i; ?>][enabled]" value="1"<?php checked( ! empty( $p['enabled'] ) ); ?>> <label style="display:inline">изтрий</label> <input type="checkbox" name="p[<?php echo $i; ?>][del]" value="1"></div>
							<div><label>ключ (UTM)</label><input type="text" name="p[<?php echo $i; ?>][key]" value="<?php echo esc_attr( $p['key'] ); ?>" placeholder="<?php echo ! empty( $p['_new'] ) ? 'нов' : ''; ?>"></div>
							<div><label>име на страницата <?php echo $wn ? '<span style="color:#787c82">· празно = „' . esc_html( $wn ) . '“ от WC</span>' : ''; ?></label><input type="text" name="p[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $p['name'] ); ?>" placeholder="<?php echo esc_attr( $wn ); ?>"></div>
							<div style="position:relative"><label>WC продукт (търси по име или ID)</label><div class="ap-wc"><input type="number" name="p[<?php echo $i; ?>][product_id]" value="<?php echo (int) $p['product_id']; ?>" class="ap-pid"><input type="text" class="ap-q" placeholder="търси…" style="flex:1" autocomplete="off"><img class="ap-thumb" src="<?php echo esc_url( $wc && $wc['img'] ? $wc['img'] : '' ); ?>" alt="" <?php echo $wc && $wc['img'] ? '' : 'style="visibility:hidden"'; ?>></div><div class="ap-res" hidden></div><small class="ap-note"><?php echo $wc ? esc_html( ( $wc['ok'] ? $wc['wc_name'] . ' · €' . number_format( $wc['price'], 2, ',', '' ) . ( $wc['variation_id'] ? ' · вар.#' . $wc['variation_id'] : '' ) . ( $wc['stock'] ? '' : ' · НЕ Е НАЛИЧЕН' ) : 'ПРОБЛЕМ: ' . $wc['note'] ) ) : 'не е свързан — няма да се показва'; ?></small></div>
							<div><label>емоджи</label><input type="text" name="p[<?php echo $i; ?>][ph]" value="<?php echo esc_attr( $p['ph'] ); ?>"></div>
						</div>
						<div class="ap-row2" style="grid-template-columns:1fr 2fr">
							<div><label>SKU на вариацията „1 опаковка“ (печели над WC ID)</label><input type="text" name="p[<?php echo $i; ?>][sku]" value="<?php echo esc_attr( $p['sku'] ); ?>" placeholder="напр. dgr001"></div>
							<div style="font-size:12px;color:#50575e;align-self:end"><?php if ( '' !== $p['sku'] ) { echo $rpid ? 'SKU → WC #' . (int) $rpid . ( $wc && $wc['base_name'] ? ' · ' . esc_html( $wc['base_name'] ) : '' ) . ( $wc && $wc['variation_id'] ? ' · вариация #' . (int) $wc['variation_id'] : '' ) . ( $wc ? ' · € ' . esc_html( number_format( $wc['price'], 2 ) ) : '' ) : '<b style="color:#b32d2e">SKU не е намерен в WooCommerce</b>'; } ?></div>
						</div>
						<div class="ap-row2 ap-pct" style="grid-template-columns:repeat(<?php echo max( 1, count( $draft['boxes'] ) ); ?>,1fr) 2fr">
							<?php foreach ( $draft['boxes'] as $b ) : $pv = isset( $p['pct'][ $b['id'] ] ) ? (float) $p['pct'][ $b['id'] ] : ''; ?>
							<div><label>−% в <?php echo esc_html( $b['ic'] . ' ' . $b['name'] ); ?> <span style="color:#787c82">· празно = −<?php echo esc_html( Cart::fmt( $b['pct'] ) ); ?>%</span></label><input type="number" step="0.5" min="0.5" max="99.5" name="p[<?php echo $i; ?>][pct][<?php echo esc_attr( $b['id'] ); ?>]" value="<?php echo '' === $pv ? '' : esc_attr( Cart::fmt( $pv ) ); ?>" placeholder="<?php echo esc_attr( Cart::fmt( $b['pct'] ) ); ?>"<?php echo $wc && $wc['ok'] ? ' data-price="' . esc_attr( $wc['price'] ) . '"' : ''; ?>></div>
							<?php endforeach; ?>
							<div style="font-size:12px;color:#50575e;align-self:end">Отстъпката на този продукт по кутия — <b>само цената</b>; лейбълите на страницата остават −<?php echo esc_html( implode( '/−', array_map( function ( $b ) { return Cart::fmt( $b['pct'] ); }, $draft['boxes'] ) ) ); ?>%.<?php if ( $wc && $wc['ok'] ) : ?> Цена в WC: € <?php echo esc_html( number_format( $wc['price'], 2 ) ); ?> → <?php echo esc_html( implode( ' · ', array_map( function ( $b ) use ( $p, $wc ) { return $b['ic'] . ' € ' . number_format( $wc['price'] * ( 1 - Config::product_pct( $p, $b ) / 100 ), 2 ); }, $draft['boxes'] ) ) ); ?><?php endif; ?></div>
						</div>
						<div class="ap-row2">
							<div><label>категория (селектор)</label><input type="text" name="p[<?php echo $i; ?>][cat]" value="<?php echo esc_attr( $p['cat'] ); ?>"></div>
							<div><label>кратко „за какво е“ (ds) <?php echo $wds ? '<span style="color:#787c82">· празно = от WC</span>' : ''; ?></label><input type="text" name="p[<?php echo $i; ?>][ds]" value="<?php echo esc_attr( $p['ds'] ); ?>" placeholder="<?php echo esc_attr( $wds ); ?>"></div>
							<div><label>снимка (attachment id, по избор)</label><input type="number" name="p[<?php echo $i; ?>][img]" value="<?php echo (int) $p['img']; ?>"></div>
						</div>
						<p style="margin:8px 0 0;font-size:12px"><label><input type="checkbox" name="p[<?php echo $i; ?>][wc_text]" value="1"<?php checked( ! empty( $p['wc_text'] ) ); ?>> <b>празните полета се пълнят от WooCommerce</b> — име, „за какво е“ и описание идват от продукта (марката без „ansa™“, заглавието на Shrine, краткото описание), но <b>всичко, което напишеш тук, печели</b>. Без отметка се ползват само полетата тук.</label><?php if ( $wc && ! empty( $p['wc_text'] ) ) : ?><br><span style="color:#50575e">WC сега: <?php echo esc_html( $wc['subtitle'] ?: mb_substr( $wc['short'], 0, 90 ) ); ?><?php echo mb_strlen( $wc['short'] ) > 90 && ! $wc['subtitle'] ? '…' : ''; ?></span><?php endif; ?></p>
						<details><summary>още: описание · съставки · за кого · опаковка · рейтинг · отзиви · gate име/подзаглавие · цветове</summary>
							<div class="ap-row2">
								<div><label>описание (desc) <?php echo $wdesc ? '<span style="color:#787c82">· празно = от WC</span>' : ''; ?></label><textarea name="p[<?php echo $i; ?>][desc]" rows="3" placeholder="<?php echo esc_attr( $wdesc ); ?>"><?php echo esc_textarea( $p['desc'] ); ?></textarea></div>
								<div><label>какво съдържа (ing)</label><textarea name="p[<?php echo $i; ?>][ing]" rows="3"><?php echo esc_textarea( $p['ing'] ); ?></textarea></div>
								<div><label>за кого е (who)</label><textarea name="p[<?php echo $i; ?>][who]" rows="3"><?php echo esc_textarea( $p['who'] ); ?></textarea></div>
								<div><label>опаковка (напр. 60 капсули · за 30 дни)</label><input type="text" name="p[<?php echo $i; ?>][pack]" value="<?php echo esc_attr( $p['pack'] ); ?>"></div>
								<div><label>рейтинг (напр. 4.9 · 1 120 отзива)</label><input type="text" name="p[<?php echo $i; ?>][rating]" value="<?php echo esc_attr( $p['rating'] ); ?>"></div>
								<div><label>цветове на gate-а (3 hex, със запетая)</label><input type="text" name="p[<?php echo $i; ?>][theme]" value="<?php echo esc_attr( implode( ',', (array) $p['theme'] ) ); ?>"></div>
								<div><label>gate: пълно име (gname)</label><input type="text" name="p[<?php echo $i; ?>][gname]" value="<?php echo esc_attr( $p['gname'] ); ?>"></div>
								<div><label>gate: подзаглавие (gsub)</label><input type="text" name="p[<?php echo $i; ?>][gsub]" value="<?php echo esc_attr( $p['gsub'] ); ?>"></div>
								<div><label>отзиви (ред = име|звезди|текст)</label><textarea name="p[<?php echo $i; ?>][reviews]" rows="3"><?php echo esc_textarea( $p['reviews'] ); ?></textarea></div>
							</div>
						</details>
					</div>
					<?php endforeach; ?>
					<p><button class="button button-primary">💾 Запази продуктите в черновата</button></p>
				</form>
				<script>
				(function(){
					var nonce=<?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?>,ajax=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,t;
					document.querySelectorAll('#apProducts .ap-q').forEach(function(q){
						var box=q.closest('.ap-wc').parentNode.querySelector('.ap-res'),pid=q.closest('.ap-wc').querySelector('.ap-pid'),img=q.closest('.ap-wc').querySelector('.ap-thumb'),note=q.closest('.ap-wc').parentNode.querySelector('.ap-note');
						q.addEventListener('input',function(){clearTimeout(t);var v=q.value.trim();if(v.length<2){box.hidden=true;return}
							t=setTimeout(function(){fetch(ajax+'?action=ansa_promo_admin_search&nonce='+nonce+'&q='+encodeURIComponent(v),{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(r){
								if(!r||!r.success){box.hidden=true;return}
								box.innerHTML=r.data.length?r.data.map(function(x){return '<button type="button" data-id="'+x.id+'" data-img="'+(x.img||'')+'" data-name="'+x.name.replace(/"/g,'&quot;')+'" data-price="'+x.price+'"><img src="'+(x.img||'')+'" alt="">#'+x.id+' '+x.name+' <small>'+x.type+' · €'+x.price+(x.note?' · '+x.note:'')+'</small></button>'}).join(''):'<div style="padding:8px">нищо</div>';
								box.hidden=false;
								box.querySelectorAll('button').forEach(function(b){b.onclick=function(){pid.value=b.dataset.id;img.src=b.dataset.img;img.style.visibility=b.dataset.img?'visible':'hidden';note.textContent=b.dataset.name+' · €'+b.dataset.price+' (запази, за да влезе в черновата)';box.hidden=true;q.value=''}});
							})},300)});
						document.addEventListener('click',function(e){if(!box.contains(e.target)&&e.target!==q)box.hidden=true});
					});
				})();
				</script>
			<?php elseif ( 'orders' === $tab ) : ?>
				<?php $rows = Tickets::orders( array( 'limit' => 200 ) ); $f = sanitize_key( (string) ( $_GET['f'] ?? '' ) ); if ( $f ) { $rows = array_values( array_filter( $rows, function ( $r ) use ( $f ) { $ts = $r['ticket'] ? $r['ticket']['status'] : 'none'; return 'invalid' === $f ? ! $r['valid'] : $ts === $f; } ) ); } ?>
				<p style="margin-top:14px">Промо поръчки: <b><?php echo count( $rows ); ?></b> (последните 200) · билети: издадени <b><?php echo (int) Tickets::count( 'issued' ); ?></b> (<?php echo (int) Tickets::sum( 'issued' ); ?> бр.), активирани <b><?php echo (int) Tickets::count( 'activated' ); ?></b> (<?php echo (int) Tickets::sum( 'activated' ); ?> бр.), анулирани <?php echo (int) Tickets::count( 'void' ); ?>
					&nbsp; · филтър: <?php foreach ( array( '' => 'всички', 'issued' => 'издаден', 'activated' => 'активиран', 'void' => 'анулиран', 'none' => 'без билет', 'invalid' => 'непълна кутия' ) as $k => $l ) : ?><a href="<?php echo esc_url( self::url( array( 'tab' => 'orders', 'f' => $k ) ) ); ?>"<?php echo $k === $f ? ' style="font-weight:700"' : ''; ?>><?php echo esc_html( $l ); ?></a> <?php endforeach; ?>
					&nbsp; <?php self::form_open( 'export_orders', 'orders' ); ?><button class="button">⬇ CSV</button></form>
					<?php $lp = Leads::stats()['pending']; ?>&nbsp; <?php self::form_open( 'leads_sync', 'orders', ' onsubmit="return confirm(\'Да пратя ли ' . (int) $lp . ' лийда от имейл-попъпа във FunnelKit (контакт + лист + таг)? Ако автоматизацията по тага/листа е активна, имейлите ще тръгнат към тях.\')"' ); ?><button class="button"<?php disabled( 0 === $lp ); ?> title="лийдовете, които още не са минали през FunnelKit (или са за повторен опит)">📨 Лийдове → FunnelKit: <?php echo (int) $lp; ?> чакат</button></form>
					<?php $unt = count( Merge::untagged() ); ?>&nbsp; <?php self::form_open( 'fka_backfill', 'orders', ' onsubmit="return confirm(\'Да сложа ли FunnelKit таговете (общия + по кутия) на ' . (int) $unt . ' промо поръчки, на които липсват? Ако автоматизацията „Tag Added“ е активна, имейлът ще тръгне към тези клиенти.\')"' ); ?><button class="button"<?php disabled( 0 === $unt ); ?> title="поръчките отпреди 1.8.2 нямат тага — това го слага на контактите им">🏷️ FKA таг на <?php echo (int) $unt; ?> стари поръчки</button></form></p>
				<table class="widefat striped"><thead><tr><th>поръчка</th><th>дата</th><th>статус</th><th>клиент</th><th>кутия</th><th>билети</th><th>код</th><th>билет</th><th>FKA таг</th><th>сет</th><th>книга</th><th>UTM</th><th>сума</th></tr></thead><tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr><td><a href="<?php echo esc_url( $r['edit'] ); ?>">#<?php echo esc_html( $r['number'] ); ?></a><?php echo $r['partner'] ? ' 🤝' : ''; ?></td><td><?php echo esc_html( $r['date'] ); ?></td><td><?php echo esc_html( wc_get_order_status_name( $r['status'] ) ); ?></td><td><?php echo esc_html( $r['name'] ); ?><br><small><?php echo esc_html( $r['email'] ); ?></small></td>
						<td><?php echo esc_html( $r['box'] . ' · ' . $r['packs'] . ' · −' . $r['pct'] . '%' ); ?><?php echo $r['valid'] ? '' : ' <span style="color:#cf222e">непълна</span>'; ?></td><td><?php echo $r['ticket'] ? (int) $r['ticket']['tickets'] . ( $r['ticket']['bonus'] ? ' <b title="бонус от препоръки">+' . (int) $r['ticket']['bonus'] . '</b>' : '' ) : (int) $r['allowance'] . ' <small>(очаква)</small>'; ?><?php echo $r['ref_gift'] ? ' <small title="по препоръка ' . esc_attr( $r['ref_from'] ) . '">🎁</small>' : ''; ?></td>
						<td><?php echo $r['ticket'] ? '<code>' . esc_html( $r['ticket']['code'] ) . '</code> <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ansa_promo_ticket_print&order=' . (int) $r['id'] ), 'ansa_promo_ticket_print' ) ) . '" target="_blank" title="стикер">🖨</a>' : '—'; ?></td>
						<td><?php echo $r['ticket'] ? esc_html( $r['ticket']['status'] ) . ( $r['ticket']['activated_at'] ? '<br><small>' . esc_html( $r['ticket']['activated_at'] . ' · ' . $r['ticket']['portal_user'] ) . '</small>' : '' ) : '—'; ?></td>
						<td><?php $ft = (string) ( $r['fka_tag'] ?? '' ); echo '' === $ft ? '—' : ( 0 === strpos( $ft, 'retry' ) ? '<span style="color:#9a6700" title="' . esc_attr( $ft ) . '">⏳ retry</span>' : '<span title="' . esc_attr( $ft ) . '">✓ ' . esc_html( preg_replace( '/^.*· /u', '', $ft ) ) . '</span>' ); ?></td>
						<td><?php echo esc_html( $r['cosm'] ?: '—' ); ?><?php echo $r['voucher'] ? '<br><code>' . esc_html( $r['voucher'] ) . '</code>' : ''; ?></td><td><?php echo $r['book'] ? ( $r['book_sent'] ? '✓ ' . esc_html( $r['book_sent'] ) : 'при изпълнена' ) : '—'; ?></td><td><?php echo esc_html( $r['utm'] ); ?></td><td><?php echo wp_kses_post( wc_price( $r['total'] ) ); ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?><tr><td colspan="13">Още няма промо поръчки.</td></tr><?php endif; ?>
				</tbody></table>
			<?php elseif ( 'tombola' === $tab ) : ?>
				<?php $ent = Tombola::entries( $pub ); $tot = 0; $act = 0; $refs = 0; $bon = 0; foreach ( $ent as $e ) { $tot += $e['total']; $bon += $e['bonus']; $refs += $e['refs']; if ( 'activated' === $e['status'] ) { $act++; } } $boxes = array(); foreach ( $pub['boxes'] as $b ) { $boxes[ $b['id'] ] = $b['ic'] . ' ' . $b['name']; } $draws = (array) get_option( 'ansa_promo_tombola_draws', array() ); ?>
				<p style="margin-top:14px">Страница: <a href="<?php echo esc_url( Tombola::url( $pub ) ); ?>" target="_blank"><?php echo esc_html( Tombola::url( $pub ) ); ?></a> · теглене <b><?php echo esc_html( Tombola::draw_label( $pub ) ); ?></b> · „получена“ = <?php echo esc_html( implode( ', ', (array) $pub['tombola']['statuses'] ) ); ?><?php echo Tombola::enabled( $pub ) ? '' : ' · <b style="color:#cf222e">ИЗКЛЮЧЕНА</b>'; ?></p>
				<p>Участници (получени поръчки с билет): <b><?php echo count( $ent ); ?></b> · билети общо <b><?php echo (int) $tot; ?></b> (от тях <?php echo (int) $bon; ?> бонус от препоръки) · проверили се на страницата: <b><?php echo (int) $act; ?></b> · броени препоръки: <b><?php echo (int) $refs; ?></b> (всички редове: <?php echo (int) Tombola::count_referrals(); ?> · self <?php echo (int) Tombola::count_referrals( 'self' ); ?> · dup <?php echo (int) Tombola::count_referrals( 'dup' ); ?> · void <?php echo (int) Tombola::count_referrals( 'void' ); ?>)
					&nbsp; <?php self::form_open( 'export_tombola', 'tombola' ); ?><button class="button">⬇ CSV на участниците</button></form>
					&nbsp; <?php self::form_open( 'draw', 'tombola', ' onsubmit="return confirm(\'Да изтегля ли случаен участник (тежест = билетите му)? Резултатът се записва в дневника.\')"' ); ?><input type="text" name="label" placeholder="етикет, напр. „Яхта – финал“" style="width:200px"> <button class="button button-primary">🎲 Изтегли печеливш</button></form></p>
				<h3>🔎 Защо не намира поръчка?</h3>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:0 0 10px"><input type="hidden" name="page" value="<?php echo esc_attr( Plugin::MENU_SLUG ); ?>"><input type="hidden" name="tab" value="tombola"><input type="email" name="diag_email" value="<?php echo esc_attr( (string) ( $_GET['diag_email'] ?? '' ) ); ?>" placeholder="имейл от поръчката" class="regular-text"> <input type="text" name="diag_phone" value="<?php echo esc_attr( (string) ( $_GET['diag_phone'] ?? '' ) ); ?>" placeholder="телефон" style="width:160px"> <button class="button">Провери като клиента</button> <span class="description">същата проверка като на страницата, но с обяснение за всяка поръчка с този имейл (не активира билети)</span></form>
				<?php if ( ! empty( $_GET['diag_email'] ) ) : $dg = Tombola::diagnose( sanitize_email( (string) wp_unslash( $_GET['diag_email'] ) ), sanitize_text_field( (string) wp_unslash( $_GET['diag_phone'] ?? '' ) ), $pub ); ?>
					<p>Резултат за клиента: <b><?php echo ! empty( $dg['result']['ok'] ) ? '✅ намира — ' . (int) $dg['result']['total'] . ' билет(а) от ' . count( $dg['result']['orders'] ) . ' поръчки' : '❌ ' . esc_html( (string) ( $dg['result']['code'] ?? '' ) ); ?></b> · поръчки с този имейл: <?php echo count( $dg['orders'] ); ?></p>
					<table class="widefat striped" style="max-width:1200px"><thead><tr><th>поръчка</th><th>статус</th><th>телефон (billing / shipping)</th><th>промо</th><th>билет</th><th>защо (не)</th></tr></thead><tbody>
					<?php foreach ( $dg['orders'] as $d ) : ?><tr><td><a href="<?php echo esc_url( $d['edit'] ); ?>">#<?php echo esc_html( $d['number'] ); ?></a><br><small><?php echo esc_html( $d['date'] ); ?></small></td><td><?php echo esc_html( $d['status'] ); ?><?php echo $d['delivered'] ? ' ✓' : ''; ?></td><td><?php echo esc_html( $d['billing_phone'] ); ?><?php echo $d['shipping_phone'] && $d['shipping_phone'] !== $d['billing_phone'] ? ' / ' . esc_html( $d['shipping_phone'] ) : ''; ?><?php echo $d['phone_ok'] ? ' ✓' : ''; ?></td><td><?php echo $d['promo'] ? ( $d['valid'] ? '✓ ' : 'непълна · ' ) . esc_html( $d['box'] ) . ' · ' . (int) $d['allowance'] . ' бил.' . ( $d['draft'] ? ' · чернова' : '' ) : '—'; ?></td><td><?php echo esc_html( $d['ticket'] ?: '—' ); ?></td><td><?php echo $d['ok'] ? '<b style="color:#1a7f37">✅ хваща се</b>' : '<span style="color:#cf222e">' . esc_html( implode( ' · ', $d['why'] ) ) . '</span>'; ?></td></tr><?php endforeach; ?>
					<?php if ( ! $dg['orders'] ) : ?><tr><td colspan="6">Няма нито една поръчка с billing имейл <code><?php echo esc_html( (string) $_GET['diag_email'] ); ?></code> (нито по клиент с този имейл). Провери имейла в самата поръчка.</td></tr><?php endif; ?>
					</tbody></table>
				<?php endif; ?>
				<?php if ( $draws ) : ?><h3>Дневник на тегленията</h3><ul><?php foreach ( $draws as $d ) : ?><li><?php echo esc_html( $d['at'] ); ?> · <?php echo esc_html( $d['label'] ?: '—' ); ?> → <b>поръчка #<?php echo esc_html( $d['order'] ); ?></b> · <?php echo esc_html( $d['email'] ); ?> · <?php echo (int) $d['tickets']; ?> билет(а) · жребий <?php echo (int) $d['pick']; ?> от <?php echo (int) $d['pool']; ?> (<?php echo (int) $d['entries']; ?> участници)</li><?php endforeach; ?></ul><?php endif; ?>
				<table class="widefat striped"><thead><tr><th>поръчка</th><th>клиент</th><th>телефон</th><th>кутия</th><th>билети</th><th>бонус (препоръки)</th><th>общо</th><th>билет</th><th>код за препоръка</th><th>препоръки</th><th>по препоръка</th><th>ваучер</th></tr></thead><tbody>
				<?php foreach ( $ent as $e ) : ?>
					<tr><td><a href="<?php echo esc_url( $e['edit'] ); ?>">#<?php echo esc_html( $e['number'] ); ?></a></td><td><?php echo esc_html( $e['name'] ); ?><br><small><?php echo esc_html( $e['email'] ); ?></small></td><td><?php echo esc_html( $e['phone'] ); ?></td><td><?php echo esc_html( $boxes[ $e['box'] ] ?? $e['box'] ); ?></td><td><?php echo (int) $e['tickets']; ?></td><td><?php echo (int) $e['bonus']; ?></td><td><b><?php echo (int) $e['total']; ?></b></td><td><code><?php echo esc_html( $e['code'] ); ?></code><br><small><?php echo esc_html( $e['status'] . ( $e['activated'] ? ' · ' . $e['activated'] : '' ) ); ?></small></td><td><code><?php echo esc_html( $e['ref_code'] ?: '—' ); ?></code></td><td><?php echo (int) $e['refs']; ?></td><td><?php echo $e['gift'] ? '🎁 +' . (int) $e['gift'] : '—'; ?></td><td><?php echo esc_html( $e['voucher'] ?: '—' ); ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $ent ) : ?><tr><td colspan="12">Още няма получени промо поръчки с билет (статуси: <?php echo esc_html( implode( ', ', (array) $pub['tombola']['statuses'] ) ); ?>).</td></tr><?php endif; ?>
				</tbody></table>
			<?php elseif ( 'insights' === $tab ) : ?>
				<?php Insights::render( isset( $_GET['days'] ) ? (int) $_GET['days'] : 30 ); ?>
			<?php elseif ( 'json' === $tab ) : ?>
				<?php self::form_open( 'import', 'json', ' style="display:block;max-width:900px;margin-top:14px"' ); ?>
					<p>Черновата като JSON. Редактирай и „Внеси“ — записва се като чернова (минава през normalize), публикуваш от 🧰 Инструменти.</p>
					<textarea name="json" style="width:100%;height:520px;font:12px/1.4 Menlo,Consolas,monospace" spellcheck="false"><?php echo esc_textarea( Config::export() ); ?></textarea>
					<p><button class="button button-primary">⬆️ Внеси като чернова</button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
