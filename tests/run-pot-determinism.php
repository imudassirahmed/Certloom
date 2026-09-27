<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/support/python-process.php';
$root = dirname( __DIR__ );
$tool = $root . '/tests/i18n/check_make_pot_determinism.py';
if ( ! is_file( $tool ) ) {
    throw new RuntimeException( 'POT determinism regression helper is missing.' );
}
list( $rc, $output ) = certloom_test_python( array( $tool, $root ) );
if ( 0 !== $rc ) {
    throw new RuntimeException( "POT determinism regression failed:\n" . $output );
}
echo $output . "\n";
