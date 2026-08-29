<?php
namespace Certloom\Infrastructure\Backup;

/**
 * Creates one current-authority safety snapshot before a reviewed destructive
 * operation. Receipts are short-lived process state and never credential truth.
 */
final class SafetySnapshot {
	const RECEIPT_PREFIX    = 'certloom_safety_snapshot_';
	const RECEIPT_TTL       = 12 * HOUR_IN_SECONDS;
	const OPTION_RETENTION  = 'certloom_safety_backup_retention';
	const DEFAULT_RETENTION = 10;

	/** @return array<string,mixed>|\WP_Error */
	public static function before( $operation, $operation_key ) {
		$operation = sanitize_key( (string) $operation );
		$allowed   = array( 'restore', 'csv_import', 'bulk_delete', 'bulk_change', 'field_purge' );
		if ( ! in_array( $operation, $allowed, true ) ) {
			return new \WP_Error( 'certloom_safety_operation_invalid', __( 'Certloom refused an unknown safety-snapshot operation.', 'certloom' ) );
		}
		$operation_key = trim( (string) $operation_key );
		if ( '' === $operation_key || strlen( $operation_key ) > 512 ) {
			return new \WP_Error( 'certloom_safety_operation_key_invalid', __( 'Certloom could not identify the destructive operation safely.', 'certloom' ) );
		}

		$site_id     = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$fingerprint = hash( 'sha256', $operation . '|' . $operation_key . '|' . $site_id );
		$transient   = self::RECEIPT_PREFIX . $fingerprint;
		$cached      = get_transient( $transient );
		if ( is_array( $cached ) && ! empty( $cached['backup_id'] ) ) {
			$resolved    = ( new LocalBackupStore() )->resolve( (string) $cached['backup_id'] );
			$cached_hash = strtolower( trim( (string) ( $cached['file_sha256'] ?? '' ) ) );
			if ( ! is_wp_error( $resolved )
				&& 'safety' === (string) ( $resolved['purpose'] ?? '' )
				&& 1 === preg_match( '/^[a-f0-9]{64}$/', $cached_hash )
				&& hash_equals( (string) ( $resolved['file_sha256'] ?? '' ), $cached_hash ) ) {
				return $cached;
			}
			delete_transient( $transient );
		}

		/*
		 * Rotate already-expired automatic safety snapshots before creation so an
		 * otherwise-recoverable full catalog cannot block the next protected
		 * operation. Manual backups are never touched by this cleanup.
		 */
		( new LocalBackupStore() )->prune_safety( self::retention() );

		$result = ScheduledBackup::create_now( 'safety' );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'certloom_safety_snapshot_failed',
				sprintf(
					/* translators: %s: local backup error. */
					__( 'Certloom stopped the destructive operation because its safety snapshot could not be created: %s', 'certloom' ),
					$result->get_error_message()
				)
			);
		}

		$receipt = array(
			'operation'   => $operation,
			'backup_id'   => sanitize_text_field( (string) ( $result['id'] ?? '' ) ),
			'file_sha256' => sanitize_text_field( (string) ( $result['file_sha256'] ?? '' ) ),
			'created_ts'  => time(),
			'created_at'  => gmdate( 'c' ),
		);
		if ( '' === $receipt['backup_id'] || 1 !== preg_match( '/^[a-f0-9]{64}$/', $receipt['file_sha256'] ) ) {
			return new \WP_Error( 'certloom_safety_snapshot_receipt_invalid', __( 'Certloom created a backup but refused to continue because its safety receipt was incomplete.', 'certloom' ) );
		}
		set_transient( $transient, $receipt, self::RECEIPT_TTL );

		$pruned = ( new LocalBackupStore() )->prune_safety( self::retention() );
		if ( is_wp_error( $pruned ) ) {
			// The new snapshot is valid; keep the receipt so retry cannot create a second snapshot.
			$receipt['retention_warning'] = sanitize_text_field( $pruned->get_error_code() );
			set_transient( $transient, $receipt, self::RECEIPT_TTL );
		}
		return $receipt;
	}

	public static function retention() {
		$value = absint( get_option( self::OPTION_RETENTION, self::DEFAULT_RETENTION ) );
		return max( 3, min( 30, $value ?: self::DEFAULT_RETENTION ) );
	}
}
