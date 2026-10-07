<?php
/** Pure tests for WordPress-local job scheduling, watchdog recovery and continuation. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'MINUTE_IN_SECONDS', 60 );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code, $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $t ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ); }
$GLOBALS['jd_events'] = array();
$GLOBALS['jd_actions'] = array();
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['jd_actions'][ $hook ] = $callback; }
function add_filter( $hook, $callback ) { return true; }
function wp_next_scheduled( $hook, $args = array() ) {
	$key = $hook . '|' . json_encode( array_values( $args ) );
	return isset( $GLOBALS['jd_events'][ $key ] ) ? $GLOBALS['jd_events'][ $key ]['time'] : false;
}
function wp_schedule_single_event( $time, $hook, $args = array(), $wp_error = false ) {
	$key = $hook . '|' . json_encode( array_values( $args ) );
	$GLOBALS['jd_events'][ $key ] = array( 'time' => $time, 'hook' => $hook, 'args' => array_values( $args ), 'recurrence' => null );
	return true;
}
function wp_schedule_event( $time, $recurrence, $hook, $args = array(), $wp_error = false ) {
	$key = $hook . '|' . json_encode( array_values( $args ) );
	$GLOBALS['jd_events'][ $key ] = array( 'time' => $time, 'hook' => $hook, 'args' => array_values( $args ), 'recurrence' => $recurrence );
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	foreach ( array_keys( $GLOBALS['jd_events'] ) as $key ) {
		if ( $GLOBALS['jd_events'][ $key ]['hook'] === $hook ) unset( $GLOBALS['jd_events'][ $key ] );
	}
	return true;
}

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Job\JobRunner;
use Certloom\Application\Job\JobService;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Domain\Job\JobStates;
use Certloom\Infrastructure\Job\JobDispatcher;

final class JDRepo implements JobRepositoryInterface {
	public $jobs = array();
	public $recover_calls = 0;
	private $next = 1;
	public function create( array $data ) {
		$key = str_pad( dechex( $this->next++ ), 32, '0', STR_PAD_LEFT );
		$this->jobs[ $key ] = array( 'job_key'=>$key, 'job_type'=>$data['job_type'], 'status'=>JobStates::QUEUED, 'owner_user_id'=>0, 'cursor'=>0, 'phase'=>null, 'total'=>null, 'selection_max_id'=>null, 'payload'=>array(), 'result'=>array(), 'error'=>array(), 'attempts'=>0, 'locked_by'=>null );
		return $key;
	}
	public function find( $key ) { return $this->jobs[ $key ] ?? null; }
	public function claim( $key, $worker, $lock_seconds ) {
		if ( empty( $this->jobs[ $key ] ) || JobStates::QUEUED !== $this->jobs[ $key ]['status'] ) return new WP_Error( 'certloom_job_not_claimable', 'not claimable' );
		$this->jobs[ $key ]['status'] = JobStates::RUNNING; $this->jobs[ $key ]['locked_by'] = $worker; $this->jobs[ $key ]['attempts']++;
		return $this->jobs[ $key ];
	}
	public function save_progress( $key, $worker, array $progress ) {
		if ( $this->jobs[ $key ]['locked_by'] !== $worker ) return new WP_Error( 'conflict', 'conflict' );
		$this->jobs[ $key ]['cursor']=$progress['cursor']; $this->jobs[ $key ]['phase']=$progress['phase']; $this->jobs[ $key ]['result']=$progress['result']; return true;
	}
	public function find_due_queued( $limit = 20 ) { return array_values( array_filter( $this->jobs, static fn( $j ) => JobStates::QUEUED === $j['status'] ) ); }
	public function recover_stale_running( $limit = 20 ) { $this->recover_calls++; return 1; }
	public function transition( $key, $from, $to, array $changes = array(), $worker = null ) {
		if ( $this->jobs[$key]['status'] !== $from || ( null !== $worker && $this->jobs[$key]['locked_by'] !== $worker ) ) return new WP_Error( 'conflict', 'conflict' );
		$this->jobs[$key]['status']=$to; $this->jobs[$key]['locked_by']=null;
		if ( array_key_exists('result',$changes) ) $this->jobs[$key]['result']=$changes['result'];
		if ( array_key_exists('error',$changes) ) $this->jobs[$key]['error']=$changes['error'];
		return true;
	}
}
final class JDHandler implements JobHandlerInterface {
	public $calls = 0;
	public function handle( array $job, $worker_token ) {
		$this->calls++;
		return 1 === $this->calls ? JobSliceResult::progress( 1, 'slice', array('n'=>1) ) : JobSliceResult::succeeded( array('n'=>2), 2 );
	}
}
function jd_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}

$repo = new JDRepo(); $service = new JobService($repo); $handler = new JDHandler();
$runner = new JobRunner($service,$repo,array('demo'=>$handler)); $dispatcher = new JobDispatcher($runner,$repo);
$key = $service->create('demo');
jd_same(true,$dispatcher->schedule($key),'first schedule');
jd_same(true,$dispatcher->schedule($key),'dedupe schedule');
$run_key = JobDispatcher::RUN_HOOK . '|' . json_encode(array($key));
jd_same(true,isset($GLOBALS['jd_events'][$run_key]),'run event exists');
jd_same(1,count(array_filter($GLOBALS['jd_events'],static fn($e)=>JobDispatcher::RUN_HOOK===$e['hook'])),'one run event');
echo "PASS  per-job scheduling is idempotent\n";

// Simulate WordPress consuming the current one-shot event before callback execution.
unset($GLOBALS['jd_events'][$run_key]);
$dispatcher->run_scheduled($key);
jd_same(JobStates::QUEUED,$repo->find($key)['status'],'progress yielded');
jd_same(true,isset($GLOBALS['jd_events'][$run_key]),'continuation scheduled');
unset($GLOBALS['jd_events'][$run_key]);
$dispatcher->run_scheduled($key);
jd_same(JobStates::SUCCEEDED,$repo->find($key)['status'],'second slice succeeds');
jd_same(false,isset($GLOBALS['jd_events'][$run_key]),'terminal does not reschedule');
echo "PASS  queued slices reschedule and terminal jobs stop scheduling\n";

$queued = $service->create('demo');
$dispatcher->watchdog();
jd_same(1,$repo->recover_calls,'stale recovery invoked');
$queued_key = JobDispatcher::RUN_HOOK . '|' . json_encode(array($queued));
jd_same(true,isset($GLOBALS['jd_events'][$queued_key]),'watchdog schedules due queue');
echo "PASS  watchdog recovers stale leases and reschedules due queued work\n";

$schedules = JobDispatcher::cron_schedules(array());
jd_same(300,$schedules[JobDispatcher::WATCHDOG_SCHEDULE]['interval'],'watchdog interval');
JobDispatcher::deactivate();
jd_same(0,count($GLOBALS['jd_events']),'deactivation clears domain job events');
echo "PASS  watchdog cadence is explicit and deactivation clears all domain-job events\n";

echo "\n4 tests, 0 failures\n";
