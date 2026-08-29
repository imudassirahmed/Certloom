<?php
if ( ! defined( 'ABSPATH' ) || ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	fwrite( STDERR, "Run through WP-CLI inside WordPress.\n" );
	exit( 2 );
}

require_once __DIR__ . '/domain-acceptance-bundle-lib.php';

function certloom_db_acceptance_external_path( $path, $exists ) {
	$path = trim( (string) $path );
	if ( '' === $path || preg_match( '#(^|[\\/])\.\.?(?:[\\/]|$)#', $path ) ) {
		throw new RuntimeException( 'Database acceptance path is empty or ambiguous.' );
	}
	$root = realpath( defined( 'CERTLOOM_PATH' ) ? CERTLOOM_PATH : dirname( __DIR__, 2 ) );
	if ( false === $root ) {
		throw new RuntimeException( 'Could not resolve Certloom source root.' );
	}
	if ( $exists ) {
		$real = realpath( $path );
		if ( false === $real || ! is_dir( $real ) || is_link( $path ) ) {
			throw new RuntimeException( 'Database acceptance input is unavailable or symbolic.' );
		}
	} else {
		if ( file_exists( $path ) || is_link( $path ) ) {
			throw new RuntimeException( 'Database acceptance output path must be new.' );
		}
		$parent = realpath( dirname( $path ) );
		if ( false === $parent || ! is_dir( $parent ) ) {
			throw new RuntimeException( 'Database acceptance output parent is unavailable.' );
		}
		$real = rtrim( $parent, '/\\' ) . DIRECTORY_SEPARATOR . basename( $path );
	}
	if ( $real === $root || 0 === strpos( $real . DIRECTORY_SEPARATOR, rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
		throw new RuntimeException( 'Database acceptance evidence must remain outside the Certloom source tree.' );
	}
	return $real;
}

/** @return array<string,array<string,mixed>> */
function certloom_db_collect_stored_evidence( array $providers, array $payload, array $environment ) {
	$stored = array();
	foreach ( $providers as $name => $class ) {
		if ( ! class_exists( $class ) || ! method_exists( $class, 'evidence_state' ) || ! method_exists( $class, 'get_last_result' ) || ! method_exists( $class, 'get_text_report' ) ) {
			throw new RuntimeException( 'Required normalized-domain evidence provider is unavailable: ' . $name );
		}
		$state  = (string) call_user_func( array( $class, 'evidence_state' ) );
		$result = call_user_func( array( $class, 'get_last_result' ) );
		$report = (string) call_user_func( array( $class, 'get_text_report' ), $result );
		if (
			'passed' !== $state
			|| ! is_array( $result )
			|| ! \Certloom\Infrastructure\Diagnostics\RuntimePayloadEvidence::matches_current( $result['runtime_payload'] ?? null )
			|| ! \Certloom\Infrastructure\Diagnostics\RuntimeEnvironmentEvidence::matches_current( $result['runtime_environment'] ?? null )
			|| ! certloom_db_payload_equals( $payload, $result['runtime_payload'] ?? null )
		) {
			throw new RuntimeException( 'Fresh passing normalized-domain evidence is required: ' . $name );
		}
		$stored_environment = $result['runtime_environment'];
		if ( isset( $stored_environment['schema'], $stored_environment['version'] ) ) {
			unset( $stored_environment['schema'], $stored_environment['version'] );
		}
		if ( certloom_db_canonical_json( $stored_environment ) !== certloom_db_canonical_json( $environment ) ) {
			throw new RuntimeException( 'Fresh normalized-domain evidence belongs to a different runtime/database environment: ' . $name );
		}
		$stored[ $name ] = array( 'state' => $state, 'result' => $result, 'report' => $report );
	}
	return $stored;
}

/** Re-read immutable provider bytes without asking evidence_state(), which intentionally stales while maintenance is active. */
function certloom_db_assert_stored_evidence_unchanged( array $providers, array $frozen ) {
	foreach ( $providers as $name => $class ) {
		if ( ! isset( $frozen[ $name ] ) || ! class_exists( $class ) || ! method_exists( $class, 'get_last_result' ) || ! method_exists( $class, 'get_text_report' ) ) {
			throw new RuntimeException( 'Frozen normalized-domain evidence provider is unavailable: ' . $name );
		}
		$result = call_user_func( array( $class, 'get_last_result' ) );
		$report = (string) call_user_func( array( $class, 'get_text_report' ), $result );
		if ( ! is_array( $result ) || certloom_db_canonical_json( $result ) !== certloom_db_canonical_json( $frozen[ $name ]['result'] ) || ! hash_equals( (string) $frozen[ $name ]['report'], $report ) ) {
			throw new RuntimeException( 'Normalized-domain evidence changed during final acceptance publication: ' . $name );
		}
	}
	return true;
}

function certloom_db_remove_private_stage( $stage ) {
	if ( ! is_string( $stage ) || '' === $stage || ! is_dir( $stage ) || is_link( $stage ) ) { return; }
	foreach ( glob( $stage . '/*' ) ?: array() as $leaf ) {
		if ( is_file( $leaf ) && ! is_link( $leaf ) ) { @unlink( $leaf ); }
	}
	@rmdir( $stage );
}

/** Atomically removes the canonical success path first; hidden quarantine cleanup is best-effort. */
function certloom_db_withdraw_published_acceptance( $out_dir ) {
	if ( ! is_dir( $out_dir ) || is_link( $out_dir ) ) { return ! file_exists( $out_dir ); }
	$parent = dirname( $out_dir );
	try { $suffix = bin2hex( random_bytes( 12 ) ); } catch ( Throwable $e ) { $suffix = hash( 'sha256', $out_dir . microtime( true ) ); }
	$quarantine = $parent . DIRECTORY_SEPARATOR . '.certloom-db-acceptance-withdrawn.' . $suffix;
	if ( ! rename( $out_dir, $quarantine ) ) { return false; }
	certloom_db_remove_private_stage( $quarantine );
	return ! file_exists( $out_dir );
}

$stage     = '';
$out_dir   = '';
$owner     = '';
$published = false;
$index     = null;
$failure   = null;

try {
	$base_dir = certloom_db_acceptance_external_path( getenv( 'CERTLOOM_DB_BASE_GATE_DIR' ), true );
	$out_dir  = certloom_db_acceptance_external_path( getenv( 'CERTLOOM_DB_ACCEPTANCE_DIR' ), false );
	$base     = certloom_db_validate_base_gate_bundle( $base_dir, __DIR__ );
	$payload  = \Certloom\Infrastructure\Diagnostics\RuntimePayloadEvidence::current();
	if ( ! is_array( $payload ) ) { throw new RuntimeException( 'Could not fingerprint current Certloom production payload.' ); }
	$version     = defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '';
	$environment = certloom_db_environment_snapshot();
	$providers   = array(
		'database_proof'      => 'Ecapla_CertVerify_Database_Proof',
		'domain_acceptance'   => '\\Certloom\\Infrastructure\\Diagnostics\\DomainAcceptanceProof',
		'migration_preflight' => '\\Certloom\\Infrastructure\\Diagnostics\\LegacyMigrationPreflightProof',
		'staging_migration'   => '\\Certloom\\Infrastructure\\Diagnostics\\StagingMigrationRehearsal',
		'staging_restore'     => '\\Certloom\\Infrastructure\\Diagnostics\\StagingRestoreRehearsal',
		'staging_cutover'     => '\\Certloom\\Infrastructure\\Diagnostics\\StagingCutoverRehearsal',
	);

	// Evidence providers must be currently passed before the collector creates its own maintenance boundary.
	$stored = certloom_db_collect_stored_evidence( $providers, $payload, $environment );

	// Acquire once, fingerprint the entire graph, and deliberately retain the same owner-matched lock through publication.
	$retained = certloom_db_current_domain_fingerprint_retained();
	$owner    = (string) $retained['owner_token'];

	// Revalidate every externally mutable identity after the retained publication boundary exists.
	$locked_base = certloom_db_validate_base_gate_bundle( $base_dir, __DIR__ );
	if ( certloom_db_canonical_json( $locked_base ) !== certloom_db_canonical_json( $base ) ) {
		throw new RuntimeException( 'Base-gate evidence changed after final acceptance publication began.' );
	}
	$locked_payload = \Certloom\Infrastructure\Diagnostics\RuntimePayloadEvidence::current();
	if ( ! is_array( $locked_payload ) || ! certloom_db_payload_equals( $payload, $locked_payload ) ) {
		throw new RuntimeException( 'Certloom runtime payload changed during final acceptance publication.' );
	}
	$locked_environment = certloom_db_environment_snapshot();
	if ( certloom_db_canonical_json( $locked_environment ) !== certloom_db_canonical_json( $environment ) ) {
		throw new RuntimeException( 'WordPress/PHP/database environment changed during final acceptance publication.' );
	}
	certloom_db_assert_stored_evidence_unchanged( $providers, $stored );
	certloom_db_revalidate_retained_domain_boundary( $retained );

	$bundle = certloom_db_build_final_acceptance( $locked_base, $version, $locked_payload, $locked_environment, $stored, $retained['domain_state'], time() );
	$parent = dirname( $out_dir );
	$stage  = $parent . DIRECTORY_SEPARATOR . '.certloom-db-acceptance.' . bin2hex( random_bytes( 12 ) );
	if ( ! mkdir( $stage, 0700 ) ) { throw new RuntimeException( 'Could not create private database-acceptance staging directory.' ); }

	$json = certloom_db_canonical_json( $bundle ) . "\n";
	$file = $stage . '/normalized-domain-acceptance.json';
	if ( false === file_put_contents( $file, $json, LOCK_EX ) ) { throw new RuntimeException( 'Could not write normalized-domain acceptance evidence.' ); }
	if ( ! chmod( $file, 0600 ) ) { throw new RuntimeException( 'Could not protect normalized-domain acceptance evidence permissions.' ); }

	$index = array(
		'schema'              => 'certloom-normalized-domain-acceptance-index',
		'version'             => 5,
		'plugin_version'      => $version,
		'runtime_payload'     => $locked_payload,
		'environment_sha256'  => hash( 'sha256', certloom_db_canonical_json( $locked_environment ) ),
		'domain_state_sha256' => $bundle['domain_state_sha256'],
		'acceptance_sha256'   => hash( 'sha256', $json ),
		'acceptance_bytes'    => strlen( $json ),
	);
	$index_json = certloom_db_canonical_json( $index ) . "\n";
	$index_file = $stage . '/acceptance-index.json';
	if ( false === file_put_contents( $index_file, $index_json, LOCK_EX ) ) { throw new RuntimeException( 'Could not write normalized-domain acceptance index.' ); }
	if ( ! chmod( $index_file, 0600 ) ) { throw new RuntimeException( 'Could not protect normalized-domain acceptance index permissions.' ); }

	// Final pre-rename check: same lock owner, same graph/config bounds, same payload/environment, same evidence bytes.
	certloom_db_revalidate_retained_domain_boundary( $retained );
	$final_payload = \Certloom\Infrastructure\Diagnostics\RuntimePayloadEvidence::current();
	if ( ! is_array( $final_payload ) || ! certloom_db_payload_equals( $locked_payload, $final_payload ) ) {
		throw new RuntimeException( 'Certloom runtime payload changed immediately before acceptance publication.' );
	}
	$final_environment = certloom_db_environment_snapshot();
	if ( certloom_db_canonical_json( $final_environment ) !== certloom_db_canonical_json( $locked_environment ) ) {
		throw new RuntimeException( 'Runtime/database environment changed immediately before acceptance publication.' );
	}
	certloom_db_assert_stored_evidence_unchanged( $providers, $stored );

	if ( ! rename( $stage, $out_dir ) ) { throw new RuntimeException( 'Could not atomically publish normalized-domain acceptance evidence.' ); }
	$stage     = '';
	$published = true;
} catch ( Throwable $e ) {
	$failure = $e;
}

if ( '' !== $stage ) { certloom_db_remove_private_stage( $stage ); }

if ( '' !== $owner ) {
	try {
		certloom_db_release_retained_domain_boundary( $owner );
	} catch ( Throwable $cleanup_error ) {
		$failure = new RuntimeException(
			( $failure ? $failure->getMessage() . ' ' : '' )
			. 'Maintenance-lock cleanup failed: ' . $cleanup_error->getMessage()
		);
	}
}

if ( $failure ) {
	if ( $published && ! certloom_db_withdraw_published_acceptance( $out_dir ) ) {
		$failure = new RuntimeException( $failure->getMessage() . ' Published acceptance evidence could not be withdrawn safely.' );
	}
	fwrite( STDERR, 'FAIL  ' . $failure->getMessage() . "\n" );
	exit( 1 );
}

if ( ! $published || ! is_array( $index ) ) {
	fwrite( STDERR, "FAIL  normalized-domain acceptance publication did not complete.\n" );
	exit( 1 );
}

echo "PASS  normalized-domain acceptance bundle published atomically under retained maintenance boundary\n";
echo 'PASS  acceptance SHA-256: ' . $index['acceptance_sha256'] . "\n";
