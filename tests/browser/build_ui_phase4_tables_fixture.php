<?php
/** Current Table/Data Table fixture over the Certificate Studio shell. */
if ( false === getenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION' ) || '' === (string) getenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION' ) ) { putenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION=4' ); }
ob_start(); require __DIR__ . '/build_ui_phase4_sticky_workspace_fixture.php'; $html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Current Studio fixture did not render.' ); }
if ( false === strpos( $html, 'data-certloom-designer-content="table"' ) || false === strpos( $html, 'data-certloom-designer-content="data_table"' ) || false === strpos( $html, 'data-certloom-designer-style="table"' ) ) { throw new RuntimeException( 'Current table fixture is incomplete.' ); }
echo $html;
