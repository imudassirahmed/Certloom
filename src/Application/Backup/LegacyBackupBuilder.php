<?php
namespace Certloom\Application\Backup;

use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded v4 capture. A crashed append is discarded before replaying a slice. */
final class LegacyBackupBuilder {
	private $files;
	public function __construct( ?PrivateJobFileStore $files = null ) { $this->files = $files ?: new PrivateJobFileStore(); }

	public function start( $job_key ) {
		$before = \Ecapla_CertVerify_Backup::capture_state_marker();
		if ( is_wp_error( $before ) ) { return $before; }
		$manifest = \Ecapla_CertVerify_Backup::export_manifest();
		if ( is_wp_error( $manifest ) ) { return $manifest; }
		$after = \Ecapla_CertVerify_Backup::capture_state_marker();
		if ( is_wp_error( $after ) || ! $this->same( $before, $after ) ) { return $this->error( 'changed' ); }
		$path = $this->files->create_path( $job_key, 'whole-legacy' );
		if ( is_wp_error( $path ) ) { return $path; }
		$json = wp_json_encode( $manifest );
		if ( ! is_string( $json ) ) { return $this->error( 'encode' ); }
		$line = $json . "\n";
		$handle = @fopen( $path, 'xb' );
		if ( ! is_resource( $handle ) ) { return $this->error( 'create' ); }
		$written = fwrite( $handle, $line ); $flushed = fflush( $handle ); $closed = fclose( $handle );
		if ( strlen( $line ) !== $written || ! $flushed || ! $closed || strlen( $line ) > \Ecapla_CertVerify_Backup::MAX_FILE_BYTES ) { $this->files->delete( $path ); return $this->error( 'write' ); }
		@chmod( $path, 0600 );
		return array( 'path' => $path, 'marker' => $before, 'offset' => strlen( $line ), 'cursor' => 0, 'records' => 0, 'prefix_sha256' => hash( 'sha256', $line ), 'complete' => false );
	}

	public function continue_build( array $state, $limit = 100 ) {
		if ( ! $this->valid( $state ) ) { return $this->error( 'state' ); }
		$current = \Ecapla_CertVerify_Backup::capture_state_marker();
		if ( is_wp_error( $current ) || ! $this->same( $state['marker'], $current ) ) { return $this->error( 'changed' ); }
		$handle = @fopen( $state['path'], 'r+b' );
		if ( ! is_resource( $handle ) ) { return $this->error( 'open' ); }
		try {
			$stat = fstat( $handle );
			if ( ! is_array( $stat ) || $stat['size'] < $state['offset'] ) { return $this->error( 'short' ); }
			$hash = hash_init( 'sha256' ); $left = $state['offset'];
			while ( $left > 0 ) { $chunk = fread( $handle, min( 65536, $left ) ); if ( ! is_string( $chunk ) || '' === $chunk ) { return $this->error( 'read' ); } hash_update( $hash, $chunk ); $left -= strlen( $chunk ); }
			if ( ! hash_equals( $state['prefix_sha256'], hash_final( hash_copy( $hash ) ) ) ) { return $this->error( 'prefix' ); }
			if ( ! ftruncate( $handle, $state['offset'] ) || 0 !== fseek( $handle, $state['offset'] ) ) { return $this->error( 'resume' ); }
			if ( $state['complete'] ) { return $state; }
			$limit = max( 1, min( 500, (int) $limit ) );
			$rows = \Ecapla_CertVerify_Database::get_export_batch( $state['cursor'], $limit );
			if ( is_wp_error( $rows ) ) { return $rows; }
			foreach ( $rows as $row ) {
				if ( (int) $row['id'] <= $state['cursor'] || ++$state['records'] > \Ecapla_CertVerify_Backup::MAX_RECORDS ) { return $this->error( 'records' ); }
				$line = \Ecapla_CertVerify_Backup::export_record_line( $row );
				if ( is_wp_error( $line ) || strlen( $line ) > \Ecapla_CertVerify_Backup::MAX_FILE_BYTES - $state['offset'] || strlen( $line ) !== fwrite( $handle, $line ) ) { return $this->error( 'write' ); }
				hash_update( $hash, $line ); $state['offset'] += strlen( $line ); $state['cursor'] = (int) $row['id'];
			}
			if ( count( $rows ) < $limit ) {
				$current = \Ecapla_CertVerify_Backup::capture_state_marker();
				if ( is_wp_error( $current ) || ! $this->same( $state['marker'], $current ) ) { return $this->error( 'changed' ); }
				$line = wp_json_encode( array( 'type' => 'integrity', 'records' => $state['records'], 'sha256' => hash_final( hash_copy( $hash ) ) ) ) . "\n";
				if ( strlen( $line ) > \Ecapla_CertVerify_Backup::MAX_FILE_BYTES - $state['offset'] || strlen( $line ) !== fwrite( $handle, $line ) ) { return $this->error( 'footer' ); }
				hash_update( $hash, $line ); $state['offset'] += strlen( $line ); $state['complete'] = true;
			}
			if ( ! fflush( $handle ) ) { return $this->error( 'flush' ); }
			$state['prefix_sha256'] = hash_final( $hash );
			return $state;
		} finally { fclose( $handle ); }
	}

	private function valid( array $s ) {
		return count( $s ) === 7 && isset( $s['path'], $s['marker'], $s['offset'], $s['cursor'], $s['records'], $s['prefix_sha256'], $s['complete'] ) && is_string( $s['path'] ) && $this->files->owns( $s['path'] ) && is_file( $s['path'] ) && is_array( $s['marker'] ) && is_int( $s['offset'] ) && $s['offset'] > 0 && $s['offset'] <= \Ecapla_CertVerify_Backup::MAX_FILE_BYTES && is_int( $s['cursor'] ) && $s['cursor'] >= 0 && is_int( $s['records'] ) && $s['records'] >= 0 && $s['records'] <= \Ecapla_CertVerify_Backup::MAX_RECORDS && is_string( $s['prefix_sha256'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $s['prefix_sha256'] ) && is_bool( $s['complete'] );
	}
	private function same( array $a, array $b ) { try { return ( new CanonicalJson() )->hash( $a ) === ( new CanonicalJson() )->hash( $b ); } catch ( \Throwable $e ) { return false; } }
	private function error( $code ) { return new \WP_Error( 'certloom_whole_legacy_' . $code, __( 'Certloom could not finish the legacy component safely. No complete recovery snapshot was published.', 'certloom' ) ); }
}
