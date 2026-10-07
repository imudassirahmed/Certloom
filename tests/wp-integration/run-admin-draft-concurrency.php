<?php
/** Real separate-request CAS, warm caches and overlapping draft writers on Local. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and database snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true );
require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site/database.' ); }
use Certloom\Application\Admin\DraftWorkspace;
function draft_live_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } echo 'PASS  ' . $message . "\n"; }
function draft_live_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
$admin = get_user_by( 'login', 'admin' );
if ( ! $admin || ! user_can( $admin, 'manage_options' ) ) { throw new RuntimeException( 'Existing test administrator required.' ); }
wp_set_current_user( $admin->ID );
$mode = $argv[1] ?? '';
if ( 'save' === $mode ) {
	$scope = $argv[2];
	if ( ! preg_match( '/^audit_draft_[a-f0-9]{16}$/D', $scope ) ) { throw new RuntimeException( 'Invalid owned test scope.' ); }
	$item = draft_live_ok( DraftWorkspace::save( $scope, array( 'label' => 'Independent request' ), array( 'expected_revision' => (int) $argv[3], 'run_id' => $argv[4], 'client_revision' => 500 ) ) );
	echo 'RESULT ' . wp_json_encode( $item ) . "\n"; exit;
}
if ( 'hold' === $mode ) {
	$signal = $argv[2];
	if ( ! preg_match( '/^draft_ready_[a-f0-9]{16}\.data$/D', basename( $signal ) ) || realpath( dirname( $signal ) ) !== realpath( sys_get_temp_dir() ) ) { throw new RuntimeException( 'Invalid owned synchronization fixture.' ); }
	$name = 'certloom-draft-' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->usermeta . '|' . DraftWorkspace::META_KEY . '|' . $admin->ID ), 0, 48 );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) { throw new RuntimeException( 'Worker cannot hold the draft mutex.' ); }
	try {
		if ( false === file_put_contents( $signal, 'ready' ) ) { throw new RuntimeException( 'Cannot announce owned worker lock.' ); }
		$deadline = microtime( true ) + 12;
		do { clearstatcache( true, $signal ); if ( ! is_file( $signal ) ) { break; } usleep( 50000 ); } while ( microtime( true ) < $deadline );
	} finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
	exit;
}
function draft_live_spawn( array $arguments ) {
	$ini = php_ini_loaded_file(); if ( ! $ini ) { throw new RuntimeException( 'A usable CLI PHP ini is required for separate-request acceptance.' ); }
	$process = proc_open( array_merge( array( PHP_BINARY, '-c', $ini, __FILE__ ), $arguments ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start owned draft worker.' ); } fclose( $pipes[0] );
	return array( $process, $pipes[1] );
}
function draft_live_join( array $worker ) {
	$output = stream_get_contents( $worker[1] ); fclose( $worker[1] );
	if ( 0 !== proc_close( $worker[0] ) ) { throw new RuntimeException( 'Draft worker failed: ' . $output ); }
	return $output;
}
$scope = 'audit_draft_' . bin2hex( random_bytes( 8 ) );
$other = 'audit_draft_' . bin2hex( random_bytes( 8 ) );
$original = get_user_meta( $admin->ID, DraftWorkspace::META_KEY, true );
$signal = sys_get_temp_dir() . '/draft_ready_' . bin2hex( random_bytes( 8 ) ) . '.data'; $held = null;
try {
	$first = draft_live_ok( DraftWorkspace::start_new( $scope, array( 'label' => 'Initial' ) ) );
	$warm = DraftWorkspace::get( $scope );
	$output = draft_live_join( draft_live_spawn( array( 'save', $scope, (string) $warm['revision'], $warm['run_id'] ) ) );
	if ( ! preg_match( '/^RESULT (.+)$/m', $output, $matches ) ) { throw new RuntimeException( 'Missing independent save receipt.' ); }
	$newer = json_decode( $matches[1], true );
	draft_live_assert( is_array( $newer ) && $newer['revision'] === $first['revision'] + 1, 'independent real request advances the durable server revision' );
	$stale = DraftWorkspace::save( $scope, array( 'label' => 'Stale cached request' ), array( 'expected_revision' => $warm['revision'], 'run_id' => $warm['run_id'], 'client_revision' => 900 ) );
	draft_live_assert( is_wp_error( $stale ) && 'certloom_draft_workspace_conflict' === $stale->get_error_code(), 'warm request cache cannot overwrite a newer separate-request save' );
	draft_live_assert( 'Independent request' === DraftWorkspace::get( $scope )['payload']['label'], 'conflict preserves the actual independent-request payload' );
	$skewed = draft_live_ok( DraftWorkspace::save( $scope, array( 'label' => 'Clock behind' ), array( 'expected_revision' => $newer['revision'], 'run_id' => $newer['run_id'], 'client_revision' => 1 ) ) );
	draft_live_assert( 'Clock behind' === $skewed['payload']['label'], 'valid CAS works across different client clocks' );
	$held = draft_live_spawn( array( 'hold', $signal ) ); $deadline = microtime( true ) + 8;
	do { clearstatcache( true, $signal ); if ( is_file( $signal ) ) { break; } usleep( 50000 ); } while ( microtime( true ) < $deadline );
	if ( ! is_file( $signal ) ) { throw new RuntimeException( 'Owned worker did not acquire the mutex.' ); }
	$blocked = DraftWorkspace::save( $other, array( 'label' => 'Competing task' ) );
	draft_live_assert( is_wp_error( $blocked ) && 'certloom_draft_workspace_busy' === $blocked->get_error_code(), 'overlapping different-task writes share the real per-user map lock' );
	draft_live_assert( is_wp_error( DraftWorkspace::clear( $scope ) ), 'concurrent discard is excluded by the same worker' );
	unlink( $signal ); draft_live_join( $held ); $held = null;
	draft_live_ok( DraftWorkspace::save( $other, array( 'label' => 'Separate task' ) ) );
	draft_live_assert( 'Clock behind' === DraftWorkspace::get( $scope )['payload']['label'], 'second-task persistence preserves the first task' );
	$called = false;
	$refused = DraftWorkspace::run_if_current( $scope, $warm['run_id'], $warm['revision'], static function () use ( &$called ) { $called = true; return true; } );
	draft_live_assert( is_wp_error( $refused ) && ! $called, 'stale final wizard action is refused before its callback runs' );
	$current = DraftWorkspace::get( $scope );
	$final = draft_live_ok( DraftWorkspace::run_if_current( $scope, $current['run_id'], $current['revision'], static function () use ( $scope ) { return DraftWorkspace::save( $scope, array( 'label' => 'Final action' ) ); } ) );
	draft_live_assert( 'Final action' === $final['payload']['label'], 'current final action can safely use nested draft persistence on real MySQL' );
} finally {
	if ( is_file( $signal ) ) { unlink( $signal ); }
	if ( $held ) { draft_live_join( $held ); }
	draft_live_ok( DraftWorkspace::clear( $scope ) ); draft_live_ok( DraftWorkspace::clear( $other ) );
	wp_cache_delete( $admin->ID, 'user_meta' );
	draft_live_assert( $original === get_user_meta( $admin->ID, DraftWorkspace::META_KEY, true ), 'owned fixture cleanup restores the original administrator workspace exactly' );
}
echo "10 live checks, 0 failures\n";
