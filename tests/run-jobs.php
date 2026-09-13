<?php
/** Pure tests for the shared resumable job lifecycle. */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Job\JobService;
use Certloom\Domain\Job\JobStateMachine;
use Certloom\Domain\Job\JobStates;

final class FakeJobRepository implements JobRepositoryInterface {
	public $jobs = array();
	private $next = 1;
	public function create( array $data ) {
		$key = str_pad( dechex( $this->next++ ), 32, '0', STR_PAD_LEFT );
		$this->jobs[ $key ] = array(
			'job_key' => $key, 'job_type' => $data['job_type'], 'status' => JobStates::QUEUED,
			'owner_user_id' => $data['owner_user_id'], 'cursor' => 0, 'phase' => null,
			'total' => $data['total'], 'selection_max_id' => $data['selection_max_id'],
			'payload' => $data['payload'], 'result' => array(), 'error' => array(), 'attempts' => 0,
			'parent_job_key' => $data['parent_job_key'] ?? null,
			'locked_by' => null,
		);
		return $key;
	}
	public function find( $job_key ) { return $this->jobs[ $job_key ] ?? null; }
	public function claim( $job_key, $worker_token, $lock_seconds ) {
		if ( empty( $this->jobs[ $job_key ] ) || JobStates::QUEUED !== $this->jobs[ $job_key ]['status'] ) {
			return new WP_Error( 'certloom_job_not_claimable', 'not claimable' );
		}
		$this->jobs[ $job_key ]['status'] = JobStates::RUNNING;
		$this->jobs[ $job_key ]['locked_by'] = $worker_token;
		$this->jobs[ $job_key ]['attempts']++;
		return $this->jobs[ $job_key ];
	}
	public function save_progress( $job_key, $worker_token, array $progress ) {
		if ( empty( $this->jobs[ $job_key ] ) || JobStates::RUNNING !== $this->jobs[ $job_key ]['status'] || $worker_token !== $this->jobs[ $job_key ]['locked_by'] ) {
			return new WP_Error( 'certloom_job_progress_conflict', 'conflict' );
		}
		$this->jobs[ $job_key ]['cursor'] = $progress['cursor'];
		$this->jobs[ $job_key ]['phase'] = $progress['phase'];
		$this->jobs[ $job_key ]['result'] = $progress['result'];
		return true;
	}
	public function find_due_queued( $limit = 20 ) {
		return array_values( array_filter( $this->jobs, static function ( $job ) { return JobStates::QUEUED === $job['status']; } ) );
	}
	public function recover_stale_running( $limit = 20 ) { return 0; }
	public function transition( $job_key, $expected_state, $new_state, array $changes = array(), $worker_token = null ) {
		if ( empty( $this->jobs[ $job_key ] ) || $expected_state !== $this->jobs[ $job_key ]['status'] ) {
			return new WP_Error( 'certloom_job_transition_conflict', 'conflict' );
		}
		if ( null !== $worker_token && $worker_token !== $this->jobs[ $job_key ]['locked_by'] ) {
			return new WP_Error( 'certloom_job_transition_conflict', 'wrong owner' );
		}
		$this->jobs[ $job_key ]['status'] = $new_state;
		$this->jobs[ $job_key ]['locked_by'] = null;
		if ( array_key_exists( 'result', $changes ) ) { $this->jobs[ $job_key ]['result'] = $changes['result']; }
		if ( array_key_exists( 'error', $changes ) ) { $this->jobs[ $job_key ]['error'] = $changes['error']; }
		return true;
	}
}

function job_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) { throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}

$states = new JobStateMachine();
job_same( true, $states->can_transition( JobStates::QUEUED, JobStates::RUNNING ), 'queued starts' );
job_same( true, $states->can_transition( JobStates::FAILED, JobStates::QUEUED ), 'failed can retry' );
job_same( false, $states->can_transition( JobStates::SUCCEEDED, JobStates::RUNNING ), 'success is terminal' );
echo "PASS  explicit job transition matrix\n";

$repo = new FakeJobRepository();
$service = new JobService( $repo );
$key = $service->create( 'legacy_migration', 9, array( 'source' => 'legacy' ), 500, 700 );
job_same( false, is_wp_error( $key ), 'job created' );
job_same( JobStates::QUEUED, $repo->find( $key )['status'], 'queued' );
job_same( 700, $repo->find( $key )['selection_max_id'], 'selection snapshot' );
echo "PASS  job creation preserves owner/payload/selection boundary\n";

$parent = str_repeat( 'f', 32 );
$child = $service->create( 'child_work', 9, array(), null, null, $parent );
job_same( $parent, $repo->find( $child )['parent_job_key'], 'parent relation' );
echo "PASS  child jobs durably retain their parent dependency for safe retention ordering\n";

$worker = str_repeat( 'a', 32 );
$claimed = $service->begin( $key, $worker );
job_same( JobStates::RUNNING, $claimed['status'], 'claimed running' );
job_same( 1, $claimed['attempts'], 'attempt incremented' );
job_same( true, $service->progress( $key, $worker, 250, 'commit', array( 'created' => 249, 'skipped' => 1 ) ), 'progress' );
job_same( 250, $repo->find( $key )['cursor'], 'cursor saved' );
job_same( 'commit', $repo->find( $key )['phase'], 'phase saved' );
echo "PASS  claimed worker owns resumable progress\n";

$wrong = $service->succeed( $key, str_repeat( 'b', 32 ), array() );
job_same( 'certloom_job_transition_conflict', $wrong->get_error_code(), 'wrong worker cannot finish' );
job_same( true, $service->succeed( $key, $worker, array( 'created' => 499, 'skipped' => 1 ) ), 'owner can finish' );
job_same( JobStates::SUCCEEDED, $repo->find( $key )['status'], 'terminal success' );
$restart = $service->begin( $key, $worker );
job_same( 'certloom_job_not_claimable', $restart->get_error_code(), 'terminal cannot rerun' );
echo "PASS  worker ownership and terminal-state protection\n";

$failed = $service->create( 'export' );
$service->begin( $failed, $worker );
job_same( true, $service->fail( $failed, $worker, array( 'code' => 'disk_full' ), array( 'rows' => 10 ) ), 'failure recorded' );
job_same( JobStates::FAILED, $repo->find( $failed )['status'], 'failed terminal-ish' );
job_same( true, $service->requeue_failed( $failed ), 'manual retry' );
job_same( JobStates::QUEUED, $repo->find( $failed )['status'], 'requeued' );
echo "PASS  failed jobs require explicit retry\n";

$bad = $service->begin( $failed, 'not-a-token' );
job_same( 'certloom_job_worker_token_invalid', $bad->get_error_code(), 'worker token validated' );
echo "PASS  worker tokens are bounded/validated\n";

echo "\n7 tests, 0 failures\n";
