<?php
/** Regression: import staging must avoid MariaDB ROW_NUMBER while preserving application row_number semantics. */
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

final class PortableImportWpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $columns = array();
	public $queries = array();
	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_results( $prepared, $format ) { return $this->columns; }
	public function query( $prepared ) { $this->queries[] = $prepared; return 1; }
}
$GLOBALS['wpdb'] = new PortableImportWpdb();
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Database\SchemaManager;

$checks = 0;
function irsp_check( $condition, $message ) {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$checks++;
	echo "PASS  {$message}\n";
}

$root = dirname( __DIR__ );
$schema = file_get_contents( $root . '/src/Infrastructure/Database/SchemaManager.php' );
$repo = file_get_contents( $root . '/src/Infrastructure/Import/DomainImportStageRepository.php' );
irsp_check( false !== strpos( $schema, "const SCHEMA_VERSION         = '18'" ), 'current schema contract retains portable import row storage after v17' );
irsp_check( false !== strpos( $schema, 'source_row_number BIGINT UNSIGNED NOT NULL' ), 'import staging persists the portable source_row_number column' );
irsp_check( 0 === preg_match( '/^\s*row_number\s+BIGINT\b/m', $schema ), 'import staging DDL no longer declares MariaDB-reserved row_number' );
irsp_check( false !== strpos( $schema, 'UNIQUE KEY job_source_row (job_key,source_row_number)' ), 'portable source-row uniqueness uses a non-conflicting index name' );
irsp_check( false !== strpos( $repo, "'source_row_number' => \$row_number" ), 'new staged writes persist source_row_number' );
irsp_check( false !== strpos( $repo, "'row_number' => absint( \$row['source_row_number'] )" ), 'repository preserves the public application row_number key' );
irsp_check( false !== strpos( $repo, 'ORDER BY source_row_number ASC' ), 'staged reads order by the portable persisted source row number' );

$migrate = new ReflectionMethod( SchemaManager::class, 'migrate_import_source_row_number' );
$migrate->setAccessible( true );
$GLOBALS['wpdb']->columns = array( array( 'Field' => 'id' ), array( 'Field' => 'row_number' ), array( 'Field' => 'source_row_number' ) );
$result = $migrate->invoke( null );
irsp_check( true === $result && 1 === count( $GLOBALS['wpdb']->queries ), 'schema compatibility copies legacy staged row numbers idempotently when both columns exist' );
$query = $GLOBALS['wpdb']->queries[0];
irsp_check( 'UPDATE %i SET %i = %i WHERE %i = %d AND %i <> %d' === $query['query'], 'compatibility copy quotes legacy reserved identifier through wpdb percent-i placeholders' );
irsp_check( array( 'wp_certloom_import_rows', 'source_row_number', 'row_number', 'source_row_number', 0, 'row_number', 0 ) === $query['args'], 'compatibility copy preserves nonzero modern values while carrying forward legacy row positions' );

$GLOBALS['wpdb']->queries = array();
$GLOBALS['wpdb']->columns = array( array( 'Field' => 'id' ), array( 'Field' => 'source_row_number' ) );
$result = $migrate->invoke( null );
irsp_check( true === $result && 0 === count( $GLOBALS['wpdb']->queries ), 'fresh portable import staging tables require no legacy compatibility copy' );

echo "\n{$checks} tests, 0 failures\n";
