<?php
/** Premium transaction-boundary and InnoDB contract regression. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
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
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Database\Transaction;

function tx_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function tx_same( $expected, $actual, $message ) { if ( $expected !== $actual ) { throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); } }

final class TxWpdb {
	public $options = 'wp_options';
	public $last_error = '';
	public $maintenance = null;
	public $fence_error = false;
	public $engine = 'InnoDB';
	public $script = array();
	public $queries = array();
	public $reads = array();
	public function __construct( array $script = array() ) { $this->script = $script; }
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_var( $prepared ) {
		$this->last_error = ''; list( $query, $args ) = $prepared; $this->reads[] = $query;
		if ( strpos( $query, 'SELECT ENGINE' ) === 0 ) { return $this->engine; }
		if ( strpos( $query, 'SELECT option_name' ) === 0 ) { return null; }
		if ( ( $args[1] ?? '' ) === 'certloom_domain_write_fence' ) {
			if ( $this->fence_error && strpos( $query, 'LOCK IN SHARE MODE' ) !== false ) { $this->last_error = 'fence failed'; return null; }
			return '1';
		}
		return $this->maintenance;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		$this->last_error = '';
		$step = array_shift( $this->script );
		if ( is_array( $step ) ) {
			$this->last_error = (string) ( $step['error'] ?? '' );
			return $step['result'] ?? 0;
		}
		return 0;
	}
}

// Successful command.
$GLOBALS['wpdb'] = new TxWpdb( array( array( 'result' => 0 ), array( 'result' => 0 ) ) );
$result = ( new Transaction() )->run( function () { return 'ok'; } );
tx_same( 'ok', $result, 'successful transaction result' );
tx_same( array( 'START TRANSACTION', 'COMMIT' ), $GLOBALS['wpdb']->queries, 'success transaction statements' );

// Start failure never invokes callback.
$GLOBALS['wpdb'] = new TxWpdb( array( array( 'result' => false, 'error' => 'start failed' ) ) );
$called = false;
$result = ( new Transaction() )->run( function () use ( &$called ) { $called = true; return 'unexpected'; } );
tx_assert( is_wp_error( $result ) && 'certloom_transaction_start_failed' === $result->get_error_code(), 'start failure must fail closed' );
tx_same( false, $called, 'callback must not run after failed START TRANSACTION' );

// Application error with successful rollback preserves the application error.
$GLOBALS['wpdb'] = new TxWpdb( array( array( 'result' => 0 ), array( 'result' => 0 ) ) );
$result = ( new Transaction() )->run( function () { return new WP_Error( 'application_failed', 'expected' ); } );
tx_assert( is_wp_error( $result ) && 'application_failed' === $result->get_error_code(), 'successful rollback should preserve application error' );
tx_same( array( 'START TRANSACTION', 'ROLLBACK' ), $GLOBALS['wpdb']->queries, 'application failure must rollback' );

// Rollback failure overrides the ordinary application error because database state is uncertain.
$GLOBALS['wpdb'] = new TxWpdb( array( array( 'result' => 0 ), array( 'result' => false, 'error' => 'rollback failed' ) ) );
$result = ( new Transaction() )->run( function () { return new WP_Error( 'application_failed', 'expected' ); } );
tx_assert( is_wp_error( $result ) && 'certloom_transaction_rollback_failed' === $result->get_error_code(), 'rollback failure must surface integrity uncertainty' );

// Commit failure with successful rollback reports commit failure.
$GLOBALS['wpdb'] = new TxWpdb( array( array( 'result' => 0 ), array( 'result' => false, 'error' => 'commit failed' ), array( 'result' => 0 ) ) );
$result = ( new Transaction() )->run( function () { return true; } );
tx_assert( is_wp_error( $result ) && 'certloom_transaction_commit_failed' === $result->get_error_code(), 'commit failure must be reported after successful rollback' );
tx_same( array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), $GLOBALS['wpdb']->queries, 'failed commit must attempt rollback' );

// Commit + rollback failure gets its own state-uncertain error.
$GLOBALS['wpdb'] = new TxWpdb( array( array( 'result' => 0 ), array( 'result' => false, 'error' => 'commit failed' ), array( 'result' => false, 'error' => 'rollback failed' ) ) );
$result = ( new Transaction() )->run( function () { return true; } );
tx_assert( is_wp_error( $result ) && 'certloom_transaction_commit_rollback_failed' === $result->get_error_code(), 'commit+rollback failure must surface uncertain database state' );

// Unexpected exception with successful rollback becomes a controlled fail-soft WP_Error.
$GLOBALS['wpdb'] = new TxWpdb( array( array( 'result' => 0 ), array( 'result' => 0 ) ) );
$result = ( new Transaction() )->run( function () { throw new LogicException( 'boom' ); } );
tx_assert( is_wp_error( $result ) && 'certloom_transaction_callback_failed' === $result->get_error_code(), 'unexpected callback exception must fail soft after confirmed rollback' );

// Unexpected exception + failed rollback keeps the stronger integrity-uncertain signal.
$GLOBALS['wpdb'] = new TxWpdb( array( array( 'result' => 0 ), array( 'result' => false, 'error' => 'rollback failed' ) ) );
$result = ( new Transaction() )->run( function () { throw new LogicException( 'boom' ); } );
tx_assert( is_wp_error( $result ) && 'certloom_transaction_callback_rollback_failed' === $result->get_error_code(), 'exception + rollback failure must surface uncertain database state' );

$schema = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Database/SchemaManager.php' );
// Admission is fenced before the callback; an active lease never invokes it.
$GLOBALS['wpdb'] = new TxWpdb();
$GLOBALS['wpdb']->maintenance = str_repeat( 'a', 32 ) . '|' . time() . '|' . ( time() + 60 ) . '|restore';
$called = false;
$result = ( new Transaction() )->run( function () use ( &$called ) { $called = true; return true; } );
tx_assert( is_wp_error( $result ) && 'certloom_domain_maintenance_write_locked' === $result->get_error_code() && ! $called, 'active maintenance prevents callback entry' );
tx_same( array( 'START TRANSACTION', 'ROLLBACK' ), $GLOBALS['wpdb']->queries, 'blocked admission rolls back shared fence' );
tx_assert( in_array( 'SELECT option_value FROM %i WHERE option_name = %s LOCK IN SHARE MODE', $GLOBALS['wpdb']->reads, true ), 'admission acquires an InnoDB shared lock' );
$GLOBALS['wpdb'] = new TxWpdb(); $GLOBALS['wpdb']->fence_error = true;
$result = ( new Transaction() )->run( function () { throw new RuntimeException( 'Must not run' ); } );
tx_assert( is_wp_error( $result ) && 'certloom_write_fence_unavailable' === $result->get_error_code(), 'failed shared fence blocks callback' );
$GLOBALS['wpdb'] = new TxWpdb(); $GLOBALS['wpdb']->engine = 'MyISAM';
$result = ( new Transaction() )->run( function () { throw new RuntimeException( 'Must not run' ); } );
tx_assert( is_wp_error( $result ) && 'certloom_write_fence_engine_unsupported' === $result->get_error_code() && ! $GLOBALS['wpdb']->queries, 'nontransactional options cannot admit a credential transaction' );

tx_assert( is_string( $schema ) && substr_count( $schema, 'ENGINE=InnoDB' ) >= 10, 'every Certloom domain table CREATE contract must explicitly request InnoDB' );
tx_assert( false !== strpos( $schema, 'SHOW TABLE STATUS LIKE %s' ) && false !== strpos( $schema, 'certloom_domain_engine_unsupported' ), 'schema health must independently verify InnoDB at runtime' );

$merge = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Restore/DomainRestoreMergeService.php' );
tx_assert( is_string( $merge ) && false !== strpos( $merge, 'TransactionInterface' ) && false !== strpos( $merge, '$this->transaction->run' ), 'Merge restore must use the shared checked transaction boundary' );
tx_assert( false === strpos( $merge, "'START TRANSACTION'" ) && false === strpos( $merge, "'COMMIT'" ) && false === strpos( $merge, "'ROLLBACK'" ), 'Merge restore must not issue unchecked transaction-control SQL directly' );

echo "PASS  transaction start/commit/rollback failures are checked and fail closed\n";
echo "PASS  uncertain rollback/commit states are surfaced explicitly\n";
echo "PASS  all domain tables explicitly require InnoDB and schema health verifies it\n";
echo "PASS  Merge restore uses the shared checked transaction boundary\n";
echo "PASS  shared fence admission blocks active maintenance, read failure and nontransactional options\n";
$GLOBALS['wpdb'] = new TxWpdb();
$result = ( new Transaction() )->run( function () { return ( new Transaction() )->run( function () { return 'nested'; } ); } );
tx_same( 'nested', $result, 'nested result' );
tx_same( 1, count( array_keys( $GLOBALS['wpdb']->queries, 'START TRANSACTION', true ) ), 'only outer command starts a transaction' );
tx_same( 1, count( array_keys( $GLOBALS['wpdb']->queries, 'COMMIT', true ) ), 'only outer command commits' );
tx_assert( strpos( $GLOBALS['wpdb']->queries[1], 'SAVEPOINT certloom_command_' ) === 0 && strpos( $GLOBALS['wpdb']->queries[2], 'RELEASE SAVEPOINT certloom_command_' ) === 0, 'nested command owns a checked savepoint' );
echo "PASS  nested repository commands do not implicitly commit their outer transaction\n";
$GLOBALS['wpdb'] = new TxWpdb();
$result = ( new Transaction() )->run( function () {
	$inner = ( new Transaction() )->run( function () { return new WP_Error( 'expected_duplicate', 'duplicate' ); } );
	tx_same( 'expected_duplicate', $inner->get_error_code(), 'handled inner error preserved' );
	return 'handled';
} );
tx_same( 'handled', $result, 'handled error may continue after checked savepoint rollback' );
tx_assert( strpos( $GLOBALS['wpdb']->queries[2], 'ROLLBACK TO SAVEPOINT certloom_command_' ) === 0, 'inner error rolls back its writes before handler continues' );
echo "PASS  an expected nested failure is rolled back before the outer command handles it\n";
$GLOBALS['wpdb'] = new TxWpdb( array( array( 'result' => 0 ), array( 'result' => 0 ), array( 'result' => false, 'error' => 'savepoint rollback failed' ), array( 'result' => 0 ) ) );
$result = ( new Transaction() )->run( function () { ( new Transaction() )->run( function () { return new WP_Error( 'failure' ); } ); return 'ignored'; } );
tx_same( 'certloom_transaction_savepoint_rollback_failed', $result->get_error_code(), 'failed nested rollback cannot be ignored' );
tx_same( 'ROLLBACK', end( $GLOBALS['wpdb']->queries ), 'uncertain nested command rolls back the complete outer transaction' );
echo "PASS  failed savepoint control forces the outer update to roll back even if its caller ignores the error\n";
$GLOBALS['wpdb'] = new TxWpdb();
$result = ( new Transaction() )->run( function () { return ( new Transaction() )->run( function () { throw new LogicException( 'nested exception' ); } ); } );
tx_same( 'certloom_transaction_callback_failed', $result->get_error_code(), 'nested exception controlled' );
tx_same( 'ROLLBACK', end( $GLOBALS['wpdb']->queries ), 'unhandled nested exception rolls back outer transaction' );
echo "PASS  nested exceptions roll back both the command savepoint and its unhandled outer transaction\n";
echo "\n9 tests, 0 failures\n";
