<?php
namespace Certloom\Infrastructure\Restore;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Data\SafeJson;
use Certloom\Infrastructure\Database\SchemaManager;

/** Durable source-ID -> merged-target-ID mapping for resumable Merge restore. */
final class DomainRestoreMapRepository {
	/** @return array<string,mixed>|null|\WP_Error */
	public function get( $owner_token, $entity, $source_id ) {
		global $wpdb;
		$owner_token = $this->owner( $owner_token );
		$entity      = $this->entity( $entity );
		$source_id   = absint( $source_id );
		if ( is_wp_error( $owner_token ) || is_wp_error( $entity ) || $source_id < 1 ) {
			return is_wp_error( $owner_token ) ? $owner_token : ( is_wp_error( $entity ) ? $entity : new \WP_Error( 'certloom_restore_map_source_invalid', __( 'The restore mapping source identifier is invalid.', 'certloom' ) ) );
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT source_hash, target_id, action, meta_json FROM %i WHERE owner_token = %s AND entity = %s AND source_id = %d',
				SchemaManager::table( 'restore_map' ),
				$owner_token,
				$entity,
				$source_id
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_restore_map_read_failed', __( 'Certloom could not read its Merge restore mapping state.', 'certloom' ) );
		}
		if ( ! is_array( $row ) ) {
			return null;
		}
		$meta = array();
		if ( isset( $row['meta_json'] ) && null !== $row['meta_json'] && '' !== (string) $row['meta_json'] ) {
			$decoded = json_decode( (string) $row['meta_json'], true );
			if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
				return new \WP_Error( 'certloom_restore_map_corrupt', __( 'Certloom found corrupt Merge restore mapping metadata.', 'certloom' ) );
			}
			$meta = $decoded;
		}
		return array(
			'source_hash' => (string) $row['source_hash'],
			'target_id'   => absint( $row['target_id'] ?? 0 ) ?: null,
			'action'      => (string) $row['action'],
			'meta'        => $meta,
		);
	}

	/** @return true|\WP_Error */
	public function save( $owner_token, $entity, $source_id, $source_hash, $target_id, $action, array $meta = array() ) {
		global $wpdb;
		$owner_token = $this->owner( $owner_token );
		$entity      = $this->entity( $entity );
		$source_id   = absint( $source_id );
		$source_hash = strtolower( trim( (string) $source_hash ) );
		$action      = sanitize_key( (string) $action );
		if ( is_wp_error( $owner_token ) || is_wp_error( $entity ) || $source_id < 1 || ! preg_match( '/^[a-f0-9]{64}$/', $source_hash ) || ! in_array( $action, array( 'inserted', 'existing', 'skipped' ), true ) ) {
			return new \WP_Error( 'certloom_restore_map_invalid', __( 'Certloom refused invalid Merge restore mapping data.', 'certloom' ) );
		}
		$meta_json = empty( $meta ) ? null : wp_json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $meta_json ) {
			return new \WP_Error( 'certloom_restore_map_encode_failed', __( 'Certloom could not encode Merge restore mapping metadata.', 'certloom' ) );
		}
		$wpdb->last_error = '';
		$inserted = $wpdb->insert(
			SchemaManager::table( 'restore_map' ),
			array(
				'owner_token' => $owner_token,
				'entity'      => $entity,
				'source_id'   => $source_id,
				'source_hash' => $source_hash,
				'target_id'   => $target_id ? absint( $target_id ) : null,
				'action'      => $action,
				'meta_json'   => $meta_json,
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		return false === $inserted || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_restore_map_write_failed', __( 'Certloom could not save its Merge restore mapping state.', 'certloom' ) )
			: true;
	}

	/** @return true|\WP_Error */
	public function clear( $owner_token ) {
		global $wpdb;
		$owner_token = $this->owner( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}
		$wpdb->last_error = '';
		$deleted = $wpdb->delete( SchemaManager::table( 'restore_map' ), array( 'owner_token' => $owner_token ) );
		return false === $deleted || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_restore_map_clear_failed', __( 'Certloom could not clear completed Merge restore mapping state.', 'certloom' ) )
			: true;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function by_action( $owner_token, $entity, $action ) {
		global $wpdb;
		$owner_token = $this->owner( $owner_token );
		$entity      = $this->entity( $entity );
		$action      = sanitize_key( (string) $action );
		if ( is_wp_error( $owner_token ) || is_wp_error( $entity ) || ! in_array( $action, array( 'inserted', 'existing', 'skipped' ), true ) ) {
			return new \WP_Error( 'certloom_restore_map_invalid', __( 'Certloom refused an invalid Merge restore map query.', 'certloom' ) );
		}
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source_id, target_id, action, meta_json FROM %i WHERE owner_token = %s AND entity = %s AND action = %s ORDER BY source_id ASC',
				SchemaManager::table( 'restore_map' ),
				$owner_token,
				$entity,
				$action
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_restore_map_read_failed', __( 'Certloom could not read its Merge restore mapping state.', 'certloom' ) );
		}
		foreach ( $rows as &$row ) {
			$row['source_id'] = absint( $row['source_id'] ?? 0 );
			$row['target_id'] = absint( $row['target_id'] ?? 0 ) ?: null;
			$decoded = ( new SafeJson() )->decode_array( $row['meta_json'] ?? null, 'certloom_restore_map_corrupt', __( 'Certloom found corrupt Merge restore mapping metadata.', 'certloom' ) );
			if ( is_wp_error( $decoded ) ) { return $decoded; }
			$row['meta'] = $decoded;
			unset( $row['meta_json'] );
		}
		unset( $row );
		return $rows;
	}

	/** @return string|\WP_Error */
	private function owner( $owner_token ) {
		$owner_token = strtolower( trim( (string) $owner_token ) );
		return preg_match( '/^[a-f0-9]{32}$/', $owner_token ) ? $owner_token : new \WP_Error( 'certloom_restore_map_owner_invalid', __( 'The Merge restore owner token is invalid.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	private function entity( $entity ) {
		$entity = sanitize_key( (string) $entity );
		$allowed = array( 'issuers', 'programs', 'recipients', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_numbers', 'credential_versions', 'credential_events' );
		return in_array( $entity, $allowed, true ) ? $entity : new \WP_Error( 'certloom_restore_map_entity_invalid', __( 'The Merge restore entity type is invalid.', 'certloom' ) );
	}
}
