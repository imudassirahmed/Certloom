<?php
/** AUD-EML-01 privacy-safe automation evidence and immutable read metadata. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return$this->c;} public function get_error_message(){return$this->m;} } }
$GLOBALS['ae_opts']=array();
function is_wp_error($v){return$v instanceof WP_Error;} function __($v,$d=null){return$v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function current_time($f,$gmt=false){return'2026-09-17 08:00:00';} function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['ae_opts'])?$GLOBALS['ae_opts'][$k]:$d;} function update_option($k,$v,$a=false){$GLOBALS['ae_opts'][$k]=$v;return true;} function wp_generate_password($l=12,$s=true,$x=false){return str_repeat('a',$l);}
require_once __DIR__.'/bootstrap-autoload.php';
use Certloom\Infrastructure\Email\EmailAutomationRuleRepository; use Certloom\Infrastructure\Email\EmailAutomationRunRepository;
$pass=0;$fail=0; function aec($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
$GLOBALS['ae_opts'][EmailAutomationRuleRepository::OPTION]=array(array('id'=>'global','name'=>'Global','active'=>1,'issuer_id'=>0,'program_id'=>0,'batch_id'=>0,'source_type'=>'any','updated_at'=>'2026-09-01 01:02:03'));
$r=new EmailAutomationRuleRepository(); $before=$GLOBALS['ae_opts'][EmailAutomationRuleRepository::OPTION][0]['updated_at']; $rows=$r->all();
aec($before===($rows[0]['updated_at']??''),'reading automation rules preserves original updated_at');
aec($before===($GLOBALS['ae_opts'][EmailAutomationRuleRepository::OPTION][0]['updated_at']??''),'rule reads do not mutate stored audit timestamp');
aec('global'===(string)(($r->best_matching(0,0,0,'manual_admin'))['id']??''),'Global rule matches independent credential with no Program');
$run=new EmailAutomationRunRepository();
aec($run->record('global',11,'manual_admin','queued',''),'queued evidence persists');
aec('queued'===(string)(($run->latest_for_rule('global'))['outcome']??''),'latest rule evidence is queryable');
aec($run->record('global',12,'manual_admin','skipped','recipient_email_missing'),'skip evidence persists');
aec('recipient_email_missing'===(string)(($run->latest_for_rule('global'))['code']??''),'skip evidence stores bounded reason code');
for($i=0;$i<60;$i++){$run->record('r'.$i,$i,'webhook','failed','queue_failed');}
aec(50===count($run->all()),'automation evidence is globally bounded to 50 records');
$raw=json_encode($GLOBALS['ae_opts'][EmailAutomationRunRepository::OPTION]??array());
aec(false===strpos($raw,'@')&&false===stripos($raw,'recipient_name'),'automation evidence stores no obvious recipient email/name PII');
aec(false===$run->record('',1,'manual_admin','queued',''),'invalid empty rule identity fails closed');
printf("\n%d automation evidence checks, %d failures\n",$pass,$fail);exit($fail?1:0);
