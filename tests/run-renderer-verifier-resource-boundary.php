<?php
/** 1.6.32 independent PDF-04 verifier resource-boundary regression. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/support/python-process.php';
$checks = 0;
function rvrb_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
$script = __DIR__ . '/renderer-candidate/test-verifier-resource-boundary.py';
rvrb_check( is_file( $script ), 'Focused verifier resource-boundary test must ship in source.' );
list( $rc, $output ) = certloom_test_python( array( $script ) );
rvrb_check( 0 === $rc, "Focused verifier resource-boundary test failed:\n" . $output );
preg_match( '/(\d+) checks, 0 failures, (\d+) platform skips/', $output, $counts );
rvrb_check( isset( $counts[2] ) && 11 === (int) $counts[1] + (int) $counts[2], 'Focused verifier resource-boundary proof count drifted.' );
echo $output;
$verifier = file_get_contents( __DIR__ . '/renderer-candidate/verify-bundle.py' );
rvrb_check( false !== strpos( $verifier, 'MAX_ARTIFACT_BYTES = 50 * 1024 * 1024' ), 'Verifier artifact byte cap must remain explicit.' );
rvrb_check( false !== strpos( $verifier, 'MAX_RASTER_PIXELS = 5_000_000' ), 'Verifier raster pixel cap must remain explicit.' );
rvrb_check( false !== strpos( $verifier, 'TOOL_TIMEOUT_SECONDS = 30' ), 'Verifier external-tool timeout must remain explicit.' );
rvrb_check( false !== strpos( $verifier, 'bundle raster DPI does not match the frozen acceptance DPI' ), 'Verifier must bind artifact DPI to the frozen golden contract.' );
echo "PASS  PDF-04 independent verifier has explicit resource, symlink, geometry and timeout boundaries\n";
echo "\n{$checks} checks, 0 failures\n";
