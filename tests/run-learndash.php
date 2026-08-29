<?php
/** INT-03 LearnDash first-party adapter contract. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'LEARNDASH_VERSION', '4.25.1' );
define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return$this->c;} public function get_error_message(){return$this->m;} public function get_error_data(){return$this->d;} }
}
$GLOBALS['ld_options'] = array();
$GLOBALS['ld_actions'] = array();
$GLOBALS['ld_completed'] = true;
$GLOBALS['ld_completed_date'] = 1787991300;
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags(preg_replace('/[\r\n\t]+/',' ',(string)$v)));}
function current_time($format,$gmt=false){ if('timestamp'===$format)return 1787990400; return '2026-08-29 08:00:00'; }
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function get_option($k,$default=false){return array_key_exists($k,$GLOBALS['ld_options'])?$GLOBALS['ld_options'][$k]:$default;}
function update_option($k,$v,$autoload=null){$old=get_option($k,null);$GLOBALS['ld_options'][$k]=$v;return$old!==$v;}
function delete_option($k){unset($GLOBALS['ld_options'][$k]);return true;}
function add_action($hook,$callback,$priority=10,$accepted_args=1){$GLOBALS['ld_actions'][]=array($hook,$callback,$priority,$accepted_args);}
function learndash_get_post_type_slug($key=''){return 'course'===$key?'sfwd-courses':'';}
function learndash_course_completed($user_id,$course_id){return !empty($GLOBALS['ld_completed']);}
function learndash_user_get_course_completed_date($user_id,$course_id){return (int)$GLOBALS['ld_completed_date'];}

require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\IntegrationMappingResolverInterface;
use Certloom\Application\Contract\IntegrationRecipientResolverInterface;
use Certloom\Application\Integration\IntegrationEventContext;
use Certloom\Application\Integration\IntegrationIssuanceCoordinator;
use Certloom\Infrastructure\Integration\LearnDashAdapter;
use Certloom\Infrastructure\Integration\LearnDashBridge;
use Certloom\Infrastructure\Integration\LearnDashMappingStore;

$pass=0;$fail=0;
function ldcheck($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
function lderr($v,$code){return is_wp_error($v)&&$code===$v->get_error_code();}

$adapter=new LearnDashAdapter();
ldcheck('learndash'===$adapter->key(),'adapter key is stable LearnDash machine key');
ldcheck('LearnDash'===$adapter->label(),'adapter label is customer-facing LearnDash');
ldcheck(LearnDashAdapter::is_available(),'adapter detects LearnDash from first-party runtime');
ldcheck('sfwd-courses'===LearnDashAdapter::course_post_type(),'adapter uses LearnDash post-type resolver');
ldcheck('2026-08-29T08:15:00Z'===LearnDashAdapter::completion_time(101,42),'completion time uses persisted LearnDash course completion timestamp');
$GLOBALS['ld_completed_date']=0;
ldcheck('2026-08-29T08:00:00Z'===LearnDashAdapter::completion_time(101,42),'missing persisted completion timestamp uses bounded UTC fallback');
$GLOBALS['ld_completed_date']=1787991300;

$event=$adapter->normalize_event(array('course_id'=>101,'user_id'=>42,'completed_at'=>'2026-08-29T08:15:00Z','certificate_threshold'=>100,'email'=>'must-not-survive@example.test','learner_name'=>'Must Not Survive','progress'=>array('arbitrary'=>'payload')));
ldcheck(!is_wp_error($event),'valid LearnDash course completion normalizes');
ldcheck('course_completed'===($event['event_type']??'')&&'course'===($event['source_object']['type']??''),'normalized event has bounded completion/course semantics');
ldcheck('course:101:user:42'===($event['source_event_id']??''),'source event identity is stable per course and WordPress user');
ldcheck(42===($event['wp_user_id']??0),'normalized event carries only explicit WordPress learner identity');
ldcheck(1===count($event['attempts']??array())&&100.0===(float)($event['attempts'][0]['percentage']??-1)&&!empty($event['attempts'][0]['completed']),'course completion is represented as one completed 100 percent attempt');
ldcheck(!isset($event['email'])&&!isset($event['learner_name'])&&!isset($event['progress'])&&!isset($event['provider_payload']),'arbitrary LearnDash learner/progress payload is not retained');
ldcheck(lderr($adapter->normalize_event(array('course_id'=>0,'user_id'=>42,'completed_at'=>'2026-08-29T08:15:00Z')),'certloom_learndash_course_invalid'),'invalid LearnDash course fails closed');
ldcheck(lderr($adapter->normalize_event(array('course_id'=>101,'user_id'=>0,'completed_at'=>'2026-08-29T08:15:00Z')),'certloom_learndash_user_invalid'),'invalid LearnDash WordPress user fails closed');
ldcheck(lderr($adapter->normalize_event(array('course_id'=>101,'user_id'=>42,'completed_at'=>'not-a-time')),'certloom_learndash_completion_time_invalid'),'invalid LearnDash completion time fails closed');
ldcheck(lderr($adapter->normalize_event(array('course_id'=>101,'user_id'=>42,'completed_at'=>'2026-08-29T08:15:00Z','certificate_threshold'=>101)),'certloom_learndash_threshold_invalid'),'invalid LearnDash certificate threshold fails closed');

$context=IntegrationEventContext::from_array($event);
ldcheck(!is_wp_error($context),'LearnDash adapter output enters generic IntegrationEventContext');
$arr=$context->to_array();
ldcheck('learndash'===$context->adapter_key()&&'101'===$context->source_object_id(),'generic context retains bounded LearnDash adapter/course identity');
ldcheck(100.0===$context->certificate_threshold()&&$context->is_eligible(),'LearnDash course completion satisfies exact 100 percent mapping threshold');
ldcheck(!array_key_exists('email',$arr)&&!array_key_exists('learner_name',$arr)&&!array_key_exists('progress',$arr),'generic context contains no learner name, email or progress payload');
$event2=$event;$event2['completed_at']='2026-08-29T08:16:00Z';$event2['attempts'][0]['attempted_at']='2026-08-29T08:16:00Z';
$context2=IntegrationEventContext::from_array($event2);
ldcheck(!is_wp_error($context2)&&$context->idempotency_key()===$context2->idempotency_key(),'same LearnDash course/user completion keeps deterministic idempotency despite timestamp evidence changes');
ldcheck($context->sha256()!==$context2->sha256(),'changed completion evidence changes normalized context SHA');

$store=new LearnDashMappingStore();
$save=$store->save(array('course_id'=>101,'program_id'=>7,'template_id'=>9,'certificate_threshold'=>100,'expiry_mode'=>'program','active'=>true));
ldcheck(true===$save,'LearnDash mapping persists through bounded server-owned store');
$found=$store->find(101);
ldcheck(is_array($found)&&7===($found['program_id']??0)&&9===($found['template_id']??0)&&!empty($found['active']),'LearnDash mapping round-trips exact Program/Template targets');
$resolved=$store->resolve_mapping($context);
ldcheck(is_array($resolved)&&'learndash'===($resolved['adapter']??'')&&100.0===(float)($resolved['certificate_threshold']??-1),'LearnDash mapping resolves only into generic mapping contract');
$other=$event;$other['adapter']='tutor_lms';$otherContext=IntegrationEventContext::from_array($other);
ldcheck(!is_wp_error($otherContext)&&null===$store->resolve_mapping($otherContext),'LearnDash store refuses a different LMS adapter');
ldcheck(lderr($store->save(array('course_id'=>102,'program_id'=>0,'template_id'=>9)),'certloom_learndash_mapping_target_invalid'),'mapping refuses missing Certloom Program');
ldcheck(lderr($store->save(array('course_id'=>102,'program_id'=>7,'template_id'=>0)),'certloom_learndash_mapping_target_invalid'),'mapping refuses missing published Template');
ldcheck(lderr($store->save(array('course_id'=>102,'program_id'=>7,'template_id'=>9,'expiry_mode'=>'date','expires_at'=>'bad-date')),'certloom_learndash_mapping_expiry_invalid'),'mapping refuses invalid explicit expiry date');
ldcheck(true===$store->delete(101)&&null===$store->find(101),'LearnDash mapping deletes cleanly');
$store->save(array('course_id'=>101,'program_id'=>7,'template_id'=>9,'certificate_threshold'=>100,'expiry_mode'=>'program','active'=>true));

final class LearnDashTestRecipients implements IntegrationRecipientResolverInterface { public function resolve_recipient_for_wp_user($id){return array('id'=>1,'display_name'=>'Explicit Recipient');} }
final class LearnDashMismatchMappings implements IntegrationMappingResolverInterface { public function resolve_mapping(IntegrationEventContext $c){return array('adapter'=>'learndash','source_object_type'=>'course','source_object_id'=>'101','program_id'=>7,'template_id'=>9,'certificate_threshold'=>99,'expiry_mode'=>'program','expires_at'=>null,'active'=>true);} }
$coord=new IntegrationIssuanceCoordinator(new LearnDashTestRecipients(),new LearnDashMismatchMappings());
$mismatch=$coord->review($context);
ldcheck(lderr($mismatch,'certloom_integration_mapping_threshold_mismatch'),'generic coordinator rejects a changed LearnDash eligibility threshold before issuance');

LearnDashBridge::register();
$hook=end($GLOBALS['ld_actions']);
ldcheck(is_array($hook)&&'learndash_course_completed'===$hook[0]&&20===$hook[2]&&1===$hook[3],'bridge registers exact LearnDash post-completion hook with one argument');
ldcheck(!LearnDashBridge::authority_ready(),'LearnDash automatic credential writes remain closed with normalized write authority');
$course=(object)array('ID'=>101,'post_type'=>'sfwd-courses');$user=(object)array('ID'=>42,'user_email'=>'must-not-survive@example.test','display_name'=>'Must Not Survive');
$GLOBALS['ld_completed']=false;
LearnDashBridge::course_completed(array('user'=>$user,'course'=>$course,'progress'=>array('arbitrary'=>'payload')));
$last=get_option(LearnDashBridge::LAST_RESULT_OPTION,array());
ldcheck('failed'===($last['status']??'')&&'certloom_learndash_completion_unconfirmed'===($last['error_code']??''),'bridge refuses a hook event LearnDash itself does not confirm as completed');
$GLOBALS['ld_completed']=true;
LearnDashBridge::course_completed(array('user'=>$user,'course'=>$course,'progress'=>array('arbitrary'=>'payload')));
$last=get_option(LearnDashBridge::LAST_RESULT_OPTION,array());
ldcheck('blocked'===($last['status']??'')&&'certloom_learndash_authority_closed'===($last['error_code']??''),'mapped confirmed LearnDash completion records bounded blocked result while authority is closed');
ldcheck(101===($last['course_id']??0)&&!isset($last['email'])&&!isset($last['learner_name'])&&!isset($last['progress'])&&!isset($last['provider_payload']),'LearnDash last-result evidence is privacy-safe and bounded');
$before=$last;
LearnDashBridge::course_completed(array('user'=>$user,'course'=>(object)array('ID'=>101,'post_type'=>'post')));
ldcheck($before===get_option(LearnDashBridge::LAST_RESULT_OPTION,array()),'bridge ignores a non-LearnDash course object even if the hook is fired manually');
LearnDashBridge::course_completed(array('user'=>(object)array('ID'=>0),'course'=>$course));
ldcheck($before===get_option(LearnDashBridge::LAST_RESULT_OPTION,array()),'bridge ignores invalid LearnDash hook identity safely');

$root=dirname(__DIR__);
$adapterSrc=file_get_contents($root.'/src/Infrastructure/Integration/LearnDashAdapter.php');
$bridgeSrc=file_get_contents($root.'/src/Infrastructure/Integration/LearnDashBridge.php');
$mappingSrc=file_get_contents($root.'/src/Infrastructure/Integration/LearnDashMappingStore.php');
$adminSrc=file_get_contents($root.'/src/Infrastructure/Admin/LearnDashIntegrationAdmin.php');
$coordSrc=file_get_contents($root.'/src/Application/Integration/IntegrationIssuanceCoordinator.php');
$registrySrc=file_get_contents($root.'/src/Application/Integration/IntegrationAdapterRegistry.php');
$plugin=file_get_contents($root.'/certloom.php');
$uninstall=file_get_contents($root.'/uninstall.php');
$view=file_get_contents($root.'/admin/views/integrations.php');
ldcheck(false!==strpos($bridgeSrc,"const HOOK = 'learndash_course_completed'")&&false!==strpos($bridgeSrc,'learndash_course_completed( $user_id, $course_id )')&&false!==strpos($bridgeSrc,'IntegrationAdapterRegistry')&&false!==strpos($bridgeSrc,'LinkedRecipientResolver')&&false!==strpos($bridgeSrc,'IntegrationIssuanceCoordinator'),'LearnDash bridge verifies provider completion and stays behind generic INT-01 boundaries');
ldcheck(false===strpos($adapterSrc,'Ecapla_CertVerify_Database')&&false===strpos($bridgeSrc,'Ecapla_CertVerify_Database'),'LearnDash adapter never writes legacy credential tables directly');
ldcheck(false===stripos($adapterSrc,'user_email')&&false===stripos($bridgeSrc,'user_email')&&false===stripos($mappingSrc,'email'),'LearnDash path contains no email-based recipient inference');
ldcheck(false!==strpos($adminSrc,'CAP_MANAGE_INTEGRATIONS')&&false!==strpos($adminSrc,'check_admin_referer'),'LearnDash mapping mutations require dedicated capability and nonce');
ldcheck(false!==strpos($adminSrc,'DomainFeatureGate::admin_preview_ready()')&&false!==strpos($adminSrc,'find_published'),'LearnDash mappings require gated Program/Template preview and published Template validation');
ldcheck(false!==strpos($adminSrc,"add_query_arg( 'certloom_error', \$result->get_error_message(), \$url )")&&false===strpos($adminSrc,'rawurlencode( $result->get_error_message() )'),'LearnDash admin redirect lets WordPress encode errors once rather than double-encoding');
ldcheck(false!==strpos($view,"'learndash_mapping_deleted'")&&false!==strpos($view,"'Course mapping removed.'")&&false!==strpos($view,"'Course mapping saved.'"),'LearnDash mapping saved/deleted notices are accurate');
ldcheck(false!==strpos($coordSrc,'certificate_threshold')&&false!==strpos($coordSrc,'certloom_integration_mapping_threshold_mismatch'),'generic coordinator binds LearnDash mapping threshold to normalized event threshold');
ldcheck(false===stripos($coordSrc,'learndash')&&false===stripos($registrySrc,'learndash'),'generic coordinator/registry remain LearnDash-agnostic');
ldcheck(1===preg_match('/LearnDashBridge::register\(\)/',$plugin)&&1===preg_match('/LearnDashIntegrationAdmin\(\)\s*\)->register\(\)/',$plugin),'plugin boot registers LearnDash runtime and admin boundaries');
ldcheck(false!==strpos($uninstall,"'certloom_learndash_last_result'")&&false!==strpos($uninstall,"'certloom_learndash_mappings'"),'uninstall removes local LearnDash mappings and operational evidence');
ldcheck(false!==strpos($plugin,"define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )")&&false!==strpos($plugin,"define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false )")&&false!==strpos($plugin,"define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false )"),'LearnDash does not open normalized-domain production authority');
ldcheck(!file_exists($root.'/resources/renderer/accepted.json'),'LearnDash does not manufacture renderer authority');

echo "\n$pass INT-03 LearnDash checks, $fail failures\n"; exit($fail?1:0);
