<?php
/**
 * Офлайн тест на Config::maybe_migrate_popup (1.24.29): сийдовите съставки/„за кого е“/рейтинг/отзиви се изчистват еднократно, редактираните остават.
 * php tests/popup-migrate-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Config; use AnsaPromo\Seed; use AnsaPromo\Plugin;
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };
set_error_handler( function ( $no, $msg, $file, $line ) { throw new ErrorException( $msg, 0, $no, $file, $line ); } );
$seed = array(); foreach ( Seed::products() as $sp ) { $seed[ $sp['key'] ] = $sp; }
$sak = $seed['sakura']; $sak['who'] = 'моят текст за кого е'; /* редактирано от админа */
$new = array( 'key' => 'eyefresh', 'name' => 'Пачове', 'ing' => '7 натурални съставки', 'who' => '', 'rating' => '', 'reviews' => '' ); /* не е от сийда */
$GLOBALS['opts'][ Plugin::OPTION ] = array( 'draft' => array( 'products' => array( $sak, $seed['greens'], $new ) ), 'published' => array( 'products' => array( $seed['greens'] ) ), 'published_at' => 0, 'version' => 1, 'history' => array() );
Config::maybe_migrate_popup();
$st = $GLOBALS['opts'][ Plugin::OPTION ]; $d = $st['draft']['products']; $pb = $st['published']['products'];
$ok( 'sakura: сийдовите ing/rating/reviews са празни', '' === $d[0]['ing'] && '' === $d[0]['rating'] && '' === $d[0]['reviews'], json_encode( array( $d[0]['ing'], $d[0]['rating'] ), JSON_UNESCAPED_UNICODE ) );
$ok( 'sakura: редактираното „за кого е“ остава', 'моят текст за кого е' === $d[0]['who'] );
$ok( 'sakura: името/описанието не се пипат', $sak['name'] === $d[0]['name'] && $sak['desc'] === $d[0]['desc'] );
$ok( 'greens в черновата: всичко сийдово е празно', '' === $d[1]['ing'] && '' === $d[1]['who'] && '' === $d[1]['rating'] && '' === $d[1]['reviews'] );
$ok( 'greens в публикуваното също', '' === $pb[0]['ing'] && '' === $pb[0]['reviews'] );
$ok( 'продукт извън сийда не се пипа', '7 натурални съставки' === $d[2]['ing'] );
$ok( 'маркерът е „migrated“', 'migrated' === get_option( 'ansa_promo_popup_v1' ) );
/* втори път: нищо не се променя (и редактирано след миграцията не се трие) */
$GLOBALS['opts'][ Plugin::OPTION ]['draft']['products'][1]['ing'] = $seed['greens']['ing'];
Config::maybe_migrate_popup();
$ok( 'втори път не пипа (маркерът спира миграцията)', $seed['greens']['ing'] === $GLOBALS['opts'][ Plugin::OPTION ]['draft']['products'][1]['ing'] );
/* без нищо сийдово → noop */
unset( $GLOBALS['opts']['ansa_promo_popup_v1'] ); $GLOBALS['opts'][ Plugin::OPTION ] = array( 'draft' => array( 'products' => array( $new ) ), 'published' => null, 'published_at' => 0, 'version' => 1, 'history' => array() );
$r = new ReflectionProperty( Config::class, 'memory' ); $r->setAccessible( true ); $r->setValue( null, null );
Config::maybe_migrate_popup();
$ok( 'без сийдови текстове: noop', 'noop' === get_option( 'ansa_promo_popup_v1' ) );
if ( $fail ) { echo "ГРЕШКИ:\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
