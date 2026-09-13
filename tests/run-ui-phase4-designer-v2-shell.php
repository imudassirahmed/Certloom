<?php
$root = dirname( __DIR__ );
$php  = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$css  = file_get_contents( $root . '/admin/assets/designer.css' );
$js   = file_get_contents( $root . '/admin/assets/designer.js' );
$checks = 0;
function p4_check( $condition, $message ) {
	global $checks;
	$checks++;
	if ( ! $condition ) { fwrite( STDERR, "FAIL  {$message}\n" ); exit( 1 ); }
}
function p4_has( $haystack, $needle, $message ) { p4_check( false !== strpos( $haystack, $needle ), $message ); }
function p4_not( $haystack, $needle, $message ) { p4_check( false === strpos( $haystack, $needle ), $message ); }
function p4_slice( $haystack, $start, $end ) {
	$offset = strpos( $haystack, $start );
	if ( false === $offset ) { return ''; }
	$end_offset = strpos( $haystack, $end, $offset + strlen( $start ) );
	return false === $end_offset ? substr( $haystack, $offset ) : substr( $haystack, $offset, $end_offset - $offset );
}

p4_has( $php, 'certloom-template-designer certloom-template-designer-v2', 'Designer v2 has an explicit shell class.' );
p4_has( $php, 'data-designer-default-mode="pro"', 'Designer v2 keeps one unified precise editor mode.' );
p4_not( $php, 'data-certloom-designer-mode-button=', 'Customer-facing Quick/Pro mode switch stays removed.' );
p4_has( $php, "add_filter( 'admin_body_class', array( \$this, 'admin_body_class' ) )", 'Studio registers a bounded Designer page body class.' );
p4_has( $php, 'certloom-designer-workspace-page', 'Studio marks only the focused Designer page for bounded layout.' );
p4_has( $php, "array( 'library', 'templates', 'manage', 'editor' )", 'Design Center now has an explicit Manage design route separate from Designer.' );
p4_has( $php, 'certloom-template-admin is-designer-workspace', 'Actual editing renders in the dedicated focused workspace shell.' );
p4_has( $php, "esc_html__( 'Design Center', 'certloom' )", 'Designer exposes a direct return to Design Center.' );
p4_has( $php, "esc_html__( 'Manage design', 'certloom' )", 'Designer exposes lifecycle management without embedding it.' );
p4_has( $php, "esc_html__( 'Edit in Designer', 'certloom' )", 'Management surface can intentionally return to artwork editing.' );

$editor = p4_slice( $php, 'private function editor( $editing )', 'private function manage_design( $editing )' );
$manage = p4_slice( $php, 'private function manage_design( $editing )', 'private function schema_upgrade_panel' );
$panel  = p4_slice( $php, 'private function designer_panel( array $editing, $layout_json )', 'private function designer_catalog_field_options' );
p4_has( $editor, '$this->designer_panel( $editing, $layout_json );', 'Focused editor owns the actual Designer panel.' );
p4_not( $editor, 'Publish design', 'Focused editor does not mix publication controls into artwork editing.' );
p4_not( $editor, 'Canonical JSON editor', 'Focused editor does not expose technical JSON maintenance.' );
p4_not( $editor, '$this->preview_panel', 'Focused editor does not embed the Design Center preview surface.' );
p4_has( $manage, '$this->preview_panel( $editing );', 'Design management owns read-only preview.' );
p4_has( $manage, 'Publish design', 'Design management owns publication.' );
p4_has( $manage, 'Archive template', 'Design management owns archive lifecycle.' );
p4_has( $manage, 'Restore template', 'Design management owns restoration lifecycle.' );
p4_has( $manage, 'Canonical JSON editor', 'Design management owns technical JSON maintenance.' );
p4_has( $manage, 'Technical integrity', 'Design management owns technical integrity disclosure.' );
p4_not( $manage, '$this->designer_panel( $editing, $layout_json );', 'Design management no longer embeds the artwork editor.' );
p4_not( $panel, 'data-certloom-designer-preview-scroll', 'Focused Designer toolbar no longer links to a separate page preview below the editor.' );
p4_not( $panel, 'Technical integrity', 'Focused Designer no longer appends administrative technical disclosure.' );
p4_has( $panel, 'data-certloom-designer-undo', 'Undo remains in the editing toolbar.' );
p4_has( $panel, 'data-certloom-designer-redo', 'Redo remains in the editing toolbar.' );
p4_has( $panel, 'certloom-designer-v2-save', 'Save remains directly reachable in the editing toolbar.' );
p4_has( $panel, "esc_attr__( 'Layers and insert elements', 'certloom' )", 'Layers and Insert remain the left rail.' );
p4_has( $panel, "esc_attr__( 'Designer properties', 'certloom' )", 'Properties remains the right rail.' );
p4_has( $panel, 'expected_revision_sha256', 'Immutable revision concurrency boundary remains present.' );
p4_has( $panel, 'data-certloom-designer-recovery-json', 'Recovery autosave restore data remains present.' );

$marker = 'Phase 4 Gamma-02 Studio';
$offset = strpos( $css, $marker );
p4_check( false !== $offset, 'Studio focused-workspace CSS marker exists.' );
$studio = substr( $css, $offset );
p4_has( $studio, 'height: calc(100dvh - 50px);', 'Desktop Designer is bounded to the available viewport.' );
p4_has( $studio, 'overflow: hidden;', 'Desktop page shell prevents page-level editing scroll.' );
p4_has( $studio, 'flex: 1 1 auto;', 'Designer form and stage consume remaining workspace height.' );
p4_has( $studio, 'grid-template-columns: 220px minmax(420px, 1fr) 300px;', 'Desktop keeps Layers, canvas and Properties in three working columns.' );
p4_has( $studio, 'display: contents;', 'Legacy shell wrapper does not create a second grid boundary.' );
p4_has( $studio, 'position: relative;', 'Canvas is no longer a sticky page-flow pane in Studio.' );
p4_has( $studio, 'overflow: auto;', 'Layers, canvas and Properties can scroll internally.' );
p4_has( $studio, 'scrollbar-gutter: stable both-edges;', 'Canvas scrolling avoids horizontal layout jitter.' );
p4_has( $studio, 'body.certloom-designer-workspace-page #wpfooter { display: none; }', 'WordPress footer is removed from the focused editing viewport.' );
p4_not( $studio, '!important', 'Studio focused-workspace override introduces no !important declarations.' );

p4_has( $js, "var insertFocusButton = root.querySelector( '[data-certloom-designer-insert-focus]' );", 'Designer JS still binds Insert.' );
p4_has( $js, 'data-certloom-designer-resize', 'Direct resize engine remains present.' );
p4_has( $js, 'data-certloom-designer-rotate', 'Direct rotation engine remains present.' );
p4_has( $js, 'startDirectTextEdit', 'Direct text editing remains present.' );
p4_has( $js, 'pushHistory', 'Undo/redo history engine remains present.' );
p4_has( $js, 'autosave', 'Recovery autosave authority remains present.' );
p4_has( $php, 'data-layout-schema-version=', 'Canonical layout schema boundary remains present.' );

echo "PASS  UI Phase 4 Gamma-02 Studio focused Designer shell\n\n{$checks} checks, 0 failures\n";
