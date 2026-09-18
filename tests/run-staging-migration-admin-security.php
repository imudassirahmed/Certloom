<?php
/** Static security boundary guards for the staging-only real migration rehearsal. */
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$view = file_get_contents( $root . '/admin/views/settings-manager.php' );
$plugin = file_get_contents( $root . '/certloom.php' );
$rehearsal = file_get_contents( $root . '/src/Infrastructure/Diagnostics/StagingMigrationRehearsal.php' );
$uninstall = file_get_contents( $root . '/uninstall.php' );
foreach ( compact( 'admin', 'view', 'plugin', 'rehearsal', 'uninstall' ) as $name => $source ) {
    if ( false === $source ) { throw new RuntimeException( 'Could not read ' . $name . ' source.' ); }
}
$required_admin = array(
    'handle_staging_migration_rehearsal',
    '$this->require_options_permission()',
    "check_admin_referer( 'certverify_run_staging_migration_rehearsal' )",
);
foreach ( $required_admin as $needle ) {
    if ( false === strpos( $admin, $needle ) ) { throw new RuntimeException( 'Missing staging rehearsal admin guard: ' . $needle ); }
}
if ( false === strpos( $plugin, "define( 'CERTLOOM_ALLOW_STAGING_MIGRATION_REHEARSAL', true )" ) ) {
    throw new RuntimeException( 'Staging migration rehearsal review opt-in is not enabled in the review-unlocked build.' );
}
foreach ( array( "'local', 'development', 'staging'", 'LegacyMigrationCoordinator', 'LegacyMigrationWriter', 'DomainMaintenanceLock', 'assert_domain_empty', 'idempotency_while_locked', 'Credential authority changed: NO', 'certloom_staging_rehearsal_version_changed' ) as $needle ) {
    if ( false === strpos( $rehearsal, $needle ) ) { throw new RuntimeException( 'Missing staging rehearsal safety/acceptance guard: ' . $needle ); }
}
if ( false !== strpos( $rehearsal, 'DomainCutoverCoordinator' ) || false !== strpos( $rehearsal, "update_option( DomainCutoverState::OPTION" ) ) {
    throw new RuntimeException( 'Staging rehearsal must never invoke cutover or change authority.' );
}
if ( false === strpos( $view, 'wp_nonce_field( \'certverify_run_staging_migration_rehearsal\'' ) || false === strpos( $view, 'CERTLOOM_ALLOW_STAGING_MIGRATION_REHEARSAL' ) ) {
    throw new RuntimeException( 'Staging rehearsal UI lost nonce/explicit-opt-in guidance.' );
}
foreach ( array( "delete_option( 'certloom_staging_migration_rehearsal' )", "delete_option( 'certloom_staging_migration_rehearsal_lock' )" ) as $needle ) {
    if ( false === strpos( $uninstall, $needle ) ) { throw new RuntimeException( 'Uninstall does not remove staging rehearsal operational evidence: ' . $needle ); }
}
echo "PASS  staging migration rehearsal is staging-only, explicit-opt-in, admin/nonce protected and cannot cut over authority\n\n1 test, 0 failures\n";
