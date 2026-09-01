<?php
/** UX-CORE-02 Coursera Standard xAPI source contract. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return$this->c;} public function get_error_message(){return$this->m;} public function get_error_data(){return$this->d;} } }
$GLOBALS['cx_options']=array();$GLOBALS['cx_actions']=array();
function is_wp_error($v){return$v instanceof WP_Error;} function __($v,$d=null){return$v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function current_time($f,$gmt=false){return'timestamp'===$f?1788177600:'2026-08-31 12:00:00';} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['cx_options'])?$GLOBALS['cx_options'][$k]:$d;} function update_option($k,$v,$autoload=null){$o=get_option($k,null);$GLOBALS['cx_options'][$k]=$v;return$o!==$v;} function delete_option($k){unset($GLOBALS['cx_options'][$k]);return true;} function rest_url($p=''){return'https://example.test/wp-json/'.ltrim($p,'/');} function add_action($h,$c,$p=10,$a=1){$GLOBALS['cx_actions'][]=array($h,$c,$p,$a);} 
require_once __DIR__.'/bootstrap-autoload.php';

use Certloom\Application\Integration\IntegrationEventContext;
use Certloom\Infrastructure\Integration\CourseraMappingStore;
use Certloom\Infrastructure\Integration\CourseraXapiAdapter;
use Certloom\Infrastructure\Integration\CourseraXapiBridge;
use Certloom\Infrastructure\Integration\CourseraXapiCredentials;

$pass=0;$fail=0;function cx($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}} function cxerr($v,$c){return is_wp_error($v)&&$c===$v->get_error_code();}

$adapter=new CourseraXapiAdapter();
cx('coursera'===$adapter->key(),'adapter key is stable Coursera machine key');
cx('Coursera'===$adapter->label(),'adapter label is customer-facing Coursera');
$event=$adapter->normalize_event(array('statement_id'=>'abc-123','course_id'=>'course-42','recipient_id'=>18,'completed_at'=>'2026-08-31T10:30:00Z','email'=>'must-not-survive@example.test'));
cx(!is_wp_error($event),'valid reviewed Coursera completion normalizes');
cx(0===($event['wp_user_id']??-1)&&18===($event['recipient_id']??0),'Coursera uses explicit local Recipient identity without requiring a WordPress user');
cx('xapi:abc-123'===($event['source_event_id']??''),'xAPI statement ID becomes stable source-event identity');
cx('course'===($event['source_object']['type']??'')&&'course-42'===($event['source_object']['id']??''),'normalized context retains only stable Coursera course identity');
cx(100.0===(float)($event['certificate_threshold']??0)&&!empty($event['attempts'][0]['completed']),'Coursera completion becomes exact 100 percent eligibility evidence');
cx(!isset($event['email'])&&!isset($event['learner_name'])&&!isset($event['provider_payload']),'adapter retains no arbitrary Coursera learner payload');
cx(cxerr($adapter->normalize_event(array('statement_id'=>'','course_id'=>'course-42','recipient_id'=>18,'completed_at'=>'2026-08-31T10:30:00Z')),'certloom_coursera_statement_invalid'),'missing xAPI statement ID fails closed');
cx(cxerr($adapter->normalize_event(array('statement_id'=>'abc','course_id'=>'bad course','recipient_id'=>18,'completed_at'=>'2026-08-31T10:30:00Z')),'certloom_coursera_course_invalid'),'unsafe course ID fails closed');
cx(cxerr($adapter->normalize_event(array('statement_id'=>'abc','course_id'=>'course-42','recipient_id'=>0,'completed_at'=>'2026-08-31T10:30:00Z')),'certloom_coursera_recipient_invalid'),'missing explicit Recipient fails closed');
cx(cxerr($adapter->normalize_event(array('statement_id'=>'abc','course_id'=>'course-42','recipient_id'=>18,'completed_at'=>'bad')),'certloom_coursera_completion_time_invalid'),'invalid completion time fails closed');
$context=IntegrationEventContext::from_array($event);
cx(!is_wp_error($context),'Coursera adapter output enters generic IntegrationEventContext');
cx(18===$context->recipient_id()&&0===$context->wp_user_id(),'generic context preserves explicit Recipient-only identity');
cx($context->is_eligible()&&'course-42'===$context->source_object_id(),'Coursera context is eligible and provider-object stable');
$arr=$context->to_array();cx(isset($arr['recipient_id'])&&!isset($arr['email']),'normalized context persists Recipient ID but no email identity');

$creds=CourseraXapiCredentials::status();cx(empty($creds['configured']),'Coursera xAPI connection begins disconnected');
cx(false!==strpos($creds['token_url'],'/certloom/v1/integrations/coursera/token'),'status exposes site-local OAuth token URL');
cx(false!==strpos($creds['statement_url'],'/certloom/v1/integrations/coursera/xapi/statements'),'status exposes site-local xAPI statement URL');
$rot=CourseraXapiCredentials::rotate();cx(is_array($rot)&&preg_match('/^clm_[a-f0-9]{24}$/',$rot['client_id']??'')&&64===strlen($rot['client_secret']??''),'rotation creates high-entropy client credentials');
$stored=get_option(CourseraXapiCredentials::OPTION,array());cx(!isset($stored['client_secret'])&&64===strlen($stored['client_secret_sha256']??''),'raw client secret is never stored');
cx(!empty(CourseraXapiCredentials::status()['configured']),'rotated Coursera connection reports configured');
$bad=CourseraXapiCredentials::issue_token($rot['client_id'],'wrong');cx(cxerr($bad,'certloom_coursera_client_invalid'),'wrong Coursera client secret fails closed');
$token=CourseraXapiCredentials::issue_token($rot['client_id'],$rot['client_secret']);cx(is_array($token)&&64===strlen($token['access_token']??'')&&'Bearer'===($token['token_type']??''),'valid client credentials issue bounded bearer token');
$stored=get_option(CourseraXapiCredentials::OPTION,array());cx(!isset($stored['access_token'])&&64===strlen($stored['token_sha256']??''),'raw bearer token is never stored');
cx(CourseraXapiCredentials::verify_bearer($token['access_token']),'issued bearer token authenticates');
cx(!CourseraXapiCredentials::verify_bearer('wrong-token'),'wrong bearer token fails closed');
cx(true===CourseraXapiCredentials::disconnect()&&empty(CourseraXapiCredentials::status()['configured']),'disconnect removes Coursera connection state');

$store=new CourseraMappingStore();cx(true===$store->save(array('course_id'=>'course-42','program_id'=>7,'template_id'=>9,'active'=>true)),'Coursera course mapping persists');
$found=$store->find('course-42');cx(is_array($found)&&7===($found['program_id']??0)&&9===($found['template_id']??0),'Coursera mapping round-trips Program/design');
$resolved=$store->resolve_mapping($context);cx(is_array($resolved)&&'coursera'===($resolved['adapter']??'')&&'course-42'===($resolved['source_object_id']??''),'Coursera mapping resolves into generic mapping contract');
cx(cxerr($store->save(array('course_id'=>'bad course','program_id'=>7,'template_id'=>9)),'certloom_coursera_mapping_target_invalid'),'unsafe Coursera course mapping fails closed');
cx(cxerr($store->save(array('course_id'=>'course-x','program_id'=>0,'template_id'=>9)),'certloom_coursera_mapping_target_invalid'),'mapping requires Program');
cx(cxerr($store->save(array('course_id'=>'course-x','program_id'=>7,'template_id'=>0)),'certloom_coursera_mapping_target_invalid'),'mapping requires published design target');
cx(true===$store->delete('course-42')&&null===$store->find('course-42'),'Coursera mapping deletes cleanly');

$statement=array(
 'id'=>'stmt-100', 'timestamp'=>'2026-08-31T10:30:00Z',
 'actor'=>array('objectType'=>'Agent','mbox'=>'mailto:ignored@example.test'),
 'verb'=>array('id'=>'http://adlnet.gov/expapi/verbs/completed','display'=>array('en-US'=>'COMPLETED')),
 'object'=>array('id'=>'https://coursera.org/course/course-42','definition'=>array('type'=>'http://adlnet.gov/expapi/activities/course'),'objectType'=>'Activity'),
 'result'=>array('success'=>true,'completion'=>true,'score'=>array('scaled'=>1)),
 'context'=>array(CourseraXapiBridge::COURSE_ID_KEY=>'course-42',CourseraXapiBridge::EXTERNAL_ID_KEY=>'student-2026-001')
);
$ref=new ReflectionClass(CourseraXapiBridge::class);$method=$ref->getMethod('parse_statement');$method->setAccessible(true);$parsed=$method->invoke(null,$statement);
cx(!is_wp_error($parsed),'official-shape Standard xAPI completion parses');
cx('course-42'===($parsed['course_id']??'')&&'student-2026-001'===($parsed['external_id']??''),'parser requires Coursera courseId and External ID extensions');
cx(64===strlen($parsed['statement_sha256']??''),'parser retains only a statement fingerprint rather than raw payload evidence');
$progress=$statement;$progress['verb']['id']='http://adlnet.gov/expapi/verbs/progressed';$progress['verb']['display']['en-US']='PROGRESSED';$progress['result']['completion']=false;cx(cxerr($method->invoke(null,$progress),'certloom_coursera_not_completion'),'progress events cannot issue credentials');
$failed=$statement;$failed['result']['success']=false;cx(cxerr($method->invoke(null,$failed),'certloom_coursera_not_eligible'),'unsuccessful completion fails closed');
$item=$statement;$item['object']['definition']['type']='http://adlnet.gov/expapi/activities/item';cx(cxerr($method->invoke(null,$item),'certloom_coursera_object_invalid'),'item-level xAPI activity cannot issue a course credential');
$noext=$statement;unset($noext['context'][CourseraXapiBridge::EXTERNAL_ID_KEY]);cx(cxerr($method->invoke(null,$noext),'certloom_coursera_external_id_missing'),'missing External ID fails closed rather than falling back to actor email');

$root=dirname(__DIR__);$bridge=file_get_contents($root.'/src/Infrastructure/Integration/CourseraXapiBridge.php');$rest=file_get_contents($root.'/src/Infrastructure/Integration/CourseraXapiRest.php');$admin=file_get_contents($root.'/src/Infrastructure/Admin/CourseraIntegrationAdmin.php');$recipient=file_get_contents($root.'/src/Infrastructure/Repository/RecipientRepository.php');$plugin=file_get_contents($root.'/certloom.php');$view=file_get_contents($root.'/admin/views/integrations.php');$uninstall=file_get_contents($root.'/uninstall.php');
cx(false!==strpos($bridge,'find_by_external_id_exact')&&false===stripos($bridge,'mbox'),'runtime resolves explicit Recipient External ID and never actor mbox/email');
cx(false!==strpos($recipient,'certloom_recipient_external_ambiguous')&&false!==strpos($recipient,'LIMIT 2'),'duplicate Recipient external IDs fail closed instead of choosing one');
cx(false!==strpos($rest,"'/integrations/coursera/token'")&&false!==strpos($rest,"'/integrations/coursera/xapi/statements'"),'Coursera registers bounded OAuth token and statement endpoints');
cx(false!==strpos($rest,"'grant_type'")&&false!==strpos($rest,"'client_credentials'"),'token endpoint accepts only OAuth client-credentials grant');
cx(false!==strpos($admin,'CAP_MANAGE_INTEGRATIONS')&&false!==strpos($admin,'check_admin_referer'),'Coursera admin mutations require integration capability and nonces');
cx(false!==strpos($admin,'find_published')&&false!==strpos($admin,'TemplateFieldCompatibility'),'Coursera mapping validates published design and Program field compatibility');
cx(false!==strpos($admin,'one_time_secret')&&false!==strpos($admin,'delete_transient'),'Coursera client secret is shown through one-time user-bound handoff');
cx(false!==strpos($view,"'Your Client Secret is shown once'")&&false!==strpos($view,"'Learner identity must be explicit'"),'Coursera setup explains secret and Recipient identity boundaries in human language');
cx(false!==strpos($view,"'Publishing destinations'")&&false!==strpos($view,"'Credly'"),'Credly is visibly separated from eligibility sources as a destination');
cx(false!==strpos($plugin,'CourseraXapiRest::register()')&&false!==strpos($plugin,'CourseraIntegrationAdmin()'),'plugin boot registers Coursera runtime and admin boundaries');
cx(false===strpos($bridge,'Ecapla_CertVerify_Database')&&false===strpos($bridge,'get_user_by'),'Coursera has no alternate credential database path or identity guessing');
cx(!CourseraXapiBridge::authority_ready(),'Coursera automatic credential writes remain closed when normalized authority is closed');
cx(!file_exists($root.'/resources/domain/accepted.json')&&!file_exists($root.'/resources/renderer/accepted.json'),'Coursera implementation manufactures no production authority manifests');
cx(false!==strpos($uninstall,"'certloom_coursera_last_result'")&&false!==strpos($uninstall,"'certloom_coursera_mappings_v1'")&&false!==strpos($uninstall,"'certloom_coursera_xapi_credentials_v1'"),'uninstall removes Coursera credentials, mappings and operational evidence');

echo "\n$pass UX-CORE-02 Coursera checks, $fail failures\n";exit($fail?1:0);
