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
	}

	public static function menu() {
		add_menu_page( 'ansa™ Промо', '🎁 ansa Промо', Plugin::CAP, Plugin::MENU_SLUG, array( __CLASS__, 'page' ), 'dashicons-tickets-alt', 56 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Plugin::MENU_SLUG ) ) . '">Промо</a>' );
		return $links;
	}

	public static function url( $args = array() ) { return add_query_arg( $args, admin_url( 'admin.php?page=' . Plugin::MENU_SLUG ) ); }

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
				$cfg['bgn']['show'] = ! empty( $_POST['bgn_show'] );
				Config::save_draft( $cfg ); $msg = 'Черновата е записана.'; break;
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
		$tabs = array( 'status' => 'Състояние', 'tools' => '🧰 Инструменти', 'json' => 'JSON' );
		?>
		<div class="wrap">
			<h1>🎁 ansa™ Промо <small style="font-weight:400;color:#777">v<?php echo esc_html( ANSA_PROMO_VER ); ?> · Фаза 1</small></h1>
			<?php if ( $msg ) : ?><div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $k => $label ) : ?><a class="nav-tab<?php echo $k === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => $k ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
				<span class="nav-tab" style="opacity:.5">✏️ Редактор · Фаза 2</span>
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
				<?php self::form_open( 'settings', 'tools', ' style="display:block"' ); ?>
					<table class="form-table">
						<tr><th>Страница с [ansa_promo]</th><td><select name="page_id"><option value="0">— избери —</option>
							<?php foreach ( Doctor::pages_with_shortcode( true ) as $pg ) : ?><option value="<?php echo (int) $pg['id']; ?>"<?php selected( $pg['id'], $draft['page_id'] ); ?>>#<?php echo (int) $pg['id']; ?> <?php echo esc_html( $pg['title'] ); ?> (<?php echo esc_html( $pg['status'] ); ?>)</option><?php endforeach; ?>
						</select><p class="description">Само страници, в чието съдържание има <code>[ansa_promo]</code>.</p></td></tr>
						<tr><th>Играта е включена</th><td><label><input type="checkbox" name="enabled" value="1"<?php checked( $draft['enabled'] ); ?>> публичната страница показва играта (иначе „скоро“; черновата се вижда винаги с <code>?ansa_promo=draft</code>)</label></td></tr>
						<tr><th>Край</th><td><input type="text" name="deadline" value="<?php echo esc_attr( $draft['deadline'] ); ?>" class="regular-text" placeholder="2026-12-31 23:59"></td></tr>
						<tr><th>UTM параметър</th><td><input type="text" name="utm_param" value="<?php echo esc_attr( $draft['utm_param'] ); ?>" class="regular-text"> <span class="description">кацане: <code>?<?php echo esc_html( $draft['utm_param'] ); ?>=sakura</code></span></td></tr>
						<tr><th>Имейл-попъп</th><td><label><input type="checkbox" name="gate_enabled" value="1"<?php checked( $draft['gate']['enabled'] ); ?>> включен</label> &nbsp; <label><input type="checkbox" name="gate_required" value="1"<?php checked( $draft['gate']['required'] ); ?>> задължителен (без „Продължи без имейл“)</label></td></tr>
						<tr><th>Лева в поръчката</th><td><label><input type="checkbox" name="bgn_show" value="1"<?php checked( $draft['bgn']['show'] ); ?>> показвай „(… лв.)“ до крайната сума</label></td></tr>
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
				<h2>Продуктите в черновата</h2>
				<table class="widefat striped"><thead><tr><th>ключ (UTM)</th><th>име</th><th>WC продукт</th><th>ред · цена · наличност</th></tr></thead><tbody>
				<?php foreach ( $draft['products'] as $p ) : $wc = $p['product_id'] ? Catalog::wc_line( $p['product_id'] ) : null; ?>
					<tr><td><code><?php echo esc_html( $p['key'] ); ?></code></td><td><?php echo esc_html( $p['ph'] . ' ' . $p['name'] ); ?></td>
						<td><?php echo $p['product_id'] ? '#' . (int) $p['product_id'] . ' ' . esc_html( $wc['wc_name'] ) : '<span style="color:#cf222e">няма — изключен от страницата</span>'; ?></td>
						<td><?php echo $wc ? ( $wc['ok'] ? ( $wc['variation_id'] ? 'вариация #' . (int) $wc['variation_id'] : 'simple' ) . ' · €' . esc_html( number_format( $wc['price'], 2, ',', '' ) ) . ' · ' . ( $wc['stock'] ? 'наличен' : '<span style="color:#cf222e">НЕ е наличен</span>' ) . ( $wc['note'] ? ' · ' . esc_html( $wc['note'] ) : '' ) : '<span style="color:#cf222e">' . esc_html( $wc['note'] ) . '</span>' ) : '—'; ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="description">Свързването ключ ↔ WC продукт и останалите полета са в ⚙️ Двигател (Фаза 2). Дотогава: таб JSON (полето <code>product_id</code>).</p>
				<?php self::form_open( 'seed', 'tools', ' onsubmit="return confirm(\'Това презаписва продуктите, FIT и проблемите в черновата с тези от мокъпа. Страницата, текстовете, срокът и чекаутът остават. Продължавам?\')"' ); ?><button class="button">🌱 Направи продуктите наново от мокъпа (търси ги в WC по име)</button></form>
				</div>
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
