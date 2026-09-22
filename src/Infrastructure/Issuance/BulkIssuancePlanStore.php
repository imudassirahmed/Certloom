<?php
namespace Certloom\Infrastructure\Issuance;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Crash-resumable protected JSONL plan storage for reviewed Bulk Issuance rows. */
final class BulkIssuancePlanStore {
	/** @var PrivateJobFileStore */ private $files;
	/** @var CanonicalJson */ private $json;
	public function __construct( ?PrivateJobFileStore $files = null, ?CanonicalJson $json = null ) { $this->files = $files ?: new PrivateJobFileStore(); $this->json = $json ?: new CanonicalJson(); }

	/** @return string|\WP_Error */
	public function create( $job_key ) {
		$path = $this->files->create_path( $job_key, 'bulk-plan' ); if ( is_wp_error( $path ) ) { return $path; }
		$h = @fopen( $path, 'xb' ); if ( false === $h ) { return new \WP_Error( 'certloom_bulk_plan_create_failed', __( 'Certloom could not create its protected Bulk Issuance review plan.', 'certloom' ) ); }
		fclose( $h ); @chmod( $path, 0600 ); return $path;
	}

	/**
	 * Truncates uncommitted crash residue to the last DB-saved offset before a new slice.
	 * @return resource|\WP_Error
	 */
	public function open_append( $path, $committed_offset ) {
		$valid = $this->validate( $path ); if ( is_wp_error( $valid ) ) { return $valid; }
		$h = @fopen( (string) $path, 'c+b' ); if ( false === $h ) { return new \WP_Error( 'certloom_bulk_plan_open_failed', __( 'Certloom could not reopen its protected Bulk Issuance review plan.', 'certloom' ) ); }
		$committed_offset = max( 0, (int) $committed_offset );
		if ( ! @flock( $h, LOCK_EX ) || ! @ftruncate( $h, $committed_offset ) || 0 !== fseek( $h, $committed_offset, SEEK_SET ) ) { @flock( $h, LOCK_UN ); fclose( $h ); return new \WP_Error( 'certloom_bulk_plan_resume_failed', __( 'Certloom could not restore the Bulk Issuance review plan to its last committed boundary.', 'certloom' ) ); }
		return $h;
	}

	/** @return int|\WP_Error */
	public function append( $handle, array $entry ) {
		if ( ! is_resource( $handle ) ) { return new \WP_Error( 'certloom_bulk_plan_handle_invalid', __( 'The Bulk Issuance review-plan handle is invalid.', 'certloom' ) ); }
		try { $line = $this->json->encode( $entry ) . "\n"; } catch ( \Throwable $e ) { return new \WP_Error( 'certloom_bulk_plan_encode_failed', __( 'Certloom could not encode a reviewed Bulk Issuance row deterministically.', 'certloom' ) ); }
		$written = fwrite( $handle, $line ); if ( false === $written || strlen( $line ) !== $written || ! fflush( $handle ) ) { return new \WP_Error( 'certloom_bulk_plan_write_failed', __( 'Certloom could not durably append a reviewed Bulk Issuance row.', 'certloom' ) ); }
		$offset = ftell( $handle ); return false === $offset ? new \WP_Error( 'certloom_bulk_plan_position_failed', __( 'Certloom could not record the Bulk Issuance plan position.', 'certloom' ) ) : (int) $offset;
	}

	public function close_append( $handle ) { if ( is_resource( $handle ) ) { @flock( $handle, LOCK_UN ); fclose( $handle ); } }

	/** @return array<string,bool>|\WP_Error */
	public function identity_set( $path, $limit_offset ) {
		$valid = $this->validate( $path ); if ( is_wp_error( $valid ) ) { return $valid; }
		$h = @fopen( $path, 'rb' ); if ( false === $h ) { return new \WP_Error( 'certloom_bulk_plan_read_failed', __( 'Certloom could not read its Bulk Issuance review plan.', 'certloom' ) ); }
		$limit_offset = max( 0, (int) $limit_offset ); $set = array();
		while ( ( $pos = ftell( $h ) ) !== false && $pos < $limit_offset && false !== ( $line = fgets( $h ) ) ) {
			$entry = json_decode( $line, true ); if ( ! is_array( $entry ) ) { fclose( $h ); return new \WP_Error( 'certloom_bulk_plan_corrupt', __( 'The protected Bulk Issuance review plan is invalid.', 'certloom' ) ); }
			foreach ( (array) ( $entry['identity_keys'] ?? array() ) as $key ) { $key = (string) $key; if ( '' !== $key ) { $set[ $key ] = true; } }
		}
		fclose( $h ); return $set;
	}

	/** @return array{entries:array<int,array<string,mixed>>,offset:int,complete:bool}|\WP_Error */
	public function read_slice( $path, $offset, $limit = 25 ) {
		$valid = $this->validate( $path ); if ( is_wp_error( $valid ) ) { return $valid; }
		$h = @fopen( $path, 'rb' ); if ( false === $h || 0 !== fseek( $h, max( 0, (int) $offset ), SEEK_SET ) ) { if ( is_resource( $h ) ) { fclose( $h ); } return new \WP_Error( 'certloom_bulk_plan_resume_failed', __( 'Certloom could not resume the protected Bulk Issuance plan.', 'certloom' ) ); }
		$limit = max( 1, min( 100, absint( $limit ) ) ); $entries = array();
		while ( count( $entries ) < $limit && false !== ( $line = fgets( $h ) ) ) { if ( '' === trim( $line ) ) { continue; } $entry = json_decode( $line, true ); if ( ! is_array( $entry ) ) { fclose( $h ); return new \WP_Error( 'certloom_bulk_plan_corrupt', __( 'The protected Bulk Issuance review plan is invalid.', 'certloom' ) ); } $entries[] = $entry; }
		$new_offset = ftell( $h ); $size = @filesize( $path ); $complete = feof( $h ) || ( false !== $size && false !== $new_offset && (int) $new_offset >= (int) $size ); fclose( $h );
		return array( 'entries' => $entries, 'offset' => false === $new_offset ? max( 0, (int) $offset ) : (int) $new_offset, 'complete' => $complete );
	}

	/** @return string|\WP_Error */
	public function sha256( $path ) { $valid = $this->validate( $path ); if ( is_wp_error( $valid ) ) { return $valid; } $hash = @hash_file( 'sha256', $path ); return is_string( $hash ) && preg_match( '/^[a-f0-9]{64}$/', $hash ) ? $hash : new \WP_Error( 'certloom_bulk_plan_hash_failed', __( 'Certloom could not fingerprint the protected Bulk Issuance review plan.', 'certloom' ) ); }

	/** @return true|\WP_Error */
	private function validate( $path ) { if ( ! $this->files->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) { return new \WP_Error( 'certloom_bulk_plan_path_invalid', __( 'The protected Bulk Issuance review plan is unavailable.', 'certloom' ) ); } return true; }
}
