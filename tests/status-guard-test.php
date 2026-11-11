<?php
/**
 * Офлайн тест на пазача на „Получена“ (1.50.0): Status_Guard::should_keep / downgrades / recent / note_patterns без WordPress.
 * php tests/status-guard-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Status_Guard;
$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };
set_error_handler( function ( $no, $msg, $file, $line ) { throw new ErrorException( $msg, 0, $no, $file, $line ); } );

/* #107643: обектът е „получена“, курирът праща „в офис“ → остава; #106576: обектът е „в път“, базата вече е „получена“ → остава */
$ok( 'delivered → at-office се спира', Status_Guard::should_keep( 'wc-delivered', 'at-office' ) );
$ok( 'delivered → shipped се спира', Status_Guard::should_keep( 'delivered', 'wc-shipped' ) );
$ok( 'ръчно от админа минава', ! Status_Guard::should_keep( 'wc-delivered', 'at-office', true ) );
$ok( 'delivered → cancelled / refunded / completed минава (връщане, приключване)', ! Status_Guard::should_keep( 'wc-delivered', 'cancelled' ) && ! Status_Guard::should_keep( 'wc-delivered', 'refunded' ) && ! Status_Guard::should_keep( 'wc-delivered', 'completed' ) );
$ok( 'delivered → delivered не е намеса', ! Status_Guard::should_keep( 'wc-delivered', 'delivered' ) );
$ok( 'shipped → at-office (нормалният път) минава', ! Status_Guard::should_keep( 'wc-shipped', 'at-office' ) );
$ok( 'processing → shipped минава', ! Status_Guard::should_keep( 'wc-processing', 'shipped' ) );
$ok( 'празна база (нова поръчка) минава', ! Status_Guard::should_keep( '', 'at-office' ) );
$ok( 'downgrades по подразбиране: shipped, at-office', array( 'shipped', 'at-office' ) === Status_Guard::downgrades() );
$ok( 'bare маха wc-', 'at-office' === Status_Guard::bare( 'wc-at-office' ) && 'delivered' === Status_Guard::bare( 'delivered' ) );

/* прозорецът: до 60 дни след бележката → нормален преход, после тих запис */
$now = 1800000000;
$ok( 'бележка отпреди 59 дни → преход', Status_Guard::recent( $now - 59 * DAY_IN_SECONDS, $now ) );
$ok( 'бележка отпреди 61 дни → тих запис', ! Status_Guard::recent( $now - 61 * DAY_IN_SECONDS, $now ) );

/* шаблоните на бележката на WC (bg + en), по етикета и буквално Delivered */
$p = Status_Guard::note_patterns( 'Получена' );
$ok( 'шаблони: етикетът и Delivered, bg и en', array( '% на Получена.', '% to Получена.', '% на Delivered.', '% to Delivered.' ) === $p, implode( ' | ', $p ) );
$ok( 'шаблони: етикет Delivered → без дубли', array( '% на Delivered.', '% to Delivered.' ) === Status_Guard::note_patterns( 'Delivered' ) );
$like = function ( $s, $pat ) { return (bool) preg_match( '/^' . str_replace( '%', '.*', preg_quote( $pat, '/' ) ) . '$/su', $s ); };
$ok( 'бележката на WC на български съвпада', $like( 'Състоянието на поръчката е променено от Shipped на Delivered.', '% на Delivered.' ) );
$ok( 'бележката на WC на английски съвпада', $like( 'Order status changed from Shipped to Delivered.', '% to Delivered.' ) );
$ok( '„→ В офис“ не съвпада', ! $like( 'Състоянието на поръчката е променено от Shipped на В офис за получаване.', '% на Delivered.' ) );
$ok( 'бележката на пазача НЕ съвпада (иначе кронът се върти)', ! $like( 'ansa™ Промо: статусът остава „Delivered“ — заявка за „В офис за получаване“ дойде, след като поръчката вече е получена (курирските статуси пристигат едновременно), и беше спряна.', '% на Delivered.' ) );
$ok( 'бележката на връщането НЕ съвпада сама по себе си', ! $like( 'ansa™ Промо: поръчката е върната на „Delivered“ — курирската интеграция прати „В офис за получаване“ и „Delivered“ едновременно и „В офис за получаване“ презаписа (бележката за получаването е от 27.10.2026 11:29).', '% на Delivered.' ) );

if ( $fail ) { echo "FAIL\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
