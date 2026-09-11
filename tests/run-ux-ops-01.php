<?php
/** UX-OPS-01 1.6.98 setup/operations corrective contract. */
$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function uxops_check( $ok, $label ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS  {$label}\n"; } else { ++$fail; echo "FAIL  {$label}\n"; } }
function uxops_src( $root, $path ) { $v = file_get_contents( $root . '/' . $path ); return is_string( $v ) ? $v : ''; }

$wizard = uxops_src( $root, 'admin/views/setup-wizard.php' );
$dashboard = uxops_src( $root, 'admin/views/dashboard.php' );
$admin = uxops_src( $root, 'admin/class-ecapla-certverify-admin.php' );
$admin_js = uxops_src( $root, 'admin/assets/admin.js' );
$admin_css = uxops_src( $root, 'admin/assets/admin.css' );
$foundation_css = uxops_src( $root, 'admin/assets/certloom-foundation.css' );
$backup = uxops_src( $root, 'admin/views/import-upload.php' );
$settings = uxops_src( $root, 'admin/views/settings-manager.php' );
$editor = uxops_src( $root, 'admin/views/edit-certificate.php' );
$public_css = uxops_src( $root, 'public/assets/certloom-public.css' );
$nav = uxops_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$oauth = uxops_src( $root, 'src/Infrastructure/Backup/GoogleDriveOAuth.php' );
$drive = uxops_src( $root, 'src/Infrastructure/Backup/GoogleDriveBackup.php' );
$onboarding = uxops_src( $root, 'includes/class-ecapla-certverify-onboarding.php' );
$uninstall = uxops_src( $root, 'uninstall.php' );

foreach ( array( $wizard,$dashboard,$admin,$admin_js,$admin_css,$backup,$settings,$editor,$public_css,$nav,$oauth,$drive,$onboarding,$uninstall ) as $source ) {
    uxops_check( '' !== $source, 'Required UX-OPS source is available' );
}

// 1. Setup v2 presentation remains optional and uses canonical published Designs without duplicating design authority.
uxops_check( false !== strpos( $wizard, 'name="presentation_mode" value="details_only"' ) && false !== strpos( $wizard, 'name="presentation_mode" value="image"' ) && false !== strpos( $wizard, 'name="presentation_mode" value="design"' ), 'Setup v2 exposes Details only, Image and Design as mutually exclusive presentation choices' );
uxops_check( false !== strpos( $wizard, 'name="design_template_id"' ) && false !== strpos( $wizard, 'data-certloom-wizard-template-preview' ) && false !== strpos( $admin, "search_published_candidates( 'certloom-preset-', 50 )" ), 'Setup v2 design choice uses canonical published-design candidates and live preview' );
uxops_check( false === strpos( $admin, 'TemplateRepository::create' ), 'Setup v2 does not create a second Template authority' );

// 2. Numbering remains a canonical Settings concern; Setup v2 does not force advanced numbering into first-run onboarding.
foreach ( array( 'numbering_prefix','numbering_suffix','numbering_start','numbering_padding' ) as $field ) {
    uxops_check( false === strpos( $wizard, 'name="' . $field . '"' ) && false !== strpos( $settings, 'name="' . $field . '"' ), 'Advanced numbering stays out of Setup v2 and remains available in Settings: ' . $field );
}
uxops_check( false !== strpos( $admin, 'Ecapla_CertVerify_Numbering::normalize_policy' ), 'Canonical numbering validation remains intact outside Setup v2' );

// 3/7. Google Drive remains available in Backup & Restore rather than bloating the required Setup v2 path.
uxops_check( false === strpos( $wizard, 'google_connection_mode' ) && false !== strpos( $backup, "'Connect with Google', 'certloom'" ) && false !== strpos( $backup, "'Use your own Google app', 'certloom'" ), 'Google Drive setup stays out of required Setup v2 and remains available in Backup & Restore' );
uxops_check( false !== strpos( $oauth, "https://www.googleapis.com/auth/drive.file" ) && false !== strpos( $oauth, 'access_type' ) && false !== strpos( $oauth, "'offline'" ), 'Google integration requests the bounded Drive file scope with offline backup access' );
uxops_check( false !== strpos( $admin_js, "window.open( '', 'certloomGoogleDriveOAuth'" ) && false !== strpos( $admin_js, "event.origin !== window.location.origin" ), 'OAuth popup uses a separate window and same-origin postMessage validation' );
uxops_check( false !== strpos( $admin_js, 'Your browser blocked the popup' ) && false !== strpos( $admin_js, "returnMode.value = 'backup'" ), 'OAuth has a usable same-tab fallback when a popup is blocked' );
uxops_check( false !== strpos( $drive, 'restore_settings_snapshot' ), 'Google settings retain rollback support without making Setup v2 own the provider connection' );

// 4. Setup v2 completion remains honest and returns administrators to normal product navigation without manufacturing hierarchy/design authority.
uxops_check( false !== strpos( $onboarding, "empty( \$state['wizard_draft']['first_credential_id'] )" ) && false !== strpos( $onboarding, "'wizard_completed_at'" ), 'Setup v2 cannot record completion without a real first credential' );
uxops_check( false !== strpos( $wizard, "'Continue to Dashboard for now', 'certloom'" ) && false !== strpos( $wizard, "'Exit setup', 'certloom'" ), 'Setup v2 always provides a normal route back to Dashboard' );
uxops_check( false === strpos( $wizard, 'assign( $template_id' ) && false === strpos( $wizard, 'program_id' ) && false === strpos( $wizard, 'batch_id' ), 'Setup v2 does not manufacture hierarchy or Global Design assignment authority' );

// 5. Hidden contextual routes keep the correct visible WordPress parent active.
uxops_check( false !== strpos( $nav, "add_filter( 'parent_file'" ) && false !== strpos( $nav, '? \'certloom\' : $parent_file' ), 'Hidden Certloom routes keep the Certloom WordPress parent menu highlighted' );
uxops_check( false !== strpos( $nav, "add_filter( 'submenu_file'" ) && false !== strpos( $nav, "'certloom-domain-data-tools'" ), 'Hidden contextual routes map back to one of the six visible primary rows' );

// 6/8/9. Backup alignment and compact Dashboard readiness/setup actions.
uxops_check( false !== strpos( $backup, 'certloom-backup-setting' ) && false !== strpos( $backup, 'certloom-backup-settings-actions' ), 'Automatic backup fields and save action use the compact aligned layout contract' );
uxops_check( false !== strpos( $admin_css, 'grid-template-columns:minmax(180px,280px) minmax(180px,240px) max-content' ) && false !== strpos( $admin_css, 'width:auto!important' ), 'Backup save action is content-sized rather than stretched across the card' );
uxops_check( false !== strpos( $dashboard, "'Setup: %1\$d of %2\$d checks complete', 'certloom'" ) && false !== strpos( $dashboard, "'Remind me in 3 days', 'certloom'" ) && false !== strpos( $dashboard, "'Do not remind me again', 'certloom'" ), 'Dashboard uses a compact readiness count with snooze and permanent-dismiss actions' );
uxops_check( false !== strpos( $foundation_css, '.certloom-admin-shell .certloom-dashboard-setup-link' ) && false !== strpos( $foundation_css, 'position: absolute;' ) && false !== strpos( $foundation_css, 'right: var(--cl-5);' ) && false !== strpos( $dashboard, 'class="certloom-dashboard-setup-link"' ), 'Setup Wizard remains a compact permanent Dashboard action in the user-restored top-right position' );

// 8. The reminder cadence is per-user, not a global option.
uxops_check( false !== strpos( uxops_src( $root, 'src/Infrastructure/Admin/SetupReadinessReminder.php' ), 'get_user_meta' ) && false !== strpos( uxops_src( $root, 'src/Infrastructure/Admin/SetupReadinessReminder.php' ), '3 * DAY_IN_SECONDS' ), 'Setup reminder snooze is per-administrator and exactly three days' );

// 10. Legacy Registry relationship explanation is truthful and points to normalized management.
uxops_check( false !== strpos( $editor, 'Legacy credential issued before the current Program and Batch model' ) && false !== strpos( $editor, "'Open Credential Management', 'certloom'" ), 'Legacy credential view explains why Program/Batch assignment is absent and points to normalized Credential Management' );

// 11. Public actions share one strong action palette and consistent focus/hover treatment.
uxops_check( false !== strpos( $public_css, '.certloom-result-action,.certloom-result-action.is-secondary{color:#0f6e56' ) && false !== strpos( $public_css, '.certloom-result-action:hover,.certloom-result-action:focus-visible' ), 'Copy/Profile/Share/Print actions share one strong Certloom action color and hover/focus treatment' );

// 12. Deactivation-before-delete lifecycle decision, keep default, destructive warning, multisite consistency.
uxops_check( false !== strpos( $admin, "'Before deactivating Certloom', 'certloom'" ) && false !== strpos( $admin, "'Keep certificate data', 'certloom'" ) && false !== strpos( $admin, "'Delete Certloom data if the plugin is deleted', 'certloom'" ), 'Plugin lifecycle dialog explains keep/delete choices before Certloom becomes inactive' );
uxops_check( false !== strpos( $admin, 'Public verification pages, credential QR destinations and the recipient portal stop working while Certloom is inactive.' ), 'Lifecycle dialog explicitly warns that public verification stops while the plugin is inactive' );
uxops_check( false !== strpos( $admin_js, "candidate.getAttribute( 'data-plugin' ) === pluginFile" ), 'Lifecycle interception targets only the exact Certloom plugin row' );
uxops_check( false !== strpos( $admin, 'certloom_uninstall_delete_data_network_override' ) && false !== strpos( $uninstall, 'certloom_uninstall_delete_data_network_override' ), 'Network deactivation persists one coherent keep/delete decision for multisite uninstall' );
uxops_check( false !== strpos( $uninstall, "delete_site_option( 'certloom_uninstall_delete_data_network_override' )" ), 'Network uninstall decision is cleaned after the deletion ceremony' );

// Missed-list correction: actual 50-row pagination, not a first-N truncation.
uxops_check( false !== strpos( $backup, '$certloom_backup_per_page = 50;' ) && false !== strpos( $backup, '$certloom_remote_per_page = 50;' ), 'Local and Google backup histories use the standard 50-row operational page size' );
uxops_check( false !== strpos( $backup, "'Previous', 'certloom'" ) && false !== strpos( $backup, "'Next', 'certloom'" ) && false !== strpos( $backup, "'Page %1\$d of %2\$d', 'certloom'" ), 'Backup histories provide real Previous/Page/Next navigation rather than hiding records after the first page' );

// Authority discipline remains intact.
uxops_check( false === file_exists( $root . '/resources/domain/accepted.json' ) && false === file_exists( $root . '/resources/renderer/accepted.json' ), 'UX-OPS work does not manufacture domain or renderer release authority' );
uxops_check( false !== strpos( uxops_src( $root, 'certloom.php' ), "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ), 'Production normalized write readiness remains closed in the working source' );

if ( $fail ) { echo "\n{$pass} passed, {$fail} failures\n"; exit( 1 ); }
echo "PASS  UX-OPS-01: setup, Google, backup, navigation, public actions and plugin lifecycle stay practical and authority-safe\n\n{$pass} checks, 0 failures\n";
