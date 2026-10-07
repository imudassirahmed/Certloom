<?php
/** Warm-request backup consistency regression against the exact backed-up Local site. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
define( 'DISABLE_WP_CRON', true );
require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }

use Certloom\Application\Backup\DomainBackupBuilder;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;

function bc_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function bc_raw_generation() { global $wpdb; return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, DomainGeneration::OPTION ) ); }
if ( ( $argv[1] ?? '' ) === 'change' ) {
	$value = $argv[2];
	bc_assert( preg_match( '/^cache-probe-[a-f0-9]{32}$/', $value ), 'Invalid fixture marker.' );
	update_option( DomainGeneration::OPTION, $value, false );
	bc_assert( $value === bc_raw_generation(), 'Cannot commit independent change marker.' ); exit;
}
foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION ) as $key ) {
	bc_assert( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name = %s', $wpdb->options, $key ) ), 'Existing maintenance state; refusing test.' );
}
$old = bc_raw_generation(); $nonce = 'cache-probe-' . bin2hex( random_bytes( 16 ) ); $path = '';
try {
	$builder = new DomainBackupBuilder();
	$state = $builder->start( bin2hex( random_bytes( 16 ) ), CERTLOOM_VERSION );
	bc_assert( ! is_wp_error( $state ), 'Cannot start real native backup.' ); $path = $state['path'];
	$warm = (string) get_option( DomainGeneration::OPTION, '0' ); // Deliberately prime the request-local option cache after snapshot ownership is released.
	$process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'change', $nonce ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	bc_assert( is_resource( $process ), 'Cannot start independent change process.' );
	fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
	bc_assert( proc_close( $process ) === 0, 'Independent change failed: ' . $out . $err );
	bc_assert( $nonce === bc_raw_generation(), 'Another process did not commit its marker.' );
	for ( $slice = 0; $slice < 1000 && ! is_wp_error( $state ) && empty( $state['complete'] ); $slice++ ) { $state = $builder->continue_build( $state, 7 ); }
	echo wp_json_encode( array( 'warm_matches_snapshot' => $warm !== $nonce, 'backup_rejected' => is_wp_error( $state ), 'completed' => is_array( $state ) && ! empty( $state['complete'] ), 'error' => is_wp_error( $state ) ? $state->get_error_code() : null ) ) . "\n";
	bc_assert( is_wp_error( $state ) && 'certloom_domain_backup_concurrent_change' === $state->get_error_code(), 'A warm request accepted a backup after another process changed its generation.' );
	echo "PASS  warm-request native backup rejects another process's generation change\n";
} finally {
	// CAS restores only this probe's marker, preserving any unrelated later update.
	if ( $old === null ) { $sql = $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, DomainGeneration::OPTION, $nonce ); }
	else { $sql = $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $old, DomainGeneration::OPTION, $nonce ); }
	if ( $nonce === bc_raw_generation() ) { bc_assert( 1 === (int) $wpdb->query( $sql ), 'Cannot restore this probe\'s exact previous marker.' ); }
	foreach ( array( DomainGeneration::OPTION, 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
	if ( $path && is_file( $path ) ) { $removed = ( new PrivateJobFileStore() )->delete( $path ); bc_assert( ! is_wp_error( $removed ) && $removed !== false, 'Cannot remove probe backup.' ); }
	bc_assert( $old === bc_raw_generation(), 'Probe changed the generation marker.' );
}
