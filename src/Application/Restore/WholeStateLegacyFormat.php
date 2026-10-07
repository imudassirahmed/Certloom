<?php
namespace Certloom\Application\Restore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Current v4 stream preflight without transient sessions, runtime field reads or repairs. */
final class WholeStateLegacyFormat {
	public static function columns() { return array( 'id', 'cert_number', 'cert_number_hash', 'status', 'status_source', 'holder_name', 'issue_date', 'expiry_date', 'custom_fields', 'created_at', 'updated_at' ); }
	public function analyze( $handle, callable $on_record ) {
		if ( ! is_resource( $handle ) ) { return $this->invalid(); }
		$bytes = 0; $first = $this->line( $handle, $bytes ); if ( is_wp_error( $first ) || null === $first ) { return $this->invalid(); }
		$manifest = $this->decode( $first );
		$keys = array( 'type', 'format', 'format_version', 'plugin_version', 'schema_version', 'generated_at', 'fields', 'retired_fields', 'settings', 'numbering_state' );
		if ( ! $this->keys( $manifest, $keys ) || 'manifest' !== $manifest['type'] || \Ecapla_CertVerify_Backup::FORMAT !== $manifest['format'] || 4 !== $manifest['format_version'] || ! WholeStateOptionRows::iso_date( $manifest['generated_at'] ) ) { return $this->invalid(); }
		foreach ( array( 'plugin_version', 'schema_version' ) as $key ) {
			if ( ! is_string( $manifest[$key] ) || strlen( $manifest[$key] ) > 80 || ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+.][a-zA-Z0-9.-]+)?$/D', $manifest[$key] ) ) { return $this->invalid(); }
		}
		$fields = WholeStateOptionRows::guarded( static function () use ( $manifest ) { return \Ecapla_CertVerify_Fields::validate_recovery_value( $manifest['fields'] ); } );
		$retired = WholeStateOptionRows::guarded( static function () use ( $manifest ) { return \Ecapla_CertVerify_Fields::validate_recovery_value( $manifest['retired_fields'], true ); } );
		if ( is_wp_error( $fields ) || is_wp_error( $retired ) ) { return $this->invalid(); }
		$catalog = $retired; foreach ( $fields as $field ) { $catalog[$field['key']] = $field; }
		$settings = WholeStateOptionRows::guarded( static function () use ( $manifest, $catalog ) { return \Ecapla_CertVerify_Settings::validate_recovery_value( $manifest['settings'], $catalog ); } );
		$numbering = \Ecapla_CertVerify_Numbering::validate_backup_state( $manifest['numbering_state'] ); if ( is_wp_error( $settings ) || is_wp_error( $numbering ) || ( null !== $manifest['numbering_state'] && ! is_int( $manifest['numbering_state'] ) ) ) { return $this->invalid(); }
		$hash = hash_init( 'sha256' ); hash_update( $hash, $first ); $count = 0; $seen = array();
		while ( null !== ( $line = $this->line( $handle, $bytes ) ) ) {
			if ( is_wp_error( $line ) ) { return $line; } $item = $this->decode( $line ); if ( is_wp_error( $item ) ) { return $item; }
			if ( 'integrity' === ( $item['type'] ?? null ) ) {
				if ( ! $this->keys( $item, array( 'type', 'records', 'sha256' ) ) || $item['records'] !== $count || ! is_string( $item['sha256'] ) || ! hash_equals( hash_final( $hash ), $item['sha256'] ) || null !== $this->line( $handle, $bytes ) ) { return $this->invalid(); }
				return array( 'manifest' => $manifest, 'records' => $count );
			}
			if ( ! $this->keys( $item, array( 'type', 'data' ) ) || 'record' !== $item['type'] || ! is_array( $item['data'] ) || ++$count > \Ecapla_CertVerify_Backup::MAX_RECORDS ) { return $this->invalid(); }
			$row = $this->to_row( $item['data'], $catalog, $count ); if ( is_wp_error( $row ) || isset( $seen[$row['cert_number_hash']] ) ) { return $this->invalid(); } $seen[$row['cert_number_hash']] = true;
			$accepted = $on_record( $row ); if ( is_wp_error( $accepted ) ) { return $accepted; } hash_update( $hash, $line );
		}
		return $this->invalid();
	}
	public function to_row( array $record, array $catalog, $id ) {
		$keys = array( 'cert_number', 'status', 'status_source', 'holder_name', 'issue_date', 'expiry_date', 'custom_fields', 'created_at', 'updated_at' );
		if ( ! $this->keys( $record, $keys ) || ! is_int( $id ) || $id < 1 || ! is_string( $record['cert_number'] ) || '' === $record['cert_number'] || strlen( $record['cert_number'] ) > 400 || trim( sanitize_text_field( $record['cert_number'] ) ) !== $record['cert_number'] || ! is_string( $record['holder_name'] ) || strlen( $record['holder_name'] ) > 1020 || sanitize_text_field( $record['holder_name'] ) !== $record['holder_name'] || ! in_array( $record['status'], array( 'valid', 'suspended', 'withdrawn', 'expired' ), true ) || ! in_array( $record['status_source'], array( 'manual', 'legacy', 'date' ), true ) || ! is_array( $record['custom_fields'] ) || count( $record['custom_fields'] ) > 5000 ) { return $this->invalid(); }
		foreach ( array( 'created_at', 'updated_at' ) as $key ) { if ( ! WholeStateOptionRows::date( $record[$key], 'Y-m-d H:i:s' ) ) { return $this->invalid(); } }
		foreach ( array( 'issue_date', 'expiry_date' ) as $key ) { if ( null !== $record[$key] && ! WholeStateOptionRows::date( $record[$key], 'Y-m-d' ) ) { return $this->invalid(); } }
		if ( $record['issue_date'] && $record['expiry_date'] && $record['expiry_date'] < $record['issue_date'] ) { return $this->invalid(); }
		foreach ( $record['custom_fields'] as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $catalog[$key] ) || ! empty( $catalog[$key]['column'] ) || ( null !== $value && ! is_scalar( $value ) ) || ( is_float( $value ) && ! is_finite( $value ) ) ) { return $this->invalid(); }
			$normalized = WholeStateOptionRows::guarded( static function () use ( $value, $catalog, $key ) { return \Ecapla_CertVerify_Validator::custom_field_value( $value, $catalog[$key] ); } );
			if ( is_wp_error( $normalized ) || (string) $value !== $normalized ) { return $this->invalid(); }
		}
		$core = $record; unset( $core['custom_fields'], $core['status_source'], $core['created_at'], $core['updated_at'] );
		$validated = \Ecapla_CertVerify_Validator::certificate_data( $core ); if ( is_wp_error( $validated ) ) { return $this->invalid(); }
		foreach ( $core as $key => $value ) { if ( ! array_key_exists( $key, $validated ) || $validated[$key] !== $value ) { return $this->invalid(); } }
		$json = wp_json_encode( $record['custom_fields'] ); if ( ! is_string( $json ) ) { return $this->invalid(); }
		$row = array_merge( array( 'id' => $id, 'cert_number_hash' => \Ecapla_CertVerify_Database::certificate_hash( $record['cert_number'] ) ), $record ); $row['custom_fields'] = $json; return $row;
	}
	private function line( $handle, &$bytes ) { $line = fgets( $handle, 8388609 ); if ( false === $line ) { return feof( $handle ) ? null : $this->invalid(); } if ( "\n" !== substr( $line, -1 ) || strlen( $line ) > 8388608 || strlen( $line ) > \Ecapla_CertVerify_Backup::MAX_FILE_BYTES - $bytes ) { return $this->invalid(); } $bytes += strlen( $line ); return $line; }
	private function decode( $line ) { $value = json_decode( $line, true, 48 ); return JSON_ERROR_NONE !== json_last_error() || ! is_array( $value ) || wp_json_encode( $value ) . "\n" !== $line ? $this->invalid() : $value; }
	private function keys( $value, array $keys ) { return is_array( $value ) && count( $value ) === count( $keys ) && ! array_diff( array_keys( $value ), $keys ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_legacy_semantics_invalid', __( 'The recovery registry contains invalid records, fields, numbering or receipt evidence. No registry was restored.', 'certloom' ) ); }
}
