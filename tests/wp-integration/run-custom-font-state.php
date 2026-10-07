<?php
/** Owned Local font-upload fixtures; never exposes account data or accepts HTTP. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || home_url() !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }
use Certloom\Application\Rendering\CustomFontLibrary;
use Certloom\Infrastructure\Data\DatabaseOptions;
function font_state_ok( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function font_state_digest() {
 global $wpdb; $data = array();
 $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'certloom_' ) . '%' ) );
 $tables[] = Ecapla_CertVerify_Database::table_name();
 foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ); font_state_ok( is_array( $rows ) && '' === $wpdb->last_error, 'Cannot read preservation data.' ); $rows = array_map( 'serialize', $rows ); sort( $rows, SORT_STRING ); $data[$table] = $rows; }
 return hash( 'sha256', serialize( $data ) );
}
$path = sys_get_temp_dir() . '/certloom-font-live-state-20260929.json';
$uploads = wp_upload_dir(); $dir = $uploads['basedir'] . '/' . CustomFontLibrary::SUBDIR;
$label = 'Certloom QA font 20260929';
$source = CERTLOOM_PATH . 'resources/renderer/fonts/57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e.ttf';
$dest = $dir . '/' . basename( $source );
$action = $argv[1] ?? 'inspect';
if ( 'begin' === $action ) {
 font_state_ok( ! file_exists( $path ), 'A previous owned font test needs cleanup first.' );
 $saved = DatabaseOptions::read( array( CustomFontLibrary::OPTION ) ); font_state_ok( ! is_wp_error( $saved ), 'Cannot save original library.' );
 $families = ( new CustomFontLibrary() )->families();
 foreach ( $families as $family ) { font_state_ok( strpos( $family['label'], $label ) !== 0, 'QA family already exists.' ); }
 $state = array( 'option' => $saved, 'digest' => font_state_digest(), 'dir_exists' => is_dir( $dir ), 'files' => is_dir( $dir ) ? array_map( 'basename', glob( $dir . '/*' ) ) : array() );
 font_state_ok( false !== file_put_contents( $path, wp_json_encode( $state, JSON_THROW_ON_ERROR ), LOCK_EX ), 'Cannot preserve test state.' ); @chmod( $path, 0600 );
 echo "PASS  saved font index, file inventory and all credential-table hashes\n"; exit;
}
font_state_ok( is_file( $path ) && ! is_link( $path ), 'Owned begin receipt required.' );
$state = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
if ( 'corrupt' === $action || 'repair' === $action ) {
 font_state_ok( ! in_array( basename( $dest ), $state['files'], true ) && is_file( $dest ) && ! is_link( $dest ) && dirname( realpath( $dest ) ) === realpath( $dir ), 'Refusing a pre-existing or foreign font file.' );
 font_state_ok( hash_file( 'sha256', $dest ) === hash_file( 'sha256', $source ) || file_get_contents( $dest ) === 'Certloom QA corrupted bytes', 'Unexpected fixture content.' );
 font_state_ok( false !== file_put_contents( $dest, 'corrupt' === $action ? 'Certloom QA corrupted bytes' : file_get_contents( $source ), LOCK_EX ), 'Cannot mutate owned font fixture.' );
 echo 'PASS  ' . $action . " owned content-addressed fixture only\n"; exit;
}
if ( 'verify' === $action ) {
 font_state_ok( $state['digest'] === font_state_digest(), 'Font requests changed credential data.' );
 $family = ( new CustomFontLibrary() )->families()['custom-' . substr( hash( 'sha256', strtolower( $label ) ), 0, 16 )] ?? null;
 font_state_ok( is_array( $family ) && 1 === count( $family['faces'] ) && $family['faces']['400-normal']['sha256'] === hash_file( 'sha256', $source ), 'Expected exact regular face only.' );
 font_state_ok( hash_file( 'sha256', $dest ) === hash_file( 'sha256', $source ), 'Font bytes were not restored.' );
 echo "PASS  exact uploaded face and credential-table preservation\n"; exit;
}
if ( 'cleanup' === $action ) {
 $current = DatabaseOptions::read( array( CustomFontLibrary::OPTION ) ); font_state_ok( ! is_wp_error( $current ), 'Cannot read current library.' );
 $value = $current[CustomFontLibrary::OPTION]['value'] ?? array(); font_state_ok( is_array( $value ), 'Refusing a corrupt library.' );
 foreach ( $value as $key => $family ) { if ( strpos( $family['label'] ?? '', $label ) === 0 ) { unset( $value[$key] ); } }
 $original = $state['option'][CustomFontLibrary::OPTION];
 font_state_ok( $value === ( $original['exists'] ? $original['value'] : array() ), 'Another writer changed the font library; cleanup needs review.' );
 DatabaseOptions::invalidate( array( CustomFontLibrary::OPTION ) );
 if ( $original['exists'] ) { update_option( CustomFontLibrary::OPTION, $original['value'], false ); } else { delete_option( CustomFontLibrary::OPTION ); }
 font_state_ok( DatabaseOptions::read( array( CustomFontLibrary::OPTION ) ) === $state['option'], 'Original index was not restored.' );
 foreach ( is_dir( $dir ) ? glob( $dir . '/*' ) : array() as $file ) {
  if ( in_array( basename( $file ), $state['files'], true ) ) { continue; }
  font_state_ok( dirname( realpath( $file ) ) === realpath( $dir ) && ! is_link( $file ) && preg_match( '/^[a-f0-9]{64}\.(ttf|otf)$/', basename( $file ) ), 'Unsafe cleanup path.' );
  font_state_ok( in_array( hash_file( 'sha256', $file ), array( '57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e', 'a4c5bc453ca281d90ea079e596da7ae0dfeb5777497c29ec254e76d97ff6f890', hash( 'sha256', 'Certloom QA corrupted bytes' ) ), true ), 'Refusing an unknown new file.' );
  font_state_ok( unlink( $file ), 'Cannot remove owned fixture.' );
 }
 if ( ! $state['dir_exists'] && is_dir( $dir ) ) { font_state_ok( rmdir( $dir ), 'Owned directory is not empty.' ); }
 font_state_ok( $state['digest'] === font_state_digest(), 'Credential preservation failed.' ); font_state_ok( unlink( $path ), 'Cannot remove owned receipt.' );
 echo "PASS  original index/files and all credential-table hashes restored\n"; exit;
}
throw new RuntimeException( 'Unknown action.' );
