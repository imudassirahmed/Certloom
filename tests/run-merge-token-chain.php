<?php
/** AUD-4.74 merge-token chain: defined -> resolved -> offered -> deterministic preview. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $code; private $message; public function __construct($c='',$m=''){ $this->code=$c; $this->message=$m; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} } }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;} function _n($s,$p,$n,$d=null){return 1===$n?$s:$p;} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function absint($v){return abs((int)$v);} function get_bloginfo($k=''){return 'Certloom Test';}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');} function esc_html__($v,$d=null){return $v;}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Issuance\MergeTokenCatalog;
use Certloom\Application\Issuance\MergeSnapshotBuilder;
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Infrastructure\Email\EmailMergeTokenCatalog;
$checks=0;$fail=0; function mt($ok,$label){global $checks,$fail;$checks++;if($ok){echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
$tokens=MergeTokenCatalog::scalar_tokens(); mt(count($tokens)>=25,'canonical catalog defines a broad immutable scalar vocabulary');
$builder=new MergeSnapshotBuilder();
$values=$builder->build(
 array('display_name'=>'Alexandria Noor Rahman','first_name'=>'Alexandria','middle_name'=>'Noor','last_name'=>'Rahman','email'=>'alex@example.invalid','external_id'=>'REC-1','custom_fields'=>array()),
 array('title'=>'Program','code'=>'PRG-1'),
 array('name'=>'Issuer','settings'=>array('title'=>'Credentialing Office')),
 array('display_number'=>'CERT-1','title'=>'Program Credential','public_id'=>'PUBLIC-1','valid_from'=>'2026-09-16','expires_at'=>'2028-09-16','grade'=>'A','score'=>'92','verification_url'=>'https://example.invalid/v/PUBLIC-1'),
 array('elements'=>array()),array(),
 array('display_name'=>'Signer','title'=>'Director','email'=>'signer@example.invalid'),false,
 array('name'=>'Cohort','code'=>'B-1')
);
mt(!is_wp_error($values),'canonical merge snapshot builds');
foreach($tokens as $token=>$label){mt(is_array($values)&&array_key_exists($token,$values),'resolved token: '.$token);}
$email=(new EmailMergeTokenCatalog())->flat(); foreach($tokens as $token=>$label){mt(array_key_exists($token,$email),'Email Studio offers token: '.$token);}
$admin=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Admin/TemplateAdmin.php');
mt(false!==strpos($admin,'MergeTokenCatalog::groups()')&&false!==strpos($admin,'MergeTokenCatalog::scalar_tokens()'),'Certificate Studio picker is generated from the canonical token catalog');
$elements=[];$i=0;foreach($tokens as $token=>$label){$elements[]=array('id'=>'t'.(++$i),'type'=>'merge','token'=>$token);}
$preview=(new TemplatePreviewSamples())->snapshot_for_layout(array('elements'=>$elements)); foreach($tokens as $token=>$label){mt(array_key_exists($token,$preview)&&''!==(string)$preview[$token],'preview resolves deterministic token: '.$token);}
foreach(MergeTokenCatalog::table_sources() as $source){$snap=(new TemplatePreviewSamples())->snapshot_for_layout(array('elements'=>array(array('id'=>'table','type'=>'data_table','source'=>$source)))); mt(isset($snap[$source])&&is_array($snap[$source]),'preview resolves table source: '.$source);}
$scoped=file_get_contents(dirname(__DIR__).'/src/Application/Fields/ScopedFieldContract.php'); mt(false!==strpos($scoped,"'credential.custom.' . \$key"),'custom field authority uses credential.custom.<key> canonically');
echo "\n{$checks} merge-token-chain checks, {$fail} failures\n"; exit($fail?1:0);
