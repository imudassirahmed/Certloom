<?php
/** Independent-process cache regression against the exact backed-up Local site. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
define( 'DISABLE_WP_CRON', true );
require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }
function lbc_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function lbc_raw( $key ) { global $wpdb; $wpdb->last_error = ''; $value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) ); lbc_assert( '' === $wpdb->last_error, 'Option read failed.' ); return $value; }
$keys = array( Ecapla_CertVerify_Settings::OPTION_KEY, Ecapla_CertVerify_Backup::GENERATION_OPTION );
if ( 'change' === ( $argv[1] ?? '' ) ) {
	$key = $argv[2] ?? ''; $nonce = $argv[3] ?? '';
	lbc_assert( in_array( $key, $keys, true ) && preg_match( '/^legacy-cache-probe-[a-f0-9]{32}$/', $nonce ), 'Invalid probe arguments.' );
	$value = Ecapla_CertVerify_Backup::GENERATION_OPTION === $key ? $nonce : array_merge( (array) get_option( $key, array() ), array( '__certloom_legacy_probe' => $nonce ) );
	update_option( $key, $value, false );
	lbc_assert( maybe_serialize( $value ) === lbc_raw( $key ), 'Independent option write failed.' ); exit;
}
foreach ( array( 'certloom_domain_maintenance_lock', 'certloom_domain_restore_build', 'certloom_domain_restore_journal', Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION, Ecapla_CertVerify_Backup::JOURNAL_OPTION ) as $key ) { lbc_assert( null === lbc_raw( $key ), 'Existing maintenance state; refusing probe.' ); }
$file_helper_initially_loaded = function_exists( 'wp_tempnam' );
foreach ( $keys as $key ) {
	$old = lbc_raw( $key ); $nonce = 'legacy-cache-probe-' . bin2hex( random_bytes( 16 ) ); $result = null; $triggered = false;
	$filter = static function ( $sql ) use ( $key, $nonce, &$triggered ) {
		if ( ! $triggered && preg_match( '/^SELECT \* FROM `' . preg_quote( Ecapla_CertVerify_Database::table_name(), '/' ) . '` WHERE id > /', $sql ) ) {
			$triggered = true;
			$process = proc_open( array( PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'change', $key, $nonce ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			lbc_assert( is_resource( $process ), 'Cannot start independent writer.' ); fclose( $pipes[0] );
			$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
			lbc_assert( 0 === proc_close( $process ), 'Independent write failed: ' . $out . $err );
		}
		return $sql;
	};
	try {
		get_option( $key ); Ecapla_CertVerify_Fields::get_fields(); Ecapla_CertVerify_Fields::get_retired_fields();
		add_filter( 'query', $filter );
		$result = Ecapla_CertVerify_Backup::create_export_file();
		remove_filter( 'query', $filter );
		echo wp_json_encode( array( 'option' => $key, 'changed_during_records' => $triggered, 'backup_rejected' => is_wp_error( $result ), 'error' => is_wp_error( $result ) ? $result->get_error_code() : null ) ) . "\n";
		lbc_assert( $triggered, 'Did not exercise the record-reading boundary.' );
		lbc_assert( is_wp_error( $result ) && 'ecapla_certverify_backup_changed_during_generation' === $result->get_error_code(), 'Warm legacy backup accepted an independent change.' );
		echo 'PASS  legacy backup rejects an independent ' . $key . " change despite warm caches\n";
	} finally {
		remove_filter( 'query', $filter );
		$current = lbc_raw( $key );
		if ( is_string( $current ) && strpos( $current, $nonce ) !== false ) {
			$sql = null === $old ? $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $key, $current ) : $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $old, $key, $current );
			lbc_assert( 1 === (int) $wpdb->query( $sql ), 'Cannot restore this probe\'s option.' );
		}
		foreach ( array( $key, 'alloptions', 'notoptions' ) as $cache_key ) { wp_cache_delete( $cache_key, 'options' ); }
		Ecapla_CertVerify_Fields::reset_runtime_cache();
		if ( is_array( $result ) && ! empty( $result['path'] ) && is_file( $result['path'] ) ) { lbc_assert( unlink( $result['path'] ), 'Cannot remove this probe\'s backup.' ); }
		lbc_assert( $old === lbc_raw( $key ), 'Probe option was not preserved.' );
	}
}
$stable = null; $token = null;
try {
	$stable = Ecapla_CertVerify_Backup::create_export_file();
	lbc_assert( is_array( $stable ) && is_file( $stable['path'] ), 'Stable legacy backup failed.' );
	lbc_assert( function_exists( 'wp_tempnam' ), 'Builder did not load its required file helper.' );
	$token = Ecapla_CertVerify_Backup::analyze_file( $stable['path'] );
	if ( is_wp_error( $token ) ) { echo wp_json_encode( array( 'stable_review_error' => $token->get_error_code() ) ) . "\n"; }
	lbc_assert( is_string( $token ) && is_array( Ecapla_CertVerify_Backup::get_preview( $token ) ), 'Stable legacy backup cannot be reviewed.' );
	echo wp_json_encode( array( 'file_helper_initially_loaded' => $file_helper_initially_loaded, 'records' => $stable['records'], 'restore_review' => 'passed' ) ) . "\n";
	echo "PASS  non-admin WordPress loads its file helper and produces a reviewable legacy backup\n";
} finally {
	if ( is_string( $token ) ) { Ecapla_CertVerify_Backup::delete_session( $token ); }
	if ( is_array( $stable ) && is_file( $stable['path'] ) ) { lbc_assert( unlink( $stable['path'] ), 'Cannot remove stable probe backup.' ); }
}
echo "3 live groups, 0 failures\n";
