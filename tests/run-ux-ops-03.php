<?php
/** 1.6.98 UX-OPS-03 admin coherence and non-destructive workflow contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function u3_src( $root, $path ) { $v = @file_get_contents( $root . '/' . $path ); return is_string( $v ) ? $v : ''; }
function u3( $ok, $message ) { global $pass,$fail; if ( $ok ) { echo "PASS  {$message}\n"; $pass++; } else { echo "FAIL  {$message}\n"; $fail++; } }

$nav       = u3_src( $root, 'src/Infrastructure/Admin/AdminProductNavigation.php' );
$domain    = u3_src( $root, 'src/Infrastructure/Admin/DomainAdmin.php' );
$structure = u3_src( $root, 'src/Infrastructure/Admin/DomainStructureAdmin.php' );
$fields    = u3_src( $root, 'src/Infrastructure/Admin/ScopedFieldAdminAjax.php' );
$template  = u3_src( $root, 'src/Infrastructure/Admin/TemplateAdmin.php' );
$rules     = u3_src( $root, 'src/Infrastructure/Admin/DesignRulesAdmin.php' );
$admin     = u3_src( $root, 'admin/class-ecapla-certverify-admin.php' );
$js        = u3_src( $root, 'admin/assets/admin.js' );
$css       = u3_src( $root, 'admin/assets/admin.css' );
$wizard    = u3_src( $root, 'admin/views/setup-wizard.php' );
$manual    = u3_src( $root, 'src/Infrastructure/Admin/ManualIssuanceAdmin.php' );
$issue_js  = u3_src( $root, 'admin/assets/issue-credential.js' );
$bulk      = u3_src( $root, 'src/Infrastructure/Admin/BulkIssuanceAdmin.php' );
$email     = u3_src( $root, 'src/Infrastructure/Admin/EmailDeliveryAdmin.php' );
foreach ( array( $nav,$domain,$structure,$fields,$template,$rules,$admin,$js,$css,$wizard,$manual,$bulk,$email ) as $source ) { u3( '' !== $source, 'Required UX-OPS-03 source is available' ); }

// 1. Contextual pages keep the WordPress Certloom parent visibly active, even if another filter runs late.
u3( false !== strpos( $nav, "add_filter( 'parent_file', array( \$this, 'filter_parent_file' ), 9999 )" ) && false !== strpos( $nav, "add_filter( 'submenu_file', array( \$this, 'filter_submenu_file' ), 9999 )" ), 'WordPress parent/submenu mapping runs at late priority' );
u3( false !== strpos( $js, "top.classList.add( 'wp-has-current-submenu', 'wp-menu-open' )" ) && false !== strpos( $js, "link.setAttribute( 'aria-current', 'page' )" ), 'Admin JS has a visual parent-state fallback for contextual Certloom routes' );

// 2. Entity validation errors return to the editor and browser-session drafts are restored.
foreach ( array( 'RECIPIENTS_PAGE','PROGRAMS_PAGE','ISSUERS_PAGE' ) as $page ) { u3( false !== strpos( $domain, "'certloom_retry' => 1" ), 'Domain entity failures preserve editor retry state' ); break; }
u3( substr_count( $domain, 'data-certloom-preserve-on-error' ) >= 3 && substr_count( $structure, 'data-certloom-preserve-on-error' ) >= 2, 'Create/edit forms opt into non-destructive retry preservation' );
u3( false !== strpos( $js, 'certloomDomainDraft:' ) && false !== strpos( $js, "'1' === params.get( 'certloom_retry' )" ) && false !== strpos( $js, 'storage.setItem' ) && false !== strpos( $js, "kinds[ name ] = 'checkable'" ), 'Browser restores complete submitted entity state, including unchecked controls, only on a marked validation retry' );
u3( false !== strpos( $domain, "'certloom_new' => 1, 'certloom_retry' => 1" ) && false !== strpos( $structure, "'certloom_new' => 1, 'certloom_retry' => 1" ), 'Create failures return to the create editor rather than the list' );

// 3. Program/Batch scoped fields show selected changes, support quick creation, and keep full policy in Settings → Fields.
u3( false !== strpos( $domain, 'program_field_editor' ) && false !== strpos( $domain, "'Create new field', 'certloom'" ) && false !== strpos( $domain, "'Manage all fields', 'certloom'" ), 'Program field contract uses compact add/remove field editor' );
u3( false !== strpos( $structure, 'batch_field_editor' ) && false !== strpos( $structure, "'Credential field changes', 'certloom'" ), 'Batch field contract shows only Batch changes/additions' );
u3( false !== strpos( $fields, "const QUICK_FIELD_ACTION = 'certloom_quick_add_scoped_field'" ) && false !== strpos( $fields, '$sensitivity = isset( $_POST' ) && false !== strpos( $fields, '$public_exposure = isset( $_POST' ) && false !== strpos( $fields, "'internal' === \$sensitivity" ), 'Quick field creation is server-authorized, privacy-configurable, and forces Internal fields private' );
u3( false !== strpos( $domain, 'Settings → Fields' ) && false !== strpos( $structure, 'Settings → Fields' ), 'Quick-create dialogs point to the canonical full Field Manager' );
u3( false !== strpos( $js, "body.set( 'action', 'certloom_quick_add_scoped_field' )" ) && false !== strpos( $js, 'data-certloom-scope-field-remove' ) && 1 === preg_match( '/\.certloom-scoped-field-row\[hidden\]\s*\{[^}]*display\s*:\s*none/s', $css ), 'Scoped-field UI supports quick add/remove and owns its hidden state without specificity debt' );

// 4. Brand/media controls are visual; complex machine fields use compact contextual help.
u3( false !== strpos( $domain, 'input type="color"' ) && false !== strpos( $domain, 'data-certloom-color-picker' ), 'Issuer brand colors use a real visual color picker' );
u3( false !== strpos( $domain, "'Logo', 'certloom'" ) && false !== strpos( $domain, 'data-certloom-media-select' ) && false === strpos( $domain, "'Logo media ID', 'certloom'" ), 'Issuer logo/seal use WordPress Media rather than numeric media IDs' );
u3( false !== strpos( $structure, "'Signature image', 'certloom'" ) && false === strpos( $structure, "'Signature media ID', 'certloom'" ), 'Signer signature uses WordPress Media rather than a numeric media ID' );
u3( false !== strpos( $admin, 'wp_enqueue_media()' ), 'WordPress Media Library is loaded only for entity screens that need it' );
u3( false !== strpos( $domain, 'certloom-help-tip' ) && false !== strpos( $structure, 'certloom-help-tip' ) && false !== strpos( $css, '.certloom-help-tip' ), 'Complex field guidance uses compact accessible help affordances instead of permanent helper paragraphs' );

// 5/6. Template Library is aligned and has one obvious publication route.
u3( false !== strpos( $template, 'certloom-template-library-filter-primary' ) && false !== strpos( u3_src( $root, 'admin/assets/template-library.css' ), '.certloom-template-library-filter-primary' ), 'Certificate Library filter row has an explicit responsive grid contract' );
u3( false !== strpos( $template, "'Search', 'certloom'" ) && false !== strpos( $template, "'Purpose', 'certloom'" ) && false !== strpos( $template, "'Orientation', 'certloom'" ) && false !== strpos( $template, "'Style', 'certloom'" ) && false === strpos( $template, 'library_program_id' ), 'Certificate Library filters designs without reintroducing Program assignment context' );
u3( false !== strpos( $template, 'role="switch"' ) && false !== strpos( $template, 'data-certloom-card-publish' ) && false !== strpos( $template, 'data-certloom-card-unpublish' ) && false !== strpos( $template, "'Published', 'certloom'" ) && false !== strpos( $template, "'Draft', 'certloom'" ), 'Customer certificate cards retain an accessible Draft/Published lifecycle control' );
u3( false !== strpos( $template, 'certloom-template-card-more' ) && false !== strpos( $template, "'Export template', 'certloom'" ) && false !== strpos( $template, "'Used by %d rule'" ), 'Certificate cards keep secondary Export and usage actions in a compact overflow menu' );
u3( false !== strpos( $js, 'initTemplateCardPublishing' ) && false !== strpos( $js, 'data-certloom-card-feedback' ), 'Card publication surfaces exact success/failure feedback without redirecting away' );
u3( false !== strpos( $template, "'Preview', 'certloom'" ) && false !== strpos( $template, "'Customize', 'certloom'" ) && false === strpos( $template, 'Choose Program first' ), 'Built-in Certificate Library cards use Preview/Customize without assignment prerequisites' );
u3( false === strpos( $template, 'data-certloom-designer-publication-scroll' ) && false === strpos( $template, 'Publish reviewed revision' ), 'Designer no longer exposes competing publication buttons or technical review wording' );

// 7. Assignment authority is centralized in Design Rules rather than duplicated in Design.
u3( false === strpos( $template, 'Assign at this scope' ) && false === strpos( $template, 'Use for this Program' ) && false === strpos( $template, 'library_program_id' ) && false !== strpos( $rules, "'Design Rules', 'certloom'" ), 'Certificate Design is assignment-free and hands hierarchy authority to Design Rules' );
u3( false !== strpos( $rules, "'No change', 'certloom'" ) && false !== strpos( $rules, "'Inherit from parent', 'certloom'" ) && false !== strpos( $rules, "'Certificate design', 'certloom'" ) && false !== strpos( $rules, "'Email design', 'certloom'" ), 'Design Rules exposes explicit independent bulk semantics for certificate and email design authority' );

// 7B. Integrity evidence stays available without dominating ordinary issuance/delivery/preview workflows.
u3( false !== strpos( $manual, 'data-review-technical' ) && false !== strpos( $manual, "__( 'Technical details', 'certloom' )" ) && false !== strpos( $issue_js, 'Review SHA-256' ) && false !== strpos( $issue_js, 'Template revision' ), 'Manual Issuance collapses review/layout/render integrity identifiers under Technical details' );
u3( false !== strpos( $bulk, 'certloom-technical-details' ) && false !== strpos( $bulk, 'Reviewed batch fingerprint' ) && false !== strpos( $bulk, "'certloom'" ), 'Bulk Issuance keeps batch/plan integrity identifiers in collapsed Technical details' );
u3( false !== strpos( $email, 'certloom-email-review-meta' ) && false !== strpos( $email, 'certloom-technical-details' ) && false !== strpos( $email, "'Composition SHA-256', 'certloom'" ), 'Email Delivery keeps destination/kind visible while collapsing composition integrity proof' );
u3( false !== strpos( $template, 'certloom-template-preview-technical' ) && false !== strpos( $template, "'Layout SHA-256', 'certloom'" ) && false !== strpos( $template, "'Render Plan SHA-256', 'certloom'" ), 'Template Preview moves revision/hash evidence into collapsed Technical details' );
u3( false !== strpos( $css, '.certloom-technical-details' ) && false !== strpos( $css, '.certloom-technical-details>summary:focus-visible' ), 'Technical-details disclosures have an explicit keyboard-visible presentation contract' );

// 8. Completed Setup v2 defaults to the Finish landing and restarts explicitly without replaying stale required steps.
u3( false !== strpos( $wizard, "'Setup complete', 'certloom'" ) && false !== strpos( $wizard, "'Run setup again', 'certloom'" ) && false !== strpos( $wizard, 'name="certloom_setup_wizard_action" value="restart"' ), 'Completed Setup v2 opens a finished landing with an explicit restart action' );
u3( false !== strpos( $admin, "! empty( \$wizard_state['wizard_completed_at'] )" ) && false !== strpos( $admin, "\$wizard_step = 'finish';" ), 'Completed Setup v2 defaults back to Finish unless the administrator explicitly navigates/restarts' );

// Entity pages are list-first and creation is an explicit top-right action.
foreach ( array( 'Add recipient','Add program','Add issuer' ) as $label ) { u3( false !== strpos( $domain, $label ), 'Domain list provides explicit top-right action: ' . $label ); }
foreach ( array( 'Add batch','Add signer' ) as $label ) { u3( false !== strpos( $structure, $label ), 'Structure list provides explicit top-right action: ' . $label ); }

// Authority discipline.
u3( ! file_exists( $root . '/resources/domain/accepted.json' ) && ! file_exists( $root . '/resources/renderer/accepted.json' ), 'UX-OPS-03 does not manufacture domain or renderer authority' );
u3( false !== strpos( u3_src( $root, 'certloom.php' ), "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ), 'Production normalized write readiness remains closed' );

if ( $fail ) { echo "\n{$pass} passed, {$fail} failures\n"; exit( 1 ); }
echo "PASS  UX-OPS-03: admin navigation, entity forms, scoped fields, Template publishing and completed Setup are coherent and non-destructive\n\n{$pass} checks, 0 failures\n";
