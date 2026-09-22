<?php
/** Build a self-contained DSX-02A browser fixture from the frozen DSX-01 fixture. */
ob_start();
require __DIR__ . '/build_quick_designer_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Quick Designer fixture did not render.' ); }

$html = str_replace(
    'data-designer-default-mode="quick" ',
    'data-designer-default-mode="quick" data-designer-safe-margin-mm="10" ',
    $html
);
$html = str_replace('DSX-01 · Quick Designer', 'DSX-02A · Easy Designer', $html);
$html = str_replace('Quick and Pro edit the same canonical layout.', 'Easy Designer supports direct placement, Smart Guides and direct text editing over the same canonical layout.', $html);
$html = str_replace('class="certloom-template-designer-snap certloom-designer-pro-only"', 'class="certloom-template-designer-snap"', $html);
$html = str_replace('<span>Guides</span><span class="certloom-template-designer-check"><input type="checkbox" data-certloom-designer-snap> Snap while moving</span>', '<span>Smart Guides</span><span class="certloom-template-designer-check"><input type="checkbox" data-certloom-designer-snap> Sticky alignment</span>', $html);
$html = str_replace('<small>Drag after adding</small>', '<small>Click or drag onto canvas</small>', $html);
foreach ( array('text','image','logo','merge','qr','rectangle','line') as $kind ) {
    $html = str_replace('data-certloom-designer-add="' . $kind . '"', 'data-certloom-designer-add="' . $kind . '" draggable="true"', $html);
}
$quick_align = '<div class="certloom-template-designer-quick-align certloom-designer-quick-only" data-certloom-designer-quick-align role="toolbar" aria-label="Quick alignment controls"><span class="certloom-template-designer-productivity-label">Align</span>';
foreach ( array('left'=>'Left','center-x'=>'Center horizontally','right'=>'Right','top'=>'Top','center-y'=>'Center vertically','bottom'=>'Bottom') as $mode=>$label ) {
    $quick_align .= '<button type="button" class="button button-small" data-certloom-designer-align="' . $mode . '" disabled>' . $label . '</button>';
}
$quick_align .= '</div>';
$html = str_replace('<div class="certloom-template-designer-productivity certloom-designer-pro-only"', $quick_align . '<div class="certloom-template-designer-productivity certloom-designer-pro-only"', $html);
$html = str_replace('<label><span>Color</span><input type="text" data-certloom-designer-style-field="color"></label>', '<label><span>Color</span><span class="certloom-template-designer-color-control"><input type="color" value="#000000" data-certloom-designer-color-picker data-certloom-designer-color-picker-for="color" aria-label="Choose text color"><input type="text" data-certloom-designer-style-field="color"></span></label>', $html);

if ( false === strpos( $html, 'DSX-02A · Easy Designer' ) || false === strpos( $html, 'data-certloom-designer-color-picker' ) || false === strpos( $html, 'data-designer-safe-margin-mm="10"' ) ) {
    throw new RuntimeException( 'Easy Designer fixture transformation is incomplete.' );
}
echo $html;
