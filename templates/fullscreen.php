<?php
/**
 * Template Name: ansa™ Промо — цял екран
 *
 * Регистрира се от плъгина (theme_page_templates). Без хедър/футър на темата: само wp_head, съдържанието и wp_footer.
 * Плаващите бутони, които темата/плъгините добавят в wp_footer, се крият през настройката „Скрий елементите на темата“.
 *
 * @package AnsaPromo
 * @since 1.0.3
 */
defined( 'ABSPATH' ) || exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'ansa-promo-fullscreen ansa-promo-page' ); ?>>
<?php wp_body_open(); ?>
<?php while ( have_posts() ) { the_post(); the_content(); } ?>
<?php wp_footer(); ?>
</body>
</html>
