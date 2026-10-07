<?php
/** Separate MySQL session admission and rollback-only real Designer checkpoints. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' !== $root || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact disposable Local root and fresh private snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== home_url() || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { throw new RuntimeException( 'Refusing another site/database.' ); }
use Certloom\Infrastructure\Admin\TemplateDesignerAutosave;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Application\Templates\TemplateDefaults;
function studio_live_name( $template, $user ) {
 global $wpdb;
 return 'certloom-studio-' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->usermeta . '|' . TemplateDesignerAutosave::meta_key( $template ) . '|' . $user ), 0, 48 );
}
if ( 'hold' === ( $argv[1] ?? '' ) ) {
 $template = (int) $argv[2]; $user = (int) $argv[3]; $signal = $argv[4];
 if ( $template < 1 || $user < 1 || realpath( dirname( $signal ) ) !== realpath( sys_get_temp_dir() ) || ! preg_match( '/^studio_ready_[a-f0-9]{16}\.data$/D', basename( $signal ) ) ) { throw new RuntimeException( 'Invalid owned worker fixture.' ); }
 $name = studio_live_name( $template, $user );
 if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) { throw new RuntimeException( 'Cannot hold worker mutex.' ); }
 try {
  if ( false === file_put_contents( $signal, 'ready' ) ) { throw new RuntimeException( 'Cannot signal worker.' ); }
  $deadline = microtime( true ) + 12;
  do { clearstatcache( true, $signal ); if ( ! is_file( $signal ) ) { break; } usleep( 50000 ); } while ( microtime( true ) < $deadline );
 } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); }
 exit;
}
$checks = 0;
function studio_live_check( $ok, $label ) { global $checks; $checks++; if ( ! $ok ) { throw new RuntimeException( $label ); } echo 'PASS  ' . $label . "\n"; }
function studio_live_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
function studio_live_digest( $user ) {
 global $wpdb;
 $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'certloom_' ) . '%' ) ); $rows = array();
 foreach ( $tables as $table ) { $items = array_map( 'serialize', $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ) ); sort( $items, SORT_STRING ); $rows[ $table ] = $items; }
 $rows['user_meta'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id=%d ORDER BY umeta_id', $wpdb->usermeta, $user ), ARRAY_A );
 $rows['generation'] = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, Certloom\Infrastructure\Data\DomainGeneration::OPTION ) );
 return hash( 'sha256', serialize( $rows ) );
}
$admin = get_user_by( 'login', 'admin' ); if ( ! $admin ) { throw new RuntimeException( 'Existing test administrator required.' ); }
$actor = (int) $admin->ID; $before = studio_live_digest( $actor );
$result = ( new Transaction() )->run( static function () use ( $actor ) {
 global $wpdb;
 $process = null; $pipes = array(); $signal = sys_get_temp_dir() . '/studio_ready_' . bin2hex( random_bytes( 8 ) ) . '.data';
 try {
  $repository = new TemplateRepository();
  $id = studio_live_ok( $repository->create( array( 'machine_key' => 'audit-studio-' . bin2hex( random_bytes( 8 ) ), 'name' => 'Rollback-only Studio mutex probe', 'layout' => TemplateDefaults::blank_a4_landscape() ), $actor ) );
  $template = studio_live_ok( $repository->find( $id ) ); $revision = $template['current_revision'];
  $sha = $revision['layout_sha256']; $base = (int) $template['current_revision_id']; $layout = $revision['layout'];
  $ini = php_ini_loaded_file(); if ( ! $ini ) { throw new RuntimeException( 'CLI ini required.' ); }
  $process = proc_open( array( PHP_BINARY, '-c', $ini, __FILE__, 'hold', (string) $id, (string) $actor, $signal ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
  if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start separate Studio worker.' ); } fclose( $pipes[0] );
  $deadline = microtime( true ) + 8;
  do { clearstatcache( true, $signal ); if ( is_file( $signal ) ) { break; } usleep( 50000 ); } while ( microtime( true ) < $deadline );
  if ( ! is_file( $signal ) ) { throw new RuntimeException( 'Worker did not announce mutex.' ); }
  $service = new TemplateDesignerAutosave(); $session = wp_generate_uuid4();
  $blocked = $service->save( $id, $actor, $base, $sha, $session, $layout, array() );
  studio_live_check( is_wp_error( $blocked ) && 'certloom_designer_autosave_busy' === $blocked->get_error_code(), 'independent MySQL worker excludes a first recovery save' );
  studio_live_check( is_wp_error( $service->discard( $id, $actor, $session ) ), 'discard cannot bypass the other worker' );
  studio_live_check( 'cleanup_failed' === $service->prepare_for_editor( $id, $actor )['status'], 'editor preparation preserves state while the other worker owns cleanup' );
  unlink( $signal ); $output = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $pipes = array();
  $code = proc_close( $process ); $process = null;
  studio_live_check( 0 === $code, 'separate worker exits and releases its mutex' );
  studio_live_ok( $service->save( $id, $actor, $base, $sha, $session, $layout, array() ) );
  studio_live_check( 'ready' === $service->inspect( $id, $actor )['status'], 'real WordPress metadata stores an intact recoverable checkpoint' );
  $other = $service->save( $id, $actor, $base, $sha, wp_generate_uuid4(), $layout, array() );
  studio_live_check( is_wp_error( $other ) && 'certloom_designer_autosave_session_conflict' === $other->get_error_code(), 'a second session cannot overwrite the real durable owner' );
  studio_live_check( is_wp_error( $service->retire_active( $id, $actor ) ) && 'ready' === $service->inspect( $id, $actor )['status'], 'stale retirement intent cannot delete a ready real checkpoint' );
  studio_live_ok( $service->discard( $id, $actor, $session ) );
  studio_live_check( 'none' === $service->inspect( $id, $actor )['status'], 'the owning session can discard its checkpoint' );
  studio_live_check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', studio_live_name( $id, $actor ) ) ), 'no MySQL session lock remains after cleanup' );
  return new WP_Error( 'studio_probe_rollback', 'Intentional rollback.' );
 } catch ( Throwable $error ) { return new WP_Error( 'studio_probe_failure', $error->getMessage() ); }
 finally {
  if ( is_file( $signal ) ) { unlink( $signal ); }
  if ( is_resource( $process ) ) { if ( isset( $pipes[1] ) && is_resource( $pipes[1] ) ) { stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); } proc_close( $process ); }
 }
} );
studio_live_check( is_wp_error( $result ) && 'studio_probe_rollback' === $result->get_error_code(), 'fixture transaction rolls back' . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) );
wp_cache_delete( $actor, 'user_meta' );
studio_live_check( hash_equals( $before, studio_live_digest( $actor ) ), 'all native rows, generation and original administrator metadata are preserved exactly' );
studio_live_check( ! is_file( CERTLOOM_PATH . 'resources/renderer/accepted.json' ), 'renderer release authority remains closed' );
echo "$checks live checks, 0 failures\n";
