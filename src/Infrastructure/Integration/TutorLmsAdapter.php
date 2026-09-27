<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * First-party Tutor LMS completion adapter.
 *
 * Tutor-specific payloads terminate here. Only bounded course completion facts
 * enter IntegrationEventContext; learner names, email, arbitrary post meta and
 * serialized Tutor payloads are never retained as Certloom issuance truth.
 */
final class TutorLmsAdapter implements IntegrationAdapterInterface {
	const KEY = 'tutor_lms';
	const SOURCE_OBJECT_TYPE = 'course';
	const EVENT_TYPE = 'course_completed';

	public function key() { return self::KEY; }
	public function label() { return __( 'Tutor LMS', 'certloom' ); }

	/** @return bool */
	public static function is_available() {
		return defined( 'TUTOR_VERSION' ) || function_exists( 'tutor' ) || function_exists( 'tutor_utils' );
	}

	/** @return string */
	public static function course_post_type() {
		if ( function_exists( 'tutor' ) ) {
			$tutor = tutor();
			if ( is_object( $tutor ) && isset( $tutor->course_post_type ) ) {
				$post_type = sanitize_key( (string) $tutor->course_post_type );
				if ( '' !== $post_type ) { return $post_type; }
			}
		}
		return 'courses';
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_event( array $provider_event ) {
		$course_id = absint( $provider_event['course_id'] ?? 0 );
		$user_id = absint( $provider_event['user_id'] ?? 0 );
		if ( $course_id < 1 ) { return new \WP_Error( 'certloom_tutor_course_invalid', __( 'Tutor LMS did not provide a valid course for this completion.', 'certloom' ) ); }
		if ( $user_id < 1 ) { return new \WP_Error( 'certloom_tutor_user_invalid', __( 'Tutor LMS did not provide a valid WordPress learner for this completion.', 'certloom' ) ); }

		$completed_at = self::normalize_utc_time( $provider_event['completed_at'] ?? '' );
		if ( is_wp_error( $completed_at ) ) { return $completed_at; }
		$threshold = isset( $provider_event['certificate_threshold'] ) ? (float) $provider_event['certificate_threshold'] : 100.0;
		if ( ! is_finite( $threshold ) || $threshold < 0 || $threshold > 100 ) {
			return new \WP_Error( 'certloom_tutor_threshold_invalid', __( 'The Tutor LMS certificate threshold must be between 0 and 100.', 'certloom' ) );
		}

		/*
		 * A Tutor course-completion hook is already the provider's completed-course
		 * assertion. Represent it as one 100% completed attempt. Quiz/assignment
		 * credentials require their own future provider event contract rather than
		 * smuggling arbitrary Tutor scoring payloads into this course adapter.
		 */
		return array(
			'adapter' => self::KEY,
			'event_type' => self::EVENT_TYPE,
			'source_event_id' => 'course:' . $course_id . ':user:' . $user_id,
			'wp_user_id' => $user_id,
			'source_object' => array( 'type' => self::SOURCE_OBJECT_TYPE, 'id' => (string) $course_id ),
			'certificate_threshold' => $threshold,
			'attempts' => array(
				array(
					'attempt_id' => 'course-completion:' . $course_id,
					'attempted_at' => $completed_at,
					'completed' => true,
					'percentage' => 100.0,
					'provider_pass_threshold' => null,
				),
			),
		);
	}

	/**
	 * Finds the Tutor completion time without binding Certloom to Tutor tables.
	 * Tutor stores course completion as a WordPress comment before firing the
	 * completion hook. A bounded WordPress comment query is therefore sufficient.
	 *
	 * @return string
	 */
	public static function completion_time( $course_id, $user_id ) {
		$course_id = absint( $course_id );
		$user_id = absint( $user_id );
		if ( function_exists( 'get_comments' ) && $course_id && $user_id ) {
			$comments = get_comments( array(
				'post_id' => $course_id,
				'user_id' => $user_id,
				'type' => 'course_completed',
				// Tutor writes the literal "approved" status. WordPress's
				// "approve" query alias matches only its native numeric status.
				'status' => array( 'approved', 'approve' ),
				'number' => 3,
				'orderby' => 'comment_ID',
				'order' => 'DESC',
			) );
			foreach ( (array) $comments as $comment ) {
				if ( ! is_object( $comment ) || 'TutorLMSPlugin' !== (string) ( $comment->comment_agent ?? '' ) ) { continue; }
				$gmt = trim( (string) ( $comment->comment_date_gmt ?? '' ) );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $gmt ) ) {
					return str_replace( ' ', 'T', $gmt ) . 'Z';
				}
			}
		}
		$timestamp = function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/** @return string|\WP_Error */
	private static function normalize_utc_time( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return new \WP_Error( 'certloom_tutor_completion_time_invalid', __( 'Tutor LMS completion time could not be normalized safely.', 'certloom' ) );
		}
		$time = strtotime( $value );
		if ( false === $time || gmdate( 'Y-m-d\TH:i:s\Z', $time ) !== $value ) {
			return new \WP_Error( 'certloom_tutor_completion_time_invalid', __( 'Tutor LMS completion time could not be normalized safely.', 'certloom' ) );
		}
		return $value;
	}
}
