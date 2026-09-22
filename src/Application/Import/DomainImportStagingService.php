<?php
namespace Certloom\Application\Import;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\DomainImportStageInterface;
use Certloom\Domain\Credential\StatusService;

/** Resumable complete-file preflight that persists normalized plans and global duplicate state. */
final class DomainImportStagingService {
	const MAX_ERROR_DETAILS = 100;
	private $stage;
	private $mapper;
	private $status;

	public function __construct( DomainImportStageInterface $stage, ?DomainImportRowMapper $mapper = null, ?StatusService $status = null ) {
		$this->stage = $stage;
		$this->mapper = $mapper ?: new DomainImportRowMapper();
		$this->status = $status ?: new StatusService();
	}

	/** @return array<string,mixed> */
	public function stage_batch( array $rows, $job_key, $row_offset = 0, $allow_historical_expired = false, $today = null ) {
		$result = array( 'processed' => 0, 'ready' => 0, 'duplicates' => 0, 'already_staged' => 0, 'errors' => 0, 'status_counts' => array(), 'error_details' => array(), 'last_row' => absint( $row_offset ) );
		foreach ( array_values( $rows ) as $index => $row ) {
			$row_number = absint( $row_offset ) + $index + 1;
			$result['processed']++;
			$result['last_row'] = $row_number;
			$staged = $this->stage_row( is_array( $row ) ? $row : array(), $job_key, $row_number, $allow_historical_expired, $today );
			if ( is_wp_error( $staged ) ) {
				$result['errors']++;
				if ( count( $result['error_details'] ) < self::MAX_ERROR_DETAILS ) {
					$result['error_details'][] = array( 'row' => $row_number, 'message' => $staged->get_error_message() );
				}
				continue;
			}
			$outcome = (string) ( $staged['outcome'] ?? '' );
			if ( 'ready' === $outcome ) { $result['ready']++; }
			elseif ( 'duplicate' === $outcome ) { $result['duplicates']++; }
			elseif ( 'already_staged' === $outcome ) { $result['already_staged']++; }
			$code = (string) ( $staged['status_code'] ?? '' );
			if ( '' !== $code ) { $result['status_counts'][ $code ] = absint( $result['status_counts'][ $code ] ?? 0 ) + 1; }
		}
		return $result;
	}

	/** Stages one explicit CSV record number for resumable file readers. @return array<string,mixed>|\WP_Error */
	public function stage_row( array $row, $job_key, $row_number, $allow_historical_expired = false, $today = null ) {
		try {
			$plan = $this->mapper->map( $row, $job_key, $row_number, $allow_historical_expired );
			$status = $this->status->evaluate(
				array(
					'issuance_state' => $plan['version']['issuance_state'],
					'administrative_state' => $plan['series']['administrative_state'],
					'legacy_expired' => ! empty( $plan['version']['legacy_expired'] ),
					'valid_from' => $plan['version']['valid_from'],
					'expires_at' => $plan['version']['expires_at'],
					'compliance_state' => 'not_applicable',
				),
				$today
			);
			$staged = $this->stage->stage( $job_key, absint( $row_number ), $plan );
			if ( is_wp_error( $staged ) ) { return $staged; }
			$outcome = (string) ( $staged['outcome'] ?? '' );
			if ( ! in_array( $outcome, array( 'ready', 'duplicate', 'already_staged' ), true ) ) {
				return new \WP_Error( 'certloom_import_stage_outcome_invalid', __( 'The import staging repository returned an unknown outcome.', 'certloom' ) );
			}
			$staged['status_code'] = $status->code();
			return $staged;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_import_row_invalid', $error->getMessage() );
		}
	}

}
