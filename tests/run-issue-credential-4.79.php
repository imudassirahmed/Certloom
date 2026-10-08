<?php
/** Current Issue Credential REST application contract, evolved from alpha.4.79. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$rest = file_get_contents( $root . '/src/Infrastructure/Admin/IssueCredentialRest.php' );
$batch_repository = file_get_contents( $root . '/src/Infrastructure/Repository/BatchRepository.php' );
$search = file_get_contents( $root . '/src/Infrastructure/Admin/AdminSearchRest.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );
$js = file_get_contents( $root . '/admin/assets/issue-credential.js' );
$global_js = file_get_contents( $root . '/admin/assets/admin.js' );
$css = file_get_contents( $root . '/admin/assets/issue-credential.css' );
$foundation_css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$pass = 0; $fail = 0;
function i479( $ok, $label ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS  $label\n"; } else { ++$fail; echo "FAIL  $label\n"; } }

// Application shell and step architecture.
i479( substr_count( $admin, 'data-step-key=') >= 6 && false === strpos( $admin, 'A focused eight-step workflow' ), 'Issue Credential keeps the focused six-step workflow without internal explanatory hero copy' );
foreach ( array( 'Recipient', 'Credential details', 'Certificate and signing', 'Email', 'Review & issue' ) as $label ) {
	i479( false !== strpos( $admin, $label ), 'step/source contains ' . $label );
}
i479( false !== strpos( $admin, "'%1\$s & %2\$s'" ) && false !== strpos( $admin, '$program, $batch' ), 'context step combines active Program and Batch terminology' );
i479( false !== strpos( $admin, 'data-issue-step="5"' ) && false === strpos( $admin, 'data-issue-step="6"' ), 'sixth Review step is final' );
i479( false !== strpos( $admin, "render_async_picker( 'program'" ) && false !== strpos( $admin, "render_async_picker( 'batch'" ), 'Program and Batch are async pickers' );
i479( false !== strpos( $admin, 'data-manage-program' ) && false !== strpos( $admin, 'data-independent-issuer' ) && false === strpos( $admin, 'data-step-key="authority"' ), 'Program-backed issuer authority is managed from Program context instead of a standalone issuance step' );
i479( false !== strpos( $admin, 'data-field="credential_title"' ), 'credential title is always a visible text field' );
i479( false !== strpos( $admin, 'data-step-key="details"' ) && false !== strpos( $admin, 'data-design-required-fields' ) && false !== strpos( $admin, 'data-oneoff-fields' ) && false === strpos( $admin, 'data-step-key="data"' ), 'credential data is merged into Details while design-required and one-off surfaces remain distinct' );
i479( false !== strpos( $admin, 'data-preview-stage' ) && false !== strpos( $admin, 'data-preview-status' ) && false !== strpos( $admin, "render_async_picker( 'signer'" ), 'certificate step owns live preview and signer selection' );
i479( false !== strpos( $admin, 'data-email-mode="review"' ) && false !== strpos( $admin, 'data-email-mode="later"' ), 'Email is an explicit post-issuance decision' );
i479( false !== strpos( $admin, 'Existing automation rules run separately and may send an email.' ) && false !== strpos( $admin, 'Prepare a manual email later' ), 'Email copy distinguishes manual follow-up from configured automations' );
i479( false !== strpos( $admin, 'data-review-technical' ), 'technical evidence remains available without cluttering primary review' );
i479( false !== strpos( $admin, 'type="button" data-quick-close' ) && false !== strpos( $js, "querySelectorAll('[data-quick-close]')" ), 'quick-create dialogs have non-submitting close controls' );

// REST application authority.
i479( false !== strpos( $plugin, 'IssueCredentialRest() )->register()' ), 'Plugin registers single Issue Credential REST application' );
foreach ( array( '/bootstrap', '/program-context', '/quick-program', '/quick-batch', '/review', '/issue', '/receipt/' ) as $route ) {
	i479( false !== strpos( $rest, $route ), 'REST route exists: ' . $route );
}
i479( false !== strpos( $rest, 'new ManualIssuanceService() )->review' ) && false !== strpos( $rest, '$service->issue(' ), 'Review and Issue converge on canonical ManualIssuanceService' );
i479( false !== strpos( $rest, "preg_match( '/^[a-f0-9]{64}$/', \$fingerprint )" ), 'Issue requires exact SHA-256 review fingerprint' );
i479( false !== strpos( $rest, 'certloom_issue_profile_repeating_data_invalid' ), 'REST rejects profile-inappropriate repeating rowsets' );
i479( false !== strpos( $rest, 'TableDataContract::MAX_ROWS' ) && false !== strpos( $js, "limit('repeating_rows', 50)" ), 'browser and REST bootstrap use the canonical repeating-data row cap' );
i479( false !== strpos( $rest, 'get_post_mime_type' ) && false !== strpos( $rest, "array( 'image/png', 'image/jpeg' )" ), 'REST independently validates uploaded certificate image MIME' );
i479( false !== strpos( $rest, 'ScopedFieldGlobalRepository' ) && false !== strpos( $rest, 'resolve_hierarchy(' ), 'Program context resolves Global → Issuer → Program → Batch field hierarchy' );
i479( false !== strpos( $batch_repository, 'wp_generate_uuid4()' ) && false !== strpos( $rest, "'code' => \$code" ), 'quick Batch delegates optional code generation to the shared repository' );
i479( false !== strpos( $rest, "'email_url'" ) && false !== strpos( $rest, "'issue_another_url'" ), 'Issue returns post-commit continuation URLs' );
i479( false === strpos( $rest, 'wp_mail(' ), 'Issue REST controller never sends email itself' );

// REST preview accepts JSON from the dedicated app.
i479( false !== strpos( $search, '$request->get_json_params()' ) && false !== strpos( $search, '$request->get_body_params()' ), 'mutation-free preview accepts JSON with form-body fallback' );

// Client-state behavior.
i479( false !== strpos( $js, "function searchPicker(kind, query, immediate)" ), 'dedicated client owns on-demand async picker search' );
i479( false !== strpos( $js, "if ('batch' === kind) { url.searchParams.set('program_id', String(state.issuance.program_id || 0)); }" ), 'Batch search always scopes by current Program id' );
i479( false !== strpos( $js, "clearPicker('batch', {silent:true});" ) && false !== strpos( $js, "state.issuance.batch_id = 0;" ), 'Program change clears stale Batch before loading replacements' );
i479( false !== strpos( $js, 'state.programContextController.abort(); state.programContextController = null;' ) && false !== strpos( $js, "state.issuance.issuer_id = state.selected.issuer ? state.selected.issuer.id : 0;" ), 'Program context is cancelled before Independent mode restores only an explicit independent Issuer choice' );
i479( false !== strpos( $js, "searchPicker('batch', '', true);" ), 'Program change immediately fetches fresh Batches' );
i479( false !== strpos( $js, "state.issuance.credential_title = String(choice.title || choice.label || '');" ), 'Program title prefills credential title' );
i479( false !== strpos( $js, "state.issuance.title_source = 'custom'" ), 'typing into title can switch only this issuance to a custom title' );
i479( false !== strpos( $js, 'data-use-program-title' ), 'custom title can reset to Program title without editing Program' );
i479( false !== strpos( $js, "rest(cfg.previewUrl, {method:'POST', body:state.issuance" ), 'live preview posts unsaved issuance state through REST' );
i479( false !== strpos( $js, 'AbortController' ) && false !== strpos( $js, 'previewController' ), 'preview/search requests can cancel stale work' );
i479( false !== strpos( $js, 'renderDesignRequired' ) && false !== strpos( $js, 'design_field_aliases' ), 'design-required data can map to existing credential details' );
i479( false !== strpos( $js, "focusExistingScopedField" ) || false !== strpos( $js, "data-scoped-field" ), 'design Add value path can route to existing scoped data' );
i479( false !== strpos( $js, 'function buildReview()' ) && false !== strpos( $js, 'state.reviewFingerprint' ), 'client builds and retains server Review fingerprint' );
i479( false !== strpos( $js, 'function issueCredential()' ) && false !== strpos( $js, 'review_fingerprint:state.reviewFingerprint' ), 'final Issue submits exact Review fingerprint' );
i479( false !== strpos( $js, 'renderReceipt' ) && false !== strpos( $js, 'issue_another_url' ), 'client renders inline receipt and continuations' );
i479( false !== strpos( $js, 'cfg.deepLink' ) && false !== strpos( $js, 'recipient_id' ) && false !== strpos( $js, 'batch_id' ), 'client hydrates Recipient/Program/Batch deep links through bootstrap' );
i479( false === strpos( $js, 'location.reload(' ) && false === strpos( $js, 'window.location.reload(' ), 'issuance journey does not rely on page reloads' );

// Deleted old one-page implementation.
i479( false === strpos( $global_js, 'initPhase2Issuance' ), 'old global Phase-2 Issue Credential controller is deleted' );
i479( false === strpos( $global_js, 'initIssueDesignPreview' ), 'old global Issue Credential preview controller is deleted' );
i479( false === strpos( $global_js, 'data-certloom-phase2-issuance' ), 'global JS no longer targets deleted mega-form' );
i479( false === strpos( $foundation_css, 'certloom-issue-v2' ) && false === strpos( $foundation_css, 'certloom-issue-context-rail' ) && false === strpos( $foundation_css, 'certloom-manual-steps' ), 'legacy one-page Issue Credential CSS is deleted from the shared foundation' );

// Dedicated style contract does not add design-system debt.
i479( false !== strpos( $css, '.certloom-issue-app-body' ) && false !== strpos( $css, 'grid-template-columns: minmax(0, 1fr) minmax(270px, 320px);' ), 'dedicated CSS provides workspace plus sticky summary layout' );
i479( false !== strpos( $css, 'grid-template-columns: repeat(6, minmax(0, 1fr));' ) && false !== strpos( $css, 'linear-gradient(90deg, var(--cl-brand-blue), var(--cl-cyan), var(--cl-brand-emerald))' ), 'six-step progress reuses Setup Wizard token and gradient language' );
i479( false !== strpos( $css, '.certloom-issue-summary' ) && false !== strpos( $css, 'position: sticky;' ), 'live summary stays available on desktop' );
i479( false !== strpos( $css, '@media (max-width: 960px)' ) && false !== strpos( $css, 'position: static;' ), 'responsive layout removes sticky summary on narrow screens' );
i479( 0 === preg_match_all( '/#[0-9a-fA-F]{3,8}/', $css ), 'dedicated CSS adds no raw hex colours' );
i479( false === strpos( $css, '!important' ), 'dedicated CSS adds no !important overrides' );
i479( 0 === preg_match_all( '/border-radius\s*:\s*[0-9]/', $css ), 'dedicated CSS uses radius tokens only' );

i479( false !== strpos( $rest, "'title_source' =>" ), 'Review payload preserves resolved title provenance' );
i479( false !== strpos( $js, "['Title source'" ), 'Review UI communicates resolved title provenance' );
echo "\n{$pass} alpha.4.79 Issue Credential checks, {$fail} failures\n";
exit( $fail ? 1 : 0 );
