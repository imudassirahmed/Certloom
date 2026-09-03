<?php
/** UX-CORE-10 browser fixture for first-class Email Designs. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'CERTLOOM_PATH', dirname( __DIR__, 2 ) . '/' );
function add_query_arg($args,$url){$sep=strpos($url,'?')===false?'?':'&';return $url.$sep.http_build_query((array)$args);}
function __($v,$d=null){return $v;} function esc_html__($v,$d=null){return $v;} function esc_html_e($v,$d=null){echo htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_attr_e($v,$d=null){echo htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_textarea($v){return htmlspecialchars((string)$v,ENT_NOQUOTES,'UTF-8');} function esc_url($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function admin_url($p=''){return 'https://example.test/wp-admin/'.ltrim((string)$p,'/');} function number_format_i18n($n){return number_format((int)$n);} function absint($v){return abs((int)$v);} function selected($a,$b,$echo=true){$o=(string)$a===(string)$b?' selected="selected"':'';if($echo)echo$o;return$o;} function checked($a,$b=true,$echo=true){$o=(bool)$a===(bool)$b?' checked="checked"':'';if($echo)echo$o;return$o;} function wp_nonce_field($a){echo '<input type="hidden" name="_wpnonce" value="fixture-'.esc_attr($a).'">';} function wp_json_encode($v,$f=0){return json_encode($v,$f);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function wp_kses_post($v){return (string)$v;} function wp_strip_all_tags($v,$r=false){return strip_tags((string)$v);} function sanitize_hex_color($v){return (string)$v;} function esc_url_raw($v){return (string)$v;} function get_bloginfo($k){return 'UTF-8';}
require CERTLOOM_PATH.'src/Application/Rendering/CanonicalJson.php';
require CERTLOOM_PATH.'src/Application/Email/EmailTemplateService.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailDesignPurposes.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailDesignCompiler.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailAutomationRuleRepository.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailTemplateSettingsRepository.php';
require CERTLOOM_PATH.'src/Infrastructure/Email/EmailTemplateCatalogRepository.php';
require CERTLOOM_PATH.'src/Infrastructure/Admin/EmailDesignAdmin.php';
$compiler=new \Certloom\Infrastructure\Email\EmailDesignCompiler();
$compiled=$compiler->compile(\Certloom\Infrastructure\Email\EmailDesignCompiler::default_blocks(),\Certloom\Infrastructure\Email\EmailDesignCompiler::default_theme());
$editing=array('id'=>'issued-main','name'=>'Credential issued','purpose'=>'credential_issued','preheader'=>'Your credential is ready.','active'=>1,'subject'=>'Your {{program_title}} credential','text_body'=>'','blocks'=>$compiled['blocks'],'theme'=>$compiled['theme']);
$designs=array(
 $editing,
 array_merge($editing,array('id'=>'expiry-main','name'=>'Expiry reminder','purpose'=>'expiry_reminder')),
 array_merge($editing,array('id'=>'paused-issued','name'=>'Paused credential email','active'=>0)),
);
$assignments=array(
 array('scope_type'=>'global','scope_id'=>0,'purpose'=>'credential_issued','design_id'=>'issued-main','updated_at'=>'2026-09-03 01:00:00'),
 array('scope_type'=>'program','scope_id'=>7,'purpose'=>'credential_revoked','design_id'=>'missing-revoked','updated_at'=>'2026-09-03 01:00:00'),
);
$issuer_names=array(3=>'Northstar University');$program_names=array(7=>'MBA Program');$batch_names=array();
$design_by_id=array();foreach($designs as$d){$design_by_id[$d['id']]=$d;}
$initial_issuers=array(array('id'=>3,'name'=>'Northstar University'));$initial_programs=array(array('id'=>7,'title'=>'MBA Program'));$context_nonce='fixture-context';$notice='';$error='';$purposes=\Certloom\Infrastructure\Email\EmailDesignPurposes::labels();
$css=file_get_contents(CERTLOOM_PATH.'admin/assets/admin.css');$js=file_get_contents(CERTLOOM_PATH.'admin/assets/email-designer.js');
$l10n=array('addHeading'=>'Heading','addText'=>'Text','addButton'=>'Button','addImage'=>'Image','addDivider'=>'Divider','addSpacer'=>'Spacer','addColumns'=>'Two columns','headingPlaceholder'=>'Your credential is ready','textPlaceholder'=>'Write the message recipients should receive.','buttonText'=>'View credential','buttonUrl'=>'{{verification_url}}','chooseImage'=>'Choose an email image','useImage'=>'Use this image','remove'=>'Remove','moveUp'=>'Move up','moveDown'=>'Move down','blockType'=>'Content block','leftColumn'=>'Left column','rightColumn'=>'Right column','urlLabel'=>'URL','altText'=>'Alternative text','spacingPixels'=>'Spacing (px)','headingLevel'=>'Heading level','sampleRecipient'=>'Sara Khan','sampleProgram'=>'Professional Leadership Program','sampleIssuer'=>'Example Institute','sampleGrade'=>'Distinction','verifyThisCredential'=>'Verify this credential','desktop'=>'Desktop preview','mobile'=>'Mobile preview','previewTitle'=>'Email preview','noCompatibleDesign'=>'No available email design matches this message purpose. Create or activate a compatible design first.','contentBlocksRequired'=>'Add at least one usable content block before saving this email design.','emptyContent'=>'No content blocks yet. Add a Heading, Text, Button, Image or another block to build this message.');
ob_start();include CERTLOOM_PATH.'admin/views/email-designs.php';$view=ob_get_clean();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{margin:20px}.dashicons{display:inline-block}.button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 12px;border:1px solid #2271b1;border-radius:4px;background:#f6f7f7;color:#2271b1;text-decoration:none}.button-primary{background:#2271b1;color:#fff}<?php echo $css; ?></style></head><body><?php echo $view; ?><script>window.certloomEmailDesignerL10n=<?php echo json_encode($l10n); ?>;window.__emailParentPwned=false;</script><script><?php echo $js; ?></script></body></html>
