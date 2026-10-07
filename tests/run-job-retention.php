<?php
/** Infrastructure-level retention tests for finished jobs and operational staging. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
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
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_normalize_path( $value ) { return str_replace( '\\', '/', (string) $value ); }
function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/\\' ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0700, true ); }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function apply_filters( $hook, $value ) { global $retention_dir; return 'certloom_private_job_directory' === $hook ? $retention_dir : $value; }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
$GLOBALS['retention_options'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['retention_options'] ) ? $GLOBALS['retention_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['retention_options'][ $key ] = $value; return true; }

final class RetentionWpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $tables = array();
	public function prepare( $query, ...$args ) { if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; } return array( 'query' => $query, 'args' => $args ); }
	public function get_results( $prepared, $format ) {
		$this->last_error = '';
		$q = $prepared['query']; $a = $prepared['args'];
		if ( 0 === strpos( $q, 'SELECT * FROM %i WHERE status IN' ) ) {
			$table = (string) $a[0]; $states = array_slice( $a, 1, 4 ); $before = (string) $a[5]; $limit = (int) $a[6];
			$rows = array_values( array_filter( $this->tables[ $table ] ?? array(), static function ( $row ) use ( $states, $before ) {
				return in_array( $row['status'], $states, true ) && ! empty( $row['finished_at'] ) && $row['finished_at'] < $before;
			} ) );
			usort( $rows, static function ( $left, $right ) { return (int) $left['id'] <=> (int) $right['id']; } );
			return array_slice( $rows, 0, $limit );
		}
		return array();
	}
	public function get_var( $prepared ) {
		$this->last_error = '';
		$q = $prepared['query']; $a = $prepared['args'];
		if ( 0 === strpos( $q, 'SELECT COUNT(*) FROM %i WHERE parent_job_key' ) ) {
			$table = (string) $a[0]; $parent = (string) $a[1]; $count = 0;
			foreach ( $this->tables[ $table ] ?? array() as $row ) { if ( (string) ( $row['parent_job_key'] ?? '' ) === $parent ) { $count++; } }
			return $count;
		}
		return 0;
	}
	public function query( $prepared ) {
		$this->last_error = '';
		$q = $prepared['query']; $a = $prepared['args'];
		if ( 0 === strpos( $q, 'DELETE FROM %i WHERE job_key' ) ) {
			$table = (string) $a[0]; $key = (string) $a[1]; $states = array_slice( $a, 2, 4 );
			foreach ( $this->tables[ $table ] ?? array() as $id => $row ) {
				if ( (string) $row['job_key'] === $key && in_array( $row['status'], $states, true ) ) { unset( $this->tables[ $table ][ $id ] ); return 1; }
			}
			return 0;
		}
		return 0;
	}
	public function delete( $table, $where ) {
		$this->last_error = '';
		$deleted = 0;
		foreach ( $this->tables[ $table ] ?? array() as $id => $row ) {
			$match = true;
			foreach ( $where as $key => $value ) { if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) { $match = false; break; } }
			if ( $match ) { unset( $this->tables[ $table ][ $id ] ); $deleted++; }
		}
		return $deleted;
	}
}
$GLOBALS['wpdb'] = new RetentionWpdb();
$retention_dir = sys_get_temp_dir() . '/certloom-retention-' . bin2hex( random_bytes( 4 ) );

require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Job\JobRetentionService;

function jr_same( $expected, $actual, $message = '' ) { if ( $expected !== $actual ) { throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); } }

$jobs = SchemaManager::table( 'jobs' );
$imports = SchemaManager::table( 'import_rows' );
$maps = SchemaManager::table( 'restore_map' );
$old = gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS );
$recent = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$parent = str_repeat( 'a', 32 );
$child = str_repeat( 'b', 32 );
$backup = str_repeat( 'c', 32 );
$restore = str_repeat( 'd', 32 );
$import = str_repeat( 'e', 32 );
$recent_key = str_repeat( 'f', 32 );
$GLOBALS['wpdb']->tables[ $jobs ] = array(
	1 => array( 'id'=>1,'job_key'=>$parent,'job_type'=>JobTypes::DOMAIN_IMPORT_PREFLIGHT,'status'=>JobStates::SUCCEEDED,'parent_job_key'=>null,'payload_json'=>'{}','result_json'=>'{}','error_json'=>'{}','finished_at'=>$old ),
	2 => array( 'id'=>2,'job_key'=>$child,'job_type'=>JobTypes::DOMAIN_IMPORT_COMMIT,'status'=>JobStates::SUCCEEDED,'parent_job_key'=>$parent,'payload_json'=>json_encode(array('preflight_job_key'=>$parent)),'result_json'=>'{}','error_json'=>'{}','finished_at'=>$recent ),
	3 => array( 'id'=>3,'job_key'=>$backup,'job_type'=>JobTypes::DOMAIN_BACKUP,'status'=>JobStates::SUCCEEDED,'parent_job_key'=>null,'payload_json'=>'{}','result_json'=>'{}','error_json'=>'{}','finished_at'=>$old ),
	4 => array( 'id'=>4,'job_key'=>$restore,'job_type'=>JobTypes::DOMAIN_RESTORE_MERGE,'status'=>JobStates::FAILED,'parent_job_key'=>null,'payload_json'=>'{}','result_json'=>'{}','error_json'=>'{}','finished_at'=>$old ),
	5 => array( 'id'=>5,'job_key'=>$import,'job_type'=>JobTypes::DOMAIN_IMPORT_PREFLIGHT,'status'=>JobStates::PARTIALLY_SUCCEEDED,'parent_job_key'=>null,'payload_json'=>'{}','result_json'=>'{}','error_json'=>'{}','finished_at'=>$old ),
	6 => array( 'id'=>6,'job_key'=>$recent_key,'job_type'=>JobTypes::DOMAIN_BACKUP,'status'=>JobStates::SUCCEEDED,'parent_job_key'=>null,'payload_json'=>'{}','result_json'=>'{}','error_json'=>'{}','finished_at'=>$recent ),
);
$GLOBALS['wpdb']->tables[ $imports ] = array(
	1 => array( 'job_key'=>$parent, 'row_number'=>1 ),
	2 => array( 'job_key'=>$import, 'row_number'=>1 ),
);
$GLOBALS['wpdb']->tables[ $maps ] = array( 1 => array( 'owner_token'=>$restore, 'entity'=>'issuers', 'source_id'=>1 ) );

@mkdir( $retention_dir, 0700, true );
$old_file = $retention_dir . '/' . str_repeat('1',32) . '-backup-' . str_repeat('2',48) . '.data';
file_put_contents( $old_file, 'old' ); touch( $old_file, time() - 20 * DAY_IN_SECONDS );
$fresh_file = $retention_dir . '/' . str_repeat('3',32) . '-backup-' . str_repeat('4',48) . '.data';
file_put_contents( $fresh_file, 'fresh' );

$service = new JobRetentionService();
$result = $service->cleanup( 25 );
if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
jr_same( 3, $result['jobs'], 'deleted standalone old jobs' );
jr_same( 1, $result['files'], 'deleted old private file' );
jr_same( true, isset( $GLOBALS['wpdb']->tables[ $jobs ][1] ), 'parent retained while child exists' );
jr_same( true, isset( $GLOBALS['wpdb']->tables[ $imports ][1] ), 'parent staging retained while child exists' );
jr_same( false, isset( $GLOBALS['wpdb']->tables[ $jobs ][3] ), 'old backup job deleted' );
jr_same( false, isset( $GLOBALS['wpdb']->tables[ $jobs ][4] ), 'old restore job deleted' );
jr_same( false, isset( $GLOBALS['wpdb']->tables[ $jobs ][5] ), 'old independent import preflight deleted' );
jr_same( false, isset( $GLOBALS['wpdb']->tables[ $imports ][2] ), 'deleted import staging with old preflight' );
jr_same( 0, count( $GLOBALS['wpdb']->tables[ $maps ] ), 'failed restore map cleaned' );
jr_same( true, isset( $GLOBALS['wpdb']->tables[ $jobs ][6] ), 'recent finished job retained' );
jr_same( false, file_exists( $old_file ), 'expired private file removed' );
jr_same( true, file_exists( $fresh_file ), 'fresh private file retained' );
echo "PASS  retention is bounded, dependency-aware, and removes only operational data\n";

@unlink( $fresh_file );
foreach ( glob( $retention_dir . '/*' ) ?: array() as $file ) { @unlink( $file ); }
@rmdir( $retention_dir );
echo "\n1 test, 0 failures\n";
