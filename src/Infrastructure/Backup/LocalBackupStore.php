<?php
namespace Certloom\Infrastructure\Backup;

/**
 * Persistent private storage for current-authority Certloom backup packages.
 *
 * This store is deliberately separate from PrivateJobFileStore so customer
 * retention settings are never overridden by short-lived job cleanup.
 */
final class LocalBackupStore {
	const CATALOG_OPTION = 'certloom_local_backup_catalog';
	const MAX_CATALOG    = 100;

	/** @return array<int,array<string,mixed>> */
	public function list_backups() {
		$catalog = get_option( self::CATALOG_OPTION, array() );
		if ( ! is_array( $catalog ) ) { return array(); }
		$items = array();
		foreach ( $catalog as $entry ) {
			$clean = $this->normalize_entry( $entry );
			if ( $clean ) { $items[] = $clean; }
		}
		usort( $items, function ( $a, $b ) { return (int) $b['created_ts'] <=> (int) $a['created_ts']; } );
		return array_slice( $items, 0, self::MAX_CATALOG );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function store( $source_path, array $metadata, $purpose = 'scheduled' ) {
		$source_path = (string) $source_path;
		$purpose     = sanitize_key( (string) $purpose );
		if ( '' === $source_path || ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
			return new \WP_Error( 'certloom_backup_source_invalid', __( 'The completed Certloom backup is not available for private storage.', 'certloom' ) );
		}
		if ( ! in_array( $purpose, array( 'scheduled', 'manual', 'safety' ), true ) ) {
			return new \WP_Error( 'certloom_backup_purpose_invalid', __( 'Certloom refused an invalid backup purpose.', 'certloom' ) );
		}
		$catalog = $this->list_backups();
		if ( count( $catalog ) >= self::MAX_CATALOG ) {
			return new \WP_Error( 'certloom_backup_catalog_full', __( 'The private backup catalog has reached its safety limit. Delete an older manual backup before creating another.', 'certloom' ) );
		}
		$expected_hash = strtolower( trim( (string) ( $metadata['file_sha256'] ?? '' ) ) );
		$expected_size = absint( $metadata['bytes'] ?? 0 );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) || $expected_size <= 0 ) {
			return new \WP_Error( 'certloom_backup_metadata_invalid', __( 'Certloom refused incomplete backup integrity metadata.', 'certloom' ) );
		}
		$actual_hash = @hash_file( 'sha256', $source_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Guarded immediately below.
		$actual_size = @filesize( $source_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Guarded immediately below.
		if ( ! is_string( $actual_hash ) || ! hash_equals( $expected_hash, strtolower( $actual_hash ) ) || (int) $actual_size !== $expected_size ) {
			return new \WP_Error( 'certloom_backup_source_integrity_failed', __( 'Certloom refused a backup whose stored bytes do not match its completed integrity metadata.', 'certloom' ) );
		}

		$dir = $this->ensure_directory();
		if ( is_wp_error( $dir ) ) { return $dir; }
		try {
			$id     = bin2hex( random_bytes( 16 ) );
			$random = bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_backup_identity_failed', __( 'Certloom could not create a secure backup identifier.', 'certloom' ) );
		}
		$filename = $id . '-' . $purpose . '-' . $random . '.cvbackup';
		$path     = trailingslashit( $dir ) . $filename;
		$in       = @fopen( $source_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Controlled local backup stream.
		$out      = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation prevents replacement races.
		if ( false === $in || false === $out ) {
			if ( is_resource( $in ) ) { fclose( $in ); }
			if ( is_resource( $out ) ) { fclose( $out ); }
			@unlink( $path );
			return new \WP_Error( 'certloom_backup_store_open_failed', __( 'Certloom could not open its private backup storage safely.', 'certloom' ) );
		}
		$copied  = stream_copy_to_stream( $in, $out );
		$flushed = fflush( $out );
		fclose( $in );
		fclose( $out );
		@chmod( $path, 0600 );
		if ( false === $copied || ! $flushed ) {
			@unlink( $path );
			return new \WP_Error( 'certloom_backup_store_copy_failed', __( 'Certloom could not finish writing the private backup.', 'certloom' ) );
		}
		$stored_hash = @hash_file( 'sha256', $path );
		$stored_size = @filesize( $path );
		if ( ! is_string( $stored_hash ) || ! hash_equals( $expected_hash, strtolower( $stored_hash ) ) || (int) $stored_size !== $expected_size ) {
			@unlink( $path );
			return new \WP_Error( 'certloom_backup_store_integrity_failed', __( 'Certloom removed a private backup copy that failed its post-write integrity check.', 'certloom' ) );
		}

		$created_ts = time();
		$entry      = array(
			'id'               => $id,
			'filename'         => $filename,
			'purpose'          => $purpose,
			'created_at'       => gmdate( 'c', $created_ts ),
			'created_ts'       => $created_ts,
			'generated_at'     => sanitize_text_field( (string) ( $metadata['generated_at'] ?? '' ) ),
			'bytes'            => $expected_size,
			'records'          => absint( $metadata['records'] ?? 0 ),
			'file_sha256'      => $expected_hash,
			'content_sha256'   => strtolower( trim( (string) ( $metadata['content_sha256'] ?? '' ) ) ),
			'format_version'   => absint( $metadata['format_version'] ?? 0 ),
			'plugin_version'   => sanitize_text_field( (string) ( $metadata['plugin_version'] ?? '' ) ),
			'schema_version'   => sanitize_text_field( (string) ( $metadata['schema_version'] ?? '' ) ),
			'source_authority' => 'current_registry',
			'integrity'        => 'verified',
		);
		array_unshift( $catalog, $entry );
		if ( ! $this->save_catalog( $catalog ) ) {
			@unlink( $path );
			return new \WP_Error( 'certloom_backup_catalog_save_failed', __( 'Certloom created the backup bytes but could not save their private catalog entry, so the untracked copy was removed.', 'certloom' ) );
		}
		return $entry;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function resolve( $id ) {
		$id = strtolower( trim( (string) $id ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return new \WP_Error( 'certloom_backup_id_invalid', __( 'The requested Certloom backup identifier is invalid.', 'certloom' ) );
		}
		foreach ( $this->list_backups() as $entry ) {
			if ( ! hash_equals( $id, (string) $entry['id'] ) ) { continue; }
			$path = $this->path_for_entry( $entry );
			if ( is_wp_error( $path ) ) { return $path; }
			if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
				return new \WP_Error( 'certloom_backup_file_missing', __( 'That private Certloom backup file is no longer available.', 'certloom' ) );
			}
			$hash = @hash_file( 'sha256', $path );
			$size = @filesize( $path );
			if ( ! is_string( $hash ) || ! hash_equals( (string) $entry['file_sha256'], strtolower( $hash ) ) || (int) $size !== (int) $entry['bytes'] ) {
				return new \WP_Error( 'certloom_backup_file_integrity_failed', __( 'Certloom refused a stored backup whose bytes no longer match its verified catalog entry.', 'certloom' ) );
			}
			$entry['path'] = $path;
			return $entry;
		}
		return new \WP_Error( 'certloom_backup_not_found', __( 'That private Certloom backup is not in the local backup catalog.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	public function delete( $id ) {
		$id = strtolower( trim( (string) $id ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return new \WP_Error( 'certloom_backup_id_invalid', __( 'The requested Certloom backup identifier is invalid.', 'certloom' ) );
		}
		$catalog = $this->list_backups();
		$target = null;
		foreach ( $catalog as $entry ) { if ( hash_equals( $id, (string) $entry['id'] ) ) { $target = $entry; break; } }
		if ( ! $target ) { return new \WP_Error( 'certloom_backup_not_found', __( 'That private Certloom backup is not in the local backup catalog.', 'certloom' ) ); }
		$path = $this->path_for_entry( $target, true );
		if ( is_wp_error( $path ) ) { return $path; }
		if ( ( file_exists( $path ) || is_link( $path ) ) && ! @unlink( $path ) ) {
			return new \WP_Error( 'certloom_backup_delete_failed', __( 'Certloom could not remove the selected private backup file.', 'certloom' ) );
		}
		$catalog = array_values( array_filter( $catalog, function ( $entry ) use ( $id ) { return ! hash_equals( (string) $id, (string) $entry['id'] ); } ) );
		return $this->save_catalog( $catalog ) ? true : new \WP_Error( 'certloom_backup_catalog_save_failed', __( 'The backup file was removed, but Certloom could not update its local backup catalog.', 'certloom' ) );
	}

	/** Keep only the newest N scheduled backups; manual/safety backups remain explicit. @return int|\WP_Error */
	public function prune_scheduled( $retain ) {
		$retain  = max( 1, min( 60, absint( $retain ) ) );
		$catalog = $this->list_backups();
		$scheduled_seen = 0;
		$keep = array();
		$remove = array();
		foreach ( $catalog as $entry ) {
			if ( 'scheduled' !== $entry['purpose'] ) { $keep[] = $entry; continue; }
			$scheduled_seen++;
			if ( $scheduled_seen <= $retain ) { $keep[] = $entry; } else { $remove[] = $entry; }
		}
		if ( empty( $remove ) ) { return 0; }
		$deleted = 0;
		foreach ( $remove as $entry ) {
			$path = $this->path_for_entry( $entry, true );
			if ( is_wp_error( $path ) ) { return $path; }
			if ( ( file_exists( $path ) || is_link( $path ) ) && ! @unlink( $path ) ) {
				return new \WP_Error( 'certloom_backup_prune_failed', __( 'Certloom stopped automatic retention cleanup because an owned scheduled backup could not be removed safely.', 'certloom' ) );
			}
			$deleted++;
		}
		return $this->save_catalog( $keep ) ? $deleted : new \WP_Error( 'certloom_backup_catalog_save_failed', __( 'Certloom removed expired scheduled backup files but could not save the updated retention catalog.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	public function directory() {
		$configured = trim( (string) apply_filters( 'certloom_private_backup_directory', '' ) );
		if ( '' !== $configured ) {
			return $this->assert_private_location( wp_normalize_path( $configured ) );
		}
		if ( defined( 'ABSPATH' ) ) {
			$webroot = untrailingslashit( wp_normalize_path( ABSPATH ) );
			$parent  = untrailingslashit( wp_normalize_path( dirname( $webroot ) ) );
			if ( '' !== $parent && $parent !== $webroot ) {
				$candidate = trailingslashit( $parent ) . 'certloom-backups';
				$private = $this->assert_private_location( $candidate );
				if ( ! is_wp_error( $private ) && $this->candidate_is_writable( $candidate ) ) { return $private; }
			}
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$candidate = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . 'certloom-backups';
			$private = $this->assert_private_location( $candidate );
			if ( ! is_wp_error( $private ) && $this->candidate_is_writable( $candidate ) ) { return $private; }
		}
		return new \WP_Error( 'certloom_backup_directory_unavailable', __( 'Certloom could not find persistent private backup storage outside the public WordPress webroot. Configure certloom_private_backup_directory to an absolute non-public path before enabling automatic backups.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	public function ensure_directory() {
		$dir = $this->directory();
		if ( is_wp_error( $dir ) ) { return $dir; }
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'certloom_backup_directory_create_failed', __( 'Certloom could not create its private backup directory.', 'certloom' ) );
		}
		$private = $this->assert_private_location( $dir );
		if ( is_wp_error( $private ) ) { return $private; }
		$sentinels = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",
		);
		foreach ( $sentinels as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) && false === @file_put_contents( $path, $contents, LOCK_EX ) ) {
				return new \WP_Error( 'certloom_backup_directory_protection_failed', __( 'Certloom could not protect its private backup directory.', 'certloom' ) );
			}
		}
		return $dir;
	}


	/** Proves the configured persistent destination can create, flush, verify, and remove owned bytes. @return true|\WP_Error */
	public function test_storage() {
		$dir = $this->ensure_directory();
		if ( is_wp_error( $dir ) ) { return $dir; }
		try {
			$token  = bin2hex( random_bytes( 16 ) );
			$marker = random_bytes( 32 );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_backup_storage_probe_identity_failed', __( 'Certloom could not create a secure private-storage test.', 'certloom' ) );
		}
		$path = trailingslashit( $dir ) . 'certloom-storage-probe-' . $token . '.tmp';
		$out  = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive private storage probe.
		if ( false === $out ) {
			return new \WP_Error( 'certloom_backup_storage_probe_open_failed', __( 'Certloom could not create a test file in the configured private backup destination.', 'certloom' ) );
		}
		$written = fwrite( $out, $marker ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded random probe bytes.
		$flushed = fflush( $out );
		fclose( $out );
		@chmod( $path, 0600 );
		if ( strlen( $marker ) !== (int) $written || ! $flushed ) {
			@unlink( $path );
			return new \WP_Error( 'certloom_backup_storage_probe_write_failed', __( 'Certloom could not finish writing its private backup destination test.', 'certloom' ) );
		}
		$actual_hash = @hash_file( 'sha256', $path );
		$expected    = hash( 'sha256', $marker );
		$removed     = @unlink( $path );
		if ( ! is_string( $actual_hash ) || ! hash_equals( $expected, strtolower( $actual_hash ) ) ) {
			return new \WP_Error( 'certloom_backup_storage_probe_integrity_failed', __( 'Certloom refused a private backup destination that did not reproduce its test bytes exactly.', 'certloom' ) );
		}
		if ( ! $removed || file_exists( $path ) || is_link( $path ) ) {
			return new \WP_Error( 'certloom_backup_storage_probe_cleanup_failed', __( 'Certloom refused a private backup destination where its temporary test file could not be removed cleanly.', 'certloom' ) );
		}
		return true;
	}

	/** @return array<string,mixed>|false */
	private function normalize_entry( $entry ) {
		if ( ! is_array( $entry ) ) { return false; }
		$id       = strtolower( trim( (string) ( $entry['id'] ?? '' ) ) );
		$filename = sanitize_file_name( (string) ( $entry['filename'] ?? '' ) );
		$purpose  = sanitize_key( (string) ( $entry['purpose'] ?? '' ) );
		$hash     = strtolower( trim( (string) ( $entry['file_sha256'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) || 1 !== preg_match( '/^[a-f0-9]{32}-(scheduled|manual|safety)-[a-f0-9]{48}\.cvbackup$/', $filename ) || ! in_array( $purpose, array( 'scheduled', 'manual', 'safety' ), true ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return false;
		}
		return array(
			'id'               => $id,
			'filename'         => $filename,
			'purpose'          => $purpose,
			'created_at'       => sanitize_text_field( (string) ( $entry['created_at'] ?? '' ) ),
			'created_ts'       => absint( $entry['created_ts'] ?? 0 ),
			'generated_at'     => sanitize_text_field( (string) ( $entry['generated_at'] ?? '' ) ),
			'bytes'            => absint( $entry['bytes'] ?? 0 ),
			'records'          => absint( $entry['records'] ?? 0 ),
			'file_sha256'      => $hash,
			'content_sha256'   => strtolower( trim( (string) ( $entry['content_sha256'] ?? '' ) ) ),
			'format_version'   => absint( $entry['format_version'] ?? 0 ),
			'plugin_version'   => sanitize_text_field( (string) ( $entry['plugin_version'] ?? '' ) ),
			'schema_version'   => sanitize_text_field( (string) ( $entry['schema_version'] ?? '' ) ),
			'source_authority' => 'current_registry',
			'integrity'        => 'verified',
		);
	}

	/** @return string|\WP_Error */
	private function path_for_entry( array $entry, $for_delete = false ) {
		$dir = $this->directory();
		if ( is_wp_error( $dir ) ) { return $dir; }
		$filename = (string) $entry['filename'];
		$path = trailingslashit( $dir ) . $filename;
		if ( ( ! $for_delete && is_link( $path ) ) || 0 !== strpos( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( $dir ) ) ) ) {
			return new \WP_Error( 'certloom_backup_path_invalid', __( 'Certloom refused a backup path outside its owned private directory.', 'certloom' ) );
		}
		return $path;
	}

	private function save_catalog( array $catalog ) {
		$catalog = array_values( array_slice( $catalog, 0, self::MAX_CATALOG ) );
		$saved = update_option( self::CATALOG_OPTION, $catalog, false );
		return $saved || $catalog === get_option( self::CATALOG_OPTION, array() );
	}

	/** @return string|\WP_Error */
	private function assert_private_location( $path ) {
		$raw = wp_normalize_path( (string) $path );
		if ( '/' === $raw || 1 === preg_match( '#^[A-Za-z]:/?$#', $raw ) ) {
			return new \WP_Error( 'certloom_backup_directory_root_refused', __( 'Certloom refused to use a filesystem root as its backup directory.', 'certloom' ) );
		}
		if ( '' !== $raw && is_link( $raw ) ) {
			return new \WP_Error( 'certloom_backup_directory_symlink', __( 'Certloom refused a symlink as its persistent backup directory.', 'certloom' ) );
		}
		$path = $this->canonical_path( $raw );
		if ( '' === $path || ! $this->is_absolute_path( $path ) ) {
			return new \WP_Error( 'certloom_backup_directory_not_absolute', __( 'Certloom private backup storage requires an absolute filesystem path.', 'certloom' ) );
		}
		if ( '/' === $path || 1 === preg_match( '#^[A-Za-z]:/?$#', $path ) ) {
			return new \WP_Error( 'certloom_backup_directory_root_refused', __( 'Certloom refused to use a filesystem root as its backup directory.', 'certloom' ) );
		}
		if ( ! defined( 'ABSPATH' ) ) { return $path; }
		$webroot = $this->canonical_path( ABSPATH );
		$inside  = '' !== $webroot && ( $path === $webroot || 0 === strpos( trailingslashit( $path ), trailingslashit( $webroot ) ) );
		if ( ! $inside ) { return $path; }
		return (bool) apply_filters( 'certloom_allow_private_backup_directory_inside_webroot', false )
			? $path
			: new \WP_Error( 'certloom_backup_directory_web_exposed', __( 'Certloom refused persistent backup storage inside the public WordPress webroot. Server deny files are defense in depth, not a portable confidentiality boundary.', 'certloom' ) );
	}

	private function candidate_is_writable( $path ) {
		$probe = wp_normalize_path( (string) $path );
		if ( is_dir( $probe ) ) { return is_writable( $probe ); }
		while ( '' !== $probe && ! file_exists( $probe ) ) {
			$parent = wp_normalize_path( dirname( $probe ) );
			if ( $parent === $probe ) { break; }
			$probe = $parent;
		}
		return is_dir( $probe ) && is_writable( $probe );
	}

	private function canonical_path( $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( '' === $path ) { return ''; }
		$real = @realpath( $path );
		if ( false !== $real ) { return untrailingslashit( wp_normalize_path( $real ) ); }
		$prefix = '';
		if ( preg_match( '#^[A-Za-z]:/#', $path ) ) { $prefix = strtoupper( substr( $path, 0, 2 ) ); $path = substr( $path, 2 ); }
		elseif ( 0 === strpos( $path, '/' ) ) { $prefix = '/'; }
		$parts = array();
		foreach ( explode( '/', trim( $path, '/' ) ) as $part ) {
			if ( '' === $part || '.' === $part ) { continue; }
			if ( '..' === $part ) { array_pop( $parts ); continue; }
			$parts[] = $part;
		}
		$body = implode( '/', $parts );
		return '/' === $prefix ? '/' . $body : ( '' !== $prefix ? $prefix . '/' . $body : $body );
	}

	private function is_absolute_path( $path ) {
		$path = wp_normalize_path( (string) $path );
		return '' !== $path && ( '/' === $path[0] || 1 === preg_match( '#^[A-Za-z]:/#', $path ) );
	}
}
