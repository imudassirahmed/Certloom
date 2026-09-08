<?php
/** Source contract for 1.7 UI Phase 2.1 visual consistency audit. */
$root = dirname( __DIR__ );
$foundation = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$dashboard = file_get_contents( $root . '/admin/views/dashboard.php' );
$email = file_get_contents( $root . '/admin/assets/email-designs.css' );
$tokens = file_get_contents( $root . '/assets/css/certloom-tokens.css' );
$verification_analytics = file_get_contents( $root . '/admin/views/analytics.php' );
$issuance_analytics = file_get_contents( $root . '/admin/views/issuance-analytics.php' );
$checks = 0;
$failures = array();
function p21_check( $ok, $label ) {
	global $checks, $failures;
	$checks++;
	if ( ! $ok ) { $failures[] = $label; }
}
$p21 = strstr( $foundation, 'CERTLOOM 1.7 · UI PHASE 2.1 VISUAL CONSISTENCY' );
p21_check( false !== $p21, 'phase2.1 foundation section exists' );
p21_check( false !== strpos( $p21, '.certloom-admin-shell .certloom-dashboard-hero' ) && false !== strpos( $p21, 'background: transparent;' ) && false !== strpos( $p21, 'box-shadow: none;' ), 'dashboard hero is explicitly flat in the last-loaded foundation' );
p21_check( false !== strpos( $p21, '.certloom-admin-shell .certloom-page-hero' ) && false !== strpos( $p21, '.certloom-admin-shell .certloom-workspace-hero' ) && false !== strpos( $p21, '.certloom-admin-shell .certloom-credential-hero' ), 'shared top-level hero families use one authority' );
p21_check( false !== strpos( $p21, 'border-bottom: 1px solid var(--cl-rule);' ) && false !== strpos( $p21, 'padding: 0 0 var(--cl-4);' ), 'top-level headers retain deliberate bottom padding and ruled structure' );
p21_check( false !== strpos( $p21, '.certloom-admin-shell .certloom-metric-card' ) && false !== strpos( $p21, 'border-radius: var(--cl-r-none);' ), 'dashboard metric cards are flat data cells' );
p21_check( false !== strpos( $p21, '.certloom-metric-icon.is-valid' ) && false !== strpos( $p21, 'var(--cl-valid-wash)' ) && false !== strpos( $p21, '.certloom-metric-icon.is-withdrawn' ) && false !== strpos( $p21, 'var(--cl-revoked-wash)' ) && false !== strpos( $p21, '.certloom-metric-icon.is-expired' ) && false !== strpos( $p21, 'var(--cl-expired-wash)' ), 'credential-state metric icons use approved verdict tokens' );
p21_check( false !== strpos( $p21, '.certloom-workspace-card' ) && false !== strpos( $p21, '.certloom-manual-card' ) && false !== strpos( $p21, '.certloom-settings-info-card' ), 'shared section surfaces lose card-soup elevation' );
p21_check( false !== strpos( $p21, 'backdrop-filter: none;' ), 'normal sticky admin chrome explicitly removes blur/glass' );
p21_check( false === strpos( $dashboard, '→' ) && false === strpos( $dashboard, '↗' ), 'Dashboard no longer appends decorative arrows to links' );
p21_check( false !== strpos( $dashboard, 'class="button certloom-dashboard-setup-link"' ), 'Setup Wizard remains permanently reachable as a normal secondary dashboard action' );
p21_check( false === strpos( substr( $email, 0, 300 ), '--cl-ink:' ) && false !== strpos( substr( $email, 0, 500 ), '--certloom-email-ink: var(--cl-ink);' ), 'Email Designs no longer shadows the canonical global ink token' );
p21_check( false !== strpos( substr( $email, 0, 500 ), '--certloom-email-brand: var(--cl-blue);' ), 'Email Designs local action alias resolves to canonical blue' );
p21_check( false !== strpos( $email, '.certloom-email-hero {' ) && false !== strpos( $email, 'border-bottom: 1px solid var(--cl-rule);' ) && false !== strpos( $email, 'background: transparent;' ), 'Email Designs top header uses the same flat ruled language' );
p21_check( false !== strpos( $email, 'backdrop-filter: none;' ) && false === strpos( $email, 'backdrop-filter: blur(' ), 'Email Designer toolbar is opaque, not glass' );
p21_check( false !== strpos( $p21, 'linear-gradient(90deg, var(--cl-ink)' ) && false !== strpos( $p21, '.certloom-admin-shell .certloom-email-hero::before' ), 'thin logo-spectrum brand rail is shared by top-level header families without gradient-filling the header surface' );
p21_check( false !== strpos( $tokens, '--cl-chart-1: var(--cl-blue);' ) && false !== strpos( $tokens, '--cl-chart-2: var(--cl-cyan-deep);' ) && false !== strpos( $tokens, '--cl-chart-4: var(--cl-ink-soft);' ), 'generic analytics palette is tokenized from non-verdict Certloom logo colours' );
p21_check( false !== strpos( $verification_analytics, 'certloom-analytics-chart is-verification' ) && false !== strpos( $issuance_analytics, 'certloom-analytics-chart is-issuance' ), 'verification and issuance analytics declare stable semantic series classes' );
p21_check( false !== strpos( $p21, '.certloom-dashboard-mini-chart.is-verification > span' ) && false !== strpos( $p21, 'background: var(--cl-chart-2);' ), 'verification trend uses Certloom cyan rather than legacy green' );
p21_check( false !== strpos( $p21, '.certloom-dashboard-mini-chart.is-issuance > span' ) && false !== strpos( $p21, 'background: var(--cl-chart-1);' ), 'issuance trend uses Certloom action blue' );
p21_check( false !== strpos( $p21, '.certloom-chart-series:nth-child(n + 5)' ) && false !== strpos( $p21, 'stroke-dasharray' ), 'multi-series palette repeats after four series with a secondary stroke pattern instead of inventing new colours' );
p21_check( false !== strpos( $p21, '.certloom-analytics-metrics .is-success' ) && false !== strpos( $p21, 'var(--cl-valid-mark)' ) && false !== strpos( $p21, '.certloom-analytics-metrics .is-revoked' ) && false !== strpos( $p21, 'var(--cl-revoked)' ), 'status analytics use verdict colours only when the metric actually represents that verdict' );
if ( $failures ) {
	echo "FAIL  UI Phase 2.1 visual consistency source contract: " . implode( ', ', $failures ) . "\n\n";
	echo ( $checks - count( $failures ) ) . " passed, " . count( $failures ) . " failures\n";
	exit( 1 );
}
echo "PASS  UI Phase 2.1 visual consistency source contract\n\n";
echo $checks . " checks, 0 failures\n";
