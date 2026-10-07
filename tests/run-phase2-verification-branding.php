<?php
/** Behavioral/static acceptance for VER-03 secondary proof + clean-route branding. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $c; private $m; public function __construct($c,$m){$this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$domain=null){return $v;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function current_time($type,$gmt=false){return $type==='timestamp'?1700000000:'2023-11-14 22:13:20';}
function okv($c,$m){if(!$c)throw new RuntimeException($m);echo "PASS  $m\n";}

require dirname(__DIR__).'/includes/class-ecapla-certverify-secondary-code.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-settings.php';
$valid = '0123-4567-89AB-CDEF';
$normalized = Ecapla_CertVerify_Secondary_Code::normalize($valid);
okv(!is_wp_error($normalized) && $normalized==='0123456789ABCDEF','formatted Crockford code normalizes');
okv(Ecapla_CertVerify_Secondary_Code::format($normalized)==='0123-4567-89AB-CDEF','normalized code formats consistently');
okv(is_wp_error(Ecapla_CertVerify_Secondary_Code::normalize('SHORT')),'short proof rejected');
okv(is_wp_error(Ecapla_CertVerify_Secondary_Code::normalize('IIII-LLLL-OOOO-UUUU')),'ambiguous Crockford characters rejected');
okv(is_wp_error(Ecapla_CertVerify_Secondary_Code::normalize('AAAA-AAAA-AAAA-AAAA')),'weak repetitive proof rejected');
$hash = Ecapla_CertVerify_Secondary_Code::hash_from_input($valid);
okv(is_string($hash) && 0===strpos($hash,'$2y$'),'secondary proof stored with portable bcrypt, not plaintext/fast digest');
okv(false===strpos($hash,$normalized),'stored hash never contains normalized code');
$secured = array('custom_fields'=>array(Ecapla_CertVerify_Secondary_Code::FIELD_KEY=>$hash));
okv(Ecapla_CertVerify_Secondary_Code::has_code($secured),'configured proof detected');
okv(Ecapla_CertVerify_Secondary_Code::matches($secured,'0123456789ABCDEF'),'correct proof matches');
okv(!Ecapla_CertVerify_Secondary_Code::matches($secured,'FEDC-BA98-7654-3210'),'wrong proof fails');
okv(Ecapla_CertVerify_Secondary_Code::matches(array('custom_fields'=>array()),''),'unconfigured credential does not require proof');
okv(Ecapla_CertVerify_Settings::contrast_ratio('#ffffff','#17201d')>=4.5,'default brand surface/text meets normal-text contrast');
okv(Ecapla_CertVerify_Settings::accessible_text_color('#ffffff','#ffffff')==='#111111','unsafe brand text color is corrected to readable contrast');

class Ecapla_CertVerify_Public_Policy {
 const MODE_PROFILE='public';
 public static function allows_context($c,$context){return true;}
 public static function guest_mode($c){return self::MODE_PROFILE;}
 public static function visibility($c){return 'public';}
 public static function actions($c,$ctx,$v,$p){return array();}
}
class Ecapla_CertVerify_REST { public static function is_rate_limited($scope='verify'){return false;} }
class Ecapla_CertVerify_Database {
 public static $row=null;
 public static function normalize_certificate_number($n){return strtolower(trim($n));}
 public static function get_by_cert_number($n){return self::$row;}
 public static function effective_status($c){return 'valid';}
}
class Ecapla_CertVerify_Validator { public static function format_date($v){return $v;} }
class Ecapla_CertVerify_Fields {
 public static function get_visible_fields($context='verification'){return array(array('key'=>'cert_number','label'=>'Certificate number','type'=>'text','column'=>'cert_number'));}
 public static function get_statuses(){return array('valid'=>array('label'=>'Valid','desc'=>'Current evidence.'));}
}
class Ecapla_CertVerify_Onboarding { public static function get_state(){return array('organization_name'=>'Example Institute');} }
class Ecapla_CertVerify_Public_Router { public static function verification_url($n){return 'https://example.test/verify/'.rawurlencode($n); } public static function profile_url($n){return 'https://example.test/credential/'.rawurlencode($n);} }
function ecapla_certverify_verification_is_available(){return true;}
require dirname(__DIR__).'/includes/class-ecapla-certverify-public-presentation.php';
Ecapla_CertVerify_Database::$row=array('cert_number'=>'CERT-SEC','status'=>'valid','custom_fields'=>array(Ecapla_CertVerify_Secondary_Code::FIELD_KEY=>$hash));
$missing=Ecapla_CertVerify_Public_Presentation::verify('CERT-SEC',false,'verification','');
okv(is_array($missing) && empty($missing['found']) && count($missing)===1,'missing required proof returns generic not-found shape');
$wrong=Ecapla_CertVerify_Public_Presentation::verify('CERT-SEC',false,'verification','FEDCBA9876543210');
okv(is_array($wrong) && empty($wrong['found']) && count($wrong)===1,'wrong proof returns same generic not-found shape');
$right=Ecapla_CertVerify_Public_Presentation::verify('CERT-SEC',false,'verification',$valid);
okv(!empty($right['found']) && ($right['secondary_proof']??'')==='verified','correct proof unlocks exact matched credential');
Ecapla_CertVerify_Database::$row=array('cert_number'=>'CERT-OPEN','status'=>'valid','custom_fields'=>array());
$open=Ecapla_CertVerify_Public_Presentation::verify('CERT-OPEN',false,'verification','');
okv(!empty($open['found']) && ($open['secondary_proof']??'')==='not_required','credential without proof remains backward compatible');

$root=dirname(__DIR__);
$src=array(
 'fields'=>file_get_contents($root.'/includes/class-ecapla-certverify-fields.php'),
 'admin'=>file_get_contents($root.'/admin/class-ecapla-certverify-admin.php'),
 'editor'=>file_get_contents($root.'/admin/views/edit-certificate.php'),
 'import'=>file_get_contents($root.'/includes/class-ecapla-certverify-import.php'),
 'rest'=>file_get_contents($root.'/includes/class-ecapla-certverify-rest.php'),
 'render'=>file_get_contents($root.'/includes/class-ecapla-certverify-render.php'),
 'router'=>file_get_contents($root.'/includes/class-ecapla-certverify-public-router.php'),
 'js'=>file_get_contents($root.'/public/assets/certloom-public.js'),
 'settings'=>file_get_contents($root.'/includes/class-ecapla-certverify-settings.php'),
 'settings_view'=>file_get_contents($root.'/admin/views/settings-manager.php'),
 'route'=>file_get_contents($root.'/public/views/credential-route.php'),
 'css'=>file_get_contents($root.'/public/assets/certloom-public.css'),
 'onboarding'=>file_get_contents($root.'/includes/class-ecapla-certverify-onboarding.php'),
 'privacy'=>file_get_contents($root.'/includes/class-ecapla-certverify-privacy.php'),
);
foreach($src as $name=>$text){okv(is_string($text)&&$text!=='','source loaded: '.$name);}
okv(false!==strpos($src['fields'],"__certloom_verification_code_hash") && false!==strpos($src['fields'],"'system_only'"),'secondary proof is reserved system-only schema state');
okv(false!==strpos($src['fields'],'get_manageable_fields') && false!==strpos($src['fields'],'get_exchange_fields'),'managed/exchange schemas explicitly exclude system-only fields');
okv(false!==strpos($src['admin'],"! empty( \$field['system_only'] )"),'generic certificate admin save skips system-only fields');
okv(false!==strpos($src['import'],'get_exchange_fields()'),'CSV import/export uses exchange-safe schema');
okv(false!==strpos($src['editor'],'Generate secure code') && false!==strpos($src['editor'],'certloom_secondary_code_clear'),'editor supports generate/rotate/clear without displaying stored hash');
okv(false===strpos($src['editor'],'__certloom_verification_code_hash" value='),'stored proof hash is never placed in editor value attributes');
okv(false!==strpos($src['rest'],'WP_REST_Server::CREATABLE') && false!==strpos($src['rest'],"'code'"),'REST verifier accepts POST proof while preserving readable route');
okv(false!==strpos($src['rest'],'secondary_code_from_request') && false!==strpos($src['rest'],'get_json_params') && false!==strpos($src['rest'],'get_body_params') && false===strpos($src['rest'],"get_param( 'code' )"),'REST proof is read from POST body only, never query parameters');
okv(false!==strpos($src['js'],"method: 'POST'") && false!==strpos($src['js'],"body: JSON.stringify( { number: number, code: code, source: source } )") && false!==strpos($src['js'],"get( 'certloom_source' ) === 'qr'"),'Certloom browser verifier keeps proof in POST body while adding only bounded analytics source attribution');
$directCodePos=strpos($src['render'],'private static function direct_code');
$directCode=$directCodePos===false?'':substr($src['render'],$directCodePos,700);
okv(false!==strpos($directCode,'$_POST') && false===strpos($directCode,'$_GET'),'no-JS secondary proof reads POST only, never URL query');
okv(false===strpos($src['router'],'certloom_code'),'clean/copyable route never carries secondary proof');
okv(false!==strpos($src['settings'],"'verification_branding_enabled'") && false!==strpos($src['settings'],"'verification_surface_color'") && false!==strpos($src['settings'],"'verification_use_site_icon'"),'advanced clean-route branding is stored in canonical settings');
okv(false!==strpos($src['settings_view'],'This does not override shortcode or Elementor design settings.'),'branding UI documents component isolation');
okv(false!==strpos($src['route'],'certloom-route-brand') && false!==strpos($src['route'],'get_site_icon_url'),'clean routes render shared brand strip using WordPress-managed site icon');
okv(false!==strpos($src['route'],'is-certloom-branded') && false!==strpos($src['css'],'.certloom-route-main.is-certloom-branded'),'configured brand surface applies to the whole Certloom-owned clean route');
okv(false!==strpos($src['route'],"Ecapla_CertVerify_Onboarding::get_state()") && false!==strpos($src['route'],"get_bloginfo( 'name' )"),'brand name has deterministic onboarding/site fallback');
$brandCssPos=strpos($src['css'],'.certloom-route-brand{');
$brandCss=$brandCssPos===false?'':substr($src['css'],$brandCssPos,1800);
okv(false!==$brandCssPos && false===strpos($brandCss,'color-mix('),'brand CSS is route-scoped and avoids unnecessary modern-only color mixing');
okv(false!==strpos($src['onboarding'],'get_manageable_fields()'),'setup field counts exclude invisible system proof field');
okv(false!==strpos($src['privacy'],'! empty( $fields[ $key ][\'system_only\'] )'),'personal-data export excludes system-only verification proof material');
okv(false!==strpos($src['admin'],"isset( \$retired[ \$key ]['sensitivity'] ) ? \$retired[ \$key ]['sensitivity'] : 'personal'"),'retired-field restore preserves sensitivity classification');
okv(false!==strpos(file_get_contents($root.'/includes/class-ecapla-certverify-secondary-code.php'),'equalize_public_miss'),'secondary proof includes a fixed-work public miss equalizer');
okv(false!==strpos(file_get_contents($root.'/includes/class-ecapla-certverify-public-presentation.php'),'Ecapla_CertVerify_Secondary_Code::equalize_public_miss'),'public presentation burns equivalent bcrypt work for valid-shaped proof misses');

echo "\n" . (39 + count($src)) . " tests, 0 failures\n";
