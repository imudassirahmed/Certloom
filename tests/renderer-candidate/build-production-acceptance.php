<?php
/** Canonically derive RendererAcceptanceContract evidence from verified PDF-04 production evidence. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/acceptance-evidence-lib.php';

if ( 6 !== $argc ) {
	fwrite( STDERR, "usage: build-production-acceptance.php BUNDLE_DIR VERIFICATION-REPORT.json VERIFIER-ENVIRONMENT.json VERIFICATION-INDEX.json OUTPUT.json\n" );
	exit( 2 );
}

try {
	$builder = new CertloomProductionAcceptanceEvidenceBuilder( dirname( __DIR__, 2 ) );
	$evidence = $builder->build( $argv[1], $argv[2], $argv[3], $argv[4], $argv[5] );
	$contract = new Certloom\Application\Rendering\RendererAcceptanceContract();
	echo json_encode( array(
		'schema' => 'certloom-renderer-acceptance-build-result',
		'version' => 1,
		'plugin_version' => $evidence['plugin_version'],
		'acceptance_sha256' => $contract->hash( $evidence ),
		'output' => $argv[5],
	), JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 3 );
}
