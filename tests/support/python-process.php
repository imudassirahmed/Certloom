<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

/** Execute Python without cmd.exe/shell quoting or a Unix-only executable name. */
function certloom_test_python( array $arguments, array $environment = array() ) {
	require_once __DIR__ . '/packaging-tools.php';
	$python = getenv( 'CERTLOOM_TEST_PYTHON' ) ?: ( PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3' );
	// JSON/PO evidence is UTF-8, including when Windows uses a legacy console code page.
	$environment = array_merge( array( 'PYTHONIOENCODING' => 'utf-8' ), $environment );
	return certloom_test_process( array_merge( array( $python ), $arguments ), $environment );
}
