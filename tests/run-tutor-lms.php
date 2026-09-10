<?php
/** INT-02 Tutor LMS first-party adapter contract. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'TUTOR_VERSION', '3.7.0' );
define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return$this->c;} public function get_error_message(){return$this->m;} public function get_error_data(){return$this->d;} }
}
$GLOBALS['tutor_options'] = array();
$GLOBALS['tutor_actions'] = array();
$GLOBALS['tutor_comments'] = array();
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags(preg_replace('/[\r\n\t]+/',' ',(string)$v)));}
function current_time($format,$gmt=false){ if('timestamp'===$format)return 1787990400; return '2026-08-29 08:00:00'; }
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_option($k,$default=false){return array_key_exists($k,$GLOBALS['tutor_options'])?$GLOBALS['tutor_options'][$k]:$default;}
function update_option($k,$v,$autoload=null){$old=get_option($k,null);$GLOBALS['tutor_options'][$k]=$v;return$old!==$v;}
function delete_option($k){unset($GLOBALS['tutor_options'][$k]);return true;}
function add_action($hook,$callback,$priority=10,$accepted_args=1){$GLOBALS['tutor_actions'][]=array($hook,$callback,$priority,$accepted_args);}
function tutor(){return (object)array('course_post_type'=>'courses');}
function tutor_utils(){return (object)array();}
function get_comments($args=array()){return $GLOBALS['tutor_comments'];}

require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\IntegrationMappingResolverInterface;
use Certloom\Application\Contract\IntegrationRecipientResolverInterface;
use Certloom\Application\Integration\IntegrationEventContext;
use Certloom\Application\Integration\IntegrationIssuanceCoordinator;
use Certloom\Infrastructure\Integration\TutorLmsAdapter;
use Certloom\Infrastructure\Integration\TutorLmsBridge;
use Certloom\Infrastructure\Integration\TutorLmsMappingStore;

$pass=0;$fail=0;
function tcheck($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
function terr($v,$code){return is_wp_error($v)&&$code===$v->get_error_code();}

$adapter=new TutorLmsAdapter();
tcheck('tutor_lms'===$adapter->key(),'adapter key is stable Tutor LMS machine key');
tcheck('Tutor LMS'===$adapter->label(),'adapter label is customer-facing Tutor LMS');
tcheck(TutorLmsAdapter::is_available(),'adapter detects Tutor LMS from first-party runtime');
tcheck('courses'===TutorLmsAdapter::course_post_type(),'adapter uses Tutor course post type without hard-coded alternate model');

$GLOBALS['tutor_comments']=array((object)array('comment_agent'=>'TutorLMSPlugin','comment_date_gmt'=>'2026-08-29 07:15:00'));
tcheck('2026-08-29T07:15:00Z'===TutorLmsAdapter::completion_time(101,42),'completion time is sourced from bounded Tutor completion comment evidence');
$GLOBALS['tutor_comments']=array((object)array('comment_agent'=>'OtherPlugin','comment_date_gmt'=>'2026-08-29 07:15:00'));
tcheck('2026-08-29T08:00:00Z'===TutorLmsAdapter::completion_time(101,42),'foreign completion comments are ignored and safe UTC fallback is used');

$event=$adapter->normalize_event(array('course_id'=>101,'user_id'=>42,'completed_at'=>'2026-08-29T07:15:00Z','certificate_threshold'=>100,'email'=>'must-not-survive@example.test','learner_name'=>'Must Not Survive'));
tcheck(!is_wp_error($event),'valid Tutor course completion normalizes');
tcheck('course_completed'===($event['event_type']??'')&&'course'===($event['source_object']['type']??''),'normalized event has bounded completion/course semantics');
tcheck('course:101:user:42'===($event['source_event_id']??''),'source event identity is stable per course and WordPress user');
tcheck(42===($event['wp_user_id']??0),'normalized event carries only explicit WordPress learner identity');
tcheck(1===count($event['attempts']??array())&&100.0===(float)($event['attempts'][0]['percentage']??-1)&&!empty($event['attempts'][0]['completed']),'course completion is represented as one completed 100 percent attempt');
tcheck(!isset($event['email'])&&!isset($event['learner_name'])&&!isset($event['provider_payload']),'arbitrary Tutor learner/provider payload is not retained');
tcheck(terr($adapter->normalize_event(array('course_id'=>0,'user_id'=>42,'completed_at'=>'2026-08-29T07:15:00Z')),'certloom_tutor_course_invalid'),'invalid Tutor course fails closed');
tcheck(terr($adapter->normalize_event(array('course_id'=>101,'user_id'=>0,'completed_at'=>'2026-08-29T07:15:00Z')),'certloom_tutor_user_invalid'),'invalid Tutor WordPress user fails closed');
tcheck(terr($adapter->normalize_event(array('course_id'=>101,'user_id'=>42,'completed_at'=>'not-a-time')),'certloom_tutor_completion_time_invalid'),'invalid Tutor completion time fails closed');
tcheck(terr($adapter->normalize_event(array('course_id'=>101,'user_id'=>42,'completed_at'=>'2026-08-29T07:15:00Z','certificate_threshold'=>101)),'certloom_tutor_threshold_invalid'),'invalid Tutor certificate threshold fails closed');

$context=IntegrationEventContext::from_array($event);
tcheck(!is_wp_error($context),'Tutor adapter output enters generic IntegrationEventContext');
$arr=$context->to_array();
tcheck('tutor_lms'===$context->adapter_key()&&'101'===$context->source_object_id(),'generic context retains bounded Tutor adapter/course identity');
tcheck(100.0===$context->certificate_threshold()&&$context->is_eligible(),'Tutor course completion satisfies exact 100 percent mapping threshold');
tcheck(!array_key_exists('email',$arr)&&!array_key_exists('learner_name',$arr),'generic context contains no learner name or email');
$event2=$event;$event2['completed_at']='2026-08-29T07:16:00Z';$event2['attempts'][0]['attempted_at']='2026-08-29T07:16:00Z';
$context2=IntegrationEventContext::from_array($event2);
tcheck(!is_wp_error($context2)&&$context->idempotency_key()===$context2->idempotency_key(),'same Tutor course/user completion keeps deterministic idempotency despite timestamp evidence changes');
tcheck($context->sha256()!==$context2->sha256(),'changed completion evidence changes normalized context SHA');

$store=new TutorLmsMappingStore();
$save=$store->save(array('course_id'=>101,'program_id'=>7,'template_id'=>9,'certificate_threshold'=>100,'expiry_mode'=>'program','active'=>true));
tcheck(true===$save,'Tutor mapping persists through bounded server-owned store');
$found=$store->find(101);
tcheck(is_array($found)&&7===($found['program_id']??0)&&9===($found['template_id']??0)&&!empty($found['active']),'Tutor mapping round-trips exact Program/Template targets');
$resolved=$store->resolve_mapping($context);
tcheck(is_array($resolved)&&'tutor_lms'===($resolved['adapter']??'')&&100.0===(float)($resolved['certificate_threshold']??-1),'Tutor mapping resolves only into generic mapping contract');
$other=$event;$other['adapter']='other_lms';$otherContext=IntegrationEventContext::from_array($other);
tcheck(!is_wp_error($otherContext)&&null===$store->resolve_mapping($otherContext),'Tutor store refuses a different LMS adapter');
tcheck(terr($store->save(array('course_id'=>102,'program_id'=>0,'template_id'=>9)),'certloom_tutor_mapping_target_invalid'),'mapping refuses missing Certloom Program');
tcheck(true===$store->save(array('course_id'=>102,'program_id'=>7,'batch_id'=>0,'template_id'=>0)),'mapping stores Program context without pinning a certificate design');
tcheck(terr($store->save(array('course_id'=>102,'program_id'=>7,'template_id'=>9,'expiry_mode'=>'date','expires_at'=>'bad-date')),'certloom_tutor_mapping_expiry_invalid'),'mapping refuses invalid explicit expiry date');
tcheck(true===$store->delete(101)&&null===$store->find(101),'Tutor mapping deletes cleanly');
$store->save(array('course_id'=>101,'program_id'=>7,'template_id'=>9,'certificate_threshold'=>100,'expiry_mode'=>'program','active'=>true));

final class TutorTestRecipients implements IntegrationRecipientResolverInterface { public function resolve_recipient_for_wp_user($id){return array('id'=>1,'display_name'=>'Explicit Recipient');} }
final class TutorMismatchMappings implements IntegrationMappingResolverInterface { public function resolve_mapping(IntegrationEventContext $c){return array('adapter'=>'tutor_lms','source_object_type'=>'course','source_object_id'=>'101','program_id'=>7,'template_id'=>9,'certificate_threshold'=>99,'expiry_mode'=>'program','expires_at'=>null,'active'=>true);} }
$coord=new IntegrationIssuanceCoordinator(new TutorTestRecipients(),new TutorMismatchMappings());
$mismatch=$coord->review($context);
tcheck(terr($mismatch,'certloom_integration_mapping_threshold_mismatch'),'generic coordinator rejects a changed Tutor eligibility threshold before issuance');

TutorLmsBridge::register();
$hook=end($GLOBALS['tutor_actions']);
tcheck(is_array($hook)&&'tutor_course_complete_after'===$hook[0]&&20===$hook[2]&&2===$hook[3],'bridge registers exact Tutor completion hook with two arguments');
tcheck(!TutorLmsBridge::authority_ready(),'Tutor automatic credential writes remain closed with normalized write authority');
TutorLmsBridge::course_completed(101,42);
$last=get_option(TutorLmsBridge::LAST_RESULT_OPTION,array());
tcheck('blocked'===($last['status']??'')&&'certloom_tutor_authority_closed'===($last['error_code']??''),'mapped Tutor completion records bounded blocked result while authority is closed');
tcheck(101===($last['course_id']??0)&&!isset($last['email'])&&!isset($last['learner_name'])&&!isset($last['provider_payload']),'Tutor last-result evidence is privacy-safe and bounded');

$root=dirname(__DIR__);
$adapterSrc=file_get_contents($root.'/src/Infrastructure/Integration/TutorLmsAdapter.php');
$bridgeSrc=file_get_contents($root.'/src/Infrastructure/Integration/TutorLmsBridge.php');
$mappingSrc=file_get_contents($root.'/src/Infrastructure/Integration/TutorLmsMappingStore.php');
$adminSrc=file_get_contents($root.'/src/Infrastructure/Admin/TutorLmsIntegrationAdmin.php');
$coordSrc=file_get_contents($root.'/src/Application/Integration/IntegrationIssuanceCoordinator.php');
$registrySrc=file_get_contents($root.'/src/Application/Integration/IntegrationAdapterRegistry.php');
$plugin=file_get_contents($root.'/certloom.php');
$uninstall=file_get_contents($root.'/uninstall.php');
$view=file_get_contents($root.'/admin/views/integrations.php');
tcheck(false!==strpos($bridgeSrc,"const HOOK = 'tutor_course_complete_after'")&&false!==strpos($bridgeSrc,'IntegrationAdapterRegistry')&&false!==strpos($bridgeSrc,'LinkedRecipientResolver')&&false!==strpos($bridgeSrc,'IntegrationIssuanceCoordinator'),'Tutor bridge stays behind closed generic INT-01 adapter/recipient/coordinator boundaries');
tcheck(false===strpos($adapterSrc,'Ecapla_CertVerify_Database')&&false===strpos($bridgeSrc,'Ecapla_CertVerify_Database'),'Tutor adapter never writes legacy credential tables directly');
tcheck(false===stripos($adapterSrc,'user_email')&&false===stripos($bridgeSrc,'user_email')&&false===stripos($mappingSrc,'email'),'Tutor path contains no email-based recipient inference');
tcheck(false!==strpos($adminSrc,'CAP_MANAGE_INTEGRATIONS')&&false!==strpos($adminSrc,'check_admin_referer'),'Tutor mapping mutations require dedicated capability and nonce');
tcheck(false!==strpos($adminSrc,'DomainFeatureGate::admin_preview_ready()')&&false!==strpos($adminSrc,'IntegrationMappingTargetGuard'),'Tutor mappings require gated Program/Batch preview and shared hierarchy compatibility validation');
tcheck(false!==strpos($adminSrc,"add_query_arg( 'certloom_error', \$result->get_error_message(), \$url )")&&false===strpos($adminSrc,'rawurlencode( $result->get_error_message() )'),'Tutor admin redirect lets WordPress encode errors once rather than double-encoding');
tcheck(false!==strpos($view,"'mapping_deleted'")&&false!==strpos($view,"'learndash_mapping_deleted'")&&false!==strpos($view,"'Course mapping removed.'")&&false!==strpos($view,"'Course mapping saved.'"),'shared LMS mapping saved/deleted notices remain distinct and accurate');
tcheck(false!==strpos($coordSrc,'certificate_threshold')&&false!==strpos($coordSrc,'certloom_integration_mapping_threshold_mismatch'),'generic coordinator binds Tutor mapping threshold to normalized event threshold');
tcheck(false===stripos($coordSrc,'tutor')&&false===stripos($registrySrc,'tutor'),'generic coordinator/registry remain Tutor-agnostic');
tcheck(1 === preg_match('/TutorLmsBridge::register\(\)/', $plugin) && 1 === preg_match('/TutorLmsIntegrationAdmin\(\)\s*\)->register\(\)/', $plugin),'plugin boot registers Tutor runtime and admin boundaries');
tcheck(false!==strpos($uninstall,"'certloom_tutor_lms_last_result'")&&false!==strpos($uninstall,"'certloom_tutor_lms_mappings'"),'uninstall removes local Tutor mappings and operational evidence');
tcheck(false!==strpos($plugin,"define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )")&&false!==strpos($plugin,"define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false )")&&false!==strpos($plugin,"define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false )"),'Tutor LMS does not open normalized-domain production authority');
tcheck(!file_exists($root.'/resources/renderer/accepted.json'),'Tutor LMS does not manufacture renderer authority');

echo "\n$pass INT-02 Tutor LMS checks, $fail failures\n"; exit($fail?1:0);
