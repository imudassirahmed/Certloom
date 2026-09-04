<?php
/** UX-SYSTEM-01 — external audit reconciliation and fail-safe UI contracts. */
$root = dirname( __DIR__ ); $pass = 0; $fail = 0;
function uxsys( $ok, $label ) { global $pass,$fail; if ($ok){$pass++;echo "PASS  {$label}\n";}else{$fail++;echo "FAIL  {$label}\n";} }
function uxsyssrc($root,$path){$v=file_get_contents($root.'/'.$path);return false===$v?'':$v;}
$css=uxsyssrc($root,'admin/assets/admin.css'); $public=uxsyssrc($root,'public/assets/certloom-public.css');
$nav=uxsyssrc($root,'src/Infrastructure/Admin/AdminProductNavigation.php'); $tpl=uxsyssrc($root,'src/Infrastructure/Admin/TemplateAdmin.php');
$tplcss=uxsyssrc($root,'admin/assets/template-library.css'); $designer=uxsyssrc($root,'admin/assets/designer.js'); $designcss=uxsyssrc($root,'admin/assets/designer.css');
$render=uxsyssrc($root,'includes/class-ecapla-certverify-render.php'); $status=uxsyssrc($root,'src/Domain/Credential/StatusService.php');
$list=uxsyssrc($root,'admin/class-ecapla-certverify-list-table.php'); $bootstrap=uxsyssrc($root,'certloom.php'); $admin=uxsyssrc($root,'admin/class-ecapla-certverify-admin.php');
$frontend=uxsyssrc($root,'admin/views/frontend-manager.php'); $dashboard=uxsyssrc($root,'admin/views/dashboard.php'); $reminder=uxsyssrc($root,'src/Infrastructure/Admin/SetupReadinessReminder.php');

uxsys(false!==strpos($css,':root {') && false!==strpos($css,'--certloom-accent: #0f6e56'), 'Global tokens use the canonical PHP brand accent');
uxsys(false===strpos(uxsyssrc($root,'src/Infrastructure/Admin/ManualIssuanceAdmin.php'),'class="wrap certloom-shell'), 'Manual Issuance no longer uses undefined certloom-shell');
uxsys(false===strpos(uxsyssrc($root,'src/Infrastructure/Admin/BulkIssuanceAdmin.php'),'class="wrap certloom-shell'), 'Bulk Issuance no longer uses undefined certloom-shell');
uxsys(false===strpos(uxsyssrc($root,'src/Infrastructure/Admin/EmailDeliveryAdmin.php'),'class="wrap certloom-shell'), 'Email Delivery no longer uses undefined certloom-shell');
foreach (array('src/Infrastructure/Admin/ManualIssuanceAdmin.php','src/Infrastructure/Admin/BulkIssuanceAdmin.php','src/Infrastructure/Admin/EmailDeliveryAdmin.php','src/Infrastructure/Admin/TemplateAdmin.php','src/Infrastructure/Admin/DomainDataToolsAdmin.php') as $file) {
    $s=uxsyssrc($root,$file); uxsys(false!==strpos($s,'ecapla-certverify-admin-wrap') && false!==strpos($s,'certloom-admin-shell'), basename($file).' carries tokens + standard shell');
}
uxsys(1===substr_count($nav,"'certloom-email-delivery'" ) || (false===strpos($nav,"'insights'    => array( 'certloom-analytics', 'certloom-integrations', 'certloom-automations', 'certloom-email-delivery'")), 'Email Delivery is not assigned to both Credentials and Insights');
uxsys(false!==strpos($list,'public function no_items()') && false!==strpos($list,'No credentials yet') && false!==strpos($list,'No credentials match these filters'), 'Credentials has actionable empty and filtered-empty states');
uxsys(false!==strpos($bootstrap,'certloom_activation_redirect_') && false!==strpos($admin,'maybe_redirect_after_activation') && false!==strpos($admin,"admin.php?page=certloom-setup"), 'Fresh activation has one-shot Setup continuation');
uxsys(false!==strpos($dashboard,'Remind me in 3 days') && false!==strpos($dashboard,'Do not remind me again') && false!==strpos($reminder,'DISMISSED_FOREVER'), 'Incomplete Setup reminder supports snooze or permanent dismissal');
uxsys(false!==strpos($frontend,'certloom-embed-design-grid') && false!==strpos($frontend,'certloom-embed-design-preview') && false!==strpos($frontend,'[certloom design='), 'Appearance & Embed merchandises designs with visual previews and copyable shortcodes');
uxsys(false!==strpos($admin,'certloomAdminL10n') && false!==strpos(uxsyssrc($root,'admin/assets/admin.js'),'navigator.clipboard') && false!==strpos(uxsyssrc($root,'admin/assets/admin.js'),'certloom-copy-button'), 'Shortcode copy enhancement has localized Clipboard + fallback behavior');
uxsys(false!==strpos($tpl,'certloom-template-library-filter-advanced'), 'Template Library emits its advanced-filter grid class');
uxsys(false!==strpos($css,'.certloom-template-state-draft'), 'Draft lifecycle state has explicit styling');
uxsys(false!==strpos($tplcss,'.certloom-design-nav .is-current') && false!==strpos($tplcss,'border-bottom-color:var(--certloom-accent)') && false!==strpos($css,'.certloom-domain-pagination'), 'Template Library current state and shared pagination contracts use the design system');
uxsys(false===strpos($designer,"certloom-template-designer-element-' +") && false!==strpos($designcss,'.is-barcode') && false!==strpos($designcss,'.is-shape') && false!==strpos($designcss,'.is-list'), 'Designer elements have deliberate type semantics without dead dynamic classes');
uxsys(false!==strpos($designer,"'s' === event.key.toLowerCase()") && false!==strpos($designer,"'Delete' === event.key"), 'Designer includes save and delete keyboard shortcuts in addition to existing history/nudge controls');
uxsys(false!==strpos($render,'certloom-verification-label') && false!==strpos($render,'I have a verification code') && false!==strpos($render,'certloom-verification-code-disclosure'), 'Public verification uses visible labels and optional-code disclosure');
uxsys(false!==strpos($css,'.certloom-bulk-status.is-paused'), 'Paused bulk job has explicit status styling');
uxsys(false!==strpos($public,'status--voided') && false!==strpos($public,'status--not_issued'), 'Portal voided/not-issued states have explicit status styling');
uxsys(false!==strpos($public,'neutral by default; only explicit valid earns green') && false!==strpos($public,'.cv-status-valid .cv-status-mark'), 'Public status badge fails neutral and opts valid into green');

preg_match_all("/result\\(\\s*(?:true|false),\\s*'([a-z_]+)'/",$status,$m); $verdicts=array_values(array_unique($m[1])); sort($verdicts);
foreach($verdicts as $v){ uxsys(false!==strpos($public,'.cv-status-'.$v.' .cv-status-mark'), 'Public CSS covers StatusService verdict '.$v); }
uxsys(false!==strpos($public,'.cv-status-unknown .cv-status-mark'), 'Unknown public verdict fails neutral explicitly');

uxsys(0===substr_count(strtolower($css),'#126b55') && 1===substr_count(strtolower($css),'#2271b1'), 'Brand/current-state styling no longer bleeds WordPress blue; platform blue remains only the focus token');
$css_lines=preg_split('/\R/',$css); $css_longest=0; foreach($css_lines as $line){$css_longest=max($css_longest,strlen($line));}
uxsys($css_longest<=500, 'Admin CSS has no minified mega-lines longer than 500 characters');
uxsys(false!==strpos($tpl,'Create from scratch, customize a built-in certificate, or import a Certloom design package.') && false!==strpos($tpl,'Clear filters') && false!==strpos($tpl,'certloom-empty-state-actions'), 'Design empty states always offer a next action and distinguish filtered-empty results');
$bulk=uxsyssrc($root,'src/Infrastructure/Admin/BulkIssuanceAdmin.php'); $bulk_lines=preg_split('/\R/',$bulk); $bulk_longest=0; foreach($bulk_lines as $line){$bulk_longest=max($bulk_longest,strlen($line));}
uxsys(count($bulk_lines)>=200 && $bulk_longest<1000, 'Bulk Issuance production source is expanded into reviewable PHP instead of compressed one-line logic');

$translation_missing=0; foreach(array('admin','includes','public','src') as $dir){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));foreach($it as $file){if(!$file->isFile()||'php'!==strtolower($file->getExtension()))continue;$source=file_get_contents($file->getPathname());if(false===$source)continue;if(preg_match_all('/\b(?:sprintf|printf)\s*\(\s*(?:(\/\*\s*translators:.*?\*\/\s*))?((?:__|_n|_x|esc_html__|esc_attr__)\s*\()/s',$source,$matches,PREG_SET_ORDER)){foreach($matches as $match){$tail=substr($source,strpos($source,$match[0]),1400);if(preg_match("/(?:__|_n|_x|esc_html__|esc_attr__)\\s*\\(\\s*'((?:\\\\'|[^'])*)'/s",$match[0].$tail,$message)&&preg_match('/%(?!%)(?:\d+\$)?[bcdeEfFgGosuxXdi]/',$message[1])&&empty($match[1])){$translation_missing++;}}}}}
uxsys(0===$translation_missing, 'Placeholder-bearing translated sprintf/printf calls carry translators comments');

preg_match_all('/@media\s*\([^)]*(?:max|min)-width\s*:\s*(\d+)px[^)]*\)/',$css,$media); $allowed=array(480,540,560,600,620,640,700,720,782,900,960,961,980,1000,1050,1080,1100,1180,1380); $unknown=array_diff(array_unique(array_map('intval',$media[1])),$allowed);
uxsys(empty($unknown), 'Responsive contract prevents new one-off breakpoints while legacy widths are reduced deliberately');

printf("\n%d UX-SYSTEM-01 source checks, %d failures\n",$pass+$fail,$fail); exit($fail?1:0);
