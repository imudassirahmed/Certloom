<?php

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';
/**
 * Development-only PDF-04 readiness probe.
 *
 * It does not install dependencies, execute external processes, mutate
 * WordPress, or claim production acceptance. It only records whether the
 * currently concrete PHP-7.4-compatible candidate path can be exercised.
 */
$missing = array();
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	$missing[] = 'PHP>=7.4';
}
foreach ( array( 'mbstring', 'gd' ) as $extension ) {
	if ( ! extension_loaded( $extension ) ) {
		$missing[] = 'ext:' . $extension;
	}
}
$prefixed_class = 'CertloomVendor\\Mpdf\\Mpdf';
if ( ! class_exists( $prefixed_class, false ) ) {
	$missing[] = 'bundled-prefixed-library:' . $prefixed_class;
}

$adapter_classes = array( 'Certloom\\Infrastructure\\Rendering\\MpdfPdfRenderer', 'Certloom\\Infrastructure\\Rendering\\GdRasterRenderer' );
$missing_adapters = array();
foreach ( $adapter_classes as $adapter_class ) { if ( ! class_exists( $adapter_class ) ) { $missing_adapters[] = $adapter_class; } }
$result = array(
	'schema' => 'certloom-renderer-runtime-probe',
	'version' => 1,
	'candidate' => 'certloom-mpdf-gd-render-plan-v1',
	'php_version' => PHP_VERSION,
	'candidate_adapters_available' => empty( $missing_adapters ),
	'missing_candidate_adapters' => $missing_adapters,
	'ready_for_candidate_execution' => empty( $missing ) && empty( $missing_adapters ),
	'missing' => $missing,
	'note' => 'Readiness only. PDF-04 still requires permanent fixture, parity, exact-font, no-network and deterministic-regeneration evidence.',
);
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
