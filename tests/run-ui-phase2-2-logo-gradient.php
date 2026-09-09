<?php
/** Source contract for 1.7 UI Phase 2.2 exact logo-gradient correction. */
$root = dirname( __DIR__ );
$tokens = file_get_contents( $root . '/assets/css/certloom-tokens.css' );
$foundation = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$admin_css = file_get_contents( $root . '/admin/assets/admin.css' );
$dashboard = file_get_contents( $root . '/admin/views/dashboard.php' );
$domain_admin = file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' );
$template_admin = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$settings = file_get_contents( $root . '/admin/views/settings-manager.php' );
$automations = file_get_contents( $root . '/admin/views/automations.php' );
$credentials = file_get_contents( $root . '/admin/views/list-certificates.php' );
$checks = 0;
$failures = array();
function p22_check( $ok, $label ) {
    global $checks, $failures;
    $checks++;
    if ( ! $ok ) { $failures[] = $label; }
}
p22_check( false !== strpos( $tokens, '--cl-brand-blue-dark: #0048EF;' ), 'exact dark shield blue token exists' );
p22_check( false !== strpos( $tokens, '--cl-brand-blue: #1369FC;' ), 'exact logo blue token exists' );
p22_check( false !== strpos( $tokens, '--cl-brand-blue-light: #679CFC;' ), 'exact light shield blue token exists' );
p22_check( false !== strpos( $tokens, '--cl-cyan: #03CDF3;' ), 'exact logo cyan token exists' );
p22_check( false !== strpos( $tokens, '--cl-brand-emerald: #01C174;' ), 'exact logo emerald token exists' );
$hero_line = '';
if ( preg_match( '/--cl-brand-hero:\s*([^;]+);/', $tokens, $m ) ) { $hero_line = $m[1]; }
p22_check( false !== strpos( $hero_line, 'var(--cl-brand-blue-dark) 0%' ), 'hero gradient starts with shield blue' );
p22_check( false !== strpos( $hero_line, 'var(--cl-brand-blue) 28%' ), 'hero gradient carries logo blue' );
p22_check( false !== strpos( $hero_line, 'var(--cl-brand-blue-light) 52%' ), 'hero gradient carries light logo blue' );
p22_check( false !== strpos( $hero_line, 'var(--cl-cyan) 74%' ), 'hero gradient carries logo cyan' );
p22_check( false !== strpos( $hero_line, 'var(--cl-brand-emerald) 100%' ), 'hero gradient ends in logo emerald' );
p22_check( false === strpos( $hero_line, 'var(--cl-ink)' ) && false === strpos( $hero_line, 'var(--cl-blue-deep)' ), 'blue-heavy navy/deep-blue experiment is absent from hero gradient' );
p22_check( false !== strpos( $foundation, 'UI PHASE 2.2 LOGO-GRADIENT CORRECTION' ), 'phase2.2 correction is explicit in foundation authority' );
p22_check( false !== strpos( $foundation, 'background: var(--cl-brand-hero);' ) && false !== strpos( $admin_css, 'background: var(--cl-brand-hero);' ), 'shared and direct source hero rules use one gradient token' );
p22_check( false !== strpos( $foundation, 'border: 1px solid var(--cl-brand-blue-dark);' ), 'hero border is anchored to logo shield blue' );
p22_check( false !== strpos( $foundation, 'background: var(--cl-on-brand-wash);' ) && false !== strpos( $foundation, 'color: var(--cl-ink);' ), 'secondary/top-right controls use light surfaces and dark text over bright gradient end' );
p22_check( false !== strpos( $tokens, '--cl-chart-1: var(--cl-brand-blue-dark);' ), 'chart series 1 uses logo shield blue' );
p22_check( false !== strpos( $tokens, '--cl-chart-2: var(--cl-cyan);' ), 'chart series 2 uses logo cyan' );
p22_check( false !== strpos( $tokens, '--cl-chart-3: var(--cl-brand-blue);' ), 'chart series 3 uses logo blue' );
p22_check( false !== strpos( $tokens, '--cl-chart-4: var(--cl-brand-emerald);' ), 'chart series 4 uses logo emerald graphic exception' );
p22_check( false !== strpos( $foundation, '.certloom-chart-series:nth-child(n + 5)' ) && false !== strpos( $foundation, 'stroke-dasharray' ), 'chart palette repeats after four lines with style differentiation' );
p22_check( false !== strpos( $dashboard, 'class="certloom-dashboard-setup-link"' ) && false !== strpos( $foundation, 'position: absolute;' ), 'Setup Wizard remains in restored top-right treatment' );
p22_check( false !== strpos( $dashboard, 'certloom-dashboard-hero' ), 'Home uses shared top card' );
p22_check( false !== strpos( $credentials, 'certloom-page-hero' ), 'Credentials uses shared top card' );
p22_check( false !== strpos( $domain_admin, 'certloom-page-hero certloom-domain-title-row' ), 'Programs/domain workspace uses shared top card' );
p22_check( false !== strpos( $template_admin, 'certloom-page-hero certloom-template-page-head' ), 'Design workspace uses shared top card' );
p22_check( false !== strpos( $automations, 'certloom-workspace-hero' ), 'Automations uses shared top card' );
p22_check( false !== strpos( $settings, 'certloom-page-hero certloom-settings-hero' ), 'Settings uses shared top card' );
p22_check( false === stripos( $admin_css, '#0f6e56' ), 'retired forest-green admin anchor stays absent' );
if ( $failures ) {
    echo "FAIL  UI Phase 2.2 logo-gradient source contract: " . implode( ', ', $failures ) . "\n\n";
    echo ( $checks - count( $failures ) ) . " passed, " . count( $failures ) . " failures\n";
    exit( 1 );
}
echo "PASS  UI Phase 2.2 logo-gradient source contract\n\n";
echo $checks . " checks, 0 failures\n";
