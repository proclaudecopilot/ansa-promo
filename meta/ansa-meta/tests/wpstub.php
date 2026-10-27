<?php
/* минимален WP/WC stub за офлайн тестовете на ansa™ Meta (без WordPress): php tests/identity-test.php · php tests/capi-test.php */
define( 'ABSPATH', __DIR__ . '/' ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 ); define( 'ARRAY_A', 'ARRAY_A' );
define( 'ANSA_META_VER', '1.0.0-test' ); define( 'ANSA_META_DB_VER', '1' ); define( 'ANSA_META_PATH', dirname( __DIR__ ) . '/' ); define( 'ANSA_META_URL', 'http://x/wp-content/plugins/ansa-meta/' ); define( 'ANSA_META_FILE', ANSA_META_PATH . 'ansa-meta.php' );
spl_autoload_register( function ( $c ) { if ( 0 !== strpos( $c, 'AnsaMeta\\' ) ) return; $n = substr( $c, 9 ); $f = ANSA_META_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $n ) ) . '.php'; if ( is_file( $f ) ) require_once $f; } );
$GLOBALS['opts'] = array(); $GLOBALS['actions'] = array(); $GLOBALS['filters'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; } function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; } function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); }
function apply_filters( $h, $v ) { return $v; } function do_action() {} function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['actions'][ $h ][] = $cb; } function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][ $h ][] = $cb; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function esc_url_raw( $u ) { return (string) $u; } function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); } function home_url( $p = '' ) { return 'https://ansa.bg' . $p; } function is_ssl() { return true; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); } function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) ); }
function is_user_logged_in() { return ! empty( $GLOBALS['uid'] ); } function get_current_user_id() { return (int) ( $GLOBALS['uid'] ?? 0 ); }
function wp_get_current_user() { return (object) array( 'ID' => get_current_user_id(), 'roles' => (array) ( $GLOBALS['roles'] ?? array() ) ); }
function get_userdata( $id ) { return $GLOBALS['users'][ $id ] ?? false; } function get_user_meta( $id, $k, $s = false ) { return $GLOBALS['umeta'][ $id ][ $k ] ?? ''; }
function wp_doing_ajax() { return ! empty( $GLOBALS['ajax'] ); } function wp_get_referer() { return $GLOBALS['ref'] ?? ''; }
function wp_next_scheduled( $h ) { return $GLOBALS['cron'][ $h ] ?? false; } function wp_schedule_event( $t, $s, $h ) { $GLOBALS['cron'][ $h ] = $t; } function wp_unschedule_event( $t, $h ) { unset( $GLOBALS['cron'][ $h ] ); }
function wp_remote_post( $url, $args ) { $GLOBALS['http'][] = array( 'url' => $url, 'args' => $args ); $r = $GLOBALS['http_response'] ?? array( 'code' => 200, 'body' => '{"events_received":1,"fbtrace_id":"abc"}' ); if ( $r instanceof WP_Error ) return $r; return array( 'response' => array( 'code' => $r['code'] ), 'body' => $r['body'] ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; } function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; } function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_rand( $a, $b ) { return mt_rand( $a, $b ); } function wc_get_checkout_url() { return 'https://ansa.bg/checkout/'; } function get_woocommerce_currency() { return 'EUR'; }

class WP_Error { private $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
/* $wpdb в паметта: log + queue */
class FakeWPDB {
	public $prefix = 'wp_'; public $log = array(); public $queue = array(); public $insert_id = 0; public $options = 'wp_options';
	function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) $a = $a[0]; foreach ( $a as $v ) { $q = preg_replace( '/%[ds]/', is_int( $v ) ? $v : "'" . addslashes( (string) $v ) . "'", $q, 1 ); } return $q; }
	function insert( $t, $row, $f = null ) { $row['id'] = ++$this->insert_id; if ( false !== strpos( $t, 'queue' ) ) { $this->queue[ $row['id'] ] = $row; } else { $this->log[ $row['id'] ] = $row; } return 1; }
	function get_var( $q ) { if ( false !== strpos( $q, 'COUNT(*)' ) ) { return false !== strpos( $q, 'queue' ) ? count( $this->queue ) : count( $this->log ); } return null; }
	function get_results( $q, $f = null ) { if ( false !== strpos( $q, 'ansa_meta_queue' ) ) { return array_values( $this->queue ); } return array(); }
	function get_col( $q ) { return array(); } function get_row( $q, $f = null ) { return null; }
	function delete( $t, $w, $f = null ) { unset( $this->queue[ (int) $w['id'] ] ); return 1; }
	function update( $t, $d, $w, $f1 = null, $f2 = null ) { if ( isset( $this->queue[ (int) $w['id'] ] ) ) $this->queue[ (int) $w['id'] ] = array_merge( $this->queue[ (int) $w['id'] ], $d ); return 1; }
	function query( $q ) { if ( preg_match( '/DELETE FROM wp_ansa_meta_queue WHERE id IN \(([\d,]+)\)/', $q, $m ) ) { foreach ( explode( ',', $m[1] ) as $id ) unset( $this->queue[ (int) $id ] ); } return 1; }
}
$GLOBALS['wpdb'] = new FakeWPDB(); $wpdb = $GLOBALS['wpdb'];
/* тест-харнес */
$GLOBALS['T'] = array( 'n' => 0, 'err' => array() );
function ok( $cond, $label, $note = '' ) { $GLOBALS['T']['n']++; if ( ! $cond ) { $GLOBALS['T']['err'][] = $label . ( '' !== $note ? ' — ' . $note : '' ); } }
function eq( $a, $b, $label ) { ok( $a === $b, $label, 'очаквах ' . var_export( $b, true ) . ', получих ' . var_export( $a, true ) ); }
function done() { $T = $GLOBALS['T']; if ( $T['err'] ) { echo "ГРЕШКИ:\n - " . implode( "\n - ", $T['err'] ) . "\n"; exit( 1 ); } echo 'OK ' . $T['n'] . " проверки\n"; }
