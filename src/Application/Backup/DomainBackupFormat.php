<?php
namespace Certloom\Application\Backup;

/** Versioned line-oriented backup format whose chained hash can resume across requests. */
final class DomainBackupFormat {
	const FORMAT = 'certloom-domain-backup';
	const VERSION = 3;
	const LEGACY_VERSION = 1;
	const STRUCTURE_VERSION = 2;
	const HASH_SEED_V1 = 'certloom-domain-backup-v1';
	const HASH_SEED_V2 = 'certloom-domain-backup-v2';
	const HASH_SEED_V3 = 'certloom-domain-backup-v3';
	const MAX_LINE_BYTES = 4 * 1024 * 1024;
	const MAX_FILE_BYTES = 1024 * 1024 * 1024;

	/** @return array<int,int> */
	public static function supported_versions() { return array( self::LEGACY_VERSION, self::STRUCTURE_VERSION, self::VERSION ); }

	/** @return array<int,string> */
	public static function entity_types_for_version( $version ) {
		if ( self::LEGACY_VERSION === absint( $version ) ) {
			return array( 'issuers', 'programs', 'recipients', 'credential_series', 'credential_numbers', 'credential_versions', 'credential_events' );
		}
		return array( 'issuers', 'programs', 'recipients', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'credential_series', 'credential_numbers', 'credential_versions', 'credential_events' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_manifest( array $manifest ) {
		$version = absint( $manifest['format_version'] ?? 0 );
		if ( 'manifest' !== (string) ( $manifest['type'] ?? '' ) || self::FORMAT !== (string) ( $manifest['format'] ?? '' ) || ! in_array( $version, self::supported_versions(), true ) ) {
			return new \WP_Error( 'certloom_domain_backup_manifest_invalid', __( 'This is not a supported Certloom domain backup.', 'certloom' ) );
		}
		$source_types = self::entity_types_for_version( $version );
		$declared = isset( $manifest['entities'] ) && is_array( $manifest['entities'] ) ? $manifest['entities'] : array();
		if ( array_keys( $declared ) !== $source_types ) {
			return new \WP_Error( 'certloom_domain_backup_manifest_invalid', __( 'The Certloom backup manifest does not declare the exact supported entity set in canonical order.', 'certloom' ) );
		}
		foreach ( $source_types as $entity ) {
			if ( ! is_array( $declared[ $entity ] ) || ! array_key_exists( 'count', $declared[ $entity ] ) || ! array_key_exists( 'max_id', $declared[ $entity ] ) ) {
				return new \WP_Error( 'certloom_domain_backup_manifest_invalid', __( 'The Certloom backup manifest contains an incomplete entity declaration.', 'certloom' ) );
			}
		}
		$normalized = $manifest;
		$normalized['entities'] = array();
		foreach ( self::entity_types_for_version( self::VERSION ) as $entity ) {
			$bound = isset( $declared[ $entity ] ) && is_array( $declared[ $entity ] ) ? $declared[ $entity ] : array( 'count' => 0, 'max_id' => 0 );
			$normalized['entities'][ $entity ] = array( 'count' => absint( $bound['count'] ?? 0 ), 'max_id' => absint( $bound['max_id'] ?? 0 ) );
		}
		$normalized['source_format_version'] = $version;
		return $normalized;
	}

	/**
	 * Upgrade an entity row from an older supported backup format into the
	 * current in-memory row contract before semantic validation/staging.
	 *
	 * @return array<string,mixed>
	 */
	public function normalize_entity_row( $version, $entity, array $row ) {
		$version = absint( $version );
		$entity  = sanitize_key( (string) $entity );
		$normalized = $row;
		if ( self::LEGACY_VERSION === $version && 'credential_series' === $entity && ! array_key_exists( 'batch_id', $normalized ) ) {
			$rebuilt = array();
			foreach ( $normalized as $key => $value ) {
				$rebuilt[ $key ] = $value;
				if ( 'program_id' === (string) $key ) { $rebuilt['batch_id'] = null; }
			}
			if ( ! array_key_exists( 'batch_id', $rebuilt ) ) { $rebuilt['batch_id'] = null; }
			$normalized = $rebuilt;
		}
		if ( $version < self::VERSION && 'recipients' === $entity ) {
			$rebuilt = array();
			foreach ( $normalized as $key => $value ) {
				$rebuilt[ $key ] = $value;
				if ( 'email' === (string) $key ) {
					if ( ! array_key_exists( 'privacy_identity_hashes', $normalized ) ) { $rebuilt['privacy_identity_hashes'] = null; }
					if ( ! array_key_exists( 'privacy_anonymized_at', $normalized ) ) { $rebuilt['privacy_anonymized_at'] = null; }
				}
			}
			if ( ! array_key_exists( 'privacy_identity_hashes', $rebuilt ) ) { $rebuilt['privacy_identity_hashes'] = null; }
			if ( ! array_key_exists( 'privacy_anonymized_at', $rebuilt ) ) { $rebuilt['privacy_anonymized_at'] = null; }
			$normalized = $rebuilt;
		}
		return $normalized;
	}

	/** @return string|\WP_Error */
	public function encode_line( array $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json
			? new \WP_Error( 'certloom_domain_backup_encode_failed', __( 'Certloom could not encode backup data safely.', 'certloom' ) )
			: $json . "\n";
	}

	/** @return string|null|\WP_Error */
	public function read_line( $handle, $line_no ) {
		$line = fgets( $handle, self::MAX_LINE_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Bounded streaming backup parser.
		if ( false === $line ) {
			return feof( $handle ) ? null : new \WP_Error( 'certloom_domain_backup_read_failed', __( 'Certloom could not read the backup stream safely.', 'certloom' ) );
		}
		if ( strlen( $line ) > self::MAX_LINE_BYTES || ( self::MAX_LINE_BYTES === strlen( $line ) && ! "\n" === substr( $line, -1 ) && ! feof( $handle ) ) ) {
			return new \WP_Error( 'certloom_domain_backup_line_too_large', sprintf( __( 'The Certloom backup contains an oversized record on line %d.', 'certloom' ), absint( $line_no ) ) );
		}
		return $line;
	}

	/** @return string */
	public function next_hash( $previous, $line, $version = self::VERSION ) {
		$version = absint( $version );
		$seed = self::LEGACY_VERSION === $version ? self::HASH_SEED_V1 : ( self::STRUCTURE_VERSION === $version ? self::HASH_SEED_V2 : self::HASH_SEED_V3 );
		$previous = '' === (string) $previous ? $seed : (string) $previous;
		return hash( 'sha256', $previous . "\n" . (string) $line );
	}

	/** @return array<string,mixed> */
	public function manifest( array $snapshot, $plugin_version ) {
		return array(
			'type' => 'manifest',
			'format' => self::FORMAT,
			'format_version' => self::VERSION,
			'plugin_version' => (string) $plugin_version,
			'generated_at' => gmdate( 'c' ),
			'generation' => (string) ( $snapshot['generation'] ?? '' ),
			'entities' => (array) ( $snapshot['entities'] ?? array() ),
			'configuration' => (array) ( $snapshot['configuration'] ?? array() ),
			'configuration_hash' => (string) ( $snapshot['configuration_hash'] ?? '' ),
		);
	}
}
