<?php
/** UX-C04 / UX-CORE — normal workflows use human language; specialist evidence remains Advanced-only. */
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

plx_check( false !== strpos( $settings, '$certloom_advanced_mode = \\Certloom\\Infrastructure\\Admin\\AdminUiMode::is_advanced();' ), 'Settings resolves the per-user Advanced presentation boundary' );
plx_check( false !== strpos( $settings, '<?php if ( $certloom_advanced_mode ) : ?>' ) && false !== strpos( $settings, 'id="certloom-system-status"' ), 'database and migration diagnostics remain Advanced-only' );
plx_check( false !== strpos( $nav, '$preview && ! $simple' ) && false !== strpos( $nav, "__( 'Developer tools', 'certloom' )" ), 'developer tooling stays Advanced-only in product navigation' );
plx_check( false !== strpos( $roadmap, 'Technical terminology remains available only in Advanced/Diagnostics/Developer surfaces where genuinely useful.' ), 'approved plain-language rule remains explicit' );

plx_check( false !== strpos( $domain, '$simple = AdminUiMode::is_simple();' ) && false !== strpos( $domain, "__( 'Credentials', 'certloom' )" ), 'Credential Management has an explicit Simple presentation path' );
plx_check( false !== strpos( $domain, "__( 'Credential status', 'certloom' )" ) && false !== strpos( $domain, "__( 'See whether this credential is currently valid and the dates that affect it.', 'certloom' )" ), 'Simple credential status explains the human outcome rather than the status engine' );
plx_check( false !== strpos( $domain, "__( 'Review status change', 'certloom' )" ) && false !== strpos( $domain, "__( 'Confirm status change', 'certloom' )" ), 'Simple credential actions use status-change language rather than lifecycle-operation language' );
plx_check( false !== strpos( $domain, '$simple ?') && false !== strpos( $domain, "__( 'Current immutable Version', 'certloom' )" ), 'advanced immutable-Version evidence remains available without becoming the Simple hero' );
plx_check( false !== strpos( $domain, "'Public ID', 'certloom'" ) && false !== strpos( $domain, 'if ( $simple )') && false !== strpos( $domain, "__( 'Open credential', 'certloom' )" ), 'Simple linked-credential history omits raw Public ID and provides a human continuation' );

plx_check( false !== strpos( $manual, '$simple = AdminUiMode::is_simple();' ) && false !== strpos( $manual, "__( 'Issue a credential', 'certloom' )" ), 'Manual Issuance has an explicit Simple-mode header' );
plx_check( false !== strpos( $manual, 'Online verification or downloadable certificate files may be unavailable until this site finishes its release setup.' ), 'Simple Manual Issuance describes feature availability without authority/renderer jargon' );
plx_check( false !== strpos( $manual, 'The existing registry still powers public verification in this build') && false !== strpos( $manual, '} else {'), 'specialist Manual Issuance authority explanation remains available in Advanced mode' );
plx_check( false !== strpos( $manual, "__( 'Technical details', 'certloom' )" ) && false !== strpos( $manual, 'Review SHA-256' ), 'issuance integrity evidence is preserved under explicit Technical details' );

plx_check( false !== strpos( $template, 'if ( ! $simple )') && false !== strpos( $template, "__( 'Technical integrity', 'certloom' )" ), 'Designer integrity architecture remains Advanced-only' );
plx_check( false !== strpos( $template, "__( 'Design name', 'certloom' )" ) && false !== strpos( $template, '<input type="hidden" name="machine_key"'), 'Simple Design hides its internal machine key while preserving it for writes' );
plx_check( false !== strpos( $template, '$custom_token_option = $simple ?') && false !== strpos( $template, "__( 'Advanced custom field…', 'certloom' )" ), 'raw custom field-token editing is not offered in Simple Designer' );
plx_check( false !== strpos( $template, "__( 'Choose information that should be filled automatically when the credential is issued.', 'certloom' )" ), 'Simple dynamic-field help explains purpose instead of catalog mechanics' );
plx_check( false !== strpos( $template, "__( 'Certificate number', 'certloom' )" ) && false !== strpos( $template, "__( 'The barcode represents the credential’s certificate number.', 'certloom' )" ), 'barcode controls name the human certificate number instead of its machine token' );
plx_check( false !== strpos( $template, "__( 'On-screen preview', 'certloom' )" ) && false !== strpos( $template, "__( 'Rendering details', 'certloom' )" ), 'preview uses a human Simple label while retaining Advanced rendering evidence' );

plx_check( false !== strpos( $designer_js, 'The Designer could not load its editing controls.') && false === strpos( $designer_js, 'typed Designer Element Registry is unavailable'), 'Designer load failure is human-readable' );
plx_check( false !== strpos( $designer_js, 'This element needs a newer design format.') && false === strpos( $designer_js, 'newer certloom-layout schema'), 'Designer format failure avoids schema terminology' );
plx_check( false !== strpos( $designer_js, 'Choose an image from the WordPress Media Library.') && false === strpos( $designer_js, 'attachment:ID reference'), 'Designer media validation avoids storage-reference syntax' );
plx_check( false !== strpos( $designer_js, 'supported text limit') && false === strpos( $designer_js, 'canonical text budget'), 'Designer list validation avoids canonical-budget terminology' );

plx_check( false !== strpos( $integrations, 'Only a successfully issued Certloom credential can be published to a destination.' ), 'destination UX describes the issuance boundary in customer language' );
plx_check( false !== strpos( $integrations, 'Open Credly to review this publication before trying again.') && false !== strpos( $integrations, 'Review reason:' ), 'Credly history gives Simple guidance while preserving Advanced reason evidence' );
plx_check( false !== strpos( $setup, 'Advanced options stay connected' ) && false === strpos( $setup, 'Advanced choices stay canonical' ), 'Setup avoids canonical terminology in its Advanced-mode guidance' );
plx_check( false !== strpos( $scoped, 'Credential field changes are not available on this site yet.' ) && false === strpos( $scoped, 'normalized write authority is ready' ), 'quick field creation reports availability without write-authority jargon' );

plx_check( false === strpos( $plugin, 'Certloom QA · Ungated' ) && false === strpos( $plugin, 'certloom-qa-ungated' ), 'production-intent bootstrap contains no QA-only presentation identity' );
plx_check( false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ) && false !== strpos( $plugin, "define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false )" ), 'plain-language cleanup does not open production data authority' );
plx_check( ! file_exists( $root . '/resources/domain/accepted.json' ), 'plain-language cleanup does not manufacture normalized-domain authority' );
plx_check( ! file_exists( $root . '/resources/renderer/accepted.json' ), 'plain-language cleanup does not manufacture renderer authority' );

echo "\n$pass UX-C04 / UX-CORE plain-language checks, $fail failures\n";
exit( $fail ? 1 : 0 );
