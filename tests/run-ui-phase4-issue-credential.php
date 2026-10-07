<?php
/** Current Issue Credential six-step REST compatibility contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
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

foreach ( array( 'recipient','context','details','certificate','email','review' ) as $key ) {
    i427( false !== strpos( $manual, 'data-step-key="' . $key . '"' ), 'six-step app includes ' . $key . ' step' );
}
i427( false === strpos( $manual, 'data-step-key="data"' ) && false === strpos( $manual, 'data-step-key="authority"' ), 'Data and Authority are no longer standalone wizard steps' );
i427( false === strpos( $manual, 'A focused eight-step workflow.' ) && false === strpos( $manual, 'certloom-manual-issuance-technical-status' ), 'ordinary Issue Credential chrome omits internal review/release-status prose' );
i427( false !== strpos( $manual, "render_async_picker( 'recipient'" ) && false !== strpos( $manual, "render_async_picker( 'program'" ) && false !== strpos( $manual, "render_async_picker( 'batch'" ), 'Recipient, Program and Batch use one async-picker pattern' );
i427( false !== strpos( $manual, 'data-quick-dialog="program"' ) && false !== strpos( $manual, 'data-quick-dialog="batch"' ), 'Program and Batch remain quick-creatable without leaving issuance' );
i427( false !== strpos( $manual, 'type="button" data-quick-close' ) && false !== strpos( $js, "querySelectorAll('[data-quick-close]')" ), 'quick-create Cancel and close controls dismiss dialogs without becoming create submissions' );
i427( false !== strpos( $manual, 'data-field="credential_title"' ) && false !== strpos( $manual, "__( 'Set the title and lifecycle, then complete only the credential data this %s or certificate design needs.', 'certloom' )" ), 'profile-aware Program-derived title remains visibly editable inside merged Details' );
i427( false !== strpos( $manual, 'data-design-required-fields' ) && strpos( $manual, 'data-design-required-fields' ) < strpos( $manual, 'Additional one-off credential data' ), 'design-required facts stay visible before collapsed optional one-off data' );
i427( false !== strpos( $manual, 'data-step-key="details"' ) && false !== strpos( $manual, 'data-repeating-section' ) && false !== strpos( $manual, 'Subjects & results' ), 'structured Subjects/Results stay bounded inside merged Details' );

i427( false !== strpos( $manual, 'data-manage-program' ) && false !== strpos( $manual, 'data-independent-issuer' ) && false === strpos( $manual, 'data-step-key="authority"' ), 'Program-backed Issuer is managed on Program while independent credentials can still choose one in Context' );
i427( false !== strpos( $manual, 'data-presentation="details_only"' ) && false !== strpos( $manual, 'data-presentation="image"' ) && false !== strpos( $manual, 'data-presentation="design"' ), 'Certificate step exposes details-only, uploaded-image and Certloom-design choices' );
i427( false !== strpos( $manual, 'data-design-source="inherited"' ) && false !== strpos( $manual, 'data-design-source="assigned"' ), 'Certloom design supports explicit inherited or one-off assigned source' );
i427( false !== strpos( $manual, 'Live certificate preview' ) && false !== strpos( $manual, 'data-preview-stage' ) && false !== strpos( $manual, "render_async_picker( 'signer'" ), 'Certificate step keeps live preview and signer selection together' );
i427( false !== strpos( $manual, 'data-email-mode="review"' ) && false !== strpos( $manual, 'data-email-mode="later"' ) && false !== strpos( $manual, 'Email design' ), 'Email is a first-class post-commit step with an explicit design choice' );
i427( false !== strpos( $manual, 'Existing automation rules run separately and may send an email.' ) && false !== strpos( $manual, 'Manual email requires a separate review; configured automations may run after issuance.' ), 'issuance accurately distinguishes optional manual email review from post-commit automations' );

i427( false !== strpos( $manual, 'data-review-technical' ) && false !== strpos( $manual, "__( 'Technical details', 'certloom' )" ) && false !== strpos( $js, 'Review SHA-256' ), 'technical/release evidence remains available under progressive disclosure' );
i427( false !== strpos( $manual, 'data-issue-summary' ) && false !== strpos( $manual, 'data-summary-recipient' ) && false !== strpos( $manual, 'data-summary-batch' ) && false !== strpos( $manual, 'data-summary-email' ), 'live summary follows the important issuance decisions' );
i427( false !== strpos( $manual, 'data-issue-receipt' ), 'Review step contains an inline issuance receipt continuation surface' );

i427( false !== strpos( $manual, "'restNonce' => wp_create_nonce( 'wp_rest' )" ) && false !== strpos( $js, "'X-WP-Nonce': cfg.restNonce" ), 'REST requests use the dedicated WordPress REST nonce' );
i427( false !== strpos( $manual, "'previewUrl' => rest_url( 'certloom/v1/admin/issuance-preview' )" ) && false !== strpos( $js, 'cfg.previewUrl' ) && false !== strpos( $search, 'issuance_preview' ), 'live preview uses the canonical mutation-free issuance preview route' );
i427( false !== strpos( $js, 'AbortController' ) && false !== strpos( $js, 'previewController.abort()' ), 'stale async search/preview work is cancellable' );
i427( false !== strpos( $js, "url.searchParams.set('program_id', String(state.issuance.program_id || 0))" ) && false !== strpos( $js, "searchPicker('batch', '', true)" ), 'Batch lookup is freshly scoped to the current Program' );
i427( false !== strpos( $js, 'state.issuance.batch_id = 0' ) && false !== strpos( $js, "text('programChangedBatch', batchTerm + ' cleared because the ' + programTerm + ' changed." ), 'changing Program clears stale Batch state before refetch with active profile terminology' );
i427( false !== strpos( $js, "state.issuance.title_source = 'custom'" ) && false !== strpos( $js, 'data-use-program-title' ), 'direct title edits become one-off custom titles with a safe Program-title reset' );
i427( false !== strpos( $js, 'focusOrAddCredentialDetail' ) && false !== strpos( $js, 'data-scoped-input' ), 'design Add value focuses an existing scoped fact before creating a duplicate one-off field' );
i427( false !== strpos( $js, 'state.issuance.design_field_aliases[target] = source' ), 'design-required facts can map to existing credential details' );

i427( false !== strpos( $rest, "self::BASE . '/review'" ) && false !== strpos( $rest, "self::BASE . '/issue'" ) && false !== strpos( $rest, 'new ManualIssuanceService()' ), 'Review and Issue REST routes converge on canonical ManualIssuanceService authority' );
i427( false !== strpos( $rest, "preg_match( '/^[a-f0-9]{64}$/'" ) && false !== strpos( $js, 'review_fingerprint:state.reviewFingerprint' ), 'final Issue requires the exact server Review fingerprint' );
i427( false !== strpos( $rest, "'title_source' =>" ) && false !== strpos( $js, "['Title source'" ), 'server Review and browser Review visibly preserve title provenance' );
i427( false !== strpos( $rest, 'certloom_issue_profile_repeating_data_invalid' ), 'REST boundary rejects stale profile-inappropriate repeating data' );
i427( false !== strpos( $rest, 'TableDataContract::MAX_ROWS' ) && false !== strpos( $js, "limit('repeating_rows', 50)" ), 'REST bootstrap and browser editor share the canonical 50-row repeating-data ceiling' );
i427( false !== strpos( $rest, 'get_post_mime_type' ) && false !== strpos( $rest, "array( 'image/png', 'image/jpeg' )" ), 'uploaded-certificate image mode is MIME-validated server-side' );
i427( false !== strpos( $rest, "'email_url'" ) && false !== strpos( $rest, "'issue_another_url'" ) && false !== strpos( $rest, "'credentials_url'" ), 'receipt returns explicit post-commit continuations' );

i427( false === strpos( $global_js, 'initPhase2Issuance' ) && false === strpos( $global_js, 'initIssueDesignPreview' ), 'deleted one-page Issue Credential controllers do not compete with the dedicated app' );
i427( false !== strpos( $css, 'position: sticky;' ) && false !== strpos( $css, '.certloom-issue-summary' ), 'desktop app keeps a deliberate sticky decision summary' );
i427( false !== strpos( $css, '@media (max-width: 960px)' ) && false !== strpos( $css, 'position: static;' ), 'summary stops being sticky on narrower viewports' );
i427( ! preg_match( '/#[0-9a-fA-F]{3,8}\b/', $css ) && false === strpos( $css, '!important' ), 'dedicated 4.79 Issue Credential CSS adds no raw color or !important debt' );

// Alpha.4.81 live-WordPress acceptance corrections.
i427( false !== strpos( $css, '.certloom-issue-app [hidden]' ) && false !== strpos( $css, 'display: none;' ), 'HTML hidden state overrides flex/grid component display without !important debt' );
i427( false !== strpos( $css, '::-webkit-search-cancel-button' ) && false !== strpos( $css, '-webkit-appearance: none;' ), 'async picker suppresses the browser-native duplicate search clear affordance' );
i427( false !== strpos( $js, "if (status) { status.textContent = ''; }") && false !== strpos( $js, "if (selectedChoice && search.value === selectedLabel)" ), 'selected picker state clears stale match feedback and does not reopen its exact match on focus' );
i427( false !== strpos( $manual, "array( 'context', __( 'Context', 'certloom' )" ) && false !== strpos( $manual, "array( 'details', __( 'Details', 'certloom' )" ) && false !== strpos( $manual, "array( 'review', __( 'Review', 'certloom' )" ), 'stepper uses six compact stable visible task labels' );
i427( false !== strpos( $manual, 'private function render_summary( $program, $batch )' ) && false !== strpos( $manual, 'esc_html( $program )' ) && false !== strpos( $manual, 'esc_html( $batch )' ), 'live summary labels follow active profile Program/Batch terminology' );
i427( false !== strpos( $manual, "esc_html__( 'Credential title', 'certloom' ) . ' <em>*</em>" ) && false !== strpos( $manual, "esc_html__( 'Certificate number', 'certloom' ) . ' <em>*</em>" ) && false !== strpos( $manual, 'aria-required="true"' ), 'credential title and manual certificate number expose required semantics' );

// Alpha.4.82 live-acceptance presentation corrections.
i427( false !== strpos( $css, '.certloom-issue-step {' ) && false !== strpos( $css, 'min-height: 0;' ) && false === strpos( $css, 'min-height: 520px;' ), 'short issuance steps size naturally instead of forcing a large empty desktop panel' );

// Alpha.4.83 live-acceptance picker/dialog positioning corrections.
i427( false !== strpos( $manual, 'certloom-picker-heading' ) && false !== strpos( $manual, 'certloom-picker-quick-add' ) && strpos( $manual, 'certloom-picker-quick-add' ) < strpos( $manual, 'data-picker-search' ), 'quick-add action lives in the picker heading instead of underneath an open result list' );
i427( false !== strpos( $css, '.certloom-picker-results {' ) && false !== strpos( $css, 'position: static;' ) && false !== strpos( $css, 'margin-top: var(--cl-2);' ), 'picker results participate in normal flow so suggestions cannot cover adjacent actions' );
i427( false !== strpos( $css, '.certloom-dialog-actions {' ) && false !== strpos( $css, 'justify-content: space-between;' ) && false !== strpos( $css, '.certloom-dialog-actions .button-primary' ) && false !== strpos( $css, 'margin-left: auto;' ), 'quick-setup secondary and primary actions are deliberately separated across the dialog footer' );
i427( false !== strpos( $css, '.certloom-issue-dialog header > [data-quick-close]' ) && false !== strpos( $css, 'flex: 0 0 36px;' ) && false !== strpos( $css, 'height: 36px;' ), 'quick-setup close affordance has a dedicated accessible hit target away from the title' );
i427( false !== strpos( $manual, "__( 'Use %s default', 'certloom' )" ) && false !== strpos( $manual, 'No %1$s or %2$s relationship will be stored.' ) && false !== strpos( $manual, 'These values are frozen only on this credential and do not change the %1$s, %2$s or Recipient profile.' ), 'profile terminology stays consistent through credential, independent and one-off-data copy' );

// Alpha.4.84 six-step information architecture + Setup Wizard token alignment.
i427( substr_count( $manual, 'data-step-key=') === 6 && false !== strpos( $manual, 'Step 6 of 6' ) && false === strpos( $manual, 'Step 8 of 8' ), 'Issue Credential is reduced from eight to six steps' );
i427( false !== strpos( $manual, 'certloom-issue-data-inline' ) && false !== strpos( $manual, 'Credential data' ), 'Credential fields are merged into the Details step instead of consuming a separate step' );
i427( false !== strpos( $manual, 'certloom-issue-signing' ) && false !== strpos( $manual, 'Inherited authority' ), 'Signer and inherited authority are folded into Certificate instead of a standalone Authority step' );
i427( false !== strpos( $css, 'grid-template-columns: repeat(6, minmax(0, 1fr));' ) && false !== strpos( $css, 'var(--cl-brand-emerald)' ) && false !== strpos( $css, 'var(--cl-r-panel)' ), 'issuance progress and panels reuse Setup Wizard design tokens' );
i427( false !== strpos( $css, '.certloom-issue-step::before' ) && false !== strpos( $css, 'linear-gradient(90deg, var(--cl-brand-blue), var(--cl-cyan), var(--cl-brand-emerald))' ), 'issuance step card reuses the Setup Wizard brand accent language' );
i427( false !== strpos( $js, "card.classList.toggle('is-compact', 'design' !== state.issuance.presentation_mode)" ) && false !== strpos( $css, '.certloom-issue-preview-card.is-compact .certloom-issue-preview-stage' ), 'non-design certificate modes collapse the otherwise empty live-preview canvas' );
i427( false !== strpos( $js, 'var stepCount = stepPanels.length;' ) && false !== strpos( $js, 'var reviewStep = Math.max(0, stepCount - 1);' ), 'stepper logic derives its count instead of hard-coding eight steps' );


// Alpha.4.85 live-acceptance geometry, quick-dialog and navigation safety corrections.
i427( false !== strpos( $css, '.certloom-issue-grid {' ) && false !== strpos( $css, 'align-items: start;' ) && false !== strpos( $css, '.certloom-issue-field,' ) && false !== strpos( $css, 'align-content: start;' ), 'lifecycle controls align at the top even when only Validity has helper text' );
i427( false !== strpos( $css, '.certloom-issue-dialog {' ) && false !== strpos( $css, 'overflow-x: hidden;' ) && false !== strpos( $css, 'box-sizing: border-box;' ) && false !== strpos( $css, '.certloom-issue-dialog input {' ), 'all Issue Credential quick dialogs contain their inputs without horizontal overflow' );
i427( false !== strpos( $manual, 'name="title" required aria-required="true"' ) && false !== strpos( $manual, 'name="name" required aria-required="true"' ) && false !== strpos( $manual, "__( 'Optional — generated when blank', 'certloom' )" ), 'quick Program and Batch forms expose required/optional semantics clearly' );
i427( substr_count( $manual, 'target="_blank" rel="noopener noreferrer"' ) >= 3 && false !== strpos( $manual, 'data-manage-program target="_blank"' ), 'in-progress management links preserve Issue Credential in the current tab' );
i427( false !== strpos( $js, 'View existing credential</a>' ) && false !== strpos( $js, 'target="_blank" rel="noopener noreferrer"' ), 'duplicate recovery and post-issue navigation links also use safe new tabs' );
i427( false !== strpos( $manual, 'certloom-issue-field-action-cluster' ) && false !== strpos( $manual, 'certloom-issue-email-template' ), 'Email design management is grouped with its selector instead of floating below it' );
i427( false !== strpos( $manual, 'Check the final credential details before issuing.' ) && false !== strpos( $manual, 'Issuing creates an immutable credential version.' ), 'Review copy stays product-facing while retaining the immutable issuance boundary' );

// Alpha.4.86 Program credential fields + verification exposure and lifecycle geometry.
i427( false !== strpos( $js, 'verification_field_keys' ) && false !== strpos( $js, 'data-verification-field' ) && false !== strpos( $js, 'verificationFieldAllowed' ), 'Details exposes per-credential verification choices for resolved reusable fields' );
i427( false !== strpos( $manual, 'Fields configured for this credential context appear here.' ) && false !== strpos( $manual, 'certloom-issue-lifecycle-field' ), 'Details explains inherited credential fields and marks both lifecycle controls for shared geometry' );
i427( false !== strpos( $css, '.certloom-issue-lifecycle-field > input' ) && false !== strpos( $css, 'height: 44px;' ) && false !== strpos( $css, '.certloom-issue-scoped-entry' ) && false !== strpos( $css, '.certloom-issue-verification-toggle' ), 'lifecycle controls have identical explicit height and reusable fields receive verification-choice layout' );
i427( false !== strpos( $rest, "'value' => (string) \$field_values[ \$key ]" ) && false !== strpos( $js, "text('publicVerificationValues'" ), 'server Review exposes exact public verification field values for confirmation' );
$service486 = file_get_contents( $root . '/src/Application/Issuance/ManualIssuanceService.php' );
$contract486 = file_get_contents( $root . '/src/Application/Fields/ScopedFieldContract.php' );
i427( false !== strpos( $service486, 'apply_verification_selection' ) && false !== strpos( $service486, "'verification_field_keys'" ), 'Manual Issuance freezes the per-credential verification selection before field values are committed' );
i427( false !== strpos( $contract486, 'certloom_scoped_field_verification_selection_private' ) && false !== strpos( $contract486, "'both' === \$exposure ? 'profile' : 'private'" ), 'verification selection can tighten but never widen the field privacy contract' );

echo "\n$pass alpha.4.79 Issue Credential compatibility checks, $fail failures\n";
exit( $fail ? 1 : 0 );
