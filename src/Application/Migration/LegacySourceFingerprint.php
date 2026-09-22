<?php
namespace Certloom\Application\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic privacy-safe fingerprint for legacy 1.5 migration source rows.
 *
 * The digest is evidence only; source values are never persisted by this class.
 */
final class LegacySourceFingerprint {
	const SCHEMA = 'certloom-legacy-source-fingerprint-v1';

	/** @return string */
	public function seed() {
		return hash( 'sha256', self::SCHEMA );
	}

	/** @return string|\WP_Error */
	public function advance( $current, array $rows ) {
		$current = strtolower( trim( (string) $current ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $current ) ) {
			return new \WP_Error( 'certloom_legacy_fingerprint_invalid', __( 'Certloom refused invalid legacy-source fingerprint state.', 'certloom' ) );
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'certloom_legacy_fingerprint_row_invalid', __( 'Certloom encountered an invalid legacy row while fingerprinting migration input.', 'certloom' ) );
			}
			$payload = array(
				'id'            => absint( $row['id'] ?? 0 ),
				'cert_number'   => (string) ( $row['cert_number'] ?? '' ),
				'status'        => (string) ( $row['status'] ?? '' ),
				'status_source' => (string) ( $row['status_source'] ?? '' ),
				'holder_name'   => (string) ( $row['holder_name'] ?? '' ),
				'issue_date'    => $row['issue_date'] ?? null,
				'expiry_date'   => $row['expiry_date'] ?? null,
				'custom_fields' => $row['custom_fields'] ?? null,
				'created_at'    => $row['created_at'] ?? null,
				'updated_at'    => $row['updated_at'] ?? null,
			);
			$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $json ) {
				return new \WP_Error( 'certloom_legacy_fingerprint_encode_failed', __( 'A legacy migration row could not be encoded deterministically for source verification.', 'certloom' ) );
			}
			$current = hash( 'sha256', $current . "\n" . $json );
		}
		return $current;
	}
}
