<?php
/** UX-01 + FEUX-01 presentation-only simplification contract. */
$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;
function uxcheck( $ok, $label ) {
	global $pass, $fail;
	if ( $ok ) { ++$pass; echo "PASS  $label\n"; }
	else { ++$fail; echo "FAIL  $label\n"; }
}
function uxsrc( $root, $path ) { return file_get_contents( $root . '/' . $path ); }

$mode       = uxsrc( $root, 'src/Infrastructure/Admin/AdminUiMode.php' );
$admin      = uxsrc( $root, 'admin/class-ecapla-certverify-admin.php' );
$domain     = uxsrc( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$settings   = uxsrc( $root, 'admin/views/settings-manager.php' );
$admin_js   = uxsrc( $root, 'admin/assets/admin.js' );
$admin_css  = uxsrc( $root, 'admin/assets/admin.css' );
$policy     = uxsrc( $root, 'includes/class-ecapla-certverify-public-policy.php' );
$render     = uxsrc( $root, 'includes/class-ecapla-certverify-render.php' );
$public_js  = uxsrc( $root, 'public/assets/certloom-public.js' );
$public_css = uxsrc( $root, 'public/assets/certloom-public.css' );
$plugin     = uxsrc( $root, 'certloom.php' );

uxcheck( false !== strpos( $mode, "const META_KEY = '_certloom_admin_ui_mode'" ), 'admin UI mode is stored as a dedicated per-user preference' );
uxcheck( false !== strpos( $mode, "const SIMPLE" ) && false !== strpos( $mode, "= 'simple';" ) && false !== strpos( $mode, "const ADVANCED" ) && false !== strpos( $mode, "= 'advanced';" ), 'Simple and Advanced are explicit bounded modes' );
uxcheck( false !== strpos( $mode, 'get_current_user_id' ) && false !== strpos( $mode, 'get_user_meta' ) && false !== strpos( $mode, 'update_user_meta' ), 'mode reads and writes current-user metadata rather than global configuration' );
uxcheck( false !== strpos( $mode, 'return self::SIMPLE;' ), 'administrators without a saved preference default to Simple' );
uxcheck( false !== strpos( $admin, 'admin_post_certloom_set_ui_mode' ) && false !== strpos( $admin, 'check_admin_referer' ), 'mode switching uses a nonce-protected admin POST endpoint' );
uxcheck( false !== strpos( $admin, "__( 'Home', 'certloom' )" ) && false !== strpos( $admin, "__( 'Design', 'certloom' )" ), 'Simple navigation uses Home and Design language' );
uxcheck( false !== strpos( $admin, 'AdminUiMode::is_simple()' ), 'primary administration is conditional on presentation mode' );
uxcheck( false !== strpos( $domain, "'summary'" ) && false !== strpos( $domain, "__( 'Summary', 'certloom' )" ) && false !== strpos( $domain, "'credential'" ) && false !== strpos( $domain, "__( 'Credential', 'certloom' )" ) && false !== strpos( $domain, "'history'" ) && false !== strpos( $domain, "__( 'History', 'certloom' )" ) && false !== strpos( $domain, "'actions'" ) && false !== strpos( $domain, "__( 'Actions', 'certloom' )" ), 'Simple Credential workspace has four primary areas' );
uxcheck( false !== strpos( $domain, "'recipient'" ) && false !== strpos( $domain, "__( 'Recipient', 'certloom' )" ) && false !== strpos( $domain, "'program'" ) && false !== strpos( $domain, "__( 'Program', 'certloom' )" ) && false !== strpos( $domain, "'status'" ) && false !== strpos( $domain, "__( 'Status', 'certloom' )" ) && false !== strpos( $domain, "'verification'" ) && false !== strpos( $domain, "__( 'Verification', 'certloom' )" ), 'Credential contextual subtabs cover Recipient, Program, Status and Verification' );
uxcheck( false !== strpos( $domain, "'versions' => __( 'Versions', 'certloom' )" ) && false !== strpos( $domain, "'activity' => __( 'Activity', 'certloom' )" ), 'History contextual subtabs cover Versions and Activity' );
uxcheck( false !== strpos( $domain, "'overview', 'recipient', 'program', 'status', 'verification', 'versions', 'activity', 'actions'" ), 'Advanced mode preserves all eight direct diagnostic tabs' );
uxcheck( false !== strpos( $domain, 'credential_panel_target' ) && false !== strpos( $domain, 'credential_workspace_state' ), 'legacy/direct Credential URLs are mapped into grouped presentation without changing the underlying panels' );
uxcheck( false !== strpos( $settings, 'certloom-settings-search' ) && false !== strpos( $settings, 'certloom-settings-local-nav' ), 'Settings includes the missed search and local-navigation UX requirement' );
uxcheck( false !== strpos( $settings, "data-empty-label=\"<?php esc_attr_e( 'No settings sections match.', 'certloom' ); ?>\"" ), 'settings-search empty state is translatable' );
uxcheck( false !== strpos( $settings, 'certloom-settings-numbering' ) && false !== strpos( $settings, 'certloom-settings-verification' ) && false !== strpos( $settings, 'certloom-settings-retention' ), 'Settings sections expose stable local-navigation anchors' );
uxcheck( false !== strpos( $admin_js, 'function initSettingsSearch()' ) && false !== strpos( $admin_js, "query.length < 2" ) && false !== strpos( $admin_js, ").slice( 0, 8 )" ), 'settings search is bounded and progressive rather than rendering another long index' );
uxcheck( false !== strpos( $admin_css, '.certloom-ui-mode-bar' ) && false !== strpos( $admin_css, '.certloom-credential-subtabs' ), 'admin has dedicated compact mode and contextual-subtab styling' );
uxcheck( false !== strpos( $admin_css, 'min-height:44px' ) || false !== strpos( $admin_css, 'min-height: 44px' ), 'admin compact controls preserve 44px accessible targets' );
uxcheck( false !== strpos( $policy, "'label' => __( 'Copy link', 'certloom' )" ), 'public action copy is reduced to concise Copy link language' );
uxcheck( false !== strpos( $render, '<details class="certloom-qr-disclosure">' ) && false !== strpos( $render, "__( 'Show QR', 'certloom' )" ), 'server-rendered QR is a compact disclosure rather than dominant page content' );
uxcheck( false !== strpos( $public_js, 'certloom-qr-disclosure' ) && false !== strpos( $public_js, 'Show QR' ), 'dynamic REST rendering mirrors the server QR disclosure' );
uxcheck( false !== strpos( $public_css, '.certloom-qr-disclosure-panel' ) && false !== strpos( $public_css, '132px' ), 'disclosed QR uses the approved compact 132px treatment' );
uxcheck( false !== strpos( $public_css, '.certloom-result-action' ) && false !== strpos( $public_css, 'min-height:44px' ) || false !== strpos( $public_css, 'min-height: 44px' ), 'public compact actions retain accessible hit targets' );
uxcheck( false !== strpos( $public_css, '.certloom-share > summary' ) && ( false !== strpos( $public_css, 'width:max-content' ) || false !== strpos( $public_css, 'max-width:max-content' ) ), 'mobile Share keeps a compact visible control while its disclosure container can remain safe' );
uxcheck( false !== strpos( $public_css, '@media (prefers-reduced-motion: reduce)' ) && false !== strpos( $public_css, '@media (forced-colors: active)' ), 'FEUX includes reduced-motion and forced-colors protection' );
uxcheck( false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ) && false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false )" ), 'UX simplification does not open normalized-domain production authority' );
uxcheck( ! file_exists( $root . '/resources/renderer/accepted.json' ), 'UX simplification does not manufacture renderer authority' );
uxcheck( ! preg_match( '/DSN-09/i', $mode . $admin . $domain . $settings . $render ), 'UX work does not falsely reopen the completed Designer foundation as DSN-09' );

echo "\n$pass UX-01 / FEUX-01 checks, $fail failures\n";
exit( $fail ? 1 : 0 );
