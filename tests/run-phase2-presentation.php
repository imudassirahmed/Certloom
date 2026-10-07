<?php
/** Behavioral tests for the shared legacy public presenter. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $c; private $m; public function __construct($c,$m){$this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v){return $v;}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function current_time($type,$gmt=false){return $type==='timestamp'?1700000000:'2023-11-14 22:13:20';}
class Ecapla_CertVerify_REST { public static $limited=false; public static function is_rate_limited($scope='verify'){return self::$limited;} }
class Ecapla_CertVerify_Database {
 public static $row=null;
 public static function normalize_certificate_number($n){return strtolower(trim($n));}
 public static function get_by_cert_number($n){return self::$row;}
 public static function effective_status($c){return $c['effective_status'] ?? $c['status'] ?? 'valid';}
}
class Ecapla_CertVerify_Validator { public static function format_date($v){return 'FMT-'.$v;} }
class Ecapla_CertVerify_Fields {
 public static function get_visible_fields(){return array(
  array('key'=>'cert_number','label'=>'Certificate number','type'=>'text','column'=>'cert_number'),
  array('key'=>'status','label'=>'Current status','type'=>'status','column'=>'status'),
  array('key'=>'holder_name','label'=>'Certificate holder','type'=>'text','column'=>'holder_name'),
  array('key'=>'issue_date','label'=>'Issue date','type'=>'date','column'=>'issue_date'),
  array('key'=>'status_reason','label'=>'Status reason','type'=>'textarea','column'=>null),
 );}
 public static function get_statuses(){return array('valid'=>array('label'=>'Valid','desc'=>'Current evidence.'),'suspended'=>array('label'=>'Suspended','desc'=>'Temporarily suspended.'));}
}
class Ecapla_CertVerify_Onboarding { public static function get_state(){return array('organization_name'=>'Example Institute');} }
class Ecapla_CertVerify_Public_Router { public static function verification_url($n){return 'https://example.test/certloom/verify/'.rawurlencode($n).'/';} public static function profile_url($n){return 'https://example.test/certloom/credential/'.rawurlencode($n).'/';} }
function ecapla_certverify_verification_is_available(){return true;}
require dirname(__DIR__).'/includes/class-ecapla-certverify-public-presentation.php';
function okp($c,$m){if(!$c)throw new RuntimeException($m);echo "PASS  $m\n";}
$row=array(
 'cert_number'=>'CERT-1','status'=>'valid','effective_status'=>'suspended','holder_name'=>'Ada Lovelace','issue_date'=>'2026-01-01',
 'custom_fields'=>array('status_reason'=>'Review pending','internal_notes'=>'SECRET')
);
Ecapla_CertVerify_Database::$row=$row;
$p=Ecapla_CertVerify_Public_Presentation::verify('CERT-1',true);
okp(!is_wp_error($p)&&!empty($p['found']),'found result presented');
okp($p['certificate_number']==='CERT-1','certificate number preserved');
okp($p['status']['key']==='suspended'&&$p['status']['label']==='Suspended','effective status presented');
okp($p['status']['description']==='Temporarily suspended.','status description presented');
okp($p['issuer']==='Example Institute','issuer context presented');
okp(strpos($p['verified_at'],'2023-11-14T22:13:20')===0,'verification timestamp deterministic');
$labels=array_column($p['fields'],'label');
okp(in_array('Certificate holder',$labels,true),'visible holder included');
okp(in_array('Status reason',$labels,true),'public status reason included');
okp(!in_array('Internal notes',$labels,true),'internal notes excluded');
$values=array_column($p['fields'],'value','label');
okp($values['Issue date']==='FMT-2026-01-01','public date formatting delegated');
$types=array_column($p['actions'],'type');
okp($types===array('copy','profile','print'),'only allowed non-mutating actions emitted');
okp($p['verification_url']==='https://example.test/certloom/verify/CERT-1/','clean verification URL included');
okp($p['profile_url']==='https://example.test/certloom/credential/CERT-1/','clean profile URL included');
Ecapla_CertVerify_REST::$limited=true;
Ecapla_CertVerify_Database::$row=array('cert_number'=>'CERT-2');
$rate=Ecapla_CertVerify_Public_Presentation::verify('CERT-2',true);
okp(is_wp_error($rate)&&$rate->get_error_code()==='ecapla_certverify_rate_limited','rate limiting fails closed');
$invalid=Ecapla_CertVerify_Public_Presentation::verify(str_repeat('A',101),false);
okp(is_array($invalid)&&empty($invalid['found']),'overlong public lookup rejected before database');
echo "\n15 tests, 0 failures\n";
