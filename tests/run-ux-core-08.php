<?php
/** UX-CORE-08: hands-on clarity/access corrective after QA.15. */
$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function ux8_src( $root, $file ) { $v = file_get_contents( $root . '/' . $file ); if ( false === $v ) { throw new RuntimeException( 'Missing ' . $file ); } return $v; }
function ux8( $ok, $label ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS  $label\n"; } else { ++$fail; echo "FAIL  $label\n"; } }

$domain = ux8_src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$structure = ux8_src( $root, 'src/Infrastructure/Admin/DomainStructureAdmin.php' );
$legacy_admin = ux8_src( $root, 'admin/class-ecapla-certverify-admin.php' );
$nav = ux8_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$templates = ux8_src( $root, 'src/Infrastructure/Admin/TemplateAdmin.php' );
$settings = ux8_src( $root, 'admin/views/settings-manager.php' );
$css = ux8_src( $root, 'admin/assets/admin.css' );
$manual = ux8_src( $root, 'src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$bulk = ux8_src( $root, 'src/Infrastructure/Admin/BulkIssuanceAdmin.php' );

ux8( false !== strpos( $css, '.certloom-credential-row-actions a + a::before' ) && false !== strpos( $css, 'color: var(--certloom-accent)' ) && false === strpos( substr( $css, strpos( $css, '.certloom-credential-row-actions a {' ), 420 ), 'border: 1px solid' ), 'Credential View/Manage use familiar text row actions rather than badge-like pills' );
ux8( false !== strpos( $domain, "__( 'Record version', 'certloom' )" ) && false !== strpos( $domain, 'Current immutable record version; earlier versions remain in the credential history.' ), 'Credential version label explains the audit lineage instead of exposing an unexplained Version column' );
ux8( false !== strpos( $domain, "__( 'Added to Certloom', 'certloom' )" ), 'registry entry date is labeled as Added to Certloom rather than ambiguous Added' );

$program_list = substr( $domain, strpos( $domain, 'private function simple_program_list' ), 5200 );
ux8( false !== strpos( $program_list, "esc_html__( 'Issue', 'certloom' )" ) && false !== strpos( $program_list, "esc_html__( 'Edit record', 'certloom' )" ) && false === strpos( $program_list, "esc_html__( 'Design', 'certloom' )" ), 'Unified Programs list removes the redundant Design row action while preserving Issue/Edit' );
ux8( false !== strpos( $structure, "esc_html__( 'Credentials issued', 'certloom' )" ) && false !== strpos( $structure, "__( 'Instructor: %s', 'certloom' )" ) && false !== strpos( $structure, "__( 'Dates not set', 'certloom' )" ) && false !== strpos( $structure, "esc_html__( 'Recipients', 'certloom' )" ), 'Unified Batches surface credential count, recipients, dates and instructor context without opening the record' );

$signer_edit = strpos( $structure, '$this->signer_form( $editing ); $this->signer_assignments( $repo, $editing );' );
ux8( false !== $signer_edit, 'Signer edit path actually renders signer usage management instead of returning after the profile form' );
ux8( false !== strpos( $structure, "__( 'Where this signer is used', 'certloom' )" ) && false !== strpos( $structure, "__( 'Used in', 'certloom' )" ) && false !== strpos( $structure, "_n( 'Used in %d place', 'Used in %d places'" ), 'Human signer copy replaces unexplained assignment terminology with where-used language' );
ux8( false !== strpos( $structure, "__( 'Signer hierarchy rules', 'certloom' )" ) && false !== strpos( $structure, "__( 'Add hierarchy rule', 'certloom' )" ), 'Technical signer copy keeps hierarchy precision while calling the entries rules rather than generic assignments' );
ux8( false !== strpos( $structure, "certloom-signer-priority-control" ) && false !== strpos( $structure, "__( 'Technical options', 'certloom' )" ) && false !== strpos( $structure, 'name="priority" value="100"' ), 'Signer priority stays out of the normal flow but remains reachable under Technical options with a safe default' );

ux8( false !== strpos( $css, '--certloom-flow-accent:var(--cl-blue);' ) && false !== strpos( $css, 'background:var(--cl-blue);border-color:var(--cl-blue);color:var(--cl-surface)' ), 'primary actions resolve through the canonical Certloom blue token authority rather than a legacy forest fallback' );
ux8( false !== strpos( $templates, "__( 'Edit', 'certloom' )" ) && false !== strpos( $templates, "__( 'View design', 'certloom' )" ), 'My Designs keeps explicit Edit/View design actions' );

ux8( false !== strpos( $legacy_admin, 'Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();' ) && false !== strpos( $legacy_admin, "add_submenu_page( null, __( 'Analytics', 'certloom' ), __( 'Analytics', 'certloom' ), ECAPLA_CERTVERIFY_CAP" ), 'Analytics refreshes capability health and registers as a hidden parentless route' );
ux8( false !== strpos( $legacy_admin, 'CAP_VIEW_ANALYTICS ) && ! current_user_can( ECAPLA_CERTVERIFY_CAP )' ), 'Analytics render guard accepts either granular analytics access or the legacy-equivalent Certloom management capability' );
ux8( 1 === substr_count( $nav, "'certloom-analytics',array('certloom-analytics'),\Ecapla_CertVerify_Permissions::CAP_VIEW_ANALYTICS" ), 'Unified Analytics subtab remains visibility-gated by the granular analytics capability while hidden-route reachability stays stable after menu consolidation' );

ux8( false !== strpos( $settings, 'certloom-settings-info-grid' ) && false !== strpos( $settings, "esc_html_e( 'Guidance & support', 'certloom' )" ), 'Settings groups non-editable guidance into one deliberate reference section' );
ux8( false !== strpos( $settings, "esc_html_e( 'Expiry behavior', 'certloom' )" ) && false !== strpos( $settings, "esc_html_e( 'Public-data privacy', 'certloom' )" ) && false !== strpos( $settings, "esc_html_e( 'Support', 'certloom' )" ) && false === strpos( $settings, "esc_html_e( 'Ecapla support', 'certloom' )" ), 'Expiry, privacy and Support are clear cards in the unified workspace and Ecapla support jargon is removed' );
ux8( false !== strpos( $css, '.certloom-settings-info-card' ) && false !== strpos( $css, '@media(max-width:1100px){.certloom-settings-info-grid{grid-template-columns:1fr}' ), 'Settings guidance cards have intentional responsive styling instead of loose heading/paragraph fragments' );
ux8( false !== strpos( $settings, '<h3 id="certloom-settings-expiry">' ) && false !== strpos( $settings, '<h3 id="certloom-settings-privacy">' ) && false !== strpos( $settings, '<h3 id="certloom-settings-support">' ), 'redesigned reference cards remain discoverable through Settings search anchors' );

ux8( false === strpos( $manual, 'most specific assignment' ) && false === strpos( $bulk, 'Batch-specific design assignments' ), 'high-frequency issuance copy avoids internal design-assignment jargon where plain choice/override language is sufficient' );
ux8( ! file_exists( $root . '/resources/domain/accepted.json' ) && ! file_exists( $root . '/resources/renderer/accepted.json' ), 'UX corrective does not manufacture domain or renderer authority' );

printf( "\n%d UX-CORE-08 source checks, %d failures\n", $pass, $fail );
exit( $fail ? 1 : 0 );
