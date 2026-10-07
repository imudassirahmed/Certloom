<?php
/** Phase 4.5 canvas interaction source contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ );
$admin = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$js = file_get_contents( $root . '/admin/assets/designer.js' );
$css = file_get_contents( $root . '/admin/assets/designer.css' );
$pass = 0; $fail = 0;
function p45( $ok, $label ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

p45( strpos( $admin, 'data-certloom-designer-safe-area' ) !== false, 'toolbar exposes safe-area control' );
p45( strpos( $admin, 'Show or hide the 10 mm safe area' ) !== false, 'safe-area purpose and 10 mm boundary are discoverable' );
p45( strpos( $admin, 'data-certloom-designer-pan-tool' ) !== false, 'toolbar exposes non-keyboard Pan control' );
p45( strpos( $admin, 'Pan canvas (Space + drag)' ) !== false, 'Pan control documents Space plus drag shortcut' );
p45( strpos( $admin, 'Shift+F10 opens element actions' ) !== false, 'Properties help documents keyboard context menu' );
p45( strpos( $js, "root.querySelector( '[data-certloom-designer-safe-area]' )" ) !== false, 'runtime binds safe-area control' );
p45( strpos( $js, "root.querySelector( '[data-certloom-designer-pan-tool]' )" ) !== false, 'runtime binds Pan control' );
p45( strpos( $js, 'var safeAreaVisible = false' ) !== false, 'safe area is transient editor state' );
p45( strpos( $js, 'var panToolActive = false' ) !== false, 'Pan mode is transient editor state' );
p45( strpos( $js, 'var marqueeGesture = null' ) !== false, 'marquee gesture is transient editor state' );
p45( strpos( $js, 'var activeSpacingGuides = []' ) !== false, 'equal-spacing guides are transient editor state' );
p45( strpos( $js, 'function renderSafeArea()' ) !== false, 'safe-area overlay has dedicated renderer' );
p45( strpos( $js, "data-certloom-designer-safe-area-overlay" ) !== false, 'safe-area overlay is explicitly marked as editor UI' );
p45( strpos( $js, 'safeMarginMm()' ) !== false, 'safe area reuses configured safe-margin contract' );
p45( strpos( $js, 'function renderSpacingGuides()' ) !== false, 'equal-spacing visual guide renderer exists' );
p45( strpos( $js, 'function equalSpacingCandidateForBounds' ) !== false, 'equal-spacing snap candidate is computed' );
p45( strpos( $js, 'orthogonalOverlaps' ) !== false || strpos( $js, 'overlap' ) !== false, 'equal-spacing candidate considers orthogonal overlap' );
p45( strpos( $js, 'setEqualSpacingGuides' ) !== false, 'equal-spacing candidate drives transient guide feedback' );
p45( strpos( $js, 'function startMarqueeSelection' ) !== false, 'marquee selection pointer path exists' );
p45( strpos( $js, 'function moveMarqueeSelection' ) !== false, 'marquee selection updates during pointer move' );
p45( strpos( $js, 'function endMarqueeSelection' ) !== false, 'marquee selection commits selection on pointer end' );
p45( strpos( $js, 'rotatedBounds' ) !== false && strpos( $js, 'boundsIntersect' ) !== false, 'marquee hit-testing uses rotated element bounds' );
p45( strpos( $js, 'isHiddenId( list[ i ].id )' ) !== false, 'marquee ignores elements hidden in editor' );
p45( strpos( $js, 'baseIds:' ) !== false && strpos( $js, 'gesture.additive' ) !== false, 'modifier marquee supports additive selection' );
p45( strpos( $js, 'function startCanvasPan' ) !== false, 'canvas Pan pointer path exists' );
p45( strpos( $js, 'spacePanHeld || event.button === 1' ) !== false, 'Space-drag and middle-button temporary Pan are supported' );
p45( strpos( $js, 'workspace.scrollLeft' ) !== false && strpos( $js, 'workspace.scrollTop' ) !== false, 'Pan changes only local workspace scroll position' );
p45( strpos( $js, "'Escape' === key && panToolActive" ) !== false, 'Escape exits explicit Pan mode' );
p45( strpos( $js, "'ContextMenu' === key" ) !== false && strpos( $js, "'F10' === key" ) !== false, 'keyboard context-menu invocation exists' );
p45( strpos( $js, "pageEl.addEventListener( 'contextmenu'" ) !== false, 'right-click context-menu invocation exists' );
p45( strpos( $js, "setAttribute( 'role', 'menu' )" ) !== false, 'context menu exposes ARIA menu role' );
p45( strpos( $js, "setAttribute( 'role', 'menuitem' )" ) !== false, 'context actions expose menuitem role' );
foreach ( array( 'copy', 'cut', 'duplicate', 'front', 'back', 'lock', 'delete' ) as $action ) {
    p45( strpos( $js, "contextMenuActionButton( '$action'" ) !== false, 'context menu exposes ' . $action . ' action' );
}
p45( strpos( $js, 'copySelectionToDesignerClipboard( false )' ) !== false, 'context Copy reuses canonical Designer clipboard' );
p45( strpos( $js, 'copySelectionToDesignerClipboard( true )' ) !== false, 'context Cut reuses canonical Designer clipboard' );
p45( strpos( $js, 'duplicateSelected()' ) !== false, 'context Duplicate reuses canonical duplicate path' );
p45( strpos( $js, 'reorderSelected( action )' ) !== false, 'context layer actions reuse canonical reorder path' );
p45( strpos( $js, 'toggleLock( selectedId )' ) !== false, 'context Lock reuses canonical authoring-state path' );
p45( strpos( $js, 'removeSelected()' ) !== false, 'context Delete reuses canonical remove path' );
p45( strpos( $js, "[ 'cut', 'delete' ].indexOf( action )" ) !== false, 'locked selection disables destructive context actions' );
p45( strpos( $js, "[ 'duplicate', 'front', 'forward', 'backward', 'back', 'lock', 'visibility' ].indexOf( action )" ) !== false, 'ambiguous multi-selection context actions fail closed' );
p45( strpos( $js, "'Escape' === key && contextMenu && ! contextMenu.hidden" ) !== false, 'Escape closes open context menu before form-control early return' );
p45( strpos( $js, 'first.focus( { preventScroll: true } )' ) !== false && strpos( $js, 'catch ( ignore ) { first.focus(); }' ) !== false, 'context menu focuses first action with browser-compatible fallback' );
p45( strpos( $js, "workspace.addEventListener( 'scroll', closeContextMenu )" ) !== false, 'context menu closes when sticky workspace scrolls' );
p45( strpos( $js, "window.addEventListener( 'resize', closeContextMenu )" ) !== false, 'context menu closes on viewport resize' );
p45( strpos( $js, 'safeAreaVisible = false; panToolActive = false' ) !== false, 'Reset clears transient safe-area and Pan modes' );
p45( strpos( $js, 'designerState.safe_area' ) === false && strpos( $js, 'designerState.pan' ) === false, 'safe-area and Pan modes do not enter authoring state' );
p45( strpos( $js, "safe_area:" ) === false && strpos( $js, "pan_tool:" ) === false, 'canvas tools do not create artifact layout fields' );
p45( strpos( $css, '.certloom-template-designer-safe-area' ) !== false, 'safe-area overlay has dedicated CSS' );
p45( strpos( $css, '.certloom-template-designer-marquee' ) !== false, 'marquee overlay has dedicated CSS' );
p45( strpos( $css, '.certloom-template-designer-spacing-guide' ) !== false, 'equal-spacing overlay has dedicated CSS' );
p45( strpos( $css, '.certloom-template-designer-context-menu' ) !== false, 'context menu has dedicated CSS' );
p45( strpos( $css, 'var(--cl-blue)' ) !== false && strpos( $css, 'var(--cl-surface)' ) !== false, 'canvas interaction UI uses Certloom token authority' );
p45( strpos( $css, '@media (forced-colors: active)' ) !== false, 'canvas interaction UI preserves forced-colors support' );
p45( strpos( $css, 'min-height: 44px' ) !== false, 'canvas interaction controls preserve mobile touch-target floor' );
p45( strpos( $css, '.certloom-template-designer-workspace' ) !== false && strpos( $css, 'position: sticky' ) !== false, 'sticky middle-workspace correction remains in CSS authority' );
p45( strpos( $admin, 'data-certloom-designer-add="table"' ) === false, 'canvas tranche does not fabricate Table insertion' );
p45( ! file_exists( $root . '/resources/renderer/accepted.json' ), 'canvas tranche does not manufacture renderer authority' );
p45( ! file_exists( $root . '/resources/domain/accepted.json' ), 'canvas tranche does not manufacture normalized-domain authority' );

echo "\n$pass Phase 4.5 canvas-interaction checks, $fail failures\n";
exit( $fail ? 1 : 0 );
