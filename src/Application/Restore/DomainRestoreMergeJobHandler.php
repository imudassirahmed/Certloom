<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Contract\DomainRestoreStagingInterface;
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Restore\DomainRestoreMergeService;

/** Explicitly-confirmed, resumable Merge restore job. */
final class DomainRestoreMergeJobHandler implements JobHandlerInterface {
	/** @var DomainRestoreStagingInterface */
	private $restore;
	/** @var DomainRestoreMergeService */
	private $merge;
	/** @var DomainRestoreConfirmationValidator */
	private $confirmation;
	/** @var int */
	private $batch_size;

	public function __construct(
		DomainRestoreStagingInterface $restore,
		DomainRestoreMergeService $merge,
		?DomainRestoreConfirmationValidator $confirmation = null,
		$batch_size = 200
	) {
		$this->restore      = $restore;
		$this->merge        = $merge;
		$this->confirmation = $confirmation ?: new DomainRestoreConfirmationValidator();
		$this->batch_size   = max( 1, min( 500, absint( $batch_size ) ) );
	}

	/** @return JobSliceResult|\WP_Error */
	public function handle( array $job, $worker_token ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Ownership enforced by JobRunner.
		$job_key = (string) ( $job['job_key'] ?? '' );
		$receipt = $this->restore->completion_receipt( $job_key );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( is_array( $receipt ) ) {
			return JobSliceResult::succeeded( $receipt, absint( $receipt['records'] ?? 0 ) );
		}

		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$result  = isset( $job['result'] ) && is_array( $job['result'] ) ? $job['result'] : array();
		$restore_state = isset( $result['restore_state'] ) && is_array( $result['restore_state'] ) ? $result['restore_state'] : null;
		$merge_state   = isset( $result['merge_state'] ) && is_array( $result['merge_state'] ) ? $result['merge_state'] : null;

		if ( null === $restore_state ) {
			$confirmed = $this->confirmation->validate(
				$payload,
				'confirmed_merge',
				__( 'A Merge restore requires explicit administrator confirmation after reviewing its conflicts and additions.', 'certloom' )
			);
			if ( is_wp_error( $confirmed ) ) {
				return $confirmed;
			}
			$restore_state = $this->restore->start(
				(string) $payload['path'],
				$job_key,
				absint( $payload['max_records'] ?? DomainRestoreStagingService::DEFAULT_MAX_RECORDS )
			);
			if ( is_wp_error( $restore_state ) ) {
				return $restore_state;
			}
		}

		if ( empty( $restore_state['complete'] ) ) {
			$restore_state = $this->restore->continue_stage( $restore_state, $this->batch_size );
			if ( is_wp_error( $restore_state ) ) {
				return $restore_state;
			}
			return JobSliceResult::progress(
				absint( $restore_state['records'] ?? 0 ),
				'restore_merge_stage',
				array( 'restore_state' => $restore_state )
			);
		}

		if ( null === $merge_state ) {
			$merge_state = $this->merge->start( $restore_state );
			if ( is_wp_error( $merge_state ) ) {
				return $merge_state;
			}
		}

		if ( empty( $merge_state['complete'] ) ) {
			$merge_state = $this->merge->continue_merge( $merge_state, $this->batch_size );
			if ( is_wp_error( $merge_state ) ) {
				return $merge_state;
			}
			if ( empty( $merge_state['complete'] ) ) {
				return JobSliceResult::progress(
					absint( $restore_state['records'] ?? 0 ),
					'restore_merge_reconcile',
					array( 'restore_state' => $restore_state, 'merge_state' => $merge_state )
				);
			}
		}

		$committed = $this->merge->commit( $merge_state );
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$counts = (array) ( $merge_state['counts'] ?? array() );
		return JobSliceResult::succeeded(
			array( 'mode' => 'merge', 'records' => array_sum( $counts ), 'counts' => $counts ),
			absint( $restore_state['records'] ?? 0 )
		);
	}
}
