<?php
namespace Certloom\Application\Restore;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\DomainRestoreStagingInterface;
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Restore\DomainRestoreMergeService;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;

/**
 * Non-destructive restore review job.
 *
 * The job fully stages and validates the backup. For Merge it also constructs
 * the complete private merged graph to discover identity conflicts and counts.
 * It never promotes tables. After review data is captured, private staging is
 * abandoned and the maintenance lock is released. A later explicit commit job
 * must restage/revalidate against the unchanged live-data generation.
 */
final class DomainRestorePreflightJobHandler implements JobHandlerInterface {
	/** @var DomainRestoreStagingInterface */
	private $restore;
	/** @var DomainRestoreMergeService */
	private $merge;
	/** @var DomainRestoreWorkspace */
	private $workspace;
	/** @var DomainGeneration */
	private $generation;
	/** @var DomainBackupRepository */
	private $backup_repository;
	/** @var int */
	private $batch_size;

	public function __construct(
		DomainRestoreStagingInterface $restore,
		DomainRestoreMergeService $merge,
		DomainRestoreWorkspace $workspace,
		?DomainGeneration $generation = null,
		?DomainBackupRepository $backup_repository = null,
		$batch_size = 200
	) {
		$this->restore           = $restore;
		$this->merge             = $merge;
		$this->workspace         = $workspace;
		$this->generation        = $generation ?: new DomainGeneration();
		$this->backup_repository = $backup_repository ?: new DomainBackupRepository();
		$this->batch_size        = max( 1, min( 500, absint( $batch_size ) ) );
	}

	/** @return JobSliceResult|\WP_Error */
	public function handle( array $job, $worker_token ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Worker ownership enforced by JobRunner.
		$job_key = (string) ( $job['job_key'] ?? '' );
		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$result  = isset( $job['result'] ) && is_array( $job['result'] ) ? $job['result'] : array();
		$mode    = sanitize_key( (string) ( $payload['mode'] ?? '' ) );
		if ( ! in_array( $mode, array( 'replace', 'merge' ), true ) || true !== ( $payload['confirmed_review'] ?? false ) ) {
			return new \WP_Error( 'certloom_domain_restore_review_invalid', __( 'The restore review job is missing its explicitly selected mode or review confirmation.', 'certloom' ) );
		}

		$restore_state = isset( $result['restore_state'] ) && is_array( $result['restore_state'] ) ? $result['restore_state'] : null;
		$merge_state   = isset( $result['merge_state'] ) && is_array( $result['merge_state'] ) ? $result['merge_state'] : null;
		if ( null === $restore_state ) {
			$restore_state = $this->restore->start(
				(string) ( $payload['path'] ?? '' ),
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
			if ( empty( $restore_state['complete'] ) ) {
				return JobSliceResult::progress(
					absint( $restore_state['records'] ?? 0 ),
					'restore_review_stage',
					array( 'restore_state' => $restore_state )
				);
			}
		}

		if ( 'merge' === $mode ) {
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
						'restore_review_merge',
						array( 'restore_state' => $restore_state, 'merge_state' => $merge_state )
					);
				}
			}
		}

		$config = $this->backup_repository->configuration_snapshot();
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$baseline_generation = $this->generation->read_current();
		if ( is_wp_error( $baseline_generation ) ) { return $baseline_generation; }
		$review = array(
			'ready_for_commit'            => true,
			'mode'                        => $mode,
			'backup_records'              => absint( $restore_state['records'] ?? 0 ),
			'backup_counts'               => (array) ( $restore_state['counts'] ?? array() ),
			'merge_counts'                => 'merge' === $mode ? (array) ( $merge_state['counts'] ?? array() ) : array(),
			'baseline_generation'         => (string) $baseline_generation,
			'baseline_configuration_hash' => (string) $config['hash'],
		);
		$abandoned = $this->workspace->abandon( (array) $restore_state['workspace'] );
		if ( is_wp_error( $abandoned ) ) {
			return $abandoned;
		}
		return JobSliceResult::succeeded( $review, absint( $restore_state['records'] ?? 0 ) );
	}
}
