<?php
/** Independently validate one ArtifactReceipt v4 against actual bundle bytes and frozen identities. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\ArtifactReceipt;

if ( 10 !== $argc ) {
	fwrite( STDERR, "usage: verify-receipt.php RECEIPT.json ARTIFACT FORMAT RENDERER_ID RENDERER_VERSION CONTEXT_SHA256 LAYOUT_SHA256 REQUEST_SHA256 PLAN_SHA256\n" );
	exit( 2 );
}
list( $script, $receipt_path, $artifact_path, $format, $renderer_id, $renderer_version, $context_hash, $layout_hash, $request_hash, $plan_hash ) = $argv;
$receipt_raw = @file_get_contents( $receipt_path );
$bytes = @file_get_contents( $artifact_path );
$receipt = is_string( $receipt_raw ) ? json_decode( $receipt_raw, true ) : null;
if ( ! is_array( $receipt ) || ! is_string( $bytes ) ) {
	fwrite( STDERR, "receipt/artifact input is unreadable\n" );
	exit( 3 );
}
$verifier = new ArtifactReceipt();
if ( ! $verifier->verify( $receipt, $bytes ) ) {
	fwrite( STDERR, "ArtifactReceipt integrity verification failed\n" );
	exit( 4 );
}
$expected = array(
	'format' => strtolower( $format ),
	'renderer_id' => $renderer_id,
	'renderer_version' => $renderer_version,
	'renderer_context_hash' => strtolower( $context_hash ),
	'layout_hash' => strtolower( $layout_hash ),
	'request_hash' => strtolower( $request_hash ),
	'render_plan_hash' => strtolower( $plan_hash ),
);
foreach ( $expected as $key => $value ) {
	if ( ! array_key_exists( $key, $receipt ) || ! hash_equals( (string) $value, (string) $receipt[ $key ] ) ) {
		fwrite( STDERR, "ArtifactReceipt identity mismatch: {$key}\n" );
		exit( 5 );
	}
}
echo json_encode( array( 'schema' => 'certloom-artifact-receipt-validation', 'version' => 1, 'receipt_hash' => $receipt['receipt_hash'], 'artifact_sha256' => $receipt['sha256'] ), JSON_UNESCAPED_SLASHES ) . "\n";
