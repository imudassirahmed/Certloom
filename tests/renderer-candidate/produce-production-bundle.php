<?php
/** Produce a two-run seven-fixture PDF-04 production-candidate bundle. */
require_once __DIR__ . '/producer-lib.php';

if ( 3 !== $argc ) {
	fwrite( STDERR, "usage: php produce-production-bundle.php BOOTSTRAP.php OUTPUT_DIR\n" );
	fwrite( STDERR, "BOOTSTRAP.php must return the source-only candidate config documented in the current renderer production-candidate execution contract\n" );
	exit( 2 );
}
$bootstrap = realpath( $argv[1] );
if ( false === $bootstrap || ! is_file( $bootstrap ) || is_link( $bootstrap ) ) { fwrite( STDERR, "candidate bootstrap must be a readable non-symlink PHP file\n" ); exit( 3 ); }
try {
	$config = require $bootstrap;
	if ( ! is_array( $config ) ) { throw new InvalidArgumentException( 'Candidate bootstrap must return an array.' ); }
	$producer = new CertloomProductionCandidateBundleProducer( dirname( __DIR__, 2 ) );
	$manifest = $producer->produce( $argv[2], $config, 'production-candidate' );
	$bundle = rtrim( $argv[2], '/\\' ) . '/bundle.json';
	$result = array(
		'schema' => 'certloom-renderer-candidate-production-result',
		'version' => 1,
		'qualification' => $manifest['qualification'],
		'plugin_version' => $manifest['plugin_version'],
		'bundle_manifest_sha256' => hash_file( 'sha256', $bundle ),
		'output_dir' => realpath( $argv[2] ),
	);
	echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 4 );
}
