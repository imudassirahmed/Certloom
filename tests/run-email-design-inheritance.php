<?php
/** EML-02 functional Email Design hierarchy / fail-closed authority gate. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/bootstrap-option-command-double.php';
define( 'ABSPATH', __DIR__ . '/webroot/' );
define( 'CERTLOOM_PATH', dirname( __DIR__ ) . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;} function esc_html__($v,$d=null){return $v;} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_url($v){return (string)$v;} function esc_url_raw($v){return trim((string)$v);} function sanitize_hex_color($v){return preg_match('/^#[0-9a-fA-F]{6}$/',(string)$v)?strtolower((string)$v):null;} function sanitize_text_field($v){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags((string)$v)));} function sanitize_textarea_field($v){return trim(strip_tags((string)$v));} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function absint($v){return abs((int)$v);} function wp_kses_post($v){return preg_replace('#<script\b[^>]*>.*?</script>#is','',(string)$v);} function wp_strip_all_tags($v,$remove_breaks=false){return strip_tags((string)$v);} function get_bloginfo($k){return 'charset'===$k?'UTF-8':'Certloom Test';} function current_time($f,$gmt=false){return '2026-09-03 01:00:00';} function wp_generate_password($l=12,$special=true,$extra=false){return str_repeat('a',$l);} function wp_json_encode($v,$f=0){return json_encode($v,$f);} function update_option($k,$v,$autoload=false){$GLOBALS['eml2_options'][$k]=$v;return true;} function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['eml2_options']??array())?$GLOBALS['eml2_options'][$k]:$d;}
require CERTLOOM_PATH.'src/Application/Rendering/CanonicalJson.php';
require CERTLOOM_PATH.'src/Application/Email/EmailTemplateService.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailDesignPurposes.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailDesignCompiler.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailAutomationRuleRepository.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailTemplateSettingsRepository.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailTemplateCatalogRepository.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailDesignAssignmentRepository.php';
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Email\EmailDesignAssignmentRepository;
$GLOBALS['eml2_options']=array();
function eml2_design($id,$name,$purpose,$active=1){return array('id'=>$id,'name'=>$name,'purpose'=>$purpose,'active'=>$active,'subject'=>'Credential {{program_title}}','preheader'=>'Ready','blocks'=>array(array('type'=>'text','html'=>'<p>Hello {{recipient_name}}</p>')),'theme'=>array(),'text_body'=>'Hello {{recipient_name}}','updated_at'=>'2026-09-03 00:00:00');}
$GLOBALS['eml2_options'][EmailTemplateCatalogRepository::OPTION]=array('schema_version'=>2,'templates'=>array(
 eml2_design('default','Default issued',EmailDesignPurposes::ISSUED),
 eml2_design('global-issued','Global issued',EmailDesignPurposes::ISSUED),
 eml2_design('program-issued','Program issued',EmailDesignPurposes::ISSUED),
 eml2_design('batch-issued','Batch issued',EmailDesignPurposes::ISSUED),
 eml2_design('paused-issued','Paused issued',EmailDesignPurposes::ISSUED,0),
 eml2_design('expiry','Expiry reminder',EmailDesignPurposes::EXPIRY_REMINDER),
));
function eml2_rule($scope,$id,$purpose,$design,$updated='2026-09-03 00:10:00'){return array('scope_type'=>$scope,'scope_id'=>$id,'purpose'=>$purpose,'design_id'=>$design,'updated_at'=>$updated);}
$GLOBALS['eml2_options'][EmailDesignAssignmentRepository::OPTION]=array(
 eml2_rule('global',0,EmailDesignPurposes::ISSUED,'global-issued'),
 eml2_rule('program',7,EmailDesignPurposes::ISSUED,'program-issued'),
 eml2_rule('batch',9,EmailDesignPurposes::ISSUED,'batch-issued'),
);
$repo=new EmailDesignAssignmentRepository();$pass=0;$fail=0;function eml2($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}} function eml2err($v,$code){return is_wp_error($v)&&$code===$v->get_error_code();}
$r=$repo->resolve(3,7,9,EmailDesignPurposes::ISSUED);eml2(!is_wp_error($r)&&'batch-issued'===($r['design']['id']??'')&&'batch'===($r['scope_type']??''),'Batch override wins over Program and Global');
$r=$repo->resolve(3,7,0,EmailDesignPurposes::ISSUED);eml2(!is_wp_error($r)&&'program-issued'===($r['design']['id']??'')&&'program'===($r['scope_type']??''),'Program override wins when no Batch override applies');
$r=$repo->resolve(3,8,0,EmailDesignPurposes::ISSUED);eml2(!is_wp_error($r)&&'global-issued'===($r['design']['id']??'')&&'global'===($r['scope_type']??''),'Global rule is the final explicit parent');
$rows=$GLOBALS['eml2_options'][EmailDesignAssignmentRepository::OPTION];$rows[2]['design_id']='missing-design';$GLOBALS['eml2_options'][EmailDesignAssignmentRepository::OPTION]=$rows;
$all=$repo->all();eml2(3===count($all)&&'missing-design'===($all[2]['design_id']??''),'structurally valid broken stored rule remains visible to authority resolution');
$r=$repo->resolve(3,7,9,EmailDesignPurposes::ISSUED);eml2(eml2err($r,'certloom_email_assignment_broken'),'broken explicit Batch rule fails closed instead of falling through');
$r=$repo->remove('batch',9,EmailDesignPurposes::ISSUED);eml2(true===$r,'Restore inheritance can remove a broken explicit rule');
$r=$repo->resolve(3,7,9,EmailDesignPurposes::ISSUED);eml2(!is_wp_error($r)&&'program-issued'===($r['design']['id']??''),'restoring Batch inheritance resumes Program parent resolution');
$GLOBALS['eml2_options'][EmailDesignAssignmentRepository::OPTION][]=eml2_rule('batch',9,EmailDesignPurposes::ISSUED,'paused-issued');
$r=$repo->resolve(3,7,9,EmailDesignPurposes::ISSUED);eml2(eml2err($r,'certloom_email_assignment_broken'),'explicit rule pointing to paused design fails closed');
$GLOBALS['eml2_options'][EmailDesignAssignmentRepository::OPTION][count($GLOBALS['eml2_options'][EmailDesignAssignmentRepository::OPTION])-1]=eml2_rule('batch',9,EmailDesignPurposes::ISSUED,'expiry');
$r=$repo->resolve(3,7,9,EmailDesignPurposes::ISSUED);eml2(eml2err($r,'certloom_email_assignment_broken'),'explicit rule pointing to wrong-purpose design fails closed');
$r=$repo->resolve(3,7,9,'bogus-purpose');eml2(eml2err($r,'certloom_email_assignment_purpose_invalid'),'unsupported runtime purpose fails closed instead of becoming Credential issued');
$r=$repo->remove('batch',9,'bogus-purpose');eml2(eml2err($r,'certloom_email_assignment_purpose_invalid'),'tampered Restore inheritance purpose cannot remove the issued rule');
$r=$repo->save('global',0,'bogus-purpose','global-issued');eml2(eml2err($r,'certloom_email_assignment_purpose_invalid'),'tampered assignment purpose is rejected at mutation boundary');
$catalog_rows=$GLOBALS['eml2_options'][EmailTemplateCatalogRepository::OPTION]['templates'];
$catalog_rows[]=array('id'=>'corrupt-purpose','name'=>'Corrupt purpose','purpose'=>'not-real','active'=>1,'subject'=>'Unsafe','html_body'=>'<p>Unsafe</p>','text_body'=>'Unsafe','updated_at'=>'2026-09-03 00:00:00');
$catalog_rows[]=array('id'=>'legacy-no-purpose','name'=>'Legacy no purpose','active'=>1,'subject'=>'Legacy','html_body'=>'<p>Legacy</p>','text_body'=>'Legacy','updated_at'=>'2026-09-03 00:00:00');
$GLOBALS['eml2_options'][EmailTemplateCatalogRepository::OPTION]['templates']=$catalog_rows;
$catalog=new EmailTemplateCatalogRepository();
eml2(null===$catalog->get('corrupt-purpose'),'modern stored design with explicit invalid purpose is excluded rather than reinterpreted as issued');
$legacy=$catalog->get('legacy-no-purpose');eml2(is_array($legacy)&&EmailDesignPurposes::ISSUED===($legacy['purpose']??''),'pre-purpose legacy design with no purpose remains compatible as Credential issued');
echo"\n$pass Email Design inheritance checks, $fail failures\n";exit($fail?1:0);
