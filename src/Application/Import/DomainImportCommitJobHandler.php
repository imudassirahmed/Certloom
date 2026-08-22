<?php
namespace Certloom\Application\Import;

use Certloom\Application\Contract\DomainImportStageInterface;
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Domain\Job\JobStates;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;

/**
 * Explicitly-confirmed import commit job.
 *
 * The preflight file is never reparsed. This job consumes only durable plans
 * that passed preflight, holds the shared maintenance boundary while slices
 * are active, and records per-row durable receipts so a crashed worker can
 * replay safely without corrupting exact result accounting.
 */
final class DomainImportCommitJobHandler implements JobHandlerInterface {
	/** @var JobRepositoryInterface */
	private $jobs;
	/** @var DomainImportStagedCommitService */
	private $commit;
	/** @var DomainImportStageInterface */
	private $stage;
	/** @var DomainImportConsistencyGuard */
	private $consistency;
	/** @var DomainMaintenanceLock */
	private $maintenance;
	/** @var int */
	private $batch_size;

	public function __construct(
		JobRepositoryInterface $jobs,
		DomainImportStagedCommitService $commit,
		DomainImportStageInterface $stage,
		DomainImportConsistencyGuard $consistency = null,
		DomainMaintenanceLock $maintenance = null,
		$batch_size = 200
	) {
		$this->jobs        = $jobs;
		$this->commit      = $commit;
		$this->stage       = $stage;
		$this->consistency = $consistency ?: new DomainImportConsistencyGuard();
		$this->maintenance = $maintenance ?: new DomainMaintenanceLock();
		$this->batch_size  = max( 1, min( 500, absint( $batch_size ) ) );
	}

	/** @return JobSliceResult|\WP_Error */
	public function handle( array $job, $worker_token ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Job ownership is enforced by JobRunner.
		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$job_key = (string) ( $job['job_key'] ?? '' );
		if ( empty( $payload['confirmed_commit'] ) ) {
			return new \WP_Error( 'certloom_import_commit_confirmation_required', __( 'Committing a reviewed Certloom import requires explicit administrator confirmation.', 'certloom' ) );
		}
		$preflight_key = strtolower( trim( (string) ( $payload['preflight_job_key'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $preflight_key ) ) {
			return new \WP_Error( 'certloom_import_preflight_job_invalid', __( 'The reviewed import job identifier is invalid.', 'certloom' ) );
		}
		$preflight = $this->jobs->find( $preflight_key );
		if ( is_wp_error( $preflight ) ) { return $preflight; }
		if ( ! is_array( $preflight ) || ! in_array( (string) ( $preflight['status'] ?? '' ), array( JobStates::SUCCEEDED, JobStates::PARTIALLY_SUCCEEDED ), true ) ) {
			return new \WP_Error( 'certloom_import_preflight_incomplete', __( 'The CSV preflight must finish before its reviewed rows can be committed.', 'certloom' ) );
		}
		$preflight_result = isset( $preflight['result'] ) && is_array( $preflight['result'] ) ? $preflight['result'] : array();
		if ( empty( $preflight_result['ready_for_commit'] ) ) {
			return new \WP_Error( 'certloom_import_preflight_not_ready', __( 'This CSV preflight is not eligible for commit. Resolve the blocking errors and review the file again.', 'certloom' ) );
		}
		$preflight_owner = absint( $preflight['owner_user_id'] ?? 0 );
		$commit_owner    = absint( $job['owner_user_id'] ?? 0 );
		if ( $preflight_owner && $commit_owner && $preflight_owner !== $commit_owner ) {
			return new \WP_Error( 'certloom_import_owner_mismatch', __( 'The reviewed import belongs to a different Certloom administrator.', 'certloom' ) );
		}

		$duplicate_policy = in_array( (string) ( $payload['duplicate_policy'] ?? 'skip' ), array( 'skip', 'correct' ), true )
			? (string) $payload['duplicate_policy']
			: 'skip';
		$state = isset( $job['result']['commit_state'] ) && is_array( $job['result']['commit_state'] )
			? $job['result']['commit_state']
			: array( 'after_row' => 0 );

		$continued_lease = $this->maintenance->owned_active( $job_key );
		$locked = $this->maintenance->acquire( $job_key, 'import_commit', DomainMaintenanceLock::DEFAULT_TTL );
		if ( is_wp_error( $locked ) ) { return $locked; }

		$baseline_generation = isset( $state['expected_generation'] )
			? (string) $state['expected_generation']
			: (string) ( $preflight_result['baseline_generation'] ?? '' );
		$baseline_config = isset( $state['expected_configuration_hash'] )
			? (string) $state['expected_configuration_hash']
			: (string) ( $preflight_result['baseline_configuration_hash'] ?? '' );
		$consistent = $this->consistency->validate( $baseline_generation, $baseline_config );
		if ( is_wp_error( $consistent ) && ! $continued_lease ) {
			return $this->release_after_error( $job_key, $consistent );
		}
		if ( is_wp_error( $consistent ) && $continued_lease ) {
			// The same unexpired maintenance lease proves no normal credential or
			// configuration writer could have changed the baseline. A mismatch here
			// can therefore be the durable result of this job's previous slice dying
			// after its row writes but before JobRunner saved the new cursor.
			$recovered_baseline = $this->consistency->snapshot();
			if ( is_wp_error( $recovered_baseline ) ) {
				return $this->release_after_error( $job_key, $recovered_baseline );
			}
		}

		$batch = DomainMaintenanceLock::run_internal( function () use ( $preflight_key, $state, $duplicate_policy ) {
			return $this->commit->commit_batch(
				$preflight_key,
				absint( $state['after_row'] ?? 0 ),
				$this->batch_size,
				$duplicate_policy
			);
		} );
		if ( ! is_array( $batch ) ) {
			return $this->release_after_error(
				$job_key,
				new \WP_Error( 'certloom_import_commit_result_invalid', __( 'The Certloom import writer returned an invalid commit result.', 'certloom' ) )
			);
		}
		if ( ! empty( $batch['failed'] ) ) {
			$error = isset( $batch['errors'][0] ) && is_array( $batch['errors'][0] ) ? $batch['errors'][0] : array();
			return $this->release_after_error(
				$job_key,
				new \WP_Error(
					(string) ( $error['code'] ?? 'certloom_import_commit_failed' ),
					(string) ( $error['message'] ?? __( 'Certloom stopped the import at the first unsafe write failure.', 'certloom' ) )
				)
			);
		}

		$receipt = $this->stage->commit_summary( $preflight_key );
		if ( is_wp_error( $receipt ) ) {
			return $this->release_after_error( $job_key, $receipt );
		}
		$preflight_summary = isset( $preflight_result['summary'] ) && is_array( $preflight_result['summary'] ) ? $preflight_result['summary'] : array();
		$state['after_row'] = absint( $batch['last_row'] ?? $state['after_row'] ?? 0 );

		if ( ! empty( $batch['complete'] ) ) {
			$release = $this->maintenance->release( $job_key );
			if ( is_wp_error( $release ) ) {
				/*
				 * The rows are already durable, but a succeeded job must never leave
				 * normal credential writes blocked. Persist the completed cursor and
				 * yield a cleanup slice; row receipts make the replay idempotent.
				 */
				$state['cleanup_pending'] = true;
				return JobSliceResult::progress(
					absint( $state['after_row'] ),
					'import_cleanup',
					array(
						'commit_state'   => $state,
						'commit_summary' => $receipt,
						'cleanup_error'  => array(
							'code'    => $release->get_error_code(),
							'message' => $release->get_error_message(),
						),
					)
				);
			}
			return JobSliceResult::succeeded(
				array(
					'preflight_job_key' => $preflight_key,
					'duplicate_policy'  => $duplicate_policy,
					'created'           => absint( $receipt['created'] ?? 0 ),
					'corrected'         => absint( $receipt['corrected'] ?? 0 ),
					'committed'         => absint( $receipt['committed'] ?? 0 ),
					'skipped_duplicates'=> 'skip' === $duplicate_policy ? absint( $preflight_summary['duplicates'] ?? 0 ) : 0,
					'invalid_rows'      => absint( $preflight_summary['errors'] ?? 0 ),
					'warnings'          => array(),
				),
				absint( $state['after_row'] )
			);
		}

		$next_baseline = $this->consistency->snapshot();
		if ( is_wp_error( $next_baseline ) ) {
			return $this->release_after_error( $job_key, $next_baseline );
		}
		$state['expected_generation'] = (string) $next_baseline['generation'];
		$state['expected_configuration_hash'] = (string) $next_baseline['configuration_hash'];
		return JobSliceResult::progress(
			absint( $state['after_row'] ),
			'import_commit',
			array(
				'commit_state' => $state,
				'commit_summary' => $receipt,
			)
		);
	}

	/** Returns the original failure only when the maintenance boundary was safely released. */
	private function release_after_error( $job_key, \WP_Error $error ) {
		$released = $this->maintenance->release( $job_key );
		return is_wp_error( $released ) ? $released : $error;
	}
}
