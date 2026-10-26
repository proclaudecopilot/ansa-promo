<?php
/**
 * Офлайн тест на Insights_Portal (1.27.0) — отделният адрес на 📈 Insights за екипа по реклами: настройки (начален акаунт, слъг, потребител,
 * политика за паролата), вход (hash_equals + password_verify), лимитите срещу brute force (по IP и общо), дневникът, подписаната сесия
 * (срок · браузър · поколение · подправяне), маршрутът, HTTPS. Без WordPress — опциите са в масив.
 * php tests/insights-portal-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaPromo\Insights_Portal; use AnsaPromo\Insights;

function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function home_url( $p = '/' ) { return 'https://ansa.test' . $p; }
function add_query_arg( $a, $u ) { return $u . ( strpos( $u, '?' ) === false ? '?' : '&' ) . http_build_query( $a ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_salt( $s = '' ) { return 'test-salt-' . $s; }
function is_user_logged_in() { return false; }
function current_user_can( $c ) { return false; }
function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } function esc_attr( $s ) { return esc_html( $s ); } function esc_url( $u ) { return (string) $u; }
function admin_url( $p = '' ) { return 'https://ansa.test/wp-admin/' . $p; } function wp_create_nonce( $a ) { return 'n0nce'; }
$_SERVER['REMOTE_ADDR'] = '203.0.113.7'; $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test)'; $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

$fail = array(); $n = 0; $ok = function ( $name, $cond, $info = '' ) use ( &$fail, &$n ) { $n++; if ( ! $cond ) { $fail[] = $name . ( '' !== $info ? ' — ' . $info : '' ); } };
set_error_handler( function ( $no, $msg, $file, $line ) { throw new ErrorException( $msg, 0, $no, $file, $line ); } );
$NOW = 1_800_000_000;

/* ── начален акаунт ── */
$s = Insights_Portal::settings();
$ok( 'начално: включен, promo-insights, adminpromo, gen 1, 12 ч, само HTTPS', $s['enabled'] && 'promo-insights' === $s['slug'] && 'adminpromo' === $s['user'] && 1 === $s['gen'] && 12 === $s['ttl'] && $s['https_only'], json_encode( $s ) );
$ok( 'началният хеш е bcrypt, паролата не е в кода', 0 === strpos( $s['hash'], '$2y$12$' ) && false === strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-insights-portal.php' ), 'sa*DC' ) );
$ok( 'опцията е записана веднъж (created_at)', isset( $GLOBALS['opts'][ Insights_Portal::OPT ] ) && $s['created_at'] > 0 && ! $s['changed_at'] );
$ok( 'status: предупреждава за началната парола', 'warn' === Insights_Portal::status()[1] && false !== strpos( Insights_Portal::status()[0], 'НАЧАЛНАТА' ) );
$ok( 'url: https://ansa.test/promo-insights/ · с аргументи · без tab', 'https://ansa.test/promo-insights/' === Insights_Portal::url() && 'https://ansa.test/promo-insights/?view=live' === Insights_Portal::url( array( 'tab' => 'insights', 'view' => 'live', 'x' => '' ) ), Insights_Portal::url( array( 'tab' => 'insights', 'view' => 'live' ) ) );

/* ── нормализации ── */
$ok( 'norm_slug', 'promo-insights' === Insights_Portal::norm_slug( ' Promo Insights/ ' ) && 'promo-insights' === Insights_Portal::norm_slug( '' ) && 'promo-insights' === Insights_Portal::norm_slug( 'wp-admin' ) && 'ads/stats' === Insights_Portal::norm_slug( '/ads//stats/' ), Insights_Portal::norm_slug( '/ads//stats/' ) );
$ok( 'norm_user', 'adminpromo' === Insights_Portal::norm_user( ' AdminPromo ' ) && '' === Insights_Portal::norm_user( 'ab' ) && '' === Insights_Portal::norm_user( 'bad name!' ) && 'a.b_c-1' === Insights_Portal::norm_user( 'a.b_c-1' ) );
$_SERVER['REQUEST_URI'] = '/promo-insights/?view=live'; $ok( 'request_matches: от REQUEST_URI, с query string', Insights_Portal::request_matches() && Insights_Portal::request_matches( '/promo-insights' ) && ! Insights_Portal::request_matches( '/promo-insights/x' ) && ! Insights_Portal::request_matches( '/' ) );
$ok( 'matches: пътят със/без наклонени черти, без „почти“', Insights_Portal::matches( 'promo-insights' ) && Insights_Portal::matches( '/Promo-Insights/' ) && ! Insights_Portal::matches( 'promo-insightsx' ) && ! Insights_Portal::matches( 'promo-insights/x' ) && ! Insights_Portal::matches( '' ) );

/* ── политика за паролата ── */
$ok( 'парола: къса', true !== Insights_Portal::password_policy( 'short1!', 'short1!' ) );
$ok( 'парола: несъвпадащи', true !== Insights_Portal::password_policy( 'LongEnough-Pass-1', 'LongEnough-Pass-2' ) );
$ok( 'парола: съдържа потребителя', true !== Insights_Portal::password_policy( 'xx-adminpromo-xx', 'xx-adminpromo-xx', 'adminpromo' ) );
$ok( 'парола: прекалено проста', true !== Insights_Portal::password_policy( 'aaaaaaaaaaaaaa', 'aaaaaaaaaaaaaa' ) && true !== Insights_Portal::password_policy( 'password12345', 'password12345' ) );
$ok( 'парола: добра', true === Insights_Portal::password_policy( 'Correct-Horse-9-Battery', 'Correct-Horse-9-Battery', 'adminpromo' ) );

/* ── save ── */
list( $ok1, $m1 ) = Insights_Portal::save( array( 'enabled' => 1, 'slug' => 'promo-insights', 'user' => 'bad name', 'https_only' => 1, 'ttl' => 12 ) );
$ok( 'save: невалиден потребител → грешка, нищо не се пипа', ! $ok1 && 'adminpromo' === Insights_Portal::settings()['user'], $m1 );
list( $ok1, $m1 ) = Insights_Portal::save( array( 'enabled' => 1, 'slug' => 'promo-insights', 'user' => 'adminpromo', 'https_only' => 1, 'ttl' => 12, 'password' => 'short', 'password2' => 'short' ) );
$ok( 'save: слаба парола → грешка, хешът остава', ! $ok1 && Insights_Portal::DEFAULT_HASH === Insights_Portal::settings()['hash'], $m1 );
$PW = 'Correct-Horse-9-Battery';
list( $ok1, $m1 ) = Insights_Portal::save( array( 'enabled' => 1, 'slug' => 'Reklama Stats', 'user' => 'adminpromo', 'https_only' => 1, 'ttl' => 4, 'password' => $PW, 'password2' => $PW ) );
$s = Insights_Portal::settings();
$ok( 'save: нова парола → bcrypt хеш, gen 2, changed_at, слъг reklama-stats, 4 ч', $ok1 && password_verify( $PW, $s['hash'] ) && 2 === $s['gen'] && $s['changed_at'] > 0 && 'reklama-stats' === $s['slug'] && 4 === $s['ttl'], $m1 . ' ' . json_encode( $s ) );
$ok( 'save: дневникът има pwchange', 'pwchange' === Insights_Portal::log_rows()[0]['res'] );
$ok( 'status: ok след смяната', 'ok' === Insights_Portal::status()[1], Insights_Portal::status()[0] );
$ok( 'url следва слъга', 'https://ansa.test/reklama-stats/' === Insights_Portal::url() );
list( $ok1, $m1 ) = Insights_Portal::save( array( 'enabled' => 1, 'slug' => 'reklama-stats', 'user' => 'reklama', 'https_only' => 1, 'ttl' => 4 ) );
$ok( 'save: смяна на потребителя → gen 3, паролата остава', $ok1 && 'reklama' === Insights_Portal::settings()['user'] && 3 === Insights_Portal::settings()['gen'] && password_verify( $PW, Insights_Portal::settings()['hash'] ), $m1 );
$USER = 'reklama';

/* ── вход ── */
$IP = '203.0.113.7';
$r = Insights_Portal::attempt( $USER, $PW, $IP, $NOW ); $ok( 'вход: верни данни → ok', $r['ok'] && 'ok' === $r['code'], json_encode( $r ) );
$r = Insights_Portal::attempt( ' Reklama ', $PW, $IP, $NOW ); $ok( 'вход: името не зависи от регистъра/интервалите', $r['ok'] );
$r = Insights_Portal::attempt( $USER, 'wrong-password-123', $IP, $NOW ); $ok( 'вход: грешна парола → bad, без блок', ! $r['ok'] && 'bad' === $r['code'] && 0 === $r['wait'], json_encode( $r ) );
$r = Insights_Portal::attempt( 'someone', $PW, $IP, $NOW ); $ok( 'вход: грешно име с вярна парола → bad (същото съобщение)', ! $r['ok'] && 'bad' === $r['code'] );
$r = Insights_Portal::attempt( $USER, '', $IP, $NOW ); $ok( 'вход: празна парола → bad', ! $r['ok'] && 'bad' === $r['code'] );
$ok( 'дневникът: ok · bad · bad · bad отгоре надолу', array( 'bad', 'bad', 'bad', 'ok', 'ok' ) === array_slice( array_column( Insights_Portal::log_rows(), 'res' ), 0, 5 ), implode( ',', array_column( Insights_Portal::log_rows(), 'res' ) ) );
$ok( 'дневникът пази IP-то и името', $IP === Insights_Portal::log_rows()[0]['ip'] && 'reklama' === Insights_Portal::log_rows()[0]['user'] );
$r = Insights_Portal::attempt( $USER, 'wrong-4', $IP, $NOW + 10 ); $ok( '4-ти грешен → още bad', 'bad' === $r['code'] );
$r = Insights_Portal::attempt( $USER, 'wrong-5', $IP, $NOW + 20 ); $ok( '5-ти грешен от IP → блок 15 мин', ! $r['ok'] && 'locked' === $r['code'] && 15 * MINUTE_IN_SECONDS === $r['wait'], json_encode( $r ) );
$r = Insights_Portal::attempt( $USER, $PW, $IP, $NOW + 30 ); $ok( 'докато трае блокът и вярната парола не минава (не се проверява)', ! $r['ok'] && 'locked' === $r['code'] && $r['wait'] > 0, json_encode( $r ) );
$ok( 'locked(): остатъкът пада с времето', Insights_Portal::locked( $IP, $NOW + 20 + 10 * MINUTE_IN_SECONDS ) === 5 * MINUTE_IN_SECONDS );
$ok( 'locked(): друг IP е свободен', 0 === Insights_Portal::locked( '198.51.100.9', $NOW + 30 ) );
$lk = Insights_Portal::locks( $NOW + 30 ); $ok( 'locks(): блокираният IP е в списъка; опитът по време на блока се брои (n 6), но не удължава', 1 === count( $lk['ips'] ) && $IP === $lk['ips'][0]['ip'] && 6 === $lk['ips'][0]['n'] && $NOW + 20 + 15 * MINUTE_IN_SECONDS === $lk['ips'][0]['until'] && 0 === $lk['all_until'], json_encode( $lk ) );
$r = Insights_Portal::attempt( $USER, $PW, $IP, $NOW + 20 + 15 * MINUTE_IN_SECONDS + 1 ); $ok( 'след 15 мин → вярната парола минава и броячът на IP се чисти', $r['ok'] && 0 === Insights_Portal::locked( $IP, $NOW + 20 + 15 * MINUTE_IN_SECONDS + 2 ), json_encode( $r ) );
/* ескалация: 10 → 1 час */
for ( $i = 0; $i < 10; $i++ ) { $r = Insights_Portal::attempt( $USER, 'w' . $i, $IP, $NOW + 3600 + $i ); }
$ok( '10 грешни → блок 1 час', 'locked' === $r['code'] && HOUR_IN_SECONDS === $r['wait'], json_encode( $r ) );
Insights_Portal::clear_ip( $IP ); $ok( 'clear_ip отключва този IP', 0 === Insights_Portal::locked( $IP, $NOW + 3600 ) );
/* общият блок: 25 грешни от различни IP за 15 мин → всички чакат */
$T = $NOW + 2 * HOUR_IN_SECONDS;
for ( $i = 1; $i <= 24; $i++ ) { $r = Insights_Portal::attempt( $USER, 'x', '10.0.' . $i . '.1', $T + $i ); }
$ok( '24 грешни от 24 IP → още няма общ блок', 'bad' === $r['code'] && 0 === Insights_Portal::locked( '192.0.2.1', $T + 30 ), json_encode( $r ) );
$r = Insights_Portal::attempt( $USER, 'x', '10.0.25.1', $T + 25 );
$ok( '25-ти → общ блок 15 мин за всички', 'locked' === $r['code'] && Insights_Portal::locked( '192.0.2.1', $T + 30 ) > 0 && Insights_Portal::locks( $T + 30 )['all_until'] === $T + 25 + 15 * MINUTE_IN_SECONDS, json_encode( $r ) . ' ' . json_encode( Insights_Portal::locks( $T + 30 ) ) );
$r = Insights_Portal::attempt( $USER, $PW, '192.0.2.1', $T + 30 ); $ok( 'по време на общия блок и вярната парола чака', ! $r['ok'] && 'locked' === $r['code'] );
Insights_Portal::unlock_all(); $ok( 'unlock_all → всички свободни, записано в дневника', 0 === Insights_Portal::locked( '192.0.2.1', $T + 30 ) && 0 === Insights_Portal::locked( '10.0.3.1', $T + 30 ) && 'unlock' === Insights_Portal::log_rows()[0]['res'] );
$r = Insights_Portal::attempt( $USER, $PW, '192.0.2.1', $T + 31 ); $ok( 'след отключването входът минава', $r['ok'] );
/* два ключа на клиент: CF IP + REMOTE_ADDR — подправен CF-Connecting-IP не заобикаля блока */
$T2 = $T + 3 * HOUR_IN_SECONDS;
for ( $i = 1; $i <= 5; $i++ ) { $r = Insights_Portal::attempt( $USER, 'spoof', array( '203.0.113.' . $i, '198.51.100.50' ), $T2 + $i ); }
$ok( 'подправен CF IP (5 различни) със същия REMOTE_ADDR → блок по REMOTE_ADDR', 'locked' === $r['code'] && Insights_Portal::locked( '198.51.100.50', $T2 + 6 ) > 0 && Insights_Portal::locked( array( '203.0.113.99', '198.51.100.50' ), $T2 + 6 ) > 0 && 0 === Insights_Portal::locked( '203.0.113.99', $T2 + 6 ), json_encode( $r ) );
$ok( 'общият брояч се вдига веднъж на опит, не по ключ', 0 === Insights_Portal::locks( $T2 + 6 )['all_until'] );
Insights_Portal::clear_ip( array( '198.51.100.50', '203.0.113.1' ) ); $ok( 'clear_ip със списък', 0 === Insights_Portal::locked( array( '203.0.113.1', '198.51.100.50' ), $T2 + 6 ) );
$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.77'; $ok( 'client_ips: CF IP + REMOTE_ADDR (различни)', array( '192.0.2.77', '203.0.113.7' ) === Insights_Portal::client_ips() ); unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
$ok( 'client_ips: без CF → само REMOTE_ADDR', array( '203.0.113.7' ) === Insights_Portal::client_ips() );
/* дневникът е ограничен */
for ( $i = 0; $i < 110; $i++ ) { Insights_Portal::log( 'bad', 'u' . $i, '1.2.3.4', $T ); }
$ok( 'дневникът пази най-много ' . Insights_Portal::LOG_MAX, Insights_Portal::LOG_MAX === count( Insights_Portal::log_rows() ) && 'u109' === Insights_Portal::log_rows()[0]['user'] );
Insights_Portal::log_clear(); $ok( 'log_clear', array() === Insights_Portal::log_rows() );

/* ── HTTPS ── */
$ok( 'https_ok: X-Forwarded-Proto https', Insights_Portal::https_ok() );
unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ); $ok( 'https_ok: без SSL и „само HTTPS“ → не', ! Insights_Portal::https_ok() );
$r = Insights_Portal::attempt( $USER, $PW, $IP, $T + 100 ); $ok( 'вход по http при „само HTTPS“ → отказ без проверка на паролата', ! $r['ok'] && 'https' === $r['code'] && 'https' === Insights_Portal::log_rows()[0]['res'], json_encode( $r ) );
$_SERVER['HTTP_CF_VISITOR'] = '{"scheme":"https"}'; $ok( 'https_ok: CF-Visitor https', Insights_Portal::https_ok() ); unset( $_SERVER['HTTP_CF_VISITOR'] );
Insights_Portal::save( array( 'enabled' => 1, 'slug' => 'reklama-stats', 'user' => $USER, 'https_only' => 0, 'ttl' => 4 ) ); $ok( 'https_ok: изключено „само HTTPS“ → да (и status предупреждава)', Insights_Portal::https_ok() && 'warn' === Insights_Portal::status()[1] );
Insights_Portal::save( array( 'enabled' => 1, 'slug' => 'reklama-stats', 'user' => $USER, 'https_only' => 1, 'ttl' => 4 ) ); $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

/* ── сесията: подписан токен ── */
$s = Insights_Portal::settings(); $tok = Insights_Portal::token( $USER, $T, null, 'Mozilla/5.0 (test)', $s );
$d = Insights_Portal::verify( $tok, $T + 10, 'Mozilla/5.0 (test)', $s );
$ok( 'токен: проверява се, носи потребителя, изтича след ttl (4 ч) и поколението', is_array( $d ) && $USER === $d['u'] && $T + 4 * HOUR_IN_SECONDS === $d['exp'] && $s['gen'] === $d['g'], json_encode( $d ) );
$ok( 'токен: 2 части, base64url + hex подпис', 1 === substr_count( $tok, '.' ) && preg_match( '/^[A-Za-z0-9_\-]+\.[a-f0-9]{64}$/', $tok ) );
$ok( 'токен: изтекъл → не', null === Insights_Portal::verify( $tok, $T + 4 * HOUR_IN_SECONDS, 'Mozilla/5.0 (test)', $s ) );
$ok( 'токен: друг браузър → не', null === Insights_Portal::verify( $tok, $T + 10, 'Other UA', $s ) );
$ok( 'токен: подправен подпис → не', null === Insights_Portal::verify( substr( $tok, 0, -1 ) . ( 'a' === substr( $tok, -1 ) ? 'b' : 'a' ), $T + 10, 'Mozilla/5.0 (test)', $s ) );
list( $p0 ) = explode( '.', $tok ); $p1 = rtrim( strtr( base64_encode( str_replace( '"u":"' . $USER . '"', '"u":"admin"', base64_decode( strtr( $p0, '-_', '+/' ) ) ) ), '+/', '-_' ), '=' );
$ok( 'токен: подправен payload (друг потребител) → не', null === Insights_Portal::verify( $p1 . '.' . substr( $tok, strpos( $tok, '.' ) + 1 ), $T + 10, 'Mozilla/5.0 (test)', $s ) );
$ok( 'токен: боклук → не', null === Insights_Portal::verify( '', $T, 'x', $s ) && null === Insights_Portal::verify( 'a.b', $T, 'x', $s ) && null === Insights_Portal::verify( str_repeat( 'A', 700 ), $T, 'x', $s ) && null === Insights_Portal::verify( 'a.b.c', $T, 'x', $s ) );
$ok( 'токен: издаден „в бъдещето“ → не', null === Insights_Portal::verify( Insights_Portal::token( $USER, $T + HOUR_IN_SECONDS, null, 'Mozilla/5.0 (test)', $s ), $T, 'Mozilla/5.0 (test)', $s ) );
Insights_Portal::logout_all(); $s2 = Insights_Portal::settings();
$ok( 'logout_all: gen +1 → старият токен пада, новият минава', $s2['gen'] === $s['gen'] + 1 && null === Insights_Portal::verify( $tok, $T + 10, 'Mozilla/5.0 (test)', $s2 ) && is_array( Insights_Portal::verify( Insights_Portal::token( $USER, $T, null, 'Mozilla/5.0 (test)', $s2 ), $T + 10, 'Mozilla/5.0 (test)', $s2 ) ) );
$tok2 = Insights_Portal::token( $USER, $T, null, 'Mozilla/5.0 (test)' );
Insights_Portal::save( array( 'enabled' => 1, 'slug' => 'reklama-stats', 'user' => $USER, 'https_only' => 1, 'ttl' => 4, 'password' => 'Another-Good-Pass-77', 'password2' => 'Another-Good-Pass-77' ) );
$ok( 'смяна на паролата → токенът пада (ключът и gen са други)', null === Insights_Portal::verify( $tok2, $T + 10, 'Mozilla/5.0 (test)' ) );
$ok( 'current(): без бисквитка → никой, csrf празен', null === Insights_Portal::current() && '' === Insights_Portal::csrf() );
$_COOKIE[ Insights_Portal::COOKIE ] = Insights_Portal::token( $USER, time(), null, 'Mozilla/5.0 (test)' );
$ref = new ReflectionProperty( Insights_Portal::class, 'cur_done' ); $ref->setAccessible( true ); $ref->setValue( null, false );
$c = Insights_Portal::current(); $ok( 'current(): с валидна бисквитка → сесия на потребителя', is_array( $c ) && 'session' === $c['via'] && $USER === $c['name'] && $c['exp'] > time(), json_encode( $c ) );
$cs = Insights_Portal::csrf(); $ok( 'csrf: 64 hex, проверява се, друг не минава', preg_match( '/^[a-f0-9]{64}$/', $cs ) && Insights_Portal::csrf_ok( $cs ) && ! Insights_Portal::csrf_ok( strrev( $cs ) ) && ! Insights_Portal::csrf_ok( null ) );

/* ── изключен ── */
Insights_Portal::save( array( 'enabled' => 0, 'slug' => 'reklama-stats', 'user' => $USER, 'https_only' => 1, 'ttl' => 4 ) ); $ref->setValue( null, false );
$r = Insights_Portal::attempt( $USER, 'Another-Good-Pass-77', $IP, $T + 500 );
$ok( 'изключен: входът е disabled, никой не е вътре, status info', ! $r['ok'] && 'disabled' === $r['code'] && null === Insights_Portal::current() && 'info' === Insights_Portal::status()[1] );

/* ── Insights в режим портал: линковете и формите сочат към портала ── */
Insights_Portal::save( array( 'enabled' => 1, 'slug' => 'reklama-stats', 'user' => $USER, 'https_only' => 1, 'ttl' => 4 ) );
Insights::$portal = true;
$ok( 'Insights::url в портала → адресът на портала без tab', 'https://ansa.test/reklama-stats/?view=history&from=2026-10-16' === Insights::url( array( 'tab' => 'insights', 'view' => 'history', 'from' => '2026-10-16' ) ), Insights::url( array( 'tab' => 'insights', 'view' => 'history', 'from' => '2026-10-16' ) ) );
ob_start(); Insights::export_form_open( 'sessions' ); $f = ob_get_clean();
$ok( 'формата за CSV в портала → GET към портала с csv=sessions', false !== strpos( $f, 'action="https://ansa.test/reklama-stats/"' ) && false !== strpos( $f, 'name="csv" value="sessions"' ) && false === strpos( $f, 'admin-post' ), $f );
Insights::$portal = false;
$ok( 'Insights::url в админа → admin.php?page=ansa-promo&tab=insights', false !== strpos( Insights::url( array( 'tab' => 'insights', 'view' => 'live' ) ), 'wp-admin/admin.php?page=ansa-promo' ), Insights::url( array( 'tab' => 'insights', 'view' => 'live' ) ) );

if ( $fail ) { echo "FAIL\n - " . implode( "\n - ", $fail ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
