<?php
/**
 * 1.44.0: темплейтът на /shop/ — header/footer на темата (Elementor Pro header/footer се закачат на get_header/get_footer),
 * между тях — страницата „Всички продукти“ на ansa™ Промо (AnsaPromo\Shop::render).
 */
defined( 'ABSPATH' ) || exit;
get_header();
echo \AnsaPromo\Shop::render(); // phpcs:ignore WordPress.Security.EscapeOutput -- сглобен и escape-нат HTML
get_footer();
