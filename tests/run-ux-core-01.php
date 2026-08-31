<?php
$root = dirname( __DIR__ );
$checks = 0; $failures = 0;
function uxcore_src( $root, $path ) { $value = @file_get_contents( $root . '/' . $path ); return is_string( $value ) ? $value : ''; }
function uxcore( $condition, $label ) { global $checks, $failures; ++$checks; if ( $condition ) { echo "PASS  {$label}\n"; } else { ++$failures; echo "FAIL  {$label}\n"; } }
$nav = uxcore_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$domain = uxcore_src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$dashboard = uxcore_src( $root, 'admin/views/dashboard.php' );
$css = uxcore_src( $root, 'admin/assets/admin.css' );
$public_css = uxcore_src( $root, 'public/assets/certloom-public.css' );
$presenter = uxcore_src( $root, 'src/Application/PublicCredentialPresenter.php' );
$renderer = uxcore_src( $root, 'includes/class-ecapla-certverify-render.php' );
$automation = uxcore_src( $root, 'src/Infrastructure/Admin/AutomationAdmin.php' );
$listener = uxcore_src( $root, 'src/Infrastructure/Email/EmailAutomationListener.php' );
$issuance = uxcore_src( $root, 'src/Application/Issuance/ManualIssuanceService.php' );
$integrations = uxcore_src( $root, 'admin/views/integrations.php' );
$woo_bridge = uxcore_src( $root, 'src/Infrastructure/Integration/WooCommerceBridge.php' );
$woo_adapter = uxcore_src( $root, 'src/Infrastructure/Integration/WooCommerceAdapter.php' );
$email_admin = uxcore_src( $root, 'src/Infrastructure/Admin/EmailDeliveryAdmin.php' );
$email_coordinator = uxcore_src( $root, 'src/Application/Email/EmailDeliveryCoordinator.php' );
$email_template_service = uxcore_src( $root, 'src/Application/Email/EmailTemplateService.php' );

uxcore( false !== strpos( $nav, "'certloom'                 => 'Home'" ) && false !== strpos( $nav, "'certloom-automations'     => 'Automations'" ), 'Simple primary navigation is task based' );
uxcore( false !== strpos( $nav, "'certloom-domain-programs' => 'Programs'" ) && false !== strpos( $nav, "'certloom-templates'       => 'Design'" ), 'Simple primary navigation makes Program and Design first-class tasks' );
uxcore( false !== strpos( $nav, "'Legacy data', 'certloom'" ) && false !== strpos( $nav, 'if ( $simple && $preview )' ), 'Legacy data is retained for Advanced while Simple uses canonical Credentials' );
uxcore( false !== strpos( $nav, "__( 'Recipients', 'certloom' )" ) && false !== strpos( $nav, "__( 'Issue', 'certloom' )" ), 'Simple Credentials keeps Recipient and issuance context close together' );
uxcore( false !== strpos( $nav, "__( 'Programs', 'certloom' )" ) && false !== strpos( $nav, "array( 'certloom-domain-programs', 'certloom-domain-issuers', 'certloom-domain-batches', 'certloom-domain-signers' )" ), 'Simple People routes collapse into one Program workspace context' );

uxcore( false !== strpos( $domain, 'simple_credential_cards' ) && false !== strpos( $domain, 'certloom-simple-credential-grid' ), 'Simple Credentials uses cards instead of the Advanced implementation table' );
uxcore( false !== strpos( $domain, "'View credential', 'certloom'" ) && false !== strpos( $domain, 'NormalizedPublicCredentialUrl' ), 'Simple Credentials exposes a direct human public View credential action' );
uxcore( false !== strpos( $domain, "'Issue another', 'certloom'" ) || false !== strpos( $domain, "'Issue credential', 'certloom'" ), 'Credential cards continue naturally into another issuance task' );
uxcore( false !== strpos( $domain, 'simple_program_cards' ) && false !== strpos( $domain, 'certloom-simple-program-grid' ), 'Simple Programs uses connected workspace cards' );
uxcore( false !== strpos( $domain, 'program_workspace' ) && false !== strpos( $domain, "'Design & issue', 'certloom'" ), 'Program becomes the connected setup workspace and continues into design/issuance' );
uxcore( false !== strpos( $domain, 'certloom_program_quick_issuer' ) && false !== strpos( $domain, 'certloom_program_quick_batch' ) && false !== strpos( $domain, 'certloom_program_quick_signer' ), 'Program workspace supports quick Issuer, Batch and Signer setup without tab hopping' );

uxcore( false !== strpos( $dashboard, '$certloom_simple_normalized' ) && false !== strpos( $dashboard, 'management_summary()' ) && false !== strpos( $dashboard, 'list_management_page' ), 'Simple Dashboard reads the normalized Credential model rather than legacy registry metrics' );
uxcore( false !== strpos( $dashboard, "'Credentials', 'certloom'" ) && false !== strpos( $dashboard, "'Programs', 'certloom'" ) && false !== strpos( $dashboard, "'Needs attention', 'certloom'" ), 'Simple Dashboard summary reflects human tasks and current credential state' );
uxcore( false !== strpos( $dashboard, "'View credential', 'certloom'" ) && false !== strpos( $dashboard, "'Manage', 'certloom'" ), 'Simple Dashboard recent activity can open the public credential or manage it directly' );
uxcore( false !== strpos( $dashboard, '$review > 0 && ! $certloom_simple_normalized' ), 'Legacy review warnings do not dominate Simple mode' );
uxcore( false !== strpos( $dashboard, "'Automations', 'certloom'" ) && false !== strpos( $dashboard, "'Create and publish credential designs', 'certloom'" ), 'Simple Dashboard shortcuts follow Issue → Program → Design → Automations' );
uxcore( false !== strpos( $dashboard, 'if ( ! $certloom_simple_normalized )' ) && false !== strpos( $dashboard, "'No automatic storage changes', 'certloom'" ), 'Migration/data-safety implementation messaging stays Advanced-only' );

uxcore( false !== strpos( $presenter, "'recipient_name'" ) && false !== strpos( $presenter, "'program_title'" ) && false !== strpos( $presenter, "'issuer_name'" ), 'Public presentation carries bounded human snapshot context' );
uxcore( false === strpos( $presenter, "'recipient_email'" ) && false === strpos( $presenter, "'external_id'" ), 'Public profile presenter does not leak recipient email/external identity' );
uxcore( false !== strpos( $renderer, 'normalized_profile_card_html' ) && false !== strpos( $renderer, 'cvp-profile-recipient' ), 'Normalized public output is a human credential profile instead of a raw registry detail block' );
uxcore( false !== strpos( $renderer, "'Credential ID', 'certloom'" ) && false !== strpos( $renderer, '<details class="cvp-profile-technical">' ), 'Technical Credential ID remains available but collapsed' );
uxcore( false !== strpos( $renderer, "'Copy link', 'certloom'" ), 'Public actions use customer language' );

uxcore( false !== strpos( $automation, 'EmailTemplateCatalogRepository' ) && false !== strpos( $automation, 'EmailAutomationRuleRepository' ), 'Automations supports multiple reusable templates and rules' );
uxcore( false !== strpos( $listener, "add_action( 'certloom_credential_issued'" ), 'Automation listens to the canonical successful issuance event' );
uxcore( false !== strpos( $issuance, "function_exists( 'do_action' )" ) && false !== strpos( $issuance, "\\do_action( 'certloom_credential_issued'" ), 'Issuance emits the post-commit automation event without breaking standalone/release contracts' );
uxcore( false !== strpos( $integrations, 'certloom-integration-source-grid' ) && false !== strpos( $integrations, "'WooCommerce'" ), 'Integrations is organized around sources feeding the same Certloom workflow' );
uxcore( false !== strpos( $integrations, "'Coursera'" ) && false !== strpos( $integrations, 'certloom_coursera_mapping_save' ) && false !== strpos( $integrations, "'Publishing destinations'" ) && false !== strpos( $integrations, "'Credly'" ), 'Coursera is a real eligibility source while Credly remains an explicit post-issuance destination boundary' );
uxcore( false !== strpos( $integrations, 'certloom-integration-destination-grid' ) && false !== strpos( $integrations, 'Destinations never decide who earned a credential.' ), 'Integrations visually separates qualification sources from post-issuance publishing destinations' );
uxcore( false !== strpos( $integrations, "'Connect Credly'" ) && false !== strpos( $integrations, "'Program → Credly badge'" ) && false !== strpos( $integrations, "'Credly delivery history'" ), 'Credly destination has a complete connect, map and delivery-review workflow' );
uxcore( false !== strpos( uxcore_src( $root, 'admin/views/automations.php' ), "'Destinations'" ) && false !== strpos( uxcore_src( $root, 'admin/views/automations.php' ), "'Destinations run only after Certloom has issued the credential.'" ), 'Automations reinforces the source → credential → destination model' );
uxcore( false !== strpos( $woo_bridge, 'wc_get_order' ) && false !== strpos( $woo_bridge, 'get_user_id' ), 'WooCommerce runtime starts from a completed order and authenticated WordPress customer' );
uxcore( false !== strpos( $woo_adapter, "'woocommerce'" ) && false !== strpos( $woo_adapter, 'IntegrationAdapterInterface' ), 'WooCommerce uses the generic integration adapter architecture' );

uxcore( false !== strpos( $email_admin, 'name="template_id"' ) && false !== strpos( $email_admin, 'page=certloom-automations' ), 'Manual Email Delivery selects the same reusable messages owned by Automations' );
uxcore( false === strpos( $email_admin, 'Issued-credential email template' ) && false !== strpos( $email_admin, 'Choose a credential first' ) && false === strpos( $email_admin, 'Credential Version ID' ), 'Email Delivery has no competing template editor or internal Version-ID entry workflow' );
uxcore( false !== strpos( $email_admin, "saved['template_id']" ) && false !== strpos( $email_coordinator, "'template_name'" ), 'Selected message identity survives preview, queue and privacy-minimized delivery evidence' );
uxcore( false !== strpos( $email_template_service, 'Online verification is not available for this credential yet.' ) && false === strpos( $email_template_service, 'Public normalized verification is not yet authoritative' ), 'Recipient-facing email copy hides normalized-domain authority jargon' );

uxcore( false !== strpos( $css, 'UX-CORE-01' ) && false !== strpos( $css, '--certloom-flow-' ), 'Backend redesign uses one shared UX-CORE visual grammar' );
uxcore( false !== strpos( $css, '.certloom-simple-credential-grid' ) && false !== strpos( $css, '.certloom-simple-program-grid' ), 'Simple Credential and Program collections share premium card structure' );
uxcore( false !== strpos( $css, '.certloom-dashboard-credential-list' ) && false !== strpos( $css, '.certloom-metric-grid-simple' ), 'Simple Dashboard has dedicated normalized responsive styling' );
uxcore( false !== strpos( $public_css, '.cvp-profile-recipient' ) && false !== strpos( $public_css, '.cvp-profile-proof' ), 'Public profile has dedicated human hierarchy and verification styling' );
uxcore( false !== strpos( $css, '@media(forced-colors:active)' ) && false !== strpos( $public_css, '@media (forced-colors: active)' ) || false !== strpos( $public_css, '@media(forced-colors:active)' ), 'Backend and public redesign preserve forced-colors coverage' );

uxcore( false !== strpos( uxcore_src( $root, 'src/Infrastructure/Migration/DomainFeatureGate.php' ), 'DomainAuthorityGate' ), 'Structural redesign does not replace manifest-backed normalized-domain authority' );
uxcore( false === strpos( $woo_bridge, 'get_billing_email' ) && false === strpos( $woo_bridge, 'get_billing_first_name' ), 'WooCommerce does not guess Recipient ownership from billing identity' );

echo "\n{$checks} UX-CORE-01 source checks, {$failures} failures\n";
exit( $failures ? 1 : 0 );
