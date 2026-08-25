<?php
/**
 * Shared evidence header for Certloom's real WordPress/MySQL integration gates.
 *
 * The copied console transcript is useful only if it is bound to both the exact
 * production-intended Certloom payload and the exact source-side gate bytes that
 * produced it. Keep this helper source-only; it does not participate in runtime
 * credential authority.
 */
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run inside WordPress.\n" );
	exit( 1 );
}

/** @return array<string,mixed> */
function certloom_wp_integration_evidence( $gate_id, $gate_file ) {
	$gate_id = strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $gate_id ) );
	$gate_real = realpath( (string) $gate_file );
	$helper_real = realpath( __FILE__ );
	if ( '' === $gate_id || false === $gate_real || ! is_file( $gate_real ) || ! is_readable( $gate_real ) || false === $helper_real ) {
		throw new RuntimeException( 'Certloom could not establish the real integration-gate evidence boundary.' );
	}
	$payload = \Certloom\Infrastructure\Diagnostics\RuntimePayloadEvidence::current();
	if ( ! is_array( $payload ) ) {
		throw new RuntimeException( 'Certloom could not fingerprint the exact installed production payload for the integration gate.' );
	}
	$gate_hash = hash_file( 'sha256', $gate_real );
	$helper_hash = hash_file( 'sha256', $helper_real );
	if ( ! is_string( $gate_hash ) || 64 !== strlen( $gate_hash ) || ! is_string( $helper_hash ) || 64 !== strlen( $helper_hash ) ) {
		throw new RuntimeException( 'Certloom could not hash the integration-gate source bytes.' );
	}
	$evidence = array(
		'schema' => 'certloom-wp-integration-gate-evidence',
		'version' => 1,
		'gate' => $gate_id,
		'plugin_version' => defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '',
		'runtime_payload' => $payload,
		'gate_sha256' => strtolower( $gate_hash ),
		'helper_sha256' => strtolower( $helper_hash ),
	);
	if ( '' === $evidence['plugin_version'] ) {
		throw new RuntimeException( 'Certloom plugin version is unavailable for integration-gate evidence.' );
	}
	echo "EVIDENCE  certloom-wp-integration-gate-evidence v1\n";
	echo 'EVIDENCE  gate: ' . $evidence['gate'] . "\n";
	echo 'EVIDENCE  Certloom version: ' . $evidence['plugin_version'] . "\n";
	echo 'EVIDENCE  Certloom payload SHA-256: ' . $payload['sha256'] . "\n";
	echo 'EVIDENCE  Certloom payload files: ' . (int) $payload['file_count'] . "\n";
	echo 'EVIDENCE  Certloom payload bytes: ' . (int) $payload['total_bytes'] . "\n";
	echo 'EVIDENCE  gate SHA-256: ' . $evidence['gate_sha256'] . "\n";
	echo 'EVIDENCE  evidence-helper SHA-256: ' . $evidence['helper_sha256'] . "\n";
	return $evidence;
}
