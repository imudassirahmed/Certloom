<?php
namespace Certloom\Infrastructure\Backup;

use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Backup\WholeStateReferenceRecord;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Immutable private checkpoints keep configuration/sealed credentials out of job progress and browser responses. */
final class WholeStateCaptureCheckpoint {
	const MAX_BYTES = 16777216;
	private $files;
	public function __construct( ?PrivateJobFileStore $files = null ) { $this->files = $files ?: new PrivateJobFileStore(); }
	public function write( $job_key, array $data, $purpose = 'whole-checkpoint' ) {
		if ( ! $this->purpose( $purpose ) || ! is_string( $job_key ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $job_key ) ) { return $this->failed(); }
		try { $bytes = ( new CanonicalJson() )->encode( array( 'version' => 1, 'job_key' => $job_key, 'data' => $data ) ) . "\n"; } catch ( \Throwable $e ) { return $this->failed(); }
		if ( strlen( $bytes ) > self::MAX_BYTES ) { return $this->failed(); }
		$path = $this->files->create_path( $job_key, $purpose ); if ( is_wp_error( $path ) ) { return $path; }
		$h = @fopen( $path, 'xb' ); if ( ! is_resource( $h ) ) { return $this->failed(); }
		$written = fwrite( $h, $bytes ); $flushed = fflush( $h ); $closed = fclose( $h );
		if ( strlen( $bytes ) !== $written || ! $flushed || ! $closed ) { $this->files->delete( $path ); return $this->failed(); } @chmod( $path, 0600 );
		return array( 'path' => $path, 'bytes' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ) );
	}
	public function read( array $reference, $job_key, $purpose = 'whole-checkpoint' ) {
		if ( count( $reference ) !== 3 || ! $this->purpose( $purpose ) || ! is_string( $job_key ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $job_key ) || ! is_string( $reference['path'] ?? null ) || ! $this->files->owns( $reference['path'] ) || 1 !== preg_match( '/^' . $job_key . '-' . $purpose . '-[a-f0-9]{48}\.data$/D', basename( $reference['path'] ) ) || ! is_int( $reference['bytes'] ?? null ) || $reference['bytes'] < 1 || $reference['bytes'] > self::MAX_BYTES || ! WholeStateReferenceRecord::sha( $reference['sha256'] ?? null ) ) { return $this->failed(); }
		clearstatcache( true, $reference['path'] ); if ( ! is_file( $reference['path'] ) || filesize( $reference['path'] ) !== $reference['bytes'] ) { return $this->failed(); }
		$bytes = file_get_contents( $reference['path'], false, null, 0, self::MAX_BYTES + 1 );
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== $reference['bytes'] || ! hash_equals( $reference['sha256'], hash( 'sha256', $bytes ) ) ) { return $this->failed(); }
		$data = json_decode( $bytes, true, 64 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || count( $data ) !== 3 || 1 !== ( $data['version'] ?? null ) || $job_key !== ( $data['job_key'] ?? null ) || ! is_array( $data['data'] ?? null ) ) { return $this->failed(); }
		try { $canonical = ( new CanonicalJson() )->encode( $data ) . "\n"; } catch ( \Throwable $e ) { return $this->failed(); }
		return hash_equals( $bytes, $canonical ) ? $data['data'] : $this->failed();
	}
	private function purpose( $v ) { return in_array( $v, array( 'whole-checkpoint', 'whole-context' ), true ); }
	private function failed() { return new \WP_Error( 'certloom_whole_checkpoint_invalid', __( 'The private recovery checkpoint is missing, changed or outside its owned capture. No complete archive was published.', 'certloom' ) ); }
}
