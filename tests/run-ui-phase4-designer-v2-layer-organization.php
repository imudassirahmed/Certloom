<?php
$root = dirname( __DIR__ );
$php  = file_get_contents( $root . '/src/Infrastructure/Admin/TemplateAdmin.php' );
$css  = file_get_contents( $root . '/admin/assets/designer.css' );
$js   = file_get_contents( $root . '/admin/assets/designer.js' );
$state_php = file_get_contents( $root . '/src/Application/Templates/TemplateDesignerState.php' );
$checks = 0;
function p42_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { fwrite( STDERR, "FAIL  {$message}\n" ); exit( 1 ); } }
function p42_has( $haystack, $needle, $message ) { p42_check( false !== strpos( $haystack, $needle ), $message ); }
function p42_not( $haystack, $needle, $message ) { p42_check( false === strpos( $haystack, $needle ), $message ); }

p42_has( $state_php, 'const VERSION = 2', 'Authoring-state contract is explicitly versioned for organization metadata.' );
p42_has( $state_php, "'hidden_ids'", 'Authoring state supports editor-only hidden layer ids.' );
p42_has( $state_php, "'layer_labels'", 'Authoring state supports editor-only layer labels.' );
p42_has( $state_php, 'MAX_LAYER_LABEL_LENGTH = 80', 'Layer labels have an explicit bounded length.' );
p42_has( $state_php, "isset( \$known[ \$id ] )", 'State normalizer retains only ids present in the canonical layout.' );
p42_has( $php, 'data-certloom-designer-copy', 'Toolbar exposes a non-keyboard Copy action.' );
p42_has( $php, 'data-certloom-designer-cut', 'Toolbar exposes a non-keyboard Cut action.' );
p42_has( $php, 'data-certloom-designer-paste', 'Toolbar exposes a non-keyboard Paste action.' );
p42_has( $php, 'Copy selection (Ctrl/Cmd+C)', 'Copy action documents its keyboard equivalent.' );
p42_has( $php, 'Cut selection (Ctrl/Cmd+X)', 'Cut action documents its keyboard equivalent.' );
p42_has( $php, 'Paste Designer clipboard (Ctrl/Cmd+V)', 'Paste action documents its keyboard equivalent.' );
p42_has( $js, "layerActionButton( element, 'visibility'", 'Every layer exposes editor visibility control.' );
p42_has( $js, "layerActionButton( element, 'rename'", 'Every layer exposes rename control.' );
p42_has( $js, 'data-certloom-designer-layer-rename-input', 'Rename uses an inline input instead of a browser prompt.' );
p42_not( $js, 'window.prompt', 'Designer layer rename never falls back to browser prompt UI.' );
p42_has( $js, 'function toggleEditorVisibility', 'Visibility changes share a dedicated authoring-state operation.' );
p42_has( $js, "if ( isHiddenId( element.id ) ) { continue; }", 'Hidden-in-editor layers are suppressed only from the editing canvas.' );
p42_has( $js, "Certificate output is unchanged.", 'Visibility copy states the non-artifact boundary truthfully.' );
p42_has( $js, 'function copySelectionToDesignerClipboard', 'Designer owns a deterministic internal copy/cut operation.' );
p42_has( $js, 'function pasteDesignerClipboard', 'Designer owns a deterministic internal paste operation.' );
p42_has( $js, "'c' === lower", 'Ctrl/Cmd+C is wired through Designer keyboard handling.' );
p42_has( $js, "'x' === lower", 'Ctrl/Cmd+X is wired through Designer keyboard handling.' );
p42_has( $js, "'v' === lower", 'Ctrl/Cmd+V is wired through Designer keyboard handling.' );
p42_has( $js, 'isFormControl( event.target )', 'Clipboard shortcuts preserve normal text/form-field behavior.' );
p42_has( $js, 'elements().length + designerClipboard.length > 500', 'Paste respects the canonical 500-element limit.' );
p42_has( $js, 'secureUuidV4()', 'Pasted elements receive new cryptographically generated ids.' );
p42_has( $js, 'designerState.hidden_ids = designerState.hidden_ids.filter', 'Deletion cleans editor visibility state.' );
p42_has( $js, 'delete designerState.layer_labels[ id ]', 'Deletion cleans stale layer-label state.' );
p42_has( $css, '.certloom-template-designer-layer-rename', 'Rename editor has dedicated compact styling.' );
p42_has( $css, '.is-hidden-in-editor', 'Hidden-in-editor layers have a visible organizational state.' );
p42_not( substr( $css, strpos( $css, 'Phase 4.2 authoring organization' ) ), '!important', 'New Phase 4.2 CSS adds no !important declarations.' );

echo "PASS  UI Phase 4 Designer v2 layer organization + clipboard source contract\n\n{$checks} checks, 0 failures\n";
