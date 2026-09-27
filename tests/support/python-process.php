<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

/** Execute Python without cmd.exe/shell quoting or a Unix-only executable name. */
function certloom_test_python( array $arguments ) {
	$python = getenv( 'CERTLOOM_TEST_PYTHON' ) ?: ( PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3' );
	$process = proc_open( array_merge( array( $python ), $arguments ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Python 3 is required; set CERTLOOM_TEST_PYTHON to its executable path.' ); }
	fclose( $pipes[0] );
	$output = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
	return array( proc_close( $process ), $output );
}
