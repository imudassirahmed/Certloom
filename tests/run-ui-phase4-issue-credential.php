<?php
/** Alpha.4.27 Issue Credential compact visual/source contract. */
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
i427(false!==strpos($a,'Choose “Use a Certloom design” to review this credential in the current build.')&&false!==strpos($j,'Ready to review. Nothing is issued until you confirm on the next step.'),'localized/browser readiness copy remains intact');
i427(false===strpos($c,'.certloom-manual-issuance .certloom-manual-steps li.is-current !important'),'new compact layer adds no !important override');
echo "\n$pass alpha.4.27 Issue Credential source checks, $fail failures\n"; exit($fail?1:0);
