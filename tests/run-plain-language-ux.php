<?php
/** UX-C04 / UX-CORE — normal workflows use human language; specialist evidence remains progressively disclosed. */
$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;
function plx_check( $ok, $label ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS  $label\n"; } else { ++$fail; echo "FAIL  $label\n"; } }
function plx_src( $root, $path ) { return file_get_contents( $root . '/' . $path ); }

$settings = plx_src( $root, 'admin/views/settings-manager.php' );
$nav = plx_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$domain = plx_src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$template = plx_src( $root, 'src/Infrastructure/Admin/TemplateAdmin.php' );
$manual = plx_src( $root, 'src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$integrations = plx_src( $root, 'admin/views/integrations.php' );
$setup = plx_src( $root, 'admin/views/setup-wizard.php' );
$scoped = plx_src( $root, 'src/Infrastructure/Admin/ScopedFieldAdminAjax.php' );
$designer_js = plx_src( $root, 'admin/assets/designer.js' );
$plugin = plx_src( $root, 'certloom.php' );
$roadmap = plx_src( $root, 'docs/approved-ux-consolidation-after-lms.md' );

plx_check( false !== strpos( $settings, '$certloom_show_diagnostics = current_user_can( \'manage_options\' );' ) && false === strpos( $settings, '$certloom_advanced_mode' ), 'Settings uses capability-based diagnostics without a retired workspace-mode boundary' );
plx_check( false !== strpos( $settings, '<details id="certloom-system-status" class="certloom-technical-details certloom-settings-diagnostics"' ) && false !== strpos( $settings, "esc_html_e( 'Diagnostics & maintenance', 'certloom' )" ), 'database and migration diagnostics remain available under collapsed Technical details' );
plx_check( false !== strpos( $nav, "__( 'Developer tools','certloom')" ) && false === strpos( $nav, '$preview && ! $simple' ), 'developer tooling remains capability-controlled without a retired mode gate' );
plx_check( false !== strpos( $roadmap, 'Technical terminology remains available only in Technical details, Diagnostics, Designer specialist controls, or Developer surfaces where genuinely useful.' ), 'approved progressive-disclosure language rule remains explicit' );

plx_check( false !== strpos( $domain, '$detailed_registry = true;' ) && false !== strpos( $domain, "__( 'Credentials', 'certloom' )" ), 'Credential Management keeps the detailed registry inside the one task-focused workspace' );
plx_check( false !== strpos( $domain, "__( 'Credential status', 'certloom' )" ) && false !== strpos( $domain, "__( 'See whether this credential is currently valid and the dates that affect it.', 'certloom' )" ), 'Simple credential status explains the human outcome rather than the status engine' );
plx_check( false !== strpos( $domain, "__( 'Review status change', 'certloom' )" ) && false !== strpos( $domain, "__( 'Confirm status change', 'certloom' )" ), 'Simple credential actions use status-change language rather than lifecycle-operation language' );
plx_check( false !== strpos( $domain, 'certloom-credential-technical-overview' ) && false !== strpos( $domain, "__( 'Record version', 'certloom' )" ) && false !== strpos( $domain, "__( 'Public Credential ID', 'certloom' )" ), 'immutable credential identity remains available under Technical details without becoming the primary view' );
plx_check( false !== strpos( $domain, "'Public ID', 'certloom'" ) && false !== strpos( $domain, 'if ( $simple )') && false !== strpos( $domain, "__( 'Open credential', 'certloom' )" ), 'Simple linked-credential history omits raw Public ID and provides a human continuation' );

plx_check( false !== strpos( $manual, "__( 'Issue credential', 'certloom' )" ) && false !== strpos( $manual, 'A focused eight-step workflow.' ) && false === strpos( $manual, 'AdminUiMode::is_simple' ), 'Manual Issuance uses the task-focused header in the unified workspace' );
plx_check( false !== strpos( $manual, 'Online verification is available whenever the credential store is healthy. Downloadable PDF/PNG/JPEG certificate files are not available yet.' ), 'normal Manual Issuance availability copy stays human-readable without renderer-authority jargon' );
plx_check( false !== strpos( $manual, 'certloom-manual-issuance-technical-status' ) && false !== strpos( $manual, "__( 'Technical status', 'certloom' )" ) && false !== strpos( $manual, 'Manual Issuance and normalized public verification are operational when the credential store is healthy.' ), 'specialist Manual Issuance authority explanation remains progressively disclosed under Technical status' );
$issue_js = plx_src( $root, 'admin/assets/issue-credential.js' );
plx_check( false !== strpos( $manual, "__( 'Technical details', 'certloom' )" ) && false !== strpos( $issue_js, 'Review SHA-256' ) && false !== strpos( $issue_js, 'certloom-issue-receipt-technical' ), 'issuance integrity evidence is preserved under explicit Technical details' );

plx_check( false !== strpos( $template, 'certloom-template-designer-boundary' ) && false !== strpos( $template, "__( 'Technical integrity', 'certloom' )" ), 'Designer integrity architecture remains available under Technical integrity disclosure' );
plx_check( false !== strpos( $template, "__( 'Design name', 'certloom' )" ) && false !== strpos( $template, 'certloom-design-identity-technical' ) && false !== strpos( $template, "__( 'Machine key', 'certloom' )" ), 'Design machine identity is progressively disclosed rather than lost' );
plx_check( false !== strpos( $template, "__( 'Technical custom field…', 'certloom' )" ) && false !== strpos( $template, "__( 'Technical field token', 'certloom' )" ), 'raw custom field-token editing remains a deliberately technical escape hatch' );
plx_check( false !== strpos( $template, "__( 'Choose information that should be filled automatically when the credential is issued.', 'certloom' )" ), 'Simple dynamic-field help explains purpose instead of catalog mechanics' );
plx_check( false !== strpos( $template, "__( 'Certificate number', 'certloom' )" ) && false !== strpos( $template, "__( 'The barcode represents the credential’s certificate number.', 'certloom' )" ), 'barcode controls name the human certificate number instead of its machine token' );
plx_check( false !== strpos( $template, "__( 'On-screen preview', 'certloom' )" ) && false !== strpos( $template, "__( 'Rendering details', 'certloom' )" ), 'preview uses a human Simple label while retaining Advanced rendering evidence' );

plx_check( false !== strpos( $designer_js, 'The Designer could not load its editing controls.') && false === strpos( $designer_js, 'typed Designer Element Registry is unavailable'), 'Designer load failure is human-readable' );
plx_check( false !== strpos( $designer_js, 'This element needs a newer design format.') && false === strpos( $designer_js, 'newer certloom-layout schema'), 'Designer format failure avoids schema terminology' );
plx_check( false !== strpos( $designer_js, 'Choose an image from the WordPress Media Library.') && false === strpos( $designer_js, 'attachment:ID reference'), 'Designer media validation avoids storage-reference syntax' );
plx_check( false !== strpos( $designer_js, 'supported text limit') && false === strpos( $designer_js, 'canonical text budget'), 'Designer list validation avoids canonical-budget terminology' );

plx_check( false !== strpos( $integrations, 'Only a successfully issued Certloom credential can be published to a destination.' ), 'destination UX describes the issuance boundary in customer language' );
plx_check( false !== strpos( $integrations, 'Open Credly to review this publication before trying again.') && false !== strpos( $integrations, 'Review reason:' ), 'Credly history gives Simple guidance while preserving Advanced reason evidence' );
plx_check( false !== strpos( $setup, 'Programs, Batches and Signers are optional power tools, not setup requirements.' ) && false !== strpos( $setup, 'You can change advanced settings later.' ) && false !== strpos( $setup, 'How Certloom organizes this profile' ) && false === strpos( $setup, 'Simple mode' ) && false === strpos( $setup, 'Advanced mode' ), 'Setup v2 explains optional power tools and contextual help without resurrecting a Simple/Advanced workspace split' );
plx_check( false !== strpos( $scoped, 'Credential field changes are not available on this site yet.' ) && false === strpos( $scoped, 'normalized write authority is ready' ), 'quick field creation reports availability without write-authority jargon' );

$is_qa_flavor = false !== strpos( $plugin, "define( 'CERTLOOM_QA_UNGATED', true )" );
plx_check(
    $is_qa_flavor
        ? ( false !== strpos( $plugin, 'Ungated' ) && false !== strpos( $plugin, 'certloom-qa-ungated' ) )
        : ( false === strpos( $plugin, 'Certloom QA' ) && false === strpos( $plugin, 'certloom-qa-ungated' ) ),
    $is_qa_flavor ? 'QA derivative carries unmistakable staging-only presentation identity' : 'production-intent bootstrap contains no QA-only presentation identity'
);
plx_check( false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ) && false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false )" ), 'plain-language cleanup does not open production data authority' );
plx_check( ! file_exists( $root . '/resources/domain/accepted.json' ), 'plain-language cleanup does not manufacture normalized-domain authority' );
plx_check( ! file_exists( $root . '/resources/renderer/accepted.json' ), 'plain-language cleanup does not manufacture renderer authority' );

echo "\n$pass UX-C04 / UX-CORE plain-language checks, $fail failures\n";
exit( $fail ? 1 : 0 );
