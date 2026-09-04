<?php
/** Email Design library, package and designer separation contract tests. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['certloom_test_options'] = array();
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;} function esc_html__($t){return $t;}
function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));} function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function sanitize_hex_color($v){return preg_match('/^#[0-9a-fA-F]{6}$/',(string)$v)?strtolower((string)$v):null;}
function esc_url_raw($v){$v=trim((string)$v);return preg_match('#^(https?://|\{\{verification_url\}\})#',$v)?$v:'';} function esc_url($v){return esc_url_raw($v);}
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function wp_kses_post($v){return (string)$v;} function wp_strip_all_tags($v,$remove_breaks=false){return strip_tags((string)$v);} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_bloginfo($key){return 'UTF-8';} function current_time($type,$gmt=false){return '2026-09-04 01:02:03';}
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['certloom_test_options'])?$GLOBALS['certloom_test_options'][$key]:$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['certloom_test_options'][$key]=$value;return true;}
function wp_generate_password($length=12,$special=true,$extra=false){return substr(str_repeat('abcdef',$length),0,$length);}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Email\EmailDesignCompiler;
use Certloom\Infrastructure\Email\EmailDesignPackage;
use Certloom\Infrastructure\Email\EmailDesignPresetCatalog;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;

$checks=0;
function ed_ok($ok,$message){global $checks;if(!$ok){throw new RuntimeException($message);}$checks++;}
function ed_same($a,$b,$message){ed_ok($a===$b,$message.' expected '.var_export($a,true).' got '.var_export($b,true));}
function ed_has($source,$needle,$message){ed_ok(false!==strpos($source,$needle),$message);}
function ed_lacks($source,$needle,$message){ed_ok(false===strpos($source,$needle),$message);}

$compiler=new EmailDesignCompiler();
$presets=(new EmailDesignPresetCatalog())->all();
ed_same(count(EmailDesignPurposes::all()),count($presets),'one calm starter exists for every supported message purpose');
$ids=[];$purposes=[];
foreach($presets as $preset){
	ed_ok('factory'===($preset['source']??''),'starter is product-owned factory content');
	ed_ok(!empty($preset['active']),'starter ships Published');
	ed_ok(EmailDesignPurposes::is_valid($preset['purpose']??''),'starter purpose is canonical');
	ed_ok(!isset($ids[$preset['id']]),'starter ID is unique');$ids[$preset['id']]=1;$purposes[$preset['purpose']]=1;
	$compiled=$compiler->compile($preset['blocks']??array(),$preset['theme']??array());
	ed_ok(''!==trim($compiled['html_body']),'starter compiles to HTML');
	ed_ok(''!==trim($compiled['text_body']),'starter compiles to plain text');
}
ed_same(EmailDesignPurposes::all(),array_values(array_filter(EmailDesignPurposes::all(),fn($p)=>isset($purposes[$p]))),'every purpose is represented');

$sample=$presets[0];$sample['id']='private-id';$sample['source']='customer';$sample['active']=1;$sample['assignment']=array('scope_type'=>'program','scope_id'=>9);
$package=(new EmailDesignPackage($compiler))->build($sample,'1.6.99-qa.23');
ed_same(EmailDesignPackage::FORMAT,$package['format'],'package format identity');
ed_same(EmailDesignPackage::VERSION,$package['format_version'],'package format version');
ed_ok(isset($package['sha256'])&&64===strlen($package['sha256']),'package has SHA-256 integrity proof');
ed_lacks(json_encode($package),'private-id','stored design identity is not exported');
ed_lacks(json_encode($package),'assignment','Design Rules are not exported with email design');
ed_lacks(json_encode($package),'scope_id','assignment scope is absent from package');
$decoded=(new EmailDesignPackage($compiler))->decode(json_encode($package));
ed_ok(is_array($decoded),'valid package round-trips');
ed_same(0,$decoded['active'],'import is deliberately Unpublished');
ed_same('import',$decoded['source'],'import source is explicit');
ed_same($sample['purpose'],$decoded['purpose'],'purpose survives package round-trip');
ed_same($sample['subject'],$decoded['subject'],'subject survives package round-trip');

$tampered=$package;$tampered['design']['subject']='Tampered';$bad=(new EmailDesignPackage($compiler))->decode(json_encode($tampered));
ed_ok(is_wp_error($bad)&&'certloom_email_design_package_integrity'===$bad->get_error_code(),'tampered package fails integrity check');
$future=$package;$future['format_version']=99;$bad=(new EmailDesignPackage($compiler))->decode(json_encode($future));
ed_ok(is_wp_error($bad)&&'certloom_email_design_package_version'===$bad->get_error_code(),'unsupported package version fails closed');

// Factory library repair/upgrade preserves site-owned Published state.
$catalog=new EmailTemplateCatalogRepository();
$result=$catalog->ensure_factory_presets();
ed_same(true,$result,'factory library seeds automatically');
$all=$catalog->all();
$factory=array_values(array_filter($all,fn($d)=>'factory'===($d['source']??'')));
ed_same(count($presets),count($factory),'all built-in email designs are present after seeding');
$first_id=$factory[0]['id'];
foreach($GLOBALS['certloom_test_options'][EmailTemplateCatalogRepository::OPTION]['templates'] as &$row){if(($row['id']??'')===$first_id){$row['active']=0;$row['subject']='Old but valid subject';$row['factory_version']=0;}}
unset($row);
$result=$catalog->ensure_factory_presets();ed_same(true,$result,'factory library repairs an older product-owned record');
$repaired=$catalog->get($first_id);$canonical=array_values(array_filter($presets,fn($d)=>$d['id']===$first_id))[0];
ed_same(0,$repaired['active'],'factory repair preserves administrator Unpublished choice');
ed_same($canonical['subject'],$repaired['subject'],'factory repair restores canonical product content');
ed_same(EmailDesignPresetCatalog::VERSION,$repaired['factory_version'],'factory repair advances product factory version');

// Reserved built-in identity must never overwrite a custom record.
$GLOBALS['certloom_test_options']=array();
$custom=$canonical;$custom['source']='customer';$custom['active']=1;
$repo=new EmailTemplateCatalogRepository();$repo->save($custom);
$conflict=$repo->ensure_factory_presets();
ed_ok(is_wp_error($conflict)&&'certloom_email_factory_identity_conflict'===$conflict->get_error_code(),'custom record using reserved factory identity is never overwritten');

$root=dirname(__DIR__);
$view=file_get_contents($root.'/admin/views/email-designs.php');
$designer=file_get_contents($root.'/admin/views/email-designer.php');
$admin=file_get_contents($root.'/src/Infrastructure/Admin/EmailDesignAdmin.php');
$repo_source=file_get_contents($root.'/src/Infrastructure/Email/EmailTemplateCatalogRepository.php');
$package_source=file_get_contents($root.'/src/Infrastructure/Email/EmailDesignPackage.php');
$js=file_get_contents($root.'/admin/assets/email-designer.js');
$css=file_get_contents($root.'/admin/assets/email-designs.css');
foreach(compact('view','designer','admin','repo_source','package_source','js','css') as $name=>$source){ed_ok(is_string($source)&&''!==$source,'source '.$name.' available');}

ed_has($view,"'Library'",'Email Designs has Library subtab');
ed_has($view,"'My Templates'",'Email Designs has My Templates subtab');
ed_has($view,"'New Template'",'New Template is a primary page action');
ed_has($view,"'Import'",'Import is a primary page action');
ed_has($view,"'Publish'",'library exposes Publish lifecycle');
ed_has($view,"'Unpublish'",'library exposes Unpublish lifecycle');
ed_has($view,"'Export'",'library exposes Export');
ed_has($view,"'View usage'",'library links usage to Design Rules');
ed_has($view,'DesignRulesAdmin::PAGE','usage routes to centralized Design Rules');
ed_lacks($view,'scope_type','Email library has no Issuer/Program/Batch assignment selector');
ed_lacks($view,'issuer_id','Email library does not manage Issuer assignment');
ed_lacks($view,'program_id','Email library does not manage Program assignment');
ed_lacks($view,'batch_id','Email library does not manage Batch assignment');

ed_has($designer,'certloom-designer-palette','designer has Blocks palette');
ed_has($designer,'certloom-designer-stage','designer has visual Canvas');
ed_has($designer,'certloom-designer-inspector','designer has contextual Inspector');
ed_has($designer,'data-certloom-device="desktop"','designer exposes desktop preview mode');
ed_has($designer,'data-certloom-device="mobile"','designer exposes mobile preview mode');
ed_has($designer,'data-certloom-token-list','designer exposes dynamic-data insertion');
ed_has($designer,'data-certloom-theme','designer exposes visual theme controls');
ed_has($designer,"'Plain-text fallback'",'designer retains explicit plain-text fallback');
ed_has($designer,'data-certloom-designer-feedback','designer exposes inline focusable validation feedback');
ed_lacks($designer,'scope_type','designer contains no assignment controls');
ed_has($js,'beforeunload','designer protects unsaved work');
ed_has($js,'wp.media','designer uses WordPress Media Library');
ed_has($js,"draggable=\"true\"",'designer supports pointer drag-and-drop reordering without removing keyboard controls');
ed_has($js,'showFeedback(','empty-content validation is inline instead of a browser alert');
ed_lacks($js,'window.alert(','designer does not use blocking browser alerts for validation');
ed_has($css,'@media (max-width:1100px)','designer has responsive layout');
ed_has($css,'prefers-reduced-motion','designer respects reduced motion');
ed_has($css,'forced-colors','designer supports forced-colors mode');

ed_has($repo_source,'certloom_email_design_assigned_pause_blocked','assigned designs cannot be silently unpublished');
ed_has($repo_source,'certloom_email_design_assigned_purpose_blocked','assigned designs cannot silently change purpose');
ed_has($repo_source,'certloom_email_design_in_use','assigned designs cannot be deleted');
ed_has($package_source,"'active'",'package import contract carries explicit lifecycle state');
ed_has($repo_source,'install_email_assets','email package import installs embedded local media through the shared portable-media boundary');
ed_has($repo_source,'cleanup( $installed[\'created_ids\'] )','failed email design persistence cleans media created earlier in the import');
ed_lacks($package_source,'EmailDesignAssignmentRepository','package layer has no assignment authority dependency');
ed_has($admin,'EmailDesignPackage::MAX_BYTES','email import UI/handler derives its upload ceiling from the portable package contract');
ed_has($admin,'wp_max_upload_size','email import respects the site upload ceiling when it is lower than the Certloom package ceiling');
ed_has($admin,'->encode( $result )','email export uses the bounded package encoder instead of emitting unchecked JSON');

echo "PASS  Email Designs separates Library/My Templates/Designer from centralized Design Rules\n";
echo "PASS  email package round-trip is integrity-checked and assignment-free, and built-in designs self-repair without overriding Published state\n\n";
echo $checks." checks, 0 failures\n";
