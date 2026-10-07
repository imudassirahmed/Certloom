<?php
/** FLD-02 Template/Program/Batch field compatibility. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function _n($s,$p,$n){return 1===$n?$s:$p;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Templates\TemplateFieldCompatibility;

$pass=0;$fail=0;function tf($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$catalog=array(
 'first_name'=>array('key'=>'first_name','label'=>'First name','type'=>'text','sensitivity'=>'personal','public_exposure'=>'private','system_only'=>false,'locked_visibility'=>false),
 'grade'=>array('key'=>'grade','label'=>'Grade','type'=>'text','sensitivity'=>'personal','public_exposure'=>'verification','system_only'=>false,'locked_visibility'=>false),
 'gpa'=>array('key'=>'gpa','label'=>'GPA','type'=>'number','sensitivity'=>'personal','public_exposure'=>'profile','system_only'=>false,'locked_visibility'=>false),
);
$c=new ScopedFieldContract();$guard=new TemplateFieldCompatibility();
$program=$c->normalize_program(array('first_name'=>'required','gpa'=>'optional'),$catalog);
$batch=$c->normalize_batch(array('gpa'=>'required'),$catalog);
$layout=array('elements'=>array(array('type'=>'merge','token'=>'recipient.first_name'),array('type'=>'merge','token'=>'custom:gpa')));
$r=$guard->validate($layout,$program,$batch,$catalog);tf(true===$r,'Template accepts tokens provided by effective Program/Batch contract');
$bad=array('elements'=>array(array('type'=>'merge','token'=>'credential.grade')));$r=$guard->validate($bad,$program,$batch,$catalog);tf(is_wp_error($r)&&'certloom_template_field_contract_incompatible'===$r->get_error_code(),'Template blocks a catalog field absent from configured Program/Batch contract');
$legacy=ScopedFieldContract::empty_program(false);$r=$guard->validate($bad,$legacy,null,$catalog);tf(true===$r,'Legacy unconfigured Program keeps historical free-token compatibility');
$unmanaged=array('elements'=>array(array('type'=>'merge','token'=>'recipient.name'),array('type'=>'merge','token'=>'credential.display_number')));$r=$guard->validate($unmanaged,$program,null,$catalog);tf(true===$r,'Universal merge vocabulary is not incorrectly treated as FLD-02 scope');
$broad=ScopedFieldContract::empty_program(true);$r=$guard->validate($layout,$broad,null,$catalog);tf(is_wp_error($r)&&'certloom_template_field_contract_incompatible'===$r->get_error_code(),'Configured-empty broad scope rejects Program/Batch credential-field tokens');
$r=$guard->validate($unmanaged,$broad,null,$catalog);tf(true===$r,'Broad-scope universal merge tokens remain valid');
$root=dirname(__DIR__);$repo=file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php');$admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php');
tf(false!==strpos($repo,'validate_assignment_field_contract')&&false!==strpos($repo,'TemplateFieldCompatibility'),'Template repository enforces field compatibility at direct assignment');
tf(false!==strpos($repo,'validate_active_assignment_field_contracts')&&false===strpos($repo,"scope_type IN ('program','batch')"),'Template publication revalidates every active assignment scope, including Global/Issuer');
tf(false!==strpos($repo,'certloom_template_broad_scope_field_incompatible')&&false!==strpos($repo,'ScopedFieldContract::empty_program( true )'),'Global/Issuer assignments reject scoped catalog fields instead of making unverifiable cross-Program promises');
tf(false!==strpos($admin,'ScopedFieldContract::merge_token'),'Designer catalog uses canonical field ownership tokens');
tf(false===strpos($admin,'$token = \'custom:\' . $key'),'Designer catalog no longer forces every field into custom:*');
echo"\n$pass Template field compatibility checks, $fail failures\n";exit($fail?1:0);
