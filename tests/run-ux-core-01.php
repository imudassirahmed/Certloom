<?php
/** UX-CORE-01 — unified product workspace + frontend continuity contract. */
$root = dirname( __DIR__ );
$checks = 0; $failures = 0;
function uxcore_src( $root, $path ) { $value = @file_get_contents( $root . '/' . $path ); return is_string( $value ) ? $value : ''; }
function uxcore( $condition, $label ) { global $checks, $failures; ++$checks; if ( $condition ) { echo "PASS  {$label}\n"; } else { ++$failures; echo "FAIL  {$label}\n"; } }
$nav = uxcore_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$mode = uxcore_src( $root, 'src/Infrastructure/Admin/AdminUiMode.php' );
$domain = uxcore_src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$dashboard = uxcore_src( $root, 'admin/views/dashboard.php' );
$presenter = uxcore_src( $root, 'src/Application/PublicCredentialPresenter.php' );
$renderer = uxcore_src( $root, 'includes/class-ecapla-certverify-render.php' );
$automation = uxcore_src( $root, 'src/Infrastructure/Admin/AutomationAdmin.php' );
$automations_view = uxcore_src( $root, 'admin/views/automations.php' );
$email_design = uxcore_src( $root, 'src/Infrastructure/Admin/EmailDesignAdmin.php' );
$email_admin = uxcore_src( $root, 'src/Infrastructure/Admin/EmailDeliveryAdmin.php' );
$email_coordinator = uxcore_src( $root, 'src/Application/Email/EmailDeliveryCoordinator.php' );
$listener = uxcore_src( $root, 'src/Infrastructure/Email/EmailAutomationListener.php' );
$issuance = uxcore_src( $root, 'src/Application/Issuance/ManualIssuanceService.php' );
$integrations = uxcore_src( $root, 'admin/views/integrations.php' );
$woo_bridge = uxcore_src( $root, 'src/Infrastructure/Integration/WooCommerceBridge.php' );
$woo_adapter = uxcore_src( $root, 'src/Infrastructure/Integration/WooCommerceAdapter.php' );
$public_css = uxcore_src( $root, 'public/assets/certloom-public.css' );
$public_js = uxcore_src( $root, 'public/assets/certloom-public.js' );

uxcore( false !== strpos( $mode, 'const UNIFIED' ) && false !== strpos( $mode, 'return self::UNIFIED' ) && false !== strpos( $mode, 'is_simple() { return true; }' ) && false !== strpos( $mode, 'is_advanced() { return false; }' ), 'Certloom exposes one unified workspace while the former task-focused branch remains a temporary compatibility surface' );
uxcore( false !== strpos( $mode, 'delete_user_meta' ) && false === strpos( $mode, 'update_user_meta' ), 'retired Simple/Advanced preference is removed rather than persisted as new product authority' );
uxcore( false !== strpos( $nav, 'private const PRIMARY = array(' ) && false === strpos( $nav, 'PRIMARY_SIMPLE' ) && false === strpos( $nav, 'PRIMARY_ADVANCED' ), 'product navigation has one canonical primary map' );
foreach ( array( "'certloom'                    => 'Home'", "'certloom-domain-credentials' => 'Credentials'", "'certloom-domain-programs'    => 'Programs'", "'certloom-templates'", "=> 'Design'", "'certloom-automations'", "=> 'Automations'", "'certloom-settings'", "=> 'Settings'" ) as $needle ) { uxcore( false !== strpos( $nav, $needle ), 'canonical navigation contains ' . $needle ); }
uxcore( false === strpos( $nav, "__( 'Legacy data', 'certloom' )" ) && false !== strpos( $nav, "'certloom-certificates', 'certloom-add'" ), 'legacy registry routes are hidden compatibility aliases inside Credentials' );
uxcore( false !== strpos( $nav, "__( 'Issue credential', 'certloom' )" ) && false !== strpos( $nav, "__( 'Recipients', 'certloom' )" ) && false !== strpos( $nav, "__( 'Email delivery', 'certloom' )" ), 'Credentials keeps issuance, recipients and delivery contextual rather than creating extra primary sections' );
$people = substr( $nav, strpos( $nav, "case 'people':" ), strpos( $nav, "case 'design':" ) - strpos( $nav, "case 'people':" ) );
$pp = strpos( $people, 'certloom-domain-programs' ); $bp = strpos( $people, 'certloom-domain-batches' ); $ip = strpos( $people, 'certloom-domain-issuers' ); $sp = strpos( $people, 'certloom-domain-signers' );
uxcore( false !== $pp && false !== $bp && false !== $ip && false !== $sp && $pp < $bp && $bp < $ip && $ip < $sp, 'Programs family is ordered Programs → Batches → Issuers → Signers' );

uxcore( false !== strpos( $domain, '$detailed_registry = true' ) && false !== strpos( $domain, "__( 'Record version', 'certloom' )" ) && false !== strpos( $domain, "__( 'Added to Certloom', 'certloom' )" ), 'unified Credentials keeps the detailed registry rather than regressing to the old simplified cards' );
uxcore( false !== strpos( $domain, 'certloom-credential-row-actions' ) && false !== strpos( $domain, "__( 'Manage', 'certloom' )" ), 'credential registry provides compact View/Manage row actions' );
uxcore( false !== strpos( $domain, 'certloom-technical-details' ) && false !== strpos( $domain, "__( 'External ID', 'certloom' )" ) && false !== strpos( $domain, "__( 'Batch code', 'certloom' )" ), 'specialist credential relationship identifiers remain reachable through Technical details' );
uxcore( false !== strpos( $domain, 'program_workspace' ) && false !== strpos( $domain, 'program_history' ), 'Program workspace keeps both task setup and credential activity in one workspace' );
uxcore( false !== strpos( $domain, 'certloom_program_quick_issuer' ) && false !== strpos( $domain, 'certloom_program_quick_batch' ) && false !== strpos( $domain, 'certloom_program_quick_signer' ), 'Program workspace supports quick Issuer, Batch and Signer setup without tab hopping' );

uxcore( false !== strpos( $dashboard, 'UnifiedCredentialRegistry' ) && false !== strpos( $dashboard, '->summary()' ) && false !== strpos( $dashboard, '->list_page(' ), 'Dashboard reads one unified current + legacy credential feed' );
uxcore( false !== strpos( $dashboard, '$review > 0' ) && false === strpos( $dashboard, '$review > 0 && ! $certloom_simple_normalized' ), 'legacy review warnings remain reachable in the unified workspace' );
uxcore( false !== strpos( $dashboard, '$certloom_automations_url' ) && false !== strpos( $dashboard, "esc_html_e( 'Automations', 'certloom' )" ) && false === strpos( $dashboard, 'Reusable messages are created in Automations' ), 'Dashboard links to Automations without assigning reusable email-content ownership to Automations' );

uxcore( false !== strpos( $presenter, "'recipient_name'" ) && false !== strpos( $presenter, "'program_title'" ) && false !== strpos( $presenter, "'issuer_name'" ), 'public presentation carries bounded human credential context' );
uxcore( false === strpos( $presenter, "'recipient_email'" ) && false === strpos( $presenter, "'external_id'" ), 'public profile presenter does not leak recipient email or external identity' );
uxcore( false !== strpos( $renderer, 'normalized_profile_card_html' ) && false !== strpos( $renderer, '<details class="cvp-profile-technical">' ), 'normalized public profile keeps human content primary and technical identity collapsed' );
uxcore( false !== strpos( $public_css, '@media (forced-colors: active)' ) && false !== strpos( $public_css, '@media (prefers-reduced-motion: reduce)' ), 'public frontend retains forced-colors and reduced-motion resilience' );
uxcore( false !== strpos( $public_js, 'printCertloomArea' ) && false !== strpos( $public_css, 'certloom-print-portal' ), 'public Print isolates Certloom content from the surrounding WordPress theme' );

uxcore( false === strpos( $automation, 'EmailTemplateCatalogRepository' ) && false !== strpos( $automation, 'EmailDesignAdmin::PAGE' ), 'Automation controller does not own reusable email content and redirects retired content endpoints to Email designs' );
uxcore( false !== strpos( $email_design, "const PAGE = 'certloom-email-designs'" ) && false !== strpos( $email_design, "__( 'Email designs', 'certloom' )" ), 'Email designs is a first-class Design workspace' );
uxcore( false !== strpos( $automations_view, 'Reusable message content and branding are managed separately in Email designs.' ) && false !== strpos( $automations_view, 'Email design resolves automatically' ), 'Automations explains its delivery-rule boundary and automatic design resolution' );
uxcore( false !== strpos( $listener, "add_action( 'certloom_credential_issued'" ), 'Automation listens to the canonical successful issuance event' );
uxcore( false !== strpos( $issuance, "\\do_action( 'certloom_credential_issued'" ), 'issuance emits the canonical post-commit event' );
uxcore( false !== strpos( $email_admin, 'Automatic — use email hierarchy' ) && false !== strpos( $email_admin, 'One-send override' ) && false !== strpos( $email_admin, 'Email designs decide what the recipient sees. Automations decide when' ), 'manual Email Delivery clearly separates automatic hierarchy, one-send override and Automation timing' );
uxcore( false !== strpos( $email_coordinator, "'selection_intent'" ) && false !== strpos( $email_coordinator, "'resolution_scope'" ) && false !== strpos( $email_coordinator, "'message_purpose'" ) && false !== strpos( $email_coordinator, "'template_name'" ), 'delivery evidence preserves selection intent, resolved hierarchy scope, message purpose and human design identity' );

uxcore( false !== strpos( $integrations, 'certloom-integration-source-grid' ) && false !== strpos( $integrations, "'WooCommerce'" ) && false !== strpos( $integrations, "'Coursera'" ), 'Integrations groups credential qualification sources coherently' );
uxcore( false !== strpos( $integrations, 'certloom-integration-destination-grid' ) && false !== strpos( $integrations, 'Destinations never decide who earned a credential.' ) && false !== strpos( $integrations, "'Credly'" ), 'Integrations separates post-issuance destinations from earning authority' );
uxcore( false !== strpos( $integrations, "esc_html_e( 'Technical status', 'certloom' )" ) && false === strpos( $integrations, 'if ( ! $simple ) : ?><details class="certloom-technical-details"><summary><?php esc_html_e( \'Technical status\'' ), 'integration authority evidence remains reachable through Technical status in the unified workspace' );
uxcore( false !== strpos( $woo_bridge, 'wc_get_order' ) && false !== strpos( $woo_bridge, 'get_user_id' ) && false !== strpos( $woo_adapter, 'IntegrationAdapterInterface' ), 'WooCommerce retains the generic integration adapter boundary' );

printf( "\n%d UX-CORE-01 checks, %d failures\n", $checks, $failures );
exit( $failures ? 1 : 0 );
