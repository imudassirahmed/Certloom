<?php
/** Current-authority legacy reads must fail closed on corrupt custom-field JSON. */
define('ABSPATH',__DIR__.'/');
define('DAY_IN_SECONDS',86400);define('MINUTE_IN_SECONDS',60);define('ARRAY_A','ARRAY_A');define('ECAPLA_CERTVERIFY_TABLE','certificates');
class WP_Error { private $c; private $m; public function __construct($c,$m,$d=null){$this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$domain=null){return $v;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function get_option($key,$default=false){return $default;}
function delete_option($key){return true;}
class FakeWpdbLegacyIntegrity {
 public $prefix='wp_'; public $last_error=''; public $row;
 public function prepare($sql,...$args){return $sql;}
 public function get_row($sql,$format=null){return $this->row;}
}
$wpdb=new FakeWpdbLegacyIntegrity();
require dirname(__DIR__).'/includes/class-ecapla-certverify-database.php';
function okl($c,$m){if(!$c)throw new RuntimeException($m);echo "PASS  $m\n";}
$base=array('id'=>1,'cert_number'=>'CERT-1','status'=>'valid','status_source'=>'manual','holder_name'=>'Ada','issue_date'=>null,'expiry_date'=>null,'created_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00');
$wpdb->row=$base+array('custom_fields'=>'{"public":"ok"}');
$valid=Ecapla_CertVerify_Database::get_by_cert_number('CERT-1');
okl(is_array($valid)&&($valid['custom_fields']['public']??'')==='ok','valid custom-field JSON decodes normally');
$wpdb->row=$base+array('custom_fields'=>'{"broken"');
$corrupt=Ecapla_CertVerify_Database::get_by_cert_number('CERT-1');
okl(is_wp_error($corrupt)&&$corrupt->get_error_code()==='ecapla_certverify_custom_fields_corrupt','corrupt custom-field JSON fails closed instead of becoming empty');
$wpdb->row=$base+array('custom_fields'=>'"scalar"');
$scalar=Ecapla_CertVerify_Database::get_by_cert_number('CERT-1');
okl(is_wp_error($scalar)&&$scalar->get_error_code()==='ecapla_certverify_custom_fields_corrupt','valid JSON scalar also fails closed because certificate custom state must be structured');
$dbSource=file_get_contents(dirname(__DIR__).'/includes/class-ecapla-certverify-database.php');
okl(false!==strpos($dbSource,'ecapla_certverify_purge_corrupt_custom_fields'),'retired-field purge stops on corrupt custom JSON');
okl(false!==strpos($dbSource,'JSON_ERROR_NONE !== json_last_error()') && false!==strpos($dbSource,'discover_orphaned_custom_field_keys'),'orphan discovery no longer silently skips corrupt custom JSON');
echo "\n5 tests, 0 failures\n";
