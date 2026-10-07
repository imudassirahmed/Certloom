<?php
/** Two-process writer/restore exclusion on the explicitly backed-up disposable Local site. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' );
$backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) {
	fwrite( STDERR, "The exact disposable Local root and a database snapshot are required.\n" ); exit( 1 );
}
define( 'DISABLE_WP_CRON', true );
require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) {
	throw new RuntimeException( 'Refusing another site/database.' );
}

use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;

function wf_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function wf_option( $key ) { global $wpdb; return maybe_unserialize( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) ) ); }
function wf_spawn( array $args ) {
	// Windows anonymous stdout pipes do not provide dependable nonblocking fgets.
	$stdout = tempnam( sys_get_temp_dir(), 'certloom-writer-out-' ); $stderr = tempnam( sys_get_temp_dir(), 'certloom-writer-err-' );
	wf_assert( $stdout && $stderr, 'Cannot create small worker-output fixtures.' );
	$p = proc_open( array_merge( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__ ), $args ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $stdout, 'w' ), 2 => array( 'file', $stderr, 'w' ) ), $pipes );
	wf_assert( is_resource( $p ), 'Cannot start independent WordPress worker.' );
	return array( $p, $pipes, $stdout, $stderr );
}
function wf_poll_line( array $child ) {
	static $offsets = array();
	$offset = $offsets[ $child[2] ] ?? 0;
	$text = (string) file_get_contents( $child[2] );
	$end = strpos( $text, "\n", $offset );
	if ( false === $end ) { return null; }
	$offsets[ $child[2] ] = $end + 1;
	return trim( substr( $text, $offset, $end - $offset ) );
}
function wf_line( array $child, $seconds = 12 ) {
	$deadline = microtime( true ) + $seconds;
	do { $line = wf_poll_line( $child ); if ( null !== $line ) { return $line; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
	throw new RuntimeException( 'Worker timeout: ' . file_get_contents( $child[2] ) . file_get_contents( $child[3] ) );
}
function wf_finish( array $child ) {
	foreach ( $child[1] as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
	$status = proc_close( $child[0] );
	$error = (string) file_get_contents( $child[3] ); unlink( $child[2] ); unlink( $child[3] );
	wf_assert( 0 === $status, 'Worker failed with status ' . $status . ': ' . $error );
}

$action = $argv[1] ?? 'test';
if ( 'legacy-writer' === $action ) {
	$paused = false;
	$pause = static function ( $key ) use ( &$paused ) {
		if ( Ecapla_CertVerify_Backup::GENERATION_OPTION === $key && ! $paused ) { $paused = true; echo "BARRIER legacy_before_sql\n"; flush(); wf_assert( trim( (string) fgets( STDIN ) ) === 'continue', 'Legacy writer not resumed.' ); }
	};
	add_action( 'added_option', $pause ); add_action( 'updated_option', $pause );
	$result = ( new Transaction() )->run( static function () {
		$settings = Ecapla_CertVerify_Settings::get_all(); $settings['numbering_prefix'] = 'FENCE-PROBE-';
		wf_assert( Ecapla_CertVerify_Settings::save( $settings ), 'Legacy settings write failed.' );
		return new WP_Error( 'intentional_legacy_fence_rollback', 'Rollback-only probe.' );
	} );
	wf_assert( is_wp_error( $result ) && 'intentional_legacy_fence_rollback' === $result->get_error_code(), 'Legacy writer did not roll back.' );
	echo "RESULT legacy_rolled_back\n"; exit;
}
if ( 'legacy-maintenance' === $action ) {
	$method = new ReflectionMethod( Ecapla_CertVerify_Backup::class, 'acquire_restore_maintenance_lock' );
	if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
	$result = Certloom\Infrastructure\Data\MaintenanceMutex::run( static function () use ( $method, $argv ) { return $method->invoke( null, $argv[2] ); } );
	echo is_wp_error( $result ) ? 'RESULT blocked ' . $result->get_error_code() . "\n" : "RESULT acquired\n"; exit;
}
if ( 'parallel-reader' === $action ) {
	$result = ( new Transaction() )->run( static function () {
		echo "BARRIER shared_admitted\n"; flush();
		wf_assert( trim( (string) fgets( STDIN ) ) === 'continue', 'Shared reader not resumed.' );
		return true;
	} );
	wf_assert( ! is_wp_error( $result ), 'Ordinary transaction could not hold its shared fence.' );
	echo "RESULT reader_committed\n"; exit;
}
if ( 'writer' === $action ) {
	$issuer_id = (int) $argv[2];
	$paused = false;
	$pause = static function ( $key ) use ( &$paused ) {
		if ( DomainGeneration::OPTION === $key && ! $paused ) { $paused = true; echo "BARRIER writer_before_sql\n"; flush(); wf_assert( trim( (string) fgets( STDIN ) ) === 'continue', 'Writer not resumed.' ); }
	};
	add_action( 'added_option', $pause ); add_action( 'updated_option', $pause );
	$result = ( new Transaction() )->run( static function () use ( $issuer_id ) {
		$result = ( new IssuerRepository() )->update( $issuer_id, array( 'name' => '__rolled back fence probe__' ) );
		return is_wp_error( $result ) ? $result : new WP_Error( 'intentional_fence_rollback', 'Rollback-only probe.' );
	} );
	wf_assert( is_wp_error( $result ) && 'intentional_fence_rollback' === $result->get_error_code(), 'Writer did not complete its rollback-only command.' );
	echo "RESULT writer_rolled_back\n"; exit;
}
if ( 'maintenance' === $action ) {
	$owner = $argv[2];
	$result = ( new DomainRestoreWorkspace() )->create( $owner );
	if ( is_wp_error( $result ) ) { echo 'RESULT blocked ' . $result->get_error_code() . "\n"; }
	else { echo "RESULT acquired\n"; }
	exit;
}

foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION ) as $key ) { wf_assert( null === wf_option( $key ), 'Existing maintenance state; refusing test.' ); }
$issuer = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id LIMIT 1', SchemaManager::table( 'issuers' ) ), ARRAY_A );
wf_assert( is_array( $issuer ), 'Existing issuer required.' );
$generation = wf_option( DomainGeneration::OPTION );
$owner = bin2hex( random_bytes( 16 ) ); $writer = null; $maintenance = null; $reader = null; $early = null;
try {
	$writer = wf_spawn( array( 'writer', (string) $issuer['id'] ) );
	wf_assert( wf_line( $writer ) === 'BARRIER writer_before_sql', 'Writer did not pause after its maintenance check.' );
	$maintenance = wf_spawn( array( 'maintenance', $owner ) );
	// A successful acquisition while this writer is paused is the exact forbidden race.
	$deadline = microtime( true ) + 4.0;
	do { $line = wf_poll_line( $maintenance ); if ( null !== $line ) { $early = $line; break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
	echo wp_json_encode( array( 'maintenance_while_writer_paused' => $early ) ) . "\n";
	fwrite( $writer[1][0], "continue\n" ); fflush( $writer[1][0] );
	wf_assert( wf_line( $writer ) === 'RESULT writer_rolled_back', 'Writer rollback missing.' ); wf_finish( $writer ); $writer = null;
	$completion = $early ?? wf_line( $maintenance ); wf_finish( $maintenance ); $maintenance = null;
	wf_assert( $early !== 'RESULT acquired', 'Restore acquired maintenance while an ordinary writer had already passed its guard inside an open transaction.' );
	wf_assert( str_starts_with( $completion, 'RESULT blocked ' ) || $completion === 'RESULT acquired', 'Unexpected maintenance result.' );
	echo "PASS  restore acquisition cannot overtake an in-flight native writer\n";
	// A fast worker may acquire after the rollback. Release that owned lease
	// before testing ordinary shared admission; active maintenance must deny it.
	if ( 'RESULT acquired' === $completion ) {
		$build = wf_option( DomainRestoreWorkspace::BUILD_OPTION );
		wf_assert( is_array( $build ) && ( $build['owner_token'] ?? '' ) === $owner, 'Acquired workspace does not belong to this probe.' );
		wf_assert( ! is_wp_error( ( new DomainRestoreWorkspace() )->abandon( $build ) ), 'Cannot abandon the first owned workspace.' );
	}
	$writer = wf_spawn( array( 'parallel-reader' ) );
	wf_assert( wf_line( $writer ) === 'BARRIER shared_admitted', 'First shared transaction admission missing.' );
	$reader = wf_spawn( array( 'parallel-reader' ) );
	wf_assert( wf_line( $reader ) === 'BARRIER shared_admitted', 'Second ordinary transaction cannot overlap the first.' );
	foreach ( array( $writer, $reader ) as $child ) { fwrite( $child[1][0], "continue\n" ); fflush( $child[1][0] ); }
	wf_assert( wf_line( $writer ) === 'RESULT reader_committed' && wf_line( $reader ) === 'RESULT reader_committed', 'Shared transaction completion missing.' );
	wf_finish( $writer ); $writer = null; wf_finish( $reader ); $reader = null;
	echo "PASS  ordinary transactions can hold shared fences concurrently\n";

	// A brief writer finishes before the maintenance timeout: acquisition then succeeds.
	$writer = wf_spawn( array( 'writer', (string) $issuer['id'] ) );
	wf_assert( wf_line( $writer ) === 'BARRIER writer_before_sql', 'Second writer barrier missing.' );
	$maintenance = wf_spawn( array( 'maintenance', $owner ) );
	usleep( 700000 );
	wf_assert( null === wf_poll_line( $maintenance ), 'Maintenance must wait while the brief writer is paused.' );
	fwrite( $writer[1][0], "continue\n" ); fflush( $writer[1][0] );
	wf_assert( wf_line( $writer ) === 'RESULT writer_rolled_back', 'Second writer rollback missing.' ); wf_finish( $writer ); $writer = null;
	wf_assert( wf_line( $maintenance ) === 'RESULT acquired', 'Maintenance did not resume after the brief writer finished.' ); wf_finish( $maintenance ); $maintenance = null;
	echo "PASS  maintenance succeeds after a brief admitted writer finishes\n";
	$called = false;
	$result = ( new Transaction() )->run( static function () use ( &$called ) { $called = true; return true; } );
	wf_assert( ! $called && is_wp_error( $result ) && $result->get_error_code() === 'certloom_domain_maintenance_write_locked', 'A new writer entered after the maintenance lease was published.' );
	echo "PASS  active maintenance blocks new ordinary transaction callbacks\n";
} finally {
	foreach ( array( $writer, $maintenance, $reader ) as $child ) { if ( is_array( $child ) ) { if ( is_resource( $child[0] ) ) { proc_terminate( $child[0] ); } foreach ( $child[1] as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } } if ( is_resource( $child[0] ) ) { proc_close( $child[0] ); } foreach ( array( $child[2], $child[3] ) as $path ) { if ( is_file( $path ) ) { unlink( $path ); } } } }
	$build = wf_option( DomainRestoreWorkspace::BUILD_OPTION );
	if ( is_array( $build ) && ( $build['owner_token'] ?? '' ) === $owner ) {
		$result = ( new DomainRestoreWorkspace() )->abandon( $build ); wf_assert( ! is_wp_error( $result ), 'Cannot remove this probe\'s private workspace.' );
	}
	$after = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', SchemaManager::table( 'issuers' ), $issuer['id'] ), ARRAY_A );
	wf_assert( $issuer === $after && $generation === wf_option( DomainGeneration::OPTION ), 'Rollback-only test changed issuer or generation.' );
	wf_assert( null === wf_option( DomainMaintenanceLock::OPTION ) && null === wf_option( DomainRestoreWorkspace::BUILD_OPTION ) && null === wf_option( DomainRestoreWorkspace::JOURNAL_OPTION ), 'Probe left maintenance state.' );
}

// Same row fence protects legacy writes and both maintenance families.
$legacy_generation = wf_option( Ecapla_CertVerify_Backup::GENERATION_OPTION );
$legacy_settings = wf_option( Ecapla_CertVerify_Settings::OPTION_KEY );
foreach ( array( array( 'legacy-writer', 'maintenance', 'BARRIER legacy_before_sql', 'RESULT legacy_rolled_back' ), array( 'writer', 'legacy-maintenance', 'BARRIER writer_before_sql', 'RESULT writer_rolled_back' ) ) as $pair ) {
	$writer = null; $maintenance = null;
	try {
		$writer = wf_spawn( array( $pair[0], (string) $issuer['id'] ) );
		wf_assert( wf_line( $writer ) === $pair[2], 'Cross-store writer barrier missing.' );
		$maintenance = wf_spawn( array( $pair[1], $owner ) );
		usleep( 700000 );
		wf_assert( null === wf_poll_line( $maintenance ), 'Maintenance overtook an admitted cross-store writer.' );
		fwrite( $writer[1][0], "continue\n" ); fflush( $writer[1][0] );
		wf_assert( wf_line( $writer ) === $pair[3], 'Cross-store rollback missing.' ); wf_finish( $writer ); $writer = null;
		wf_assert( wf_line( $maintenance ) === 'RESULT acquired', 'Cross-store maintenance did not resume after rollback.' ); wf_finish( $maintenance ); $maintenance = null;
		$entered = false;
		$blocked = ( new Transaction() )->run( static function () use ( &$entered ) { $entered = true; return true; } );
		wf_assert( ! $entered && is_wp_error( $blocked ), 'Cross-store lease admitted an ordinary callback.' );
		echo 'PASS  ' . $pair[0] . ' excludes ' . $pair[1] . " until its rollback completes\n";
	} finally {
		foreach ( array( $writer, $maintenance ) as $child ) { if ( is_array( $child ) ) { proc_terminate( $child[0] ); foreach ( $child[1] as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } } proc_close( $child[0] ); foreach ( array( $child[2], $child[3] ) as $path ) { if ( is_file( $path ) ) { unlink( $path ); } } } }
		$build = wf_option( DomainRestoreWorkspace::BUILD_OPTION );
		if ( is_array( $build ) && ( $build['owner_token'] ?? '' ) === $owner ) { wf_assert( ! is_wp_error( ( new DomainRestoreWorkspace() )->abandon( $build ) ), 'Cross-store workspace cleanup failed.' ); }
		if ( 'legacy-maintenance' === $pair[1] ) {
			$method = new ReflectionMethod( Ecapla_CertVerify_Backup::class, 'release_restore_maintenance_lock' ); if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
			wf_assert( true === Certloom\Infrastructure\Data\MaintenanceMutex::run( static function () use ( $method, $owner ) { return $method->invoke( null, $owner ); } ), 'Owned legacy lease cleanup failed.' );
		}
	}
}
wf_assert( $legacy_generation === wf_option( Ecapla_CertVerify_Backup::GENERATION_OPTION ) && $legacy_settings === wf_option( Ecapla_CertVerify_Settings::OPTION_KEY ), 'Cross-store rollback changed legacy settings/generation.' );
echo "6 live groups, 0 failures\n";
