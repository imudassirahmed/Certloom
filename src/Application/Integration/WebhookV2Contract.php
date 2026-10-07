<?php
namespace Certloom\Application\Integration;

use Certloom\Application\Issuance\IssuanceEvidence;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded wire contract. Version, route and exact bytes share one signing domain. */
final class WebhookV2Contract {

	const MAX_BODY_BYTES = 32768;
	const MAX_FIELDS = 30;
	const MAX_CLOCK_SKEW = 300;

	public function mapping( $fields ) {
		if ( ! is_array( $fields ) || count( $fields ) > self::MAX_FIELDS ) { return $this->invalid( 'mapping' ); }
		$out = array(); $targets = array();
		foreach ( $fields as $slot => $target ) {
			if ( ! is_string( $slot ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $slot ) || ! is_string( $target ) || '' === $target || strlen( $target ) > 64 || sanitize_key( $target ) !== $target || isset( $targets[ $target ] ) ) { return $this->invalid( 'mapping' ); }
			$out[ $slot ] = $target; $targets[ $target ] = true;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	public static function source_key( $key ) { return is_string( $key ) && 1 === preg_match( '/^v2-[a-f0-9]{12}$/D', $key ); }

	public function route( $source, $mode, $public_ref = '' ) {
		if ( ! self::source_key( $source ) || ! in_array( $mode, array( 'intake', 'test', 'evidence' ), true ) || ( 'evidence' === $mode && ( ! is_string( $public_ref ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $public_ref ) ) ) || ( 'evidence' !== $mode && '' !== $public_ref ) ) { return $this->invalid( 'route' ); }
		$path = '/certloom/v2/integrations/webhook/' . $source;
		return $path . ( 'test' === $mode ? '/test' : ( 'evidence' === $mode ? '/requests/' . $public_ref . '/evidence' : '' ) );
	}

	public function signing_input( $source, $mode, $timestamp, $raw, $public_ref = '' ) {
		$route = $this->route( $source, $mode, $public_ref );
		if ( is_wp_error( $route ) ) { return $route; }
		if ( ! is_string( $timestamp ) || 1 !== preg_match( '/^[0-9]{10}$/D', $timestamp ) || ! is_string( $raw ) || strlen( $raw ) < 2 || strlen( $raw ) > self::MAX_BODY_BYTES ) { return $this->invalid( 'signature' ); }
		return "certloom-webhook-v2\nPOST\n" . $route . "\n" . $timestamp . "\n" . $raw;
	}

	public function authenticate( $source, $mode, $timestamp, $signature, $raw, $secret, $now, $public_ref = '' ) {
		$input = $this->signing_input( $source, $mode, $timestamp, $raw, $public_ref );
		if ( is_wp_error( $input ) ) { return $input; }
		if ( ! is_int( $now ) || abs( $now - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) { return $this->invalid( 'timestamp' ); }
		if ( ! is_string( $secret ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $secret ) || ! is_string( $signature ) ) { return $this->invalid( 'signature' ); }
		if ( 0 === strpos( $signature, 'sha256=' ) ) { $signature = substr( $signature, 7 ); }
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $signature ) && hash_equals( hash_hmac( 'sha256', $input, $secret ), $signature ) ? true : $this->invalid( 'signature' );
	}

	/** Intake identifies the exact existing person; a test uses the identical payload. */
	public function intake( $raw, array $mapping ) {
		$data = $this->intake_identity( $raw );
		if ( is_wp_error( $data ) ) { return $data; }
		$completed = $this->utc( $data['completed_at'] );
		$evidence = $this->evidence( $data['evidence'], $mapping );
		return is_wp_error( $evidence ) ? $evidence : array( 'event_id' => $data['event_id'], 'recipient_external_id' => $data['recipient_external_id'], 'registration_id' => $data['registration_id'], 'completed_at' => $completed, 'evidence' => $evidence );
	}

	/** Read identity before current mapping so exact accepted retries remain reconcilable. */
	public function intake_identity( $raw ) {
		$data = $this->json( $raw );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( ! $this->keys( $data, array( 'schema_version', 'event_id', 'recipient_external_id', 'registration_id', 'completed_at', 'evidence' ) ) || 2 !== $data['schema_version'] || ! $this->event_id( $data['event_id'] ) || ! is_string( $data['recipient_external_id'] ) || '' === trim( $data['recipient_external_id'] ) || trim( $data['recipient_external_id'] ) !== $data['recipient_external_id'] || strlen( $data['recipient_external_id'] ) > 191 || ! $this->text( $data['recipient_external_id'] ) || ! is_int( $data['registration_id'] ) || $data['registration_id'] < 0 ) { return $this->invalid( 'payload' ); }
		$completed = $this->utc( $data['completed_at'] );
		if ( is_wp_error( $completed ) ) { return $completed; }
		return $data;
	}

	public function evidence_update( $raw, array $mapping ) {
		$data = $this->json( $raw );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( ! $this->keys( $data, array( 'schema_version', 'event_id', 'expected_revision', 'evidence' ) ) || 2 !== $data['schema_version'] || ! $this->event_id( $data['event_id'] ) || ! is_int( $data['expected_revision'] ) || $data['expected_revision'] < 1 ) { return $this->invalid( 'payload' ); }
		$evidence = $this->evidence( $data['evidence'], $mapping );
		return is_wp_error( $evidence ) ? $evidence : array( 'event_id' => $data['event_id'], 'expected_revision' => $data['expected_revision'], 'evidence' => $evidence );
	}

	private function evidence( $data, array $mapping ) {
		$mapping = $this->mapping( $mapping );
		if ( is_wp_error( $mapping ) ) { return $mapping; }
		if ( ! is_array( $data ) || ! $this->keys( $data, array( 'eligibility', 'checked_at', 'valid_until', 'fields' ) ) || ! is_array( $data['fields'] ) || count( $data['fields'] ) > self::MAX_FIELDS ) { return $this->invalid( 'evidence' ); }
		$dates = array();
		foreach ( array( 'checked_at', 'valid_until' ) as $key ) { $dates[ $key ] = null === $data[ $key ] ? null : $this->utc( $data[ $key ] ); if ( is_wp_error( $dates[ $key ] ) ) { return $dates[ $key ]; } }
		$fields = array();
		foreach ( $data['fields'] as $slot => $field ) {
			if ( ! isset( $mapping[ $slot ] ) || ! is_array( $field ) || ! $this->keys( $field, array( 'value', 'revision' ) ) || ! is_string( $field['value'] ) || strlen( $field['value'] ) > 8000 || ! $this->text( $field['value'] ) || ! $this->event_id( $field['revision'] ) ) { return $this->invalid( 'evidence' ); }
			$fields[ $mapping[ $slot ] ] = array( 'value' => $field['value'], 'writer' => 'source', 'revision' => $field['revision'] );
		}
		return ( new IssuanceEvidence() )->normalize( array( '_meta' => array( 'schema' => IssuanceEvidence::SCHEMA, 'schema_version' => IssuanceEvidence::VERSION ), 'eligibility' => $data['eligibility'], 'checked_at' => $dates['checked_at'], 'valid_until' => $dates['valid_until'], 'fields' => $fields ) );
	}

	private function json( $raw ) {
		if ( ! is_string( $raw ) || strlen( $raw ) < 2 || strlen( $raw ) > self::MAX_BODY_BYTES || '{' !== substr( ltrim( $raw ), 0, 1 ) ) { return $this->invalid( 'payload' ); }
		$data = json_decode( $raw, true, 12 );
		return JSON_ERROR_NONE === json_last_error() && is_array( $data ) ? $data : $this->invalid( 'payload' );
	}
	private function utc( $value ) {
		if ( ! is_string( $value ) ) { return $this->invalid( 'date' ); }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) ); $errors = \DateTimeImmutable::getLastErrors();
		return false !== $date && ( ! is_array( $errors ) || ( ! $errors['warning_count'] && ! $errors['error_count'] ) ) && $date->format( 'Y-m-d\TH:i:s\Z' ) === $value && $date->format( 'Y' ) >= '1000' ? $date->format( 'Y-m-d H:i:s' ) : $this->invalid( 'date' );
	}
	private function keys( array $data, array $expected ) { $actual = array_keys( $data ); sort( $actual ); sort( $expected ); return $actual === $expected; }
	private function event_id( $value ) { return is_string( $value ) && strlen( $value ) <= 191 && 1 === preg_match( '/^[A-Za-z0-9_.:\/-]+$/D', $value ); }
	private function text( $value ) { return false === strpos( $value, "\0" ) && 1 === preg_match( '//u', $value ); }
	private function invalid( $part ) { return new \WP_Error( 'certloom_webhook_v2_' . $part . '_invalid', __( 'This signed app request has an invalid or unsupported contract. Check its connection, mapped fields, timestamp and payload.', 'certloom' ) ); }
}
