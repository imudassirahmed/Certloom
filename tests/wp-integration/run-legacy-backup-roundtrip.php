<?php
/** Populated public legacy restore API on the explicitly backed-up disposable Local site. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $snapshot = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\Users\Admin\Local Sites\ecapla-test-wp-site\app\public' || ! $snapshot || ! is_file( $snapshot ) || filesize( $snapshot ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }

use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
use Certloom\Application\Migration\DomainCutoverState;

function lbr_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function lbr_ok( $value ) { lbr_assert( ! is_wp_error( $value ), is_wp_error( $value ) ? $value->get_error_code() . ': ' . $value->get_error_message() : '' ); return $value; }
function lbr_clear() {
 $keys = array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION, Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION, Ecapla_CertVerify_Backup::JOURNAL_OPTION, 'certverify_restore_builds' );
 foreach ( lbr_ok( DatabaseOptions::read( $keys ) ) as $key => $entry ) { lbr_assert( ! $entry['exists'], 'Pending maintenance: ' . $key ); }
}
function lbr_hashes() {
 global $wpdb; $hashes = array();
 foreach ( DomainBackupRepository::entity_types() as $type ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', SchemaManager::table( $type ) ), ARRAY_A ); lbr_assert( is_array( $rows ) && '' === $wpdb->last_error, 'Cannot hash native table.' ); $hashes[ $type ] = hash( 'sha256', wp_json_encode( $rows ) ); }
 return $hashes;
}
function lbr_restore( $path, $mode, $policy ) {
 $token = lbr_ok( Ecapla_CertVerify_Backup::analyze_file( $path ) );
 try {
  lbr_assert( is_array( Ecapla_CertVerify_Backup::get_preview( $token ) ), 'No review.' );
  $progress = lbr_ok( Ecapla_CertVerify_Backup::start_restore( $token, $policy, $mode ) );
  $called = false; $blocked = ( new \Certloom\Infrastructure\Database\Transaction() )->run( static function () use ( &$called ) { $called = true; return true; } );
  lbr_assert( is_wp_error( $blocked ) && ! $called, 'In-progress restore admitted an ordinary callback.' );
  for ( $slice = 0; $slice < 20 && empty( $progress['complete'] ); $slice++ ) { $progress = lbr_ok( Ecapla_CertVerify_Backup::continue_restore( $token ) ); }
  lbr_assert( ! empty( $progress['complete'] ), 'Restore did not finish.' ); lbr_clear(); return $progress;
 } finally { Ecapla_CertVerify_Backup::delete_session( $token ); }
}
lbr_clear();
lbr_assert( DomainCutoverState::LEGACY === ( new DomainCutoverState() )->authority(), 'Legacy authority required.' );
lbr_assert( 0 === lbr_ok( Ecapla_CertVerify_Database::get_registry_count() ), 'This fixture refuses a populated baseline.' );
$native = lbr_hashes(); $settings = Ecapla_CertVerify_Settings::get_all(); $fields = Ecapla_CertVerify_Fields::get_fields(); $retired = Ecapla_CertVerify_Fields::get_retired_fields();
$baseline = lbr_ok( Ecapla_CertVerify_Backup::create_export_file() ); $populated = null; $clean = false; $nonce = 'roundtrip-' . bin2hex( random_bytes( 8 ) );
try {
 lbr_ok( Ecapla_CertVerify_Database::activate() ); lbr_clear();
 lbr_assert( $native === lbr_hashes() && 0 === Ecapla_CertVerify_Database::get_registry_count(), 'Idempotent schema activation changed content.' );
 echo "PASS  real legacy schema activation releases its exclusive maintenance lease and preserves content\n";
 $one = lbr_ok( Ecapla_CertVerify_Database::insert( array( 'cert_number' => $nonce . '-one', 'holder_name' => 'Backup recipient' ) ) );
 $populated = lbr_ok( Ecapla_CertVerify_Backup::create_export_file() ); lbr_assert( 1 === $populated['records'], 'Populated backup missing row.' );
 $stale = lbr_ok( Ecapla_CertVerify_Backup::analyze_file( $populated['path'] ) );
 lbr_ok( Ecapla_CertVerify_Database::update( $one, array( 'cert_number' => $nonce . '-one', 'holder_name' => 'Current recipient' ) ) );
 $denied = Ecapla_CertVerify_Backup::start_restore( $stale, 'update', 'replace' );
 lbr_assert( is_wp_error( $denied ) && 'ecapla_certverify_restore_preview_stale' === $denied->get_error_code(), 'Stale review activated data.' );
 Ecapla_CertVerify_Backup::delete_session( $stale ); lbr_clear();
 echo "PASS  real stale backup review stops before promotion and releases maintenance\n";
 lbr_ok( Ecapla_CertVerify_Database::insert( array( 'cert_number' => $nonce . '-two', 'holder_name' => 'Current-only recipient' ) ) );
 // Force a real configuration write failure after the actual table swap.
 $current_settings = $settings; $current_settings['numbering_prefix'] = $nonce . '-';
 lbr_assert( Ecapla_CertVerify_Settings::save( $current_settings ), 'Cannot prepare distinct current configuration.' );
 $row_hash = hash( 'sha256', wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', Ecapla_CertVerify_Database::table_name() ), ARRAY_A ) ) );
 $failure_token = lbr_ok( Ecapla_CertVerify_Backup::analyze_file( $populated['path'] ) );
 lbr_ok( Ecapla_CertVerify_Backup::start_restore( $failure_token, 'update', 'replace' ) );
 $injected = false;
 $fault = static function ( $query ) use ( &$injected ) {
  if ( ! $injected && 0 === strpos( $query, 'UPDATE ' ) && false !== strpos( $query, Ecapla_CertVerify_Settings::OPTION_KEY ) ) { $injected = true; return 'UPDATE `certloom_missing_restore_config_fixture` SET missing = 1'; }
  return $query;
 };
 $suppressed = $wpdb->suppress_errors( true ); add_filter( 'query', $fault );
 try { $failed = Ecapla_CertVerify_Backup::continue_restore( $failure_token ); }
 finally { remove_filter( 'query', $fault ); $wpdb->suppress_errors( $suppressed ); }
 lbr_assert( $injected && is_wp_error( $failed ) && 'ecapla_certverify_backup_config_restore_failed' === $failed->get_error_code(), 'Injected post-swap failure was not handled.' );
 lbr_assert( $row_hash === hash( 'sha256', wp_json_encode( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', Ecapla_CertVerify_Database::table_name() ), ARRAY_A ) ) ) && $current_settings === Ecapla_CertVerify_Settings::get_all(), 'Post-swap failure did not restore exact current rows/configuration.' );
 Ecapla_CertVerify_Backup::delete_session( $failure_token ); lbr_clear();
 echo "PASS  real post-swap configuration failure rolls back original rows and configuration before releasing maintenance\n";
 foreach ( array( array( 'merge', 'skip' ), array( 'merge', 'update' ), array( 'replace', 'update' ) ) as $case ) {
  $result = lbr_restore( $populated['path'], $case[0], $case[1] );
  $one_row = lbr_ok( Ecapla_CertVerify_Database::get_by_cert_number( $nonce . '-one' ) );
  lbr_assert( ( 'skip' === $case[1] ? 'Current recipient' : 'Backup recipient' ) === $one_row['holder_name'], 'Conflict policy did not select the expected recipient.' );
  lbr_assert( ( 'replace' === $case[0] ? 1 : 2 ) === Ecapla_CertVerify_Database::get_registry_count(), 'Wrong current-only record policy.' );
  lbr_assert( $native === lbr_hashes(), 'Legacy restore changed the native graph/templates.' );
  echo 'PASS  populated public ' . $case[0] . '/' . $case[1] . ' conflict policy and current-only records: ' . wp_json_encode( $result['results'] ) . "\n";
 }
} finally {
 // The original empty backup is reviewed again after every fixture mutation.
 // Never use an unowned bulk-delete or erase durable recovery evidence.
 if ( ! Ecapla_CertVerify_Backup::is_restore_in_progress() && ! ( new DomainMaintenanceLock() )->active() ) {
  lbr_restore( $baseline['path'], 'replace', 'update' );
  lbr_assert( 0 === Ecapla_CertVerify_Database::get_registry_count() && $native === lbr_hashes(), 'Baseline content was not restored.' );
  lbr_assert( $settings === Ecapla_CertVerify_Settings::get_all() && $fields === Ecapla_CertVerify_Fields::get_fields() && $retired === Ecapla_CertVerify_Fields::get_retired_fields(), 'Baseline configuration changed.' );
  $clean = true;
 }
 if ( $clean ) {
  foreach ( array( $baseline, $populated ) as $file ) { if ( is_array( $file ) && is_file( $file['path'] ) ) { lbr_assert( unlink( $file['path'] ), 'Cannot delete this fixture backup.' ); } }
 } else { fwrite( STDERR, 'Recovery evidence preserved; original backup: ' . $baseline['path'] . "\n" ); }
}
echo "PASS  original empty registry, configuration and fourteen native table hashes restored\n7 live groups, 0 failures\n";
