<?php
/** Alpha.4.26 Issue Credential visual/source contract. */
$root=dirname(__DIR__);
$m=file_get_contents($root.'/src/Infrastructure/Admin/ManualIssuanceAdmin.php');
$c=file_get_contents($root.'/admin/assets/certloom-foundation.css');
$j=file_get_contents($root.'/admin/assets/admin.js');
$a=file_get_contents($root.'/admin/class-ecapla-certverify-admin.php');
$pass=0;$fail=0; function i426($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
i426(false!==strpos($m,'certloom-page-hero certloom-issue-hero')&&false!==strpos($m,"__( 'Credential operations', 'certloom' )"),'Issue credential now participates in shared hero hierarchy');
i426(false===strpos($m,'certloom-ui-contract-badge')&&false===strpos($m,'certloom-phase2-backend-boundary'),'developer UI-contract/alpha-boundary chrome removed from primary compose flow');
i426(false!==strpos($m,'render_technical_status')&&false!==strpos($m,'certloom-issue-technical-status'),'technical/release detail remains available in a collapsed disclosure');
i426(false!==strpos($m,'certloom-review-readiness')&&false!==strpos($m,'data-certloom-phase2-compatibility-message'),'backend readiness is translated into concise actionable form feedback');
i426(false!==strpos($c,'/* alpha.4.26 — Issue Credential visual hierarchy.')&&false!==strpos($c,'width: min(1180px, 100%)'),'alpha.4.26 visual contract gives the task a coherent bounded width');
i426(false!==strpos($c,'.certloom-manual-issuance .certloom-manual-steps li.is-current::after')&&false!==strpos($c,'var(--cl-brand-emerald)'),'issuance progress uses Certloom brand progress states');
i426(false!==strpos($c,'grid-template-areas:')&&false!==strpos($c,'"search select"'),'Recipient lookup is compact on desktop');
i426(false!==strpos($c,'.certloom-issue-v2-form .certloom-issue-actions')&&false!==strpos($c,'.certloom-review-action-copy'),'review action has deliberate footer hierarchy');
i426(false!==strpos($a,'Choose “Use a Certloom design” to review this credential in the current build.')&&false!==strpos($j,'Ready to review. Nothing is issued until you confirm on the next step.'),'localized/browser readiness copy avoids backend jargon');
i426(false===strpos($c,'.certloom-manual-issuance .certloom-manual-steps li.is-current !important'),'new visual layer adds no !important override');
echo "\n$pass alpha.4.26 Issue Credential source checks, $fail failures\n"; exit($fail?1:0);
