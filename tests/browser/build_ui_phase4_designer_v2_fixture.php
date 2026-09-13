<?php
/** Build a self-contained Gamma-02R Certloom Studio fixture over the canonical Designer runtime. */
ob_start();
require __DIR__ . '/build_template_designer_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Base Designer fixture did not render.' ); }
$tokens = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/certloom-tokens.css' );
if ( ! is_string( $tokens ) ) { throw new RuntimeException( 'Certloom tokens are unavailable.' ); }
$html = str_replace( '<style>', '<style>' . $tokens, $html );

function studio_replace_between( $html, $start, $end, $replacement ) {
    $a = strpos( $html, $start );
    if ( false === $a ) { throw new RuntimeException( 'Studio fixture start marker missing: ' . $start ); }
    $b = strpos( $html, $end, $a );
    if ( false === $b ) { throw new RuntimeException( 'Studio fixture end marker missing: ' . $end ); }
    return substr( $html, 0, $a ) . $replacement . substr( $html, $b );
}

$html = str_replace(
    '<body>',
    '<body class="certloom-designer-workspace-page" style="margin:0;padding:0"><div class="certloom-template-admin is-designer-workspace"><div class="certloom-template-editor-focused">',
    $html
);
$html = str_replace(
    '<section class="certloom-template-designer" data-certloom-designer ',
    '<section class="certloom-template-designer certloom-template-designer-v2 is-pro-mode" data-certloom-designer data-designer-default-mode="pro" data-designer-mode="pro" data-designer-safe-margin-mm="10" ',
    $html
);
$html = str_replace(
    'data-template-id="77"',
    'data-template-id="77" data-template-updated-at="2026-09-13 10:00:00" data-studio-metadata-nonce="fixture-studio-metadata-nonce"',
    $html
);

$header = <<<'HTML'
<header class="certloom-studio-header">
  <div class="certloom-studio-brand"><span aria-hidden="true">✓</span><span>Certloom Studio</span></div>
  <a class="certloom-studio-back" href="#templates"><span aria-hidden="true">←</span> Templates</a>
  <span class="certloom-studio-divider" aria-hidden="true"></span>
  <div class="certloom-studio-document">
    <button type="button" class="certloom-studio-title-button" data-certloom-studio-name-display aria-label="Rename certificate design" title="Click to rename"><span data-certloom-studio-name-text>Academic — A4 Landscape</span></button>
    <input type="text" class="certloom-studio-title-input" maxlength="255" value="Academic — A4 Landscape" data-certloom-studio-name-input hidden aria-label="Certificate design name">
    <span class="certloom-template-state">Draft</span>
    <details class="certloom-studio-profile-picker" data-certloom-studio-profile-picker>
      <summary title="Choose the usage profiles this design is recommended for"><span aria-hidden="true">◫</span><span data-certloom-studio-profile-summary>Profiles</span></summary>
      <div class="certloom-studio-profile-popover"><strong>Recommended profiles</strong><p>These tags guide Templates and Setup recommendations. They never prevent another profile from using this design.</p><div class="certloom-studio-profile-options">
        <label><input type="checkbox" value="training_lms" data-certloom-studio-profile><span>Training / LMS</span></label>
        <label><input type="checkbox" value="iso_management" data-certloom-studio-profile><span>ISO / Management Systems</span></label>
        <label><input type="checkbox" value="government_licensing" data-certloom-studio-profile><span>Government / Licensing</span></label>
        <label><input type="checkbox" value="school_results" data-certloom-studio-profile><span>School / Results</span></label>
        <label><input type="checkbox" value="corporate_compliance" data-certloom-studio-profile><span>Corporate / Compliance</span></label>
        <label><input type="checkbox" value="membership_association" data-certloom-studio-profile><span>Membership / Association</span></label>
        <label><input type="checkbox" value="events_awards" data-certloom-studio-profile><span>Events / Awards</span></label>
        <label><input type="checkbox" value="product_inspection" data-certloom-studio-profile><span>Product / Inspection</span></label>
        <label><input type="checkbox" value="general_other" data-certloom-studio-profile><span>General / Other</span></label>
      </div><small data-certloom-studio-profile-status aria-live="polite"></small></div>
    </details>
  </div>
  <div class="certloom-studio-save-state"><span class="certloom-studio-save-dot" aria-hidden="true"></span><span data-certloom-designer-dirty>Saved draft</span><small data-certloom-designer-autosave-status>Recovery ready</small></div>
  <div class="certloom-studio-actions"><button type="submit" form="certloom-studio-fixture-form" class="button certloom-studio-draft" data-certloom-designer-save>Draft</button><button type="submit" form="certloom-studio-fixture-form" class="button button-primary certloom-studio-primary" data-certloom-designer-primary-save data-certloom-designer-quick-save>Publish</button></div>
</header>
<div class="certloom-studio-toast" role="status" data-certloom-studio-toast hidden><span></span></div>
<div class="certloom-studio-conflict" data-certloom-designer-conflict-banner hidden role="alert"><div><strong>Template changed in another session</strong><span>Your current browser edits are still here. Recovery autosave has paused so it cannot overwrite the newer saved revision.</span></div><div class="certloom-studio-conflict-actions"><button type="button" class="button button-small" data-certloom-designer-conflict-review>Keep reviewing</button><button type="button" class="button button-small" data-certloom-designer-conflict-reload>Reload saved version</button></div></div>
HTML;
$html = studio_replace_between( $html, '<div class="certloom-template-designer-head">', '<form data-certloom-designer-form>', $header );
$html = str_replace( '<form data-certloom-designer-form>', '<form id="certloom-studio-fixture-form" data-certloom-designer-form novalidate>', $html );
$html = str_replace( '</body>', '<script>document.addEventListener("submit",function(e){if(e.target&&e.target.id==="certloom-studio-fixture-form"){e.preventDefault();document.body.setAttribute("data-submitted","1");}});</script></body>', $html );

$docbar = <<<'HTML'
<div class="certloom-studio-documentbar" role="toolbar" aria-label="Studio document and canvas controls">
  <label class="certloom-studio-select"><span class="screen-reader-text">Page size</span><select data-certloom-designer-page-size aria-label="Page size"><option value="A4">A4</option><option value="Letter">Letter</option><option value="Custom">Custom</option></select></label>
  <label class="certloom-studio-select"><span class="screen-reader-text">Orientation</span><select data-certloom-designer-orientation aria-label="Orientation"><option value="landscape">Landscape</option><option value="portrait">Portrait</option></select></label>
  <div class="certloom-studio-custom-size" data-certloom-designer-custom-size hidden><label><span>W</span><input type="number" min="25" max="1200" step="0.1" data-certloom-designer-custom-width aria-label="Custom page width in millimetres"></label><span>×</span><label><span>H</span><input type="number" min="25" max="1200" step="0.1" data-certloom-designer-custom-height aria-label="Custom page height in millimetres"></label><span>mm</span></div>
  <span class="certloom-studio-divider" aria-hidden="true"></span>
  <div class="certloom-studio-zoom" role="group" aria-label="Canvas zoom"><button type="button" class="certloom-studio-icon-button" data-certloom-designer-zoom-out aria-label="Zoom out">−</button><input type="hidden" value="100" data-certloom-designer-zoom><output data-certloom-designer-zoom-output>100%</output><button type="button" class="certloom-studio-icon-button" data-certloom-designer-zoom-in aria-label="Zoom in">+</button></div>
  <button type="button" class="certloom-studio-icon-button" data-certloom-designer-fit title="Fit page to workspace — 100% (Ctrl/Cmd+0)" aria-label="Fit page to workspace at 100%">⤢</button>
  <div class="certloom-studio-preview-toggle" role="group" aria-label="Dynamic field preview"><button type="button" class="is-active" data-certloom-designer-preview-mode="sample" aria-pressed="true" title="Show sample values for dynamic fields">Sample</button><button type="button" data-certloom-designer-preview-mode="tokens" aria-pressed="false" title="Show dynamic field tokens on the canvas">Tokens</button></div>
  <button type="button" class="certloom-studio-icon-button" data-certloom-designer-test-print title="Test print" aria-label="Test print">▣</button>
  <button type="button" class="certloom-studio-icon-button" data-certloom-studio-import-open title="Import a .certloom template" aria-label="Import template">⇧</button>
  <details class="certloom-studio-view-menu"><summary class="certloom-studio-icon-button" title="View options" aria-label="View options">•••</summary><div class="certloom-studio-view-popover"><label><input type="checkbox" data-certloom-designer-snap> Snap while moving</label><button type="button" class="button" data-certloom-designer-safe-area aria-pressed="false" title="Show or hide the 10 mm safe area">Safe area</button><button type="button" class="button" data-certloom-designer-pan-tool aria-pressed="false" title="Pan canvas (Space + drag)">Pan</button><label>Sample data<select data-certloom-designer-sample-data><option value="standard">Normal person</option></select></label></div></details>
  <span class="certloom-studio-page-readout" data-certloom-designer-page-readout></span>
</div>
HTML;
$html = studio_replace_between( $html, '<div class="certloom-template-designer-toolbar">', '<div class="certloom-template-designer-productivity">', $docbar );
$html = studio_replace_between( $html, '<div class="certloom-template-designer-productivity">', '<div class="certloom-template-designer-palette">', '' );

$left = <<<'HTML'
<div class="certloom-template-designer-stage"><aside class="certloom-template-designer-add-panel certloom-studio-left" aria-label="Insert and layers">
  <div class="certloom-studio-history" role="toolbar" aria-label="History"><button type="button" class="certloom-studio-icon-button" data-certloom-designer-undo disabled title="Undo">↶</button><button type="button" class="certloom-studio-icon-button" data-certloom-designer-redo disabled title="Redo">↷</button><button type="button" class="certloom-studio-icon-button" data-certloom-designer-reset title="Restore last saved state">⟳</button></div>
  <button type="button" class="certloom-studio-insert-trigger" data-certloom-designer-insert-focus aria-expanded="false"><span>＋</span><span>Insert</span><span class="certloom-studio-insert-caret">⌄</span></button>
  <div class="certloom-studio-insert-drawer" data-certloom-designer-insert-panel hidden>
    <div class="certloom-studio-insert-search"><span>⌕</span><input type="search" placeholder="Search elements…" data-certloom-designer-palette-search aria-label="Search insert elements"></div>
    <section class="certloom-studio-insert-category" data-certloom-insert-category="data"><h4>Data &amp; dynamic</h4><div class="certloom-studio-insert-grid">
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="qr" data-certloom-designer-palette-item data-certloom-designer-palette-category="data" data-certloom-palette-label="qr code"><span>▦</span><span>QR Code</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="barcode" data-certloom-designer-palette-item data-certloom-designer-palette-category="data" data-certloom-palette-label="barcode"><span>▥</span><span>Barcode</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="field" data-certloom-designer-palette-item data-certloom-designer-palette-category="data" data-certloom-palette-label="field"><span>▤</span><span>Field</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="dynamic_field" data-certloom-designer-palette-item data-certloom-designer-palette-category="data" data-certloom-palette-label="dynamic field"><span>◇</span><span>Dynamic Field</span></button>
    </div></section>
    <section class="certloom-studio-insert-category" data-certloom-insert-category="text"><h4>Text</h4><div class="certloom-studio-insert-grid">
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="heading" data-certloom-designer-palette-item data-certloom-designer-palette-category="text" data-certloom-palette-label="heading"><span>H</span><span>Heading</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="text" data-certloom-designer-palette-item data-certloom-designer-palette-category="text" data-certloom-palette-label="text"><span>T</span><span>Text</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="list" data-certloom-designer-palette-item data-certloom-designer-palette-category="text" data-certloom-palette-label="list"><span>≡</span><span>List</span></button>
    </div></section>
    <section class="certloom-studio-insert-category" data-certloom-insert-category="branding"><h4>Branding &amp; media</h4><div class="certloom-studio-insert-grid">
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="image" data-certloom-designer-palette-item data-certloom-designer-palette-category="branding" data-certloom-palette-label="image"><span>▧</span><span>Image</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="logo" data-certloom-designer-palette-item data-certloom-designer-palette-category="branding" data-certloom-palette-label="logo"><span>◈</span><span>Logo</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="seal" data-certloom-designer-palette-item data-certloom-designer-palette-category="branding" data-certloom-palette-label="seal"><span>◎</span><span>Seal</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="signature" data-certloom-designer-palette-item data-certloom-designer-palette-category="branding" data-certloom-palette-label="signature"><span>✎</span><span>Signature</span></button>
    </div></section>
    <section class="certloom-studio-insert-category" data-certloom-insert-category="shapes"><h4>Shapes &amp; layout</h4><div class="certloom-studio-insert-grid">
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="line" data-certloom-designer-palette-item data-certloom-designer-palette-category="shapes" data-certloom-palette-label="line"><span>╱</span><span>Line</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="rectangle" data-certloom-designer-palette-item data-certloom-designer-palette-category="shapes" data-certloom-palette-label="rectangle"><span>□</span><span>Rectangle</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="ellipse" data-certloom-designer-palette-item data-certloom-designer-palette-category="shapes" data-certloom-palette-label="ellipse"><span>○</span><span>Ellipse</span></button>
      <button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="background" data-certloom-designer-palette-item data-certloom-designer-palette-category="shapes" data-certloom-palette-label="background"><span>▨</span><span>Background</span></button>
    </div></section>
  </div>
  <div class="certloom-template-designer-layers certloom-template-designer-layers-panel certloom-template-designer-layers-primary"><div class="certloom-template-designer-layers-head"><h4>Layers</h4><span class="certloom-studio-layer-order-hint" tabindex="0" aria-label="Front to back layer order. Drag a layer up or down to change its position. The first layer is shown in front."><span class="certloom-studio-layer-order-alert" aria-hidden="true">!</span><span>Front → back</span><span class="certloom-studio-layer-order-tooltip" role="tooltip">Drag layers up or down to change their position. Layers nearer the top appear in front.</span></span></div><div class="certloom-studio-layer-list" data-certloom-designer-layers></div></div>
</aside><div class="certloom-template-designer-shell">
HTML;
$html = studio_replace_between( $html, '<div class="certloom-template-designer-palette">', '<div class="certloom-template-designer-shell">', $left );

// Remove the legacy inspector-owned Layers block so the left rail is the only layer authority.
$legacy_layers = '<div class="certloom-template-designer-layers"><div class="certloom-template-designer-layers-head"><h4>Layers</h4><span>Front → back</span></div><div data-certloom-designer-layers></div></div>';
$html = str_replace( $legacy_layers, '', $html );
$html = str_replace( '<aside class="certloom-template-designer-inspector">', '<aside class="certloom-template-designer-inspector" aria-label="Selected layer properties">', $html );
$html = str_replace( '<div data-certloom-designer-empty><h4>Element geometry</h4></div>', '<div data-certloom-designer-empty><h4>Properties</h4><p>Select an element to edit it. Shift+F10 opens element actions.</p></div>', $html );

// Divide the old flat inspector into the Studio single-open accordion contract.
$html = str_replace(
    '<div class="certloom-template-designer-geometry-grid">',
    '<section class="certloom-template-designer-property-section" data-certloom-designer-property-section="geometry"><h4>Position &amp; size</h4><div class="certloom-template-designer-geometry-grid">',
    $html
);
$html = str_replace(
    '</div><div class="certloom-template-designer-content-editor">',
    '</div></section><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="content"><h4>Content &amp; data</h4><div class="certloom-template-designer-content-editor">',
    $html
);
$html = str_replace(
    '</div><div class="certloom-template-designer-style-editor" data-certloom-designer-style-editor>',
    '</div></section><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="appearance"><h4>Appearance</h4><div class="certloom-template-designer-style-editor" data-certloom-designer-style-editor>',
    $html
);
// Close Appearance, append the remaining Studio accordions, then close fields/inspector/shell/stage.
$footer_marker = "\n<div class=\"certloom-template-designer-footer\">";
$footer_pos = strpos( $html, $footer_marker );
if ( false === $footer_pos ) { throw new RuntimeException( 'Studio footer marker missing.' ); }
$closing = '</div></div></aside></div>';
$close_pos = strrpos( substr( $html, 0, $footer_pos ), $closing );
if ( false === $close_pos ) { throw new RuntimeException( 'Studio inspector closing marker missing.' ); }
$extras = '</div></section><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="transform"><h4>Rotate &amp; transform</h4><p class="description">Use the canvas handles to move, resize and rotate the selected element.</p></section><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="arrange"><h4>Arrange</h4><div class="certloom-studio-arrange-grid"><button type="button" data-certloom-designer-align="left" disabled>Left</button><button type="button" data-certloom-designer-align="center-x" disabled>Center</button><button type="button" data-certloom-designer-align="right" disabled>Right</button></div><div class="certloom-studio-arrange-actions"><button type="button" class="button" data-certloom-designer-layer-action="front" disabled>To front</button><button type="button" class="button" data-certloom-designer-layer-action="back" disabled>To back</button></div></section><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="layer"><h4>Layer</h4><div class="certloom-studio-layer-inspector-actions"><button type="button" class="certloom-studio-inspector-icon" data-certloom-designer-lock disabled aria-pressed="false"><span>Lock</span></button><button type="button" class="certloom-studio-inspector-icon" data-certloom-designer-duplicate disabled><span>Duplicate</span></button><button type="button" class="certloom-studio-inspector-icon certloom-studio-danger" data-certloom-designer-remove disabled><span>Delete</span></button></div></section><section class="certloom-template-designer-property-section certloom-studio-advanced" data-certloom-designer-property-section="advanced"><h4>Advanced</h4><p class="description">Technical identifiers are available here for support.</p><div class="certloom-studio-technical-id"><span>Element ID</span><code data-certloom-designer-advanced-id></code></div></section><p class="certloom-studio-shortcuts">Arrow keys nudge 1 mm · Shift+Arrow 5 mm · Alt/Option+Arrow 0.1 mm · Space+drag pans · Shift+F10 actions · Ctrl/Cmd+0 fits · Ctrl/Cmd+Z undo</p></div></aside></div></div>';
$html = substr( $html, 0, $close_pos ) . $extras . substr( $html, $close_pos + strlen( $closing ) );

// Footer save is redundant in Studio; leave hidden recovery state but no third visible save action.
$html = preg_replace( '#<div class="certloom-template-designer-footer">.*?</div></form>#s', '</form>', $html, 1 );
$html = str_replace( '<pre id="certloom-browser-result"></pre>', '</div></div><dialog data-certloom-studio-import-dialog class="certloom-studio-import-dialog"><button type="button" data-certloom-studio-import-close>Close</button><p>Import a portable .certloom template.</p></dialog><pre id="certloom-browser-result" style="display:none"></pre>', $html );

// Browser fixture should not open a native print dialog.
$html = str_replace( '<script>window.wp=', '<script>window.print=function(){window.__certloomPrinted=(window.__certloomPrinted||0)+1;};</script><script>window.wp=', $html );

if ( false === strpos( $html, 'certloom-template-designer-v2' ) || 1 !== substr_count( $html, 'data-certloom-designer-layers></div>' ) || false === strpos( $html, 'data-certloom-designer-insert-panel' ) || false === strpos( $html, 'data-certloom-designer-property-section="appearance"' ) || false === strpos( $html, 'data-certloom-designer-preview-mode="tokens"' ) || false === strpos( $html, 'data-certloom-designer-property-section="advanced"' ) ) {
    throw new RuntimeException( 'Gamma-02R Studio fixture transformation is incomplete.' );
}
echo $html;
