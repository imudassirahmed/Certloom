<?php
namespace Certloom\Infrastructure\Backup;

use Certloom\Application\Backup\WholeStateOptionInventory;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Backup\WholeStateTemplateRecommendationInventory;
use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only, bounded raw capture, including orphan metadata for later graph review. */
final class WholeStateTemplateRecommendationRepository {
	const PAGE_SIZE = 200;

	public function snapshot() {
		global $wpdb;
		$before = $this->descriptors();
		if ( is_wp_error( $before ) ) { return $before; }
		$records = array(); $format = new WholeStateOptionRecord();
		foreach ( array_chunk( array_keys( $before['options'] ), self::PAGE_SIZE ) as $keys ) {
			$cases = array(); $args = array();
			foreach ( $keys as $key ) { $cases[] = 'WHEN %s THEN %d'; $args[] = $key; $args[] = $before['options'][ $key ]['bytes']; }
			// Match each captured length in SQL: even a racing growth cannot return more than 4 MiB total.
			$args[] = $wpdb->options; $args = array_merge( $args, $keys, array( self::PAGE_SIZE + 1 ) );
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT option_name, CASE WHEN OCTET_LENGTH(option_value) = CASE option_name ' . implode( ' ', $cases ) . ' END THEN option_value ELSE NULL END AS storage FROM %i FORCE INDEX (option_name) WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $keys ), '%s' ) ) . ') LIMIT %d', $args
			), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > self::PAGE_SIZE ) { return $this->failed(); }
			if ( count( $rows ) !== count( $keys ) ) { return $this->changed(); }
			foreach ( $rows as $row ) {
				$name = $row['option_name'] ?? null;
				if ( ! is_string( $name ) || ! in_array( $name, $keys, true ) || isset( $records[ $name ] ) ) { return $this->failed(); }
				if ( ! isset( $row['storage'] ) || ! is_string( $row['storage'] ) ) { return $this->changed(); }
				$record = $format->from_storage( $row['storage'] );
				if ( is_wp_error( $record ) ) { return $record; }
				if ( $record['bytes'] !== $before['options'][ $name ]['bytes'] || ! hash_equals( $before['options'][ $name ]['sha256'], $record['sha256'] ) ) { return $this->changed(); }
				$records[ $name ] = $record;
			}
		}
		// An exact-key value page cannot see a newly inserted key; recheck the complete namespace.
		$current = $this->descriptors();
		if ( is_wp_error( $current ) ) { return $current; }
		if ( ! hash_equals( $before['fingerprint'], $current['fingerprint'] ) ) { return $this->changed(); }
		$fingerprint = $format->fingerprint_set( $records, array_keys( $before['options'] ) );
		if ( is_wp_error( $fingerprint ) || ! hash_equals( $before['fingerprint'], $fingerprint ) ) { return $this->changed(); }
		return array( 'inventory_version' => WholeStateTemplateRecommendationInventory::VERSION, 'options' => $records, 'fingerprint' => $fingerprint );
	}

	public function verify( $expected_fingerprint ) {
		if ( ! is_string( $expected_fingerprint ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_fingerprint ) ) { return $this->failed(); }
		$current = $this->descriptors();
		return is_wp_error( $current ) ? $current : ( hash_equals( $expected_fingerprint, $current['fingerprint'] ) ? true : $this->changed() );
	}

	private function descriptors() {
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT option_name, OCTET_LENGTH(option_value) AS bytes, SHA2(option_value,256) AS sha256 FROM %i FORCE INDEX (option_name) WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d',
			array( $wpdb->options, $wpdb->esc_like( WholeStateTemplateRecommendationInventory::PREFIX ) . '%', WholeStateTemplateRecommendationInventory::MAX_OPTIONS + 1 )
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) || count( $rows ) > WholeStateTemplateRecommendationInventory::MAX_OPTIONS ) { return $this->failed(); }
		$records = array(); $total = 0;
		foreach ( $rows as $row ) {
			$name = $row['option_name'] ?? null; $length = $row['bytes'] ?? null; $sha = $row['sha256'] ?? null;
			if ( false === WholeStateTemplateRecommendationInventory::template_id( $name ) || isset( $records[ $name ] ) || ( ! is_int( $length ) && ! is_string( $length ) ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]{0,9})$/D', (string) $length ) || (int) $length > WholeStateOptionInventory::MAX_OPTION_BYTES || (int) $length > WholeStateOptionInventory::MAX_TOTAL_OPTION_BYTES - $total || ! is_string( $sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha ) ) { return $this->failed(); }
			$records[ $name ] = array( 'exists' => true, 'bytes' => (int) $length, 'sha256' => $sha ); $total += (int) $length;
		}
		try { $fingerprint = ( new CanonicalJson() )->hash( $records ); } catch ( \Throwable $error ) { return $this->failed(); }
		return array( 'options' => $records, 'fingerprint' => $fingerprint );
	}

	private function changed() { return new \WP_Error( 'certloom_whole_recommendations_changed', __( 'Certificate design profile metadata changed during recovery capture. Retry after current edits finish.', 'certloom' ) ); }
	private function failed() { return new \WP_Error( 'certloom_whole_recommendations_read_failed', __( 'Certloom could not read its complete bounded certificate design profile metadata for recovery.', 'certloom' ) ); }
}
