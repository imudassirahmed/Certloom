<?php
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\RuntimePayloadFingerprint;
use Certloom\Infrastructure\Diagnostics\RuntimePayloadEvidence;

$checks = 0;
function drpe_check( $condition, $message ) {
	global $checks;
	$checks++;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$current = RuntimePayloadEvidence::current();
drpe_check( is_array( $current ), 'Current runtime payload evidence must be available.' );
drpe_check( RuntimePayloadFingerprint::SCHEMA === (string) ( $current['schema'] ?? '' ), 'Database evidence must reuse the canonical runtime-payload schema.' );
drpe_check( RuntimePayloadFingerprint::VERSION === (int) ( $current['version'] ?? 0 ), 'Database evidence must reuse the canonical runtime-payload version.' );
drpe_check( 1 === preg_match( '/^[0-9a-f]{64}$/', (string) ( $current['sha256'] ?? '' ) ), 'Runtime payload SHA-256 must be canonical.' );
drpe_check( (int) ( $current['file_count'] ?? 0 ) > 0, 'Runtime payload must bind at least one production file.' );
drpe_check( (int) ( $current['total_bytes'] ?? 0 ) > 0, 'Runtime payload must bind non-empty production bytes.' );
drpe_check( $current === RuntimePayloadEvidence::normalize( $current ), 'Normalized runtime evidence must round-trip exactly.' );
drpe_check( RuntimePayloadEvidence::matches_current( $current ), 'Current payload evidence must match the exact installed production bytes.' );
drpe_check( null === RuntimePayloadEvidence::normalize( null ), 'Non-array runtime evidence must fail closed.' );
$bad_shape = $current; $bad_shape['unexpected'] = true;
drpe_check( null === RuntimePayloadEvidence::normalize( $bad_shape ), 'Unexpected runtime evidence fields must fail closed.' );
$bad_hash = $current; $bad_hash['sha256'] = str_repeat( '0', 64 );
drpe_check( ! RuntimePayloadEvidence::matches_current( $bad_hash ), 'A same-version production hash change must invalidate stored evidence.' );
$bad_count = $current; $bad_count['file_count'] = (int) $bad_count['file_count'] + 1;
drpe_check( ! RuntimePayloadEvidence::matches_current( $bad_count ), 'A production file-count change must invalidate stored evidence.' );
$bad_bytes = $current; $bad_bytes['total_bytes'] = (int) $bad_bytes['total_bytes'] + 1;
drpe_check( ! RuntimePayloadEvidence::matches_current( $bad_bytes ), 'A production byte-count change must invalidate stored evidence.' );

$root = dirname( __DIR__ );
$sources = array(
	'database' => file_get_contents( $root . '/includes/class-ecapla-certverify-database-proof.php' ),
	'domain' => file_get_contents( $root . '/src/Infrastructure/Diagnostics/DomainAcceptanceProof.php' ),
	'preflight' => file_get_contents( $root . '/src/Infrastructure/Diagnostics/LegacyMigrationPreflightProof.php' ),
	'migration' => file_get_contents( $root . '/src/Infrastructure/Diagnostics/StagingMigrationRehearsal.php' ),
	'restore' => file_get_contents( $root . '/src/Infrastructure/Diagnostics/StagingRestoreRehearsal.php' ),
	'cutover' => file_get_contents( $root . '/src/Infrastructure/Diagnostics/StagingCutoverRehearsal.php' ),
);
foreach ( $sources as $name => $source ) {
	drpe_check( is_string( $source ) && false !== strpos( $source, "'runtime_payload'" ) && false !== strpos( $source, 'RuntimePayloadEvidence::matches_current' ), ucfirst( $name ) . ' evidence must persist and revalidate exact runtime payload identity.' );
	drpe_check( false !== strpos( $source, "'runtime_environment'" ) && false !== strpos( $source, 'RuntimeEnvironmentEvidence::matches_current' ), ucfirst( $name ) . ' evidence must persist and revalidate exact runtime environment identity.' );
}
drpe_check( false !== strpos( $sources['preflight'], "'runtime_payload_changed'" ), 'Migration preflight must stop if production bytes change mid-run.' );
drpe_check( false !== strpos( $sources['preflight'], "'runtime_environment_changed'" ), 'Migration preflight must stop if the WordPress/PHP/database environment changes mid-run.' );
drpe_check( false !== strpos( $sources['migration'], 'certloom_staging_rehearsal_runtime_payload_changed' ), 'Staging migration must stop if production bytes change mid-run.' );
drpe_check( false !== strpos( $sources['restore'], 'certloom_staging_restore_runtime_payload_changed' ), 'Staging restore must stop if production bytes change mid-run.' );
drpe_check( false !== strpos( $sources['cutover'], 'certloom_staging_cutover_runtime_payload_changed' ), 'Staging cutover must stop if production bytes change mid-run.' );
drpe_check( false !== strpos( $sources['migration'], 'runtime_environment_changed' ) && false !== strpos( $sources['restore'], 'runtime_environment_changed' ) && false !== strpos( $sources['cutover'], 'runtime_environment_changed' ), 'All multi-slice staging rehearsals must stop if the runtime/database environment changes mid-run.' );
drpe_check( false !== strpos( $sources['restore'], "['domain_generation']" ) && false !== strpos( $sources['restore'], 'new DomainGeneration()' ) && false !== strpos( $sources['cutover'], "['domain_generation']" ) && false !== strpos( $sources['cutover'], 'new DomainGeneration()' ), 'Restore and cutover evidence must stale after any later normalized-domain generation change.' );
drpe_check( false !== strpos( $sources['database'], 'const RESULT_VERSION = 4' ), 'Isolated database-proof evidence schema must advance for payload binding.' );
drpe_check( false !== strpos( $sources['domain'], 'const RESULT_VERSION = 7' ), 'Gated-domain acceptance evidence schema must advance for payload binding.' );
drpe_check( false !== strpos( $sources['preflight'], 'const RESULT_VERSION = 4' ), 'Migration-preflight evidence schema must advance for payload binding.' );
drpe_check( false !== strpos( $sources['migration'], 'const RESULT_VERSION = 4' ) && false !== strpos( $sources['restore'], 'const RESULT_VERSION = 5' ) && false !== strpos( $sources['cutover'], 'const RESULT_VERSION = 5' ), 'All staging rehearsal evidence schemas must advance for payload binding.' );
$gate_helper = file_get_contents( $root . '/tests/wp-integration/gate-evidence.php' );
drpe_check( is_string( $gate_helper ) && false !== strpos( $gate_helper, 'RuntimePayloadEvidence::current()' ) && false !== strpos( $gate_helper, "'certloom-wp-integration-gate-evidence'" ), 'Real WordPress/MySQL gate transcripts must bind the exact Certloom payload.' );
$environment_source = file_get_contents( $root . '/src/Infrastructure/Diagnostics/RuntimeEnvironmentEvidence.php' );
drpe_check( is_string( $environment_source ) && false !== strpos( $environment_source, 'const VERSION = 2' ) && false !== strpos( $environment_source, "'database_instance_sha256'" ) && false !== strpos( $environment_source, "'db_host'" ) && false !== strpos( $environment_source, "'db_name'" ) && false !== strpos( $environment_source, "'db_user'" ) && false !== strpos( $environment_source, "'table_prefix'" ), 'Runtime-environment evidence v2 must bind a privacy-safe configured database/table instance identity.' );
foreach ( array( 'run-numbering-policy.php', 'run-domain-schema.php', 'run-domain-premium-gate.php', 'run-domain-row-lock-gate.php' ) as $gate_file ) {
	$gate_source = file_get_contents( $root . '/tests/wp-integration/' . $gate_file );
	drpe_check( is_string( $gate_source ) && false !== strpos( $gate_source, "require_once __DIR__ . '/gate-evidence.php';" ) && false !== strpos( $gate_source, 'certloom_wp_integration_evidence(' ), 'Real integration gate ' . $gate_file . ' must emit exact-byte-bound evidence.' );
}

echo "PASS  Normalized-domain database evidence is bound to the exact Certloom production payload across proof, preflight, migration, restore and cutover\n\n{$checks} checks, 0 failures\n";
