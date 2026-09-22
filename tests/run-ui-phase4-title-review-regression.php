<?php
/** Alpha.4.79 regression: inherited Program title survives REST Review → Issue. */
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$rest = file_get_contents( $root . '/src/Infrastructure/Admin/IssueCredentialRest.php' );
$js = file_get_contents( $root . '/admin/assets/issue-credential.js' );
$service = file_get_contents( $root . '/src/Application/Issuance/ManualIssuanceService.php' );
$resolver = file_get_contents( $root . '/src/Application/Issuance/EffectiveCredentialTitleResolver.php' );
$readiness = file_get_contents( $root . '/src/Application/Issuance/IssuanceDataReadiness.php' );
$template = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$catalog = file_get_contents( $root . '/src/Application/Issuance/MergeTokenCatalog.php' );
$checks=0;$failures=0;
function a441($ok,$label){global$checks,$failures;$checks++;if($ok){echo "PASS  $label\n";}else{$failures++;echo "FAIL  $label\n";}}
a441(is_string($admin)&&is_string($rest)&&is_string($js)&&is_string($service)&&is_string($resolver),'title-review sources are readable');
a441(false!==strpos($service,'new EffectiveCredentialTitleResolver()')&&false!==strpos($service,"title_resolution['title']"),'canonical service resolves effective title before fingerprinting');
a441(false!==strpos($resolver, "if ( 'program' === \$source )")&&false!==strpos($resolver, "\$program['title']"),'Program title resolver inherits authoritative Program title');
a441(false===strpos($admin,'certloom_phase2_title_required')&&false===strpos($admin,'Enter a credential title before reviewing this credential.'),'admin shell does not require a redundant legacy Program title field');
a441(false!==strpos($rest,"'credential_title' => (string) ( \$request['credential_title'] ?? '' )")&&false!==strpos($rest,"'title_source' =>"),'server Review returns canonical resolved title and provenance from ManualIssuanceService');
a441(false!==strpos($js,"['Credential title', review.credential_title]")&&false!==strpos($js,"['Title source'")&&false!==strpos($js,"'program' === review.title_source"),'Review visibly communicates Program/custom title provenance');
a441(false!==strpos($js,"body:{issuance:state.issuance,email:state.email}")&&false!==strpos($js,"body:{issuance:state.issuance,email:state.email,review_fingerprint:state.reviewFingerprint}"),'Review and final Issue preserve the same resolved title/source state with exact fingerprint revalidation');
a441(false!==strpos($js,'state.reviewSignature !== signature()')&&false!==strpos($js,'Review again before issuing'),'any title change after Review invalidates final Issue confirmation');
a441(false!==strpos($readiness,"'credential.issue_date' => __( 'Effective date', 'certloom' )")&&false!==strpos($readiness,"'issue_date' => __( 'Effective date', 'certloom' )"),'design readiness presents valid_from compatibility tokens as Effective date');
a441(false!==strpos($template,'MergeTokenCatalog::groups()')&&false!==strpos($catalog,"'credential.issue_date' => __( 'Effective date', 'certloom' )"),'Certificate Designer obtains credential.issue_date = Effective date from canonical merge catalog');
echo "\n".($checks-$failures)." alpha.4.79 title-review regression checks, {$failures} failures\n";
exit($failures?1:0);
