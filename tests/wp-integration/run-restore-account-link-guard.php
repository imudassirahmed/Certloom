<?php
/** Actual pre-existing staging/commit guard; never promote an account-linked graph. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( ! $root || realpath( $root ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { throw new RuntimeException( 'Exact Local root and verified database snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); } require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { throw new RuntimeException( 'Refusing another site.' ); }
require __DIR__ . '/isolated-local-sandbox.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX );
use Certloom\Application\Restore\DomainRestoreRowValidator;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
$checks = 0;
function account_guard_check( $ok, $label ) { global $checks; if ( ! $ok ) { throw new RuntimeException( $label ); } $checks++; }
function account_guard_ok( $value, $label ) { account_guard_check( ! is_wp_error( $value ), $label . ( is_wp_error( $value ) ? ': ' . $value->get_error_code() : '' ) ); return $value; }
function account_guard_denied( $value, $label ) { account_guard_check( is_wp_error( $value ) && 'certloom_domain_restore_account_links_unverified' === $value->get_error_code(), $label ); }
function account_guard_digest() {
 global $wpdb;
 $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'certloom_' ) . '%' ) );
 $expected = array_map( static function ( $entity ) { return SchemaManager::table( $entity ); }, array_merge( \Certloom\Infrastructure\Backup\DomainBackupRepository::entity_types(), array( 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map' ) ) ); sort( $expected ); if ( is_array( $tables ) ) { sort( $tables ); }
 account_guard_check( $expected === $tables && '' === $wpdb->last_error, 'Exactly the current original native table inventory, with no existing workspace' );
 $rows = array();
 foreach ( $tables as $table ) {
  $wpdb->last_error = ''; $data = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
  account_guard_check( is_array( $data ) && '' === $wpdb->last_error, 'Read native rows for preservation' );
  $data = array_map( 'serialize', $data ); sort( $data, SORT_STRING ); $rows[ $table ] = $data;
 }
 $rows['options'] = account_guard_ok( DatabaseOptions::read( array( DomainGeneration::OPTION, DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION ) ), 'Read original generation and maintenance state' );
 return hash( 'sha256', serialize( $rows ) );
}
$directory = sys_get_temp_dir() . '/certloom-isolated-' . bin2hex( random_bytes( 8 ) ); account_guard_check( mkdir( $directory, 0700 ), 'Exact private guard fixture created' );
$sandbox = new CertloomIsolatedLocalSandbox( $directory . '/journal.json' ); $cleanup = null; $failure = null; $workspace = null; $service = new DomainRestoreWorkspace();
try {
 $sandbox->create(); $sandbox->enter();
 foreach ( array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION ) as $key ) { account_guard_check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, $key ) ), 'No existing maintenance operation' ); }
 $before = account_guard_digest();
 $row = array_fill_keys( SchemaManager::column_names( 'recipients' ), null );
 $row = array_replace( $row, array( 'id' => 1, 'display_name' => 'Synthetic account-linked restore', 'wp_user_id' => 1, 'custom_fields' => '{}', 'created_at' => '2026-09-29 00:00:00', 'updated_at' => '2026-09-29 00:00:00' ) );
 account_guard_denied( ( new DomainRestoreRowValidator() )->validate( 'recipients', $row ), 'Ordinary restore staging rejects an unverified link' );
 $owner = bin2hex( random_bytes( 16 ) ); $sandbox->register_domain_workspace( $owner );
 $workspace = account_guard_ok( $service->create( $owner ), 'Create owned empty private workspace' );
 account_guard_denied( $service->insert_row( $workspace, 'recipients', $row ), 'Native staging also rejects an unverified account link' );
 // Simulate pre-guard persisted data only in the exact registered disposable
 // staging table; neither the native writer nor original site tables are bypassed.
 $stage = $wpdb->prefix . 'certloom_rs_' . substr( $owner, 0, 12 ) . '_r';
 account_guard_check( preg_match( '/^clqa_[a-f0-9]{12}_$/D', $wpdb->prefix ) && in_array( $owner, $sandbox->read()['workspace_owners'], true ), 'Synthetic old workspace belongs to the disposable journal' );
 $wpdb->last_error = ''; account_guard_check( 1 === $wpdb->insert( $stage, $row ) && '' === $wpdb->last_error, 'Exact disposable older-workspace row is persisted for the commit guard' );
 account_guard_denied( $service->validate_relationships( $workspace ), 'Older staged account link cannot become ready' );
 account_guard_denied( $service->commit_replace( $workspace, array() ), 'Actual Replace refuses before table promotion' );
 account_guard_denied( $service->commit_merge( $workspace, array() ), 'Actual Merge refuses before table promotion' );
} catch ( Throwable $error ) { $failure = $error->getMessage(); }
finally {
 try {
  if ( is_array( $workspace ) ) { account_guard_ok( $service->abandon( $workspace ), 'Remove only owned private tables and maintenance markers' ); }
  if ( isset( $before ) ) { account_guard_check( $before === account_guard_digest(), 'All current native table rows, generation and maintenance state preserved' ); }
  $cleanup = $sandbox->cleanup(); account_guard_check( rmdir( $directory ), 'Exact private guard directory removed' ); $directory = null;
 } catch ( Throwable $error ) { $failure = ( $failure ? $failure . '; ' : '' ) . $error->getMessage(); $sandbox->leave(); }
}
echo wp_json_encode( array( 'success' => null === $failure && is_array( $cleanup ), 'checks_passed' => $checks, 'failure' => $failure, 'cleanup' => $cleanup, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure && is_array( $cleanup ) ? 0 : 1 );
