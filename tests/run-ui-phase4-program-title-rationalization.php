<?php
/** Alpha.4.79 Program-first credential-title rationalization contract. */
$root = dirname( __DIR__ );
$service = file_get_contents( $root . '/src/Application/Issuance/ManualIssuanceService.php' );
$resolver = file_get_contents( $root . '/src/Application/Issuance/EffectiveCredentialTitleResolver.php' );
$merge = file_get_contents( $root . '/src/Application/Issuance/MergeSnapshotBuilder.php' );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$rest = file_get_contents( $root . '/src/Infrastructure/Admin/IssueCredentialRest.php' );
$template = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$catalog = file_get_contents( $root . '/src/Application/Issuance/MergeTokenCatalog.php' );
$samples = file_get_contents( $root . '/src/Application/Templates/TemplatePreviewSamples.php' );
$js = file_get_contents( $root . '/admin/assets/issue-credential.js' );
$css = file_get_contents( $root . '/admin/assets/issue-credential.css' );
$presenter = file_get_contents( $root . '/src/Application/PublicCredentialPresenter.php' );
$render = file_get_contents( $root . '/includes/class-ecapla-certverify-render.php' );
$pass=0;$fail=0;
function p432($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
p432(false!==strpos($resolver,"'program'")&&false!==strpos($resolver,"'custom'"),'effective title resolver has closed Program/custom modes');
p432(false!==strpos($resolver,'certloom_credential_title_program_required')&&false!==strpos($resolver,'certloom_credential_title_required'),'effective title resolver fails closed for missing Program or independent title');
p432(false!==strpos($service,"'credential_basis'")&&false!==strpos($service,"'independent'")&&false!==strpos($service,'certloom_manual_issue_program_required'),'issuance service normalizes explicit Program versus Independent basis');
p432(false!==strpos($service,'new EffectiveCredentialTitleResolver()')&&false!==strpos($service,"credential_title'] = (string)")&&false!==strpos($service,"title_resolution['title']"),'canonical issuance review resolves title centrally before fingerprinting');
p432(false!==strpos($service,"'title_source' => (string)")&&false!==strpos($service,"'title_program_id' => absint"),'immutable snapshot freezes title source and Program provenance');
p432(false!==strpos($merge,"'credential.title'")&&false!==strpos($merge,"'credential_title'"),'merge snapshot exposes canonical credential.title and compatibility alias');
p432(false!==strpos($service,"'title' => (string)")&&false!==strpos($service,"current['request']['credential_title']"),'commit-time merge facts include resolved credential title');

p432(false!==strpos($admin,'data-credential-basis="program"')&&false!==strpos($admin,'data-credential-basis="independent"'),'Issue Credential exposes Program versus Independent basis in the primary context step');
p432(false!==strpos($admin,"render_async_picker( 'program'")&&false!==strpos($admin,'data-program-context'),'profile-aware Program picker is first-class instead of duplicated under advanced options');
p432(false===strpos($admin,"Program (optional)"),'the deleted mega-form no longer duplicates an optional Program selector');
p432(false!==strpos($admin,'data-field="credential_title"')&&false!==strpos($admin,'The title stays editable even when a Program supplies the default.'),'credential title is always visible and directly overridable');
p432(false!==strpos($rest,'private function program_choice')&&false!==strpos($rest,"'title' => (string)")&&false!==strpos($rest,"row['title']"),'Program REST choices transport exact title separately from display label');
p432(false!==strpos($rest,'public function quick_program')&&false!==strpos($rest,"'program' => ")&&false!==strpos($rest,"program_choice("),'quick-created Programs return the same title-bearing choice contract immediately');
p432(false!==strpos($js,"state.issuance.credential_title = String(choice.title || choice.label || '')")&&false!==strpos($js,"state.issuance.title_source = 'program'"),'selecting a Program prefills and marks its title as inherited');
p432(false!==strpos($js,"state.issuance.expiry_mode = choice.program_validity_available ? 'program' : 'permanent'"),'selecting a Program applies its validity default when available');
p432(false!==strpos($js,"state.credentialBasis = basis")&&false!==strpos($js,"state.issuance.title_source = 'custom'")&&false!==strpos($js,"clearPicker('program'"),'Independent mode clears Program context and switches to explicit title provenance');
p432(false!==strpos($js,"state.issuance.title_source = state.issuance.program_id && value === programTitle ? 'program' : 'custom'")&&false!==strpos($js,'data-use-program-title'),'direct title typing becomes a one-off custom title and offers Program-title reset');
p432(false!==strpos($js,"state.issuance.credential_title = String(state.selected.program.title || state.selected.program.label || '')")&&false!==strpos($js,"state.issuance.title_source = 'program'"),'Use Program title restores exact Program title without modifying the Program');
p432(false!==strpos($js,"state.issuance.batch_id = 0")&&false!==strpos($js,"searchPicker('batch', '', true)"),'fresh Program selection also resets and reloads dependent Batch context');

p432(false!==strpos($template,'MergeTokenCatalog::groups()')&&false!==strpos($catalog,"'credential.title'"),'Certificate Designer exposes credential.title through the canonical merge catalog');
p432(false!==strpos($samples,"'credential.title' => 'Professional Credential Program'")&&false!==strpos($samples,"standard['credential.title'] =")&&false!==strpos($samples,"standard['program.title']"),'designer preview samples model credential title independently while following Program title by default');
p432(false!==strpos($css,'.certloom-issue-step')&&false!==strpos($css,'.certloom-segmented'),'Program/title semantics have dedicated eight-step app styling');
p432(false!==strpos($rest,"'title_source' =>")&&false!==strpos($js,"['Title source'")&&false!==strpos($js,"'program' === review.title_source"),'Review visibly communicates resolved Program/custom title provenance');
p432(false!==strpos($js,"body:{issuance:state.issuance,email:state.email}")&&false!==strpos($js,"body:{issuance:state.issuance,email:state.email,review_fingerprint:state.reviewFingerprint}"),'Review and Issue reuse the same title-bearing canonical issuance state');
p432(false===strpos($admin,'certloom_phase2_title_required')&&false===strpos($admin,'Enter a credential title before reviewing this credential.'),'admin shell does not reimplement canonical title resolution with legacy validation');
p432(false!==strpos($presenter,"'credential_title'")&&false!==strpos($presenter,"credential_snapshot['title']")&&false!==strpos($presenter,'$credential_title = $program_title'),'public profile presents immutable credential title with Program fallback for older snapshots');
p432(false!==strpos($render,"profile['credential_title']")&&false!==strpos($render,"__( 'Program', 'certloom' )"),'public credential hero uses exact credential title and keeps Program as a distinct fact');

echo "\n$pass alpha.4.79 Program/title source checks, $fail failures\n"; exit($fail?1:0);
