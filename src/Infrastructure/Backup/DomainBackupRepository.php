<?php
namespace Certloom\Infrastructure\Backup;

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;

/** Keyset read model for complete domain backups. Jobs and ephemeral locks are intentionally excluded. */
final class DomainBackupRepository {
	/** @return array<int,string> */
	public static function configuration_keys() { return array( 'certverify_fields', 'certverify_retired_fields', 'certverify_settings' ); }

	/** @return array<int,string> */
	public static function entity_types() {
		return array( 'issuers', 'programs', 'recipients', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_numbers', 'credential_versions', 'credential_events' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function snapshot() {
		global $wpdb;
		$entities = array();
		foreach ( self::entity_types() as $type ) {
			$table = SchemaManager::table( $type );
			$wpdb->last_error = '';
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS row_count, COALESCE(MAX(id),0) AS max_id FROM %i', $table ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
				return new \WP_Error( 'certloom_domain_backup_snapshot_failed', __( 'Certloom could not snapshot its credential data for backup.', 'certloom' ) );
			}
			$entities[ $type ] = array( 'count' => absint( $row['row_count'] ?? 0 ), 'max_id' => absint( $row['max_id'] ?? 0 ) );
		}
		$config = $this->configuration_snapshot();
		if ( is_wp_error( $config ) ) { return $config; }
		return array(
			'generation' => ( new DomainGeneration() )->current(),
			'entities' => $entities,
			'configuration' => $config['values'],
			'configuration_hash' => $config['hash'],
		);
	}

	/** @return array{values:array<string,mixed>,hash:string}|\WP_Error */
	public function configuration_snapshot() {
		$values = array();
		foreach ( self::configuration_keys() as $key ) {
			$sentinel = array( '__certloom_missing_option__' => $key );
			$value = get_option( $key, $sentinel );
			$values[ $key ] = $value === $sentinel ? array( 'exists' => false, 'value' => null ) : array( 'exists' => true, 'value' => $value );
		}
		$json = wp_json_encode( $values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) { return new \WP_Error( 'certloom_domain_backup_configuration_encode_failed', __( 'Certloom could not snapshot its configuration safely.', 'certloom' ) ); }
		return array( 'values' => $values, 'hash' => hash( 'sha256', $json ) );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function read_batch( $type, $after_id, $max_id, $limit = 200 ) {
		global $wpdb;
		if ( ! in_array( $type, self::entity_types(), true ) ) {
			return new \WP_Error( 'certloom_domain_backup_entity_invalid', __( 'Certloom encountered an unsupported backup entity type.', 'certloom' ) );
		}
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$after_id = absint( $after_id );
		$max_id = absint( $max_id );
		if ( 0 === $max_id || $after_id >= $max_id ) { return array(); }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d',
				SchemaManager::table( $type ),
				$after_id,
				$max_id,
				$limit
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_domain_backup_read_failed', __( 'Certloom could not read a domain backup batch.', 'certloom' ) );
		}
		return $rows;
	}
}
