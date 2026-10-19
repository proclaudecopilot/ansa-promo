<?php
/**
 * ansa™ Промо — стоп на имейлите от FunnelKit (1.24.0, искане на човека: „ansa-promo изпраща имейли за книга, томбола,
 * козметичен сет — трябва да ги спрем, изпращат се от FunnelKit автоматично“).
 *
 * Плъгинът не изпраща тези имейли сам. Той слага ТАГОВЕ на FunnelKit контакта — при промо поръчка „Сезонът на Анса“ + тага
 * на кутията (Merge::tag_order), при имейл от попъпа лист + таг sezon-lead (Lead_Sync::sync) — а автоматизациите във
 * FunnelKit Automations (тригър „Tag Added“ / „Contact Added to List“) изпращат писмата за книгата, томболата и сета.
 * Тук е ключът: докато имейлите са СПРЕНИ, плъгинът не тагва нищо и автоматизациите нямат с какво да тръгнат.
 *
 * Отделна опция (`ansa_promo_fka_emails` = 'on' | 'off'), без публикуване — действа веднага. По подразбиране СПРЕНИ
 * (липсваща опция = off): ъпдейтът до 1.24.0 ги спира без клик. Включват се от 🧰 Инструменти → „✉️ Имейлите от
 * FunnelKit“ или с константата ANSA_PROMO_FKA_EMAILS (true/false) в wp-config.php, която има предимство пред опцията.
 *
 * Какво НЕ спира (и защо): автоматизации, които вече са тръгнали за контакт, тагнат ПРЕДИ спирането (стъпка Delay →
 * Send Email) — те се спират във FunnelKit → Automations (Inactive). Свалянето на тага на лийда при поръчка
 * (Lead_Sync::on_order) продължава да работи — то спира писма, не праща. Поръчките/лийдовете, минали докато е спряно, не
 * получават meta/отметка „тагнато“, така че при включване бутоните „🏷️ Сложи тага“ / „📨 Синхронизирай“ ги намират.
 *
 * @package AnsaPromo
 * @since 1.24.0
 */
namespace AnsaPromo;

defined( 'ABSPATH' ) || exit;

final class FKA_Pause {

	const OPT = 'ansa_promo_fka_emails';

	/** Включени ли са имейлите от FunnelKit (= плъгинът слага таговете). Константата бие опцията; липсваща опция = спрени. */
	public static function enabled() {
		if ( defined( 'ANSA_PROMO_FKA_EMAILS' ) ) { return (bool) constant( 'ANSA_PROMO_FKA_EMAILS' ); }
		return 'on' === (string) get_option( self::OPT, 'off' );
	}
	public static function paused() { return ! self::enabled(); }
	/** Зададено ли е с константата (тогава чекбоксът е заключен). */
	public static function by_const() { return defined( 'ANSA_PROMO_FKA_EMAILS' ); }

	public static function save( $on ) {
		update_option( self::OPT, $on ? 'on' : 'off', false );
		return self::enabled();
	}

	/** [текст, ниво] за Doctor / 🧰 Инструменти. */
	public static function status() {
		$how = self::by_const() ? ' (константа ANSA_PROMO_FKA_EMAILS)' : '';
		if ( self::paused() ) { return array( 'СПРЕНИ' . $how . ' — плъгинът не слага таговете „Сезонът на Анса“ / по кутия / sezon-lead на контактите, автоматизациите във FunnelKit (книга, томбола, сет) не тръгват за нови поръчки и лийдове', 'warn' ); }
		return array( 'включени' . $how . ' — при промо поръчка и лийд контактът получава таговете, автоматизациите във FunnelKit тръгват', 'ok' );
	}
}
