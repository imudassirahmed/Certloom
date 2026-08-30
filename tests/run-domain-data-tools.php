<?php
/** Data-tools coordinator tests for two-step restore review/commit. */
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_file_name($v){return preg_replace('/[^A-Za-z0-9._-]/','-',basename((string)$v));}
function trailingslashit($v){return rtrim((string)$v,'/\\').'/';}
function untrailingslashit($v){return rtrim((string)$v,'/\\');}
function wp_normalize_path($v){return str_replace('\\','/',(string)$v);}
function wp_mkdir_p($v){return is_dir($v)||mkdir($v,0700,true);}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
$GLOBALS['dt_options']=array(
	'certloom_domain_data_generation'=>'g1',
	'certverify_fields'=>array(),
	'certverify_retired_fields'=>array(),
	'certverify_settings'=>array('verification_page'=>10),
);
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['dt_options'])?$GLOBALS['dt_options'][$k]:$d;}
$dt_private_dir=sys_get_temp_dir().'/certloom-data-tools-'.bin2hex(random_bytes(4));
function apply_filters($name,$value){global $dt_private_dir;return 'certloom_private_job_directory'===$name?$dt_private_dir:$value;}
require_once __DIR__.'/bootstrap-autoload.php';

use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Contract\JobSchedulerInterface;
use Certloom\Application\DataTools\DomainDataToolsCoordinator;
use Certloom\Application\Job\JobQueue;
use Certloom\Application\Job\JobService;
use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

final class DTRepo implements JobRepositoryInterface{
	public $jobs=array(); private $next=1;
	public function create(array $d){$key=str_pad(dechex($this->next++),32,'0',STR_PAD_LEFT);$this->jobs[$key]=array('job_key'=>$key,'job_type'=>$d['job_type'],'status'=>JobStates::QUEUED,'owner_user_id'=>$d['owner_user_id'],'payload'=>$d['payload'],'result'=>array(),'parent_job_key'=>$d['parent_job_key']??null);return $key;}
	public function find($k){return $this->jobs[$k]??null;}
	public function claim($a,$b,$c){return new WP_Error('unused','unused');}
	public function save_progress($a,$b,array $c){return true;}
	public function find_due_queued($l=20){return array();}
	public function recover_stale_running($l=20){return 0;}
	public function transition($a,$b,$c,array $d=array(),$e=null){return true;}
}
final class DTScheduler implements JobSchedulerInterface{public $scheduled=array();public function schedule($k,$d=1){$this->scheduled[]=$k;return true;}}
function dt_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}

$repo=new DTRepo();$scheduler=new DTScheduler();$queue=new JobQueue(new JobService($repo),$scheduler);$files=new PrivateJobFileStore();$coordinator=new DomainDataToolsCoordinator($queue,$repo,$files);
$source=tempnam(sys_get_temp_dir(),'certloom-restore-');file_put_contents($source,"test backup placeholder\n");
$no=$coordinator->enqueue_restore_review($source,'safe.cvbackup',7,'merge',false);dt_same('certloom_domain_restore_review_intent_required',$no->get_error_code(),'review confirmation');
$review=$coordinator->enqueue_restore_review($source,'safe.cvbackup',7,'merge',true);if(is_wp_error($review))throw new RuntimeException($review->get_error_message());
$review_job=$repo->find($review);dt_same(JobTypes::DOMAIN_RESTORE_PREFLIGHT,$review_job['job_type'],'preflight type');dt_same('merge',$review_job['payload']['mode'],'mode');dt_same(true,$files->owns($review_job['payload']['path']),'private restore source');
echo "PASS  restore upload queues only a private non-destructive review job\n";

$config=(new DomainBackupRepository())->configuration_snapshot();
$repo->jobs[$review]['status']=JobStates::SUCCEEDED;
$repo->jobs[$review]['result']=array('ready_for_commit'=>true,'mode'=>'merge','baseline_generation'=>'g1','baseline_configuration_hash'=>$config['hash'],'merge_counts'=>array('inserted'=>3,'existing'=>2,'skipped'=>1));
$commit=$coordinator->enqueue_restore_commit($review,7,true);if(is_wp_error($commit))throw new RuntimeException($commit->get_error_message());
$commit_job=$repo->find($commit);dt_same(JobTypes::DOMAIN_RESTORE_MERGE,$commit_job['job_type'],'commit type');dt_same($review,$commit_job['parent_job_key'],'parent review retained');dt_same(true,$commit_job['payload']['confirmed_merge'],'explicit final confirmation');
echo "PASS  reviewed restore creates a separate child commit job with the reviewed live-data snapshot\n";

$GLOBALS['dt_options']['certloom_domain_data_generation']='g2';
$stale=$coordinator->enqueue_restore_commit($review,7,true);dt_same('certloom_domain_restore_review_stale',$stale->get_error_code(),'stale review');
echo "PASS  final restore commit refuses a review after credential data changes\n";

foreach($repo->jobs as $job){$path=(string)($job['payload']['path']??'');if($files->owns($path)&&file_exists($path))$files->delete($path);}
@unlink($source);foreach(glob($dt_private_dir.'/*')?:array() as $f){@unlink($f);}@rmdir($dt_private_dir);
echo "\n3 tests, 0 failures\n";
