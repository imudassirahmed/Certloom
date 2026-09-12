<?php
/** Alpha.4.29 live search + standalone inherited-design issuance source contract. */
$root = dirname( __DIR__ );
$rest = file_get_contents( $root . '/src/Infrastructure/Admin/AdminSearchRest.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );
$lookup = file_get_contents( $root . '/src/Infrastructure/Admin/ScopedFieldAdminAjax.php' );
$domain = file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$service = file_get_contents( $root . '/src/Application/Issuance/ManualIssuanceService.php' );
$bulk = file_get_contents( $root . '/src/Infrastructure/Admin/BulkIssuanceAdmin.php' );
$admin = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$js = file_get_contents( $root . '/admin/assets/admin.js' );
$css = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$legacy_css = file_get_contents( $root . '/admin/assets/admin.css' );
$pass=0;$fail=0;
function c429($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}

c429(false!==strpos($css,'.certloom-dashboard-date-line.is-expiry .certloom-dashboard-date-dot')&&false!==strpos($css,'background: var(--cl-error);'),'Dashboard expiry marker uses semantic red');
c429(false!==strpos($css,'height: 42px;')&&false!==strpos($css,'.certloom-credential-filters input[type="search"]')&&false!==strpos($css,'.certloom-credential-filters select'),'Credentials primary filter controls share one desktop height');
c429(false!==strpos($legacy_css,'.certloom-domain-screen .certloom-credential-filters > .button[type="submit"]')&&false!==strpos($legacy_css,'height: 42px !important;'),'legacy button conflict is resolved in the legacy compatibility layer');
c429(false!==strpos($domain,'data-certloom-credential-live-filter')&&false!==strpos($domain,'data-certloom-credential-results')&&false!==strpos($domain,'data-certloom-credential-live-status'),'Credentials registry exposes progressive-enhancement hooks for live results');
c429(false!==strpos($domain,'public function credential_results_payload')&&false!==strpos($domain,'render_credential_results'),'live and normal Credentials requests share one authoritative renderer');
c429(false!==strpos($rest,"'/admin/credentials'")&&false!==strpos($rest,"'/admin/context'")&&false!==strpos($rest,"'/admin/template-preview'"),'authenticated REST routes cover registry results, shared context search and design preview');
c429(false!==strpos($rest,'ScopedFieldAdminAjax::can_lookup()')&&false!==strpos($rest,'CAP_VIEW_CREDENTIALS')&&false!==strpos($rest,'CAP_ISSUE_CREDENTIALS'),'REST endpoints retain capability checks');
c429(false!==strpos($lookup,'public function choices(')&&false!==strpos($lookup,'public static function can_lookup()'),'legacy AJAX and REST context lookup share reusable bounded lookup logic');
c429(false!==strpos($plugin,'( new \\Certloom\\Infrastructure\\Admin\\AdminSearchRest() )->register();')&&false!==strpos($plugin,'DomainFeatureGate::admin_preview_ready()'),'REST routes register outside ordinary wp-admin rendering while respecting the preview gate');
c429(false!==strpos($admin,"'restContextUrl' => rest_url( 'certloom/v1/admin/context' )")&&false!==strpos($admin,"'restCredentialsUrl' => rest_url( 'certloom/v1/admin/credentials' )")&&false!==strpos($admin,"'restTemplatePreviewUrl' => rest_url( 'certloom/v1/admin/template-preview' )"),'browser receives canonical REST URLs and WP REST nonce');
c429(false!==strpos($js,'function initCredentialLiveSearch()')&&false!==strpos($js,"search.addEventListener( 'input', schedule )")&&false!==strpos($js,'AbortController'),'Credentials typing is debounced and stale REST requests are cancelled');
c429(false!==strpos($js,'certloomL10n.restContextUrl')&&false!==strpos($js,"headers: certloomL10n.restNonce ? { 'X-WP-Nonce': certloomL10n.restNonce } : {}"),'shared relationship searches prefer authenticated REST');
c429(false!==strpos($manual,'certloom-issue-left-column')&&false!==strpos($manual,'certloom-issue-essential-fields')&&false!==strpos($css,'grid-template-areas:')&&false!==strpos($css,'"main presentation"'),'Issue Credential uses a compact two-column task composition with recipient and credential fields grouped in the main column');
c429(substr_count($manual,'legend class="screen-reader-text"')>=3,'redundant Recipient/Credential/Presentation legends remain accessible but are visually removed');
c429(false!==strpos($css,'.certloom-issue-essential-fields')&&false!==strpos($css,'grid-template-columns: 1fr;'),'core credential fields use one compact column');
c429(false!==strpos($manual,'data-certloom-issue-template-select')&&false!==strpos($manual,'data-certloom-issue-design-preview')&&false!==strpos($manual,'data-certloom-template-preview-canvas'),'Issue Credential exposes a bounded live certificate-design preview surface');
c429(false!==strpos($js,'function initIssueDesignPreview()')&&false!==strpos($js,'restTemplatePreviewUrl')&&false!==strpos($js,'fitWizardTemplatePreview( panel )'),'design selection fetches and fits preview content without a scroll-window workflow');
c429(false!==strpos($css,'.certloom-issue-design-preview-canvas')&&false!==strpos($css,'height: 210px;')&&false!==strpos($css,'overflow: hidden;'),'desktop design preview is intentionally small and scrollbar-free');
c429(false!==strpos($css,'.certloom-presentation-panel[hidden]')&&false!==strpos($css,'display: none;'),'layout grid never exposes inactive presentation panels');
c429(false===strpos($css,'.certloom-domain-screen .certloom-credential-filters > .button[type="submit"] {\n  min-height: 42px !important;'),'new foundation layer does not absorb the legacy !important conflict');


c429(false!==strpos($manual,'name="design_source" value="inherited"')&&false!==strpos($manual,'name="design_source" value="assigned"'),'Issue Credential exposes explicit inherited versus assigned design choices');
c429(false!==strpos($manual,'data-certloom-assigned-design-field')&&false!==strpos($js,'assignedField.hidden = ! assigned'),'assigned-design selector is conditionally hidden until assigned mode is chosen');
c429(false!==strpos($manual,'data-certloom-template-preview-source')&&false!==strpos($rest,"Inherited from Global design")&&false!==strpos($rest,"Inherited from Program"),'live design preview reports inheritance provenance including Global and Program sources');
c429(false!==strpos($js,"form.addEventListener( 'change'")&&false!==strpos($js,"'certloom_manual_issuance_step' !== event.target.name")&&false!==strpos($js,"form.addEventListener( 'input'"),'live issuance preview refreshes when hierarchy or credential data changes');
c429(false!==strpos($service,"'credential_basis'")&&false!==strpos($service, '\'independent\' === $credential_basis')&&false!==strpos($service,'if ( $batch_id && ! $program_id )'),'standalone issuance is an explicit Independent mode while Batch still requires its owning Program');
c429(false!==strpos($service,'certloom_manual_issue_program_expiry_requires_program')&&false!==strpos($service,"'program' === \$expiry_mode && ! \$program_id"),'Program validity remains correctly dependent on selecting a Program');
c429(substr_count($service, "'design_source'")>=4&&false!==strpos($service,'template_resolution_scope_id'),'immutable review and issuance snapshot retain inherited/assigned design source');
c429(false!==strpos($rest,'resolve_published_assignment_details')&&false!==strpos($rest,"'issuer_id' => absint")&&false!==strpos($rest,"'program_id' => absint")&&false!==strpos($rest,"'batch_id' => absint"),'inherited preview resolves the same Global → Issuer → Program → Batch assignment context');
c429(false!==strpos($bulk,'name="bulk_design_source" value="inherited"')&&false!==strpos($bulk,'name="bulk_design_source" value="assigned"')&&false!==strpos($bulk,'data-certloom-bulk-assigned-design'),'Bulk Issuance uses the same inherited/assigned design-source vocabulary where applicable');
c429(false!==strpos($js,'function initBulkDesignSource()')&&false!==strpos($js,'data-certloom-bulk-design-source-status'),'Bulk Issuance progressively reveals assigned design and reports inherited source state');

echo "\n$pass alpha.4.29 live-search/standalone-inherited-design source checks, $fail failures\n";
exit($fail?1:0);
