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
p4_has( $php, "esc_html__( 'Reset', 'certloom' )", 'Reset is compact in the primary toolbar.' );
p4_has( $php, "esc_html__( 'Guides', 'certloom' )", 'Guide vocabulary is compact.' );
p4_has( $php, "esc_html__( 'Snap while moving', 'certloom' )", 'Snap control uses clear human copy.' );

$boundary_pos = strpos( $php, 'certloom-template-designer-boundary' );
$form_end_pos = strpos( $php, "echo '</form>';" );
p4_check( false !== $boundary_pos && false !== $form_end_pos && $boundary_pos > $form_end_pos, 'Technical integrity details render after the working form instead of interrupting the editor.' );

$marker = 'Phase 4.4.1 UX correction';
$offset = strpos( $css, $marker );
p4_check( false !== $offset, 'Sticky-workspace UX correction marker exists.' );
$uxcss = substr( $css, $offset );
p4_has( $uxcss, 'height: auto;', 'Designer v2 returns to page flow instead of a fixed-height frame.' );
p4_has( $uxcss, 'grid-template-columns: 220px minmax(520px, 1fr) 300px;', 'Desktop stage keeps Layers, sticky canvas and Properties as three working columns.' );
p4_has( $uxcss, 'display: contents;', 'Legacy shell wrapper no longer creates a second grid boundary around canvas and Properties.' );
p4_has( $uxcss, 'position: sticky;', 'Canvas workspace is the sticky desktop pane.' );
p4_has( $uxcss, 'top: 44px;', 'Sticky canvas clears normal WordPress admin chrome.' );
p4_has( $uxcss, 'height: calc(100vh - 64px);', 'Sticky canvas uses viewport-aware height.' );
p4_has( $uxcss, 'overflow: auto;', 'Sticky canvas retains internal scrolling.' );
p4_has( $uxcss, 'scrollbar-gutter: stable both-edges;', 'Canvas scrolling does not cause horizontal layout jitter.' );
p4_has( $uxcss, 'position: static;', 'Properties is intentionally not sticky.' );
p4_has( $uxcss, 'overflow: visible;', 'Properties follows normal page scrolling instead of using a competing internal scroller.' );
p4_has( $uxcss, 'height: 52px;', 'Context dock is compact and cannot consume the canvas viewport.' );
p4_not( $uxcss, '!important', 'Sticky-workspace correction introduces no !important declarations.' );

p4_has( $js, "var insertFocusButton = root.querySelector( '[data-certloom-designer-insert-focus]' );", 'Designer JS binds the top Insert action.' );
p4_has( $js, 'data-certloom-designer-resize', 'Direct resize engine remains present.' );
p4_has( $js, 'data-certloom-designer-rotate', 'Direct rotation engine remains present.' );
p4_has( $js, 'startDirectTextEdit', 'Direct text-edit engine remains present.' );
p4_has( $js, 'pushHistory', 'Undo/redo history engine remains present.' );
p4_has( $js, 'autosave', 'Recovery autosave authority remains present.' );
p4_has( $php, 'data-layout-schema-version=', 'Canonical layout schema boundary remains present.' );
p4_has( $php, 'expected_revision_sha256', 'Immutable revision concurrency boundary remains present.' );

echo "PASS  UI Phase 4 Designer v2 sticky-workspace shell\n\n{$checks} checks, 0 failures\n";
