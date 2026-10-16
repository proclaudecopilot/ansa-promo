<?php
/**
 * ansa™ Промо 1.8.2 — merge tag класовете за FunnelKit Automations ({{ansa_promo_tickets}}, {{ansa_promo_bileta}},
 * {{ansa_promo_tickets_text}}, {{ansa_promo_box}}, 1.9.0: {{ansa_promo_book_url}}, 1.13.0: {{ansa_promo_ref_link}} / {{ansa_promo_ref_code}}, 1.20.0: {{ansa_promo_fav}} / {{ansa_promo_fav_url}}). Зарежда се САМО от AnsaPromo\Merge::fka(), след като Reflection
 * gate-ът е потвърдил, че BWFAN_Merge_Tag е съвместим. Глобален namespace — FKA класовете са глобални.
 * Логиката е в Merge::shortcode(); тук е само обвивката, която FKA очаква (tag_name, parse_shortcode, dummy preview).
 *
 * @package AnsaPromo
 * @since 1.8.2
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BWFAN_Merge_Tag' ) || class_exists( 'Ansa_Promo_FKA_Tag' ) ) { return; }

abstract class Ansa_Promo_FKA_Tag extends BWFAN_Merge_Tag {
	public $tag_name         = '';
	public $tag_description  = '';
	public $support_fallback = true;
	public $priority         = 90;
	/** Ключът в AnsaPromo\Merge::TAGS (tickets / bileta / tickets_text / box). */
	protected $ansa_key  = '';
	protected $ansa_desc = '';
	private static $instances = array();

	public static function get_instance() {
		$c = static::class;
		if ( ! isset( self::$instances[ $c ] ) ) { self::$instances[ $c ] = new $c(); }
		return self::$instances[ $c ];
	}
	public function __construct() {
		$this->tag_name        = 'ansa_promo_' . $this->ansa_key;
		$this->tag_description = $this->ansa_desc;
		/* FKA превръща {{tag}} в [bwfan_tag] и пуска do_shortcode — шорткодът е това, което реално работи */
		add_shortcode( 'bwfan_' . $this->tag_name, array( $this, 'parse_shortcode' ) );
	}
	public function parse_shortcode( $attr = array(), $content = '' ) {
		$attr = is_array( $attr ) ? $attr : array();
		$value = AnsaPromo\Merge::shortcode( $this->ansa_key, $attr );
		if ( method_exists( $this, 'parse_shortcode_output' ) ) { try { return $this->parse_shortcode_output( $value, $attr ); } catch ( \Throwable $e ) { /* fallback-ът е вече приложен в Merge::shortcode */ } }
		return $value;
	}
	public function get_dummy_preview( $attr = array(), $extra = '' ) { return AnsaPromo\Merge::dummy( $this->ansa_key ); }
	/** 1.8.7: parent:: тук е BWFAN_Merge_Tag (не нашата обвивка) — проверява се ТОЙ; ако няма такъв метод, връщаме празна схема (без полета). */
	public function get_setting_schema( $attr = array(), $extra = '' ) {
		if ( self::parent_has( 'get_setting_schema' ) ) { try { return parent::get_setting_schema( $attr, $extra ); } catch ( \Throwable $e ) {} }
		return array();
	}
	public function get_view( $attr = array(), $extra = '' ) {
		if ( self::parent_has( 'get_view' ) ) { try { return parent::get_view( $attr, $extra ); } catch ( \Throwable $e ) {} }
		return '';
	}
	/** Има ли BWFAN_Merge_Tag (родителят на обвивката) реален, неабстрактен метод с това име. */
	protected static function parent_has( $method ) {
		$p = get_parent_class( 'Ansa_Promo_FKA_Tag' ); if ( ! $p || ! method_exists( $p, $method ) ) { return false; }
		try { return ! ( new ReflectionMethod( $p, $method ) )->isAbstract(); } catch ( \Throwable $e ) { return false; }
	}
}

class Ansa_Promo_FKA_Tickets extends Ansa_Promo_FKA_Tag {
	protected $ansa_key = 'tickets'; protected $ansa_desc = 'ansa™ Промо: брой билети за томболата (0 извън играта)';
}
class Ansa_Promo_FKA_Bileta extends Ansa_Promo_FKA_Tag {
	protected $ansa_key = 'bileta'; protected $ansa_desc = 'ansa™ Промо: „билет“ / „билета“ според броя';
}
class Ansa_Promo_FKA_Tickets_Text extends Ansa_Promo_FKA_Tag {
	protected $ansa_key = 'tickets_text'; protected $ansa_desc = 'ansa™ Промо: „Имаш X билета. Ще се активират, щом вземеш пратката си.“ (празно извън играта)';
}
class Ansa_Promo_FKA_Box extends Ansa_Promo_FKA_Tag {
	protected $ansa_key = 'box'; protected $ansa_desc = 'ansa™ Промо: името на кутията от поръчката';
}
class Ansa_Promo_FKA_Book_Url extends Ansa_Promo_FKA_Tag {
	protected $ansa_key = 'book_url'; protected $ansa_desc = 'ansa™ Промо: линкът към книгата с рецепти (/kniga — телефон + имейл от поръчката; празно, ако поръчката няма книга)';
}
/* 1.13.0: личният линк за препоръка на поръчката — работи от момента на заявката (преди пратката), билетите се активират при получаване */
class Ansa_Promo_FKA_Ref_Link extends Ansa_Promo_FKA_Tag {
	protected $ansa_key = 'ref_link'; protected $ansa_desc = 'ansa™ Промо: личният линк за препоръка на поръчката ({промо}?r=КОД — приятелят поръчва през него: ×билети за клиента, +1 билет за приятеля); празно извън играта';
}
class Ansa_Promo_FKA_Ref_Code extends Ansa_Promo_FKA_Tag {
	protected $ansa_key = 'ref_code'; protected $ansa_desc = 'ansa™ Промо: самият код за препоръка (8 знака) от линка; празно извън играта';
}
/* 1.20.0: най-купуваният продукт на човека (по имейла на контакта), с краткото име от картата в 🧰 Настройки → ⭐ Любим продукт */
class Ansa_Promo_FKA_Fav extends Ansa_Promo_FKA_Tag {
	protected $ansa_key = 'fav'; protected $ansa_desc = 'ansa™ Промо: най-купуваният продукт на човека с краткото му име (Daily Greens / Sakura SkinTonik / YoniBiotic / MenoStop / Dihydroberberine / Thyrozen — картата в 🧰 Настройки); празно (или fallback), ако няма поръчки с тези продукти';
}
class Ansa_Promo_FKA_Fav_Url extends Ansa_Promo_FKA_Tag {
	protected $ansa_key = 'fav_url'; protected $ansa_desc = 'ansa™ Промо: линкът към продуктовата страница на най-купувания продукт на човека; празно, ако няма';
}
