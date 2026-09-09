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

p4_has( $php, 'certloom-template-designer certloom-template-designer-v2', 'Designer v2 has an explicit shell class.' );
p4_has( $php, 'data-designer-default-mode="pro"', 'Designer v2 uses one unified precise editor mode.' );
p4_not( $php, 'data-certloom-designer-mode-button=', 'Customer-facing Quick/Pro mode switch is removed.' );
p4_not( $php, "esc_html__( 'Easy Designer', 'certloom' )", 'Designer v2 removes the legacy Easy Designer eyebrow.' );
p4_has( $php, "esc_attr__( 'Layers and insert elements', 'certloom' )", 'Left rail is explicitly Layers plus Insert.' );
p4_has( $php, 'certloom-template-designer-layers-primary', 'Primary Layers panel is rendered in the left rail.' );
p4_check( 1 === substr_count( $php, 'data-certloom-designer-layers></div>' ), 'Exactly one live Layers container exists.' );
p4_has( $php, "esc_html__( 'Insert', 'certloom' )", 'Insert vocabulary replaces the generic Add heading.' );
p4_has( $php, 'data-certloom-designer-insert-focus', 'Top toolbar exposes an Insert action.' );
p4_has( $php, 'data-certloom-designer-undo', 'Undo remains in the top toolbar.' );
p4_has( $php, 'data-certloom-designer-redo', 'Redo remains in the top toolbar.' );
p4_has( $php, 'data-certloom-designer-preview-scroll', 'Preview remains directly reachable from the top toolbar.' );
p4_has( $php, 'certloom-designer-v2-save', 'Save remains directly reachable from the top toolbar.' );
p4_has( $php, "esc_attr__( 'Designer properties', 'certloom' )", 'Right rail is explicitly the Properties inspector.' );
p4_has( $php, "esc_html__( 'Select an element to edit its content, appearance and exact geometry.', 'certloom' )", 'Properties empty state reflects the unified editor.' );

$marker = 'Certloom 1.7 — Designer v2 shell foundation.';
$offset = strpos( $css, $marker );
p4_check( false !== $offset, 'Designer v2 CSS foundation marker exists.' );
$v2css = substr( $css, $offset );
p4_has( $v2css, 'height: clamp(620px, calc(100vh - 170px), 860px);', 'Designer v2 establishes a viewport-bounded editor frame.' );
p4_has( $v2css, 'grid-template-columns: 220px minmax(0, 1fr);', 'Desktop stage keeps a dedicated left Layers/Insert rail.' );
p4_has( $v2css, 'grid-template-columns: minmax(0, 1fr) 290px;', 'Desktop shell keeps canvas centre and Properties right.' );
p4_has( $v2css, 'overflow: auto;', 'Designer v2 uses internal panel/canvas scrolling.' );
p4_has( $v2css, 'border-radius: var(--cl-r-none);', 'Designer v2 panel geometry uses approved tokens.' );
p4_has( $v2css, 'background: var(--cl-surface);', 'Designer v2 uses the tokenized surface authority.' );
p4_has( $v2css, 'background: var(--cl-paper);', 'Canvas workspace uses the tokenized paper authority.' );
p4_has( $v2css, '[data-certloom-designer-save] { display: none; }', 'Duplicate footer save button is removed from the v2 chrome.' );
p4_not( $v2css, '!important', 'New Designer v2 shell CSS introduces no !important declarations.' );

p4_has( $js, "var insertFocusButton = root.querySelector( '[data-certloom-designer-insert-focus]' );", 'Designer JS binds the top Insert action.' );
p4_has( $js, "firstInsert.focus();", 'Top Insert action restores keyboard focus to the Insert rail.' );
p4_has( $js, 'data-certloom-designer-resize', 'Direct resize engine remains present.' );
p4_has( $js, 'data-certloom-designer-rotate', 'Direct rotation engine remains present.' );
p4_has( $js, 'startDirectTextEdit', 'Direct text-edit engine remains present.' );
p4_has( $js, 'pushHistory', 'Undo/redo history engine remains present.' );
p4_has( $js, 'autosave', 'Recovery autosave authority remains present.' );
p4_has( $js, "event.shiftKey ? 5 : 1", 'Keyboard nudge sizing remains present.' );
p4_has( $php, 'data-layout-schema-version=', 'Canonical layout schema boundary remains present.' );
p4_has( $php, 'expected_revision_sha256', 'Immutable revision concurrency boundary remains present.' );

echo "PASS  UI Phase 4 Designer v2 shell foundation\n\n{$checks} checks, 0 failures\n";
