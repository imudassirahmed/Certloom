<?php
/** Normalize one source-side verifier environment manifest and print its canonical SHA-256. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\RendererVerifierEnvironment;

if ( 2 !== $argc ) { fwrite( STDERR, "usage: validate-verifier-environment.php MANIFEST.json\n" ); exit( 2 ); }
$raw = @file_get_contents( $argv[1] );
$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
if ( ! is_array( $data ) ) { fwrite( STDERR, "verifier environment is not valid JSON\n" ); exit( 3 ); }
try {
	$contract = new RendererVerifierEnvironment();
	$normalized = $contract->normalize( $data );
	echo json_encode( array( 'schema' => 'certloom-renderer-verifier-environment-validation', 'version' => 1, 'verifier_environment_sha256' => $contract->hash( $normalized ), 'plugin_version' => $normalized['plugin_version'] ), JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 4 );
}
