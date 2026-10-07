<?php

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$files = array(
    'domain'    => $root . '/src/Infrastructure/Admin/DomainAdmin.php',
    'structure' => $root . '/src/Infrastructure/Admin/DomainStructureAdmin.php',
    'css'       => $root . '/admin/assets/admin.css',
    'js'        => $root . '/admin/assets/admin.js',
);
foreach ( $files as $path ) {
    if ( ! is_file( $path ) ) { fwrite( STDERR, "Missing UX-OPS-05 source: {$path}\n" ); exit( 1 ); }
}
$domain = file_get_contents( $files['domain'] );
$structure = file_get_contents( $files['structure'] );
$css = file_get_contents( $files['css'] );
$js = file_get_contents( $files['js'] );
$checks = 0; $fails = array();
function u5( $ok, $label ) { global $checks, $fails; $checks++; if ( $ok ) { echo "PASS  {$label}\n"; } else { echo "FAIL  {$label}\n"; $fails[] = $label; } }

u5( false === strpos( $domain, '<form method="dialog" class="certloom-quick-field-dialog-shell"' ), 'Program quick-field dialog no longer nests a form inside the Program form' );
u5( false === strpos( $structure, '<form method="dialog" class="certloom-quick-field-dialog-shell"' ), 'Batch quick-field dialog no longer nests a form inside the Batch form' );
u5( false !== strpos( $domain, 'certloom-quick-field-dialog-shell" role="document"' ), 'Program quick-field dialog uses a non-form document shell' );
u5( false !== strpos( $structure, 'certloom-quick-field-dialog-shell" role="document"' ), 'Batch quick-field dialog uses a non-form document shell' );
u5( false !== strpos( $domain, 'certloom-quick-field-field certloom-quick-field-wide' ) && false !== strpos( $domain, '<label for="certloom-quick-field-label">' ), 'Quick-field markup separates labels from controls instead of relying on wrapping labels' );
u5( 1 === preg_match( '/width\s*:\s*min\(760px/', $css ) && 1 === preg_match( '/padding\s*:\s*28px\s+30px\s+24px/', $css ) && 1 === preg_match( '/gap\s*:\s*20px\s+24px/', $css ), 'Quick-field dialog has deliberate outer padding and field spacing' );
u5( 1 === preg_match( '/\.certloom-quick-field-field\s*>\s*label\s*\{[^}]*display\s*:\s*block/s', $css ) && 1 === preg_match( '/\.certloom-quick-field-field input\s*,\s*\.certloom-quick-field-field select\s*\{[^}]*display\s*:\s*block[^}]*width\s*:\s*100%/s', $css ), 'Quick-field label/control stacking is hardened against WordPress/admin CSS collisions without !important' );
u5( false !== strpos( $domain, 'data-certloom-entity-form="program"' ), 'Program create/update form opts into explicit submit feedback' );
u5( false !== strpos( $domain, 'data-certloom-entity-form="recipient"' ) && false !== strpos( $domain, 'data-certloom-entity-form="issuer"' ), 'Recipient and Issuer forms use the same explicit submit-feedback contract' );
u5( false !== strpos( $structure, 'data-certloom-entity-form="batch"' ) && false !== strpos( $structure, 'data-certloom-entity-form="signer"' ), 'Batch and Signer forms use the same explicit submit-feedback contract' );
u5( substr_count( $domain, 'data-certloom-domain-form-feedback aria-live="polite"' ) >= 3 && substr_count( $structure, 'data-certloom-domain-form-feedback aria-live="polite"' ) >= 2, 'All five primary entity editors include an accessible live feedback region' );
u5( false !== strpos( $structure, 'required aria-required="true"' ) && false !== strpos( $structure, 'certloom-field-required' ), 'Batch and Signer required fields expose visible and semantic required state' );
u5( false !== strpos( $domain, 'data-certloom-saving-message=' ) && false !== strpos( $domain, 'Adding program…' ), 'Program form declares a visible in-progress state' );
u5( false !== strpos( $domain, 'data-certloom-invalid-message=' ) && false !== strpos( $domain, 'Please complete the highlighted required field before saving this program.' ), 'Program form declares actionable required-field feedback' );
u5( false !== strpos( $domain, 'data-certloom-domain-form-feedback aria-live="polite"' ), 'Program editor includes an accessible live feedback region beside the submit workflow' );
u5( false !== strpos( $domain, 'certloom-field-required' ) && false !== strpos( $domain, "esc_html__( 'Required', 'certloom' )" ), 'Required text fields visibly identify their requirement' );
u5( false !== strpos( $domain, 'required aria-required="true"' ), 'Required text fields expose semantic required state' );
u5( false !== strpos( $js, 'function initDomainEntityFormFeedback()' ), 'Admin JavaScript initializes explicit entity-form feedback' );
u5( false !== strpos( $js, "form.addEventListener( 'invalid'" ) && false !== strpos( $js, "field.classList.add( 'is-invalid' )" ), 'Browser-native validation failures are also surfaced in Certloom UI' );
u5( false !== strpos( $js, "form.addEventListener( 'submit'" ) && false !== strpos( $js, "submit.setAttribute( 'aria-busy', 'true' )" ) && false !== strpos( $js, 'submit.disabled = true' ), 'Successful form submission immediately shows busy state and prevents double submit' );
u5( false !== strpos( $js, 'initDomainEntityFormFeedback();' ), 'Entity-form feedback initializer is booted on admin DOM ready' );
u5( false !== strpos( $domain, "add_action( 'admin_post_certloom_domain_create_program'" ), 'Program create POST handler remains explicitly registered' );
u5( false !== strpos( $domain, "array( 'certloom_new' => 1, 'certloom_retry' => 1 )" ), 'Server-side Program validation failures still return to the focused editor with retry restoration' );
u5( false !== strpos( $css, '.certloom-domain-form-feedback.is-error' ) && false !== strpos( $css, '.certloom-domain-form-feedback.is-saving' ), 'Program submit feedback has distinct error and saving presentation' );

if ( $fails ) { echo "\n" . ( $checks - count( $fails ) ) . " passed, " . count( $fails ) . " failures\n"; exit( 1 ); }
echo "\n{$checks} UX-OPS-05 source checks, 0 failures\n";
