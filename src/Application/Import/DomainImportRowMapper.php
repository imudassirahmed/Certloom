<?php
namespace Certloom\Application\Import;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Domain\Credential\States;
use InvalidArgumentException;

/** Pure mapper from an import row into Recipient/Series/immutable-Version plan. */
final class DomainImportRowMapper {
	/**
	 * @param array<string,mixed> $row Normalized import row using Certloom machine keys.
	 * @return array<string,array<string,mixed>>
	 */
	public function map( array $row, $job_key, $row_number, $allow_historical_expired = false ) {
		$job_key = strtolower( trim( (string) $job_key ) );
		$row_number = absint( $row_number );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $job_key ) || $row_number < 1 ) {
			throw new InvalidArgumentException( 'A valid import job and row number are required.' );
		}

		$display_number = trim( (string) ( $row['cert_number'] ?? '' ) );
		$holder_name    = trim( (string) ( $row['holder_name'] ?? '' ) );
		if ( '' === $display_number || '' === $holder_name ) {
			throw new InvalidArgumentException( 'Certificate number and holder name are required.' );
		}
		if ( self::length( $display_number ) > 100 || self::length( $holder_name ) > 255 ) {
			throw new InvalidArgumentException( 'Certificate number or holder name exceeds the supported length.' );
		}

		$status_provided = array_key_exists( 'status', $row );
		$status = strtolower( trim( (string) ( $row['status'] ?? 'valid' ) ) );
		$allowed = array( 'valid', 'suspended', 'withdrawn' );
		if ( $allow_historical_expired ) { $allowed[] = 'expired'; }
		if ( ! in_array( $status, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Unsupported imported status: ' . $status );
		}

		$issue_date_provided = array_key_exists( 'issue_date', $row );
		$expiry_date_provided = array_key_exists( 'expiry_date', $row );
		$issue_date = $this->nullable_date( $row['issue_date'] ?? null, 'issue_date' );
		$expiry_date = $this->nullable_date( $row['expiry_date'] ?? null, 'expiry_date' );
		if ( null !== $issue_date && null !== $expiry_date && $expiry_date < $issue_date ) {
			throw new InvalidArgumentException( 'Expiry date cannot be earlier than the issue date.' );
		}

		$administrative_state = States::ADMIN_ACTIVE;
		if ( 'suspended' === $status ) { $administrative_state = States::ADMIN_SUSPENDED; }
		if ( 'withdrawn' === $status ) { $administrative_state = States::ADMIN_REVOKED; }
		$custom = $this->custom_fields( $row['custom_fields'] ?? array() );
		$email = trim( (string) ( $row['email'] ?? '' ) );
		if ( '' !== $email && function_exists( 'is_email' ) && ! is_email( $email ) ) {
			throw new InvalidArgumentException( 'Recipient email is invalid.' );
		}

		$recipient_patch = array( 'display_name' => $holder_name );
		$series_patch = array();
		if ( $status_provided ) { $series_patch['administrative_state'] = $administrative_state; }
		if ( array_key_exists( 'email', $row ) ) { $recipient_patch['email'] = '' === $email ? null : $email; }
		if ( array_key_exists( 'external_id', $row ) ) { $recipient_patch['external_id'] = isset( $row['external_id'] ) && '' !== trim( (string) $row['external_id'] ) ? trim( (string) $row['external_id'] ) : null; }
		if ( array_key_exists( 'custom_fields', $row ) ) { $recipient_patch['custom_fields'] = $custom; }

		$source_ref = $job_key . ':' . $row_number;
		return array(
			'recipient' => array(
				'display_name' => $holder_name,
				'email' => '' === $email ? null : $email,
				'external_id' => isset( $row['external_id'] ) && '' !== trim( (string) $row['external_id'] ) ? trim( (string) $row['external_id'] ) : null,
				'custom_fields' => $custom,
			),
			'recipient_patch' => $recipient_patch,
			'series' => array(
				'administrative_state' => $administrative_state,
				'compliance_state' => States::COMPLIANCE_NOT_APPLICABLE,
				'visibility' => isset( $row['visibility'] ) ? sanitize_key( (string) $row['visibility'] ) : 'verification_summary',
			),
			'series_patch' => $series_patch,
			'provided_fields' => array(
				'issue_date' => $issue_date_provided,
				'expiry_date' => $expiry_date_provided,
				'status' => $status_provided,
				'custom_fields' => array_key_exists( 'custom_fields', $row ),
			),
			'version' => array(
				'display_number' => $display_number,
				'issuance_state' => States::ISSUANCE_ISSUED,
				'valid_from' => null,
				'issued_at' => null === $issue_date ? null : $issue_date . ' 00:00:00',
				'expires_at' => $expiry_date,
				'legacy_expired' => 'expired' === $status,
				'source_type' => 'certloom_csv',
				'source_ref' => $source_ref,
				'source_idempotency_key' => hash( 'sha256', 'certloom-csv:' . $source_ref ),
				'snapshot' => array(
					'snapshot_schema' => 'certloom-import-1',
					'import_job' => $job_key,
					'import_row' => $row_number,
					'holder_name' => $holder_name,
					'issue_date' => $issue_date,
					'expiry_date' => $expiry_date,
					'custom_fields' => $custom,
				),
			),
		);
	}

	/** @return array<string,mixed> */
	private function custom_fields( $value ) {
		if ( is_array( $value ) ) { return $value; }
		$value = trim( (string) $value );
		if ( '' === $value ) { return array(); }
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new InvalidArgumentException( 'Custom fields contain invalid JSON.' );
		}
		return $decoded;
	}

	/** @return string|null */
	private function nullable_date( $value, $field ) {
		$value = null === $value ? '' : trim( (string) $value );
		if ( '' === $value ) { return null; }
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			throw new InvalidArgumentException( $field . ' must use YYYY-MM-DD.' );
		}
		list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			throw new InvalidArgumentException( $field . ' is not a valid calendar date.' );
		}
		return $value;
	}

	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
	}
}
