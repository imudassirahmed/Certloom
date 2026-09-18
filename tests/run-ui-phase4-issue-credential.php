<?php
/** Alpha.4.34 Issue Credential staging-correction contract. */
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
i427(false!==strpos($c,'/* alpha.4.35 — Certificate appearance follows the page instead of creating a nested scroll region.')&&false!==strpos($c,'position: static;')&&false!==strpos($c,'max-height: none;')&&false!==strpos($c,'overflow: visible;'),'Certificate appearance uses the normal page scroll and does not create a nested scrollbar');
i427(false!==strpos($m,'data-certloom-additional-details')&&false===strpos($m,'<details class="certloom-additional-details" open'),'preselected Issuer/Batch/Signer no longer force Additional details open on page load');
i427(false!==strpos($j,'certloom:add-credential-detail')&&false!==strpos($j,'designDataEditRecipient')&&false!==strpos($j,'designDataAddValue'),'missing design fields provide owner-aware actions for Recipient data and credential-specific values');
i427(false!==strpos($j,"if ( '_wpnonce' === key || '_wp_http_referer' === key ) { return; }")&&false!==strpos($j,"'X-WP-Nonce': certloomL10n.restNonce"),'live issuance preview keeps the Manual action nonce out of REST cookie authentication and uses the dedicated REST nonce header');
i427(false!==strpos($a,"'previewSessionExpired'")&&false!==strpos($j,"'rest_cookie_invalid_nonce' === payload.code"),'expired REST preview sessions receive actionable refresh copy instead of raw WordPress cookie-check text');
i427(strpos($m,'data-certloom-legacy-custom-fields')>strpos($m,'data-certloom-additional-details')&&strpos($m,'data-certloom-legacy-custom-fields')<strpos($m,'render_repeating_data_editor'),'Technical custom fields live below Additional details and before the repeating Subjects/results editor');
i427(false!==strpos($c,'/* alpha.4.34 — Manual Issuance screenshot corrections.')&&false!==strpos($c,'grid-template-columns: repeat(4, minmax(0, 1fr));')&&false!==strpos($c,'.certloom-issue-v2-form .certloom-numbering-summary'),'screenshot correction layer bounds repeating rows and restores deliberate Credential-number spacing');

i427(false!==strpos($m,'certloom-issue-context-rail')&&false!==strpos($m,'certloom-issue-summary')&&false!==strpos($m,'data-certloom-issue-summary-recipient'),'alpha.4.73 uses the formerly empty right side for a live appearance/summary/review rail');
i427(strpos($m,'data-certloom-additional-details')<strpos($m,'certloom-issue-context-rail'),'Additional details now stays in the wide main compose column before the context rail');
i427(false!==strpos($c,'/* alpha.4.73 — Profile-adaptive Compose layout.')&&false!==strpos($c,'grid-template-areas: "main rail"')&&false!==strpos($c,'position: sticky;'),'alpha.4.73 establishes a sticky desktop context rail without changing issuance authority');
i427(false!==strpos($j,'syncIssueSummary')&&false!==strpos($j,'data-certloom-issue-summary-validity'),'live credential summary follows recipient, Program, title, validity and appearance changes');
i427(false!==strpos($m,'data-certloom-repeating-empty')&&false===strpos($m,'if ( ! $rows ) { $rows = array( array() ); }'),'academic Subjects/results no longer render a permanent empty row editor');

i427(false!==strpos($m,'certloom-issue-batch-primary')&&strpos($m,'certloom-issue-batch-primary')<strpos($m,'data-certloom-additional-details'),'alpha.4.78 promotes Batch/cohort beside the primary Program choice');
i427(false!==strpos($m,'certloom-delivery-fieldset')&&strpos($m,'certloom-delivery-fieldset')>strpos($m,'certloom-issue-context-rail'),'alpha.4.78 keeps Email as a first-class decision in the right rail');
i427(false!==strpos($m,"'review' === \$email_after_issue")&&false!==strpos($m,"__( 'Review email next', 'certloom' )")&&false!==strpos($m,'EmailTemplateCatalogRepository'),'alpha.4.78 defaults to review-next email intent without sending during issuance');
i427(false!==strpos($m,'data-certloom-oneoff-details')&&false===strpos($m,'<details class="certloom-oneoff-details" open')&&strpos($m,'data-certloom-independent-detail-suggestions')<strpos($m,'data-certloom-oneoff-details'),'alpha.4.78 collapses optional one-off data while keeping design-required facts visible');
i427(false!==strpos($m,'certloom-validity-compact')&&false!==strpos($m,'certloom-validity-options'),'alpha.4.78 summarizes validity and moves override choices behind Change validity');
i427(false!==strpos($j,'data-certloom-issue-summary-batch')&&false!==strpos($j,'data-certloom-issue-summary-email')&&false!==strpos($j,'syncEmailAfterIssue'),'alpha.4.78 live summary tracks Batch and post-issuance email intent');
i427(false!==strpos($c,'/* alpha.4.78 — Issue Credential task-flow correction.')&&false!==strpos($c,'minmax(390px, 430px)')&&false!==strpos($c,'.certloom-delivery-fieldset .certloom-email-after-issue'),'alpha.4.78 has an explicit desktop task rail and Email layout contract');
i427(false!==strpos($m,'email_next')&&false!==strpos($m,'email_template_id')&&false!==strpos($m,'wp_safe_redirect'),'alpha.4.78 carries review-next email intent only after successful issuance');

echo "\n$pass alpha.4.78 Issue Credential source checks, $fail failures\n"; exit($fail?1:0);
