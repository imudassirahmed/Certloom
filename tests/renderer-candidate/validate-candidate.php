<?php
/** Normalize one production renderer candidate manifest and print its canonical SHA-256. */
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\RendererCandidateManifest;

if ( 2 !== $argc ) {
	fwrite( STDERR, "usage: validate-candidate.php MANIFEST.json\n" );
	exit( 2 );
}
$raw = @file_get_contents( $argv[1] );
$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
if ( ! is_array( $data ) ) {
	fwrite( STDERR, "candidate manifest is not valid JSON\n" );
	exit( 3 );
}
try {
	$contract = new RendererCandidateManifest();
	$normalized = $contract->normalize( $data );
	$result = array( 'schema' => 'certloom-renderer-candidate-validation', 'version' => 1, 'candidate_manifest_sha256' => $contract->hash( $data ), 'renderer_id' => $normalized['renderer_id'], 'renderer_version' => $normalized['renderer_version'], 'formats' => $normalized['formats'], 'source_sha256' => $normalized['implementation']['source_sha256'], 'dependency_lock_sha256' => $normalized['implementation']['dependency_lock_sha256'] );
	echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 4 );
}
