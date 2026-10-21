<?php
/**
 * ansa™ Промо — конфигурация (чернова / публикувано / история).
 *
 * Един option `ansa_promo_store` = { draft, published, published_at, version, history[] }.
 * Черновата се пази при всеки запис; „Публикувай“ копира черновата, вдига version, добавя в history (последните 10).
 * Фронтът чете published; админ с ?ansa_promo=draft чете draft. Всичко минава през normalize() — UI-ят никога не дрейфва.
 *
 * @package AnsaPromo
 * @since 1.0.2
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class Config {

	const OPTION   = Plugin::OPTION;
	const HISTORY  = 10;
	private static $memory = null;

	public static function defaults() {
		return array(
			'id'        => 'orange_q4_2026',
			'name'      => 'Сезонът на ansa™',
			'enabled'   => false,
			'deadline'  => '2026-12-31 23:59',
			/* v1.19.0: седмиците на сезона — отстъпката на всяка кутия пада всяка седмица от start (петък 00:00 по часа на сайта) до deadline.
			   weeks[i] = { s, m, l, to } проценти за седмица i+1 (null = процентът на кутията); to (1.22.0) = последният ден на седмицата (Y-m-d,
			   включително) по избор — следващата започва на другия ден в 00:00; празно = 7 дни; grace_hours = колко часа цената от добавянето в количката
			   се пази след смяната на седмицата (човекът: 24). Override-ите по продукт (📦 Продукти) не се пипат от седмиците. weekly=false = както досега. */
			'season'    => array( 'weekly' => false, 'start' => '2026-10-23', 'grace_hours' => 24, 'weeks' => array() ),
			'page_id'   => 0,
			'utm_param' => 'utm_content',
			/* images: снимките на плочките в попъпа (4 награди + 3 кутии) — attachment id или URL; празно = примерната снимка от assets/img/gate/ */
			'gate'      => array( 'enabled' => true, 'required' => false, 'consent_default' => true, 'images' => self::gate_image_defaults() ),
			'timer'     => array( 'minutes' => 15, 'warn_under' => 3 ),
			'ship'      => array( 'paid' => 2.55 ),
			/* v1.10.0 (човекът): кутията не е с точен брой — packs на кутията е МИНИМУМ, добавяш и повече на същата отстъпка; броят сам
			   вдига кутията (малка → средна → голяма). max_packs = таванът на опаковките в една кутия (защита; ⚙️ Двигател → Играта). */
			'max_packs' => 20,
			/* продуктите в играта — key = UTM стойност; цената, наличността и (по подразбиране) снимката идват от WC при рендиране */
			'products'  => array(),
			/* FIT: key => [key, key, key] — препоръчани към основния */
			'fit'       => array(),
			/* 1.24.14: кампании по UTM — ключ (general / cosm / yacht / продукт) → gate: default|on|off (имейл-попъпът за този UTM), cosm_pop: попъпът
			   „Козметиката за €1“, box: '' = всички кутии (екранът с трите) или id на кутия, която се избира сама (направо пълненето) */
			'campaigns' => array(),
			/* 1.24.19: текстове по UTM — utm ключ → { copy ключ → текст }; override над общия copy само за този UTM (редакторът пише тук при избран UTM) */
			'copy_utm'  => array(),
			/* „С какво да ти помогнем?“ — проблем → продукт (по един продукт на проблем) */
			'problems'  => array(),
			'boxes'     => array(
				/* v1.0.13 (човекът, 06.10): Малка −15% · 1× яхта; Средна −30% · 3× яхта + книга + доставка (без сет); Голяма без промяна.
				   v1.7.1 (човекът, 14.10): Малката пак е −15% (1.0.30 я беше вдигнала на −20%). */
				/* v1.12.0 (човекът): grow = кутията приема и повече опаковки от packs (на същата отстъпка; таванът е max_packs) — само
				   Голямата засега („за 1 кутия и 3 кутии не прави функцията“); tix_extra = билети за всяка опаковка над packs (+1). */
				array( 'id' => 's', 'ic' => '📦', 'name' => 'Малка кутия',  'packs' => 1, 'pct' => 15, 'tickets' => 1, 'rw' => array( 'tix' ),                 'no' => array( 'book', 'ship', 'cosm1' ), 'tag' => '',                                 'cosm_pay' => null, 'grow' => false, 'tix_extra' => 0 ),
				array( 'id' => 'm', 'ic' => '🎁', 'name' => 'Средна кутия', 'packs' => 3, 'pct' => 30, 'tickets' => 3, 'rw' => array( 'tix3', 'book', 'ship' ), 'no' => array( 'cosm1' ),                 'tag' => 'Най-популярна',                       'cosm_pay' => null, 'grow' => false, 'tix_extra' => 0 ),
				array( 'id' => 'l', 'ic' => '👑', 'name' => 'Голяма кутия', 'packs' => 5, 'pct' => 40, 'tickets' => 5, 'rw' => array( 'tix5', 'cosm1', 'book', 'ship' ),  'no' => array(),                           'tag' => 'Най-изгодна · спестяваш най-много', 'cosm_pay' => 1, 'grow' => true, 'tix_extra' => 1 ),
			),
			'order'     => array( 'desktop' => array( 's', 'l', 'm' ), 'mobile' => array( 'l', 'm', 's' ) ),
			'rewards'   => array(
				'yacht' => array( 'value' => 8800 ),
				'book'  => array( 'product_id' => 0, 'value' => 19, 'url' => '' ), /* url: линк към PDF-а, ако WC продуктът не е downloadable (имейл при „Изпълнена“) */
				'cosm'  => array( 'product_id' => 0, 'value' => 129, 'limit' => 10000, 'bar_from' => '2026-10-23' ), /* limit: броят комплекти за €1 (v1.7.0, „Как работи“: {{cosm_limit}} + прогресът „Първите N са резервирани“) · bar_from (1.8.9, човекът): прогресът се показва чак от тази дата (YYYY-MM-DD, час на сайта); '' = винаги */
				'icons' => array( 'tix' => '🎟️', 'tix3' => '🎟️', 'tix5' => '🎟️', 'ship' => '🚚', 'book' => '📖', 'cosm50' => '🌸', 'cosm1' => '👑' ),
			),
			'secret'    => array( 'enabled' => false, 'pct' => 35, 'count' => 3 ),
			'checkout'  => array( 'card_gateway' => 'fkwcs_stripe', 'cod_gateway' => 'cod', 'block_coupons' => true, 'consent_default' => true ),
			'tickets'   => array( 'portal_url' => '', 'secret' => '', 'partner_bonus' => 1, 'email' => false, 'fka_tag' => 'Сезонът на Анса', 'fka_tag_box' => array( 's' => 'malka-kutiq', 'm' => 'sredna-kutiq', 'l' => 'golqma-kutiq' ) ), /* 1.8.8 fka_tag_box: таг по кутия */ /* 1.8.2 fka_tag: FunnelKit таг на контакта при промо поръчка; '' = без */ /* email: и имейл с кода при издаване (QR-ът е в пратката така или иначе) */
			/* v1.6.0: томболата — страницата {промо}/tombola (проверка по телефон + имейл), тегленето, „доставен“ статуси, препоръка (?r=КОД):
			   ref_mult = множител на билетите на препоръчалия при първа платена препоръка (всяка следваща добавя още толкова), ref_max = най-много
			   броени препоръки (0 = без лимит), ref_gift = подаръкът за новия клиент */
			/* v1.8.0: страницата „Как работи“ — {промо}/info, самостоятелна и индексируема (class-info.php) */
			'info'      => array( 'enabled' => true, 'slug' => 'info' ),
			'li'        => array( 'enabled' => true, 'slug' => 'kozmetika' ), /* 1.24.20: страницата „Комплектът за 1 €“ (listicle за рекламата) */
			/* v1.9.0: книгата с рецепти — страницата /kniga (class-book.php): телефон + имейл от поръчката → PDF. file = относителен път в
			   {uploads}/ansa-promo-book/ (качва се от 🧰 Инструменти), url = външен линк (алтернатива), remember = колко дни браузърът я помни
			   (подписана бисквитка); отключва се при „получена“ поръчка = tombola.statuses */
			'book'      => array( 'enabled' => true, 'slug' => 'kniga', 'file' => '', 'url' => '', 'remember' => 365 ), /* статусите са общите „получена“ — tombola.statuses */
			/* v1.9.0: демо данни — телефон + имейл, които отварят /kniga и формата на томболата без поръчка (за екипа/админа); празно = изключено */
			'demo'      => array( 'phone' => '0888000000', 'email' => 'demo@ansa.bg' ),
			/* v1.11.0: лийдовете от имейл-попъпа → FunnelKit Automations (Lead_Sync): контакт (и за гост), лист + таг → тригър за
			   автоматизация „не е завършила поръчката“. consent_only = само с отметнато съгласие; untag_on_order = при промо поръчка
			   тагът пада от контакта (за да спре/не тръгне автоматизацията), листът остава. Празно име = без лист/без таг. */
			'leads'     => array( 'fka' => true, 'list' => 'Сезонът на ansa — лийдове', 'tag' => 'sezon-lead', 'consent_only' => true, 'untag_on_order' => true ),
			'tombola'   => array( 'enabled' => true, 'slug' => 'tombola', 'draw' => '2027-01-25', 'statuses' => array( 'delivered' ), /* 1.9.0: САМО Delivered — „Изпълнена“ не брои (томбола · ваучер за сета · книга) */ 'ref_param' => 'r', 'ref_mult' => 2, 'ref_max' => 10, 'ref_gift' => 1 ),
			'bgn'       => array( 'show' => true, 'rate' => 1.95583 ),
			/* v1.0.37: преглед с парола — докато играта не е пусната, страницата показва форма за парола и след нея черновата (за екипа и избрани клиенти) */
			'preview'   => array( 'enabled' => true, 'password' => 'SD(A0%as09' ),
			/* v1.0.3: страницата на цял екран — темплейтът „ansa™ Промо — цял екран“ + скриване на елементи на темата по селектори */
			'theme'     => array( 'hide' => true, 'selectors' => self::default_hide_selectors() ),
			'copy'      => array(),
		);
	}

	/** v1.0.13: новата структура на наградите. Еднократно пренаписва кутиите s/m в черновата и публикуваното, ако още са със
	 *  старите дефолти (Малка −20% · [tix]; Средна −30% · [tix3, cosm50, book, ship]); ръчно променени кутии не се пипат. */
	public static function maybe_migrate() {
		if ( get_option( 'ansa_promo_rw_v2' ) ) { return; }
		$s = self::read_store(); $changed = false; $d = self::defaults();
		$old = array( 's' => array( 'pct' => 20.0, 'rw' => array( 'tix' ) ), 'm' => array( 'pct' => 30.0, 'rw' => array( 'tix3', 'cosm50', 'book', 'ship' ) ) );
		foreach ( array( 'draft', 'published' ) as $slot ) {
			if ( ! is_array( $s[ $slot ] ) || empty( $s[ $slot ]['boxes'] ) || ! is_array( $s[ $slot ]['boxes'] ) ) { continue; }
			foreach ( $s[ $slot ]['boxes'] as $i => $b ) {
				$id = is_array( $b ) ? (string) ( $b['id'] ?? '' ) : '';
				if ( ! isset( $old[ $id ] ) ) { continue; }
				if ( (float) ( $b['pct'] ?? 0 ) !== $old[ $id ]['pct'] || array_values( array_map( 'strval', (array) ( $b['rw'] ?? array() ) ) ) !== $old[ $id ]['rw'] ) { continue; }
				foreach ( $d['boxes'] as $db ) { if ( $db['id'] === $id ) { foreach ( array( 'pct', 'rw', 'no', 'cosm_pay' ) as $f ) { $s[ $slot ]['boxes'][ $i ][ $f ] = $db[ $f ]; } $changed = true; } }
			}
		}
		if ( $changed ) { self::write_store( $s ); Catalog::purge_runtime(); $pub = self::get_published(); if ( ! empty( $pub['page_id'] ) ) { self::purge_page( (int) $pub['page_id'] ); } }
		update_option( 'ansa_promo_rw_v2', $changed ? 'migrated' : 'noop', false );
	}

	/** v1.9.0: „получена“ поръчка е САМО Delivered (решение на човека: „Изпълнена“ не върши работа — само Delivered поръчки ползват
	 *  сет, книга, томбола). Еднократно: tombola.statuses, ако още е старият дефолт (completed + delivered), става [delivered] в черновата
	 *  и публикуваното; ръчно сменен списък не се пипа. */
	public static function maybe_migrate_delivered() {
		if ( get_option( 'ansa_promo_delivered_v1' ) ) { return; }
		$s = self::read_store(); $changed = false;
		foreach ( array( 'draft', 'published' ) as $slot ) {
			if ( ! is_array( $s[ $slot ] ) || empty( $s[ $slot ]['tombola'] ) || ! is_array( $s[ $slot ]['tombola'] ) ) { continue; }
			$st = array_values( array_map( 'strval', (array) ( $s[ $slot ]['tombola']['statuses'] ?? array() ) ) ); sort( $st );
			if ( array( 'completed', 'delivered' ) === $st ) { $s[ $slot ]['tombola']['statuses'] = array( 'delivered' ); $changed = true; }
		}
		if ( $changed ) { self::write_store( $s ); }
		update_option( 'ansa_promo_delivered_v1', $changed ? 'migrated' : 'noop', false );
	}

	/** v1.0.24: промото се казва „Сезонът на ansa™“ — старото име по подразбиране („ansa™ Розово Тримесечие“) се пренаписва еднократно
	 *  в черновата и публикуваното; ръчно сменено име не се пипа. */
	public static function maybe_migrate_name() {
		if ( get_option( 'ansa_promo_name_v2' ) ) { return; }
		$s = self::read_store(); $changed = false; $new = self::defaults()['name'];
		foreach ( array( 'draft', 'published' ) as $slot ) {
			if ( is_array( $s[ $slot ] ) && isset( $s[ $slot ]['name'] ) && 'ansa™ Розово Тримесечие' === (string) $s[ $slot ]['name'] ) { $s[ $slot ]['name'] = $new; $changed = true; }
		}
		if ( $changed ) { self::write_store( $s ); Catalog::purge_runtime(); $pub = self::get_published(); if ( ! empty( $pub['page_id'] ) ) { self::purge_page( (int) $pub['page_id'] ); } }
		update_option( 'ansa_promo_name_v2', $changed ? 'migrated' : 'noop', false );
	}

	/** v1.0.26: SKU-тата на шестте продукта (човекът ги даде) — еднократно в черновата и публикуваното, само където полето е празно. */
	public static function maybe_migrate_sku() {
		if ( get_option( 'ansa_promo_sku_v1' ) ) { return; }
		$map = array( 'greens' => 'dgr001', 'sakura' => 'SKST', 'berber' => 'BRBR', 'meno' => 'MENO', 'red' => 'prod002', 'thyro' => 'prod003' );
		$s = self::read_store(); $changed = false;
		foreach ( array( 'draft', 'published' ) as $slot ) {
			if ( ! is_array( $s[ $slot ] ) || empty( $s[ $slot ]['products'] ) || ! is_array( $s[ $slot ]['products'] ) ) { continue; }
			foreach ( $s[ $slot ]['products'] as $i => $p ) {
				$k = is_array( $p ) ? (string) ( $p['key'] ?? '' ) : '';
				if ( isset( $map[ $k ] ) && '' === trim( (string) ( $p['sku'] ?? '' ) ) ) { $s[ $slot ]['products'][ $i ]['sku'] = $map[ $k ]; $changed = true; }
			}
		}
		if ( $changed ) { self::write_store( $s ); Catalog::purge_runtime(); $pub = self::get_published(); if ( ! empty( $pub['page_id'] ) ) { self::purge_page( (int) $pub['page_id'] ); } }
		update_option( 'ansa_promo_sku_v1', $changed ? 'migrated' : 'noop', false );
	}

	/** v1.0.28: категориите на MenoStop и ThyroZen (човекът): „Менопауза и перименопауза“ / „Щитовидна жлеза и Хашимото“ —
	 *  еднократно, само ако още са със сийднатото „Хормони и менопауза“. */
	public static function maybe_migrate_cat() {
		if ( get_option( 'ansa_promo_cat_v1' ) ) { return; }
		$map = array( 'meno' => 'Менопауза и перименопауза', 'thyro' => 'Щитовидна жлеза и Хашимото' );
		$s = self::read_store(); $changed = false;
		foreach ( array( 'draft', 'published' ) as $slot ) {
			if ( ! is_array( $s[ $slot ] ) || empty( $s[ $slot ]['products'] ) || ! is_array( $s[ $slot ]['products'] ) ) { continue; }
			foreach ( $s[ $slot ]['products'] as $i => $p ) {
				$k = is_array( $p ) ? (string) ( $p['key'] ?? '' ) : '';
				if ( isset( $map[ $k ] ) && 'Хормони и менопауза' === trim( (string) ( $p['cat'] ?? '' ) ) ) { $s[ $slot ]['products'][ $i ]['cat'] = $map[ $k ]; $changed = true; }
			}
		}
		if ( $changed ) { self::write_store( $s ); Catalog::purge_runtime(); $pub = self::get_published(); if ( ! empty( $pub['page_id'] ) ) { self::purge_page( (int) $pub['page_id'] ); } }
		update_option( 'ansa_promo_cat_v1', $changed ? 'migrated' : 'noop', false );
	}

	/** v1.7.1: Малката е −15% (човекът, 14.10) — еднократно връща −20% от 1.0.30 на −15% в черновата и публикуваното; друга стойност
	 *  (ръчно сменена) не се пипа. Старата миграция 15→20 (option ansa_promo_pct_s20) е махната, за да не вдига новия дефолт. */
	public static function maybe_migrate_pct() {
		if ( get_option( 'ansa_promo_pct_s15' ) ) { return; }
		$s = self::read_store(); $changed = false;
		foreach ( array( 'draft', 'published' ) as $slot ) {
			if ( ! is_array( $s[ $slot ] ) || empty( $s[ $slot ]['boxes'] ) || ! is_array( $s[ $slot ]['boxes'] ) ) { continue; }
			foreach ( $s[ $slot ]['boxes'] as $i => $b ) {
				if ( is_array( $b ) && 's' === (string) ( $b['id'] ?? '' ) && 20.0 === (float) ( $b['pct'] ?? 0 ) ) { $s[ $slot ]['boxes'][ $i ]['pct'] = 15; $changed = true; }
			}
		}
		if ( $changed ) { self::write_store( $s ); Catalog::purge_runtime(); $pub = self::get_published(); if ( ! empty( $pub['page_id'] ) ) { self::purge_page( (int) $pub['page_id'] ); } }
		update_option( 'ansa_promo_pct_s15', $changed ? 'migrated' : 'noop', false );
	}

	/** v1.14.0 (човекът: „сега искаме да добавим йонибиотик“, WC ID 15836): еднократно свързва ключа `yoni` с продукта, ако редът е без SKU
	 *  и без валиден WC продукт (или сочи грешен — името не съдържа „yoni“, напр. сетът „Тайна оферта“ от търсенето по име), и го включва.
	 *  На init (след сийда), защото ползва WC. Ако #15836 не съществува на сайта, не пипа нищо (noop) — свързването става от 📦 Продукти. */
	const YONI_PID = 15836;
	public static function maybe_migrate_yoni() {
		if ( get_option( 'ansa_promo_yoni_v1' ) || ! function_exists( 'wc_get_product' ) ) { return; }
		$target = wc_get_product( self::YONI_PID );
		if ( ! $target ) { update_option( 'ansa_promo_yoni_v1', 'no-product', false ); return; }
		$s = self::read_store(); $changed = false;
		foreach ( array( 'draft', 'published' ) as $slot ) {
			if ( ! is_array( $s[ $slot ] ) || empty( $s[ $slot ]['products'] ) || ! is_array( $s[ $slot ]['products'] ) ) { continue; }
			foreach ( $s[ $slot ]['products'] as $i => $p ) {
				if ( ! is_array( $p ) || 'yoni' !== (string) ( $p['key'] ?? '' ) || '' !== trim( (string) ( $p['sku'] ?? '' ) ) ) { continue; }
				$pid = (int) ( $p['product_id'] ?? 0 ); $wc = $pid ? Catalog::wc_line( $pid ) : null;
				$fine = $wc && $wc['ok'] && false !== mb_stripos( $wc['base_name'] ?: $wc['wc_name'], 'yoni' );
				if ( $fine && ! empty( $p['enabled'] ) ) { continue; }
				if ( ! $fine ) { $s[ $slot ]['products'][ $i ]['product_id'] = self::YONI_PID; }
				$s[ $slot ]['products'][ $i ]['enabled'] = true; $changed = true;
			}
		}
		if ( $changed ) { self::write_store( $s ); Catalog::purge_runtime(); $pub = self::get_published(); if ( ! empty( $pub['page_id'] ) ) { self::purge_page( (int) $pub['page_id'] ); } }
		update_option( 'ansa_promo_yoni_v1', $changed ? 'migrated' : 'noop', false );
	}

	/** v1.0.38: „за какво е“ и описанието от сийда (мокъпа) се изчистват еднократно за продуктите с „текстове от WooCommerce“, за да ги попълва WC;
	 *  всичко, което админът е променил, остава. Имената (Sakura, Daily Greens, …) не се пипат. */
	public static function maybe_migrate_wctext() {
		if ( get_option( 'ansa_promo_wctext_v2' ) ) { return; }
		$seed = array(); foreach ( Seed::products() as $sp ) { $seed[ $sp['key'] ] = $sp; }
		$s = self::read_store(); $changed = false;
		foreach ( array( 'draft', 'published' ) as $slot ) {
			if ( ! is_array( $s[ $slot ] ) || empty( $s[ $slot ]['products'] ) || ! is_array( $s[ $slot ]['products'] ) ) { continue; }
			foreach ( $s[ $slot ]['products'] as $i => $p ) {
				$k = is_array( $p ) ? (string) ( $p['key'] ?? '' ) : '';
				if ( ! isset( $seed[ $k ] ) || ( isset( $p['wc_text'] ) && empty( $p['wc_text'] ) ) ) { continue; }
				foreach ( array( 'ds', 'desc' ) as $f ) { if ( isset( $p[ $f ] ) && (string) $p[ $f ] === (string) ( $seed[ $k ][ $f ] ?? '' ) && '' !== (string) $p[ $f ] ) { $s[ $slot ]['products'][ $i ][ $f ] = ''; $changed = true; } }
			}
		}
		if ( $changed ) { self::write_store( $s ); Catalog::purge_runtime(); $pub = self::get_published(); if ( ! empty( $pub['page_id'] ) ) { self::purge_page( (int) $pub['page_id'] ); } }
		update_option( 'ansa_promo_wctext_v2', $changed ? 'migrated' : 'noop', false );
	}

	/** Плочките със снимка (имейл-попъп + картите на кутиите): ключ → етикет за админа. */
	public static function gate_image_slots() {
		return array( 'yacht' => 'Почивка с яхта', 'cosm' => 'Козметичен сет', 'book' => 'Книга с рецепти', 'pct' => 'Отстъпка', 'ship' => 'Безплатна доставка', 'box_s' => 'Малка кутия', 'box_m' => 'Средна кутия', 'box_l' => 'Голяма кутия' );
	}
	public static function gate_image_defaults() { return array_fill_keys( array_keys( self::gate_image_slots() ), '' ); }

	/** Хедър/футър/плаващи бутони на OceanWP, Elementor, FunnelKit Cart, Claspo — скриват се на промо страницата. */
	public static function default_hide_selectors() {
		return "#site-header\n#site-header-sticky-wrapper\n.oceanwp-mobile-menu-icon\n#footer\n#site-footer\n.site-footer\n.page-header\nheader.elementor-location-header\nfooter.elementor-location-footer\n[data-elementor-type=\"header\"]\n[data-elementor-type=\"footer\"]\n.fkcart-floating-toggler\n#fkcart-floating-toggler\n.fkcart-toggler\n#scroll-top\n.claspo-widget\n#elementor-popup-modal";
	}

	public static function product_defaults() {
		return array( 'key' => '', 'product_id' => 0, 'ph' => '🌿', 'img' => 0, 'name' => '', 'gname' => '', 'gsub' => '', 'ds' => '', 'desc' => '', 'ing' => '', 'who' => '', 'rating' => '', 'reviews' => '', 'cat' => '', 'pack' => '', 'theme' => array( '#fff0f6', '#ffd1e3', '#8a1147' ), 'enabled' => true, 'wc_text' => true, 'sku' => '', 'pct' => array() ); /* v1.14.0 pct: box id => % (празно = процентът на кутията) */
	}

	public static function normalize( $data ) {
		$d = self::defaults();
		if ( ! is_array( $data ) ) { return $d; }
		$out = self::merge( $d, $data );
		$out['id']        = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $out['id'] ) ?: $d['id'];
		$out['name']      = sanitize_text_field( (string) $out['name'] );
		$out['enabled']   = ! empty( $out['enabled'] );
		$out['deadline']  = trim( (string) $out['deadline'] );
		$out['page_id']   = (int) $out['page_id'];
		$out['utm_param'] = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $out['utm_param'] ) ?: 'utm_content';
		$out['gate']['enabled'] = ! empty( $out['gate']['enabled'] ); $out['gate']['required'] = ! empty( $out['gate']['required'] ); $out['gate']['consent_default'] = ! empty( $out['gate']['consent_default'] );
		$imgs = array();
		foreach ( self::gate_image_slots() as $k => $label ) { $v = trim( (string) ( is_array( $out['gate']['images'] ?? null ) ? ( $out['gate']['images'][ $k ] ?? '' ) : '' ) ); $imgs[ $k ] = ctype_digit( $v ) ? (string) (int) $v : esc_url_raw( $v ); }
		$out['gate']['images'] = $imgs;
		$out['timer']['minutes'] = max( 0, (int) $out['timer']['minutes'] ); $out['timer']['warn_under'] = max( 0, (int) $out['timer']['warn_under'] );
		$out['ship']['paid'] = round( (float) $out['ship']['paid'], 2 );
		/* products */
		$prods = array(); $seen = array();
		foreach ( (array) $out['products'] as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$p = self::merge( self::product_defaults(), $p );
			$p['key'] = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $p['key'] ) );
			$p['product_id'] = (int) $p['product_id']; $p['img'] = (int) $p['img'];
			if ( '' === $p['key'] && $p['product_id'] ) { $p['key'] = 'p' . $p['product_id']; }
			if ( '' === $p['key'] || isset( $seen[ $p['key'] ] ) ) { continue; }
			$seen[ $p['key'] ] = 1;
			foreach ( array( 'ph', 'name', 'gname', 'gsub', 'ds', 'ing', 'who', 'rating', 'cat', 'pack' ) as $f ) { $p[ $f ] = sanitize_text_field( (string) $p[ $f ] ); }
			$p['desc'] = wp_kses( (string) $p['desc'], array( 'b' => array(), 'strong' => array(), 'em' => array(), 'br' => array() ) );
			$p['reviews'] = (string) $p['reviews'];
			$p['theme'] = array_values( array_map( function ( $c ) { return preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $c ) ? $c : '#ffffff'; }, array_pad( array_slice( (array) $p['theme'], 0, 3 ), 3, '#ffffff' ) ) );
			$p['enabled'] = ! empty( $p['enabled'] );
			$p['wc_text'] = ! empty( $p['wc_text'] ); /* v1.0.25: „за какво е“ и описанието идват от WooCommerce (по подразбиране) */
			$p['sku'] = sanitize_text_field( (string) ( $p['sku'] ?? '' ) ); /* v1.0.26: SKU на вариацията „1 опаковка“ — печели над product_id */
			/* v1.14.0: отстъпка по продукт и кутия — само валидни box id и проценти в (0,100); празно/0 = процентът на кутията */
			$pp = array(); foreach ( (array) ( $p['pct'] ?? array() ) as $bid => $v ) { $bid = sanitize_key( (string) $bid ); $v = is_numeric( $v ) ? (float) $v : 0; if ( '' !== $bid && $v > 0 && $v < 100 ) { $pp[ $bid ] = $v; } } $p['pct'] = $pp;
			$prods[] = $p;
		}
		$out['products'] = $prods;
		/* fit: само валидни ключове */
		$fit = array();
		foreach ( (array) $out['fit'] as $k => $ks ) { if ( isset( $seen[ $k ] ) ) { $fit[ $k ] = array_values( array_filter( array_map( 'strval', (array) $ks ), function ( $x ) use ( $seen, $k ) { return isset( $seen[ $x ] ) && $x !== $k; } ) ); } }
		$out['fit'] = $fit;
		/* 1.24.14: кампаниите по UTM */
		$camps = array();
		foreach ( (array) ( $out['campaigns'] ?? array() ) as $ck => $cv ) {
			$ck = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $ck ) ); if ( '' === $ck || ! is_array( $cv ) ) { continue; }
			$g = (string) ( $cv['gate'] ?? 'default' ); $b = sanitize_key( (string) ( $cv['box'] ?? '' ) );
			$camps[ $ck ] = array( 'gate' => in_array( $g, array( 'on', 'off' ), true ) ? $g : 'default', 'cosm_pop' => ! empty( $cv['cosm_pop'] ), 'box' => in_array( $b, array( 's', 'm', 'l' ), true ) ? $b : '' );
		}
		$out['campaigns'] = $camps;
		/* problems */
		$pr = array();
		foreach ( (array) $out['problems'] as $x ) { if ( is_array( $x ) && isset( $seen[ (string) ( $x['key'] ?? '' ) ] ) ) { $pr[] = array( 't' => sanitize_text_field( (string) ( $x['t'] ?? '' ) ), 'sub' => sanitize_text_field( (string) ( $x['sub'] ?? '' ) ), 'why' => sanitize_text_field( (string) ( $x['why'] ?? '' ) ), 'key' => (string) $x['key'] ); } }
		$out['problems'] = $pr;
		/* boxes: винаги 3, с фиксирани id */
		$bx = array(); $byid = array();
		foreach ( (array) $out['boxes'] as $b ) { if ( is_array( $b ) && isset( $b['id'] ) ) { $byid[ $b['id'] ] = $b; } }
		foreach ( $d['boxes'] as $db ) {
			$b = isset( $byid[ $db['id'] ] ) ? self::merge( $db, $byid[ $db['id'] ] ) : $db;
			$b['id'] = $db['id'];
			$b['name'] = sanitize_text_field( (string) $b['name'] ) ?: $db['name']; $b['ic'] = sanitize_text_field( (string) $b['ic'] ) ?: $db['ic']; $b['tag'] = sanitize_text_field( (string) $b['tag'] );
			$b['packs'] = max( 1, min( 12, (int) $b['packs'] ) ); $b['pct'] = max( 0, min( 90, (float) $b['pct'] ) ); $b['tickets'] = max( 0, (int) $b['tickets'] );
			$b['rw'] = array_values( array_filter( array_map( 'strval', (array) $b['rw'] ) ) ); $b['no'] = array_values( array_filter( array_map( 'strval', (array) $b['no'] ) ) );
			$b['cosm_pay'] = ( null === $b['cosm_pay'] || '' === $b['cosm_pay'] ) ? null : round( (float) $b['cosm_pay'], 2 );
			$b['grow'] = ! empty( $b['grow'] ); $b['tix_extra'] = max( 0, min( 10, (int) ( $b['tix_extra'] ?? 0 ) ) ); /* v1.12.0 */
			if ( isset( $b['base_pct'] ) ) { if ( ! empty( $out['season']['applied'] ) ) { $b['pct'] = max( 0, min( 90, (float) $b['base_pct'] ) ); } unset( $b['base_pct'] ); } /* v1.19.0: конфиг „сега“ (Config::at) никога не се записва с процента на седмицата */
			$bx[] = $b;
		}
		$out['boxes'] = $bx;
		/* v1.10.0: таванът никога не е под най-голямата кутия */
		$out['max_packs'] = max( self::max_packs_floor( $bx ), min( 50, (int) ( $out['max_packs'] ?? 20 ) ?: 20 ) );
		/* v1.19.0: седмиците на сезона */
		$sn = is_array( $out['season'] ?? null ) ? $out['season'] : array(); unset( $sn['applied'], $sn['cur'] ); $out['season'] = self::merge( $d['season'], $sn );
		$out['season']['weekly'] = ! empty( $out['season']['weekly'] );
		$st = trim( (string) $out['season']['start'] ); $out['season']['start'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $st ) ? $st : $d['season']['start'];
		$out['season']['grace_hours'] = max( 0, min( 168, (int) $out['season']['grace_hours'] ) );
		$wk = array();
		foreach ( (array) $out['season']['weeks'] as $w ) { $row = array(); foreach ( $bx as $b ) { $v = is_array( $w ) && isset( $w[ $b['id'] ] ) && is_numeric( $w[ $b['id'] ] ) ? (float) $w[ $b['id'] ] : null; $row[ $b['id'] ] = ( null !== $v && $v > 0 && $v < 100 ) ? $v : null; } $to = is_array( $w ) ? trim( (string) ( $w['to'] ?? '' ) ) : ''; $row['to'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) && checkdate( (int) substr( $to, 5, 2 ), (int) substr( $to, 8, 2 ), (int) substr( $to, 0, 4 ) ) ? $to : ''; $wk[] = $row; } /* 1.22.0: + to (краят на седмицата по избор) */
		$out['season']['weeks'] = array_slice( $wk, 0, 60 );
		foreach ( array( 'desktop', 'mobile' ) as $dev ) {
			$ord = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $out['order'][ $dev ] ?? array() ) ), function ( $x ) { return in_array( $x, array( 's', 'm', 'l' ), true ); } ) ) );
			$out['order'][ $dev ] = count( $ord ) === 3 ? $ord : $d['order'][ $dev ];
		}
		$out['rewards']['yacht']['value'] = (float) $out['rewards']['yacht']['value'];
		$out['rewards']['book']['product_id'] = (int) $out['rewards']['book']['product_id']; $out['rewards']['book']['value'] = (float) $out['rewards']['book']['value']; $out['rewards']['book']['url'] = esc_url_raw( (string) ( $out['rewards']['book']['url'] ?? '' ) );
		$out['rewards']['cosm']['product_id'] = (int) $out['rewards']['cosm']['product_id']; $out['rewards']['cosm']['value'] = (float) $out['rewards']['cosm']['value']; $out['rewards']['cosm']['limit'] = max( 0, (int) ( $out['rewards']['cosm']['limit'] ?? 10000 ) ); $bf = trim( (string) ( $out['rewards']['cosm']['bar_from'] ?? '' ) ); $out['rewards']['cosm']['bar_from'] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $bf ) && strtotime( $bf ) ? $bf : '';
		$out['secret']['pct'] = max( 0, min( 90, (float) $out['secret']['pct'] ) ); $out['secret']['count'] = max( 0, min( 6, (int) $out['secret']['count'] ) ); $out['secret']['enabled'] = ! empty( $out['secret']['enabled'] );
		$out['checkout']['card_gateway'] = sanitize_key( (string) $out['checkout']['card_gateway'] ); $out['checkout']['cod_gateway'] = sanitize_key( (string) $out['checkout']['cod_gateway'] ); $out['checkout']['block_coupons'] = ! empty( $out['checkout']['block_coupons'] ); $out['checkout']['consent_default'] = ! empty( $out['checkout']['consent_default'] );
		$out['tickets']['portal_url'] = esc_url_raw( (string) $out['tickets']['portal_url'] ); $out['tickets']['secret'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $out['tickets']['secret'] ); $out['tickets']['partner_bonus'] = max( 0, (int) $out['tickets']['partner_bonus'] ); $out['tickets']['email'] = ! empty( $out['tickets']['email'] ); $out['tickets']['fka_tag'] = mb_substr( trim( wp_strip_all_tags( (string) ( $out['tickets']['fka_tag'] ?? '' ) ) ), 0, 100 ); $bt = is_array( $out['tickets']['fka_tag_box'] ?? null ) ? $out['tickets']['fka_tag_box'] : array(); $out['tickets']['fka_tag_box'] = array(); foreach ( array( 's', 'm', 'l' ) as $bid ) { $out['tickets']['fka_tag_box'][ $bid ] = mb_substr( trim( wp_strip_all_tags( (string) ( $bt[ $bid ] ?? '' ) ) ), 0, 100 ); }
		$out['preview']['enabled'] = ! empty( $out['preview']['enabled'] ); $out['preview']['password'] = trim( wp_strip_all_tags( (string) ( $out['preview']['password'] ?? '' ) ) );
		/* v1.6.0: томбола */
		$tb = is_array( $out['tombola'] ?? null ) ? $out['tombola'] : array(); $out['tombola'] = self::merge( $d['tombola'], $tb );
		$out['info']['enabled'] = ! empty( $out['info']['enabled'] ); $out['info']['slug'] = sanitize_title( (string) ( $out['info']['slug'] ?? 'info' ) ) ?: 'info';
		$out['li']['enabled'] = ! empty( $out['li']['enabled'] ); $out['li']['slug'] = sanitize_title( (string) ( $out['li']['slug'] ?? 'kozmetika' ) ) ?: 'kozmetika'; /* 1.24.20 */
		$out['tombola']['enabled'] = ! empty( $out['tombola']['enabled'] ); $out['tombola']['slug'] = sanitize_title( (string) $out['tombola']['slug'] ) ?: 'tombola'; $out['tombola']['draw'] = trim( (string) $out['tombola']['draw'] );
		$sts = is_array( $out['tombola']['statuses'] ) ? $out['tombola']['statuses'] : preg_split( '/[\s,]+/', (string) $out['tombola']['statuses'] );
		$out['tombola']['statuses'] = array_values( array_unique( array_filter( array_map( function ( $s ) { return preg_replace( '/^wc-/', '', sanitize_key( (string) $s ) ); }, (array) $sts ) ) ) ) ?: $d['tombola']['statuses'];
		$out['tombola']['ref_param'] = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $out['tombola']['ref_param'] ) ?: 'r'; if ( 'ref' === $out['tombola']['ref_param'] ) { $out['tombola']['ref_param'] = 'r'; } /* ?ref= е на ansa Partner */
		$out['tombola']['ref_mult'] = max( 1, min( 10, (int) $out['tombola']['ref_mult'] ) ); $out['tombola']['ref_max'] = max( 0, min( 1000, (int) $out['tombola']['ref_max'] ) ); $out['tombola']['ref_gift'] = max( 0, min( 10, (int) $out['tombola']['ref_gift'] ) );
		/* v1.9.0: книга + демо данни */
		$bk = is_array( $out['book'] ?? null ) ? $out['book'] : array(); $out['book'] = self::merge( $d['book'], $bk );
		$out['book']['enabled'] = ! empty( $out['book']['enabled'] ); $out['book']['slug'] = sanitize_title( (string) $out['book']['slug'] ) ?: 'kniga'; $out['book']['file'] = str_replace( '..', '', trim( (string) $out['book']['file'] ) ); $out['book']['url'] = esc_url_raw( (string) $out['book']['url'] ); $out['book']['remember'] = max( 1, min( 3650, (int) $out['book']['remember'] ) );
		unset( $out['book']['statuses'] ); /* общите „получена“ статуси са tombola.statuses */
		$dm = is_array( $out['demo'] ?? null ) ? $out['demo'] : array(); $out['demo'] = self::merge( $d['demo'], $dm ); $out['demo']['phone'] = sanitize_text_field( (string) $out['demo']['phone'] ); $out['demo']['email'] = sanitize_email( (string) $out['demo']['email'] );
		/* v1.11.0: лийдовете → FKA */
		$ld = is_array( $out['leads'] ?? null ) ? $out['leads'] : array(); $out['leads'] = self::merge( $d['leads'], $ld );
		$out['leads']['fka'] = ! empty( $out['leads']['fka'] ); $out['leads']['consent_only'] = ! empty( $out['leads']['consent_only'] ); $out['leads']['untag_on_order'] = ! empty( $out['leads']['untag_on_order'] );
		foreach ( array( 'list', 'tag' ) as $lk ) { $out['leads'][ $lk ] = mb_substr( trim( wp_strip_all_tags( (string) $out['leads'][ $lk ] ) ), 0, 100 ); }
		$out['bgn']['show'] = ! empty( $out['bgn']['show'] ); $out['bgn']['rate'] = (float) $out['bgn']['rate'] ?: 1.95583;
		$out['theme']['hide'] = ! empty( $out['theme']['hide'] ); $out['theme']['selectors'] = implode( "\n", array_filter( array_map( function ( $l ) { return trim( preg_replace( '/[{}<>]/', '', (string) $l ) ); }, preg_split( '/\r?\n/', (string) $out['theme']['selectors'] ) ) ) );
		$out['copy'] = Copy::diff( is_array( $out['copy'] ) ? $out['copy'] : array() );
		/* 1.24.19: copy_utm — само познати ключове от регистъра, непразни текстове; празен UTM пада */
		$cu = array(); $reg = Copy::defaults();
		foreach ( (array) ( $out['copy_utm'] ?? array() ) as $uk => $m ) {
			$uk = strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $uk ) ); if ( '' === $uk || 'general' === $uk || ! is_array( $m ) ) { continue; }
			$o = array(); foreach ( $m as $k => $v ) { if ( isset( $reg[ $k ] ) && is_string( $v ) && trim( $v ) !== '' ) { $o[ $k ] = $v; } }
			if ( $o ) { $cu[ $uk ] = $o; }
		}
		$out['copy_utm'] = $cu;
		return $out;
	}

	private static function merge( $base, $over ) {
		if ( ! is_array( $over ) ) { return $base; }
		foreach ( $over as $k => $v ) {
			if ( isset( $base[ $k ] ) && is_array( $base[ $k ] ) && is_array( $v ) && self::is_assoc( $base[ $k ] ) && self::is_assoc( $v ) ) { $base[ $k ] = self::merge( $base[ $k ], $v ); }
			else { $base[ $k ] = $v; }
		}
		return $base;
	}
	private static function is_assoc( $a ) { if ( ! is_array( $a ) || array() === $a ) { return false; } return array_keys( $a ) !== range( 0, count( $a ) - 1 ); }

	/* ── store ── */
	private static function read_store() {
		if ( null !== self::$memory ) { return self::$memory; }
		$s = get_option( self::OPTION, null );
		if ( ! is_array( $s ) ) { $s = array( 'draft' => null, 'published' => null, 'published_at' => 0, 'version' => 0, 'history' => array() ); }
		if ( ! isset( $s['history'] ) || ! is_array( $s['history'] ) ) { $s['history'] = array(); }
		self::$memory = $s; return $s;
	}
	private static function write_store( $s ) { self::$memory = $s; update_option( self::OPTION, $s, false ); }
	public static function reset_memory() { self::$memory = null; }
	public static function store_exists() { return is_array( get_option( self::OPTION, null ) ); }

	public static function get_draft() { $s = self::read_store(); return self::normalize( is_array( $s['draft'] ) ? $s['draft'] : ( is_array( $s['published'] ) ? $s['published'] : array() ) ); }
	public static function get_published() { $s = self::read_store(); return self::normalize( is_array( $s['published'] ) ? $s['published'] : array() ); }
	public static function has_published() { $s = self::read_store(); return is_array( $s['published'] ); }
	public static function has_draft() { $s = self::read_store(); return is_array( $s['draft'] ); }
	public static function version() { $s = self::read_store(); return (int) $s['version']; }
	public static function published_at() { $s = self::read_store(); return (int) $s['published_at']; }
	public static function history() { $s = self::read_store(); return array_map( function ( $h ) { return array( 'version' => (int) $h['version'], 'at' => (int) $h['at'] ); }, $s['history'] ); }

	public static function save_draft( $data ) { $s = self::read_store(); $s['draft'] = self::normalize( $data ); $s['draft_at'] = time(); self::write_store( $s ); return $s['draft']; }
	public static function publish() {
		$s = self::read_store();
		$src = is_array( $s['draft'] ) ? $s['draft'] : $s['published'];
		if ( ! is_array( $src ) ) { return false; }
		if ( is_array( $s['published'] ) ) { array_unshift( $s['history'], array( 'version' => (int) $s['version'], 'at' => (int) $s['published_at'], 'cfg' => $s['published'] ) ); $s['history'] = array_slice( $s['history'], 0, self::HISTORY ); }
		$s['published'] = self::normalize( $src ); $s['draft'] = null; $s['published_at'] = time(); $s['version'] = (int) $s['version'] + 1;
		self::write_store( $s );
		self::after_publish( $s['published'] );
		return true;
	}
	public static function discard_draft() { $s = self::read_store(); $s['draft'] = null; self::write_store( $s ); }
	/** Връща историческа версия като нова чернова. */
	public static function restore( $version ) {
		$s = self::read_store();
		foreach ( $s['history'] as $h ) { if ( (int) $h['version'] === (int) $version && is_array( $h['cfg'] ) ) { return self::save_draft( $h['cfg'] ); } }
		return null;
	}

	/** JSON за export/import (черновата). */
	public static function export() { return wp_json_encode( self::get_draft(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); }
	public static function import( $json ) { $d = json_decode( (string) $json, true ); if ( ! is_array( $d ) ) { return null; } return self::save_draft( $d ); }

	private static function after_publish( $cfg ) {
		Catalog::purge_runtime();
		self::schedule_flip( $cfg ); /* v1.19.0: кешът се чисти точно при смяната на седмицата */
		if ( ! empty( $cfg['page_id'] ) ) { self::purge_page( (int) $cfg['page_id'] ); }
		do_action( 'ansa_promo_published', $cfg );
	}
	/** Purge само на URL-а на страницата — никога purge_everything. */
	public static function purge_page( $page_id ) {
		$url = $page_id ? get_permalink( (int) $page_id ) : '';
		if ( ! $url ) { return; }
		if ( class_exists( '\\FlyingPress\\Purge' ) ) { try { if ( is_callable( array( '\\FlyingPress\\Purge', 'purge_url' ) ) ) { \FlyingPress\Purge::purge_url( $url ); } elseif ( is_callable( array( '\\FlyingPress\\Purge', 'purge_urls' ) ) ) { \FlyingPress\Purge::purge_urls( array( $url ) ); } } catch ( \Throwable $e ) {} }
		if ( function_exists( 'rocket_clean_post' ) ) { try { rocket_clean_post( (int) $page_id ); } catch ( \Throwable $e ) {} }
		/* 1.23.7: и другите разпространени кеш плъгини — само URL-а на страницата, всичко е guarded */
		try {
			do_action( 'litespeed_purge_url', $url ); do_action( 'litespeed_purge_post', (int) $page_id );
			if ( function_exists( 'wpsc_delete_post_cache' ) ) { wpsc_delete_post_cache( (int) $page_id ); }
			if ( function_exists( 'w3tc_flush_post' ) ) { w3tc_flush_post( (int) $page_id ); }
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache( $url ); }
			if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) { wpfc_clear_post_cache_by_id( (int) $page_id ); }
			if ( function_exists( 'breeze_varnish_purge_cache' ) ) { breeze_varnish_purge_cache( $url ); }
			if ( class_exists( '\\Nginx_Helper' ) ) { do_action( 'rt_nginx_helper_purge_all' ); }
			do_action( 'cloudflare_purge_by_url', array( $url ), (int) $page_id );
			if ( function_exists( 'wp_cache_post_change' ) ) { wp_cache_post_change( (int) $page_id ); }
		} catch ( \Throwable $e ) {}
		do_action( 'ansa_promo_purge_url', $url, (int) $page_id );
	}

	/* ── помощници ── */
	public static function product( $cfg, $key ) { foreach ( $cfg['products'] as $p ) { if ( $p['key'] === $key ) { return $p; } } return null; }
	/** v1.14.0: ефективната отстъпка на продукт в кутия — override от 📦 Продукти (pct[box id]) или процентът на кутията. */
	public static function product_pct( $p, $box ) {
		$bid = is_array( $box ) ? (string) ( $box['id'] ?? '' ) : (string) $box;
		$v = is_array( $p ) && isset( $p['pct'][ $bid ] ) ? (float) $p['pct'][ $bid ] : 0;
		if ( $v > 0 && $v < 100 ) { return $v; }
		return is_array( $box ) ? (float) ( $box['pct'] ?? 0 ) : 0;
	}

	public static function box( $cfg, $id ) { foreach ( $cfg['boxes'] as $b ) { if ( $b['id'] === $id ) { return $b; } } return null; }
	private static function max_packs_floor( $boxes ) { $m = 1; foreach ( (array) $boxes as $b ) { $m = max( $m, (int) ( $b['packs'] ?? 1 ) ); } return $m; }
	/** v1.12.0: най-много опаковки за кутията — растящата (grow) до max_packs, останалите точно packs. */
	public static function max_for( $cfg, $box ) { return ! empty( $box['grow'] ) ? self::max_packs( $cfg ) : (int) $box['packs']; }
	/** v1.12.0: билетите за кутия с $count опаковки — tickets + tix_extra за всяка опаковка над packs (само grow кутия). */
	public static function tickets_for( $box, $count ) { $n = (int) $box['tickets']; if ( ! empty( $box['grow'] ) && (int) $count > (int) $box['packs'] ) { $n += ( (int) $count - (int) $box['packs'] ) * (int) ( $box['tix_extra'] ?? 0 ); } return $n; }
	/** v1.10.0: кутията според броя опаковки — най-голямата кутия с packs ≤ $count, но не по-малка от $min (избраната от клиента).
	 *  v1.12.0: само растяща (grow) кутия може да бъде „прескочена“ от броя — малка/средна са с точен брой и не минават оттук. */
	public static function box_for_count( $cfg, $count, $min = null ) {
		$best = is_array( $min ) ? $min : ( $min ? self::box( $cfg, $min ) : null );
		foreach ( $cfg['boxes'] as $b ) {
			if ( (int) $b['packs'] <= (int) $count && ( ! $best || (int) $b['packs'] > (int) $best['packs'] ) ) { $best = $b; }
		}
		return $best;
	}
	public static function max_packs( $cfg ) { return max( self::max_packs_floor( $cfg['boxes'] ?? array() ), (int) ( $cfg['max_packs'] ?? 20 ) ?: 20 ); }
	public static function max_pct( $cfg ) { $m = 0; foreach ( $cfg['boxes'] as $b ) { $m = max( $m, (float) $b['pct'] ); } return $m; }
	public static function deadline_ts( $cfg ) { if ( empty( $cfg['deadline'] ) ) { return 0; } $t = strtotime( $cfg['deadline'] . ' ' . wp_timezone_string() ); return $t ? (int) $t : 0; }
	/** 1.8.9: броячът „Първите N са резервирани“ (попъпът „Как работи“ + /info) се показва чак от rewards.cosm.bar_from (00:00 по часа на сайта); празно = винаги. */
	public static function cosm_bar_on( $cfg ) { $bf = (string) ( $cfg['rewards']['cosm']['bar_from'] ?? '' ); if ( '' === $bf ) { return true; } $t = strtotime( $bf . ' 00:00 ' . wp_timezone_string() ); return ! $t || time() >= $t; }
	/* ── v1.19.0: седмиците на сезона ──
	   Сезонът е N седмици от season.start (00:00 по часа на сайта) до deadline (последната може да е по-къса). Всяка седмица има свой
	   процент за всяка кутия (season.weeks); празно = процентът на кутията. Config::at($cfg) връща конфига „сега“ — кутиите с процента на
	   текущата седмица (base_pct пази въведения); количката, runtime JSON-ът, „Как работи“ и /info минават оттук. Преди старта важи
	   седмица 1, след края — последната. „Сега“ може да се подмени: ?ansa_promo=draft&week=N (Frontend::preview_week) и filter ansa_promo_now (Probe). */
	const FLIP_HOOK = 'ansa_promo_week_flip';
	private static $now = null;
	public static function now() { $t = null !== self::$now ? (int) self::$now : time(); return (int) apply_filters( 'ansa_promo_now', $t ); }
	public static function set_now( $ts ) { self::$now = $ts ? (int) $ts : null; }
	public static function weekly_on( $cfg ) { return ! empty( $cfg['season']['weekly'] ) && self::season_start_ts( $cfg ) > 0; }
	public static function season_start_ts( $cfg ) { $s = (string) ( $cfg['season']['start'] ?? '' ); if ( '' === $s ) { return 0; } $t = strtotime( $s . ' 00:00 ' . wp_timezone_string() ); return $t ? (int) $t : 0; }
	/** Колко седмици има сезонът = броят редове на season_weeks(): началата на седмици преди deadline (по 7 дни или по въведения край); без срок — колкото са въведени (поне 1). */
	public static function season_count( $cfg ) { return max( 1, count( self::season_weeks( $cfg ) ) ); }
	private static function tz() { try { return new \DateTimeZone( wp_timezone_string() ); } catch ( \Exception $x ) { return new \DateTimeZone( 'UTC' ); } }
	/** Седмиците: [{ i (1…N), from, to (ts; to = началото на следващата или deadline), pct { box id => % }, set (има въведен ред), to_custom (краят е въведен), to_bad (въведеният край е преди началото — ползва се 1 ден) }].
	 *  Границите са 00:00 по часа на сайта (DST-safe). 1.22.0 (човекът: „една по-дълга и по-кратка седмица“): weeks[i].to = последният ден на седмицата по избор (включително);
	 *  следващата тръгва на другия ден; празно = 7 дни. Последната винаги свършва на срока. Без срок — колкото реда са въведени. */
	public static function season_weeks( $cfg ) {
		$s = self::season_start_ts( $cfg ); if ( ! $s ) { return array(); }
		$e = self::deadline_ts( $cfg ); $in = (array) ( $cfg['season']['weeks'] ?? array() ); $rows = array(); $tz = self::tz();
		if ( $e && $e <= $s ) { $e = 0; } /* стартът е след края — Doctor го казва; тук се броят въведените редове */
		$n_in = max( 1, count( $in ) );
		$cur = ( new \DateTimeImmutable( '@' . $s ) )->setTimezone( $tz );
		for ( $i = 0; $i < 60; $i++ ) {
			$custom = (string) ( $in[ $i ]['to'] ?? '' ); $bad = false;
			if ( '' !== $custom && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $custom ) ) { try { $next = ( new \DateTimeImmutable( $custom . ' 00:00', $tz ) )->modify( '+1 day' )->setTime( 0, 0 ); } catch ( \Exception $x ) { $custom = ''; } } else { $custom = ''; }
			if ( '' === $custom ) { $next = $cur->modify( '+7 days' )->setTime( 0, 0 ); }
			if ( $next->getTimestamp() <= $cur->getTimestamp() ) { $next = $cur->modify( '+1 day' )->setTime( 0, 0 ); $bad = true; } /* краят е преди началото — 1 ден, не 0 */
			$to = $next->getTimestamp(); $last = $e ? ( $to >= $e ) : ( $i >= $n_in - 1 );
			if ( $e && $last ) { $to = $e; }
			$pct = array(); foreach ( (array) $cfg['boxes'] as $b ) { $v = isset( $in[ $i ][ $b['id'] ] ) && null !== $in[ $i ][ $b['id'] ] ? (float) $in[ $i ][ $b['id'] ] : (float) ( $b['base_pct'] ?? $b['pct'] ); $pct[ (string) $b['id'] ] = $v; }
			$rows[] = array( 'i' => $i + 1, 'from' => $cur->getTimestamp(), 'to' => $to, 'pct' => $pct, 'set' => isset( $in[ $i ] ), 'to_custom' => '' !== $custom, 'to_bad' => $bad );
			if ( $last ) { break; }
			$cur = $next;
		}
		return $rows;
	}
	/** Текущата седмица (+ total, next, last, days_left) или null, когато седмиците са изключени. */
	public static function week_for( $cfg, $ts = null ) {
		if ( ! self::weekly_on( $cfg ) ) { return null; }
		$rows = self::season_weeks( $cfg ); if ( ! $rows ) { return null; }
		$ts = null === $ts ? self::now() : (int) $ts; $k = 0;
		foreach ( $rows as $j => $r ) { if ( $ts >= $r['from'] ) { $k = $j; } }
		$w = $rows[ $k ]; $w['total'] = count( $rows ); $w['next'] = $rows[ $k + 1 ] ?? null; $w['last'] = ! isset( $rows[ $k + 1 ] );
		$w['days_left'] = max( 0, (int) ceil( ( $w['to'] - $ts ) / DAY_IN_SECONDS ) );
		return $w;
	}
	/** Процентът на кутия „сега“ (или в момент $ts). */
	public static function box_pct_at( $cfg, $box, $ts = null ) {
		$bid = is_array( $box ) ? (string) ( $box['id'] ?? '' ) : (string) $box; $w = self::week_for( $cfg, $ts );
		if ( $w && isset( $w['pct'][ $bid ] ) ) { return (float) $w['pct'][ $bid ]; }
		$b = is_array( $box ) ? $box : self::box( $cfg, $bid ); return $b ? (float) ( $b['base_pct'] ?? $b['pct'] ) : 0;
	}
	/** Конфигът „сега“: кутиите с процента на текущата седмица; season.cur = седмицата, season.applied = маркер (normalize го маха и връща base_pct). */
	public static function at( $cfg, $ts = null ) {
		if ( ! is_array( $cfg ) || ! empty( $cfg['season']['applied'] ) ) { return $cfg; }
		$w = self::week_for( $cfg, $ts ); if ( ! $w ) { return $cfg; }
		foreach ( $cfg['boxes'] as $i => $b ) { $cfg['boxes'][ $i ]['base_pct'] = (float) $b['pct']; $cfg['boxes'][ $i ]['pct'] = (float) ( $w['pct'][ $b['id'] ] ?? $b['pct'] ); }
		$cfg['season']['applied'] = true; $cfg['season']['cur'] = $w;
		return $cfg;
	}
	/** Еднократен cron в началото на следващата седмица: кешът на runtime-а, страницата и /info се чистят точно при смяната. */
	public static function schedule_flip( $cfg = null ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) { return; }
		$cfg = $cfg ?: self::get_published();
		$next = wp_next_scheduled( self::FLIP_HOOK ); if ( $next ) { wp_unschedule_event( $next, self::FLIP_HOOK ); }
		$w = self::week_for( $cfg, time() ); if ( ! $w ) { return; }
		$at = (int) ( $w['next'] ? $w['next']['from'] : $w['to'] ) + 5; if ( $at <= time() ) { return; }
		wp_schedule_single_event( $at, self::FLIP_HOOK );
	}
	public static function on_flip() {
		Catalog::purge_runtime(); $pub = self::get_published();
		if ( ! empty( $pub['page_id'] ) ) { self::purge_page( (int) $pub['page_id'] ); }
		if ( class_exists( __NAMESPACE__ . '\\Info' ) ) { Info::purge( $pub ); }
		self::schedule_flip( $pub );
	}
	public static function is_live( $cfg ) {
		if ( empty( $cfg['enabled'] ) ) { return false; }
		$t = self::deadline_ts( $cfg ); if ( $t && time() > $t ) { return false; }
		return true;
	}
}
