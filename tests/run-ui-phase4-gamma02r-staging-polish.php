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
g02rsp_check( false !== strpos( $js, "if ( nudgeSelected( event ) ) { event.preventDefault(); }" ), 'canvas arrow-key nudging prevents the browser from scrolling the outer page' );
g02rsp_check( false !== strpos( $js, "modifier && ! event.altKey && '0' === key" ) && false !== strpos( $js, 'fitWorkspace(); focusSelectedCanvasElement();' ), 'Ctrl/Cmd+0 returns to fitted 100% and restores Studio keyboard focus' );
g02rsp_check( false !== strpos( $js, 'renderStudioRulers' ) && false !== strpos( $js, 'data-certloom-designer-ruler' ), 'center workspace renders millimetre rulers as viewport presentation' );
g02rsp_check( false !== strpos( $admin, 'data-certloom-designer-property-section="advanced"' ) && false !== strpos( $admin, 'data-certloom-designer-advanced-id' ), 'technical element identity is moved behind Advanced rather than the normal Inspector header' );
g02rsp_check( false !== strpos( $admin, 'Template changed in another session' ) && false !== strpos( $admin, 'data-certloom-designer-conflict-banner' ), 'autosave concurrency conflict has a compact dedicated Studio banner' );
g02rsp_check( false !== strpos( $js, 'suppressCanvasClick = true' ) && false !== strpos( $js, 'if ( suppressCanvasClick ) { suppressCanvasClick = false; return; }' ), 'post-transform synthetic canvas clicks cannot clear the active selection' );
g02rsp_check( false !== strpos( $js, 'renderSelectionToolbar' ) && false !== strpos( $js, 'toolbarHeight' ), 'floating selected-element toolbar is positioned with transform-handle clearance' );
g02rsp_check( false !== strpos( $js, 'ensureSelectedLayerVisible' ), 'canvas selection keeps the corresponding Layers row visible' );
g02rsp_check( false !== strpos( $js, 'openPreferredInspectorSection' ) && false !== strpos( $js, "[ 'shape', 'line' ].indexOf( type ) !== -1 ? 'appearance'" ), 'Inspector default section is contextual and Shape prioritizes Appearance' );
g02rsp_check( false !== strpos( $admin, "__( 'Draft', 'certloom' )" ) && ( false !== strpos( $admin, "__( 'Publish', 'certloom' )" ) || false !== strpos( $admin, "__( 'Save', 'certloom' )" ) ), 'authoritative Draft plus Publish/Save actions remain visible in Studio' );
g02rsp_check( false === strpos( $js, 'localStorage' ) && false === strpos( $js, 'sessionStorage' ), 'staging polish creates no browser-local design authority' );
g02rsp_check( false === strpos( $admin, 'wp_remote_' ) && false === strpos( $js, 'fetch("http' ) && false === strpos( $js, "fetch('http" ), 'staging polish adds no remote content path' );

echo "\n" . ( $checks - $failures ) . " checks passed, {$failures} failures\n";
exit( $failures ? 1 : 0 );
