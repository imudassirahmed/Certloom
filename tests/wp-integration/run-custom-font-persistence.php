<?php
namespace Certloom\Application\Rendering {
 // CLI-only provenance seam. Real HTTP upload provenance is tested separately in Lance.
 function is_uploaded_file( $path ) { return isset( $GLOBALS['font_persistence_files'][$path] ) && is_file( $path ); }
 function move_uploaded_file( $source, $destination ) { return is_uploaded_file( $source ) && rename( $source, $destination ); }
}
namespace {
 if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
 $root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
 if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new \RuntimeException( 'Exact Local root and snapshot required.' ); }
 define( 'DISABLE_WP_CRON', true ); require $root . '/wp-load.php';
 if ( wp_get_environment_type() !== 'local' || home_url() !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new \RuntimeException( 'Refusing another site.' ); }
 use Certloom\Application\Rendering\CustomFontLibrary;
 use Certloom\Infrastructure\Data\DatabaseOptions;
 use Certloom\Infrastructure\Data\DomainMaintenanceLock;
 use Certloom\Infrastructure\Database\Transaction;
 $checks = 0;
 function font_persist_check( $ok, $label ) { global $checks; if ( ! $ok ) { throw new \RuntimeException( $label ); } $checks++; echo 'PASS  ' . $label . "\n"; }
 function font_persist_upload( $label ) {
  $source = CERTLOOM_PATH . 'resources/renderer/fonts/57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e.ttf';
  $path = tempnam( sys_get_temp_dir(), 'certloom-font-db-' ); if ( ! $path || ! copy( $source, $path ) ) { throw new \RuntimeException( 'Cannot create owned fixture.' ); }
  $GLOBALS['font_persistence_files'][$path] = true;
  return ( new CustomFontLibrary() )->store_upload( array( 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK ), $label );
 }
 $before = DatabaseOptions::read( array( CustomFontLibrary::OPTION, DomainMaintenanceLock::OPTION ) ); font_persist_check( ! is_wp_error( $before ) && ! $before[DomainMaintenanceLock::OPTION]['exists'], 'No existing maintenance owner' );
 $uploads = wp_upload_dir(); $dir = $uploads['basedir'] . '/' . CustomFontLibrary::SUBDIR;
 $dest = $dir . '/57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e.ttf'; $existed = file_exists( $dest ); $dir_existed = is_dir( $dir );
 $GLOBALS['font_persistence_files'] = array(); $owner = bin2hex( random_bytes( 16 ) ); $locked = false;
 try {
  $result = ( new Transaction() )->run( static function () {
   $success = font_persist_upload( 'Certloom rollback font ' . bin2hex( random_bytes( 6 ) ) ); font_persist_check( ! is_wp_error( $success ), 'Actual SQL persistence accepts an exact face' );
   $snapshot = DatabaseOptions::read( array( CustomFontLibrary::OPTION ) );
   $deny = static function ( $next, $old ) { return $old; }; add_filter( 'pre_update_option_' . CustomFontLibrary::OPTION, $deny, 10, 2 );
   try { $failed = font_persist_upload( 'Certloom denied font ' . bin2hex( random_bytes( 6 ) ) ); }
   finally { remove_filter( 'pre_update_option_' . CustomFontLibrary::OPTION, $deny, 10 ); }
   font_persist_check( is_wp_error( $failed ) && 'certloom_font_store' === $failed->get_error_code(), 'Denied index update cannot report success' );
   font_persist_check( $snapshot === DatabaseOptions::read( array( CustomFontLibrary::OPTION ) ), 'Denied update preserves the earlier exact index' );
   $damaged = $snapshot[CustomFontLibrary::OPTION]['value']; $key = array_key_first( $damaged ); $damaged[$key]['faces']['900-italic'] = array( 'sha256' => array( 'invalid' ), 'format' => 'ttf', 'file' => 'broken.ttf' );
   update_option( CustomFontLibrary::OPTION, $damaged, false ); DatabaseOptions::invalidate( array( CustomFontLibrary::OPTION ) );
   $corrupt_before = DatabaseOptions::read( array( CustomFontLibrary::OPTION ) );
   $blocked = font_persist_upload( 'Certloom damaged index font' );
   font_persist_check( is_wp_error( $blocked ) && 'certloom_font_index_invalid' === $blocked->get_error_code(), 'A partially damaged face index refuses uploads without PHP notices' );
   font_persist_check( $corrupt_before === DatabaseOptions::read( array( CustomFontLibrary::OPTION ) ), 'Damaged metadata remains available for review rather than being silently discarded' );
   update_option( CustomFontLibrary::OPTION, $snapshot[CustomFontLibrary::OPTION]['value'], false ); DatabaseOptions::invalidate( array( CustomFontLibrary::OPTION ) );
   return new \WP_Error( 'font_fixture_rollback', 'Owned fixture rollback.' );
  } );
  font_persist_check( is_wp_error( $result ) && 'font_fixture_rollback' === $result->get_error_code(), 'Outer SQL transaction rolls all index fixtures back' );
  DatabaseOptions::invalidate( array( CustomFontLibrary::OPTION ) );
  font_persist_check( $before === DatabaseOptions::read( array( CustomFontLibrary::OPTION, DomainMaintenanceLock::OPTION ) ), 'Original font index and maintenance state preserved' );
  $lease = ( new DomainMaintenanceLock() )->acquire( $owner, 'font_test', 120 ); font_persist_check( ! is_wp_error( $lease ), 'Acquire owned maintenance lease' ); $locked = true;
  $blocked = font_persist_upload( 'Certloom maintenance blocked font' ); font_persist_check( is_wp_error( $blocked ) && 'certloom_domain_maintenance_write_locked' === $blocked->get_error_code(), 'Real maintenance lease refuses font index writes' );
 } finally {
  if ( $locked ) { font_persist_check( ! is_wp_error( ( new DomainMaintenanceLock() )->release( $owner ) ), 'Release only the owned maintenance lease' ); }
  foreach ( $GLOBALS['font_persistence_files'] as $path => $unused ) { if ( is_file( $path ) ) { font_persist_check( unlink( $path ), 'Remove owned temporary font bytes' ); } }
  if ( ! $existed && is_file( $dest ) ) { font_persist_check( ! is_link( $dest ) && dirname( realpath( $dest ) ) === realpath( $dir ) && hash_file( 'sha256', $dest ) === basename( $dest, '.ttf' ), 'Verify newly owned destination before cleanup' ); font_persist_check( unlink( $dest ), 'Remove only the new destination fixture' ); }
  if ( ! $dir_existed && is_dir( $dir ) ) { font_persist_check( rmdir( $dir ), 'Remove only the empty new font directory' ); }
  DatabaseOptions::invalidate( array( CustomFontLibrary::OPTION ) );
  font_persist_check( $before === DatabaseOptions::read( array( CustomFontLibrary::OPTION, DomainMaintenanceLock::OPTION ) ), 'Original durable index and lock state restored' );
 }
 echo $checks . " persistence checks, 0 failures\n";
}
