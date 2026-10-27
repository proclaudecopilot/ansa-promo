<?php
/**
 * ansa™ Meta — свързва модулите. Нищо не се закача, ако липсва Pixel ID или token (или ANSA_META_DISABLED).
 *
 * @package AnsaMeta
 */
namespace AnsaMeta;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static $inst;
	public static function instance() { return self::$inst ?: ( self::$inst = new self() ); }

	public function boot() {
		Schema::maybe_upgrade();
		Capi::init();
		Woo::init();
		Frontend::init();
		Rest::init();
		Admin::init();
		Probe::init();
	}

	/** Включен и конфигуриран: Pixel ID + token, настройката „Включено“, без ANSA_META_DISABLED. */
	public static function enabled() {
		if ( defined( 'ANSA_META_DISABLED' ) && ANSA_META_DISABLED ) return false;
		if ( ! Settings::get( 'enabled' ) ) return false;
		return '' !== Settings::pixel_id() && '' !== Settings::token();
	}

	public static function activate() {
		Schema::install();
		Capi::schedule();
	}

	public static function deactivate() {
		Capi::unschedule();
	}
}
