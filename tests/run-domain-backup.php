<?php
/** Pure-ish integration tests for resumable domain backup builder/reader. */
$root = sys_get_temp_dir() . '/certloom-domain-backup-test-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $root . '/web/' );
define( 'WP_CONTENT_DIR', $root . '/web/wp-content' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code, $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $t ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }
function untrailingslashit( $path ) { return rtrim( (string) $path, '/\\' ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0700, true ); }
function apply_filters( $hook, $value ) { global $root; return 'certloom_private_job_directory' === $hook ? $root . '/private' : $value; }
$GLOBALS['db_options'] = array( 'certloom_domain_data_generation' => 'generation-1' );
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['db_options'] ) ? $GLOBALS['db_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['db_options'][ $key ] = $value; return true; }

final class BackupFakeWpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $tables = array();
	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_row( $prepared, $format ) {
		$table = $prepared['args'][0] ?? '';
		$rows = array_values( $this->tables[ $table ] ?? array() );
		$max = 0;
		foreach ( $rows as $row ) { $max = max( $max, (int) ( $row['id'] ?? 0 ) ); }
		return array( 'row_count' => count( $rows ), 'max_id' => $max );
	}
	public function get_results( $prepared, $format ) {
		$args = $prepared['args'];
		$table = $args[0] ?? '';
		$after = (int) ( $args[1] ?? 0 );
		$max = (int) ( $args[2] ?? 0 );
		$limit = (int) ( $args[3] ?? 200 );
		$rows = array_values( $this->tables[ $table ] ?? array() );
		usort( $rows, static function( $a, $b ){ return (int)$a['id'] <=> (int)$b['id']; } );
		$out = array();
		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > $after && $id <= $max ) { $out[] = $row; if ( count( $out ) >= $limit ) { break; } }
		}
		return $out;
	}
}
$GLOBALS['wpdb'] = new BackupFakeWpdb();

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Backup\DomainBackupBuilder;
use Certloom\Application\Backup\DomainBackupReader;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Database\SchemaManager;

function db_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) { throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}
function db_true( $actual, $message = '' ) { db_same( true, (bool) $actual, $message ); }
function db_rm( $path ) { if ( ! file_exists( $path ) ) return; if ( is_dir( $path ) ) { foreach ( scandir( $path ) as $n ) { if ( '.' === $n || '..' === $n ) continue; db_rm( $path . '/' . $n ); } rmdir( $path ); } else { unlink( $path ); } }

// Populate all domain entity types, including empty and multi-row tables.
$id = 1;
foreach ( DomainBackupRepository::entity_types() as $type ) {
	$table = SchemaManager::table( $type );
	$GLOBALS['wpdb']->tables[ $table ] = array();
	$count = 'credential_events' === $type ? 5 : ( 'credential_numbers' === $type ? 0 : 2 );
	for ( $i = 0; $i < $count; $i++ ) {
		$GLOBALS['wpdb']->tables[ $table ][] = array( 'id' => $id++, 'kind' => $type, 'value' => $type . '-' . $i );
	}
}

$builder = new DomainBackupBuilder();
$state = $builder->start( str_repeat( 'a', 32 ), '1.5.4' );
db_same( false, is_wp_error( $state ), 'backup start' );
db_same( false, $state['complete'], 'not complete after manifest' );
$iterations = 0;
while ( ! $state['complete'] && $iterations++ < 100 ) {
	$state = $builder->continue_build( $state, 1 );
	if ( is_wp_error( $state ) ) { throw new RuntimeException( 'Unexpected backup error: ' . $state->get_error_code() ); }
}
db_true( $state['complete'], 'resumable backup completes' );
db_true( $iterations > 5, 'multiple bounded iterations used' );
$analysis = ( new DomainBackupReader() )->analyze( $state['path'] );
db_same( false, is_wp_error( $analysis ), 'reader accepts backup' );
db_same( (int) $state['records'], (int) $analysis['records'], 'record count' );
db_same( 'certloom-domain-backup', $analysis['manifest']['format'], 'format' );
echo "PASS  resumable backup completes and validates through streaming reader\n";

// Simulate a PHP crash after file append but before the new job state was saved.
// The retry must truncate the uncommitted tail to the persisted byte offset.
$crash_state = $builder->start( str_repeat( 'd', 32 ), '1.5.4' );
db_same( false, is_wp_error( $crash_state ), 'crash recovery start' );
file_put_contents( $crash_state['path'], "UNCOMMITTED-CRASH-TAIL\n", FILE_APPEND );
$crash_state = $builder->continue_build( $crash_state, 1 );
db_same( false, is_wp_error( $crash_state ), 'retry after uncommitted append' );
db_same( false, false !== strpos( file_get_contents( $crash_state['path'] ), 'UNCOMMITTED-CRASH-TAIL' ), 'uncommitted tail removed' );
$guard = 0;
while ( ! $crash_state['complete'] && $guard++ < 100 ) {
	$crash_state = $builder->continue_build( $crash_state, 500 );
	if ( is_wp_error( $crash_state ) ) { throw new RuntimeException( 'Unexpected crash-recovery backup error: ' . $crash_state->get_error_code() ); }
}
db_true( $crash_state['complete'], 'crash recovery backup completes' );
db_same( false, is_wp_error( ( new DomainBackupReader() )->analyze( $crash_state['path'] ) ), 'crash recovery file remains valid' );
echo "PASS  backup retry truncates a crash-written tail to the persisted file offset before replay\n";

// Tamper one entity line without updating the integrity record.
$tampered = $state['path'] . '.tampered';
$contents = file_get_contents( $state['path'] );
$contents = preg_replace( '/"value":"([^"]+)"/', '"value":"tampered"', $contents, 1 );
file_put_contents( $tampered, $contents );
$tampered_result = ( new DomainBackupReader() )->analyze( $tampered );
db_same( 'certloom_domain_backup_integrity_failed', $tampered_result->get_error_code(), 'tamper rejected' );
echo "PASS  chained integrity hash rejects modified backup content\n";

// Concurrent domain mutation must delete the mixed-state backup instead of finalizing it.
$GLOBALS['db_options']['certloom_domain_data_generation'] = 'generation-2';
$state2 = $builder->start( str_repeat( 'b', 32 ), '1.5.4' );
db_same( false, is_wp_error( $state2 ), 'second start' );
$path2 = $state2['path'];
// start snapshots generation-2; change it before the finalization slice.
$last_index = count( DomainBackupRepository::entity_types() ) - 1;
while ( $state2['entity_index'] < $last_index ) {
	$state2 = $builder->continue_build( $state2, 500 );
	if ( is_wp_error( $state2 ) ) { throw new RuntimeException( 'Unexpected build error before mutation: ' . $state2->get_error_code() ); }
}
// Read the final entity rows but do not yet make the empty-batch/finalization call.
$state2 = $builder->continue_build( $state2, 500 );
if ( is_wp_error( $state2 ) ) { throw new RuntimeException( 'Unexpected final-entity build error: ' . $state2->get_error_code() ); }
$GLOBALS['db_options']['certloom_domain_data_generation'] = 'generation-3';
$changed = $builder->continue_build( $state2, 500 );
db_same( 'certloom_domain_backup_concurrent_change', $changed->get_error_code(), 'concurrent change blocked' );
db_same( false, file_exists( $path2 ), 'mixed backup deleted' );
echo "PASS  concurrent domain generation change rejects and deletes mixed-state backup\n";


// Configuration changes must also invalidate an otherwise generation-stable backup.
$GLOBALS['db_options']['certloom_domain_data_generation'] = 'generation-4';
$GLOBALS['db_options']['certverify_settings'] = array( 'verification_page' => 10 );
$state3 = $builder->start( str_repeat( 'c', 32 ), '1.5.4' );
db_same( false, is_wp_error( $state3 ), 'third start' );
$path3 = $state3['path'];
$last_index = count( DomainBackupRepository::entity_types() ) - 1;
while ( $state3['entity_index'] < $last_index ) {
	$state3 = $builder->continue_build( $state3, 500 );
	if ( is_wp_error( $state3 ) ) { throw new RuntimeException( 'Unexpected build error before config mutation: ' . $state3->get_error_code() ); }
}
$state3 = $builder->continue_build( $state3, 500 );
$GLOBALS['db_options']['certverify_settings'] = array( 'verification_page' => 11 );
$config_changed = $builder->continue_build( $state3, 500 );
db_same( 'certloom_domain_backup_configuration_changed', $config_changed->get_error_code(), 'config change blocked' );
db_same( false, file_exists( $path3 ), 'mixed config backup deleted' );
echo "PASS  configuration change rejects and deletes mixed-state backup
";

// Manifest count mismatch should fail even if an attacker recomputes the chain hash; easiest regression is a valid backup with altered manifest count + regenerated integrity.
$lines = file( $state['path'] );
$manifest = json_decode( trim( $lines[0] ), true );
$first_entity = array_key_first( $manifest['entities'] );
$manifest['entities'][ $first_entity ]['count']++;
$format = new Certloom\Application\Backup\DomainBackupFormat();
$lines[0] = $format->encode_line( $manifest );
$hash = $format->next_hash( '', $lines[0] );
$records = 0;
for ( $i = 1; $i < count( $lines ) - 1; $i++ ) { $hash = $format->next_hash( $hash, $lines[$i] ); $records++; }
$lines[count($lines)-1] = $format->encode_line( array( 'type' => 'integrity', 'records' => $records, 'chain_sha256' => $hash ) );
$count_bad = $state['path'] . '.countbad';
file_put_contents( $count_bad, implode( '', $lines ) );
$count_result = ( new DomainBackupReader() )->analyze( $count_bad );
db_same( 'certloom_domain_backup_count_mismatch', $count_result->get_error_code(), 'count mismatch' );
echo "PASS  manifest entity-count mismatch is rejected independently of chain integrity\n";


// No data may be appended after the integrity footer.
$trailing = $state['path'] . '.trailing';
file_put_contents( $trailing, file_get_contents( $state['path'] ) . "{\"type\":\"entity\",\"entity\":\"recipients\",\"data\":{\"id\":999}}\n" );
$trailing_result = ( new DomainBackupReader() )->analyze( $trailing );
db_same( 'certloom_domain_backup_trailing_data', $trailing_result->get_error_code(), 'trailing content rejected' );
echo "PASS  data appended after integrity footer is rejected\n";

// Unsupported entity records are rejected even when their JSON shape is otherwise valid.
$unsupported_lines = file( $state['path'] );
$entity_line_index = 1;
$entity_item = json_decode( trim( $unsupported_lines[$entity_line_index] ), true );
$entity_item['entity'] = 'unknown_future_table';
$format2 = new Certloom\Application\Backup\DomainBackupFormat();
$unsupported_lines[$entity_line_index] = $format2->encode_line( $entity_item );
$hash2 = $format2->next_hash( '', $unsupported_lines[0] );
$records2 = 0;
for ( $i = 1; $i < count( $unsupported_lines ) - 1; $i++ ) { $hash2 = $format2->next_hash( $hash2, $unsupported_lines[$i] ); $records2++; }
$unsupported_lines[count($unsupported_lines)-1] = $format2->encode_line( array( 'type' => 'integrity', 'records' => $records2, 'chain_sha256' => $hash2 ) );
$unsupported = $state['path'] . '.unsupported';
file_put_contents( $unsupported, implode( '', $unsupported_lines ) );
$unsupported_result = ( new DomainBackupReader() )->analyze( $unsupported );
db_same( 'certloom_domain_backup_entity_unsupported', $unsupported_result->get_error_code(), 'unknown entity rejected' );
echo "PASS  unsupported backup entity types are rejected\n";

db_rm( $root );
@unlink( $tampered );
@unlink( $count_bad );
@unlink( $trailing );
@unlink( $unsupported );
echo "\n8 tests, 0 failures\n";
