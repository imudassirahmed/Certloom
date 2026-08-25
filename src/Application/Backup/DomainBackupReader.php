<?php
namespace Certloom\Application\Backup;

/** Streaming validator/preflight reader for the versioned domain backup format. */
final class DomainBackupReader {
	private $format;
	public function __construct( ?DomainBackupFormat $format = null ) { $this->format = $format ?: new DomainBackupFormat(); }

	/** @return array<string,mixed>|\WP_Error */
	public function analyze( $path, $max_records = 500000 ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) { return new \WP_Error( 'certloom_domain_backup_read_failed', __( 'The Certloom domain backup file could not be read.', 'certloom' ) ); }
		$size = @filesize( $path );
		if ( false !== $size && $size > DomainBackupFormat::MAX_FILE_BYTES ) { return new \WP_Error( 'certloom_domain_backup_file_too_large', __( 'The Certloom domain backup exceeds the supported restore size limit.', 'certloom' ) ); }
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) { return new \WP_Error( 'certloom_domain_backup_read_failed', __( 'The Certloom domain backup file could not be opened.', 'certloom' ) ); }
		$manifest = null; $hash = ''; $records = 0; $counts = array(); $integrity = null; $line_no = 0; $after_integrity = false; $format_version = 0;
		$allowed_entities = array();
		while ( true ) {
			$line_no++;
			$line = $this->format->read_line( $handle, $line_no );
			if ( is_wp_error( $line ) ) { fclose( $handle ); return $line; }
			if ( null === $line ) { $line_no--; break; }
			if ( $after_integrity ) {
				if ( '' !== trim( $line ) ) { fclose( $handle ); return new \WP_Error( 'certloom_domain_backup_trailing_data', __( 'The Certloom domain backup contains unexpected data after its integrity record.', 'certloom' ) ); }
				continue;
			}
			$item = json_decode( trim( $line ), true );
			if ( ! is_array( $item ) ) { fclose($handle); return new \WP_Error( 'certloom_domain_backup_json_invalid', sprintf( __( 'The Certloom domain backup contains invalid JSON on line %d.', 'certloom' ), $line_no ) ); }
			$type = (string) ( $item['type'] ?? '' );
			if ( 1 === $line_no ) {
				$normalized = $this->format->normalize_manifest( $item );
				if ( is_wp_error( $normalized ) ) { fclose( $handle ); return $normalized; }
				$format_version = absint( $item['format_version'] ?? 0 );
				$allowed_entities = DomainBackupFormat::entity_types_for_version( $format_version );
				$manifest = $normalized; $hash = $this->format->next_hash( '', $line, $format_version ); continue;
			}
			if ( 'integrity' === $type ) { $integrity = $item; $after_integrity = true; continue; }
			if ( 'entity' !== $type || empty( $item['entity'] ) || ! is_array( $item['data'] ?? null ) ) { fclose($handle); return new \WP_Error( 'certloom_domain_backup_record_invalid', sprintf( __( 'The Certloom domain backup contains an unsupported record on line %d.', 'certloom' ), $line_no ) ); }
			$records++; if ( $records > absint( $max_records ) ) { fclose($handle); return new \WP_Error( 'certloom_domain_backup_limit', __( 'The Certloom domain backup exceeds the configured record safety limit.', 'certloom' ) ); }
			$entity = sanitize_key( (string) $item['entity'] );
			if ( ! in_array( $entity, $allowed_entities, true ) ) { fclose( $handle ); return new \WP_Error( 'certloom_domain_backup_entity_unsupported', __( 'The Certloom domain backup contains an unsupported entity type.', 'certloom' ) ); }
			$counts[$entity] = absint($counts[$entity] ?? 0)+1;
			$hash = $this->format->next_hash( $hash, $line, $format_version );
		}
		fclose( $handle );
		if ( ! is_array( $manifest ) || ! is_array( $integrity ) || $records !== absint( $integrity['records'] ?? -1 ) || ! hash_equals( $hash, (string) ( $integrity['chain_sha256'] ?? '' ) ) ) {
			return new \WP_Error( 'certloom_domain_backup_integrity_failed', __( 'The Certloom domain backup is incomplete or failed its integrity check.', 'certloom' ) );
		}
		$configuration = (array) ( $manifest['configuration'] ?? array() );
		$config_json = wp_json_encode( $configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$config_hash = strtolower( (string) ( $manifest['configuration_hash'] ?? '' ) );
		if ( false === $config_json || ! preg_match( '/^[a-f0-9]{64}$/', $config_hash ) || ! hash_equals( $config_hash, hash( 'sha256', $config_json ) ) ) {
			return new \WP_Error( 'certloom_domain_backup_configuration_hash_invalid', __( 'The Certloom domain backup configuration metadata is inconsistent.', 'certloom' ) );
		}

		$normalized_counts = array();
		foreach ( DomainBackupFormat::entity_types_for_version( DomainBackupFormat::VERSION ) as $entity ) {
			$bound = $manifest['entities'][ $entity ];
			$normalized_counts[ $entity ] = absint( $counts[ $entity ] ?? 0 );
			if ( absint( $bound['count'] ?? 0 ) !== $normalized_counts[ $entity ] ) {
				return new \WP_Error( 'certloom_domain_backup_count_mismatch', __( 'The Certloom domain backup record counts do not match its manifest.', 'certloom' ) );
			}
		}
		return array( 'manifest' => $manifest, 'records' => $records, 'counts' => $normalized_counts, 'chain_sha256' => $hash );
	}
}
