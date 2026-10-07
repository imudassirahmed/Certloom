<?php
/** Real, rollback-only legacy commands and fail-closed recovery admission. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
define( 'DISABLE_WP_CRON', true );
require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }

use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;
use Certloom\Application\Migration\DomainCutoverState;

function lwp_assert( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function lwp_ok( $value, $message ) { lwp_assert( ! is_wp_error( $value ), $message . ( is_wp_error( $value ) ? ': ' . $value->get_error_code() : '' ) ); return $value; }
function lwp_raw( $key ) { $entry = lwp_ok( DatabaseOptions::read( array( $key ) ), 'Option read' ); return $entry[ $key ]; }
function lwp_digest() {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', Ecapla_CertVerify_Database::table_name() ), ARRAY_A );
	lwp_assert( is_array( $rows ) && '' === $wpdb->last_error, 'Registry read failed.' );
	$keys = array( Ecapla_CertVerify_Settings::OPTION_KEY, Ecapla_CertVerify_Fields::OPTION_KEY, Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY, Ecapla_CertVerify_Backup::GENERATION_OPTION, Ecapla_CertVerify_Numbering::STATE_OPTION, DomainCutoverState::OPTION, Ecapla_CertVerify_Database::LEGACY_REVIEW_OPTION );
	return hash( 'sha256', serialize( array( $rows, lwp_ok( DatabaseOptions::read( $keys ), 'Baseline options' ) ) ) );
}
$pending = array( DomainMaintenanceLock::OPTION, DomainRestoreWorkspace::BUILD_OPTION, DomainRestoreWorkspace::JOURNAL_OPTION, Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION, Ecapla_CertVerify_Backup::JOURNAL_OPTION, 'certverify_restore_builds' );
foreach ( $pending as $key ) { lwp_assert( ! lwp_raw( $key )['exists'], 'Existing maintenance state; refusing probe.' ); }
lwp_assert( DomainCutoverState::LEGACY === ( new DomainCutoverState() )->authority(), 'Legacy authority required; no authority changes outside rollback.' );
$cache_keys = array( Ecapla_CertVerify_Settings::OPTION_KEY, Ecapla_CertVerify_Fields::OPTION_KEY, Ecapla_CertVerify_Fields::RETIRED_OPTION_KEY, Ecapla_CertVerify_Numbering::STATE_OPTION, DomainCutoverState::OPTION );
$cache_before = array(); foreach ( $cache_keys as $key ) { $cache_before[ $key ] = get_option( $key, null ); }
$fields_before = Ecapla_CertVerify_Fields::get_fields(); $retired_before = Ecapla_CertVerify_Fields::get_retired_fields();
$before = lwp_digest(); $nonce = 'legacy-probe-' . bin2hex( random_bytes( 10 ) );
$result = ( new Transaction() )->run( static function () use ( $nonce ) {
	global $wpdb;
	$settings = Ecapla_CertVerify_Settings::get_all();
	$settings['numbering_mode'] = 'sequential'; $settings['numbering_prefix'] = 'PROBE-'; $settings['numbering_start'] = 200;
	lwp_assert( Ecapla_CertVerify_Settings::save( $settings ), 'Settings command failed.' );
	lwp_assert( is_int( Ecapla_CertVerify_Numbering::allocate_sequence() ), 'Protected numbering allocation failed.' );
	$field = array( 'key' => $nonce, 'label' => 'Rollback fixture', 'type' => 'text', 'sensitivity' => 'personal' );
	lwp_assert( Ecapla_CertVerify_Fields::retire_field( $field ), 'Protected field retirement failed.' );
	$id = lwp_ok( Ecapla_CertVerify_Database::insert( array( 'cert_number' => $nonce, 'holder_name' => 'Rollback fixture' ) ), 'Insert' );
	lwp_ok( Ecapla_CertVerify_Database::update( $id, array( 'cert_number' => $nonce, 'holder_name' => 'Updated rollback fixture' ) ), 'Update' );
	// Trusted restore also persists retired values; no direct fixture row writes.
	lwp_ok( Ecapla_CertVerify_Database::restore_record( array( 'cert_number' => $nonce, 'holder_name' => 'Restored rollback fixture', 'custom_fields' => array( $nonce => 'erase me' ) ), $id ), 'Trusted-record restore' );
	$purged = lwp_ok( Ecapla_CertVerify_Database::purge_custom_field_values( $nonce ), 'Purge' );
	lwp_assert( 1 === $purged['changed'] && $purged['complete'], 'Purge did not process a populated row.' );
	lwp_assert( false === strpos( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT custom_fields FROM %i WHERE id = %d', Ecapla_CertVerify_Database::table_name(), $id ) ), $nonce ), 'Retired value survived purge.' );
	lwp_ok( Ecapla_CertVerify_Database::delete( $id ), 'Delete' );
	$one = lwp_ok( Ecapla_CertVerify_Database::insert( array( 'cert_number' => $nonce . '-a', 'holder_name' => 'A' ) ), 'Bulk fixture A' );
	$two = lwp_ok( Ecapla_CertVerify_Database::insert( array( 'cert_number' => $nonce . '-b', 'holder_name' => 'B' ) ), 'Bulk fixture B' );
	lwp_ok( Ecapla_CertVerify_Database::delete_many( array( $one, $two ) ), 'Bulk delete' );
	lwp_assert( Ecapla_CertVerify_Fields::remove_retired_field( $nonce ), 'Retired definition removal failed.' );
	update_option( DomainCutoverState::OPTION, DomainCutoverState::DOMAIN, false );
	$denied = Ecapla_CertVerify_Database::restore_record( array( 'cert_number' => $nonce, 'holder_name' => 'Must not write' ) );
	lwp_assert( is_wp_error( $denied ) && 'certloom_legacy_registry_read_only' === $denied->get_error_code(), 'Trusted helper bypassed domain cutover.' );
	foreach ( array( Ecapla_CertVerify_Backup::start_restore( 'unavailable-probe', 'update' ), Ecapla_CertVerify_Backup::continue_restore( 'unavailable-probe' ) ) as $blocked_restore ) { lwp_assert( is_wp_error( $blocked_restore ) && 'certloom_legacy_registry_read_only' === $blocked_restore->get_error_code(), 'Legacy restore coordinator bypassed cutover.' ); }
	return new WP_Error( 'intentional_legacy_rollback', 'Rollback-only probe.' );
} );
lwp_assert( is_wp_error( $result ) && 'intentional_legacy_rollback' === $result->get_error_code(), 'Populated legacy probe failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
lwp_assert( $before === lwp_digest(), 'Legacy rows/options/generations were not rolled back exactly.' );
foreach ( $cache_keys as $key ) { lwp_assert( $cache_before[ $key ] === get_option( $key, null ), 'Rolled-back option remains in cache: ' . $key ); }
lwp_assert( $fields_before === Ecapla_CertVerify_Fields::get_fields() && $retired_before === Ecapla_CertVerify_Fields::get_retired_fields(), 'Runtime field cache survived rollback.' );
echo "PASS  populated legacy CRUD, numbering, fields, trusted restore and purge roll back together\n";

$cursor_key = 'certverify_field_purge_' . md5( $nonce );
$key_method = new ReflectionMethod( Ecapla_CertVerify_Database::class, 'purge_state_option_key' );
if ( PHP_VERSION_ID < 80100 ) { $key_method->setAccessible( true ); }
$cursor_key = $key_method->invoke( null, $nonce );
lwp_assert( ! lwp_raw( $cursor_key )['exists'], 'Existing nonce purge progress.' );
$cursor_probe = ( new Transaction() )->run( static function () use ( $nonce, $cursor_key ) {
 global $wpdb;
 lwp_assert( Ecapla_CertVerify_Fields::retire_field( array( 'key' => $nonce, 'label' => 'Cursor rollback fixture', 'type' => 'text' ) ), 'Cannot retire cursor fixture.' );
 $id = lwp_ok( Ecapla_CertVerify_Database::restore_record( array( 'cert_number' => $nonce . '-cursor-0', 'holder_name' => 'Cursor fixture', 'custom_fields' => array( $nonce => 'erase' ) ) ), 'Cursor seed' );
 // Seed a bounded copied-data fixture inside the same rollback-only transaction.
 $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Ecapla_CertVerify_Database::table_name(), $id ), ARRAY_A ); unset( $row['id'] );
 for ( $i = 1; $i <= 200; $i++ ) { $row['cert_number'] = $nonce . '-cursor-' . $i; $row['cert_number_hash'] = Ecapla_CertVerify_Database::certificate_hash( $row['cert_number'] ); lwp_assert( false !== $wpdb->insert( Ecapla_CertVerify_Database::table_name(), $row ), 'Cursor fixture copy failed.' ); }
 $progress = lwp_ok( Ecapla_CertVerify_Database::purge_custom_field_values( $nonce ), 'Bounded cursor purge' );
 lwp_assert( 200 === $progress['changed'] && ! $progress['complete'] && is_array( get_option( $cursor_key ) ), 'Real purge did not save and warm a resumable cursor.' );
 return new WP_Error( 'intentional_cursor_rollback', 'Rollback cursor fixture.' );
} );
lwp_assert( is_wp_error( $cursor_probe ) && 'intentional_cursor_rollback' === $cursor_probe->get_error_code() && ! lwp_raw( $cursor_key )['exists'] && false === get_option( $cursor_key ) && $before === lwp_digest(), 'Rolled-back purge cursor/rows survived in storage or cache.' );
echo "PASS  populated 201-row purge rolls back its first batch and warmed resumable cursor together\n";

// These fixtures are durable only long enough to test ordinary callback admission.
$fixtures = array(
	Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION => array( 'token_hash' => hash( 'sha256', $nonce ), 'expires_at' => time() - 1 ),
	Ecapla_CertVerify_Backup::JOURNAL_OPTION => 'corrupt-recovery-state',
	'certverify_restore_builds' => array( 'fixture' => array( 'token_hash' => hash( 'sha256', $nonce ), 'updated_at' => time() ) ),
);
foreach ( $fixtures as $key => $value ) {
	lwp_assert( add_option( $key, $value, '', false ), 'Cannot install owned recovery fixture.' );
	try {
		$called = false;
		$blocked = ( new Transaction() )->run( static function () use ( &$called ) { $called = true; return true; } );
		lwp_assert( ! $called && is_wp_error( $blocked ), 'Legacy recovery state admitted a native writer.' );
		lwp_assert( false === Ecapla_CertVerify_Settings::save( Ecapla_CertVerify_Settings::get_all() ), 'Legacy recovery state admitted settings.' );
		$native = ( new DomainMaintenanceLock() )->acquire( bin2hex( random_bytes( 16 ) ) );
		lwp_assert( is_wp_error( $native ), 'Native maintenance displaced legacy recovery state.' );
		if ( Ecapla_CertVerify_Backup::JOURNAL_OPTION === $key ) {
			$recover = Ecapla_CertVerify_Backup::recover_interrupted_restore();
			lwp_assert( is_wp_error( $recover ) && 'ecapla_certverify_restore_state_corrupt' === $recover->get_error_code(), 'Corrupt journal was mistaken for no recovery evidence.' );
		}
		lwp_assert( lwp_raw( $key )['value'] === $value, 'Admission modified protected recovery evidence.' );
	} finally {
		$current = lwp_raw( $key );
		lwp_assert( $current['exists'] && $current['value'] === $value, 'Recovery fixture ownership changed; refusing cleanup.' );
		lwp_assert( delete_option( $key ), 'Cannot remove owned recovery fixture.' );
	}
}
$malformed = array( 'state' => 'swapped', 'suffix' => '../unsafe', 'staging' => Ecapla_CertVerify_Database::table_name() );
lwp_assert( add_option( Ecapla_CertVerify_Backup::JOURNAL_OPTION, $malformed, '', false ), 'Cannot install malformed journal fixture.' );
try {
 $recover = Ecapla_CertVerify_Backup::recover_interrupted_restore();
 lwp_assert( is_wp_error( $recover ) && 'ecapla_certverify_restore_state_corrupt' === $recover->get_error_code(), 'Malformed array journal reached recovery writes.' );
 lwp_assert( lwp_raw( Ecapla_CertVerify_Backup::JOURNAL_OPTION )['value'] === $malformed && $before === lwp_digest(), 'Malformed journal recovery changed durable data.' );
} finally { lwp_assert( lwp_raw( Ecapla_CertVerify_Backup::JOURNAL_OPTION )['value'] === $malformed && delete_option( Ecapla_CertVerify_Backup::JOURNAL_OPTION ), 'Cannot remove owned malformed journal.' ); }
echo "PASS  expired leases, corrupt journals and pending builds block both stores without erasing evidence\n";

// A failed direct option read must never be interpreted as absent recovery state.
$fail = static function ( $query ) { return strpos( $query, 'SELECT option_name, option_value' ) === 0 && strpos( $query, Ecapla_CertVerify_Backup::JOURNAL_OPTION ) !== false ? 'SELECT * FROM `certloom_missing_read_failure_fixture`' : $query; };
$suppressed = $wpdb->suppress_errors( true );
add_filter( 'query', $fail );
try {
	$called = false;
	$blocked = ( new Transaction() )->run( static function () use ( &$called ) { $called = true; return true; } );
	lwp_assert( ! $called && is_wp_error( $blocked ), 'Failed durable read admitted a writer.' );
	$recover = Ecapla_CertVerify_Backup::recover_interrupted_restore();
	lwp_assert( is_wp_error( $recover ) && 'certloom_database_options_read_failed' === $recover->get_error_code(), 'Recovery ignored a failed durable read.' );
} finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $suppressed ); }
lwp_assert( $before === lwp_digest(), 'Probe changed the baseline.' );
echo "PASS  failed durable recovery reads stop callbacks and recovery\n4 live groups, 0 failures\n";
