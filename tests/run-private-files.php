<?php
/** Pure filesystem tests for private randomized job files. */
$root = sys_get_temp_dir() . '/certloom-private-test-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $root . '/web/' );
define( 'WP_CONTENT_DIR', $root . '/web/wp-content' );
define( 'HOUR_IN_SECONDS', 3600 );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $code; public function __construct( $code = '' ) { $this->code = $code; } public function get_error_code(){ return $this->code; } } }
function is_wp_error( $v ){ return $v instanceof WP_Error; }
function __( $t ){ return $t; }
function absint( $v ){ return abs( (int) $v ); }
function sanitize_key( $key ){ return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function apply_filters( $hook, $value ){ global $root; return 'certloom_private_job_directory' === $hook ? $root . '/private' : $value; }
function wp_normalize_path( $path ){ return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ){ return rtrim( (string) $path, '/\\' ) . '/'; }
function untrailingslashit( $path ){ return rtrim( (string) $path, '/\\' ); }
function wp_mkdir_p( $path ){ return is_dir( $path ) || mkdir( $path, 0700, true ); }

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Files\PrivateJobFileStore;
function pf_same( $e, $a, $m='' ){ if($e!==$a){ throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true)); } }

$store = new PrivateJobFileStore();
$dir = $store->ensure_directory();
pf_same( false, is_wp_error($dir), 'directory created' );
pf_same( true, is_file($dir . '/index.php'), 'index protection' );
pf_same( true, is_file($dir . '/.htaccess'), 'apache protection' );
pf_same( true, is_file($dir . '/web.config'), 'iis protection' );
echo "PASS  private directory creates deny/sentinel files\n";

$source = $root . '/source.csv';
file_put_contents( $source, "number,name\nC-1,Alice\n" );
$key = str_repeat('a',32);
$stored = $store->store_copy( $key, $source, 'import' );
pf_same( false, is_wp_error($stored), 'stored copy' );
pf_same( true, $store->owns($stored), 'owned path' );
pf_same( file_get_contents($source), file_get_contents($stored), 'bytes preserved' );
pf_same( '.data', substr($stored,-5), 'opaque extension' );
echo "PASS  job files use randomized opaque private names\n";

$outside = $root . '/outside.data';
file_put_contents($outside,'x');
$rejected = $store->delete($outside);
pf_same( 'certloom_private_file_path_invalid', $rejected->get_error_code(), 'outside deletion rejected' );
pf_same( true, $store->delete($stored), 'owned delete' );
echo "PASS  deletion is constrained to owned private files\n";

$old = $store->create_path( str_repeat('b',32), 'backup' );
file_put_contents( $old, 'old' );
touch( $old, time() - 3 * HOUR_IN_SECONDS );
$fresh = $store->create_path( str_repeat('c',32), 'backup' );
file_put_contents( $fresh, 'fresh' );
$cleaned = $store->cleanup_older_than( 2 * HOUR_IN_SECONDS, 10 );
pf_same( 1, $cleaned, 'one expired data file removed' );
pf_same( false, file_exists($old), 'old removed' );
pf_same( true, file_exists($fresh), 'fresh retained' );
file_put_contents( $dir . '/do-not-touch.txt', 'sentinel' );
touch( $dir . '/do-not-touch.txt', time() - 5 * HOUR_IN_SECONDS );
$store->cleanup_older_than( 2 * HOUR_IN_SECONDS, 10 );
pf_same( true, file_exists($dir . '/do-not-touch.txt'), 'unknown file never removed' );
echo "PASS  bounded retention cleanup deletes only expired Certloom job-data filenames\n";

$map_old = $store->create_path( str_repeat( 'e', 32 ), 'import-map' );
file_put_contents( $map_old, 'mapping' );
touch( $map_old, time() - 2 * HOUR_IN_SECONDS );
$backup_old = $store->create_path( str_repeat( 'f', 32 ), 'backup' );
file_put_contents( $backup_old, 'backup' );
touch( $backup_old, time() - 2 * HOUR_IN_SECONDS );
$map_cleaned = $store->cleanup_purpose_older_than( 'import-map', HOUR_IN_SECONDS, 10 );
pf_same( 1, $map_cleaned, 'one expired mapping file removed' );
pf_same( false, file_exists( $map_old ), 'expired mapping removed' );
pf_same( true, file_exists( $backup_old ), 'unrelated backup retained' );
$store->delete( $backup_old );
echo "PASS  short-lived mapping cleanup cannot delete unrelated retained backup files\n";

function pf_rm($path){ if(!file_exists($path))return; if(is_dir($path)){ foreach(scandir($path) as $n){ if($n==='.'||$n==='..')continue; pf_rm($path.'/'.$n);} rmdir($path);} else unlink($path); }
pf_rm($root);
echo "\n5 tests, 0 failures\n";
