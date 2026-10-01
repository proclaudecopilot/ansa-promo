<?php
/**
 * ansa™ Промо — админ.
 *
 * Фаза 0: меню „🎁 ansa Промо“ (top-level) с екран „Състояние“ (Doctor::env()).
 * Фаза 2 носи редактора (региони · канвас · инспектор · ⚙️ Двигател · 🧰 Инструменти).
 *
 * @package AnsaPromo
 * @since 1.0.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Admin {

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ANSA_PROMO_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( 'ansa™ Промо', '🎁 ansa Промо', Plugin::CAP, Plugin::MENU_SLUG, array( __CLASS__, 'page' ), 'dashicons-tickets-alt', 56 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . Plugin::MENU_SLUG ) ) . '">Промо</a>' );
		return $links;
	}

	public static function page() {
		if ( ! current_user_can( Plugin::CAP ) ) { wp_die( 'Нямаш права.' ); }
		$rows = Doctor::env();
		$col  = array( 'ok' => '#1a7f37', 'warn' => '#9a6700', 'bad' => '#cf222e', 'info' => '#57606a' );
		?>
		<div class="wrap">
			<h1>🎁 ansa™ Промо <small style="font-weight:400;color:#777">v<?php echo esc_html( ANSA_PROMO_VER ); ?> · Фаза 0</small></h1>
			<p>Скелет: плъгинът е инсталиран, таблиците са създадени, <code>[ansa_promo]</code> печата заместител. Редакторът идва с Фаза 2, страницата — с Фаза 1.</p>
			<h2>Състояние</h2>
			<table class="widefat striped" style="max-width:1100px">
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<th style="width:260px"><?php echo esc_html( $r['label'] ); ?></th>
						<td style="color:<?php echo esc_attr( $col[ $r['level'] ] ?? $col['info'] ); ?>"><?php echo esc_html( $r['value'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:16px;color:#777">Пълният отчет за средата (за чата) е в <code>snippets/phase-0.php</code> на репото — пуска се като Code Snippet.</p>
		</div>
		<?php
	}
}
