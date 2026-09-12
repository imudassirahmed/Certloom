<?php
/** Alpha.4.41 regression: inherited Program title must survive Compose → Review → Issue. */
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$service = file_get_contents( $root . '/src/Application/Issuance/ManualIssuanceService.php' );
$resolver = file_get_contents( $root . '/src/Application/Issuance/EffectiveCredentialTitleResolver.php' );
$readiness = file_get_contents( $root . '/src/Application/Issuance/IssuanceDataReadiness.php' );
$template = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$checks = 0; $failures = 0;
function a441( $ok, $label ) { global $checks, $failures; $checks++; if ( $ok ) { echo "PASS  {$label}\n"; } else { $failures++; echo "FAIL  {$label}\n"; } }
a441( is_string( $admin ) && is_string( $service ) && is_string( $resolver ), 'title-review sources are readable' );
a441( false !== strpos( $service, 'new EffectiveCredentialTitleResolver()' ) && false !== strpos( $service, '$request[\'credential_title\'] = (string) $title_resolution[\'title\']' ), 'canonical service resolves the effective title before fingerprinting' );
a441( false !== strpos( $resolver, "if ( 'program' === \$source )" ) && false !== strpos( $resolver, "\$program['title']" ), 'Program title resolver inherits the authoritative Program title' );
a441( false === strpos( $admin, 'certloom_phase2_title_required' ) && false === strpos( $admin, 'Enter a credential title before reviewing this credential.' ), 'admin compatibility layer does not require a redundant explicit Program title' );
a441( false !== strpos( $admin, "array_merge( isset( \$review['phase2_ui_request'] )" ) && false !== strpos( $admin, ", (array) \$review['request'] );" ), 'canonical resolved review request wins over raw compose values' );
a441( false !== strpos( $admin, "'program' === (string) ( \$request['title_source'] ?? '' ) ? __( 'Inherited from the selected Program', 'certloom' )" ), 'review explicitly communicates Program-title provenance' );
a441( false !== strpos( $admin, "foreach ( array( 'recipient_mode', 'recipient_id', 'credential_basis', 'title_source', 'credential_title'" ), 'resolved title/source are preserved into the final Issue confirmation form' );
a441( false !== strpos( $readiness, "'credential.issue_date' => __( 'Effective date', 'certloom' )" ) && false !== strpos( $readiness, "'issue_date' => __( 'Effective date', 'certloom' )" ), 'design readiness presents valid_from compatibility tokens as Effective date' );
a441( substr_count( $template, "credential.issue_date\">' . esc_html__( 'Effective Date', 'certloom' )" ) >= 2, 'Certificate Designer labels credential.issue_date as Effective Date while retaining the compatibility token' );
echo "\n" . ( $checks - $failures ) . " alpha.4.41 title-review regression checks, {$failures} failures\n";
exit( $failures ? 1 : 0 );
