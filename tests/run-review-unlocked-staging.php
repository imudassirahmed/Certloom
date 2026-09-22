<?php
/** Review-unlocked safety contract: gates remain explicit without development banners in ordinary product UI. */
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/certloom.php' );
$gate = file_get_contents( $root . '/src/Infrastructure/Migration/DomainFeatureGate.php' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$migration = file_get_contents( $root . '/src/Infrastructure/Diagnostics/StagingMigrationRehearsal.php' );
$restore = file_get_contents( $root . '/src/Infrastructure/Diagnostics/StagingRestoreRehearsal.php' );
$cutover = file_get_contents( $root . '/src/Infrastructure/Diagnostics/StagingCutoverRehearsal.php' );
$checks = 0; $failures = array();
function review_unlock_check( $ok, $label ) { global $checks, $failures; $checks++; if ( ! $ok ) { $failures[] = $label; } }
foreach ( compact( 'plugin', 'gate', 'admin', 'migration', 'restore', 'cutover' ) as $name => $source ) {
	review_unlock_check( is_string( $source ) && '' !== $source, 'source readable: ' . $name );
}
review_unlock_check( false !== strpos( $plugin, "define( 'CERTLOOM_REVIEW_UNLOCKED', true );" ), 'review build is explicitly labelled unlocked' );
review_unlock_check( false !== strpos( $plugin, "define( 'CERTLOOM_REVIEW_ADMIN_PREVIEW', true );" ), 'implemented admin workspaces are open by default' );
review_unlock_check( false !== strpos( $gate, "self::\$scopes['admin_preview'] = true;" ) && false !== strpos( $gate, "self::\$scopes['write_paths'] = true;" ), 'ordinary normalized admin/write paths open under review override' );
review_unlock_check( false !== strpos( $gate, "self::\$scopes['data_tools'] = true;" ) && false !== strpos( $gate, "'local', 'development', 'staging'" ), 'Data Tools unlock only in explicit non-production WordPress environments' );
foreach ( array( 'MIGRATION', 'RESTORE', 'CUTOVER' ) as $kind ) {
	review_unlock_check( false !== strpos( $plugin, "define( 'CERTLOOM_ALLOW_STAGING_{$kind}_REHEARSAL', true );" ), strtolower( $kind ) . ' rehearsal review opt-in enabled' );
}
foreach ( array( $migration, $restore, $cutover ) as $source ) {
	review_unlock_check( false !== strpos( $source, "'local', 'development', 'staging'" ), 'rehearsal retains hard non-production environment guard' );
}
review_unlock_check( false === strpos( $admin, "render_review_unlocked_notice" ) && false === strpos( $admin, "Certloom review-unlocked build" ), 'review-mode development banner stays out of ordinary Certloom admin UI' );
review_unlock_check( false === strpos( $admin, 'Production renderer downloads also remain authority-gated' ) && false === strpos( $admin, 'Production renderer downloads remain authority-gated' ), 'renderer release-boundary jargon stays out of global product notices' );
if ( $failures ) {
	echo "FAIL  review-unlocked staging contract: " . implode( ', ', $failures ) . "\n\n";
	echo ( $checks - count( $failures ) ) . " passed, " . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "PASS  review-unlocked staging contract\n\n{$checks} checks, 0 failures\n";
