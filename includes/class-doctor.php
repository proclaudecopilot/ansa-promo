<?php
/**
 * ansa™ Промо — Doctor.
 *
 * Фаза 0: env() — състоянието на средата (същите редове печата и snippets/phase-0.php,
 * който обаче е самостоятелен, за да работи и ако плъгинът не се зареди).
 * Фаза 2 добавя checks() — publish gate-а (§7 от плана).
 * @since 1.0.2 env() знае за чернова/продукти/регистър/leads.
 *
 * @package AnsaPromo
 * @since 1.0.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Doctor {

	/** Списък от [label, value, level ok|warn|bad|info]. */
	public static function env() {
		global $wpdb, $wp_version;
		$rows = array();
		$add  = function ( $label, $value, $level = 'info' ) use ( &$rows ) { $rows[] = array( 'label' => $label, 'value' => (string) $value, 'level' => $level ); };

		$add( 'ansa™ Промо', ANSA_PROMO_VER . ' · ANSA_PROMO_PLUGIN_VER=' . ( defined( 'ANSA_PROMO_PLUGIN_VER' ) ? ANSA_PROMO_PLUGIN_VER : '—' ), 'ok' );
		$add( 'WordPress / PHP', $wp_version . ' / ' . PHP_VERSION, 'info' );
		$add( 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : 'липсва', defined( 'WC_VERSION' ) ? 'ok' : 'bad' );

		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$add( 'HPOS', $hpos ? 'включен (custom order tables)' : 'изключен (posts)', 'info' );

		$sv = Plugin::shrine_version();
		if ( '' === $sv ) {
			$add( 'ansa™ Shrine', 'не е активен', 'warn' );
		} else {
			$loaded = Plugin::shrine_promo_module_loaded();
			$add( 'ansa™ Shrine', $sv . ( version_compare( $sv, '6.19.22', '>=' ) ? ' (има guard)' : ' (БЕЗ guard — трябва ≥ 6.19.22)' ), version_compare( $sv, '6.19.22', '>=' ) ? 'ok' : 'bad' );
			$add( 'Промо модулът на Shrine', $loaded ? 'ЗАРЕДЕН — конфликт' : 'не е зареден (guard-ът работи)', $loaded ? 'bad' : 'ok' );
		}

		$owner = Plugin::shortcode_owner();
		$add( 'Shortcode [ansa_promo]', $owner ?: 'не е регистриран', ( 0 === strpos( $owner, 'AnsaPromo\\' ) ) ? 'ok' : 'bad' );

		foreach ( Schema::status() as $t => $ok ) { $add( 'Таблица ' . $t, $ok ? 'има' : 'ЛИПСВА', $ok ? 'ok' : 'bad' ); }
		$add( 'DB схема', (string) get_option( Schema::OPT_DB_VER, '—' ) . ' (очаквана ' . ANSA_PROMO_DB_VER . ')', 'info' );

		$store = get_option( Plugin::OPTION );
		$add( 'Option ' . Plugin::OPTION, is_array( $store ) ? 'има · ключове: ' . implode( ', ', array_keys( $store ) ) . ' · version=' . (int) Config::version() . ' · history=' . count( Config::history() ) : 'няма (seed-ва се при първо зареждане)', is_array( $store ) ? 'ok' : 'warn' );
		$draft = Config::get_draft(); $live = Frontend::products( $draft );
		$ig = Insights::diagnostics(); $add( 'Insights 2 (1.17.0)', ( $ig['tables'] ? 'таблици ок' : 'ТАБЛИЦИТЕ ЛИПСВАТ' ) . ' · ' . (int) $ig['sessions'] . ' сесии · ' . (int) $ig['events'] . ' събития · последно ' . ( $ig['last_event'] ?: 'нищо' ) . ( $ig['live'] ? '' : ' · играта не е на живо — не се брои' ), $ig['tables'] && $ig['events'] ? 'ok' : ( $ig['tables'] ? 'warn' : 'bad' ) );
		$add( 'Продукти в черновата', count( $draft['products'] ) . ' · с WC продукт: ' . count( array_filter( $draft['products'], function ( $p ) { return $p['product_id'] > 0; } ) ) . ' · живи (цена>0): ' . count( $live ) . ( $draft['page_id'] ? ' · page_id=' . $draft['page_id'] : ' · БЕЗ страница' ), count( $live ) ? 'ok' : 'warn' );
		$sw = Config::week_for( $pubc0 = Config::get_published(), time() ); $add( 'Седмици на сезона (1.19.0)', $sw ? 'седмица ' . $sw['i'] . '/' . $sw['total'] . ' · −' . implode( ' / −', array_map( array( 'AnsaPromo\\Cart', 'fmt' ), $sw['pct'] ) ) . '% · ' . ( $sw['next'] ? 'смяна ' . wp_date( 'd.m H:i', (int) $sw['next']['from'] ) : 'последна' ) . ' · гратис ' . (int) $pubc0['season']['grace_hours'] . ' ч. · cron: ' . ( wp_next_scheduled( Config::FLIP_HOOK ) ? wp_date( 'd.m H:i', wp_next_scheduled( Config::FLIP_HOOK ) ) : 'не е планиран' ) : 'изключени (процентите на кутиите важат целия сезон)', $sw ? 'ok' : 'info' );
		$add( 'Копи-регистър', count( Copy::defaults() ) . ' ключа · override-и в черновата: ' . count( $draft['copy'] ), 'info' );
		$add( 'Leads', Leads::count() . ' реда', 'info' );
		$st = Insights::report( 30 )['by_step']; $add( 'Insights (30 дни)', 'view ' . (int) $st['view'] . ' · box ' . (int) $st['box'] . ' · full ' . (int) $st['full'] . ' · add ' . (int) $st['add'] . ' · created ' . (int) $st['created'] . ' · paid ' . (int) $st['paid'] . ' · ansa Partner: ' . ( Partner::available() ? 'има (мостът е активен)' : 'няма' ), 'info' );
		$add( 'Билети', Tickets::count( 'issued' ) . ' издадени (' . Tickets::sum( 'issued' ) . ' бр.) · ' . Tickets::count( 'activated' ) . ' активирани · ' . Tickets::count( 'void' ) . ' анулирани · портал: ' . ( $draft['tickets']['portal_url'] ?: 'няма (QR → промо страницата)' ), 'info' );

		$pubc = Config::get_published(); $add( 'Томбола (1.6.0)', ( Tombola::enabled( $pubc ) ? 'включена · ' . Tombola::url( $pubc ) : 'изключена' ) . ' · теглене ' . Tombola::draw_label( $pubc ) . ' · статуси: ' . implode( ', ', (array) $pubc['tombola']['statuses'] ) . ' · препоръки: ' . Tombola::count_referrals( 'credited' ) . ' броени / ' . Tombola::count_referrals() . ' общо', Tombola::enabled( $pubc ) ? 'ok' : 'warn' );
		$bi = Book::file_info( $pubc ); $add( 'Книга /kniga (1.9.0)', ( Book::enabled( $pubc ) ? 'включена · ' . Book::url( $pubc ) : 'изключена' ) . ' · PDF: ' . ( 'file' === $bi['source'] ? $bi['name'] . ' (' . $bi['size'] . ')' : ( 'url' === $bi['source'] ? $bi['url'] : 'НЯМА' ) ) . ' · получена = ' . implode( ', ', (array) $pubc['tombola']['statuses'] ) . ' · помни ' . (int) $pubc['book']['remember'] . ' дни · демо: ' . ( $pubc['demo']['phone'] && $pubc['demo']['email'] ? $pubc['demo']['phone'] . ' / ' . $pubc['demo']['email'] : 'изключено' ), Book::enabled( $pubc ) && '' === $bi['source'] ? 'warn' : ( Book::enabled( $pubc ) ? 'ok' : 'info' ) );
		$add( 'proclaudecopilot GitHub Updater', defined( 'PCC_GH_UPDATER_VERSION' ) ? PCC_GH_UPDATER_VERSION . ( class_exists( 'PCC_GitHub_Updater' ) && \PCC_GitHub_Updater::token() ? ' · token има' : ' · БЕЗ token' ) : 'не е зареден (mu-plugin)', defined( 'PCC_GH_UPDATER_VERSION' ) ? 'ok' : 'warn' );

		$pages = self::pages_with_shortcode();
		$add( 'Страници с [ansa_promo]', $pages ? implode( '; ', $pages ) : 'няма', $pages ? 'ok' : 'warn' );

		Merge::ensure_tag(); /* 1.8.3: тагът да съществува още преди първата поръчка */
		$add( 'FunnelKit', ( class_exists( 'WFACP_Core' ) ? 'Checkout ' . ( defined( 'WFACP_VERSION' ) ? WFACP_VERSION : '' ) : 'Checkout липсва' ) . ' · ' . ( class_exists( 'WFFN_Core' ) ? 'Funnel Builder ' . ( defined( 'WFFN_VERSION' ) ? WFFN_VERSION : '' ) : 'Funnel Builder липсва' ) . ' · ' . ( defined( 'BWFAN_VERSION' ) ? 'Automations ' . BWFAN_VERSION . ' (merge tags: ' . Merge::fka_status() . ' · ' . Merge::tag_status( $draft ) . ')' : 'Automations липсва' ), 'info' );
		Lead_Sync::ensure_terms(); /* 1.11.0: листът и тагът на лийдовете да съществуват преди първия лийд */
		$add( 'FKA лийдове (1.11.0)', Lead_Sync::status( $draft ), Lead_Sync::enabled( $draft ) ? ( Lead_Sync::available() ? 'ok' : 'warn' ) : 'info' );
		$lps = Link_Proxy::status(); $add( 'SendGrid линкове (1.13.1)', $lps[0], $lps[1] );
		$add( 'WC checkout page', (int) wc_get_page_id( 'checkout' ) . ' · ' . wc_get_checkout_url(), 'info' );

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$g = array();
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) { $g[] = $id . ( 'yes' === $gw->enabled ? ' ✓' : ' ✗' ); }
			$add( 'Gateways', implode( ', ', $g ), 'info' );
		}

		$cache = array();
		if ( class_exists( '\\FlyingPress\\Purge' ) ) $cache[] = 'FlyingPress';
		if ( defined( 'WP_ROCKET_VERSION' ) ) $cache[] = 'WP Rocket';
		if ( defined( 'LSCWP_V' ) ) $cache[] = 'LiteSpeed';
		if ( wp_using_ext_object_cache() ) $cache[] = 'object cache';
		$add( 'Кеш', $cache ? implode( ', ', $cache ) : 'не е засечен', 'info' );

		return $rows;
	}

	/* ── Publish gate (план §7): [{id, level ok|warn|bad|info, msg}] — bad спира публикуването ── */
	public static function checks( $cfg = null ) {
		$cfg = $cfg ?: Config::get_draft(); $out = array();
		$c = function ( $id, $level, $msg ) use ( &$out ) { $out[] = array( 'id' => $id, 'level' => $level, 'msg' => $msg ); };
		/* 1. страница */
		$pid = (int) $cfg['page_id']; $post = $pid ? get_post( $pid ) : null;
		if ( ! $post ) { $c( 'page', 'bad', 'Няма избрана страница с [ansa_promo] (🧰 Инструменти).' ); }
		else { $has = has_shortcode( (string) $post->post_content, Plugin::SHORTCODE ) || false !== strpos( (string) get_post_meta( $pid, '_elementor_data', true ), '[' . Plugin::SHORTCODE ); $c( 'page', $has ? 'ok' : 'bad', 'Страница #' . $pid . ' „' . get_the_title( $pid ) . '“ (' . get_post_status( $pid ) . ')' . ( $has ? ' съдържа [ansa_promo]' : ' НЕ съдържа [ansa_promo]' ) ); }
		/* 2. продукти */
		$live = Frontend::products( $cfg ); $enabled = array_filter( $cfg['products'], function ( $p ) { return ! empty( $p['enabled'] ); } );
		$c( 'products', count( $live ) >= 1 ? 'ok' : 'bad', count( $live ) . ' продукта с WC ред и цена > 0 от ' . count( $enabled ) . ' включени' . ( count( $live ) < count( $enabled ) ? ' — ' . implode( ', ', array_diff( array_column( $enabled, 'key' ), array_keys( $live ) ) ) . ' нямат WC продукт/цена (изключени от страницата)' : '' ) );
		$noimg = array(); $oos = array(); foreach ( $live as $k => $p ) { if ( empty( $p['img'] ) ) { $noimg[] = $k; } if ( empty( $p['stock'] ) ) { $oos[] = $k; } }
		if ( $noimg ) { $c( 'images', 'warn', 'Без снимка: ' . implode( ', ', $noimg ) ); } if ( $oos ) { $c( 'stock', 'warn', 'Изчерпани в WC: ' . implode( ', ', $oos ) ); }
		/* 3. кутии */
		$rw_ok = array( 'tix', 'tix3', 'tix5', 'ship', 'book', 'cosm50', 'cosm1' );
		foreach ( $cfg['boxes'] as $b ) {
			$e = array(); if ( (int) $b['packs'] < 1 ) { $e[] = 'packs < 1'; } if ( (float) $b['pct'] <= 0 || (float) $b['pct'] >= 100 ) { $e[] = 'pct извън (0,100)'; } foreach ( $b['rw'] as $r ) { if ( ! in_array( $r, $rw_ok, true ) ) { $e[] = 'непозната награда ' . $r; } } if ( ( in_array( 'cosm1', $b['rw'], true ) || in_array( 'cosm50', $b['rw'], true ) ) && null === $b['cosm_pay'] ) { $e[] = 'има сет без cosm_pay'; }
			$c( 'box_' . $b['id'], $e ? 'bad' : 'ok', $b['ic'] . ' ' . $b['name'] . ': ' . $b['packs'] . ' опак.' . ( ! empty( $b['grow'] ) ? '+ (расте, +' . (int) $b['tix_extra'] . ' билет/опаковка)' : '' ) . ' · −' . $b['pct'] . '% · ' . $b['tickets'] . ' билета · ' . implode( '+', $b['rw'] ) . ( $e ? ' — ' . implode( '; ', $e ) : '' ) );
		}
		/* 3а. v1.19.0: седмиците на сезона — дупки, обхват, посока (стълбата пада), текстовете за фиксирана отстъпка */
		if ( Config::weekly_on( $cfg ) ) {
			$rows = Config::season_weeks( $cfg ); $n = count( $rows ); $set = count( array_filter( $rows, function ( $r ) { return ! empty( $r['set'] ); } ) ); $e = array(); $wn = array();
			if ( Config::deadline_ts( $cfg ) && Config::season_start_ts( $cfg ) >= Config::deadline_ts( $cfg ) ) { $e[] = 'стартът е след края'; }
			if ( $set < $n ) { $wn[] = ( $n - $set ) . ' седмици без въведени проценти (важи процентът на кутията)'; }
			$prev = null; foreach ( $rows as $r ) { foreach ( $r['pct'] as $bid => $v ) { if ( $v <= 0 || $v >= 100 ) { $e[] = 'седм. ' . $r['i'] . ' ' . $bid . ': ' . $v; } if ( $prev && $v > $prev[ $bid ] + 0.001 ) { $wn[] = 'седм. ' . $r['i'] . ': ' . $bid . ' качва (−' . $v . '% след −' . $prev[ $bid ] . '%)'; } } $prev = $r['pct']; }
			$w = Config::week_for( $cfg, time() );
			$c( 'season', $e ? 'bad' : ( $wn ? 'warn' : 'ok' ), 'Седмици на сезона: ' . $n . ' от ' . $cfg['season']['start'] . ' до ' . ( $cfg['deadline'] ?: '—' ) . ( $w ? ' · сега седмица ' . $w['i'] . ' (−' . implode( ' / −', array_map( array( 'AnsaPromo\\Cart', 'fmt' ), $w['pct'] ) ) . '%)' . ( $w['next'] ? ' · смяна ' . wp_date( 'd.m H:i', (int) $w['next']['from'] ) : ' · последна' ) : '' ) . ' · гратис ' . (int) $cfg['season']['grace_hours'] . ' ч.' . ( $e || $wn ? ' — ' . implode( '; ', array_merge( $e, array_slice( $wn, 0, 6 ) ) ) : '' ) );
			foreach ( array( 'how.faq.8.a', 'how.hero.note' ) as $k ) { if ( isset( $cfg['copy'][ $k ] ) && preg_match( '/остава до|за всички времена/u', (string) $cfg['copy'][ $k ] ) ) { $c( 'season_copy', 'warn', 'Текстът ' . $k . ' е редактиран и говори за фиксирана отстъпка — при падащи седмици провери го в ✏️ Редактор (група „Как работи“).' ); } }
		}
		/* 4–5. книга и сет */
		$needs_book = (bool) array_filter( $cfg['boxes'], function ( $b ) { return in_array( 'book', $b['rw'], true ); } ); $bp = ! empty( $cfg['rewards']['book']['product_id'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $cfg['rewards']['book']['product_id'] ) : null;
		if ( $needs_book ) { $c( 'book', $bp && $bp->is_purchasable() ? 'ok' : 'bad', $bp ? 'Книга: #' . $bp->get_id() . ' ' . $bp->get_name() . ( $bp->is_purchasable() ? '' : ' — НЕ е purchasable' ) . ( $bp->is_downloadable() ? ' · downloadable (WC праща файла)' : ( $cfg['rewards']['book']['url'] ? ' · PDF линк по имейл' : ' · без PDF линк (само физически подарък)' ) ) : 'Кутия има „книга“, но rewards.book.product_id не е избран (книгата няма да влезе в количката на €0).' ); }
		/* 1.9.0: „получена“ = tombola.statuses (само delivered) — всеки статус трябва да съществува в WC, иначе нищо не се отключва и ваучер/книга не тръгват */
		if ( function_exists( 'wc_get_order_statuses' ) ) { $wcst = array_map( function ( $s ) { return preg_replace( '/^wc-/', '', $s ); }, array_keys( wc_get_order_statuses() ) ); $miss = array_diff( (array) $cfg['tombola']['statuses'], $wcst ); $c( 'delivered', $miss ? 'bad' : ( in_array( 'completed', (array) $cfg['tombola']['statuses'], true ) ? 'warn' : 'ok' ), '„Получена“ поръчка (томбола · ваучер · книга) = ' . implode( ', ', (array) $cfg['tombola']['statuses'] ) . ( $miss ? ' — НЯМА такъв статус в WC: ' . implode( ', ', $miss ) . ' (налични: ' . implode( ', ', $wcst ) . ')' : '' ) . ( in_array( 'completed', (array) $cfg['tombola']['statuses'], true ) ? ' — „Изпълнена“ брои, а решението е само Delivered' : '' ) ); }
		if ( Book::enabled( $cfg ) && '' === Book::source( $cfg ) ) { $c( 'book_page', 'warn', 'Страницата /' . $cfg['book']['slug'] . ' е включена, но няма качен PDF/линк — клиентът ще види „книгата още не е качена“ (🧰 Инструменти → 📖 Книга).' ); }
		$needs_cosm = (bool) array_filter( $cfg['boxes'], function ( $b ) { return null !== $b['cosm_pay']; } ); $cp = ! empty( $cfg['rewards']['cosm']['product_id'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $cfg['rewards']['cosm']['product_id'] ) : null;
		if ( $needs_cosm ) { $c( 'cosm', 'info', $cp ? 'Сет: #' . $cp->get_id() . ' ' . $cp->get_name() . ' · видимост ' . $cp->get_catalog_visibility() . ' (ваучер по имейл при „Изпълнена“)' : 'Сетът няма WC продукт — не е нужен за ваучера (само за справка).' ); }
		/* 6. gateways */
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) { $gws = WC()->payment_gateways()->payment_gateways(); foreach ( array( 'card_gateway' => 'карта', 'cod_gateway' => 'наложен платеж' ) as $k => $l ) { $id = $cfg['checkout'][ $k ]; if ( 'cod_gateway' === $k && '' === $id ) { $c( $k, 'info', 'Наложен платеж е изключен в играта.' ); continue; } $ok = isset( $gws[ $id ] ) && 'yes' === $gws[ $id ]->enabled; $c( $k, $ok ? 'ok' : 'bad', 'Gateway за ' . $l . ': ' . $id . ( $ok ? ' ✓' : ( isset( $gws[ $id ] ) ? ' — ИЗКЛЮЧЕН в WooCommerce' : ' — НЯМА такъв gateway' ) ) ); } }
		/* 7. срок / включено */
		$ts = Config::deadline_ts( $cfg ); if ( $ts && $ts < time() ) { $c( 'deadline', 'warn', 'Срокът ' . $cfg['deadline'] . ' е минал — страницата показва „скоро“.' ); } elseif ( ! $ts ) { $c( 'deadline', 'warn', 'Няма срок (deadline) — таймерът и „само до …“ са празни.' ); }
		if ( empty( $cfg['enabled'] ) ) { $c( 'enabled', 'warn', 'Играта е изключена — публикуването записва текстовете, но публичната страница остава „скоро“' . ( ! empty( $cfg['preview']['enabled'] ) ? ' (прегледът с парола показва черновата)' : '' ) . '.' ); }
		/* 8. копи */
		$bad = array(); foreach ( $cfg['copy'] as $k => $v ) { foreach ( Copy::validate( $v ) as $e ) { $bad[] = $k . ': ' . $e; } } $c( 'copy', $bad ? 'bad' : 'ok', $bad ? implode( '; ', array_slice( $bad, 0, 8 ) ) : count( $cfg['copy'] ) . ' променени текста, всички валидни' );
		/* 9. Shrine */
		if ( Plugin::shrine_version() ) { $c( 'shrine', Plugin::shrine_promo_module_loaded() ? 'bad' : 'ok', 'ansa™ Shrine ' . Plugin::shrine_version() . ( Plugin::shrine_promo_module_loaded() ? ' — промо модулът му Е ЗАРЕДЕН (конфликт)' : ' — промо модулът му не е зареден' ) ); }
		/* 10. билети */
		$c( 'tickets', 'ok', 'Тайна за билетите: ' . ( ! empty( $cfg['tickets']['secret'] ) ? 'от конфига' : 'автоматична' ) . ' · портал: ' . ( $cfg['tickets']['portal_url'] ?: 'промо страницата (?ticket=)' ) );
		/* 11. проблеми и FIT */
		$keys = array_column( $cfg['products'], 'key' ); $pe = array(); foreach ( $cfg['problems'] as $x ) { if ( ! in_array( $x['key'], $keys, true ) || ! isset( $live[ $x['key'] ] ) ) { $pe[] = $x['t'] . ' → ' . $x['key']; } } if ( $pe ) { $c( 'problems', 'bad', 'Проблем сочи към продукт извън страницата: ' . implode( '; ', $pe ) ); }
		$fe = array(); foreach ( $cfg['fit'] as $k => $ks ) { foreach ( $ks as $x ) { if ( ! isset( $live[ $x ] ) ) { $fe[] = $k . '→' . $x; } } } if ( $fe ) { $c( 'fit', 'warn', 'FIT сочи към продукт извън страницата: ' . implode( ', ', $fe ) ); }
		/* 12. кеш */
		$cache = array(); if ( class_exists( '\\FlyingPress\\Purge' ) ) { $cache[] = 'FlyingPress'; } if ( defined( 'WP_ROCKET_VERSION' ) ) { $cache[] = 'WP Rocket'; } if ( defined( 'LSCWP_V' ) ) { $cache[] = 'LiteSpeed'; }
		if ( $cache ) { $c( 'cache', 'info', implode( ', ', $cache ) . ': изключи от минификация/отлагане promo.js и admin-ajax; страницата се purge-ва при публикуване, runtime JSON-ът се опреснява през ajax.' ); }
		return $out;
	}

	/** $structured=true → [{id,title,status,url}], иначе списък от редове за отчета. */
	public static function pages_with_shortcode( $structured = false ) {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('page','post') AND post_status IN ('publish','draft','private','pending') AND post_content LIKE '%[ansa_promo%' ORDER BY ID DESC LIMIT 10" );
		$out = array();
		foreach ( $ids as $id ) { $out[] = $structured ? array( 'id' => (int) $id, 'title' => get_the_title( $id ), 'status' => get_post_status( $id ), 'url' => get_permalink( $id ) ) : '#' . $id . ' „' . get_the_title( $id ) . '“ (' . get_post_status( $id ) . ') ' . get_permalink( $id ); }
		return $out;
	}
}
