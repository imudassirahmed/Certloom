<?php
namespace Certloom\Application\DataTools;

use Certloom\Application\Backup\DomainBackupFormat;
use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Job\JobQueue;
use Certloom\Application\Restore\DomainRestoreStagingService;
use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

/**
 * Protected application boundary for new-domain backup and restore jobs.
 *
 * Restore is deliberately two-step: first a non-destructive review job stages
 * and validates the selected mode, then an explicit commit job restages and
 * revalidates only if the live data/configuration still match that review.
 */
final class DomainDataToolsCoordinator {
	/** @var JobQueue */
	private $queue;
	/** @var JobRepositoryInterface */
	private $jobs;
	/** @var PrivateJobFileStore */
	private $files;
	/** @var DomainGeneration */
	private $generation;
	/** @var DomainBackupRepository */
	private $backup_repository;

	public function __construct(
		JobQueue $queue,
		JobRepositoryInterface $jobs,
		PrivateJobFileStore $files = null,
		DomainGeneration $generation = null,
		DomainBackupRepository $backup_repository = null
	) {
		$this->queue             = $queue;
		$this->jobs              = $jobs;
		$this->files             = $files ?: new PrivateJobFileStore();
		$this->generation        = $generation ?: new DomainGeneration();
		$this->backup_repository = $backup_repository ?: new DomainBackupRepository();
	}

	/** @return string|\WP_Error Backup job key. */
	public function enqueue_backup( $owner_user_id ) {
		return $this->queue->enqueue( JobTypes::DOMAIN_BACKUP, absint( $owner_user_id ) );
	}

	/** @return string|\WP_Error Non-destructive restore-review job key. */
	public function enqueue_restore_review( $source_path, $original_name, $owner_user_id, $mode, $confirmed_review = false ) {
		$source_path   = (string) $source_path;
		$original_name = sanitize_file_name( (string) $original_name );
		$mode          = sanitize_key( (string) $mode );
		if ( ! $confirmed_review || ! in_array( $mode, array( 'replace', 'merge' ), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_review_intent_required', __( 'Choose a restore mode and confirm that Certloom may perform a non-destructive restore review.', 'certloom' ) );
		}
		if ( 'cvbackup' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error( 'certloom_domain_restore_file_type_invalid', __( 'Choose a Certloom .cvbackup file for the new-domain restore workflow.', 'certloom' ) );
		}
		$size = @filesize( $source_path );
		if ( false === $size || $size < 1 || $size > DomainBackupFormat::MAX_FILE_BYTES ) {
			return new \WP_Error( 'certloom_domain_restore_file_size_invalid', __( 'The Certloom backup is empty or exceeds the supported restore size limit.', 'certloom' ) );
		}
		try {
			$file_key = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_domain_restore_file_key_failed', __( 'Certloom could not create a secure restore-file identifier.', 'certloom' ) );
		}
		$private_path = $this->files->store_copy( $file_key, $source_path, 'restore' );
		if ( is_wp_error( $private_path ) ) {
			return $private_path;
		}
		$job_key = $this->queue->enqueue(
			JobTypes::DOMAIN_RESTORE_PREFLIGHT,
			absint( $owner_user_id ),
			array(
				'path'             => $private_path,
				'mode'             => $mode,
				'confirmed_review' => true,
				'max_records'      => DomainRestoreStagingService::DEFAULT_MAX_RECORDS,
			)
		);
		if ( is_wp_error( $job_key ) ) {
			$this->files->delete( $private_path );
			return $job_key;
		}
		return $job_key;
	}

	/** @return string|\WP_Error Explicit restore-commit job key. */
	public function enqueue_restore_commit( $preflight_job_key, $owner_user_id, $confirmed_commit = false ) {
		if ( ! $confirmed_commit ) {
			return new \WP_Error( 'certloom_domain_restore_commit_confirmation_required', __( 'Confirm the reviewed restore before Certloom creates its commit job.', 'certloom' ) );
		}
		$preflight_job_key = strtolower( trim( (string) $preflight_job_key ) );
		$preflight = $this->jobs->find( $preflight_job_key );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		if ( ! is_array( $preflight ) || JobTypes::DOMAIN_RESTORE_PREFLIGHT !== (string) ( $preflight['job_type'] ?? '' ) ) {
			return new \WP_Error( 'certloom_domain_restore_review_job_invalid', __( 'The selected Certloom job is not a restore review.', 'certloom' ) );
		}
		if ( JobStates::SUCCEEDED !== (string) ( $preflight['status'] ?? '' ) || empty( $preflight['result']['ready_for_commit'] ) ) {
			return new \WP_Error( 'certloom_domain_restore_review_incomplete', __( 'The restore review must finish successfully before commit can be queued.', 'certloom' ) );
		}
		$owner_user_id = absint( $owner_user_id );
		$preflight_owner = absint( $preflight['owner_user_id'] ?? 0 );
		if ( $preflight_owner && $owner_user_id && $preflight_owner !== $owner_user_id ) {
			return new \WP_Error( 'certloom_domain_restore_owner_mismatch', __( 'That restore review belongs to a different Certloom administrator.', 'certloom' ) );
		}
		$mode = sanitize_key( (string) ( $preflight['result']['mode'] ?? '' ) );
		if ( ! in_array( $mode, array( 'replace', 'merge' ), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_review_mode_invalid', __( 'The reviewed restore mode is invalid.', 'certloom' ) );
		}
		$path = (string) ( $preflight['payload']['path'] ?? '' );
		if ( ! $this->files->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'certloom_domain_restore_review_source_missing', __( 'The protected restore source is no longer available. Upload and review the backup again.', 'certloom' ) );
		}

		$review_generation = (string) ( $preflight['result']['baseline_generation'] ?? '' );
		$review_config_hash = strtolower( (string) ( $preflight['result']['baseline_configuration_hash'] ?? '' ) );
		if ( '' === $review_generation || ! preg_match( '/^[a-f0-9]{64}$/', $review_config_hash ) ) {
			return new \WP_Error( 'certloom_domain_restore_review_snapshot_invalid', __( 'The restore review is missing its live-data consistency snapshot.', 'certloom' ) );
		}
		if ( ! hash_equals( $review_generation, $this->generation->current() ) ) {
			return new \WP_Error( 'certloom_domain_restore_review_stale', __( 'Certloom credential data changed after this restore review. Run the review again before committing.', 'certloom' ) );
		}
		$config = $this->backup_repository->configuration_snapshot();
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		if ( ! hash_equals( $review_config_hash, strtolower( (string) $config['hash'] ) ) ) {
			return new \WP_Error( 'certloom_domain_restore_review_stale', __( 'Certloom configuration changed after this restore review. Run the review again before committing.', 'certloom' ) );
		}

		$type = 'replace' === $mode ? JobTypes::DOMAIN_RESTORE_REPLACE : JobTypes::DOMAIN_RESTORE_MERGE;
		return $this->queue->enqueue(
			$type,
			$owner_user_id,
			array(
				'path'                        => $path,
				'baseline_generation'         => $review_generation,
				'baseline_configuration_hash' => $review_config_hash,
				'max_records'                 => DomainRestoreStagingService::DEFAULT_MAX_RECORDS,
				'confirmed_replace'           => 'replace' === $mode,
				'confirmed_merge'             => 'merge' === $mode,
			),
			null,
			null,
			$preflight_job_key
		);
	}
}
