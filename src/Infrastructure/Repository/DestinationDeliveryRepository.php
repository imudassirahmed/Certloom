<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Application\Data\SafeJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Durable, privacy-minimized evidence for external publishing destinations.
 *
 * The record exists separately from immutable Credential truth because an
 * external side effect may fail, succeed, or become uncertain without changing
 * whether the Certloom credential itself exists. The unique dedupe key prevents
 * one immutable Version from being silently published twice to the same mapped
 * destination configuration.
 */
final class DestinationDeliveryRepository {
	const STATUS_PREPARING = 'preparing';
	const STATUS_QUEUED    = 'queued';
	const STATUS_SENDING   = 'sending';
	const STATUS_SUCCEEDED = 'succeeded';
	const STATUS_FAILED    = 'failed';
	const STATUS_UNCERTAIN = 'uncertain';

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_by_dedupe_key( $dedupe_key ) {
		global $wpdb;
		$dedupe_key = self::sha256( $dedupe_key );
		if ( is_wp_error( $dedupe_key ) ) { return $dedupe_key; }
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE dedupe_key = %s LIMIT 1', SchemaManager::table( 'destination_deliveries' ), $dedupe_key ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_destination_delivery_read_failed', __( 'Certloom could not read the external publishing record.', 'certloom' ) ); }
		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_delivery( $delivery_key ) {
		global $wpdb;
		$delivery_key = self::key( $delivery_key );
		if ( is_wp_error( $delivery_key ) ) { return $delivery_key; }
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE delivery_key = %s LIMIT 1', SchemaManager::table( 'destination_deliveries' ), $delivery_key ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_destination_delivery_read_failed', __( 'Certloom could not read the external publishing record.', 'certloom' ) ); }
		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/** @return string|array<string,mixed>|\WP_Error Delivery key or existing row. */
	public function create_delivery( array $data ) {
		global $wpdb;
		$destination = sanitize_key( (string) ( $data['destination'] ?? '' ) );
		$series_id   = absint( $data['series_id'] ?? 0 );
		$version_id  = absint( $data['version_id'] ?? 0 );
		$dedupe_key  = self::sha256( $data['dedupe_key'] ?? '' );
		$mapping_sha = self::sha256( $data['mapping_sha256'] ?? '' );
		$config_sha  = self::sha256( $data['configuration_sha256'] ?? '' );
		$recipient_sha = self::sha256( $data['recipient_sha256'] ?? '' );
		foreach ( array( $dedupe_key, $mapping_sha, $config_sha, $recipient_sha ) as $value ) { if ( is_wp_error( $value ) ) { return $value; } }
		if ( '' === $destination || strlen( $destination ) > 32 || ! $series_id || ! $version_id ) {
			return new \WP_Error( 'certloom_destination_delivery_identity_invalid', __( 'The external publishing record is missing its credential or destination identity.', 'certloom' ) );
		}
		$existing = $this->find_by_dedupe_key( $dedupe_key );
		if ( is_wp_error( $existing ) || is_array( $existing ) ) { return $existing; }
		try { $delivery_key = bin2hex( random_bytes( 16 ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_destination_delivery_key_failed', __( 'Certloom could not create a secure external publishing identifier.', 'certloom' ) ); }
		$evidence = ( new SafeJson() )->encode_array( is_array( $data['evidence'] ?? null ) ? $data['evidence'] : array(), 'certloom_destination_delivery_evidence_invalid', __( 'Certloom could not encode external publishing evidence safely.', 'certloom' ) );
		if ( is_wp_error( $evidence ) ) { return $evidence; }
		$now = current_time( 'mysql', true );
		$row = array(
			'delivery_key'         => $delivery_key,
			'dedupe_key'           => $dedupe_key,
			'destination'          => $destination,
			'series_id'            => $series_id,
			'version_id'           => $version_id,
			'job_key'              => null,
			'status'               => self::STATUS_PREPARING,
			'mapping_sha256'       => $mapping_sha,
			'configuration_sha256' => $config_sha,
			'recipient_sha256'     => $recipient_sha,
			'external_reference'   => null,
			'attempt_token'        => null,
			'attempted_at'         => null,
			'accepted_at'          => null,
			'failed_at'            => null,
			'failure_code'         => null,
			'evidence_json'        => $evidence,
			'created_at'           => $now,
			'updated_at'           => $now,
		);
		$ok = ( new DeliveryWriteBoundary( 'destination_deliveries' ) )->insert( $row );
		if ( is_wp_error( $ok ) ) {
			/* A concurrent listener may have created the same deterministic delivery. */
			$existing = $this->find_by_dedupe_key( $dedupe_key );
			return is_array( $existing ) ? $existing : $ok;
		}
		return $delivery_key;
	}

	/** @return true|\WP_Error */
	public function attach_job( $delivery_key, $job_key ) {
		$job_key = strtolower( trim( (string) $job_key ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $job_key ) ) { return new \WP_Error( 'certloom_destination_job_key_invalid', __( 'The external publishing job identifier is invalid.', 'certloom' ) ); }
		return $this->update_state( $delivery_key, array( 'job_key' => $job_key, 'status' => self::STATUS_QUEUED, 'updated_at' => current_time( 'mysql', true ) ), array( self::STATUS_PREPARING ) );
	}

	/** @return true|\WP_Error */
	public function mark_sending( $delivery_key, $attempt_token ) {
		$attempt_token = strtolower( trim( (string) $attempt_token ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $attempt_token ) ) { return new \WP_Error( 'certloom_destination_attempt_invalid', __( 'The external publishing attempt identifier is invalid.', 'certloom' ) ); }
		return $this->update_state( $delivery_key, array( 'status' => self::STATUS_SENDING, 'attempt_token' => $attempt_token, 'attempted_at' => current_time( 'mysql', true ), 'failed_at' => null, 'failure_code' => null, 'updated_at' => current_time( 'mysql', true ) ), array( self::STATUS_QUEUED, self::STATUS_FAILED ) );
	}

	/** @return true|\WP_Error */
	public function mark_succeeded( $delivery_key, $external_reference, array $evidence = array() ) {
		$external_reference = trim( (string) $external_reference );
		if ( strlen( $external_reference ) > 191 ) { $external_reference = substr( $external_reference, 0, 191 ); }
		return $this->finish( $delivery_key, self::STATUS_SUCCEEDED, '', $external_reference ?: null, $evidence );
	}

	/** @return true|\WP_Error */
	public function mark_failed( $delivery_key, $failure_code, array $evidence = array(), $uncertain = false ) {
		return $this->finish( $delivery_key, $uncertain ? self::STATUS_UNCERTAIN : self::STATUS_FAILED, sanitize_key( (string) $failure_code ), null, $evidence );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function recent( $destination, $limit = 20 ) {
		global $wpdb;
		$destination = sanitize_key( (string) $destination );
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT d.*, v.display_number AS credential_number FROM %i d LEFT JOIN %i v ON v.id = d.version_id WHERE d.destination = %s ORDER BY d.id DESC LIMIT %d',
				SchemaManager::table( 'destination_deliveries' ),
				SchemaManager::table( 'credential_versions' ),
				$destination,
				$limit
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_destination_delivery_list_failed', __( 'Certloom could not load external publishing history.', 'certloom' ) ); }
		return array_map( array( $this, 'decode' ), $rows );
	}

	/** @return true|\WP_Error */
	private function finish( $delivery_key, $status, $failure_code, $external_reference, array $evidence ) {
		$delivery_key = self::key( $delivery_key );
		if ( is_wp_error( $delivery_key ) ) { return $delivery_key; }
		return ( new DeliveryWriteBoundary( 'destination_deliveries' ) )->change( $delivery_key, function ( array $row ) use ( $status, $failure_code, $external_reference, $evidence ) {
			$current = $this->decode( $row );
			if ( ! DeliveryWriteBoundary::attempt_matches( $current, $evidence ) ) { return new \WP_Error( 'certloom_destination_delivery_state_conflict', __( 'This result belongs to a different publishing attempt. Refresh the delivery status.', 'certloom' ) ); }
			if ( self::STATUS_SUCCEEDED === (string) ( $current['status'] ?? '' ) ) {
				return self::STATUS_SUCCEEDED === $status ? true : new \WP_Error( 'certloom_destination_already_succeeded', __( 'This credential has already been published to the external destination.', 'certloom' ) );
			}
			if ( self::STATUS_UNCERTAIN === (string) ( $current['status'] ?? '' ) ) {
				return self::STATUS_UNCERTAIN === $status ? true : new \WP_Error( 'certloom_destination_uncertain', __( 'This external publishing attempt has an uncertain outcome and requires administrator review before another attempt.', 'certloom' ) );
			}
			$state = (string) $current['status'];
			if ( ! in_array( $state, array( self::STATUS_PREPARING, self::STATUS_QUEUED, self::STATUS_SENDING, self::STATUS_FAILED ), true ) || ( self::STATUS_SUCCEEDED === $status && self::STATUS_SENDING !== $state ) ) { return new \WP_Error( 'certloom_destination_delivery_state_conflict', __( 'The publishing state changed before this result could be saved.', 'certloom' ) ); }
			if ( self::STATUS_FAILED === $status && self::STATUS_FAILED === $state ) { return true; }
			$merged = is_array( $current['evidence'] ?? null ) ? $current['evidence'] : array();
			$merged = array_merge( $merged, $evidence );
			$encoded = ( new SafeJson() )->encode_array( $merged, 'certloom_destination_delivery_evidence_invalid', __( 'Certloom could not encode external publishing evidence safely.', 'certloom' ) );
			if ( is_wp_error( $encoded ) ) { return $encoded; }
			$now = current_time( 'mysql', true );
			$changes = array( 'status' => $status, 'failure_code' => $failure_code ?: null, 'external_reference' => $external_reference, 'evidence_json' => $encoded, 'updated_at' => $now );
			if ( self::STATUS_SUCCEEDED === $status ) { $changes['accepted_at'] = $now; $changes['failed_at'] = null; }
			else { $changes['failed_at'] = $now; }
			return $changes;
		} );
	}

	/** @return true|\WP_Error */
	private function update_state( $delivery_key, array $changes, array $allowed ) {
		$delivery_key = self::key( $delivery_key );
		if ( is_wp_error( $delivery_key ) ) { return $delivery_key; }
		return ( new DeliveryWriteBoundary( 'destination_deliveries' ) )->change( $delivery_key, static function ( array $current ) use ( $changes, $allowed ) {
			return in_array( (string) $current['status'], $allowed, true ) ? $changes : new \WP_Error( 'certloom_destination_delivery_state_conflict', __( 'The external publishing state changed before this operation could be saved.', 'certloom' ) );
		} );
	}

	/** @return array<string,mixed> */
	private function decode( array $row ) {
		$row['id'] = absint( $row['id'] ?? 0 );
		$row['series_id'] = absint( $row['series_id'] ?? 0 );
		$row['version_id'] = absint( $row['version_id'] ?? 0 );
		$evidence = json_decode( (string) ( $row['evidence_json'] ?? '' ), true );
		$row['evidence'] = is_array( $evidence ) ? $evidence : array();
		return $row;
	}

	/** @return string|\WP_Error */
	private static function key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : new \WP_Error( 'certloom_destination_delivery_key_invalid', __( 'The external publishing identifier is invalid.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	private static function sha256( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : new \WP_Error( 'certloom_destination_delivery_hash_invalid', __( 'External publishing evidence requires valid SHA-256 fingerprints.', 'certloom' ) );
	}
}
