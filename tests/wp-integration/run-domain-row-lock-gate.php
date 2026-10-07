<?php
/**
 * Real two-process InnoDB row-lock gate for the Certloom Credential Series.
 *
 * Run on a disposable WordPress/MySQL installation under WP-CLI:
 *   wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-row-lock-gate.php
 */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Run inside WordPress.\n" ); exit( 1 ); }
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) { wp_die( 'Certloom row-lock integration gate requires WP-CLI.' ); }
if ( ! function_exists( 'pcntl_fork' ) || ! function_exists( 'pcntl_waitpid' ) ) {
	fwrite( STDERR, "FAIL  The development gate environment needs PHP pcntl to prove two-process row locking.\n" );
	exit( 1 );
}

require_once __DIR__ . '/gate-evidence.php';
certloom_wp_integration_evidence( 'domain-row-lock', __FILE__ );

use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;

function cl_lock_ok( $value, $message ) {
	if ( is_wp_error( $value ) ) { throw new RuntimeException( $message . ': ' . $value->get_error_code() . ' — ' . $value->get_error_message() ); }
	return $value;
}

$recipient_id = 0; $series_id = 0;
$token = bin2hex( random_bytes( 8 ) );
$signal = trailingslashit( sys_get_temp_dir() ) . 'certloom-row-lock-' . $token . '.signal';
$result_file = trailingslashit( sys_get_temp_dir() ) . 'certloom-row-lock-' . $token . '.json';
$exit_code = 0;
try {
	cl_lock_ok( SchemaManager::activate(), 'Schema activation failed' );
	$recipient_id = cl_lock_ok( ( new RecipientRepository() )->create( array( 'display_name' => 'Row Lock Gate ' . $token ) ), 'Recipient create failed' );
	$series_id = cl_lock_ok( ( new CredentialRepository() )->create_series( array( 'recipient_id' => $recipient_id ) ), 'Series create failed' );

	$pid = pcntl_fork();
	if ( -1 === $pid ) { throw new RuntimeException( 'Could not fork the second database worker.' ); }
	if ( 0 === $pid ) {
		$deadline = microtime( true ) + 8.0;
		while ( ! is_file( $signal ) && microtime( true ) < $deadline ) { usleep( 20000 ); }
		if ( ! is_file( $signal ) ) { file_put_contents( $result_file, wp_json_encode( array( 'ok' => false, 'error' => 'lock signal timeout' ) ) ); exit( 2 ); }

		// Never share the inherited MySQL connection between forked workers.
		$child_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		if ( ! empty( $child_db->last_error ) ) { file_put_contents( $result_file, wp_json_encode( array( 'ok' => false, 'error' => $child_db->last_error ) ) ); exit( 3 ); }
		$GLOBALS['wpdb'] = $child_db;
		$start = microtime( true );
		$outcome = ( new Transaction() )->run( function () use ( $series_id ) {
			$row = ( new CredentialRepository() )->find_series_for_update( $series_id );
			return is_wp_error( $row ) ? $row : true;
		} );
		$elapsed = microtime( true ) - $start;
		file_put_contents( $result_file, wp_json_encode( array( 'ok' => ! is_wp_error( $outcome ), 'elapsed' => $elapsed, 'error' => is_wp_error( $outcome ) ? $outcome->get_error_message() : '' ) ) );
		exit( is_wp_error( $outcome ) ? 4 : 0 );
	}

	$parent_result = ( new Transaction() )->run( function () use ( $series_id, $signal ) {
		$row = ( new CredentialRepository() )->find_series_for_update( $series_id );
		if ( is_wp_error( $row ) ) { return $row; }
		if ( ! is_array( $row ) ) { return new WP_Error( 'certloom_gate_series_missing', 'Row-lock Series disappeared.' ); }
		if ( false === file_put_contents( $signal, 'locked' ) ) { return new WP_Error( 'certloom_gate_signal_failed', 'Could not create row-lock coordination signal.' ); }
		sleep( 3 ); // Child must block on the same FOR UPDATE row while this transaction is open.
		return true;
	} );
	cl_lock_ok( $parent_result, 'Parent row-lock transaction failed' );

	$status = 0; pcntl_waitpid( $pid, $status );
	if ( ! is_file( $result_file ) ) { throw new RuntimeException( 'Second worker produced no row-lock result.' ); }
	$child = json_decode( (string) file_get_contents( $result_file ), true );
	if ( ! is_array( $child ) || empty( $child['ok'] ) ) { throw new RuntimeException( 'Second worker failed: ' . (string) ( $child['error'] ?? 'unknown failure' ) ); }
	$elapsed = (float) ( $child['elapsed'] ?? 0 );
	if ( $elapsed < 2.25 ) { throw new RuntimeException( sprintf( 'FOR UPDATE did not block the concurrent worker long enough (%.3fs).', $elapsed ) ); }
	echo sprintf( "PASS  concurrent FOR UPDATE blocks on the same Credential Series row (%.3fs)\n", $elapsed );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL  ' . $error->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	@unlink( $signal ); @unlink( $result_file );
	global $wpdb;
	if ( $series_id ) {
		$wpdb->delete( SchemaManager::table( 'credential_events' ), array( 'series_id' => $series_id ), array( '%d' ) );
		$wpdb->delete( SchemaManager::table( 'credential_numbers' ), array( 'series_id' => $series_id ), array( '%d' ) );
		$wpdb->delete( SchemaManager::table( 'credential_versions' ), array( 'series_id' => $series_id ), array( '%d' ) );
		$wpdb->delete( SchemaManager::table( 'credential_series' ), array( 'id' => $series_id ), array( '%d' ) );
	}
	if ( $recipient_id ) { $wpdb->delete( SchemaManager::table( 'recipients' ), array( 'id' => $recipient_id ), array( '%d' ) ); }
}
exit( $exit_code );
