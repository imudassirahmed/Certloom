<?php
/** Current Phase 4.4 Insert + sample-data fixture over the Designer-v2 shell. */
ob_start();
require __DIR__ . '/build_ui_phase4_designer_v2_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Phase 4 Designer fixture did not render.' ); }
if ( false === strpos( $html, 'data-certloom-designer-insert-panel' ) || false === strpos( $html, 'data-certloom-designer-palette-item' ) || false === strpos( $html, 'window.certloomTemplateDesignerSamples=' ) ) {
    throw new RuntimeException( 'Current Insert/sample-data fixture is incomplete.' );
}
echo $html;
