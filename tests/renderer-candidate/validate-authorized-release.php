<?php
/** Validate reviewed renderer authority before deterministic release packaging. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/authorized-release-lib.php';

if ( $argc < 2 || $argc > 3 ) {
    fwrite( STDERR, "usage: validate-authorized-release.php AUTHORITY_DIR [OUTPUT_ACCEPTED.json]\n" );
    exit( 2 );
}

try {
    $validator = new CertloomAuthorizedReleaseAuthority( dirname( __DIR__, 2 ) );
    $result = $validator->validate_authority_directory( $argv[1] );
    if ( 3 === $argc ) {
        $target = (string) $argv[2];
        $parent = dirname( $target );
        if ( ! is_dir( $parent ) || ! is_writable( $parent ) || file_exists( $target ) || is_link( $target ) ) {
            throw new InvalidArgumentException( 'Authorized renderer manifest export target must be a new file in an existing writable directory.' );
        }
        $handle = @fopen( $target, 'xb' );
        if ( false === $handle ) { throw new RuntimeException( 'Authorized renderer manifest export could not be created.' ); }
        try {
            $bytes = $result['accepted_json_bytes'];
            $written = fwrite( $handle, $bytes );
            if ( false === $written || $written !== strlen( $bytes ) ) { throw new RuntimeException( 'Authorized renderer manifest export was incomplete.' ); }
            fflush( $handle );
        } finally { fclose( $handle ); }
        @chmod( $target, 0644 );
    }
    echo json_encode( array(
        'schema' => 'certloom-authorized-release-validation',
        'version' => 1,
        'plugin_version' => $result['plugin_version'],
        'runtime_payload' => $result['runtime_payload'],
        'release_manifest_sha256' => $result['release_manifest_sha256'],
    ), JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) {
    fwrite( STDERR, $error->getMessage() . "\n" );
    exit( 3 );
}
