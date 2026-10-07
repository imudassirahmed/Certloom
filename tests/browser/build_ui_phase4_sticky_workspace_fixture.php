<?php
/** Browser fixture for the current independent three-pane Certificate Studio workspace. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
ob_start();
require __DIR__ . '/build_ui_phase4_contextual_properties_fixture.php';
$html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Current Designer fixture did not render.' ); }

/* The sample selector now lives in View options and the Insert drawer is native. */
if ( false === strpos( $html, 'data-certloom-designer-sample-data' )
    || false === strpos( $html, 'data-certloom-designer-insert-focus' )
    || false === strpos( $html, 'data-certloom-designer-workspace' ) ) {
    throw new RuntimeException( 'Current workspace fixture is incomplete.' );
}
echo $html;
