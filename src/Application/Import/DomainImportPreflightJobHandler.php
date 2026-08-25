<?php
namespace Certloom\Application\Import;

use Certloom\Application\Contract\DomainImportStageInterface;
use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Import\DomainImportCsvReader;

/**
 * Resumable complete-file CSV preflight.
 *
 * No credential-domain write is performed here. Every accepted row is
 * normalized and durably staged; commit is a separate explicitly-confirmed
 * background job that consumes only those staged plans.
 */
final class DomainImportPreflightJobHandler implements JobHandlerInterface {
	/** @var DomainImportCsvReader */
	private $reader;
	/** @var DomainImportStagingService */
	private $staging;
	/** @var DomainImportStageInterface */
	private $stage;
	/** @var DomainImportConsistencyGuard */
	private $consistency;
	/** @var PrivateJobFileStore */
	private $files;
	/** @var int */
	private $batch_size;

	public function __construct(
		DomainImportCsvReader $reader,
		DomainImportStagingService $staging,
		DomainImportStageInterface $stage,
		?DomainImportConsistencyGuard $consistency = null,
		?PrivateJobFileStore $files = null,
		$batch_size = 250
	) {
		$this->reader      = $reader;
		$this->staging     = $staging;
		$this->stage       = $stage;
		$this->consistency = $consistency ?: new DomainImportConsistencyGuard();
		$this->files       = $files ?: new PrivateJobFileStore();
		$this->batch_size  = max( 1, min( 500, absint( $batch_size ) ) );
	}

	/** @return JobSliceResult|\WP_Error */
	public function handle( array $job, $worker_token ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Worker lease is enforced by JobRunner.
		$payload = isset( $job['payload'] ) && is_array( $job['payload'] ) ? $job['payload'] : array();
		$job_key = (string) ( $job['job_key'] ?? '' );
		$path    = (string) ( $payload['path'] ?? '' );

		$consistent = $this->consistency->validate(
			(string) ( $payload['baseline_generation'] ?? '' ),
			(string) ( $payload['baseline_configuration_hash'] ?? '' )
		);
		if ( is_wp_error( $consistent ) ) {
			$cleanup = $this->cleanup( $job_key, $path );
			return is_wp_error( $cleanup ) ? $cleanup : $consistent;
		}

		$state = isset( $job['result']['import_state'] ) && is_array( $job['result']['import_state'] )
			? $job['result']['import_state']
			: null;
		$aggregate = isset( $job['result']['summary'] ) && is_array( $job['result']['summary'] )
			? $this->normalize_summary( $job['result']['summary'] )
			: $this->empty_summary();

		if ( null === $state ) {
			$state = $this->reader->start(
				$path,
				isset( $payload['mapping'] ) && is_array( $payload['mapping'] ) ? $payload['mapping'] : array(),
				! empty( $payload['clear_empty'] ),
				absint( $payload['max_rows'] ?? DomainImportCsvReader::MAX_ROWS )
			);
			if ( is_wp_error( $state ) ) {
				$cleanup = $this->cleanup( $job_key, $path );
				return is_wp_error( $cleanup ) ? $cleanup : $state;
			}
		}

		$slice = $this->reader->continue_read( $state, $this->batch_size );
		if ( is_wp_error( $slice ) ) {
			$cleanup = $this->cleanup( $job_key, $path );
			return is_wp_error( $cleanup ) ? $cleanup : $slice;
		}
		$state = $slice['state'];
		foreach ( (array) $slice['records'] as $record ) {
			$row_number = absint( $record['row_number'] ?? 0 );
			$aggregate['processed']++;
			$aggregate['last_row'] = max( $aggregate['last_row'], $row_number );
			if ( isset( $record['error'] ) && is_wp_error( $record['error'] ) ) {
				$this->add_error( $aggregate, $row_number, $record['error']->get_error_message() );
				continue;
			}
			$staged = $this->staging->stage_row(
				isset( $record['row'] ) && is_array( $record['row'] ) ? $record['row'] : array(),
				$job_key,
				$row_number,
				! empty( $payload['allow_historical_expired'] ),
				isset( $payload['today'] ) ? (string) $payload['today'] : null
			);
			if ( is_wp_error( $staged ) ) {
				$this->add_error( $aggregate, $row_number, $staged->get_error_message() );
				continue;
			}
			$outcome = (string) ( $staged['outcome'] ?? '' );
			if ( 'ready' === $outcome ) { $aggregate['ready']++; }
			elseif ( 'duplicate' === $outcome ) { $aggregate['duplicates']++; }
			elseif ( 'already_staged' === $outcome ) { $aggregate['already_staged']++; }
			$status_code = sanitize_key( (string) ( $staged['status_code'] ?? '' ) );
			if ( '' !== $status_code ) {
				$aggregate['status_counts'][ $status_code ] = absint( $aggregate['status_counts'][ $status_code ] ?? 0 ) + 1;
			}
		}

		if ( empty( $state['complete'] ) ) {
			return JobSliceResult::progress(
				absint( $aggregate['last_row'] ),
				'import_preflight',
				array( 'import_state' => $state, 'summary' => $aggregate )
			);
		}

		$stage_summary = $this->stage->summary( $job_key );
		if ( is_wp_error( $stage_summary ) ) {
			$cleanup = $this->cleanup( $job_key, $path );
			return is_wp_error( $cleanup ) ? $cleanup : $stage_summary;
		}
		// Durable staging is the authority for ready/duplicate counts after retries.
		$aggregate['ready']      = absint( $stage_summary['ready'] ?? 0 );
		$aggregate['duplicates'] = absint( $stage_summary['duplicate'] ?? 0 );
		$aggregate['staged']     = absint( $stage_summary['total'] ?? 0 );
		$strict                  = ! array_key_exists( 'strict', $payload ) || ! empty( $payload['strict'] );
		$eligible                = $aggregate['ready'] + $aggregate['duplicates'];
		$ready_for_commit        = $eligible > 0 && ( ! $strict || 0 === $aggregate['errors'] );

		$deleted = $this->files->delete( $path );
		if ( is_wp_error( $deleted ) || false === $deleted ) {
			$aggregate['warnings'][] = __( 'The import preflight completed, but Certloom could not remove its protected source copy. Cleanup will be retried later.', 'certloom' );
		}

		$result = array(
			'preflight_job_key'           => $job_key,
			'baseline_generation'         => (string) ( $payload['baseline_generation'] ?? '' ),
			'baseline_configuration_hash' => (string) ( $payload['baseline_configuration_hash'] ?? '' ),
			'summary'                     => $aggregate,
			'ready_for_commit'            => $ready_for_commit,
			'strict'                      => $strict,
		);
		return $aggregate['errors'] > 0
			? JobSliceResult::partially_succeeded( $result, absint( $aggregate['last_row'] ) )
			: JobSliceResult::succeeded( $result, absint( $aggregate['last_row'] ) );
	}

	/** @return array<string,mixed> */
	private function empty_summary() {
		return array(
			'processed'      => 0,
			'staged'         => 0,
			'ready'          => 0,
			'duplicates'     => 0,
			'already_staged' => 0,
			'errors'         => 0,
			'last_row'       => 0,
			'status_counts'  => array(),
			'error_details'  => array(),
			'warnings'       => array(),
		);
	}

	/** @return array<string,mixed> */
	private function normalize_summary( array $summary ) {
		$base = $this->empty_summary();
		foreach ( array( 'processed', 'staged', 'ready', 'duplicates', 'already_staged', 'errors', 'last_row' ) as $key ) {
			$base[ $key ] = absint( $summary[ $key ] ?? 0 );
		}
		$base['status_counts'] = isset( $summary['status_counts'] ) && is_array( $summary['status_counts'] ) ? $summary['status_counts'] : array();
		$base['error_details'] = isset( $summary['error_details'] ) && is_array( $summary['error_details'] ) ? array_slice( $summary['error_details'], 0, DomainImportStagingService::MAX_ERROR_DETAILS ) : array();
		$base['warnings'] = isset( $summary['warnings'] ) && is_array( $summary['warnings'] ) ? array_slice( $summary['warnings'], 0, 20 ) : array();
		return $base;
	}

	private function add_error( array &$summary, $row_number, $message ) {
		$summary['errors']++;
		if ( count( $summary['error_details'] ) < DomainImportStagingService::MAX_ERROR_DETAILS ) {
			$summary['error_details'][] = array( 'row' => absint( $row_number ), 'message' => sanitize_text_field( (string) $message ) );
		}
	}

	/** @return true|\WP_Error */
	private function cleanup( $job_key, $path ) {
		$stage = $this->stage->clear( $job_key );
		$file = true;
		if ( '' !== (string) $path && $this->files->owns( $path ) ) { $file = $this->files->delete( $path ); }
		if ( is_wp_error( $stage ) || is_wp_error( $file ) || false === $file ) {
			return new \WP_Error( 'certloom_import_preflight_cleanup_failed', __( 'Certloom stopped the import preflight, but could not prove that all private staging/source data was removed. Cleanup must be retried before treating the failure as fully resolved.', 'certloom' ) );
		}
		return true;
	}
}
