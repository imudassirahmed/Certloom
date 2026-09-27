<?php
/** Real WordPress request-cache regression. Run only against a backed-up disposable Local site. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' );
$backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) {
	fwrite( STDERR, "Set CERTLOOM_TEST_WP_ROOT and CERTLOOM_TEST_DB_BACKUP first.\n" ); exit( 1 );
}
define( 'DISABLE_WP_CRON', true );
require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' ) { throw new RuntimeException( 'Local test environment required.' ); }

use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;

global $wpdb;
$lock = new DomainMaintenanceLock();
$owner = $argv[2] ?? bin2hex( random_bytes( 16 ) );
if ( ( $argv[1] ?? '' ) === 'acquire' ) {
	$result = $lock->acquire( $owner, 'cache_regression', 60 );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); }
	exit;
}
foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::JOURNAL_OPTION, DomainRestoreWorkspace::BUILD_OPTION ) as $key ) {
	if ( null !== get_option( $key, null ) ) { throw new RuntimeException( 'Existing maintenance state; refusing test.' ); }
}
$initial = $lock->active(); // Prime this request's negative option cache.
$command = array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'acquire', $owner );
$process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start second WordPress process.' ); }
fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
try {
	if ( $exit !== 0 ) { throw new RuntimeException( 'Child failed: ' . $stderr . $stdout ); }
	$active = $lock->active();
	$blocked = is_wp_error( $lock->assert_writes_allowed() );
	echo wp_json_encode( array( 'initial_active' => $initial, 'active_after_other_worker' => $active, 'writes_blocked' => $blocked ) ) . "\n";
	if ( ! $active || ! $blocked ) { throw new RuntimeException( 'A cached absence hides another worker\'s maintenance lock.' ); }
} finally {
	// Remove only this test's exact owner, even when the old implementation cached absence.
	$stored = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, DomainMaintenanceLock::OPTION ) );
	if ( is_string( $stored ) && str_starts_with( $stored, $owner . '|' ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, DomainMaintenanceLock::OPTION, $stored ) );
		wp_cache_delete( DomainMaintenanceLock::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' );
	}
}
echo "PASS  another WordPress process's maintenance lock is visible in a warm request\n";
