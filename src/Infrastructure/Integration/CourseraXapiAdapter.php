<?php
namespace Certloom\Infrastructure\Integration;

use Certloom\Application\Contract\IntegrationAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Provider adapter for reviewed Coursera Standard xAPI course-completion facts. */
final class CourseraXapiAdapter implements IntegrationAdapterInterface {
	const KEY                = 'coursera';
	const SOURCE_OBJECT_TYPE = 'course';
	const EVENT_TYPE         = 'course_completed';

	public function key() { return self::KEY; }
	public function label() { return __( 'Coursera', 'certloom' ); }

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_event( array $provider_event ) {
		$statement_id = trim( (string) ( $provider_event['statement_id'] ?? '' ) );
		$course_id    = trim( (string) ( $provider_event['course_id'] ?? '' ) );
		$recipient_id = absint( $provider_event['recipient_id'] ?? 0 );
		if ( ! self::stable_id( $statement_id ) ) { return new \WP_Error( 'certloom_coursera_statement_invalid', __( 'Coursera did not provide a stable xAPI statement identifier.', 'certloom' ) ); }
		if ( ! self::stable_id( $course_id ) ) { return new \WP_Error( 'certloom_coursera_course_invalid', __( 'Coursera did not provide a stable course identifier.', 'certloom' ) ); }
		if ( $recipient_id < 1 ) { return new \WP_Error( 'certloom_coursera_recipient_invalid', __( 'The Coursera completion is not explicitly linked to a Certloom Recipient.', 'certloom' ) ); }
		$completed_at = self::normalize_utc_time( $provider_event['completed_at'] ?? '' );
		if ( is_wp_error( $completed_at ) ) { return $completed_at; }
		return array(
			'adapter'               => self::KEY,
			'event_type'            => self::EVENT_TYPE,
			'source_event_id'       => 'xapi:' . $statement_id,
			'wp_user_id'            => 0,
			'recipient_id'          => $recipient_id,
			'source_object'         => array( 'type' => self::SOURCE_OBJECT_TYPE, 'id' => $course_id ),
			'certificate_threshold' => 100.0,
			'attempts'              => array(
				array(
					'attempt_id'              => 'completion:' . $statement_id,
					'attempted_at'            => $completed_at,
					'completed'               => true,
					'percentage'              => 100.0,
					'provider_pass_threshold' => null,
				),
			),
		);
	}

	/** @return string|\WP_Error */
	private static function normalize_utc_time( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 64 ) { return new \WP_Error( 'certloom_coursera_completion_time_invalid', __( 'Coursera did not provide a valid completion time.', 'certloom' ) ); }
		try { $date = new \DateTimeImmutable( $value ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_coursera_completion_time_invalid', __( 'Coursera did not provide a valid completion time.', 'certloom' ) ); }
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/** @return bool */
	private static function stable_id( $value ) { return '' !== $value && strlen( $value ) <= 191 && 1 === preg_match( '/^[A-Za-z0-9._:@\/-]+$/', $value ); }
}
