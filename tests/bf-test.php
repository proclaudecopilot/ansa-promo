<?php
/**
 * Офлайн тест на „Черен петък 2026“ (1.45.0): BF\Config (фази по дати/force, броячът „записани“, normalize, продуктите) и BF\Copy (регистърът, validate, parse_lines) без WordPress.
 * php tests/bf-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts ) { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( wp_timezone_string() ) )->format( $f ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '' ) { return 'https://ansa.bg' . $p; } }
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( $k, $v = null, $u = null ) { if ( is_array( $k ) ) { $u = $v; $q = $k; } else { $q = array( $k => $v ); } return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $q ); } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = '' ) { return 'salt'; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode() {} }
spl_autoload_register( function ( $c ) { if ( 0 !== strpos( $c, 'AnsaPromo\\BF\\' ) ) { return; } $f = ANSA_PROMO_PATH . 'includes/bf/class-' . strtolower( str_replace( '_', '-', substr( $c, 13 ) ) ) . '.php'; if ( is_file( $f ) ) { require_once $f; } } );
use AnsaPromo\BF\Config;
use AnsaPromo\BF\Copy;
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };
set_error_handler( function ( $no, $msg, $file, $line ) { throw new ErrorException( $msg, 0, $no, $file, $line ); } );
$tz = new DateTimeZone( 'Europe/Sofia' ); $ts = function ( $s ) use ( $tz ) { return ( new DateTimeImmutable( $s, $tz ) )->getTimestamp(); };

/* ── конфиг ── */
$d = Config::normalize( Config::defaults() );
$ok( 'normalize idempotent', Config::normalize( $d ) === $d );
$ok( 'slug по подразбиране cheren-petak-2026', 'cheren-petak-2026' === $d['slug'] );
$ok( 'токенът се генерира сам (≥ 16 знака, [a-z0-9])', strlen( $d['early']['token'] ) >= 16 && preg_match( '/^[a-z0-9]+$/i', $d['early']['token'] ) );
$ok( 'продуктите: 13 (9 за купуване + 4 само подарък и нови)', 13 === count( $d['products'] ) && 4 === count( array_filter( $d['products'], function ( $p ) { return $p['gift_only'] && $p['is_new']; } ) ) );
$ok( 'ключовете на продуктите са уникални', count( $d['products'] ) === count( array_unique( array_column( $d['products'], 'key' ) ) ) );
$ok( 'ваучер 10 € / 30 мин / 17 мин на страницата', 10.0 === (float) $d['cold']['voucher'] && 30 === (int) $d['cold']['minutes'] && 17 === (int) $d['cold']['landing_minutes'] );
$ok( 'партньор: ваучер 5 €, маска MASK01', 5.0 === (float) $d['aff']['voucher'] && 'MASK01' === $d['aff']['mask_sku'] );

/* фазите по датите */
$ph = Config::phase_ts( $d );
$ok( 'early_start = 25.11.2026 10:00 София', $ph['early_start'] === $ts( '2026-11-25 10:00' ) );
$ok( 'преди старта → prep', 'prep' === Config::phase_now( $d, $ts( '2026-11-20 12:00' ) ) );
$ok( 'в ранния достъп → early', 'early' === Config::phase_now( $d, $ts( '2026-11-26 12:00' ) ) );
$ok( 'точно в 27.11 10:00 → public', 'public' === Config::phase_now( $d, $ts( '2026-11-27 10:00' ) ) );
$ok( 'след края → over', 'over' === Config::phase_now( $d, $ts( '2026-11-30 10:00' ) ) );
$ok( 'краят на prep = early_start, на early = early_end, на public = public_end', Config::phase_end( $d, 'prep' ) === $ph['early_start'] && Config::phase_end( $d, 'early' ) === $ph['early_end'] && Config::phase_end( $d, 'public' ) === $ph['public_end'] && 0 === Config::phase_end( $d, 'over' ) );
$f = $d; $f['force'] = 'public'; $ok( 'force=public печели над датите', 'public' === Config::phase_now( $f, $ts( '2026-11-01 00:00' ) ) );
$f['force'] = 'nope'; $ok( 'непознат force пада на „по датите“', '' === Config::normalize( $f )['force'] );
$ok( 'is_live: enabled + early/public', ! Config::is_live( $d ) );
$e = $d; $e['enabled'] = true; $e['force'] = 'early'; $ok( 'is_live при enabled+early', Config::is_live( $e ) );
$e['force'] = 'prep'; $ok( 'не е live в prep', ! Config::is_live( $e ) );

/* броячът „записани“: 312 в ден 0, расте, никога не стига 440 */
$from = $ts( '2026-11-04 00:00' );
$ok( 'ден 0 → 312', 312 === Config::seats_now( $d, $from ) );
$s3 = Config::seats_now( $d, $from + 3 * DAY_IN_SECONDS ); $s7 = Config::seats_now( $d, $from + 7 * DAY_IN_SECONDS ); $s12 = Config::seats_now( $d, $from + 12 * DAY_IN_SECONDS );
$ok( 'расте по дни (3 < 7 < 12)', $s3 > 312 && $s7 > $s3 && $s12 > $s7, "$s3 / $s7 / $s12" );
$ok( 'ден 7 ≈ 382 (440 − 128·e^(−7/9))', abs( $s7 - 382 ) <= 1, (string) $s7 );
$ok( 'ден 365 → max − 1 = 439', 439 === Config::seats_now( $d, $from + 365 * DAY_IN_SECONDS ) );
$ok( 'преди from → 312', 312 === Config::seats_now( $d, $from - 5 * DAY_IN_SECONDS ) );
$off = $d; $off['seats']['enabled'] = false; $ok( 'изключен брояч → 0', 0 === Config::seats_now( $off ) );

/* normalize защитава границите */
$x = $d; $x['cold']['minutes'] = 999999; $x['aff']['cookie_days'] = -3; $x['seats']['max'] = 100; $x['seats']['start'] = 500; $x = Config::normalize( $x );
$ok( 'normalize: минутите на ваучера са ограничени', $x['cold']['minutes'] <= 24 * 60, (string) $x['cold']['minutes'] );
$ok( 'normalize: cookie_days ≥ 1', $x['aff']['cookie_days'] >= 1 );
$ok( 'normalize: start < max при броячa', $x['seats']['start'] < $x['seats']['max'], $x['seats']['start'] . '/' . $x['seats']['max'] );
$ok( 'early_url носи токена', false !== strpos( Config::early_url( $d ), 'cheren-petak-2026/?ea=' . $d['early']['token'] ) );

/* ── копи-регистърът ── */
$reg = Copy::defaults(); $ok( 'регистърът има ≥ 160 ключа', count( $reg ) >= 160, (string) count( $reg ) );
$bad = array(); foreach ( $reg as $k => $v ) { foreach ( Copy::validate( $v ) as $err ) { $bad[] = $k . ': ' . $err; } } $ok( 'всички текстове по подразбиране минават validate', ! $bad, implode( '; ', $bad ) );
$ok( 'ключовете са групирани и уникални', array_sum( array_map( 'count', Copy::registry() ) ) === count( $reg ) && count( Copy::groups() ) === count( Copy::registry() ) );
foreach ( array( 'cold.title', 'cold.vch.title', 'wallet.sub.ok', 'aff.title', 'aff.v.title', 'aff.m.title', 'shop.steps', 'shop.foot.pay', 'shop.save', 'gifts.new', 'gifts.best', 'shop.closed.early.title', 'co.h', 'ty.h', 'err.early', 'over.title' ) as $k ) { $ok( 'ключ ' . $k, isset( $reg[ $k ] ) && '' !== $reg[ $k ] ); }
$ok( 'aff.v.title е само „Ваучер {{aff_voucher}} €“', 'Ваучер {{aff_voucher}} €' === $reg['aff.v.title'] );
$ok( 'wallet.sub.ok е новият текст на човека', 0 === strpos( $reg['wallet.sub.ok'], 'Не чакай промото — започни още днес с нещо малко. Ще ти хареса' ) );
$ok( 'стъпките са Продукти|Подаръци|Финализиране', 'Продукти|Подаръци|Финализиране' === $reg['shop.steps'] );
$eff = Copy::effective( array( 'cold.title' => 'Здравей', 'непознат' => 'x' ) ); $ok( 'effective: override-ът влиза, непознатият ключ не', 'Здравей' === $eff['cold.title'] && ! isset( $eff['непознат'] ) );
$diff = Copy::diff( array( 'cold.title' => 'Здравей', 'cold.sub' => $reg['cold.sub'] ) ); $ok( 'diff: само различните', array( 'cold.title' => 'Здравей' ) === $diff, json_encode( $diff, JSON_UNESCAPED_UNICODE ) );
$lines = Copy::parse_lines( "# коментар\ncold.title = Здравей\n\nshop.steps = А|Б|В\nнепознат = x\nwallet.sub.ok = ред1\\nред2" );
$ok( 'parse_lines: ключ = текст, коментарите и непознатите се прескачат, \\n става нов ред', 'Здравей' === ( $lines['cold.title'] ?? null ) && 'А|Б|В' === ( $lines['shop.steps'] ?? null ) && ! isset( $lines['непознат'] ) && "ред1\nред2" === ( $lines['wallet.sub.ok'] ?? null ), json_encode( $lines, JSON_UNESCAPED_UNICODE ) );
$ok( 'validate хваща непознат плейсхолдър', Copy::validate( 'x {{nqma_takuv}}' ) );
$ok( 'validate хваща небалансирани скоби', Copy::validate( 'x {{voucher' ) );
$ok( 'render слага стойностите', false !== strpos( Copy::render( $reg['cold.seats'], array( 'seats' => 312, 'max' => 440, 'left' => 128 ) ), '312' ) );

if ( $fail ) { echo "ГРЕШКИ:\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
