<?php
namespace Certloom\Application\Backup;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Lossless SQL storage transport. Delivery semantics and restart permission are separate. */
final class WholeStateDeliveryRecord {
	const VERSION = 1;
	const MAX_ROW_BYTES = 262144;
	const MAX_RECORDS = 100000;
	const MAX_TOTAL_BYTES = 268435456;

	public static function columns( $entity ) {
		if ( 'email_deliveries' === $entity ) {
			return array( 'id', 'delivery_key', 'series_id', 'version_id', 'job_key', 'delivery_kind', 'status', 'destination_mask', 'destination_sha256', 'subject_sha256', 'html_sha256', 'text_sha256', 'composition_sha256', 'template_version', 'attempt_token', 'attempted_at', 'accepted_at', 'failed_at', 'failure_code', 'evidence_json', 'created_at', 'updated_at' );
		}
		if ( 'destination_deliveries' === $entity ) {
			return array( 'id', 'delivery_key', 'dedupe_key', 'destination', 'series_id', 'version_id', 'job_key', 'status', 'mapping_sha256', 'configuration_sha256', 'recipient_sha256', 'external_reference', 'attempt_token', 'attempted_at', 'accepted_at', 'failed_at', 'failure_code', 'evidence_json', 'created_at', 'updated_at' );
		}
		return array();
	}

	public static function id( $value, $allow_zero = false ) {
		if ( ! is_string( $value ) || 1 !== preg_match( $allow_zero ? '/^(?:0|[1-9][0-9]{0,18})$/D' : '/^[1-9][0-9]{0,18}$/D', $value ) ) { return false; }
		$ceiling = (string) PHP_INT_MAX;
		// Check decimal magnitude before casting: PHP 8.5 warns on overflowing strings.
		return strlen( $value ) < strlen( $ceiling ) || ( strlen( $value ) === strlen( $ceiling ) && strcmp( $value, $ceiling ) <= 0 );
	}

	public function from_row( $entity, array $row ) {
		$columns = self::columns( $entity );
		if ( ! $columns || count( $row ) !== count( $columns ) || array_diff( array_keys( $row ), $columns ) || ! self::id( $row['id'] ?? null ) ) { return $this->invalid(); }
		$stored = array(); $bytes = 0;
		foreach ( $columns as $column ) {
			$value = $row[ $column ];
			if ( null !== $value && ! is_string( $value ) ) { return $this->invalid(); }
			$length = null === $value ? 0 : strlen( $value );
			if ( $length > self::MAX_ROW_BYTES - $bytes ) { return $this->invalid(); }
			$bytes += $length; $stored[ $column ] = null === $value ? null : base64_encode( $value );
		}
		try { $sha = ( new CanonicalJson() )->hash( $stored ); } catch ( \Throwable $error ) { return $this->invalid(); }
		return array( 'record_version' => self::VERSION, 'entity' => $entity, 'id' => $row['id'], 'bytes' => $bytes, 'sha256' => $sha, 'columns' => $stored );
	}

	/** Decode only base64 storage. No JSON evidence parsing or PHP unserialization. */
	public function to_row( $record ) {
		$keys = array( 'record_version', 'entity', 'id', 'bytes', 'sha256', 'columns' );
		if ( ! is_array( $record ) || count( $record ) !== count( $keys ) || array_diff( array_keys( $record ), $keys ) || self::VERSION !== $record['record_version'] || ! is_string( $record['entity'] ) || ! self::id( $record['id'] ) || ! is_int( $record['bytes'] ) || $record['bytes'] < 0 || $record['bytes'] > self::MAX_ROW_BYTES || ! is_string( $record['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record['sha256'] ) || ! is_array( $record['columns'] ) ) { return $this->invalid(); }
		$columns = self::columns( $record['entity'] );
		if ( ! $columns || count( $record['columns'] ) !== count( $columns ) || array_diff( array_keys( $record['columns'] ), $columns ) ) { return $this->invalid(); }
		$row = array(); $bytes = 0;
		foreach ( $columns as $column ) {
			$encoded = $record['columns'][ $column ];
			if ( null === $encoded ) { $row[ $column ] = null; continue; }
			if ( ! is_string( $encoded ) || strlen( $encoded ) > 4 * (int) ceil( ( self::MAX_ROW_BYTES - $bytes ) / 3 ) ) { return $this->invalid(); }
			$value = base64_decode( $encoded, true );
			if ( ! is_string( $value ) || base64_encode( $value ) !== $encoded || strlen( $value ) > self::MAX_ROW_BYTES - $bytes ) { return $this->invalid(); }
			$row[ $column ] = $value; $bytes += strlen( $value );
		}
		$expected = $this->from_row( $record['entity'], $row );
		return is_wp_error( $expected ) || $expected['id'] !== $record['id'] || $expected['bytes'] !== $record['bytes'] || ! hash_equals( $expected['sha256'], $record['sha256'] ) ? $this->invalid() : $row;
	}

	private function invalid() { return new \WP_Error( 'certloom_whole_delivery_record_invalid', __( 'Certloom could not verify bounded delivery storage without losing its evidence.', 'certloom' ) ); }
}
