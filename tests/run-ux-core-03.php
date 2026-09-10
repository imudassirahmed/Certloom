<?php
/**
 * UX-CORE-03 — premium list/form system recovered from QA.8 hands-on acceptance.
 *
 * Protects the customer's explicit direction: registries are compact lists rather
 * than card collections; ordinary forms share one restrained control rhythm;
 * required fields fail visibly with useful examples; mobile targets stay >=44px.
 */
$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function ux3( $ok, $label ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS  {$label}\n"; } else { $fail++; echo "FAIL  {$label}\n"; } }
function ux3src( $root, $path ) { $value = file_get_contents( $root . '/' . $path ); return false === $value ? '' : $value; }

$css = ux3src( $root, 'admin/assets/admin.css' );
$js = ux3src( $root, 'admin/assets/admin.js' );
$domain = ux3src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$structure = ux3src( $root, 'src/Infrastructure/Admin/DomainStructureAdmin.php' );
$manual = ux3src( $root, 'src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$automations = ux3src( $root, 'admin/views/automations.php' );
$email_designs = ux3src( $root, 'admin/views/email-designs.php' );
$email_designer = ux3src( $root, 'admin/views/email-designer.php' );

ux3( false !== strpos( $domain, 'certloom-premium-list certloom-recipient-list' ), 'Unified Recipients uses the shared compact premium list' );
ux3( false !== strpos( $domain, 'certloom-premium-list certloom-program-list' ), 'Unified Programs uses the shared compact premium list' );
ux3( false !== strpos( $domain, 'certloom-premium-list certloom-credential-list' ), 'Unified Credentials uses the shared compact premium list' );
ux3( false !== strpos( $domain, 'certloom-premium-list certloom-premium-list-issuers' ), 'Unified Issuers uses the shared compact premium list' );
ux3( false !== strpos( $structure, 'certloom-premium-list certloom-premium-list-batches' ), 'Unified Batches uses the shared compact premium list' );
ux3( false !== strpos( $structure, 'certloom-premium-list certloom-premium-list-signers' ), 'Unified Signers uses the shared compact premium list' );
ux3( false === strpos( $css, '.certloom-simple-credential-card' ) && false === strpos( $css, '.certloom-simple-program-card' ) && false === strpos( $css, '.certloom-recipient-card' ), 'Rejected registry-card styling is removed rather than left as a future regression path' );
ux3( false !== strpos( $css, '.certloom-my-design-card-grid' ), 'Visual design gallery remains intentionally card-based' );
ux3( false !== strpos( $css, '.certloom-premium-row-link' ), 'List continuations use quiet row links instead of redundant boxed Open buttons' );
ux3( false !== strpos( $domain, "esc_html__( 'Issue', 'certloom' )" ) && false !== strpos( $domain, 'certloom-premium-row-link' ), 'High-value list actions retain one clear primary action plus quiet continuations' );

ux3( false !== strpos( $css, 'min-height:40px' ) && false !== strpos( $css, 'border:1px solid #c8d2ce' ) && false !== strpos( $css, 'border-radius:6px' ), 'Ordinary desktop controls share the 40px/1px/6px form rhythm' );
ux3( false !== strpos( $css, 'label.is-invalid input' ) && false !== strpos( $css, '.certloom-inline-field-error' ) && false !== strpos( $css, '#b32d2e' ), 'Invalid fields have a persistent red field and inline-error presentation' );
ux3( false !== strpos( $js, "control.getAttribute( 'data-certloom-required-example' )" ) && false !== strpos( $js, 'Please fill in this required field.' ), 'Required-field validation prefers an appropriate example and has a clear fallback' );
ux3( false !== strpos( $js, 'initDomainEntityFormFeedback' ) && false !== strpos( $js, 'initHumanFormValidation' ), 'Entity forms and connected human forms share explicit browser validation feedback' );
ux3( 1 === preg_match( '/@media\s*\(max-width\s*:\s*782px\)/', $css ) && 1 === preg_match( '/\.certloom-premium-row-link\s*\{[^}]*min-height\s*:\s*44px/s', $css ), 'Compact desktop UI preserves 44px mobile interaction targets' );

ux3( 1 === preg_match( '/\.certloom-manual-card\s*\{[^}]*padding\s*:\s*18px/s', $css ) && 1 === preg_match( '/\.certloom-manual-card-head h2\s*\{[^}]*font-size\s*:\s*18px/s', $css ), 'Compose Credential uses a restrained form surface instead of an oversized card' );
ux3( false !== strpos( $manual, 'data-certloom-context-lookup="recipient"' ) && false !== strpos( $manual, 'Search recipients by name, email, or external ID' ), 'Existing Recipient is one server-backed searchable selector, not a duplicate search form' );
ux3( false !== strpos( $manual, 'certloom-new-recipient-dialog' ) && false !== strpos( $manual, 'Complete recipient profile' ), 'New Recipient retains the complete profile popup' );
ux3( 1 === preg_match( '/\.certloom-premium-dialog input:not\(\[type=checkbox\]\):not\(\[type=radio\]\)\s*,\s*\.certloom-premium-dialog select\s*\{[^}]*height\s*:\s*40px/s', $css ), 'Quick-create dialogs use the same compact desktop control height' );

ux3( false !== strpos( $css, '.certloom-automations-workspace .certloom-context-lookup__search' ) && false !== strpos( $css, 'height:40px!important' ), 'Automation enhanced selectors align with ordinary 40px fields' );
ux3( false !== strpos( $automations, 'data-certloom-human-form' ) && false !== strpos( $automations, 'data-certloom-invalid-message' ), 'Automation forms participate in explicit required-field feedback' );
ux3( false !== strpos( $email_designer, 'Optional. Leave blank to let Certloom derive plain text from the message blocks.' ), 'Email Designer explains the automatic plain-text fallback without creating a second content authority' );

ux3( false !== strpos( $structure, 'data-certloom-required-example="' ) && false !== strpos( $structure, 'Choose the Program this Batch belongs to.' ), 'Required Batch→Program selection provides an appropriate inline example' );
ux3( false !== strpos( $css, '.certloom-domain-search input[type=search]' ) && false !== strpos( $css, 'height:40px!important' ), 'Entity search controls follow the same compact form rhythm' );
ux3( false !== strpos( $css, '@media(forced-colors:active)' ) && false !== strpos( $css, '.certloom-premium-row-link{color:LinkText}' ), 'New compact list interactions preserve forced-colors visibility' );

printf( "\n%d UX-CORE-03 source checks, %d failures\n", $pass + $fail, $fail );
exit( $fail ? 1 : 0 );
