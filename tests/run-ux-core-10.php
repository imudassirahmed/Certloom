<?php
/** UX-CORE-10 unified workspace + Email Design authority contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$files = array(
	'ui' => file_get_contents( $root . '/src/Infrastructure/Admin/AdminUiMode.php' ),
	'nav' => file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' ),
	'plugin' => file_get_contents( $root . '/src/Plugin.php' ),
	'design_admin' => file_get_contents( $root . '/src/Infrastructure/Admin/EmailDesignAdmin.php' ),
	'design_view' => file_get_contents( $root . '/admin/views/email-designs.php' ),
	'designer_view' => file_get_contents( $root . '/admin/views/email-designer.php' ),
	'design_rules' => file_get_contents( $root . '/src/Infrastructure/Admin/DesignRulesAdmin.php' ),
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
	'email_css' => file_get_contents( $root . '/admin/assets/email-designs.css' ),
	'public_css' => file_get_contents( $root . '/public/assets/certloom-public.css' ),
);
$pass=0;$fail=0;
function ux10($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
ux10(false!==strpos($files['ui'],'return self::UNIFIED;')&&false!==strpos($files['ui'],'is_simple() { return true; }')&&false!==strpos($files['ui'],'is_advanced() { return false; }'),'customer-facing Simple/Advanced split is retired behind one compatibility shim');
ux10(false!==strpos($files['nav'],'private const PRIMARY')&&false===strpos($files['nav'],'PRIMARY_SIMPLE')&&false===strpos($files['nav'],'PRIMARY_ADVANCED'),'product navigation has one canonical primary map');
ux10(false!==strpos($files['nav'],"'certloom-email-designs'")&&false!==strpos($files['nav'],"__( 'Email designs', 'certloom' )"),'Design navigation exposes Email designs as a first-class workspace');
ux10(false!==strpos($files['plugin'],'new \\Certloom\\Infrastructure\\Admin\\EmailDesignAdmin'),'Email Design admin is booted by the plugin');
ux10(false!==strpos($files['design_admin'],'maybe_upgrade_capabilities()')&&false!==strpos($files['design_admin'],'add_submenu_page(')&&false!==strpos($files['design_admin'],"\t\t\tnull,")&&false!==strpos($files['design_admin'],'CAP_MANAGE_CREDENTIALS')&&false!==strpos($files['design_admin'],'current_user_can( ECAPLA_CERTVERIFY_CAP )'),'Email Designs is a parentless contextual route registered on the granular capability while its callback retains established-management compatibility');
ux10(false!==strpos($files['design_rules'],'Global defaults')&&false!==strpos($files['design_rules'],'Issuer, Program and Batch rules')&&false!==strpos($files['assignments'],"array( 'batch'")&&false!==strpos($files['assignments'],"array( 'program'")&&false!==strpos($files['assignments'],"array( 'issuer'")&&false!==strpos($files['assignments'],"array( 'global'"),'email design inheritance remains Batch → Program → Issuer → Global while assignment UI lives in Design Rules');
ux10(false!==strpos($files['assignments'],'certloom_email_assignment_broken')&&false!==strpos($files['assignments'],'Restore inheritance or choose another design before sending.'),'explicit broken inheritance fails closed rather than silently falling back');
ux10(false!==strpos($files['design_rules'],'Only Credential issued is an automatic trigger in this release.')&&false!==strpos($files['automation'],"'trigger'     => 'credential_issued'"),'only Credential issued is claimed/wired as the automatic email trigger in this tranche');
ux10(false===strpos($files['automation_view'],'name="template_id"')&&false!==strpos($files['automation_view'],'Email design')&&false!==strpos($files['automation_view'],'Automatic'),'Automations no longer own reusable email design selection');
ux10(false!==strpos($files['automation'],'legacy_template_id')&&false!==strpos($files['listener'],"'certloom_email_design_unresolved'")&&false!==strpos($files['listener'],'legacy_template_id'),'legacy Automation template pin is compatibility-only and used only for genuinely unresolved hierarchy');
ux10(false!==strpos($files['automation'],'best_matching')&&false!==strpos($files['listener'],'best_matching'),'overlapping email Automations resolve to one deterministic most-specific rule');
ux10(false!==strpos($files['delivery_admin'],'Automatic — use email hierarchy')&&false!==strpos($files['delivery_admin'],'One-send override'),'manual Email Delivery distinguishes automatic inheritance from one-send override');
ux10(false!==strpos($files['delivery_admin'],"saved['selection_value']")&&false!==strpos($files['delivery_admin'],'$selection')&&false!==strpos($files['delivery_admin'],'composition_sha256'),'Preview → Send preserves selection intent and rechecks composition before queueing');
ux10(false!==strpos($files['delivery'],'selection_intent')&&false!==strpos($files['delivery'],'resolution_scope')&&false!==strpos($files['delivery'],'message_purpose'),'delivery evidence records email selection intent, hierarchy source and purpose');
ux10(false!==strpos($files['delivery_admin'],'maybe_upgrade_capabilities()')&&false!==strpos($files['delivery_admin'],'add_submenu_page(')&&false!==strpos($files['delivery_admin'],"\t\t\tnull,")&&false!==strpos($files['delivery_admin'],'ECAPLA_CERTVERIFY_CAP')&&false!==strpos($files['delivery_admin'],'CAP_MANAGE_CREDENTIALS')&&false!==strpos($files['nav'],"'certloom-email-delivery'"),'Email Delivery is parentless at route registration and remains routable after unified submenu consolidation');
ux10(false!==strpos($files['delivery_admin'],'No legacy template changes were applied')&&false===strpos($files['delivery_admin'],'email_delivery()->save_template'),'legacy Email Delivery content endpoint is non-mutating');
ux10(false!==strpos($files['designer_view'],'iframe')&&false!==strpos($files['designer_view'],'sandbox=""'),'Email Designer preview is isolated in a sandboxed iframe');
ux10(false!==strpos($files['designer_js'],'Content-Security-Policy')&&false!==strpos($files['designer_js'],"default-src 'none'"),'Email Designer preview has an explicit restrictive CSP');
ux10(false!==strpos($files['design_rules'],'program_title')&&false!==strpos($files['design_rules'],'Search Issuers, Programs or Batches'),'Batch targets expose their Program context in the server-backed Design Rules inventory');
ux10(false!==strpos($files['email_css'],'.certloom-designer-shell')&&false!==strpos($files['email_css'],'@media (max-width:782px)')&&false!==strpos($files['email_css'],'min-height: 60px'),'Email Designer has responsive, mobile-touch-aware permanent styling');
ux10(false!==strpos($files['public_css'],'UX-CORE-10 public hardening')&&false!==strpos($files['public_css'],'overflow-wrap:anywhere')&&false!==strpos($files['public_css'],'forced-colors:active'),'public credential surfaces retain overflow/accessibility hardening');
ux10(false===strpos($files['setup'],'$wizard_mode')&&false===strpos($files['setup'],'name="workspace_mode"'),'Setup Wizard no longer depends on the retired mode selector or stale variable');
ux10(strpos($files['uninstall'],"delete_option( 'certloom_email_design_assignments_v1' )")>strpos($files['uninstall'],'if ( ! $delete_data ) {')&&strpos($files['uninstall'],"delete_option( 'certloom_email_template_issued_v1' )")>strpos($files['uninstall'],'if ( ! $delete_data ) {')&&strpos($files['uninstall'],"delete_option( 'certloom_email_template_catalog_v1' )")>strpos($files['uninstall'],'if ( ! $delete_data ) {')&&false!==strpos($files['uninstall'],"'_transient_certloom_email_preview_'"),'uninstall retains saved email designs unless deletion is explicit, and removes short-lived review state');
ux10(!file_exists($root.'/resources/domain/accepted.json')&&!file_exists($root.'/resources/renderer/accepted.json'),'UX-CORE-10 does not manufacture domain or renderer authority');
echo "\n$pass UX-CORE-10 checks, $fail failures\n"; exit($fail?1:0);
