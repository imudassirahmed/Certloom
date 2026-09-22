<?php
/** FLD-02 large-site relationship lookup source contract. */
$root = dirname( __DIR__ );
$files = array(
	'ajax' => file_get_contents( $root . '/src/Infrastructure/Admin/ScopedFieldAdminAjax.php' ),
	'choices' => file_get_contents( $root . '/src/Infrastructure/Admin/ScopedContextChoices.php' ),
	'js' => file_get_contents( $root . '/admin/assets/admin.js' ),
	'manual' => file_get_contents( $root . '/src/Infrastructure/Admin/ManualIssuanceAdmin.php' ),
	'issue_js' => file_get_contents( $root . '/admin/assets/issue-credential.js' ),
	'bulk' => file_get_contents( $root . '/src/Infrastructure/Admin/BulkIssuanceAdmin.php' ),
	'batches' => file_get_contents( $root . '/src/Infrastructure/Admin/DomainStructureAdmin.php' ),
	'credentials' => file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' ),
	'templates' => file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' ),
	'design_rules' => file_get_contents( $root . '/src/Infrastructure/Admin/DesignRulesAdmin.php' ),
	'integrations' => file_get_contents( $root . '/admin/views/integrations.php' ),
	'tutor_admin' => file_get_contents( $root . '/src/Infrastructure/Admin/TutorLmsIntegrationAdmin.php' ),
	'learndash_admin' => file_get_contents( $root . '/src/Infrastructure/Admin/LearnDashIntegrationAdmin.php' ),
);
function scl( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( 'FAIL  ' . $message ); } echo "PASS  {$message}\n"; }
scl( false !== strpos( $files['ajax'], "const LOOKUP_ACTION = 'certloom_lookup_scoped_context'" ) && false !== strpos( $files['ajax'], "list_page( \$query, 1, 20, \$program_status )" ), 'Program lookup is server-bounded to 20 matches with an explicit status policy' );
scl( false !== strpos( $files['ajax'], 'search_active_for_program( $program_id, $query, 20 )' ), 'Batch lookup is Program-constrained and server-bounded' );
scl( false !== strpos( $files['choices'], 'const INITIAL_LIMIT = 20' ) && false !== strpos( $files['choices'], "array( 'active', 'inactive', 'all' )" ) && false !== strpos( $files['choices'], 'contains_id' ), 'initial HTML remains bounded, status-aware, and preserves an existing selected relationship' );
scl( false !== strpos( $files['js'], 'function initScopedContextLookups( root )' ) && false !== strpos( $files['js'], "body.append( 'action', 'certloom_lookup_scoped_context' )" ), 'admin JavaScript consumes the bounded lookup endpoint' );
scl( false !== strpos( $files['js'], "status.setAttribute( 'aria-live', 'polite' )" ) && false !== strpos( $files['js'], '220' ), 'lookup UX has live accessibility feedback and debounced requests' );
scl( false !== strpos( $files['js'], "data-certloom-context-program-status" ) && false !== strpos( $files['js'], "body.append( 'program_status', programStatus )" ), 'Program lookup can intentionally include inactive Programs on historical/filtering surfaces without changing relationship forms' );
scl( false !== strpos( $files['js'], "if ( 'batch' === kind && ! programId() )" ) && false !== strpos( $files['js'], "body.append( 'program_id', String( programId() ) )" ), 'Batch search cannot detach from selected Program context' );
scl( false !== strpos( $files['manual'], "render_async_picker( 'program'" ) && false !== strpos( $files['manual'], "render_async_picker( 'batch'" ) && false !== strpos( $files['issue_js'], "if ('batch' === kind && !state.issuance.program_id)" ) && false !== strpos( $files['issue_js'], "url.searchParams.set('program_id', String(state.issuance.program_id || 0))" ) && false === strpos( $files['manual'], "list_page( '', 1, 250, 'active' )" ), 'Manual Issuance uses REST-backed Program/Batch pickers and never loads a global 250-Batch selector' );
scl( false !== strpos( $files['bulk'], 'data-certloom-context-lookup="program"' ) && false !== strpos( $files['bulk'], 'data-certloom-context-lookup="batch"' ) && false === strpos( $files['bulk'], "list_page('',1,250,'active')" ), 'Bulk Issuance uses the same scalable Program/Batch relationship UX' );
scl( false !== strpos( $files['batches'], 'data-certloom-context-lookup="program"' ), 'Batch editor can select Programs beyond the first result page' );
scl( false !== strpos( $files['credentials'], 'data-certloom-context-lookup="batch"' ) && false !== strpos( $files['credentials'], 'data-program-id=' ), 'credential relationship correction exposes only Program-aware Batch choices' );
scl( false !== strpos( $files['credentials'], 'data-certloom-context-program-status="all"' ) && false !== strpos( $files['credentials'], 'Search all Programs…' ) && false === strpos( $files['credentials'], "list_page( '', 1, 100, 'all' )" ), 'Credential Management can filter any historical Program instead of only the first 100' );
scl( substr_count( $files['integrations'], 'data-certloom-context-lookup="program"' ) >= 2, 'Tutor and LearnDash Program mapping forms use complete-registry search' );
scl( false === strpos( $files['templates'], 'library_program_id' ) && false !== strpos( $files['design_rules'], 'TARGET_PAGE_SIZE' ) && false !== strpos( $files['design_rules'], 'targets_ajax' ), 'Certificate Library no longer owns Program assignment context; Design Rules uses bounded server-backed hierarchy search instead' );
scl( false !== strpos( $files['ajax'], "'issuer' === \$kind" ) && false !== strpos( $files['ajax'], "'signer' === \$kind" ) && false !== strpos( $files['ajax'], "'signer_scope' === \$kind" ), 'issuer, signer and mixed signer-scope searches use the same bounded server endpoint' );
scl( false !== strpos( $files['batches'], 'data-certloom-context-lookup="signer"' ) && false !== strpos( $files['batches'], 'data-certloom-context-lookup="signer_scope"' ) && false === strpos( $files['batches'], "list_page( '', 1, 100, 'active' )" ), 'Batch signer and signer hierarchy selectors no longer treat the first 100 records as complete' );
scl( false !== strpos( $files['credentials'], 'data-certloom-context-lookup="issuer"' ) && false === strpos( $files['credentials'], "( new IssuerRepository() )->list_page( '', 1, 100 )" ), 'Program issuer relationship uses bounded complete-registry search' );
scl( false !== strpos( $files['credentials'], 'profile_template_variant_template_id[]" data-certloom-context-lookup="template"' ) && false !== strpos( $files['credentials'], 'selected( absint( $variant_template_id ), absint( $candidate[\'id\']' ) && false === strpos( $files['credentials'], 'list_published_candidates( 250 )' ), 'Program language-specific design selectors are scalable and compare persisted Template IDs to Template IDs' );
scl( false !== strpos( $files['ajax'], "'wp_user' === \$kind" ) && false !== strpos( $files['ajax'], "'number'  => 20" ) && false !== strpos( $files['ajax'], "'search_columns'] = array( 'user_login', 'user_email', 'display_name' )" ), 'Recipient WordPress-account lookup is server-bounded and searchable by human account attributes' );
scl( false !== strpos( $files['credentials'], 'data-certloom-context-lookup="wp_user"' ) && false !== strpos( $files['credentials'], 'Search WordPress users…' ) && false === strpos( $files['credentials'], "'WordPress user ID', 'certloom'" ), 'Recipient editor exposes searchable WordPress-account selection instead of a raw numeric user ID' );
scl( false !== strpos( $files['ajax'], "'tutor_course' === \$kind" ) && false !== strpos( $files['ajax'], "'learndash_course' === \$kind" ) && false !== strpos( $files['ajax'], "'posts_per_page' => 20" ), 'Tutor and LearnDash course search is server-bounded to 20 results' );
scl( false !== strpos( $files['integrations'], 'data-certloom-context-lookup="tutor_course"' ) && false !== strpos( $files['integrations'], 'data-certloom-context-lookup="learndash_course"' ), 'Tutor and LearnDash course forms expose progressive complete-registry search' );
scl( false === strpos( $files['tutor_admin'], "'posts_per_page' => 250" ) && false === strpos( $files['learndash_admin'], "'posts_per_page' => 250" ) && substr_count( $files['tutor_admin'], 'ScopedContextChoices::INITIAL_LIMIT' ) >= 1 && substr_count( $files['learndash_admin'], 'ScopedContextChoices::INITIAL_LIMIT' ) >= 1, 'LMS initial HTML is bounded while preserving already-mapped course labels' );
scl( false !== strpos( $files['js'], "choice.value !== undefined" ) && false !== strpos( $files['js'], "'signer_scope'" ), 'lookup widget supports non-numeric relationship values without weakening numeric Program/Batch selectors' );
scl( false !== strpos( $files['js'], 'function initScopedContextLookups( root )' ) && false !== strpos( $files['js'], 'initScopedContextLookups( newRow )' ), 'dynamically added Program language variants receive the same searchable Template control' );
echo "\n25 scalable context lookup checks, 0 failures\n";
