<?php
/** Behavioral regression for exact WordPress/PHP/database environment evidence. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DB_NAME', 'certloom_test_db' );
define( 'DB_HOST', 'db.test.local' );
define( 'DB_USER', 'certloom_test_user' );
$GLOBALS['wp_version'] = '6.8.2-test';
$GLOBALS['dre_env_type'] = 'staging';
$GLOBALS['dre_multisite'] = false;
$GLOBALS['dre_db_info'] = 'MySQL 8.0.42 test-server';
$GLOBALS['dre_db_version'] = '8.0.42';

function wp_get_environment_type() { return (string) $GLOBALS['dre_env_type']; }
function is_multisite() { return (bool) $GLOBALS['dre_multisite']; }
$GLOBALS['wpdb'] = new class {
	public $prefix = 'secret_prefix_9384_';
	public function db_server_info() { return (string) $GLOBALS['dre_db_info']; }
	public function db_version() { return (string) $GLOBALS['dre_db_version']; }
};

require_once dirname( __DIR__ ) . '/src/Infrastructure/Diagnostics/RuntimeEnvironmentEvidence.php';
use Certloom\Infrastructure\Diagnostics\RuntimeEnvironmentEvidence;

$checks = 0;
function dre_check( $condition, $message ) {
	global $checks;
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$current = RuntimeEnvironmentEvidence::current();
dre_check( is_array( $current ), 'Current runtime environment evidence must be available.' );
dre_check( RuntimeEnvironmentEvidence::SCHEMA === (string) $current['schema'], 'Environment schema must be canonical.' );
dre_check( RuntimeEnvironmentEvidence::VERSION === (int) $current['version'], 'Environment version must be canonical.' );
dre_check( '6.8.2-test' === $current['wp_version'], 'WordPress version must be captured.' );
dre_check( PHP_VERSION === $current['php_version'], 'PHP version must be captured.' );
dre_check( 'staging' === $current['wp_environment_type'], 'WordPress environment type must be captured.' );
dre_check( 'MySQL 8.0.42 test-server' === $current['db_server_info'], 'Database server identity must be captured.' );
dre_check( '8.0.42' === $current['db_version'], 'Database version must be captured.' );
dre_check( 1 === preg_match( '/^[a-f0-9]{64}$/', $current['database_instance_sha256'] ), 'Configured database/site instance must be bound through a privacy-safe SHA-256 identity.' );
dre_check( false === $current['multisite'], 'Multisite state must be captured.' );
dre_check( $current === RuntimeEnvironmentEvidence::normalize( $current ), 'Environment evidence must normalize idempotently.' );
dre_check( RuntimeEnvironmentEvidence::matches_current( $current ), 'Fresh environment evidence must match the current host.' );
dre_check( 1 === preg_match( '/^[a-f0-9]{64}$/', RuntimeEnvironmentEvidence::sha256( $current ) ), 'Environment evidence must expose a canonical SHA-256 identity.' );
dre_check( '' === RuntimeEnvironmentEvidence::sha256( null ), 'Invalid environment evidence must not receive a hash.' );
dre_check( null === RuntimeEnvironmentEvidence::normalize( null ), 'Non-array environment evidence must fail closed.' );
$bad = $current; $bad['unexpected'] = true;
dre_check( null === RuntimeEnvironmentEvidence::normalize( $bad ), 'Unexpected environment fields must fail closed.' );
$bad = $current; $bad['schema'] = 'wrong';
dre_check( null === RuntimeEnvironmentEvidence::normalize( $bad ), 'Wrong environment schema must fail closed.' );
$bad = $current; $bad['wp_environment_type'] = '../../staging';
dre_check( null === RuntimeEnvironmentEvidence::normalize( $bad ), 'Ambiguous environment type must fail closed.' );
$bad = $current; $bad['db_version'] = '10.11.9';
dre_check( ! RuntimeEnvironmentEvidence::matches_current( $bad ), 'Database version change must stale evidence.' );
$bad = $current; $bad['php_version'] = '8.4.99';
dre_check( ! RuntimeEnvironmentEvidence::matches_current( $bad ), 'PHP version change must stale evidence.' );
$bad = $current; $bad['wp_version'] = '6.9.0';
dre_check( ! RuntimeEnvironmentEvidence::matches_current( $bad ), 'WordPress version change must stale evidence.' );
$bad = $current; $bad['multisite'] = true;
dre_check( ! RuntimeEnvironmentEvidence::matches_current( $bad ), 'Multisite-state change must stale evidence.' );
$original_prefix = $GLOBALS['wpdb']->prefix;
$GLOBALS['wpdb']->prefix = 'different_prefix_';
dre_check( ! RuntimeEnvironmentEvidence::matches_current( $current ), 'Different WordPress table/database instance must stale evidence even when software versions are identical.' );
$GLOBALS['wpdb']->prefix = $original_prefix;
$GLOBALS['dre_db_info'] = "MySQL 8.0.42\n test-server";
$clean = RuntimeEnvironmentEvidence::current();
dre_check( is_array( $clean ) && false === strpos( $clean['db_server_info'], "\n" ), 'Control characters must be removed from portable environment evidence.' );
$GLOBALS['dre_db_info'] = 'MySQL 8.0.42 test-server';
dre_check( false === strpos( json_encode( $current ), DB_NAME ) && false === strpos( json_encode( $current ), DB_HOST ) && false === strpos( json_encode( $current ), DB_USER ) && false === strpos( json_encode( $current ), 'secret_prefix_9384_' ), 'Portable environment evidence must not expose raw database connection or table-prefix identifiers.' );

$root = dirname( __DIR__ );
foreach ( array(
	'includes/class-ecapla-certverify-database-proof.php',
	'src/Infrastructure/Diagnostics/DomainAcceptanceProof.php',
	'src/Infrastructure/Diagnostics/LegacyMigrationPreflightProof.php',
	'src/Infrastructure/Diagnostics/StagingMigrationRehearsal.php',
	'src/Infrastructure/Diagnostics/StagingRestoreRehearsal.php',
	'src/Infrastructure/Diagnostics/StagingCutoverRehearsal.php',
) as $file ) {
	$source = file_get_contents( $root . '/' . $file );
	dre_check( is_string( $source ) && false !== strpos( $source, "'runtime_environment'" ) && false !== strpos( $source, 'RuntimeEnvironmentEvidence::matches_current' ), basename( $file ) . ' must persist and revalidate runtime-environment evidence.' );
}
$bundle_lib = file_get_contents( $root . '/tests/wp-integration/domain-acceptance-bundle-lib.php' );
dre_check( is_string( $bundle_lib ) && false !== strpos( $bundle_lib, 'RuntimeEnvironmentEvidence::current()' ), 'Real-host transcript environment capture must delegate to the production environment contract.' );
dre_check( false !== strpos( $bundle_lib, 'different WordPress/PHP/database environment' ), 'Final database acceptance must reject cross-environment stored evidence.' );

echo "PASS  {$checks}/{$checks} normalized-domain runtime-environment evidence checks\n";
