<?php
/** Two real MySQL sessions must coalesce configuration edits without lost work. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local root and verified private snapshot required.\n" ); exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || realpath( ABSPATH ) !== realpath( $site ) || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { fwrite( STDERR, "Refusing another site or database.\n" ); exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'QA' ); }, PHP_INT_MAX );

use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Recipient\PendingConfigurationQueue;

function config_race_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; }
function config_race_row() {
 global $wpdb; $wpdb->last_error = '';
 $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE option_name=%s', $wpdb->options, PendingConfigurationQueue::OPTION ), ARRAY_A );
 if ( '' !== $wpdb->last_error || ( null !== $row && ! is_array( $row ) ) ) { throw new RuntimeException( 'Outbox read failed.' ); }
 return $row;
}
function config_race_directory( $path ) {
 $resolved = realpath( $path ); $temp = realpath( sys_get_temp_dir() );
 if ( ! $resolved || ! preg_match( '/^certloom-config-race-[a-f0-9]{16}$/D', basename( $resolved ) ) || realpath( dirname( $resolved ) ) !== $temp ) { throw new RuntimeException( 'Exact owned private directory required.' ); }
 return $resolved;
}
function config_race_write( $path, $value ) { if ( false === file_put_contents( $path, wp_json_encode( $value ) ) ) { throw new RuntimeException( 'Private synchronization write failed.' ); } }
function config_race_spawn( array $arguments ) {
 $ini = php_ini_loaded_file(); if ( ! $ini ) { throw new RuntimeException( 'CLI configuration required.' ); }
 $process = proc_open( array_merge( array( PHP_BINARY, '-c', $ini, __FILE__ ), $arguments ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes );
 if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Worker start failed.' ); }
 fclose( $pipes[0] ); return array( $process, $pipes[1] );
}
function config_race_join( array $worker ) {
 $text = stream_get_contents( $worker[1] ); fclose( $worker[1] ); $exit = proc_close( $worker[0] );
 if ( 0 !== $exit ) { throw new RuntimeException( 'Worker failed: ' . trim( $text ) ); }
 return $text;
}
function config_race_wait_file( $path ) {
 $deadline = microtime( true ) + 8;
 do { clearstatcache( true, $path ); if ( is_file( $path ) ) { return json_decode( file_get_contents( $path ), true ); } usleep( 50000 ); } while ( microtime( true ) < $deadline );
 throw new RuntimeException( 'Worker synchronization timed out.' );
}
function config_race_fingerprints() {
 global $wpdb; $out = array();
 $tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients', 'programs', 'issuers', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map', 'program_registrations', 'field_submissions', 'issuance_requests', 'issuance_request_events' ) ) );
 foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Preservation read failed.' ); } $out[ $table ] = hash( 'sha256', wp_json_encode( $rows ) ); }
 $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE (LEFT(option_name,9)='certloom_' OR LEFT(option_name,11)='certverify_' OR option_name='cron') AND option_name<>%s ORDER BY option_name", $wpdb->options, PendingConfigurationQueue::OPTION ), ARRAY_A );
 if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Option preservation read failed.' ); } $out['options_without_fixture_outbox'] = hash( 'sha256', wp_json_encode( $rows ) );
 return $out;
}
function config_race_restore( array $original_journal, array $expected_row, $preserve_background_options = false ) {
 $current = config_race_fingerprints();
 // An earlier interrupted fixture journal used a broad option fingerprint.
 // Its exact committed worker receipt still owns only the disposable outbox.
 // Preserve other options at their current state; every business table must
 // still match before cleanup and the owned queue row must match exactly.
 if ( $preserve_background_options ) { $original_journal['before_fingerprints']['options_without_fixture_outbox'] = $current['options_without_fixture_outbox']; }
 if ( $original_journal['before_fingerprints'] !== $current ) { throw new RuntimeException( 'Tracked site state changed in ' . implode( ', ', array_keys( array_diff_assoc( $current, $original_journal['before_fingerprints'] ) ) ) . '; preserve the private journal.' ); }
 $original = $original_journal['original'];
 config_race_ok( ( new Transaction() )->run( static function () use ( $original, $expected_row, $original_journal ) {
  global $wpdb; $wpdb->last_error = '';
  $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, PendingConfigurationQueue::OPTION ), ARRAY_A );
  if ( '' !== $wpdb->last_error || $row !== $expected_row || $original_journal['before_fingerprints'] !== config_race_fingerprints() ) { return new WP_Error( 'qa_cleanup_conflict', 'Preserve a concurrent change.' ); }
  $result = null === $original ? $wpdb->delete( $wpdb->options, array( 'option_id' => $row['option_id'], 'option_value' => $row['option_value'] ) ) : $wpdb->update( $wpdb->options, array( 'option_value' => $original['option_value'], 'autoload' => $original['autoload'] ), array( 'option_id' => $row['option_id'], 'option_value' => $row['option_value'] ) );
  return 1 === $result && '' === $wpdb->last_error ? true : new WP_Error( 'qa_cleanup_failed', 'Checked outbox restoration failed.' );
 } ) );
 LegacyWriteGuard::invalidate_option( PendingConfigurationQueue::OPTION );
 if ( $original !== config_race_row() || $original_journal['before_fingerprints'] !== config_race_fingerprints() ) { throw new RuntimeException( 'Restoration comparison failed.' ); }
}
function config_race_remove_files( $directory ) {
 config_race_directory( $directory );
 foreach ( array( 'first-ready.json', 'second-entered.json', 'second-result.json', 'release.json', 'journal.json' ) as $file ) { if ( is_file( $directory . '/' . $file ) && ! unlink( $directory . '/' . $file ) ) { throw new RuntimeException( 'Exact private fixture cleanup failed.' ); } }
 if ( ! rmdir( $directory ) ) { throw new RuntimeException( 'Owned empty directory cleanup failed.' ); }
}

$mode = $argv[1] ?? '';
if ( in_array( $mode, array( 'hold', 'change', 'ack', 'restore', 'restore-preserve-background', 'inspect' ), true ) ) {
 try {
  $directory = config_race_directory( $argv[2] ?? '' );
  $journal = json_decode( file_get_contents( $directory . '/journal.json' ), true );
  if ( ! is_array( $journal ) || $journal['directory'] !== $directory || SchemaManager::SCHEMA_VERSION !== (string) get_option( SchemaManager::SCHEMA_OPTION ) ) { throw new RuntimeException( 'Private journal and current schema required.' ); }
  if ( 'inspect' === $mode ) {
   $current = config_race_row(); $expected = json_decode( file_get_contents( $directory . '/second-result.json' ), true );
   $current_state = json_decode( $current['option_value'], true ); $expected_state = json_decode( $expected['option_value'], true );
   echo wp_json_encode( array( 'changed_outbox_columns' => array_keys( array_diff_assoc( $current, $expected ) ), 'changed_state_fields' => array_keys( array_diff_assoc( $current_state, $expected_state ) ), 'current_pending' => $current_state['pending'], 'current_ceiling' => $current_state['max_id'], 'same_owned_generation' => $current_state['generation'] === $expected_state['generation'], 'changed_fingerprints' => array_keys( array_diff_assoc( config_race_fingerprints(), $journal['before_fingerprints'] ) ) ) ) . "\n"; exit;
  }
  if ( in_array( $mode, array( 'restore', 'restore-preserve-background' ), true ) ) {
   if ( ! is_file( $directory . '/second-result.json' ) ) { throw new RuntimeException( 'Exact committed worker receipt required before recovery.' ); }
   $expected = json_decode( file_get_contents( $directory . '/second-result.json' ), true );
   if ( 'restore-preserve-background' === $mode && $expected !== config_race_row() ) {
    // The real watchdog may acknowledge this owned empty-ceiling hint. Accept
    // only that exact transition, with the same generation and all other bytes.
    $state = json_decode( $expected['option_value'], true );
    if ( true !== $state['pending'] || 0 !== $state['max_id'] || null !== $state['job_key'] ) { throw new RuntimeException( 'Owned empty-ceiling receipt required.' ); }
    $state['pending'] = false; $expected['option_value'] = wp_json_encode( $state );
    if ( $expected !== config_race_row() ) { throw new RuntimeException( 'A different outbox change must be preserved.' ); }
   }
   config_race_restore( $journal, $expected, 'restore-preserve-background' === $mode );
   config_race_remove_files( $directory ); echo "Exact original state restored; private journal removed.\n"; exit;
  }
  $queue = new PendingConfigurationQueue();
  if ( 'ack' === $mode ) { config_race_ok( $queue->acknowledge( (string) ( $argv[3] ?? '' ) ) ); echo "acknowledged\n"; exit; }
  if ( 'change' === $mode ) {
   config_race_write( $directory . '/second-entered.json', array( 'connection_id' => (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) );
   config_race_ok( $queue->changed( 202 ) );
   config_race_write( $directory . '/second-result.json', config_race_row() ); echo "second committed\n"; exit;
  }
  $result = ( new Transaction() )->run( static function () use ( $queue, $directory ) {
   config_race_ok( $queue->changed( 101 ) );
   config_race_write( $directory . '/first-ready.json', config_race_row() );
   $deadline = microtime( true ) + 20;
   do { clearstatcache( true, $directory . '/release.json' ); if ( is_file( $directory . '/release.json' ) ) { return true; } usleep( 50000 ); } while ( microtime( true ) < $deadline );
   return new WP_Error( 'qa_release_timeout', 'Parent did not release the owned transaction.' );
  } );
  config_race_ok( $result ); echo "first committed\n"; exit;
 } catch ( Throwable $error ) { fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" ); exit( 1 ); }
}
if ( '' !== $mode ) { fwrite( STDERR, "Unknown fixture mode.\n" ); exit( 1 ); }

$checks = array(); $directory = null; $workers = array(); $failure = null; $restored = false;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$fingerprints = 'config_race_fingerprints';
try {
 $wpdb->last_error = ''; $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', SchemaManager::table( 'issuance_requests' ) ) );
 $check( '' === $wpdb->last_error && '0' === (string) $count, 'concurrency fixture starts without existing pending requests' );
 $before = $fingerprints(); $original = config_race_row(); $original_state = $original ? json_decode( $original['option_value'], true ) : null;
 $check( null === $original || ( is_array( $original_state ) && false === ( $original_state['pending'] ?? null ) ), 'existing configuration work is not discarded by the fixture' );
 $directory = sys_get_temp_dir() . '/certloom-config-race-' . bin2hex( random_bytes( 8 ) );
 if ( ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'Private journal directory failed.' ); }
 $directory = config_race_directory( $directory );
 config_race_write( $directory . '/journal.json', array( 'directory' => $directory, 'original' => $original, 'before_fingerprints' => $before ) );
 $initial = wp_json_encode( array( 'schema_version' => 1, 'generation' => bin2hex( random_bytes( 16 ) ), 'pending' => false, 'program_id' => 0, 'max_id' => 0, 'job_key' => null ) );
 config_race_ok( ( new Transaction() )->run( static function () use ( $original, $initial ) {
  global $wpdb; $wpdb->last_error = '';
  $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, PendingConfigurationQueue::OPTION ), ARRAY_A );
  if ( '' !== $wpdb->last_error || $row !== $original ) { return new WP_Error( 'qa_stage_conflict', 'Preserve a concurrent change.' ); }
  $result = null === $original ? $wpdb->insert( $wpdb->options, array( 'option_name' => PendingConfigurationQueue::OPTION, 'option_value' => $initial, 'autoload' => 'off' ) ) : $wpdb->update( $wpdb->options, array( 'option_value' => $initial ), array( 'option_name' => PendingConfigurationQueue::OPTION ) );
  return 1 === $result && '' === $wpdb->last_error ? true : new WP_Error( 'qa_stage_failed', 'Isolated outbox initialization failed.' );
 } ) );
 LegacyWriteGuard::invalidate_option( PendingConfigurationQueue::OPTION );
 $workers['first'] = config_race_spawn( array( 'hold', $directory ) );
 $first_row = config_race_wait_file( $directory . '/first-ready.json' );
 $first_state = json_decode( $first_row['option_value'], true );
 $check( $first_state['pending'] && 101 === $first_state['program_id'], 'first independent request retains its scope while holding the transaction' );
 $workers['second'] = config_race_spawn( array( 'change', $directory ) );
 $entered = config_race_wait_file( $directory . '/second-entered.json' );
 $waiting = false; $deadline = microtime( true ) + 8;
 do {
  $wpdb->last_error = ''; $wait_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=%d AND trx_state='LOCK WAIT'", (int) $entered['connection_id'] ) );
  if ( '' !== $wpdb->last_error ) { throw new RuntimeException( 'Independent lock-wait read failed.' ); }
  if ( (int) $wait_count > 0 ) { $waiting = true; break; } usleep( 50000 );
 } while ( microtime( true ) < $deadline );
 $check( $waiting && ! is_file( $directory . '/second-result.json' ), 'MySQL confirms that the second session actually waits on the first transaction' );
 config_race_write( $directory . '/release.json', array( 'release' => true ) );
 config_race_join( $workers['first'] ); unset( $workers['first'] );
 config_race_join( $workers['second'] ); unset( $workers['second'] );
 $final_row = config_race_row(); $second_row = json_decode( file_get_contents( $directory . '/second-result.json' ), true ); $final_state = json_decode( $final_row['option_value'], true );
 $check( $final_row === $second_row && $final_state['pending'] && 0 === $final_state['program_id'] && 0 === $final_state['max_id'] && null === $final_state['job_key'], 'overlapping different-Program edits coalesce both scopes into one global pending pass' );
 $check( $first_state['generation'] !== $final_state['generation'], 'second committed edit retains a distinct durable generation' );
 config_race_join( config_race_spawn( array( 'ack', $directory, $first_state['generation'] ) ) );
 $check( $final_row === config_race_row(), 'a third independent session cannot acknowledge newer work with an older generation' );
 $check( $before === $fingerprints(), 'concurrent hints create no business records, delivery jobs or other option changes' );
 config_race_restore( array( 'original' => $original, 'before_fingerprints' => $before ), $final_row );
 $check( $original === config_race_row() && $before === $fingerprints(), 'exact original outbox and all tracked site data are restored' );
 $restored = true;
 config_race_remove_files( $directory );
 $directory = null;
} catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); }
finally {
 if ( $workers ) {
  if ( $directory && is_dir( $directory ) ) { config_race_write( $directory . '/release.json', array( 'release' => true ) ); }
  foreach ( $workers as $worker ) { try { config_race_join( $worker ); } catch ( Throwable $error ) { if ( null === $failure ) { $failure = $error->getMessage(); } } }
 }
}
echo wp_json_encode( array( 'success' => null === $failure && $restored && null === $directory, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'tracked_state_restored' => $restored, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure && $restored && null === $directory ? 0 : 1 );
