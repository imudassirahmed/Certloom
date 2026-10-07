<?php
namespace Certloom\Infrastructure\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent private storage for current-authority Certloom backup packages.
 *
 * This store is deliberately separate from PrivateJobFileStore so customer
 * retention settings are never overridden by short-lived job cleanup.
 */
final class LocalBackupStore {
	const CATALOG_OPTION = 'certloom_local_backup_catalog';
	const MAX_CATALOG    = 100;
	/** Structure only: imported catalog entries never establish target file availability. */
	public function validate_recovery_value( $value ) {
		$rows = \Certloom\Application\Restore\WholeStateOptionRows::validate_list( $value, self::MAX_CATALOG, function ( $row ) { return $this->normalize_entry( $row ); }, array( 'id' ), null );
		if ( is_wp_error( $rows ) ) { return $rows; }
		foreach ( $rows as $row ) {
			// Complete archives contain independently hashed components and use the
			// verified file hash. Their native receipts have no legacy content hash.
			$content_hash_valid = 1 === preg_match( '/^[a-f0-9]{64}$/D', $row['content_sha256'] ) || ( 'whole_state' === $row['scope'] && '' === $row['content_sha256'] );
			if ( ! \Certloom\Application\Restore\WholeStateOptionRows::iso_date( $row['created_at'] ) || $row['created_ts'] < 1 || $row['bytes'] < 1 || ! $content_hash_valid ) { return new \WP_Error( 'certloom_whole_backup_catalog_invalid', __( 'The recovery copy catalog contains invalid evidence.', 'certloom' ) ); }
		}
		return $value;
	}

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
		return $this->store_verified( $source_path, $metadata, $purpose, 'legacy_registry' );
	}

	/** Whole-state scope requires actual archive integrity and the local source signature. */
	public function store_whole( $source_path, array $metadata, $purpose = 'manual' ) {
		$archive = ( new \Certloom\Application\Backup\WholeStateBackupReader() )->analyze( $source_path );
		if ( is_wp_error( $archive ) ) { return $archive; }
		$origin = ( new WholeStateProvenanceRepository() )->verify( $archive['manifest'] );
		if ( is_wp_error( $origin ) ) { return $origin; }
		if ( ( $metadata['file_sha256'] ?? null ) !== $archive['archive_sha256'] || ( $metadata['bytes'] ?? null ) !== $archive['archive_bytes'] ) { return new \WP_Error( 'certloom_whole_catalog_proof_invalid', __( 'The complete recovery archive does not match its capture receipt.', 'certloom' ) ); }
		$metadata['export_id'] = $archive['manifest']['source']['export_id'];
		$metadata['format_version'] = \Certloom\Application\Backup\WholeStateBackupFormat::VERSION;
		$metadata['plugin_version'] = $archive['manifest']['plugin_version'];
		$metadata['generated_at'] = $archive['manifest']['created_at'];
		$current = $this->current_catalog(); if ( is_wp_error( $current ) ) { return $current; }
		foreach ( $current as $row ) {
			$entry = $this->normalize_entry( $row );
			if ( 'whole_state' === $entry['scope'] && $entry['export_id'] === $metadata['export_id'] ) {
				if ( $entry['file_sha256'] !== $metadata['file_sha256'] || $entry['purpose'] !== $purpose ) { return new \WP_Error( 'certloom_whole_catalog_collision', __( 'This capture identity already has a different recovery receipt.', 'certloom' ) ); }
				$verified = $this->resolve_entry( $entry ); return is_wp_error( $verified ) ? $verified : $entry;
			}
		}
		return $this->store_verified( $source_path, $metadata, $purpose, 'whole_state' );
	}

	private function store_verified( $source_path, array $metadata, $purpose, $scope ) {
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
		// A whole capture's signed export identity owns one deterministic copy.
		// A crash before catalog commit can verify and adopt these exact bytes on retry.
		if ( 'whole_state' === $scope ) { $id = $metadata['export_id']; $random = substr( $expected_hash, 0, 48 ); }
		$filename = $id . '-' . $purpose . '-' . $random . ( 'whole_state' === $scope ? '.cvrecovery' : '.cvbackup' );
		$path     = trailingslashit( $dir ) . $filename;
		$adopt = 'whole_state' === $scope && file_exists( $path );
		if ( $adopt && ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) !== $expected_size || hash_file( 'sha256', $path ) !== $expected_hash ) ) { return new \WP_Error( 'certloom_backup_store_collision', __( 'Certloom refused to replace different bytes at this recovery capture destination.', 'certloom' ) ); }
		if ( ! $adopt ) {
		$in       = @fopen( $source_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Controlled local backup stream.
		$out      = @fopen( $path, 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation prevents replacement races.
		if ( false === $in || false === $out ) {
			$created = is_resource( $out );
			if ( is_resource( $in ) ) { fclose( $in ); }
			if ( is_resource( $out ) ) { fclose( $out ); }
			if ( $created ) { @unlink( $path ); }
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
			'source_authority' => 'whole_state' === $scope ? 'whole_installation' : 'current_registry',
			'scope'            => $scope,
			'export_id'        => 'whole_state' === $scope ? $metadata['export_id'] : null,
			'integrity'        => 'verified',
		);
		$existing = null;
		$saved = ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->change( self::CATALOG_OPTION, function ( $raw ) use ( $entry, &$existing ) {
			$current = $this->decode_catalog( $raw ); if ( is_wp_error( $current ) ) { return $current; }
			if ( 'whole_state' === $entry['scope'] ) {
				foreach ( $current as $row ) { $clean = $this->normalize_entry( $row ); if ( $clean && 'whole_state' === $clean['scope'] && $clean['export_id'] === $entry['export_id'] ) { if ( $clean['file_sha256'] !== $entry['file_sha256'] || $clean['purpose'] !== $entry['purpose'] ) { return new \WP_Error( 'certloom_whole_catalog_collision', __( 'This capture identity already has a different recovery receipt.', 'certloom' ) ); } $existing = $clean; return $raw; } }
			}
			if ( count( $current ) >= self::MAX_CATALOG ) { return new \WP_Error( 'certloom_backup_catalog_full', __( 'The private backup catalog is full. Remove an older manual backup before creating another.', 'certloom' ) ); }
			array_unshift( $current, $entry ); return (string) maybe_serialize( $current );
		} );
		if ( true !== $saved ) {
			@unlink( $path );
			return new \WP_Error( 'certloom_backup_catalog_save_failed', __( 'Certloom created the backup bytes but could not save their private catalog entry, so the untracked copy was removed.', 'certloom' ) );
		}
		if ( $existing ) { if ( $existing['filename'] !== $filename && ! @unlink( $path ) ) { return new \WP_Error( 'certloom_backup_duplicate_cleanup_failed', __( 'The recovery capture is already stored, but its duplicate private copy could not be removed.', 'certloom' ) ); } $verified = $this->resolve( $existing['id'] ); return is_wp_error( $verified ) ? $verified : $existing; }
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
			return $this->resolve_entry( $entry );
		}
		return new \WP_Error( 'certloom_backup_not_found', __( 'That private Certloom backup is not in the local backup catalog.', 'certloom' ) );
	}

	private function resolve_entry( array $entry ) {
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
		$removed = $this->remove_entries( array( $target ), false ); if ( is_wp_error( $removed ) ) { return $removed; }
		if ( class_exists( RestoreTest::class ) ) { RestoreTest::forget( $id ); }
		return true;
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
		return $this->remove_entries( $remove, true );
	}

	/** Keep only the newest N automatic safety snapshots; manual/scheduled backups remain explicit. @return int|\WP_Error */
	public function prune_safety( $retain ) {
		$retain  = max( 3, min( 30, absint( $retain ) ?: 10 ) );
		$catalog = $this->list_backups();
		$safety_seen = 0;
		$keep = array();
		$remove = array();
		foreach ( $catalog as $entry ) {
			if ( 'safety' !== $entry['purpose'] ) { $keep[] = $entry; continue; }
			$safety_seen++;
			if ( $safety_seen <= $retain ) { $keep[] = $entry; } else { $remove[] = $entry; }
		}
		if ( empty( $remove ) ) { return 0; }
		return $this->remove_entries( $remove, true );
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
		$scope = $entry['scope'] ?? 'legacy_registry';
		$extension = 'whole_state' === $scope ? 'cvrecovery' : 'cvbackup';
		$export_id = $entry['export_id'] ?? null;
		if ( ! in_array( $scope, array( 'legacy_registry', 'whole_state' ), true ) || ( 'whole_state' === $scope && ( ! is_string( $export_id ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $export_id ) ) ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) || 1 !== preg_match( '/^' . $id . '-' . $purpose . '-[a-f0-9]{48}\.' . $extension . '$/D', $filename ) || ! in_array( $purpose, array( 'scheduled', 'manual', 'safety' ), true ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
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
			'source_authority' => 'whole_state' === $scope ? 'whole_installation' : 'current_registry',
			'scope'            => $scope,
			'export_id'        => 'whole_state' === $scope ? $export_id : null,
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

	/** Remove selected identities from a current locked catalog; concurrent additions survive. */
	private function remove_entries( array $selected, $verify_bytes ) {
		$deleted = 0;
		$saved = ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->change( self::CATALOG_OPTION, function ( $raw ) use ( $selected, $verify_bytes, &$deleted ) {
			$current = $this->decode_catalog( $raw ); if ( is_wp_error( $current ) ) { return $current; }
			$remove = array();
			foreach ( $selected as $expected ) {
				$matched = null;
				foreach ( $current as $row ) { $entry = $this->normalize_entry( $row ); if ( $entry['id'] === $expected['id'] ) { $matched = $entry; break; } }
				if ( ! $matched || $matched !== $expected ) { return new \WP_Error( 'certloom_backup_catalog_changed', __( 'The backup catalog changed. Refresh before removing selected copies.', 'certloom' ) ); }
				$path = $this->path_for_entry( $matched ); if ( is_wp_error( $path ) ) { return $path; }
				if ( file_exists( $path ) && $verify_bytes ) { $verified = $this->resolve_entry( $matched ); if ( is_wp_error( $verified ) ) { return $verified; } }
				$remove[$matched['id']] = $path;
			}
			foreach ( $remove as $id => $path ) { if ( file_exists( $path ) && ! @unlink( $path ) ) { return new \WP_Error( 'certloom_backup_delete_failed', __( 'Certloom could not remove a selected private backup file.', 'certloom' ) ); } ++$deleted; }
			return (string) maybe_serialize( array_values( array_filter( $current, static function ( $row ) use ( $remove ) { return ! isset( $remove[$row['id']] ); } ) ) );
		} );
		if ( true !== $saved ) { return is_wp_error( $saved ) ? $saved : new \WP_Error( 'certloom_backup_catalog_save_failed', __( 'Certloom could not update the private backup catalog. Refresh to review the selected copies.', 'certloom' ) ); }
		if ( class_exists( RestoreTest::class ) ) { foreach ( $selected as $entry ) { RestoreTest::forget( $entry['id'] ); } }
		return $deleted;
	}

	private function decode_catalog( $raw ) {
		if ( null === $raw ) { return array(); }
		$record = ( new \Certloom\Application\Backup\WholeStateOptionRecord() )->from_storage( $raw );
		if ( is_wp_error( $record ) ) { return $record; }
		$decoded = ( new \Certloom\Application\Backup\WholeStateOptionValue() )->decode( $record );
		if ( is_wp_error( $decoded ) || ! is_array( $decoded['value'] ) || count( $decoded['value'] ) > self::MAX_CATALOG || ( $decoded['value'] && array_keys( $decoded['value'] ) !== range( 0, count( $decoded['value'] ) - 1 ) ) ) { return new \WP_Error( 'certloom_backup_catalog_invalid', __( 'The private backup catalog needs review before another copy can be stored.', 'certloom' ) ); }
		$ids = array(); $names = array(); $exports = array();
		foreach ( $decoded['value'] as $row ) { $entry = $this->normalize_entry( $row ); if ( ! $entry || isset( $ids[$entry['id']] ) || isset( $names[$entry['filename']] ) || ( $entry['export_id'] && isset( $exports[$entry['export_id']] ) ) ) { return new \WP_Error( 'certloom_backup_catalog_invalid', __( 'The private backup catalog contains an unsupported or duplicate entry. No existing entry was replaced.', 'certloom' ) ); } $ids[$entry['id']] = true; $names[$entry['filename']] = true; if ( $entry['export_id'] ) { $exports[$entry['export_id']] = true; } }
		return $decoded['value'];
	}

	private function current_catalog() {
		global $wpdb; $wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT CASE WHEN OCTET_LENGTH(option_value)<=2097152 THEN option_value ELSE NULL END AS storage FROM %i WHERE option_name=%s', $wpdb->options, self::CATALOG_OPTION ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ( null !== $row && ( ! is_array( $row ) || ! is_string( $row['storage'] ?? null ) ) ) ) { return new \WP_Error( 'certloom_backup_catalog_read_failed', __( 'Certloom could not read its current private backup catalog.', 'certloom' ) ); }
		return $this->decode_catalog( null === $row ? null : $row['storage'] );
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
