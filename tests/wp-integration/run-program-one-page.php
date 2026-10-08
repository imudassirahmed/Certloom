<?php
/** Real Program handlers and batch guards; every fixture is rolled back. No issuance or LMS events. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
$backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( realpath( (string) getenv( 'CERTLOOM_TEST_WP_ROOT' ) ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local site and verified private snapshot required.\n" ); exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); } require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { throw new RuntimeException( 'Refusing another site.' ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled' ); }, PHP_INT_MAX );
use Certloom\Infrastructure\Admin\DomainAdmin;
use Certloom\Infrastructure\Admin\ProgramBatchChanges;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
use Certloom\Application\Program\ProgramProfile;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Templates\TemplateDefaults;
class ProgramProbeRedirect extends RuntimeException { public $url; public function __construct( $url ) { $this->url = $url; parent::__construct( 'Owned probe redirect' ); } }
$checks = array(); $failure = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$ok = static function ( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( $v->get_error_code() ); } return $v; };
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) ); if ( ! $admins ) { throw new RuntimeException( 'Local administrator required.' ); } wp_set_current_user( (int) $admins[0] );
$check( current_user_can( Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) && current_user_can( Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ) && current_user_can( Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES ), 'Existing Local role permits the real commands.' );
$fingerprint = static function () {
	global $wpdb; $out = array();
	$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->options ), array_map( array( SchemaManager::class, 'table' ), DomainBackupRepository::entity_types() ) );
	foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Preservation read failed.' ); } $out[$table] = hash( 'sha256', wp_json_encode( $rows ) ); }
	return $out;
};
$before = $fingerprint();
$redirect = static function ( $url ) { if ( false === strpos( $url, 'page=certloom-domain-programs' ) ) { throw new RuntimeException( 'Unexpected probe redirect.' ); } throw new ProgramProbeRedirect( $url ); };
add_filter( 'wp_redirect', $redirect, PHP_INT_MAX );
$post = static function ( $input, $method ) {
	$_POST = wp_slash( $input ); $_REQUEST = $_POST;
	try { ( new DomainAdmin() )->$method(); throw new RuntimeException( 'Expected a guarded redirect.' ); }
	catch ( ProgramProbeRedirect $redirect ) { parse_str( parse_url( $redirect->url, PHP_URL_QUERY ), $query ); return $query; }
};
$result = ( new Transaction() )->run( static function () use ( $check, $ok, $post, &$failure ) {
	try {
		global $wpdb; $nonce = 'one-page-' . bin2hex( random_bytes( 8 ) );
		$programs = new ProgramRepository(); $batches = new BatchRepository(); $templates = new TemplateRepository();
		$profile = ProgramProfile::defaults(); $profile['validity_mode'] = 'permanent';
		$input = array( 'program_workspace_version' => '1', 'program_form_complete' => '1', 'title' => 'QA ' . $nonce, 'code' => $nonce, 'description' => '', 'active' => '1', 'profile' => $profile, 'program_template_id' => '0', 'program_field_requirement' => array(), '_wpnonce' => wp_create_nonce( 'certloom_domain_create_program' ) );
		$drafts = array(); foreach ( array( 'spring', 'autumn' ) as $name ) { $drafts[] = array( 'id' => 0, 'key' => 'new-' . $name, 'patch' => array( 'name' => $name . ' ' . $nonce, 'code' => $nonce . '-' . $name, 'active' => true ) ); }
		$input['program_batch_changes'] = wp_json_encode( $drafts ); $created = $post( $input, 'create_program' );
		$check( isset( $created['program_created'], $created['program_id'] ) && ! isset( $created['certloom_error'] ), 'Shared add form creates one Program with its staged batches.' );
		$id = (int) $created['program_id']; $program = $ok( $programs->find( $id ) ); $items = $ok( $batches->list_page( '', 1, 25, 'all', $id ) )['items'];
		$check( 2 === count( $items ), 'Both staged batches belong to the newly created Program.' );
		$listed = $ok( $programs->list_page( $nonce, 1, 50 ) );
		$check( 1 === count( $listed['items'] ) && 2 === $listed['items'][0]['batch_count'], 'Paged Program rows include an exact scoped batch count without per-row reads.' );
		$ids = array_map( static function ( $b ) { return (int) $b['id']; }, $items ); sort( $ids );
		$recipient = $ok( ( new RecipientRepository() )->create( array( 'display_name' => 'QA ' . $nonce ) ) );
		foreach ( $ids as $batch_id ) { $ok( $batches->add_recipient( $batch_id, $recipient ) ); }
		$metadata = array( 'structured' => array( 'keep' => 'exactly' ) );
		$ok( $batches->update( $ids[0], array( 'metadata' => $metadata ) ) );
		$design_id = $ok( $templates->create( array( 'name' => 'QA ' . $nonce, 'machine_key' => $nonce, 'layout' => TemplateDefaults::blank_a4_landscape() ) ) );
		$design = $ok( $templates->find( $design_id ) ); $ok( $templates->publish( $design_id, $design['current_revision_id'], $design['current_revision']['layout_sha256'] ) );
		$ok( $templates->assign( $design_id, 'batch', $ids[0] ) );
		$make_update = static function ( $changes, $title = null ) use ( $programs, $templates, $id, $input ) {
			$program = $programs->find( $id ); $assignment = $templates->get_assignment( 'program', $id );
			return array_merge( $input, array( 'program_id' => (string) $id, 'title' => null !== $title ? $title : $program['title'], 'program_revision' => ProgramBatchChanges::revision( $program, $assignment ), 'program_batch_changes' => wp_json_encode( $changes ), '_wpnonce' => wp_create_nonce( 'certloom_domain_update_program_' . $id ) ) );
		};
		$make_change = static function ( $batch_id, $patch ) use ( $batches, $templates ) { return array( 'id' => $batch_id, 'revision' => ProgramBatchChanges::revision( $batches->find( $batch_id ), $templates->get_assignment( 'batch', $batch_id ) ), 'patch' => $patch ); };
		$changes = array(); foreach ( $ids as $batch_id ) { $changes[] = $make_change( $batch_id, array( 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'batch_template_id' => 0, 'active' => false ) ); }
		$saved = $post( $make_update( $changes ), 'update_program' );
		$check( isset( $saved['program_updated'] ) && ! isset( $saved['certloom_error'] ), 'Real edit handler saves the bulk date, design and status patches.' );
		foreach ( $ids as $batch_id ) { $row = $ok( $batches->find( $batch_id ) ); $check( '2026-01-01' === $row['start_date'] && '2026-12-31' === $row['end_date'] && false === $row['active'], 'Bulk patch applies both dates and explicit inactive status.' ); $check( 1 === $ok( $batches->members_page( $batch_id ) )['total'], 'Bulk patch preserves recipient membership.' ); }
		$check( $metadata === $batches->find( $ids[0] )['metadata'] && empty( $batches->find( $ids[0] )['field_contract']['_meta']['configured'] ), 'Real bulk patch preserves structured metadata and an unconfigured field contract.' );
		$cleared_assignment = $templates->get_assignment( 'batch', $ids[0] );
		$check( ! is_array( $cleared_assignment ) || empty( $cleared_assignment['active'] ), 'Inherit design clears the existing batch assignment canonically.' );
		$baseline = array( $programs->find( $id ), $batches->find( $ids[0] ), $batches->find( $ids[1] ) );
		$changes = array( $make_change( $ids[0], array( 'name' => 'Must roll back' ) ), $make_change( $ids[1], array( 'end_date' => '2025-01-01' ) ) );
		$failed = $post( $make_update( $changes, 'Must also roll back' ), 'update_program' );
		$check( isset( $failed['certloom_error'] ) && $baseline === array( $programs->find( $id ), $batches->find( $ids[0] ), $batches->find( $ids[1] ) ), 'A later invalid batch rolls back the Program and every earlier batch patch.' );
		$stale_input = $make_update( array(), 'Stale window' ); $ok( $programs->update( $id, array( 'title' => 'Newer window' ) ) );
		$failed = $post( $stale_input, 'update_program' ); $check( isset( $failed['certloom_error'] ) && 'Newer window' === $programs->find( $id )['title'], 'A stale Program form cannot overwrite a newer edit.' );
		$changes = array( $make_change( $ids[0], array( 'name' => 'Must roll back again' ) ), $make_change( $ids[1], array( 'active' => true ) ) );
		$ok( $batches->update( $ids[1], array( 'name' => 'Newer batch name', 'metadata' => $batches->find( $ids[1] )['metadata'] ) ) );
		$baseline = array( $programs->find( $id ), $batches->find( $ids[0] ), $batches->find( $ids[1] ) );
		$failed = $post( $make_update( $changes, 'Conflict rollback' ), 'update_program' );
		$check( isset( $failed['certloom_error'] ) && $baseline === array( $programs->find( $id ), $batches->find( $ids[0] ), $batches->find( $ids[1] ) ), 'A stale later batch rolls back the Program and earlier valid batch changes.' );
		$foreign = $ok( $programs->create( array( 'title' => 'Other ' . $nonce, 'code' => $nonce . '-other' ) ) ); $outside = $ok( $batches->create( array( 'program_id' => $foreign, 'name' => 'Other intake', 'code' => $nonce . '-foreign', 'active' => true ) ) );
		$failed = $post( $make_update( array( $make_change( $outside, array( 'active' => false ) ) ) ), 'update_program' ); $check( isset( $failed['certloom_error'] ) && $batches->find( $outside )['active'], 'Cross-Program patches are rejected by the real handler.' );
		$broken = $input; $broken['code'] = $nonce . '-broken'; $broken['program_batch_changes'] = wp_json_encode( array( array( 'id' => 0, 'key' => 'broken', 'patch' => array( 'name' => '' ) ) ) );
		$failed = $post( $broken, 'create_program' ); $check( isset( $failed['certloom_error'] ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE code=%s', SchemaManager::table( 'programs' ), $broken['code'] ) ), 'Invalid new batches leave no half-created Program.' );
		$broken = $make_update( array() ); unset( $broken['program_form_complete'] ); $failed = $post( $broken, 'update_program' ); $check( isset( $failed['certloom_error'] ), 'Truncated form is rejected before writes.' );
	} catch ( Throwable $error ) { $failure = $error->getMessage(); }
	return new WP_Error( 'program_probe_rollback', 'Always roll back all owned probe fixtures.' );
} );
remove_filter( 'wp_redirect', $redirect, PHP_INT_MAX ); LegacyWriteGuard::invalidate_cache();
$check( is_wp_error( $result ) && 'program_probe_rollback' === $result->get_error_code(), 'Outer fixture rollback is confirmed.' );
$check( $before === $fingerprint(), 'All domain rows and WordPress users, posts, metadata and options are unchanged, including LearnDash state.' );
if ( null !== $failure ) { fwrite( STDERR, $failure . "\n" ); exit( 1 ); }
echo wp_json_encode( array( 'success' => true, 'checks_passed' => count( $checks ), 'native_handlers' => true, 'fixtures_rolled_back' => true, 'learndash_untouched' => true ) ) . "\n";
