<?php
/** Browser fixture for WIZ-01/02 adaptive setup interaction and layout. */
$state = getenv( 'CERTLOOM_WIZ_STATE' );
if ( ! in_array( $state, array( 'purpose', 'advanced', 'review', 'completed', 'rtl' ), true ) ) { $state = 'purpose'; }
$css = file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.css' );
$js  = file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.js' );
$steps = array( 'Purpose', 'Organization', 'First Program', 'Design', 'Delivery & Verification', 'Backup & Finish', 'Review configuration' );
$purpose_options = array(
    'certification_standards' => 'Certification & Standards',
    'academic_credentials' => 'Academic Credentials',
    'training_courses' => 'Training & Courses',
    'professional_license_membership' => 'Professional License / Membership',
    'awards_recognition' => 'Awards & Recognition',
    'events_participation' => 'Events & Participation',
    'custom_other' => 'Custom / Other',
);
$subcategories = array(
    'certification_standards' => array(
        array('management_systems','Management systems','Certification','Formal certification'),
        array('personnel','Personnel','Certification','Formal certification'),
        array('product_service','Product / service','Certification','Formal certification'),
        array('inspection_assessment','Inspection / assessment','Certification','Formal certification'),
        array('custom_scheme','Custom scheme','Certification','Formal certification'),
    ),
    'academic_credentials' => array(
        array('degree','Degree','Academic credential','Academic and institution-led'),
        array('diploma','Diploma','Academic credential','Academic and institution-led'),
        array('academic_certificate','Academic certificate','Academic credential','Academic and institution-led'),
        array('course_completion','Course completion','Academic credential','Academic and institution-led'),
        array('continuing_education','Continuing education','Academic credential','Academic and institution-led'),
    ),
    'training_courses' => array(
        array('online_course','Online course','Course','Course completion'),
        array('workshop','Workshop','Course','Course completion'),
        array('corporate_training','Corporate training','Course','Course completion'),
        array('safety_compliance','Safety / compliance','Course','Course completion'),
        array('cpd','CPD','Course','Course completion'),
    ),
    'professional_license_membership' => array(
        array('professional_license','Professional license','License','Clear and professional'),
        array('membership','Membership','Membership','Clear and professional'),
    ),
    'awards_recognition' => array(
        array('award','Award','Award','Recognition and achievement'),
        array('recognition','Recognition','Award','Recognition and achievement'),
    ),
    'events_participation' => array(
        array('participation','Participation','Participation credential','Event and participation'),
        array('attendance','Attendance','Participation credential','Event and participation'),
        array('speaker','Speaker','Participation credential','Event and participation'),
    ),
    'custom_other' => array( array('custom','Custom','Credential','Choose your own style') ),
);
function wiz_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$dir = 'rtl' === $state ? 'rtl' : 'ltr';
$current = 'review' === $state || 'completed' === $state ? 6 : 0;
$advanced = 'advanced' === $state;
$completed = 'completed' === $state;
?><!doctype html><html lang="en" dir="<?php echo $dir; ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.fixture-main{min-width:0;padding:18px}.wrap{margin:0}.certloom-page-hero{display:flex;justify-content:space-between;gap:18px;margin-bottom:16px}.certloom-page-hero h1{font-size:28px;margin:2px 0 5px}.certloom-eyebrow{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#646970}.certloom-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px}.certloom-panel-header{display:flex;justify-content:space-between;gap:12px;margin-bottom:14px}.certloom-panel-header h2{margin:2px 0 0}.certloom-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.certloom-form-field{display:grid;gap:6px}.certloom-form-field-wide{grid-column:1/-1}.certloom-form-field input[type=text],.certloom-form-field input[type=number],.certloom-form-field select{width:100%;min-height:40px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 12px;border:1px solid #2271b1;border-radius:4px;background:#fff;color:#2271b1;text-decoration:none}.button-primary{background:#2271b1;color:#fff}.button-link{border:0;background:transparent;color:#2271b1}.certloom-callout{display:flex;gap:10px;padding:12px;border:1px solid #c3c4c7;border-radius:8px;background:#f6f7f7}.notice{padding:10px 12px;border-left:4px solid #2271b1;background:#fff}@media(max-width:782px){.fixture-main{padding:10px}.certloom-form-grid{grid-template-columns:1fr}.certloom-form-field-wide{grid-column:auto}}
<?php echo $css; ?>
</style></head><body><main class="fixture-main"><div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-setup-screen certloom-adaptive-setup">
<section class="certloom-page-hero"><div><span class="certloom-eyebrow">Adaptive setup</span><h1>Set up Certloom</h1><p>Choose what you need, review recommendations, and apply only what you approve.</p></div><?php if($completed): ?><span class="certloom-setup-complete-badge">✓ Setup reviewed</span><?php endif; ?></section>
<div class="certloom-notice-rail" role="region" aria-label="Setup notices"></div>
<nav class="certloom-wizard-progress" aria-label="Setup progress"><?php foreach($steps as $i=>$label): ?><a href="#" class="certloom-wizard-progress-step <?php echo $i===$current?'is-current ':''; ?><?php echo $i<$current?'is-done ':''; ?><?php echo 3===$i && $current>3?'is-skipped':''; ?>" <?php echo $i===$current?'aria-current="step"':''; ?>><span><?php echo $i+1; ?></span><?php echo wiz_h($label); ?></a><?php endforeach; ?></nav>
<div class="certloom-wizard-layout"><main class="certloom-panel certloom-wizard-main"><div class="certloom-panel-header"><div><span class="certloom-eyebrow">Step <?php echo $current+1; ?> of 7</span><h2><?php echo wiz_h($steps[$current]); ?></h2></div></div>
<?php if($current===0): ?>
<form method="post" id="certloom-wizard-step-form" class="certloom-wizard-form" data-certloom-purpose-form><input type="hidden" name="certloom_setup_wizard_action" value="save_step"><input type="hidden" name="wizard_step" value="purpose"><input type="hidden" name="wizard_next_step" value="organization">
<fieldset class="certloom-wizard-mode"><legend>How much setup detail do you want?</legend><div class="certloom-wizard-choice-row"><label><input type="radio" name="workspace_mode" value="simple" <?php echo !$advanced?'checked':''; ?>><strong>Simple</strong><span>The essentials with sensible recommendations.</span></label><label><input type="radio" name="workspace_mode" value="advanced" <?php echo $advanced?'checked':''; ?>><strong>Advanced</strong><span>The same settings with more lifecycle, delivery and integration detail.</span></label></div></fieldset>
<div class="certloom-form-grid"><label class="certloom-form-field certloom-form-field-wide"><span>What will you use Certloom for?</span><select name="purpose" id="certloom-wizard-purpose"><?php foreach($purpose_options as $key=>$label): ?><option value="<?php echo wiz_h($key); ?>" <?php echo 'custom_other'===$key?'selected':''; ?>><?php echo wiz_h($label); ?></option><?php endforeach; ?></select></label><label class="certloom-form-field certloom-form-field-wide"><span>Type of credential or program</span><select name="subcategory" id="certloom-wizard-subcategory"><?php foreach($subcategories as $purpose=>$options): foreach($options as $opt): ?><option value="<?php echo wiz_h($opt[0]); ?>" data-purpose="<?php echo wiz_h($purpose); ?>" data-terminology="<?php echo wiz_h($opt[2]); ?>" data-design="<?php echo wiz_h($opt[3]); ?>" <?php echo 'custom'===$opt[0]?'selected':''; ?>><?php echo wiz_h($opt[1]); ?></option><?php endforeach; endforeach; ?></select></label><label class="certloom-form-field certloom-form-field-wide certloom-wizard-scheme" data-certloom-scheme-field><span>Scheme or reference (optional)</span><input type="text" name="scheme_reference" value=""><small>Examples help organize setup; they are not proof of compliance.</small></label></div><div class="certloom-wizard-actions"><button class="button button-primary" type="submit">Save &amp; continue</button></div></form>
<div class="certloom-callout"><strong>Program creation remains protected</strong><span>The Wizard never bypasses the canonical Program gate.</span></div>
<div class="certloom-wizard-footer-actions"><button class="button">Back</button><form><button class="button-link" type="submit">Skip for now</button></form><button id="save-later" type="submit" class="button-link certloom-wizard-save-exit" form="certloom-wizard-step-form" name="wizard_finish_later" value="1">Save &amp; finish later</button></div>
<?php else: ?>
<p>Review current settings beside your proposed choices.</p><div class="certloom-wizard-review-table-wrap"><table class="widefat striped certloom-wizard-review-table"><thead><tr><th>Setting</th><th>Current</th><th>Proposed</th><th>Source</th></tr></thead><tbody><tr class="has-change"><th>Certificate numbering</th><td>manual</td><td>sequential</td><td>Administrator choice</td></tr><tr><th>First Program</th><td>Production Program admin locked</td><td>Auditor Certification</td><td>Plan only until canonical Program authority is available</td></tr></tbody></table></div><div class="certloom-callout"><strong>Authority stays fail-closed</strong><span>No Program/Template authority is opened.</span></div><form id="certloom-wizard-review-form"><div class="certloom-wizard-actions"><button id="apply-review" class="button button-primary button-hero" type="submit" data-ecapla-confirm="Apply the reviewed setup changes now?">Apply reviewed settings</button></div></form>
<?php endif; ?></main>
<aside class="certloom-panel certloom-wizard-summary"><div class="certloom-panel-header"><div><span class="certloom-eyebrow">Your setup</span><h2>Recommendations</h2></div></div><dl class="certloom-wizard-summary-list"><div><dt>Purpose</dt><dd data-certloom-wizard-summary-purpose>Custom / Other</dd></div><div><dt>Type</dt><dd data-certloom-wizard-summary-type>Custom</dd></div><div><dt>Terminology</dt><dd data-certloom-wizard-summary-terminology>Credential</dd></div><div><dt>Design direction</dt><dd data-certloom-wizard-summary-design>Choose your own style</dd></div></dl><p class="description">Recommendations are editable starting points, not compliance claims.</p><?php if($completed): ?><form class="certloom-wizard-restart"><button class="button" type="submit">Review setup again</button><p class="description">Starts a fresh draft. Live settings stay unchanged until applied.</p></form><?php endif; ?></aside></div></div></main><script><?php echo $js; ?></script></body></html>
