<?php
/** Static guard: retained future-domain credential data must retain migration/cutover lineage across uninstall/reinstall. */
$source = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
if ( false === $source ) { throw new RuntimeException( 'Could not read uninstall.php' ); }
$required = array(
    '$preserve_domain_lineage = ! $delete_data',
    '$migration_state_present',
    '$protected_authority_state',
    '$protected_receipt_state',
    "\$migration_state_present ? 'corrupt' : 'not_started'",
    "get_option( 'certloom_legacy_domain_migration_state'",
    "get_option( 'certloom_domain_write_authority'",
    "get_option( 'certloom_domain_cutover_receipt'",
    'if ( ! $preserve_domain_lineage )',
    "delete_option( 'certloom_legacy_migration_maintenance_owner' )",
    "delete_option( 'certloom_domain_cutover_owner' )",
    "delete_option( 'certloom_staging_migration_rehearsal' )",
    "delete_option( 'certloom_staging_migration_rehearsal_lock' )",
    'Explicit delete-data choice removes durable migration/cutover lineage too.',
);
foreach ( $required as $needle ) {
    if ( false === strpos( $source, $needle ) ) { throw new RuntimeException( 'Missing domain lineage uninstall guard: ' . $needle ); }
}
$conditional = substr( $source, strpos( $source, 'if ( ! $preserve_domain_lineage )' ), 1500 );
foreach ( array(
    "delete_option( 'certverify_data_generation' )",
    "delete_option( 'certloom_legacy_domain_migration_state' )",
    "delete_option( 'certloom_domain_write_authority' )",
    "delete_option( 'certloom_domain_cutover_receipt' )",
    "delete_option( 'certloom_series_public_verification_ready' )",
    "delete_option( 'certloom_domain_schema_version' )",
    "delete_option( 'certloom_domain_data_generation' )",
) as $needle ) {
    if ( false === strpos( $conditional, $needle ) ) { throw new RuntimeException( 'Durable lineage option is no longer inside retain-data preservation guard: ' . $needle ); }
}
if ( false === strpos( $source, 'if ( ! $delete_data ) {' ) || false === strpos( $source, 'return;' ) ) {
    throw new RuntimeException( 'Uninstall no longer preserves durable credential tables when delete-data is off.' );
}
echo "PASS  uninstall preserves durable migration/cutover lineage whenever retained future-domain credential data could exist\n\n1 test, 0 failures\n";
