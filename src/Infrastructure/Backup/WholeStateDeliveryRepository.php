<?php
namespace Certloom\Infrastructure\Backup;

use Certloom\Application\Backup\WholeStateDeliveryRecord;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded keyset storage capture. Does not import rows or reconstruct execution jobs. */
final class WholeStateDeliveryRepository {
	const PAGE_SIZE = 16; // At most 4 MiB raw returned storage, even after a racing growth.

	public function snapshot() {
		global $wpdb;
		$generation = ( new DomainGeneration() )->read_current();
		if ( is_wp_error( $generation ) ) { return $generation; }
		$entities = array(); $count = 0; $bytes = 0;
		foreach ( array( 'email_deliveries', 'destination_deliveries' ) as $type ) {
			$length = $this->length_sql( $type ); $wpdb->last_error = '';
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS row_count,COALESCE(MAX(id),0) AS max_id,COALESCE(MAX(' . $length . '),0) AS max_bytes,COALESCE(SUM(' . $length . '),0) AS total_bytes FROM %i', SchemaManager::table( $type ) ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) { return $this->failed(); }
			foreach ( array( 'row_count', 'max_id', 'max_bytes', 'total_bytes' ) as $key ) { if ( ! WholeStateDeliveryRecord::id( $row[ $key ] ?? null, true ) ) { return $this->failed(); } }
			if ( (int) $row['row_count'] > WholeStateDeliveryRecord::MAX_RECORDS - $count || (int) $row['total_bytes'] > WholeStateDeliveryRecord::MAX_TOTAL_BYTES - $bytes || (int) $row['max_bytes'] > WholeStateDeliveryRecord::MAX_ROW_BYTES || ( 0 === (int) $row['row_count'] ) !== ( '0' === $row['max_id'] ) ) { return $this->failed(); }
			$count += (int) $row['row_count']; $bytes += (int) $row['total_bytes'];
			$entities[ $type ] = array( 'count' => (int) $row['row_count'], 'max_id' => $row['max_id'], 'bytes' => (int) $row['total_bytes'] );
		}
		$after = ( new DomainGeneration() )->read_current();
		if ( is_wp_error( $after ) ) { return $after; }
		if ( ! hash_equals( $generation, $after ) ) { return $this->changed(); }
		$data = array( 'inventory_version' => WholeStateDeliveryRecord::VERSION, 'generation' => $generation, 'entities' => $entities );
		try { $data['fingerprint'] = ( new CanonicalJson() )->hash( $data ); } catch ( \Throwable $error ) { return $this->failed(); }
		return $data;
	}

	public function verify( array $snapshot ) {
		$valid = $this->validate_snapshot( $snapshot ); if ( is_wp_error( $valid ) ) { return $valid; }
		$current = $this->snapshot();
		return is_wp_error( $current ) ? $current : ( hash_equals( $snapshot['fingerprint'], $current['fingerprint'] ) ? true : $this->changed() );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error Lossless records; SQL null remains distinct from empty. */
	public function read_batch( array $snapshot, $type, $after_id ) {
		global $wpdb;
		$valid = $this->validate_snapshot( $snapshot ); if ( is_wp_error( $valid ) ) { return $valid; }
		if ( ! is_string( $type ) || ! isset( $snapshot['entities'][ $type ] ) || ! WholeStateDeliveryRecord::id( $after_id, true ) ) { return $this->failed(); }
		$generation = ( new DomainGeneration() )->read_current();
		if ( is_wp_error( $generation ) ) { return $generation; }
		if ( ! hash_equals( $snapshot['generation'], $generation ) ) { return $this->changed(); }
		$max = $snapshot['entities'][ $type ]['max_id'];
		if ( (int) $after_id >= (int) $max ) { return array(); }
		$columns = array();
		foreach ( WholeStateDeliveryRecord::columns( $type ) as $column ) { $columns[] = 'evidence_json' === $column ? 'CASE WHEN ' . $this->length_sql( $type ) . ' <= ' . WholeStateDeliveryRecord::MAX_ROW_BYTES . ' THEN evidence_json ELSE NULL END AS evidence_json' : '`' . $column . '`'; }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . implode( ',', $columns ) . ',' . $this->length_sql( $type ) . ' AS storage_bytes FROM %i FORCE INDEX (PRIMARY) WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d', SchemaManager::table( $type ), (int) $after_id, (int) $max, self::PAGE_SIZE ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > self::PAGE_SIZE ) { return $this->failed(); }
		if ( ! $rows ) { return $this->changed(); }
		$out = array(); $last = (int) $after_id; $codec = new WholeStateDeliveryRecord();
		foreach ( $rows as $row ) {
			if ( ! WholeStateDeliveryRecord::id( $row['storage_bytes'] ?? null, true ) || (int) $row['storage_bytes'] > WholeStateDeliveryRecord::MAX_ROW_BYTES || ! WholeStateDeliveryRecord::id( $row['id'] ?? null ) || (int) $row['id'] <= $last || (int) $row['id'] > (int) $max ) { return $this->changed(); }
			$size = (int) $row['storage_bytes']; unset( $row['storage_bytes'] );
			$record = $codec->from_row( $type, $row ); if ( is_wp_error( $record ) ) { return $record; }
			if ( $record['bytes'] !== $size ) { return $this->changed(); }
			$out[] = $record; $last = (int) $row['id'];
		}
		$generation = ( new DomainGeneration() )->read_current();
		return is_wp_error( $generation ) ? $generation : ( hash_equals( $snapshot['generation'], $generation ) ? $out : $this->changed() );
	}

	private function validate_snapshot( array $snapshot ) {
		$keys = array( 'inventory_version', 'generation', 'entities', 'fingerprint' );
		if ( count( $snapshot ) !== count( $keys ) || array_diff( array_keys( $snapshot ), $keys ) || WholeStateDeliveryRecord::VERSION !== $snapshot['inventory_version'] || ! is_string( $snapshot['generation'] ) || '' === $snapshot['generation'] || strlen( $snapshot['generation'] ) > 128 || ! is_array( $snapshot['entities'] ) || count( $snapshot['entities'] ) !== 2 || array_diff( array_keys( $snapshot['entities'] ), array( 'email_deliveries', 'destination_deliveries' ) ) || ! is_string( $snapshot['fingerprint'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot['fingerprint'] ) ) { return $this->failed(); }
		$count = 0; $bytes = 0;
		foreach ( $snapshot['entities'] as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) !== 3 || array_diff( array_keys( $entry ), array( 'count', 'max_id', 'bytes' ) ) || ! is_int( $entry['count'] ) || $entry['count'] < 0 || $entry['count'] > WholeStateDeliveryRecord::MAX_RECORDS - $count || ! WholeStateDeliveryRecord::id( $entry['max_id'], true ) || ! is_int( $entry['bytes'] ) || $entry['bytes'] < 0 || $entry['bytes'] > WholeStateDeliveryRecord::MAX_TOTAL_BYTES - $bytes || ( 0 === $entry['count'] ) !== ( '0' === $entry['max_id'] ) || ( 0 === $entry['count'] && 0 !== $entry['bytes'] ) ) { return $this->failed(); }
			if ( $entry['count'] > (int) $entry['max_id'] || $entry['bytes'] < $entry['count'] ) { return $this->failed(); }
			$count += $entry['count']; $bytes += $entry['bytes'];
		}
		$unsigned = $snapshot; unset( $unsigned['fingerprint'] );
		try { return hash_equals( $snapshot['fingerprint'], ( new CanonicalJson() )->hash( $unsigned ) ) ? true : $this->failed(); } catch ( \Throwable $error ) { return $this->failed(); }
	}

	private function length_sql( $type ) { return '(' . implode( '+', array_map( static function ( $column ) { return 'OCTET_LENGTH(COALESCE(`' . $column . '`,\'\'))'; }, WholeStateDeliveryRecord::columns( $type ) ) ) . ')'; }
	private function failed() { return new \WP_Error( 'certloom_whole_delivery_capture_failed', __( 'Certloom could not capture the complete bounded delivery inventory.', 'certloom' ) ); }
	private function changed() { return new \WP_Error( 'certloom_whole_delivery_capture_changed', __( 'Delivery evidence changed during capture. Start a fresh recovery snapshot.', 'certloom' ) ); }
}
