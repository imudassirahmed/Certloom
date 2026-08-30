<?php
/** BAK-02A canonical current-authority backup-builder behavioral regression. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'MB_IN_BYTES', 1024 * 1024 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ECAPLA_CERTVERIFY_VERSION', '1.6.88-test' );
define( 'ECAPLA_CERTVERIFY_SCHEMA_VERSION', '1.4.7' );
class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v,$d=null){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function sanitize_file_name($v){return preg_replace('/[^A-Za-z0-9._-]/','-',basename((string)$v));} function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);} function maybe_serialize($v){return serialize($v);} function wp_tempnam($name=''){return tempnam(sys_get_temp_dir(),'certloom-bak-');} function wp_generate_password($l=12){return str_repeat('x',$l);} function wp_die($m){throw new RuntimeException((string)$m);} function esc_html($v){return $v;}
$GLOBALS['builder_options']=array('certverify_data_generation'=>'gen-1','fields_opt'=>array('a'),'retired_opt'=>array(),'settings_opt'=>array('certificate_number_strategy'=>'manual'),'number_state'=>null);
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['builder_options'])?$GLOBALS['builder_options'][$k]:$d;}
class Ecapla_CertVerify_Fields { const OPTION_KEY='fields_opt'; const RETIRED_OPTION_KEY='retired_opt'; public static function get_fields(){return array(array('key'=>'course','label'=>'Course','type'=>'text'));} public static function get_retired_fields(){return array();} }
class Ecapla_CertVerify_Settings { const OPTION_KEY='settings_opt'; public static function get_all(){return array('certificate_number_strategy'=>'manual');} }
class Ecapla_CertVerify_Numbering { const STATE_OPTION='number_state'; public static function normalize_policy($s){return array('certificate_number_strategy'=>'manual');} public static function export_state(){return null;} }
class Ecapla_CertVerify_Database {
 public static $snapshot_calls=0; public static $mutate_snapshot=false;
 public static function get_registry_snapshot(){ self::$snapshot_calls++; return array('count'=>2,'max_id'=>self::$mutate_snapshot && self::$snapshot_calls>1?3:2,'max_updated_at'=>'2026-08-29 00:00:00'); }
 public static function get_export_batch($last,$limit){ if($last>=2)return array(); return array(array('id'=>1,'cert_number'=>'CERT-1','status'=>'valid','holder_name'=>'Ada','issue_date'=>'2026-01-01','expiry_date'=>null,'custom_fields'=>array('course'=>'A'),'created_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00'),array('id'=>2,'cert_number'=>'CERT-2','status'=>'revoked','holder_name'=>'Lin','issue_date'=>'2026-01-02','expiry_date'=>null,'custom_fields'=>array('course'=>'B'),'created_at'=>'2026-01-02 00:00:00','updated_at'=>'2026-01-02 00:00:00')); }
 public static function workflow_status($r){return $r['status'];} public static function effective_status_source($r){return 'explicit';}
}
require dirname(__DIR__).'/includes/class-ecapla-certverify-backup.php';
$checks=0;$fails=0; function bcheck($v,$m){global $checks,$fails;$checks++;if($v)echo "PASS  $m\n";else{$fails++;echo "FAIL  $m\n";}}
$b=Ecapla_CertVerify_Backup::create_export_file();
bcheck(is_array($b),'canonical builder returns metadata on stable current authority');
bcheck('current_registry'===($b['source_authority']??''),'builder declares current registry authority');
bcheck(2===($b['records']??-1),'builder reports exact record count');
bcheck(4===($b['format_version']??0),'builder preserves complete-backup v4 format');
bcheck(is_file($b['path']) && filesize($b['path'])===$b['bytes'],'builder returns complete existing file and exact byte size');
bcheck(hash_equals(hash_file('sha256',$b['path']),$b['file_sha256']),'builder fingerprints exact completed file bytes');
$lines=file($b['path'],FILE_IGNORE_NEW_LINES); $manifest=json_decode($lines[0],true); $last=json_decode($lines[count($lines)-1],true);
bcheck('manifest'===($manifest['type']??'') && 'certverify-backup'===($manifest['format']??''),'first line remains canonical manifest');
bcheck('1.6.88-test'===($manifest['plugin_version']??'') && '1.4.7'===($manifest['schema_version']??''),'manifest binds plugin/schema version');
bcheck('integrity'===($last['type']??'') && 2===($last['records']??0),'last line remains bounded integrity record');
$content=implode("\n",array_slice($lines,0,-1))."\n"; bcheck(hash_equals(hash('sha256',$content),$last['sha256']),'integrity SHA covers manifest and records exactly');
@unlink($b['path']);
Ecapla_CertVerify_Database::$snapshot_calls=0; Ecapla_CertVerify_Database::$mutate_snapshot=true;
$changed=Ecapla_CertVerify_Backup::create_export_file();
bcheck(is_wp_error($changed) && 'ecapla_certverify_backup_changed_during_generation'===$changed->get_error_code(),'builder fails closed when registry state changes during generation');
$src=file_get_contents(dirname(__DIR__).'/includes/class-ecapla-certverify-backup.php');
bcheck(false!==strpos($src,'public static function create_export_file()'),'reusable builder remains explicit public contract for manual/scheduled paths');
bcheck(false!==strpos($src,'$backup = self::create_export_file();'),'manual stream exporter delegates to canonical builder');
bcheck(false===strpos($src,"'source_authority' => 'normalized_domain'"),'current-authority builder never claims gated normalized-domain authority');
echo "\n$checks checks, $fails failures\n"; exit($fails?1:0);
