<?php
/** Functional contract for automation scope matching after UX-CORE-06. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} } }
$GLOBALS['ux6_options'] = array();
function is_wp_error($v){return $v instanceof WP_Error;} function __($v,$d=null){return$v;} function esc_html__($v,$d=null){return$v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim(strip_tags(preg_replace('/[\r\n\t]+/',' ',(string)$v)));} function sanitize_textarea_field($v){return trim(strip_tags((string)$v));} function wp_kses_post($v){return (string)$v;} function current_time($f,$gmt=false){return '2026-09-02 04:00:00';} function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['ux6_options'])?$GLOBALS['ux6_options'][$k]:$d;} function update_option($k,$v,$a=null){$GLOBALS['ux6_options'][$k]=$v;return true;} function wp_generate_password($l=12,$s=true,$x=false){return str_repeat('a',$l);} function wp_strip_all_tags($v){return strip_tags((string)$v);} function esc_url($v){return$v;} function get_bloginfo($k=''){return 'Example';}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Email\EmailAutomationRuleRepository;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;

$GLOBALS['ux6_options'][EmailTemplateCatalogRepository::OPTION] = array('schema_version'=>1,'templates'=>array(array('id'=>'mail-a','name'=>'Issued message','active'=>1,'subject'=>'Issued {{program_title}}','html_body'=>'<p>Issued {{program_title}}</p>','text_body'=>'Issued {{program_title}}','updated_at'=>'2026-09-02 04:00:00')));
$GLOBALS['ux6_options'][EmailAutomationRuleRepository::OPTION] = array(
 array('id'=>'all','name'=>'All issued','active'=>1,'issuer_id'=>0,'program_id'=>0,'batch_id'=>0,'template_id'=>'mail-a','source_type'=>'any','updated_at'=>'2026-09-02 04:00:00'),
 array('id'=>'issuer','name'=>'Issuer 5','active'=>1,'issuer_id'=>5,'program_id'=>0,'batch_id'=>0,'template_id'=>'mail-a','source_type'=>'any','updated_at'=>'2026-09-02 04:00:00'),
 array('id'=>'batch','name'=>'Batch source','active'=>1,'issuer_id'=>5,'program_id'=>7,'batch_id'=>9,'template_id'=>'mail-a','source_type'=>'tutor_lms','updated_at'=>'2026-09-02 04:00:00'),
 array('id'=>'paused','name'=>'Paused','active'=>0,'issuer_id'=>5,'program_id'=>7,'batch_id'=>9,'template_id'=>'mail-a','source_type'=>'tutor_lms','updated_at'=>'2026-09-02 04:00:00'),
);
$r = new EmailAutomationRuleRepository(); $pass=0;$fail=0;
function ach($ok,$label){global$pass,$fail;if($ok){++$pass;echo"PASS  $label\n";}else{++$fail;echo"FAIL  $label\n";}}
$ids=function($rows){return array_values(array_map(static function($x){return(string)$x['id'];},$rows));};
ach($ids($r->matching(5,7,9,'tutor_lms'))===array('all','issuer','batch'),'exact Issuer Program Batch source context matches broad plus narrowed rules');
ach($ids($r->matching(5,7,10,'tutor_lms'))===array('all','issuer'),'different Batch excludes Batch-narrowed rule');
ach($ids($r->matching(5,7,9,'manual_admin'))===array('all','issuer'),'different issuance source excludes source-narrowed rule');
ach($ids($r->matching(6,7,9,'tutor_lms'))===array('all'),'different Issuer excludes Issuer-narrowed rules');
$matched=$r->matching(5,7,9,'tutor_lms'); ach('mail-a'===($matched[2]['template_id']??''),'matched template_id remains reusable email-message identity, not certificate-design identity');
ach(!in_array('paused',$ids($r->matching(5,7,9,'tutor_lms')),true),'paused automation never matches');
printf("\n%d automation scope checks, %d failures\n",$pass,$fail);exit($fail?1:0);
