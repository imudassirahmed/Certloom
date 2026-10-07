<?php
/** FLD-02 automatic integration field compatibility regression. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define('ABSPATH',__DIR__.'/');
if(!class_exists('WP_Error')){class WP_Error{private$c,$m;function __construct($c='',$m=''){$this->c=$c;$this->m=$m;}function get_error_code(){return$this->c;}function get_error_message(){return$this->m;}}}
function is_wp_error($v){return$v instanceof WP_Error;} function __($v){return$v;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once __DIR__.'/bootstrap-autoload.php';
use Certloom\Application\Integration\IntegrationFieldCompatibility;
$pass=0;$fail=0;function ifc($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$catalog=array(
 'first_name'=>array('key'=>'first_name','label'=>'First name','type'=>'text','sensitivity'=>'personal','public_exposure'=>'private'),
 'grade'=>array('key'=>'grade','label'=>'Grade','type'=>'text','sensitivity'=>'personal','public_exposure'=>'private'),
 'employee_id'=>array('key'=>'employee_id','label'=>'Employee ID','type'=>'text','sensitivity'=>'personal','public_exposure'=>'private'),
);
$guard=new IntegrationFieldCompatibility();
$legacy=array('field_contract'=>array()); ifc(true===$guard->validate_program($legacy,$catalog),'legacy Program remains structurally compatible with current LMS automation');
$recipientOnly=array('field_contract'=>array('_meta'=>array('schema'=>'certloom-scoped-fields','schema_version'=>1,'scope'=>'program','configured'=>true),'fields'=>array('first_name'=>'required'))); ifc(true===$guard->validate_program($recipientOnly,$catalog),'required Recipient-owned field is structurally sourceable by an explicitly linked Recipient');
$grade=array('field_contract'=>array('_meta'=>array('schema'=>'certloom-scoped-fields','schema_version'=>1,'scope'=>'program','configured'=>true),'fields'=>array('grade'=>'required'))); $r=$guard->validate_program($grade,$catalog); ifc(is_wp_error($r)&&'certloom_integration_required_fields_unmapped'===$r->get_error_code(),'required Grade blocks automatic mapping because current LMS adapters do not invent credential values');
$custom=array('field_contract'=>array('_meta'=>array('schema'=>'certloom-scoped-fields','schema_version'=>1,'scope'=>'program','configured'=>true),'fields'=>array('employee_id'=>'required'))); $r=$guard->validate_program($custom,$catalog); ifc(is_wp_error($r)&&false!==strpos($r->get_error_message(),'Employee ID'),'required credential-custom field blocks mapping with a clear field label');
$optional=array('field_contract'=>array('_meta'=>array('schema'=>'certloom-scoped-fields','schema_version'=>1,'scope'=>'program','configured'=>true),'fields'=>array('grade'=>'optional','employee_id'=>'optional'))); ifc(true===$guard->validate_program($optional,$catalog),'optional unsourced credential fields do not block automatic mapping');
$batchRequired=array('field_contract'=>array('_meta'=>array('schema'=>'certloom-scoped-fields','schema_version'=>1,'scope'=>'batch','configured'=>true),'fields'=>array('grade'=>'required'))); $r=$guard->validate_context($optional,$batchRequired,$catalog); ifc(is_wp_error($r)&&'certloom_integration_required_fields_unmapped'===$r->get_error_code(),'Batch-required credential fields are included in automatic-source compatibility');
$root=dirname(__DIR__);$tutor=file_get_contents($root.'/src/Infrastructure/Admin/TutorLmsIntegrationAdmin.php');$ld=file_get_contents($root.'/src/Infrastructure/Admin/LearnDashIntegrationAdmin.php');
$contracts=new \Certloom\Application\Fields\ScopedFieldContract();
$globalRequired=$contracts->normalize_scope('global',array('grade'=>'required'),$catalog);
$issuerRequired=$contracts->normalize_scope('issuer',array('employee_id'=>'required'),$catalog);
$r=$guard->validate_context($legacy,null,$catalog,$globalRequired,null);
ifc(is_wp_error($r)&&'certloom_integration_required_fields_unmapped'===$r->get_error_code(),'Global-required results block automation even for a legacy Program');
$r=$guard->validate_context($legacy,null,$catalog,null,$issuerRequired);
ifc(is_wp_error($r)&&false!==strpos($r->get_error_message(),'Employee ID'),'Issuer-required custom information is included in automatic compatibility');
$globalRecipient=$contracts->normalize_scope('global',array('first_name'=>'required'),$catalog);
ifc(true===$guard->validate_context($legacy,null,$catalog,$globalRecipient,null),'Global-required canonical recipient information remains structurally sourceable');
$childExclude=$contracts->normalize_program(array('grade'=>'exclude'),$catalog);
$r=$guard->validate_context(array('field_contract'=>$childExclude),null,$catalog,$globalRequired,null);
ifc(is_wp_error($r)&&'certloom_scoped_field_cannot_exclude_required'===$r->get_error_code(),'automatic mapping cannot exclude an inherited required field');
ifc(false!==strpos($tutor,'TutorCompletionMappingCommand')&&false!==strpos(file_get_contents($root.'/src/Infrastructure/Integration/TutorCompletionMappingCommand.php'),'IntegrationMappingTargetGuard'),'Tutor mapping save uses the shared Program/Batch field and hierarchy compatibility preflight');
ifc(false!==strpos($ld,'IntegrationMappingTargetGuard'),'LearnDash mapping save uses the shared Program/Batch field and hierarchy compatibility preflight');
echo"\n$pass integration field compatibility checks, $fail failures\n";exit($fail?1:0);
