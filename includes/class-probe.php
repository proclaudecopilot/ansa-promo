<?php
/**
 * ansa™ Промо — проверките за LOGADOR Probe (вместо снипети).
 *
 * Плъгинът LOGADOR Probe (probe/ansa-probe, качва се веднъж) дава REST с token и пуска „suites“, регистрирани през
 * filter `logador_probe_suites`. Тук са нашите: env (Doctor), page (страницата като анонимен посетител), cart (Фаза 3).
 * Всяка suite получава $t (ok/eq/note) и параметрите на заявката; хвърлено изключение = провалена suite, не 500.
 *
 * @package AnsaPromo
 * @since 1.0.53
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Probe {

	public static function register() {
		add_filter( 'logador_probe_suites', array( __CLASS__, 'suites' ) );
		add_filter( 'logador_probe_env', array( __CLASS__, 'env' ) );
	}

	public static function suites( $s ) {
		$s[] = array( 'id' => 'promo-env',  'label' => 'ansa™ Промо · среда (Doctor)',       'plugin' => 'ansa-promo', 'callback' => array( __CLASS__, 'suite_env' ) );
		$s[] = array( 'id' => 'promo-page', 'label' => 'ansa™ Промо · страницата на живо',   'plugin' => 'ansa-promo', 'callback' => array( __CLASS__, 'suite_page' ) );
		return $s;
	}

	/** Кратък блок в /env: версия, конфиг, страница. */
	public static function env( $env ) {
		$pub = Config::get_published(); $draft = Config::get_draft();
		$env['ansa_promo'] = array( 'version' => ANSA_PROMO_VER, 'db_ver' => get_option( Schema::OPT_DB_VER ), 'config_version' => Config::version(), 'has_draft' => Config::has_draft(), 'published' => Config::has_published(), 'live' => Config::is_live( $pub ), 'enabled' => ! empty( $pub['enabled'] ), 'deadline' => $pub['deadline'], 'page_id' => (int) $pub['page_id'], 'page_url' => $pub['page_id'] ? get_permalink( (int) $pub['page_id'] ) : '', 'preview_pw' => ! empty( $draft['preview']['enabled'] ), 'products' => count( $pub['products'] ), 'live_products' => count( Frontend::products( $pub ) ), 'gateways' => $pub['checkout'], 'shrine' => Plugin::shrine_version(), 'shrine_promo_loaded' => Plugin::shrine_promo_module_loaded() );
		return $env;
	}

	/* ── env: редовете на Doctor като проверки ── */
	public static function suite_env( $t, $params ) {
		foreach ( Doctor::env() as $r ) {
			if ( 'bad' === $r['level'] ) { $t->ok( $r['label'], false, $r['value'] ); }
			elseif ( 'warn' === $r['level'] ) { $t->note( '⚠ ' . $r['label'], $r['value'] ); }
			else { $t->note( $r['label'], $r['value'] ); }
		}
		$pub = Config::get_published();
		$t->ok( 'публикуван конфиг', Config::has_published(), 'version=' . Config::version() );
		$t->ok( 'страница избрана', ! empty( $pub['page_id'] ), $pub['page_id'] ? get_permalink( (int) $pub['page_id'] ) : 'page_id=0' );
		$live = Frontend::products( $pub );
		$t->ok( 'продукти с цена > 0', count( $live ) >= 1, count( $live ) . ' от ' . count( $pub['products'] ) );
		foreach ( $pub['products'] as $p ) { if ( empty( $p['enabled'] ) ) { continue; } $pid = '' !== $p['sku'] ? ( Catalog::find_by_sku( $p['sku'] ) ?: (int) $p['product_id'] ) : (int) $p['product_id']; $wc = Catalog::wc_line( $pid ); $t->ok( 'продукт ' . $p['key'], $wc['ok'] && $wc['stock'], ( $pid ? '#' . $pid : 'без WC продукт' ) . ( $wc['variation_id'] ? ' вар #' . $wc['variation_id'] : '' ) . ' · €' . $wc['price'] . ( $wc['stock'] ? ' · наличен' : ' · НЕ Е НАЛИЧЕН' ) . ( $wc['note'] ? ' · ' . $wc['note'] : '' ) ); }
		foreach ( array( 'book', 'cosm' ) as $rw ) { $pid = (int) $pub['rewards'][ $rw ]['product_id']; $pr = $pid && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null; $t->note( 'награда ' . $rw, $pr ? '#' . $pid . ' ' . $pr->get_name() . ' · ' . ( $pr->is_purchasable() ? 'purchasable' : 'НЕ purchasable' ) : 'без WC продукт (' . $pid . ')' ); }
		$t->ok( 'промо модулът на Shrine не е зареден', ! Plugin::shrine_promo_module_loaded() );
		$cnt = Copy::defaults(); $t->note( 'копи-регистър', count( $cnt ) . ' ключа · override-и: ' . count( $pub['copy'] ) );
		return array( 'config_version' => Config::version(), 'live' => Config::is_live( $pub ) );
	}

	/* ── page: страницата, изтеглена от сървъра като анонимен посетител ── */
	public static function suite_page( $t, $params ) {
		$pub = Config::get_published(); $draft = Config::get_draft();
		if ( empty( $pub['page_id'] ) ) { $t->ok( 'страница', false, 'няма page_id в публикувания конфиг' ); return null; }
		$url = get_permalink( (int) $pub['page_id'] ); $t->note( 'url', $url );
		$get = function ( $u, $cookie = '' ) { $a = array( 'timeout' => 30, 'redirection' => 3, 'sslverify' => false, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (LOGADOR Probe) Chrome', 'Cache-Control' => 'no-cache' ) ); if ( $cookie ) { $a['headers']['Cookie'] = $cookie; } $r = wp_remote_get( $u, $a ); return is_wp_error( $r ) ? array( 0, '', $r->get_error_message() ) : array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ), '' ); };
		list( $code, $body, $err ) = $get( $url );
		$t->ok( 'HTTP 200', 200 === $code, $code . ' ' . $err );
		$t->ok( 'има #ansaPromo', false !== strpos( $body, 'id="ansaPromo"' ) );
		$pw = Frontend::preview_available();
		if ( $pw ) {
			$t->ok( 'формата за парола е пред анонимния', false !== strpos( $body, 'ansa-promo-pwgate' ), 'preview.enabled=1' );
			/* с бисквитката — играта (черновата) */
			$cookie = Frontend::PW_COOKIE . '=' . Frontend::preview_token( $draft['preview']['password'] );
			list( $c2, $b2 ) = $get( $url, $cookie );
			$t->ok( 'с парола → играта', 200 === $c2 && false !== strpos( $b2, 'window.AnsaPromoRuntime=' ) && false === strpos( $b2, 'ansa-promo-pwgate' ), 'HTTP ' . $c2 );
			$body = $b2;
		} else {
			$t->ok( Config::is_live( $pub ) ? 'играта е на екрана' : 'играта е „скоро“', Config::is_live( $pub ) ? false !== strpos( $body, 'window.AnsaPromoRuntime=' ) : false !== strpos( $body, 'ansa-promo-soon' ) );
		}
		if ( preg_match( '/window\.AnsaPromoRuntime=(\{[^\n]*\});/', $body, $m ) ) {
			$rt = json_decode( $m[1], true );
			$t->ok( 'runtime JSON валиден', is_array( $rt ), strlen( $m[1] ) . ' B' );
			if ( is_array( $rt ) ) {
				$t->eq( 'runtime.ver = плъгина', ANSA_PROMO_VER, $rt['ver'] ?? '' );
				$t->ok( 'runtime продукти', count( (array) $rt['products'] ) >= 1, count( (array) $rt['products'] ) . ' продукта · cart=' . var_export( $rt['cart'] ?? null, true ) . ' · live=' . var_export( $rt['live'] ?? null, true ) );
				$t->ok( 'nonce', ! empty( $rt['nonce'] ) );
			}
		} else { $t->ok( 'runtime JSON', false, 'няма window.AnsaPromoRuntime в HTML-а' ); }
		$t->ok( 'без непопълнени {{…}}', ! preg_match( '/\{\{\s*[a-z0-9_]+\s*\}\}/i', (string) preg_replace( '/<script.*?<\/script>/s', '', $body ) ) );
		$t->ok( 'promo.js се зарежда', (bool) preg_match( '#assets/promo\.js\?ver=' . preg_quote( ANSA_PROMO_VER, '#' ) . '#', $body ), 'ver=' . ANSA_PROMO_VER );
		$t->note( 'body class', preg_match( '/<body[^>]*class="([^"]*)"/i', $body, $bm ) ? $bm[1] : '' );
		/* ajax runtime */
		$r = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 20, 'sslverify' => false, 'body' => array( 'action' => 'ansa_promo_runtime' ) ) );
		$j = is_wp_error( $r ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$t->ok( 'ajax ansa_promo_runtime', is_array( $j ) && ! empty( $j['success'] ), is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r ) );
		return array( 'url' => $url, 'bytes' => strlen( $body ) );
	}
}
