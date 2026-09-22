<?php
/** UX-CORE-05 — Credential provenance, record editing and audit-workspace contract. */
$root = dirname( __DIR__ );
$pass = 0;
$fail = 0;
function ux5( $ok, $label ) {
	global $pass, $fail;
	if ( $ok ) { ++$pass; echo "PASS  {$label}\n"; }
	else { ++$fail; echo "FAIL  {$label}\n"; }
}
function ux5src( $root, $path ) { return file_get_contents( $root . '/' . $path ); }

$domain    = ux5src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$registry  = ux5src( $root, 'src/Infrastructure/Repository/UnifiedCredentialRegistry.php' );
$dashboard = ux5src( $root, 'admin/views/dashboard.php' );
$events    = ux5src( $root, 'src/Infrastructure/Repository/EventRepository.php' );
$relation  = ux5src( $root, 'src/Application/CredentialRelationshipCorrectionService.php' );
$css       = ux5src( $root, 'admin/assets/admin.css' );

ux5( false !== strpos( $registry, "__( 'Credential', 'certloom' )" ) && false !== strpos( $registry, "__( 'Legacy', 'certloom' )" ), 'Unified Credentials exposes the approved Credential / Legacy provenance vocabulary' );
ux5( false === strpos( $registry, "_credential_source_label'] = __( 'Current'" ) && false === strpos( $registry, "_credential_source_label'] = __( 'Historical'" ), 'Unified registry no longer emits Current / Historical as customer provenance badges' );
ux5( false !== strpos( $dashboard, 'certloom-credential-source-meta' ) && false !== strpos( $dashboard, "'is-legacy'" ) && false !== strpos( $dashboard, "'is-credential'" ), 'Dashboard recent-credential table preserves Credential / Legacy provenance through dedicated metadata markup' );
ux5( false !== strpos( $dashboard, "__( 'Manage', 'certloom' )" ) && false !== strpos( $dashboard, "__( 'Edit', 'certloom' )" ), 'Dashboard recent-credential table uses explicit Manage for Legacy and Edit for current Credential records' );
ux5( false !== strpos( $domain, 'certloom-credential-hero-actions' ) && false !== strpos( $domain, "'Edit Legacy record', 'certloom'" ), 'Legacy detail uses the shared separated hero-action group and explicit Legacy wording' );
ux5( false !== strpos( ux5src( $root, 'admin/views/edit-certificate.php' ), "This is a Legacy credential issued before the current Program and Batch model." ) && false === strpos( ux5src( $root, 'admin/views/edit-certificate.php' ), "This is a historical credential issued before the current Program and Batch model." ), 'Legacy compatibility editor uses the approved Legacy provenance wording instead of Historical provenance' );
ux5( false !== strpos( ux5src( $root, 'admin/views/list-certificates.php' ), "'Legacy credentials', 'certloom'" ) && false === strpos( ux5src( $root, 'admin/views/list-certificates.php' ), "'Historical credentials', 'certloom'" ), 'Compatibility registry fallback uses Legacy provenance wording consistently' );
ux5( false !== strpos( $domain, "__( 'Edit credential record', 'certloom' )" ) && false !== strpos( $domain, 'credential_record_editor(' ), 'Current Simple Credential opens a dedicated record editor' );
ux5( false !== strpos( $domain, "if ( \$simple ) {\n\t\t\t\$this->credential_record_editor" ) && false !== strpos( $domain, "\t\t\treturn;\n\t\t}" ), 'Simple record editor returns before the Advanced tab workspace is rendered' );
ux5( false !== strpos( $domain, "__( 'Issued record', 'certloom' )" ) && false !== strpos( $domain, "__( 'Recipient', 'certloom' )" ) && false !== strpos( $domain, "__( 'Program & Batch', 'certloom' )" ) && false !== strpos( $domain, "__( 'Status & delivery', 'certloom' )" ) && false !== strpos( $domain, "__( 'Verification', 'certloom' )" ) && false !== strpos( $domain, "__( 'History & audit', 'certloom' )" ), 'Record editor exposes one coherent six-section workspace instead of a separate Actions tab' );
ux5( false !== strpos( $domain, 'certloom-locked-record-badge' ) && false !== strpos( $domain, 'readonly aria-readonly="true"' ), 'Issued facts are visibly immutable and readonly' );
ux5( false !== strpos( $domain, "__( 'Certificate number', 'certloom' )" ) && false !== strpos( $domain, "__( 'Issued', 'certloom' )" ) && false !== strpos( $domain, "__( 'Valid from', 'certloom' )" ) && false !== strpos( $domain, "__( 'Expires', 'certloom' )" ) && false !== strpos( $domain, "__( 'Grade', 'certloom' )" ) && false !== strpos( $domain, "__( 'Design used', 'certloom' )" ), 'Immutable record presents the important issued certificate facts as form fields' );
ux5( false !== strpos( $domain, 'credential_locked_custom_fields' ) && false !== strpos( $domain, "__( 'Issued credential fields', 'certloom' )" ), 'Issued custom credential fields are included in the locked record rather than hidden in metadata' );
ux5( false !== strpos( $domain, "'Edit Recipient profile'" ) && false !== strpos( $domain, 'RECIPIENTS_PAGE' ), 'Recipient correction continues to the reusable Recipient profile instead of mutating an issuance snapshot' );
ux5( false !== strpos( $domain, "__( 'Edit Program / Batch', 'certloom' )" ) && false !== strpos( $domain, 'certloom_prepare_relationship' ), 'Program / Batch correction is available inline on the current Credential record' );
ux5( false !== strpos( $relation, 'create_version' ) && false !== strpos( $relation, "'credential.relationship_corrected'" ), 'Program / Batch edits create a successor immutable Version and audit event' );
ux5( false !== strpos( $domain, "__( 'Status & delivery', 'certloom' )" ) && false !== strpos( $domain, 'CredentialLifecycleReviewService' ) && false !== strpos( $domain, 'credential_actions_panel' ), 'Reviewed lifecycle and delivery controls are embedded in the record workspace' );
ux5( false !== strpos( $events, 'actor_user_id' ) && false !== strpos( $events, 'old_state' ) && false !== strpos( $events, 'new_state' ) && false !== strpos( $events, 'created_at' ), 'Append-only event persistence carries actor, before/after state and timestamp evidence' );
ux5( false !== strpos( $domain, 'credential_actor_label' ) && false !== strpos( $domain, 'get_userdata' ) && false !== strpos( $domain, 'credential_event_changes' ), 'History resolves administrators to human labels and computes before/after changes' );
ux5( false !== strpos( $domain, 'certloom-audit-actor' ) && false !== strpos( $domain, 'certloom-audit-changes' ) && false !== strpos( $domain, 'certloom-audit-reason' ), 'Simple history presents actor, changed values and reason in an audit timeline' );
ux5( false !== strpos( $css, '.certloom-credential-source-line{display:flex;align-items:center;gap:7px' ), 'Provenance badge spacing is a permanent layout contract' );
ux5( false !== strpos( $css, '.certloom-credential-source.is-credential' ) && false !== strpos( $css, '.certloom-credential-source.is-legacy' ), 'Credential and Legacy provenance badges have deliberate visual styles' );
ux5( false !== strpos( $css, '.certloom-credential-hero-actions{display:flex' ) && false !== strpos( $css, 'gap:8px' ), 'Credential hero actions have an explicit non-collapsing gap' );
ux5( false !== strpos( $css, '.certloom-credential-record-editor' ) && false !== strpos( $css, '.certloom-locked-field-grid' ) && false !== strpos( $css, '.certloom-audit-list' ), 'Record editor, immutable field grid and audit timeline have dedicated responsive styling' );
ux5( false !== strpos( $css, '@media(max-width:782px)' ) && false !== strpos( $css, '.certloom-locked-field-grid{grid-template-columns:1fr}' ), 'Credential record editor collapses cleanly on narrow screens' );
ux5( false !== strpos( $css, '@media(forced-colors:active)' ) && false !== strpos( $css, '.certloom-credential-source' ), 'Provenance and record editor retain forced-colors treatment' );

printf( "\n%d UX-CORE-05 source checks, %d failures\n", $pass + $fail, $fail );
exit( $fail ? 1 : 0 );
