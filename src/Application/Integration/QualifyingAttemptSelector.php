<?php
namespace Certloom\Application\Integration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Canonical LMS attempt eligibility policy.
 *
 * A provider's generic pass state is evidence, not certificate authority. A
 * Certloom credential is eligible only when the attempt is complete and its
 * percentage satisfies the certificate-specific threshold. Among eligible
 * attempts, the latest deterministic attempt wins.
 */
final class QualifyingAttemptSelector {
	/** @return array<string,mixed>|null|\WP_Error */
	public function select( array $attempts, $certificate_threshold ) {
		$threshold = $this->percentage( $certificate_threshold, 'certloom_integration_certificate_threshold_invalid', __( 'The certificate eligibility threshold must be between 0 and 100.', 'certloom' ) );
		if ( is_wp_error( $threshold ) ) { return $threshold; }
		if ( count( $attempts ) > IntegrationEventContext::MAX_ATTEMPTS ) {
			return new \WP_Error( 'certloom_integration_attempt_limit', __( 'The integration event contains too many attempts for one bounded eligibility decision.', 'certloom' ) );
		}
		$eligible = array();
		$seen = array();
		foreach ( $attempts as $index => $attempt ) {
			if ( ! is_array( $attempt ) ) {
				return new \WP_Error( 'certloom_integration_attempt_invalid', __( 'Every integration attempt must be a structured record.', 'certloom' ) );
			}
			$normalized = $this->normalize_attempt( $attempt, $index );
			if ( is_wp_error( $normalized ) ) { return $normalized; }
			$id = $normalized['attempt_id'];
			if ( isset( $seen[ $id ] ) ) {
				return new \WP_Error( 'certloom_integration_attempt_duplicate', __( 'The integration event contains a duplicate attempt identifier.', 'certloom' ) );
			}
			$seen[ $id ] = true;
			if ( $normalized['completed'] && $normalized['percentage'] >= $threshold ) {
				$eligible[] = $normalized;
			}
		}
		if ( empty( $eligible ) ) { return null; }
		usort( $eligible, static function ( array $a, array $b ) {
			$time = strcmp( $a['attempted_at'], $b['attempted_at'] );
			if ( 0 !== $time ) { return $time; }
			return strcmp( $a['attempt_id'], $b['attempt_id'] );
		} );
		return $eligible[ count( $eligible ) - 1 ];
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_attempt( array $attempt, $index = 0 ) {
		$id = $this->stable_id( $attempt['attempt_id'] ?? '', 'certloom_integration_attempt_id_invalid', __( 'Each integration attempt requires a stable platform attempt identifier.', 'certloom' ) );
		if ( is_wp_error( $id ) ) { return $id; }
		$at = trim( (string) ( $attempt['attempted_at'] ?? '' ) );
		if ( ! $this->is_utc_datetime( $at ) ) {
			return new \WP_Error( 'certloom_integration_attempt_time_invalid', __( 'Each integration attempt requires a valid UTC attempt timestamp.', 'certloom' ) );
		}
		$percentage = $this->percentage( $attempt['percentage'] ?? null, 'certloom_integration_attempt_percentage_invalid', __( 'Attempt percentage must be between 0 and 100.', 'certloom' ) );
		if ( is_wp_error( $percentage ) ) { return $percentage; }
		$pass_threshold = null;
		if ( array_key_exists( 'pass_threshold', $attempt ) && null !== $attempt['pass_threshold'] && '' !== $attempt['pass_threshold'] ) {
			$pass_threshold = $this->percentage( $attempt['pass_threshold'], 'certloom_integration_pass_threshold_invalid', __( 'The source pass threshold must be between 0 and 100.', 'certloom' ) );
			if ( is_wp_error( $pass_threshold ) ) { return $pass_threshold; }
		}
		$score = null;
		if ( array_key_exists( 'score', $attempt ) && null !== $attempt['score'] && '' !== $attempt['score'] ) {
			if ( ! is_numeric( $attempt['score'] ) || ! is_finite( (float) $attempt['score'] ) ) {
				return new \WP_Error( 'certloom_integration_attempt_score_invalid', __( 'Attempt score must be a finite numeric value when provided.', 'certloom' ) );
			}
			$score = (float) $attempt['score'];
		}
		return array(
			'attempt_id' => $id,
			'attempted_at' => $at,
			'completed' => ! empty( $attempt['completed'] ),
			'score' => $score,
			'percentage' => $percentage,
			'pass_threshold' => $pass_threshold,
		);
	}

	/** @return float|\WP_Error */
	private function percentage( $value, $code, $message ) {
		if ( ! is_numeric( $value ) ) { return new \WP_Error( $code, $message ); }
		$value = (float) $value;
		if ( ! is_finite( $value ) || $value < 0 || $value > 100 ) { return new \WP_Error( $code, $message ); }
		return $value;
	}

	/** @return string|\WP_Error */
	private function stable_id( $value, $code, $message ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > IntegrationEventContext::MAX_STABLE_ID_BYTES || ! preg_match( '/^[A-Za-z0-9._:@\/-]+$/', $value ) ) {
			return new \WP_Error( $code, $message );
		}
		return $value;
	}

	private function is_utc_datetime( $value ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $value ) ) { return false; }
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', (string) $value, new \DateTimeZone( 'UTC' ) );
		return $parsed && $parsed->format( 'Y-m-d\TH:i:s\Z' ) === $value;
	}
}
