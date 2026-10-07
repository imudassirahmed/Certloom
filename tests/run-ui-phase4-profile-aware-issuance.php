<?php
/** Alpha.4.36 profile-aware issuance and design-driven detail source contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$settings = file_get_contents( $root . '/includes/class-ecapla-certverify-settings.php' );
$presentation = file_get_contents( $root . '/src/Infrastructure/Admin/UsageProfilePresentation.php' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$readiness = file_get_contents( $root . '/src/Application/Issuance/IssuanceDataReadiness.php' );
$merge = file_get_contents( $root . '/src/Application/Issuance/MergeSnapshotBuilder.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$issue_js = file_get_contents( $root . '/admin/assets/issue-credential.js' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$issue_css = file_get_contents( $root . '/admin/assets/issue-credential.css' );
$settings_view = file_get_contents( $root . '/admin/views/settings-manager.php' );
$checks=0;$fail=0;
function a436($ok,$label){global$checks,$fail;$checks++;if($ok){echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
foreach(array('settings'=>$settings,'presentation'=>$presentation,'admin'=>$admin,'manual'=>$manual,'readiness'=>$readiness,'merge'=>$merge,'js'=>$js,'issue_js'=>$issue_js,'css'=>$css,'issue_css'=>$issue_css,'settings_view'=>$settings_view) as $name=>$source){a436(is_string($source)&&''!==$source,'source '.$name.' available');}
a436(false!==strpos($settings,"'usage_profile'              => 'general_other'")&&false!==strpos($settings,"array_key_exists( 'usage_profile', \$raw_data )"),'usage profile is canonical site configuration and unrelated setting saves preserve it');
a436(false!==strpos($admin, "\$settings['usage_profile'] = \$profile")&&false!==strpos($admin,'Ecapla_CertVerify_Settings::save( $settings )')&&false!==strpos($admin, "\$next_settings['usage_profile'] = \$profile"),'Setup Wizard activates the selected profile immediately and the legacy review path preserves the same runtime setting');
foreach(array(
    'training_lms'=>array('Provider','Course / certification','Cohort / session','Trainer / authorized academic authority'),
    'iso_management'=>array('Certification body','Scheme / standard / service line','Audit / issuance cycle','Authorized certification authority'),
    'government_licensing'=>array('Ministry / regulator / agency','License / permit / registration scheme','Application / issuance cycle','Authorized officer / registrar / inspector'),
    'school_results'=>array('School / university / board','Class / course / exam / qualification','Session / semester / cohort','Principal / registrar / controller / dean'),
) as $profile=>$terms){$ok=false!==strpos($presentation,"case '$profile':");foreach($terms as $term){$ok=$ok&&false!==strpos($presentation,$term);}a436($ok,$profile.' has complete profile-aware entity terminology');}
a436(false!==strpos($settings_view,'id="certloom-settings-profile"')&&false!==strpos($settings_view,'name="usage_profile"'),'Settings exposes the active usage profile as an editable site setting');
a436(false!==strpos($admin,"'activeProfileLabel' => __( 'Active profile', 'certloom' )")&&false!==strpos($js,'data-certloom-active-profile'),'admin localization and hero presentation expose one active-profile status badge');
a436(false!==strpos($js,'placeholderReplacements')&&false!==strpos($js,"data-certloom-context-search-placeholder"),'profile terminology adapts form labels and relationship-search placeholders without renaming canonical storage');
a436(false===strpos($manual,"How Certloom organizes common profiles")&&false===strpos($manual,'certloom-profile-context-grid'),'Issue Credential removes the repeated cross-profile key from Additional details');
a436(false!==strpos($manual,'data-design-required-fields')&&strpos($manual,'data-design-required-fields')<strpos($manual,'Additional one-off credential data'),'design-required missing facts stay visible before optional one-off credential data');
a436(false!==strpos($issue_js,'function renderDesignRequired')&&false!==strpos($issue_js,"action.textContent = key && Object.prototype.hasOwnProperty.call(state.issuance.custom_merge_fields, key) ? 'Edit value' : 'Add value'"),'missing design fields expose an explicit Add/Edit value action');
a436(false!==strpos($issue_css,'.certloom-design-required-row'),'design-required actions have a dedicated responsive 4.79 presentation contract');
a436(false!==strpos($readiness,"preg_match( '/^[a-z][a-z0-9_-]{0,63}$/'")&&false!==strpos($readiness,"'action' => 'credential_detail'"),'safe legacy plain design tokens become actionable one-off credential details');
a436(substr_count($merge,'in_array( $key, $required, true )')>=2&&false!==strpos($merge,'$required = $this->required_tokens( $layout )'),'required legacy plain tokens can resolve from Recipient or one-off values without canonical collisions');
a436(false!==strpos($manual,'data-presentation="details_only"')&&false!==strpos($manual,'data-presentation="image"')&&false!==strpos($manual,'data-presentation="design"'),'Certificate appearance is an explicit dedicated step instead of an implicit Program side effect');
a436(false===strpos($issue_js,'autoApplyInheritedDesign')&&false!==strpos($issue_js,"state.issuance.presentation_mode = button.getAttribute('data-presentation')"),'Program selection never silently overwrites an administrator appearance choice');
a436(false!==strpos($manual,'data-design-source="inherited"')&&false!==strpos($manual,'data-design-source="assigned"'),'Certloom design keeps explicit inherited versus one-off assigned provenance');
a436(false!==strpos($issue_js,'function focusOrAddCredentialDetail')&&false!==strpos($issue_js,'data-scoped-input'),'design Add value focuses a Program/Batch scoped field before creating a duplicate one-off field');
a436(false!==strpos($manual,'Additional one-off credential data')&&false!==strpos($manual,'These values are frozen only on this credential')&&false!==strpos($manual,'Independent credential'),'credential-specific one-off details remain available for Program-backed and Independent issuance');
echo "\n" . ($checks-$fail) . " alpha.4.36 profile-aware issuance source checks, $fail failures\n";
exit($fail?1:0);
