<?php
/** A recipient update must preserve identity history after an older RR snapshot waits. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( ! $root || realpath( $root ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php'; require __DIR__ . '/isolated-local-sandbox.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX );
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Application\Privacy\IdentityDigest;
function re_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; }
function re_write( $path, array $value ) { if ( false === file_put_contents( $path, wp_json_encode( $value ) ) ) { throw new RuntimeException( 'Private signal unavailable.' ); } }
if ( 'worker' === ( $argv[1] ?? '' ) ) {
	try {
		$dir = realpath( $argv[2] ?? '' ); if ( ! $dir || realpath( dirname( $dir ) ) !== realpath( sys_get_temp_dir() ) || ! preg_match( '/^certloom-isolated-[a-f0-9]{16}$/D', basename( $dir ) ) ) { throw new RuntimeException( 'Exact private worker fixture required.' ); }
		$sandbox = new CertloomIsolatedLocalSandbox( $dir . '/journal.json' ); $sandbox->enter(); $fixture = json_decode( file_get_contents( $dir . '/fixture.json' ), true );
		$result = ( new Transaction() )->run( static function () use ( $dir, $fixture ) {
			global $wpdb; $repo = new RecipientRepository(); $old = $repo->find( $fixture['recipient'] ); if ( is_wp_error( $old ) || ! is_array( $old ) || 'original@example.invalid' !== $old['email'] ) { return new WP_Error( 'qa_snapshot_not_primed' ); }
			re_write( $dir . '/primed.json', array( 'connection' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ), 'original_snapshot' => true ) );
			return $repo->update( $fixture['recipient'], array( 'email' => 'second@example.invalid' ) );
		} ); re_ok( $result ); re_write( $dir . '/complete.json', array( 'success' => true ) ); $sandbox->leave(); exit;
	} catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage() ); exit( 1 ); }
}
$checks = array(); $failure = null; $cleanup = null; $sandbox = null; $directory = null; $worker = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$join = static function () use ( &$worker ) { if ( ! $worker ) { return; } $out = stream_get_contents( $worker[1] ); fclose( $worker[1] ); $exit = proc_close( $worker[0] ); $worker = null; if ( 0 !== $exit ) { throw new RuntimeException( 'Independent recipient worker failed: ' . trim( $out ) ); } };
try {
	$directory = sys_get_temp_dir() . '/certloom-isolated-' . bin2hex( random_bytes( 8 ) ); $check( mkdir( $directory, 0700 ), 'exact private identity fixture created' );
	$sandbox = new CertloomIsolatedLocalSandbox( $directory . '/journal.json' ); $sandbox->create(); $sandbox->enter(); $repo = new RecipientRepository();
	$recipient = re_ok( $repo->create( array( 'display_name' => 'QA Identity history', 'email' => 'original@example.invalid' ) ) ); re_write( $directory . '/fixture.json', array( 'recipient' => $recipient ) );
	$first = ( new Transaction() )->run( function () use ( $repo, $recipient, $directory, &$worker, $check ) {
		global $wpdb; $changed = $repo->update( $recipient, array( 'email' => 'first@example.invalid' ) ); if ( is_wp_error( $changed ) ) { return $changed; }
		$process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker', $directory ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
		if ( ! is_resource( $process ) ) { return new WP_Error( 'qa_worker_unavailable' ); } fclose( $pipes[0] ); $worker = array( $process, $pipes[1] );
		$deadline = microtime( true ) + 20; do { clearstatcache( true, $directory . '/primed.json' ); if ( is_file( $directory . '/primed.json' ) ) { break; } usleep( 50000 ); } while ( microtime( true ) < $deadline );
		$check( is_file( $directory . '/primed.json' ), 'competing command primes its older ordinary-read snapshot' ); $primed = json_decode( file_get_contents( $directory . '/primed.json' ), true );
		$check( $primed['original_snapshot'] && $primed['connection'] !== (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ), 'recipient commands use actual independent database connections' );
		$waiting = false; $deadline = microtime( true ) + 12; do {
			$wpdb->last_error = ''; $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=%d AND trx_state='LOCK WAIT'", $primed['connection'] ) );
			if ( $wpdb->last_error ) { return new WP_Error( 'qa_wait_read_failed' ); } if ( (int) $count > 0 ) { $waiting = true; break; } usleep( 300000 );
		} while ( microtime( true ) < $deadline );
		$check( $waiting && ! is_file( $directory . '/complete.json' ), 'the competing recipient update waits on the current row until the first commit' ); return true;
	} ); re_ok( $first ); $join(); $current = re_ok( $repo->find( $recipient ) ); $digest = new IdentityDigest();
	$expected = $digest->merge_email( $digest->merge_email( $digest->merge_email( array(), 'original@example.invalid' ), 'first@example.invalid' ), 'second@example.invalid' );
	$check( 'second@example.invalid' === $current['email'] && $expected === $current['privacy_identity_hashes'], 'second committed update retains all three exact private identity digests despite its old snapshot' );
	$before = $current; $generation = ( new \Certloom\Infrastructure\Data\DomainGeneration() )->read_current(); re_ok( $repo->update( $recipient, array( 'email' => 'second@example.invalid' ) ) );
	$check( $before === re_ok( $repo->find( $recipient ) ) && $generation === ( new \Certloom\Infrastructure\Data\DomainGeneration() )->read_current(), 'same-email refresh preserves the full row and its generation' );
} catch ( Throwable $error ) { $failure = $error->getMessage(); }
finally {
	try { $join(); if ( $sandbox ) { $cleanup = $sandbox->cleanup(); } foreach ( array( 'fixture.json', 'primed.json', 'complete.json' ) as $name ) { if ( $directory && is_file( $directory . '/' . $name ) && ! unlink( $directory . '/' . $name ) ) { throw new RuntimeException( 'Exact private signal cleanup failed.' ); } } if ( $directory && ! rmdir( $directory ) ) { throw new RuntimeException( 'Exact private identity directory cleanup failed.' ); } $directory = null; }
	catch ( Throwable $error ) { $failure = ( $failure ? $failure . '; ' : '' ) . $error->getMessage(); if ( $sandbox ) { $sandbox->leave(); } }
}
echo wp_json_encode( array( 'success' => null === $failure && is_array( $cleanup ), 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'cleanup' => $cleanup, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure && is_array( $cleanup ) ? 0 : 1 );
