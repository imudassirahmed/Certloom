<?php
/** UX-C01A — current-authority profile exchange + migration promotion contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
class WP_Error { private $code; private $message; public function __construct($c='',$m=''){ $this->code=$c;$this->message=$m; } public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_textarea_field($v){return trim(strip_tags((string)$v));}
function sanitize_email($v){$v=trim((string)$v);return filter_var($v,FILTER_VALIDATE_EMAIL)?$v:'';}
function absint($v){return abs((int)$v);}
function current_time($type){return '2026-08-29 20:00:00';}
function get_option($k,$d=false){return $d;}
function update_option($k,$v,$a=null){return true;}
function delete_option($k){return true;}
function is_admin(){return true;}

require dirname(__DIR__).'/includes/class-ecapla-certverify-fields.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-validator.php';
require dirname(__DIR__).'/includes/class-ecapla-certverify-import.php';
require dirname(__DIR__).'/src/Domain/Credential/States.php';
require dirname(__DIR__).'/src/Application/Migration/LegacyCertificateMapper.php';

$checks=0;$fails=0;
function pex($ok,$label){global $checks,$fails;$checks++;if($ok){echo "PASS  $label\n";}else{$fails++;echo "FAIL  $label\n";}}
$defaults=Ecapla_CertVerify_Fields::get_default_fields();
$map=[];foreach($defaults as $f){$map[$f['key']]=$f;}
foreach(['first_name','middle_name','last_name','email','external_id','grade'] as $key){
    pex(isset($map[$key]),"$key is a stable core exchange field");
    pex(!empty($map[$key]['core'])&&!empty($map[$key]['protected'])&&empty($map[$key]['column'])&&empty($map[$key]['system_only']),"$key persists in versioned custom_fields without becoming arbitrary/deletable schema");
    pex('private'===($map[$key]['public_exposure']??''),"$key is private by default");
}
$good=Ecapla_CertVerify_Validator::custom_field_value('person@example.test',$map['email']);
pex('person@example.test'===$good,'profile email validator accepts a valid address');
$bad=Ecapla_CertVerify_Validator::custom_field_value('not-an-email',$map['email']);
pex(is_wp_error($bad)&&'ecapla_certverify_invalid_email'===$bad->get_error_code(),'profile email validator fails closed on invalid address');
$long=Ecapla_CertVerify_Validator::custom_field_value(str_repeat('G',101),$map['grade']);
pex(is_wp_error($long),'grade enforces normalized-version length bound');

$header=['display_name','email','first_name','middle_name','last_name','external_id','grade','certificate_number'];
$guess=Ecapla_CertVerify_Import::guess_mapping($header,$defaults);
pex($guess===['holder_name','email','first_name','middle_name','last_name','external_id','grade','cert_number'],'common CSV headings auto-map to truthful current-authority targets');

$mapper=new \Certloom\Application\Migration\LegacyCertificateMapper();
$plan=$mapper->map([
    'id'=>17,'cert_number'=>'CERT-17','status'=>'valid','status_source'=>'manual','holder_name'=>'Ada Lovelace','issue_date'=>'2026-01-01','expiry_date'=>'2027-01-01','created_at'=>'2026-01-01 10:00:00','updated_at'=>'2026-01-02 10:00:00',
    'custom_fields'=>['first_name'=>'Ada','middle_name'=>'Byron','last_name'=>'Lovelace','email'=>'ada@example.test','external_id'=>'REC-17','grade'=>'Distinction','department'=>'Research']
]);
pex('Ada'===($plan['recipient']['first_name']??null)&&'Byron'===($plan['recipient']['middle_name']??null)&&'Lovelace'===($plan['recipient']['last_name']??null),'legacy migration promotes structured recipient names');
pex('ada@example.test'===($plan['recipient']['email']??null)&&'REC-17'===($plan['recipient']['external_id']??null),'legacy migration promotes recipient email/external ID');
pex('Distinction'===($plan['version']['grade']??null),'legacy migration promotes Grade to immutable credential Version');
pex('Research'===($plan['version']['snapshot']['custom_fields']['department']??null)&&'Ada'===($plan['version']['snapshot']['custom_fields']['first_name']??null),'migration snapshot retains complete legacy custom-field lineage');

echo "\n$checks UX-C01A checks, $fails failures\n";exit($fails?1:0);
