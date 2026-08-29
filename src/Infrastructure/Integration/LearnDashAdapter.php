<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * First-party LearnDash course-completion adapter.
 *
 * LearnDash payloads terminate here. Only bounded course completion facts enter
 * IntegrationEventContext; learner names, emails, arbitrary progress arrays and
 * provider metadata are never retained as Certloom issuance truth.
 */
final class LearnDashAdapter implements IntegrationAdapterInterface {
	const KEY = 'learndash';
	const SOURCE_OBJECT_TYPE = 'course';
	const EVENT_TYPE = 'course_completed';

	public function key() { return self::KEY; }
	public function label() { return __( 'LearnDash', 'certloom' ); }

	/** @return bool */
	public static function is_available() {
		return defined( 'LEARNDASH_VERSION' ) || class_exists( 'SFWD_LMS' ) || function_exists( 'learndash_course_completed' );
	}

	/** @return string */
	public static function course_post_type() {
		if ( function_exists( 'learndash_get_post_type_slug' ) ) {
			$post_type = sanitize_key( (string) learndash_get_post_type_slug( 'course' ) );
			if ( '' !== $post_type ) { return $post_type; }
		}
		return 'sfwd-courses';
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_event( array $provider_event ) {
		$course_id = absint( $provider_event['course_id'] ?? 0 );
		$user_id = absint( $provider_event['user_id'] ?? 0 );
		if ( $course_id < 1 ) { return new \WP_Error( 'certloom_learndash_course_invalid', __( 'LearnDash did not provide a valid course for this completion.', 'certloom' ) ); }
		if ( $user_id < 1 ) { return new \WP_Error( 'certloom_learndash_user_invalid', __( 'LearnDash did not provide a valid WordPress learner for this completion.', 'certloom' ) ); }

		$completed_at = self::normalize_utc_time( $provider_event['completed_at'] ?? '' );
		if ( is_wp_error( $completed_at ) ) { return $completed_at; }
		$threshold = isset( $provider_event['certificate_threshold'] ) ? (float) $provider_event['certificate_threshold'] : 100.0;
		if ( ! is_finite( $threshold ) || $threshold < 0 || $threshold > 100 ) {
			return new \WP_Error( 'certloom_learndash_threshold_invalid', __( 'The LearnDash certificate threshold must be between 0 and 100.', 'certloom' ) );
		}

		/*
		 * learndash_course_completed fires after LearnDash marks the course complete.
		 * Represent that provider assertion as one completed 100% attempt. Quiz or
		 * lesson credentials require their own future event contracts rather than
		 * copying arbitrary LearnDash progress/scoring payloads into this adapter.
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

	/** @return string */
	public static function completion_time( $course_id, $user_id ) {
		$course_id = absint( $course_id );
		$user_id = absint( $user_id );
		if ( $course_id && $user_id && function_exists( 'learndash_user_get_course_completed_date' ) ) {
			$timestamp = (int) learndash_user_get_course_completed_date( $user_id, $course_id );
			if ( $timestamp > 0 ) { return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ); }
		}
		$timestamp = function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time();
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/** @return string|\WP_Error */
	private static function normalize_utc_time( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return new \WP_Error( 'certloom_learndash_completion_time_invalid', __( 'LearnDash completion time could not be normalized safely.', 'certloom' ) );
		}
		$time = strtotime( $value );
		if ( false === $time || gmdate( 'Y-m-d\TH:i:s\Z', $time ) !== $value ) {
			return new \WP_Error( 'certloom_learndash_completion_time_invalid', __( 'LearnDash completion time could not be normalized safely.', 'certloom' ) );
		}
		return $value;
	}
}
