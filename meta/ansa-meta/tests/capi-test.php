<?php
/**
 * Офлайн тест на Capi / Queue / Woo / Catalog / Settings със стъбове (без WordPress):
 * строене на събитие, буфер → изпращане на shutdown, тялото на заявката към Graph API, грешки (повторение / отпадане),
 * Purchase веднъж на поръчка с event_id-то от поръчката, content_ids по формат, sanitize на настройките.
 * php tests/capi-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
use AnsaMeta\Capi; use AnsaMeta\Queue; use AnsaMeta\Woo; use AnsaMeta\Catalog; use AnsaMeta\Settings; use AnsaMeta\Identity; use AnsaMeta\Plugin;

$GLOBALS['opts']['ansa_meta'] = array( 'pixel_id' => '1234567890', 'token' => 'EAABtoken', 'enabled' => 1, 'api_version' => 'v24.0' );
$_SERVER = array( 'REQUEST_URI' => '/product/daily-greens/?fbclid=CLICK123', 'HTTP_HOST' => 'ansa.bg', 'REMOTE_ADDR' => '93.1.2.3', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)' );
$_COOKIE = array( '_fbp' => 'fb.1.1700000000000.1234567890', 'ansa_meta_vid' => str_repeat( 'ab', 16 ) ); $_GET = array( 'fbclid' => 'CLICK123' );
ok( Plugin::enabled(), 'enabled с pixel + token' );

/* ── event() ── */
$ev = Capi::event( 'ViewContent', array( 'content_ids' => array( 3995 ), 'content_type' => 'product', 'value' => '19.9', 'currency' => 'eur', 'contents' => array( array( 'id' => 3995, 'quantity' => '2', 'item_price' => 9.95 ) ), 'junk' => null, 'empty' => '' ), Identity::current(), array() );
eq( $ev['event_name'], 'ViewContent', 'event_name' ); eq( $ev['action_source'], 'website', 'action_source' );
ok( preg_match( '/^[0-9a-f-]{36}$/', $ev['event_id'] ) === 1, 'event_id uuid' );
eq( $ev['event_source_url'], 'https://ansa.bg/product/daily-greens/?fbclid=CLICK123', 'event_source_url от заявката' );
eq( $ev['custom_data']['value'], 19.9, 'value като число' ); eq( $ev['custom_data']['currency'], 'EUR', 'currency главни' );
eq( $ev['custom_data']['content_ids'], array( '3995' ), 'content_ids като низове' ); eq( $ev['custom_data']['contents'][0]['quantity'], 2, 'contents.quantity int' );
ok( ! isset( $ev['custom_data']['junk'] ) && ! isset( $ev['custom_data']['empty'] ), 'празните custom ключове отпадат' );
eq( $ev['user_data']['fbp'], 'fb.1.1700000000000.1234567890', 'fbp от бисквитката' );
ok( substr( $ev['user_data']['fbc'], -8 ) === 'CLICK123', 'fbc от ?fbclid', $ev['user_data']['fbc'] );
eq( $ev['user_data']['client_ip_address'], '93.1.2.3', 'ip' ); eq( $ev['user_data']['external_id'], array( hash( 'sha256', str_repeat( 'ab', 16 ) ) ), 'external_id = sha256(vid бисквитката) за гост' );
$noc = $_COOKIE; unset( $_COOKIE['ansa_meta_vid'] ); ok( ! isset( Capi::event( 'X', array(), Identity::current(), array() )['user_data']['external_id'] ), 'без vid бисквитка и без право за бисквитки → без external_id (не измисляме id, който браузърът няма)' ); $_COOKIE = $noc;
eq( $ev['data_processing_options'], array(), 'LDU изключен → []' );
$old = Capi::event( 'X', array(), array(), array( 'time' => time() - 10 * DAY_IN_SECONDS ) ); ok( $old['event_time'] >= time() - 5, 'event_time > 7 дни назад → сега' );
$given = Capi::event( 'X', array(), array(), array( 'event_id' => 'order-abc-123', 'url' => 'https://ansa.bg/checkout/' ) ); eq( $given['event_id'], 'order-abc-123', 'подаден event_id' ); eq( $given['event_source_url'], 'https://ansa.bg/checkout/', 'подаден url' );

/* ── track → буфер → flush → HTTP ── */
$GLOBALS['http'] = array();
$id = Capi::track( 'PageView', array(), Identity::current(), array( 'source' => 'browser', 'event_id' => 'pv-00000001' ) );
eq( $id, 'pv-00000001', 'track връща event_id' ); eq( Capi::pending(), 1, 'в буфера' ); eq( count( $GLOBALS['http'] ), 0, 'нищо не е пратено преди shutdown' );
Capi::track( 'AddToCart', array( 'value' => 5 ), array(), array() ); eq( Capi::pending(), 2, 'две в буфера' );
Capi::flush();
eq( count( $GLOBALS['http'] ), 1, 'една партида' ); eq( Capi::pending(), 0, 'буферът е празен' );
$req = $GLOBALS['http'][0]; eq( $req['url'], 'https://graph.facebook.com/v24.0/1234567890/events', 'URL на Graph API' );
$body = json_decode( $req['args']['body'], true );
eq( count( $body['data'] ), 2, 'data[] с двете събития' ); eq( $body['access_token'], 'EAABtoken', 'access_token' ); ok( ! isset( $body['test_event_code'] ), 'без test_event_code' );
eq( $body['data'][0]['event_name'], 'PageView', 'ред 1' ); eq( $body['data'][1]['event_name'], 'AddToCart', 'ред 2' );
eq( count( $wpdb->log ), 2, 'два реда в дневника' ); eq( (int) $wpdb->log[1]['ok'], 1, 'дневник ok' ); ok( false !== strpos( $wpdb->log[1]['response'], 'received=1' ), 'дневник response' );
eq( $wpdb->log[1]['source'], 'browser', 'дневник source' );
$sum = json_decode( $wpdb->log[1]['payload'], true ); ok( in_array( 'fbp', $sum['user'], true ) && in_array( 'client_ip_address', $sum['user'], true ), 'резюме: user ключове' );
eq( count( $wpdb->queue ), 0, 'опашката е празна при успех' );
/* след flush → track праща веднага */
Capi::track( 'Lead', array(), array( 'em' => 'x@y.bg' ), array() ); eq( count( $GLOBALS['http'] ), 2, 'след shutdown flush → веднага' ); eq( Capi::pending(), 0, 'без остатък' );

/* ── изключено събитие / изключен плъгин ── */
$GLOBALS['opts']['ansa_meta']['events'] = array( 'Search' => 0 ); $reset = function () { $r = new ReflectionProperty( 'AnsaMeta\\Settings', 'all' ); $r->setAccessible( true ); $r->setValue( null, null ); }; $reset();
eq( Capi::track( 'Search', array( 'search_string' => 'x' ), array(), array() ), '', 'изключено събитие → не се праща' );
eq( Capi::track( 'ViewCategory', array(), array(), array() ) !== '', true, 'непознато/custom събитие → минава' );
$GLOBALS['opts']['ansa_meta']['enabled'] = 0; $reset(); eq( Capi::track( 'PageView', array(), array(), array() ), '', 'изключен плъгин → нищо' ); $GLOBALS['opts']['ansa_meta']['enabled'] = 1; $reset();

/* ── грешки: повторение (5xx / мрежа) → опашка; невалидно (100) → отпада; token (190) → повторение ── */
$GLOBALS['http_response'] = array( 'code' => 500, 'body' => '{"error":{"message":"Service temporarily unavailable","code":2}}' );
Capi::track( 'PageView', array(), array(), array( 'event_id' => 'pv-retry-001' ) ); Capi::flush();
eq( count( $wpdb->queue ), 1, '5xx → ред в опашката' ); $qrow = reset( $wpdb->queue ); eq( $qrow['event_id'], 'pv-retry-001', 'опашка: event_id' ); ok( false !== strpos( $qrow['last_error'], '(2)' ), 'опашка: грешката' );
$GLOBALS['http_response'] = new WP_Error( 'http', 'cURL timeout' );
Capi::track( 'PageView', array(), array(), array( 'event_id' => 'pv-retry-002' ) ); Capi::flush(); eq( count( $wpdb->queue ), 2, 'мрежова грешка → опашка' );
$GLOBALS['http_response'] = array( 'code' => 400, 'body' => '{"error":{"message":"Invalid parameter","code":100,"error_subcode":2804003}}' );
Capi::track( 'PageView', array(), array(), array( 'event_id' => 'pv-bad-001' ) ); Capi::flush(); eq( count( $wpdb->queue ), 2, 'код 100 → НЕ влиза в опашката' );
$last = end( $wpdb->log ); eq( (int) $last['ok'], 0, 'дневник: грешка' ); ok( false !== strpos( $last['response'], '(100/2804003) Invalid parameter' ), 'дневник: кодът и подкодът', $last['response'] );
$GLOBALS['http_response'] = array( 'code' => 400, 'body' => '{"error":{"message":"Invalid OAuth access token","code":190}}' );
Capi::track( 'PageView', array(), array(), array( 'event_id' => 'pv-tok-001' ) ); Capi::flush(); eq( count( $wpdb->queue ), 3, 'код 190 → опашка (token-ът ще бъде оправен)' );
/* партида с едно невалидно → едно по едно (нова „заявка“: буферът пак се пълни до shutdown) */
$fresh = function () { $r = new ReflectionProperty( 'AnsaMeta\\Capi', 'finished' ); $r->setAccessible( true ); $r->setValue( null, false ); }; $fresh();
$GLOBALS['http'] = array(); $GLOBALS['http_response'] = array( 'code' => 400, 'body' => '{"error":{"message":"Invalid parameter","code":100}}' );
Capi::track( 'A', array(), array(), array() ); Capi::track( 'B', array(), array(), array() ); Capi::flush();
eq( count( $GLOBALS['http'] ), 3, 'партида 2 + невалидно → 1 опит за партидата + 2 единични' );

/* ── Queue::process: успех трие, провал бута напред с backoff, 8 провала → отпада ── */
$GLOBALS['http_response'] = null; $GLOBALS['http'] = array();
$r = Queue::process( true ); eq( $r['sent'], 3, 'опашката е изпратена (force)' ); eq( count( $wpdb->queue ), 0, 'и изтрита' ); eq( count( $GLOBALS['http'] ), 1, 'една партида за трите' );
$GLOBALS['http_response'] = array( 'code' => 503, 'body' => '' );
Queue::push( array( 'event_name' => 'PageView', 'event_id' => 'q1', 'event_time' => time(), 'user_data' => array( 'fbp' => 'fb.1.1700000000000.1' ) ), 'server', 'x' );
$r = Queue::process( true ); eq( $r['failed'], 1, 'провал → за повторение' ); $row = reset( $wpdb->queue ); eq( (int) $row['attempts'], 1, 'attempts=1' ); ok( strtotime( $row['next_at'] . ' UTC' ) >= time() + 110, 'backoff ≥ 2 мин', $row['next_at'] );
$wpdb->queue[ $row['id'] ]['attempts'] = 7; $r = Queue::process( true ); eq( $r['dropped'], 1, '8-ият провал → отпада' ); eq( count( $wpdb->queue ), 0, 'опашката е празна' );
$last = end( $wpdb->log ); ok( false !== strpos( $last['response'], 'отпада след 8 опита' ), 'дневник: отпадането', $last['response'] );
$GLOBALS['http_response'] = null;

/* ── Woo::purchase: веднъж на поръчка, event_id от поръчката, време = създаването, ctx от поръчката ── */
require_once __DIR__ . '/identity-test-order.php';
$o = new FakeOrder(); $o->status = 'pending'; $o->pm = 'cod';
$GLOBALS['http'] = array();
Woo::order_processed( 1001, array(), $o );
ok( '' !== $o->get_meta( Woo::META_EVENT ), 'stamp: event_id в поръчката' ); ok( '' !== $o->get_meta( Woo::META_CTX ), 'stamp: ctx в поръчката' );
ok( '' !== $o->get_meta( Woo::META_SENT ), 'COD → Purchase при checkout' ); ok( false !== strpos( $o->get_meta( Woo::META_SENT ), '|checkout' ), 'причината е записана' );
Capi::flush(); eq( count( $GLOBALS['http'] ), 1, 'Purchase е пратен' );
$pb = json_decode( $GLOBALS['http'][0]['args']['body'], true )['data'][0];
eq( $pb['event_name'], 'Purchase', 'Purchase' ); eq( $pb['event_id'], $o->get_meta( Woo::META_EVENT ), 'event_id = този в поръчката (общ с браузъра)' );
eq( $pb['event_time'], $GLOBALS['order_ts'], 'event_time = създаването на поръчката' ); eq( $pb['custom_data']['value'], 61.5, 'value = total' ); eq( $pb['custom_data']['currency'], 'EUR', 'currency' );
eq( $pb['custom_data']['order_id'], '1001', 'order_id' ); eq( $pb['custom_data']['coupon'], 'PROMO10', 'coupon' ); eq( $pb['custom_data']['payment_method'], 'cod', 'payment_method' ); eq( $pb['custom_data']['shipping'], 2.55, 'shipping' ); eq( $pb['custom_data']['delivery_category'], 'home_delivery', 'delivery_category' );
ok( isset( $pb['user_data']['em'], $pb['user_data']['ph'], $pb['user_data']['fn'], $pb['user_data']['ln'], $pb['user_data']['ct'], $pb['user_data']['zp'], $pb['user_data']['country'], $pb['user_data']['fbp'], $pb['user_data']['client_ip_address'] ), 'user_data пълен от поръчката + ctx' );
$ctx = json_decode( $o->get_meta( Woo::META_CTX ), true ); eq( $pb['user_data']['fbp'], $ctx['fbp'], 'fbp от ctx на поръчката' );
$GLOBALS['orders'][1001] = $o; Woo::payment_complete( 1001 ); Woo::status_changed( 1001, 'pending', 'processing', $o ); Woo::thankyou( 1001 ); Capi::flush();
eq( count( $GLOBALS['http'] ), 1, 'payment_complete / status / thankyou → НЕ пращат втори Purchase' );
/* карта + „при плащане“: чакаме плащането */
$GLOBALS['opts']['ansa_meta']['purchase_on'] = 'paid'; $reset();
$o2 = new FakeOrder(); $o2->status = 'pending'; $o2->pm = 'fkwcs_stripe'; $GLOBALS['http'] = array();
Woo::order_processed( 1002, array(), $o2 ); eq( $o2->get_meta( Woo::META_SENT ), '', 'карта + paid → още не' ); ok( '' !== $o2->get_meta( Woo::META_EVENT ), 'но event_id вече има (за браузъра)' );
$GLOBALS['orders'][1002] = $o2; Woo::payment_complete( 1002 ); ok( false !== strpos( $o2->get_meta( Woo::META_SENT ), '|payment' ), 'плащане → Purchase' ); Capi::flush(); eq( count( $GLOBALS['http'] ), 1, 'един Purchase' );
$GLOBALS['opts']['ansa_meta']['purchase_on'] = 'order'; $reset();
$o3 = new FakeOrder(); $o3->status = 'pending'; $o3->pm = 'fkwcs_stripe'; Woo::order_processed( 1003, array(), $o3 ); ok( '' !== $o3->get_meta( Woo::META_SENT ), 'карта + order → веднага' );
/* probe поръчки не се броят */
$o4 = new FakeOrder(); $o4->email = 'probe@example.com'; eq( Woo::purchase( $o4, 'x' ), '', 'probe@example.com → пропуска се' );

/* ── predicted_ltv: връщащ се клиент → сума на предишните + тази; първа поръчка → без ключ ── */
function wc_get_order_statuses() { return array( 'wc-pending' => 'P', 'wc-processing' => 'Pr', 'wc-completed' => 'C', 'wc-cancelled' => 'X', 'wc-refunded' => 'R', 'wc-failed' => 'F', 'wc-delivered' => 'D' ); }
function wc_get_orders( $a ) { $GLOBALS['ltv_q'] = $a; return $GLOBALS['ltv_orders'] ?? array(); }
$o5 = new FakeOrder(); $o5->status = 'pending'; $o5->pm = 'cod';
$GLOBALS['ltv_orders'] = array(); eq( Catalog::ltv( $o5 ), 0.0, 'ltv: първа поръчка = 0' );
$prev1 = new FakeOrder(); $prev2 = new FakeOrder(); $GLOBALS['ltv_orders'] = array( $prev1, $prev2 ); /* всяка по 61.5, но get_id() = 1001 като текущата → изключват се */
eq( Catalog::ltv( $o5 ), 0.0, 'ltv: същото id се пропуска' );
class FakeOrderOld extends FakeOrder { function get_id() { return 900; } function get_total() { return 40.0; } }
$GLOBALS['ltv_orders'] = array( new FakeOrderOld(), new FakeOrderOld() ); eq( Catalog::ltv( $o5 ), 141.5, 'ltv: 40 + 40 + 61.5' );
ok( ! in_array( 'wc-cancelled', $GLOBALS['ltv_q']['status'], true ) && in_array( 'wc-delivered', $GLOBALS['ltv_q']['status'], true ), 'ltv: статусите без отказани, с custom (delivered)' );
$GLOBALS['http'] = array(); Woo::order_processed( 1005, array(), $o5 ); Capi::flush();
$pb5 = json_decode( $GLOBALS['http'][0]['args']['body'], true )['data'][0]; eq( $pb5['custom_data']['predicted_ltv'], 141.5, 'Purchase носи predicted_ltv' );
$GLOBALS['ltv_orders'] = array();

/* ── „Изпрати пак“ (force): същият event_id, веднага, source manual ── */
$GLOBALS['http'] = array(); $id5 = Woo::purchase( $o5, 'manual', true );
eq( $id5, $o5->get_meta( Woo::META_EVENT ), 'force: същият event_id' ); eq( count( $GLOBALS['http'] ), 1, 'force: праща веднага' );
$last = end( $wpdb->log ); eq( $last['source'], 'manual', 'force: source=manual' ); ok( false !== strpos( $o5->get_meta( Woo::META_SENT ), '|manual' ), 'force: причината е manual' );
eq( Woo::purchase( $o5, 'again' ), '', 'без force → не праща втори път' );

/* ── тестов режим: всички сървърни събития с test_event_code ── */
$GLOBALS['opts']['ansa_meta']['test_mode'] = 1; $GLOBALS['opts']['ansa_meta']['test_code'] = 'TEST2170'; $reset(); $GLOBALS['http'] = array();
Capi::track( 'PageView', array(), array(), array() ); Capi::flush();
eq( json_decode( $GLOBALS['http'][0]['args']['body'], true )['test_event_code'], 'TEST2170', 'test_mode → test_event_code в тялото' );
$GLOBALS['opts']['ansa_meta']['test_mode'] = 0; $reset(); $GLOBALS['http'] = array(); Capi::track( 'PageView', array(), array(), array() ); Capi::flush();
ok( ! isset( json_decode( $GLOBALS['http'][0]['args']['body'], true )['test_event_code'] ), 'без test_mode → без код' );

/* ── WPForms → Lead ── */
$GLOBALS['http'] = array();
Woo::wpforms( array( array( 'type' => 'name', 'value' => 'Мария Иванова', 'first' => '', 'last' => '' ), array( 'type' => 'email', 'value' => 'Maria@Example.com' ), array( 'type' => 'phone', 'value' => '0888 123 456' ), array( 'type' => 'textarea', 'value' => 'здравей' ) ), array(), array( 'settings' => array( 'form_title' => 'Контакт' ) ), 7 );
Capi::flush(); $lead = json_decode( $GLOBALS['http'][0]['args']['body'], true )['data'][0];
eq( $lead['event_name'], 'Lead', 'WPForms → Lead' ); eq( $lead['custom_data']['content_name'], 'Контакт', 'Lead: името на формата' );
eq( $lead['user_data']['em'], array( hash( 'sha256', 'maria@example.com' ) ), 'Lead: em от формата' ); eq( $lead['user_data']['ph'], array( hash( 'sha256', '359888123456' ) ), 'Lead: ph от формата' );
eq( $lead['user_data']['fn'], array( hash( 'sha256', 'мария' ) ), 'Lead: fn от „Име Фамилия“' ); eq( $lead['user_data']['ln'], array( hash( 'sha256', 'иванова' ) ), 'Lead: ln' );
$GLOBALS['http'] = array(); Woo::wpforms( array( array( 'type' => 'textarea', 'value' => 'само текст' ) ), array(), array(), 8 ); Capi::flush(); eq( count( $GLOBALS['http'] ), 0, 'форма без имейл/телефон → не е Lead' );

/* ── Catalog::content_id по формат ── */
class FakeProduct { public $id, $sku, $parent, $price, $name; function __construct( $id, $sku = '', $parent = 0, $price = 9.9, $name = 'P' ) { $this->id = $id; $this->sku = $sku; $this->parent = $parent; $this->price = $price; $this->name = $name; }
	function get_id() { return $this->id; } function get_sku() { return $this->sku; } function get_parent_id() { return $this->parent; } function get_price() { return $this->price; } function get_name() { return $this->name; } }
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? null; } function get_the_terms( $id, $tax ) { return array( (object) array( 'name' => 'Добавки' ) ); }
$GLOBALS['products'] = array( 3994 => new FakeProduct( 3994, 'DGR' ), 3995 => new FakeProduct( 3995, '', 3994, 19.9, 'Daily Greens – 1 кутия' ) );
$v = $GLOBALS['products'][3995];
eq( Catalog::content_id( $v ), '3995', 'id формат' );
$GLOBALS['opts']['ansa_meta']['id_format'] = 'sku'; $reset(); eq( Catalog::content_id( $v ), 'DGR', 'sku формат: вариация без SKU → SKU на родителя' );
$GLOBALS['opts']['ansa_meta']['id_format'] = 'wc_post_id'; $reset(); eq( Catalog::content_id( $v ), 'wc_post_id_3995', 'wc_post_id формат' );
$GLOBALS['opts']['ansa_meta']['id_format'] = 'id'; $reset();
$pd = Catalog::product_data( $v, 2 );
eq( $pd['value'], 39.8, 'product_data value = цена × бр' ); eq( $pd['contents'][0]['item_price'], 19.9, 'item_price' ); eq( $pd['content_category'], 'Добавки', 'категория' ); eq( $pd['product_group_id'], '3994', 'product_group_id за вариация' ); eq( $pd['currency'], 'EUR', 'валута' );

/* ── Settings::sanitize ── */
$GLOBALS['opts']['ansa_meta'] = array( 'token' => 'OLDTOKEN' );
$c = Settings::sanitize( array( 'pixel_id' => ' 12 34 ', 'token' => '', 'api_version' => 'bad', 'id_format' => 'zzz', 'purchase_on' => 'paid', 'events' => array( 'PageView' => '1' ), 'exclude_roles' => array( 'administrator', 'x y' ), 'internal_ips' => "10.0.0.0/8, junk!, 1.2.3.4", 'test_code' => 'test-12 345', 'log_keep' => 5 ) );
eq( $c['pixel_id'], '1234', 'pixel_id само цифри' ); eq( $c['token'], 'OLDTOKEN', 'празен token пази стария' ); eq( $c['api_version'], 'v24.0', 'api_version по подразбиране при боклук' );
eq( $c['id_format'], 'id', 'id_format по подразбиране' ); eq( $c['purchase_on'], 'paid', 'purchase_on' ); eq( $c['events']['PageView'], 1, 'events включено' ); eq( $c['events']['Purchase'], 0, 'неотметнато → 0' );
eq( $c['exclude_roles'], array( 'administrator', 'xy' ), 'роли sanitize_key' ); eq( $c['internal_ips'], "10.0.0.0/8\n1.2.3.4", 'IP списък без боклук' ); eq( $c['test_code'], 'TEST12345', 'test_code главни/без тирета' ); eq( $c['log_keep'], 50, 'log_keep минимум 50' );
$c2 = Settings::sanitize( array( 'token' => 'NEW', 'token_clear' => '1' ) ); eq( $c2['token'], '', 'token_clear трие' );
$c3 = Settings::sanitize( array( 'token' => 'NEW' ) ); eq( $c3['token'], 'NEW', 'нов token' );

done();
