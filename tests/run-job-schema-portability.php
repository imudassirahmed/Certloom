<?php
/** Regression: durable jobs schema must avoid SQL-reserved identifiers while preserving the application cursor contract. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code, $message, $data;
		public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }

final class PortableJobWpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $columns = array();
	public $queries = array();
	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_results( $prepared, $format ) { return $this->columns; }
	public function query( $prepared ) { $this->queries[] = $prepared; return 1; }
}
$GLOBALS['wpdb'] = new PortableJobWpdb();
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Job\JobRepository;

$checks = 0;
function jsp_check( $condition, $message ) {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$checks++;
	echo "PASS  {$message}\n";
}

$schema = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Database/SchemaManager.php' );
$repo   = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Job/JobRepository.php' );
jsp_check( false !== strpos( $schema, "const SCHEMA_VERSION         = '17'" ), 'current schema contract retains the v16 portable job progress migration under v17' );
jsp_check( false !== strpos( $schema, 'progress_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0' ), 'jobs DDL uses non-reserved progress_cursor identifier' );
jsp_check( 0 === preg_match( '/^\s*cursor\s+BIGINT\b/m', $schema ), 'jobs DDL never reintroduces unquoted SQL-reserved cursor column' );
jsp_check( false !== strpos( $repo, "'progress_cursor'  => 0" ) && false !== strpos( $repo, "'progress_cursor' => absint( \$progress['cursor'] ?? 0 )" ), 'job repository persists application cursor through progress_cursor' );

$decode = new ReflectionMethod( JobRepository::class, 'decode' );
$decode->setAccessible( true );
$modern = $decode->invoke( null, array(
	'id' => '1', 'owner_user_id' => null, 'progress_cursor' => '42', 'total' => null, 'selection_max_id' => null,
	'attempts' => '0', 'recovery_count' => '0', 'payload_json' => null, 'result_json' => null, 'error_json' => null,
) );
jsp_check( 42 === $modern['cursor'] && ! array_key_exists( 'progress_cursor', $modern ), 'modern persisted progress_cursor decodes to stable application cursor key' );
$legacy = $decode->invoke( null, array(
	'id' => '2', 'owner_user_id' => null, 'cursor' => '17', 'total' => null, 'selection_max_id' => null,
	'attempts' => '0', 'recovery_count' => '0', 'payload_json' => null, 'result_json' => null, 'error_json' => null,
) );
jsp_check( 17 === $legacy['cursor'], 'legacy cursor rows remain readable during gated compatibility migration' );

$migrate = new ReflectionMethod( SchemaManager::class, 'migrate_job_progress_cursor' );
$migrate->setAccessible( true );
$GLOBALS['wpdb']->columns = array( array( 'Field' => 'id' ), array( 'Field' => 'cursor' ), array( 'Field' => 'progress_cursor' ) );
$result = $migrate->invoke( null );
jsp_check( true === $result && 1 === count( $GLOBALS['wpdb']->queries ), 'schema v16 copies legacy job progress idempotently when both columns exist' );
$query = $GLOBALS['wpdb']->queries[0];
jsp_check( 'UPDATE %i SET %i = %i WHERE %i = %d AND %i <> %d' === $query['query'], 'compatibility copy quotes table and column identifiers through wpdb percent-i placeholders' );
jsp_check( array( 'wp_certloom_jobs', 'progress_cursor', 'cursor', 'progress_cursor', 0, 'cursor', 0 ) === $query['args'], 'compatibility copy targets only zero-valued new progress state and preserves nonzero new writes' );

$GLOBALS['wpdb']->queries = array();
$GLOBALS['wpdb']->columns = array( array( 'Field' => 'id' ), array( 'Field' => 'progress_cursor' ) );
$result = $migrate->invoke( null );
jsp_check( true === $result && 0 === count( $GLOBALS['wpdb']->queries ), 'fresh portable jobs tables require no legacy compatibility copy' );

echo "\n{$checks} tests, 0 failures\n";
