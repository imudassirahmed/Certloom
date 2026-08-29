<?php
/** UX-C04 — customer surfaces use plain language; technical evidence stays Advanced-only. */
$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;
function plx_check( $ok, $label ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS  $label\n"; } else { ++$fail; echo "FAIL  $label\n"; } }
function plx_src( $root, $path ) { return file_get_contents( $root . '/' . $path ); }
function plx_strings( $source ) {
	$out = array();
	if ( preg_match_all( "/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\(\\s*'((?:\\\\'|[^'])*)'/", $source, $m ) ) { $out = $m[1]; }
	return $out;
}

$ordinary_paths = array(
	'admin/views/integrations.php',
	'src/Infrastructure/Admin/ManualIssuanceAdmin.php',
	'src/Infrastructure/Admin/BulkIssuanceAdmin.php',
	'src/Infrastructure/Admin/EmailDeliveryAdmin.php',
	'admin/views/dashboard.php',
	'admin/views/frontend-manager.php',
	'admin/views/issuance-analytics.php',
	'admin/views/analytics.php',
	'admin/views/import-upload.php',
	'admin/views/backup-progress.php',
	'admin/views/import-progress.php',
);
$banned = '/\\b(preflight|queued|queue|worker|normalized|authority|candidate|artifact|rehearsal|cutover|provenance|idempotent|idempotency|bounded|immutable)\\b/i';
foreach ( $ordinary_paths as $path ) {
	$bad = array();
	foreach ( plx_strings( plx_src( $root, $path ) ) as $string ) { if ( preg_match( $banned, $string ) ) { $bad[] = $string; } }
	plx_check( empty( $bad ), $path . ' ordinary translated copy excludes internal engineering vocabulary' );
}

$settings = plx_src( $root, 'admin/views/settings-manager.php' );
$mode     = plx_src( $root, 'src/Infrastructure/Admin/AdminUiMode.php' );
$nav      = plx_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$roadmap  = plx_src( $root, 'docs/approved-ux-consolidation-after-lms.md' );
plx_check( false !== strpos( $settings, '$certloom_advanced_mode = \\Certloom\\Infrastructure\\Admin\\AdminUiMode::is_advanced();' ), 'Settings resolves the per-user Advanced presentation boundary' );
plx_check( false !== strpos( $settings, '<?php if ( $certloom_advanced_mode ) : ?>' ) && false !== strpos( $settings, 'id="certloom-system-status"' ), 'database/migration diagnostics are wrapped behind Advanced mode' );
plx_check( false !== strpos( $settings, "'System status', 'certloom'" ) && false !== strpos( $settings, "'Populated legacy migration preflight', 'certloom'" ) && false !== strpos( $settings, "'Staging final authority-cutover rehearsal', 'certloom'" ), 'technical evidence remains available rather than being weakened or deleted' );
plx_check( false !== strpos( $mode, 'Advanced restores specialist and diagnostic surfaces' ) || false !== strpos( plx_src( $root, 'admin/class-ecapla-certverify-admin.php' ), 'Advanced restores specialist and diagnostic surfaces' ), 'workspace control explains the Advanced diagnostic boundary' );
plx_check( false !== strpos( $nav, '$preview && $advanced' ) && false !== strpos( $nav, "__( 'Developer tools', 'certloom' )" ), 'developer tooling stays Advanced-only in product navigation' );
plx_check( false !== strpos( $roadmap, 'Technical terminology remains available only in Advanced/Diagnostics/Developer surfaces where genuinely useful.' ), 'approved C04 rule remains explicit in roadmap' );

$integrations = plx_src( $root, 'admin/views/integrations.php' );
plx_check( false !== strpos( $integrations, 'Repeated notifications for the same completed course and learner are recognized as the same completion' ), 'Integrations explains duplicate protection in customer language' );
plx_check( false !== strpos( $integrations, "Certloom’s production credential-writing checks" ), 'Integrations explains the closed write gate without authority jargon' );
$email = plx_src( $root, 'src/Infrastructure/Admin/EmailDeliveryAdmin.php' );
plx_check( false !== strpos( $email, "'Send reviewed email','certloom'" ) && false !== strpos( $email, 'Credential email is being sent through WordPress.' ), 'Email Delivery uses send/progress language rather than queue terminology' );
$bulk = plx_src( $root, 'src/Infrastructure/Admin/BulkIssuanceAdmin.php' );
plx_check( false !== strpos( $bulk, 'Certloom is still processing this batch. Refresh to see the latest progress.' ) && false !== strpos( $bulk, "__('Safe retries','certloom')" ), 'Bulk Issuance hides worker/idempotency vocabulary from normal users' );

$plugin = plx_src( $root, 'certloom.php' );
plx_check( false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ) && false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false )" ), 'plain-language cleanup does not open production data authority' );
plx_check( ! file_exists( $root . '/resources/renderer/accepted.json' ), 'plain-language cleanup does not manufacture renderer authority' );

echo "\n$pass UX-C04 checks, $fail failures\n";
exit( $fail ? 1 : 0 );
