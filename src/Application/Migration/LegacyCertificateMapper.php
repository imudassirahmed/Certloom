<?php
namespace Certloom\Application\Migration;

use Certloom\Domain\Credential\States;
use InvalidArgumentException;

/**
 * Pure migration mapper from the proven Certloom 1.5 registry row into the
 * next-generation Recipient / Credential Series / immutable Version write plan.
 *
 * This class performs no database writes. The eventual resumable migration job
 * can therefore preflight every legacy row and compare public-status parity
 * before committing anything.
 */
final class LegacyCertificateMapper {
	/**
	 * @param array<string,mixed> $row Decoded legacy certificate row.
	 * @return array<string,array<string,mixed>>
	 */
	public function map( array $row ) {
		$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
		if ( $id <= 0 ) {
			throw new InvalidArgumentException( 'Legacy certificate ID is required for deterministic migration.' );
		}

		$display_number = trim( (string) ( $row['cert_number'] ?? '' ) );
		$holder_name    = trim( (string) ( $row['holder_name'] ?? '' ) );
		if ( '' === $display_number || '' === $holder_name ) {
			throw new InvalidArgumentException( 'Legacy certificate number and holder name are required.' );
		}

		$status = strtolower( trim( (string) ( $row['status'] ?? 'valid' ) ) );
		if ( ! in_array( $status, array( 'valid', 'suspended', 'withdrawn', 'expired' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown legacy certificate status: ' . $status );
		}
		$status_source = strtolower( trim( (string) ( $row['status_source'] ?? ( 'expired' === $status ? 'legacy' : 'manual' ) ) ) );
		if ( ! in_array( $status_source, array( 'manual', 'date', 'legacy' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown legacy certificate status source: ' . $status_source );
		}

		$issue_date  = $this->nullable_date( $row['issue_date'] ?? null, 'issue_date' );
		$expiry_date = $this->nullable_date( $row['expiry_date'] ?? null, 'expiry_date' );
		$created_at  = $this->nullable_datetime( $row['created_at'] ?? null, 'created_at' );
		$updated_at  = $this->nullable_datetime( $row['updated_at'] ?? null, 'updated_at' );
		$custom      = $this->custom_fields( $row['custom_fields'] ?? array() );

		$administrative_state = States::ADMIN_ACTIVE;
		if ( 'suspended' === $status ) {
			$administrative_state = States::ADMIN_SUSPENDED;
		} elseif ( 'withdrawn' === $status ) {
			/* Final Certloom semantics normalize historical Withdrawn to Revoked. */
			$administrative_state = States::ADMIN_REVOKED;
		}

		$legacy_expired = 'expired' === $status || 'legacy' === $status_source;
		$issued_at      = $created_at;
		if ( null === $issued_at && null !== $issue_date ) {
			$issued_at = $issue_date . ' 00:00:00';
		}

		return array(
			'recipient' => array(
				'display_name' => $holder_name,
			),
			'series' => array(
				'administrative_state' => $administrative_state,
				'compliance_state'     => States::COMPLIANCE_NOT_APPLICABLE,
				'visibility'           => 'verification_summary',
			),
			'version' => array(
				'display_number'         => $display_number,
				'issuance_state'         => States::ISSUANCE_ISSUED,
				/* Legacy issue_date was informational, not a not-yet-valid gate. */
				'valid_from'             => null,
				'issued_at'              => $issued_at,
				'expires_at'             => $expiry_date,
				'legacy_expired'         => $legacy_expired,
				'source_type'            => 'certloom_legacy',
				'source_ref'             => (string) $id,
				'source_idempotency_key' => hash( 'sha256', 'certloom-legacy-certificate:' . $id ),
				'snapshot'               => array(
					'snapshot_schema'       => 'certloom-legacy-1',
					'legacy_certificate_id' => $id,
					'legacy_status'         => $status,
					'legacy_status_source'  => $status_source,
					'holder_name'           => $holder_name,
					'issue_date'            => $issue_date,
					'expiry_date'           => $expiry_date,
					'custom_fields'         => $custom,
					'created_at'            => $created_at,
					'updated_at'            => $updated_at,
				),
			),
		);
	}

	/** @return array<string,mixed> */
	private function custom_fields( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new InvalidArgumentException( 'Legacy custom fields contain invalid JSON and require review before migration.' );
		}
		return $decoded;
	}

	/** @return string|null */
	private function nullable_date( $value, $field ) {
		$value = null === $value ? '' : trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			throw new InvalidArgumentException( $field . ' must be an exact Y-m-d date.' );
		}
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			throw new InvalidArgumentException( $field . ' is not a valid calendar date.' );
		}
		return $value;
	}

	/** @return string|null */
	private function nullable_datetime( $value, $field ) {
		$value = null === $value ? '' : trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new InvalidArgumentException( $field . ' must be an exact MySQL datetime.' );
		}
		return $value;
	}
}
