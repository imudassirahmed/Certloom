<?php
/** FLD-02 Program + Batch scoped field contract. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Fields\ScopedFieldContract;

$pass=0;$fail=0; function sf($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$catalog=array(
 'first_name'=>array('key'=>'first_name','label'=>'First name','type'=>'text','sensitivity'=>'personal','public_exposure'=>'private','system_only'=>false,'locked_visibility'=>false),
 'grade'=>array('key'=>'grade','label'=>'Grade','type'=>'text','sensitivity'=>'personal','public_exposure'=>'verification','system_only'=>false,'locked_visibility'=>false),
 'project_title'=>array('key'=>'project_title','label'=>'Project title','type'=>'text','sensitivity'=>'public','public_exposure'=>'both','system_only'=>false,'locked_visibility'=>false),
 'internal_notes'=>array('key'=>'internal_notes','label'=>'Internal notes','type'=>'textarea','sensitivity'=>'internal','public_exposure'=>'private','system_only'=>false,'locked_visibility'=>true),
);
$c=new ScopedFieldContract();
$p=$c->normalize_program(array('grade'=>'required','first_name'=>'optional'),$catalog);sf(!is_wp_error($p),'Program field contract normalizes');
sf(array('first_name'=>'optional','grade'=>'required')===($p['fields']??array()),'Program contract has deterministic key order');
$b=$c->normalize_batch(array('first_name'=>'required','project_title'=>'optional'),$catalog);sf(!is_wp_error($b),'Batch field override normalizes');
$r=$c->resolve($p,$b,$catalog);sf(!is_wp_error($r),'Program + Batch resolve');
$by=array();foreach(($r['fields']??array()) as $row){$by[$row['key']]=$row;}
sf(true===($by['grade']['required']??false)&&'program'===($by['grade']['origin']??''),'Program-required field survives Batch');
sf(true===($by['first_name']['required']??false)&&'batch'===($by['first_name']['origin']??''),'Batch may tighten Program optional field');
sf('optional'===($by['project_title']['requirement']??'')&&'batch'===($by['project_title']['origin']??''),'Batch may add an optional field');
sf('recipient'===($by['first_name']['value_source']??''),'recipient identity field declares Recipient value source');
sf('credential'===($by['grade']['value_source']??''),'Grade declares issued-credential value source');
sf('credential_custom'===($by['project_title']['value_source']??''),'custom field declares issued-credential custom value source');
$bad=$c->resolve($p,array('_meta'=>array(),'fields'=>array('grade'=>'exclude')),$catalog);sf(is_wp_error($bad)&&'certloom_batch_field_cannot_exclude_required'===$bad->get_error_code(),'Batch cannot exclude Program-required field');
$downgrade=$c->resolve($p,array('_meta'=>array(),'fields'=>array('grade'=>'optional')),$catalog);$d=array();if(!is_wp_error($downgrade)){foreach($downgrade['fields'] as $row){$d[$row['key']]=$row;}}sf(true===($d['grade']['required']??false),'Batch cannot downgrade Program-required field');
$exclude=$c->resolve($p,array('_meta'=>array(),'fields'=>array('first_name'=>'exclude')),$catalog);$keys=array();if(!is_wp_error($exclude)){$keys=array_column($exclude['fields'],'key');}sf(!in_array('first_name',$keys,true),'Batch may exclude Program-optional field');
sf(is_wp_error($c->normalize_program(array('internal_notes'=>'required'),$catalog)),'locked/internal field cannot be assigned to Program/Batch contract');
sf(is_wp_error($c->normalize_batch(array('missing_field'=>'optional'),$catalog)),'unknown field fails closed');
$meta=array('unrelated'=>'keep');$with=$c->with_program_metadata($meta,array('grade'=>'required'),$catalog);sf(!is_wp_error($with)&&'keep'===($with['unrelated']??''),'Program field envelope preserves unrelated metadata');
$round=$c->from_program_metadata($with,$catalog);sf(!is_wp_error($round)&&array('grade'=>'required')===($round['fields']??array()),'Program field envelope round-trips');
sf('recipient'===ScopedFieldContract::value_source('email')&&'credential'===ScopedFieldContract::value_source('grade'),'canonical value-source rules are explicit');
sf('recipient.first_name'===ScopedFieldContract::merge_token('first_name')&&'credential.grade'===ScopedFieldContract::merge_token('grade')&&'custom:project_title'===ScopedFieldContract::merge_token('project_title'),'canonical merge tokens preserve field ownership');
sf('first_name'===ScopedFieldContract::field_key_from_merge_token('recipient.first_name')&&'grade'===ScopedFieldContract::field_key_from_merge_token('credential.grade')&&'project_title'===ScopedFieldContract::field_key_from_merge_token('custom:project_title'),'merge tokens round-trip to scoped field keys');
$corrupt=$with;$corrupt[ScopedFieldContract::STORAGE_KEY]['_meta']['scope']='batch';$corruptRead=$c->from_program_metadata($corrupt,$catalog);sf(is_wp_error($corruptRead)&&'certloom_scoped_field_storage_schema_invalid'===$corruptRead->get_error_code(),'typed Program storage rejects a mismatched envelope scope');
$corrupt=$with;unset($corrupt[ScopedFieldContract::STORAGE_KEY]['_meta']);$corruptRead=$c->from_program_metadata($corrupt,$catalog);sf(is_wp_error($corruptRead)&&'certloom_scoped_field_storage_invalid'===$corruptRead->get_error_code(),'typed Program storage rejects a missing envelope marker');
sf(ScopedFieldContract::is_scopable_definition($catalog['project_title']),'ordinary catalog field is scopable');
sf(!ScopedFieldContract::is_scopable_definition($catalog['internal_notes']),'locked field is not scopable');
$root=dirname(__DIR__);$program=file_get_contents($root.'/src/Infrastructure/Admin/DomainAdmin.php');$batch=file_get_contents($root.'/src/Infrastructure/Admin/DomainStructureAdmin.php');$prepo=file_get_contents($root.'/src/Infrastructure/Repository/ProgramRepository.php');$brepo=file_get_contents($root.'/src/Infrastructure/Repository/BatchRepository.php');
sf(false!==strpos($program,'Credential fields')&&false!==strpos($program,'program_field_requirement'),'Program editor exposes field requirements');
sf(false!==strpos($batch,'Credential field overrides')&&false!==strpos($batch,'batch_field_requirement'),'Batch editor exposes inheritance overrides');
sf(false!==strpos($batch,'certloom_batch_field_cannot_exclude_required')||false!==strpos($batch,'->resolve('),'Batch save path revalidates Program/Batch resolution');
sf(false!==strpos($prepo,'ScopedFieldContract::STORAGE_KEY')&&false!==strpos($prepo,"['field_contract']"),'Program repository separates typed field contract from generic metadata');
sf(false!==strpos($brepo,'ScopedFieldContract::STORAGE_KEY')&&false!==strpos($brepo,"['field_contract']"),'Batch repository separates typed field contract from generic metadata');

echo "\n$pass scoped field contract checks, $fail failures\n";exit($fail?1:0);
