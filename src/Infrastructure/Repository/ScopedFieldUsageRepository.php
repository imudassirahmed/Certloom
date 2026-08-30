<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Data\SafeJson;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Issuance\MergeSnapshotBuilder;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * FLD-02 active usage index for safe field retirement and admin visibility.
 *
 * Only mutable consumers block retirement: Program requirements, Batch
 * overrides, and active Template revisions. Historical Credential Version
 * snapshots deliberately do not: retiring a field must preserve history while
 * preventing dangling requirements/designs from being created going forward.
 */
final class ScopedFieldUsageRepository {
	const CHUNK = 250;
	/** @var array<string,array<string,mixed>>|\WP_Error|null */ private $cache = null;

	/** @return array<string,array<string,mixed>>|\WP_Error */
	public function all_usage() {
		if ( null !== $this->cache ) { return $this->cache; }
		$usage = array();
		$catalog = class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
		$contracts = new ScopedFieldContract();
		$json = new SafeJson();

		$programs = $this->scan_programs( $usage, $catalog, $contracts, $json );
		if ( is_wp_error( $programs ) ) { return $this->cache = $programs; }
		$batches = $this->scan_batches( $usage, $catalog, $contracts, $json );
		if ( is_wp_error( $batches ) ) { return $this->cache = $batches; }
		$templates = $this->scan_templates( $usage, new MergeSnapshotBuilder(), $json );
		if ( is_wp_error( $templates ) ) { return $this->cache = $templates; }
		ksort( $usage, SORT_STRING );
		return $this->cache = $usage;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function usage_for( $field_key ) {
		$key = sanitize_key( (string) $field_key );
		$all = $this->all_usage();
		if ( is_wp_error( $all ) ) { return $all; }
		return $all[ $key ] ?? $this->empty_usage( $key );
	}

	/** @return true|\WP_Error */
	private function scan_programs( array &$usage, array $catalog, ScopedFieldContract $contracts, SafeJson $json ) {
		global $wpdb; $last = 0;
		do {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,title,metadata_json FROM %i WHERE id>%d ORDER BY id ASC LIMIT %d', SchemaManager::table( 'programs' ), $last, self::CHUNK ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_field_usage_program_read_failed', __( 'Certloom could not verify Program field usage. No field can be retired safely until this check succeeds.', 'certloom' ) ); }
			foreach ( $rows as $row ) {
				$last = max( $last, absint( $row['id'] ?? 0 ) );
				$metadata = $json->decode_array( $row['metadata_json'] ?? '', 'certloom_field_usage_program_metadata_invalid', __( 'A Program has invalid field metadata. No field can be retired safely until it is repaired.', 'certloom' ) );
				if ( is_wp_error( $metadata ) ) { return $metadata; }
				$contract = $contracts->from_program_metadata( $metadata, $catalog );
				if ( is_wp_error( $contract ) ) { return $contract; }
				if ( empty( $contract['_meta']['configured'] ) ) { continue; }
				foreach ( array_keys( (array) ( $contract['fields'] ?? array() ) ) as $key ) {
					$this->record( $usage, $key, 'program', absint( $row['id'] ?? 0 ), (string) ( $row['title'] ?? '' ) );
				}
			}
		} while ( count( $rows ) === self::CHUNK );
		return true;
	}

	/** @return true|\WP_Error */
	private function scan_batches( array &$usage, array $catalog, ScopedFieldContract $contracts, SafeJson $json ) {
		global $wpdb; $last = 0;
		do {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,name,metadata_json FROM %i WHERE id>%d ORDER BY id ASC LIMIT %d', SchemaManager::table( 'batches' ), $last, self::CHUNK ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_field_usage_batch_read_failed', __( 'Certloom could not verify Batch field usage. No field can be retired safely until this check succeeds.', 'certloom' ) ); }
			foreach ( $rows as $row ) {
				$last = max( $last, absint( $row['id'] ?? 0 ) );
				$metadata = $json->decode_array( $row['metadata_json'] ?? '', 'certloom_field_usage_batch_metadata_invalid', __( 'A Batch has invalid field metadata. No field can be retired safely until it is repaired.', 'certloom' ) );
				if ( is_wp_error( $metadata ) ) { return $metadata; }
				$contract = $contracts->from_batch_metadata( $metadata, $catalog );
				if ( is_wp_error( $contract ) ) { return $contract; }
				if ( empty( $contract['_meta']['configured'] ) ) { continue; }
				foreach ( array_keys( (array) ( $contract['fields'] ?? array() ) ) as $key ) {
					$this->record( $usage, $key, 'batch', absint( $row['id'] ?? 0 ), (string) ( $row['name'] ?? '' ) );
				}
			}
		} while ( count( $rows ) === self::CHUNK );
		return true;
	}

	/** @return true|\WP_Error */
	private function scan_templates( array &$usage, MergeSnapshotBuilder $merges, SafeJson $json ) {
		global $wpdb; $last = 0;
		do {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT t.id,t.name,cr.layout_json AS current_layout,pr.layout_json AS published_layout FROM %i t LEFT JOIN %i cr ON cr.id=t.current_revision_id LEFT JOIN %i pr ON pr.id=t.published_revision_id WHERE t.id>%d AND t.archived_at IS NULL ORDER BY t.id ASC LIMIT %d',
				SchemaManager::table( 'templates' ), SchemaManager::table( 'template_revisions' ), SchemaManager::table( 'template_revisions' ), $last, self::CHUNK
			), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_field_usage_template_read_failed', __( 'Certloom could not verify Template field usage. No field can be retired safely until this check succeeds.', 'certloom' ) ); }
			foreach ( $rows as $row ) {
				$last = max( $last, absint( $row['id'] ?? 0 ) );
				$keys = array();
				foreach ( array( 'current_layout', 'published_layout' ) as $column ) {
					if ( null === ( $row[ $column ] ?? null ) || '' === (string) $row[ $column ] ) { continue; }
					$layout = $json->decode_array( $row[ $column ], 'certloom_field_usage_template_layout_invalid', __( 'An active Template has invalid layout data. No field can be retired safely until it is repaired.', 'certloom' ) );
					if ( is_wp_error( $layout ) ) { return $layout; }
					foreach ( $merges->required_tokens( $layout ) as $token ) {
						$key = ScopedFieldContract::field_key_from_merge_token( $token ); if ( null !== $key ) { $keys[ $key ] = true; }
					}
				}
				foreach ( array_keys( $keys ) as $key ) { $this->record( $usage, $key, 'template', absint( $row['id'] ?? 0 ), (string) ( $row['name'] ?? '' ) ); }
			}
		} while ( count( $rows ) === self::CHUNK );
		return true;
	}

	private function record( array &$usage, $field_key, $type, $id, $label ) {
		$key = sanitize_key( (string) $field_key ); if ( '' === $key ) { return; }
		if ( ! isset( $usage[ $key ] ) ) { $usage[ $key ] = $this->empty_usage( $key ); }
		$bucket = $type . 's'; $usage[ $key ][ $bucket ][] = array( 'id' => absint( $id ), 'label' => trim( (string) $label ) );
		$usage[ $key ][ $type . '_count' ] = count( $usage[ $key ][ $bucket ] );
		$usage[ $key ]['referenced'] = true;
	}

	/** @return array<string,mixed> */
	private function empty_usage( $key ) { return array( 'key' => sanitize_key( (string) $key ), 'referenced' => false, 'program_count' => 0, 'batch_count' => 0, 'template_count' => 0, 'programs' => array(), 'batches' => array(), 'templates' => array() ); }
}
