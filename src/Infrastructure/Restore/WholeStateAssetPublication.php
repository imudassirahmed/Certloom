<?php
namespace Certloom\Infrastructure\Restore;

use Certloom\Application\Backup\WholeStateBackupReader;
use Certloom\Application\Backup\WholeStateReferenceFormat;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Backup\WholeStateOptionValue;
use Certloom\Application\Rendering\AssetInspector;
use Certloom\Application\Rendering\FontAssetInspector;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Backup\SecretVault;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Data\MaintenanceMutex;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Sealed local publication journal; one exact asset per slice, no ordinary uploads changed. */
final class WholeStateAssetPublication {
	const OPTION = 'certloom_whole_asset_publication';
	const PURPOSE = 'certloom-whole-asset-publication-v1';
	const MAX_JOURNAL_BYTES = 2097152;
	public function start( $archive_path, $archive_sha256, $owner_token ) {
		return MaintenanceMutex::run( function () use ( $archive_path, $archive_sha256, $owner_token ) {
			if ( ! $this->owned( $owner_token ) || is_wp_error( ( new WholeStateProvenanceRepository() )->identity() ) ) { return $this->invalid(); }
			$current = $this->read(); if ( is_wp_error( $current ) ) { return $current; }
			if ( $current ) { return $current['owner_token'] === $owner_token && $current['archive_sha256'] === $archive_sha256 && $current['archive_path'] === $archive_path ? $current : $this->invalid(); }
			$archive = ( new WholeStateBackupReader() )->analyze( $archive_path ); if ( is_wp_error( $archive ) || $archive['archive_sha256'] !== $archive_sha256 || $archive['uncompressed_bytes'] > \Certloom\Application\Backup\WholeStateBackupBuilder::MAX_CAPTURE_BYTES ) { return $this->invalid(); }
			$zip = new \ZipArchive(); if ( true !== $zip->open( $archive_path, \ZipArchive::RDONLY ) ) { return $this->invalid(); }
			try { $h = $zip->getStream( 'data/references.jsonl' ); if ( ! is_resource( $h ) ) { return $this->invalid(); } try { $refs = ( new WholeStateReferenceFormat() )->analyze( $h, static function () { return true; } ); } finally { fclose( $h ); } } finally { $zip->close(); }
			if ( is_wp_error( $refs ) || $refs['format_version'] !== $archive['manifest']['scope']['references'] ) { return $this->invalid(); }
			$declared = array(); foreach ( $archive['manifest']['members'] as $name => $member ) { if ( strpos( $name, 'assets/' ) === 0 ) { $declared[substr( $name, 7 )] = $member; } }
			$keys = array_keys( $declared ); $expected = array_keys( $refs['assets'] ); sort( $keys ); sort( $expected ); if ( $keys !== $expected ) { return $this->invalid(); }
			$files = new PrivateJobFileStore(); $private = $files->ensure_directory(); if ( is_wp_error( $private ) ) { return $private; } $root = $private . '/recovery-assets';
			if ( is_link( $root ) || ( file_exists( $root ) && ( ! is_dir( $root ) || dirname( (string) realpath( $root ) ) !== realpath( $private ) ) ) ) { return $this->invalid(); }
			$assets = array();
			foreach ( $refs['assets'] as $sha => $descriptor ) {
				if ( $declared[$sha]['bytes'] !== $descriptor['size'] || $declared[$sha]['sha256'] !== $sha ) { return $this->invalid(); }
				$path = $root . '/' . $sha; $exists = file_exists( $path ); if ( is_link( $path ) || ( $exists && ! $this->matches( $path, $descriptor ) ) ) { return $this->invalid(); }
				$temp = $files->create_path( $owner_token, 'whole-restore-asset' ); if ( is_wp_error( $temp ) ) { return $temp; }
				$assets[] = array( 'descriptor' => $descriptor, 'temporary' => $temp, 'created' => ! $exists );
			}
			$journal = array( 'version' => 1, 'purpose' => self::PURPOSE, 'owner_token' => $owner_token, 'archive_path' => $archive_path, 'archive_sha256' => $archive_sha256, 'root_created' => ! is_dir( $root ), 'assets' => $assets, 'cursor' => 0, 'phase' => 'publishing' );
			$saved = $this->write( $journal ); return is_wp_error( $saved ) ? $saved : $journal;
		} );
	}

	public function continue_publication( $owner_token ) {
		return MaintenanceMutex::run( function () use ( $owner_token ) {
			$j = $this->read(); if ( is_wp_error( $j ) || ! $j || $j['owner_token'] !== $owner_token || ! $this->owned( $owner_token ) || ! in_array( $j['phase'], array( 'publishing', 'published' ), true ) || ! $this->source_current( $j ) ) { return $this->invalid(); }
			if ( 'published' === $j['phase'] ) { return $j; }
			$root = $this->root(); if ( is_wp_error( $root ) ) { return $root; } if ( ! is_dir( $root ) && ! mkdir( $root, 0700 ) ) { return $this->invalid(); }
			if ( $j['cursor'] < count( $j['assets'] ) ) {
				$a = $j['assets'][$j['cursor']]; $d = $a['descriptor']; $path = $root . '/' . $d['sha256'];
				if ( ! file_exists( $path ) ) {
					if ( ! $a['created'] ) { return $this->invalid(); }
					$zip = new \ZipArchive(); if ( true !== $zip->open( $j['archive_path'], \ZipArchive::RDONLY ) ) { return $this->invalid(); }
					try {
						$in = $zip->getStream( 'assets/' . $d['sha256'] ); $out = fopen( $a['temporary'], 'wb' );
						if ( ! is_resource( $in ) || ! is_resource( $out ) ) { if ( is_resource( $in ) ) { fclose( $in ); } if ( is_resource( $out ) ) { fclose( $out ); } return $this->invalid(); }
						$written = stream_copy_to_stream( $in, $out, $d['size'] + 1 ); $flushed = fflush( $out ); fclose( $in ); fclose( $out ); @chmod( $a['temporary'], 0600 );
						if ( $written !== $d['size'] || ! $flushed || ! $this->matches( $a['temporary'], $d ) || ! $this->source_current( $j ) ) { return $this->invalid(); }
						// Hard-link publication is exclusive and atomic: incomplete target bytes never appear.
						if ( ! @link( $a['temporary'], $path ) ) { return $this->invalid(); }
					} finally { $zip->close(); }
				}
				if ( ! $this->matches( $path, $d ) || true !== ( new PrivateJobFileStore() )->delete( $a['temporary'] ) ) { return $this->invalid(); }
				++$j['cursor'];
			}
			if ( $j['cursor'] === count( $j['assets'] ) ) { $j['phase'] = 'published'; }
			$saved = $this->write( $j ); return is_wp_error( $saved ) ? $saved : $j;
		} );
	}

	/** Called after DB rollback; pre-existing identical assets are retained. */
	public function rollback( $owner_token ) {
		return MaintenanceMutex::run( function () use ( $owner_token ) {
			$j = $this->read(); if ( is_wp_error( $j ) || ! $j || $j['owner_token'] !== $owner_token || ! $this->owned( $owner_token ) || 'committed' === $j['phase'] ) { return $this->invalid(); }
			if ( 'rolling_back' !== $j['phase'] ) { $j['phase'] = 'rolling_back'; $j['cursor'] = 0; $saved = $this->write( $j ); if ( is_wp_error( $saved ) ) { return $saved; } }
			$root = $this->root(); if ( is_wp_error( $root ) ) { return $root; }
			for ( $i = 0; $i < 10 && $j['cursor'] < count( $j['assets'] ); ++$i, ++$j['cursor'] ) {
				$a = $j['assets'][$j['cursor']]; $path = $root . '/' . $a['descriptor']['sha256'];
				if ( $a['created'] && ( file_exists( $path ) || is_link( $path ) ) && ( ! $this->matches( $path, $a['descriptor'] ) || ! unlink( $path ) ) ) { return $this->invalid(); }
				if ( true !== ( new PrivateJobFileStore() )->delete( $a['temporary'] ) ) { return $this->invalid(); }
			}
			$saved = $this->write( $j ); if ( is_wp_error( $saved ) ) { return $saved; }
			if ( $j['cursor'] < count( $j['assets'] ) ) { return $j; }
			if ( $j['root_created'] && is_dir( $root ) && count( scandir( $root ) ) === 2 && ! rmdir( $root ) ) { return $this->invalid(); }
			return $this->clear( $j );
		} );
	}

	/** DB completion must be durable before marking these immutable bytes retained. */
	public function commit_after_whole_receipt( $owner_token, $archive_sha256 ) {
		$journal = ( new WholeStateRestoreJournal() )->read();
		if ( is_wp_error( $journal ) || ! $journal || 'committed' !== $journal['phase'] || $journal['owner_token'] !== $owner_token || $journal['source_sha256'] !== $archive_sha256 ) { return $this->invalid(); }
		return $this->commit( $owner_token );
	}
	public function commit( $owner_token ) {
		return MaintenanceMutex::run( function () use ( $owner_token ) {
			$whole = ( new WholeStateRestoreJournal() )->read();
			if ( is_wp_error( $whole ) || ( $whole && ( 'committed' !== $whole['phase'] || $whole['owner_token'] !== $owner_token ) ) ) { return $this->invalid(); }
			$j = $this->read(); if ( is_wp_error( $j ) || ! $j || $j['owner_token'] !== $owner_token || ! $this->owned( $owner_token ) || ! in_array( $j['phase'], array( 'published', 'committed' ), true ) ) { return $this->invalid(); }
			if ( $whole && $whole['source_sha256'] !== $j['archive_sha256'] ) { return $this->invalid(); }
			$root = $this->root(); if ( is_wp_error( $root ) ) { return $root; }
			foreach ( $j['assets'] as $a ) { if ( ! $this->matches( $root . '/' . $a['descriptor']['sha256'], $a['descriptor'] ) ) { return $this->invalid(); } }
			$j['phase'] = 'committed'; $saved = $this->write( $j ); return is_wp_error( $saved ) ? $saved : $this->clear( $j );
		} );
	}

	/** Read-only pending ownership check, also used before a maintenance lease is replaced. */
	public function pending_owner() { $j = $this->read(); return is_wp_error( $j ) ? $j : ( $j ? $j['owner_token'] : null ); }
	private function read() {
		global $wpdb; $wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT CASE WHEN OCTET_LENGTH(option_value)<=%d THEN option_value ELSE NULL END AS storage FROM %i WHERE option_name=%s', self::MAX_JOURNAL_BYTES, $wpdb->options, self::OPTION ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); } if ( null === $row ) { return null; }
		if ( ! is_array( $row ) || ! is_string( $row['storage'] ?? null ) || is_wp_error( ( new WholeStateProvenanceRepository() )->identity() ) ) { return $this->invalid(); }
		$record = ( new WholeStateOptionRecord() )->from_storage( $row['storage'] ); if ( is_wp_error( $record ) ) { return $this->invalid(); } $decoded = ( new WholeStateOptionValue() )->decode( $record );
		if ( is_wp_error( $decoded ) || ! is_array( $decoded['value'] ) || count( $decoded['value'] ) !== 2 || ! is_string( $decoded['value']['owner_token'] ?? null ) || ! is_string( $decoded['value']['sealed'] ?? null ) ) { return $this->invalid(); }
		$j = SecretVault::open( $decoded['value']['sealed'] );
		if ( is_wp_error( $j ) || ! is_array( $j ) || ( $j['owner_token'] ?? null ) !== $decoded['value']['owner_token'] || ! $this->valid( $j ) ) { return $this->invalid(); } return $j;
	}
	private function write( array $j ) {
		if ( ! $this->valid( $j ) || ! $this->owned( $j['owner_token'] ) ) { return $this->invalid(); }
		$sealed = SecretVault::seal( $j ); if ( is_wp_error( $sealed ) ) { return $this->invalid(); }
		$raw = maybe_serialize( array( 'owner_token' => $j['owner_token'], 'sealed' => $sealed ) ); if ( strlen( $raw ) > self::MAX_JOURNAL_BYTES ) { return $this->invalid(); }
		$result = DomainMaintenanceLock::run_internal( function () use ( $j, $raw ) { return ( new Transaction() )->run( function () use ( $j, $raw ) {
			global $wpdb; $wpdb->last_error = ''; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, self::OPTION ), ARRAY_A ); if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			if ( $row ) { $current = $this->read(); if ( is_wp_error( $current ) || ! $current || $current['owner_token'] !== $j['owner_token'] || $current['archive_sha256'] !== $j['archive_sha256'] ) { return $this->invalid(); } }
			$wpdb->last_error = ''; $n = $row ? $wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => self::OPTION ) ) : $wpdb->insert( $wpdb->options, array( 'option_name' => self::OPTION, 'option_value' => $raw, 'autoload' => 'off' ) ); return 1 === $n && '' === (string) $wpdb->last_error ? true : $this->invalid();
		} ); } ); DatabaseOptions::invalidate( array( self::OPTION ) ); return $result;
	}
	private function clear( array $j ) {
		$result = DomainMaintenanceLock::run_internal( function () use ( $j ) { return ( new Transaction() )->run( function () use ( $j ) {
			global $wpdb; $current = $this->read(); if ( is_wp_error( $current ) || ! $current || ( new CanonicalJson() )->hash( $current ) !== ( new CanonicalJson() )->hash( $j ) ) { return $this->invalid(); } $wpdb->last_error = ''; $n = $wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION ) ); return 1 === $n && '' === (string) $wpdb->last_error ? true : $this->invalid();
		} ); } ); DatabaseOptions::invalidate( array( self::OPTION ) ); return $result;
	}
	private function valid( array $j ) {
		$keys = array( 'version', 'purpose', 'owner_token', 'archive_path', 'archive_sha256', 'root_created', 'assets', 'cursor', 'phase' );
		if ( count( $j ) !== count( $keys ) || array_diff( array_keys( $j ), $keys ) || 1 !== $j['version'] || self::PURPOSE !== $j['purpose'] || ! is_string( $j['owner_token'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $j['owner_token'] ) || ! is_string( $j['archive_path'] ) || strlen( $j['archive_path'] ) > 2048 || false !== strpos( $j['archive_path'], "\0" ) || ! is_string( $j['archive_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $j['archive_sha256'] ) || ! is_bool( $j['root_created'] ) || ! is_array( $j['assets'] ) || count( $j['assets'] ) > 2048 || ! is_int( $j['cursor'] ) || $j['cursor'] < 0 || $j['cursor'] > count( $j['assets'] ) || ! in_array( $j['phase'], array( 'publishing', 'published', 'rolling_back', 'committed' ), true ) || ( $j['assets'] && array_keys( $j['assets'] ) !== range( 0, count( $j['assets'] ) - 1 ) ) ) { return false; }
		$seen = array(); $temporary = array(); $files = new PrivateJobFileStore();
		foreach ( $j['assets'] as $a ) {
			if ( ! is_array( $a ) || count( $a ) !== 3 || ! is_array( $a['descriptor'] ?? null ) || ! is_bool( $a['created'] ?? null ) || ! is_string( $a['temporary'] ?? null ) || ! $files->owns( $a['temporary'] ) || ! preg_match( '/^' . $j['owner_token'] . '-whole-restore-asset-[a-f0-9]{48}\.data$/D', basename( $a['temporary'] ) ) ) { return false; }
			$d = $a['descriptor'];
			if ( ! is_string( $d['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $d['sha256'] ) || isset( $seen[$d['sha256']] ) || ! is_int( $d['size'] ?? null ) || $d['size'] < 1 || $d['size'] > 26214400 || isset( $temporary[$a['temporary']] ) ) { return false; }
			$record = isset( $d['format'] ) ? array( 'kind' => 'font' ) + $d : array( 'kind' => isset( $d['file_type'] ) ? 'file' : 'image', 'reference' => 'attachment:1', 'descriptor' => $d );
			if ( is_wp_error( ( new \Certloom\Application\Backup\WholeStateReferenceRecord() )->validate( $record ) ) ) { return false; }
			$temporary[$a['temporary']] = true; $seen[$d['sha256']] = true;
		}
		return true;
	}
	private function root() { $private = ( new PrivateJobFileStore() )->directory(); if ( is_wp_error( $private ) ) { return $private; } $root = $private . '/recovery-assets'; return is_link( $root ) || ( file_exists( $root ) && ( ! is_dir( $root ) || dirname( (string) realpath( $root ) ) !== realpath( $private ) ) ) ? $this->invalid() : $root; }
	private function matches( $path, array $d ) {
		if ( is_link( $path ) || ! is_file( $path ) ) { return false; }
		try {
			$inspector = isset( $d['format'] ) ? new FontAssetInspector() : ( isset( $d['file_type'] ) ? new \Certloom\Application\Backup\WholeStateFileInspector() : new AssetInspector() );
			$asset = $inspector->inspect( array( 'path' => $path ) + $d ); unset( $asset['path'] );
			return ( new CanonicalJson() )->hash( $asset ) === ( new CanonicalJson() )->hash( $d );
		} catch ( \Throwable $e ) { return false; }
	}
	private function source_current( array $j ) { return ! is_link( $j['archive_path'] ) && is_file( $j['archive_path'] ) && hash_file( 'sha256', $j['archive_path'] ) === $j['archive_sha256']; }
	private function owned( $token ) { return is_string( $token ) && preg_match( '/^[a-f0-9]{32}$/D', $token ) && ( new DomainMaintenanceLock() )->owned_active( $token ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_asset_publication_invalid', __( 'Certloom could not confirm its recovery asset journal or exact bytes. Existing media remains unchanged; finish recovery before another maintenance operation.', 'certloom' ) ); }
}
