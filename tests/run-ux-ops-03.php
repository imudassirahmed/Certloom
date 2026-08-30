<?php
/** 1.6.98 UX-OPS-03 admin coherence and non-destructive workflow contract. */
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
$admin     = u3_src( $root, 'admin/class-ecapla-certverify-admin.php' );
$js        = u3_src( $root, 'admin/assets/admin.js' );
$css       = u3_src( $root, 'admin/assets/admin.css' );
$wizard    = u3_src( $root, 'admin/views/setup-wizard.php' );
foreach ( array( $nav,$domain,$structure,$fields,$template,$admin,$js,$css,$wizard ) as $source ) { u3( '' !== $source, 'Required UX-OPS-03 source is available' ); }

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
u3( false !== strpos( $fields, "const QUICK_FIELD_ACTION = 'certloom_quick_add_scoped_field'" ) && false !== strpos( $fields, 'Personal and Private' ), 'Quick field creation is server-authorized and starts privacy-conservative' );
u3( false !== strpos( $domain, 'Settings → Fields' ) && false !== strpos( $structure, 'Settings → Fields' ), 'Quick-create dialogs point to the canonical full Field Manager' );
u3( false !== strpos( $js, "body.set( 'action', 'certloom_quick_add_scoped_field' )" ) && false !== strpos( $js, 'data-certloom-scope-field-remove' ) && false !== strpos( $css, '.certloom-scoped-field-row[hidden]{display:none!important}' ), 'Scoped-field UI supports quick add/remove and CSS cannot override the hidden state' );

// 4. Brand/media controls are visual; complex machine fields use compact contextual help.
u3( false !== strpos( $domain, 'input type="color"' ) && false !== strpos( $domain, 'data-certloom-color-picker' ), 'Issuer brand colors use a real visual color picker' );
u3( false !== strpos( $domain, "'Logo', 'certloom'" ) && false !== strpos( $domain, 'data-certloom-media-select' ) && false === strpos( $domain, "'Logo media ID', 'certloom'" ), 'Issuer logo/seal use WordPress Media rather than numeric media IDs' );
u3( false !== strpos( $structure, "'Signature image', 'certloom'" ) && false === strpos( $structure, "'Signature media ID', 'certloom'" ), 'Signer signature uses WordPress Media rather than a numeric media ID' );
u3( false !== strpos( $admin, 'wp_enqueue_media()' ), 'WordPress Media Library is loaded only for entity screens that need it' );
u3( false !== strpos( $domain, 'certloom-help-tip' ) && false !== strpos( $structure, 'certloom-help-tip' ) && false !== strpos( $css, '.certloom-help-tip' ), 'Complex field guidance uses compact accessible help affordances instead of permanent helper paragraphs' );

// 5/6. Template Library is aligned and has one obvious publication route.
u3( false !== strpos( $template, 'certloom-template-library-filter-primary' ) && false !== strpos( $css, '.certloom-template-library-filter-primary' ), 'Template Library filter row has an explicit responsive grid contract' );
u3( false !== strpos( $template, "'Search designs', 'certloom'" ) && false !== strpos( $template, "'Program', 'certloom'" ) && false !== strpos( $template, 'certloom-library-filter-action' ), 'Template filters use concise aligned Search/Program/Purpose/Orientation/Style controls' );
u3( false !== strpos( $template, 'data-certloom-card-publish' ) && false !== strpos( $template, "'Published', 'certloom'" ) && false !== strpos( $template, "'Draft', 'certloom'" ), 'Template cards expose publication state and direct Publish action' );
u3( false !== strpos( $js, 'initTemplateCardPublishing' ) && false !== strpos( $js, 'data-certloom-card-feedback' ), 'Card publication surfaces exact success/failure feedback without redirecting away' );
u3( false === strpos( $template, 'Choose Program first' ) && false !== strpos( $template, "'Edit design', 'certloom'" ) && false !== strpos( $template, "'More', 'certloom'" ), 'Template cards keep primary actions small and move Duplicate into More' );
u3( false === strpos( $template, 'data-certloom-designer-publication-scroll' ) && false === strpos( $template, 'Publish reviewed revision' ), 'Designer no longer exposes competing publication buttons or technical review wording' );

// 7. Assignment language describes what the action does at the current hierarchy level.
u3( false === strpos( $template, 'Assign at this scope' ) && false === strpos( $template, 'Choose a direct Template for a scope' ) && false === strpos( $template, 'Source: %1$s · fallback depth %2$d' ) && false === strpos( $template, 'This visual discovery pass is intentionally bounded' ) && false !== strpos( $template, 'Design priority:' ) && false !== strpos( $template, 'Inherited from: %s' ) && false !== strpos( $template, 'Technical details' ), 'Template assignment hides implementation terminology while preserving collapsed technical integrity details' );
foreach ( array( 'Use for this Program','Use for this Batch','Use for this issuer','Use as global default' ) as $copy ) { u3( false !== strpos( $template, $copy ), 'Template assignment uses context-specific copy: ' . $copy ); }

// 8. Completed Wizard is actually complete and restarts explicitly.
u3( false !== strpos( $wizard, "'Setup complete', 'certloom'" ) && false !== strpos( $wizard, "'Run setup again', 'certloom'" ), 'Completed Setup opens a finished landing with an explicit restart action' );
u3( false !== strpos( $wizard, '<?php return; endif; ?>' ), 'Completed Setup returns before rendering the seven-step progress/editor again' );

// Entity pages are list-first and creation is an explicit top-right action.
foreach ( array( 'Add recipient','Add program','Add issuer' ) as $label ) { u3( false !== strpos( $domain, $label ), 'Domain list provides explicit top-right action: ' . $label ); }
foreach ( array( 'Add batch','Add signer' ) as $label ) { u3( false !== strpos( $structure, $label ), 'Structure list provides explicit top-right action: ' . $label ); }

// Authority discipline.
u3( ! file_exists( $root . '/resources/domain/accepted.json' ) && ! file_exists( $root . '/resources/renderer/accepted.json' ), 'UX-OPS-03 does not manufacture domain or renderer authority' );
u3( false !== strpos( u3_src( $root, 'certloom.php' ), "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ), 'Production normalized write readiness remains closed' );

if ( $fail ) { echo "\n{$pass} passed, {$fail} failures\n"; exit( 1 ); }
echo "PASS  UX-OPS-03: admin navigation, entity forms, scoped fields, Template publishing and completed Setup are coherent and non-destructive\n\n{$pass} checks, 0 failures\n";
