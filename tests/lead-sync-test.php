<?php
/**
 * Офлайн тест на Lead_Sync (1.11.0) със стъбове: фалшиви WooFunnels_Contact / BWFCRM_Contact / BWFCRM_Tag / BWFCRM_Lists и $wpdb.
 * php tests/lead-sync-test.php → „OK n проверки“ или списък с грешки (exit 1).
 */
require __DIR__ . '/wpstub.php';
define( 'ARRAY_A', 'ARRAY_A' );
use AnsaPromo\Config; use AnsaPromo\Leads; use AnsaPromo\Lead_Sync; use AnsaPromo\Merge; use AnsaPromo\FKA_Pause;

function email_exists( $e ) { return 'user@example.com' === $e ? 7 : false; }
function wp_strip_all_tags_x() {}
function current_user_can( $c ) { return false; }

/* ── $wpdb: leads таблица в паметта + термините на FKA ── */
class FakeWPDB {
	public $prefix = 'wp_'; public $insert_id = 0; public $last_error = ''; public $leads = array(); public $terms = array(); public $log = array();
	function prepare( $q, ...$a ) { foreach ( $a as $v ) { $q = preg_replace( '/%[ds]/', is_int( $v ) ? $v : "'" . addslashes( (string) $v ) . "'", $q, 1 ); } return $q; }
	function get_var( $q ) { if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $q, $m ) ) { return false !== strpos( $m[1], 'bwfan_terms' ) || false !== strpos( $m[1], 'leads' ) ? $m[1] : null; } if ( false !== strpos( $q, 'COUNT(*)' ) ) { return count( $this->leads ); } return null; }
	function get_col( $q ) { if ( false !== strpos( $q, 'SHOW COLUMNS FROM wp_bwfan_terms' ) ) { return array( 'ID', 'name', 'type' ); } if ( false !== strpos( $q, 'SHOW COLUMNS' ) ) { return array( 'id', 'email', 'fka', 'contact_id' ); } return array(); }
	function get_results( $q, $f = null ) { if ( false !== strpos( $q, 'SHOW COLUMNS FROM wp_bwfan_terms' ) ) { return array( array( 'Field' => 'ID', 'Type' => 'int', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment' ), array( 'Field' => 'name', 'Type' => 'varchar', 'Null' => 'NO', 'Default' => null, 'Extra' => '' ), array( 'Field' => 'type', 'Type' => 'int', 'Null' => 'NO', 'Default' => null, 'Extra' => '' ) ); }
		if ( false !== strpos( $q, 'ansa_promo_leads' ) ) { return array_values( array_filter( $this->leads, function ( $r ) { return '' === $r['fka'] || 0 === strpos( $r['fka'], 'retry' ); } ) ); } return array(); }
	function get_row( $q, $f = null ) {
		if ( preg_match( "/FROM wp_bwfan_terms WHERE type = (\d+) AND name = '([^']+)'/", $q, $m ) ) { foreach ( $this->terms as $t ) { if ( (int) $t['type'] === (int) $m[1] && $t['name'] === stripslashes( $m[2] ) ) { return $t; } } return null; }
		if ( preg_match( '/ansa_promo_leads WHERE id = (\d+)/', $q, $m ) ) { return $this->leads[ (int) $m[1] ] ?? null; }
		if ( false !== strpos( $q, 'COUNT(*) AS total' ) ) { $s = array( 'total' => count( $this->leads ), 'consent' => 0, 'synced' => 0, 'pending' => 0, 'converted' => 0 ); foreach ( $this->leads as $r ) { $s['consent'] += (int) $r['consent']; $s['synced'] += ( '' !== $r['fka'] && 0 !== strpos( $r['fka'], 'retry' ) && 'no-consent' !== $r['fka'] ) ? 1 : 0; $s['pending'] += ( '' === $r['fka'] || 0 === strpos( $r['fka'], 'retry' ) ) ? 1 : 0; $s['converted'] += $r['converted_order_id'] ? 1 : 0; } return $s; }
		return null;
	}
	function insert( $t, $row, $fmt = null ) { if ( false !== strpos( $t, 'bwfan_terms' ) ) { $id = count( $this->terms ) + 1; $this->terms[] = array( 'ID' => $id, 'name' => $row['name'], 'type' => $row['type'] ); $this->insert_id = $id; return 1; }
		$id = count( $this->leads ) + 1; $this->leads[ $id ] = array_merge( array( 'id' => $id, 'converted_order_id' => null, 'contact_id' => null, 'fka' => '', 'fka_at' => null ), $row ); $this->insert_id = $id; return 1; }
	function update( $t, $data, $where, $f1 = null, $f2 = null ) { $id = (int) $where['id']; if ( isset( $this->leads[ $id ] ) ) { $this->leads[ $id ] = array_merge( $this->leads[ $id ], $data ); return 1; } return 0; }
	function query( $q ) { if ( preg_match( "/SET converted_order_id = (\d+) WHERE converted_order_id IS NULL AND email = '([^']+)'/", $q, $m ) ) { $n = 0; foreach ( $this->leads as &$r ) { if ( null === $r['converted_order_id'] && $r['email'] === $m[2] ) { $r['converted_order_id'] = (int) $m[1]; $n++; } } return $n; } return 0; }
}
$GLOBALS['wpdb'] = new FakeWPDB(); $wpdb = $GLOBALS['wpdb'];

/* ── FunnelKit контакти (Funnel Builder) ── */
class WooFunnels_Contact {
	public static $db = array(); /* email => [id, wpid, tags, lists, status, type, source, creation_date] */
	private $email = ''; private $wpid = 0; private $id = 0; private $tags = array(); private $lists = array(); private $status = 1; private $type = 'lead'; private $source = ''; private $cd = ''; public $is_subscribed = false;
	function __construct( $wp_id = '', $email = '', $phone = '', $cid = '', $uid = '' ) { $this->email = $email; $this->wpid = (int) $wp_id; if ( isset( self::$db[ $email ] ) ) { foreach ( self::$db[ $email ] as $k => $v ) { $this->$k = $v; } } }
	function get_id() { return $this->id; } function get_tags() { return $this->tags; } function get_lists() { return $this->lists; } function set_tags( $t ) { $this->tags = $t; } function set_lists( $l ) { $this->lists = $l; }
	function set_email( $e ) { $this->email = $e; } function set_wpid( $w ) { $this->wpid = (int) $w; } function set_creation_date( $d ) { $this->cd = $d; } function set_source( $s ) { $this->source = $s; } function get_source() { return $this->source; } function set_type( $t ) { $this->type = $t; } function get_type() { return $this->type; }
	function set_status( $s ) { if ( 1 === (int) $s ) { $this->is_subscribed = true; BWFCRM_Contact::$events[] = 'subscribed:' . $this->email; } $this->status = (int) $s; } function get_status() { return $this->status; }
	function save() { if ( ! $this->id ) { $this->id = count( self::$db ) + 1; } self::$db[ $this->email ] = array( 'id' => $this->id, 'wpid' => $this->wpid, 'tags' => $this->tags, 'lists' => $this->lists, 'status' => $this->status, 'type' => $this->type, 'source' => $this->source, 'cd' => $this->cd ); }
}
function bwf_get_contact( $wp_id, $email ) { return new WooFunnels_Contact( $wp_id, $email ); }
/* Automations: BWFCRM_Contact с add/remove (event-ите); BWFCRM_Tag / BWFCRM_Lists само търсят, създаването пада на таблицата */
class BWFCRM_Contact { private $c; public static $events = array(); function __construct( $x ) { $this->c = $x instanceof WooFunnels_Contact ? $x : new WooFunnels_Contact( '', is_string( $x ) ? $x : '' ); if ( $this->c->get_id() <= 0 ) { throw new Exception( 'no contact' ); } }
	private function ids( $t ) { return array_map( function ( $x ) { return is_array( $x ) ? (int) $x['id'] : (int) $x; }, $t ); }
	function add_tags( $t ) { $this->c->set_tags( array_values( array_unique( array_merge( $this->c->get_tags(), $this->ids( $t ) ) ) ) ); self::$events[] = 'tag_added'; }
	function add_lists( $t ) { $this->c->set_lists( array_values( array_unique( array_merge( $this->c->get_lists(), $this->ids( $t ) ) ) ) ); self::$events[] = 'list_added'; }
	function remove_tags( $t ) { $this->c->set_tags( array_values( array_diff( $this->c->get_tags(), $this->ids( $t ) ) ) ); self::$events[] = 'tag_removed'; }
	function save() { $this->c->save(); } }
class BWFCRM_Tag { static function get_tags( $a = array(), $b = false, $c = '', $d = '' ) { return array_values( array_filter( $GLOBALS['wpdb']->terms, function ( $t ) { return 1 === (int) $t['type']; } ) ); } }
class BWFCRM_Lists { static function get_lists( $a = array(), $b = false, $c = '', $d = '' ) { return array_values( array_filter( $GLOBALS['wpdb']->terms, function ( $t ) { return 2 === (int) $t['type']; } ) ); } }
/* фалшива поръчка */
class FakeOrder { public $meta = array(); public $notes = array(); private $email; private $id; function __construct( $id, $email, $m ) { $this->id = $id; $this->email = $email; $this->meta['_ansa_promo'] = $m; }
	function get_id() { return $this->id; } function get_customer_id() { return 0; } function get_billing_email() { return $this->email; } function get_meta( $k ) { return $this->meta[ $k ] ?? ''; } function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; } function save() {} function add_order_note( $n ) { $this->notes[] = $n; } }

/* ── тестове ── */
$fails = array(); $n = 0;
function ok( $name, $cond, $msg = '' ) { global $fails, $n; $n++; if ( ! $cond ) { $fails[] = $name . ( $msg ? ' — ' . $msg : '' ); } }

$cfg = Config::normalize( Config::defaults() );
/* 1.24.0: имейлите от FunnelKit са СПРЕНИ по подразбиране (липсваща опция) — за тестовете на самата синхронизация ги включваме */
ok( 'FKA_Pause: по подразбиране спрени', FKA_Pause::paused() && 'warn' === FKA_Pause::status()[1] );
ok( 'FKA_Pause::save(true) → включени', FKA_Pause::save( true ) && FKA_Pause::enabled() && 'on' === get_option( FKA_Pause::OPT ) && 'ok' === FKA_Pause::status()[1] );
ok( 'дефолт: включено, лист + таг', Lead_Sync::enabled( $cfg ) && 'sezon-lead' === $cfg['leads']['tag'] && '' !== $cfg['leads']['list'] );
ok( 'ensure_terms създава листа (type 2) и тага (type 1)', Lead_Sync::ensure_terms() && Merge::list_id( $cfg['leads']['list'] ) > 0 && Merge::tag_id( $cfg['leads']['tag'] ) > 0, Merge::$tag_diag );
$lid = Merge::list_id( $cfg['leads']['list'] ); $tid = Merge::tag_id( $cfg['leads']['tag'] );
ok( 'листът и тагът са различни термини', $lid !== $tid && 2 === (int) $wpdb->terms[ $lid - 1 ]['type'] && 1 === (int) $wpdb->terms[ $tid - 1 ]['type'] );

/* гост със съгласие */
$id1 = Leads::capture( 'Guest@Example.com', true, 'sakura', 'l' ); ok( 'capture → лийд', $id1 > 0 );
$r = Lead_Sync::sync( $id1, 'Guest@Example.com', true );
ok( 'sync: лист + таг през BWFCRM (crm)', false !== strpos( $r, 'лист #' . $lid . ' · crm' ) && false !== strpos( $r, 'таг #' . $tid . ' · crm' ), $r );
$c = WooFunnels_Contact::$db['guest@example.com'] ?? null;
ok( 'гост контакт: без wpid, type lead, source ansa-promo, status 1 (subscribed)', $c && 0 === $c['wpid'] && 'lead' === $c['type'] && 'ansa-promo' === $c['source'] && 1 === $c['status'] && in_array( 'subscribed:guest@example.com', BWFCRM_Contact::$events, true ), json_encode( $c ) );
ok( 'имейлът в leads е с малки букви', 'guest@example.com' === $wpdb->leads[ $id1 ]['email'] );
ok( 'event-ите на FKA са пуснати', in_array( 'list_added', BWFCRM_Contact::$events, true ) && in_array( 'tag_added', BWFCRM_Contact::$events, true ) );
ok( 'leads.fka / contact_id записани', $wpdb->leads[ $id1 ]['fka'] === $r && (int) $wpdb->leads[ $id1 ]['contact_id'] === $c['id'] );
ok( 'повторен sync → има', false !== strpos( Lead_Sync::sync( $id1, 'guest@example.com', true ), 'има' ) );

/* без съгласие → не */
$id2 = Leads::capture( 'nope@example.com', false, '', '' );
ok( 'без съгласие → no-consent, без контакт', 'no-consent' === Lead_Sync::sync( $id2, 'nope@example.com', false ) && ! isset( WooFunnels_Contact::$db['nope@example.com'] ) );
/* с consent_only изключено → синхронизира и без съгласие */
$cfg2 = $cfg; $cfg2['leads']['consent_only'] = false;
ok( 'consent_only=off → синхронизира (без set_status(1))', false !== strpos( Lead_Sync::sync( $id2, 'nope@example.com', false, $cfg2 ), 'лист #' ) && isset( WooFunnels_Contact::$db['nope@example.com'] ) && ! in_array( 'subscribed:nope@example.com', BWFCRM_Contact::$events, true ) );

/* WP потребител → wpid */
$id3 = Leads::capture( 'user@example.com', true, '', '' ); Lead_Sync::sync( $id3, 'user@example.com', true );
ok( 'WP потребител → контакт с wpid 7', 7 === (int) ( WooFunnels_Contact::$db['user@example.com']['wpid'] ?? 0 ) );

/* поръчка: billing имейлът е друг (гостът е дал друг в чекаута) → и двата контакта губят тага, лийдът е конвертиран, листът остава */
$id4 = Leads::capture( 'other@example.com', true, '', '' ); Lead_Sync::sync( $id4, 'other@example.com', true );
$o = new FakeOrder( 500, 'other@example.com', array( 'promo' => $cfg['id'], 'box' => 'l', 'lead_id' => $id1, 'lead_email' => 'guest@example.com', 'draft' => false ) );
$r = Lead_Sync::on_order( $o, $o->get_meta( '_ansa_promo' ) );
ok( 'on_order → ok', 'ok' === $r && 'ok' === $o->meta['_ansa_promo_lead_untag'], $r . ' / ' . ( $o->meta['_ansa_promo_lead_untag'] ?? '' ) );
ok( 'тагът падна от billing контакта и от контакта на попъпа', ! in_array( $tid, WooFunnels_Contact::$db['other@example.com']['tags'], true ) && ! in_array( $tid, WooFunnels_Contact::$db['guest@example.com']['tags'], true ) );
ok( 'листът остава', in_array( $lid, WooFunnels_Contact::$db['other@example.com']['lists'], true ) && in_array( $lid, WooFunnels_Contact::$db['guest@example.com']['lists'], true ) );
ok( 'event Tag Removed', in_array( 'tag_removed', BWFCRM_Contact::$events, true ) );
ok( 'лийдовете са конвертирани', 500 === (int) $wpdb->leads[ $id1 ]['converted_order_id'] && 500 === (int) $wpdb->leads[ $id4 ]['converted_order_id'] );
ok( 'бележка към поръчката', count( $o->notes ) >= 1, json_encode( $o->notes ) );
ok( 'повторно on_order → ok без работа', 'ok' === Lead_Sync::on_order( $o, $o->get_meta( '_ansa_promo' ) ) );
ok( 'конвертиран лийд при sync → листът да, тагът не', false !== strpos( Lead_Sync::sync( $id1, 'guest@example.com', true ), 'таг: конвертиран' ) && ! in_array( $tid, WooFunnels_Contact::$db['guest@example.com']['tags'], true ) );

/* backfill: лийд без fka (напр. от преди 1.11.0) */
$wpdb->leads[ $id4 ]['fka'] = ''; $wpdb->leads[ $id2 ]['fka'] = 'retry: нещо';
$bf = Lead_Sync::backfill();
ok( 'backfill: минава през празни и retry', 1 === $bf['ok'] && 1 === $bf['skip'] && 0 === $bf['retry'], json_encode( $bf ) );

/* изключено */
$cfg3 = $cfg; $cfg3['leads']['fka'] = false;
ok( 'изключено → off', 'off' === Lead_Sync::sync( 0, 'x@example.com', true, $cfg3 ) && ! Lead_Sync::enabled( $cfg3 ) );
ok( 'status текст', false !== strpos( Lead_Sync::status( $cfg ), 'лист „' . $cfg['leads']['list'] . '“ #' . $lid ), Lead_Sync::status( $cfg ) );

/* 1.24.0: спрени имейли от FunnelKit → нищо не се тагва */
FKA_Pause::save( false );
ok( 'FKA_Pause::save(false) → спрени', FKA_Pause::paused() && 'off' === get_option( FKA_Pause::OPT ) );
$id5 = Leads::capture( 'paused@example.com', true, '', '' );
ok( 'спрени: capture → лийдът остава без fka (чака), без контакт', $id5 > 0 && '' === $wpdb->leads[ $id5 ]['fka'] && ! isset( WooFunnels_Contact::$db['paused@example.com'] ), json_encode( $wpdb->leads[ $id5 ] ) );
ok( 'спрени: sync → paused, без запис', 'paused' === Lead_Sync::sync( $id5, 'paused@example.com', true ) && '' === $wpdb->leads[ $id5 ]['fka'] && 'paused' === Lead_Sync::$diag );
$bf2 = Lead_Sync::backfill();
ok( 'спрени: backfill → всичко е skip', 0 === $bf2['ok'] && 0 === $bf2['retry'] && $bf2['skip'] >= 1, json_encode( $bf2 ) );
ok( 'спрени: status казва СПРЕНО', false !== strpos( Lead_Sync::status( $cfg ), 'СПРЕНО' ), Lead_Sync::status( $cfg ) );
$po = new FakeOrder( 501, 'paused@example.com', array( 'promo' => $cfg['id'], 'box' => 'l', 'valid' => true, 'draft' => false ) );
ok( 'спрени: Merge::tag_order → paused, без meta', 'paused' === Merge::tag_order( $po ) && ! isset( $po->meta['_ansa_promo_fka_tags'] ) && ! isset( $po->meta['_ansa_promo_fka_tag'] ), json_encode( $po->meta ) );
ok( 'спрени: on_order (тагът пада) продължава да работи', 'ok' === Lead_Sync::on_order( $po, $po->meta['_ansa_promo'] ) );
FKA_Pause::save( true ); Lead_Sync::contact( 'paused@example.com', true ); /* контактът (bwf_create_update_contact_object не е стъбнат) */
ok( 'включени → tag_order пак тагва', in_array( Merge::tag_order( $po ), array( 'crm', 'db', 'има' ), true ) && ! empty( $po->meta['_ansa_promo_fka_tags'] ), (string) Merge::tag_order( $po ) . ' ' . json_encode( $po->meta ) );
define( 'ANSA_PROMO_FKA_EMAILS', false );
ok( 'константата ANSA_PROMO_FKA_EMAILS бие опцията', FKA_Pause::paused() && FKA_Pause::by_const() && ! FKA_Pause::save( true ) );

if ( $fails ) { echo "FAIL:\n - " . implode( "\n - ", $fails ) . "\n"; exit( 1 ); }
echo "OK $n проверки\n";
