<?php
/** Alpha.4.36 profile-aware issuance and design-driven detail source contract. */
$root = dirname( __DIR__ );
$settings = file_get_contents( $root . '/includes/class-ecapla-certverify-settings.php' );
$presentation = file_get_contents( $root . '/src/Infrastructure/Admin/UsageProfilePresentation.php' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$readiness = file_get_contents( $root . '/src/Application/Issuance/IssuanceDataReadiness.php' );
$merge = file_get_contents( $root . '/src/Application/Issuance/MergeSnapshotBuilder.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$settings_view = file_get_contents( $root . '/admin/views/settings-manager.php' );
$checks=0;$fail=0;
function a436($ok,$label){global$checks,$fail;$checks++;if($ok){echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
foreach(array('settings'=>$settings,'presentation'=>$presentation,'admin'=>$admin,'manual'=>$manual,'readiness'=>$readiness,'merge'=>$merge,'js'=>$js,'css'=>$css,'settings_view'=>$settings_view) as $name=>$source){a436(is_string($source)&&''!==$source,'source '.$name.' available');}
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
a436(false!==strpos($manual,'data-certloom-independent-detail-suggestions')&&false!==strpos($js,'Needed by the selected design')&&false!==strpos($js,'data-certloom-suggested-field'),'design-required missing credential details are suggested directly below Add credential detail');
a436(false!==strpos($js,"button.innerHTML = '<span aria-hidden=\"true\">+</span> '")&&false!==strpos($css,'.certloom-independent-detail-suggestion.button'),'missing design fields expose a compact plus action with deliberate styling');
a436(false!==strpos($readiness,"preg_match( '/^[a-z][a-z0-9_-]{0,63}$/'")&&false!==strpos($readiness,"'action' => 'credential_detail'"),'safe legacy plain design tokens become actionable one-off credential details');
a436(substr_count($merge,"in_array( \$key, \$required, true )")>=2&&false!==strpos($merge,"\$required = \$this->required_tokens( \$layout )"),'required legacy plain tokens can resolve from Recipient or one-off credential custom values without canonical collisions');
a436(false!==strpos($manual,'data-certloom-presentation-explicit')&&false!==strpos($js,"appearanceTouched = '1' === form.getAttribute( 'data-certloom-presentation-explicit' )"),'server and browser preserve an explicit administrator appearance choice');
a436(false!==strpos($js,'function autoApplyInheritedDesign')&&false!==strpos($js,"url.searchParams.set( 'mode', 'inherited' )")&&false!==strpos($js,"setCheckedValue( presentationRadios, 'design' )")&&false!==strpos($js,"setCheckedValue( designSources, 'inherited' )"),'Program changes resolve the authoritative inherited published design and auto-switch appearance when untouched');
a436(false!==strpos($js,'appearanceTouched || ! programId')&&false!==strpos($js,'if ( ! autoApplyingAppearance ) { appearanceTouched = true; }'),'automatic appearance never overwrites a deliberate Details/Image/assigned-design choice');
a436(false!==strpos($js,"var scoped = form.querySelector( '[data-certloom-scoped-value-key=\"' + safeKey + '\"]' )")&&false!==strpos($js,'if ( scoped ) { if ( focus ) { scoped.focus(); } return scoped; }'),'design Add value focuses a Program-scoped field instead of creating a duplicate credential field');
a436(false!==strpos($js,'if ( independent ) { independent.hidden = false; }')&&false!==strpos($admin,'Add one-off credential details below when this issuance or its design needs them.'),'credential-specific one-off details remain available for Program-backed as well as Independent issuance');
echo "\n" . ($checks-$fail) . " alpha.4.36 profile-aware issuance source checks, $fail failures\n";
exit($fail?1:0);
