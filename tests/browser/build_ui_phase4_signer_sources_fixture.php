<?php
/** Current Signer signature-source fixture over the Certificate Studio shell. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( false === getenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION' ) || '' === (string) getenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION' ) ) { putenv( 'CERTLOOM_DESIGNER_SCHEMA_VERSION=4' ); }
ob_start(); require __DIR__ . '/build_ui_phase4_sticky_workspace_fixture.php'; $html = ob_get_clean();
if ( ! is_string( $html ) || '' === $html ) { throw new RuntimeException( 'Current Studio fixture did not render.' ); }
if ( false === strpos( $html, 'data-certloom-designer-content="signature"' ) || false === strpos( $html, 'data-certloom-designer-signature-source="signer"' ) || false === strpos( $html, 'data-certloom-designer-signature-status' ) ) { throw new RuntimeException( 'Current Signer source fixture is incomplete.' ); }
echo $html;
