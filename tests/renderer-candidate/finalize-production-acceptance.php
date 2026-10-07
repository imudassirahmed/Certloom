<?php
/** Canonically import PDF-04 evidence and stage release-bound accepted.json. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/finalize-lib.php';

if ( 6 !== $argc ) {
	fwrite( STDERR, "usage: finalize-production-acceptance.php ACCEPTANCE.json VERIFICATION-REPORT.json VERIFIER-ENVIRONMENT.json VERIFICATION-INDEX.json OUTPUT_DIR\n" );
	exit( 2 );
}

try {
	$finalizer = new CertloomProductionAcceptanceFinalizer( dirname( __DIR__, 2 ) );
	$result = $finalizer->finalize( $argv[1], $argv[2], $argv[3], $argv[4], $argv[5] );
	echo json_encode( array(
		'schema' => 'certloom-renderer-authority-finalization-result',
		'version' => 1,
		'plugin_version' => $result['release_manifest']['plugin_version'],
		'acceptance_sha256' => $result['acceptance_sha256'],
		'release_manifest_sha256' => $result['release_manifest_sha256'],
		'output_dir' => $result['output_dir'],
	), JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 3 );
}
