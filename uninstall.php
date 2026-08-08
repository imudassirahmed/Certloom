<?php
/**
 * Certloom uninstall routine.
 *
 * Data is removed only when an administrator explicitly enabled
 * "Delete all plugin data on uninstall" before deleting the plugin.
 *
 * @package EcaplaCertloom
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove Certloom's custom table and bounded plugin-owned transient rows.

/**
 * Removes executable state and, when opted in, stored data for the current site.
 */
function ecapla_certverify_uninstall_site() {
	$settings    = get_option( 'certverify_settings', array() );
	$delete_data = ! empty( $settings['delete_data_on_uninstall'] );

	global $wpdb;

	wp_clear_scheduled_hook( 'certverify_daily_expiry_check' );
	wp_clear_scheduled_hook( 'ecapla_certverify_continue_migrations' );

	$wp_roles        = wp_roles();
	$roles_option    = $wp_roles ? $wp_roles->role_key : '';
	$stored_roles    = $roles_option ? get_option( $roles_option, array() ) : array();
	$manager_users   = get_users(
		array(
			'role'   => 'certverify_manager',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	$preserve_manager = ! empty( $manager_users ) && isset( $stored_roles['certverify_manager'] );

	if ( is_array( $stored_roles ) && $roles_option ) {
		foreach ( $stored_roles as &$stored_role ) {
			if ( isset( $stored_role['capabilities'] ) && is_array( $stored_role['capabilities'] ) ) {
				unset( $stored_role['capabilities']['manage_certverify'] );
			}
		}
		unset( $stored_role );
		if ( isset( $stored_roles['certverify_manager'] ) && ! $preserve_manager ) {
			unset( $stored_roles['certverify_manager'] );
		}
		$saved_roles = update_option( $roles_option, $stored_roles );
		$role_cleanup_ok = $saved_roles || $stored_roles === get_option( $roles_option, array() );
		if ( ! $role_cleanup_ok ) {
			update_option( 'certverify_uninstall_role_cleanup_failed', 1, false );
		} else {
			delete_option( 'certverify_uninstall_role_cleanup_failed' );
			if ( method_exists( $wp_roles, 'for_site' ) ) {
				$wp_roles->for_site( get_current_blog_id() );
			}
		}
	}

	if ( $preserve_manager ) {
		$preserve_saved = update_option( 'certverify_manager_role_preserved', 1, false );
		if ( ! $preserve_saved && 1 !== (int) get_option( 'certverify_manager_role_preserved', 0 ) ) {
			update_option( 'certverify_uninstall_role_cleanup_failed', 1, false );
		}
	} elseif ( ! delete_option( 'certverify_manager_role_preserved' ) && false !== get_option( 'certverify_manager_role_preserved', false ) ) {
		update_option( 'certverify_uninstall_role_cleanup_failed', 1, false );
	}

	// Executable lifecycle state must never survive uninstall, even when the
	// administrator chooses to retain certificate records and settings.
	delete_option( 'certverify_capability_schema_version' );
	delete_option( 'certverify_expiry_cron_retired' );
	delete_option( 'certverify_last_expiry_check' );
	delete_option( 'certverify_130_status_review_required' );
	delete_option( 'certverify_legacy_review_refresh_needed' );
	delete_option( 'certverify_schema_health' );
	delete_option( 'certverify_schema_migration_lock' );
	delete_option( 'certverify_field_schema_repair_needed' );
	delete_option( 'certverify_hash_migration_cursor' );
	delete_option( 'certverify_hash_migration_complete' );
	delete_option( 'certverify_orphan_migration_cursor' );
	delete_option( 'certverify_orphan_migration_complete' );
	delete_option( 'certverify_capability_health' );
	delete_option( 'certverify_restore_cleanup_needed' );
	delete_option( 'certverify_restore_journal' );
	delete_option( 'certverify_restore_builds' );
	delete_option( 'certverify_restore_maintenance_lock' );
	delete_option( 'certverify_data_generation' );

	$transient_prefixes = array(
		'_transient_ecapla_certverify_import_',
		'_transient_timeout_ecapla_certverify_import_',
		'_transient_ecapla_certverify_rl_',
		'_transient_timeout_ecapla_certverify_rl_',
		'_transient_ecapla_certverify_backup_',
		'_transient_timeout_ecapla_certverify_backup_',
	);

	foreach ( $transient_prefixes as $prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( $prefix ) . '%'
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared inline.
	}

	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'certverify_rl_' ) . '%'
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Removes plugin-owned atomic rate-limit buckets.


	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'certverify_import_lock_' ) . '%'
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Removes plugin-owned import locks.


	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'certverify_restore_lock_' ) . '%'
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Removes plugin-owned resumable restore locks.

	/* Resumable retired-field purge cursors are executable lifecycle state. */
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'certverify_purge_' ) . '%'
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared inline.

	$temporary_tables = $wpdb->get_col(
		$wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$wpdb->esc_like( $wpdb->prefix . 'certverify_' ) . '%'
		)
	);
	foreach ( (array) $temporary_tables as $temporary_table ) {
		if ( preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'certverify_(?:stage|rollback|failed)_[a-z0-9]+$/i', (string) $temporary_table ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $temporary_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	if ( ! $delete_data ) {
		return;
	}

	$table = $wpdb->prefix . 'certverify_certificates';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit opt-in uninstall cleanup of the plugin-owned table.

	delete_option( 'certverify_fields' );
	delete_option( 'certverify_retired_fields' );
	delete_option( 'certverify_settings' );
	delete_option( 'certverify_schema_version' );
	delete_option( 'certverify_db_version' );
	delete_option( 'certverify_legacy_expired_review_count' );
}

/**
 * Runs uninstall cleanup for one site or every site in a multisite network.
 */
function ecapla_certverify_uninstall_all_sites() {
	if ( ! is_multisite() ) {
		ecapla_certverify_uninstall_site();
		return;
	}

	$number = 100;
	$offset = 0;
	do {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $number,
				'offset' => $offset,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			ecapla_certverify_uninstall_site();
			restore_current_blog();
		}
		$offset += count( $site_ids );
	} while ( count( $site_ids ) === $number );
}

ecapla_certverify_uninstall_all_sites();
delete_site_option( 'certverify_provisioning_failures' );

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
