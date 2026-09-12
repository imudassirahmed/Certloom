<?php
namespace Certloom\Application\Program;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Canonical Program lifecycle and repeat-issuance policy.
 *
 * ProgramProfile owns the administrator configuration while the historical
 * programs.validity_days column remains a compatibility projection for older
 * integrations and pre-v3 Program Profiles. Issued credential Versions always
 * freeze the exact dates actually used at issuance time.
 */
final class ProgramLifecyclePolicy {
	const VALIDITY_LEGACY = 'legacy';
	const VALIDITY_PERMANENT = 'permanent';
	const VALIDITY_DURATION = 'duration';
	const VALIDITY_ISSUANCE = 'issuance';

	const REPEAT_LIFECYCLE = 'lifecycle';
	const REPEAT_BATCH = 'batch';
	const REPEAT_SEPARATE = 'separate';

	/** @return array<string,string> */
	public static function validity_labels() {
		return array(
			self::VALIDITY_PERMANENT => __( 'Does not expire', 'certloom' ),
			self::VALIDITY_DURATION => __( 'Expires after a duration', 'certloom' ),
			self::VALIDITY_ISSUANCE => __( 'Decide at issuance', 'certloom' ),
		);
	}

	/** @return array<string,string> */
	public static function unit_labels() {
		return array(
			'days' => __( 'Days', 'certloom' ),
			'months' => __( 'Months', 'certloom' ),
			'years' => __( 'Years', 'certloom' ),
		);
	}

	/** @return array<string,string> */
	public static function renewal_labels() {
		return array(
			'not_applicable' => __( 'No renewal workflow', 'certloom' ),
			'optional' => __( 'Renewal optional', 'certloom' ),
			'required' => __( 'Renewal required', 'certloom' ),
		);
	}

	/** @return array<string,string> */
	public static function repeat_labels() {
		return array(
			self::REPEAT_LIFECYCLE => __( 'Maintain one credential lifecycle', 'certloom' ),
			self::REPEAT_BATCH => __( 'One credential per Batch / session', 'certloom' ),
			self::REPEAT_SEPARATE => __( 'Allow separate credentials', 'certloom' ),
		);
	}

	/**
	 * Resolve a full Program row into one coherent lifecycle policy.
	 *
	 * v1/v2 profiles intentionally resolve through VALIDITY_LEGACY so their
	 * existing validity_days meaning is preserved until an administrator saves
	 * the Program through the v3 lifecycle UI.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function resolve( array $program ) {
		$profile = isset( $program['profile'] ) && is_array( $program['profile'] ) ? $program['profile'] : array();
		$mode = sanitize_key( (string) ( $profile['validity_mode'] ?? self::VALIDITY_LEGACY ) );
		if ( ! in_array( $mode, array( self::VALIDITY_LEGACY, self::VALIDITY_PERMANENT, self::VALIDITY_DURATION, self::VALIDITY_ISSUANCE ), true ) ) {
			return new \WP_Error( 'certloom_program_lifecycle_validity_invalid', __( 'The Program has an unsupported credential validity policy.', 'certloom' ) );
		}

		$value = absint( $profile['validity_value'] ?? 0 );
		$unit = sanitize_key( (string) ( $profile['validity_unit'] ?? 'days' ) );
		if ( ! in_array( $unit, array( 'days', 'months', 'years' ), true ) ) { $unit = 'days'; }
		$legacy = self::VALIDITY_LEGACY === $mode;
		if ( $legacy ) {
			$legacy_days = null !== ( $program['validity_days'] ?? null ) ? absint( $program['validity_days'] ) : 0;
			if ( $legacy_days > 0 ) {
				$mode = self::VALIDITY_DURATION;
				$value = $legacy_days;
				$unit = 'days';
			} else {
				$mode = self::VALIDITY_PERMANENT;
				$value = 0;
				$unit = 'days';
			}
		}
		if ( self::VALIDITY_DURATION === $mode && $value < 1 ) {
			return new \WP_Error( 'certloom_program_lifecycle_duration_required', __( 'A duration-based Program must define a positive validity duration.', 'certloom' ) );
		}
		if ( self::VALIDITY_DURATION !== $mode ) { $value = 0; }

		$renewal = sanitize_key( (string) ( $profile['renewal'] ?? 'not_applicable' ) );
		if ( ! in_array( $renewal, array( 'not_applicable', 'optional', 'required' ), true ) ) { $renewal = 'not_applicable'; }
		$coherence_warning = false;
		if ( self::VALIDITY_PERMANENT === $mode && 'not_applicable' !== $renewal ) {
			// Preserve old contradictory Programs as readable historical config, but
			// flag them for review. New v3 saves reject this combination.
			$coherence_warning = $legacy;
			if ( ! $legacy ) { return new \WP_Error( 'certloom_program_lifecycle_permanent_renewal_conflict', __( 'A credential that does not expire cannot require an after-expiry renewal workflow.', 'certloom' ) ); }
		}

		$repeat = sanitize_key( (string) ( $profile['repeat_policy'] ?? self::REPEAT_LIFECYCLE ) );
		if ( ! in_array( $repeat, array( self::REPEAT_LIFECYCLE, self::REPEAT_BATCH, self::REPEAT_SEPARATE ), true ) ) { $repeat = self::REPEAT_LIFECYCLE; }

		return array(
			'validity_mode' => $mode,
			'validity_value' => $value,
			'validity_unit' => $unit,
			'validity_days' => self::VALIDITY_DURATION === $mode ? $this->compatibility_days( $value, $unit ) : null,
			'program_default_available' => self::VALIDITY_ISSUANCE !== $mode,
			'renewal' => $renewal,
			'repeat_policy' => $repeat,
			'legacy' => $legacy,
			'coherence_warning' => $coherence_warning,
		);
	}

	/** @return int */
	public function compatibility_days( $value, $unit ) {
		$value = max( 0, absint( $value ) );
		$unit = sanitize_key( (string) $unit );
		if ( 'years' === $unit ) { return $value * 365; }
		if ( 'months' === $unit ) { return $value * 30; }
		return $value;
	}

	/** @return string|null|\WP_Error */
	public function expiry_date( $valid_from, array $policy ) {
		$mode = (string) ( $policy['validity_mode'] ?? '' );
		if ( self::VALIDITY_PERMANENT === $mode ) { return null; }
		if ( self::VALIDITY_ISSUANCE === $mode ) {
			return new \WP_Error( 'certloom_program_lifecycle_decide_at_issuance', __( 'This Program does not define a default expiry. Choose No expiry or a specific expiry date for this credential.', 'certloom' ) );
		}
		if ( self::VALIDITY_DURATION !== $mode ) {
			return new \WP_Error( 'certloom_program_lifecycle_validity_invalid', __( 'The Program validity policy could not be resolved safely.', 'certloom' ) );
		}
		$value = absint( $policy['validity_value'] ?? 0 );
		$unit = sanitize_key( (string) ( $policy['validity_unit'] ?? 'days' ) );
		if ( $value < 1 || ! in_array( $unit, array( 'days', 'months', 'years' ), true ) ) {
			return new \WP_Error( 'certloom_program_lifecycle_duration_invalid', __( 'The Program validity duration is incomplete.', 'certloom' ) );
		}
		try {
			return ( new \DateTimeImmutable( (string) $valid_from . ' 00:00:00', new \DateTimeZone( 'UTC' ) ) )
				->modify( '+' . $value . ' ' . $unit )
				->format( 'Y-m-d' );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_program_lifecycle_expiry_calculation_failed', __( 'Certloom could not calculate the Program credential expiry date.', 'certloom' ) );
		}
	}

	/** @return string */
	public function validity_summary( array $policy ) {
		$mode = (string) ( $policy['validity_mode'] ?? '' );
		if ( self::VALIDITY_PERMANENT === $mode ) { return __( 'Does not expire', 'certloom' ); }
		if ( self::VALIDITY_ISSUANCE === $mode ) { return __( 'Decide at issuance', 'certloom' ); }
		$value = absint( $policy['validity_value'] ?? 0 );
		$unit = (string) ( $policy['validity_unit'] ?? 'days' );
		if ( 'years' === $unit ) { return sprintf( /* translators: %d: Program credential validity length in years. */ _n( '%d year', '%d years', $value, 'certloom' ), $value ); }
		if ( 'months' === $unit ) { return sprintf( /* translators: %d: Program credential validity length in months. */ _n( '%d month', '%d months', $value, 'certloom' ), $value ); }
		return sprintf( /* translators: %d: Program credential validity length in days. */ _n( '%d day', '%d days', $value, 'certloom' ), $value );
	}
}
