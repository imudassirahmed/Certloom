<?php
/**
 * Development-only PDF-04 readiness probe.
 *
 * It does not install dependencies, execute external processes, mutate
 * WordPress, or claim production acceptance. It only records whether the
 * currently preferred PHP-7.4-compatible benchmark path can be exercised.
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

$result = array(
	'schema' => 'certloom-renderer-runtime-probe',
	'version' => 1,
	'candidate' => 'mpdf-svg-pdf',
	'php_version' => PHP_VERSION,
	'ready_for_candidate_execution' => empty( $missing ),
	'missing' => $missing,
	'note' => 'Readiness only. PDF-04 still requires permanent fixture, parity, exact-font, no-network and deterministic-regeneration evidence.',
);
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
