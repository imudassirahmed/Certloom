<?php
/** Validate an already-bundled accepted.json against the exact source payload. */
require_once __DIR__ . '/authorized-release-lib.php';

if ( 2 !== $argc ) {
    fwrite( STDERR, "usage: validate-bundled-authority.php ACCEPTED.json\n" );
    exit( 2 );
}
try {
    $validator = new CertloomAuthorizedReleaseAuthority( dirname( __DIR__, 2 ) );
    $result = $validator->validate_manifest_file( $argv[1] );
    echo json_encode( array(
        'schema' => 'certloom-bundled-authority-validation',
        'version' => 1,
        'plugin_version' => $result['plugin_version'],
        'runtime_payload' => $result['runtime_payload'],
        'release_manifest_sha256' => $result['release_manifest_sha256'],
    ), JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) {
    fwrite( STDERR, $error->getMessage() . "\n" );
    exit( 3 );
}
