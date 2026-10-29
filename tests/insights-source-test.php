<?php
/* 1.42.0: офлайн тест на Source — произходът (вход към лендъра / външен източник) от суровия query string + referrer. php tests/insights-source-test.php */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-source.php';
use AnsaPromo\Source;
$n = 0; $bad = 0;
function chk( $qs, $ref, $k, $path = null, $extra = array() ) { global $n, $bad; $n++; $c = Source::classify( $qs, $ref ); $ok = $c['k'] === $k && ( null === $path || $c['path'] === $path ); foreach ( $extra as $ek => $ev ) { if ( ( $c[ $ek ] ?? null ) !== $ev ) { $ok = false; } } if ( ! $ok ) { $bad++; echo "FAIL [$qs | $ref] → ", json_encode( $c, JSON_UNESCAPED_UNICODE ), " очаквах $k ", json_encode( $path, JSON_UNESCAPED_UNICODE ), "\n"; } }
/* Meta — както идват на живо */
chk( 'utm_source=facebook&utm_medium=paid&utm_campaign=THYROZEN&utm_content=thyro', 'https://l.facebook.com/', 'meta', array( 'THYROZEN' ), array( 'product' => 'thyro' ) );
chk( 'utm_source=Facebook&utm_medium=Facebook_Mobile_Feed&utm_campaign=YONI+WINNERS', '', 'meta', array( 'YONI WINNERS' ), array( 'placement' => 'Facebook_Mobile_Feed' ) );
chk( 'utm_source=an&utm_medium=paid&utm_campaign=120216144065080252', '', 'meta', array( '120216144065080252' ) );
chk( 'utm_source=GREENSREDSSCALINGmaxroas&utm_medium=Instagram_Reels', '', 'meta', array( 'GREENSREDSSCALINGmaxroas' ), array( 'placement' => 'Instagram_Reels' ) );
chk( 'utm_source=th&utm_medium=paid', 'https://www.threads.com/', 'meta', array( 'без име на кампания' ) );
chk( 'fbclid=IwAR0abc', 'http://m.facebook.com/', 'meta', array( 'без име на кампания' ) );
chk( 'utm_campaign=Season&utm_id=120', 'https://l.facebook.com/', 'meta', array( 'Season' ), array( 'ids' => array( '120', '', '' ) ) );
/* препоръчаният шаблон: кампания → рекламна група → реклама, с ID-та */
chk( 'utm_source=facebook&utm_medium=paid&utm_campaign=Season+%7C+Ansa&utm_id=1201&adset_name=MOF+Sakura&adset_id=1202&ad_name=Video+A&ad_id=1203&placement=Instagram_Reels&utm_content=sakura', 'https://l.facebook.com/',
	'meta', array( 'Season | Ansa', 'MOF Sakura', 'Video A' ), array( 'ids' => array( '1201', '1202', '1203' ), 'placement' => 'Instagram_Reels', 'product' => 'sakura' ) );
chk( 'utm_source=facebook&utm_medium=paid&adset_id=1202&ad_id=1203', '', 'meta', array( 'без име на кампания', '#1202', '#1203' ) );
/* Google */
chk( '', 'https://www.google.com/', 'google_org', array( 'търсене' ) );
chk( 'gclid=abc', 'https://www.google.com/', 'google_paid', array( 'без име на кампания' ) );
chk( 'utm_source=google&utm_medium=cpc&utm_campaign=Brand', '', 'google_paid', array( 'Brand' ) );
/* попъп: Shrine ≥ 6.33 (ap_pop / ap_from) и старият начин (utm_medium=popup) */
chk( 'utm_content=sakura&box=l&ap_entry=popup&ap_pop=product-sakura&ap_from=%2Fproduct%2Fsakura-skintonic', 'https://www.ansa.bg/product/sakura-skintonic/', 'popup', array( '/product/sakura-skintonic', 'product-sakura' ), array( 'product' => 'sakura' ) );
chk( 'ap_pop=season', 'https://www.ansa.bg/shop/', 'popup', array( '/shop', 'season' ) );
chk( 'utm_source=site&utm_medium=popup&utm_campaign=Exit+popup', 'https://www.ansa.bg/shop/', 'popup', array( '/shop', 'Exit popup' ) );
chk( 'utm_source=popup', '', 'popup', array( 'неизвестна страница', 'попъп' ) );
/* имейл */
chk( 'utm_source=newsletter&utm_medium=email&utm_campaign=Early+Access+Email+1&utm_content=button2', '', 'email', array( 'Early Access Email 1', 'button2' ) );
chk( 'utm_source=WinBackFlow&utm_medium=email&utm_campaign=WinBackFlowEmail2', '', 'email', array( 'WinBackFlowEmail2' ) );
/* вътрешни линкове */
chk( 'utm_source=home&utm_medium=hero', 'https://www.ansa.bg/', 'internal', array( 'home / hero' ) );
chk( '', 'https://www.ansa.bg/shop/', 'internal', array( '/shop' ) );
chk( '', 'https://ansa.bg/%D0%B2%D1%81%D0%B8%D1%87%D0%BA%D0%B8', 'internal', array( '/всички' ) );
/* UTM от referrer-а (кацане на подстраница от реклама → клик към лендъра) */
chk( '', 'https://www.ansa.bg/sezonut-na-ansa/kozmetika/?utm_source=facebook&utm_medium=paid&utm_campaign=Season+%7C+%D0%A1%D0%B5%D0%B7%D0%BE%D0%BD', 'meta', array( 'Season | Сезон' ), array( 'from_ref' => true ) );
chk( 'utm_content=general', 'https://www.ansa.bg/sezonut-na-ansa/?utm_content=general&utm_source=facebook&utm_medium=paid&utm_campaign=S', 'meta', array( 'S' ), array( 'product' => 'general' ) );
/* без реклама, друг сайт, директно, телефон, друг UTM */
chk( '', 'https://l.facebook.com/', 'meta_org', array( 'facebook.com' ) );
chk( '', 'https://read.dailywomenshealth.com/', 'other_ref', array( 'read.dailywomenshealth.com' ) );
chk( '', '', 'direct', array() );
chk( 'utm_source=phone&utm_medium=call&utm_campaign=nikol', '', 'phone', array( 'nikol' ) );
chk( 'utm_source=chatgpt.com&utm_medium=referral&utm_campaign=x', '', 'other_utm', array( 'chatgpt.com / referral', 'x' ) );
chk( 'box=l&fill=sakura&go=1', 'https://www.ansa.bg/kozmetichen-set-promo/', 'internal', array( '/kozmetichen-set-promo' ) );
/* of(): стар ред (колоните) и нов ред (entry + touch) */
$o = Source::of( array( 'src' => 'facebook', 'med' => 'paid', 'camp' => 'THYROZEN', 'ref' => '', 'utm' => 'thyro' ) ); $n++;
if ( 'meta' !== $o['ext']['k'] || 'meta' !== $o['entry']['k'] || empty( $o['entry']['legacy'] ) ) { $bad++; echo "FAIL of() legacy: ", json_encode( $o, JSON_UNESCAPED_UNICODE ), "\n"; }
$o = Source::of( array( 'src' => 'Facebook', 'med' => 'Instagram_Feed', 'camp' => 'ASC Berberine Ad set', 'ref' => 'http://m.facebook.com/', 'touch_qs' => 'utm_source=Facebook&utm_medium=Instagram_Feed&utm_campaign=ASC+Berberine+Ad+set&utm_content=berber', 'touch_ref' => 'http://m.facebook.com/', 'entry_qs' => 'utm_content=berber&box=l&ap_pop=product-berber&ap_from=%2Fproduct%2Fberberine', 'entry_ref' => 'https://www.ansa.bg/product/berberine/' ) ); $n++;
if ( 'popup' !== $o['entry']['k'] || array( '/product/berberine', 'product-berber' ) !== $o['entry']['path'] || 'meta' !== $o['ext']['k'] || array( 'ASC Berberine Ad set' ) !== $o['ext']['path'] || ! empty( $o['entry']['legacy'] ) ) { $bad++; echo "FAIL of() popup+meta: ", json_encode( $o, JSON_UNESCAPED_UNICODE ), "\n"; }
/* кацане направо на лендъра от реклама: входът = рекламата; външният = рекламата */
$o = Source::of( array( 'touch_qs' => 'utm_source=facebook&utm_medium=paid&utm_campaign=T', 'touch_ref' => 'https://l.facebook.com/', 'entry_qs' => 'utm_source=facebook&utm_medium=paid&utm_campaign=T', 'entry_ref' => 'https://l.facebook.com/' ) ); $n++;
if ( 'meta' !== $o['entry']['k'] || 'meta' !== $o['ext']['k'] ) { $bad++; echo "FAIL of() direct ad\n"; }
/* touch-ът е изгубен (вътрешен), а входът е имейл → външният е имейлът */
$o = Source::of( array( 'touch_qs' => '', 'touch_ref' => 'https://www.ansa.bg/', 'entry_qs' => 'utm_source=newsletter&utm_medium=email&utm_campaign=E1', 'entry_ref' => '' ) ); $n++;
if ( 'email' !== $o['entry']['k'] || 'email' !== $o['ext']['k'] ) { $bad++; echo "FAIL of() email fallback: ", json_encode( $o, JSON_UNESCAPED_UNICODE ), "\n"; }
/* входът е „директен“ (лендърът отворен без параметри), външният е известен → входът е външният */
$o = Source::of( array( 'touch_qs' => 'utm_source=newsletter&utm_medium=email&utm_campaign=E1', 'touch_ref' => '', 'entry_qs' => '', 'entry_ref' => 'https://www.ansa.bg/' ) ); $n++;
if ( 'internal' !== $o['entry']['k'] || 'email' !== $o['ext']['k'] ) { $bad++; echo "FAIL of() internal entry + email ext: ", json_encode( $o, JSON_UNESCAPED_UNICODE ), "\n"; }
/* 1.42.1: двойно кодирани параметри (Meta) и отрязан referrer с невалиден UTF-8 */
chk( '', 'https://www.ansa.bg/sezonut-na-ansa/?utm_content=general&utm_source=facebook&utm_medium=paid&utm_campaign=Season%2B%257C%2B%25D0%25A1%25D0%25B5%25D0%25B7%25D0%25BE%25D0%25BD%25D1%258A%25D1%2582%2B%25D0%25BD%25D0%25B0%2BAnsa&utm_term=MOF', 'meta', array( 'Season | Сезонът на Ansa', 'MOF' ) );
chk( '', 'https://www.ansa.bg/sezonut-na-ansa/?utm_content=general&utm_source=facebook&utm_medium=paid&utm_campaign=Season%2B%257C%2B%25D0%25A1%25D0%25B5%25D0%25B7%25D0%25BE%25D0%25BD%25D1%258A%25D1%', 'meta', array( 'Season | Сезонъ' ) );
chk( '', 'https://www.ansa.bg/sezonut-na-ansa/?utm_source=facebook&utm_medium=paid&utm_campaign=Season+%7C+%D0%A1%D0%B5%D0%B7%D0%BE%D0%BD%D1', 'meta', array( 'Season | Сезон' ) );
/* params: чистене и малки ключове */
$p = Source::params( '?UTM_Source=FB&utm_campaign=a%20b&x[]=1&ad_id=' . "12\x00" ); $n++; if ( 'FB' !== ( $p['utm_source'] ?? '' ) || 'a b' !== ( $p['utm_campaign'] ?? '' ) || isset( $p['x'] ) || '12' !== ( $p['ad_id'] ?? '' ) ) { $bad++; echo "FAIL params: ", json_encode( $p ), "\n"; }
echo $bad ? "FAILED $bad / $n\n" : "OK $n проверки\n"; exit( $bad ? 1 : 0 );
