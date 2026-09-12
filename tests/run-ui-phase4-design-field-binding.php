<?php
/** Alpha.4.37 design-required credential field binding source contract. */
$root = dirname( __DIR__ );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$service = file_get_contents( $root . '/src/Application/Issuance/ManualIssuanceService.php' );
$readiness = file_get_contents( $root . '/src/Application/Issuance/IssuanceDataReadiness.php' );
$merge = file_get_contents( $root . '/src/Application/Issuance/MergeSnapshotBuilder.php' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$checks = 0; $fail = 0;
function a437( $ok, $label ) { global $checks, $fail; $checks++; if ( $ok ) { echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
foreach ( array( 'manual'=>$manual, 'service'=>$service, 'readiness'=>$readiness, 'merge'=>$merge, 'admin'=>$admin, 'js'=>$js, 'css'=>$css ) as $name=>$source ) { a437( is_string( $source ) && '' !== $source, 'source ' . $name . ' available' ); }
a437( false !== strpos( $readiness, "0 === strpos( \$token, 'credential.' )" ) && false !== strpos( $readiness, "'action' => 'credential_detail'" ), 'credential.<key> design requirements become actionable issued credential details' );
a437( false !== strpos( $merge, "\$credential_token = 'credential.' . \$key" ) && false !== strpos( $merge, 'in_array( $credential_token, $required, true )' ), 'one-off credential values can satisfy exact required credential.<key> merge tokens' );
a437( false !== strpos( $js, 'data-certloom-design-required-detail' ) && false !== strpos( $js, 'data-certloom-suggested-field' ), 'missing design details render directly beneath Add credential detail' );
a437( false !== strpos( $js, "button.innerHTML = '<span aria-hidden=\"true\">+</span> '") && false !== strpos( $admin, "'designSuggestedAdd' => __( 'Add %s', 'certloom' )" ), 'missing design detail exposes an explicit plus Add field action' );
a437( false !== strpos( $js, 'data-certloom-design-field-map' ) && false !== strpos( $admin, "'designSuggestedMap' => __( 'Assign existing detail', 'certloom' )" ), 'missing design detail exposes Assign existing detail mapping' );
a437( false !== strpos( $js, "input.name = 'design_field_aliases[' + targetKey + ']'" ) && false !== strpos( $js, 'setDesignAlias( field._safeFieldKey, sourceKey )' ), 'browser persists design-to-existing-detail mappings as bounded form data' );
a437( false !== strpos( $manual, "isset( \$post['design_field_aliases'] )") && false !== strpos( $manual, "'design_field_aliases' => \$design_field_aliases" ), 'Manual Issuance Review parses design-field mappings from the submitted form' );
a437( false !== strpos( $manual, "\$r['design_field_aliases'] ?? array()") && false !== strpos( $manual, 'name="design_field_aliases[' ), 'Review-to-Issue hidden request preserves design-field mappings' );
a437( false !== strpos( $service, 'design_field_alias_map' ) && false !== strpos( $service, 'count( $value ) > 30' ), 'service validates a bounded design-field mapping list' );
a437( false !== strpos( $service, "'design_field_aliases' => \$request['design_field_aliases']") && false !== strpos( $service, "'design_field_aliases' => \$review['request']['design_field_aliases']" ), 'review fingerprint inputs and immutable issuance snapshot retain mapping provenance' );
a437( false !== strpos( $service, 'array_key_exists( $target, $out )' ) && false !== strpos( $service, '$out[ $target ] = (string) $out[ $source ]' ), 'mapping cannot overwrite an explicitly supplied target value' );
a437( false !== strpos( $service, '$base_count = count( $rows )' ) && false !== strpos( $service, "\$contract['_meta']['one_off'] = true" ), 'issued-only field contract records one-off extension without mutating reusable Program/Batch configuration' );
a437( false !== strpos( $css, '.certloom-design-required-detail' ) && false !== strpos( $css, '.certloom-design-required-detail__mapping' ), 'design-required field actions have a dedicated responsive presentation contract' );
a437( false !== strpos( $js, "candidate.key !== field._safeFieldKey") && false !== strpos( $js, 'renderIndependentField( sourceKey, true' ), 'Assign existing detail excludes self-mapping and exposes/focuses the chosen source field' );
echo "\n" . ( $checks - $fail ) . " alpha.4.37 design-field binding source checks, $fail failures\n";
exit( $fail ? 1 : 0 );
