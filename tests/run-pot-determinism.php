<?php
$root = dirname( __DIR__ );
$tool = $root . '/tests/i18n/check_make_pot_determinism.py';
if ( ! is_file( $tool ) ) {
    throw new RuntimeException( 'POT determinism regression helper is missing.' );
}
$output = array();
$rc = 0;
exec( 'python3 ' . escapeshellarg( $tool ) . ' ' . escapeshellarg( $root ) . ' 2>&1', $output, $rc );
if ( 0 !== $rc ) {
    throw new RuntimeException( "POT determinism regression failed:\n" . implode( "\n", $output ) );
}
echo implode( "\n", $output ) . "\n";
