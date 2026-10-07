<?php
/** Normalize one independent renderer verification report and print canonical SHA-256. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\RendererVerificationReport;

if ( 2 !== $argc ) { fwrite( STDERR, "usage: validate-verification-report.php REPORT.json\n" ); exit( 2 ); }
$raw = @file_get_contents( $argv[1] );
$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
if ( ! is_array( $data ) ) { fwrite( STDERR, "verification report is not valid JSON\n" ); exit( 3 ); }
try {
	$contract = new RendererVerificationReport();
	$normalized = $contract->normalize( $data );
	echo json_encode( array( 'schema' => 'certloom-renderer-verification-report-validation', 'version' => 1, 'verification_report_sha256' => $contract->hash( $normalized ), 'qualification' => $normalized['qualification'], 'qualifies_pdf04' => $normalized['qualifies_pdf04'], 'verifier_environment_sha256' => $normalized['verifier_environment_sha256'] ), JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 4 );
}
