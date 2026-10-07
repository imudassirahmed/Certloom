<?php
/** FLD-02 safe field-retirement usage index regression. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} function absint($v){return abs((int)$v);}
class Ecapla_CertVerify_Fields { public static function get_field_map(){ return array(
 'employee_id'=>array('key'=>'employee_id','label'=>'Employee ID','type'=>'text','sensitivity'=>'personal','public_exposure'=>'private'),
 'campus'=>array('key'=>'campus','label'=>'Campus','type'=>'text','sensitivity'=>'public_safe','public_exposure'=>'profile'),
); } }
final class FLD02FakeWpdb {
 public $prefix='wp_'; public $last_error=''; public $calls=array(); public $bad_template=false;
 public function prepare($query,...$args){ return array($query,$args); }
 public function get_results($prepared,$format){ [$query,$args]=$prepared; $table=''; foreach($args as $arg){ if(is_string($arg)&&0===strpos($arg,'wp_certloom_')){$table=$arg;break;} } $this->calls[$table]=($this->calls[$table]??0)+1; if(($this->calls[$table]??0)>1)return array();
  if('wp_certloom_programs'===$table){ $meta=array('certloom_scoped_fields_v1'=>array('_meta'=>array('schema'=>'certloom-scoped-fields','schema_version'=>1,'scope'=>'program','configured'=>true),'fields'=>array('employee_id'=>'required'))); return array(array('id'=>2,'title'=>'Safety Training','metadata_json'=>json_encode($meta))); }
  if('wp_certloom_batches'===$table){ $meta=array('certloom_scoped_fields_v1'=>array('_meta'=>array('schema'=>'certloom-scoped-fields','schema_version'=>1,'scope'=>'batch','configured'=>true),'fields'=>array('campus'=>'optional'))); return array(array('id'=>4,'name'=>'Spring Cohort','metadata_json'=>json_encode($meta))); }
  if('wp_certloom_templates'===$table){ $layout=$this->bad_template ? '{broken' : json_encode(array('elements'=>array(array('type'=>'merge','token'=>'custom:employee_id')))); return array(array('id'=>8,'name'=>'Training Certificate','current_layout'=>$layout,'published_layout'=>$layout)); }
  return array();
 }
}
$GLOBALS['wpdb']=new FLD02FakeWpdb();
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Repository\ScopedFieldUsageRepository;
$pass=0;$fail=0;function fldret($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$repo=new ScopedFieldUsageRepository(); $all=$repo->all_usage();
fldret(!is_wp_error($all),'usage index resolves active mutable field consumers');
$employee=is_wp_error($all)?array():($all['employee_id']??array()); $campus=is_wp_error($all)?array():($all['campus']??array());
fldret(1===($employee['program_count']??0)&&1===($employee['template_count']??0)&&!empty($employee['referenced']),'Program requirement and active Template token both block retirement');
fldret(1===($campus['batch_count']??0)&&!empty($campus['referenced']),'Batch override blocks retirement');
$none=$repo->usage_for('unused_field'); fldret(!is_wp_error($none)&&empty($none['referenced']),'unreferenced field remains safely retireable');
fldret(1===($GLOBALS['wpdb']->calls['wp_certloom_programs']??0)&&1===($GLOBALS['wpdb']->calls['wp_certloom_batches']??0)&&1===($GLOBALS['wpdb']->calls['wp_certloom_templates']??0),'usage index reads each small fixture consumer set once and does not issue duplicate scans');
$GLOBALS['wpdb']=new FLD02FakeWpdb(); $GLOBALS['wpdb']->bad_template=true; $bad=(new ScopedFieldUsageRepository())->all_usage();
fldret(is_wp_error($bad)&&'certloom_field_usage_template_layout_invalid'===$bad->get_error_code(),'invalid active Template layout fails retirement indexing closed');
$root=dirname(__DIR__);$admin=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');$view=file_get_contents($root.'/admin/views/fields-manager.php');$src=file_get_contents($root.'/src/Infrastructure/Repository/ScopedFieldUsageRepository.php');
fldret(false!==strpos($admin,'ScopedFieldUsageRepository')&&false!==strpos($admin,'referenced'),'server-side retirement action refuses fields that are still referenced');
fldret(false!==strpos($view,'Used by')||false!==strpos($view,'In use'),'Field Manager exposes active usage instead of silently detaching assignments');
fldret(false!==strpos($src,'Historical Credential Version')&&false!==strpos($src,'CHUNK = 250'),'retirement design preserves immutable history and bounds usage scans');
echo "\n$pass FLD-02 retirement checks, $fail failures\n"; exit($fail?1:0);
