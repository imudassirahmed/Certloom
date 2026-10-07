<?php
/** Real two-process warm-cache regression; restores only this probe's exact option values. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
define( 'DISABLE_WP_CRON', true );
require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
use Certloom\Application\Import\DomainImportConsistencyGuard;
use Certloom\Application\Restore\DomainRestoreConfirmationValidator;
function review_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function review_raw( $key ) { global $wpdb; $wpdb->last_error = ''; $value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) ); review_assert( '' === $wpdb->last_error, 'Option read failed.' ); return $value; }
$mode = $argv[1] ?? '';
if ( 'change' === $mode ) {
	$key = $argv[2] ?? ''; $nonce = $argv[3] ?? '';
	review_assert( in_array( $key, array( 'certverify_settings', DomainGeneration::OPTION ), true ) && preg_match( '/^review-probe-[a-f0-9]{32}$/', $nonce ), 'Invalid probe arguments.' );
	$value = DomainGeneration::OPTION === $key ? $nonce : array_merge( (array) get_option( $key, array() ), array( '__certloom_review_probe' => $nonce ) );
	update_option( $key, $value, false );
	review_assert( maybe_serialize( $value ) === review_raw( $key ), 'Independent option write failed.' ); exit;
}
foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION ) as $key ) { review_assert( null === review_raw( $key ), 'Existing maintenance state; refusing probe.' ); }
$guard = new DomainImportConsistencyGuard(); $confirmation = new DomainRestoreConfirmationValidator();
foreach ( array( 'certverify_settings', DomainGeneration::OPTION ) as $key ) {
	$old = review_raw( $key ); $nonce = 'review-probe-' . bin2hex( random_bytes( 16 ) ); $written = null;
	try {
		$baseline = $guard->snapshot(); review_assert( ! is_wp_error( $baseline ), 'Cannot snapshot review baseline.' );
		get_option( 'certverify_settings' ); get_option( DomainGeneration::OPTION );
		$process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'change', $key, $nonce ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		review_assert( is_resource( $process ), 'Cannot start independent writer.' ); fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		$status = proc_close( $process ); $written = review_raw( $key );
		review_assert( 0 === $status && is_string( $written ) && strpos( $written, $nonce ) !== false, 'Independent write failed: ' . $out . $err );
		$import = $guard->validate( $baseline['generation'], $baseline['configuration_hash'] );
		$restore = $confirmation->validate( array( 'confirmed_replace' => true, 'path' => '/private/probe', 'baseline_generation' => $baseline['generation'], 'baseline_configuration_hash' => $baseline['configuration_hash'] ), 'confirmed_replace', 'confirm' );
		$expected = DomainGeneration::OPTION === $key ? 'certloom_import_domain_changed' : 'certloom_import_configuration_changed';
		review_assert( is_wp_error( $import ) && $expected === $import->get_error_code(), 'Warm request accepted stale import review.' );
		review_assert( is_wp_error( $restore ) && 'certloom_domain_restore_confirmation_stale' === $restore->get_error_code(), 'Warm request accepted stale restore confirmation.' );
		echo 'PASS  real warm-cache import and restore reject independent ' . $key . " change\n";
	} finally {
		$current = review_raw( $key );
		// Also clean a child that committed just before reporting failure; never overwrite an unrelated update.
		if ( is_string( $current ) && strpos( $current, $nonce ) !== false ) {
			$sql = null === $old ? $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $key, $current ) : $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $old, $key, $current );
			review_assert( 1 === (int) $wpdb->query( $sql ), 'Cannot restore this probe\'s option.' );
		}
		foreach ( array( $key, 'alloptions', 'notoptions' ) as $cache_key ) { wp_cache_delete( $cache_key, 'options' ); }
		review_assert( $old === review_raw( $key ), 'Probe option was not preserved.' );
	}
}
echo "2 live groups, 0 failures\n";
