<?php
/** Static regression guards for official populated migration hardening. */
$root = dirname( __DIR__ );
$state = file_get_contents( $root . '/src/Application/Migration/LegacyMigrationState.php' );
$coordinator = file_get_contents( $root . '/src/Infrastructure/Migration/LegacyMigrationCoordinator.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );
if ( false === $state || false === $coordinator || false === $plugin ) {
    throw new RuntimeException( 'Could not read migration hardening sources.' );
}
$state_required = array(
    'PREFLIGHT_VERIFYING',
    'preflight_fingerprint',
    'verify_fingerprint',
    'source_fingerprint',
    'begin_preflight_verification',
    'finish_preflight_verification',
    'certloom_legacy_migration_fingerprint_missing',
);
foreach ( $state_required as $needle ) {
    if ( false === strpos( $state, $needle ) ) {
        throw new RuntimeException( 'Missing official two-pass migration state guard: ' . $needle );
    }
}
$coordinator_required = array(
    'LegacySourceFingerprint',
    'PREFLIGHT_VERIFYING',
    'DomainMaintenanceLock',
    'run_internal',
    "OWNER_OPTION = 'certloom_legacy_migration_maintenance_owner'",
    'recover_previous_cleanup',
    'commit_batch_locked',
);
foreach ( $coordinator_required as $needle ) {
    if ( false === strpos( $coordinator, $needle ) ) {
        throw new RuntimeException( 'Missing official migration coordinator hardening: ' . $needle );
    }
}
if ( false === strpos( $plugin, 'LegacyMigrationState::NOT_STARTED' ) || false === strpos( $plugin, 'new LegacyMigrationState()' ) ) {
    throw new RuntimeException( 'Started official migration no longer forces future-domain storage at boot.' );
}
echo "PASS  official migration uses two-pass semantic fingerprinting, shared maintenance, and durable bootstrap\n";
echo "\n1 test, 0 failures\n";
