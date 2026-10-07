<?php
/** Alpha.4.37 design-required credential field binding source contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$service = file_get_contents( $root . '/src/Application/Issuance/ManualIssuanceService.php' );
$readiness = file_get_contents( $root . '/src/Application/Issuance/IssuanceDataReadiness.php' );
$merge = file_get_contents( $root . '/src/Application/Issuance/MergeSnapshotBuilder.php' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$issue_js = file_get_contents( $root . '/admin/assets/issue-credential.js' );
$rest = file_get_contents( $root . '/src/Infrastructure/Admin/IssueCredentialRest.php' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$issue_css = file_get_contents( $root . '/admin/assets/issue-credential.css' );
$checks = 0; $fail = 0;
function a437( $ok, $label ) { global $checks, $fail; $checks++; if ( $ok ) { echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
foreach ( array( 'manual'=>$manual, 'service'=>$service, 'readiness'=>$readiness, 'merge'=>$merge, 'admin'=>$admin, 'js'=>$js, 'issue_js'=>$issue_js, 'rest'=>$rest, 'css'=>$css, 'issue_css'=>$issue_css ) as $name=>$source ) { a437( is_string( $source ) && '' !== $source, 'source ' . $name . ' available' ); }
a437( false !== strpos( $readiness, "0 === strpos( \$token, 'credential.' )" ) && false !== strpos( $readiness, "'action' => 'credential_detail'" ), 'credential.<key> design requirements become actionable issued credential details' );
a437( false !== strpos( $merge, "\$credential_token = 'credential.' . \$key" ) && false !== strpos( $merge, 'in_array( $credential_token, $required, true )' ), 'one-off credential values can satisfy exact required credential.<key> merge tokens' );
a437( false !== strpos( $js, 'data-certloom-design-required-detail' ) && false !== strpos( $js, 'data-certloom-suggested-field' ), 'missing design details render directly beneath Add credential detail' );
a437( false !== strpos( $js, "button.innerHTML = '<span aria-hidden=\"true\">+</span> '") && false !== strpos( $admin, "'designSuggestedAdd' => __( 'Add %s', 'certloom' )" ), 'missing design detail exposes an explicit plus Add field action' );
a437( false !== strpos( $js, 'data-certloom-design-field-map' ) && false !== strpos( $admin, "'designSuggestedMap' => __( 'Assign existing detail', 'certloom' )" ), 'missing design detail exposes Assign existing detail mapping' );
a437( false !== strpos( $js, "input.name = 'design_field_aliases[' + targetKey + ']'" ) && false !== strpos( $js, 'setDesignAlias( field._safeFieldKey, sourceKey )' ), 'browser persists design-to-existing-detail mappings as bounded form data' );
a437( false !== strpos( $issue_js, 'state.issuance.design_field_aliases[target] = source' ) && false !== strpos( $rest, "isset( \$body['issuance'] )" ), 'REST Issue Credential keeps design-field mappings inside the canonical issuance request instead of a parallel form parser' );
a437( false !== strpos( $issue_js, "body:{issuance:state.issuance,email:state.email}" ) && false !== strpos( $issue_js, "body:{issuance:state.issuance,email:state.email,review_fingerprint:state.reviewFingerprint}" ), 'Review and final Issue send the same canonical issuance object, preserving design-field mappings without hidden form state' );
a437( false !== strpos( $service, 'design_field_alias_map' ) && false !== strpos( $service, 'count( $value ) > 30' ), 'service validates a bounded design-field mapping list' );
a437( false !== strpos( $service, "'design_field_aliases' => \$request['design_field_aliases']") && false !== strpos( $service, "'design_field_aliases' => \$review['request']['design_field_aliases']" ), 'review fingerprint inputs and immutable issuance snapshot retain mapping provenance' );
a437( false !== strpos( $service, 'array_key_exists( $target, $out )' ) && false !== strpos( $service, '$out[ $target ] = (string) $out[ $source ]' ), 'mapping cannot overwrite an explicitly supplied target value' );
a437( false !== strpos( $service, '$base_count = count( $rows )' ) && false !== strpos( $service, "\$contract['_meta']['one_off'] = true" ), 'issued-only field contract records one-off extension without mutating reusable Program/Batch configuration' );
a437( false !== strpos( $issue_css, '.certloom-design-required-row' ) && false !== strpos( $issue_css, 'grid-template-columns: minmax(0, 1fr) auto auto' ) && 1 === preg_match( '/\.certloom-design-required-row\s*\{\s*grid-template-columns:\s*1fr;/', $issue_css ), 'design-required field actions have a dedicated responsive presentation contract' );
a437( false !== strpos( $js, 'credentialDetailMappingCandidates( field._safeFieldKey )' ) && false !== strpos( $js, 'key === targetKey' ) && false !== strpos( $js, 'renderIndependentField( sourceKey, true' ), 'Assign existing detail excludes self-mapping and exposes/focuses catalog, scoped and already-added credential details' );

a437( false !== strpos( $manual, 'data-design-required-fields') && false !== strpos( $manual, 'Additional one-off credential data') && strpos( $manual, 'data-design-required-fields') < strpos( $manual, 'Additional one-off credential data'), 'design-required suggestions remain visible before the collapsed one-off credential-detail editor' );
a437( false !== strpos( $readiness, "0 === strpos( \$token, 'recipient.' )") && false !== strpos( $merge, "'recipient.' . \$key"), 'legacy unknown recipient.<key> design tokens become issued-only actionable details without mutating Recipient profile' );
a437( false !== strpos( $js, 'certloomNormalizeDesignReadinessField') && false !== strpos( $js, "'Credential context' === source"), 'browser repairs legacy preview payloads that still report an addable custom design field as generic Credential context' );
echo "\n" . ( $checks - $fail ) . " alpha.4.37 design-field binding source checks, $fail failures\n";
exit( $fail ? 1 : 0 );
