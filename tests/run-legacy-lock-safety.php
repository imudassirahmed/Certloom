<?php
/** Regression tests for checked lock cleanup on the live legacy 1.5 authority paths. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1024 * 1024 );
$GLOBALS['legacy_lock_options'] = array();
$GLOBALS['legacy_lock_delete_fail'] = false;
$GLOBALS['legacy_lock_transients'] = array();

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $t ) { return $t; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['legacy_lock_options'] ) ? $GLOBALS['legacy_lock_options'][ $k ] : $d; }
function add_option( $k, $v, $deprecated = '', $autoload = false ) { if ( array_key_exists( $k, $GLOBALS['legacy_lock_options'] ) ) return false; $GLOBALS['legacy_lock_options'][ $k ] = $v; return true; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['legacy_lock_options'][ $k ] = $v; return true; }
function delete_option( $k ) { if ( array_key_exists( $k, $GLOBALS['legacy_lock_options'] ) ) { unset( $GLOBALS['legacy_lock_options'][ $k ] ); return true; } return false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['legacy_lock_transients'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['legacy_lock_transients'][ $k ] ?? false; }
function wp_cache_delete() { return true; }
function wp_generate_password( $len = 12 ) { return str_repeat( 'g', $len ); }
function maybe_serialize( $v ) { return serialize( $v ); }
require __DIR__ . '/support/option-database.php';
require dirname( __DIR__ ) . '/src/Infrastructure/Data/DatabaseOptions.php';
require_once __DIR__ . '/bootstrap-autoload.php';

class LegacyLockWpdb {
	public $options = 'wp_options';
	public $last_error = '';
	public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
	public function get_var( $prepared ) {
		$this->last_error = '';
		$sql = is_array( $prepared ) ? $prepared['sql'] : $prepared;
		if ( strpos( $sql, 'SELECT GET_LOCK' ) === 0 || strpos( $sql, 'SELECT RELEASE_LOCK' ) === 0 ) { return '1'; }
		if ( strpos( $sql, 'SELECT option_name' ) === 0 ) { return null; }
		$key = $prepared['args'][1] ?? '';
		$value = $GLOBALS['legacy_lock_options'][ $key ] ?? null;
		return is_array( $value ) ? serialize( $value ) : $value;
	}
	public function get_results( $prepared, $format ) {
		$this->last_error = '';
		return certloom_test_option_rows( array( 'query' => $prepared['sql'], 'args' => $prepared['args'] ), $GLOBALS['legacy_lock_options'] );
	}
	public function query( $prepared ) {
		$args = is_array( $prepared ) ? $prepared['args'] : array();
		if ( false === stripos( (string) ( $prepared['sql'] ?? '' ), 'DELETE' ) ) { return 0; }
		if ( $GLOBALS['legacy_lock_delete_fail'] ) { $GLOBALS['legacy_lock_delete_fail'] = false; return 0; }
		$key = $args[1] ?? '';
		$expected = $args[2] ?? null;
		$current = $GLOBALS['legacy_lock_options'][ $key ] ?? null;
		$matches = is_array( $current ) ? maybe_serialize( $current ) === $expected : $current === $expected;
		if ( $matches ) { unset( $GLOBALS['legacy_lock_options'][ $key ] ); return 1; }
		return 0;
	}
}
$GLOBALS['wpdb'] = new LegacyLockWpdb();

require_once dirname( __DIR__ ) . '/includes/class-ecapla-certverify-database.php';
require_once dirname( __DIR__ ) . '/includes/class-ecapla-certverify-import.php';
require_once dirname( __DIR__ ) . '/includes/class-ecapla-certverify-backup.php';

function ll_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) {
		throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

// Spreadsheet exports must neutralize dangerous prefixes even when malformed UTF-8 makes the Unicode matcher fail.
ll_same( "'=SUM(A1:A2)", Ecapla_CertVerify_Import::escape_spreadsheet_cell( '=SUM(A1:A2)' ), 'ordinary formula prefix neutralized' );
$invalid_utf8 = "\xFF=SUM(A1:A2)";
ll_same( "'" . $invalid_utf8, Ecapla_CertVerify_Import::escape_spreadsheet_cell( $invalid_utf8 ), 'malformed UTF-8 fails safe' );
echo "PASS  CSV formula neutralization fails safe on malformed UTF-8\n";

// Legacy schema migration lock.
$migration = new ReflectionMethod( Ecapla_CertVerify_Database::class, 'release_migration_lock' );
if ( PHP_VERSION_ID < 80100 ) { $migration->setAccessible( true ); }
$token = 'schema-owner';
$GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Database::MIGRATION_LOCK_OPTION ] = $token . '|' . time();
ll_same( true, $migration->invoke( null, $token ), 'owned migration lock releases' );
$GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Database::MIGRATION_LOCK_OPTION ] = 'other|' . time();
ll_same( 'ecapla_certverify_schema_lock_owner_changed', $migration->invoke( null, $token )->get_error_code(), 'migration owner mismatch explicit' );
$GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Database::MIGRATION_LOCK_OPTION ] = $token . '|' . time();
$GLOBALS['legacy_lock_delete_fail'] = true;
ll_same( 'ecapla_certverify_schema_lock_release_failed', $migration->invoke( null, $token )->get_error_code(), 'migration delete failure explicit' );
echo "PASS  live legacy schema-migration lock cleanup is owner-checked and explicit\n";

// Resumable CSV process lock.
$import_key_method = new ReflectionMethod( Ecapla_CertVerify_Import::class, 'process_lock_key' );
if ( PHP_VERSION_ID < 80100 ) { $import_key_method->setAccessible( true ); }
$import_release = new ReflectionMethod( Ecapla_CertVerify_Import::class, 'release_process_lock' );
if ( PHP_VERSION_ID < 80100 ) { $import_release->setAccessible( true ); }
$import_token = 'import-session';
$import_owner = 'import-owner';
$import_key = $import_key_method->invoke( null, $import_token );
$GLOBALS['legacy_lock_options'][ $import_key ] = $import_owner . '|' . time();
ll_same( true, $import_release->invoke( null, $import_token, $import_owner ), 'owned import lock releases' );
$GLOBALS['legacy_lock_options'][ $import_key ] = 'other|' . time();
ll_same( 'ecapla_certverify_import_lock_owner_changed', $import_release->invoke( null, $import_token, $import_owner )->get_error_code(), 'import owner mismatch explicit' );
$GLOBALS['legacy_lock_options'][ $import_key ] = $import_owner . '|' . time();
$GLOBALS['legacy_lock_delete_fail'] = true;
ll_same( 'ecapla_certverify_import_lock_release_failed', $import_release->invoke( null, $import_token, $import_owner )->get_error_code(), 'import delete failure explicit' );
echo "PASS  live resumable-import lock cleanup cannot silently fail\n";

// Short per-request restore lock.
$restore_name = new ReflectionMethod( Ecapla_CertVerify_Backup::class, 'restore_lock_name' );
if ( PHP_VERSION_ID < 80100 ) { $restore_name->setAccessible( true ); }
$restore_release = new ReflectionMethod( Ecapla_CertVerify_Backup::class, 'release_restore_lock' );
if ( PHP_VERSION_ID < 80100 ) { $restore_release->setAccessible( true ); }
$restore_token = 'restore-session';
$restore_owner = 'restore-owner|' . time();
$restore_key = $restore_name->invoke( null, $restore_token );
$GLOBALS['legacy_lock_options'][ $restore_key ] = $restore_owner;
ll_same( true, $restore_release->invoke( null, $restore_token, $restore_owner ), 'owned restore request lock releases' );
$GLOBALS['legacy_lock_options'][ $restore_key ] = 'other|' . time();
ll_same( 'ecapla_certverify_restore_lock_owner_changed', $restore_release->invoke( null, $restore_token, $restore_owner )->get_error_code(), 'restore owner mismatch explicit' );
$GLOBALS['legacy_lock_options'][ $restore_key ] = $restore_owner;
$GLOBALS['legacy_lock_delete_fail'] = true;
ll_same( 'ecapla_certverify_restore_lock_release_failed', $restore_release->invoke( null, $restore_token, $restore_owner )->get_error_code(), 'restore delete failure explicit' );
echo "PASS  live restore request-lock cleanup cannot silently fail\n";

// A malformed live restore-maintenance option must not reopen normal writes.
$GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] = 'corrupt-maintenance-state';
ll_same( true, Ecapla_CertVerify_Backup::is_restore_in_progress(), 'corrupt maintenance state fails closed' );
$blocked = Ecapla_CertVerify_Backup::assert_writes_allowed();
ll_same( 'ecapla_certverify_restore_write_locked', $blocked->get_error_code(), 'corrupt maintenance state blocks live writes' );
unset( $GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] );
echo "PASS  corrupt live restore-maintenance state fails closed for ordinary writes\n";

// A committed legacy restore keeps its durable recovery journal until the
// maintenance lock is actually released; replay finishes cleanup safely.
$lock_hash = str_repeat( 'a', 40 );
$GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::JOURNAL_OPTION ] = array(
	'state' => 'committed', 'suffix' => 'abc123', 'staging' => '', 'rollback' => '', 'lock_hash' => $lock_hash,
);
$GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] = array(
	'token_hash' => $lock_hash, 'expires_at' => time() + 600,
);
$GLOBALS['legacy_lock_options']['certverify_restore_builds'] = array(
	substr( $lock_hash, 0, 32 ) => array( 'table' => '', 'token_hash' => $lock_hash, 'updated_at' => time() ),
);
$GLOBALS['legacy_lock_delete_fail'] = true;
$first = Ecapla_CertVerify_Backup::recover_interrupted_restore();
ll_same( 'ecapla_certverify_restore_cleanup_failed', $first->get_error_code(), 'committed cleanup failure explicit' );
ll_same( true, isset( $GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::JOURNAL_OPTION ] ), 'journal preserved after failed maintenance release' );
$second = Ecapla_CertVerify_Backup::recover_interrupted_restore();
ll_same( true, $second, 'committed cleanup replay succeeds' );
ll_same( false, isset( $GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::JOURNAL_OPTION ] ), 'journal removed only after cleanup' );
ll_same( false, isset( $GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] ), 'maintenance lock released on replay' );
ll_same( false, isset( $GLOBALS['legacy_lock_options']['certverify_restore_builds'] ), 'workspace tracking removed with committed recovery' );
echo "PASS  committed live restore cleanup remains durably replayable\n";

// Abandoning an uncommitted restore must surface maintenance-release failure
// instead of returning only the original validation/storage error.
$abandon = new ReflectionMethod( Ecapla_CertVerify_Backup::class, 'abandon_restore_process' );
if ( PHP_VERSION_ID < 80100 ) { $abandon->setAccessible( true ); }
$abandon_token = 'abandon-session';
$abandon_hash = substr( hash( 'sha256', $abandon_token ), 0, 40 );
$GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] = array(
    'token_hash' => $abandon_hash, 'expires_at' => time() + 600,
);
$abandon_manifest = array( 'restore_process' => array( 'staging' => '' ) );
$GLOBALS['legacy_lock_delete_fail'] = true;
$abandon_first = $abandon->invoke( null, $abandon_token, $abandon_manifest );
ll_same( 'ecapla_certverify_restore_cleanup_failed', $abandon_first->get_error_code(), 'abandon cleanup failure explicit' );
ll_same( true, isset( $GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] ), 'abandon keeps lock for retry' );
$abandon_second = $abandon->invoke( null, $abandon_token, $abandon_manifest );
ll_same( true, $abandon_second, 'abandon cleanup replay succeeds' );
ll_same( false, isset( $GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] ), 'abandon lock released on retry' );
echo "PASS  uncommitted restore abandon cannot hide cleanup uncertainty\n";

// Expired restore-build cleanup must not forget its tracking record when the
// owner-matched maintenance lock cannot be released yet.
$cleanup_stale = new ReflectionMethod( Ecapla_CertVerify_Backup::class, 'cleanup_stale_restore_builds' );
if ( PHP_VERSION_ID < 80100 ) { $cleanup_stale->setAccessible( true ); }
$stale_hash = str_repeat( 'b', 40 );
$stale_key = substr( $stale_hash, 0, 32 );
$GLOBALS['legacy_lock_options']['certverify_restore_builds'] = array(
    $stale_key => array( 'table' => '', 'token_hash' => $stale_hash, 'updated_at' => time() - 7200 ),
);
$GLOBALS['legacy_lock_options'][ Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION ] = array(
    'token_hash' => $stale_hash, 'expires_at' => time() + 600,
);
$GLOBALS['legacy_lock_delete_fail'] = true;
$stale_first = $cleanup_stale->invoke( null );
ll_same( 'ecapla_certverify_restore_cleanup_failed', $stale_first->get_error_code(), 'stale cleanup release failure explicit' );
ll_same( true, isset( $GLOBALS['legacy_lock_options']['certverify_restore_builds'][ $stale_key ] ), 'stale cleanup tracking preserved' );
$stale_second = $cleanup_stale->invoke( null );
ll_same( true, $stale_second, 'stale cleanup replay succeeds' );
ll_same( false, isset( $GLOBALS['legacy_lock_options']['certverify_restore_builds'] ), 'stale tracking cleared after successful retry' );
echo "PASS  expired restore workspace cleanup remains retryable\n";

echo "\n7 tests, 0 failures\n";
