<?php
/** Build a self-contained UI Phase 4 Designer v2 shell fixture over the permanent canonical Designer fixture. */
ob_start();
require __DIR__ . '/build_template_designer_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Base Designer fixture did not render.' ); }
$tokens = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/certloom-tokens.css' );
if ( ! is_string( $tokens ) ) { throw new RuntimeException( 'Certloom tokens are unavailable.' ); }
$html = str_replace( '<style>', '<style>' . $tokens, $html );
$html = str_replace(
    '<section class="certloom-template-designer" data-certloom-designer ',
    '<section class="certloom-template-designer certloom-template-designer-v2" data-certloom-designer data-designer-default-mode="pro" data-designer-safe-margin-mm="10" ',
    $html
);
$html = str_replace(
    '<div class="certloom-template-designer-head"><div><span class="certloom-template-designer-kicker">DSN-08</span><h3>Certificate Designer</h3><p>Fixture</p></div>',
    '<div class="certloom-template-designer-head"><div><h3>Certificate Designer</h3><p>Edit the certificate directly on the canvas. Layers stay on the left; properties update on the right.</p></div>',
    $html
);
$html = str_replace(
    '<div class="certloom-template-designer-toolbar">',
    '<div class="certloom-template-designer-toolbar" role="toolbar" aria-label="Certificate Designer tools"><button type="button" class="button certloom-designer-v2-insert" data-certloom-designer-insert-focus>+ Insert</button>',
    $html
);
$html = str_replace('<label><span>Page size</span><select data-certloom-designer-page-size>', '<label class="certloom-designer-pro-only"><span>Page size</span><select data-certloom-designer-page-size>', $html);
$html = str_replace('<label><span>Orientation</span><select data-certloom-designer-orientation>', '<label class="certloom-designer-pro-only"><span>Orientation</span><select data-certloom-designer-orientation>', $html);
$html = str_replace(
    '<button type="button" class="button" data-certloom-designer-fit>Fit workspace</button><button type="button" class="button" data-certloom-designer-reset>Reset unsaved changes</button>',
    '<button type="button" class="button" data-certloom-designer-fit title="Fit canvas (0)">Fit</button><button type="button" class="button" data-certloom-designer-true-size title="Actual size / 100% (1)">1:1</button><button type="button" class="button" data-certloom-designer-preview-scroll>Preview</button><button type="submit" class="button button-primary certloom-designer-v2-save" data-certloom-designer-quick-save title="Save design (Ctrl/Cmd+S)">Save design</button><button type="button" class="button certloom-designer-pro-only" data-certloom-designer-reset>Reset unsaved changes</button>',
    $html
);
$html = str_replace('<div class="certloom-template-designer-productivity">', '<div class="certloom-template-designer-productivity certloom-designer-pro-only" data-certloom-designer-productivity>', $html);
$html = str_replace('<input type="number" data-certloom-designer-style-field="line_height">', '<input type="number" min="0.5" max="5" step="0.05" data-certloom-designer-style-field="line_height">', $html);
$html = str_replace(
    '<div class="certloom-template-designer-palette"><span class="certloom-template-designer-productivity-label">Add</span>',
    '<div class="certloom-template-designer-stage"><aside class="certloom-template-designer-add-panel" aria-label="Layers and insert elements"><div class="certloom-template-designer-layers certloom-template-designer-layers-panel certloom-template-designer-layers-primary"><div class="certloom-template-designer-layers-head"><h4>Layers</h4><span>Front → back</span></div><div data-certloom-designer-layers></div></div><div class="certloom-template-designer-add-head"><strong>Insert</strong><small>Click or drag onto canvas</small></div><div class="certloom-template-designer-palette" role="toolbar" aria-label="Insert or remove Designer elements"><span class="certloom-template-designer-productivity-label">Insert</span>',
    $html
);
$html = str_replace(
    '<button type="button" class="button" data-certloom-designer-remove disabled>Remove selected</button></div>',
    '<button type="button" class="button" data-certloom-designer-duplicate disabled title="Duplicate selected (Ctrl/Cmd+D)">Duplicate selected</button><button type="button" class="button" data-certloom-designer-remove disabled title="Delete selected (Delete/Backspace)">Delete selected</button></div>',
    $html
);
$html = str_replace('<div class="certloom-template-designer-shell">', '</aside><div class="certloom-template-designer-shell">', $html);
$html = str_replace('<aside class="certloom-template-designer-inspector">', '<aside class="certloom-template-designer-inspector" aria-label="Designer properties">', $html);
$html = str_replace('<div data-certloom-designer-empty><h4>Element geometry</h4></div>', '<div data-certloom-designer-empty><h4>Properties</h4><p>Select an element to edit its content, appearance and exact geometry.</p></div>', $html);
$html = str_replace('<div class="certloom-template-designer-layers"><div class="certloom-template-designer-layers-head"><h4>Layers</h4><span>Front → back</span></div><div data-certloom-designer-layers></div></div>', '', $html);
$html = str_replace("</aside></div>\n<div class=\"certloom-template-designer-footer\">", "</aside></div></div>\n<div class=\"certloom-template-designer-footer\">", $html);
$html = str_replace(
    '<pre id="certloom-browser-result"></pre>',
    '<section class="certloom-template-preview-card" style="position:fixed;left:-9999px;top:0;width:1px;height:1px;overflow:hidden" tabindex="-1"><h3>Preview target</h3></section><pre id="certloom-browser-result"></pre>',
    $html
);
if ( false === strpos( $html, 'certloom-template-designer-v2' ) || 1 !== substr_count( $html, 'data-certloom-designer-layers></div>' ) || false === strpos( $html, 'data-certloom-designer-insert-focus' ) ) {
    throw new RuntimeException( 'Designer v2 fixture transformation is incomplete.' );
}
echo $html;
