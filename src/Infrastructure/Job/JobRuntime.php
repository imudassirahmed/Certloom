<?php
namespace Certloom\Infrastructure\Job;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Backup\DomainBackupBuilder;
use Certloom\Application\Backup\DomainBackupJobHandler;
use Certloom\Application\DataTools\DomainDataToolsCoordinator;
use Certloom\Application\Job\JobQueue;
use Certloom\Application\Job\JobRunner;
use Certloom\Application\Job\JobService;
use Certloom\Application\Import\DomainImportCommitJobHandler;
use Certloom\Application\Import\DomainImportConsistencyGuard;
use Certloom\Application\Import\DomainImportJobCoordinator;
use Certloom\Application\Import\DomainImportPreflightJobHandler;
use Certloom\Application\Import\DomainImportStagedCommitService;
use Certloom\Application\Import\DomainImportStagingService;
use Certloom\Application\Issuance\BulkIssuanceCoordinator;
use Certloom\Application\Issuance\BulkIssuancePreflightJobHandler;
use Certloom\Application\Issuance\BulkIssuanceCommitJobHandler;
use Certloom\Application\Email\EmailDeliveryCoordinator;
use Certloom\Application\Email\EmailDeliveryJobHandler;
use Certloom\Application\Integration\CredlyPublishJobHandler;
use Certloom\Infrastructure\Issuance\BulkIssuanceCsvReader;
use Certloom\Infrastructure\Issuance\BulkIssuancePlanStore;
use Certloom\Application\Restore\DomainRestoreJobHandler;
use Certloom\Application\Restore\DomainRestoreMergeJobHandler;
use Certloom\Application\Restore\DomainRestorePreflightJobHandler;
use Certloom\Application\Restore\DomainRestoreStagingService;
use Certloom\Infrastructure\Restore\DomainRestoreMergeService;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Import\DomainImportCsvReader;
use Certloom\Infrastructure\Import\DomainImportUploadSession;
use Certloom\Infrastructure\Import\DomainImportStageRepository;
use Certloom\Infrastructure\Import\DomainImportWriter;
use Certloom\Domain\Job\JobTypes;

/** Composition root for the WordPress-local durable job subsystem. */
final class JobRuntime {
	/** @var JobRepository */
	private $repository;
	/** @var JobService */
	private $service;
	/** @var JobDispatcher */
	private $dispatcher;
	/** @var JobQueue */
	private $queue;
	/** @var DomainImportJobCoordinator */
	private $imports;
	/** @var PrivateJobFileStore */
	private $private_files;
	/** @var DomainImportUploadSession */
	private $import_uploads;
	/** @var DomainDataToolsCoordinator */
	private $data_tools;
	/** @var BulkIssuanceCoordinator */
	private $bulk_issuance;
	/** @var EmailDeliveryCoordinator */
	private $email_delivery;

	public function __construct() {
		$this->repository = new JobRepository();
		$this->service    = new JobService( $this->repository );

		$this->private_files = new PrivateJobFileStore();
		$private_files       = $this->private_files;
		$import_stage = new DomainImportStageRepository();
		$import_consistency = new DomainImportConsistencyGuard();
		$import_reader = new DomainImportCsvReader( $private_files );
		$import_staging = new DomainImportStagingService( $import_stage );
		$import_commit = new DomainImportStagedCommitService( $import_stage, new DomainImportWriter() );

		$restore_workspace = new DomainRestoreWorkspace();
		$restore_staging   = new DomainRestoreStagingService( $restore_workspace, $private_files );
		$restore_merge     = new DomainRestoreMergeService( $restore_workspace );

		$bulk_reader = new BulkIssuanceCsvReader( $private_files );
		$bulk_plans = new BulkIssuancePlanStore( $private_files );

		$handlers = array(
			JobTypes::DOMAIN_BACKUP => new DomainBackupJobHandler(
				new DomainBackupBuilder(),
				defined( 'CERTLOOM_VERSION' ) ? CERTLOOM_VERSION : ''
			),
			JobTypes::DOMAIN_RESTORE_PREFLIGHT => new DomainRestorePreflightJobHandler(
				$restore_staging,
				$restore_merge,
				$restore_workspace
			),
			JobTypes::DOMAIN_RESTORE_REPLACE => new DomainRestoreJobHandler( $restore_staging ),
			JobTypes::DOMAIN_RESTORE_MERGE => new DomainRestoreMergeJobHandler( $restore_staging, $restore_merge ),
			JobTypes::DOMAIN_IMPORT_PREFLIGHT => new DomainImportPreflightJobHandler(
				$import_reader,
				$import_staging,
				$import_stage,
				$import_consistency,
				$private_files
			),
			JobTypes::DOMAIN_IMPORT_COMMIT => new DomainImportCommitJobHandler(
				$this->repository,
				$import_commit,
				$import_stage,
				$import_consistency
			),
			JobTypes::BULK_ISSUANCE_PREFLIGHT => new BulkIssuancePreflightJobHandler( $bulk_reader, $bulk_plans, null, null, $private_files ),
			JobTypes::BULK_ISSUANCE_COMMIT => new BulkIssuanceCommitJobHandler( $this->repository, $bulk_plans, null, null, $private_files ),
			JobTypes::EMAIL_DELIVERY => new EmailDeliveryJobHandler(),
			JobTypes::CREDLY_PUBLISH => new CredlyPublishJobHandler(),
		);

		$runner           = new JobRunner( $this->service, $this->repository, $handlers );
		$retention        = new JobRetentionService( $this->repository, $import_stage, null, $private_files );
		$this->dispatcher = new JobDispatcher( $runner, $this->repository, $retention );
		$this->queue      = new JobQueue( $this->service, $this->dispatcher );
		$this->imports    = new DomainImportJobCoordinator(
			$this->queue,
			$this->repository,
			$private_files,
			$import_reader,
			$import_consistency
		);
		$this->import_uploads = new DomainImportUploadSession( $private_files, $import_reader );
		$this->data_tools     = new DomainDataToolsCoordinator( $this->queue, $this->repository, $private_files );
		$this->bulk_issuance  = new BulkIssuanceCoordinator( $this->queue, $this->repository, $private_files, $bulk_reader );
		$this->email_delivery = new EmailDeliveryCoordinator( $this->queue );
	}

	public function register() {
		$this->dispatcher->register();
	}

	/** @return JobQueue */
	public function queue() {
		return $this->queue;
	}

	/** @return JobService */
	public function service() {
		return $this->service;
	}

	/** @return DomainImportJobCoordinator */
	public function imports() {
		return $this->imports;
	}

	/** @return JobRepository */
	public function repository() {
		return $this->repository;
	}

	/** @return PrivateJobFileStore */
	public function private_files() {
		return $this->private_files;
	}

	/** @return DomainImportUploadSession */
	public function import_uploads() {
		return $this->import_uploads;
	}

	/** @return DomainDataToolsCoordinator */
	public function data_tools() {
		return $this->data_tools;
	}

	/** @return BulkIssuanceCoordinator */
	public function bulk_issuance() {
		return $this->bulk_issuance;
	}

	/** @return EmailDeliveryCoordinator */
	public function email_delivery() {
		return $this->email_delivery;
	}

	/**
	 * Explicitly retries one failed job and schedules its next bounded slice.
	 *
	 * A scheduling failure is non-fatal after the durable state is requeued: the
	 * five-minute watchdog will rediscover the queued job and schedule it.
	 *
	 * @return true|\WP_Error
	 */
	public function retry_failed( $job_key ) {
		$job = $this->repository->find( (string) $job_key );
		if ( is_wp_error( $job ) ) { return $job; }
		if ( is_array( $job ) && \Certloom\Domain\Job\JobTypes::EMAIL_DELIVERY === (string) ( $job['job_type'] ?? '' ) ) {
			$error = is_array( $job['error'] ?? null ) ? $job['error'] : array();
			if ( 'certloom_email_delivery_uncertain' === (string) ( $error['code'] ?? '' ) ) {
				return new \WP_Error( 'certloom_email_retry_uncertain_forbidden', __( 'Certloom will not automatically retry an interrupted email whose external delivery outcome is uncertain. Review it and create a new explicit resend if needed.', 'certloom' ) );
			}
		}
		if ( is_array( $job ) && \Certloom\Domain\Job\JobTypes::CREDLY_PUBLISH === (string) ( $job['job_type'] ?? '' ) ) {
			$error = is_array( $job['error'] ?? null ) ? $job['error'] : array();
			if ( 'certloom_credly_publish_uncertain' === (string) ( $error['code'] ?? '' ) ) {
				return new \WP_Error( 'certloom_credly_retry_uncertain_forbidden', __( 'Certloom will not automatically retry this Credly publication because the external result is uncertain. Review the Credly issuer account first.', 'certloom' ) );
			}
		}
		$requeued = $this->service->requeue_failed( (string) $job_key );
		if ( is_wp_error( $requeued ) ) {
			return $requeued;
		}

		$scheduled = $this->dispatcher->schedule( (string) $job_key, 1 );
		if ( is_wp_error( $scheduled ) ) {
			/**
			 * The durable job is already QUEUED. Keep the retry successful and let
			 * the watchdog restore the missing one-shot event instead of asking the
			 * administrator to press Retry again and creating ambiguous state.
			 */
			do_action( 'certloom_job_retry_schedule_deferred', $job_key, $scheduled );
		}

		return true;
	}


	/** @return true|\WP_Error */
	public function pause_queued( $job_key ) {
		return $this->service->pause( (string) $job_key, \Certloom\Domain\Job\JobStates::QUEUED );
	}

	/** @return true|\WP_Error */
	public function resume_paused( $job_key ) {
		$resumed = $this->service->resume( (string) $job_key, \Certloom\Domain\Job\JobStates::PAUSED );
		if ( is_wp_error( $resumed ) ) {
			return $resumed;
		}
		$scheduled = $this->dispatcher->schedule( (string) $job_key, 1 );
		if ( is_wp_error( $scheduled ) ) {
			do_action( 'certloom_job_resume_schedule_deferred', $job_key, $scheduled );
		}
		return true;
	}

	/**
	 * Cancels only a non-running state. Running slices are deliberately allowed
	 * to finish atomically; cancellation can be requested once the job yields.
	 *
	 * @return true|\WP_Error
	 */
	public function cancel_non_running( $job_key ) {
		$job = $this->repository->find( (string) $job_key );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( ! is_array( $job ) ) {
			return new \WP_Error( 'certloom_job_cancel_missing', __( 'That Certloom job is no longer available.', 'certloom' ) );
		}
		$status = (string) ( $job['status'] ?? '' );
		if ( ! in_array( $status, array( \Certloom\Domain\Job\JobStates::QUEUED, \Certloom\Domain\Job\JobStates::PAUSED, \Certloom\Domain\Job\JobStates::FAILED ), true ) ) {
			return new \WP_Error( 'certloom_job_cancel_state_invalid', __( 'Only queued, paused, or failed Certloom jobs can be cancelled safely from this screen.', 'certloom' ) );
		}
		return $this->service->cancel( (string) $job_key, $status );
	}
}
