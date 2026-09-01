<?php
$root = dirname( __DIR__ );
$checks = 0;
$failures = 0;
function uxroute( $condition, $label ) {
    global $checks, $failures;
    $checks++;
    if ( $condition ) { echo "PASS  {$label}\n"; }
    else { $failures++; echo "FAIL  {$label}\n"; }
}
function uxsrc( $path ) {
    global $root;
    $data = file_get_contents( $root . '/' . $path );
    if ( false === $data ) { throw new RuntimeException( 'Cannot read ' . $path ); }
    return $data;
}
$nav = uxsrc( 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$dashboard = uxsrc( 'admin/views/dashboard.php' );
$domain = uxsrc( 'src/Infrastructure/Admin/DomainAdmin.php' );
$domain_structure = uxsrc( 'src/Infrastructure/Admin/DomainStructureAdmin.php' );
$manual = uxsrc( 'src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$email = uxsrc( 'src/Infrastructure/Admin/EmailDeliveryAdmin.php' );
$automation = uxsrc( 'src/Infrastructure/Admin/AutomationAdmin.php' );
$automations_view = uxsrc( 'admin/views/automations.php' );
$integrations_view = uxsrc( 'admin/views/integrations.php' );
$admin = uxsrc( 'admin/class-ecapla-certverify-admin.php' );
$plugin = uxsrc( 'src/Plugin.php' );
$bootstrap = uxsrc( 'certloom.php' );
$oauth = uxsrc( 'src/Infrastructure/Backup/GoogleDriveOAuth.php' );
$relay = uxsrc( 'src/Infrastructure/Backup/EcaplaOAuthRelay.php' );

// The six Simple-mode destinations must be registered/routable product pages.
foreach ( array( 'certloom', 'certloom-certificates', 'certloom-domain-programs', 'certloom-templates', 'certloom-automations', 'certloom-settings' ) as $slug ) {
    uxroute( false !== strpos( $nav, "'{$slug}'" ), 'Simple navigation owns registered route ' . $slug );
}
uxroute( false === strpos( $nav, "__( 'Legacy data', 'certloom' )" ) && false !== strpos( $nav, "'certloom-domain-credentials', 'certloom-certificates', 'certloom-add'" ), 'Historical registry routes resolve inside the single Credentials destination without exposing a Legacy data subtab' );
uxroute( false !== strpos( $nav, "__( 'Integrations', 'certloom' )" ) && false !== strpos( $nav, "'certloom-integrations'" ), 'Simple Automations area has a direct Integrations continuation' );

// Dashboard: every primary Simple task continues to the canonical task rather than legacy create.
uxroute( false !== strpos( $dashboard, 'admin.php?page=certloom-issuance' ), 'Dashboard Issue routes to canonical Manual Issuance' );
uxroute( false !== strpos( $dashboard, 'admin.php?page=certloom-domain-programs' ), 'Dashboard Programs routes to Program workspace' );
uxroute( false !== strpos( $dashboard, 'admin.php?page=certloom-templates' ), 'Dashboard Design routes to canonical Design' );
uxroute( false !== strpos( $dashboard, 'admin.php?page=certloom-automations' ), 'Dashboard Automations routes to unified delivery automation' );
uxroute( false !== strpos( $dashboard, "'certloom-domain-credentials'" ), 'Dashboard normalized recent credentials route to canonical Credential management' );

// Credential/Recipient continuity.
uxroute( false !== strpos( $domain, "'page' => ManualIssuanceAdmin::PAGE, 'recipient_id'" ), 'Recipient Issue credential preselects the exact Recipient' );
uxroute( false !== strpos( $domain, "'page' => ManualIssuanceAdmin::PAGE, 'recipient_id'" ) && false !== strpos( $domain, "'program_id'" ) && false !== strpos( $domain, "'batch_id'" ), 'Credential Issue another keeps Recipient, Program and Batch context' );
uxroute( false !== strpos( $domain, "'page' => 'certloom-email-delivery', 'version_id'" ), 'Credential Email action binds the exact immutable Version' );
uxroute( false !== strpos( $domain, "View credential" ) && false !== strpos( $domain, "target=\"_blank\"" ), 'Credential exposes a direct human public View credential continuation when available' );
uxroute( false !== strpos( $manual, "'page'=>'certloom-email-delivery','version_id'=>absint(\$receipt['version_id'])" ), 'Successful Manual Issuance continues directly into Email Delivery' );
uxroute( false !== strpos( $manual, "Issue another credential" ), 'Successful Manual Issuance exposes an obvious next issuance action' );

// Program is a connected workspace rather than five independent setup screens.
foreach ( array( 'certloom_program_quick_issuer', 'certloom_program_quick_batch', 'certloom_program_quick_signer' ) as $action ) {
    uxroute( false !== strpos( $domain, "admin_post_{$action}" ) && false !== strpos( $domain, "name=\"action\" value=\"{$action}\"" ), 'Program quick action is both rendered and registered: ' . $action );
}
uxroute( false !== strpos( $domain, 'Choose design' ) && false !== strpos( $domain, 'Issue credential' ), 'Program workspace continues directly into Design and issuance' );
uxroute( false !== strpos( $domain_structure, "name=\"recipient_id\" required" ), 'Batch membership still connects explicitly to Recipient identity' );

// Email Delivery has one template authority and returns to the human workflow.
uxroute( false !== strpos( $email, 'certloom-automations#certloom-email-templates-title' ), 'Manual Email Delivery manages reusable messages through Automations' );
uxroute( false !== strpos( $email, 'Choose a credential first' ) && false !== strpos( $email, 'certloom-domain-credentials' ), 'Direct Email Delivery asks for a human credential instead of Version ID' );
uxroute( false === strpos( $email, 'name="version_id" type="text"' ) && false === strpos( $email, 'name="version_id" type="number"' ), 'Email Delivery never exposes a raw Version-ID input' );
uxroute( false !== strpos( $email, 'Open Automations' ), 'Manual Email Delivery continues into automatic delivery setup' );

// Automations actions are registered and use the same catalog for manual + automatic delivery.
foreach ( array( 'certloom_automation_rule_save', 'certloom_automation_rule_delete', 'certloom_automation_template_save', 'certloom_automation_template_delete' ) as $action ) {
    uxroute( false !== strpos( $automation, "admin_post_{$action}" ) && false !== strpos( $automations_view, "value=\"{$action}\"" ), 'Automation control is both rendered and registered: ' . $action );
}
uxroute( false !== strpos( $automations_view, 'Manage integrations' ) && false !== strpos( $automations_view, 'certloom-integrations' ), 'Automations continues into qualification sources and publishing destinations' );

// Integration page must separate source setup from post-issuance destination setup.
foreach ( array( 'Tutor LMS', 'LearnDash', 'WooCommerce', 'Coursera', 'Webhook / API' ) as $source_label ) {
    uxroute( false !== strpos( $integrations_view, $source_label ), 'Integrations exposes source: ' . $source_label );
}
uxroute( false !== strpos( $integrations_view, 'Publishing destinations' ) && false !== strpos( $integrations_view, 'Credly' ), 'Credly is physically presented under post-issuance Publishing destinations' );
uxroute( false !== strpos( $integrations_view, 'Set up delivery' ) && false !== strpos( $integrations_view, 'certloom-automations' ), 'Integrations continues into delivery/automation rather than ending at mapping' );

// All redesigned integration mutation handlers are actually booted.
foreach ( array( 'WooCommerceIntegrationAdmin', 'CourseraIntegrationAdmin', 'WebhookIntegrationAdmin', 'CredlyIntegrationAdmin' ) as $controller ) {
    uxroute( false !== strpos( $bootstrap, $controller ), 'Plugin boot registers redesigned integration controller ' . $controller );
}
uxroute( false !== strpos( $bootstrap, 'WooCommerceBridge::register' ), 'WooCommerce runtime bridge is booted' );
uxroute( false !== strpos( $bootstrap, 'CourseraXapiRest::register' ), 'Coursera xAPI endpoint is booted' );
uxroute( false !== strpos( $bootstrap, 'WebhookRest::register' ), 'Generic webhook/API endpoint is booted' );
uxroute( false !== strpos( $plugin, 'AutomationAdmin' ) && false !== strpos( $plugin, 'EmailAutomationListener' ) && false !== strpos( $plugin, 'CredlyPublishingListener' ), 'Plugin boot connects Automations and Credly to post-issuance lifecycle' );

// Managed Google and explicit site-owned fallback must both be real, bounded actions.
uxroute( false !== strpos( $oauth, 'EcaplaOAuthRelay' ) && false !== strpos( $oauth, 'connection_mode' ), 'Google OAuth supports explicit managed-relay connection mode' );
uxroute( false !== strpos( $relay, 'https://auth.ecapla.com/' ) || false !== strpos( $relay, "auth.ecapla.com" ), 'Managed OAuth client is pinned to Ecapla relay host' );
uxroute( false !== strpos( $admin, 'certloom_google_drive_connect' ) || false !== strpos( uxsrc( 'admin/views/setup-wizard.php' ), 'certloom_google_drive_connect' ), 'Google connection submit is exposed through a registered Certloom action' );

// Release flavor is explicit. Production-intent source must contain no QA bypass or
// QA-only identity; an intentionally versioned -qa.* derivative must contain all
// three reviewed in-memory gate overrides plus visible staging identity. The same
// permanent contract therefore protects both lanes without maintaining a QA-only
// fork of this test.
$release_bootstrap = uxsrc( 'certloom.php' );
$is_qa_source = 1 === preg_match( '/^[[:space:]]*\* Version:[[:space:]]*[^\r\n]*-qa\.[0-9]+/mi', $release_bootstrap );
foreach ( array(
    'src/Infrastructure/Migration/DomainFeatureGate.php',
    'src/Infrastructure/Rendering/RendererAuthorityGate.php',
    'src/Infrastructure/Verification/PublicVerificationGate.php',
) as $gate_file ) {
    $gate = uxsrc( $gate_file );
    uxroute( $is_qa_source ? false !== strpos( $gate, 'CERTLOOM_QA_UNGATED' ) : false === strpos( $gate, 'CERTLOOM_QA_UNGATED' ), ( $is_qa_source ? 'QA derivative contains reviewed in-memory readiness override in ' : 'Production-intent redesign contains no QA readiness override in ' ) . basename( $gate_file ) );
}
uxroute( $is_qa_source ? false !== strpos( $release_bootstrap, 'CERTLOOM_QA_UNGATED' ) : false === strpos( $release_bootstrap, 'CERTLOOM_QA_UNGATED' ), $is_qa_source ? 'QA derivative bootstrap contains explicit QA ungated constant' : 'Production-intent plugin bootstrap contains no QA ungated constant' );
uxroute( $is_qa_source ? ( false !== strpos( $release_bootstrap, 'Certloom QA' ) && false !== strpos( $release_bootstrap, 'certloom-qa-ungated' ) && false !== strpos( $release_bootstrap, 'authority gates are bypassed for QA' ) ) : ( false === strpos( $release_bootstrap, 'Certloom QA' ) && false === strpos( $release_bootstrap, 'certloom-qa-ungated' ) && false === strpos( $release_bootstrap, 'authority gates are bypassed for QA' ) ), $is_qa_source ? 'QA derivative exposes persistent staging identity' : 'Production-intent plugin bootstrap contains no QA-only presentation identity' );

printf( "\n%d UX-CORE human-continuation checks, %d failures\n", $checks, $failures );
exit( $failures ? 1 : 0 );
