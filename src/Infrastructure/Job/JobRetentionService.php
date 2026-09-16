<?php
namespace Certloom\Infrastructure\Job;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Import\DomainImportStageRepository;
use Certloom\Infrastructure\Restore\DomainRestoreMapRepository;

/**
 * Bounded retention for finished operational jobs and private staging artifacts.
 *
 * Credential data is never deleted here. This service owns only background-job
 * metadata, import staging rows, Merge restore maps and randomized private job
 * files. Parent jobs are retained while any child job still references them, so
 * a reviewed import cannot lose its staged rows while a commit is pending.
 */
final class JobRetentionService {
	const LAST_RUN_OPTION   = 'certloom_job_retention_last_run';
	const RUN_INTERVAL      = HOUR_IN_SECONDS;
	const RETENTION_SECONDS = 14 * DAY_IN_SECONDS;
	const DEFAULT_LIMIT     = 25;

	/** @var JobRepository */
	private $jobs;
	/** @var DomainImportStageRepository */
	private $imports;
	/** @var DomainRestoreMapRepository */
	private $restore_maps;
	/** @var PrivateJobFileStore */
	private $files;

	public function __construct(
		?JobRepository $jobs = null,
		?DomainImportStageRepository $imports = null,
		?DomainRestoreMapRepository $restore_maps = null,
		?PrivateJobFileStore $files = null
	) {
		$this->jobs         = $jobs ?: new JobRepository();
		$this->imports      = $imports ?: new DomainImportStageRepository();
		$this->restore_maps = $restore_maps ?: new DomainRestoreMapRepository();
		$this->files        = $files ?: new PrivateJobFileStore();
	}

	/**
	 * Runs at most once per hour unless explicitly forced.
	 *
	 * @return array<string,int>|\WP_Error
	 */
	public function maybe_cleanup( $force = false, $limit = self::DEFAULT_LIMIT ) {
		$last = absint( get_option( self::LAST_RUN_OPTION, 0 ) );
		if ( ! $force && $last > 0 && ( time() - $last ) < self::RUN_INTERVAL ) {
			return array( 'jobs' => 0, 'files' => 0, 'skipped' => 1 );
		}

		$result = $this->cleanup( $limit );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_option( self::LAST_RUN_OPTION, time(), false );
		$result['skipped'] = 0;
		return $result;
	}

	/**
	 * @return array<string,int>|\WP_Error
	 */
	public function cleanup( $limit = self::DEFAULT_LIMIT ) {
		$limit  = max( 1, min( 100, absint( $limit ) ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_SECONDS );
		$jobs   = $this->jobs->find_terminal_before( $cutoff, $limit );
		if ( is_wp_error( $jobs ) ) {
			return $jobs;
		}

		$deleted_jobs = 0;
		foreach ( $jobs as $job ) {
			$job_key = (string) ( $job['job_key'] ?? '' );
			$type    = (string) ( $job['job_type'] ?? '' );
			if ( '' === $job_key ) {
				continue;
			}

			$has_children = $this->jobs->has_children( $job_key );
			if ( is_wp_error( $has_children ) ) {
				return $has_children;
			}
			if ( $has_children ) {
				continue;
			}

			if ( JobTypes::DOMAIN_IMPORT_PREFLIGHT === $type ) {
				$cleared = $this->imports->clear( $job_key );
				if ( is_wp_error( $cleared ) ) {
					return $cleared;
				}
			}

			if ( in_array( $type, array( JobTypes::DOMAIN_RESTORE_PREFLIGHT, JobTypes::DOMAIN_RESTORE_REPLACE, JobTypes::DOMAIN_RESTORE_MERGE ), true ) ) {
				$cleared = $this->restore_maps->clear( $job_key );
				if ( is_wp_error( $cleared ) ) {
					return $cleared;
				}
			}

			$deleted = $this->jobs->delete_terminal( $job_key );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
			$deleted_jobs += $deleted ? 1 : 0;
		}

		$mapping_files = $this->files->cleanup_purpose_older_than( 'import-map', HOUR_IN_SECONDS, 100 );
		if ( is_wp_error( $mapping_files ) ) {
			return $mapping_files;
		}
		$deleted_files = $this->files->cleanup_older_than( self::RETENTION_SECONDS, 100 );
		if ( is_wp_error( $deleted_files ) ) {
			return $deleted_files;
		}

		return array(
			'jobs'  => $deleted_jobs,
			'files' => absint( $deleted_files ) + absint( $mapping_files ),
		);
	}
}
