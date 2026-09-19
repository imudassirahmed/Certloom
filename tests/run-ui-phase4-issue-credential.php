<?php
/** Alpha.4.79 Issue Credential eight-step REST compatibility contract. */
$root = dirname( __DIR__ );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$rest = file_get_contents( $root . '/src/Infrastructure/Admin/IssueCredentialRest.php' );
$search = file_get_contents( $root . '/src/Infrastructure/Admin/AdminSearchRest.php' );
$nav = file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' );
$js = file_get_contents( $root . '/admin/assets/issue-credential.js' );
$global_js = file_get_contents( $root . '/admin/assets/admin.js' );
$css = file_get_contents( $root . '/admin/assets/issue-credential.css' );
$pass = 0; $fail = 0;
function i427( $ok, $label ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
foreach ( array( 'manual'=>$manual, 'rest'=>$rest, 'search'=>$search, 'nav'=>$nav, 'js'=>$js, 'global_js'=>$global_js, 'css'=>$css ) as $name=>$source ) { i427( is_string( $source ) && '' !== $source, 'source ' . $name . ' is readable' ); }

i427( false !== strpos( $manual, 'certloom-page-hero certloom-issue-app-hero' ) && false !== strpos( $manual, "__( 'Credential operations', 'certloom' )" ), 'Issue Credential participates in the shared task hero hierarchy' );
i427( false !== strpos( $manual, '( new AdminProductNavigation() )->render_current_subnav();' ), 'Issue Credential renders contextual product navigation' );
i427( false !== strpos( $nav, "'certloom-issuance'" ) && false !== strpos( $nav, 'page_renders_own_subnav' ), 'early duplicate sub-navigation stays suppressed' );
i427( false === strpos( $manual, 'certloom-ui-contract-badge' ) && false === strpos( $manual, 'certloom-phase2-backend-boundary' ), 'developer contract chrome stays outside the user workflow' );

foreach ( array( 'recipient','context','credential','data','certificate','authority','email','review' ) as $key ) {
    i427( false !== strpos( $manual, 'data-step-key="' . $key . '"' ), 'eight-step app includes ' . $key . ' step' );
}
i427( false !== strpos( $manual, 'A focused eight-step workflow.' ), 'workflow explains its progressive REST model instead of exposing a mega-form' );
i427( false !== strpos( $manual, "render_async_picker( 'recipient'" ) && false !== strpos( $manual, "render_async_picker( 'program'" ) && false !== strpos( $manual, "render_async_picker( 'batch'" ), 'Recipient, Program and Batch use one async-picker pattern' );
i427( false !== strpos( $manual, 'data-quick-dialog="program"' ) && false !== strpos( $manual, 'data-quick-dialog="batch"' ), 'Program and Batch remain quick-creatable without leaving issuance' );
i427( false !== strpos( $manual, 'data-field="credential_title"' ) && false !== strpos( $manual, 'The title stays editable even when a Program supplies the default.' ), 'Program-derived title remains visibly editable' );
i427( false !== strpos( $manual, 'data-design-required-fields' ) && strpos( $manual, 'data-design-required-fields' ) < strpos( $manual, 'Additional one-off credential data' ), 'design-required facts stay visible before collapsed optional one-off data' );
i427( false !== strpos( $manual, 'data-repeating-section' ) && false !== strpos( $manual, 'Subjects & results' ), 'structured Subjects/Results have a dedicated bounded data surface' );

i427( false !== strpos( $manual, 'data-presentation="details_only"' ) && false !== strpos( $manual, 'data-presentation="image"' ) && false !== strpos( $manual, 'data-presentation="design"' ), 'Certificate step exposes details-only, uploaded-image and Certloom-design choices' );
i427( false !== strpos( $manual, 'data-design-source="inherited"' ) && false !== strpos( $manual, 'data-design-source="assigned"' ), 'Certloom design supports explicit inherited or one-off assigned source' );
i427( false !== strpos( $manual, 'Live certificate preview' ) && false !== strpos( $manual, 'data-preview-stage' ), 'Certificate step keeps a live unsaved preview surface' );
i427( false !== strpos( $manual, 'data-email-mode="review"' ) && false !== strpos( $manual, 'data-email-mode="later"' ) && false !== strpos( $manual, 'Email design' ), 'Email is a first-class post-commit step with an explicit design choice' );
i427( false !== strpos( $manual, 'Nothing is sent during issuance.' ) && false !== strpos( $manual, 'It does not send email automatically.' ), 'issuance never implies that Review or Issue silently sends email' );

i427( false !== strpos( $manual, 'data-review-technical' ) && false !== strpos( $manual, "__( 'Technical details', 'certloom' )" ) && false !== strpos( $js, 'Review SHA-256' ), 'technical/release evidence remains available under progressive disclosure' );
i427( false !== strpos( $manual, 'data-issue-summary' ) && false !== strpos( $manual, 'data-summary-recipient' ) && false !== strpos( $manual, 'data-summary-batch' ) && false !== strpos( $manual, 'data-summary-email' ), 'live summary follows the important issuance decisions' );
i427( false !== strpos( $manual, 'data-issue-receipt' ), 'Review step contains an inline issuance receipt continuation surface' );

i427( false !== strpos( $manual, "'restNonce' => wp_create_nonce( 'wp_rest' )" ) && false !== strpos( $js, "'X-WP-Nonce': cfg.restNonce" ), 'REST requests use the dedicated WordPress REST nonce' );
i427( false !== strpos( $manual, "'previewUrl' => rest_url( 'certloom/v1/admin/issuance-preview' )" ) && false !== strpos( $js, 'cfg.previewUrl' ) && false !== strpos( $search, 'issuance_preview' ), 'live preview uses the canonical mutation-free issuance preview route' );
i427( false !== strpos( $js, 'AbortController' ) && false !== strpos( $js, 'previewController.abort()' ), 'stale async search/preview work is cancellable' );
i427( false !== strpos( $js, "url.searchParams.set('program_id', String(state.issuance.program_id || 0))" ) && false !== strpos( $js, "searchPicker('batch', '', true)" ), 'Batch lookup is freshly scoped to the current Program' );
i427( false !== strpos( $js, 'state.issuance.batch_id = 0' ) && false !== strpos( $js, 'Batch cleared because the Program changed' ), 'changing Program clears stale Batch state before refetch' );
i427( false !== strpos( $js, "state.issuance.title_source = 'custom'" ) && false !== strpos( $js, 'data-use-program-title' ), 'direct title edits become one-off custom titles with a safe Program-title reset' );
i427( false !== strpos( $js, 'focusOrAddCredentialDetail' ) && false !== strpos( $js, 'data-scoped-input' ), 'design Add value focuses an existing scoped fact before creating a duplicate one-off field' );
i427( false !== strpos( $js, 'state.issuance.design_field_aliases[target] = source' ), 'design-required facts can map to existing credential details' );

i427( false !== strpos( $rest, "self::BASE . '/review'" ) && false !== strpos( $rest, "self::BASE . '/issue'" ) && false !== strpos( $rest, 'new ManualIssuanceService()' ), 'Review and Issue REST routes converge on canonical ManualIssuanceService authority' );
i427( false !== strpos( $rest, "preg_match( '/^[a-f0-9]{64}$/'" ) && false !== strpos( $js, 'review_fingerprint:state.reviewFingerprint' ), 'final Issue requires the exact server Review fingerprint' );
i427( false !== strpos( $rest, "'title_source' =>" ) && false !== strpos( $js, "['Title source'" ), 'server Review and browser Review visibly preserve title provenance' );
i427( false !== strpos( $rest, 'certloom_issue_profile_repeating_data_invalid' ), 'REST boundary rejects stale profile-inappropriate repeating data' );
i427( false !== strpos( $rest, 'get_post_mime_type' ) && false !== strpos( $rest, "array( 'image/png', 'image/jpeg' )" ), 'uploaded-certificate image mode is MIME-validated server-side' );
i427( false !== strpos( $rest, "'email_url'" ) && false !== strpos( $rest, "'issue_another_url'" ) && false !== strpos( $rest, "'credentials_url'" ), 'receipt returns explicit post-commit continuations' );

i427( false === strpos( $global_js, 'initPhase2Issuance' ) && false === strpos( $global_js, 'initIssueDesignPreview' ), 'deleted one-page Issue Credential controllers do not compete with the dedicated app' );
i427( false !== strpos( $css, 'position: sticky;' ) && false !== strpos( $css, '.certloom-issue-summary' ), 'desktop app keeps a deliberate sticky decision summary' );
i427( false !== strpos( $css, '@media (max-width: 960px)' ) && false !== strpos( $css, 'position: static;' ), 'summary stops being sticky on narrower viewports' );
i427( ! preg_match( '/#[0-9a-fA-F]{3,8}\b/', $css ) && false === strpos( $css, '!important' ), 'dedicated 4.79 Issue Credential CSS adds no raw color or !important debt' );

echo "\n$pass alpha.4.79 Issue Credential compatibility checks, $fail failures\n";
exit( $fail ? 1 : 0 );
