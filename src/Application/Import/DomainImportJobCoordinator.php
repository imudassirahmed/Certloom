<?php
namespace Certloom\Application\Import;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Job\JobQueue;
use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Import\DomainImportCsvReader;

/**
 * Explicit application boundary for creating reviewed CSV preflight/commit jobs.
 *
 * Upload controllers only provide a validated local upload path + mapping. This
 * service immediately moves the file into Certloom-owned private storage,
 * snapshots live consistency, and creates a non-writing preflight job. Commit
 * is always a second administrator-confirmed job.
 */
final class DomainImportJobCoordinator {
	/** @var JobQueue */
	private $queue;
	/** @var JobRepositoryInterface */
	private $jobs;
	/** @var PrivateJobFileStore */
	private $files;
	/** @var DomainImportCsvReader */
	private $reader;
	/** @var DomainImportConsistencyGuard */
	private $consistency;

	public function __construct(
		JobQueue $queue,
		JobRepositoryInterface $jobs,
		?PrivateJobFileStore $files = null,
		?DomainImportCsvReader $reader = null,
		?DomainImportConsistencyGuard $consistency = null
	) {
		$this->queue       = $queue;
		$this->jobs        = $jobs;
		$this->files       = $files ?: new PrivateJobFileStore();
		$this->reader      = $reader ?: new DomainImportCsvReader( $this->files );
		$this->consistency = $consistency ?: new DomainImportConsistencyGuard();
	}

	/** @return string|\WP_Error Preflight job key. */
	public function enqueue_preflight( $source_path, $owner_user_id, array $mapping, array $options = array() ) {
		$source_path = (string) $source_path;
		$size = @filesize( $source_path );
		if ( false === $size || $size < 1 || $size > DomainImportCsvReader::MAX_FILE_BYTES ) {
			return new \WP_Error( 'certloom_import_upload_size_invalid', __( 'Choose a non-empty CSV file no larger than 10 MB.', 'certloom' ) );
		}
		$original_name = trim( (string) ( $options['original_name'] ?? '' ) );
		if ( '' !== $original_name && 'csv' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error( 'certloom_import_upload_type_invalid', __( 'Certloom accepts CSV files for this import workflow.', 'certloom' ) );
		}

		try {
			$file_key = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_import_file_key_failed', __( 'Certloom could not create a secure import file identifier.', 'certloom' ) );
		}
		$private_path = $this->files->store_copy( $file_key, $source_path, 'import' );
		if ( is_wp_error( $private_path ) ) { return $private_path; }

		return $this->enqueue_preflight_private( $private_path, $owner_user_id, $mapping, $options );
	}

	/**
	 * Transfers an already-private mapping-session file into a preflight job.
	 * On failure the file is deleted so abandoned mapping sessions cannot retain
	 * recipient data indefinitely.
	 *
	 * @return string|\WP_Error
	 */
	public function enqueue_preflight_private( $private_path, $owner_user_id, array $mapping, array $options = array() ) {
		$private_path = (string) $private_path;
		if ( ! $this->files->owns( $private_path ) || ! is_file( $private_path ) || ! is_readable( $private_path ) ) {
			return new \WP_Error( 'certloom_import_private_source_invalid', __( 'The protected CSV mapping session is no longer available.', 'certloom' ) );
		}
		$original_name = trim( (string) ( $options['original_name'] ?? '' ) );
		if ( '' !== $original_name && 'csv' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			return $this->cleanup_private_after_error( $private_path, new \WP_Error( 'certloom_import_upload_type_invalid', __( 'Certloom accepts CSV files for this import workflow.', 'certloom' ) ) );
		}

		$probe = $this->reader->start(
			$private_path,
			$mapping,
			! empty( $options['clear_empty'] ),
			absint( $options['max_rows'] ?? DomainImportCsvReader::MAX_ROWS )
		);
		if ( is_wp_error( $probe ) ) {
			return $this->cleanup_private_after_error( $private_path, $probe );
		}
		$baseline = $this->consistency->snapshot();
		if ( is_wp_error( $baseline ) ) {
			return $this->cleanup_private_after_error( $private_path, $baseline );
		}

		$payload = array(
			'path'                        => $private_path,
			'mapping'                     => (array) $probe['mapping'],
			'clear_empty'                 => ! empty( $options['clear_empty'] ),
			'allow_historical_expired'    => ! empty( $options['allow_historical_expired'] ),
			'strict'                      => ! array_key_exists( 'strict', $options ) || ! empty( $options['strict'] ),
			'max_rows'                    => absint( $probe['max_rows'] ),
			'today'                       => isset( $options['today'] ) ? (string) $options['today'] : gmdate( 'Y-m-d' ),
			'baseline_generation'         => (string) $baseline['generation'],
			'baseline_configuration_hash' => (string) $baseline['configuration_hash'],
		);
		$job_key = $this->queue->enqueue( JobTypes::DOMAIN_IMPORT_PREFLIGHT, absint( $owner_user_id ), $payload );
		if ( is_wp_error( $job_key ) ) {
			return $this->cleanup_private_after_error( $private_path, $job_key );
		}
		return $job_key;
	}


	/** Preserve the primary failure unless protected-file cleanup itself becomes uncertain. @return \WP_Error */
	private function cleanup_private_after_error( $path, \WP_Error $primary ) {
		$deleted = $this->files->delete( (string) $path );
		if ( ! is_wp_error( $deleted ) && false !== $deleted ) { return $primary; }
		return new \WP_Error(
			'certloom_import_private_cleanup_failed',
			sprintf( /* translators: %1$s: primary error; %2$s: cleanup error. */ __( 'Certloom stopped the import safely (%1$s), but could not remove its protected source copy (%2$s).', 'certloom' ), $primary->get_error_message(), is_wp_error( $deleted ) ? $deleted->get_error_message() : __( 'file deletion failed', 'certloom' ) )
		);
	}

	/** @return string|\WP_Error Commit job key. */
	public function enqueue_commit( $preflight_job_key, $owner_user_id, $duplicate_policy = 'skip', $confirmed = false ) {
		if ( ! $confirmed ) {
			return new \WP_Error( 'certloom_import_commit_confirmation_required', __( 'Confirm the reviewed import before creating its commit job.', 'certloom' ) );
		}
		$preflight_job_key = strtolower( trim( (string) $preflight_job_key ) );
		$preflight = $this->jobs->find( $preflight_job_key );
		if ( is_wp_error( $preflight ) ) { return $preflight; }
		if ( ! is_array( $preflight ) || JobTypes::DOMAIN_IMPORT_PREFLIGHT !== (string) ( $preflight['job_type'] ?? '' ) ) {
			return new \WP_Error( 'certloom_import_preflight_job_invalid', __( 'The selected Certloom job is not a CSV import preflight.', 'certloom' ) );
		}
		if ( ! in_array( (string) ( $preflight['status'] ?? '' ), array( JobStates::SUCCEEDED, JobStates::PARTIALLY_SUCCEEDED ), true ) ) {
			return new \WP_Error( 'certloom_import_preflight_incomplete', __( 'The CSV preflight must finish before commit can be queued.', 'certloom' ) );
		}
		$result = isset( $preflight['result'] ) && is_array( $preflight['result'] ) ? $preflight['result'] : array();
		if ( empty( $result['ready_for_commit'] ) ) {
			return new \WP_Error( 'certloom_import_preflight_not_ready', __( 'This CSV review still contains blocking errors and cannot be committed.', 'certloom' ) );
		}
		$preflight_owner = absint( $preflight['owner_user_id'] ?? 0 );
		$owner_user_id   = absint( $owner_user_id );
		if ( $preflight_owner && $owner_user_id && $preflight_owner !== $owner_user_id ) {
			return new \WP_Error( 'certloom_import_owner_mismatch', __( 'The reviewed import belongs to a different Certloom administrator.', 'certloom' ) );
		}
		$duplicate_policy = in_array( (string) $duplicate_policy, array( 'skip', 'correct' ), true ) ? (string) $duplicate_policy : 'skip';
		$summary = isset( $result['summary'] ) && is_array( $result['summary'] ) ? $result['summary'] : array();
		$total = absint( $summary['ready'] ?? 0 ) + ( 'correct' === $duplicate_policy ? absint( $summary['duplicates'] ?? 0 ) : 0 );

		return $this->queue->enqueue(
			JobTypes::DOMAIN_IMPORT_COMMIT,
			$owner_user_id,
			array(
				'preflight_job_key' => $preflight_job_key,
				'duplicate_policy'  => $duplicate_policy,
				'confirmed_commit'  => true,
			),
			$total,
			null,
			$preflight_job_key
		);
	}
}
