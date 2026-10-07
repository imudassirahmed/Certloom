<?php
/** Native integration indexes after reviewed course/product remapping. No provider transport. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( ! $root || realpath( $root ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php'; require __DIR__ . '/isolated-local-sandbox.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX );
use Certloom\Application\Backup\WholeStateBackupBuilder;
use Certloom\Application\Backup\WholeStateOptionValue;
use Certloom\Application\Restore\WholeStateAccountMappingProof;
use Certloom\Application\Restore\WholeStateObjectMappingProof;
use Certloom\Application\Restore\WholeStateRestoreReview;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
function im_ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; }
$checks = array(); $failure = null; $cleanup = null; $sandbox = null; $directory = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
try {
	$directory = sys_get_temp_dir() . '/certloom-isolated-' . bin2hex( random_bytes( 8 ) ); $check( mkdir( $directory, 0700 ), 'exact private mapping fixture created' );
	$job_directory = $directory . '/jobs'; $private_filter = static function () use ( $job_directory ) { return $job_directory; }; add_filter( 'certloom_private_job_directory', $private_filter, PHP_INT_MAX );
	$native_plugins = get_option( 'active_plugins', array() ); $check( is_array( $native_plugins ), 'current installed plugin inventory is readable' );
	$sandbox = new CertloomIsolatedLocalSandbox( $directory . '/journal.json' ); $sandbox->create(); $sandbox->enter(); update_option( 'active_plugins', $native_plugins, false );
	$actor = im_ok( wp_insert_user( array( 'user_login' => 'qa-indexed-owner', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) ) ); wp_set_current_user( $actor );
	im_ok( ( new \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository() )->ensure( $actor ) );
	$program = im_ok( ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->create( array( 'title' => 'QA Indexed recovery', 'code' => 'QA-INDEXED' ) ) );
	$stores = array( 'courses' => new \Certloom\Infrastructure\Integration\TutorLmsMappingStore(), 'product' => new \Certloom\Infrastructure\Integration\WooCommerceMappingStore(), 'sfwd-courses' => new \Certloom\Infrastructure\Integration\LearnDashMappingStore() );
	$source = array(); $targets = array(); $mapping = array();
	foreach ( $stores as $type => $store ) {
		// This is a WordPress reference fixture, not installed LearnDash acceptance.
		if ( ! post_type_exists( $type ) ) { im_ok( register_post_type( $type, array( 'public' => false ) ) ); }
		$source[$type] = im_ok( wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'QA Source ' . $type ), true ) );
		$targets[$type] = im_ok( wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'QA Target ' . $type ), true ) ); $mapping[$source[$type]] = $targets[$type];
		$column = 'product' === $type ? 'product_id' : 'course_id'; im_ok( $store->save( array( $column => $source[$type], 'program_id' => $program, 'active' => true ) ) );
		$native = get_option( $store::OPTION ); $check( isset( $native['mappings'][$source[$type]] ) && $store->validate_recovery_value( $native ) === $native, $type . ': actual native writer retains its source index' );
	}
	$second_course = im_ok( wp_insert_post( array( 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'QA Second source course' ), true ) );
	$second_target = im_ok( wp_insert_post( array( 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'QA Second target course' ), true ) );
	im_ok( $stores['courses']->save( array( 'course_id' => $second_course, 'program_id' => $program, 'active' => true ) ) ); $mapping[$second_course] = $second_target;
	$builder = new WholeStateBackupBuilder(); $whole = im_ok( $builder->start( bin2hex( random_bytes( 16 ) ) ) );
	for ( $slice = 0; $slice < 500 && ! $whole['complete']; ++$slice ) { $whole = im_ok( $builder->continue_build( $whole ) ); }
	$check( $whole['complete'], 'real complete source archive captures three native integration stores' );
	$accounts = array(); foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id,wp_user_id FROM %i WHERE wp_user_id IS NOT NULL', \Certloom\Infrastructure\Database\SchemaManager::table( 'recipients' ) ), ARRAY_A ) as $row ) { $accounts[(int) $row['id']] = (int) $row['wp_user_id']; }
	$review = static function ( array $choices ) use ( $whole, $actor, $accounts ) {
		$account_proof = im_ok( WholeStateAccountMappingProof::review( $whole['archive_path'], $whole['archive_sha256'], $actor, $accounts, 'reviewed_target' ) );
		$object_proof = WholeStateObjectMappingProof::review( $whole['archive_path'], $whole['archive_sha256'], $actor, $choices, 'reviewed_target' ); if ( is_wp_error( $object_proof ) ) { return $object_proof; }
		return WholeStateRestoreReview::review( $whole['archive_path'], $whole['archive_sha256'], $account_proof, $object_proof );
	};
	$mapped = im_ok( $review( $mapping ) ); $check( true === $mapped->assert_current(), 'typed target review proves current account and object choices' ); $codec = new WholeStateOptionValue();
	$collision = $mapping; $collision[$second_course] = $targets['courses']; $refused = $review( $collision );
	$check( is_wp_error( $refused ) && 'certloom_whole_object_review_invalid' === $refused->get_error_code(), 'typed ownership review prevents two source course mappings from collapsing into a shared target' );
	foreach ( $stores as $type => $store ) {
		$projected = im_ok( $codec->decode( $mapped->proposal()['fixed'][$store::OPTION] ) )['value']; $column = 'product' === $type ? 'product_id' : 'course_id';
		$expected_targets = 'courses' === $type ? array( $targets[$type], $second_target ) : array( $targets[$type] );
		$check( array_keys( $projected['mappings'] ) === $expected_targets && $projected['mappings'][$targets[$type]][$column] === $targets[$type] && false === $projected['mappings'][$targets[$type]]['active'], $type . ': reviewed projection rekeys to the target identity and pauses execution' );
		$check( $store->validate_recovery_value( $projected ) === $projected, $type . ': projected native storage remains valid without reader repair' );
		$bad = $mapping; $bad[$source[$type]] = null; $disconnected = im_ok( $review( $bad ) ); $empty = im_ok( $codec->decode( $disconnected->proposal()['fixed'][$store::OPTION] ) )['value'];
		$check( array_keys( $empty['mappings'] ) === ( 'courses' === $type ? array( $second_target ) : array() ), $type . ': explicit disconnection removes only its mapping' );
		$native = get_option( $store::OPTION ); $expected_source = 'courses' === $type ? array( $source[$type], $second_course ) : array( $source[$type] ); $check( array_keys( $native['mappings'] ) === $expected_source && true === $native['mappings'][$source[$type]]['active'], $type . ': target review never mutates source settings' );
	}
} catch ( Throwable $error ) { $failure = $error->getMessage() . ' (' . basename( $error->getFile() ) . ':' . $error->getLine() . ')'; }
finally {
	if ( $sandbox ) { try {
		$cleanup = $sandbox->cleanup();
		if ( is_dir( $job_directory ) ) { $files = new PrivateJobFileStore(); foreach ( new DirectoryIterator( $job_directory ) as $file ) {
			if ( $file->isDot() ) { continue; } $path = $file->getPathname();
			if ( in_array( $file->getFilename(), array( 'index.php', '.htaccess', 'web.config' ), true ) && $file->isFile() && ! $file->isLink() ) { if ( ! unlink( $path ) ) { throw new RuntimeException( 'Owned sentinel cleanup failed.' ); } continue; }
			if ( ! $files->owns( $path ) || ! $file->isFile() || $file->isLink() ) { throw new RuntimeException( 'Unexpected mapping fixture file retained.' ); } im_ok( $files->delete( $path ) );
		} if ( ! rmdir( $job_directory ) ) { throw new RuntimeException( 'Owned job directory cleanup failed.' ); } }
		if ( ! rmdir( $directory ) ) { throw new RuntimeException( 'Owned mapping directory cleanup failed.' ); } $directory = null;
	} catch ( Throwable $error ) { $failure = ( $failure ? $failure . '; ' : '' ) . $error->getMessage(); $sandbox->leave(); } }
	if ( isset( $private_filter ) ) { remove_filter( 'certloom_private_job_directory', $private_filter, PHP_INT_MAX ); }
}
echo wp_json_encode( array( 'success' => null === $failure && is_array( $cleanup ), 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'cleanup' => $cleanup, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure && is_array( $cleanup ) ? 0 : 1 );
