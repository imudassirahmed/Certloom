<?php
/** UX-QA-01 whole-product boundary contract. */
$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function uxqa_check( $ok, $label ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS  $label\n"; } else { ++$fail; echo "FAIL  $label\n"; } }
function uxqa_src( $root, $path ) { $v = file_get_contents( $root . '/' . $path ); return is_string( $v ) ? $v : ''; }

$bootstrap = uxqa_src( $root, 'certloom.php' );
$dashboard = uxqa_src( $root, 'admin/views/dashboard.php' );
$editor = uxqa_src( $root, 'admin/views/edit-certificate.php' );
$integrations = uxqa_src( $root, 'admin/views/integrations.php' );
$admin = uxqa_src( $root, 'admin/class-ecapla-certverify-admin.php' );
$placeholder = uxqa_src( $root, 'admin/views/roadmap-placeholder.php' );
$css = uxqa_src( $root, 'admin/assets/admin.css' );
$settings = uxqa_src( $root, 'admin/views/settings-manager.php' );
$template_admin = uxqa_src( $root, 'src/Infrastructure/Admin/TemplateAdmin.php' );
$analytics = uxqa_src( $root, 'admin/views/analytics.php' );
$issuance_analytics = uxqa_src( $root, 'admin/views/issuance-analytics.php' );
$frontend = uxqa_src( $root, 'admin/views/frontend-manager.php' );
$program = uxqa_src( $root, 'docs/ux-product-simplification-program.md' );

uxqa_check( false !== strpos( $program, '`UX-QA-01` | Product-wide usability/accessibility certification' ), 'UX-QA-01 remains the approved whole-product milestone' );
uxqa_check( false !== strpos( $program, 'desktop/mobile/keyboard/RTL/mixed-direction/forced-colors/reduced-motion' ), 'UX-QA-01 keeps its complete accessibility/responsive media contract' );
uxqa_check( false !== strpos( $program, 'end-to-end first-credential task and advanced-admin task' ), 'UX-QA-01 requires both customer journeys' );

uxqa_check( false !== strpos( $dashboard, "'Data safety', 'certloom'" ) && false !== strpos( $dashboard, "'Your certificate records stay protected', 'certloom'" ), 'Dashboard explains storage safety in present-tense customer language' );
foreach ( array( 'Proven registry is authoritative', 'current 1.5 registry', 'Credential Series migration infrastructure', 'data-store switch' ) as $obsolete ) {
	uxqa_check( false === strpos( $dashboard, $obsolete ), 'Dashboard excludes obsolete migration copy: ' . $obsolete );
}
uxqa_check( false !== strpos( $dashboard, "'Setup: %1\$d of %2\$d checks complete', 'certloom'" ) && false !== strpos( $dashboard, "'Setup wizard', 'certloom'" ), 'Dashboard combines compact incomplete-readiness language with a permanent Setup Wizard action' );
uxqa_check( false !== strpos( $dashboard, 'class="certloom-setup-reminder"' ) && false !== strpos( $dashboard, "'Review setup', 'certloom'" ), 'Post-Wizard readiness stays available as a compact dismissible reminder instead of a large checklist' );

uxqa_check( false !== strpos( $editor, "if ( \$certificate ) : ?><a href=\"#certloom-workspace-program\"" ) && false !== strpos( $editor, "if ( \$certificate ) : ?><a href=\"#certloom-workspace-activity\"" ), 'Program and Activity stay contextual to existing certificates' );
uxqa_check( false !== strpos( $editor, "'Create a certificate in the current Certloom registry.', 'certloom'" ), 'First-credential hero uses customer language' );
uxqa_check( false !== strpos( $editor, 'Linked recipient profiles and WordPress-user connections are not available in this build yet.' ), 'First-credential editor states the current recipient boundary honestly' );
uxqa_check( false === strpos( $editor, 'normalized domain' ) && false === strpos( $editor, 'current authoritative registry' ), 'Credential editor excludes authority/migration jargon from translated customer copy' );
uxqa_check( false !== strpos( $css, 'UX-QA-01: credential editor mobile touch targets' ) && false !== strpos( $css, '.certloom-certificate-editor .certloom-workspace-nav a' ) && false !== strpos( $css, 'min-height: 44px' ), 'Credential editor has component-scoped mobile target sizing' );

uxqa_check( false !== strpos( $integrations, "'Source confirms eligibility', 'certloom'" ) && false !== strpos( $integrations, "'Certloom resolves the Recipient', 'certloom'" ) && false !== strpos( $integrations, "'Publishing destinations', 'certloom'" ) && false === strpos( $admin, "'Supported LMS adapters', 'certloom'" ), 'Integrations presents the UX-CORE source → Recipient → credential → destination model instead of the obsolete LMS-only framework contract' );
uxqa_check( false === strpos( $integrations, 'What comes next' ) && false === strpos( $integrations, 'consolidation follows both adapters' ), 'Integrations excludes stale roadmap history from customer UI' );
uxqa_check( false === stripos( $placeholder, 'Phase 1' ) && false === stripos( $placeholder, 'P0' ) && false === stripos( $placeholder, 'acceptance gate' ), 'Protected workspace placeholder excludes internal phase/priority/gate jargon' );
uxqa_check( false !== strpos( $template_admin, "'Certificate preview', 'certloom'" ) && false !== strpos( $template_admin, "'Production downloads unavailable', 'certloom'" ), 'Template Preview uses customer-facing default renderer status' );
uxqa_check( false !== strpos( $template_admin, 'certloom-template-preview-technical' ) && false !== strpos( $template_admin, "'Rendering details', 'certloom'" ) && false !== strpos( $template_admin, 'Canonical Render Plan evidence remains available here for support and release verification.' ), 'Template Preview preserves exact renderer evidence behind a collapsed technical disclosure' );
uxqa_check( false !== strpos( $template_admin, "'Curated Template Library', 'certloom'" ) && false === strpos( $template_admin, "'TPLUX-02 · Curated Template Library', 'certloom'" ), 'Template Library removes milestone codes from ordinary design UI' );
uxqa_check( false !== strpos( $analytics, "'Verification insights', 'certloom'" ) && false === strpos( $analytics, 'ANL-01 · Local privacy-safe analytics' ), 'Verification Analytics removes internal milestone labeling' );
uxqa_check( false !== strpos( $issuance_analytics, "'Issuance insights', 'certloom'" ) && false === strpos( $issuance_analytics, 'ANL-02 · Immutable issuance analytics' ), 'Issuance Analytics removes internal milestone/data-model labeling' );
uxqa_check( false === strpos( $frontend, 'Phase 2 adds a shared read-only credential profile' ), 'Appearance & embed no longer exposes historical phase labeling' );

uxqa_check( false !== strpos( $settings, "'Populated legacy migration preflight', 'certloom'" ) && false !== strpos( $settings, "'Staging final authority-cutover rehearsal', 'certloom'" ), 'Diagnostics retain exact engineering evidence' );
uxqa_check( false !== strpos( $settings, 'class="certloom-technical-details certloom-settings-diagnostics"' ) && false !== strpos( $settings, "'Diagnostics & maintenance', 'certloom'" ), 'Technical diagnostics remain available through collapsed progressive disclosure in the unified workspace' );

uxqa_check( false !== strpos( $bootstrap, "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ), 'UX-QA leaves normalized write authority closed' );
uxqa_check( false !== strpos( $bootstrap, "define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false )" ), 'UX-QA leaves normalized admin-preview authority closed' );
uxqa_check( false !== strpos( $bootstrap, "define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false )" ), 'UX-QA leaves normalized Data Tools authority closed' );
uxqa_check( ! file_exists( $root . '/resources/renderer/accepted.json' ), 'UX-QA does not create renderer production authority' );

foreach ( array(
	'tests/browser/run_first_credential.py',
	'tests/browser/run_ux_qa_first_use.py',
	'tests/browser/run_ux_qa_advanced_admin.py',
) as $path ) {
	uxqa_check( is_file( $root . '/' . $path ), 'UX-QA browser evidence exists: ' . basename( $path ) );
}

if ( $fail ) { echo "\n{$pass} passed, {$fail} failures\n"; exit( 1 ); }
echo "PASS  UX-QA-01 source boundary: whole-product journeys, customer-language cleanup, contextual first credential and authority safety\n\n{$pass} checks, 0 failures\n";
