<?php
/** Static safety guard: restore review can stage/analyze but can never promote live tables. */
$root = dirname( __DIR__ );
$preflight = file_get_contents( $root . '/src/Application/Restore/DomainRestorePreflightJobHandler.php' );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/DomainDataToolsAdmin.php' );
$coordinator = file_get_contents( $root . '/src/Application/DataTools/DomainDataToolsCoordinator.php' );
if ( false === $preflight || false === $admin || false === $coordinator ) { throw new RuntimeException( 'Could not read restore-review sources.' ); }
foreach ( array( 'commit_replace(', 'commit_merge(', '->commit(' ) as $forbidden ) {
	if ( false !== strpos( $preflight, $forbidden ) ) { throw new RuntimeException( 'Non-destructive restore review contains a live commit call: ' . $forbidden ); }
}
if ( false === strpos( $preflight, 'workspace->abandon' ) ) { throw new RuntimeException( 'Restore review no longer abandons its private workspace before human review.' ); }
if ( false === strpos( $coordinator, 'DOMAIN_RESTORE_PREFLIGHT' ) || false === strpos( $coordinator, 'enqueue_restore_commit' ) ) { throw new RuntimeException( 'Two-step restore coordinator contract was removed.' ); }
if ( false === strpos( $admin, 'admin_post_certloom_domain_restore_commit' ) || false === strpos( $admin, "'confirm_phrase'" ) ) { throw new RuntimeException( 'Separate explicit restore commit UI was removed.' ); }
echo "PASS  restore review is non-destructive and requires a separate explicit commit path\n";
echo "\n1 test, 0 failures\n";
