<?php
/** Static guard for uninstall ownership and ephemeral cleanup coverage. */
$source = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
if ( false === $source ) { throw new RuntimeException( 'Could not read uninstall.php' ); }
$required = array(
	"\$wpdb->prefix . 'certloom_jobs'",
	"\$wpdb->prefix . 'certloom_import_rows'",
	"\$wpdb->prefix . 'certloom_restore_map'",
	"delete_option( 'certloom_domain_data_generation' )",
	"delete_option( 'certloom_job_retention_last_run' )",
	"delete_option( 'certloom_database_proof_lock' )",
	"delete_option( 'certloom_database_proof_last' )",
	"\$wpdb->prefix . 'certloom_db_proof'",
	"'_transient_certloom_import_map_'",
	"'_transient_certloom_domain_data_tools_notice_'",
	"certloom_(?:rs|rm|rb)_",
);
foreach ( $required as $needle ) {
	if ( false === strpos( $source, $needle ) ) { throw new RuntimeException( 'Missing uninstall cleanup guard: ' . $needle ); }
}
if ( false === strpos( $source, 'if ( ! $delete_data )' ) ) { throw new RuntimeException( 'Permanent data preserve-by-default guard was removed.' ); }
echo "PASS  uninstall removes operational 1.5.4 state while retaining permanent credential data by default\n";
echo "\n1 test, 0 failures\n";
