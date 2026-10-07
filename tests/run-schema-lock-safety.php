<?php
/** Pure regression tests for checked next-domain schema-lock cleanup. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['schema_lock_options'] = array();
$GLOBALS['schema_lock_delete_fail'] = false;
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $code; private $message; public function __construct($c='',$m=''){ $this->code=$c; $this->message=$m; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['schema_lock_options'])?$GLOBALS['schema_lock_options'][$k]:$d;}
function wp_cache_delete(){return true;}
class SchemaLockWpdb {
	public $options='wp_options'; public $last_error='';
	public function prepare($sql,...$args){return array($sql,$args);}
	public function query($prepared){
		$args=is_array($prepared)?$prepared[1]:array();
		if($GLOBALS['schema_lock_delete_fail']){return 0;}
		$key=$args[1]??''; $expected=$args[2]??'';
		if(isset($GLOBALS['schema_lock_options'][$key]) && $GLOBALS['schema_lock_options'][$key]===$expected){unset($GLOBALS['schema_lock_options'][$key]);return 1;}
		return 0;
	}
}
$GLOBALS['wpdb']=new SchemaLockWpdb();
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Database\SchemaManager;
function sl_same($e,$a,$m=''){if($e!==$a){throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}}
$method=new ReflectionMethod(SchemaManager::class,'release_lock');
if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible(true); }
$token='worker-token';
$GLOBALS['schema_lock_options'][SchemaManager::LOCK_OPTION]=$token.'|'.time();
sl_same(true,$method->invoke(null,$token),'owned schema lock releases');
sl_same(false,get_option(SchemaManager::LOCK_OPTION,false),'lock option removed');
$GLOBALS['schema_lock_options'][SchemaManager::LOCK_OPTION]='other-token|'.time();
$error=$method->invoke(null,$token);
sl_same('certloom_domain_schema_lock_owner_changed',$error->get_error_code(),'cannot release another worker lock');
$GLOBALS['schema_lock_options'][SchemaManager::LOCK_OPTION]=$token.'|'.time();
$GLOBALS['schema_lock_delete_fail']=true;
$error=$method->invoke(null,$token);
sl_same('certloom_domain_schema_lock_release_failed',$error->get_error_code(),'delete failure is explicit');
sl_same(true,(bool)get_option(SchemaManager::LOCK_OPTION,false),'failed cleanup leaves lock evidence');
echo "PASS  next-domain schema maintenance lock cleanup is owner-checked and fail-closed\n\n1 test, 0 failures\n";
