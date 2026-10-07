<?php
namespace Certloom\Infrastructure\Backup;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\BackupDestinationInterface;

/**
 * Bounded, non-secret catalog for Certloom-owned remote backup copies.
 *
 * The catalog never stores OAuth material or local filesystem paths. Pending
 * entries persist the provider file ID before upload so retries are idempotent.
 */
final class RemoteBackupCatalog {
	const OPTION = 'certloom_remote_backup_catalog';
	const MAX_ENTRIES = 100;

	/** @return array<int,array<string,mixed>> */
	public function all() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) { return array(); }
		$out = array();
		foreach ( $raw as $entry ) {
			$clean = $this->normalize( $entry );
			if ( $clean ) { $out[] = $clean; }
		}
		usort( $out, function ( $a, $b ) { return (int) $b['created_ts'] <=> (int) $a['created_ts']; } );
		return array_slice( $out, 0, self::MAX_ENTRIES );
	}

	/** @return array<string,mixed>|null */
	public function get( $id ) {
		$id = strtolower( trim( (string) $id ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) { return null; }
		foreach ( $this->all() as $entry ) {
			if ( hash_equals( $id, (string) $entry['id'] ) ) { return $entry; }
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	public function find_exact_local( $local_id, $sha256 ) {
		$local_id = strtolower( trim( (string) $local_id ) );
		$sha256   = strtolower( trim( (string) $sha256 ) );
		foreach ( $this->all() as $entry ) {
			if ( hash_equals( $local_id, (string) $entry['local_backup_id'] ) && hash_equals( $sha256, (string) $entry['file_sha256'] ) ) { return $entry; }
		}
		return null;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function reserve( array $local_entry, $remote_file_id ) {
		$existing = $this->find_exact_local( $local_entry['id'] ?? '', $local_entry['file_sha256'] ?? '' );
		if ( $existing ) { return $existing; }
		$catalog = $this->all();
		if ( count( $catalog ) >= self::MAX_ENTRIES ) {
			return new \WP_Error( 'certloom_remote_catalog_full', __( 'The Google Drive backup history has reached its safety limit. Delete an older remote backup before uploading another.', 'certloom' ) );
		}
		$remote_file_id = GoogleDriveOAuth::clean_file_id( $remote_file_id );
		$local_id = strtolower( trim( (string) ( $local_entry['id'] ?? '' ) ) );
		$sha256   = strtolower( trim( (string) ( $local_entry['file_sha256'] ?? '' ) ) );
		if ( '' === $remote_file_id || 1 !== preg_match( '/^[a-f0-9]{32}$/', $local_id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return new \WP_Error( 'certloom_remote_reservation_invalid', __( 'Certloom refused invalid metadata while preparing the Google Drive backup.', 'certloom' ) );
		}
		try { $id = bin2hex( random_bytes( 16 ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_remote_identity_failed', __( 'Certloom could not create a safe Google Drive backup identity.', 'certloom' ) ); }
		$now = time();
		$entry = array(
			'id'               => $id,
			'provider'         => 'google_drive',
			'status'           => 'pending',
			'remote_file_id'   => $remote_file_id,
			'local_backup_id'  => $local_id,
			'purpose'          => sanitize_key( (string) ( $local_entry['purpose'] ?? 'manual' ) ),
			'created_at'       => gmdate( 'c', $now ),
			'created_ts'       => $now,
			'updated_at'       => gmdate( 'c', $now ),
			'updated_ts'       => $now,
			'bytes'            => absint( $local_entry['bytes'] ?? 0 ),
			'records'          => absint( $local_entry['records'] ?? 0 ),
			'file_sha256'      => $sha256,
			'content_sha256'   => strtolower( trim( (string) ( $local_entry['content_sha256'] ?? '' ) ) ),
			'format_version'   => absint( $local_entry['format_version'] ?? 0 ),
			'plugin_version'   => sanitize_text_field( (string) ( $local_entry['plugin_version'] ?? '' ) ),
			'schema_version'   => sanitize_text_field( (string) ( $local_entry['schema_version'] ?? '' ) ),
			'source_authority' => 'current_registry',
			'attempts'         => 0,
			'last_error'       => '',
		);
		array_unshift( $catalog, $entry );
		return $this->save( $catalog ) ? $entry : new \WP_Error( 'certloom_remote_catalog_save_failed', __( 'Certloom could not save the pending Google Drive backup safely.', 'certloom' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function mark_verified( $id, array $remote ) {
		return $this->update( $id, function ( $entry ) use ( $remote ) {
			$entry['status']         = 'verified';
			$entry['name']           = sanitize_file_name( (string) ( $remote['name'] ?? '' ) );
			$entry['remote_file_id'] = GoogleDriveOAuth::clean_file_id( $remote['remote_file_id'] ?? $entry['remote_file_id'] );
			$entry['md5_checksum']   = strtolower( trim( (string) ( $remote['md5_checksum'] ?? '' ) ) );
			$entry['folder_id']      = GoogleDriveOAuth::clean_file_id( $remote['folder_id'] ?? '' );
			$entry['uploaded_at']    = sanitize_text_field( (string) ( $remote['uploaded_at'] ?? gmdate( 'c' ) ) );
			$entry['uploaded_ts']    = absint( $remote['uploaded_ts'] ?? time() );
			$entry['integrity']      = 'verified';
			$entry['last_error']     = '';
			$entry['attempts']       = absint( $entry['attempts'] ?? 0 ) + 1;
			return $entry;
		} );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function mark_failed( $id, $message ) {
		return $this->update( $id, function ( $entry ) use ( $message ) {
			$entry['status']     = 'failed';
			$entry['last_error'] = sanitize_text_field( (string) $message );
			$entry['attempts']   = absint( $entry['attempts'] ?? 0 ) + 1;
			return $entry;
		} );
	}

	/** Bind a verified remote copy to a newly recovered local copy without changing its original source identity. @return array<string,mixed>|\WP_Error */
	public function bind_recovered_local( $id, array $local_entry ) {
		$local_id = strtolower( trim( (string) ( $local_entry['id'] ?? '' ) ) );
		$sha256   = strtolower( trim( (string) ( $local_entry['file_sha256'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $local_id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return new \WP_Error( 'certloom_remote_recovery_binding_invalid', __( 'Certloom refused invalid local recovery metadata for the Google Drive backup.', 'certloom' ) );
		}
		return $this->update( $id, function ( $entry ) use ( $local_id, $sha256 ) {
			if ( 'verified' !== (string) $entry['status'] || ! hash_equals( (string) $entry['file_sha256'], $sha256 ) ) {
				return $entry;
			}
			$entry['recovered_local_backup_id'] = $local_id;
			return $entry;
		} );
	}

	/** @return true|\WP_Error */
	public function forget( $id ) {
		$id = strtolower( trim( (string) $id ) );
		$found = false;
		$keep = array();
		foreach ( $this->all() as $entry ) {
			if ( hash_equals( $id, (string) $entry['id'] ) ) { $found = true; continue; }
			$keep[] = $entry;
		}
		if ( ! $found ) { return new \WP_Error( 'certloom_remote_not_found', __( 'That Google Drive backup is not in Certloom’s remote backup history.', 'certloom' ) ); }
		return $this->save( $keep ) ? true : new \WP_Error( 'certloom_remote_catalog_save_failed', __( 'Certloom removed the Google Drive backup but could not update its remote backup history.', 'certloom' ) );
	}

	/** @return int|\WP_Error */
	public function prune_scheduled( $retain, BackupDestinationInterface $destination ) {
		$retain = max( 1, min( 60, absint( $retain ) ?: 14 ) );
		$seen = 0; $keep = array(); $remove = array();
		foreach ( $this->all() as $entry ) {
			if ( 'scheduled' !== $entry['purpose'] || 'verified' !== $entry['status'] ) { $keep[] = $entry; continue; }
			$seen++;
			if ( $seen <= $retain ) { $keep[] = $entry; } else { $remove[] = $entry; }
		}
		foreach ( $remove as $entry ) {
			$deleted = $destination->delete( $entry );
			if ( is_wp_error( $deleted ) ) { return $deleted; }
		}
		return $this->save( $keep ) ? count( $remove ) : new \WP_Error( 'certloom_remote_catalog_save_failed', __( 'Google Drive retention removed old files but Certloom could not save the updated remote backup history.', 'certloom' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function update( $id, callable $mutator ) {
		$id = strtolower( trim( (string) $id ) );
		$catalog = $this->all(); $found = false; $updated = null;
		foreach ( $catalog as &$entry ) {
			if ( ! hash_equals( $id, (string) $entry['id'] ) ) { continue; }
			$found = true; $entry = $mutator( $entry );
			$entry['updated_at'] = gmdate( 'c' ); $entry['updated_ts'] = time(); $updated = $entry; break;
		}
		unset( $entry );
		if ( ! $found || ! is_array( $updated ) ) { return new \WP_Error( 'certloom_remote_not_found', __( 'That Google Drive backup is not in Certloom’s remote backup history.', 'certloom' ) ); }
		return $this->save( $catalog ) ? $updated : new \WP_Error( 'certloom_remote_catalog_save_failed', __( 'Certloom could not update its Google Drive backup history.', 'certloom' ) );
	}

	private function save( array $catalog ) {
		$catalog = array_slice( array_values( $catalog ), 0, self::MAX_ENTRIES );
		return true === ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->set( self::OPTION, $catalog );
	}

	/** @return array<string,mixed>|null */
	private function normalize( $entry ) {
		if ( ! is_array( $entry ) ) { return null; }
		$id = strtolower( trim( (string) ( $entry['id'] ?? '' ) ) );
		$local = strtolower( trim( (string) ( $entry['local_backup_id'] ?? '' ) ) );
		$sha = strtolower( trim( (string) ( $entry['file_sha256'] ?? '' ) ) );
		$remote = GoogleDriveOAuth::clean_file_id( $entry['remote_file_id'] ?? '' );
		$status = sanitize_key( (string) ( $entry['status'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $local ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) || '' === $remote || ! in_array( $status, array( 'pending', 'failed', 'verified' ), true ) ) { return null; }
		$entry['id']=$id; $entry['local_backup_id']=$local; $entry['file_sha256']=$sha; $entry['remote_file_id']=$remote; $entry['status']=$status;
		$entry['purpose']=sanitize_key((string)($entry['purpose']??'manual')); $entry['created_ts']=absint($entry['created_ts']??0); $entry['updated_ts']=absint($entry['updated_ts']??0); $entry['bytes']=absint($entry['bytes']??0); $entry['records']=absint($entry['records']??0); $entry['attempts']=absint($entry['attempts']??0);
		$recovered = strtolower( trim( (string) ( $entry['recovered_local_backup_id'] ?? '' ) ) );
		$entry['recovered_local_backup_id'] = 1 === preg_match( '/^[a-f0-9]{32}$/', $recovered ) ? $recovered : '';
		$entry['md5_checksum']=strtolower(trim((string)($entry['md5_checksum']??''))); $entry['last_error']=sanitize_text_field((string)($entry['last_error']??''));
		return $entry;
	}
}
