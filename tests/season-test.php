<?php
/**
 * Офлайн тест на седмиците на сезона (1.19.0) с край по избор (1.22.0): Config::season_weeks / season_count / week_for / normalize без WordPress.
 * php tests/season-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Config;
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts ) { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( wp_timezone_string() ) )->format( $f ); } }
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };
set_error_handler( function ( $no, $msg, $file, $line ) { throw new ErrorException( $msg, 0, $no, $file, $line ); } );
$tz = new DateTimeZone( 'Europe/Sofia' ); $ts = function ( $s ) use ( $tz ) { return ( new DateTimeImmutable( $s, $tz ) )->getTimestamp(); };
$cfg = function ( $weeks, $start = '2026-10-16', $deadline = '2026-12-31 23:59' ) { return Config::normalize( array( 'deadline' => $deadline, 'season' => array( 'weekly' => true, 'start' => $start, 'grace_hours' => 24, 'weeks' => $weeks ) ) ); };
$fmt = function ( $rows ) { return implode( ' | ', array_map( function ( $r ) { return $r['i'] . ':' . wp_date( 'd.m', $r['from'] ) . '-' . wp_date( 'd.m', $r['to'] - 60 ) . ( $r['to_custom'] ? '*' : '' ); }, $rows ) ); };

/* по подразбиране: 11 седмици по 7 дни от 16.10 до 31.12 (както на екрана на човека) */
$c = $cfg( array() ); $rows = Config::season_weeks( $c );
$ok( 'по подразбиране 11 седмици', 11 === count( $rows ) && 11 === Config::season_count( $c ), $fmt( $rows ) );
$ok( 'седмица 1: пт 16.10 00:00 – 23.10 00:00', $rows[0]['from'] === $ts( '2026-10-16 00:00' ) && $rows[0]['to'] === $ts( '2026-10-23 00:00' ) && empty( $rows[0]['to_custom'] ) );
$ok( 'седмица 2 минава през DST (25.10) и пак е до 00:00', $rows[1]['to'] === $ts( '2026-10-30 00:00' ) );
$ok( 'последната: 25.12 – срока', $rows[10]['from'] === $ts( '2026-12-25 00:00' ) && $rows[10]['to'] === Config::deadline_ts( $c ) );

/* краят на седмица 1 = 20.10 → седмица 2 започва 21.10 (човекът: „първата да приключи 20.10 и после от 21.10“) */
$c = $cfg( array( array( 'to' => '2026-10-20' ) ) ); $rows = Config::season_weeks( $c );
$ok( 'седмица 1 до 20.10 вкл. (5 дни), със „избран край“', $rows[0]['to'] === $ts( '2026-10-21 00:00' ) && ! empty( $rows[0]['to_custom'] ) && empty( $rows[0]['to_bad'] ), $fmt( $rows ) );
$ok( 'седмица 2 от 21.10 00:00 до 28.10 00:00 (7 дни по подразбиране)', $rows[1]['from'] === $ts( '2026-10-21 00:00' ) && $rows[1]['to'] === $ts( '2026-10-28 00:00' ) );
$ok( 'броят се преизчислява: 12 седмици, последната 30.12 – 31.12', 12 === count( $rows ) && $rows[11]['from'] === $ts( '2026-12-30 00:00' ) && $rows[11]['to'] === Config::deadline_ts( $c ), $fmt( $rows ) );
$ok( 'week_for: 20.10 23:00 е седмица 1, 21.10 00:00 е седмица 2', 1 === Config::week_for( $c, $ts( '2026-10-20 23:00' ) )['i'] && 2 === Config::week_for( $c, $ts( '2026-10-21 00:00' ) )['i'] );
$w = Config::week_for( $c, $ts( '2026-10-18 12:00' ) ); $ok( 'week_for: next.from = 21.10, days_left 3', $w['next']['from'] === $ts( '2026-10-21 00:00' ) && 3 === $w['days_left'], json_encode( array( $w['days_left'] ) ) );

/* по-дълга и по-кратка: 1 = 16–20.10 (5 дни), 2 = 21.10–1.11 (12 дни), 3 = 2.11–8.11 (7), … */
$c = $cfg( array( array( 'to' => '2026-10-20', 's' => 15 ), array( 'to' => '2026-11-01', 's' => 14 ), array( 's' => 13 ) ) ); $rows = Config::season_weeks( $c );
$ok( 'седмица 2 от 21.10 до 1.11 вкл. (12 дни, през DST), седмица 3 от 2.11 до 8.11', $rows[1]['from'] === $ts( '2026-10-21 00:00' ) && $rows[1]['to'] === $ts( '2026-11-02 00:00' ) && $rows[2]['from'] === $ts( '2026-11-02 00:00' ) && $rows[2]['to'] === $ts( '2026-11-09 00:00' ), $fmt( $rows ) );
$ok( 'процентите остават по ред на седмицата', 15.0 === $rows[0]['pct']['s'] && 14.0 === $rows[1]['pct']['s'] && 13.0 === $rows[2]['pct']['s'] && $rows[3]['pct']['s'] === (float) $c['boxes'][0]['pct'] );
$ok( 'общо 11 седмици (5 + 12 + 9×7 = 77 дни = 16.10–31.12)', 11 === count( $rows ) && $rows[10]['to'] === Config::deadline_ts( $c ), $fmt( $rows ) );

/* край преди началото → 1 ден + флаг; край след срока → последна, отрязана до срока */
$c = $cfg( array( array(), array( 'to' => '2026-10-10' ) ) ); $rows = Config::season_weeks( $c );
$ok( 'край преди началото: седмица 2 е 1 ден (23.10) с to_bad', $rows[1]['from'] === $ts( '2026-10-23 00:00' ) && $rows[1]['to'] === $ts( '2026-10-24 00:00' ) && ! empty( $rows[1]['to_bad'] ) && $rows[2]['from'] === $ts( '2026-10-24 00:00' ), $fmt( $rows ) );
$c = $cfg( array( array( 'to' => '2027-01-15' ) ) ); $rows = Config::season_weeks( $c );
$ok( 'край след срока: една седмица до срока', 1 === count( $rows ) && $rows[0]['to'] === Config::deadline_ts( $c ) && ! empty( $rows[0]['to_custom'] ) );
$c = $cfg( array( array( 'to' => '2026-12-31' ) ) ); $rows = Config::season_weeks( $c );
$ok( 'край = денят на срока: една седмица, не се появява празна 0-дневна', 1 === count( $rows ) && $rows[0]['to'] === Config::deadline_ts( $c ), $fmt( $rows ) );

/* без срок: толкова седмици, колкото реда; краят по избор важи */
$c = $cfg( array( array( 'to' => '2026-10-20' ), array(), array() ), '2026-10-16', '' ); $rows = Config::season_weeks( $c );
$ok( 'без срок: 3 седмици, 1 = 5 дни, 3 = 28.10–4.11', 3 === count( $rows ) && $rows[0]['to'] === $ts( '2026-10-21 00:00' ) && $rows[2]['from'] === $ts( '2026-10-28 00:00' ) && $rows[2]['to'] === $ts( '2026-11-04 00:00' ), $fmt( $rows ) );

/* normalize: to се пази само като валидна дата */
$c = $cfg( array( array( 'to' => '2026-10-20', 's' => '15' ), array( 'to' => '20.10.2026' ), array( 'to' => '2026-02-30' ), array( 'to' => "x' OR 1" ) ) );
$ok( 'normalize: валидна дата остава, невалидни стават празни', '2026-10-20' === $c['season']['weeks'][0]['to'] && '' === $c['season']['weeks'][1]['to'] && '' === $c['season']['weeks'][2]['to'] && '' === $c['season']['weeks'][3]['to'] && 15.0 === $c['season']['weeks'][0]['s'], json_encode( $c['season']['weeks'] ) );
$c2 = Config::normalize( array( 'deadline' => '2026-12-31 23:59', 'season' => array( 'weekly' => true, 'start' => '2026-10-16', 'weeks' => array( array( 's' => 15, 'm' => 30, 'l' => 45 ) ) ) ) );
$ok( 'стар конфиг без to: седмиците са по 7 дни, както досега', '' === $c2['season']['weeks'][0]['to'] && 11 === count( Config::season_weeks( $c2 ) ) );

/* Config::at + box_pct_at през границата по избор */
$c = $cfg( array( array( 'to' => '2026-10-20', 's' => 15, 'm' => 32, 'l' => 45 ), array( 's' => 15, 'm' => 30, 'l' => 41 ) ) );
$ok( 'box_pct_at: голямата кутия е −45 на 20.10 и −41 на 21.10', 45.0 === Config::box_pct_at( $c, 'l', $ts( '2026-10-20 18:00' ) ) && 41.0 === Config::box_pct_at( $c, 'l', $ts( '2026-10-21 00:00' ) ) );
$at = Config::at( $c, $ts( '2026-10-21 09:00' ) ); $ok( 'Config::at: season.cur = седмица 2, total 12', 2 === (int) $at['season']['cur']['i'] && 12 === (int) $at['season']['cur']['total'] );

if ( $fail ) { echo "FAIL\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
