<?php
/** Evidence/privacy regression tests for staging populated migration rehearsal. */
namespace {
    define( 'ABSPATH', __DIR__ . '/fake-wp/' );
    define( 'DAY_IN_SECONDS', 86400 );
    define( 'MINUTE_IN_SECONDS', 60 );
    define( 'ECAPLA_CERTVERIFY_VERSION', '1.5.14-test' );
    define( 'CERTLOOM_ALLOW_STAGING_MIGRATION_REHEARSAL', true );
    $GLOBALS['smr_options'] = array();
    $GLOBALS['smr_source'] = array( 'count' => 2, 'max_id' => 9, 'generation' => 'legacy-gen-a' );
    $GLOBALS['smr_target_generation'] = 'domain-gen-a';
    $GLOBALS['smr_migration_status'] = 'complete';
    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error { private $c; private $m; public function __construct($c='',$m=''){ $this->c=$c; $this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
    }
    function is_wp_error( $v ) { return $v instanceof WP_Error; }
    function __( $t ) { return $t; }
    function absint( $v ) { return abs( (int) $v ); }
    function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
    function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
    function wp_get_environment_type() { return 'staging'; }
    function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['smr_options'] ) ? $GLOBALS['smr_options'][ $key ] : $default; }
    function update_option( $key, $value, $autoload = null ) { $GLOBALS['smr_options'][ $key ] = $value; return true; }
    function smr_same( $e, $a, $m ) { if ( $e !== $a ) { throw new \RuntimeException( $m . ': expected ' . var_export($e,true) . ', got ' . var_export($a,true) ); } }
}

namespace Certloom\Infrastructure\Repository {
    final class LegacyCertificateRepository { public function snapshot() { return $GLOBALS['smr_source']; } }
}
namespace Certloom\Infrastructure\Data {
    final class DomainGeneration { public function current() { return (string) $GLOBALS['smr_target_generation']; } }
    final class DomainMaintenanceLock {}
}
namespace Certloom\Application\Migration {
    final class LegacyMigrationState { const COMPLETE = 'complete'; public function status(){ return (string) $GLOBALS['smr_migration_status']; } }
    final class DomainCutoverState {}
    final class LegacyCertificateMapper {}
    final class LegacyMigrationPreflightService {}
    final class LegacySourceFingerprint {}
}
namespace Certloom\Infrastructure\Database { final class SchemaManager {} }
namespace Certloom\Infrastructure\Migration { final class LegacyMigrationCoordinator {} final class LegacyMigrationWriter {} }
namespace Certloom\Infrastructure\Repository { final class CredentialRepository {} final class EventRepository {} final class RecipientRepository {} }
namespace Certloom\Application { final class CredentialVerificationService {} }
namespace Certloom\Infrastructure\Diagnostics {
    final class LegacyMigrationPreflightProof {}
    final class DomainAcceptanceProof {}
}

namespace {
    require dirname( __DIR__ ) . '/src/Infrastructure/Diagnostics/StagingMigrationRehearsal.php';
    use Certloom\Infrastructure\Diagnostics\StagingMigrationRehearsal;

    smr_same( true, StagingMigrationRehearsal::available(), 'staging rehearsal requires allowed environment plus explicit opt-in' );
    $now = time();
    $GLOBALS['smr_options'][ StagingMigrationRehearsal::RESULT_OPTION ] = array(
        'schema' => StagingMigrationRehearsal::RESULT_SCHEMA,
        'version' => StagingMigrationRehearsal::RESULT_VERSION,
        'plugin_version' => ECAPLA_CERTVERIFY_VERSION,
        'environment' => 'staging',
        'status' => 'passed',
        'phase' => 'complete',
        'reason_code' => '',
        'started_at' => $now - 10,
        'updated_at' => $now,
        'checked_at' => $now,
        'source_count' => 2,
        'source_max_id' => 9,
        'source_generation' => 'legacy-gen-a',
        'source_fingerprint' => str_repeat( 'a', 64 ),
        'preflight_date' => '2026-08-22',
        'official_preflight_total' => 2,
        'official_verify_total' => 2,
        'migrated' => 2,
        'skipped' => 0,
        'verify_cursor' => 9,
        'verified' => 2,
        'verify_source_fingerprint' => str_repeat( 'a', 64 ),
        'target_generation' => 'domain-gen-a',
        'idempotency_cursor' => 9,
        'idempotency_checked' => 2,
        'errors' => 0,
        'error_examples' => array(),
    );
    smr_same( 'passed', StagingMigrationRehearsal::evidence_state(), 'fresh coherent staging evidence is green' );
    $report = StagingMigrationRehearsal::get_text_report();
    if ( false !== strpos( $report, 'CERT-SECRET' ) || false !== strpos( $report, '/var/www' ) ) { throw new \RuntimeException( 'Staging report leaked sensitive fixture data.' ); }
    if ( false === strpos( $report, 'Credential authority changed: NO' ) ) { throw new \RuntimeException( 'Staging report lost explicit no-cutover statement.' ); }
    $GLOBALS['smr_source']['generation'] = 'legacy-gen-b';
    smr_same( 'stale', StagingMigrationRehearsal::evidence_state(), 'source generation change stales rehearsal evidence' );
    $GLOBALS['smr_source']['generation'] = 'legacy-gen-a';
    $GLOBALS['smr_target_generation'] = 'domain-gen-b';
    smr_same( 'stale', StagingMigrationRehearsal::evidence_state(), 'target generation change stales rehearsal evidence' );
    $GLOBALS['smr_target_generation'] = 'domain-gen-a';
    $GLOBALS['smr_migration_status'] = 'blocked';
    smr_same( 'stale', StagingMigrationRehearsal::evidence_state(), 'migration state regression stales rehearsal evidence' );

    echo "PASS  staging migration rehearsal evidence is privacy-safe, source-bound and target-bound\n\n1 test, 0 failures\n";
}
