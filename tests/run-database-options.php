<?php
/** Integrity decisions must distinguish a fresh database snapshot from cached or failed reads. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
require_once __DIR__ . '/support/option-database.php';
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_option( $key, $default = false ) { return $GLOBALS['cached_options'][ $key ] ?? $default; }
$database = array( 'certverify_fields' => array(), 'certverify_retired_fields' => array(), 'certverify_settings' => array( 'verification_page' => 9 ), 'certloom_domain_data_generation' => 'before' );
$GLOBALS['cached_options'] = $database;
$wpdb = new CertloomOptionDatabase( $database );
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Application\Import\DomainImportConsistencyGuard;
use Certloom\Application\Restore\DomainRestoreConfirmationValidator;
function option_assert( $expected, $actual, $message ) { if ( $expected !== $actual ) { throw new RuntimeException( $message . ': ' . var_export( $actual, true ) ); } }
$guard = new DomainImportConsistencyGuard();
$baseline = $guard->snapshot();
option_assert( true, is_array( $baseline ), 'baseline read' );
$payload = array( 'confirmed_replace' => true, 'path' => '/private/backup', 'baseline_generation' => $baseline['generation'], 'baseline_configuration_hash' => $baseline['configuration_hash'] );
$confirmation = new DomainRestoreConfirmationValidator();
option_assert( true, $confirmation->validate( $payload, 'confirmed_replace', 'confirm' ), 'unchanged confirmation' );
$database['certverify_settings']['verification_page'] = 10;
option_assert( 9, get_option( 'certverify_settings' )['verification_page'], 'cache intentionally stale' );
option_assert( 'certloom_import_configuration_changed', $guard->validate( $baseline['generation'], $baseline['configuration_hash'] )->get_error_code(), 'stale import config' );
option_assert( 'certloom_domain_restore_confirmation_stale', $confirmation->validate( $payload, 'confirmed_replace', 'confirm' )->get_error_code(), 'stale restore config' );
echo "PASS  stale option caches cannot approve changed import or restore configuration\n";
$database['certloom_domain_data_generation'] = 'after';
option_assert( 'certloom_import_domain_changed', $guard->validate( $baseline['generation'], $baseline['configuration_hash'] )->get_error_code(), 'stale import generation' );
option_assert( 'certloom_domain_restore_confirmation_stale', $confirmation->validate( $payload, 'confirmed_replace', 'confirm' )->get_error_code(), 'stale restore generation' );
option_assert( 'after', ( new DomainGeneration() )->current(), 'diagnostic generation uncached' );
echo "PASS  cached generation cannot approve a stale import or restore baseline\n";
$wpdb->read_error = true;
option_assert( 'certloom_database_options_read_failed', ( new DomainBackupRepository() )->configuration_snapshot()->get_error_code(), 'database error is not missing options' );
option_assert( 'certloom_database_options_read_failed', $guard->snapshot()->get_error_code(), 'failed snapshot' );
option_assert( 'certloom_domain_generation_read_failed', $confirmation->validate( $payload, 'confirmed_replace', 'confirm' )->get_error_code(), 'failed confirmation read' );
$wpdb->read_error = false;
echo "PASS  unavailable database fails closed at snapshot and confirmation boundaries\n";
$database['false'] = false; $database['empty'] = ''; $database['array'] = array( 'name' => 'value' );
$values = DatabaseOptions::read( array( 'missing', 'false', 'empty', 'array' ) );
option_assert( array( 'exists' => false, 'value' => null ), $values['missing'], 'absence' );
option_assert( array( 'exists' => true, 'value' => false ), $values['false'], 'false is present' );
option_assert( array( 'exists' => true, 'value' => '' ), $values['empty'], 'empty is present' );
option_assert( array( 'exists' => true, 'value' => array( 'name' => 'value' ) ), $values['array'], 'serialized array' );
echo "PASS  option snapshots preserve absent, false, empty and structured values\n\n4 tests, 0 failures\n";
