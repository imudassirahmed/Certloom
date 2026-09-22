<?php
namespace Certloom\Application\Integration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Rendering\CanonicalJson;

/**
 * Bounded, deterministic provider-neutral integration event.
 *
 * Raw provider payloads are never retained. Stable source/attempt identifiers
 * are allowed solely for provenance and idempotency; adapters must not place
 * learner names, email addresses, free-form metadata or serialized payloads in
 * those identifier fields.
 */
final class IntegrationEventContext {
	const SCHEMA = 'certloom-integration-event';
	const VERSION = 1;
	const MAX_ATTEMPTS = 50;
	const MAX_STABLE_ID_BYTES = 191;
	const MAX_EVENT_TYPE_BYTES = 64;

	/** @var array<string,mixed> */ private $data;
	/** @var string */ private $sha256;
	/** @var string */ private $idempotency_key;

	/** @param array<string,mixed> $data */
	private function __construct( array $data, $sha256, $idempotency_key ) {
		$this->data = $data;
		$this->sha256 = (string) $sha256;
		$this->idempotency_key = (string) $idempotency_key;
	}

	/** @return self|\WP_Error */
	public static function from_array( array $input, ?CanonicalJson $json = null, ?QualifyingAttemptSelector $selector = null ) {
		$json = $json ?: new CanonicalJson();
		$selector = $selector ?: new QualifyingAttemptSelector();
		$adapter = self::machine_key( $input['adapter'] ?? '', 64 );
		if ( is_wp_error( $adapter ) ) { return $adapter; }
		$event_type = self::machine_key( $input['event_type'] ?? '', self::MAX_EVENT_TYPE_BYTES );
		if ( is_wp_error( $event_type ) ) { return $event_type; }
		$wp_user_id = (int) ( $input['wp_user_id'] ?? 0 );
		$recipient_id = (int) ( $input['recipient_id'] ?? 0 );
		if ( $wp_user_id < 1 && $recipient_id < 1 ) { return new \WP_Error( 'certloom_integration_user_invalid', __( 'The integration event requires an explicit Certloom recipient or WordPress user identity.', 'certloom' ) ); }
		$object = isset( $input['source_object'] ) && is_array( $input['source_object'] ) ? $input['source_object'] : array();
		$object_type = self::machine_key( $object['type'] ?? '', 64 );
		if ( is_wp_error( $object_type ) ) { return new \WP_Error( 'certloom_integration_object_type_invalid', __( 'The integration event requires a bounded source-object type.', 'certloom' ) ); }
		$object_id = self::stable_id( $object['id'] ?? '' );
		if ( is_wp_error( $object_id ) ) { return new \WP_Error( 'certloom_integration_object_id_invalid', __( 'The integration event requires a stable platform source-object identifier.', 'certloom' ) ); }
		$event_identity = trim( strtolower( (string) ( $input['event_identity_sha256'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $event_identity ) ) {
			$raw_event_id = self::stable_id( $input['source_event_id'] ?? '' );
			if ( is_wp_error( $raw_event_id ) ) { return new \WP_Error( 'certloom_integration_event_identity_invalid', __( 'The integration event requires a stable source-event identifier.', 'certloom' ) ); }
			$event_identity = hash( 'sha256', (string) $raw_event_id );
		}
		$threshold = $input['certificate_threshold'] ?? null;
		$attempts = isset( $input['attempts'] ) && is_array( $input['attempts'] ) ? $input['attempts'] : array();
		$qualifying = $selector->select( $attempts, $threshold );
		if ( is_wp_error( $qualifying ) ) { return $qualifying; }
		$normalized_attempts = array();
		$seen = array();
		foreach ( $attempts as $index => $attempt ) {
			if ( ! is_array( $attempt ) ) { return new \WP_Error( 'certloom_integration_attempt_invalid', __( 'Every integration attempt must be a structured record.', 'certloom' ) ); }
			$attempt = $selector->normalize_attempt( $attempt, $index );
			if ( is_wp_error( $attempt ) ) { return $attempt; }
			if ( isset( $seen[ $attempt['attempt_id'] ] ) ) { return new \WP_Error( 'certloom_integration_attempt_duplicate', __( 'The integration event contains a duplicate attempt identifier.', 'certloom' ) ); }
			$seen[ $attempt['attempt_id'] ] = true;
			$normalized_attempts[] = $attempt;
		}
		usort( $normalized_attempts, static function ( array $a, array $b ) {
			$time = strcmp( $a['attempted_at'], $b['attempted_at'] );
			if ( 0 !== $time ) { return $time; }
			return strcmp( $a['attempt_id'], $b['attempt_id'] );
		} );
		$threshold_float = is_numeric( $threshold ) ? (float) $threshold : -1;
		if ( ! is_finite( $threshold_float ) || $threshold_float < 0 || $threshold_float > 100 ) {
			return new \WP_Error( 'certloom_integration_certificate_threshold_invalid', __( 'The certificate eligibility threshold must be between 0 and 100.', 'certloom' ) );
		}
		$data = array(
			'_meta' => array( 'schema' => self::SCHEMA, 'schema_version' => self::VERSION ),
			'adapter' => $adapter,
			'event_type' => $event_type,
			'event_identity_sha256' => $event_identity,
			'wp_user_id' => $wp_user_id,
			'source_object' => array( 'type' => $object_type, 'id' => $object_id ),
			'certificate_threshold' => $threshold_float,
			'attempts' => $normalized_attempts,
			'qualifying_attempt' => $qualifying,
		);
		// Keep the v1 payload byte-for-byte stable for existing WP-user integrations.
		// External connectors add only the explicit local Recipient identifier.
		if ( $recipient_id > 0 ) { $data['recipient_id'] = $recipient_id; }
		try {
			$sha = $json->hash( $data );
			$idempotency = $json->hash( array( 'source' => 'lms_integration', 'adapter' => $adapter, 'event_identity_sha256' => $event_identity ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_integration_context_hash_failed', __( 'Certloom could not fingerprint the normalized integration event safely.', 'certloom' ) );
		}
		return new self( $data, $sha, $idempotency );
	}

	/** @return array<string,mixed> */ public function to_array() { return $this->data; }
	/** @return string */ public function sha256() { return $this->sha256; }
	/** @return string */ public function idempotency_key() { return $this->idempotency_key; }
	/** @return bool */ public function is_eligible() { return is_array( $this->data['qualifying_attempt'] ?? null ); }
	/** @return array<string,mixed>|null */ public function qualifying_attempt() { return $this->is_eligible() ? $this->data['qualifying_attempt'] : null; }
	/** @return int */ public function wp_user_id() { return (int) $this->data['wp_user_id']; }
	/** @return int */ public function recipient_id() { return absint( $this->data['recipient_id'] ?? 0 ); }
	/** @return float */ public function certificate_threshold() { return (float) $this->data['certificate_threshold']; }
	/** @return string */ public function adapter_key() { return (string) $this->data['adapter']; }
	/** @return string */ public function source_object_type() { return (string) $this->data['source_object']['type']; }
	/** @return string */ public function source_object_id() { return (string) $this->data['source_object']['id']; }

	/** @return string|\WP_Error */
	private static function machine_key( $value, $limit ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value || strlen( $value ) > (int) $limit || ! preg_match( '/^[a-z][a-z0-9_\-]*$/', $value ) ) {
			return new \WP_Error( 'certloom_integration_machine_key_invalid', __( 'The integration event contains an invalid machine key.', 'certloom' ) );
		}
		return $value;
	}

	/** @return string|\WP_Error */
	private static function stable_id( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > self::MAX_STABLE_ID_BYTES || ! preg_match( '/^[A-Za-z0-9._:@\/-]+$/', $value ) ) {
			return new \WP_Error( 'certloom_integration_stable_id_invalid', __( 'The integration event contains an invalid stable platform identifier.', 'certloom' ) );
		}
		return $value;
	}
}
