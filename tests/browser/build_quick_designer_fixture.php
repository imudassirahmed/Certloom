<?php
/** Build a self-contained DSX-01 browser fixture by extending the permanent Designer fixture with Quick/Pro presentation. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
ob_start();
require __DIR__ . '/build_template_designer_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Base Designer fixture did not render.' ); }

$html = str_replace(
    '<section class="certloom-template-designer" data-certloom-designer ',
    '<section class="certloom-template-designer" data-certloom-designer data-designer-default-mode="quick" ',
    $html
);
$html = str_replace(
    '<span class="certloom-template-designer-kicker">DSN-08</span><h3>Certificate Designer</h3><p>Fixture</p>',
    '<span class="certloom-template-designer-kicker">DSX-01 · Quick Designer</span><h3>Certificate Designer</h3><p>Quick and Pro edit the same canonical layout.</p>',
    $html
);
$html = str_replace(
    '<form data-certloom-designer-form>',
    '<div class="certloom-template-designer-mode-switch" role="group" aria-label="Designer mode"><button type="button" class="button button-primary" data-certloom-designer-mode-button="quick" aria-pressed="true">Quick Designer</button><button type="button" class="button" data-certloom-designer-mode-button="pro" aria-pressed="false">Pro Designer</button><span>Same design · more precise controls in Pro</span></div><form data-certloom-designer-form>',
    $html
);
$html = str_replace('<label><span>Page size</span><select data-certloom-designer-page-size>', '<label class="certloom-designer-pro-only"><span>Page size</span><select data-certloom-designer-page-size>', $html);
$html = str_replace('<label><span>Orientation</span><select data-certloom-designer-orientation>', '<label class="certloom-designer-pro-only"><span>Orientation</span><select data-certloom-designer-orientation>', $html);
$html = str_replace('<label class="certloom-template-designer-snap">', '<label class="certloom-template-designer-snap certloom-designer-pro-only">', $html);
$html = str_replace('<button type="button" class="button" data-certloom-designer-reset>', '<button type="button" class="button certloom-designer-pro-only" data-certloom-designer-reset>', $html);
$html = str_replace(
    '<button type="button" class="button" data-certloom-designer-fit>Fit workspace</button><button type="button" class="button certloom-designer-pro-only" data-certloom-designer-reset>',
    '<button type="button" class="button" data-certloom-designer-fit>Fit</button><button type="submit" class="button certloom-studio-draft" data-certloom-designer-save>Draft</button><button type="submit" class="button button-primary certloom-studio-primary" data-certloom-designer-primary-save data-certloom-designer-quick-save>Publish</button><button type="button" class="button certloom-designer-pro-only" data-certloom-designer-reset>',
    $html
);
$html = str_replace('<div class="certloom-template-designer-productivity">', '<div class="certloom-template-designer-productivity certloom-designer-pro-only" data-certloom-designer-productivity>', $html);

$quick = array('text','image','logo','merge','qr','rectangle','line');
foreach ( $quick as $kind ) {
    $html = str_replace('class="button" data-certloom-designer-add="' . $kind . '"', 'class="button certloom-designer-quick-palette" data-certloom-designer-add="' . $kind . '"', $html);
}
$all = array('signature','seal','barcode','list','ellipse','background');
foreach ( $all as $kind ) {
    $html = str_replace('class="button" data-certloom-designer-add="' . $kind . '"', 'class="button certloom-designer-pro-only" data-certloom-designer-add="' . $kind . '"', $html);
}
$html = str_replace('>Merge field</button>', '>Dynamic Field</button>', $html);
$html = str_replace('>Rectangle</button>', '>Shape</button>', $html);
$html = str_replace(
    '<button type="button" class="button" data-certloom-designer-remove disabled>Remove selected</button>',
    '<button type="button" class="button" data-certloom-designer-duplicate disabled>Duplicate selected</button><button type="button" class="button" data-certloom-designer-remove disabled>Delete selected</button>',
    $html
);
$html = str_replace(
    '<div class="certloom-template-designer-palette"><span class="certloom-template-designer-productivity-label">Add</span>',
    '<div class="certloom-template-designer-stage"><aside class="certloom-template-designer-add-panel" aria-label="Add elements"><div class="certloom-template-designer-add-head"><strong>Add</strong><small>Drag after adding</small></div><div class="certloom-template-designer-palette"><span class="certloom-template-designer-productivity-label">Add</span>',
    $html
);
$html = str_replace(
    "<button type=\"button\" class=\"button\" data-certloom-designer-remove disabled>Delete selected</button></div>\n<div class=\"certloom-template-designer-shell\">",
    '<button type="button" class="button" data-certloom-designer-remove disabled>Delete selected</button></div></aside><div class="certloom-template-designer-shell">',
    $html
);
$html = str_replace('<div class="certloom-template-designer-geometry-grid">', '<div class="certloom-template-designer-geometry-grid certloom-designer-pro-only">', $html);
$html = str_replace(
    '<div hidden data-certloom-designer-content="merge"><input data-certloom-designer-content-field="token"></div>',
    '<div hidden data-certloom-designer-content="merge"><label class="certloom-designer-quick-only"><span>Dynamic field</span><select data-certloom-designer-quick-token><option value="recipient.name">Recipient Name</option><option value="credential.display_number">Certificate Number</option><option value="program.name">Program</option><option value="credential.issue_date">Issue Date</option><option value="credential.expiry_date">Expiry Date</option><option value="credential.grade">Grade</option><option value="issuer.name">Issuer</option><option value="credential.status">Status</option><option value="__custom__">Custom Field…</option></select></label><label class="certloom-designer-token-advanced" data-certloom-designer-custom-token><span>Field token</span><input data-certloom-designer-content-field="token" pattern="[a-z][a-z0-9_.:-]{0,127}"></label></div>',
    $html
);
$html = str_replace(
    '<div class="certloom-template-designer-layers"><div class="certloom-template-designer-layers-head">',
    '<button type="button" class="button certloom-designer-quick-only certloom-template-designer-layers-toggle" data-certloom-designer-layers-toggle aria-expanded="false">Layers</button><div class="certloom-template-designer-layers certloom-template-designer-layers-panel"><div class="certloom-template-designer-layers-head">',
    $html
);
$html = str_replace(
    "</div></div></aside></div>\n<div class=\"certloom-template-designer-footer\">",
    "</div></div></aside></div></div>\n<div class=\"certloom-template-designer-footer\">",
    $html
);
$html = str_replace(
    '<pre id="certloom-browser-result"></pre>',
    '<section class="certloom-template-preview-card" tabindex="-1"><h3>Preview target</h3></section><section class="certloom-template-publication-card" tabindex="-1"><h3>Publication target</h3></section><pre id="certloom-browser-result"></pre>',
    $html
);

if ( false === strpos( $html, 'data-certloom-designer-quick-token' ) || false === strpos( $html, '<div class="certloom-template-designer-stage">' ) || false === strpos( $html, '<aside class="certloom-template-designer-add-panel"' ) ) {
    throw new RuntimeException( 'Quick Designer fixture transformation is incomplete.' );
}
echo $html;
