<?php
namespace Certloom\Infrastructure\Backup;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Non-destructive restore-readiness evidence for one verified local backup. */
final class RestoreTest {
	const OPTION       = 'certloom_backup_restore_test_evidence';
	const MAX_EVIDENCE = 100;

	/** @return array<string,mixed>|\WP_Error */
	public static function run( $backup_id ) {
		$backup_id = strtolower( trim( (string) $backup_id ) );
		$store     = new LocalBackupStore();
		$resolved  = $store->resolve( $backup_id );
		if ( is_wp_error( $resolved ) ) { return $resolved; }

		$token = \Ecapla_CertVerify_Backup::analyze_file( (string) $resolved['path'] );
		if ( is_wp_error( $token ) ) { return $token; }
		$preview = \Ecapla_CertVerify_Backup::get_preview( $token );
		\Ecapla_CertVerify_Backup::delete_session( $token );
		if ( ! is_array( $preview ) ) {
			return new \WP_Error( 'certloom_restore_test_preview_failed', __( 'Certloom could read the backup but could not complete its non-destructive restore preview.', 'certloom' ) );
		}

		$evidence = array(
			'backup_id'        => $backup_id,
			'file_sha256'      => sanitize_text_field( (string) $resolved['file_sha256'] ),
			'tested_at'        => gmdate( 'c' ),
			'tested_ts'        => time(),
			'status'           => 'passed',
			'records'          => absint( $preview['records'] ?? $resolved['records'] ?? 0 ),
			'current_records'  => absint( $preview['current_records'] ?? 0 ),
			'conflicts'        => absint( $preview['conflicts'] ?? 0 ),
			'current_only'     => absint( $preview['current_only'] ?? 0 ),
			'format_version'   => absint( $resolved['format_version'] ?? 0 ),
			'source_authority' => 'current_registry',
		);
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) { $all = array(); }
		$all[ $backup_id ] = $evidence;
		uasort( $all, function ( $a, $b ) { return absint( $b['tested_ts'] ?? 0 ) <=> absint( $a['tested_ts'] ?? 0 ); } );
		$all = array_slice( $all, 0, self::MAX_EVIDENCE, true );
		if ( ! update_option( self::OPTION, $all, false ) && get_option( self::OPTION, array() ) !== $all ) {
			return new \WP_Error( 'certloom_restore_test_evidence_save_failed', __( 'The restore test passed, but Certloom could not save its local evidence record.', 'certloom' ) );
		}
		return $evidence;
	}

	/**
	 * Historical pass evidence is displayed only while the catalog still binds
	 * the backup ID to the same SHA-256. Page rendering intentionally does not
	 * re-hash every large backup; byte-consuming actions call resolve() and fully
	 * re-verify before use.
	 *
	 * @return array<string,mixed>
	 */
	public static function latest_for( $backup_id ) {
		$backup_id = strtolower( trim( (string) $backup_id ) );
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $backup_id ] ) || ! is_array( $all[ $backup_id ] ) ) { return array(); }
		$evidence = $all[ $backup_id ];
		$hash = strtolower( trim( (string) ( $evidence['file_sha256'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) ) { return array(); }
		foreach ( ( new LocalBackupStore() )->list_backups() as $entry ) {
			if ( hash_equals( $backup_id, (string) ( $entry['id'] ?? '' ) )
				&& hash_equals( $hash, (string) ( $entry['file_sha256'] ?? '' ) ) ) {
				return $evidence;
			}
		}
		return array();
	}

	public static function forget( $backup_id ) {
		$backup_id = strtolower( trim( (string) $backup_id ) );
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $backup_id ] ) ) { return true; }
		unset( $all[ $backup_id ] );
		return update_option( self::OPTION, $all, false ) || get_option( self::OPTION, array() ) === $all;
	}
}
