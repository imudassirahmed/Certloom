<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Integration\IntegrationAdapterRegistry;
use Certloom\Application\Integration\IntegrationEventContext;
use Certloom\Application\Integration\IntegrationIssuanceCoordinator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Runtime bridge from Tutor LMS completion to Certloom's INT-01 coordinator. */
final class TutorLmsBridge {
	const LAST_RESULT_OPTION = 'certloom_tutor_lms_last_result';
	const HOOK = 'tutor_course_complete_after';

	/** @return void */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'course_completed' ), 20, 2 );
	}

	/** @return bool */
	public static function authority_ready() {
		return \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready();
	}

	/** @return array<string,mixed> */
	public static function status() {
		$mappings = ( new TutorLmsMappingStore() )->all();
		$last = get_option( self::LAST_RESULT_OPTION, array() );
		return array(
			'available' => TutorLmsAdapter::is_available(),
			'version' => defined( 'TUTOR_VERSION' ) ? sanitize_text_field( (string) TUTOR_VERSION ) : '',
			'authority_ready' => self::authority_ready(),
			'mapping_count' => count( $mappings ),
			'active_mapping_count' => count( array_filter( $mappings, static function ( $row ) { return is_array( $row ) && ! empty( $row['active'] ); } ) ),
			'last_result' => is_array( $last ) ? $last : array(),
		);
	}

	/** Tutor LMS completion hook. @return void */
	public static function course_completed( $course_id, $user_id ) {
		$course_id = absint( $course_id );
		$user_id = absint( $user_id );
		if ( ! TutorLmsAdapter::is_available() || $course_id < 1 || $user_id < 1 ) { return; }
		$store = new TutorLmsMappingStore();
		$mapping = $store->find( $course_id );
		if ( ! is_array( $mapping ) || empty( $mapping['active'] ) ) { return; }
		if ( ! self::authority_ready() ) {
			self::record_result( 'blocked', $course_id, 'certloom_tutor_authority_closed' );
			return;
		}

		$registry = new IntegrationAdapterRegistry( array( new TutorLmsAdapter() ) );
		$adapter = $registry->get( TutorLmsAdapter::KEY );
		if ( is_wp_error( $adapter ) ) { self::record_result( 'failed', $course_id, $adapter->get_error_code() ); return; }
		$event = $adapter->normalize_event( array(
			'course_id' => $course_id,
			'user_id' => $user_id,
			'completed_at' => TutorLmsAdapter::completion_time( $course_id, $user_id ),
			'certificate_threshold' => (float) ( $mapping['certificate_threshold'] ?? 100 ),
		) );
		if ( is_wp_error( $event ) ) { self::record_result( 'failed', $course_id, $event->get_error_code() ); return; }
		$context = IntegrationEventContext::from_array( $event );
		if ( is_wp_error( $context ) ) { self::record_result( 'failed', $course_id, $context->get_error_code() ); return; }
		$coordinator = new IntegrationIssuanceCoordinator( new LinkedRecipientResolver(), $store );
		$review = $coordinator->review( $context );
		if ( is_wp_error( $review ) ) { self::record_result( 'failed', $course_id, $review->get_error_code(), $context->sha256() ); return; }
		$result = $coordinator->issue( $context, (string) $review['review_fingerprint'], 0 );
		if ( is_wp_error( $result ) ) { self::record_result( 'failed', $course_id, $result->get_error_code(), $context->sha256() ); return; }
		$status = ! empty( $result['idempotent_replay'] ) ? 'replayed' : 'issued';
		self::record_result( $status, $course_id, '', $context->sha256(), absint( $result['version_id'] ?? 0 ) );
	}

	/** Privacy-safe bounded operational evidence only. */
	private static function record_result( $status, $course_id, $error_code = '', $context_sha256 = '', $version_id = 0 ) {
		$status = in_array( $status, array( 'issued', 'replayed', 'blocked', 'failed' ), true ) ? $status : 'failed';
		$error_code = sanitize_key( (string) $error_code );
		$context_sha256 = strtolower( trim( (string) $context_sha256 ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $context_sha256 ) ) { $context_sha256 = ''; }
		update_option( self::LAST_RESULT_OPTION, array(
			'status' => $status,
			'course_id' => absint( $course_id ),
			'error_code' => $error_code,
			'context_sha256' => $context_sha256,
			'version_id' => absint( $version_id ),
			'finished_at' => current_time( 'mysql', true ),
		), false );
	}
}
