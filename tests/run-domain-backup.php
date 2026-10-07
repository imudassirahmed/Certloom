<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
/** Pure-ish integration tests for resumable domain backup builder/reader. */
$root = sys_get_temp_dir() . '/certloom-domain-backup-test-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $root . '/web/' );
define( 'WP_CONTENT_DIR', $root . '/web/wp-content' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
require_once __DIR__ . '/support/option-database.php';

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
function update_option( $key, $value, $autoload = false ) { $changed = ! array_key_exists( $key, $GLOBALS['db_options'] ) || $GLOBALS['db_options'][ $key ] !== $value; $GLOBALS['db_options'][ $key ] = $value; return $changed; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) { if ( array_key_exists( $key, $GLOBALS['db_options'] ) ) { return false; } $GLOBALS['db_options'][ $key ] = $value; return true; }
function delete_option( $key ) { if ( ! array_key_exists( $key, $GLOBALS['db_options'] ) ) { return false; } unset( $GLOBALS['db_options'][ $key ] ); return true; }
function wp_cache_delete() { return true; }

final class BackupFakeWpdb {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $last_error = '';
	public $tables = array();
	public $saw_backup_lock = false;
	public $generation_read_error = false;
	public function prepare( $query, ...$args ) { return array( 'query' => $query, 'args' => $args ); }
	public function get_var( $p ) {
		$this->last_error = '';
		if ( is_string( $p ) && 'SELECT @@SESSION.innodb_lock_wait_timeout' === $p ) { return 50; }
		$q = $p['query']; $a = $p['args'];
		if ( $this->generation_read_error && strpos( $q, 'SELECT option_value' ) === 0 && ( $a[1] ?? '' ) === 'certloom_domain_data_generation' ) { $this->last_error = 'generation read failed'; return null; }
		if ( strpos( $q, 'SELECT ENGINE' ) === 0 ) { return 'InnoDB'; }
		if ( strpos( $q, 'SELECT option_value' ) === 0 && ( $a[1] ?? '' ) === 'certloom_domain_write_fence' ) { return '1'; }
		if ( strpos( $q, 'SELECT GET_LOCK' ) === 0 || strpos( $q, 'SELECT RELEASE_LOCK' ) === 0 ) return '1';
		if ( strpos( $q, 'SELECT option_value' ) === 0 ) return $GLOBALS['db_options'][$a[1]] ?? null;
		if ( strpos( $q, 'SELECT option_name' ) === 0 ) { foreach ( array_slice($a,1) as $key ) if (isset($GLOBALS['db_options'][$key])) return $key; }
		return null;
	}
	public function query( $prepared ) {
		$this->last_error = '';
		if ( ! is_array( $prepared ) ) { return 1; }
		$query = (string) ( $prepared['query'] ?? '' );
		$args = (array) ( $prepared['args'] ?? array() );
		if ( 0 === strpos( $query, 'UPDATE %i SET option_value' ) ) {
			$new = (string) ( $args[1] ?? '' ); $name = (string) ( $args[2] ?? '' ); $old = (string) ( $args[3] ?? '' );
			if ( ! array_key_exists( $name, $GLOBALS['db_options'] ) || (string) $GLOBALS['db_options'][ $name ] !== $old ) { return 0; }
			$GLOBALS['db_options'][ $name ] = $new; return 1;
		}
		if ( 0 === strpos( $query, 'DELETE FROM %i WHERE option_name' ) ) {
			$name = (string) ( $args[1] ?? '' ); $old = (string) ( $args[2] ?? '' );
			if ( ! array_key_exists( $name, $GLOBALS['db_options'] ) || (string) $GLOBALS['db_options'][ $name ] !== $old ) { return 0; }
			unset( $GLOBALS['db_options'][ $name ] ); return 1;
		}
		return 1;
	}
	public function get_row( $prepared, $format ) {
		if ( isset( $GLOBALS['db_options']['certloom_domain_maintenance_lock'] ) && false !== strpos( (string) $GLOBALS['db_options']['certloom_domain_maintenance_lock'], 'domain_backup_snapshot' ) ) { $this->saw_backup_lock = true; }
		$table = $prepared['args'][0] ?? '';
		$rows = array_values( $this->tables[ $table ] ?? array() );
		$max = 0;
		foreach ( $rows as $row ) { $max = max( $max, (int) ( $row['id'] ?? 0 ) ); }
		return array( 'row_count' => count( $rows ), 'max_id' => $max );
	}
	public function get_results( $prepared, $format ) {
		$options = certloom_test_option_rows( $prepared, $GLOBALS['db_options'] );
		if ( null !== $options ) { return $options; }
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
use Certloom\Infrastructure\Diagnostics\DomainStateFingerprint;

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
db_true( $GLOBALS['wpdb']->saw_backup_lock, 'snapshot reads occur inside shared maintenance boundary' );
db_same( false, array_key_exists( 'certloom_domain_maintenance_lock', $GLOBALS['db_options'] ), 'snapshot lock released before long streaming build' );
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
echo "PASS  resumable backup snapshots under maintenance lock, then completes and validates through streaming reader\n";

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


// A failed final marker read must delete an unverifiable file rather than publish it.
$unverifiable = $builder->start( str_repeat( '7', 32 ), '1.5.4' );
db_same( false, is_wp_error( $unverifiable ), 'unverifiable backup start' );
$unverifiable_path = $unverifiable['path'];
while ( $unverifiable['entity_index'] < $last_index ) { $unverifiable = $builder->continue_build( $unverifiable, 500 ); }
$unverifiable = $builder->continue_build( $unverifiable, 500 );
$GLOBALS['wpdb']->generation_read_error = true;
$unverifiable = $builder->continue_build( $unverifiable, 500 );
$GLOBALS['wpdb']->generation_read_error = false;
db_same( 'certloom_domain_generation_read_failed', $unverifiable->get_error_code(), 'failed final marker read refuses acceptance' );
db_same( false, file_exists( $unverifiable_path ), 'unverifiable backup deleted' );
echo "PASS  failed final generation read rejects and deletes an unverifiable backup\n";

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

// The staging restore oracle must fingerprint the complete graph deterministically while holding the shared maintenance boundary.
$fingerprinter = new DomainStateFingerprint();
$fp = $fingerprinter->start( str_repeat( 'e', 32 ) );
db_same( false, is_wp_error( $fp ), 'fingerprint start' );
db_true( array_key_exists( 'certloom_domain_maintenance_lock', $GLOBALS['db_options'] ), 'fingerprint keeps maintenance boundary across slices' );
$fp_loops=0;while(empty($fp['complete'])&&$fp_loops++<100){$fp=$fingerprinter->continue_fingerprint($fp,1);if(is_wp_error($fp))throw new RuntimeException('Fingerprint failed: '.$fp->get_error_code());}
db_true( $fp['complete'], 'fingerprint completes' );
db_same( false, array_key_exists( 'certloom_domain_maintenance_lock', $GLOBALS['db_options'] ), 'fingerprint releases maintenance boundary only after final verification' );
$fp2 = $fingerprinter->start( str_repeat( 'f', 32 ) );$guard=0;while(!is_wp_error($fp2)&&empty($fp2['complete'])&&$guard++<100){$fp2=$fingerprinter->continue_fingerprint($fp2,500);}
db_same(false,is_wp_error($fp2),'second fingerprint');db_same($fp['hash'],$fp2['hash'],'deterministic graph fingerprint');db_same($fp['records'],$fp2['records'],'deterministic graph count');
echo "PASS  domain-state fingerprint is deterministic and holds the maintenance boundary across its full scan\n";

// Final acceptance may retain the exact same owner-matched maintenance lease through publication.
$fp_retained = $fingerprinter->start( str_repeat( '3', 32 ), true );
db_same( false, is_wp_error( $fp_retained ), 'retained fingerprint start' );
$guard = 0;
while ( ! is_wp_error( $fp_retained ) && empty( $fp_retained['complete'] ) && $guard++ < 100 ) {
	$fp_retained = $fingerprinter->continue_fingerprint( $fp_retained, 500 );
}
db_same( false, is_wp_error( $fp_retained ), 'retained fingerprint completes' );
db_true( $fp_retained['complete'], 'retained fingerprint complete flag' );
db_same( true, array_key_exists( 'certloom_domain_maintenance_lock', $GLOBALS['db_options'] ), 'retained fingerprint preserves maintenance boundary after verification' );
$retained_lock = new Certloom\Infrastructure\Data\DomainMaintenanceLock();
db_same( true, $retained_lock->owned_active( str_repeat( '3', 32 ) ), 'retained fingerprint owner still holds active lease' );
db_same( true, $retained_lock->release( str_repeat( '3', 32 ) ), 'retained fingerprint lease releases explicitly' );
db_same( false, array_key_exists( 'certloom_domain_maintenance_lock', $GLOBALS['db_options'] ), 'retained fingerprint explicit release clears maintenance boundary' );
echo "PASS  domain-state fingerprint can retain one owner-matched maintenance boundary through an external publication step\n";

// Malformed initial boundary evidence must not strand the shared maintenance lease.
$generation_saved = $GLOBALS['db_options']['certloom_domain_data_generation'];
$GLOBALS['db_options']['certloom_domain_data_generation'] = '';
$fp_bad_start = $fingerprinter->start( str_repeat( '1', 32 ) );
db_same( 'certloom_domain_generation_read_failed', $fp_bad_start->get_error_code(), 'invalid fingerprint boundary rejected at its fresh database read' );
db_same( false, array_key_exists( 'certloom_domain_maintenance_lock', $GLOBALS['db_options'] ), 'invalid fingerprint boundary releases maintenance lease' );
$GLOBALS['db_options']['certloom_domain_data_generation'] = $generation_saved;
echo "PASS  invalid fingerprint start boundary releases its maintenance lease\n";

// Corrupt resumable state with a valid owner must also abandon its owned lease safely.
$fp_corrupt = $fingerprinter->start( str_repeat( '2', 32 ) );
db_same( false, is_wp_error( $fp_corrupt ), 'corrupt-state setup start' );
$fp_corrupt['schema'] = 'tampered-schema';
$fp_corrupt_result = $fingerprinter->continue_fingerprint( $fp_corrupt, 1 );
db_same( 'certloom_domain_fingerprint_state_invalid', $fp_corrupt_result->get_error_code(), 'corrupt fingerprint state rejected' );
db_same( false, array_key_exists( 'certloom_domain_maintenance_lock', $GLOBALS['db_options'] ), 'corrupt fingerprint state releases owned maintenance lease' );
echo "PASS  invalid resumable fingerprint state releases its owner-matched maintenance lease\n";

db_rm( $root );
@unlink( $tampered );
@unlink( $count_bad );
@unlink( $trailing );
@unlink( $unsupported );
echo "\n13 tests, 0 failures\n";
