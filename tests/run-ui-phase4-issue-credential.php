<?php
/** Alpha.4.33 Issue Credential data-readiness / real-preview contract. */
$root=dirname(__DIR__);
$m=file_get_contents($root.'/src/Infrastructure/Admin/ManualIssuanceAdmin.php');
$n=file_get_contents($root.'/src/Infrastructure/Admin/AdminProductNavigation.php');
$c=file_get_contents($root.'/admin/assets/certloom-foundation.css');
$j=file_get_contents($root.'/admin/assets/admin.js');
$a=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$pass=0;$fail=0; function i427($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
i427(false!==strpos($m,'certloom-page-hero certloom-issue-hero')&&false!==strpos($m,"__( 'Credential operations', 'certloom' )"),'Issue credential participates in shared hero hierarchy');
i427(false!==strpos($m,'( new AdminProductNavigation() )->render_current_subnav();'),'Issue Credential emits contextual subnav at final server-side position');
i427(false!==strpos($n,"'certloom-issuance'")&&false!==strpos($n,'page_renders_own_subnav'),'early admin_notices navigation is suppressed for Issue Credential');
i427(false===strpos($m,'certloom-ui-contract-badge')&&false===strpos($m,'certloom-phase2-backend-boundary'),'developer UI-contract/alpha-boundary chrome remains outside primary compose flow');
i427(false!==strpos($m,'render_technical_status')&&false!==strpos($m,'certloom-issue-technical-status'),'technical/release detail remains available in a collapsed disclosure');
i427(false!==strpos($m,'certloom-review-readiness')&&false!==strpos($m,'data-certloom-phase2-compatibility-message'),'backend readiness remains concise actionable form feedback');
i427(false!==strpos($c,'/* alpha.4.27 — Issue Credential compact visual hierarchy.')&&false!==strpos($c,'width: min(1140px, 100%)'),'alpha.4.27 bounds the task more tightly');
i427(false!==strpos($c,'min-height: 40px;')&&false!==strpos($c,'margin-top: 12px;')&&false!==strpos($c,'margin-bottom: 12px;'),'workflow stepper has reduced vertical density');
i427(false!==strpos($c,'padding: 14px 20px 16px;'),'compose fieldsets use compact spacing');
i427(false!==strpos($c,'min-height: 76px;')&&false!==strpos($c,'padding: 11px 12px;'),'presentation cards are substantially shorter without removing choices');
i427(false!==strpos($c,'.certloom-manual-issuance .certloom-issue-hero')&&false!==strpos($c,'min-height: 146px;'),'Issue hero is compacted without changing approved watermark artwork');
i427(false!==strpos($c,'grid-template-areas:')&&false!==strpos($c,'"search select"'),'Recipient lookup remains compact on desktop');
i427(false!==strpos($c,'.certloom-issue-v2-form .certloom-issue-actions')&&false!==strpos($c,'padding: 13px 20px 15px;'),'review action footer is tightened while retaining hierarchy');
i427(false!==strpos($a,'Ready to review without certificate artwork.')&&false!==strpos($a,'Choose a PNG or JPEG certificate image to continue.')&&false!==strpos($j,'phase2DetailsReady')&&false!==strpos($j,'phase2ImageReady'),'localized/browser readiness copy supports details-only and uploaded-image review paths');
i427(false!==strpos($m,"array( 'details_only', 'image', 'design' )")&&false!==strpos($m,"'image' === \$presentation")&&false===strpos($m,'certloom_phase2_presentation_backend_pending'),'Manual admin backend no longer blocks details-only review behind the legacy design path');
i427(false!==strpos($m,'certloom-review-action-copy')&&strpos($m,'certloom-review-action-copy')<strpos($m,'data-certloom-manual-review'),'review explanation precedes the primary action in source order');
i427(false!==strpos($c,'/* alpha.4.30 — usable details-only review and a deliberate review-action footer.')&&false!==strpos($c,'grid-template-columns: minmax(0, 1fr) auto;')&&false!==strpos($c,'justify-self: end;'),'alpha.4.30 aligns review copy and primary action as a deliberate footer');
i427(false!==strpos($c,'/* alpha.4.30 — keep certificate appearance task-sized')&&false!==strpos($c,'align-self: start;'),'appearance column no longer stretches into a blank panel when optional details expand');
i427(false===strpos($c,'.certloom-manual-issuance .certloom-manual-steps li.is-current !important'),'new compact layer adds no !important override');

i427(false!==strpos($m,'data-certloom-credential-data')&&false!==strpos($m,"__( 'Credential details', 'certloom' )")&&false!==strpos($m,'data-certloom-independent-details'),'Details-only and Independent issuance expose a first-class Credential details surface');
i427(false!==strpos($m,'data-certloom-design-data-check')&&false!==strpos($m,"__( 'Design data check', 'certloom' )"),'selected Certloom design exposes an explicit data-readiness surface beside the real preview');
i427(false!==strpos($j,'restIssuancePreviewUrl')&&false!==strpos($j,'bodyFromForm')&&false!==strpos($j,"method: 'POST'")&&false!==strpos($j,'certloom:design-readiness'),'design preview posts the exact current issuance form and feeds readiness back into Review state');
i427(false!==strpos($a,"'restIssuancePreviewUrl' => rest_url( 'certloom/v1/admin/issuance-preview' )")&&false!==strpos($a,"'recipientEditBase'"),'admin localization exposes the mutation-free issuance preview route and Recipient edit target');
i427(false!==strpos($c,'.certloom-issue-v2-form > .certloom-presentation-fieldset')&&false!==strpos($c,'position: sticky;')&&false!==strpos($c,'max-height: calc(100vh - 58px);'),'desktop Certificate appearance stays available while the credential-data column scrolls');
i427(false!==strpos($m,'data-certloom-additional-details')&&false===strpos($m,'<details class="certloom-additional-details" open'),'preselected Issuer/Batch/Signer no longer force Additional details open on page load');
i427(false!==strpos($j,'certloom:add-credential-detail')&&false!==strpos($j,'designDataEditRecipient')&&false!==strpos($j,'designDataAddValue'),'missing design fields provide owner-aware actions for Recipient data and credential-specific values');
echo "\n$pass alpha.4.33 Issue Credential source checks, $fail failures\n"; exit($fail?1:0);
