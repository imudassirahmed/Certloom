<?php
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$tmp  = sys_get_temp_dir() . '/certloom-bak02-' . bin2hex( random_bytes( 4 ) );
@mkdir( $tmp, 0777, true );
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

require $root . '/src/Infrastructure/Backup/LocalBackupStore.php';
require $root . '/src/Infrastructure/Backup/ScheduledBackup.php';

use Certloom\Infrastructure\Backup\LocalBackupStore;
use Certloom\Infrastructure\Backup\ScheduledBackup;

$checks = 0; $fails = 0;
function check_bak( $condition, $message ) { global $checks, $fails; $checks++; if ( $condition ) { echo "PASS  {$message}\n"; } else { $fails++; echo "FAIL  {$message}\n"; } }

$store = new LocalBackupStore();
$dir = $store->ensure_directory();
check_bak( ! is_wp_error( $dir ) && is_dir( $dir ), 'private persistent backup directory can be established' );
check_bak( 0 !== strpos( wp_normalize_path( $dir ), trailingslashit( wp_normalize_path( ABSPATH ) ) ), 'default/test backup directory is outside public WordPress webroot' );
check_bak( file_exists( $dir . '/.htaccess' ) && file_exists( $dir . '/web.config' ) && file_exists( $dir . '/index.php' ), 'backup directory receives defense-in-depth protection sentinels' );
$storage_probe = $store->test_storage();
$probe_files = glob( trailingslashit( $dir ) . 'certloom-storage-probe-*.tmp' );
check_bak( true === $storage_probe && empty( $probe_files ), 'private destination probe creates, verifies, and removes owned test bytes cleanly' );

$source = $tmp . '/source.cvbackup';
file_put_contents( $source, "{\"type\":\"manifest\"}\n{\"type\":\"integrity\"}\n" );
$meta = array(
	'file_sha256' => hash_file( 'sha256', $source ), 'bytes' => filesize( $source ), 'records' => 7,
	'content_sha256' => str_repeat( 'a', 64 ), 'generated_at' => '2026-08-29T00:00:00Z',
	'format_version' => 4, 'plugin_version' => '1.6.88', 'schema_version' => '1.4.7', 'source_authority' => 'current_registry',
);
$one = $store->store( $source, $meta, 'manual' );
check_bak( is_array( $one ) && 'manual' === $one['purpose'] && 'current_registry' === $one['source_authority'], 'manual saved backup records current-registry authority and bounded purpose' );
$list = $store->list_backups();
check_bak( 1 === count( $list ) && hash_equals( $one['id'], $list[0]['id'] ), 'saved backup catalog is persisted and bounded' );
$resolved = $store->resolve( $one['id'] );
check_bak( is_array( $resolved ) && is_file( $resolved['path'] ) && hash_equals( $meta['file_sha256'], hash_file( 'sha256', $resolved['path'] ) ), 'saved backup resolve re-verifies exact file hash and bytes' );

$two = $store->store( $source, $meta, 'scheduled' );
usleep( 1000 );
$three = $store->store( $source, $meta, 'scheduled' );
usleep( 1000 );
$four = $store->store( $source, $meta, 'scheduled' );
$pruned = $store->prune_scheduled( 2 );
$after_prune = $store->list_backups();
$manual_count = count( array_filter( $after_prune, function( $entry ) { return 'manual' === $entry['purpose']; } ) );
$scheduled_count = count( array_filter( $after_prune, function( $entry ) { return 'scheduled' === $entry['purpose']; } ) );
check_bak( 1 === $pruned && 1 === $manual_count && 2 === $scheduled_count, 'automatic retention prunes scheduled backups without deleting explicit manual backups' );

$resolved = $store->resolve( $four['id'] );
file_put_contents( $resolved['path'], 'tampered', FILE_APPEND );
$tampered = $store->resolve( $four['id'] );
check_bak( is_wp_error( $tampered ) && 'certloom_backup_file_integrity_failed' === $tampered->get_error_code(), 'stored backup tampering fails closed before download or restore review' );
$deleted_tampered = $store->delete( $four['id'] );
check_bak( true === $deleted_tampered && 2 === count( $store->list_backups() ), 'tampered backup remains safely deletable without being trusted for download or restore' );

$GLOBALS['certloom_private_backup_dir'] = ABSPATH . 'wp-content/exposed-backups';
$exposed = ( new LocalBackupStore() )->directory();
check_bak( is_wp_error( $exposed ) && 'certloom_backup_directory_web_exposed' === $exposed->get_error_code(), 'persistent backup store refuses public-webroot location by default' );

$GLOBALS['certloom_private_backup_dir'] = '/';
$root_refused = ( new LocalBackupStore() )->directory();
check_bak( is_wp_error( $root_refused ) && 'certloom_backup_directory_root_refused' === $root_refused->get_error_code(), 'persistent backup store refuses filesystem root ownership' );

$symlink_target = $tmp . '/private-backup-target';
$symlink_path   = $tmp . '/private-backup-link';
@mkdir( $symlink_target, 0777, true );
$symlink_available = function_exists( 'symlink' ) && @symlink( $symlink_target, $symlink_path );
if ( $symlink_available ) {
	$GLOBALS['certloom_private_backup_dir'] = $symlink_path;
	$symlink_refused = ( new LocalBackupStore() )->directory();
	check_bak( is_wp_error( $symlink_refused ) && 'certloom_backup_directory_symlink' === $symlink_refused->get_error_code(), 'persistent backup store refuses symlink directory ownership' );
} else {
	check_bak( true, 'persistent backup store symlink refusal contract is skipped only when platform symlinks are unavailable' );
}
$GLOBALS['certloom_private_backup_dir'] = $tmp . '/private-backups';

$catalog_before_ceiling = get_option( LocalBackupStore::CATALOG_OPTION, array() );
$ceiling_catalog = array();
for ( $i = 0; $i < LocalBackupStore::MAX_CATALOG; $i++ ) {
	$id = str_pad( dechex( $i + 1 ), 32, '0', STR_PAD_LEFT );
	$ceiling_catalog[] = array(
		'id' => $id,
		'filename' => $id . '-manual-' . str_repeat( dechex( ( $i % 15 ) + 1 ), 48 ) . '.cvbackup',
		'purpose' => 'manual', 'created_at' => '2026-08-29T00:00:00Z', 'created_ts' => 1000 + $i,
		'generated_at' => '2026-08-29T00:00:00Z', 'bytes' => $meta['bytes'], 'records' => 1,
		'file_sha256' => $meta['file_sha256'], 'content_sha256' => $meta['content_sha256'],
		'format_version' => 4, 'plugin_version' => '1.6.88', 'schema_version' => '1.4.7',
		'source_authority' => 'current_registry', 'integrity' => 'verified',
	);
}
update_option( LocalBackupStore::CATALOG_OPTION, $ceiling_catalog, false );
$files_before_ceiling = glob( trailingslashit( $tmp . '/private-backups' ) . '*.cvbackup' );
$ceiling_result = $store->store( $source, $meta, 'manual' );
$files_after_ceiling = glob( trailingslashit( $tmp . '/private-backups' ) . '*.cvbackup' );
check_bak( is_wp_error( $ceiling_result ) && 'certloom_backup_catalog_full' === $ceiling_result->get_error_code() && count( (array) $files_before_ceiling ) === count( (array) $files_after_ceiling ), 'hard backup-catalog ceiling refuses creation before any untracked file can be written' );
update_option( LocalBackupStore::CATALOG_OPTION, $catalog_before_ceiling, false );

$result = ScheduledBackup::save_settings( 'daily', 5 );
check_bak( true === $result && 'daily' === get_option( ScheduledBackup::OPTION_SCHEDULE ) && 5 === get_option( ScheduledBackup::OPTION_RETENTION ), 'daily schedule and bounded retention save together' );
check_bak( $GLOBALS['certloom_test_event'] && 'daily' === $GLOBALS['certloom_test_event']->schedule, 'daily WordPress Cron event is reconciled' );
$result = ScheduledBackup::save_settings( 'weekly', 99 );
check_bak( true === $result && 60 === get_option( ScheduledBackup::OPTION_RETENTION ) && 'certloom_weekly' === $GLOBALS['certloom_test_event']->schedule, 'weekly schedule uses reviewed recurrence and clamps retention to 60' );
$result = ScheduledBackup::save_settings( 'off', 14 );
check_bak( true === $result && null === $GLOBALS['certloom_test_event'], 'Off clears automatic backup event' );

$GLOBALS['certloom_private_backup_dir'] = ABSPATH . 'wp-content/exposed-backups';
$result = ScheduledBackup::save_settings( 'daily', 14 );
check_bak( is_wp_error( $result ) && 'off' === get_option( ScheduledBackup::OPTION_SCHEDULE, 'off' ), 'automatic backup cannot be enabled when persistent private storage is unsafe' );
$GLOBALS['certloom_private_backup_dir'] = $tmp . '/private-backups';

$backup_src = file_get_contents( $root . '/includes/class-ecapla-certverify-backup.php' );
$cron_src   = file_get_contents( $root . '/includes/class-ecapla-certverify-cron.php' );
$admin_src  = file_get_contents( $root . '/admin/class-ecapla-certverify-admin.php' );
$view_src   = file_get_contents( $root . '/admin/views/import-upload.php' );
$plugin_src = file_get_contents( $root . '/certloom.php' );
$uninstall_src = file_get_contents( $root . '/uninstall.php' );
check_bak( false !== strpos( $backup_src, 'public static function create_export_file()' ) && false !== strpos( $backup_src, '$backup = self::create_export_file();' ), 'manual download and scheduled storage share one canonical backup builder' );
check_bak( false !== strpos( $backup_src, "'source_authority'=> 'current_registry'" ) || false !== strpos( $backup_src, "'source_authority' => 'current_registry'" ), 'canonical builder identifies current registry as backup authority' );
check_bak( false !== strpos( $cron_src, 'ScheduledBackup::activate()' ) && false !== strpos( $cron_src, 'ScheduledBackup::init()' ) && false !== strpos( $cron_src, 'ScheduledBackup::deactivate()' ), 'plugin cron lifecycle owns scheduled-backup lifecycle' );
check_bak( false !== strpos( $admin_src, 'handle_backup_test_storage' ) && false !== strpos( $admin_src, 'handle_backup_run_now' ) && false !== strpos( $admin_src, 'handle_backup_saved_review' ) && false !== strpos( $admin_src, 'handle_backup_saved_delete' ), 'admin handlers cover storage test, create, verified review, download, and deletion boundaries' );
check_bak( false !== strpos( $view_src, 'Backup & Restore' ) && false !== strpos( $view_src, 'Test storage' ) && false !== strpos( $view_src, 'Daily' ) && false !== strpos( $view_src, 'Weekly' ) && false !== strpos( $view_src, 'Review restore' ), 'Backup Center exposes destination test, bounded schedule, and recovery actions' );
check_bak( false !== strpos( $plugin_src, "define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )" ) && false !== strpos( $plugin_src, "define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false )" ), 'BAK-02A does not open normalized-domain production authority' );
check_bak( false !== strpos( $plugin_src, "ecapla_certverify_for_each_site( 'ecapla_certverify_activate_site' )" ) && false !== strpos( $plugin_src, 'Ecapla_CertVerify_Cron::activate()' ), 'network activation reconciles scheduled-backup cron through the existing per-site activation boundary' );
$preserve_boundary = strpos( $uninstall_src, 'if ( ! $delete_data )' );
$catalog_cleanup   = strpos( $uninstall_src, "delete_option( 'certloom_local_backup_catalog' )" );
check_bak( false !== $preserve_boundary && false !== $catalog_cleanup && $catalog_cleanup > $preserve_boundary, 'uninstall preserves persistent customer backups unless explicit delete-data is enabled' );
check_bak( false !== strpos( $uninstall_src, "wp_clear_scheduled_hook( 'certloom_run_scheduled_backup' )" ) && false !== strpos( $uninstall_src, "delete_option( 'certloom_backup_run_lock' )" ), 'uninstall always clears executable scheduled-backup cron and lock state' );

function rrmdir_bak( $dir ) { if ( ! is_dir( $dir ) ) return; foreach ( scandir( $dir ) as $name ) { if ( '.' === $name || '..' === $name ) continue; $path = $dir . '/' . $name; if ( is_dir( $path ) && ! is_link( $path ) ) rrmdir_bak( $path ); else @unlink( $path ); } @rmdir( $dir ); }
rrmdir_bak( $tmp );
echo "\n{$checks} checks, {$fails} failures\n";
exit( $fails ? 1 : 0 );
