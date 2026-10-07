<?php
/** Core WordPress lifecycle on the backed-up exact Local site; never deletes plugin files. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
$action = $argv[1] ?? 'inspect';
if ( in_array( $action, array( 'verify-raw-restored', 'capture-raw' ), true ) ) {
 // Verify the complete recovery boundary before WordPress refreshes cron/transient options.
 $config_path = getenv( 'CERTLOOM_TEST_MYSQL_CONFIG' );
 if ( $config_path !== 'C:\\Users\\Admin\\AppData\\Local\\Temp\\certloom-audit-20260923\\mysql-client.cnf' || ! is_file( $config_path ) ) { throw new RuntimeException( 'Exact private Local client configuration required.' ); }
 $config = parse_ini_file( $config_path, true, INI_SCANNER_RAW ); $client = $config['client'] ?? $config;
 $connection = new mysqli( $client['host'] ?? 'localhost', $client['user'] ?? '', $client['password'] ?? '', 'local', (int) ( $client['port'] ?? 0 ) );
 $identity = $connection->query( 'SELECT DATABASE() AS db, @@port AS port' )->fetch_assoc();
 if ( $identity['db'] !== 'local' || (int) $identity['port'] !== 10006 ) { throw new RuntimeException( 'Refusing another database.' ); }
 $connection->set_charset( 'utf8mb4' );
 $state = json_decode( file_get_contents( sys_get_temp_dir() . '/certloom-wordpress-lifecycle-state-20260929.json' ), true, 512, JSON_THROW_ON_ERROR );
 $result = $connection->query( 'SHOW TABLES' ); $data = array();
 while ( $table = $result->fetch_row() ) {
  $name = $table[0]; if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $name ) ) { throw new RuntimeException( 'Invalid table name.' ); }
  $rows = $connection->query( 'SELECT * FROM `' . $name . '`' )->fetch_all( MYSQLI_ASSOC ); $rows = array_map( 'serialize', $rows ); sort( $rows, SORT_STRING ); $data[$name] = hash( 'sha256', serialize( $rows ) );
 }
	ksort( $data, SORT_STRING );
	if ( 'capture-raw' === $action ) {
		// The initial WordPress process has now completed its shutdown callbacks.
		// This raw baseline is captured BEFORE the SQL snapshot or lifecycle changes.
		$state['tables'] = $data;
		if ( false === file_put_contents( sys_get_temp_dir() . '/certloom-wordpress-lifecycle-state-20260929.json', json_encode( $state, JSON_THROW_ON_ERROR ), LOCK_EX ) ) { throw new RuntimeException( 'Cannot save raw baseline.' ); }
		echo 'PASS  pre-snapshot raw baseline for ' . count( $data ) . " tables, after WordPress shutdown\n"; exit;
	}
 if ( $data !== $state['tables'] ) {
  $different = array(); foreach ( array_unique( array_merge( array_keys( $data ), array_keys( $state['tables'] ) ) ) as $name ) { if ( ( $data[$name] ?? null ) !== ( $state['tables'][$name] ?? null ) ) { $different[] = $name; } }
  throw new RuntimeException( 'Raw restore mismatch: ' . implode( ', ', $different ) );
 }
 echo 'PASS  all ' . count( $data ) . " Local table row hashes restored exactly before WordPress boot\n"; exit;
}
define( 'DISABLE_WP_CRON', true );
$GLOBALS['wp_filter']['enable_maintenance_mode'][1][] = array( 'accepted_args' => 1, 'function' => static function () { return false; } );
require $root . '/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( wp_get_environment_type() !== 'local' || home_url() !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 || is_multisite() ) { throw new RuntimeException( 'Refusing another site.' ); }
function lifecycle_assert( $ok, $label ) { if ( ! $ok ) { throw new RuntimeException( $label ); } echo 'PASS  ' . $label . "\n"; }
function lifecycle_hashes() {
 global $wpdb; $tables = $wpdb->get_col( 'SHOW TABLES' ); lifecycle_assert( is_array( $tables ) && '' === $wpdb->last_error, 'Read exact Local table inventory' ); $data = array();
 foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ); lifecycle_assert( is_array( $rows ) && '' === $wpdb->last_error, 'Read ' . $table ); $rows = array_map( 'serialize', $rows ); sort( $rows, SORT_STRING ); $data[$table] = hash( 'sha256', serialize( $rows ) ); }
 ksort( $data, SORT_STRING ); return $data;
}
function lifecycle_design_options() {
 global $wpdb; $keys = array( 'certverify_settings', 'certverify_fields', 'certverify_retired_fields', 'certloom_custom_font_library_v1', 'certloom_email_template_catalog_v1', 'certloom_email_template_issued_v1', 'certloom_email_design_assignments_v1' ); $out = array();
 foreach ( $keys as $key ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name=%s', $wpdb->options, $key ), ARRAY_A ); lifecycle_assert( is_array( $rows ) && '' === $wpdb->last_error, 'Read retained ' . $key ); $out[$key] = hash( 'sha256', serialize( $rows ) ); }
 return $out;
}
$action = $argv[1] ?? 'inspect'; $plugin = 'certloom/certloom.php'; $state_path = sys_get_temp_dir() . '/certloom-wordpress-lifecycle-state-20260929.json';
if ( 'capture' === $action ) {
 lifecycle_assert( ! file_exists( $state_path ) && is_plugin_active( $plugin ), 'Active plugin and no previous lifecycle fixture' );
 lifecycle_assert( empty( get_option( 'certverify_settings', array() )['delete_data_on_uninstall'] ), 'Keep-data choice already selected' );
 lifecycle_assert( ! get_option( 'certloom_domain_restore_journal', false ) && ! get_option( 'certverify_restore_journal', false ) && ! get_option( 'certloom_domain_restore_build', false ) && ! get_option( 'certloom_domain_maintenance_lock', false ), 'No outstanding recovery ownership' );
 $tables = lifecycle_hashes(); $durable = array();
 foreach ( array( 'issuers','programs','recipients','signers','batches','batch_recipients','signer_assignments','templates','template_revisions','template_assignments','credential_series','credential_numbers','credential_versions','credential_events' ) as $type ) { $table = $wpdb->prefix . 'certloom_' . $type; lifecycle_assert( isset( $tables[$table] ), 'Durable ' . $type . ' exists' ); $durable[$table] = $tables[$table]; }
 $legacy = $wpdb->prefix . 'certverify_certificates'; lifecycle_assert( isset( $tables[$legacy] ), 'Legacy registry exists' ); $durable[$legacy] = $tables[$legacy];
 $state = array( 'tables' => $tables, 'durable' => $durable, 'options' => lifecycle_design_options(), 'active_plugins' => get_option( 'active_plugins', array() ) );
 lifecycle_assert( false !== file_put_contents( $state_path, wp_json_encode( $state, JSON_THROW_ON_ERROR ), LOCK_EX ), 'Save hashes only, with no account/provider secrets' ); @chmod( $state_path, 0600 ); exit;
}
lifecycle_assert( is_file( $state_path ) && ! is_link( $state_path ), 'Owned capture receipt required' ); $state = json_decode( file_get_contents( $state_path ), true, 512, JSON_THROW_ON_ERROR );
if ( 'deactivate' === $action ) { deactivate_plugins( $plugin ); lifecycle_assert( ! is_plugin_active( $plugin ), 'Core WordPress deactivation completed' ); }
elseif ( 'uninstall-keep' === $action ) {
 lifecycle_assert( ! is_plugin_active( $plugin ) && empty( get_option( 'certverify_settings', array() )['delete_data_on_uninstall'] ), 'Inactive keep-data state required' );
 lifecycle_assert( is_file( WP_PLUGIN_DIR . '/' . $plugin ), 'Existing source junction plugin present' ); uninstall_plugin( $plugin );
 lifecycle_assert( is_file( WP_PLUGIN_DIR . '/' . $plugin ), 'Core uninstall hook did not remove source/junction files' );
 lifecycle_assert( $state['options'] === lifecycle_design_options(), 'Saved credential configuration and email/font designs retained' );
}
elseif ( 'activate' === $action ) { $activated = activate_plugin( $plugin, '', false, false ); lifecycle_assert( ! is_wp_error( $activated ) && is_plugin_active( $plugin ), 'Core WordPress reactivation completed' ); }
elseif ( 'verify' === $action || 'verify-restored' === $action ) {
 lifecycle_assert( is_plugin_active( $plugin ) && $state['active_plugins'] === get_option( 'active_plugins', array() ), 'Original plugin activation inventory restored' );
 lifecycle_assert( $state['options'] === lifecycle_design_options(), 'Retained design/settings values match exactly' );
 $tables = lifecycle_hashes(); foreach ( $state['durable'] as $table => $hash ) { lifecycle_assert( isset( $tables[$table] ) && $tables[$table] === $hash, 'Exact retained rows in ' . $table ); }
 lifecycle_assert( current_user_can( 'manage_options' ) || get_role( 'administrator' )->has_cap( 'certloom_manage_credentials' ), 'Administrator capabilities provisioned' );
 $hooks = array(); foreach ( _get_cron_array() as $events ) { foreach ( $events as $hook => $jobs ) { if ( strpos( $hook, 'certloom' ) !== false || strpos( $hook, 'certverify' ) !== false ) { $hooks[$hook] = ( $hooks[$hook] ?? 0 ) + count( $jobs ); } } }
 lifecycle_assert( ( $hooks['certloom_job_watchdog'] ?? 0 ) === 1 && ( $hooks['certloom_verification_analytics_prune'] ?? 0 ) === 1, 'One watchdog and one analytics retention event' );
 lifecycle_assert( empty( ( new \Certloom\Infrastructure\Rendering\ProductionRendererRuntime() )->status( 'pdf' )['accepted'] ), 'Downloads remain release-authority gated' );
 if ( 'verify-restored' === $action ) {
  // Timed WordPress bootstrap tasks may update options after the exact raw recovery check.
  unset( $tables[$wpdb->options], $state['tables'][$wpdb->options] );
  lifecycle_assert( $tables === $state['tables'], 'All non-option database row hashes match after WordPress boot' );
 }
}
elseif ( 'inactive' === $action ) {
 lifecycle_assert( ! is_plugin_active( $plugin ), 'Plugin inactive in a fresh WordPress request' );
 foreach ( _get_cron_array() as $events ) { foreach ( array_keys( $events ) as $hook ) { lifecycle_assert( strpos( $hook, 'certloom' ) === false && strpos( $hook, 'certverify' ) === false, 'No Certloom cron remains: ' . $hook ); } }
}
elseif ( 'cleanup' === $action ) { lifecycle_assert( is_plugin_active( $plugin ) && unlink( $state_path ), 'Remove only owned hash receipt with plugin active' ); }
else { throw new RuntimeException( 'Unknown lifecycle action.' ); }
