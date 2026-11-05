<?php
/**
 * ansa™ Промо — Черен петък 2026: копи-регистър (текстовете на страницата, количката и чекаута).
 *
 * Всеки клиентски текст е ключ + дефолт; админът пази само променените ключове в $cfg['copy'] (🖤 Черен петък → Текстове).
 * Плейсхолдърите са {{name}} и се рендират еднакво тук (AnsaPromo\Copy::render) и в bf.js (T()).
 *
 * @package AnsaPromo
 * @since 1.45.0
 */
namespace AnsaPromo\BF;

defined( 'ABSPATH' ) || exit;

final class Copy {

	public static function groups() {
		return array(
			'page'   => 'Страницата (title · описание)',
			'cold'   => 'Студен трафик — страницата с ваучера (подготовка)',
			'wallet' => 'След отключването — портфейлът и бързата поръчка',
			'aff'    => 'Партньорски линк (?ref=) — подаръкът от партньора',
			'shop'   => 'Стъпка 1 · Продукти (ранен достъп / публичен)',
			'gifts'  => 'Стъпка 2 · Подаръци',
			'sheet'  => 'Попъпът „Прочети повече“',
			'co'     => 'Количка · чекаут · благодаря',
			'msgs'   => 'Съобщения и грешки',
			'over'   => 'След края',
			'lucky'  => 'Попъпът „Ти си късметлия“ и ваучерът от имейла (1.46.0)',
		);
	}

	/** [group => [key => default]]. */
	public static function registry() {
		return array(
			'page' => array(
				'page.title' => 'Черен петък 2026 в ansa™ — всяка опаковка = подарък по избор',
				'page.desc'  => 'Купуваш 1, получаваш 2. Всяка опаковка ти носи още една по избор — подарък. 72 часа. Ранен достъп за записаните.',
			),
			'cold' => array(
				'cold.eyebrow'     => 'Black Friday 2026 · ранен достъп',
				'cold.title'       => 'Black Friday на ANSA започва <em>първо за теб.</em>',
				'cold.sub'         => 'Получи първа достъп до най-добрите оферти за годината и грабни {{voucher}} € още днес!',
				'cold.seats'       => '<b>{{seats}}</b> / {{max}} записани жени · остават <b>{{left}}</b> места',
				'cold.timer.live'  => 'подаръкът важи още',
				'cold.timer.over'  => 'изтече',
				'cold.tbar'          => 'Ваучерът за {{voucher}} € изтича след',
				'cold.tbar.sub'      => 'отключи го сега — после го няма',
				'cold.tbar.unit'     => 'мин : сек',
				'cold.tbar.over'     => 'Времето изтече',
				'cold.tbar.over.sub' => 'запиши се — пак влизаш в ранния достъп',
				'cold.vch.title'   => 'Твоят ваучер за',
				'cold.vch.note'    => 'за всичко в ansa.bg · важи {{minutes}} минути след отключването',
				'cold.vch.locked'  => '🔒 заключен',
				'cold.vch.ok'      => '✔ отключен',
				'cold.vch.exp'     => 'изтича след',
				'cold.vch.valid'   => 'важи още',
				'cold.vch.wallet'  => 'в портфейла ти · за всичко в ansa.bg',
				'cold.form.h'      => 'Въведи имейла си, за да го отключиш',
				'cold.form.ph'     => 'твоят имейл',
				'cold.form.cta'    => 'Отключи ваучера',
				'cold.form.expired'=> 'срокът изтече — записването пак те вкарва в ранния достъп',
				'cold.form.trust'  => '🔒 2 имейла преди старта и 1 в деня на старта. Без спам.',
				'cold.foot'        => 'ansa™ · От жени за жени · Ваучерът важи еднократно, {{minutes}} минути след отключването. Пълни условия на ansa.bg/bf',
			),
			'wallet' => array(
				'wallet.pill'        => '👛 портфейл {{voucher}} €',
				'wallet.tbar'          => 'Ваучерът ти за {{voucher}} € важи още',
				'wallet.tbar.sub'      => 'поръчай сега — после цените са редовни',
				'wallet.tbar.over'     => 'Ваучерът изтече',
				'wallet.tbar.over.sub' => 'записана си за ранния достъп на {{early_date}}',
				'wallet.title.ok'    => 'Вътре си. <em>Ваучерът е отключен.</em>',
				'wallet.title.over'  => 'Вътре си. <em>Ваучерът за днес изтече.</em>',
				'wallet.sub.ok'      => 'Не чакай промото — започни още днес с нещо малко. Ще ти хареса, та със сигурност се готви за {{early_date}}: тогава всяка поръчана опаковка = 1 опаковка подарък от продукт по избор.',
				'wallet.sub.over'    => 'Записана си за ранния достъп на {{early_date}}. Можеш да поръчаш и сега на редовна цена.',
				'wallet.date'        => '📅 {{early_date}}, {{early_time}}',
				'wallet.date.sub'    => 'ранен достъп · ще ти пратим имейл точно в {{early_time}}',
				'wallet.date.pill'   => 'записана си ✔',
				'wallet.pick'        => 'Избери нещо за днес',
				'wallet.foot.empty'  => 'Количката е празна',
				'wallet.foot.voucher'=> 'ваучер {{voucher}} € в портфейла',
				'wallet.foot.add'    => 'Добави нещо за днес',
				'wallet.foot.disc'   => '−{{amount}} € с ваучера',
				'wallet.foot.expired'=> 'ваучерът изтече',
				'wallet.foot.cta'    => 'Бърза поръчка →',
				'rcpt.reg'           => 'Редовна цена',
				'rcpt.vch'           => '🎟 Минус ваучера ти',
				'rcpt.pay'           => 'Ще платиш',
				'wallet.r.exp'       => 'ваучерът за днес изтече',
				'wallet.r.hint'      => 'Добави продукт, за да го използваш — важи само днес',
				'wallet.r.hint.plain'=> 'Можеш да поръчаш на редовна цена',
				'wallet.cta.use'     => 'Използвай ваучера · {{total}} →',
				'wallet.cta.sub'     => 'вместо {{reg}} · изтича след',
				'wallet.cta.plain'   => 'Поръчай · {{total}} →',
				'wallet.cta.plain.sub' => 'на редовна цена',
				'wallet.note'        => 'Цените са редовни. Ваучерът от записването се приспада в количката.',
			),
			'aff' => array(
				'aff.pill'       => '🔗 линк на <b>{{partner}}</b>',
				'aff.eyebrow'    => 'Black Friday 2026 · 72 часа',
				'aff.title'      => 'Купуваш 1, получаваш 2. <em>И подарък от {{partner}}.</em>',
				'aff.sub'        => 'Всяка опаковка ти носи още една по избор. С линка на {{partner}} получаваш и подарък отгоре — избери го и влез.',
				'aff.choose'     => 'Подаръкът от {{partner}} — избери',
				'aff.v.title'    => 'Ваучер {{aff_voucher}} €',
				'aff.m.title'    => 'Колагенова маска подарък',
				'aff.m.sub'      => 'CollaMask · {{mask_price}} €',
				'aff.cta'        => 'Вземи подаръка и влез',
				'aff.cta.sub'    => 'добавя се към поръчката ти',
				'aff.trust'      => '🔒 Само потвърждение на поръчката. Без спам.',
				'aff.how.h'      => 'Как работи',
				'aff.how.1'      => 'Всяка опаковка ти носи още една — по избор',
				'aff.how.1.sub'  => 'Купуваш Грийнс, избираш си подарък. Може и втори Грийнс.',
				'aff.how.2'      => 'Две опаковки — два подаръка. И така до безкрай.',
				'aff.how.3'      => 'Плюс подаръкът от {{partner}}',
				'aff.how.3.sub'  => 'ваучер {{aff_voucher}} € или колагенова маска — ти избра горе',
				'aff.new.h'      => '4 нови продукта — само като подарък',
				'aff.foot'       => 'ansa™ · От жени за жени · Подаръкът от {{partner}} важи с поръчка в промоцията. Условия на ansa.bg/bf',
				'aff.wait.title' => 'Подаръкът ти е запазен.',
				'aff.wait.sub'   => 'Black Friday отваря на {{public_date}} в {{public_time}}. Ще ти пратим линк по имейл — подаръкът от {{partner}} те чака в количката.',
				'aff.wait.cta'   => 'Разгледай продуктите',
				'aff.toast'      => 'Подаръкът е запазен',
			),
			'shop' => array(
				'shop.pill.early'   => '⏳ ранен достъп',
				'shop.pill.public'  => 'BLACK FRIDAY',
				'shop.steps'        => 'Продукти|Подаръци|Финализиране',
				'shop.eq.buy'       => 'купуваш',
				'shop.eq.get'       => 'по избор',
				'shop.eq.note'      => 'Колкото опаковки купиш, толкова подаръка си избираш.',
				'shop.opk1'         => 'опаковка',
				'shop.opk'          => 'опаковки',
				'shop.gift1'        => 'подарък',
				'shop.gift'         => 'подаръка',
				'shop.step1'        => 'Стъпка 1 · Избери какво купуваш',
				'focus.h'           => 'Колко опаковки искаш?',
				'focus.unit'        => '/ опаковка',
				'focus.line'        => '{{n}} {{opk}} = <b>+{{g}} {{podaruk}} по избор</b> · плащаш {{total}}',
				'focus.line.empty'  => 'Всяка опаковка ти носи още една по избор — подарък.',
				'focus.other'       => 'Не искам този продукт. Виж други →',
				'focus.opt.gift'    => '+{{g}} {{podaruk}} по избор',
				'focus.opt.total'   => 'общо',
				'shop.example.btn'  => 'ⓘ пример',
				'shop.example'      => 'Например: избираш си 1 или повече опаковки Daily Greens, на <b>стъпка 2</b> избираш 1 или повече опаковки от продукт по избор (може пак Daily Greens).',
				'shop.filter.label' => 'Покажи',
				'shop.filter.all'   => 'Всички',
				'shop.filter.fav'   => 'Най-продавани',
				'shop.add'          => '＋ Добави',
				'shop.more'         => 'Прочети повече ▾',
				'shop.gl'           => '🎁 носи ти {{n}} {{podaruk}} по избор',
				'shop.scroll'       => 'Скролни за още {{n}} {{produkt}} ↓',
				'shop.foot.empty'   => 'Количката е празна',
				'shop.foot.empty.r' => '1 опаковка = 1 подарък по избор',
				'shop.foot.empty.cta' => 'Добави нещо, за да избереш подарък',
				'shop.panel.h'      => 'Твоята поръчка',
				'panel.eyebrow'     => 'Черен петък 2026',
				'panel.cart.h'      => 'Купуваш',
				'panel.cart.empty'  => 'Още нищо — натисни „Добави“ на продукт',
				'panel.gifts.h'     => 'Подаръците ти',
				'panel.gifts.note'  => '{{n}} {{podaruk}} по избор те чакат на стъпка 2',
				'panel.gifts.note.pick' => 'избери още {{n}} {{podaruk}}',
				'panel.gifts.note.done' => 'всички подаръци са избрани ✓',
				'panel.gifts.note.empty' => 'всяка опаковка = 1 подарък по избор',
				'shop.foot.cta.sub' => '{{n}} {{podaruk}} по избор · на 0 €',
				'panel.title'       => 'Твоята поръчка с подаръци',
				'shop.foot.pay'     => 'Плащаш',
				'shop.foot.r'       => '{{n}} {{podaruk}} по избор · −{{pct}} %',
				'shop.foot.cta'     => 'Избери {{sel}} →',
				'shop.sel1'         => 'подаръка си',
				'shop.seln'         => '{{n}}-те си подаръка',
				'shop.save'         => 'Спестяваш',
				'shop.save.est'     => 'Спестяваш',
				'shop.how.h'        => 'Как работи · пример',
				'shop.how.buy'      => 'Купуваш 2',
				'shop.how.buy.sub'  => 'Daily Greens',
				'shop.how.get'      => 'Избираш 2',
				'shop.how.get.sub'  => 'напр. KeraTonica + Берберин',
				'shop.how.sum'      => 'Вкъщи пристигат <b>4 кутии</b>',
				'shop.how.pay'      => 'плащаш {{ex_pay}} €',
				'shop.how.s1'       => 'Избираш какво купуваш',
				'shop.how.s2'       => 'Избираш толкова подаръка, колкото опаковки',
				'shop.how.s3'       => 'Завършваш поръчката',
				'shop.affnote.v'    => '🔗 Идваш от <b>{{partner}}</b>: ваучерът ти {{aff_voucher}} € се приспада в количката.',
				'shop.affnote.m'    => '🔗 Идваш от <b>{{partner}}</b>: колагенова маска CollaMask е добавена към поръчката ти.',
				'shop.affnote.none' => '🔗 Идваш от <b>{{partner}}</b>:',
				'shop.affnote.cta'  => 'вземи подаръка ѝ →',
				'shop.closed.title' => 'Ранният достъп още не е отворен.',
				'shop.closed.sub'   => '{{early_date}}, {{early_time}}. Записаните получават личен линк по имейл.',
				'shop.closed.early.title' => 'Ранният достъп е за записаните.',
				'shop.closed.early.sub'   => 'Отвори личния си линк от имейла — той те вкарва направо. Публичният старт е на {{public_date}} в {{public_time}}.',
				'shop.footnote'     => 'Цените са от ansa.bg. Участват само единични опаковки на редовна цена.',
			),
			'gifts' => array(
				'gifts.h'         => '🎁 Избери {{n}} {{podaruk}}',
				'gifts.hsub'    => 'всеки на 0 € · пристигат в същата пратка',
				'gifts.back'      => '← Назад към продуктите',
				'gifts.back.short'=> '← Назад',
				'gifts.picked'    => 'Избрани',
				'gifts.of'        => 'от',
				'gifts.change'    => '← Назад към продуктите',
				'gifts.new'       => 'Нови продукти',
				'gifts.best'      => 'Най-продавани продукти',
				'gifts.free'      => 'подарък',
				'gifts.pick'      => '🎁 Избирам',
				'gifts.cta.more'  => 'Избери още {{n}} {{podaruk}}',
				'gifts.cta.go'    => 'Продължи към поръчката →',
				'gifts.foot.r'    => '{{n}} платени + {{n}} {{podaruk}}',
				'gifts.sheet.cta' => '🎁 Избирам го като подарък',
				'gifts.sheet.note'=> 'Избери го като подарък — не струва нищо.',
			),
			'sheet' => array(
				'sheet.tabs'          => 'За какво|Състав|Как се приема|За кого',
				'sheet.new'           => 'НОВО',
				'sheet.unit'          => '/ кутия',
				'sheet.giftonly'      => 'През Black Friday го получаваш само като подарък към опаковка.',
				'sheet.giftonly.cta'  => '🎁 Само като подарък · избери го в стъпка 2',
				'sheet.giftonly.first'=> '🎁 Само като подарък · първо добави опаковка',
				'sheet.add'           => '＋ Добави · {{price}} €',
				'sheet.add.sub'       => 'или го избери като подарък в следващата стъпка',
				'sheet.other'         => 'Подходящ и като подарък по избор към всяка опаковка.',
				'sheet.empty'         => 'Описанието идва от продуктовата страница.',
			),
			'co' => array(
				'co.h'         => '🖤 Black Friday: {{paid}} платени + {{gifts}} {{podaruk}} по избор',
				'co.base.tag'  => 'Black Friday · 1 опаковка = 1 подарък',
				'co.gift.tag'  => 'подарък по избор',
				'co.pgift.tag' => 'подарък от {{partner}}',
				'co.wallet.tag'=> 'с ваучера от записването',
				'co.free'      => 'подарък',
				'co.fee.cold'  => 'Ваучер {{voucher}} € · {{code}}',
				'co.fee.aff'   => 'Ваучер {{aff_voucher}} € от {{partner}}',
				'co.ship.free' => 'безплатна доставка',
				'co.save'      => 'Спестяваш {{save}} € (−{{pct}} %)',
				'co.more'      => 'Можеш да избереш още {{n}} {{podaruk}} — <a href="{{url}}">избери ›</a>',
				'co.broken'    => 'Подаръците са повече от платените опаковки — излишните отпаднаха.',
				'co.closed'    => 'Black Friday офертата не е активна в момента. <a href="{{url}}">Към страницата ›</a>',
				'co.coupon'    => 'В Black Friday количката купоните не важат — подаръците са офертата.',
				'ty.h'         => 'Черен петък: {{paid}} платени + {{gifts}} {{podaruk}} пътуват към теб',
				'ty.p'         => 'Подаръците са в същата пратка. Спести {{save}} €.',
				'ty.wallet'    => 'Ваучерът от записването е приспаднат. Ранният достъп отваря на {{early_date}} в {{early_time}} — ще ти пратим линк.',
			),
			'msgs' => array(
				'err.nonce'   => 'Сесията изтече — презареди страницата.',
				'err.rate'    => 'Твърде много опити. Изчакай минута.',
				'err.live'    => 'Офертата не е активна в момента.',
				'err.early'   => 'Ранният достъп е за записаните — отвори линка от имейла си.',
				'err.count'   => 'Подаръците трябва да са колкото платените опаковки.',
				'err.allowed' => 'Този продукт не участва в офертата.',
				'err.stock'   => '{{name}} няма наличност в момента.',
				'err.add'     => 'Количката не се направи — опитай пак.',
				'err.code'    => 'Няма такъв ваучер — или вече е използван / изтекъл.',
				'err.email'   => 'Въведи валиден имейл, за да ти пратим подаръка.',
				'toast.added' => '{{name}} · +{{n}} {{podaruk}}',
				'toast.gifts' => 'Всички подаръци са избрани',
				'toast.giftonly' => 'Само като подарък — първо добави опаковка',
				'toast.claimed'  => 'Ваучерът е активен · −{{amount}} €',
				'wait.promo'     => 'Правим количката ти…',
				'wait.wallet'    => 'Подготвяме бързата поръчка…',
				'wait.go'        => 'Готово — отваряме поръчката',
				'wait.sub'       => 'Секунда-две. Не затваряй страницата.',
				'toast.sending'  => 'Правим количката ти…',
			),
			'lucky' => array(
				'lucky.title'   => 'Ти си късметлия! 🍀',
				'lucky.text'    => 'Попадна в случайното раздаване на подаръци.',
				'lucky.gift'    => 'ПОДАРЪК: 1 бр Колагенова маска CollaMask',
				'lucky.sub'     => 'добавя се към поръчката ти — на 0 €',
				'lucky.cta'     => 'Вземи подаръка 🎁',
				'lucky.no'      => 'Не, благодаря',
				'lucky.line'    => '🍀 + 1 бр CollaMask · подарък от раздаването',
				'lucky.toast'   => 'Маската е в поръчката ти',
				'co.lucky.tag'  => 'подарък от раздаването 🍀',
				'shop.vch.live' => '✔ Ваучерът ти за {{amount}} € е в количката',
				'shop.claim.h'  => 'Имаш ваучер от имейла?',
				'shop.claim.ph' => 'кодът от имейла',
				'shop.claim.cta' => 'Активирай',
			),
			'over' => array(
				'over.title' => 'Black Friday в ANSA приключи.',
				'over.sub'   => 'Благодарим на всички. Продуктите са в магазина на редовни цени.',
				'over.cta'   => 'Към магазина',
			),
		);
	}

	public static function vars() {
		return array( 'voucher', 'seats', 'max', 'left', 'minutes', 'early_date', 'early_time', 'public_date', 'public_time', 'partner', 'aff_voucher', 'mask_price', 'n', 'podaruk', 'produkt', 'pct', 'sel', 'ex_pay', 'price', 'paid', 'gifts', 'code', 'save', 'url', 'name', 'email', 'amount', 'total', 'reg', 'opk', 'g' );
	}
	public static function defaults() { static $d = null; if ( null === $d ) { $d = array(); foreach ( self::registry() as $g => $keys ) { $d += $keys; } } return $d; }
	public static function effective( $overrides ) {
		$d = self::defaults();
		if ( is_array( $overrides ) ) { foreach ( $overrides as $k => $v ) { if ( isset( $d[ $k ] ) && is_string( $v ) && $v !== $d[ $k ] ) { $d[ $k ] = $v; } } }
		return $d;
	}
	public static function diff( $copy ) {
		$d = self::defaults(); $out = array();
		foreach ( (array) $copy as $k => $v ) { if ( isset( $d[ $k ] ) && is_string( $v ) && $v !== $d[ $k ] && trim( $v ) !== '' ) { $out[ $k ] = $v; } }
		return $out;
	}
	public static function validate( $text ) { $e = array(); $t = (string) $text; if ( substr_count( $t, '{{' ) !== substr_count( $t, '}}' ) ) { $e[] = 'небалансирани {{ }}'; } foreach ( \AnsaPromo\Copy::placeholders( $t ) as $v ) { if ( ! in_array( $v, self::vars(), true ) ) { $e[] = 'непознат плейсхолдър {{' . $v . '}}'; } } return $e; }
	public static function render( $template, $vars ) { return \AnsaPromo\Copy::render( $template, $vars ); }
	/** „key = текст“ на ред → масив (за админа). */
	public static function parse_lines( $txt ) {
		$out = array(); $d = self::defaults();
		foreach ( preg_split( '/\r?\n/', (string) $txt ) as $ln ) { if ( ! preg_match( '/^\s*([a-z0-9_.]+)\s*=\s*(.*)$/u', $ln, $m ) ) { continue; } $k = $m[1]; if ( isset( $d[ $k ] ) ) { $out[ $k ] = str_replace( '\\n', "\n", trim( $m[2] ) ); } }
		return $out;
	}
}
