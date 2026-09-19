<?php
/** Alpha.4.79 live-search + compact REST issuance compatibility contract. */
$root = dirname( __DIR__ );
$search = file_get_contents( $root . '/src/Infrastructure/Admin/AdminSearchRest.php' );
$issue_rest = file_get_contents( $root . '/src/Infrastructure/Admin/IssueCredentialRest.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );
$lookup = file_get_contents( $root . '/src/Infrastructure/Admin/ScopedFieldAdminAjax.php' );
$domain = file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' );
$manual = file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$service = file_get_contents( $root . '/src/Application/Issuance/ManualIssuanceService.php' );
$bulk = file_get_contents( $root . '/src/Infrastructure/Admin/BulkIssuanceAdmin.php' );
$global_js = file_get_contents( $root . '/admin/assets/admin.js' );
$issue_js = file_get_contents( $root . '/admin/assets/issue-credential.js' );
$foundation = file_get_contents( $root . '/admin/assets/certloom-foundation.css' );
$issue_css = file_get_contents( $root . '/admin/assets/issue-credential.css' );
$legacy_css = file_get_contents( $root . '/admin/assets/admin.css' );
$pass=0;$fail=0;
function c429($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
foreach(array('search'=>$search,'issue_rest'=>$issue_rest,'plugin'=>$plugin,'manual'=>$manual,'service'=>$service,'issue_js'=>$issue_js,'issue_css'=>$issue_css) as $name=>$src){c429(is_string($src)&&''!==$src,'source '.$name.' available');}

c429(false!==strpos($foundation,'.certloom-dashboard-date-line.is-expiry .certloom-dashboard-date-dot')&&false!==strpos($foundation,'background: var(--cl-error);'),'Dashboard expiry marker uses semantic red');
c429(false!==strpos($foundation,'height: 42px;')&&false!==strpos($foundation,'.certloom-credential-filters input[type="search"]')&&false!==strpos($foundation,'.certloom-credential-filters select'),'Credentials primary filter controls share one desktop height');
c429(false!==strpos($legacy_css,'.certloom-domain-screen .certloom-credential-filters > .button[type="submit"]')&&false!==strpos($legacy_css,'height: 42px;'),'legacy filter button conflict remains resolved');
c429(false!==strpos($domain,'data-certloom-credential-live-filter')&&false!==strpos($domain,'data-certloom-credential-results'),'Credentials registry keeps progressive live-result hooks');
c429(false!==strpos($domain,'public function credential_results_payload')&&false!==strpos($domain,'render_credential_results'),'live and normal Credentials requests share one authoritative renderer');
c429(false!==strpos($search,"'/admin/credentials'")&&false!==strpos($search,"'/admin/context'")&&false!==strpos($search,"'/admin/template-preview'"),'shared authenticated REST routes cover registry/context/design preview');
c429(false!==strpos($search,'ScopedFieldAdminAjax::can_lookup()')&&false!==strpos($search,'CAP_VIEW_CREDENTIALS')&&false!==strpos($search,'CAP_ISSUE_CREDENTIALS'),'shared REST endpoints retain capability checks');
c429(false!==strpos($lookup,'public function choices(')&&false!==strpos($lookup,'public static function can_lookup()'),'legacy AJAX and REST context lookup share bounded lookup logic');
c429(false!==strpos($plugin,'( new \\Certloom\\Infrastructure\\Admin\\AdminSearchRest() )->register();')&&false!==strpos($plugin,'( new \\Certloom\\Infrastructure\\Admin\\IssueCredentialRest() )->register();'),'plugin registers shared search and single Issue Credential REST controllers');

c429(false!==strpos($manual,'certloom-issue-app-body')&&false!==strpos($manual,'certloom-issue-workspace')&&false!==strpos($manual,'certloom-issue-summary'),'Issue Credential uses a compact workspace plus separate summary rail rather than the deleted mega-form');
c429(false!==strpos($issue_css,'.certloom-issue-app-body')&&false!==strpos($issue_css,'.certloom-issue-summary')&&false!==strpos($issue_css,'position: sticky;'),'desktop composition keeps the task lane and sticky decision summary deliberate');
c429(false!==strpos($manual,'data-preview-stage')&&false!==strpos($issue_js,'function schedulePreview')&&false!==strpos($issue_js,'function renderPreview'),'Certificate step exposes a bounded live preview driven by the dedicated app');
c429(false!==strpos($issue_css,'.certloom-issue-preview-stage')&&false!==strpos($issue_css,'overflow: hidden;'),'live design preview remains bounded and scrollbar-free');

c429(false!==strpos($manual,'data-design-source="inherited"')&&false!==strpos($manual,'data-design-source="assigned"'),'Issue Credential exposes inherited versus one-off assigned design choices');
c429(false!==strpos($issue_js,"state.issuance.design_source = button.getAttribute('data-design-source')")&&false!==strpos($issue_js,"if ('inherited' === state.issuance.design_source)"),'client keeps inherited/assigned design state explicit');
c429(false!==strpos($issue_js,"if ('design' !== state.issuance.presentation_mode) { renderPreview(); return; }")&&false!==strpos($issue_js,'cfg.previewUrl'),'preview only calls issuance-preview when Certloom design is active');
c429(false!==strpos($issue_js,"url.searchParams.set('program_id', String(state.issuance.program_id || 0))")&&false!==strpos($issue_js,"searchPicker('batch', '', true)"),'Batch search is loaded on demand for the current Program');
c429(false!==strpos($issue_js,'state.issuance.batch_id = 0')&&false!==strpos($issue_js,'clearPicker(\'batch\'') ,'Program changes immediately clear stale Batch state');
c429(false!==strpos($issue_js,'AbortController')&&false!==strpos($issue_js,'state.searchControllers[kind].abort()'),'relationship typing cancels stale REST searches');
c429(false!==strpos($issue_js,"'X-WP-Nonce': cfg.restNonce"),'Issue Credential REST traffic uses the WordPress REST nonce');

c429(false!==strpos($service,"'credential_basis'")&&false!==strpos($service,"'independent' === ")&&false!==strpos($service,'$credential_basis')&&false!==strpos($service,'if ( $batch_id && ! $program_id )'),'standalone issuance remains explicit while Batch still requires Program ownership');
c429(false!==strpos($service,'certloom_manual_issue_program_expiry_requires_program')&&false!==strpos($service,"'program' === ")&&false!==strpos($service,'$expiry_mode && ! $program_id'),'Program validity remains dependent on a selected Program');
c429(substr_count($service,"'design_source'")>=4&&false!==strpos($service,'template_resolution_scope_id'),'immutable review/snapshot retain inherited or assigned design provenance');
c429(false!==strpos($issue_rest,'new ScopedFieldContract() )->resolve_hierarchy')&&false!==strpos($issue_rest,'ScopedFieldGlobalRepository'),'REST Program context resolves canonical Global → Issuer → Program → Batch field hierarchy');
c429(false!==strpos($issue_rest,"self::BASE . '/program-context'")&&false!==strpos($issue_js,'loadProgramContext'),'Program context itself is refreshed through authenticated REST');

c429(false!==strpos($bulk,'name="bulk_design_source" value="inherited"')&&false!==strpos($bulk,'name="bulk_design_source" value="assigned"'),'Bulk Issuance keeps the same inherited/assigned design vocabulary where applicable');
c429(false!==strpos($global_js,'function initBulkDesignSource()')&&false!==strpos($global_js,'data-certloom-bulk-design-source-status'),'Bulk Issuance still progressively reveals its assigned-design path');
c429(false===strpos($global_js,'initPhase2Issuance')&&false===strpos($global_js,'initIssueDesignPreview'),'global admin JS no longer boots a competing Issue Credential controller');

echo "\n$pass alpha.4.79 live-search/compact issuance checks, $fail failures\n";
exit($fail?1:0);
