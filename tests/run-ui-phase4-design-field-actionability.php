<?php
/** Alpha.4.40 staging regression: Designer-required fields must be actionable in Issue Credential. */
$root = dirname( __DIR__ );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$readiness = file_get_contents( $root . '/src/Application/Issuance/IssuanceDataReadiness.php' );
$merge = file_get_contents( $root . '/src/Application/Issuance/MergeSnapshotBuilder.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$checks = 0; $failures = 0;
function a440( $ok, $label ) { global $checks, $failures; $checks++; if ( $ok ) { echo "PASS  {$label}\n"; } else { $failures++; echo "FAIL  {$label}\n"; } }
a440( is_string( $manual ) && is_string( $readiness ) && is_string( $merge ) && is_string( $js ), 'required Issue Credential sources are readable' );
a440( strpos( $manual, 'data-certloom-independent-detail-suggestions' ) < strpos( $manual, 'data-certloom-oneoff-details' ), 'design-required suggestions stay visible above the collapsed one-off credential-detail editor' );
a440( false !== strpos( $readiness, "0 === strpos( \$token, 'recipient.' )" ) && false !== strpos( $readiness, "'action' => 'credential_detail'" ), 'unknown legacy recipient.<key> design tokens become issued-only credential details' );
a440( false !== strpos( $readiness, "'credential.custom.' => 18" ) && false !== strpos( $readiness, "'credential:' => 11" ) && false !== strpos( $readiness, "'custom.' => 7" ), 'bounded historical custom token spellings are normalized into one credential-detail key' );
a440( false !== strpos( $merge, "'recipient.' . \$key" ) && false !== strpos( $merge, "'credential.custom.' . \$key" ), 'one-off issued values can satisfy the exact historical token required by the selected design' );
a440( false !== strpos( $js, 'certloomNormalizeDesignReadinessField' ) && false !== strpos( $js, "'Credential context' === source" ), 'browser repairs stale preview payloads that still classify an addable design fact as Credential context' );
a440( false !== strpos( $js, "normalized.action = 'credential_detail'" ) && false !== strpos( $js, "normalized.field_key = key" ), 'repaired readiness rows use Add value semantics with a concrete field key' );
a440( false !== strpos( $js, 'data-certloom-suggested-field' ) && false !== strpos( $js, "'<span aria-hidden=\"true\">+</span> '" ), 'missing field is surfaced as a visible plus Add action' );
a440( false !== strpos( $js, 'credentialDetailMappingCandidates' ) && false !== strpos( $js, '[data-certloom-scoped-value-key],[data-certloom-independent-value-key]' ), 'Assign existing detail can discover catalog, scoped and already-added credential values' );
a440( false !== strpos( $admin, "'designDataCredentialDetails' => __( 'Credential details', 'certloom' )" ), 'compatibility normalization uses localized Credential details ownership copy' );
echo "\n" . ( $checks - $failures ) . " alpha.4.40 design-field actionability checks, {$failures} failures\n";
exit( $failures ? 1 : 0 );
