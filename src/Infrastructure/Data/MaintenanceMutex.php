<?php
namespace Certloom\Infrastructure\Data;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Serializes maintenance transitions across PHP requests, including table DDL. */
final class MaintenanceMutex {
	/** MySQL session locks survive DDL commits and are released when a worker disconnects. */
	public static function run( callable $callback ) {
		global $wpdb;
		$name = 'certloom-maint-' . substr( hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->options ), 0, 48 );
		$held = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( '1' !== (string) $held ) {
			return '0' === (string) $held
				? new \WP_Error( 'certloom_maintenance_busy', __( 'Another Certloom maintenance worker is finishing a database operation. Please retry shortly.', 'certloom' ) )
				: new \WP_Error( 'certloom_maintenance_mutex_unavailable', __( 'Certloom could not establish exclusive database maintenance access. No recovery changes were started.', 'certloom' ) );
		}
		try {
			// A request may have read these before a different worker changed them.
			foreach ( array( 'certloom_domain_maintenance_lock', 'certloom_domain_restore_build', 'certloom_domain_restore_journal', 'certloom_domain_restore_receipts', 'certloom_domain_data_generation', 'certverify_fields', 'certverify_retired_fields', 'certverify_settings', 'alloptions', 'notoptions' ) as $key ) {
				wp_cache_delete( $key, 'options' );
			}
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}
}
