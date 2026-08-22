<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Contract\DomainRestoreStagingInterface;
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;

/**
 * Explicitly-confirmed Replace restore job.
 *
 * A job is accepted only when its payload carries the live generation and
 * configuration hash captured at the administrator's confirmation step. If
 * either changed while the job waited in the queue, the restore fails before
 * creating a staging workspace and must be reviewed/confirmed again.
 */
final class DomainRestoreJobHandler implements JobHandlerInterface {
	/** @var DomainRestoreStagingInterface */
	private $restore;
	/** @var DomainRestoreConfirmationValidator */
	private $confirmation;
	/** @var int */
	private $batch_size;

	public function __construct(
		DomainRestoreStagingInterface $restore,
		DomainRestoreConfirmationValidator $confirmation = null,
		$batch_size = 200
	) {
		$this->restore      = $restore;
		$this->confirmation = $confirmation ?: new DomainRestoreConfirmationValidator();
		$this->batch_size   = max( 1, min( 500, absint( $batch_size ) ) );
	}

	/** @return JobSliceResult|\WP_Error */
	public function handle( array $job, $worker_token ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Worker ownership is enforced by the shared JobRunner/Repository.
		$job_key = (string) ( $job['job_key'] ?? '' );
		$receipt = $this->restore->completion_receipt( $job_key );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( is_array( $receipt ) ) {
			return JobSliceResult::succeeded( $receipt, absint( $receipt['records'] ?? 0 ) );
		}

		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$state   = isset( $job['result']['restore_state'] ) && is_array( $job['result']['restore_state'] ) ? $job['result']['restore_state'] : null;

		if ( null === $state ) {
			$confirmed = $this->confirmation->validate(
				$payload,
				'confirmed_replace',
				__( 'A complete Replace restore requires explicit administrator confirmation.', 'certloom' )
			);
			if ( is_wp_error( $confirmed ) ) {
				return $confirmed;
			}
			$state = $this->restore->start(
				(string) $payload['path'],
				$job_key,
				absint( $payload['max_records'] ?? DomainRestoreStagingService::DEFAULT_MAX_RECORDS )
			);
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}

		if ( empty( $state['complete'] ) ) {
			$state = $this->restore->continue_stage( $state, $this->batch_size );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}
		if ( ! empty( $state['complete'] ) ) {
			$committed = $this->restore->commit_replace( $state );
			if ( is_wp_error( $committed ) ) {
				return $committed;
			}
			return JobSliceResult::succeeded(
				array(
					'mode'    => 'replace',
					'records' => absint( $state['records'] ?? 0 ),
					'counts'  => (array) ( $state['counts'] ?? array() ),
				),
				absint( $state['records'] ?? 0 )
			);
		}
		return JobSliceResult::progress(
			absint( $state['records'] ?? 0 ),
			'restore_stage',
			array( 'restore_state' => $state )
		);
	}
}
