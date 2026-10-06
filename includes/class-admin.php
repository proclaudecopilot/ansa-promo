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

	/** Медийната библиотека за „Снимки в попъпа“ (само на нашата страница). */
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
				Config::save_draft( $cfg ); $msg = 'Черновата е записана.'; break;
			case 'products':
				$cfg = Config::get_draft(); $rows = (array) ( $_POST['p'] ?? array() ); $new = array();
				foreach ( $rows as $r ) {
					if ( ! is_array( $r ) || ! empty( $r['del'] ) ) { continue; }
					$r = wp_unslash( $r ); $key = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) ( $r['key'] ?? '' ) ) );
					if ( '' === $key && '' === trim( (string) ( $r['name'] ?? '' ) ) ) { continue; }
					$old = Config::product( $cfg, $key ) ?: Config::product_defaults();
					foreach ( array( 'key', 'name', 'ph', 'gname', 'gsub', 'ds', 'desc', 'ing', 'who', 'rating', 'reviews', 'cat', 'pack' ) as $f ) { if ( isset( $r[ $f ] ) ) { $old[ $f ] = (string) $r[ $f ]; } }
					$old['product_id'] = (int) ( $r['product_id'] ?? 0 ); $old['img'] = (int) ( $r['img'] ?? 0 ); $old['enabled'] = ! empty( $r['enabled'] );
					if ( isset( $r['theme'] ) ) { $old['theme'] = array_map( 'trim', explode( ',', (string) $r['theme'] ) ); }
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
		$tabs = array( 'status' => 'Състояние', 'tools' => '🧰 Инструменти', 'products' => '📦 Продукти', 'json' => 'JSON' );
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
						<tr><th>Снимки в попъпа</th><td>
							<p class="description" style="margin:0 0 8px">Плочките „Можеш да получиш“ (4) и „Готова ли си?“ (3) показват снимка вместо емоджи. Attachment ID от медийната библиотека или пълен URL. <b>Празно = примерната снимка</b> (сивият етикет „примерна снимка“ на картинката изчезва, щом сложиш своя). Снимките се режат на 16:10 (награди) и 4:3 (кутии) — слагай хоризонтални, ≥ 800px.</p>
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
			<?php elseif ( 'products' === $tab ) : ?>
				<?php $plist = $draft['products']; $plist[] = array_merge( Config::product_defaults(), array( 'key' => '', 'name' => '', 'enabled' => true, '_new' => true ) ); ?>
				<?php self::form_open( 'products', 'products', ' style="display:block;margin-top:14px" id="apProducts"' ); ?>
					<p>Ключът е UTM стойността (<code>?<?php echo esc_html( $draft['utm_param'] ); ?>=ключ</code>). Снимката и цената идват от избрания WC продукт (при variable — вариацията „1 брой“); „Снимка (attachment id)“ я заменя. Последният ред е за нов продукт. Записва се в <b>черновата</b> — публикуваш от 🧰 Инструменти.</p>
					<style>.ap-prod{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;margin-bottom:10px}.ap-prod .ap-row{display:grid;grid-template-columns:70px 110px 1fr 1fr 70px;gap:8px;align-items:end}.ap-prod .ap-row2{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:8px}.ap-prod label{font-size:11px;color:#50575e;display:block}.ap-prod input[type=text],.ap-prod input[type=number],.ap-prod textarea{width:100%}.ap-prod .ap-wc{display:flex;gap:6px;align-items:center}.ap-prod .ap-wc input{width:90px}.ap-prod .ap-res{position:absolute;z-index:10;background:#fff;border:1px solid #c3c4c7;box-shadow:0 4px 14px rgba(0,0,0,.12);max-height:260px;overflow:auto;min-width:320px}.ap-prod .ap-res button{display:flex;gap:8px;align-items:center;width:100%;text-align:left;border:0;background:#fff;padding:6px 8px;cursor:pointer}.ap-prod .ap-res button:hover{background:#f0f6fc}.ap-prod .ap-res img{width:32px;height:32px;object-fit:contain}.ap-prod .ap-thumb{width:48px;height:48px;object-fit:contain;border:1px solid #dcdcde;border-radius:6px;background:#fff}.ap-prod details{margin-top:6px}.ap-prod summary{cursor:pointer;color:#2271b1;font-size:12px}</style>
					<?php foreach ( $plist as $i => $p ) : $wc = $p['product_id'] ? Catalog::wc_line( $p['product_id'] ) : null; ?>
					<div class="ap-prod"<?php echo ! empty( $p['_new'] ) ? ' style="border-style:dashed"' : ''; ?>>
						<div class="ap-row">
							<div><label>вкл.</label><input type="checkbox" name="p[<?php echo $i; ?>][enabled]" value="1"<?php checked( ! empty( $p['enabled'] ) ); ?>> <label style="display:inline">изтрий</label> <input type="checkbox" name="p[<?php echo $i; ?>][del]" value="1"></div>
							<div><label>ключ (UTM)</label><input type="text" name="p[<?php echo $i; ?>][key]" value="<?php echo esc_attr( $p['key'] ); ?>" placeholder="<?php echo ! empty( $p['_new'] ) ? 'нов' : ''; ?>"></div>
							<div><label>име на страницата</label><input type="text" name="p[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $p['name'] ); ?>"></div>
							<div style="position:relative"><label>WC продукт (търси по име или ID)</label><div class="ap-wc"><input type="number" name="p[<?php echo $i; ?>][product_id]" value="<?php echo (int) $p['product_id']; ?>" class="ap-pid"><input type="text" class="ap-q" placeholder="търси…" style="flex:1" autocomplete="off"><img class="ap-thumb" src="<?php echo esc_url( $wc && $wc['img'] ? $wc['img'] : '' ); ?>" alt="" <?php echo $wc && $wc['img'] ? '' : 'style="visibility:hidden"'; ?>></div><div class="ap-res" hidden></div><small class="ap-note"><?php echo $wc ? esc_html( ( $wc['ok'] ? $wc['wc_name'] . ' · €' . number_format( $wc['price'], 2, ',', '' ) . ( $wc['variation_id'] ? ' · вар.#' . $wc['variation_id'] : '' ) . ( $wc['stock'] ? '' : ' · НЕ Е НАЛИЧЕН' ) : 'ПРОБЛЕМ: ' . $wc['note'] ) ) : 'не е свързан — няма да се показва'; ?></small></div>
							<div><label>емоджи</label><input type="text" name="p[<?php echo $i; ?>][ph]" value="<?php echo esc_attr( $p['ph'] ); ?>"></div>
						</div>
						<div class="ap-row2">
							<div><label>категория (селектор)</label><input type="text" name="p[<?php echo $i; ?>][cat]" value="<?php echo esc_attr( $p['cat'] ); ?>"></div>
							<div><label>кратко „за какво е“ (ds)</label><input type="text" name="p[<?php echo $i; ?>][ds]" value="<?php echo esc_attr( $p['ds'] ); ?>"></div>
							<div><label>снимка (attachment id, по избор)</label><input type="number" name="p[<?php echo $i; ?>][img]" value="<?php echo (int) $p['img']; ?>"></div>
						</div>
						<details><summary>още: описание · съставки · за кого · опаковка · рейтинг · отзиви · gate име/подзаглавие · цветове</summary>
							<div class="ap-row2">
								<div><label>описание (desc)</label><textarea name="p[<?php echo $i; ?>][desc]" rows="3"><?php echo esc_textarea( $p['desc'] ); ?></textarea></div>
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
