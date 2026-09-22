<?php
/** Infrastructure-level tests for stale worker-lease recovery/exhaustion. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code, $message;
		public function __construct( $code = '', $message = '' ) { $this->code=$code; $this->message=$message; }
		public function get_error_code(){return $this->code;}
		public function get_error_message(){return $this->message;}
	}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function current_time($type,$gmt=false){return '2026-08-22 00:00:00';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}

final class JobRecoveryWpdb {
	public $prefix='wp_'; public $last_error='';
	public $rows=array();
	public function prepare($query,...$args){return array('query'=>$query,'args'=>$args);}
	public function get_results($prepared,$format){
		$this->last_error='';$q=$prepared['query'];$a=$prepared['args'];
		if(strpos($q,'SELECT job_key, job_type, payload_json, recovery_count')!==0)return array();
		$status=$a[1];$now=strtotime($a[2]);$limit=(int)$a[3];$out=array();
		foreach($this->rows as $row){
			$locked=empty($row['locked_until'])?0:strtotime($row['locked_until']);
			if($row['status']===$status&&(!$locked||$locked<$now)){$out[]=array('job_key'=>$row['job_key'],'job_type'=>$row['job_type']??'generic_test','payload_json'=>$row['payload_json']??'{}','recovery_count'=>$row['recovery_count']);if(count($out)>=$limit)break;}
		}
		return $out;
	}
	public function query($prepared){
		$this->last_error='';$q=$prepared['query'];$a=$prepared['args'];
		if(strpos($q,'UPDATE')!==0)return 0;
		$is_failed=strpos($q,'error_json')!==false;
		if($is_failed){$new_status=$a[1];$error=$a[2];$job_key=$a[5];$expected=$a[6];$now=strtotime($a[7]);}
		else{$new_status=$a[1];$job_key=$a[4];$expected=$a[5];$now=strtotime($a[6]);}
		if(!isset($this->rows[$job_key]))return 0;$row=&$this->rows[$job_key];$locked=empty($row['locked_until'])?0:strtotime($row['locked_until']);
		if($row['status']!==$expected||($locked&&$locked>=$now))return 0;
		$row['status']=$new_status;$row['recovery_count']++;$row['locked_by']=null;$row['locked_until']=null;
		if($is_failed){$row['error_json']=$error;$row['finished_at']='2026-08-22 00:00:00';}else{$row['available_at']='2026-08-22 00:00:00';}
		return 1;
	}
}
$GLOBALS['wpdb']=new JobRecoveryWpdb();
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Domain\Job\JobStates;
use Certloom\Infrastructure\Job\JobRepository;

function jrr_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}
$stale=str_repeat('a',32);$exhausted=str_repeat('b',32);$live=str_repeat('c',32);
$GLOBALS['wpdb']->rows=array(
	$stale=>array('job_key'=>$stale,'status'=>JobStates::RUNNING,'recovery_count'=>0,'locked_by'=>'w','locked_until'=>'2026-08-21 23:59:00'),
	$exhausted=>array('job_key'=>$exhausted,'status'=>JobStates::RUNNING,'recovery_count'=>4,'locked_by'=>'w','locked_until'=>'2026-08-21 23:59:00'),
	$live=>array('job_key'=>$live,'status'=>JobStates::RUNNING,'recovery_count'=>0,'locked_by'=>'w','locked_until'=>'2026-08-22 00:05:00'),
);
$result=(new JobRepository())->recover_stale_running(20);jrr_same(2,$result,'two stale handled');
jrr_same(JobStates::QUEUED,$GLOBALS['wpdb']->rows[$stale]['status'],'first stale requeued');jrr_same(1,$GLOBALS['wpdb']->rows[$stale]['recovery_count'],'recovery increment');
jrr_same(JobStates::FAILED,$GLOBALS['wpdb']->rows[$exhausted]['status'],'repeated stale failed');jrr_same(5,$GLOBALS['wpdb']->rows[$exhausted]['recovery_count'],'exhaustion count');
$error=json_decode($GLOBALS['wpdb']->rows[$exhausted]['error_json'],true);jrr_same('certloom_job_worker_recovery_exhausted',$error['code'],'explicit exhaustion reason');
jrr_same(JobStates::RUNNING,$GLOBALS['wpdb']->rows[$live]['status'],'live lease untouched');
echo "PASS  stale leases requeue safely while repeated worker crashes fail explicitly without confusing healthy slice attempts\n";
echo "\n1 tests, 0 failures\n";
