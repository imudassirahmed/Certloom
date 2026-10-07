<?php
/** Disconnect only this runner's own Local DB session; prove SQL is never replayed without its locks. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
define( 'DISABLE_WP_CRON', true );
require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Data\MaintenanceMutex;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
function loss_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function loss_raw_generation() { global $wpdb; $wpdb->last_error = ''; $value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, DomainGeneration::OPTION ) ); loss_assert( '' === $wpdb->last_error, 'Cannot read marker.' ); return $value; }
function loss_kill_own_session() {
	global $wpdb;
	loss_assert( 0 === $wpdb->reconnect_retries, 'Core reconnect is still enabled inside a protected session.' );
	$id = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ); loss_assert( $id > 0, 'Invalid owned session.' );
	$killer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	loss_assert( 10006 === (int) $killer->get_var( 'SELECT @@port' ) && 'local' === $killer->get_var( 'SELECT DATABASE()' ) && $id !== (int) $killer->get_var( 'SELECT CONNECTION_ID()' ), 'Refusing another server or killer session.' );
	loss_assert( false !== $killer->query( 'KILL CONNECTION ' . $id ), 'Cannot disconnect owned probe session.' ); $killer->close();
}
$mode = $argv[1] ?? '';
if ( in_array( $mode, array( 'transaction', 'mutex' ), true ) ) {
	$nonce = $argv[2] ?? ''; loss_assert( preg_match( '/^session-probe-[a-f0-9]{24}$/', $nonce ), 'Invalid fixture owner.' );
	// Make core's DB error boundary catchable in this CLI probe; production still fails closed at that boundary.
	add_filter( 'wp_die_handler', static function () { return static function () { throw new RuntimeException( 'expected_disconnected_database' ); }; } );
	$old_retries = $wpdb->reconnect_retries; $caught = false;
	try {
		$callback = static function () use ( $mode, $nonce ) {
			global $wpdb;
			if ( 'transaction' === $mode ) {
				$created = ( new IssuerRepository() )->create( array( 'name' => $nonce, 'slug' => $nonce . '-before' ) );
				loss_assert( ! is_wp_error( $created ), 'Cannot stage rollback-only issuer.' );
			}
			loss_kill_own_session();
			if ( 'transaction' === $mode ) {
				$wpdb->insert( SchemaManager::table( 'issuers' ), array( 'name' => $nonce, 'slug' => $nonce . '-after', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ) );
			} else {
				$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i (id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB', $wpdb->prefix . 'certloom_session_probe_' . substr( $nonce, -24 ) ) );
			}
			throw new RuntimeException( 'SQL returned after losing its protected session.' );
		};
		$result = 'transaction' === $mode ? ( new Transaction() )->run( $callback ) : MaintenanceMutex::run( $callback );
		loss_assert( is_wp_error( $result ), 'Disconnected command reported success.' ); $caught = true;
	} catch ( RuntimeException $error ) {
		loss_assert( 'expected_disconnected_database' === $error->getMessage(), $error->getMessage() ); $caught = true;
	}
	loss_assert( $caught && $old_retries === $wpdb->reconnect_retries, 'Disconnected scope did not restore retry configuration.' );
	echo "PASS child failed closed without replay\n"; exit;
}
foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION ) as $key ) { loss_assert( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, $key ) ), 'Existing maintenance state; refusing probe.' ); }
$old_generation = loss_raw_generation();
foreach ( array( 'transaction', 'mutex' ) as $mode ) {
	$nonce = 'session-probe-' . bin2hex( random_bytes( 12 ) ); $table = $wpdb->prefix . 'certloom_session_probe_' . substr( $nonce, -24 );
	try {
		$process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, $mode, $nonce ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		loss_assert( is_resource( $process ), 'Cannot start disconnect probe.' ); fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		loss_assert( 0 === proc_close( $process ) && str_contains( $out, 'PASS child failed closed without replay' ), 'Probe failed: ' . $out . $err );
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE name=%s AND slug IN (%s,%s)', SchemaManager::table( 'issuers' ), $nonce, $nonce . '-before', $nonce . '-after' ) );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		loss_assert( 0 === $rows && null === $exists && $old_generation === loss_raw_generation(), 'An unlocked command was replayed after reconnect.' );
		echo 'PASS  real ' . $mode . " disconnect cannot replay DML or DDL after losing its locks\n";
	} finally {
		// Exact owner tags allow cleanup if the regression fails; no unrelated rows/tables are touched.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE name=%s AND slug IN (%s,%s)', SchemaManager::table( 'issuers' ), $nonce, $nonce . '-before', $nonce . '-after' ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
}
echo "2 live groups, 0 failures\n";
