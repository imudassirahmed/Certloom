<?php
namespace Certloom\Infrastructure\Data;

use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Short legacy commands share native maintenance admission and checked savepoints. */
final class LegacyWriteGuard {
	private static $touched_options = array();

	/** Remember dynamic purge cursors for any enclosing transaction's rollback. */
	public static function invalidate_option( $key ) {
		self::$touched_options[ (string) $key ] = true;
		DatabaseOptions::invalidate( array( (string) $key ) );
	}
	public static function run( callable $callback, $registry = false ) {
		$result = ( new Transaction() )->run( static function () use ( $callback, $registry ) {
			$allowed = \Ecapla_CertVerify_Backup::assert_writes_allowed();
			if ( is_wp_error( $allowed ) ) { return $allowed; }
			if ( $registry ) {
				global $wpdb;
				$wpdb->last_error = '';
				$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', \Ecapla_CertVerify_Database::table_name() ) );
				if ( '' !== (string) $wpdb->last_error || 'innodb' !== strtolower( (string) $engine ) ) {
					return new \WP_Error( 'certloom_legacy_engine_unsupported', __( 'Certloom needs an InnoDB registry table to protect legacy certificate updates. No changes were started.', 'certloom' ) );
				}
			}
			self::invalidate_cache();
			$value = $callback();
			return false === $value ? new \WP_Error( 'certloom_legacy_update_failed', __( 'Certloom could not save the registry change safely.', 'certloom' ) ) : $value;
		} );
		if ( is_wp_error( $result ) ) {
			self::invalidate_cache();
		}
		return $result;
	}

	/** An option API return is not evidence that the intended value reached storage. */
	public static function verify_option( $key, $value ) {
		$stored = DatabaseOptions::read( array( $key ) );
		return ! is_wp_error( $stored ) && $stored[ $key ]['exists'] && $value === $stored[ $key ]['value'];
	}

	/** Shared with outer transaction rollback so successful nested commands leave no stale cache. */
	public static function invalidate_cache() {
		$keys = array( 'certverify_data_generation', 'certverify_fields', 'certverify_retired_fields', 'certverify_settings', 'certverify_number_sequence_next', 'certverify_legacy_expired_review_count', 'certloom_domain_write_authority', 'certverify_hash_migration_cursor', 'certverify_hash_migration_complete', 'certverify_orphan_migration_cursor', 'certverify_orphan_migration_complete', 'certverify_130_status_review_required', 'certverify_legacy_review_refresh_needed' );
		DatabaseOptions::invalidate( array_merge( $keys, array_keys( self::$touched_options ) ) );
		if ( class_exists( 'Ecapla_CertVerify_Fields', false ) && method_exists( 'Ecapla_CertVerify_Fields', 'reset_runtime_cache' ) ) { \Ecapla_CertVerify_Fields::reset_runtime_cache(); }
	}
}
