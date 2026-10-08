<?php
/** Exercise portable default-Batch identities through the actual Merge row/finalize logic. */
namespace Certloom\Infrastructure\Restore {
	class DomainRestoreWorkspace {
		public function workspace_table( $workspace, $kind, $entity ) { return $kind . '_' . $entity; }
		public function validate_merge_relationships( $workspace ) { return true; }
	}
	class DomainRestoreMapRepository {
		public $rows = array();
		public function get( $owner, $entity, $id ) { return $this->rows[$entity][$id] ?? null; }
		public function save( $owner, $entity, $source, $hash, $target, $action, $meta ) { $this->rows[$entity][$source] = array( 'source_id' => $source, 'source_hash' => $hash, 'target_id' => $target, 'action' => $action, 'meta' => $meta ); return true; }
		public function by_action( $owner, $entity, $action ) { return array_values( array_filter( $this->rows[$entity] ?? array(), static function ( $row ) use ( $action ) { return $row['action'] === $action; } ) ); }
	}
}
namespace Certloom\Infrastructure\Backup {
	class DomainBackupRepository { public function configuration_snapshot() { return array( 'values' => $GLOBALS['default_restore_config'] ); } }
}
namespace {
if ( PHP_SAPI !== 'cli' ) { exit; }
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' );
class WP_Error { private $code; public function __construct( $code, ...$args ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function current_time( ...$args ) { return '2026-10-08 00:00:00'; }
class DefaultRestoreDb {
	public $last_error = '', $insert_id = 600, $tables = array(), $fail_update = false;
	public function prepare( $query, ...$args ) { return array( $query, count( $args ) === 1 && is_array( $args[0] ) ? $args[0] : $args ); }
	public function get_var( $prepared ) { foreach ( $this->tables[$prepared[1][0]] ?? array() as $row ) { if ( $row['code'] === $prepared[1][1] ) { return $row['id']; } } return null; }
	public function get_row( $prepared, $format ) { return $this->tables[$prepared[1][0]][(int) $prepared[1][1]] ?? null; }
	public function insert( $table, $row ) { $row['id'] = ++$this->insert_id; $this->tables[$table][$row['id']] = $row; return 1; }
	public function update( $table, $patch, $where ) { if ( $this->fail_update ) { $this->last_error = 'forced update failure'; return false; } $this->tables[$table][$where['id']] = array_merge( $this->tables[$table][$where['id']], $patch ); return 1; }
}
require __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Restore\DomainRestoreMergeService;
use Certloom\Infrastructure\Restore\DomainRestoreMapRepository;
use Certloom\Application\Program\ProgramProfile;
$checks = 0;
function check( $ok, $label ) { global $checks; if ( ! $ok ) { throw new \RuntimeException( $label ); } ++$checks; }
$wpdb = new DefaultRestoreDb(); $maps = new DomainRestoreMapRepository(); $service = new DomainRestoreMergeService( null, $maps );
$merge = new \ReflectionMethod( $service, 'merge_row_in_transaction' ); $finalize = new \ReflectionMethod( $service, 'finalize' );
if ( PHP_VERSION_ID < 80100 ) { $merge->setAccessible( true ); $finalize->setAccessible( true ); }
$workspace = array( 'owner_token' => str_repeat( 'a', 32 ) );
$source = array( 'id' => 7, 'code' => 'MBA', 'issuer_id' => null, 'metadata_json' => wp_json_encode( array( ProgramProfile::STORAGE_KEY => array( 'default_batch_id' => 21 ), 'unrelated' => array( 'keep' => true ) ) ) );
$mapped = $merge->invoke( $service, $workspace, 'programs', $source, 'source-hash' ); $id = $mapped['target_id'];
check( $id !== 7 && $mapped['meta']['source_default_batch_id'] === 21, 'Inserted Program defers the portable source Batch identity.' );
$metadata = json_decode( $wpdb->tables['merge_programs'][$id]['metadata_json'], true );
check( $metadata[ProgramProfile::STORAGE_KEY]['default_batch_id'] === 0 && $metadata['unrelated']['keep'], 'Source IDs cannot appear as live target preferences before Batch mappings complete.' );
$default_restore_config = array_fill_keys( array( 'certverify_fields', 'certverify_retired_fields', 'certverify_settings' ), array( 'exists' => true, 'value' => array() ) );
$state = array( 'workspace' => $workspace, 'backup_configuration' => $default_restore_config );
check( is_wp_error( $finalize->invoke( $service, $state ) ), 'A missing Batch mapping cannot finalize a default.' );
$maps->save( '', 'batches', 21, '', 71, 'inserted', array() ); $wpdb->tables['merge_batches'][71] = array( 'id' => 71, 'program_id' => $id + 1 );
check( is_wp_error( $finalize->invoke( $service, $state ) ), 'A Batch code collision mapped to another Program fails closed.' );
$wpdb->tables['merge_batches'][71]['program_id'] = $id;
$restored = $finalize->invoke( $service, $state );
$metadata = json_decode( $wpdb->tables['merge_programs'][$id]['metadata_json'], true );
check( ! is_wp_error( $restored ) && $restored['phase'] === 'ready' && $metadata[ProgramProfile::STORAGE_KEY]['default_batch_id'] === 71, 'Merge finalization uses the target Batch ID belonging to the target Program.' );
check( $metadata['unrelated'] === array( 'keep' => true ), 'Remapping preserves unrelated structured Program metadata.' );
check( ! is_wp_error( $finalize->invoke( $service, $state ) ), 'Repeated finalization is idempotent.' );
$source['id'] = 8; $source['metadata_json'] = wp_json_encode( array( ProgramProfile::STORAGE_KEY => array( 'default_batch_id' => 99 ) ) );
$existing = $merge->invoke( $service, $workspace, 'programs', $source, 'different-source-hash' );
check( $existing['action'] === 'existing' && $existing['target_id'] === $id && empty( $existing['meta']['source_default_batch_id'] ), 'An existing Program retains its authoritative target preference.' );
check( ! is_wp_error( $finalize->invoke( $service, $state ) ) && $wpdb->tables['merge_programs'][$id]['metadata_json'] === wp_json_encode( $metadata ), 'Existing-Program matches cannot overwrite current default configuration.' );
$wpdb->fail_update = true;
check( is_wp_error( $finalize->invoke( $service, $state ) ), 'A failed preference write cannot report a completed Merge graph.' );
echo "$checks default-Batch restore checks, 0 failures\n";
}
