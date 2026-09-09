<?php
/** Source contract for 1.7 UI Phase 2.1 visual consistency audit. */
$root = dirname( __DIR__ );
$foundation = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$admin_css = file_get_contents( $root . '/admin/assets/admin.css' );
$dashboard = file_get_contents( $root . '/admin/views/dashboard.php' );
$email = file_get_contents( $root . '/admin/assets/email-designs.css' );
$tokens = file_get_contents( $root . '/assets/css/certloom-tokens.css' );
$verification_analytics = file_get_contents( $root . '/admin/views/analytics.php' );
$issuance_analytics = file_get_contents( $root . '/admin/views/issuance-analytics.php' );
$plugin = file_get_contents( $root . '/certloom.php' );
$domain_gate = file_get_contents( $root . '/src/Infrastructure/Migration/DomainFeatureGate.php' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$navigation = file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' );
$checks = 0;
$failures = array();
function p21_check( $ok, $label ) {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) { $failures[] = $label; }
}
$p21 = strstr( $foundation, 'CERTLOOM 1.7 · UI PHASE 2.1 VISUAL CONSISTENCY' );
$p21_user = strstr( $foundation, 'CERTLOOM 1.7 · UI PHASE 2.2 LOGO-GRADIENT CORRECTION' );
p21_check( false !== $p21 && false !== $p21_user, 'phase2.1 foundation plus current user-directed top-card authority exist' );
p21_check( false !== strpos( $p21_user, 'background: var(--cl-brand-hero);' ) && false !== strpos( $p21_user, 'box-shadow: var(--cl-lift);' ) && false !== strpos( $p21_user, 'border-radius: var(--cl-r-lift);' ), 'user-approved top sections are substantial logo-theme gradient cards' );
p21_check( false !== strpos( $p21_user, '.certloom-admin-shell .certloom-page-hero' ) && false !== strpos( $p21_user, '.certloom-admin-shell .certloom-workspace-hero' ) && false !== strpos( $p21_user, '.certloom-admin-shell .certloom-credential-hero' ) && false !== strpos( $p21_user, '.certloom-admin-shell .certloom-email-hero' ), 'shared top-level card treatment covers dashboard/page/workspace/credential/email families' );
p21_check( false !== strpos( $tokens, '--cl-brand-hero: linear-gradient(' ) && false !== strpos( $tokens, '--cl-on-brand-soft:' ), 'top-card gradient and on-brand contrast values live in the token authority' );
p21_check( false !== strpos( $p21_user, 'content: none;' ), 'old 3px brand rail is superseded rather than layered on top of the gradient card' );
p21_check( false !== strpos( $dashboard, 'class="certloom-dashboard-setup-link"' ) && strpos( $dashboard, 'class="certloom-dashboard-setup-link"' ) < strpos( $dashboard, 'class="certloom-dashboard-actions"' ), 'Setup Wizard is restored to its earlier independent top-right position before the action group' );
p21_check( false !== strpos( $p21_user, 'position: absolute;' ) && false !== strpos( $p21_user, '.certloom-dashboard-setup-link' ), 'Setup Wizard top-right positioning is restored in the shared authority' );
p21_check( false !== strpos( $p21, '.certloom-admin-shell .certloom-metric-card' ) && false !== strpos( $p21, 'border-radius: var(--cl-r-none);' ), 'dashboard metric cards remain compact data cells beneath the top card' );
p21_check( false !== strpos( $p21, '.certloom-metric-icon.is-valid' ) && false !== strpos( $p21, 'var(--cl-valid-wash)' ) && false !== strpos( $p21, '.certloom-metric-icon.is-withdrawn' ) && false !== strpos( $p21, 'var(--cl-revoked-wash)' ) && false !== strpos( $p21, '.certloom-metric-icon.is-expired' ) && false !== strpos( $p21, 'var(--cl-expired-wash)' ), 'credential-state metric icons use approved verdict tokens' );
p21_check( false !== strpos( $p21, '.certloom-workspace-card' ) && false !== strpos( $p21, '.certloom-manual-card' ) && false !== strpos( $p21, '.certloom-settings-info-card' ), 'ordinary section surfaces remain free from generic card-soup elevation' );
p21_check( false !== strpos( $p21, 'backdrop-filter: none;' ), 'normal sticky admin chrome explicitly removes blur/glass' );
p21_check( false === strpos( $dashboard, '→' ) && false === strpos( $dashboard, '↗' ), 'Dashboard does not append decorative arrows to links' );
p21_check( false === strpos( substr( $email, 0, 300 ), '--cl-ink:' ) && false !== strpos( substr( $email, 0, 500 ), '--certloom-email-ink: var(--cl-ink);' ), 'Email Designs no longer shadows the canonical global ink token' );
p21_check( false !== strpos( substr( $email, 0, 500 ), '--certloom-email-brand: var(--cl-blue);' ), 'Email Designs local action alias resolves to canonical blue' );
p21_check( false !== strpos( $email, 'backdrop-filter: none;' ) && false === strpos( $email, 'backdrop-filter: blur(' ), 'Email Designer toolbar is opaque, not glass' );
p21_check( false !== strpos( $tokens, '--cl-chart-1:' ) && false !== strpos( $tokens, '--cl-chart-2:' ) && false !== strpos( $tokens, '--cl-chart-3:' ) && false !== strpos( $tokens, '--cl-chart-4:' ), 'analytics palette remains tokenized in the shared Certloom authority' );
p21_check( false !== strpos( $verification_analytics, 'certloom-analytics-chart is-verification' ) && false !== strpos( $issuance_analytics, 'certloom-analytics-chart is-issuance' ), 'verification and issuance analytics declare stable semantic series classes' );
p21_check( false !== strpos( $p21, '.certloom-dashboard-mini-chart.is-verification > span' ) && false !== strpos( $p21, 'background: var(--cl-chart-2);' ), 'verification trend uses Certloom cyan rather than legacy green' );
p21_check( false !== strpos( $p21, '.certloom-dashboard-mini-chart.is-issuance > span' ) && false !== strpos( $p21, 'background: var(--cl-chart-1);' ), 'issuance trend uses Certloom action blue' );
p21_check( false !== strpos( $p21, '.certloom-chart-series:nth-child(n + 5)' ) && false !== strpos( $p21, 'stroke-dasharray' ), 'multi-series palette repeats after four series with a secondary stroke pattern' );
p21_check( false !== strpos( $p21, '.certloom-analytics-metrics .is-success' ) && false !== strpos( $p21, 'var(--cl-valid-mark)' ) && false !== strpos( $p21, '.certloom-analytics-metrics .is-revoked' ) && false !== strpos( $p21, 'var(--cl-revoked)' ), 'status analytics use verdict colours only for true status semantics' );
p21_check( false === stripos( $admin_css, '#0f6e56' ), 'normal admin source no longer contains the retired forest-green anchor' );
p21_check( false === stripos( $admin_css, 'backdrop-filter: blur' ), 'normal admin source no longer carries active glass/blur chrome' );
p21_check( false !== strpos( $admin_css, 'background: var(--cl-brand-hero);' ) && false === strpos( $admin_css, 'rgba(18, 107, 85' ), 'top-card gradient is the tokenized Certloom logo theme, not the retired green hero' );
p21_check( false !== strpos( $plugin, "define( 'CERTLOOM_REVIEW_ADMIN_PREVIEW', true );" ) && false !== strpos( $domain_gate, "self::\$scopes['admin_preview'] = true;" ), 'alpha review build keeps admin workspaces open without claiming write-path/Data Tools authority' );
p21_check( false !== strpos( $navigation, "'certloom-domain-credentials' => 'Credentials'" ) && false !== strpos( $navigation, "'certloom-automations'         => 'Automations'" ), 'Credentials and Automations remain primary six-section navigation entries' );
p21_check( false !== strpos( $admin, "add_submenu_page( self::DASHBOARD_PAGE, __( 'Automations'" ) && false !== strpos( $admin, "render_automations_screen" ), 'Automations route remains registered to the real screen' );
p21_check( false !== strpos( $admin_css, '.certloom-bulk-status.is-succeeded { border-color: var(--cl-blue);' ) && false !== strpos( $admin_css, '.certloom-email-status.is-sent { border-color: var(--cl-blue);' ) && false !== strpos( $admin_css, '.certloom-template-state-published { border-color: var(--cl-blue);' ), 'generic succeeded/sent/published admin states use blue authority rather than credential-valid green' );
p21_check( false !== strpos( $admin_css, '.certloom-credential-status.is-valid { background: var(--cl-valid-wash); color: var(--cl-valid); }' ) && false !== strpos( $admin_css, '.ecapla-certverify-badge-valid { color: var(--cl-valid);' ), 'credential validity remains explicitly green through verdict tokens' );
if ( $failures ) {
	echo "FAIL  UI Phase 2.1 visual consistency source contract: " . implode( ', ', $failures ) . "\n\n";
	echo ( $checks - count( $failures ) ) . " passed, " . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "PASS  UI Phase 2.1 visual consistency source contract\n\n";
echo $checks . " checks, 0 failures\n";
