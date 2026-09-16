<?php
namespace Certloom\Application\Import;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Domain\Credential\StatusService;

/** Complete-file/chunk preflight helper for new-domain imports. Performs no writes. */
final class DomainImportPreflightService {
	const MAX_ERROR_DETAILS = 100;
	private $mapper;
	private $status;

	public function __construct( ?DomainImportRowMapper $mapper = null, ?StatusService $status = null ) {
		$this->mapper = $mapper ?: new DomainImportRowMapper();
		$this->status = $status ?: new StatusService();
	}

	/** @return array<string,mixed> */
	public function preflight( array $rows, $job_key, $row_offset = 0, $allow_historical_expired = false, $today = null ) {
		$summary = array(
			'total' => 0, 'ready' => 0, 'errors' => 0,
			'status_counts' => array(), 'error_details' => array(),
			'display_numbers' => array(),
		);
		$seen = array();
		foreach ( array_values( $rows ) as $index => $row ) {
			$summary['total']++;
			$row_number = absint( $row_offset ) + $index + 1;
			try {
				$plan = $this->mapper->map( is_array( $row ) ? $row : array(), $job_key, $row_number, $allow_historical_expired );
				$number_key = strtolower( trim( (string) $plan['version']['display_number'] ) );
				if ( isset( $seen[ $number_key ] ) ) {
					throw new \InvalidArgumentException( 'Duplicate certificate number in import rows ' . $seen[ $number_key ] . ' and ' . $row_number . '.' );
				}
				$seen[ $number_key ] = $row_number;
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
				$summary['ready']++;
				$code = $status->code();
				$summary['status_counts'][ $code ] = absint( $summary['status_counts'][ $code ] ?? 0 ) + 1;
				$summary['display_numbers'][] = $plan['version']['display_number'];
			} catch ( \Throwable $error ) {
				$summary['errors']++;
				if ( count( $summary['error_details'] ) < self::MAX_ERROR_DETAILS ) {
					$summary['error_details'][] = array( 'row' => $row_number, 'message' => $error->getMessage() );
				}
			}
		}
		return $summary;
	}
}
