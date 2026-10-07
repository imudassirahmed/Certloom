<?php
/** Actual font ZIP capture/publication/REST bytes, entirely disposable SQL and files. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( ! $root || realpath( $root ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { exit( 1 ); }
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); } if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); } require $root . '/wp-load.php'; require __DIR__ . '/isolated-local-sandbox.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX );
use Certloom\Application\Rendering\CustomFontLibrary;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
function wf_ok( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( $v->get_error_code() ); } return $v; }
$checks = array(); $failure = null; $sandbox = null; $directory = null; $cleanup = null; $owned_files = array();
$check = static function ( $v, $label ) use ( &$checks ) { if ( ! $v ) { throw new RuntimeException( $label ); } $checks[] = $label; };
try {
 $directory = sys_get_temp_dir() . '/certloom-isolated-' . bin2hex( random_bytes( 8 ) ); if ( ! mkdir( $directory, 0700 ) ) { throw new RuntimeException( 'Private fixture directory unavailable.' ); }
 $sandbox = new CertloomIsolatedLocalSandbox( $directory . '/journal.json' ); $sandbox->create(); $sandbox->enter();
 $actor = wf_ok( wp_insert_user( array( 'user_login' => 'qa-font-recovery-owner', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) ) ); wp_set_current_user( $actor );
 $uploads = $directory . '/uploads'; $private = $directory . '/private'; $upload_filter = static function ( $value ) use ( $uploads ) { $value['basedir'] = $uploads; $value['path'] = $uploads; $value['baseurl'] = 'http://example.invalid/qa-fonts'; $value['url'] = $value['baseurl']; $value['error'] = false; return $value; }; $private_filter = static function () use ( $private ) { return $private; };
 add_filter( 'upload_dir', $upload_filter, PHP_INT_MAX ); wp_upload_dir( null, false, true ); add_filter( 'certloom_private_job_directory', $private_filter, PHP_INT_MAX );
 if ( ! mkdir( $uploads, 0700 ) || ! mkdir( $uploads . '/certloom-fonts', 0700 ) ) { throw new RuntimeException( 'Owned upload fixture unavailable.' ); }
 $font_sha = '57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e'; $source = CERTLOOM_PATH . 'resources/renderer/fonts/' . $font_sha . '.ttf'; $font_path = $uploads . '/certloom-fonts/' . $font_sha . '.ttf';
 $check( is_file( $source ) && hash_file( 'sha256', $source ) === $font_sha && copy( $source, $font_path ), 'exact bundled QA font copied without touching ordinary uploads' );
 $face = array( 'sha256' => $font_sha, 'format' => 'ttf', 'file' => $font_sha . '.ttf' ); $families = array( 'custom-aabbccddeeff0011' => array( 'label' => 'QA Recovered Font', 'faces' => array( '400-normal' => $face, '900-italic' => $face ) ) );
 wf_ok( ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->set( CustomFontLibrary::OPTION, $families ) ); wf_ok( ( new \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository() )->ensure( $actor ) );
 $assets = new \Certloom\Infrastructure\Backup\WholeStateAssetRepository(); $descriptor = wf_ok( $assets->font( $font_sha, 'ttf' ) );
 $check( realpath( $descriptor['path'] ) === realpath( $font_path ) && $descriptor['sha256'] === $font_sha, 'current custom font independently passes actual SFNT/hash inspection' );
 $key = bin2hex( random_bytes( 16 ) ); $builder = new \Certloom\Application\Backup\WholeStateBackupBuilder(); $state = wf_ok( $builder->start( $key ) );
 for ( $i = 0; $i < 2000 && ! $state['complete']; ++$i ) { $state = wf_ok( $builder->continue_build( $state ) ); }
 $check( $state['complete'], 'whole-state worker captures actual custom font bytes through every component' );
 $archive = wf_ok( ( new \Certloom\Application\Backup\WholeStateBackupReader() )->analyze( $state['archive_path'] ) );
 $check( count( $archive['manifest']['members'] ) === 7 && $archive['manifest']['members']['assets/' . $font_sha]['bytes'] === filesize( $source ), 'multiple indexed faces deduplicate to one exact font asset ZIP member' );
 $zip = new ZipArchive(); $check( true === $zip->open( $state['archive_path'], ZipArchive::RDONLY ), 'actual font recovery ZIP opens read-only' ); $bytes = $zip->getFromName( 'assets/' . $font_sha ); $zip->close();
 $check( is_string( $bytes ) && hash( 'sha256', $bytes ) === $font_sha, 'captured font member contains the complete original bytes' );
 $owner = bin2hex( random_bytes( 16 ) ); $maintenance = new \Certloom\Infrastructure\Data\DomainMaintenanceLock(); $publication = new \Certloom\Infrastructure\Restore\WholeStateAssetPublication(); wf_ok( $maintenance->acquire( $owner, 'whole_font_recovery', 120 ) ); wf_ok( $publication->start( $state['archive_path'], $state['archive_sha256'], $owner ) );
 $check( unlink( $font_path ), 'owned original font removed to exercise a genuine missing-upload restore' );
 $published = wf_ok( $publication->continue_publication( $owner ) ); $check( 'published' === $published['phase'], 'actual exclusive publication finishes one recovered font' );
 $recovered = wf_ok( $assets->font( $font_sha, 'ttf' ) ); $check( $recovered['sha256'] === $descriptor['sha256'] && $recovered['size'] === $descriptor['size'] && $recovered['path'] !== $descriptor['path'], 'missing upload resolves to the exact recovered private font rather than a replacement' );
 $definitions = ( new CustomFontLibrary() )->designer_definitions(); $check( strpos( $definitions['custom-aabbccddeeff0011']['faces'][0]['url'], 'certloom/v1/design-fonts/' . $font_sha . '.ttf' ) !== false, 'native designer font URL uses the exact-byte REST route after recovery' );
 $request = new WP_REST_Request( 'GET', '/certloom/v1/design-fonts/' . $font_sha . '.ttf' ); $request->set_url_params( array( 'sha256' => $font_sha, 'format' => 'ttf' ) ); $rest = new \Certloom\Infrastructure\Rendering\CustomFontRest(); $response = wf_ok( $rest->read( $request ) );
 $check( null === $response->get_data() && strpos( wp_json_encode( $response->get_headers() ), $directory ) === false, 'REST response metadata contains no private font path or host filesystem data' );
 ob_start(); $served = $rest->serve( false, $response, $request, null ); $served_bytes = ob_get_clean();
 $check( $served && hash( 'sha256', $served_bytes ) === $font_sha && $served_bytes === $bytes, 'actual REST binary serving emits the exact recovered font bytes' );
 $head = new WP_REST_Request( 'HEAD', $request->get_route() ); ob_start(); $served = $rest->serve( false, $response, $head, null ); $head_bytes = ob_get_clean(); $check( $served && '' === $head_bytes, 'font HEAD serving emits no body' );
 $unindexed = new WP_REST_Request( 'GET', '/certloom/v1/design-fonts/' . str_repeat( '0', 64 ) . '.ttf' ); $unindexed->set_url_params( array( 'sha256' => str_repeat( '0', 64 ), 'format' => 'ttf' ) ); $check( is_wp_error( $rest->read( $unindexed ) ), 'private assets absent from the current font catalog are not publicly addressable' );
 $fallback = $recovered['path']; $check( strlen( 'changed' ) === file_put_contents( $fallback, 'changed', LOCK_EX ), 'owned recovered font corruption staged' );
 $check( is_wp_error( $rest->read( $request ) ), 'changed recovered font bytes fail public delivery closed' );
 ob_start(); $rest->serve( false, $response, $request, null ); $failed_body = ob_get_clean(); $check( strpos( $failed_body, 'certloom_design_font_unavailable' ) !== false && strpos( $failed_body, $directory ) === false, 'font changed after response preparation cannot emit corrupt bytes or a private path' );
 $check( is_wp_error( $publication->rollback( $owner ) ) && is_file( $fallback ), 'rollback preserves a changed recovered font for explicit repair' );
 $check( strlen( $bytes ) === file_put_contents( $fallback, $bytes, LOCK_EX ), 'owned font repaired with the exact archived bytes' );
 wf_ok( $publication->rollback( $owner ) ); wf_ok( $maintenance->release( $owner ) ); $check( ! is_file( $fallback ) && null === $publication->pending_owner(), 'actual owned font rollback clears publication bytes and journal safely' );
} catch ( Throwable $e ) { $failure = get_class( $e ) . ': ' . $e->getMessage(); }
finally {
 $preserve_asset_fixture = false;
 if ( isset( $publication, $owner ) ) { try { if ( $publication->pending_owner() ) { if ( isset( $fallback, $bytes ) && is_file( $fallback ) && hash_file( 'sha256', $fallback ) !== $font_sha ) { file_put_contents( $fallback, $bytes, LOCK_EX ); } wf_ok( $publication->rollback( $owner ) ); } if ( $maintenance->owned_by( $owner ) ) { wf_ok( $maintenance->release( $owner ) ); } } catch ( Throwable $e ) { $preserve_asset_fixture = true; $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); } }
 if ( ! $preserve_asset_fixture && isset( $key, $private ) && is_dir( $private ) ) { try { $files = new PrivateJobFileStore(); foreach ( new DirectoryIterator( $private ) as $file ) { $path = $file->getPathname(); if ( strpos( $file->getFilename(), $key . '-' ) === 0 && $files->owns( $path ) ) { wf_ok( $files->delete( $path ) ); } } if ( is_dir( $private . '/recovery-assets' ) && ! rmdir( $private . '/recovery-assets' ) ) { throw new RuntimeException( 'Unexpected fallback files retained.' ); } foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $name ) { if ( is_file( $private . '/' . $name ) && ! unlink( $private . '/' . $name ) ) { throw new RuntimeException( 'Owned sentinel cleanup failed.' ); } } if ( ! rmdir( $private ) ) { throw new RuntimeException( 'Private font directory cleanup failed.' ); } } catch ( Throwable $e ) { $preserve_asset_fixture = true; $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); } }
 if ( isset( $upload_filter ) ) { remove_filter( 'upload_dir', $upload_filter, PHP_INT_MAX ); wp_upload_dir( null, false, true ); } if ( isset( $private_filter ) ) { remove_filter( 'certloom_private_job_directory', $private_filter, PHP_INT_MAX ); }
 if ( $sandbox && ! $preserve_asset_fixture ) { try { if ( isset( $font_path ) && is_file( $font_path ) ) { if ( is_link( $font_path ) || hash_file( 'sha256', $font_path ) !== $font_sha || ! unlink( $font_path ) ) { throw new RuntimeException( 'Changed owned upload retained.' ); } } if ( isset( $uploads ) && is_dir( $uploads . '/certloom-fonts' ) ) { if ( ! rmdir( $uploads . '/certloom-fonts' ) || ! rmdir( $uploads ) ) { throw new RuntimeException( 'Owned font upload directory cleanup failed.' ); } } $cleanup = $sandbox->cleanup(); if ( ! rmdir( $directory ) ) { throw new RuntimeException( 'Private fixture directory cleanup failed.' ); } $directory = null; } catch ( Throwable $e ) { $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); } }
 if ( $sandbox && $preserve_asset_fixture ) { $sandbox->leave(); }
}
echo wp_json_encode( array( 'success' => null === $failure && is_array( $cleanup ), 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'cleanup' => $cleanup, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; exit( null === $failure && is_array( $cleanup ) ? 0 : 1 );
