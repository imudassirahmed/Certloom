<?php
/** Premium transaction-boundary and InnoDB contract regression. */
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
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Database\Transaction;

function tx_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function tx_same( $expected, $actual, $message ) { if ( $expected !== $actual ) { throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); } }

final class TxWpdb {
	public $last_error = '';
	public $script = array();
	public $queries = array();
	public function __construct( array $script = array() ) { $this->script = $script; }
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
tx_assert( is_string( $schema ) && substr_count( $schema, 'ENGINE=InnoDB' ) >= 10, 'every Certloom domain table CREATE contract must explicitly request InnoDB' );
tx_assert( false !== strpos( $schema, 'SHOW TABLE STATUS LIKE %s' ) && false !== strpos( $schema, 'certloom_domain_engine_unsupported' ), 'schema health must independently verify InnoDB at runtime' );

$merge = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Restore/DomainRestoreMergeService.php' );
tx_assert( is_string( $merge ) && false !== strpos( $merge, 'TransactionInterface' ) && false !== strpos( $merge, '$this->transaction->run' ), 'Merge restore must use the shared checked transaction boundary' );
tx_assert( false === strpos( $merge, "'START TRANSACTION'" ) && false === strpos( $merge, "'COMMIT'" ) && false === strpos( $merge, "'ROLLBACK'" ), 'Merge restore must not issue unchecked transaction-control SQL directly' );

echo "PASS  transaction start/commit/rollback failures are checked and fail closed\n";
echo "PASS  uncertain rollback/commit states are surfaced explicitly\n";
echo "PASS  all domain tables explicitly require InnoDB and schema health verifies it\n";
echo "PASS  Merge restore uses the shared checked transaction boundary\n";
echo "\n4 tests, 0 failures\n";
