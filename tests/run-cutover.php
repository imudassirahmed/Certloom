<?php
/** Pure tests for the final source-of-truth cutover safeguards. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', true );
define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', true );
$GLOBALS['cutover_options'] = array();
$GLOBALS['cutover_fail_delete_once'] = false;
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['cutover_options'] ) ? $GLOBALS['cutover_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['cutover_options'][ $key ] = $value; return true; }
function add_option( $key, $value ) { if ( array_key_exists( $key, $GLOBALS['cutover_options'] ) ) { return false; } $GLOBALS['cutover_options'][ $key ] = $value; return true; }
function delete_option( $key ) { if ( array_key_exists( $key, $GLOBALS['cutover_options'] ) ) { unset( $GLOBALS['cutover_options'][ $key ] ); return true; } return false; }
function wp_cache_delete() { return true; }

class CutoverWpdb {
	public $options = 'wp_options';
	public $last_error = '';
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function query( $prepared ) {
		$sql = is_array( $prepared ) ? $prepared[0] : (string) $prepared;
		$args = is_array( $prepared ) ? $prepared[1] : array();
		if ( false !== strpos( $sql, 'DELETE FROM %i' ) ) {
			$key = $args[1] ?? '';
			$expected = $args[2] ?? '';
			if ( ! empty( $GLOBALS['cutover_fail_delete_once'] ) ) {
				$GLOBALS['cutover_fail_delete_once'] = false;
				return 0;
			}
			if ( isset( $GLOBALS['cutover_options'][ $key ] ) && $GLOBALS['cutover_options'][ $key ] === $expected ) {
				unset( $GLOBALS['cutover_options'][ $key ] );
				return 1;
			}
			return 0;
		}
		if ( false !== strpos( $sql, 'UPDATE %i SET option_value' ) ) {
			$new = $args[1] ?? '';
			$key = $args[2] ?? '';
			$old = $args[3] ?? '';
			if ( isset( $GLOBALS['cutover_options'][ $key ] ) && $GLOBALS['cutover_options'][ $key ] === $old ) {
				$GLOBALS['cutover_options'][ $key ] = $new;
				return 1;
			}
			return 0;
		}
		return 1;
	}
}
$GLOBALS['wpdb'] = new CutoverWpdb();

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\LegacySourceSnapshotInterface;
use Certloom\Application\Migration\DomainCutoverState;
use Certloom\Application\Migration\LegacyMigrationState;
use Certloom\Application\Migration\LegacyRegistryWriteGuard;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Migration\DomainCutoverCoordinator;

function cut_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

class CutoverSource implements LegacySourceSnapshotInterface {
	public $snapshot;
	public $saw_lock = false;
	public function __construct( array $snapshot ) { $this->snapshot = $snapshot; }
	public function snapshot() {
		$this->saw_lock = ( new DomainMaintenanceLock() )->active();
		return $this->snapshot;
	}
}

$state = new LegacyMigrationState();
$GLOBALS['cutover_options'][ LegacyMigrationState::OPTION ] = array(
	'status' => LegacyMigrationState::COMPLETE,
	'source_count' => 7,
	'source_max_id' => 11,
	'source_generation' => 'generation-A',
);

cut_same( true, $state->source_snapshot_matches( array( 'count' => 7, 'max_id' => 11, 'generation' => 'generation-A' ) ), 'matching source' );
cut_same( false, $state->source_snapshot_matches( array( 'count' => 7, 'max_id' => 11, 'generation' => 'generation-B' ) ), 'changed generation' );

$stale_source = new CutoverSource( array( 'count' => 7, 'max_id' => 11, 'generation' => 'generation-B' ) );
$coordinator = new DomainCutoverCoordinator( $stale_source, null, $state, null, function(){ return true; } );
$result = $coordinator->promote();
cut_same( 'certloom_domain_cutover_source_changed', $result->get_error_code(), 'stale migration cannot cut over' );
cut_same( true, $stale_source->saw_lock, 'final source snapshot is taken while maintenance lock is active' );
$cutover = new DomainCutoverState();
cut_same( DomainCutoverState::LEGACY, $cutover->authority(), 'authority remains legacy on stale source' );

$fresh_source = new CutoverSource( array( 'count' => 7, 'max_id' => 11, 'generation' => 'generation-A' ) );
$coordinator = new DomainCutoverCoordinator( $fresh_source, null, $state, null, function(){ return true; } );
cut_same( true, $coordinator->promote(), 'fresh migration can cut over' );
cut_same( true, $fresh_source->saw_lock, 'successful cutover final snapshot is maintenance protected' );
cut_same( DomainCutoverState::DOMAIN, $cutover->authority(), 'authority promoted after exact source match' );
cut_same( true, $cutover->has_committed_receipt(), 'official cutover commits durable receipt' );
cut_same( false, ( new DomainMaintenanceLock() )->active(), 'cutover maintenance lock released' );
cut_same( false, get_option( DomainCutoverCoordinator::OWNER_OPTION, false ), 'cutover recovery owner cleared' );

$guard = new LegacyRegistryWriteGuard();
$blocked_write = $guard->assert_allowed();
cut_same( 'certloom_legacy_registry_read_only', $blocked_write->get_error_code(), 'legacy writes blocked after cutover' );
cut_same( true, $cutover->keep_legacy_authoritative(), 'legacy authority can be restored before public cutover' );
cut_same( false, $cutover->has_committed_receipt(), 'rollback clears cutover receipt' );
cut_same( true, $guard->assert_allowed(), 'legacy writes allowed while legacy remains authoritative' );

/* A transient maintenance-lock release failure leaves a durable owner token so retry can clean it idempotently. */
$GLOBALS['cutover_fail_delete_once'] = true;
$coordinator = new DomainCutoverCoordinator( new CutoverSource( array( 'count' => 7, 'max_id' => 11, 'generation' => 'generation-A' ) ), null, $state, null, function(){ return true; } );
$result = $coordinator->promote();
cut_same( 'certloom_domain_cutover_cleanup_failed', $result->get_error_code(), 'cleanup failure is explicit' );
cut_same( DomainCutoverState::DOMAIN, $cutover->authority(), 'authority result remains explicit after cleanup failure' );
cut_same( true, (bool) get_option( DomainCutoverCoordinator::OWNER_OPTION, false ), 'owner retained for cleanup retry' );
cut_same( true, ( new DomainMaintenanceLock() )->active(), 'writes remain paused while cleanup uncertain' );
cut_same( true, $coordinator->promote(), 'retry with same durable owner completes cleanup' );
cut_same( false, ( new DomainMaintenanceLock() )->active(), 'retry clears maintenance lock' );
cut_same( false, get_option( DomainCutoverCoordinator::OWNER_OPTION, false ), 'retry clears durable owner' );

echo "PASS  cutover takes final source snapshot under a recoverable maintenance boundary\n\n1 tests, 0 failures\n";
