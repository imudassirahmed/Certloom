<?php
namespace Certloom\Infrastructure\Backup;

/** Google Drive orchestration around verified local Certloom backups. */
final class GoogleDriveBackup {
	const RETENTION_OPTION   = 'certloom_google_drive_retention';
	const LAST_RESULT_OPTION = 'certloom_google_drive_last_result';
	const COPY_HOOK          = 'certloom_google_drive_copy_backup';

	/** @return array<string,mixed> */
	public static function state() {
		$config = GoogleDriveOAuth::config();
		return array(
			'connected'   => GoogleDriveOAuth::is_connected(),
			'enabled'     => ! empty( $config['enabled'] ),
			'client_id'   => (string) ( $config['client_id'] ?? '' ),
			'connected_ts'=> absint( $config['connected_ts'] ?? 0 ),
			'retention'   => self::retention(),
			'last_result' => self::last_result(),
			'remote'      => ( new RemoteBackupCatalog() )->all(),
			'redirect_uri'=> GoogleDriveOAuth::redirect_uri(),
		);
	}

	/** @return true|\WP_Error */
	public static function save_settings( $enabled, $retention ) {
		$retention = max( 1, min( 60, absint( $retention ) ?: 14 ) );
		if ( $enabled ) {
			if ( ! GoogleDriveOAuth::is_connected() ) { return new \WP_Error( 'certloom_google_not_connected', __( 'Connect Google Drive before enabling automatic Drive copies.', 'certloom' ) ); }
			$probe = ( new GoogleDriveDestination() )->test_connection();
			if ( is_wp_error( $probe ) ) { return $probe; }
		}
		$old = self::retention();
		$saved = update_option( self::RETENTION_OPTION, $retention, false );
		if ( ! $saved && $retention !== absint( get_option( self::RETENTION_OPTION, 14 ) ) ) { return new \WP_Error( 'certloom_google_retention_save_failed', __( 'Certloom could not save the Google Drive retention setting.', 'certloom' ) ); }
		$enabled_saved = GoogleDriveOAuth::set_enabled( (bool) $enabled );
		if ( is_wp_error( $enabled_saved ) ) { update_option( self::RETENTION_OPTION, $old, false ); return $enabled_saved; }
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function upload_local( $local_backup_id, ?GoogleDriveDestination $destination = null, ?GoogleDriveClient $client = null, ?RemoteBackupCatalog $catalog = null ) {
		$store = new LocalBackupStore();
		$local = $store->resolve( $local_backup_id );
		if ( is_wp_error( $local ) ) { return $local; }
		if ( 'safety' === (string) $local['purpose'] ) {
			return new \WP_Error( 'certloom_google_safety_local_only', __( 'Safety snapshots stay in private local storage so destructive operations never depend on Google Drive availability.', 'certloom' ) );
		}
		if ( ! GoogleDriveOAuth::is_connected() ) { return new \WP_Error( 'certloom_google_not_connected', __( 'Google Drive is not connected to Certloom.', 'certloom' ) ); }
		$catalog = $catalog ?: new RemoteBackupCatalog();
		$entry = $catalog->find_exact_local( $local['id'], $local['file_sha256'] );
		if ( $entry && 'verified' === $entry['status'] ) { return $entry; }
		if ( ! $entry ) {
			$client = $client ?: new GoogleDriveClient();
			$file_id = $client->generate_file_id();
			if ( is_wp_error( $file_id ) ) { self::save_last_result( $file_id, $local ); return $file_id; }
			$entry = $catalog->reserve( $local, $file_id );
			if ( is_wp_error( $entry ) ) { self::save_last_result( $entry, $local ); return $entry; }
		}
		$local['remote_file_id'] = $entry['remote_file_id'];
		$destination = $destination ?: new GoogleDriveDestination( $client ?: null );
		$remote = $destination->upload( $local );
		if ( is_wp_error( $remote ) ) {
			$catalog->mark_failed( $entry['id'], $remote->get_error_message() );
			self::save_last_result( $remote, $local );
			return $remote;
		}
		$verified = $catalog->mark_verified( $entry['id'], $remote );
		if ( is_wp_error( $verified ) ) { self::save_last_result( $verified, $local ); return $verified; }
		self::save_last_result( $verified, $local );
		return $verified;
	}

	public static function init() { add_action( self::COPY_HOOK, array( __CLASS__, 'run_queued_copy' ), 10, 2 ); }
	public static function deactivate() { wp_clear_scheduled_hook( self::COPY_HOOK ); }

	/** Queue a secondary Drive copy after local backup completion. @return true|\WP_Error */
	public static function queue_scheduled_local( array $local_entry ) {
		$config = GoogleDriveOAuth::config();
		if ( empty( $config['enabled'] ) || ! GoogleDriveOAuth::is_connected() || 'scheduled' !== (string) ( $local_entry['purpose'] ?? '' ) ) { return true; }
		$id = strtolower( trim( (string) ( $local_entry['id'] ?? '' ) ) );
		$sha = strtolower( trim( (string) ( $local_entry['file_sha256'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
			$error = new \WP_Error( 'certloom_google_queue_metadata_invalid', __( 'Certloom refused invalid local backup metadata before scheduling the Google Drive copy.', 'certloom' ) );
			self::save_last_result( $error, $local_entry ); return $error;
		}
		$args = array( $id, $sha );
		if ( wp_next_scheduled( self::COPY_HOOK, $args ) ) { return true; }
		$scheduled = wp_schedule_single_event( time() + 10, self::COPY_HOOK, $args, true );
		if ( is_wp_error( $scheduled ) || ! $scheduled ) {
			$error = is_wp_error( $scheduled ) ? $scheduled : new \WP_Error( 'certloom_google_queue_failed', __( 'The local backup completed, but Certloom could not schedule its Google Drive copy.', 'certloom' ) );
			self::save_last_result( $error, $local_entry ); return $error;
		}
		return true;
	}

	/** Execute one queued Drive copy only when the exact local backup still matches. */
	public static function run_queued_copy( $local_backup_id, $expected_sha256 ) {
		$resolved = ( new LocalBackupStore() )->resolve( $local_backup_id );
		if ( is_wp_error( $resolved ) ) { self::save_last_result( $resolved, array( 'id' => $local_backup_id, 'purpose' => 'scheduled' ) ); return $resolved; }
		$expected_sha256 = strtolower( trim( (string) $expected_sha256 ) );
		if ( 'scheduled' !== (string) $resolved['purpose'] || 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_sha256 ) || ! hash_equals( $expected_sha256, (string) $resolved['file_sha256'] ) ) {
			$error = new \WP_Error( 'certloom_google_queued_backup_changed', __( 'Certloom refused a queued Google Drive copy because the local backup identity no longer matches.', 'certloom' ) );
			self::save_last_result( $error, $resolved ); return $error;
		}
		return self::after_scheduled_local( $resolved );
	}

	/** Non-blocking remote copy hook for a successful scheduled local backup. */
	public static function after_scheduled_local( array $local_entry, ?GoogleDriveDestination $destination = null, ?GoogleDriveClient $client = null, ?RemoteBackupCatalog $catalog = null ) {
		$config = GoogleDriveOAuth::config();
		if ( empty( $config['enabled'] ) || ! GoogleDriveOAuth::is_connected() || 'scheduled' !== (string) ( $local_entry['purpose'] ?? '' ) ) { return true; }
		$result = self::upload_local( $local_entry['id'] ?? '', $destination, $client, $catalog );
		if ( is_wp_error( $result ) ) { return $result; }
		$catalog = $catalog ?: new RemoteBackupCatalog();
		$destination = $destination ?: new GoogleDriveDestination( $client ?: null );
		$pruned = $catalog->prune_scheduled( self::retention(), $destination );
		if ( is_wp_error( $pruned ) ) { self::save_last_result( $pruned, $local_entry ); return $pruned; }
		return $result;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function download_to_local( $remote_id, ?GoogleDriveDestination $destination = null, ?RemoteBackupCatalog $catalog = null, ?LocalBackupStore $store = null ) {
		$catalog = $catalog ?: new RemoteBackupCatalog();
		$entry = $catalog->get( $remote_id );
		if ( ! $entry || 'verified' !== $entry['status'] ) { return new \WP_Error( 'certloom_remote_not_verified', __( 'Only a verified Google Drive backup can be downloaded into Certloom.', 'certloom' ) ); }
		$store = $store ?: new LocalBackupStore();
		foreach ( array( $entry['recovered_local_backup_id'] ?? '', $entry['local_backup_id'] ?? '' ) as $candidate_local_id ) {
			if ( '' === (string) $candidate_local_id ) { continue; }
			$existing = $store->resolve( $candidate_local_id );
			if ( is_array( $existing ) && hash_equals( (string) $entry['file_sha256'], (string) $existing['file_sha256'] ) ) { return $existing; }
		}
		$destination = $destination ?: new GoogleDriveDestination();
		$temp = $destination->download( $entry );
		if ( is_wp_error( $temp ) ) { return $temp; }
		$token = \Ecapla_CertVerify_Backup::analyze_file( $temp );
		if ( is_wp_error( $token ) ) { @unlink( $temp ); return $token; }
		$preview = \Ecapla_CertVerify_Backup::get_preview( $token );
		\Ecapla_CertVerify_Backup::delete_session( $token );
		if ( ! is_array( $preview ) ) { @unlink( $temp ); return new \WP_Error( 'certloom_google_restore_analysis_failed', __( 'Certloom downloaded the Google Drive backup but could not verify it through the restore analyzer.', 'certloom' ) ); }
		$metadata = $entry;
		$metadata['generated_at'] = sanitize_text_field( (string) ( $preview['generated_at'] ?? $entry['created_at'] ?? '' ) );
		$result = $store->store( $temp, $metadata, 'manual' );
		@unlink( $temp );
		if ( is_wp_error( $result ) ) { return $result; }
		$bound = $catalog->bind_recovered_local( $entry['id'], $result );
		return is_wp_error( $bound ) ? $bound : $result;
	}

	/** @return true|\WP_Error */
	public static function delete_remote( $remote_id ) {
		$catalog = new RemoteBackupCatalog();
		$entry = $catalog->get( $remote_id );
		if ( ! $entry ) { return new \WP_Error( 'certloom_remote_not_found', __( 'That Google Drive backup is not in Certloom’s remote backup history.', 'certloom' ) ); }
		$deleted = ( new GoogleDriveDestination() )->delete( $entry );
		if ( is_wp_error( $deleted ) ) { return $deleted; }
		return $catalog->forget( $entry['id'] );
	}

	/** @return true|\WP_Error */
	public static function test_connection() { return ( new GoogleDriveDestination() )->test_connection(); }
	public static function retention() { return max( 1, min( 60, absint( get_option( self::RETENTION_OPTION, 14 ) ) ?: 14 ) ); }
	public static function last_result() { $v = get_option( self::LAST_RESULT_OPTION, array() ); return is_array( $v ) ? $v : array(); }

	private static function save_last_result( $result, array $local = array() ) {
		$payload = array(
			'status'          => is_wp_error( $result ) ? 'failed' : 'success',
			'ran_at'          => gmdate( 'c' ),
			'ran_ts'          => time(),
			'local_backup_id' => sanitize_text_field( (string) ( $local['id'] ?? '' ) ),
			'purpose'         => sanitize_key( (string) ( $local['purpose'] ?? '' ) ),
			'message'         => is_wp_error( $result ) ? sanitize_text_field( $result->get_error_message() ) : __( 'Google Drive copy completed and verified.', 'certloom' ),
		);
		if ( is_array( $result ) ) { $payload['remote_backup_id'] = sanitize_text_field( (string) ( $result['id'] ?? '' ) ); }
		update_option( self::LAST_RESULT_OPTION, $payload, false );
	}
}
