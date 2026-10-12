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
		$s[] = array( 'id' => 'promo-cart', 'label' => 'ansa™ Промо · количка и чекаут',     'plugin' => 'ansa-promo', 'callback' => array( __CLASS__, 'suite_cart' ) );
		$s[] = array( 'id' => 'promo-tickets', 'label' => 'ansa™ Промо · билети · QR · портал · имейли', 'plugin' => 'ansa-promo', 'callback' => array( __CLASS__, 'suite_tickets' ) );
		$s[] = array( 'id' => 'promo-insights', 'label' => 'ansa™ Промо · Insights · ansa Partner мост', 'plugin' => 'ansa-promo', 'callback' => array( __CLASS__, 'suite_insights' ) );
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
		foreach ( $pub['products'] as $p ) { if ( empty( $p['enabled'] ) ) { continue; } $pid = '' !== $p['sku'] ? ( Catalog::find_by_sku( $p['sku'] ) ?: (int) $p['product_id'] ) : (int) $p['product_id']; $wc = Catalog::wc_line( $pid ); $t->ok( 'продукт ' . $p['key'], $wc['ok'], ( $wc['ok'] && ! $wc['stock'] ? '⚠ ' : '' ) . ( $pid ? '#' . $pid : 'без WC продукт' ) . ( $wc['variation_id'] ? ' вар #' . $wc['variation_id'] : '' ) . ' · €' . $wc['price'] . ( $wc['stock'] ? ' · наличен' : ' · НЕ Е НАЛИЧЕН' ) . ( $wc['note'] ? ' · ' . $wc['note'] : '' ) ); }
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
		$hdr = array();
		$get = function ( $u, $cookie = '' ) use ( &$hdr ) { $a = array( 'timeout' => 30, 'redirection' => 3, 'sslverify' => false, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (LOGADOR Probe) Chrome', 'Cache-Control' => 'no-cache' ) ); if ( $cookie ) { $a['headers']['Cookie'] = $cookie; } $r = wp_remote_get( $u, $a ); if ( is_wp_error( $r ) ) { return array( 0, '', $r->get_error_message() ); } $h = wp_remote_retrieve_headers( $r ); $hdr = array(); foreach ( array( 'x-flying-press-cache', 'x-flying-press-source', 'cf-cache-status', 'x-litespeed-cache', 'cache-control' ) as $k ) { if ( isset( $h[ $k ] ) ) { $hdr[ $k ] = is_array( $h[ $k ] ) ? implode( ',', $h[ $k ] ) : $h[ $k ]; } } return array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ), '' ); };
		list( $code, $body, $err ) = $get( $url );
		$t->ok( 'HTTP 200', 200 === $code, $code . ' ' . $err . ' · ' . wp_json_encode( $hdr ) );
		$t->ok( 'има #ansaPromo', false !== strpos( $body, 'id="ansaPromo"' ) );
		$pw = Frontend::preview_available();
		if ( $pw ) {
			$t->ok( 'формата за парола е пред анонимния', false !== strpos( $body, 'ansa-promo-pwgate' ), 'preview.enabled=1' );
			/* с бисквитката — играта (черновата) */
			$cookie = Frontend::PW_COOKIE . '=' . Frontend::preview_token( $draft['preview']['password'] );
			list( $c2, $b2 ) = $get( $url, $cookie ); $cached = false !== strpos( $b2, 'ansa-promo-pwgate' ) && ! empty( $hdr['x-flying-press-cache'] ) && 'HIT' === strtoupper( (string) $hdr['x-flying-press-cache'] );
			if ( $cached ) { $t->note( '⚠ с парола (чист URL)', 'кешът връща формата (' . wp_json_encode( $hdr ) . ') — браузърът презарежда с ?ansa_pw_ok=, както по-долу; страницата трябва да е изключена от кеша (Doctor)' ); }
			else { $t->ok( 'с парола → играта', 200 === $c2 && false !== strpos( $b2, 'window.AnsaPromoRuntime=' ) && false === strpos( $b2, 'ansa-promo-pwgate' ), 'HTTP ' . $c2 . ' · ' . wp_json_encode( $hdr ) ); }
			/* както браузърът след приета парола: бисквитка + ?ansa_pw_ok= (параметърът заобикаля кеша) */
			list( $c3, $b3 ) = $get( add_query_arg( 'ansa_pw_ok', (string) time(), $url ), $cookie );
			$t->ok( 'с парола + ?ansa_pw_ok → играта', 200 === $c3 && false !== strpos( $b3, 'window.AnsaPromoRuntime=' ) && false === strpos( $b3, 'ansa-promo-pwgate' ), 'HTTP ' . $c3 . ' · ' . wp_json_encode( $hdr ) );
			$body = $b3;
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

	/* ── cart: Фаза 3 — за всяка кутия: добавяне, цени, книга, доставка, купон, заключване, tamper, грешки, идемпотентност, поръчка ──
	   Параметри: ?keep=1 оставя тестовата поръчка (иначе се трие); ?box=l само една кутия; ?checkout=0 без изтегляне на чекаута. */
	public static function suite_cart( $t, $params ) {
		if ( ! function_exists( 'WC' ) ) { $t->ok( 'WooCommerce', false ); return null; }
		if ( ! WC()->cart ) { if ( function_exists( 'wc_load_cart' ) ) { wc_load_cart(); } }
		if ( ! WC()->cart || ! WC()->session ) { $t->ok( 'WC количка в този контекст', false, 'wc_load_cart() не даде количка' ); return null; }
		$pub = Config::get_published(); $draft = ! Config::is_live( $pub ); $cfg = $draft ? Config::get_draft() : $pub;
		$t->note( 'конфиг', ( $draft ? 'ЧЕРНОВА (играта не е live) ' : 'публикуваното ' ) . 'v' . Config::version() );
		$live = Frontend::products( $cfg ); $ok_keys = array(); foreach ( $live as $k => $p ) { if ( $p['stock'] ) { $ok_keys[] = $k; } }
		$t->ok( 'поне 5 налични продукта', count( $ok_keys ) >= 5, count( $ok_keys ) . ' налични от ' . count( $live ) );
		if ( count( $ok_keys ) < 1 ) { return null; }
		$ids = function ( $keys ) use ( $live ) { return array_map( function ( $k ) use ( $live ) { return (int) $live[ $k ]['id']; }, $keys ); };
		$only = (string) ( $params['box'] ?? '' ); $keep = ! empty( $params['keep'] ); $do_checkout = ! isset( $params['checkout'] ) || '0' !== (string) $params['checkout'];
		WC()->customer->set_billing_country( 'BG' ); WC()->customer->set_shipping_country( 'BG' ); WC()->customer->set_shipping_postcode( '1000' ); WC()->customer->set_shipping_city( 'София' );
		$base = array( 'trusted' => true, 'draft' => $draft, 'pay' => 'cod', 'email' => 'probe@example.com', 'cosm' => true, 'utm' => 'probe', 'lead' => 0 );
		$order_ids = array(); $result = array();
		foreach ( $cfg['boxes'] as $box ) {
			if ( $only && $box['id'] !== $only ) { continue; }
			$n = (int) $box['packs']; $pick = array(); for ( $i = 0; $i < $n; $i++ ) { $pick[] = $ok_keys[ $i % count( $ok_keys ) ]; } /* повторения са позволени (qty) */
			$label = $box['ic'] . ' ' . $box['name'] . ' (' . $box['id'] . ')';
			$r = Cart::perform_add( array_merge( $base, array( 'box' => $box['id'], 'items' => $ids( $pick ), 'secret' => array() ) ) );
			if ( ! $t->ok( $label . ' · add', ! empty( $r['ok'] ), $r['ok'] ?? false ? 'group ' . $r['group'] . ' · total ' . $r['total'] : ( $r['code'] ?? '' ) . ': ' . ( $r['msg'] ?? '' ) ) ) { continue; }
			$t->ok( $label . ' · checkoutUrl', ! empty( $r['checkoutUrl'] ), $r['checkoutUrl'] ?? '' );
			WC()->cart->calculate_totals(); $g = Cart::group();
			$t->ok( $label . ' · групата е валидна', Cart::group_valid( $g ), 'Σ=' . ( $g['sum'] ?? 0 ) . ' packs=' . ( $g['packs'] ?? 0 ) );
			$t->eq( $label . ' · уникални base редове', count( array_unique( $pick ) ), count( $g['base'] ) );
			$bad = array();
			foreach ( $g['base'] as $it ) { $reg = (float) $it['data']->get_regular_price() ?: (float) $it['data']->get_price(); $want = round( $reg * ( 1 - $box['pct'] / 100 ), 2 ); $got = (float) $it['data']->get_price(); if ( abs( $want - $got ) > 0.011 ) { $bad[] = $it['data']->get_name() . ' ' . $got . '≠' . $want; } }
			$t->ok( $label . ' · base цени = regular × (1 − ' . $box['pct'] . '%)', ! $bad, $bad ? implode( '; ', $bad ) : count( $g['base'] ) . ' реда ок' );
			$has_book = in_array( 'book', $box['rw'], true ) && ! empty( $cfg['rewards']['book']['product_id'] );
			if ( $has_book ) { $bk = $g['book'] ? WC()->cart->get_cart_item( $g['book'] ) : null; $t->ok( $label . ' · книгата е в количката на €0', $bk && 0.0 === (float) $bk['data']->get_price(), $bk ? 'цена ' . $bk['data']->get_price() : 'няма ред book' ); }
			else { $t->ok( $label . ' · без книга', empty( $g['book'] ), in_array( 'book', $box['rw'], true ) ? 'кутията има book, но rewards.book.product_id е 0' : '' ); }
			$t->ok( $label . ' · нищо чуждо в количката', empty( $g['foreign'] ), $g['foreign'] ? count( $g['foreign'] ) . ' чужди реда (подарък на Shrine?)' : '' );
			/* доставка */
			$packages = WC()->cart->get_shipping_packages(); $rates = array();
			if ( $packages ) { $calc = WC()->shipping()->calculate_shipping( $packages ); foreach ( (array) ( $calc[0]['rates'] ?? array() ) as $rid => $rate ) { $rates[ $rid ] = (float) $rate->get_cost(); } }
			$free = in_array( 'ship', $box['rw'], true );
			if ( $rates ) { $t->ok( $label . ' · доставка ' . ( $free ? 'безплатна' : 'платена' ), $free ? max( $rates ) <= 0.0 : min( $rates ) > 0.0, wp_json_encode( $rates ) ); } else { $t->note( $label . ' · доставка', 'няма методи за BG/1000 — провери зоните' ); }
			/* купон */
			$code = 'probe-' . wp_generate_password( 6, false, false ); $cid = wp_insert_post( array( 'post_title' => $code, 'post_type' => 'shop_coupon', 'post_status' => 'publish' ) );
			if ( $cid ) { update_post_meta( $cid, 'discount_type', 'percent' ); update_post_meta( $cid, 'coupon_amount', '10' ); wc_clear_notices(); $applied = WC()->cart->apply_coupon( $code ); $msgs = wp_list_pluck( wc_get_notices( 'error' ), 'notice' ); wc_clear_notices(); WC()->cart->remove_coupon( $code ); wp_delete_post( $cid, true ); $t->ok( $label . ' · купонът е отхвърлен', ! $applied && ! in_array( $code, WC()->cart->get_applied_coupons(), true ), implode( ' | ', array_map( 'wp_strip_all_tags', $msgs ) ) ); }
			/* заключване */
			$k0 = array_key_first( $g['base'] );
			$t->ok( $label . ' · qty заключено', false !== strpos( apply_filters( 'woocommerce_cart_item_quantity', '<input>', $k0, $g['base'][ $k0 ] ), 'ansa-promo-qty' ) );
			$t->ok( $label . ' · ✕ скрито', '' === apply_filters( 'woocommerce_cart_item_remove_link', '<a>x</a>', $k0 ) );
			$t->ok( $label . ' · етикет в количката', false !== strpos( apply_filters( 'woocommerce_cart_item_name', 'X', $g['base'][ $k0 ], $k0 ), 'ansa-promo-tag' ) );
			$ctx = Cart::ctx(); $t->ok( $label . ' · ctx', ( $ctx['group'] ?? '' ) === $g['group'] && ( $ctx['pay'] ?? '' ) === 'cod', wp_json_encode( array_intersect_key( $ctx, array_flip( array( 'box', 'pay', 'email', 'draft' ) ) ), JSON_UNESCAPED_UNICODE ) );
			$t->eq( $label . ' · chosen_payment_method', $cfg['checkout']['cod_gateway'], WC()->session->get( 'chosen_payment_method' ) );
			/* идемпотентно */
			$r2 = Cart::perform_add( array_merge( $base, array( 'box' => $box['id'], 'items' => $ids( $pick ), 'secret' => array() ) ) );
			$t->ok( $label . ' · повторно add = същата група', ! empty( $r2['ok'] ) && ! empty( $r2['idempotent'] ) && $r2['group'] === $g['group'], wp_json_encode( array( 'idempotent' => $r2['idempotent'] ?? null, 'group' => $r2['group'] ?? null ) ) );
			/* чекаут HTML с тази сесия */
			if ( $do_checkout ) { $co = self::fetch_as_session( Cart::checkout_url() ); $t->ok( $label . ' · чекаутът показва наградите', 200 === $co['code'] && false !== strpos( $co['body'], 'ansa-promo-co__box' ), 'HTTP ' . $co['code'] . ' · ' . $co['bytes'] . ' B' . ( $co['err'] ? ' · ' . $co['err'] : '' ) . ( false !== strpos( $co['body'], 'name="ansa_promo_cosm"' ) ? ' · ☑ сетът' : '' ) . ( $co['wfacp'] ? ' · FunnelKit' : ' · WC' ) );
				/* ?snippet=1 → парчета от HTML-а около нашите блокове (за стилизиране срещу истинския DOM на чекаута) */
				if ( ! empty( $params['snippet'] ) && ! isset( $result['_snippets'] ) ) { $result['_snippets'] = array(); foreach ( array( 'ansa-promo-cosm', 'ansa-promo-co__box', 'order-total', 'place_order' ) as $needle ) { $pos = strpos( $co['body'], $needle ); $result['_snippets'][ $needle ] = false === $pos ? null : substr( $co['body'], max( 0, $pos - 2500 ), 5000 ); } $result['_classes'] = preg_match( '/<body[^>]*class="([^"]*)"/', $co['body'], $bm ) ? $bm[1] : ''; $result['_styles'] = array_slice( array_unique( preg_match_all( '#<link[^>]+href="([^"]+\.css[^"]*)"#', $co['body'], $lm ) ? $lm[1] : array() ), 0, 40 ); } }
			/* поръчка (COD, програмно) */
			$oid = self::make_order( $t, $label, $box, $cfg );
			if ( $oid ) { $order_ids[] = $oid; }
			/* tamper: махаме един base ред → регулярни цени, книгата пада, notice */
			if ( $n >= 1 ) {
				$r3 = Cart::perform_add( array_merge( $base, array( 'box' => $box['id'], 'items' => $ids( $pick ), 'secret' => array() ) ) ); $g = Cart::group();
				$k0 = array_key_first( $g['base'] ); WC()->cart->remove_cart_item( $k0 ); WC()->cart->calculate_totals(); $g2 = Cart::group();
				if ( $g2 ) {
					$t->ok( $label . ' · tamper → групата е невалидна', ! Cart::group_valid( $g2 ), 'Σ=' . $g2['sum'] . ' packs=' . $g2['packs'] );
					$bad = array(); foreach ( $g2['base'] as $it ) { $reg = (float) $it['data']->get_regular_price() ?: (float) $it['data']->get_price(); if ( abs( $reg - (float) $it['data']->get_price() ) > 0.011 ) { $bad[] = $it['data']->get_name(); } }
					$t->ok( $label . ' · tamper → регулярни цени', ! $bad, $bad ? implode( ', ', $bad ) : '' );
					$t->ok( $label . ' · tamper → книгата пада', empty( $g2['book'] ) );
					wc_clear_notices(); do_action( 'woocommerce_check_cart_items' ); $notices = wp_list_pluck( wc_get_notices( 'error' ), 'notice' ); wc_clear_notices();
					$t->ok( $label . ' · tamper → notice с линк към промото', (bool) array_filter( $notices, function ( $x ) { return false !== strpos( $x, 'href' ); } ), implode( ' | ', array_map( 'wp_strip_all_tags', $notices ) ) );
				} else { $t->note( $label . ' · tamper', 'кутията е с 1 опаковка — след махане няма група (ок)' ); }
			}
			$result[ $box['id'] ] = array( 'pick' => $pick, 'order' => $oid );
		}
		/* грешки */
		$bl = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][0];
		$r = Cart::perform_add( array_merge( $base, array( 'box' => $bl['id'], 'items' => $ids( array_slice( $ok_keys, 0, 1 ) ) ) ) ); $t->eq( 'грешка: грешен брой → err.count', 'count', $r['code'] ?? 'ok', $r['msg'] ?? '' );
		$r = Cart::perform_add( array_merge( $base, array( 'box' => 'zz', 'items' => array() ) ) ); $t->eq( 'грешка: невалидна кутия → err.box', 'box', $r['code'] ?? 'ok' );
		$r = Cart::perform_add( array_merge( $base, array( 'box' => $bl['id'], 'items' => array_fill( 0, (int) $bl['packs'], 999999999 ) ) ) ); $t->eq( 'грешка: продукт извън играта → err.allowed', 'allowed', $r['code'] ?? 'ok' );
		$oos = null; foreach ( $live as $k => $p ) { if ( ! $p['stock'] ) { $oos = $k; break; } }
		if ( $oos ) { $r = Cart::perform_add( array_merge( $base, array( 'box' => $bl['id'], 'items' => array_fill( 0, (int) $bl['packs'], (int) $live[ $oos ]['id'] ) ) ) ); $t->eq( 'грешка: изчерпан продукт → err.stock', 'stock', $r['code'] ?? 'ok', $r['msg'] ?? '' ); } else { $t->note( 'грешка: изчерпан продукт', 'няма изчерпан продукт за проверка' ); }
		if ( ! $draft ) { $r = Cart::perform_add( array( 'draft' => false, 'box' => $bl['id'], 'items' => array() ) ); $t->note( 'грешка без trusted (като клиент)', ( $r['code'] ?? 'ok' ) ); }
		/* чистене */
		WC()->cart->empty_cart(); Cart::clear_ctx(); wc_clear_notices();
		if ( ! $keep ) { foreach ( $order_ids as $oid ) { $o = wc_get_order( $oid ); if ( $o ) { $o->delete( true ); } } $t->note( 'тестови поръчки', count( $order_ids ) . ' създадени и изтрити (?keep=1 ги оставя)' ); }
		else { $t->note( 'тестови поръчки (оставени)', implode( ', ', $order_ids ) ); }
		return $result;
	}

	/** Поръчка от текущата количка през WC_Checkout::create_order (COD), както би я направил чекаутът. Връща order id или 0. */
	private static function make_order( $t, $label, $box, $cfg ) {
		try {
			$data = array( 'billing_first_name' => 'Probe', 'billing_last_name' => 'Тест', 'billing_email' => 'probe@example.com', 'billing_phone' => '0888000000', 'billing_country' => 'BG', 'billing_city' => 'София', 'billing_postcode' => '1000', 'billing_address_1' => 'ул. Тест 1', 'shipping_country' => 'BG', 'shipping_city' => 'София', 'shipping_postcode' => '1000', 'shipping_address_1' => 'ул. Тест 1', 'shipping_first_name' => 'Probe', 'shipping_last_name' => 'Тест', 'payment_method' => $cfg['checkout']['cod_gateway'] ?: 'cod', 'ship_to_different_address' => false, 'order_comments' => 'LOGADOR Probe — тестова поръчка', 'shipping_method' => array() );
			$packages = WC()->cart->get_shipping_packages(); if ( $packages ) { $calc = WC()->shipping()->calculate_shipping( $packages ); $first = array_key_first( (array) ( $calc[0]['rates'] ?? array() ) ); if ( $first ) { $data['shipping_method'] = array( $first ); WC()->session->set( 'chosen_shipping_methods', array( $first ) ); } }
			$_POST['ansa_promo_cosm_seen'] = '1'; $_POST['ansa_promo_cosm'] = '1';
			WC()->cart->calculate_totals();
			$oid = WC()->checkout()->create_order( $data );
			unset( $_POST['ansa_promo_cosm_seen'], $_POST['ansa_promo_cosm'] );
			if ( is_wp_error( $oid ) ) { $t->ok( $label . ' · поръчка', false, $oid->get_error_message() ); return 0; }
			$o = wc_get_order( $oid ); do_action( 'woocommerce_checkout_order_created', $o ); $o->save();
			$m = $o->get_meta( '_ansa_promo' );
			$t->ok( $label . ' · поръчка #' . $oid . ' с _ansa_promo', is_array( $m ) && ! empty( $m['valid'] ), is_array( $m ) ? 'box=' . $m['box'] . ' pct=' . $m['pct'] . ' tickets=' . $m['tickets']['allowance'] . ' cosm=' . ( $m['cosm'] ? $m['cosm']['status'] : '—' ) . ' ship_free=' . var_export( $m['ship_free'], true ) : 'няма meta' );
			$t->eq( $label . ' · _ansa_promo_tickets', (int) $box['tickets'], (int) $o->get_meta( '_ansa_promo_tickets' ) );
			$roles = array(); foreach ( $o->get_items() as $it ) { $roles[] = $it->get_meta( '_ansa_promo_role' ) ?: '?'; }
			$t->ok( $label . ' · ролите по редовете', ! in_array( '?', $roles, true ), implode( ',', $roles ) );
			$t->note( $label . ' · поръчка тотал', $o->get_total() . ' · доставка ' . $o->get_shipping_total() . ' · ' . $o->get_payment_method() );
			$gate = (string) wp_remote_retrieve_response_code( wp_remote_get( $o->get_checkout_order_received_url(), array( 'timeout' => 20, 'sslverify' => false ) ) );
			$t->note( $label . ' · thank-you URL', $gate . ' ' . $o->get_checkout_order_received_url() );
			return (int) $oid;
		} catch ( \Throwable $e ) { $t->ok( $label . ' · поръчка', false, get_class( $e ) . ': ' . $e->getMessage() ); return 0; }
	}

	/** Сървърът тегли URL със сесийната бисквитка на ТАЗИ WC сесия (както би го видял клиентът след add). */
	public static function fetch_as_session( $url ) {
		$out = array( 'code' => 0, 'body' => '', 'bytes' => 0, 'err' => '', 'wfacp' => false );
		try {
			WC()->cart->set_session(); WC()->session->save_data();
			$cid = WC()->session->get_customer_id(); $exp = time() + 2 * DAY_IN_SECONDS; $expiring = $exp - HOUR_IN_SECONDS; /* бисквитката носи само hash(customer_id|exp); редът в DB е записан от save_data() */
			$to_hash = $cid . '|' . $exp; $hash = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
			$cookie = 'wp_woocommerce_session_' . COOKIEHASH . '=' . rawurlencode( $cid . '||' . $exp . '||' . $expiring . '||' . $hash );
			$r = wp_remote_get( $url, array( 'timeout' => 40, 'redirection' => 3, 'sslverify' => false, 'headers' => array( 'Cookie' => $cookie, 'User-Agent' => 'Mozilla/5.0 (LOGADOR Probe) Chrome', 'Cache-Control' => 'no-cache' ) ) );
			if ( is_wp_error( $r ) ) { $out['err'] = $r->get_error_message(); return $out; }
			$out['code'] = (int) wp_remote_retrieve_response_code( $r ); $out['body'] = (string) wp_remote_retrieve_body( $r ); $out['bytes'] = strlen( $out['body'] ); $out['wfacp'] = false !== strpos( $out['body'], 'wfacp' );
		} catch ( \Throwable $e ) { $out['err'] = $e->getMessage(); }
		return $out;
	}

	/* ── tickets: Фаза 4 — поръчка → processing → билет (HMAC код, QR, REST), activate, completed → имейли, cancel → void. ?keep=1 оставя поръчката. */
	public static function suite_tickets( $t, $params ) {
		if ( ! function_exists( 'WC' ) ) { $t->ok( 'WooCommerce', false ); return null; }
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) { wc_load_cart(); }
		if ( ! WC()->cart || ! WC()->session ) { $t->ok( 'WC количка', false ); return null; }
		$pub = Config::get_published(); $draft = ! Config::is_live( $pub ); $cfg = $draft ? Config::get_draft() : $pub; $keep = ! empty( $params['keep'] );
		/* кодове */
		$code = Tickets::generate(); $t->ok( 'код: 12 знака с валидна контрола', 12 === strlen( $code ) && Tickets::normalize( $code ) === $code, Tickets::pretty( $code ) );
		$t->ok( 'код: приема тирета/малки букви', Tickets::normalize( strtolower( Tickets::pretty( $code ) ) ) === $code );
		$bad = substr( $code, 0, 11 ) . ( 'A' === $code[11] ? 'B' : 'A' ); $t->ok( 'код: сгрешен знак → невалиден без DB', '' === Tickets::normalize( $bad ) );
		/* QR */
		$url = Tickets::ticket_url( $code ); $png = QR::png( $url ); $svg = QR::svg( $url );
		$t->ok( 'QR PNG' . ( function_exists( 'imagecreatetruecolor' ) ? '' : ' (без GD → SVG)' ), function_exists( 'imagecreatetruecolor' ) ? strlen( $png ) > 200 && "\x89PNG" === substr( $png, 0, 4 ) : '' !== $svg, strlen( $png ) . ' B · ' . $url );
		$t->ok( 'QR SVG', false !== strpos( $svg, '<svg' ), strlen( $svg ) . ' B' );
		/* поръчка */
		$live = Frontend::products( $cfg ); $ok_keys = array(); foreach ( $live as $k => $p ) { if ( $p['stock'] ) { $ok_keys[] = $k; } }
		$box = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][ count( $cfg['boxes'] ) - 1 ]; $n = (int) $box['packs'];
		if ( count( $ok_keys ) < 1 ) { $t->ok( 'налични продукти', false ); return null; }
		$pick = array(); for ( $i = 0; $i < $n; $i++ ) { $pick[] = $ok_keys[ $i % count( $ok_keys ) ]; }
		WC()->customer->set_billing_country( 'BG' ); WC()->customer->set_shipping_country( 'BG' ); WC()->customer->set_shipping_postcode( '1000' );
		$r = Cart::perform_add( array( 'trusted' => true, 'draft' => $draft, 'box' => $box['id'], 'items' => array_map( function ( $k ) use ( $live ) { return (int) $live[ $k ]['id']; }, $pick ), 'pay' => 'cod', 'email' => 'probe@example.com', 'cosm' => true ) );
		if ( ! $t->ok( 'add ' . $box['name'], ! empty( $r['ok'] ), $r['msg'] ?? '' ) ) { return null; }
		$oid = self::make_order( $t, $box['ic'] . ' ' . $box['name'], $box, $cfg ); WC()->cart->empty_cart(); Cart::clear_ctx();
		if ( ! $oid ) { return null; }
		$o = wc_get_order( $oid ); $m = $o->get_meta( '_ansa_promo' );
		/* имейлите се прихващат */
		$mails = array(); $hook = function ( $pre, $atts ) use ( &$mails ) { $mails[] = array( 'to' => $atts['to'], 'subject' => $atts['subject'], 'bytes' => strlen( (string) $atts['message'] ) ); return true; };
		add_filter( 'pre_wp_mail', $hook, 10, 2 );
		$t->ok( 'преди processing няма билет', ! Tickets::for_order( $oid ) );
		$o->update_status( 'processing', 'probe' ); $row = Tickets::for_order( $oid );
		$t->ok( 'processing → билет', (bool) $row, $row ? Tickets::pretty( $row['code'] ) . ' · ' . $row['tickets'] . ' бр. · ' . $row['status'] : 'няма ред' );
		if ( ! $row ) { remove_filter( 'pre_wp_mail', $hook, 10 ); if ( ! $keep ) { $o->delete( true ); } return null; }
		$t->eq( 'билети = allowance', (int) $m['tickets']['allowance'], (int) $row['tickets'] );
		$t->ok( 'кодът е валиден', Tickets::normalize( $row['code'] ) === $row['code'] );
		$o = wc_get_order( $oid ); $t->ok( 'order meta _ansa_promo_ticket_code/qr_url', $o->get_meta( '_ansa_promo_ticket_code' ) === $row['code'] && '' !== $o->get_meta( '_ansa_promo_qr_url' ), (string) $o->get_meta( '_ansa_promo_qr_url' ) );
		$t->ok( 'ваучер при запазен сет', null === $box['cosm_pay'] || '' !== (string) $o->get_meta( '_ansa_promo_voucher' ), (string) $o->get_meta( '_ansa_promo_voucher' ) );
		$o->update_status( 'processing', 'probe пак' ); $t->ok( 'повторно processing → същият билет', Tickets::for_order( $oid )['id'] === $row['id'] && 1 === (int) count( array_filter( (array) \wc_get_order_notes( array( 'order_id' => $oid ) ), function ( $n ) { return false !== strpos( $n->content, 'издаден билет' ); } ) ) );
		/* REST */
		$req = new \WP_REST_Request( 'GET', '/' . Tickets::NS . '/ticket/' . Tickets::pretty( $row['code'] ) ); $res = rest_do_request( $req ); $d = $res->get_data();
		$t->ok( 'GET ticket/{code} → valid', 200 === $res->get_status() && ! empty( $d['valid'] ) && (int) $d['count'] === (int) $row['tickets'] && empty( $d['activated'] ), wp_json_encode( $d, JSON_UNESCAPED_UNICODE ) );
		$req = new \WP_REST_Request( 'GET', '/' . Tickets::NS . '/ticket/' . $bad ); $res = rest_do_request( $req ); $t->eq( 'GET невалиден код → 404', 404, $res->get_status() );
		$req = new \WP_REST_Request( 'POST', '/' . Tickets::NS . '/ticket/' . $row['code'] . '/activate' ); $req->set_param( 'email', 'portal@example.com' ); $res = rest_do_request( $req ); $d = $res->get_data();
		$t->ok( 'POST activate → activated', 200 === $res->get_status() && ! empty( $d['activated'] ) && empty( $d['already'] ), wp_json_encode( $d, JSON_UNESCAPED_UNICODE ) );
		$res = rest_do_request( $req ); $d = $res->get_data(); $t->ok( 'POST activate пак → already', 200 === $res->get_status() && ! empty( $d['already'] ) );
		$t->eq( 'редът е activated', 'activated', Tickets::for_order( $oid )['status'] );
		$req = new \WP_REST_Request( 'GET', '/' . Tickets::NS . '/order/' . $oid . '/qr.svg' ); $req->set_param( 'key', 'wrong' ); $res = rest_do_request( $req ); $t->ok( 'QR с грешен key → 401/403', in_array( $res->get_status(), array( 401, 403 ), true ), (string) $res->get_status() );
		$t->ok( 'QR auth с подписан key', true === Tickets::qr_auth( ( function () use ( $oid ) { $q = new \WP_REST_Request( 'GET', '/' ); $q['id'] = $oid; $q->set_param( 'key', Tickets::qr_key( $oid ) ); return $q; } )() ) );
		/* completed → имейли */
		$o = wc_get_order( $oid ); $o->update_status( 'completed', 'probe' ); $o = wc_get_order( $oid );
		$subjects = array_map( function ( $x ) { return $x['subject']; }, $mails );
		if ( null !== $box['cosm_pay'] ) { $t->ok( 'completed → ваучер по имейл', 'voucher_sent' === $o->get_meta( '_ansa_promo_cosm_status' ) && (bool) array_filter( $subjects, function ( $s ) { return false !== mb_stripos( $s, 'ваучер' ); } ), 'cosm_status=' . $o->get_meta( '_ansa_promo_cosm_status' ) . ' · имейли: ' . implode( ' | ', $subjects ) ); }
		else { $t->note( 'completed → ваучер', 'кутията няма сет' ); }
		$t->note( 'completed → книга', (string) $o->get_meta( '_ansa_promo_book_sent' ) ?: ( ! empty( $m['book'] ) ? 'не е изпратена (няма rewards.book.url и продуктът не е downloadable)' : 'кутията няма книга' ) );
		$t->note( 'имейли общо (WC + промо)', count( $mails ) . ': ' . implode( ' | ', $subjects ) );
		/* cancel → void → processing → issued */
		$o->update_status( 'cancelled', 'probe' ); $t->eq( 'cancelled → void', 'void', Tickets::for_order( $oid )['status'] );
		$req = new \WP_REST_Request( 'GET', '/' . Tickets::NS . '/ticket/' . $row['code'] ); $d = rest_do_request( $req )->get_data(); $t->ok( 'GET void → valid=false', empty( $d['valid'] ) && 'void' === ( $d['status'] ?? '' ) );
		$o = wc_get_order( $oid ); $o->update_status( 'processing', 'probe' ); $t->eq( 'пак processing → issued', 'issued', Tickets::for_order( $oid )['status'] );
		remove_filter( 'pre_wp_mail', $hook, 10 );
		/* стикерът */
		$t->ok( 'данни за стикера', ! empty( Tickets::ticket_url( $row['code'] ) ) && (bool) Tickets::for_order( $oid ) );
		if ( ! $keep ) { $o = wc_get_order( $oid ); $o->delete( true ); $t->ok( 'изтриване на поръчката трие билета', ! Tickets::for_order( $oid ) ); $t->note( 'тестова поръчка', '#' . $oid . ' изтрита (?keep=1 я оставя)' ); } else { $t->note( 'тестова поръчка (оставена)', '#' . $oid ); }
		return array( 'order' => $oid, 'code' => Tickets::pretty( $row['code'] ), 'mails' => $mails );
	}

	/* ── insights + partner: Фаза 5 — beacon → rollup, отчет; партньорски купон в промо количка: минава, отстъпка 0, +бонус билети. ?keep=1 */
	public static function suite_insights( $t, $params ) {
		global $wpdb;
		$t->ok( 'таблица stats', Schema::exists( Schema::table( 'stats' ) ), Schema::table( 'stats' ) . ' · DB ver ' . get_option( Schema::OPT_DB_VER ) );
		$before = Insights::report( 1 )['by_step'];
		Insights::hit( 'view', 'l', 'probe' ); Insights::hit( 'view', 'l', 'probe' ); Insights::hit( 'full', 'l', 'probe' );
		$after = Insights::report( 1 )['by_step'];
		$t->ok( 'hit() брои (view +2, full +1)', $after['view'] - $before['view'] === 2 && $after['full'] - $before['full'] === 1, 'view ' . $before['view'] . '→' . $after['view'] . ' · full ' . $before['full'] . '→' . $after['full'] );
		$rep = Insights::report( 30 ); $t->ok( 'отчетът по кутия/UTM', isset( $rep['by_box']['l'] ) && isset( $rep['by_utm']['probe'] ), wp_json_encode( $rep['by_utm']['probe'] ?? null ) );
		$t->ok( 'грешна стъпка не се брои', false === Insights::hit( 'hack', 'l', 'x' ) );
		/* beacon през ajax като клиент */
		$r = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 20, 'sslverify' => false, 'body' => array( 'action' => 'ansa_promo_beacon', 'nonce' => wp_create_nonce( Frontend::NONCE ), 'step' => 'box', 'box' => 'm', 'utm' => 'probe' ) ) );
		$j = is_wp_error( $r ) ? null : json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$t->ok( 'ajax ansa_promo_beacon', is_array( $j ) && ! empty( $j['success'] ), is_wp_error( $r ) ? $r->get_error_message() : (string) wp_remote_retrieve_body( $r ) );
		$r = wp_remote_post( admin_url( 'admin-ajax.php' ), array( 'timeout' => 20, 'sslverify' => false, 'body' => array( 'action' => 'ansa_promo_beacon', 'nonce' => 'bad', 'step' => 'box' ) ) );
		$t->eq( 'beacon без nonce → 403', 403, (int) wp_remote_retrieve_response_code( $r ) );
		/* чистим пробните редове */
		$wpdb->delete( Schema::table( 'stats' ), array( 'utm' => 'probe' ), array( '%s' ) );
		/* ── партньорски купон ── */
		$t->note( 'ansa Partner', Partner::available() ? 'има (AP_Coupons) · бонус ' . (int) Config::get_published()['tickets']['partner_bonus'] : 'няма — мостът се проверява със симулиран партньорски код' );
		if ( ! function_exists( 'WC' ) ) { return null; }
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) { wc_load_cart(); }
		if ( ! WC()->cart ) { $t->ok( 'WC количка', false ); return null; }
		$pub = Config::get_published(); $draft = ! Config::is_live( $pub ); $cfg = $draft ? Config::get_draft() : $pub; $keep = ! empty( $params['keep'] );
		$live = Frontend::products( $cfg ); $ok_keys = array(); foreach ( $live as $k => $p ) { if ( $p['stock'] ) { $ok_keys[] = $k; } }
		$box = Config::box( $cfg, 'l' ) ?: $cfg['boxes'][0]; $n = (int) $box['packs']; if ( count( $ok_keys ) < 1 ) { $t->ok( 'налични продукти', false ); return null; }
		$pick = array(); for ( $i = 0; $i < $n; $i++ ) { $pick[] = $ok_keys[ $i % count( $ok_keys ) ]; }
		/* ?pcode=КОД → истински партньорски код (AP_Coupons mapping); иначе временен купон, обявен за партньорски през filter-а */
		$pcode = sanitize_text_field( (string) ( $params['pcode'] ?? '' ) ); $cid = 0;
		if ( '' !== $pcode ) { $code = $pcode; $sim = '__return_false'; $t->ok( 'истински партньорски код', Partner::is_partner_code( $code ), $code . ( Partner::is_partner_code( $code ) ? ' · AP mapping има' : ' · НЕ е в AP mapping' ) ); }
		else {
			$code = 'probe-partner-' . wp_generate_password( 5, false, false ); $cid = wp_insert_post( array( 'post_title' => $code, 'post_type' => 'shop_coupon', 'post_status' => 'publish' ) );
			update_post_meta( $cid, 'discount_type', 'percent' ); update_post_meta( $cid, 'coupon_amount', '5' ); update_post_meta( $cid, 'free_shipping', 'yes' );
			$sim = function ( $is, $c ) use ( $code ) { return $is || wc_format_coupon_code( $c ) === wc_format_coupon_code( $code ); }; add_filter( 'ansa_promo_is_partner_coupon', $sim, 10, 2 );
		}
		WC()->customer->set_billing_country( 'BG' ); WC()->customer->set_shipping_country( 'BG' ); WC()->customer->set_shipping_postcode( '1000' );
		$r = Cart::perform_add( array( 'trusted' => true, 'draft' => $draft, 'box' => $box['id'], 'items' => array_map( function ( $k ) use ( $live ) { return (int) $live[ $k ]['id']; }, $pick ), 'pay' => 'cod', 'email' => 'probe@example.com' ) );
		if ( ! $t->ok( 'add ' . $box['name'], ! empty( $r['ok'] ), $r['msg'] ?? '' ) ) { remove_filter( 'ansa_promo_is_partner_coupon', $sim, 10 ); if ( $cid ) { wp_delete_post( $cid, true ); } return null; }
		WC()->cart->calculate_totals(); $tot0 = (float) WC()->cart->get_total( 'edit' );
		wc_clear_notices(); $applied = WC()->cart->apply_coupon( $code ); $errs = wp_list_pluck( wc_get_notices( 'error' ), 'notice' ); wc_clear_notices();
		$t->ok( 'партньорският код минава', $applied && in_array( wc_format_coupon_code( $code ), WC()->cart->get_applied_coupons(), true ), implode( ' | ', array_map( 'wp_strip_all_tags', $errs ) ) );
		WC()->cart->calculate_totals(); $tot1 = (float) WC()->cart->get_total( 'edit' );
		$t->ok( 'отстъпката му е 0 (не се стаква)', abs( $tot1 - $tot0 ) < 0.011 && (float) WC()->cart->get_discount_total() < 0.011, 'total ' . $tot0 . ' → ' . $tot1 . ' · discount ' . WC()->cart->get_discount_total() );
		$packages = WC()->cart->get_shipping_packages(); $rates = array(); if ( $packages ) { $calc = WC()->shipping()->calculate_shipping( $packages ); foreach ( (array) ( $calc[0]['rates'] ?? array() ) as $rid => $rate ) { $rates[ $rid ] = (float) $rate->get_cost(); } }
		$free = in_array( 'ship', $box['rw'], true ); if ( $rates ) { $t->ok( 'доставката остава по кутията (free_shipping на купона не важи)', $free ? max( $rates ) <= 0.0 : min( $rates ) > 0.0, wp_json_encode( $rates ) ); }
		$meta = Cart::build_meta( true ); $bonus = (int) $cfg['tickets']['partner_bonus'];
		$t->ok( 'order meta: tickets.partner + bonus', ! empty( $meta['tickets']['partner'] ) && (int) ( $meta['tickets']['bonus'] ?? -1 ) === $bonus && ( $meta['tickets']['partner_code'] ?? '' ) === wc_format_coupon_code( $code ), wp_json_encode( $meta['tickets'] ) );
		if ( '' !== $pcode ) { $co = self::fetch_as_session( Cart::checkout_url() ); $t->ok( 'чекаутът показва реда за партньора', $bonus <= 0 || ( 200 === $co['code'] && false !== strpos( $co['body'], '🤝' ) ), 'HTTP ' . $co['code'] . ( $bonus <= 0 ? ' · бонусът е 0' : '' ) ); }
		else { $t->note( 'чекаутът с реда за партньора', 'само с истински код (?pcode=КОД) — симулираният живее само в тази заявка' ); }
		$oid = self::make_order( $t, '🤝 ' . $box['name'], $box, $cfg ); WC()->cart->empty_cart(); Cart::clear_ctx();
		if ( $oid ) {
			$o = wc_get_order( $oid ); $t->ok( 'поръчката носи купона', in_array( wc_format_coupon_code( $code ), $o->get_coupon_codes(), true ), implode( ',', $o->get_coupon_codes() ) );
			$mails = function ( $pre, $atts ) { return true; }; add_filter( 'pre_wp_mail', $mails, 10, 2 );
			$o->update_status( 'processing', 'probe' ); $row = Tickets::for_order( $oid );
			$t->ok( 'билети = allowance + бонус', $row && (int) $row['tickets'] === (int) $box['tickets'] + $bonus, $row ? $row['tickets'] . ' = ' . $box['tickets'] . ' + ' . $bonus : 'няма билет' );
			remove_filter( 'pre_wp_mail', $mails, 10 );
			if ( $draft ) { $t->note( 'Insights: created+paid', 'поръчка от чернова — не се брои (по дизайн)' ); } else { $t->ok( 'Insights: created+paid за кутията', ( Insights::report( 1 )['by_box'][ $box['id'] ]['paid'] ?? 0 ) >= 1 ); }
			if ( ! $keep ) { $o = wc_get_order( $oid ); $o->delete( true ); $t->note( 'тестова поръчка', '#' . $oid . ' изтрита' ); } else { $t->note( 'тестова поръчка (оставена)', '#' . $oid ); }
		}
		remove_filter( 'ansa_promo_is_partner_coupon', $sim, 10 ); if ( $cid ) { wp_delete_post( $cid, true ); }
		return array( 'order' => $oid ?? 0 );
	}
}
