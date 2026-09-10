<?php
/** 1.6.98 UX-OPS-04 staging blocker + premium interaction regression. */
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function u4_src( $root, $path ) { $v = @file_get_contents( $root . '/' . $path ); return is_string( $v ) ? $v : ''; }
function u4( $ok, $message ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS  $message\n"; } else { $fail++; echo "FAIL  $message\n"; } }
$domain = u4_src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$structure = u4_src( $root, 'src/Infrastructure/Admin/DomainStructureAdmin.php' );
$ajax = u4_src( $root, 'src/Infrastructure/Admin/ScopedFieldAdminAjax.php' );
$guard = u4_src( $root, 'src/Infrastructure/Admin/ScopedFieldConfigurationGuard.php' );
$repo = u4_src( $root, 'src/Infrastructure/Repository/TemplateRepository.php' );
$template = u4_src( $root, 'src/Infrastructure/Admin/TemplateAdmin.php' );
$manual = u4_src( $root, 'src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$navigation = u4_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$legacy_admin = u4_src( $root, 'admin/class-ecapla-certverify-admin.php' );
$dashboard = u4_src( $root, 'admin/views/dashboard.php' );
$registry = u4_src( $root, 'admin/views/list-certificates.php' );
$admin_js = u4_src( $root, 'admin/assets/admin.js' );
$designer_js = u4_src( $root, 'admin/assets/designer.js' );
$admin_css = u4_src( $root, 'admin/assets/admin.css' );
$library_css = u4_src( $root, 'admin/assets/template-library.css' );
foreach ( array( $domain, $structure, $ajax, $guard, $repo, $template, $manual, $navigation, $legacy_admin, $dashboard, $registry, $admin_js, $designer_js, $admin_css, $library_css ) as $source ) { u4( '' !== $source, 'Required UX-OPS-04 source is available' ); }

// 1. Add actions enter a focused editor instead of leaving the create form below the list.
u4( substr_count( $domain, 'if ( $creating || $edit_id ) {' ) >= 3, 'Recipient/Program/Issuer routes explicitly enter focused create/edit mode' );
u4( substr_count( $structure, 'if ( $creating || $edit_id ) {' ) >= 2, 'Batch/Signer routes explicitly enter focused create/edit mode' );
u4( false === strpos( $domain, ") . '#certloom-domain-editor';" ) && false === strpos( $structure, ") . '#certloom-domain-editor';" ), 'Add actions no longer depend on a fragile fragment jump to a form below the list' );
u4( false !== strpos( $domain, '$this->program_form();' ) && false !== strpos( $domain, '$this->footer();' ) && false !== strpos( $domain, 'return;' ), 'Program create route renders the editor immediately and terminates the list render' );

// 2/3. Quick field creation is complete enough to make a safe decision, with clear ownership language.
u4( false !== strpos( $domain, 'data-certloom-quick-field-sensitivity' ) && false !== strpos( $domain, 'data-certloom-quick-field-exposure' ), 'Program quick-create exposes sensitivity and public visibility' );
u4( false !== strpos( $structure, 'data-certloom-quick-field-sensitivity' ) && false !== strpos( $structure, 'data-certloom-quick-field-exposure' ), 'Batch quick-create exposes sensitivity and public visibility' );
u4( false !== strpos( $ajax, 'get_sensitivity_options()' ) && false !== strpos( $ajax, 'get_public_exposure_options()' ), 'Quick-create validates privacy choices against the canonical Field Catalog options' );
u4( false !== strpos( $ajax, "if ( 'internal' === \$sensitivity ) { \$public_exposure = 'private'; }" ), 'Internal quick-created fields are forced private server-side' );
u4( false !== strpos( $admin_js, "body.set( 'sensitivity'" ) && false !== strpos( $admin_js, "body.set( 'public_exposure'" ), 'Quick-create submits the selected privacy policy' );
u4( false !== strpos( $admin_js, "exposureInput.disabled = internal" ), 'Quick-create immediately explains/enforces Internal → Private in the browser' );
u4( false !== strpos( $domain, "'Recipient profile', 'certloom'" ) && false !== strpos( $domain, "'Credential record', 'certloom'" ), 'Field ownership uses Recipient profile versus Credential record language' );
u4( false !== strpos( $domain, 'Recipient profile fields (such as name, email and external ID) are reused for that person.' ), 'Program field editor explains what Recipient profile ownership means' );
u4( false !== strpos( $admin_css, '.certloom-quick-field-grid' ) && 1 === preg_match( '/width\s*:\s*min\(760px/', $admin_css ) && 1 === preg_match( '/\.certloom-quick-field-field\s*>\s*label\s*\{[^}]*display\s*:\s*block\s*!important/s', $admin_css ), 'Quick field dialog has a deliberate responsive layout instead of the broken narrow row' );

// 4. Draft artwork does not block structural Batch/Program configuration, but issuance remains fail-closed.
u4( false !== strpos( $guard, "'certloom_template_not_published' === \$resolved->get_error_code()" ), 'Draft design assignment is treated as non-blocking for Program/Batch configuration only' );
u4( false !== strpos( $guard, 'issuance remain fail-closed until that exact design has a published revision' ), 'Guard documents that publication/issuance safety is not weakened' );
u4( false !== strpos( $repo, 'This design is still a draft. Publish it in Design before using it for credential issuance.' ), 'Any remaining publication-required error is actionable customer language' );

// 5. Designer save has visible pending, blocked and completed states.
u4( false !== strpos( $template, "'savingRevision' => __( 'Saving revision…'" ) && false !== strpos( $template, "'saveBlocked' => __( 'Save blocked'" ), 'Designer localizes explicit save-in-progress and blocked states' );
u4( false !== strpos( $designer_js, 'setSaveFeedback' ) && false !== strpos( $designer_js, "t( 'savingRevision', 'Saving revision…' )" ), 'Both Designer save actions enter a visible busy state before submission' );
u4( false !== strpos( $designer_js, 'blockSave' ) && false !== strpos( $designer_js, 'error.scrollIntoView' ), 'Client-side save validation surfaces and focuses the exact blocking feedback' );
u4( false !== strpos( $template, "'revision_saved'" ) && false !== strpos( $template, "'certloom-template-designer'" ), 'Revision save redirects back to the Designer itself rather than losing the user at page top' );
u4( false !== strpos( $template, 'certloom-designer-save-notice' ) && false !== strpos( $template, "'Saved.', 'certloom'" ), 'Designer renders an inline post-save confirmation at the interaction point' );

// 6. Certificate Library filtering is design-focused; hierarchy assignment moved to Design Rules.
u4( false === strpos( $template, 'library_program_id' ) && false === strpos( $template, 'certloom-template-library-program-filter' ), 'Certificate Library no longer mixes Program assignment context into design discovery' );
u4( false !== strpos( $template, "'Search', 'certloom'" ) && false !== strpos( $template, "'Purpose', 'certloom'" ) && false !== strpos( $template, "'Orientation', 'certloom'" ) && false !== strpos( $template, "'Style', 'certloom'" ), 'Certificate filtering keeps concise design-oriented controls' );
u4( false === strpos( $library_css, 'certloom-library-filter-program' ) && false === strpos( $library_css, 'certloom-template-library-program-filter' ), 'Retired Program-filter styling is physically removed instead of left dormant' );
u4( false !== strpos( $library_css, '.certloom-template-library-filter-primary,.certloom-template-library-filter-advanced{display:grid;grid-template-columns:minmax(220px,1.45fr) repeat(3,minmax(145px,1fr)) auto' ), 'Primary filter row contains equal-height Search/Purpose/Orientation/Style plus action' );
u4( false === strpos( $admin_css, '.certloom-template-library-filter-primary{display:grid!important' ), 'Old conflicting six-column !important filter override is gone' );
u4( false !== strpos( $library_css, '@media (max-width:782px)' ) && false !== strpos( $library_css, '.certloom-template-library-filter-primary,.certloom-template-library-filter-advanced{grid-template-columns:1fr}' ), 'Current filter structure deliberately stacks on mobile' );

// 7. Recipient is a reusable first-class identity and the ordinary create path
// must not silently bypass it once normalized issuance is available.
u4( false !== strpos( $manual, 'Who is receiving this credential?' ) && false !== strpos( $manual, 'Reuse one Recipient profile for the same person or organization.' ) && false !== strpos( $manual, 'Profile information is reusable across future credentials.' ), 'Issuance explains the reusable Recipient concept through concise contextual guidance' );
u4( false !== strpos( $manual, "isset( \$_GET['recipient_id'] )" ) && false !== strpos( $manual, 'array_unshift( $recipient_items, $selected_recipient )' ), 'Recipient profile deep-link preselection survives the bounded recipient search window' );
u4( false !== strpos( $domain, "ManualIssuanceAdmin::PAGE, 'recipient_id'" ) && false !== strpos( $domain, "'Issue credential to this recipient', 'certloom'" ), 'Recipient profiles provide a direct Recipient-preselected Issue credential action' );
u4( false !== strpos( $domain, 'data-certloom-context-lookup="wp_user"' ) && false !== strpos( $domain, "'WordPress account', 'certloom'" ) && false === strpos( $domain, "'WordPress user ID', 'certloom'" ), 'Recipient editor uses a searchable WordPress account selector instead of a raw numeric user ID' );
u4( false !== strpos( $ajax, "'wp_user' === \$kind" ) && false !== strpos( $ajax, "get_users( \$args )" ) && false !== strpos( $ajax, "'search_columns'" ), 'WordPress account selector uses bounded server-side name/email/login search' );
u4( false !== strpos( $admin_js, "'wp_user'" ) && false !== strpos( $domain, 'Certloom never guesses ownership from a matching name or email.' ), 'Recipient account lookup keeps explicit ownership and never implies email/name inference' );
u4( false !== strpos( $domain, "'Linked WordPress accounts', 'certloom'" ) && false !== strpos( $domain, "'WordPress account', 'certloom'" ) && false !== strpos( $domain, "'Linked account unavailable', 'certloom'" ) && false === strpos( $domain, "'%1\$s (#%2\$d)'" ), 'Normal Recipient and Credential views show human WordPress-account labels instead of raw numeric IDs' );
u4( false !== strpos( $dashboard, 'certloom-issuance' ) && false !== strpos( $dashboard, "'Issue credential', 'certloom'" ), 'Dashboard create actions prefer Recipient-aware issuance when normalized preview is available' );
u4( false !== strpos( $registry, 'certloom-issuance' ) && false !== strpos( $registry, "'Legacy credentials', 'certloom'" ), 'Legacy compatibility view visibly hands new credentials to Recipient-aware issuance' );
u4( false !== strpos( $legacy_admin, 'DomainFeatureGate::admin_preview_ready()' ) && substr_count( $legacy_admin, "admin.php?page=certloom-issuance" ) >= 2, 'Legacy new-record route and POST fail over to canonical Recipient-aware issuance while existing legacy edits remain supported' );
u4( false === strpos( $navigation, "__( 'Legacy data', 'certloom' )" ) && false !== strpos( $navigation, "__( 'Issue credential', 'certloom' )" ) && false !== strpos( $navigation, "'certloom-certificates', 'certloom-add', 'certloom-bulk-review'" ), 'Credentials subnavigation keeps canonical issuance and absorbs historical routes without a second Legacy data destination' );

// Authority remains closed in this UX tranche.
$feature_gate = u4_src( $root, 'src/Infrastructure/Migration/DomainFeatureGate.php' );
u4( false !== strpos( $feature_gate, 'new DomainAuthorityGate()' ) && false !== strpos( $feature_gate, "allows( 'write_paths' )" ) && false !== strpos( $feature_gate, "allows( 'admin_preview' )" ), 'UX-OPS-04 preserves manifest-backed normalized-domain authority rather than relying on historical readiness constants' );
u4( ! file_exists( $root . '/resources/domain/accepted.json' ) && ! file_exists( $root . '/resources/renderer/accepted.json' ), 'UX-OPS-04 does not manufacture DB or renderer authority' );

echo "\n$pass UX-OPS-04 checks, $fail failures\n";
exit( $fail ? 1 : 0 );
