<?php
/** Execute the real uninstall routine against isolated data-only fixtures. */
namespace {
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = dirname( __DIR__ ) . '/output/uninstall-data-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $root, 0700, true ) ) { throw new \RuntimeException( 'Cannot create owned data fixtures.' ); }
define( 'WP_UNINSTALL_PLUGIN', 'certloom/certloom.php' );
define( 'ABSPATH', $root . '/site/app/public/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
$GLOBALS['uninstall_options'] = array(
	'certverify_settings' => array( 'delete_data_on_uninstall' => false ),
	'certloom_email_template_catalog_v1' => array( 'saved' => 'email design' ),
	'certloom_email_template_issued_v1' => array( 'subject' => 'saved choice' ),
	'certloom_email_design_assignments_v1' => array( 'issuer' => 9 ),
	'certloom_email_automation_rules_v1' => array( 'executable' => true ),
);
function get_option( $key, $default = false ) { return $GLOBALS['uninstall_options'][$key] ?? $default; }
function update_option( $key, $value ) { $GLOBALS['uninstall_options'][$key] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['uninstall_options'][$key] ); return true; }
function delete_site_option( $key ) { return true; }
function is_multisite() { return false; }
function wp_clear_scheduled_hook( $hook ) { return true; }
function wp_roles() { return null; }
function get_users( $args ) { return array(); }
function get_current_blog_id() { return 1; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
function untrailingslashit( $path ) { return rtrim( $path, '/\\' ); }
function get_temp_dir() { return $GLOBALS['uninstall_shared_temp'] . '/'; }
class UninstallFixtureDatabase {
	public $prefix = 'fixture_'; public $options = 'fixture_options'; public $usermeta = 'fixture_usermeta'; public $queries = array();
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $sql, ...$args ) { return $sql . ' ' . implode( ' ', $args ); }
	public function query( $sql ) { $this->queries[] = $sql; return 0; }
	public function get_col( $sql ) { return array(); }
}
$wpdb = new UninstallFixtureDatabase();
$shared = $root . '/shared-temp/certloom-private'; $GLOBALS['uninstall_shared_temp'] = dirname( $shared );
$directories = array( WP_CONTENT_DIR . '/certloom-private', dirname( untrailingslashit( ABSPATH ) ) . '/certloom-private', $shared );
$files = array(); $checks = 0;
$check = static function ( $ok, $message ) use ( &$checks ) { if ( ! $ok ) { throw new \RuntimeException( $message ); } ++$checks; echo 'PASS  ' . $message . PHP_EOL; };
$leaf = str_repeat( 'a', 32 ) . '-import-' . str_repeat( 'b', 48 ) . '.data';
try {
	foreach ( $directories as $directory ) {
		if ( ! mkdir( $directory, 0700, true ) ) { throw new \RuntimeException( 'Cannot create fixture directory.' ); }
		foreach ( array( $leaf => 'private job bytes', '.htaccess' => 'Deny from all', 'web.config' => 'deny', 'index.php' => '<?php // silence', 'unknown.data' => 'unrecognized data', 'cache.bin' => 'cached data' ) as $name => $content ) {
			$file = $directory . '/' . $name; $files[$file] = $content;
			if ( strlen( $content ) !== file_put_contents( $file, $content ) ) { throw new \RuntimeException( 'Cannot write fixture bytes.' ); }
		}
	}
	$designs = array_intersect_key( $GLOBALS['uninstall_options'], array_flip( array( 'certloom_email_template_catalog_v1', 'certloom_email_template_issued_v1', 'certloom_email_design_assignments_v1' ) ) );
	require dirname( __DIR__ ) . '/uninstall.php';
	foreach ( $designs as $key => $value ) { $check( get_option( $key ) === $value, 'Retain-data preserves ' . $key ); }
	$check( ! isset( $GLOBALS['uninstall_options']['certloom_email_automation_rules_v1'] ), 'Executable automation is cleared separately from saved designs' );
	foreach ( array_slice( $directories, 0, 2 ) as $directory ) {
		$check( ! file_exists( $directory . '/' . $leaf ), 'Only owned site job data is removed: ' . basename( dirname( $directory ) ) );
		foreach ( array( '.htaccess', 'web.config', 'index.php', 'unknown.data', 'cache.bin' ) as $name ) { $check( file_get_contents( $directory . '/' . $name ) === $files[$directory . '/' . $name], 'Private protection/unrecognized content retained: ' . $name ); }
	}
	foreach ( glob( $shared . '/*' ) as $file ) { $check( file_get_contents( $file ) === $files[$file], 'Shared temp bytes remain untouched: ' . basename( $file ) ); }
	$check( file_get_contents( $shared . '/.htaccess' ) === $files[$shared . '/.htaccess'], 'Shared temp access control remains untouched' );
	$check( ! array_filter( $wpdb->queries, static function ( $sql ) { return strpos( $sql, 'DROP TABLE' ) !== false && strpos( $sql, 'fixture_certloom_credential_series' ) !== false; } ), 'Default uninstall preserves durable credential tables' );
	// Execute the same actual routine with the explicit delete-data opt-in.
	$GLOBALS['uninstall_options']['certverify_settings']['delete_data_on_uninstall'] = true;
	ecapla_certverify_uninstall_all_sites();
	foreach ( $designs as $key => $value ) { $check( false === get_option( $key ), 'Explicit deletion removes ' . $key ); }
	$check( (bool) array_filter( $wpdb->queries, static function ( $sql ) { return strpos( $sql, 'DROP TABLE' ) !== false && strpos( $sql, 'fixture_certloom_credential_series' ) !== false; } ), 'Explicit deletion removes the durable credential table' );
	echo "\n{$checks} checks, 0 failures\n";
} finally {
	// Remove only exact files created above, then empty owned data directories.
	foreach ( $files as $file => $content ) { if ( is_file( $file ) && ! is_link( $file ) && file_get_contents( $file ) === $content ) { unlink( $file ); } }
	$dirs = array( $root ); $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $entry ) { if ( $entry->isDir() && ! $entry->isLink() && strpos( wp_normalize_path( $entry->getPathname() ), wp_normalize_path( $root ) . '/' ) === 0 ) { @rmdir( $entry->getPathname() ); } }
	@rmdir( $root );
}
}
