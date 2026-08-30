<?php
/** Phase 2 privacy/visibility/action-policy behavior and architecture acceptance. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $c; private $m; public function __construct($c,$m){$this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v){return $v;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function current_time($type,$gmt=false){return $type==='timestamp'?1700000000:'2023-11-14 22:13:20';}
class Ecapla_CertVerify_REST { public static function is_rate_limited($scope='verify'){return false;} }
class Ecapla_CertVerify_Secondary_Code {
 public static function has_code($certificate){return !empty($certificate['custom_fields']['__certloom_verification_code_hash']);}
 public static function matches($certificate,$code){return self::has_code($certificate) && $code==='GOOD-CODE';}
 public static function equalize_public_miss($code){return null;}
}
class Ecapla_CertVerify_Database {
 public static $row=null;
 public static function normalize_certificate_number($n){return strtolower(trim($n));}
 public static function get_by_cert_number($n){return self::$row;}
 public static function effective_status($c){return $c['status'] ?? 'valid';}
}
class Ecapla_CertVerify_Validator { public static function format_date($v){return $v;} }
class Ecapla_CertVerify_Fields {
 public static function get_visible_fields($context='verification'){
  $common=array(array('key'=>'cert_number','label'=>'Certificate number','type'=>'text','column'=>'cert_number'));
  if($context==='profile'){$common[]=array('key'=>'profile_only','label'=>'Profile only','type'=>'text','column'=>null);}
  else{$common[]=array('key'=>'verify_only','label'=>'Verify only','type'=>'text','column'=>null);}
  $common[]=array('key'=>'both','label'=>'Both','type'=>'text','column'=>null);
  return $common;
 }
 public static function get_statuses(){return array('valid'=>array('label'=>'Valid','desc'=>'Current.'));}
}
class Ecapla_CertVerify_Onboarding { public static function get_state(){return array('organization_name'=>'Example Institute');} }
class Ecapla_CertVerify_Public_Router { public static function verification_url($n){return 'https://example.test/certloom/verify/'.$n.'/';} public static function profile_url($n){return 'https://example.test/certloom/credential/'.$n.'/';} }
function ecapla_certverify_verification_is_available(){return true;}
require dirname(__DIR__).'/src/Application/Sharing/ShareLinkBuilder.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-public-policy.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-public-presentation.php';
function vcheck($c,$m){if(!$c)throw new RuntimeException($m);echo "PASS  $m\n";}
$checks=0;
function vc($c,$m){global $checks;$checks++;vcheck($c,$m);}
$base=array('cert_number'=>'CERT-168','status'=>'valid','custom_fields'=>array('verify_only'=>'V','profile_only'=>'P','both'=>'B'));

Ecapla_CertVerify_Database::$row=$base;
$v=Ecapla_CertVerify_Public_Presentation::verify('CERT-168',false,'verification');
vc(!empty($v['found'])&&$v['visibility']==='public','missing visibility defaults to public for backward compatibility');
vc(array_column($v['fields'],'label')===array('Certificate number','Verify only','Both'),'verification context exposes only verification/both fields');
vc(array_column($v['actions'],'type')===array('copy','profile','share','print'),'public verification exposes only current non-mutating actions');
$p=Ecapla_CertVerify_Public_Presentation::verify('CERT-168',false,'profile');
vc(!empty($p['found']),'public credential profile allowed');
vc(array_column($p['fields'],'label')===array('Certificate number','Profile only','Both'),'profile context exposes only profile/both fields');
vc(array_column($p['actions'],'type')===array('copy','share','print'),'profile does not offer a self-referential profile action');

$protected=$base;$protected['custom_fields']['__certloom_verification_code_hash']='$2y$10$012345678901234567890uA012345678901234567890123456789012';Ecapla_CertVerify_Database::$row=$protected;
$v=Ecapla_CertVerify_Public_Presentation::verify('CERT-PROTECTED',false,'verification','GOOD-CODE');
vc(!empty($v['found'])&&$v['profile_url']==='','secondary-proof credential never advertises a proof-less standalone profile URL');
vc(array_column($v['actions'],'type')===array('copy','share','print'),'secondary-proof credential keeps safe copy/print actions but suppresses profile action');
vc(empty(Ecapla_CertVerify_Public_Presentation::verify('CERT-PROTECTED',false,'profile')['found']),'proof-less standalone profile request remains non-enumerating for a protected credential');

$summary=$base;$summary['custom_fields']['__certloom_profile_visibility']='verification_summary';Ecapla_CertVerify_Database::$row=$summary;
$v=Ecapla_CertVerify_Public_Presentation::verify('CERT-SUMMARY',false,'verification');
vc(!empty($v['found'])&&$v['profile_url']==='','verification-only credential suppresses profile URL server-side');
vc(array_column($v['actions'],'type')===array('copy','share','print'),'verification-only credential suppresses profile action');
$p=Ecapla_CertVerify_Public_Presentation::verify('CERT-SUMMARY-PROFILE',false,'profile');
vc(empty($p['found']),'verification-only credential hides richer profile existence');

$private=$base;$private['custom_fields']['__certloom_profile_visibility']='private';Ecapla_CertVerify_Database::$row=$private;
vc(empty(Ecapla_CertVerify_Public_Presentation::verify('CERT-PRIVATE-V',false,'verification')['found']),'private credential hides guest verification existence');
vc(empty(Ecapla_CertVerify_Public_Presentation::verify('CERT-PRIVATE-P',false,'profile')['found']),'private credential hides guest profile existence');
vc(!Ecapla_CertVerify_Public_Policy::allows_action('pdf',$private,'verification'),'future PDF action fails closed');
vc(Ecapla_CertVerify_Public_Policy::allows_action('share',$base,'verification'),'public share action is now implemented for guest-visible credentials');
vc(!Ecapla_CertVerify_Public_Policy::allows_action('share',$private,'verification'),'private share action fails closed');
vc(!Ecapla_CertVerify_Public_Policy::allows_action('claim',$base,'verification'),'future claim action fails closed');
vc(!Ecapla_CertVerify_Public_Policy::allows_action('unknown',$base,'verification'),'unknown action fails closed');
$malformed=$base;$malformed['custom_fields']['__certloom_profile_visibility']='definitely-not-valid';Ecapla_CertVerify_Database::$row=$malformed;
vc(empty(Ecapla_CertVerify_Public_Presentation::verify('CERT-MALFORMED',false,'verification')['found']),'malformed stored visibility fails closed instead of becoming public');

$root=dirname(__DIR__);
$fields=file_get_contents($root.'/includes/class-ecapla-certverify-fields.php');
$admin=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$field_ui=file_get_contents($root.'/admin/views/fields-manager.php');
$editor=file_get_contents($root.'/admin/views/edit-certificate.php');
$partial=file_get_contents($root.'/admin/views/partials/certificate-field.php');
$router=file_get_contents($root.'/includes/class-ecapla-certverify-public-router.php');
$rest=file_get_contents($root.'/includes/class-ecapla-certverify-rest.php');
vc(false!==strpos($fields,"'public_safe'")&&false!==strpos($fields,"'personal'")&&false!==strpos($fields,"'sensitive'")&&false!==strpos($fields,"'internal'"),'four sensitivity classes are canonical');
vc(false!==strpos($fields,"'verification'")&&false!==strpos($fields,"'profile'")&&false!==strpos($fields,"'both'"),'field exposure contexts are canonical');
vc(false!==strpos($fields,"'__certloom_profile_visibility'")&&false!==strpos($fields,"'visibility'"),'credential-level visibility is a protected core policy field');
vc(false!==strpos($fields, "0 === strpos( \$key, '__certloom_' )"), 'reserved Certloom field namespace rejects unrecognized custom policy keys');
vc(false!==strpos($fields, "is_required_core_field( \$key )")&&false!==strpos($admin, "is_required_core_field( \$key )"), 'required verification identity fields keep public-safe sensitivity server-side');
vc(false!==strpos($fields, "\$legacy_public ? 'both' : 'private'"),'legacy show_on_frontend settings migrate compatibly to both/private');
vc(false!==strpos($fields, "'internal' === \$clean['sensitivity']")&&false!==strpos($fields, "\$exposure = 'private'"),'internal sensitivity forces private exposure during normalization');
vc(false!==strpos($admin, "! empty( \$field['locked_visibility'] ) ? false"),'legacy locked-private enforcement remains explicit in save handler');
vc(false!==strpos($admin,"public_exposure")&&false!==strpos($admin,"sensitivity"),'field-save controller allow-lists sensitivity/exposure');
vc(false!==strpos($field_ui,'Sensitivity')&&false!==strpos($field_ui,'Public exposure'),'Field Manager exposes explicit policy controls');
vc(false!==strpos($editor,'$ecapla_certverify_visibility_field')&&false!==strpos($editor,'Credential Profile output'),'credential visibility is presented in Verification workspace');
vc(false!==strpos($partial, '$ecapla_certverify_name'), 'visibility control uses existing cf_{field} save contract');
vc(false===strpos($router,'sprintf( __( \'Credential %s\'')&&false!==strpos($router,"__( 'Credential profile', 'certloom' )"),'route title cannot leak identifier before policy');
vc(false!==strpos($rest, "'visibility'         => \$presentation['visibility']"),'REST additive presentation reports visibility mode');
vc(false!==strpos($fields, "'sensitivity'=> \$sensitivity")&&false!==strpos($admin, "\$retired[ \$key ]['sensitivity']"), 'retired fields preserve sensitivity but restore privately');
vc(false!==strpos(file_get_contents($root.'/includes/class-ecapla-certverify-import.php'), "'__certloom_profile_visibility' === \$field['key']")&&false!==strpos(file_get_contents($root.'/includes/class-ecapla-certverify-import.php'), "\$sample[] = 'public'"), 'CSV template gives visibility policy a valid backward-compatible sample');
vc(false!==strpos(file_get_contents($root.'/certloom.php'),"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false"),'normalized-domain authority remains disabled');

echo "\n{$checks} checks, 0 failures\n";
