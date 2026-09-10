<?php
/** Pure tests for one-slice job runner/handler orchestration. */
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
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Job\JobRunner;
use Certloom\Application\Job\JobService;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Domain\Job\JobStates;

final class JRRepo implements JobRepositoryInterface {
	public $jobs = array();
	private $next = 1;

	public function create( array $data ) {
		$key = str_pad( dechex( $this->next++ ), 32, '0', STR_PAD_LEFT );
		$this->jobs[ $key ] = array(
			'job_key' => $key,
			'job_type' => $data['job_type'],
			'status' => JobStates::QUEUED,
			'owner_user_id' => $data['owner_user_id'],
			'cursor' => 0,
			'phase' => null,
			'total' => $data['total'],
			'selection_max_id' => $data['selection_max_id'],
			'payload' => $data['payload'],
			'result' => array(),
			'error' => array(),
			'attempts' => 0,
			'locked_by' => null,
		);
		return $key;
	}

	public function find( $key ) { return $this->jobs[ $key ] ?? null; }

	public function claim( $key, $worker, $lock_seconds ) {
		if ( empty( $this->jobs[ $key ] ) || JobStates::QUEUED !== $this->jobs[ $key ]['status'] ) {
			return new WP_Error( 'not_claimable', 'x' );
		}
		$this->jobs[ $key ]['status'] = JobStates::RUNNING;
		$this->jobs[ $key ]['locked_by'] = $worker;
		$this->jobs[ $key ]['attempts']++;
		return $this->jobs[ $key ];
	}

	public function save_progress( $key, $worker, array $progress ) {
		if ( $this->jobs[ $key ]['locked_by'] !== $worker ) return new WP_Error( 'conflict', 'x' );
		$this->jobs[ $key ]['cursor'] = $progress['cursor'];
		$this->jobs[ $key ]['phase'] = $progress['phase'];
		$this->jobs[ $key ]['result'] = $progress['result'];
		return true;
	}

	public function find_due_queued( $limit = 20 ) {
		return array_values( array_filter( $this->jobs, static fn( $job ) => JobStates::QUEUED === $job['status'] ) );
	}

	public function recover_stale_running( $limit = 20 ) { return 0; }

	public function transition( $key, $from, $to, array $changes = array(), $worker = null ) {
		if ( $this->jobs[ $key ]['status'] !== $from || ( null !== $worker && $this->jobs[ $key ]['locked_by'] !== $worker ) ) {
			return new WP_Error( 'conflict', 'x' );
		}
		$this->jobs[ $key ]['status'] = $to;
		$this->jobs[ $key ]['locked_by'] = null;
		if ( array_key_exists( 'result', $changes ) ) $this->jobs[ $key ]['result'] = $changes['result'];
		if ( array_key_exists( 'error', $changes ) ) $this->jobs[ $key ]['error'] = $changes['error'];
		return true;
	}
}

final class JRHandler implements JobHandlerInterface {
	public $calls = 0;
	public function handle( array $job, $worker ) {
		$this->calls++;
		return 1 === $this->calls
			? JobSliceResult::progress( 25, 'scan', array( 'seen' => 25 ) )
			: JobSliceResult::succeeded( array( 'seen' => 50 ), 50 );
	}
}

function jr_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

$repo = new JRRepo();
$service = new JobService( $repo );
$handler = new JRHandler();
$runner = new JobRunner( $service, $repo, array( 'backup' => $handler ) );
$key = $service->create( 'backup' );
$worker = str_repeat( 'a', 32 );
$one = $runner->run( $key, $worker );
jr_same( 'queued', $one['status'], 'yielded' );
jr_same( JobStates::QUEUED, $repo->find( $key )['status'], 'repository queued' );
jr_same( 25, $repo->find( $key )['cursor'], 'cursor persisted' );
jr_same( null, $repo->find( $key )['locked_by'], 'lock released' );
echo "PASS  progress slice persists cursor then yields job back to queue\n";

$two = $runner->run( $key, $worker );
jr_same( 'succeeded', $two['status'], 'succeeded' );
jr_same( JobStates::SUCCEEDED, $repo->find( $key )['status'], 'terminal' );
jr_same( 2, $handler->calls, 'two bounded slices' );
echo "PASS  next worker slice resumes queued job and reaches terminal success\n";

$missing = $service->create( 'unknown' );
$bad = $runner->run( $missing, $worker );
jr_same( 'certloom_job_handler_missing', $bad->get_error_code(), 'missing handler' );
jr_same( JobStates::FAILED, $repo->find( $missing )['status'], 'missing handler fails job' );
echo "PASS  unknown job type fails closed instead of remaining locked/running\n";

echo "\n3 tests, 0 failures\n";
