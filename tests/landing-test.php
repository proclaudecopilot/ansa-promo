<?php
/**
 * Офлайн тест на кацанията (v1.24.0): Config::normalize (landings), Config::resolve_landing (кацане → продукт → непознато), Copy::with_landing
 * (дефолт < основа < седмица < кацане), Doctor::landing_checks — без WordPress.
 * php tests/landing-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Config; use AnsaPromo\Copy; use AnsaPromo\Doctor; use AnsaPromo\Seed;
if ( ! function_exists( 'wp_date' ) ) { function wp_date( $f, $ts ) { return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( new DateTimeZone( wp_timezone_string() ) )->format( $f ); } }
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };
set_error_handler( function ( $no, $msg, $file, $line ) { throw new ErrorException( $msg, 0, $no, $file, $line ); } );

list( $seed ) = Seed::build( false ); $base = Config::normalize( $seed );
$prods = array(); foreach ( $base['products'] as $p ) { if ( $p['enabled'] ) { $prods[ $p['key'] ] = $p; } }
$ok( 'seed има продукти greens и sakura', isset( $prods['greens'] ) && isset( $prods['sakura'] ) );

/* ── normalize ── */
$cfg = $base; $cfg['landings'] = array(
	array( 'slug' => ' Kozmetika! ', 'name' => 'Козметика', 'desc' => 'FB', 'enabled' => true, 'core' => '', 'box' => 'l', 'know' => 'no', 'gate' => 'default',
		'copy' => array( 'gate.title.noutm' => 'Козметиката за <s>€{{cosm_value}}</s> €{{cosm_pay_l}}', 'emails.foo' => 'x', 'tb.phone.label' => 'не може', 'fill.title' => '', 'nope.key' => 'y' ) ),
	array( 'slug' => 'greens', 'name' => '', 'core' => 'greens', 'box' => 'm', 'know' => 'yes', 'gate' => 'off', 'copy' => array( 'gate.title' => 'Ти реши да опиташ {{core_full}}!' ) ),
	array( 'slug' => 'greens', 'name' => 'дубликат' ),
	array( 'slug' => '', 'name' => 'без slug' ),
	array( 'slug' => 'bad', 'core' => 'nqma-takuv', 'box' => 'xl', 'know' => 'maybe', 'gate' => 'yes' ),
);
$c = Config::normalize( $cfg ); $ls = $c['landings']; $by = array(); foreach ( $ls as $l ) { $by[ $l['slug'] ] = $l; }
$ok( 'normalize: 3 кацания (дубликат и празен slug падат)', 3 === count( $ls ), json_encode( array_keys( $by ) ) );
$ok( 'slug се чисти: „ Kozmetika! “ → kozmetika', isset( $by['kozmetika'] ) );
$ok( 'копи: само познати ключове от разрешените групи, без празни', array( 'gate.title.noutm' ) === array_keys( $by['kozmetika']['copy'] ), json_encode( array_keys( $by['kozmetika']['copy'] ) ) );
$ok( 'име по подразбиране = slug', 'greens' === $by['greens']['name'] );
$ok( 'greens: core/box/know/gate се пазят', 'greens' === $by['greens']['core'] && 'm' === $by['greens']['box'] && 'yes' === $by['greens']['know'] && 'off' === $by['greens']['gate'] );
$ok( 'bad: невалидните стойности падат на дефолта', '' === $by['bad']['core'] && '' === $by['bad']['box'] && 'ask' === $by['bad']['know'] && 'default' === $by['bad']['gate'] );
$ok( 'enabled по подразбиране true', true === $by['bad']['enabled'] );

/* ── резолюция: кацане → продукт → непознато ── */
$r = Config::resolve_landing( $c, 'KOZMETIKA', $prods );
$ok( 'resolve: кацане (без core)', 'kozmetika' === $r['utm'] && $r['landing'] && 'kozmetika' === $r['landing']['slug'] && '' === $r['core'] && '' === $r['unknown'] );
$r = Config::resolve_landing( $c, 'greens', $prods );
$ok( 'resolve: кацане greens печели над продукта greens и носи core', 'greens' === $r['utm'] && $r['landing'] && 'greens' === $r['core'] );
$r = Config::resolve_landing( $c, 'sakura', $prods );
$ok( 'resolve: продукт без кацане = имплицитно кацане', 'sakura' === $r['utm'] && null === $r['landing'] && 'sakura' === $r['core'] );
$r = Config::resolve_landing( $c, 'kozmetica', $prods );
$ok( 'resolve: непозната стойност → основа + unknown', '' === $r['utm'] && null === $r['landing'] && '' === $r['core'] && 'kozmetica' === $r['unknown'] );
$r = Config::resolve_landing( $c, '', $prods ); $ok( 'resolve: празно → нищо', '' === $r['utm'] && '' === $r['unknown'] );
$c2 = $c; foreach ( $c2['landings'] as &$l ) { if ( 'kozmetika' === $l['slug'] ) { $l['enabled'] = false; } } unset( $l );
$r = Config::resolve_landing( $c2, 'kozmetika', $prods ); $ok( 'resolve: изключено кацане = непознато на живо', '' === $r['utm'] && 'kozmetika' === $r['unknown'] );
$r = Config::resolve_landing( $c2, 'kozmetika', $prods, true ); $ok( 'resolve: изключено кацане се вижда в черновата ($all)', $r['landing'] && 'kozmetika' === $r['utm'] );

/* ── копи: дефолт < основа < кацане ── */
$c3 = $c; $c3['copy'] = array( 'gate.title.noutm' => 'ОСНОВА', 'boxes.h1.noutm' => 'ОСНОВА H1' );
$eff = Copy::effective( $c3['copy'] ); $withL = Copy::with_landing( $eff, $by['kozmetika'] );
$ok( 'кацането печели над основата', 'Козметиката за <s>€{{cosm_value}}</s> €{{cosm_pay_l}}' === $withL['gate.title.noutm'] );
$ok( 'ключ без override наследява основата', 'ОСНОВА H1' === $withL['boxes.h1.noutm'] );
$ok( 'ключ без нищо = дефолт', Copy::defaults()['gate.cta'] === $withL['gate.cta'] );
$ok( 'gate.sub е ключ с {{core_gsub}} (override-ва се по кацане)', '{{core_gsub}}' === Copy::defaults()['gate.sub'] && in_array( 'core_gsub', Copy::vars(), true ) );
$ok( 'landing_groups не включва общите групи', ! array_intersect( array( 'emails', 'checkout', 'tombola', 'book', 'how' ), Copy::landing_groups() ) );

/* ── Doctor ── */
$ch = Doctor::landing_checks( $c ); $byid = array(); foreach ( $ch as $x ) { $byid[ $x['id'] ] = $x; }
$ok( 'Doctor: greens (slug = продукт, core = същият) НЕ е ⛔', isset( $byid['landing_greens'] ) ? 'bad' !== $byid['landing_greens']['level'] : true, json_encode( $byid['landing_greens'] ?? null ) );
$ok( 'Doctor: bad = „нищо не променя“ ⚠', isset( $byid['landing_bad'] ) && 'warn' === $byid['landing_bad']['level'] && false !== strpos( $byid['landing_bad']['msg'], 'безсмислено' ) );
$c4 = $c; $c4['landings'][] = array( 'slug' => 'sakura', 'name' => 'Сакура но грийнс', 'core' => 'greens', 'enabled' => true, 'box' => '', 'know' => 'ask', 'gate' => 'default', 'copy' => array( 'fill.title' => 'Сет за €1 и −40%' ) );
$c4['landings'][] = array( 'slug' => 'smallcore', 'name' => 'Малка + основен', 'core' => 'greens', 'box' => 's', 'enabled' => true, 'know' => 'ask', 'gate' => 'default', 'copy' => array( 'gate.title' => 'x {{nqma}}' ) );
$c4 = Config::normalize( $c4 ); $ch = Doctor::landing_checks( $c4, array( 'bad' => array( 'n' => 5, 'orders' => 0 ) ) ); $byid = array(); foreach ( $ch as $x ) { $byid[ $x['id'] ] = $x; }
$ok( 'Doctor: slug на продукт с друг core = ⛔', isset( $byid['landing_sakura'] ) && 'bad' === $byid['landing_sakura']['level'], json_encode( $byid['landing_sakura'] ?? null ) );
$ok( 'Doctor: число до €/% в override → в ⛔/⚠ текста', isset( $byid['landing_sakura'] ) && false !== strpos( $byid['landing_sakura']['msg'], 'число' ) );
$ok( 'Doctor: непознат плейсхолдър = ⛔', isset( $byid['landing_smallcore'] ) && 'bad' === $byid['landing_smallcore']['level'] && false !== strpos( $byid['landing_smallcore']['msg'], 'плейсхолдър' ) );
$ok( 'Doctor: основен продукт + малка кутия → „Честито“ без избор (в текста)', isset( $byid['landing_smallcore'] ) && false !== strpos( $byid['landing_smallcore']['msg'], 'пълна веднага' ) );
$c5 = $c4; foreach ( $c5['landings'] as &$l ) { if ( 'bad' === $l['slug'] ) { $l['enabled'] = false; } } unset( $l );
$ch = Doctor::landing_checks( $c5, array( 'bad' => array( 'n' => 5, 'orders' => 0 ) ) ); $byid = array(); foreach ( $ch as $x ) { $byid[ $x['id'] ] = $x; }
$ok( 'Doctor: изключено кацане с трафик = ⚠', isset( $byid['landing_bad'] ) && 'warn' === $byid['landing_bad']['level'] && false !== strpos( $byid['landing_bad']['msg'], 'посещения' ), json_encode( $byid['landing_bad'] ?? null ) );
$ok( 'Doctor: без кацания = без редове', array() === Doctor::landing_checks( $base ) );

if ( $fail ) { echo "FAIL\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
