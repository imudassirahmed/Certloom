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
    'data-template-id="77" data-publication-state="draft" data-template-updated-at="2026-09-13 10:00:00" data-studio-metadata-nonce="fixture-studio-metadata-nonce"',
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
  <div class="certloom-studio-actions"><button type="submit" form="certloom-studio-fixture-form" class="button certloom-studio-draft" name="studio_intent" value="draft" data-certloom-designer-save data-certloom-designer-save-intent="draft">Draft</button><button type="submit" form="certloom-studio-fixture-form" class="button button-primary certloom-studio-primary" name="studio_intent" value="publish" data-certloom-designer-save-intent="publish" data-certloom-designer-primary-save data-certloom-designer-quick-save>Publish</button></div>
</header>
<div class="certloom-studio-toast" role="status" data-certloom-studio-toast hidden><span></span></div>
<div class="certloom-studio-conflict" data-certloom-designer-conflict-banner hidden role="alert"><div><strong>Template changed in another session</strong><span>Your current browser edits are still here. Recovery autosave has paused so it cannot overwrite the newer saved revision.</span></div><div class="certloom-studio-conflict-actions"><button type="button" class="button button-small" data-certloom-designer-conflict-review>Keep reviewing</button><button type="button" class="button button-small" data-certloom-designer-conflict-reload>Reload saved version</button></div></div>
HTML;
$html = studio_replace_between( $html, '<div class="certloom-template-designer-head">', '<form data-certloom-designer-form>', $header );
$html = str_replace( '<form data-certloom-designer-form>', '<form id="certloom-studio-fixture-form" data-certloom-designer-form novalidate><input type="hidden" name="studio_intent_fallback" value="draft" data-certloom-designer-save-intent-field>', $html );
$html = str_replace( '</body>', '<script>document.addEventListener("submit",function(e){if(e.target&&e.target.id==="certloom-studio-fixture-form"){e.preventDefault();document.body.setAttribute("data-submitted","1");var f=e.target.querySelector("[data-certloom-designer-save-intent-field]");document.body.setAttribute("data-submit-intent",f?f.value:"");document.body.setAttribute("data-submit-button-intent",e.submitter&&e.submitter.value?e.submitter.value:"");}});</script></body>', $html );

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
$samples = ( new \Certloom\Application\Templates\TemplatePreviewSamples() )->browser_contract();
$samples_json = json_encode( $samples, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $samples_json ) ) { throw new RuntimeException( 'Sample data fixture contract unavailable.' ); }
$sample_options = '<option value="standard">Normal person</option><option value="long">Long name</option><option value="very-long-name">Very long name</option><option value="rtl">RTL person</option><option value="organization">Organization</option><option value="long-organization">Long organization</option><option value="long-iso-scope">Long ISO scope</option><option value="school-result">School result</option>';
$html = preg_replace( '#(<select data-certloom-designer-sample-data>).*?(</select>)#s', '$1' . $sample_options . '$2', $html, 1, $sample_select_count );
if ( 1 !== $sample_select_count ) { throw new RuntimeException( 'Current Sample data selector was not found exactly once.' ); }
$registry_marker = '<script>window.certloomTemplateElementRegistry=';
if ( false === strpos( $html, $registry_marker ) ) { throw new RuntimeException( 'Designer registry script marker missing.' ); }
$html = str_replace( $registry_marker, '<script>window.certloomTemplateDesignerSamples=' . $samples_json . ';</script>' . $registry_marker, $html, $sample_contract_count );
if ( 1 !== $sample_contract_count ) { throw new RuntimeException( 'Sample browser contract injection was ambiguous.' ); }
$html = studio_replace_between( $html, '<div class="certloom-template-designer-productivity">', '<div class="certloom-template-designer-palette">', '' );

$registry_labels = array(
    'qr' => 'QR Code', 'barcode' => 'Barcode', 'field' => 'Field', 'dynamicField' => 'Dynamic Field', 'mergeField' => 'Merge Field',
    'heading' => 'Heading', 'text' => 'Text', 'richText' => 'Rich Text', 'list' => 'List',
    'image' => 'Image', 'logo' => 'Logo', 'seal' => 'Seal', 'signature' => 'Signature',
    'line' => 'Line', 'rectangle' => 'Rectangle', 'ellipse' => 'Ellipse', 'background' => 'Background',
    'table' => 'Table', 'dataTable' => 'Data Table',
);
$palette_categories = array(
    'data' => array( 'label' => 'Data & dynamic', 'kinds' => array( 'qr', 'barcode', 'field', 'dynamic_field', 'merge' ) ),
    'text' => array( 'label' => 'Text', 'kinds' => array( 'heading', 'text', 'rich_text', 'list' ) ),
    'media' => array( 'label' => 'Branding & media', 'kinds' => array( 'image', 'logo', 'seal', 'signature' ) ),
    'layout' => array( 'label' => 'Shapes & layout', 'kinds' => array( 'line', 'rectangle', 'ellipse', 'background' ) ),
    'structured' => array( 'label' => 'Structured data', 'kinds' => array( 'table', 'data_table' ) ),
);
$palette_by_kind = array();
foreach ( \Certloom\Domain\Rendering\ElementRegistry::palette() as $palette_item ) { $palette_by_kind[ (string) $palette_item['kind'] ] = $palette_item; }
$palette_html = '';
foreach ( $palette_categories as $category_key => $category ) {
    $items = '';
    foreach ( $category['kinds'] as $kind ) {
        if ( ! isset( $palette_by_kind[ $kind ] ) ) { continue; }
        $item = $palette_by_kind[ $kind ];
        $maximum = (int) ( $item['max_schema_version'] ?? 0 );
        if ( $maximum && $schema_version > $maximum ) { continue; }
        $minimum = (int) ( $item['min_schema_version'] ?? 1 );
        $disabled = $schema_version < $minimum;
        $label_key = (string) ( $item['label_key'] ?? $kind );
        $label = (string) ( $registry_labels[ $label_key ] ?? $kind );
        $items .= '<button type="button" class="certloom-studio-insert-item" data-certloom-designer-add="' . esc_attr( $kind ) . '" data-certloom-designer-palette-item data-certloom-designer-palette-category="' . esc_attr( $category_key ) . '" data-certloom-palette-label="' . esc_attr( strtolower( $label ) ) . '"' . ( $disabled ? ' disabled aria-disabled="true"' : ' draggable="true"' ) . '><span>' . esc_attr( $label ) . '</span></button>';
    }
    if ( '' !== $items ) { $palette_html .= '<section class="certloom-studio-insert-category" data-certloom-insert-category="' . esc_attr( $category_key ) . '"><h4>' . esc_attr( $category['label'] ) . '</h4><div class="certloom-studio-insert-grid">' . $items . '</div></section>'; }
}
$left = '<div class="certloom-template-designer-stage"><aside class="certloom-template-designer-add-panel certloom-studio-left" aria-label="Insert and layers">'
  . '<div class="certloom-studio-history" role="toolbar" aria-label="History"><button type="button" class="certloom-studio-icon-button" data-certloom-designer-undo disabled title="Undo">↶</button><button type="button" class="certloom-studio-icon-button" data-certloom-designer-redo disabled title="Redo">↷</button><button type="button" class="certloom-studio-icon-button" data-certloom-designer-reset title="Restore last saved state">⟳</button></div>'
  . '<button type="button" class="certloom-studio-insert-trigger" data-certloom-designer-insert-focus aria-expanded="false"><span>＋</span><span>Insert</span><span class="certloom-studio-insert-caret">⌄</span></button>'
  . '<div class="certloom-studio-insert-drawer" data-certloom-designer-insert-panel hidden><div class="certloom-studio-insert-search"><span>⌕</span><input type="search" placeholder="Search elements…" data-certloom-designer-palette-search aria-label="Search insert elements"></div>' . $palette_html . '</div>'
  . '<div class="certloom-template-designer-layers certloom-template-designer-layers-panel certloom-template-designer-layers-primary"><div class="certloom-template-designer-layers-head"><h4>Layers</h4><span class="certloom-studio-layer-order-hint" tabindex="0" aria-label="Front to back layer order. Drag a layer up or down to change its position. The first layer is shown in front."><span class="certloom-studio-layer-order-alert" aria-hidden="true">!</span><span>Front → back</span><span class="certloom-studio-layer-order-tooltip" role="tooltip">Drag layers up or down to change their position. Layers nearer the top appear in front.</span></span></div><div class="certloom-studio-layer-list" data-certloom-designer-layers></div></div>'
  . '</aside><div class="certloom-template-designer-shell">';
$html = studio_replace_between( $html, '<div class="certloom-template-designer-palette">', '<div class="certloom-template-designer-shell">', $left );

// Remove the legacy inspector-owned Layers block so the left rail is the only layer authority.
$legacy_layers = '<div class="certloom-template-designer-layers"><div class="certloom-template-designer-layers-head"><h4>Layers</h4><span>Front → back</span></div><div data-certloom-designer-layers></div></div>';
$html = str_replace( $legacy_layers, '', $html );
$html = str_replace( '<aside class="certloom-template-designer-inspector">', '<aside class="certloom-template-designer-inspector" aria-label="Selected layer properties">', $html );
$html = str_replace( '<div data-certloom-designer-empty><h4>Element geometry</h4></div>', '<div data-certloom-designer-empty><h4>Properties</h4><p>Select an element to edit it. Shift+F10 opens element actions.</p></div>', $html );
$html = str_replace( '<div hidden data-certloom-designer-fields><div class="certloom-template-designer-element-meta">', '<div hidden data-certloom-designer-group><h4>Selection</h4><p><strong data-certloom-designer-group-count>0</strong> elements selected.</p><div class="certloom-studio-selection-feedback"><span data-certloom-designer-selection-status></span><span data-certloom-designer-spacing-status></span></div></div><div hidden data-certloom-designer-fields><div class="certloom-template-designer-element-meta">', $html );

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

/* Mirror current production Inspector groups that were added after the legacy base fixture. */
$asset_group = '<div hidden data-certloom-designer-content="asset"><input data-certloom-designer-content-field="asset"><select data-certloom-designer-content-field="fit"><option value="contain">Contain</option><option value="cover">Cover</option><option value="fill">Fill</option></select><button type="button" class="button" data-certloom-designer-media>Media</button></div>';
$current_asset_group = '<div hidden data-certloom-designer-content="asset"><p class="description" data-certloom-designer-issuer-media-status></p><input data-certloom-designer-content-field="asset"><select data-certloom-designer-content-field="fit"><option value="contain">Contain</option><option value="cover">Cover</option><option value="fill">Fill</option></select><button type="button" class="button" data-certloom-designer-issuer-media-source="logo">Use credential Issuer logo</button><button type="button" class="button" data-certloom-designer-issuer-media-source="seal">Use credential Issuer seal</button><button type="button" class="button" data-certloom-designer-media>Choose from Media Library</button></div>';
$signature_group = '<div hidden data-certloom-designer-content="signature"><p class="description" data-certloom-designer-signature-status></p><select data-certloom-designer-content-field="fit"><option value="contain">Contain</option><option value="cover">Cover</option><option value="fill">Fill</option></select><button type="button" class="button" data-certloom-designer-signature-source="signer">Use credential Signer signature</button><button type="button" class="button" data-certloom-designer-media>Choose fixed signature image</button></div>';
if ( false !== strpos( $html, $asset_group ) ) { $html = str_replace( $asset_group, $current_asset_group . $signature_group, $html, $asset_count ); if ( 1 !== $asset_count ) { throw new RuntimeException( 'Current asset/signature Inspector injection was ambiguous.' ); } }

$line_group = '<div hidden data-certloom-designer-content="line"></div>';
$table_groups = '<div hidden data-certloom-designer-content="table" class="certloom-template-designer-table-editor"><div class="certloom-template-designer-table-head"><strong>Columns</strong><button type="button" class="button" data-certloom-designer-table-add-column>Add column</button></div><div data-certloom-designer-table-columns></div><div class="certloom-template-designer-table-head"><strong>Rows</strong><button type="button" class="button" data-certloom-designer-table-add-row>Add row</button></div><div data-certloom-designer-table-rows></div><input type="checkbox" data-certloom-designer-table-header></div><div hidden data-certloom-designer-content="data_table" class="certloom-template-designer-table-editor"><select data-certloom-designer-table-source><option value="credential.results">Credential results</option><option value="credential.subjects">Credential subjects</option></select><input type="number" min="1" max="50" data-certloom-designer-table-max-rows><input type="checkbox" data-certloom-designer-table-header><button type="button" class="button" data-certloom-designer-table-add-column>Add column</button><div data-certloom-designer-table-columns></div></div>';
if ( false !== strpos( $html, $line_group ) ) { $html = str_replace( $line_group, $table_groups . $line_group, $html, $table_group_count ); if ( 1 !== $table_group_count ) { throw new RuntimeException( 'Current table Inspector injection was ambiguous.' ); } }
// Close Appearance, append the remaining Studio accordions, then close fields/inspector/shell/stage.
$footer_marker = "\n<div class=\"certloom-template-designer-footer\">";
$footer_pos = strpos( $html, $footer_marker );
if ( false === $footer_pos ) { throw new RuntimeException( 'Studio footer marker missing.' ); }
$closing = '</div></div></aside></div>';
$close_pos = strrpos( substr( $html, 0, $footer_pos ), $closing );
if ( false === $close_pos ) { throw new RuntimeException( 'Studio inspector closing marker missing.' ); }
$table_style = '<div class="certloom-template-designer-style-group" hidden data-certloom-designer-style="table"><div class="certloom-template-designer-style-grid"><select data-certloom-designer-style-field="font_family"><option value="sans-serif">sans</option><option value="serif">serif</option><option value="monospace">mono</option></select><input type="number" data-certloom-designer-style-field="font_size_pt"><input type="text" data-certloom-designer-style-field="color"><input type="text" data-certloom-designer-style-field="header_fill"><input type="text" data-certloom-designer-style-field="header_color"><select data-certloom-designer-style-field="header_font_weight"><option value="400">400</option><option value="600">600</option><option value="700">700</option></select><input type="text" data-certloom-designer-style-field="row_fill"><input type="text" data-certloom-designer-style-field="alternate_row_fill"><input type="text" data-certloom-designer-style-field="border_color"><input type="number" data-certloom-designer-style-field="border_width_mm"><input type="number" data-certloom-designer-style-field="cell_padding_mm"></div></div>';
$extras = $table_style . '</div></section><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="transform"><h4>Rotate &amp; transform</h4><p class="description">Use the canvas handles to move, resize and rotate the selected element.</p></section><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="arrange"><h4>Arrange</h4><div class="certloom-studio-arrange-grid"><button type="button" data-certloom-designer-align="left" disabled>Left</button><button type="button" data-certloom-designer-align="center-x" disabled>Center</button><button type="button" data-certloom-designer-align="right" disabled>Right</button></div><div class="certloom-studio-arrange-actions"><button type="button" class="button" data-certloom-designer-layer-action="front" disabled>To front</button><button type="button" class="button" data-certloom-designer-layer-action="back" disabled>To back</button></div></section><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="layer"><h4>Layer</h4><div class="certloom-studio-layer-inspector-actions"><button type="button" class="certloom-studio-inspector-icon" data-certloom-designer-lock disabled aria-pressed="false"><span>Lock</span></button><button type="button" class="certloom-studio-inspector-icon" data-certloom-designer-duplicate disabled><span>Duplicate</span></button><button type="button" class="certloom-studio-inspector-icon certloom-studio-danger" data-certloom-designer-remove disabled><span>Delete</span></button></div></section><section class="certloom-template-designer-property-section certloom-studio-advanced" data-certloom-designer-property-section="advanced"><h4>Advanced</h4><p class="description">Technical identifiers are available here for support.</p><div class="certloom-studio-technical-id"><span>Element ID</span><code data-certloom-designer-advanced-id></code></div></section><p class="certloom-studio-shortcuts">Arrow keys nudge 1 mm · Shift+Arrow 5 mm · Alt/Option+Arrow 0.1 mm · Space+drag pans · Shift+F10 actions · Ctrl/Cmd+0 fits · Ctrl/Cmd+Z undo</p></div></aside></div></div>';
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
