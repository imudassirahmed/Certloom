<?php
/** Current Phase 4 contextual-properties fixture over the Gamma-02R Studio shell. */
ob_start();
require __DIR__ . '/build_ui_phase4_designer_v2_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Phase 4 Designer fixture did not render.' ); }

/*
 * The current Studio no longer has the legacy Productivity/context dock. The
 * authoritative contextual controls live in the right Inspector rendered by
 * build_ui_phase4_designer_v2_fixture.php. Keep this fixture deliberately thin
 * so browser gates follow production rather than manufacturing removed markup.
 */
$required = array(
    'aria-label="Selected layer properties"',
    'data-certloom-designer-property-section="geometry"',
    'data-certloom-designer-property-section="content"',
    'data-certloom-designer-property-section="appearance"',
    'data-certloom-designer-property-section="arrange"',
    'data-certloom-designer-property-section="layer"',
    'data-certloom-designer-property-section="advanced"',
    'data-certloom-designer-context-edit-text',
    'data-certloom-designer-context-rich-text',
);
foreach ( $required as $marker ) {
    if ( false === strpos( $html, $marker ) ) { throw new RuntimeException( 'Current Inspector marker missing: ' . $marker ); }
}
if ( false !== strpos( $html, 'certloom-template-designer-productivity certloom-designer-pro-only' ) ) {
    throw new RuntimeException( 'Obsolete Productivity wrapper reappeared in the current Studio fixture.' );
}
echo $html;
