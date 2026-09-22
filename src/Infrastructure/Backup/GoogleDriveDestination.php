<?php
namespace Certloom\Infrastructure\Backup;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\BackupDestinationInterface;

/** Google Drive destination for already-verified current-authority backup packages. */
final class GoogleDriveDestination implements BackupDestinationInterface {
	const PROVIDER = 'google_drive';
	const FOLDER_MARKER = 'certloom_backup_folder_v1';

	/** @var GoogleDriveClient */
	private $client;

	public function __construct( ?GoogleDriveClient $client = null ) {
		$this->client = $client ?: new GoogleDriveClient();
	}

	public function provider() { return self::PROVIDER; }

	/** @return true|\WP_Error */
	public function test_connection() {
		$folder = $this->ensure_folder();
		if ( is_wp_error( $folder ) ) { return $folder; }
		$temp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'certloom-google-drive-test' ) : tempnam( sys_get_temp_dir(), 'cl-gd-' );
		if ( ! is_string( $temp ) || '' === $temp ) {
			return new \WP_Error( 'certloom_google_probe_temp_failed', __( 'Certloom could not create a temporary Google Drive connection test.', 'certloom' ) );
		}
		try { $bytes = random_bytes( 64 ); }
		catch ( \Throwable $error ) { @unlink( $temp ); return new \WP_Error( 'certloom_google_probe_random_failed', __( 'Certloom could not create a secure Google Drive connection test.', 'certloom' ) ); }
		if ( strlen( $bytes ) !== @file_put_contents( $temp, $bytes, LOCK_EX ) ) {
			@unlink( $temp );
			return new \WP_Error( 'certloom_google_probe_write_failed', __( 'Certloom could not prepare the Google Drive connection test.', 'certloom' ) );
		}
		@chmod( $temp, 0600 );
		$id = $this->client->generate_file_id();
		if ( is_wp_error( $id ) ) { @unlink( $temp ); return $id; }
		$props = array(
			'certloom_kind'   => 'connection_test',
			'certloom_sha256' => hash_file( 'sha256', $temp ),
			'certloom_bytes'  => (string) filesize( $temp ),
		);
		$result = $this->client->upload_file( $temp, $id, 'certloom-connection-test.bin', $folder, $props );
		@unlink( $temp );
		if ( is_wp_error( $result ) ) { return $result; }
		$verified = $this->verify_uploaded_metadata( $result, $id, 64, hash( 'sha256', $bytes ), hash( 'md5', $bytes ), 'connection_test' );
		$deleted  = $this->client->delete_file( $id );
		if ( is_wp_error( $verified ) ) { return $verified; }
		return is_wp_error( $deleted ) ? $deleted : true;
	}

	/** @param array<string,mixed> $local_entry @return array<string,mixed>|\WP_Error */
	public function upload( array $local_entry ) {
		$path = (string) ( $local_entry['path'] ?? '' );
		$id   = GoogleDriveOAuth::clean_file_id( $local_entry['remote_file_id'] ?? '' );
		if ( '' === $id ) {
			$id = $this->client->generate_file_id();
			if ( is_wp_error( $id ) ) { return $id; }
		}
		$hash = strtolower( trim( (string) ( $local_entry['file_sha256'] ?? '' ) ) );
		$size = absint( $local_entry['bytes'] ?? 0 );
		if ( '' === $path || ! is_file( $path ) || is_link( $path ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) || $size <= 0 ) {
			return new \WP_Error( 'certloom_google_local_backup_invalid', __( 'Certloom refused an invalid local backup before Google Drive upload.', 'certloom' ) );
		}
		$actual_hash = @hash_file( 'sha256', $path );
		$actual_size = @filesize( $path );
		if ( ! is_string( $actual_hash ) || ! hash_equals( $hash, strtolower( $actual_hash ) ) || (int) $actual_size !== $size ) {
			return new \WP_Error( 'certloom_google_local_backup_changed', __( 'Certloom refused to upload a local backup whose bytes no longer match its verified catalog entry.', 'certloom' ) );
		}
		$md5 = @hash_file( 'md5', $path );
		if ( ! is_string( $md5 ) || 32 !== strlen( $md5 ) ) {
			return new \WP_Error( 'certloom_google_local_md5_failed', __( 'Certloom could not calculate the transport checksum for Google Drive upload.', 'certloom' ) );
		}
		$folder = $this->ensure_folder();
		if ( is_wp_error( $folder ) ) { return $folder; }
		$created_ts = absint( $local_entry['created_ts'] ?? time() );
		$name = 'certloom-backup-' . gmdate( 'Ymd-His', $created_ts ) . '-' . substr( (string) ( $local_entry['id'] ?? $id ), 0, 8 ) . '.cvbackup';
		$purpose = sanitize_key( (string) ( $local_entry['purpose'] ?? 'manual' ) );
		$props = array(
			'certloom_kind'             => 'backup_v1',
			'certloom_sha256'           => $hash,
			'certloom_bytes'            => (string) $size,
			'certloom_backup_id'        => sanitize_text_field( (string) ( $local_entry['id'] ?? '' ) ),
			'certloom_purpose'          => $purpose,
			'certloom_records'          => (string) absint( $local_entry['records'] ?? 0 ),
			'certloom_format_version'   => (string) absint( $local_entry['format_version'] ?? 0 ),
			'certloom_plugin_version'   => sanitize_text_field( (string) ( $local_entry['plugin_version'] ?? '' ) ),
			'certloom_schema_version'   => sanitize_text_field( (string) ( $local_entry['schema_version'] ?? '' ) ),
			'certloom_content_sha256'   => strtolower( trim( (string) ( $local_entry['content_sha256'] ?? '' ) ) ),
			'certloom_source_authority' => 'current_registry',
		);
		$remote = $this->client->upload_file( $path, $id, $name, $folder, $props );
		if ( is_wp_error( $remote ) ) { return $remote; }
		$verified = $this->verify_uploaded_metadata( $remote, $id, $size, $hash, strtolower( $md5 ), 'backup_v1' );
		if ( is_wp_error( $verified ) ) {
			$this->client->delete_file( $id );
			return $verified;
		}
		return array(
			'provider'         => self::PROVIDER,
			'remote_file_id'   => $id,
			'name'             => sanitize_file_name( (string) ( $remote['name'] ?? $name ) ),
			'local_backup_id'  => sanitize_text_field( (string) ( $local_entry['id'] ?? '' ) ),
			'purpose'          => $purpose,
			'created_at'       => sanitize_text_field( (string) ( $local_entry['created_at'] ?? gmdate( 'c', $created_ts ) ) ),
			'created_ts'       => $created_ts,
			'uploaded_at'      => gmdate( 'c' ),
			'uploaded_ts'      => time(),
			'bytes'            => $size,
			'records'          => absint( $local_entry['records'] ?? 0 ),
			'file_sha256'      => $hash,
			'md5_checksum'     => strtolower( $md5 ),
			'content_sha256'   => strtolower( trim( (string) ( $local_entry['content_sha256'] ?? '' ) ) ),
			'format_version'   => absint( $local_entry['format_version'] ?? 0 ),
			'plugin_version'   => sanitize_text_field( (string) ( $local_entry['plugin_version'] ?? '' ) ),
			'schema_version'   => sanitize_text_field( (string) ( $local_entry['schema_version'] ?? '' ) ),
			'source_authority' => 'current_registry',
			'integrity'        => 'verified',
			'folder_id'        => $folder,
		);
	}

	/** @param array<string,mixed> $remote_entry @return string|\WP_Error */
	public function download( array $remote_entry ) {
		$file_id = GoogleDriveOAuth::clean_file_id( $remote_entry['remote_file_id'] ?? '' );
		$hash    = strtolower( trim( (string) ( $remote_entry['file_sha256'] ?? '' ) ) );
		$size    = absint( $remote_entry['bytes'] ?? 0 );
		if ( '' === $file_id || 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) || $size <= 0 ) {
			return new \WP_Error( 'certloom_google_remote_entry_invalid', __( 'Certloom refused invalid Google Drive backup metadata.', 'certloom' ) );
		}
		$metadata = $this->client->get_file( $file_id );
		if ( is_wp_error( $metadata ) ) { return $metadata; }
		$verified = $this->verify_uploaded_metadata( $metadata, $file_id, $size, $hash, strtolower( (string) ( $remote_entry['md5_checksum'] ?? '' ) ), 'backup_v1' );
		if ( is_wp_error( $verified ) ) { return $verified; }
		$temp = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'certloom-google-drive-backup' ) : tempnam( sys_get_temp_dir(), 'cl-gd-' );
		if ( ! is_string( $temp ) || '' === $temp ) { return new \WP_Error( 'certloom_google_download_temp_failed', __( 'Certloom could not create a private temporary file for the Google Drive backup.', 'certloom' ) ); }
		@unlink( $temp ); // GoogleDriveClient requires an exclusive new target.
		$result = $this->client->download_file( $file_id, $temp );
		if ( is_wp_error( $result ) ) { @unlink( $temp ); return $result; }
		$actual_hash = @hash_file( 'sha256', $temp );
		$actual_size = @filesize( $temp );
		$actual_md5  = @hash_file( 'md5', $temp );
		if ( ! is_string( $actual_hash ) || ! hash_equals( $hash, strtolower( $actual_hash ) ) || (int) $actual_size !== $size || ( isset( $remote_entry['md5_checksum'] ) && ! hash_equals( strtolower( (string) $remote_entry['md5_checksum'] ), strtolower( (string) $actual_md5 ) ) ) ) {
			@unlink( $temp );
			return new \WP_Error( 'certloom_google_download_integrity_failed', __( 'Certloom removed a Google Drive download that did not match the verified backup checksums.', 'certloom' ) );
		}
		return $temp;
	}

	/** @param array<string,mixed> $remote_entry @return true|\WP_Error */
	public function delete( array $remote_entry ) {
		$file_id = GoogleDriveOAuth::clean_file_id( $remote_entry['remote_file_id'] ?? '' );
		return '' === $file_id ? new \WP_Error( 'certloom_google_file_id_invalid', __( 'Google Drive returned an invalid file identifier.', 'certloom' ) ) : $this->client->delete_file( $file_id );
	}

	/** @return string|\WP_Error */
	private function ensure_folder() {
		$config = GoogleDriveOAuth::config();
		$folder = GoogleDriveOAuth::clean_file_id( $config['folder_id'] ?? '' );
		if ( '' !== $folder ) {
			$current = $this->client->get_file( $folder );
			if ( is_array( $current ) && empty( $current['trashed'] ) && 'application/vnd.google-apps.folder' === (string) ( $current['mimeType'] ?? '' ) && self::FOLDER_MARKER === (string) ( $current['appProperties']['certloom_kind'] ?? '' ) ) {
				return $folder;
			}
		}
		$created = $this->client->create_folder( __( 'Certloom Backups', 'certloom' ), array( 'certloom_kind' => self::FOLDER_MARKER ) );
		if ( is_wp_error( $created ) ) { return $created; }
		$folder = GoogleDriveOAuth::clean_file_id( $created['id'] ?? '' );
		if ( '' === $folder || ! empty( $created['trashed'] ) || 'application/vnd.google-apps.folder' !== (string) ( $created['mimeType'] ?? '' ) ) {
			return new \WP_Error( 'certloom_google_folder_invalid', __( 'Google Drive did not create a valid Certloom backup folder.', 'certloom' ) );
		}
		$saved = GoogleDriveOAuth::save_folder_id( $folder );
		return is_wp_error( $saved ) ? $saved : $folder;
	}

	/** @return true|\WP_Error */
	private function verify_uploaded_metadata( array $metadata, $id, $size, $sha256, $md5, $kind ) {
		$id     = GoogleDriveOAuth::clean_file_id( $id );
		$remote = GoogleDriveOAuth::clean_file_id( $metadata['id'] ?? '' );
		$props  = isset( $metadata['appProperties'] ) && is_array( $metadata['appProperties'] ) ? $metadata['appProperties'] : array();
		if ( '' === $id || ! hash_equals( $id, $remote ) || ! empty( $metadata['trashed'] ) ) {
			return new \WP_Error( 'certloom_google_upload_identity_failed', __( 'Certloom could not verify the Google Drive backup identity after upload.', 'certloom' ) );
		}
		if ( (int) ( $metadata['size'] ?? -1 ) !== (int) $size || ! hash_equals( strtolower( (string) $md5 ), strtolower( (string) ( $metadata['md5Checksum'] ?? '' ) ) ) ) {
			return new \WP_Error( 'certloom_google_upload_transport_integrity_failed', __( 'Certloom removed a Google Drive backup whose uploaded bytes could not be verified.', 'certloom' ) );
		}
		if ( ! hash_equals( strtolower( (string) $sha256 ), strtolower( (string) ( $props['certloom_sha256'] ?? '' ) ) ) || (string) $size !== (string) ( $props['certloom_bytes'] ?? '' ) || ! hash_equals( (string) $kind, (string) ( $props['certloom_kind'] ?? '' ) ) ) {
			return new \WP_Error( 'certloom_google_upload_metadata_integrity_failed', __( 'Certloom removed a Google Drive backup whose Certloom integrity metadata did not match the local package.', 'certloom' ) );
		}
		return true;
	}
}
