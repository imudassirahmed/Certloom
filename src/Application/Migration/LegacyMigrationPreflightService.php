<?php
namespace Certloom\Application\Migration;

use Certloom\Domain\Credential\States;
use Certloom\Domain\Credential\StatusService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Pure, non-writing preflight for the Certloom 1.5 registry -> Series/Version migration.
 *
 * The actual migration job must never be the first place where legacy semantics are
 * interpreted. This service maps each row, independently derives the legacy public
 * verdict, compares it with the new Core Status Service, and returns bounded errors.
 */
final class LegacyMigrationPreflightService {
	/** @var LegacyCertificateMapper */
	private $mapper;
	/** @var StatusService */
	private $status_service;
	/** @var int */
	private $error_detail_limit;

	public function __construct( LegacyCertificateMapper $mapper = null, StatusService $status_service = null, $error_detail_limit = 100 ) {
		$this->mapper              = $mapper ?: new LegacyCertificateMapper();
		$this->status_service      = $status_service ?: new StatusService();
		$this->error_detail_limit  = max( 1, (int) $error_detail_limit );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Legacy rows already decoded from the database.
	 * @param string                         $today Exact Y-m-d date used for parity evaluation.
	 * @return array<string,mixed>
	 */
	public function preflight( array $rows, $today ) {
		$this->assert_date( $today );

		$result = array(
			'total'         => 0,
			'ready'         => 0,
			'errors'        => 0,
			'error_details' => array(),
			'status_counts' => array(
				'valid'     => 0,
				'suspended' => 0,
				'revoked'   => 0,
				'expired'   => 0,
			),
		);

		foreach ( $rows as $row ) {
			$result['total']++;
			try {
				$plan     = $this->mapper->map( $row );
				$expected = $this->expected_new_verdict_from_legacy( $plan, $today );
				$actual   = $this->status_service->evaluate(
					array(
						'issuance_state'      => $plan['version']['issuance_state'],
						'administrative_state' => $plan['series']['administrative_state'],
						'legacy_expired'       => $plan['version']['legacy_expired'],
						'valid_from'           => $plan['version']['valid_from'],
						'expires_at'           => $plan['version']['expires_at'],
						'compliance_state'      => States::COMPLIANCE_NOT_APPLICABLE,
					),
					$today
				);

				if ( $expected !== $actual->code() ) {
					throw new RuntimeException( 'Legacy/new public-status parity mismatch: expected ' . $expected . ', got ' . $actual->code() . '.' );
				}

				$result['ready']++;
				if ( isset( $result['status_counts'][ $actual->code() ] ) ) {
					$result['status_counts'][ $actual->code() ]++;
				}
			} catch ( Throwable $error ) {
				$result['errors']++;
				if ( count( $result['error_details'] ) < $this->error_detail_limit ) {
					$result['error_details'][] = array(
						'legacy_id' => isset( $row['id'] ) ? (int) $row['id'] : null,
						'message'   => $error->getMessage(),
					);
				}
			}
		}

		return $result;
	}

	/**
	 * The old 1.5 effective-status semantics, normalized into the final new machine vocabulary.
	 * Withdrawn is intentionally mapped to Revoked.
	 *
	 * @param array<string,array<string,mixed>> $plan Mapper output containing preserved legacy snapshot values.
	 * @return string
	 */
	private function expected_new_verdict_from_legacy( array $plan, $today ) {
		$snapshot      = $plan['version']['snapshot'];
		$status        = (string) $snapshot['legacy_status'];
		$status_source = (string) $snapshot['legacy_status_source'];
		$expiry_date   = $snapshot['expiry_date'];

		if ( 'suspended' === $status ) {
			return 'suspended';
		}
		if ( 'withdrawn' === $status ) {
			return 'revoked';
		}
		if ( 'expired' === $status || 'legacy' === $status_source ) {
			return 'expired';
		}
		if ( null !== $expiry_date && $expiry_date < $today ) {
			return 'expired';
		}
		return 'valid';
	}

	private function assert_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			throw new InvalidArgumentException( 'Migration preflight date must be an exact Y-m-d date.' );
		}
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			throw new InvalidArgumentException( 'Migration preflight date is not a valid calendar date.' );
		}
	}
}
