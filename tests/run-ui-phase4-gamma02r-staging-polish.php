<?php
/** Phase 4 Gamma-02R staging-feedback polish contract. */
$root  = dirname( __DIR__ );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$nav   = file_get_contents( $root . '/src/Infrastructure/Admin/AdminProductNavigation.php' );
$js    = file_get_contents( $root . '/admin/assets/designer.js' );
$css   = file_get_contents( $root . '/admin/assets/designer.css' );
$checks = 0;
$failures = 0;
function g02rsp_check( $condition, $label ) {
	global $checks, $failures;
	$checks++;
	if ( $condition ) { echo "PASS  {$label}\n"; return; }
	$failures++;
	echo "FAIL  {$label}\n";
}
foreach ( array( 'admin' => $admin, 'navigation' => $nav, 'runtime' => $js, 'styles' => $css ) as $name => $source ) {
	g02rsp_check( is_string( $source ) && '' !== $source, $name . ' source available' );
}
g02rsp_check( false !== strpos( $css, 'body.certloom-designer-workspace-page #wpfooter' ) && false !== strpos( $css, 'overflow: hidden !important;' ), 'desktop Studio owns the viewport instead of leaving trailing WordPress page scroll' );
g02rsp_check( false !== strpos( $css, '.certloom-product-subnav { display: none !important; }' ), 'focused Studio removes the redundant outer Design subnavigation' );
g02rsp_check( false !== strpos( $admin, 'data-certloom-designer-preview-mode="sample"' ) && false !== strpos( $admin, 'data-certloom-designer-preview-mode="tokens"' ), 'Studio exposes Sample and Tokens live-preview modes' );
g02rsp_check( false !== strpos( $js, "activePreviewMode = 'sample'" ) && false !== strpos( $js, 'syncPreviewModeButtons' ), 'Sample/Tokens is explicit presentation state with Sample as the default' );
g02rsp_check( false !== strpos( $js, 'Template data was not changed.' ) && false !== strpos( $js, 'setPreviewMode' ), 'preview-mode switching explicitly remains non-authoritative' );
g02rsp_check( false !== strpos( $js, "[ 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown' ].indexOf( key ) !== -1" ) && false !== strpos( $js, "event.preventDefault(); if ( nudgeSelected( event ) )" ) && false !== strpos( $js, "document.addEventListener( 'keydown', keyboard, true )" ), 'canvas editing keys are captured before WordPress/browser scrolling and arrow nudging prevents default movement' );
g02rsp_check( false !== strpos( $js, "modifier && ! event.altKey && '0' === key" ) && false !== strpos( $js, 'fitWorkspace(); focusSelectedCanvasElement();' ), 'Ctrl/Cmd+0 returns to fitted 100% and restores Studio keyboard focus' );
g02rsp_check( false !== strpos( $js, 'renderStudioRulers' ) && false !== strpos( $js, 'data-certloom-designer-ruler' ), 'center workspace renders millimetre rulers as viewport presentation' );
g02rsp_check( false !== strpos( $admin, 'data-certloom-designer-property-section="advanced"' ) && false !== strpos( $admin, 'data-certloom-designer-advanced-id' ), 'technical element identity is moved behind Advanced rather than the normal Inspector header' );
g02rsp_check( false !== strpos( $admin, 'Template changed in another session' ) && false !== strpos( $admin, 'data-certloom-designer-conflict-banner' ), 'autosave concurrency conflict has a compact dedicated Studio banner' );
g02rsp_check( false !== strpos( $js, 'suppressCanvasClick = true' ) && false !== strpos( $js, 'if ( suppressCanvasClick ) { suppressCanvasClick = false; return; }' ), 'post-transform synthetic canvas clicks cannot clear the active selection' );
g02rsp_check( false !== strpos( $js, 'renderSelectionToolbar' ) && false !== strpos( $js, 'toolbarHeight' ), 'floating selected-element toolbar is positioned with transform-handle clearance' );
g02rsp_check( false !== strpos( $js, 'ensureSelectedLayerVisible' ), 'canvas selection keeps the corresponding Layers row visible' );
g02rsp_check( false !== strpos( $js, 'openPreferredInspectorSection' ) && false !== strpos( $js, "[ 'shape', 'line' ].indexOf( type ) !== -1 ? 'appearance'" ), 'Inspector default section is contextual and Shape prioritizes Appearance' );
g02rsp_check( false !== strpos( $admin, "__( 'Draft', 'certloom' )" ) && ( false !== strpos( $admin, "__( 'Publish', 'certloom' )" ) || false !== strpos( $admin, "__( 'Save', 'certloom' )" ) ), 'authoritative Draft plus Publish/Save actions remain visible in Studio' );

g02rsp_check( false !== strpos( $js, "layersContainer.addEventListener( 'contextmenu'" ) && false !== strpos( $js, "openContextMenuAt( event.clientX, event.clientY, 'layer' )" ), 'right-clicking a Layer opens the contextual layer action menu' );
g02rsp_check( false !== strpos( $js, "contextMenuActionButton( 'visibility'" ) && false !== strpos( $js, "contextMenuActionButton( 'lock'" ) && false !== strpos( $js, "contextMenuActionButton( 'duplicate'" ) && false !== strpos( $js, "contextMenuActionButton( 'delete'" ), 'Layer context menu retains visibility lock duplicate and delete actions' );
g02rsp_check( false !== strpos( $css, '.is-designer-workspace .certloom-studio-left' ) && false !== strpos( $css, 'overflow: hidden !important;' ) && false !== strpos( $css, '.is-designer-workspace .certloom-studio-layer-list' ) && false !== strpos( $css, 'overflow-x: hidden !important;' ), 'left Studio rail has no outer or horizontal scrolling while Layers own vertical overflow' );
g02rsp_check( false !== strpos( $css, 'min-height: 27px !important;' ) && false !== strpos( $css, 'height: 27px !important;' ) && false !== strpos( $css, 'border-bottom: 1px solid #e8edf4' ), 'Layer rows use the compact separated single-line density contract' );
g02rsp_check( false !== strpos( $admin, 'certloom-studio-layer-order-hint' ) && false !== strpos( $admin, 'Drag layers up or down to change their position' ), 'Layers header explains Front to back drag ordering through a compact hover/focus hint' );
g02rsp_check( false !== strpos( $js, 'compactLayerDisplayName' ) && false !== strpos( $js, "+ '....'" ), 'Layer labels are character-bounded with trailing dots instead of horizontal scrolling' );
g02rsp_check( false !== strpos( $js, "row.appendChild( layerActionButton( element, 'visibility'" ) && false !== strpos( $js, "var lock = layerActionButton( element, 'lock'" ) && false !== strpos( $js, "actions.appendChild( lock )" ) && false !== strpos( $js, "actions.appendChild( layerActionButton( element, 'delete'" ) && false === strpos( $js, "actions.appendChild( layerActionButton( element, 'duplicate'" ), 'Layer row keeps eye on the left and lock/delete on the right without a duplicate icon' );
g02rsp_check( false !== strpos( $css, '.certloom-studio-select {' ) && false !== strpos( $css, 'align-items: center;' ) && false !== strpos( $css, 'line-height: 32px !important;' ), 'A4 and orientation selects are vertically centered in the document toolbar' );
g02rsp_check( false !== strpos( $js, "if ( open ) { closeInspectorSections(); body.hidden = false;" ) && false !== strpos( $js, 'function openInspectorSection( key ) {
			closeInspectorSections();' ), 'Inspector uses an exclusive single-open accordion contract' );
g02rsp_check( false === strpos( $js, 'localStorage' ) && false === strpos( $js, 'sessionStorage' ), 'staging polish creates no browser-local design authority' );
g02rsp_check( false === strpos( $admin, 'wp_remote_' ) && false === strpos( $js, 'fetch("http' ) && false === strpos( $js, "fetch('http" ), 'staging polish adds no remote content path' );

echo "\n" . ( $checks - $failures ) . " checks passed, {$failures} failures\n";
exit( $failures ? 1 : 0 );
