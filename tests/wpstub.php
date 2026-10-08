<?php
/* минимален WP stub за офлайн тестове на Copy/Config/Seed */
define('ABSPATH', __DIR__.'/'); define('MINUTE_IN_SECONDS',60); define('HOUR_IN_SECONDS',3600); define('DAY_IN_SECONDS',86400);
define('ANSA_PROMO_VER','1.0.2'); define('ANSA_PROMO_PATH',dirname(__DIR__).'/'); define('ANSA_PROMO_URL','http://x/wp-content/plugins/ansa-promo/');
function sanitize_text_field($s){ return trim(strip_tags((string)$s)); } function sanitize_key($s){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s)); }
function wp_kses($s,$a){ return strip_tags((string)$s,'<b><strong><em><br>'); } function esc_url_raw($u){ return (string)$u; }
function wp_strip_all_tags($s){ return trim(strip_tags((string)$s)); } function wp_json_encode($v,$f=0){ return json_encode($v,$f); } function get_option($k,$d=false){ return $GLOBALS['opts'][$k]??$d; } function update_option($k,$v,$a=true){ $GLOBALS['opts'][$k]=$v; return true; }
function delete_transient($k){} function wp_timezone_string(){ return 'Europe/Sofia'; } function get_permalink($id){ return 'http://x/promo/'; } function do_action(){} function add_action(){}
function current_time($f){ return date('Y-m-d H:i:s'); } function function_exists_x(){}
spl_autoload_register(function($c){ if(0!==strpos($c,'AnsaPromo\\'))return; $n=substr($c,10); $f=ANSA_PROMO_PATH.'includes/class-'.strtolower(str_replace('_','-',$n)).'.php'; if(is_file($f))require_once $f; });
