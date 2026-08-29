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

	/*
	 * Migration/cutover metadata becomes part of durable credential lineage once
	 * official migration starts. If the administrator retains credential data,
	 * reinstall must not forget which source was authoritative or that migrated
	 * Series/Versions already exist. Runtime locks/jobs/proof evidence are still
	 * disposable and are cleared below.
	 */
	$migration_state = get_option( 'certloom_legacy_domain_migration_state', false );
	$migration_state_present = false !== $migration_state && '' !== $migration_state && array() !== $migration_state;
	$migration_status = is_array( $migration_state ) ? sanitize_key( (string) ( $migration_state['status'] ?? 'not_started' ) ) : ( $migration_state_present ? 'corrupt' : 'not_started' );
	$cutover_authority = get_option( 'certloom_domain_write_authority', null );
	$protected_authority_state = ! in_array( $cutover_authority, array( null, false, '', 'legacy' ), true );
	$cutover_receipt = get_option( 'certloom_domain_cutover_receipt', false );
	$protected_receipt_state = false !== $cutover_receipt && '' !== $cutover_receipt && array() !== $cutover_receipt;
	$preserve_domain_lineage = ! $delete_data && ( 'not_started' !== $migration_status || $protected_authority_state || $protected_receipt_state );

	global $wpdb;

	wp_clear_scheduled_hook( 'certverify_daily_expiry_check' );
	wp_clear_scheduled_hook( 'ecapla_certverify_continue_migrations' );
	wp_clear_scheduled_hook( 'certloom_run_job' );
	wp_clear_scheduled_hook( 'certloom_job_watchdog' );
	wp_clear_scheduled_hook( 'certloom_verification_analytics_prune' );
	wp_clear_scheduled_hook( 'certloom_run_scheduled_backup' );

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
				foreach ( array(
					'certloom_view_credentials', 'certloom_manage_credentials', 'certloom_issue_credentials',
					'certloom_manage_recipients', 'certloom_manage_programs', 'certloom_manage_issuers', 'certloom_manage_batches', 'certloom_manage_signers', 'certloom_manage_templates',
					'certloom_view_analytics', 'certloom_manage_integrations', 'certloom_manage_settings', 'certloom_manage_data_tools',
				) as $certloom_capability ) {
					unset( $stored_role['capabilities'][ $certloom_capability ] );
				}
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
	delete_option( 'certverify_public_rewrite_version' );
	if ( ! $preserve_domain_lineage ) {
		delete_option( 'certverify_data_generation' );
	}
	delete_option( 'certloom_domain_schema_health' );
	delete_option( 'certloom_domain_schema_lock' );
	if ( ! $preserve_domain_lineage ) {
		delete_option( 'certloom_legacy_domain_migration_state' );
		delete_option( 'certloom_domain_write_authority' );
		delete_option( 'certloom_domain_cutover_receipt' );
		delete_option( 'certloom_series_public_verification_ready' );
		delete_option( 'certloom_domain_schema_version' );
		delete_option( 'certloom_domain_generation' ); // Pre-1.5.4 development alias.
		delete_option( 'certloom_domain_data_generation' );
	}
	delete_option( 'certloom_domain_maintenance_lock' );
	delete_option( 'certloom_domain_restore_build' );
	delete_option( 'certloom_domain_restore_journal' );
	delete_option( 'certloom_domain_restore_receipts' );
	delete_option( 'certloom_job_retention_last_run' );
	delete_option( 'certloom_database_proof_lock' );
	delete_option( 'certloom_database_proof_last' );
	delete_option( 'certloom_domain_acceptance_proof_last' );
	delete_option( 'certloom_legacy_migration_preflight_proof' );
	delete_option( 'certloom_legacy_migration_preflight_proof_lock' );
	delete_option( 'certloom_legacy_migration_maintenance_owner' );
	delete_option( 'certloom_domain_cutover_owner' );
	delete_option( 'certloom_staging_migration_rehearsal' );
	delete_option( 'certloom_staging_migration_rehearsal_lock' );
	delete_option( 'certloom_staging_restore_rehearsal' );
	delete_option( 'certloom_staging_restore_rehearsal_lock' );
	delete_option( 'certloom_staging_cutover_rehearsal' );
	delete_option( 'certloom_staging_cutover_rehearsal_lock' );
	delete_option( 'certloom_backup_run_lock' );
	delete_option( 'certloom_backup_last_result' );

	/* DSN-02 personal Designer workspace state is non-artifact UI state and must not survive uninstall. */
	$designer_state_prefix = 'certloom_template_designer_state_' . get_current_blog_id() . '_';
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->usermeta,
			$wpdb->esc_like( $designer_state_prefix ) . '%'
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Removes only this site's Certloom-owned personal Designer workspace state.

	/* DSN-07 recovery checkpoints are also personal, non-authoritative editor state. */
	$designer_autosave_prefix = 'certloom_template_designer_autosave_' . get_current_blog_id() . '_';
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->usermeta,
			$wpdb->esc_like( $designer_autosave_prefix ) . '%'
		)
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Removes only this site's Certloom-owned Designer recovery checkpoints.

	$transient_prefixes = array(
		'_transient_ecapla_certverify_import_',
		'_transient_timeout_ecapla_certverify_import_',
		'_transient_ecapla_certverify_rl_',
		'_transient_timeout_ecapla_certverify_rl_',
		'_transient_ecapla_certverify_backup_',
		'_transient_timeout_ecapla_certverify_backup_',
		'_transient_ecapla_certverify_bulk_',
		'_transient_timeout_ecapla_certverify_bulk_',
		'_transient_certloom_import_map_',
		'_transient_timeout_certloom_import_map_',
		'_transient_certloom_domain_data_tools_notice_',
		'_transient_timeout_certloom_domain_data_tools_notice_',
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

	/*
	 * New-domain job/import/restore state is executable and may contain private
	 * registry material. It must not survive uninstall even when the customer
	 * chooses to retain durable credential data for a later reinstall.
	 */
	$database_proof_table = $wpdb->prefix . 'certloom_db_proof';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $database_proof_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes only Certloom's isolated database-proof scratch table.

	/*
	 * ANL-01 verification analytics are privacy-minimized operational aggregates,
	 * not credential truth. Remove them on uninstall even when credentials are
	 * retained so the documented 730-day retention ceiling cannot silently stop
	 * applying after the pruning cron disappears. Future analytics export work
	 * remains a separate explicit feature rather than changing uninstall semantics.
	 */
	$analytics_table = $wpdb->prefix . 'certloom_verification_analytics';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $analytics_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes only non-authoritative Certloom analytics aggregates.
	delete_option( 'certloom_verification_analytics_schema_version' );

	$ephemeral_domain_tables = array(
		$wpdb->prefix . 'certloom_jobs',
		$wpdb->prefix . 'certloom_import_rows',
		$wpdb->prefix . 'certloom_restore_map',
	);
	foreach ( $ephemeral_domain_tables as $ephemeral_domain_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $ephemeral_domain_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes only Certloom-owned resumable operational state.
	}

	$domain_temporary_tables = $wpdb->get_col(
		$wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$wpdb->esc_like( $wpdb->prefix . 'certloom_' ) . '%'
		)
	);
	foreach ( (array) $domain_temporary_tables as $domain_temporary_table ) {
		if ( preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . 'certloom_(?:rs|rm|rb)_[a-f0-9]{12}_[iprsnve]$/i', (string) $domain_temporary_table ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $domain_temporary_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes abandoned Certloom restore stage/rollback tables only.
		}
	}

	/* Best-effort cleanup of the two built-in private job directory locations. */
	$private_directories = array();
	if ( function_exists( 'get_temp_dir' ) ) {
		$private_directories[] = trailingslashit( wp_normalize_path( get_temp_dir() ) ) . 'certloom-private';
	}
	if ( defined( 'WP_CONTENT_DIR' ) ) {
		$private_directories[] = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . 'certloom-private';
	}
	foreach ( array_unique( $private_directories ) as $private_directory ) {
		if ( ! is_dir( $private_directory ) ) {
			continue;
		}
		$files = glob( trailingslashit( $private_directory ) . '*' );
		foreach ( (array) $files as $private_file ) {
			$base = basename( (string) $private_file );
			if ( is_file( $private_file ) && ( preg_match( '/^[a-f0-9]{32}-[a-z0-9_-]+-[a-f0-9]{48}\.data$/i', $base ) || in_array( $base, array( 'index.php', '.htaccess', 'web.config' ), true ) ) ) {
				@unlink( $private_file );
			}
		}
		@rmdir( $private_directory );
	}

	if ( ! $delete_data ) {
		return;
	}

	/* Explicit delete-data also removes persistent customer backup packages and their catalog. */
	$backup_store_file = __DIR__ . '/src/Infrastructure/Backup/LocalBackupStore.php';
	if ( file_exists( $backup_store_file ) ) {
		require_once $backup_store_file;
		$certloom_backup_store = new \Certloom\Infrastructure\Backup\LocalBackupStore();
		$certloom_backup_cleanup_ok = true;
		foreach ( $certloom_backup_store->list_backups() as $certloom_backup_entry ) {
			$certloom_backup_deleted = $certloom_backup_store->delete( $certloom_backup_entry['id'] );
			if ( is_wp_error( $certloom_backup_deleted ) ) { $certloom_backup_cleanup_ok = false; break; }
		}
		if ( $certloom_backup_cleanup_ok ) { delete_option( 'certloom_local_backup_catalog' ); }
	}
	delete_option( 'certloom_backup_schedule' );
	delete_option( 'certloom_backup_retention' );

	/* Explicit delete-data choice removes durable migration/cutover lineage too. */
	foreach ( array(
		'certloom_legacy_domain_migration_state', 'certloom_domain_write_authority', 'certloom_domain_cutover_receipt',
		'certloom_series_public_verification_ready', 'certloom_domain_schema_version', 'certloom_domain_generation', 'certloom_domain_data_generation'
	) as $certloom_domain_lineage_option ) {
		delete_option( $certloom_domain_lineage_option );
	}

	$table = $wpdb->prefix . 'certverify_certificates';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit opt-in uninstall cleanup of the plugin-owned table.

	$domain_tables = array(
		$wpdb->prefix . 'certloom_credential_events',
		$wpdb->prefix . 'certloom_credential_numbers',
		$wpdb->prefix . 'certloom_credential_versions',
		$wpdb->prefix . 'certloom_credential_series',
		$wpdb->prefix . 'certloom_template_assignments',
		$wpdb->prefix . 'certloom_template_revisions',
		$wpdb->prefix . 'certloom_templates',
		$wpdb->prefix . 'certloom_signer_assignments',
		$wpdb->prefix . 'certloom_batch_recipients',
		$wpdb->prefix . 'certloom_batches',
		$wpdb->prefix . 'certloom_signers',
		$wpdb->prefix . 'certloom_programs',
		$wpdb->prefix . 'certloom_issuers',
		$wpdb->prefix . 'certloom_recipients',
	);
	foreach ( $domain_tables as $domain_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $domain_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit opt-in cleanup of Certloom-owned next-generation domain data.
	}

	delete_option( 'certloom_domain_schema_version' );
	delete_option( 'certverify_fields' );
	delete_option( 'certverify_retired_fields' );
	delete_option( 'certverify_settings' );
	delete_option( 'certverify_number_sequence_next' );
	delete_option( 'certverify_onboarding' );
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
