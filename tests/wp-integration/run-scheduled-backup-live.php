<?php
/** Actual Local Cron hook + manual builder parity, isolated rollback-only catalog. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $snapshot = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $snapshot || ! is_file( $snapshot ) || filesize( $snapshot ) < 1024 ) { throw new RuntimeException( 'Exact disposable Local site and recovery snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }

use Certloom\Infrastructure\Backup\ScheduledBackup;
use Certloom\Infrastructure\Backup\LocalBackupStore;
use Certloom\Infrastructure\Backup\GoogleDriveOAuth;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\Transaction;

$checks = 0; $failure = ''; $owned = array();
function sbl_check( $ok, $message ) { global $checks, $failure; ++$checks; if ( ! $ok ) { $failure = $message; throw new RuntimeException( $message ); } echo "PASS  $message\n"; }
function sbl_ok( $value, $message ) { sbl_check( ! is_wp_error( $value ), $message . ( is_wp_error( $value ) ? ': ' . $value->get_error_code() : '' ) ); return $value; }
function sbl_digest( array $keys ) {
 global $wpdb; $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'certloom_' ) . '%' ) );
 $tables[] = Ecapla_CertVerify_Database::table_name(); $rows = array();
 foreach ( $tables as $table ) { $data = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ); sbl_check( is_array( $data ) && '' === $wpdb->last_error, 'Read current rows for preservation' ); $data = array_map( 'serialize', $data ); sort( $data, SORT_STRING ); $rows[ $table ] = $data; }
 $rows['options'] = sbl_ok( DatabaseOptions::read( $keys ), 'Read exact current option values' ); return hash( 'sha256', serialize( $rows ) );
}
function sbl_content( $path ) {
 $lines = array_map( static function ( $line ) { return json_decode( $line, true, 512, JSON_THROW_ON_ERROR ); }, file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
 sbl_check( 'manifest' === $lines[0]['type'] && 4 === $lines[0]['format_version'] && 'integrity' === end( $lines )['type'], 'Stored archive has canonical manifest and integrity trailer' );
 unset( $lines[0]['generated_at'] ); array_pop( $lines ); return $lines;
}
$keys = array( LocalBackupStore::CATALOG_OPTION, ScheduledBackup::OPTION_SCHEDULE, ScheduledBackup::OPTION_RETENTION, ScheduledBackup::OPTION_LAST_RESULT, ScheduledBackup::LOCK_OPTION, 'cron', 'certloom_domain_generation', 'certverify_settings', 'certverify_fields', 'certverify_retired_fields' );
sbl_check( empty( GoogleDriveOAuth::config()['enabled'] ), 'Remote backup disabled; no provider work' );
sbl_check( ! get_option( ScheduledBackup::LOCK_OPTION, false ), 'No existing backup run owns the lease' );
$before = sbl_digest( $keys ); $store = new LocalBackupStore();
$blocked = static function () { throw new RuntimeException( 'Unexpected network request in local backup test.' ); }; add_filter( 'pre_http_request', $blocked );
$result = ( new Transaction() )->run( static function () use ( $store, &$owned ) {
 try {
  // Transaction-local empty catalog makes retention unable to touch any prior archive.
  update_option( LocalBackupStore::CATALOG_OPTION, array(), false );
  sbl_check( array() === $store->list_backups(), 'Only this test catalog is visible' );
  sbl_ok( Ecapla_CertVerify_Database::insert( array( 'cert_number' => 'CRON-QA-' . bin2hex( random_bytes( 8 ) ), 'holder_name' => 'Local Cron rollback recipient' ) ), 'Create rollback-only legacy row' );
  sbl_ok( ScheduledBackup::save_settings( 'weekly', 1 ), 'Save weekly schedule with one retained scheduled archive' );
  $event = wp_get_scheduled_event( ScheduledBackup::HOOK );
  sbl_check( $event && 'certloom_weekly' === $event->schedule && WEEK_IN_SECONDS === $event->interval, 'Actual WordPress Cron event has the weekly recurrence' );
  $manual = sbl_ok( ScheduledBackup::create_now( 'manual' ), 'Create actual manual backup' ); $owned[] = $manual['id'];
  $manual_file = sbl_ok( $store->resolve( $manual['id'] ), 'Resolve verified manual backup' );
  sbl_check( ! str_starts_with( wp_normalize_path( $manual_file['path'] ), wp_normalize_path( ABSPATH ) ), 'Backup bytes remain outside the public web root' );
  $expected = sbl_content( $manual_file['path'] ); $older = '';
  for ( $i = 0; $i < 2; ++$i ) {
   do_action( ScheduledBackup::HOOK );
   $last = ScheduledBackup::last_result();
   sbl_check( 'success' === $last['status'] && 'scheduled' === $last['purpose'], 'Real registered Cron callback completes a scheduled backup' );
   $owned[] = $last['backup_id']; $file = sbl_ok( $store->resolve( $last['backup_id'] ), 'Resolve scheduled archive through its verified catalog' );
   sbl_check( $expected === sbl_content( $file['path'] ), 'Manual and scheduled archives preserve identical records and configuration' );
   if ( $older ) { sbl_check( ! file_exists( $older ), 'Retention deletes only the earlier owned scheduled file' ); }
   $older = $file['path'];
  }
  $catalog = $store->list_backups();
  sbl_check( 2 === count( $catalog ) && 1 === count( array_filter( $catalog, static function ( $entry ) { return 'manual' === $entry['purpose']; } ) ), 'Retention preserves manual backup and newest scheduled backup' );
  sbl_ok( ScheduledBackup::save_settings( 'off', 1 ), 'Disable this transaction-local schedule' );
  sbl_check( 0 === ScheduledBackup::next_run(), 'Turning schedule off clears the registered event' );
  return new WP_Error( 'certloom_scheduled_probe_rollback', 'Expected rollback.' );
 } finally {
  foreach ( $store->list_backups() as $entry ) { sbl_check( in_array( $entry['id'], $owned, true ), 'Cleanup owns the selected archive' ); sbl_ok( $store->delete( $entry['id'] ), 'Remove this test archive only' ); }
 }
} );
remove_filter( 'pre_http_request', $blocked ); DatabaseOptions::invalidate( $keys );
sbl_check( is_wp_error( $result ) && 'certloom_scheduled_probe_rollback' === $result->get_error_code(), $failure ?: 'Transaction intentionally rolled back' );
sbl_check( $before === sbl_digest( $keys ), 'Original rows, catalog, settings, schedule and result are unchanged' );
echo "\n$checks live scheduled-backup checks, 0 failures\n";
