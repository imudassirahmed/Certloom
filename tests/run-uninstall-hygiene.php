<?php
/** Static guard for uninstall ownership and ephemeral cleanup coverage. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$source = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
if ( false === $source ) { throw new RuntimeException( 'Could not read uninstall.php' ); }
$required = array(
	"\$wpdb->prefix . 'certloom_jobs'",
	"\$wpdb->prefix . 'certloom_import_rows'",
	"\$wpdb->prefix . 'certloom_restore_map'",
	"\$wpdb->prefix . 'certloom_signer_assignments'",
	"\$wpdb->prefix . 'certloom_batch_recipients'",
	"\$wpdb->prefix . 'certloom_batches'",
	"\$wpdb->prefix . 'certloom_signers'",
	"'certloom_manage_batches'",
	"'certloom_manage_signers'",
	"delete_option( 'certloom_domain_data_generation' )",
	"delete_option( 'certloom_domain_write_fence' )",
	"delete_option( 'certloom_job_retention_last_run' )",
	"delete_option( 'certloom_database_proof_lock' )",
	"delete_option( 'certloom_database_proof_last' )",
	"wp_clear_scheduled_hook( 'certloom_run_scheduled_backup' )",
	"wp_clear_scheduled_hook( 'certloom_google_drive_copy_backup' )",
	"delete_option( 'certloom_backup_run_lock' )",
	"delete_option( 'certloom_backup_last_result' )",
	"delete_option( 'certloom_google_drive_secrets' )",
	"delete_option( 'certloom_google_drive_config' )",
	"delete_option( 'certloom_google_drive_last_result' )",
	"'_transient_certloom_gdrive_oauth_'",
	"delete_option( 'certloom_google_drive_retention' )",
	"delete_option( 'certloom_remote_backup_catalog' )",
	"certloom_local_backup_catalog",
	"delete_option( 'certloom_backup_schedule' )",
	"delete_option( 'certloom_backup_retention' )",
	"\$wpdb->prefix . 'certloom_db_proof'",
	"'_transient_certloom_import_map_'",
	"'_transient_certloom_domain_data_tools_notice_'",
	"certloom_(?:rs|rm|rb)_",
);
foreach ( $required as $needle ) {
	if ( false === strpos( $source, $needle ) ) { throw new RuntimeException( 'Missing uninstall cleanup guard: ' . $needle ); }
}
if ( false === strpos( $source, 'if ( ! $delete_data )' ) ) { throw new RuntimeException( 'Permanent data preserve-by-default guard was removed.' ); }
$guard = strpos( $source, 'if ( ! $delete_data )' );
if ( strpos( $source, "delete_option( 'certloom_google_drive_secrets' )" ) > $guard ) { throw new RuntimeException( 'Google Drive OAuth secrets must be removed even when customer data is retained.' ); }
if ( strpos( $source, "delete_option( 'certloom_remote_backup_catalog' )" ) < $guard ) { throw new RuntimeException( 'Remote backup history must be preserved unless delete-data is explicitly enabled.' ); }
echo "PASS  uninstall removes operational 1.5.4 state while retaining permanent credential data by default\n";
echo "\n1 test, 0 failures\n";
