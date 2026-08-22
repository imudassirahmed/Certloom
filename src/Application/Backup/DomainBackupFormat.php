<?php
namespace Certloom\Application\Backup;

/** Versioned line-oriented backup format whose chained hash can resume across requests. */
final class DomainBackupFormat {
	const FORMAT = 'certloom-domain-backup';
	const VERSION = 1;
	const HASH_SEED = 'certloom-domain-backup-v1';
	const MAX_LINE_BYTES = 4 * 1024 * 1024;
	const MAX_FILE_BYTES = 1024 * 1024 * 1024;

	/** @return string|\WP_Error */
	public function encode_line( array $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json
			? new \WP_Error( 'certloom_domain_backup_encode_failed', __( 'Certloom could not encode backup data safely.', 'certloom' ) )
			: $json . "\n";
	}

	/**
	 * Reads one bounded backup line. A malformed/untrusted restore file must not
	 * be able to force PHP to allocate an arbitrarily large single JSON line.
	 *
	 * @param resource $handle Open binary stream.
	 * @param int      $line_no One-based line number for diagnostics.
	 * @return string|null|\WP_Error String line, null at EOF, or validation error.
	 */
	public function read_line( $handle, $line_no ) {
		$line = fgets( $handle, self::MAX_LINE_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- Bounded streaming backup parser.
		if ( false === $line ) {
			return feof( $handle ) ? null : new \WP_Error( 'certloom_domain_backup_read_failed', __( 'Certloom could not read the backup stream safely.', 'certloom' ) );
		}
		if ( strlen( $line ) > self::MAX_LINE_BYTES || ( self::MAX_LINE_BYTES === strlen( $line ) && ! "\n" === substr( $line, -1 ) && ! feof( $handle ) ) ) {
			return new \WP_Error(
				'certloom_domain_backup_line_too_large',
				sprintf( __( 'The Certloom backup contains an oversized record on line %d.', 'certloom' ), absint( $line_no ) )
			);
		}
		return $line;
	}

	/** @return string */
	public function next_hash( $previous, $line ) {
		$previous = '' === (string) $previous ? self::HASH_SEED : (string) $previous;
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
