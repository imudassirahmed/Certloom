<?php

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$tmp  = sys_get_temp_dir() . '/certloom-bak02-run-' . bin2hex( random_bytes( 4 ) );
@mkdir( $tmp . '/webroot/wp-content', 0777, true );
define( 'ABSPATH', $tmp . '/webroot/' );
define( 'WP_CONTENT_DIR', $tmp . '/webroot/wp-content' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'WEEK_IN_SECONDS', 604800 );

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text, $domain = null ) { return $text; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_file_name( $value ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', basename( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }
function untrailingslashit( $path ) { return rtrim( (string) $path, '/\\' ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function maybe_serialize( $value ) { return serialize( $value ); }
function add_filter( $tag, $callback ) { return true; }
function add_action( $tag, $callback ) { return true; }
$GLOBALS['certloom_test_options'] = array();
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['certloom_test_options'] ) ? $GLOBALS['certloom_test_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['certloom_test_options'][ $key ] = $value; return true; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { if ( array_key_exists( $key, $GLOBALS['certloom_test_options'] ) ) { return false; } $GLOBALS['certloom_test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { if ( ! array_key_exists( $key, $GLOBALS['certloom_test_options'] ) ) { return false; } unset( $GLOBALS['certloom_test_options'][ $key ] ); return true; }
$GLOBALS['certloom_private_backup_dir'] = $tmp . '/private-backups';
function apply_filters( $tag, $value ) {
	if ( 'certloom_private_backup_directory' === $tag ) { return $GLOBALS['certloom_private_backup_dir']; }
	if ( 'certloom_allow_private_backup_directory_inside_webroot' === $tag ) { return false; }
	return $value;
}
$GLOBALS['certloom_test_event'] = null;
function wp_get_scheduled_event( $hook ) { return $GLOBALS['certloom_test_event']; }
function wp_next_scheduled( $hook ) { return $GLOBALS['certloom_test_event'] ? $GLOBALS['certloom_test_event']->timestamp : false; }
function wp_clear_scheduled_hook( $hook, $args = array(), $wp_error = false ) { $GLOBALS['certloom_test_event'] = null; return 1; }
function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) { $GLOBALS['certloom_test_event'] = (object) array( 'timestamp' => $timestamp, 'schedule' => $recurrence, 'hook' => $hook ); return true; }

$GLOBALS['certloom_builder_calls'] = 0;
$GLOBALS['certloom_builder_mode']  = 'success';
class Ecapla_CertVerify_Backup {
	public static function create_export_file() {
		$GLOBALS['certloom_builder_calls']++;
		if ( 'error' === $GLOBALS['certloom_builder_mode'] ) {
			return new WP_Error( 'certloom_test_builder_failure', 'Synthetic canonical backup builder failure.' );
		}
		$path = tempnam( sys_get_temp_dir(), 'certloom-bak02-builder-' );
		$bytes = "{\"type\":\"manifest\"}\n{\"type\":\"integrity\"}\n";
		file_put_contents( $path, $bytes );
		return array(
			'path' => $path,
			'filename' => 'certloom-test.cvbackup',
			'bytes' => strlen( $bytes ),
			'records' => 2,
			'file_sha256' => hash( 'sha256', $bytes ),
			'content_sha256' => str_repeat( 'b', 64 ),
			'generated_at' => '2026-08-29T00:00:00Z',
			'format_version' => 4,
			'plugin_version' => '1.6.88',
			'schema_version' => '1.4.7',
			'source_authority' => 'current_registry',
		);
	}
}

require_once __DIR__ . '/bootstrap-option-command-double.php';
require_once __DIR__ . '/bootstrap-autoload.php';
require $root . '/src/Infrastructure/Backup/LocalBackupStore.php';
require $root . '/src/Infrastructure/Backup/ScheduledBackup.php';

use Certloom\Infrastructure\Backup\LocalBackupStore;
use Certloom\Infrastructure\Backup\ScheduledBackup;

$checks = 0; $fails = 0;
function check_run( $condition, $message ) { global $checks, $fails; $checks++; if ( $condition ) { echo "PASS  {$message}\n"; } else { $fails++; echo "FAIL  {$message}\n"; } }

$manual = ScheduledBackup::create_now( 'manual' );
check_run( is_array( $manual ) && 'manual' === $manual['purpose'], 'manual run stores one verified current-authority backup' );
check_run( false === get_option( ScheduledBackup::LOCK_OPTION, false ), 'successful backup releases run lock' );
$last = ScheduledBackup::last_result();
check_run( 'success' === ( $last['status'] ?? '' ) && 'manual' === ( $last['purpose'] ?? '' ) && ! isset( $last['path'] ) && ! isset( $last['filename'] ), 'last-result evidence is privacy-safe and excludes filesystem details' );
$resolved = ( new LocalBackupStore() )->resolve( $manual['id'] );
check_run( is_array( $resolved ) && is_file( $resolved['path'] ), 'successful run produces a resolvable private catalog entry' );

$builder_before = $GLOBALS['certloom_builder_calls'];
update_option( ScheduledBackup::LOCK_OPTION, array( 'owner' => str_repeat( 'a', 32 ), 'started' => time(), 'expires' => time() + 300 ), false );
$blocked = ScheduledBackup::create_now( 'manual' );
check_run( is_wp_error( $blocked ) && 'certloom_backup_locked' === $blocked->get_error_code(), 'active run lock refuses overlapping backup execution' );
check_run( $builder_before === $GLOBALS['certloom_builder_calls'], 'active lock refuses overlap before invoking canonical builder' );
delete_option( ScheduledBackup::LOCK_OPTION );

update_option( ScheduledBackup::LOCK_OPTION, array( 'owner' => str_repeat( 'c', 32 ), 'started' => time() - 4000, 'expires' => time() - 1 ), false );
$stale = ScheduledBackup::create_now( 'scheduled' );
check_run( is_array( $stale ) && 'scheduled' === $stale['purpose'], 'expired run lock is taken over safely for the next scheduled backup' );
check_run( false === get_option( ScheduledBackup::LOCK_OPTION, false ), 'stale-lock takeover still releases the replacement lock after success' );

$GLOBALS['certloom_builder_mode'] = 'error';
$failed = ScheduledBackup::create_now( 'manual' );
check_run( is_wp_error( $failed ) && 'certloom_test_builder_failure' === $failed->get_error_code(), 'canonical builder failure propagates without creating a false backup success' );
check_run( false === get_option( ScheduledBackup::LOCK_OPTION, false ), 'failed backup releases its owned run lock' );
$last = ScheduledBackup::last_result();
check_run( 'failed' === ( $last['status'] ?? '' ) && 'manual' === ( $last['purpose'] ?? '' ) && false === strpos( (string) ( $last['message'] ?? '' ), sys_get_temp_dir() ), 'failed last-result evidence remains concise and path-free' );
$GLOBALS['certloom_builder_mode'] = 'success';

$invalid = ScheduledBackup::create_now( '../not-a-purpose' );
check_run( is_wp_error( $invalid ) && 'certloom_backup_purpose_invalid' === $invalid->get_error_code(), 'invalid backup purpose is refused before run-lock or storage work' );

$GLOBALS['certloom_private_backup_dir'] = ABSPATH . 'wp-content/exposed';
$unsafe = ScheduledBackup::create_now( 'manual' );
check_run( is_wp_error( $unsafe ) && 'certloom_backup_directory_web_exposed' === $unsafe->get_error_code(), 'storage failure is propagated when private destination becomes unsafe at run time' );
check_run( false === get_option( ScheduledBackup::LOCK_OPTION, false ), 'storage failure still releases run lock' );
$GLOBALS['certloom_private_backup_dir'] = $tmp . '/private-backups';

function rrmdir_run( $dir ) { if ( ! is_dir( $dir ) ) return; foreach ( scandir( $dir ) as $name ) { if ( '.' === $name || '..' === $name ) continue; $path = $dir . '/' . $name; if ( is_dir( $path ) && ! is_link( $path ) ) rrmdir_run( $path ); else @unlink( $path ); } @rmdir( $dir ); }
rrmdir_run( $tmp );
echo "\n{$checks} checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
