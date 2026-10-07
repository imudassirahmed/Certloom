<?php
namespace Certloom\Infrastructure\Backup;

use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Checked raw reads, bypassing filters/cache/deserialization. No write or restoration methods. */
final class WholeStateOptionRepository {
	public function snapshot() {
		global $wpdb;
		$before = $this->descriptors();
		if ( is_wp_error( $before ) ) { return $before; }
		$keys = WholeStateOptionInventory::keys();
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS storage FROM %i WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')',
			array_merge( array( WholeStateOptionInventory::MAX_OPTION_BYTES, $wpdb->options ), $keys )
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->failed(); }
		$format = new WholeStateOptionRecord(); $records = array_fill_keys( $keys, $format->from_storage( null ) );
		foreach ( $rows as $row ) {
			$key = $row['option_name'] ?? null;
			if ( ! is_string( $key ) || ! array_key_exists( $key, $records ) || $records[ $key ]['exists'] || ! isset( $row['storage'] ) || ! is_string( $row['storage'] ) ) { return $this->failed(); }
			$record = $format->from_storage( $row['storage'] );
			if ( is_wp_error( $record ) ) { return $record; }
			$records[ $key ] = $record;
		}
		$fingerprint = $format->fingerprint( $records );
		if ( is_wp_error( $fingerprint ) ) { return $fingerprint; }
		// Detect inserts/deletes and byte changes between the bounded descriptor and value reads.
		if ( ! hash_equals( $before['fingerprint'], $fingerprint ) ) { return $this->changed(); }
		return array( 'inventory_version' => WholeStateOptionInventory::VERSION, 'options' => $records, 'fingerprint' => $fingerprint );
	}

	/** Final capture must also bracket authoring, delivery and asset reads with the common write fence. */
	public function verify( $expected_fingerprint ) {
		if ( ! is_string( $expected_fingerprint ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_fingerprint ) ) { return $this->failed(); }
		$current = $this->descriptors();
		if ( is_wp_error( $current ) ) { return $current; }
		return hash_equals( $expected_fingerprint, $current['fingerprint'] ) ? true : $this->changed();
	}

	private function descriptors() {
		global $wpdb;
		$keys = WholeStateOptionInventory::keys(); $empty = array( 'exists' => false, 'bytes' => 0, 'sha256' => hash( 'sha256', '' ) ); $records = array_fill_keys( $keys, $empty );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name, OCTET_LENGTH(option_value) AS bytes, SHA2(option_value,256) AS sha256 FROM %i WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ')',
			array_merge( array( $wpdb->options ), $keys )
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->failed(); }
		$total = 0;
		foreach ( $rows as $row ) {
			$key = $row['option_name'] ?? null; $length = $row['bytes'] ?? null; $sha = $row['sha256'] ?? null;
			if ( ! is_string( $key ) || ! array_key_exists( $key, $records ) || $records[ $key ]['exists'] || ( ! is_int( $length ) && ! is_string( $length ) ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,9})$/D', (string) $length ) || (int) $length > WholeStateOptionInventory::MAX_OPTION_BYTES || ! is_string( $sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha ) || (int) $length > WholeStateOptionInventory::MAX_TOTAL_OPTION_BYTES - $total ) { return $this->failed(); }
			$records[ $key ] = array( 'exists' => true, 'bytes' => (int) $length, 'sha256' => $sha ); $total += (int) $length;
		}
		try { $fingerprint = ( new CanonicalJson() )->hash( $records ); } catch ( \Throwable $error ) { return $this->failed(); }
		return array( 'fingerprint' => $fingerprint );
	}

	private function changed() { return new \WP_Error( 'certloom_whole_configuration_changed', __( 'Certloom configuration changed during recovery capture. Retry after current edits finish.', 'certloom' ) ); }
	private function failed() { return new \WP_Error( 'certloom_whole_configuration_read_failed', __( 'Certloom could not read its complete bounded configuration for recovery.', 'certloom' ) ); }
}
