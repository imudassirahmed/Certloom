<?php
namespace Certloom\Application\Job;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Domain\Job\JobStateMachine;
use Certloom\Domain\Job\JobStates;

/** Application boundary shared by imports, migrations, restores, PDF, email and later bulk work. */
final class JobService {
	private $jobs;
	private $states;

	public function __construct( JobRepositoryInterface $jobs, ?JobStateMachine $states = null ) {
		$this->jobs   = $jobs;
		$this->states = $states ?: new JobStateMachine();
	}

	/** @return string|\WP_Error */
	public function create( $type, $owner_user_id = 0, array $payload = array(), $total = null, $selection_max_id = null, $parent_job_key = null ) {
		$type = sanitize_key( (string) $type );
		if ( '' === $type ) {
			return new \WP_Error( 'certloom_job_type_required', __( 'A Certloom job type is required.', 'certloom' ) );
		}
		return $this->jobs->create(
			array(
				'job_type'         => $type,
				'owner_user_id'    => absint( $owner_user_id ),
				'payload'          => $payload,
				'total'            => null === $total ? null : absint( $total ),
				'selection_max_id' => null === $selection_max_id ? null : absint( $selection_max_id ),
				'parent_job_key'   => null === $parent_job_key ? null : (string) $parent_job_key,
			)
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function begin( $job_key, $worker_token, $lock_seconds = 120 ) {
		$worker_token = self::worker_token( $worker_token );
		if ( is_wp_error( $worker_token ) ) { return $worker_token; }
		return $this->jobs->claim( (string) $job_key, $worker_token, max( 30, min( 900, absint( $lock_seconds ) ) ) );
	}

	/** @return true|\WP_Error */
	public function progress( $job_key, $worker_token, $cursor, $phase = null, array $result = array(), $lock_seconds = 120 ) {
		$worker_token = self::worker_token( $worker_token );
		if ( is_wp_error( $worker_token ) ) { return $worker_token; }
		return $this->jobs->save_progress(
			(string) $job_key,
			$worker_token,
			array(
				'cursor'       => absint( $cursor ),
				'phase'        => null === $phase ? null : sanitize_key( (string) $phase ),
				'result'       => $result,
				'lock_seconds' => max( 30, min( 900, absint( $lock_seconds ) ) ),
			)
		);
	}

	/** Releases a completed slice back to the queue for a later cron/request. @return true|\WP_Error */
	public function yield_work( $job_key, $worker_token ) {
		return $this->move( $job_key, JobStates::RUNNING, JobStates::QUEUED, array(), $worker_token );
	}

	/** @return true|\WP_Error */
	public function pause( $job_key, $expected_state = JobStates::RUNNING, $worker_token = null ) {
		return $this->move( $job_key, $expected_state, JobStates::PAUSED, array(), $worker_token );
	}

	/** @return true|\WP_Error */
	public function resume( $job_key, $expected_state = JobStates::PAUSED ) {
		return $this->move( $job_key, $expected_state, JobStates::QUEUED );
	}

	/** @return true|\WP_Error */
	public function requeue_failed( $job_key ) {
		return $this->move( $job_key, JobStates::FAILED, JobStates::QUEUED, array( 'error' => null, 'recovery_count' => 0 ) );
	}

	/** @return true|\WP_Error */
	public function succeed( $job_key, $worker_token, array $result = array() ) {
		return $this->move( $job_key, JobStates::RUNNING, JobStates::SUCCEEDED, array( 'result' => $result ), $worker_token );
	}

	/** @return true|\WP_Error */
	public function partially_succeed( $job_key, $worker_token, array $result = array() ) {
		return $this->move( $job_key, JobStates::RUNNING, JobStates::PARTIALLY_SUCCEEDED, array( 'result' => $result ), $worker_token );
	}

	/** @return true|\WP_Error */
	public function fail( $job_key, $worker_token, array $error, array $result = array() ) {
		return $this->move( $job_key, JobStates::RUNNING, JobStates::FAILED, array( 'error' => $error, 'result' => $result ), $worker_token );
	}

	/** @return true|\WP_Error */
	public function cancel( $job_key, $expected_state, $worker_token = null ) {
		return $this->move( $job_key, $expected_state, JobStates::CANCELLED, array(), $worker_token );
	}

	/** @return true|\WP_Error */
	private function move( $job_key, $from, $to, array $changes = array(), $worker_token = null ) {
		$valid = $this->states->assert_transition( $from, $to );
		if ( is_wp_error( $valid ) ) { return $valid; }
		if ( null !== $worker_token ) {
			$worker_token = self::worker_token( $worker_token );
			if ( is_wp_error( $worker_token ) ) { return $worker_token; }
		}
		return $this->jobs->transition( (string) $job_key, (string) $from, (string) $to, $changes, $worker_token );
	}

	/** @return string|\WP_Error */
	private static function worker_token( $token ) {
		$token = strtolower( trim( (string) $token ) );
		return preg_match( '/^[a-f0-9]{32}$/', $token )
			? $token
			: new \WP_Error( 'certloom_job_worker_token_invalid', __( 'The Certloom worker token is invalid.', 'certloom' ) );
	}
}
