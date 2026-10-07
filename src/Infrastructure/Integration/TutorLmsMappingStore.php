<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationMappingResolverInterface;
use Certloom\Application\Integration\IntegrationEventContext;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Server-owned Tutor course → Certloom Program/Batch mappings. */
final class TutorLmsMappingStore implements IntegrationMappingResolverInterface {
	public function validate_recovery_value( $value ) {
		return \Certloom\Application\Restore\WholeStateOptionRows::validate( $value, self::SCHEMA_VERSION, 'mappings', self::MAX_MAPPINGS, function ( array $row ) { return $this->normalize_row( $row, false ); }, array( 'course_id' ), 'course_id' );
	}
	const OPTION = 'certloom_tutor_lms_mappings';
	const SCHEMA_VERSION = 1;
	const MAX_MAPPINGS = 500;

	/** @return array<string,mixed>|null|\WP_Error */
	public function resolve_mapping( IntegrationEventContext $context ) {
		if ( TutorLmsAdapter::KEY !== $context->adapter_key() || TutorLmsAdapter::SOURCE_OBJECT_TYPE !== $context->source_object_type() ) {
			return null;
		}
		$course_id = absint( $context->source_object_id() );
		if ( $course_id < 1 || (string) $course_id !== (string) $context->source_object_id() ) { return null; }
		$row = $this->find( $course_id );
		if ( ! is_array( $row ) || empty( $row['active'] ) ) { return null; }
		return array(
			'adapter' => TutorLmsAdapter::KEY,
			'source_object_type' => TutorLmsAdapter::SOURCE_OBJECT_TYPE,
			'source_object_id' => (string) $course_id,
			'program_id' => absint( $row['program_id'] ?? 0 ),
			'batch_id' => absint( $row['batch_id'] ?? 0 ),
			// Stored template_id is legacy mapping data only. Automatic issuance
			// resolves the live Global → Issuer → Program → Batch hierarchy.
			'template_id' => 0,
			'legacy_template_id' => absint( $row['template_id'] ?? 0 ),
			'expiry_mode' => (string) ( $row['expiry_mode'] ?? 'program' ),
			'expires_at' => $row['expires_at'] ?? null,
			'certificate_threshold' => (float) ( $row['certificate_threshold'] ?? 100 ),
			'active' => true,
		);
	}

	/** @return array<string,mixed>|null */
	public function find( $course_id ) {
		$course_id = absint( $course_id );
		$all = $this->all();
		return isset( $all[ (string) $course_id ] ) && is_array( $all[ (string) $course_id ] ) ? $all[ (string) $course_id ] : null;
	}

	/** @return array<string,array<string,mixed>> */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || absint( $stored['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION || ! isset( $stored['mappings'] ) || ! is_array( $stored['mappings'] ) ) { return array(); }
		$out = array();
		foreach ( $stored['mappings'] as $key => $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$normalized = $this->normalize_row( $row, false );
			if ( is_wp_error( $normalized ) ) { continue; }
			$out[ (string) $normalized['course_id'] ] = $normalized;
			if ( count( $out ) >= self::MAX_MAPPINGS ) { break; }
		}
		ksort( $out, SORT_NUMERIC );
		return $out;
	}

	/** @return true|\WP_Error */
	public function save( array $row ) {
		$row = $this->normalize_row( $row, true );
		if ( is_wp_error( $row ) ) { return $row; }
		$all = $this->all();
		$key = (string) $row['course_id'];
		if ( ! isset( $all[ $key ] ) && count( $all ) >= self::MAX_MAPPINGS ) {
			return new \WP_Error( 'certloom_tutor_mapping_limit', __( 'Tutor LMS mappings reached the Certloom safety limit. Remove an unused mapping before adding another.', 'certloom' ) );
		}
		$all[ $key ] = $row;
		ksort( $all, SORT_NUMERIC );
		return $this->persist( $all );
	}

	/** @return true|\WP_Error */
	public function delete( $course_id ) {
		$course_id = absint( $course_id );
		if ( $course_id < 1 ) { return new \WP_Error( 'certloom_tutor_course_invalid', __( 'Choose a valid Tutor LMS course mapping.', 'certloom' ) ); }
		$all = $this->all();
		unset( $all[ (string) $course_id ] );
		return $this->persist( $all );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_row( array $row, $touch ) {
		$course_id = absint( $row['course_id'] ?? 0 );
		$program_id = absint( $row['program_id'] ?? 0 );
		$batch_id = absint( $row['batch_id'] ?? 0 );
		$template_id = absint( $row['template_id'] ?? 0 ); // legacy compatibility only.
		if ( $course_id < 1 || $program_id < 1 ) {
			return new \WP_Error( 'certloom_tutor_mapping_target_invalid', __( 'Tutor LMS mappings require a course and Certloom Program. A Batch is optional; design follows Global → Issuer → Program → Batch.', 'certloom' ) );
		}
		$threshold = isset( $row['certificate_threshold'] ) ? (float) $row['certificate_threshold'] : 100.0;
		if ( ! is_finite( $threshold ) || $threshold < 0 || $threshold > 100 ) {
			return new \WP_Error( 'certloom_tutor_threshold_invalid', __( 'The Tutor LMS certificate threshold must be between 0 and 100.', 'certloom' ) );
		}
		$expiry_mode = strtolower( trim( (string) ( $row['expiry_mode'] ?? 'program' ) ) );
		if ( ! in_array( $expiry_mode, array( 'program', 'permanent', 'date' ), true ) ) {
			return new \WP_Error( 'certloom_tutor_mapping_expiry_invalid', __( 'Choose a valid expiry rule for the Tutor LMS mapping.', 'certloom' ) );
		}
		$expires_at = null;
		if ( 'date' === $expiry_mode ) {
			$expires_at = trim( (string) ( $row['expires_at'] ?? '' ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_at ) ) { return new \WP_Error( 'certloom_tutor_mapping_expiry_invalid', __( 'Choose a valid explicit expiry date for the Tutor LMS mapping.', 'certloom' ) ); }
		}
		$out = array(
			'course_id' => $course_id,
			'program_id' => $program_id,
			'batch_id' => $batch_id,
			'template_id' => $template_id,
			'certificate_threshold' => $threshold,
			'expiry_mode' => $expiry_mode,
			'expires_at' => $expires_at,
			'active' => ! array_key_exists( 'active', $row ) || ! empty( $row['active'] ),
			'updated_at' => trim( (string) ( $row['updated_at'] ?? '' ) ),
		);
		if ( $touch || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $out['updated_at'] ) ) {
			$out['updated_at'] = current_time( 'mysql', true );
		}
		return $out;
	}

	/** @return true|\WP_Error */
	private function persist( array $mappings ) {
		$payload = array( 'schema_version' => self::SCHEMA_VERSION, 'mappings' => $mappings );
		return ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->set( self::OPTION, $payload );
	}
}
