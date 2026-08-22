<?php
namespace Certloom\Infrastructure\Diagnostics;

use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resumable deterministic fingerprint of the complete future credential graph.
 *
 * The mutable DomainGeneration value is used only to prove that each scan saw one
 * stable graph; it is intentionally excluded from the resulting fingerprint so
 * a data-equivalent Replace/Merge restore can be compared across generation bumps.
 */
final class DomainStateFingerprint {
	const STATE_SCHEMA  = 'certloom-domain-state-fingerprint';
	const STATE_VERSION = 1;
	const HASH_SEED     = 'certloom-domain-state-fingerprint-v1';
	const LOCK_TTL      = 120;

	/** @var DomainBackupRepository */
	private $repository;
	/** @var DomainMaintenanceLock */
	private $maintenance;

	public function __construct( DomainBackupRepository $repository = null, DomainMaintenanceLock $maintenance = null ) {
		$this->repository  = $repository ?: new DomainBackupRepository();
		$this->maintenance = $maintenance ?: new DomainMaintenanceLock();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function start( $owner_token ) {
		$owner = $this->owner_token( $owner_token );
		if ( is_wp_error( $owner ) ) { return $owner; }
		$locked = $this->maintenance->acquire( $owner, 'domain_state_fingerprint', self::LOCK_TTL );
		if ( is_wp_error( $locked ) ) { return $locked; }
		$snapshot = $this->repository->snapshot();
		if ( is_wp_error( $snapshot ) ) { return $this->release_after_error( $owner, $snapshot ); }
		$entities = (array) ( $snapshot['entities'] ?? array() );
		if ( array_keys( $entities ) !== DomainBackupRepository::entity_types() || '' === (string) ( $snapshot['generation'] ?? '' ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $snapshot['configuration_hash'] ?? '' ) ) ) {
			return $this->release_after_error( $owner, new \WP_Error( 'certloom_domain_fingerprint_snapshot_invalid', __( 'Certloom could not freeze a complete domain boundary for fingerprinting.', 'certloom' ) ) );
		}
		$expected_records = 0;
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			if ( ! is_array( $entities[ $entity ] ) || ! array_key_exists( 'count', $entities[ $entity ] ) || ! array_key_exists( 'max_id', $entities[ $entity ] ) ) {
				return $this->release_after_error( $owner, new \WP_Error( 'certloom_domain_fingerprint_snapshot_invalid', __( 'Certloom found incomplete entity bounds while starting a domain fingerprint.', 'certloom' ) ) );
			}
			$expected_records += absint( $entities[ $entity ]['count'] );
		}
		$hash = hash( 'sha256', self::HASH_SEED . "\n" . (string) $snapshot['configuration_hash'] );
		return array(
			'schema'             => self::STATE_SCHEMA,
			'version'            => self::STATE_VERSION,
			'owner_token'        => $owner,
			'generation'         => (string) $snapshot['generation'],
			'configuration_hash' => (string) $snapshot['configuration_hash'],
			'entities'           => $entities,
			'entity_index'       => 0,
			'cursor'             => 0,
			'entity_counts'      => array_fill_keys( DomainBackupRepository::entity_types(), 0 ),
			'records'            => 0,
			'expected_records'   => $expected_records,
			'hash'               => $hash,
			'complete'           => false,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function continue_fingerprint( array $state, $limit = 200 ) {
		$valid = $this->validate_state( $state );
		if ( is_wp_error( $valid ) ) {
			$owner = isset( $state['owner_token'] ) ? strtolower( trim( (string) $state['owner_token'] ) ) : '';
			return preg_match( '/^[a-f0-9]{32}$/', $owner ) ? $this->release_after_error( $owner, $valid ) : $valid;
		}
		if ( ! empty( $state['complete'] ) ) { return $state; }
		$touched = $this->maintenance->touch( (string) $state['owner_token'], 'domain_state_fingerprint', self::LOCK_TTL );
		if ( is_wp_error( $touched ) ) { return $touched; }
		$types = DomainBackupRepository::entity_types();
		$index = absint( $state['entity_index'] );
		if ( $index >= count( $types ) ) { return $this->finish( $state ); }
		$type = $types[ $index ];
		$bound = (array) $state['entities'][ $type ];
		$rows = $this->repository->read_batch( $type, absint( $state['cursor'] ), absint( $bound['max_id'] ), $limit );
		if ( is_wp_error( $rows ) ) { return $this->release_after_error( (string) $state['owner_token'], $rows ); }
		if ( empty( $rows ) ) {
			if ( absint( $state['entity_counts'][ $type ] ?? 0 ) !== absint( $bound['count'] ?? -1 ) ) {
				return $this->release_after_error( (string) $state['owner_token'], new \WP_Error( 'certloom_domain_fingerprint_count_mismatch', __( 'The credential graph changed while Certloom was fingerprinting it.', 'certloom' ) ) );
			}
			$state['entity_index'] = $index + 1;
			$state['cursor'] = 0;
			return $state['entity_index'] >= count( $types ) ? $this->finish( $state ) : $state;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || absint( $row['id'] ?? 0 ) < 1 ) {
				return $this->release_after_error( (string) $state['owner_token'], new \WP_Error( 'certloom_domain_fingerprint_row_invalid', __( 'Certloom encountered an invalid row while fingerprinting the credential graph.', 'certloom' ) ) );
			}
			$encoded = $this->encode_row( $type, $row );
			if ( is_wp_error( $encoded ) ) { return $this->release_after_error( (string) $state['owner_token'], $encoded ); }
			$state['hash'] = hash( 'sha256', (string) $state['hash'] . "\n" . $encoded );
			$state['cursor'] = absint( $row['id'] );
			$state['records'] = absint( $state['records'] ) + 1;
			$state['entity_counts'][ $type ] = absint( $state['entity_counts'][ $type ] ?? 0 ) + 1;
		}
		return $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function finish( array $state ) {
		$owner = (string) $state['owner_token'];
		$touched = $this->maintenance->touch( $owner, 'domain_state_fingerprint', self::LOCK_TTL );
		if ( is_wp_error( $touched ) ) { return $touched; }
		$current = $this->repository->snapshot();
		if ( is_wp_error( $current ) ) { return $this->release_after_error( $owner, $current ); }
		$released = $this->maintenance->release( $owner );
		if ( is_wp_error( $released ) ) {
			return new \WP_Error( 'certloom_domain_fingerprint_verify_cleanup_failed', __( 'Certloom could not release its domain-fingerprint verification lock safely.', 'certloom' ) );
		}
		if ( ! hash_equals( (string) $state['generation'], (string) ( $current['generation'] ?? '' ) ) || ! hash_equals( (string) $state['configuration_hash'], (string) ( $current['configuration_hash'] ?? '' ) ) || (array) $state['entities'] !== (array) ( $current['entities'] ?? array() ) ) {
			return new \WP_Error( 'certloom_domain_fingerprint_concurrent_change', __( 'The credential graph or configuration changed while Certloom was fingerprinting it.', 'certloom' ) );
		}
		if ( absint( $state['records'] ) !== absint( $state['expected_records'] ) ) {
			return new \WP_Error( 'certloom_domain_fingerprint_count_mismatch', __( 'The credential graph fingerprint did not cover every frozen record.', 'certloom' ) );
		}
		$state['complete'] = true;
		return $state;
	}

	/** @return string|\WP_Error */
	private function encode_row( $entity, array $row ) {
		$value = array( 'entity' => (string) $entity, 'data' => $this->canonicalize( $row ) );
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json
			? new \WP_Error( 'certloom_domain_fingerprint_encode_failed', __( 'Certloom could not encode credential data safely for comparison.', 'certloom' ) )
			: $json;
	}

	private function canonicalize( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		$is_list = empty( $value ) || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) { ksort( $value, SORT_STRING ); }
		foreach ( $value as $key => $item ) { $value[ $key ] = $this->canonicalize( $item ); }
		return $value;
	}

	/** @return \WP_Error */
	private function release_after_error( $owner, \WP_Error $error ) {
		$released = $this->maintenance->release( (string) $owner );
		if ( is_wp_error( $released ) ) {
			return new \WP_Error( 'certloom_domain_fingerprint_cleanup_failed', sprintf( __( 'Certloom domain fingerprinting failed (%1$s) and its maintenance boundary could not be released safely (%2$s).', 'certloom' ), $error->get_error_message(), $released->get_error_message() ) );
		}
		return $error;
	}

	/** @return true|\WP_Error */
	private function validate_state( array $state ) {
		foreach ( array( 'schema', 'version', 'owner_token', 'generation', 'configuration_hash', 'entities', 'entity_index', 'cursor', 'entity_counts', 'records', 'expected_records', 'hash' ) as $key ) {
			if ( ! array_key_exists( $key, $state ) ) { return new \WP_Error( 'certloom_domain_fingerprint_state_invalid', __( 'The resumable domain-fingerprint state is incomplete.', 'certloom' ) ); }
		}
		$owner = $this->owner_token( $state['owner_token'] );
		if ( is_wp_error( $owner ) || self::STATE_SCHEMA !== (string) $state['schema'] || self::STATE_VERSION !== (int) $state['version'] || ! preg_match( '/^[a-f0-9]{64}$/', (string) $state['configuration_hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $state['hash'] ) || array_keys( (array) $state['entities'] ) !== DomainBackupRepository::entity_types() ) {
			return new \WP_Error( 'certloom_domain_fingerprint_state_invalid', __( 'The resumable domain-fingerprint state is invalid.', 'certloom' ) );
		}
		return true;
	}

	/** @return string|\WP_Error */
	private function owner_token( $token ) {
		$token = strtolower( trim( (string) $token ) );
		return preg_match( '/^[a-f0-9]{32}$/', $token )
			? $token
			: new \WP_Error( 'certloom_domain_fingerprint_owner_invalid', __( 'The Certloom domain-fingerprint owner is invalid.', 'certloom' ) );
	}
}
