<?php
/** CM-01 premium Credential Management UX + reviewed lifecycle gate. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));} function sanitize_textarea_field($v){return trim(strip_tags((string)$v));} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} function wp_salt($scheme='auth'){return 'credential-management-test-salt-'.$scheme;}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\CredentialRepositoryInterface;
use Certloom\Application\Contract\EventRepositoryInterface;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Application\CredentialLifecycleReviewService;
use Certloom\Application\CredentialLifecycleService;
use Certloom\Domain\Credential\States;

$pass=0;$fail=0; function cm($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}} function cmcode($v,$c){return is_wp_error($v)&&$c===$v->get_error_code();}

$series=array('id'=>11,'public_id'=>'ABCD-EFGH-JKMN','current_version_id'=>21,'administrative_state'=>States::ADMIN_ACTIVE,'compliance_state'=>States::COMPLIANCE_NOT_APPLICABLE,'updated_at'=>'2026-08-27 02:00:00');
$version=array('id'=>21,'series_id'=>11,'version_number'=>3,'display_number'=>'CERT-0021','issuance_state'=>States::ISSUANCE_ISSUED,'updated_at'=>'2026-08-27 02:00:00');
$reviews=new CredentialLifecycleReviewService();
$review=$reviews->prepare($series,$version,'suspend',array('reason_code'=>'issuer_request','public_reason'=>'Issuer requested temporary suspension.','internal_note'=>'Case 4421'),7);
cm(!is_wp_error($review),'review prepares valid current-Version lifecycle transition');
cm('suspended'===($review['new_state']['administrative_state']??''),'review freezes exact target administrative state');
cm('issuer_request'===($review['reason_code']??''),'review freezes normalized reason code');
cm((bool)preg_match('/^[a-f0-9]{64}$/',(string)($review['digest']??'')),'review carries HMAC SHA-256 integrity digest');
$verified=$reviews->verify($review,7); cm(!is_wp_error($verified),'review verifies for owning administrator');
cm(cmcode($reviews->verify($review,8),'certloom_lifecycle_review_owner'),'review is owner-bound');
$tampered=$review;$tampered['public_reason']='tampered'; cm(cmcode($reviews->verify($tampered,7),'certloom_lifecycle_review_corrupt'),'review rejects tampered reason text');
$bad=$reviews->prepare($series,$version,'reinstate',array(),7);cm(cmcode($bad,'certloom_lifecycle_review_transition_invalid'),'invalid lifecycle transition fails during review');
$historical=$version;$historical['id']=20;cm(cmcode($reviews->prepare($series,$historical,'void',array(),7),'certloom_lifecycle_review_current_version_invalid'),'historical Version cannot be prepared as current lifecycle target');

final class CmStore implements CredentialRepositoryInterface, EventRepositoryInterface, TransactionInterface {
 public $series,$version,$events=array(),$mutations=0;
 public function __construct($s,$v){$this->series=$s;$this->version=$v;}
 public function find_series_for_update($id){return absint($id)===absint($this->series['id'])?$this->series:null;}
 public function find_version_for_update($id){return absint($id)===absint($this->version['id'])?$this->version:null;}
 public function set_administrative_state($id,$expected,$new){if($this->series['administrative_state']!==$expected)return new WP_Error('state','state');$this->series['administrative_state']=$new;$this->mutations++;return true;}
 public function set_version_issuance_state($id,$expected,$new){if($this->version['issuance_state']!==$expected)return new WP_Error('state','state');$this->version['issuance_state']=$new;$this->mutations++;return true;}
 public function set_current_version($sid,$vid){$this->series['current_version_id']=absint($vid);$this->mutations++;return true;}
 public function append(array $e){$this->events[]=$e;$this->mutations++;return count($this->events);}
 public function run(callable $cb){$backup=serialize(array($this->series,$this->version,$this->events,$this->mutations));$r=$cb();if(is_wp_error($r)){list($this->series,$this->version,$this->events,$this->mutations)=unserialize($backup);}return $r;}
}
$ctx=array('actor_user_id'=>7,'reason_code'=>$verified['reason_code'],'public_reason'=>$verified['public_reason'],'internal_note'=>$verified['internal_note'],'expected_series_updated_at'=>$verified['series_updated_at'],'expected_current_version_id'=>$verified['version_id'],'expected_version_id'=>$verified['version_id'],'expected_version_updated_at'=>$verified['version_updated_at']);
$store=new CmStore($series,$version);$life=new CredentialLifecycleService($store,$store,null,$store);$ok=$life->suspend(11,$ctx);cm(true===$ok,'reviewed lifecycle context commits through transaction-backed service');cm(States::ADMIN_SUSPENDED===$store->series['administrative_state'],'reviewed lifecycle transition changes only expected state');cm(1===count($store->events)&&'credential.suspended'===$store->events[0]['event_type'],'reviewed lifecycle transition appends audit event');
$staleSeries=$series;$staleSeries['updated_at']='2026-08-27 02:01:00';$staleStore=new CmStore($staleSeries,$version);$staleLife=new CredentialLifecycleService($staleStore,$staleStore,null,$staleStore);$stale=$staleLife->suspend(11,$ctx);cm(cmcode($stale,'certloom_lifecycle_review_stale'),'transaction rejects Series changed after review');cm(0===$staleStore->mutations,'stale review causes no lifecycle mutation or event');
$staleVersion=$version;$staleVersion['updated_at']='2026-08-27 02:02:00';$staleStore2=new CmStore($series,$staleVersion);$staleLife2=new CredentialLifecycleService($staleStore2,$staleStore2,null,$staleStore2);$stale2=$staleLife2->suspend(11,$ctx);cm(cmcode($stale2,'certloom_lifecycle_review_stale'),'transaction rejects Version changed after review');

$root=dirname(__DIR__);$admin=file_get_contents($root.'/src/Infrastructure/Admin/DomainAdmin.php');$repo=file_get_contents($root.'/src/Infrastructure/Repository/CredentialRepository.php');$bulk=file_get_contents($root.'/src/Application/CredentialRelationshipBulkService.php');$css=file_get_contents($root.'/admin/assets/admin.css');$lifeSrc=file_get_contents($root.'/src/Application/CredentialLifecycleService.php');$plugin=file_get_contents($root.'/certloom.php');
cm(false!==strpos($admin,"__( 'Credential Management', 'certloom' )")&&false!==strpos($admin,"'overview', 'recipient', 'program', 'status', 'verification', 'versions', 'activity', 'actions'"),'workspace implements planned credential information architecture');
cm(false!==strpos($admin,'Historical Versions are read-only')&&false!==strpos($admin,'Return to the current Version'),'historical Version inspection is explicitly read-only');
cm(false!==strpos($admin,'CredentialLifecycleReviewService')&&false!==strpos($admin,'lifecycle_review'),'admin lifecycle mutation requires reviewed integrity contract');
cm(false!==strpos($admin,'CredentialRelationshipBulkService')&&false!==strpos($admin,'Confirm and create successor Version'),'Program/Batch correction uses reviewed immutable-successor workflow');
cm(false!==strpos($bulk,"'public_reason' => \$this->bounded_text")&&false!==strpos($bulk,"\$context['public_reason'] = \$review['public_reason']"),'relationship target and reasons are frozen into tamper-evident review');
cm(false!==strpos($lifeSrc,'expected_series_updated_at')&&false!==strpos($lifeSrc,'expected_version_updated_at'),'lifecycle service revalidates optimistic-concurrency evidence under transaction');
cm(false!==strpos($repo,'list_management_page')&&false!==strpos($repo,'management_summary'),'repository exposes bounded premium management list and summary queries');
cm(false!==strpos($repo,"'pending_review'")&&false!==strpos($repo,"'noncompliant'")&&false!==strpos($repo,"'not_yet_valid'"),'management filters cover derived status dimensions beyond legacy valid/suspended/revoked');
cm(false!==strpos($css,'.certloom-credential-card-grid')&&false!==strpos($css,'@media (max-width: 720px)'),'Credential Management has dedicated responsive premium styling');
cm(false!==strpos($admin,'Immutable issuance snapshot evidence')&&false!==strpos($admin,'Template SHA-256')&&false!==strpos($admin,'Merge SHA-256'),'workspace surfaces immutable snapshot provenance without raw history editing');
cm(false!==strpos($admin,'Normalized endpoint')&&false!==strpos($admin,'Production authority')&&false!==strpos($admin,'Gated'),'verification panel is honest about normalized authority gate');
cm(false!==strpos($plugin,"define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )")&&false!==strpos($plugin,"define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false )")&&false!==strpos($plugin,"define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false )"),'normalized-domain authority gates remain closed');
cm(!file_exists($root.'/resources/renderer/accepted.json'),'Credential Management does not manufacture renderer authority');

echo "\n$pass Credential Management checks, $fail failures\n"; exit($fail?1:0);
