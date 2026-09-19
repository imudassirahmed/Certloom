<?php
/** Phase 4 alpha.4.39 credential-integrity essentials contract. */
$root = dirname( __DIR__ );
$files = array(
    'manual' => 'src/Application/Issuance/ManualIssuanceService.php',
    'manual_admin' => 'src/Infrastructure/Admin/ManualIssuanceAdmin.php',
    'bulk' => 'src/Application/Issuance/BulkIssuanceCoordinator.php',
    'bulk_admin' => 'src/Infrastructure/Admin/BulkIssuanceAdmin.php',
    'context' => 'src/Infrastructure/Admin/ScopedFieldAdminAjax.php',
    'domain' => 'src/Infrastructure/Admin/DomainAdmin.php',
    'structure' => 'src/Infrastructure/Admin/DomainStructureAdmin.php',
    'rest' => 'src/Infrastructure/Admin/IssueCredentialRest.php',
    'issue_js' => 'admin/assets/issue-credential.js',
);
$src = array();
foreach ( $files as $key => $file ) {
    $src[$key] = file_get_contents( $root . '/' . $file );
    if ( ! is_string( $src[$key] ) || '' === $src[$key] ) { throw new RuntimeException( 'Missing source: ' . $file ); }
}
$checks = 0;
function de( $ok, $message ) { global $checks; if ( ! $ok ) { throw new RuntimeException( $message ); } $checks++; echo "PASS  $message\n"; }
function de_has( $s, $needle, $message ) { de( false !== strpos( $s, $needle ), $message ); }
function de_not( $s, $needle, $message ) { de( false === strpos( $s, $needle ), $message ); }

// Keep credential-context integrity without turning Certloom into a roster/LMS.
de_has( $src['manual'], 'certloom_manual_issue_batch_program_mismatch', 'Manual Issuance rejects a Batch from another Program.' );
de_has( $src['bulk'], 'certloom_bulk_batch_program_mismatch', 'Bulk Issuance rejects a Batch from another Program.' );
de_not( $src['manual'], 'membership_exists(', 'Manual Issuance does not require Recipient-to-Batch membership.' );
de_not( $src['manual'], 'add_member(', 'Manual Issuance never mutates a Batch roster as a side effect of issuance.' );

// Program-owned Issuer is authoritative and visible rather than contradictory.
de_has( $src['manual'], '$effective_issuer_id = $program_issuer_id ?: $explicit_issuer_id;', 'Canonical issuance resolves the Program Issuer before any one-off Issuer.' );
de_has( $src['manual'], 'certloom_manual_issue_issuer_program_mismatch', 'Contradictory explicit Issuer still fails closed at the service boundary.' );
de_has( $src['context'], "'issuer_id' => absint( \$row['issuer_id'] ?? 0 )", 'Program live lookup carries exact Issuer identity.' );
de_has( $src['context'], "'issuer_name' => (string) ( \$row['issuer_name'] ?? '' )", 'Program live lookup carries Issuer display context.' );
de_has( $src['rest'], "'issuer_id' => absint( \$row['issuer_id'] ?? 0 )", 'REST Program choices expose exact Issuer identity to the issuance app.' );
de_has( $src['rest'], "'issuer_name' => (string) ( \$row['issuer_name'] ?? '' )", 'REST Program choices expose Issuer display context to the issuance app.' );
de_has( $src['manual_admin'], 'data-inherited-issuer', 'Issue Credential has an explicit inherited-Issuer status surface.' );
de_has( $src['issue_js'], "var hasInherited = 'program' === state.credentialBasis", 'Issue Credential synchronizes inherited authority from the selected Program context.' );
de_has( $src['issue_js'], "independent.hidden = 'independent' !== state.credentialBasis", 'Program-backed issuance hides the contradictory independent-Issuer picker in the normal flow.' );

// valid_from and issued_at must not be presented as the same concept.
de_has( $src['manual_admin'], "__( 'Effective date', 'certloom' )", 'Manual Issuance labels valid_from as Effective date.' );
de_has( $src['issue_js'], "<small>Issued</small><strong>' + escapeHtml(receipt.issued_at", 'Issue Credential presents the actual issued_at timestamp separately in the receipt.' );
de_has( $src['bulk_admin'], "esc_html__( 'Effective date', 'certloom' )", 'Bulk Issuance uses the same Effective date terminology.' );
de_has( $src['domain'], "credential_locked_field( __( 'Effective date', 'certloom' )", 'Credential details present valid_from as Effective date.' );
de_has( $src['domain'], "__( 'Issue date', 'certloom' )", 'Credential registry retains Issue date for the actual issued_at timestamp.' );

// Do not promise a per-Issuer numbering feature that does not exist.
de_not( $src['domain'], "text_field( 'certificate_prefix'", 'Issuer form hides the future-only certificate-prefix setting.' );
de_not( $src['domain'], "\$_POST['certificate_prefix']", 'Saving an Issuer no longer creates or clears a fake certificate-prefix behavior.' );

// Avoid duplicate people/roles while preserving old data migration-safely.
de_has( $src['structure'], "__( 'Signer / instructor profile', 'certloom' )", 'Batch editing prefers a reusable Signer identity.' );
de_not( $src['structure'], "text_field( 'instructor_name'", 'New Batch edits no longer expose a second free-form instructor identity field.' );
de_has( $src['structure'], 'Legacy instructor label preserved:', 'Existing legacy instructor text is preserved visibly until replaced.' );
de_has( $src['structure'], "\$signer_id ? '' : ( null !== \$existing ? (string) ( \$existing['instructor_name'] ?? '' ) : '' )", 'Selecting a reusable Signer clears the duplicate fallback while untouched legacy data remains preserved.' );
de_has( $src['structure'], "__( 'Position / designation', 'certloom' )", 'Signer identity uses Position / designation for the person’s professional title.' );
de_has( $src['structure'], "esc_html__( 'Use as', 'certloom' )", 'Signer assignment uses Use as for contextual Signer/Instructor role.' );

echo "\n{$checks} Phase 4 domain-integrity essential checks, 0 failures\n";
