<?php
/** UX-CORE-10 unified workspace + Email Design authority contract. */
$root = dirname( __DIR__ );
$files = array(
	'ui' => file_get_contents( $root . '/src/Infrastructure/Admin/AdminUiMode.php' ),
	'nav' => file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' ),
	'plugin' => file_get_contents( $root . '/src/Plugin.php' ),
	'design_admin' => file_get_contents( $root . '/src/Infrastructure/Admin/EmailDesignAdmin.php' ),
	'design_view' => file_get_contents( $root . '/admin/views/email-designs.php' ),
	'designer_js' => file_get_contents( $root . '/admin/assets/email-designer.js' ),
	'delivery_admin' => file_get_contents( $root . '/src/Infrastructure/Admin/EmailDeliveryAdmin.php' ),
	'delivery' => file_get_contents( $root . '/src/Application/Email/EmailDeliveryCoordinator.php' ),
	'assignments' => file_get_contents( $root . '/src/Infrastructure/Email/EmailDesignAssignmentRepository.php' ),
	'automation' => file_get_contents( $root . '/src/Infrastructure/Email/EmailAutomationRuleRepository.php' ),
	'listener' => file_get_contents( $root . '/src/Infrastructure/Email/EmailAutomationListener.php' ),
	'automation_view' => file_get_contents( $root . '/admin/views/automations.php' ),
	'setup' => file_get_contents( $root . '/admin/views/setup-wizard.php' ),
	'uninstall' => file_get_contents( $root . '/uninstall.php' ),
	'css' => file_get_contents( $root . '/admin/assets/admin.css' ),
	'public_css' => file_get_contents( $root . '/public/assets/certloom-public.css' ),
);
$pass=0;$fail=0;
function ux10($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
ux10(false!==strpos($files['ui'],'return self::UNIFIED;')&&false!==strpos($files['ui'],'is_simple() { return true; }')&&false!==strpos($files['ui'],'is_advanced() { return false; }'),'customer-facing Simple/Advanced split is retired behind one compatibility shim');
ux10(false!==strpos($files['nav'],'private const PRIMARY')&&false===strpos($files['nav'],'PRIMARY_SIMPLE')&&false===strpos($files['nav'],'PRIMARY_ADVANCED'),'product navigation has one canonical primary map');
ux10(false!==strpos($files['nav'],"'certloom-email-designs'")&&false!==strpos($files['nav'],"__( 'Email designs', 'certloom' )"),'Design navigation exposes Email designs as a first-class workspace');
ux10(false!==strpos($files['plugin'],'new \\Certloom\\Infrastructure\\Admin\\EmailDesignAdmin'),'Email Design admin is booted by the plugin');
ux10(false!==strpos($files['design_admin'],'maybe_upgrade_capabilities()')&&false!==strpos($files['design_admin'],"add_submenu_page( 'certloom'")&&false!==strpos($files['design_admin'],'CAP_MANAGE_CREDENTIALS'),'Email Designs refreshes upgraded capability state immediately before WordPress route registration and keeps explicit capability enforcement');
ux10(false!==strpos($files['design_view'],'Global → Issuer → Program → Batch')&&false!==strpos($files['assignments'],"array( 'batch'")&&false!==strpos($files['assignments'],"array( 'program'")&&false!==strpos($files['assignments'],"array( 'issuer'")&&false!==strpos($files['assignments'],"array( 'global'"),'email design inheritance resolves Batch → Program → Issuer → Global');
ux10(false!==strpos($files['assignments'],'certloom_email_assignment_broken')&&false!==strpos($files['assignments'],'Do not use this fallback to conceal a broken rule'),'explicit broken inheritance fails closed rather than silently falling back');
ux10(false!==strpos($files['design_view'],'Only Credential issued has an automatic trigger')&&false!==strpos($files['automation'],"'trigger'     => 'credential_issued'"),'only Credential issued is claimed/wired as the automatic email trigger in this tranche');
ux10(false===strpos($files['automation_view'],'name="template_id"')&&false!==strpos($files['automation_view'],'Email design')&&false!==strpos($files['automation_view'],'Automatic'),'Automations no longer own reusable email design selection');
ux10(false!==strpos($files['automation'],'legacy_template_id')&&false!==strpos($files['listener'],"'certloom_email_design_unresolved'")&&false!==strpos($files['listener'],'legacy_template_id'),'legacy Automation template pin is compatibility-only and used only for genuinely unresolved hierarchy');
ux10(false!==strpos($files['automation'],'best_matching')&&false!==strpos($files['listener'],'best_matching'),'overlapping email Automations resolve to one deterministic most-specific rule');
ux10(false!==strpos($files['delivery_admin'],'Automatic — use email hierarchy')&&false!==strpos($files['delivery_admin'],'One-send override'),'manual Email Delivery distinguishes automatic inheritance from one-send override');
ux10(false!==strpos($files['delivery_admin'],"saved['selection_value']")&&false!==strpos($files['delivery_admin'],'$selection')&&false!==strpos($files['delivery_admin'],'composition_sha256'),'Preview → Send preserves selection intent and rechecks composition before queueing');
ux10(false!==strpos($files['delivery'],'selection_intent')&&false!==strpos($files['delivery'],'resolution_scope')&&false!==strpos($files['delivery'],'message_purpose'),'delivery evidence records email selection intent, hierarchy source and purpose');
ux10(false!==strpos($files['delivery_admin'],'maybe_upgrade_capabilities()')&&false!==strpos($files['delivery_admin'],'add_submenu_page(')&&false!==strpos($files['nav'],"'certloom-email-delivery'"),'Email Delivery refreshes upgraded capability state before route registration and remains routable after submenu consolidation');
ux10(false!==strpos($files['delivery_admin'],'No legacy template changes were applied')&&false===strpos($files['delivery_admin'],'email_delivery()->save_template'),'legacy Email Delivery content endpoint is non-mutating');
ux10(false!==strpos($files['designer_js'],'sandbox')||false!==strpos($files['design_view'],'iframe sandbox=""'),'Email Designer preview is isolated in a sandboxed iframe');
ux10(false!==strpos($files['designer_js'],'Content-Security-Policy')&&false!==strpos($files['designer_js'],"default-src \\'none\\'"),'Email Designer preview has an explicit restrictive CSP');
ux10(false!==strpos($files['designer_js'],"selected==='batch'?(target!=='program'&&target!=='batch')"),'Batch inheritance exposes Program context so large-site Batch search remains usable');
ux10(false!==strpos($files['css'],'.certloom-email-builder-grid')&&false!==strpos($files['css'],'@media(max-width:782px)')&&false!==strpos($files['css'],'min-height:44px'),'Email Designer has responsive, mobile-touch-aware permanent styling');
ux10(false!==strpos($files['public_css'],'UX-CORE-10 public hardening')&&false!==strpos($files['public_css'],'overflow-wrap:anywhere')&&false!==strpos($files['public_css'],'forced-colors:active'),'public credential surfaces retain overflow/accessibility hardening');
ux10(false===strpos($files['setup'],'$wizard_mode')&&false===strpos($files['setup'],'name="workspace_mode"'),'Setup Wizard no longer depends on the retired mode selector or stale variable');
ux10(false!==strpos($files['uninstall'],"delete_option( 'certloom_email_design_assignments_v1' )")&&false!==strpos($files['uninstall'],"delete_option( 'certloom_email_template_issued_v1' )")&&false!==strpos($files['uninstall'],"'_transient_certloom_email_preview_'"),'uninstall hygiene removes new Email Design authority and short-lived review state');
ux10(!file_exists($root.'/resources/domain/accepted.json')&&!file_exists($root.'/resources/renderer/accepted.json'),'UX-CORE-10 does not manufacture domain or renderer authority');
echo "\n$pass UX-CORE-10 checks, $fail failures\n"; exit($fail?1:0);
