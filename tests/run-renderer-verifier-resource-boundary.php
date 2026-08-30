<?php
/** 1.6.32 independent PDF-04 verifier resource-boundary regression. */
$checks = 0;
function rvrb_check( $condition, $message ) { global $checks; $checks++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
$script = __DIR__ . '/renderer-candidate/test-verifier-resource-boundary.py';
rvrb_check( is_file( $script ), 'Focused verifier resource-boundary test must ship in source.' );
$cmd = 'python3 ' . escapeshellarg( $script ) . ' 2>&1';
$output = array(); $rc = 0; exec( $cmd, $output, $rc );
rvrb_check( 0 === $rc, "Focused verifier resource-boundary test failed:\n" . implode( "\n", $output ) );
rvrb_check( false !== strpos( implode( "\n", $output ), '11 checks, 0 failures' ), 'Focused verifier resource-boundary proof count drifted.' );
$verifier = file_get_contents( __DIR__ . '/renderer-candidate/verify-bundle.py' );
rvrb_check( false !== strpos( $verifier, 'MAX_ARTIFACT_BYTES = 50 * 1024 * 1024' ), 'Verifier artifact byte cap must remain explicit.' );
rvrb_check( false !== strpos( $verifier, 'MAX_RASTER_PIXELS = 5_000_000' ), 'Verifier raster pixel cap must remain explicit.' );
rvrb_check( false !== strpos( $verifier, 'TOOL_TIMEOUT_SECONDS = 30' ), 'Verifier external-tool timeout must remain explicit.' );
rvrb_check( false !== strpos( $verifier, 'bundle raster DPI does not match the frozen acceptance DPI' ), 'Verifier must bind artifact DPI to the frozen golden contract.' );
echo "PASS  PDF-04 independent verifier has explicit resource, symlink, geometry and timeout boundaries\n";
echo "\n{$checks} checks, 0 failures\n";
