<?php
namespace Certloom\Application\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only outer integrity analysis. It never extracts files or authorizes restore. */
final class WholeStateBackupReader {
	private $format;
	public function __construct( ?WholeStateBackupFormat $format = null ) { $this->format = $format ?: new WholeStateBackupFormat(); }

	public function analyze( $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) { return $this->error( 'zip_unavailable' ); }
		if ( ! is_string( $path ) || false !== strpos( $path, "\0" ) || preg_match( '/^[a-z][a-z0-9+.-]*:\/\//i', $path ) || ! is_file( $path ) || is_link( $path ) || ! is_readable( $path ) ) { return $this->error( 'file_invalid' ); }
		clearstatcache( true, $path ); $bytes = filesize( $path );
		if ( ! is_int( $bytes ) || $bytes < 1 || $bytes > WholeStateBackupFormat::MAX_ARCHIVE_BYTES ) { return $this->error( 'size_invalid' ); }
		$before = @hash_file( 'sha256', $path ); if ( ! is_string( $before ) ) { return $this->error( 'file_invalid' ); }
		$zip = new \ZipArchive();
		$flags = \ZipArchive::CHECKCONS | ( defined( 'ZipArchive::RDONLY' ) ? \ZipArchive::RDONLY : 0 );
		if ( true !== @$zip->open( $path, $flags ) ) { return $this->error( 'zip_invalid' ); }
		try { $result = $this->inspect( $zip ); } catch ( \Throwable $error ) { $result = $this->error( 'read_failed' ); }
		$closed = $zip->close();
		if ( ! $closed ) { return $this->error( 'close_failed' ); }
		if ( is_wp_error( $result ) ) { return $result; }
		clearstatcache( true, $path ); $after = @hash_file( 'sha256', $path );
		if ( ! is_string( $after ) || ! hash_equals( $before, $after ) || is_link( $path ) ) { return $this->error( 'changed_during_analysis' ); }
		$result['archive_sha256'] = $after; $result['archive_bytes'] = $bytes;
		return $result;
	}

	private function inspect( \ZipArchive $zip ) {
		if ( $zip->numFiles < 7 || $zip->numFiles > WholeStateBackupFormat::MAX_MEMBERS + 1 ) { return $this->error( 'member_count_invalid' ); }
		$entries = array(); $total = 0;
		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$entry = $zip->statIndex( $index );
			if ( ! is_array( $entry ) || ! is_string( $entry['name'] ?? null ) ) { return $this->error( 'member_invalid' ); }
			$name = $entry['name'];
			if ( isset( $entries[ $name ] ) ) { return $this->error( 'duplicate_member' ); }
			if ( 'manifest.json' !== $name && ! WholeStateBackupFormat::member_name( $name ) ) { return $this->error( 'member_name_invalid' ); }
			$limit = WholeStateBackupFormat::member_limit( $name );
			if ( ! is_int( $entry['size'] ?? null ) || $entry['size'] < 1 || $entry['size'] > $limit || ! is_int( $entry['comp_size'] ?? null ) || $entry['comp_size'] < 1 || $entry['comp_size'] > WholeStateBackupFormat::MAX_ARCHIVE_BYTES || $entry['size'] > WholeStateBackupFormat::MAX_TOTAL_BYTES - $total || ! in_array( $entry['comp_method'] ?? null, array( \ZipArchive::CM_STORE, \ZipArchive::CM_DEFLATE ), true ) || 0 !== ( $entry['encryption_method'] ?? 0 ) ) { return $this->error( 'member_size_or_encoding_invalid' ); }
			$system = 0; $attributes = 0;
			if ( ! $zip->getExternalAttributesIndex( $index, $system, $attributes ) ) { return $this->error( 'member_attributes_invalid' ); }
			$mode = ( $attributes >> 16 ) & 0170000;
			if ( ( $attributes & 0x10 ) || ( \ZipArchive::OPSYS_UNIX === $system && 0 !== $mode && 0100000 !== $mode ) ) { return $this->error( 'member_not_regular' ); }
			$total += $entry['size']; $entries[ $name ] = $entry;
		}
		if ( ! isset( $entries['manifest.json'] ) ) { return $this->error( 'manifest_missing' ); }
		$bytes = @$zip->getFromIndex( $entries['manifest.json']['index'], WholeStateBackupFormat::MAX_MANIFEST_BYTES + 1 );
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $entries['manifest.json']['size'] ) { return $this->error( 'manifest_read_failed' ); }
		$manifest = $this->format->decode_manifest( $bytes ); if ( is_wp_error( $manifest ) ) { return $manifest; }
		$names = array_keys( $entries ); $expected = array_merge( array( 'manifest.json' ), array_keys( $manifest['members'] ) ); sort( $names, SORT_STRING ); sort( $expected, SORT_STRING );
		if ( $names !== $expected ) { return $this->error( 'scope_mismatch' ); }
		foreach ( $manifest['members'] as $name => $member ) {
			if ( $entries[ $name ]['size'] !== $member['bytes'] ) { return $this->error( 'member_size_mismatch' ); }
			$stream = @$zip->getStream( $name ); if ( ! is_resource( $stream ) ) { return $this->error( 'member_read_failed' ); }
			$hash = hash_init( 'sha256' ); $read = 0; $failed = false;
			while ( ! feof( $stream ) ) {
				$chunk = @fread( $stream, 65536 );
				if ( false === $chunk || ( '' === $chunk && ! feof( $stream ) ) || strlen( $chunk ) > $member['bytes'] - $read ) { $failed = true; break; }
				$read += strlen( $chunk ); hash_update( $hash, $chunk );
			}
			$closed = fclose( $stream );
			if ( $failed || ! $closed || $read !== $member['bytes'] || ! hash_equals( $member['sha256'], hash_final( $hash ) ) ) { return $this->error( 'member_integrity_failed' ); }
		}
		// Source claims and even a well-formed HMAC are not verified here.
		return array( 'integrity_valid' => true, 'ownership_verified' => false, 'restore_ready' => false, 'manifest' => $manifest, 'uncompressed_bytes' => $total );
	}

	private function error( $reason ) { return new \WP_Error( 'certloom_whole_backup_' . $reason, __( 'Certloom could not verify this whole-state backup safely. No data or files were restored.', 'certloom' ) ); }
}
