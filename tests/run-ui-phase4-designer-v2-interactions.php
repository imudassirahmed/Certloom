<?php
$root = dirname( __DIR__ );
$php  = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$css  = file_get_contents( $root . '/admin/assets/designer.css' );
$js   = file_get_contents( $root . '/admin/assets/designer.js' );
$checks = 0;
function p4i_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { fwrite( STDERR, "FAIL  {$message}\n" ); exit( 1 ); } }
function p4i_has( $haystack, $needle, $message ) { p4i_check( false !== strpos( $haystack, $needle ), $message ); }
function p4i_not( $haystack, $needle, $message ) { p4i_check( false === strpos( $haystack, $needle ), $message ); }

p4i_has( $php, 'data-certloom-designer-true-size', 'Unified toolbar exposes a non-keyboard 1:1 action.' );
p4i_has( $php, 'Actual size / 100% (1)', '1:1 action documents the keyboard equivalent.' );
p4i_has( $php, 'Undo (Ctrl/Cmd+Z)', 'Undo exposes its shortcut in the tooltip.' );
p4i_has( $php, 'Redo (Ctrl/Cmd+Shift+Z)', 'Redo exposes its shortcut in the tooltip.' );
p4i_has( $php, 'Save design (Ctrl/Cmd+S)', 'Save exposes its shortcut in the tooltip.' );
p4i_has( $php, 'Duplicate selected (Ctrl/Cmd+D)', 'Duplicate exposes its shortcut in the tooltip.' );
p4i_has( $php, 'Delete selected (Delete/Backspace)', 'Delete exposes its shortcut in the tooltip.' );
p4i_has( $php, '$layer_shortcuts', 'Layer toolbar keeps explicit shortcut metadata.' );

p4i_has( $js, 'data-certloom-designer-layer-row-action', 'Layer rows render direct action controls.' );
p4i_has( $js, "layerActionButton( element, 'front'", 'Layer rows can bring an element to front.' );
p4i_has( $js, "layerActionButton( element, 'forward'", 'Layer rows can move an element forward.' );
p4i_has( $js, "layerActionButton( element, 'backward'", 'Layer rows can move an element backward.' );
p4i_has( $js, "layerActionButton( element, 'back'", 'Layer rows can send an element to back.' );
p4i_has( $js, "layerActionButton( element, 'lock'", 'Layer rows can lock/unlock.' );
p4i_has( $js, "layerActionButton( element, 'duplicate'", 'Layer rows can duplicate.' );
p4i_has( $js, "layerActionButton( element, 'delete'", 'Layer rows can delete.' );
p4i_has( $js, "row.setAttribute( 'draggable', locked || renamingLayerId === element.id ? 'false' : 'true' )", 'Unlocked Layers are draggable while locked Layers fail closed.' );
p4i_has( $js, "layersContainer.addEventListener( 'dragstart'", 'Layers implement drag start.' );
p4i_has( $js, "layersContainer.addEventListener( 'drop'", 'Layers implement a drop reorder boundary.' );
p4i_has( $js, 'moveLayerInFrontOf', 'Layer drag resolves through the canonical z-order model.' );
p4i_has( $js, "if ( modifier && ! event.altKey && 'd' === lower )", 'Ctrl/Cmd+D duplicate shortcut exists.' );
p4i_has( $js, "( ']' === key || '[' === key )", 'Bracket layer-order shortcuts exist.' );
p4i_has( $js, "'front' : 'forward'", 'Shift bracket maps to bring-to-front rather than a second authority.' );
p4i_has( $js, "'back' : 'backward'", 'Shift bracket maps to send-to-back.' );
p4i_has( $js, "if ( modifier && ! event.altKey && 'a' === lower )", 'Ctrl/Cmd+A selects the canvas when focus is in Designer.' );
p4i_has( $js, "if ( 'Escape' === key && selectionIds().length )", 'Escape clears Designer selection.' );
p4i_has( $js, "'+' === key || '=' === key", 'Keyboard zoom-in is implemented.' );
p4i_has( $js, "'-' === key", 'Keyboard zoom-out is implemented.' );
p4i_has( $js, "'0' === key", 'Keyboard Fit action is implemented.' );
p4i_has( $js, "'1' === key", 'Keyboard 1:1 action is implemented.' );
p4i_has( $js, "var trueSizeButton = root.querySelector", 'JS binds the 1:1 toolbar action.' );
p4i_has( $js, "trueSizeButton.addEventListener( 'click', function () { trueSize( false ); } )", '1:1 toolbar and keyboard share one behavior.' );
p4i_has( $js, 'selectionHasLocked()', 'Existing locked-selection safeguards remain in the interaction engine.' );

$marker = 'Phase 4 interaction tranche — compact layer actions and drag target semantics.';
$offset = strpos( $css, $marker );
p4i_check( false !== $offset, 'Interaction CSS block is explicit.' );
$interaction_css = substr( $css, $offset );
p4i_has( $interaction_css, 'var(--cl-blue)', 'Layer selection uses the approved blue action token.' );
p4i_has( $interaction_css, 'var(--cl-focus)', 'Layer drag/focus uses the approved focus token.' );
p4i_has( $interaction_css, 'min-width: 44px; min-height: 44px;', 'Mobile Layer actions preserve 44px touch targets.' );
p4i_not( $interaction_css, '!important', 'New interaction CSS adds no !important declarations.' );

echo "PASS  UI Phase 4 Designer v2 interaction tranche\n\n{$checks} checks, 0 failures\n";
