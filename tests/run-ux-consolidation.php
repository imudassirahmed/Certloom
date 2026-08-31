<?php
/** UX-CONS-01 — six-section shell + simple Backup & Restore contract. */
$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;
function uxcons_check( $ok, $label ) {
	global $pass, $fail;
	if ( $ok ) { ++$pass; echo "PASS  $label\n"; }
	else { ++$fail; echo "FAIL  $label\n"; }
}
function uxcons_src( $root, $path ) { return file_get_contents( $root . '/' . $path ); }

$navigation = uxcons_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$admin      = uxcons_src( $root, 'admin/class-ecapla-certverify-admin.php' );
$recovery   = uxcons_src( $root, 'admin/views/import-upload.php' );
$restore    = uxcons_src( $root, 'admin/views/backup-progress.php' );
$import     = uxcons_src( $root, 'admin/views/import-progress.php' );
$css        = uxcons_src( $root, 'admin/assets/admin.css' );
$js         = uxcons_src( $root, 'admin/assets/admin.js' );
$data_tools = uxcons_src( $root, 'src/Infrastructure/Admin/DomainDataToolsAdmin.php' );
$plugin     = uxcons_src( $root, 'certloom.php' );
$roadmap    = uxcons_src( $root, 'docs/approved-ux-consolidation-after-lms.md' );

uxcons_check( false !== strpos( $navigation, "'certloom'                 => 'Dashboard'" ), 'product shell defines Dashboard primary section' );
uxcons_check( false !== strpos( $navigation, "'certloom-certificates'    => 'Credentials'" ), 'product shell defines Credentials primary section' );
uxcons_check( false !== strpos( $navigation, "'certloom-domain-programs' => 'People & Programs'" ), 'product shell defines People & Programs primary section' );
uxcons_check( false !== strpos( $navigation, "'certloom-templates'       => 'Design'" ), 'product shell defines Design primary section' );
uxcons_check( false !== strpos( $navigation, "'certloom-analytics'       => 'Insights & Integrations'" ), 'product shell defines Insights & Integrations primary section' );
uxcons_check( false !== strpos( $navigation, "'certloom-settings'        => 'Settings'" ), 'product shell defines Settings primary section' );
uxcons_check( 6 === substr_count( preg_replace( '/.*private const PRIMARY_SIMPLE = array\((.*?)\);.*private const PRIMARY_ADVANCED.*/s', '$1', $navigation ), '=>' ) && 6 === substr_count( preg_replace( '/.*private const PRIMARY_ADVANCED = array\((.*?)\);.*public function register.*/s', '$1', $navigation ), '=>' ), 'Simple and Advanced primary shells are each bounded to exactly six configured destinations' );
uxcons_check( false !== strpos( $navigation, "add_action( 'admin_menu', array( \$this, 'consolidate_primary_menu' ), 999 )" ), 'menu consolidation runs after feature page registration' );
uxcons_check( false !== strpos( $navigation, "__( 'Backup & Restore', 'certloom' )" ), 'Backup & Restore is a Settings subtab rather than a primary Data Tools destination' );
uxcons_check( false !== strpos( $navigation, "__( 'Developer tools', 'certloom' )" ) && false !== strpos( $navigation, 'if ( $preview && ! $simple )' ), 'technical new-domain tools are available only as an Advanced/preview subtab' );
uxcons_check( false !== strpos( $admin, 'AdminProductNavigation()' ), 'legacy administration registers the shared product navigation once' );
uxcons_check( false !== strpos( $admin, "add_submenu_page( null, __( 'Integrations', 'certloom' )" ) && false !== strpos( $admin, "add_submenu_page( null, __( 'Backup & Restore', 'certloom' )" ), 'contextual subtabs are registered as hidden WordPress routes so menu consolidation cannot turn them into 403 pages' );

uxcons_check( false !== strpos( $recovery, "esc_html_e( 'Backup & Restore', 'certloom' )" ), 'normal recovery screen is named Backup & Restore' );
uxcons_check( false !== strpos( $recovery, 'certloom-recovery-quickstart' ) && false !== strpos( $recovery, "esc_html_e( 'Restore a backup', 'certloom' )" ) && false !== strpos( $recovery, "esc_html_e( 'Import certificates', 'certloom' )" ), 'recovery screen exposes short task-oriented actions' );
uxcons_check( false !== strpos( $recovery, 'id="certloom-backups"' ) && false !== strpos( $recovery, "esc_html_e( 'Your backups', 'certloom' )" ), 'backup history/settings are the lead customer recovery surface' );
uxcons_check( false !== strpos( $recovery, 'certloom-product-notice-rail' ), 'Backup & Restore has a dedicated top notice rail' );
uxcons_check( false !== strpos( $js, "querySelectorAll( ':scope > .notice.certloom-owned-notice, .certloom-dashboard-notice' )" ) && false !== strpos( $js, "rail.setAttribute( 'aria-live', 'polite' )" ), 'product notice rail collects only Certloom-owned notices and does not hijack unrelated WordPress/plugin notices' );
uxcons_check( false !== strpos( $js, "var heading = wrap.querySelector( ':scope > h1' )" ) && false !== strpos( $js, "var pageHead = wrap.querySelector( ':scope > .certloom-page-head' )" ), 'global notice rail is anchored directly beneath the page heading shell' );
uxcons_check( false === strpos( $recovery, 'name="confirm_restore"' ), 'restore no longer requires an exposed confirmation checkbox' );
uxcons_check( false === strpos( $admin, "empty( \$_POST['confirm_restore'] )" ), 'server controller no longer treats a presentation checkbox as restore authority' );
uxcons_check( false !== strpos( $recovery, 'data-ecapla-confirm=' ) && false !== strpos( $recovery, "esc_html_e( 'Restore backup', 'certloom' )" ), 'restore uses a clear action button followed by confirmation dialog behavior' );
uxcons_check( false !== strpos( $js, "closest( '[data-ecapla-confirm]' )" ) && false !== strpos( $js, 'window.confirm' ), 'shared confirmation controller powers destructive-action confirmation' );
uxcons_check( false !== strpos( $recovery, "submit_button( __( 'Review backup', 'certloom' )" ) && false !== strpos( $recovery, "submit_button( __( 'Review CSV', 'certloom' )" ), 'analyze jargon is replaced by Review in ordinary recovery actions' );
uxcons_check( false === strpos( $restore, 'bounded backup chunk' ) && false !== strpos( $restore, 'restore steps complete' ), 'restore progress uses plain-language steps rather than bounded-chunk terminology' );
uxcons_check( false === strpos( $import, 'bounded batches' ) && false !== strpos( $import, 'safe steps' ), 'CSV progress hides batching internals from ordinary users' );
uxcons_check( false !== strpos( $css, '.certloom-product-subnav' ) && false !== strpos( $css, 'overflow-x:auto' ), 'section subtabs have dedicated overflow-safe styling' );
uxcons_check( false !== strpos( $css, '.certloom-recovery-grid .certloom-backup-center { order:1; grid-column:1/-1; }' ), 'Backup Center is visually first and full width' );
uxcons_check( false !== strpos( $css, 'min-height:44px' ) || false !== strpos( $css, 'min-height: 44px' ), 'new shell preserves accessible 44px controls' );
uxcons_check( false !== strpos( $css, '@media (prefers-reduced-motion:reduce)' ) || false !== strpos( $css, '@media (prefers-reduced-motion: reduce)' ), 'reduced-motion protection remains present' );
uxcons_check( false !== strpos( $css, '@media (forced-colors:active)' ) || false !== strpos( $css, '@media (forced-colors: active)' ), 'forced-colors protection remains present' );
uxcons_check( false !== strpos( $data_tools, "const PAGE = 'certloom-domain-data-tools'" ), 'developer Data Tools route remains intact for diagnostics rather than being deleted' );
uxcons_check( false !== strpos( $roadmap, 'Never offer a mapping target that the current authoritative model cannot persist truthfully.' ), 'CSV mapping integrity rule remains explicit while persistence work is audited' );
uxcons_check( false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ) && false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false )" ) && false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false )" ), 'UX consolidation does not open normalized-domain production authority' );
uxcons_check( ! file_exists( $root . '/resources/renderer/accepted.json' ), 'UX consolidation does not manufacture renderer authority' );

echo "\n$pass UX-CONS-01 checks, $fail failures\n";
exit( $fail ? 1 : 0 );
