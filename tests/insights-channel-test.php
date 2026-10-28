<?php
/* 1.38.0: офлайн тест на Channel::of — каналът от UTM-а / referrer-а на сесията. php tests/insights-channel-test.php */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-channel.php';
use AnsaPromo\Channel;
$n = 0; $bad = 0;
function chk( $r, $k, $d = null ) { global $n, $bad; $n++; $c = Channel::of( $r ); $ok = $c['k'] === $k && ( null === $d || $c['d'] === $d ); if ( ! $ok ) { $bad++; echo "FAIL ", json_encode( $r, JSON_UNESCAPED_UNICODE ), " → ", json_encode( $c, JSON_UNESCAPED_UNICODE ), " очаквах $k", null === $d ? '' : " · $d", "\n"; } }
chk( array( 'src' => 'facebook', 'med' => 'paid', 'camp' => 'THYROZEN', 'ref' => 'https://l.facebook.com/' ), 'meta', 'THYROZEN' );
chk( array( 'src' => 'fb', 'med' => 'paid', 'camp' => '120216144065080252', 'ref' => '' ), 'meta' );
chk( array( 'src' => 'Facebook', 'med' => 'Facebook_Mobile_Feed', 'camp' => 'YONI WINNERS', 'ref' => '' ), 'meta', 'YONI WINNERS' );
chk( array( 'src' => 'an', 'med' => 'paid', 'camp' => '', 'ref' => '' ), 'meta', 'an / paid' );
chk( array( 'src' => 'greensredsscalingmaxroas', 'med' => 'paid', 'camp' => '', 'ref' => '' ), 'meta' );
chk( array( 'src' => 'newsletter', 'med' => 'email', 'camp' => 'Early Access Email 1', 'ref' => '' ), 'email', 'Early Access Email 1' );
chk( array( 'src' => 'WinBackFlow', 'med' => 'email', 'camp' => 'WinBackFlowEmail1', 'ref' => '' ), 'email' );
chk( array( 'src' => 'site', 'med' => 'popup', 'camp' => 'Exit popup кутии', 'ref' => 'https://www.ansa.bg/shop/' ), 'popup', 'Exit popup кутии' );
chk( array( 'src' => 'popup', 'med' => '', 'camp' => '', 'ref' => '' ), 'popup' );
chk( array( 'src' => 'home', 'med' => 'hero', 'camp' => '', 'ref' => 'https://www.ansa.bg/' ), 'site_utm', 'home / hero' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => '' ), 'direct', '' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://www.ansa.bg/' ), 'site_ref', '/' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://www.ansa.bg/shop/' ), 'site_ref', '/shop' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://www.ansa.bg/sezonut-na-ansa/info/' ), 'promo_sub', '/sezonut-na-ansa/info' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://www.ansa.bg/sezonut-na-ansa/kozmetika/?utm_source=facebook&utm_medium=paid&utm_campaign=Season+%7C+%D0%A1%D0%B5%D0%B7%D0%BE%D0%BD' ), 'meta', 'Season | Сезон' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://www.ansa.bg/sezonut-na-ansa?utm_source=newsletter&utm_medium=email&utm_campaign=Early+Access+Email+1' ), 'email', 'Early Access Email 1' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://www.ansa.bg/sezonut-na-ansa/?utm_content=general&amp;utm_source=home&amp;utm_medium=boxes' ), 'site_utm', 'home / boxes' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://l.facebook.com/' ), 'meta_org', 'facebook.com' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'http://m.facebook.com' ), 'meta_org', 'facebook.com' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://www.google.com/' ), 'google' );
chk( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://read.dailywomenshealth.com/' ), 'other_ref', 'read.dailywomenshealth.com' );
chk( array( 'src' => 'phone', 'med' => '', 'camp' => '', 'ref' => '' ), 'phone' );
chk( array( 'src' => 'th', 'med' => '', 'camp' => 'x', 'ref' => '' ), 'other_utm', 'th · x' );
chk( array( 'src' => 'google', 'med' => 'cpc', 'camp' => 'Brand', 'ref' => '' ), 'google', 'Brand' );
$f = Channel::of( array( 'src' => 'facebook', 'med' => 'paid', 'camp' => 'THYROZEN', 'ref' => '' ) ); $n++; if ( $f['f'] !== array( 'f_src' => 'facebook', 'f_med' => 'paid', 'f_camp' => 'THYROZEN' ) ) { $bad++; echo "FAIL филтърът: ", json_encode( $f['f'] ), "\n"; }
$f = Channel::of( array( 'src' => '', 'med' => '', 'camp' => '', 'ref' => 'https://www.ansa.bg/sezonut-na-ansa/kozmetika/?utm_source=facebook&utm_medium=paid' ) ); $n++; if ( $f['f'] !== array() ) { $bad++; echo "FAIL филтър от referrer трябва да е празен\n"; }
echo $bad ? "FAILED $bad / $n\n" : "OK $n проверки\n"; exit( $bad ? 1 : 0 );
