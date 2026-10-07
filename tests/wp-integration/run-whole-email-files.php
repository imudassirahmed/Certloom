<?php
/** Actual private file capture, reviewed email remapping and whole-state publication. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( ! $root || realpath( $root ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php'; require __DIR__ . '/isolated-local-sandbox.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX );
use Certloom\Application\Backup\WholeStateBackupBuilder;
use Certloom\Application\Backup\WholeStateReferenceFormat;
use Certloom\Application\Backup\WholeStateEmailMedia;
use Certloom\Application\Backup\WholeStateOptionValue;
use Certloom\Application\Email\EmailTemplateService;
use Certloom\Application\Restore\WholeStateAccountMappingProof;
use Certloom\Application\Restore\WholeStateObjectMappingProof;
use Certloom\Application\Restore\WholeStateRestoreReview;
use Certloom\Infrastructure\Backup\WholeStateAssetRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Restore\WholeStateRestoreWorkspace;
function wef_ok( $value ) { if ( is_wp_error( $value ) ) { $data = $value->get_error_data(); $diagnostic = is_array( $data ) ? array_intersect_key( $data, array_flip( array( 'stage', 'option', 'reason' ) ) ) : array(); throw new RuntimeException( $value->get_error_code() . ':' . wp_json_encode( $diagnostic ) ); } return $value; }
$checks = array(); $failure = null; $cleanup = null; $sandbox = null; $directory = null; $owned_files = array(); $private_files = array(); $work_owner = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
try {
	$directory = sys_get_temp_dir() . '/certloom-isolated-' . bin2hex( random_bytes( 8 ) ); $check( mkdir( $directory, 0700 ), 'exact private email-file fixture created' );
	$job_directory = $directory . '/jobs'; $private_filter = static function () use ( $job_directory ) { return $job_directory; }; add_filter( 'certloom_private_job_directory', $private_filter, PHP_INT_MAX );
	$uploads = $directory . '/uploads'; $check( mkdir( $uploads, 0700 ), 'owned media fixture directory created' );
	$upload_filter = static function ( $value ) use ( $uploads, $directory ) { $url = 'http://ecapla.local/wp-content/uploads/' . basename( $directory ); return array( 'path' => $uploads, 'url' => $url, 'subdir' => '', 'basedir' => $uploads, 'baseurl' => $url, 'error' => false ); }; add_filter( 'upload_dir', $upload_filter, PHP_INT_MAX );
	$sandbox = new CertloomIsolatedLocalSandbox( $directory . '/journal.json' ); $sandbox->create(); $sandbox->enter();
	$actor = wef_ok( wp_insert_user( array( 'user_login' => 'qa-email-file-owner', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) ) ); wp_set_current_user( $actor );
	wef_ok( ( new \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository() )->ensure( $actor ) );
	$image = $uploads . '/source.png'; $pdf = $uploads . '/source.pdf';
	$check( copy( dirname( __DIR__, 2 ) . '/output/audit-20261001/current-template-independent-review/catalog-png-1.png', $image ), 'owned source image has actual PNG bytes' );
	$pdf_bytes = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n"; $check( strlen( $pdf_bytes ) === file_put_contents( $pdf, $pdf_bytes ), 'owned static PDF has deterministic document bytes' );
	$owned_files[$image] = hash_file( 'sha256', $image ); $owned_files[$pdf] = hash_file( 'sha256', $pdf );
	$attach = static function ( $path, $mime, $title ) { $id = wef_ok( wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => $mime, 'post_status' => 'inherit' ), false, 0, true ) ); if ( ! update_attached_file( $id, wp_slash( $path ) ) ) { throw new RuntimeException( 'Owned media reference was not saved.' ); } return $id; };
	$image_id = $attach( $image, 'image/png', 'QA Source email image' ); $pdf_id = $attach( $pdf, 'application/pdf', 'QA Source email attachment' ); $source_url = wp_get_attachment_url( $image_id );
	$check( attachment_url_to_postid( $source_url ) === $image_id, 'actual WordPress image URL resolves to the owned source attachment' );
	$html = '<p>Hello {{recipient_name}}</p><table background="' . esc_url( $source_url ) . '" style="background-image:url(\'' . esc_url( $source_url ) . '\')"><tr><td><img src="' . esc_url( $source_url ) . '" alt="QA artwork"></td></tr></table>';
	$email_service = new EmailTemplateService(); $source_email = wef_ok( $email_service->normalize_template( array( 'html_body' => $html, 'attachments' => array( $pdf_id, $image_id ) ) ) );
	$check( update_option( 'certloom_email_template_issued_v1', $source_email, false ), 'native issued-email configuration retains two static attachments and embedded media' );
	$catalog = new \Certloom\Infrastructure\Email\EmailTemplateCatalogRepository();
	wef_ok( $catalog->save( array( 'id' => 'qa-email-files', 'name' => 'QA Email files', 'purpose' => 'credential_issued', 'subject' => 'QA {{recipient_name}}', 'text_body' => 'Hello {{recipient_name}}', 'attachments' => array( $pdf_id ), 'blocks' => array( array( 'type' => 'image', 'url' => $source_url, 'alt' => 'QA artwork' ) ), 'theme' => array( 'background_image' => $source_url ) ) ) );
	$saved_catalog = get_option( 'certloom_email_template_catalog_v1' );
	$check( $catalog->validate_recovery_value( $saved_catalog ) === $saved_catalog, 'native visual email markup remains valid despite equivalent KSES apostrophe entities' );
	$legacy_catalog = $saved_catalog;
	$legacy_catalog['templates'][0]['html_body'] = \Certloom\Infrastructure\Email\EmailDesignCompiler::RESPONSIVE_CSS . $legacy_catalog['templates'][0]['html_body'];
	$check( $catalog->validate_recovery_value( $legacy_catalog ) === $legacy_catalog, 'actual native email catalog accepts the exact older compiler CSS prefix without rewriting source bytes' );
	$unsafe_catalog = $legacy_catalog; $unsafe_catalog['templates'][0]['html_body'] .= '<script>alert(1)</script>';
	$check( is_wp_error( $catalog->validate_recovery_value( $unsafe_catalog ) ), 'known native CSS repair cannot hide an executable email payload' );
	$check( true === \Ecapla_CertVerify_Fields::save_fields( \Ecapla_CertVerify_Fields::get_default_fields() ), 'owned source installs its own native field catalog' );
	$source_contract = ( new \Certloom\Application\Fields\ScopedFieldContract() )->normalize_scope( 'program', array( 'email' => 'required' ), \Ecapla_CertVerify_Fields::get_field_map() );
	$program_id = wef_ok( ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->create( array( 'code' => 'QA-EMAIL-FIELDS', 'title' => 'QA Recovery field requirements', 'metadata' => array( \Certloom\Application\Fields\ScopedFieldContract::STORAGE_KEY => wef_ok( $source_contract ) ) ) ) );
	$check( $program_id > 0, 'native Program retains actual required recipient fields in its source metadata' );
	$repo = new WholeStateAssetRepository(); $source_file = wef_ok( $repo->file( 'attachment:' . $pdf_id ) );
	$check( $source_file['sha256'] === $owned_files[$pdf] && $source_file['size'] === strlen( $pdf_bytes ), 'uncached static attachment descriptor proves the actual file' );
	$builder = new WholeStateBackupBuilder(); $key = bin2hex( random_bytes( 16 ) ); $whole = wef_ok( $builder->start( $key ) );
	for ( $slice = 0; $slice < 2000 && ! $whole['complete']; ++$slice ) { $whole = wef_ok( $builder->continue_build( $whole ) ); }
	$check( $whole['complete'], 'whole capture finishes through bounded current reference protocol slices' );
	$zip = new ZipArchive(); $check( true === $zip->open( $whole['archive_path'], ZipArchive::RDONLY ), 'real complete recovery archive opens read only' );
	try {
		$stream = $zip->getStream( 'data/references.jsonl' ); $refs = wef_ok( ( new WholeStateReferenceFormat() )->analyze( $stream, static function () { return true; } ) ); fclose( $stream );
		$check( 2 === $refs['format_version'] && 2 === $refs['counts']['file'] && 1 === $refs['counts']['image'] && 1 === $refs['counts']['media_url'] && 2 === count( $refs['assets'] ), 'PDF, repeated image attachment, visual background and inline image have closed deduplicated references' );
		$check( $zip->getFromName( 'assets/' . $owned_files[$pdf] ) === $pdf_bytes, 'archived static document preserves every original byte' );
		$check( hash( 'sha256', $zip->getFromName( 'assets/' . $owned_files[$image] ) ) === $owned_files[$image], 'archived image preserves exact pixels and container bytes' );
	} finally { $zip->close(); }
	$target_image = $uploads . '/target.png'; $target_pdf = $uploads . '/target.pdf';
	$check( copy( $image, $target_image ) && copy( $pdf, $target_pdf ), 'reviewed target media use independently named exact copies' ); $owned_files[$target_image] = $owned_files[$image]; $owned_files[$target_pdf] = $owned_files[$pdf];
	$target_image_id = $attach( $target_image, 'image/png', 'QA Target email image' ); $target_pdf_id = $attach( $target_pdf, 'application/pdf', 'QA Target email attachment' ); $target_url = wp_get_attachment_url( $target_image_id );
	$accounts = array(); foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id,wp_user_id FROM %i WHERE wp_user_id IS NOT NULL', \Certloom\Infrastructure\Database\SchemaManager::table( 'recipients' ) ), ARRAY_A ) as $row ) { $accounts[(int) $row['id']] = (int) $row['wp_user_id']; }
	$make_review = static function ( array $objects ) use ( $whole, $actor, $accounts ) {
		$account_proof = wef_ok( WholeStateAccountMappingProof::review( $whole['archive_path'], $whole['archive_sha256'], $actor, $accounts, 'reviewed_target' ) );
		$object_proof = wef_ok( WholeStateObjectMappingProof::review( $whole['archive_path'], $whole['archive_sha256'], $actor, $objects, 'reviewed_target' ) );
		return WholeStateRestoreReview::review( $whole['archive_path'], $whole['archive_sha256'], $account_proof, $object_proof );
	};
	$object_map = array( $image_id => $target_image_id, $pdf_id => $target_pdf_id ); $review = wef_ok( $make_review( $object_map ) );
	$program_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'programs' ), $program_id ), ARRAY_A );
	$check( wef_ok( $review->project_domain( 'programs', $program_row ) )['metadata_json'] === $program_row['metadata_json'], 'actual Source field requirements pass without changing their native stored metadata' );
	$missing_field_row = $program_row; $missing_contract = $source_contract; $missing_contract['fields']['qa_unlisted_source_field'] = 'required'; $missing_field_row['metadata_json'] = wp_json_encode( array( \Certloom\Application\Fields\ScopedFieldContract::STORAGE_KEY => $missing_contract ) );
	$missing_field = $review->project_domain( 'programs', $missing_field_row );
	$check( is_wp_error( $missing_field ) && 'certloom_whole_field_catalog_incomplete' === $missing_field->get_error_code(), 'actual database Program shape cannot introduce a requirement absent from the immutable Source field catalog' );
	$projected = wef_ok( ( new WholeStateOptionValue() )->decode( $review->proposal()['fixed']['certloom_email_template_issued_v1'] ) )['value'];
	$check( $projected['attachments'] === array( $target_pdf_id, $target_image_id ), 'reviewed attachment IDs map to their exact target files without importing WordPress objects' );
	$mapped_urls = wef_ok( WholeStateEmailMedia::urls( $projected['html_body'] ) );
	$check( $mapped_urls === array( $target_url ) && false === strpos( $projected['html_body'], $source_url ), 'HTML image, table background and CSS image URLs map together without source host leakage' );
	$projected_catalog = wef_ok( ( new WholeStateOptionValue() )->decode( $review->proposal()['fixed']['certloom_email_template_catalog_v1'] ) )['value'];
	$matched = array_values( array_filter( $projected_catalog['templates'], static function ( $row ) { return 'qa-email-files' === $row['id']; } ) )[0];
	$check( $matched['blocks'][0]['url'] === $target_url && $matched['theme']['background_image'] === $target_url && false === strpos( $matched['html_body'], $source_url ), 'visual email blocks, theme and compiled HTML retain the same reviewed target artwork' );
	file_put_contents( $target_pdf, $pdf_bytes . 'changed' );
	$check( is_wp_error( $review->assert_current() ) && is_wp_error( $make_review( $object_map ) ), 'a changed or reused target file invalidates approval before any table promotion' ); file_put_contents( $target_pdf, $pdf_bytes );
	$check( true === $review->assert_current(), 'exact target file restoration re-establishes its unchanged approval' );
	$alias_image = $uploads . '/target-renamed.png'; $check( copy( $target_image, $alias_image ), 'owned URL-change fixture retains the same actual image bytes' ); $owned_files[$alias_image] = $owned_files[$image];
	get_post_meta( $target_image_id, '_wp_attached_file', true ); wp_get_attachment_url( $target_image_id );
	$old_path = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM %i WHERE post_id=%d AND meta_key='_wp_attached_file'", $wpdb->postmeta, $target_image_id ) );
	$check( 1 === $wpdb->update( $wpdb->postmeta, array( 'meta_value' => basename( $alias_image ) ), array( 'post_id' => $target_image_id, 'meta_key' => '_wp_attached_file' ) ), 'actual SQL changes the image location behind warm WordPress caches' );
	$check( is_wp_error( $review->assert_current() ), 'identical image bytes cannot conceal a changed approved URL or attachment metadata' );
	$fresh_location = wef_ok( $make_review( $object_map ) );
	$check( $fresh_location->fingerprint() !== $review->fingerprint(), 'a deliberate fresh review establishes a new current URL approval' );
	$check( 1 === $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $old_path ), array( 'post_id' => $target_image_id, 'meta_key' => '_wp_attached_file' ) ) && true === $review->assert_current(), 'restoring exact original URL metadata restores the original review without stale cache authority' );
	$disconnected = wef_ok( $make_review( array( $image_id => null, $pdf_id => null ) ) ); $empty = wef_ok( ( new WholeStateOptionValue() )->decode( $disconnected->proposal()['fixed']['certloom_email_template_issued_v1'] ) )['value'];
	$check( array() === $empty['attachments'] && false === strpos( $empty['html_body'], $source_url ), 'explicit disconnection removes editable attachment and image connections without granting source ID access' );
	$disconnected_catalog = wef_ok( ( new WholeStateOptionValue() )->decode( $disconnected->proposal()['fixed']['certloom_email_template_catalog_v1'] ) )['value'];
	$blank = array_values( array_filter( $disconnected_catalog['templates'], static function ( $row ) { return 'qa-email-files' === $row['id']; } ) )[0];
	$check( array( array( 'type' => 'spacer', 'height' => 8 ) ) === $blank['blocks'], 'disconnecting the only image does not replace the organization design with a factory default' );
	$publication_owner = bin2hex( random_bytes( 16 ) ); $maintenance = new \Certloom\Infrastructure\Data\DomainMaintenanceLock();
	wef_ok( $maintenance->acquire( $publication_owner, 'whole_email_file_qa', 3600 ) ); $publication = new \Certloom\Infrastructure\Restore\WholeStateAssetPublication();
	$publication_state = wef_ok( $publication->start( $whole['archive_path'], $whole['archive_sha256'], $publication_owner ) );
	$publication_state = wef_ok( $publication->continue_publication( $publication_owner ) );
	$check( 1 === $publication_state['cursor'] && isset( $publication_state['assets'][1]['descriptor']['file_type'] ), 'real bounded publication reaches the opaque document after its image' );
	$faulted = false; $fault_filter = static function ( $sql ) use ( &$faulted, $wpdb ) { if ( ! $faulted && 0 === strpos( $sql, 'UPDATE `' . $wpdb->options . '`' ) && false !== strpos( $sql, \Certloom\Infrastructure\Restore\WholeStateAssetPublication::OPTION ) ) { $faulted = true; return 'SELECT * FROM certloom_qa_missing_email_file_cursor'; } return $sql; };
	$old_suppress = $wpdb->suppress_errors( true ); add_filter( 'query', $fault_filter, PHP_INT_MAX );
	try { $failed_publication = $publication->continue_publication( $publication_owner ); } finally { remove_filter( 'query', $fault_filter, PHP_INT_MAX ); $wpdb->suppress_errors( $old_suppress ); $wpdb->last_error = ''; }
	$check( $faulted && is_wp_error( $failed_publication ), 'actual document cursor SQL failure refuses to claim completed publication' );
	$private_document = $job_directory . '/recovery-assets/' . $owned_files[$pdf];
	$check( is_file( $private_document ) && hash_file( 'sha256', $private_document ) === $owned_files[$pdf], 'interrupted publication has one exclusive complete document with exact source bytes' );
	$publication_state = wef_ok( $publication->continue_publication( $publication_owner ) );
	$check( 'published' === $publication_state['phase'] && 2 === $publication_state['cursor'], 'retry adopts the same document and persists the missing cursor without duplication' );
	wef_ok( $publication->rollback( $publication_owner ) ); wef_ok( $maintenance->release( $publication_owner ) );
	$check( null === $publication->pending_owner() && ! is_file( $private_document ) && true === $review->assert_current(), 'rollback removes only unchanged owned recovered files and preserves current approval' );
	$work_owner = bin2hex( random_bytes( 16 ) ); $sandbox->register_domain_workspace( $work_owner, true ); $workspace = new WholeStateRestoreWorkspace(); $state = wef_ok( $workspace->start( $review, $work_owner ) );
	for ( $slice = 0; $slice < 500 && 'ready' !== $state['phase']; ++$slice ) { $state = wef_ok( $workspace->continue_stage( $work_owner, $review, 2 ) ); }
	$check( 'ready' === $state['phase'], 'real full workspace stages private document and image publication with all records' );
	$result = wef_ok( $workspace->commit( $work_owner, $review ) );
	for ( $slice = 0; $slice < 50 && ! $result['complete']; ++$slice ) { $result = wef_ok( $workspace->commit( $work_owner, $review ) ); }
	$check( $result['complete'] && null === ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(), 'one durable whole-state receipt commits records, email mappings and exact private files' );
	$private_root = $job_directory . '/recovery-assets';
	foreach ( array( $owned_files[$image], $owned_files[$pdf] ) as $asset_sha ) { $path = $private_root . '/' . $asset_sha; $check( is_file( $path ) && hash_file( 'sha256', $path ) === $asset_sha, 'committed recovered file has its exact source hash' ); $private_files[$path] = $asset_sha; }
	$check( get_option( 'certloom_email_template_issued_v1' ) === $projected, 'actual target settings equal the reviewed email projection after commit' );
	$check( $email_service->validate_static_attachment_ids( $projected['attachments'] ) === $projected['attachments'], 'native email delivery can resolve the recovered mapped static attachments' );
	$check( $owned_files[$image] === hash_file( 'sha256', $image ) && $owned_files[$pdf] === hash_file( 'sha256', $pdf ), 'source WordPress attachment files remain unchanged throughout recovery' );
	$missing_url = dirname( $target_url ) . '/missing-owned-artwork.png';
	$bad_email = wef_ok( $email_service->normalize_template( array( 'html_body' => '<p>Hello</p><img src="' . esc_url( $missing_url ) . '">', 'text_body' => 'Hello' ) ) );
	$check( update_option( 'certloom_email_template_issued_v1', $bad_email, false ), 'owned fixture saves an unresolved local email artwork URL' );
	$missing_capture = wef_ok( $builder->start( bin2hex( random_bytes( 16 ) ) ) );
	for ( $slice = 0; $slice < 500 && ! is_wp_error( $missing_capture ) && ! $missing_capture['complete']; ++$slice ) { $missing_capture = $builder->continue_build( $missing_capture ); }
	$check( is_wp_error( $missing_capture ) && 'certloom_whole_references_missing_email_image' === $missing_capture->get_error_code(), 'unresolved local email artwork refuses complete capture instead of silently becoming an external link' );
} catch ( Throwable $e ) { $failure = $e->getMessage(); }
finally {
	if ( $sandbox ) {
		try {
			$journal = ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(); $asset_owner = ( new \Certloom\Infrastructure\Restore\WholeStateAssetPublication() )->pending_owner();
			if ( is_wp_error( $journal ) || $journal || is_wp_error( $asset_owner ) || $asset_owner ) { throw new RuntimeException( 'Unfinished recovery retained with its exact fixture journal.' ); }
			$cleanup = $sandbox->cleanup();
			foreach ( $owned_files + $private_files as $path => $hash ) { if ( is_file( $path ) && ( is_link( $path ) || hash_file( 'sha256', $path ) !== $hash || ! unlink( $path ) ) ) { throw new RuntimeException( 'Changed owned email fixture retained.' ); } }
			if ( is_dir( $job_directory . '/recovery-assets' ) && ! rmdir( $job_directory . '/recovery-assets' ) ) { throw new RuntimeException( 'Unexpected recovered files retained.' ); }
			if ( is_dir( $job_directory ) ) {
				$files = new PrivateJobFileStore();
				foreach ( new DirectoryIterator( $job_directory ) as $file ) {
					if ( $file->isDot() ) { continue; } $path = $file->getPathname(); $name = $file->getFilename();
					if ( in_array( $name, array( 'index.php', '.htaccess', 'web.config' ), true ) && $file->isFile() && ! $file->isLink() ) { if ( ! unlink( $path ) ) { throw new RuntimeException( 'Owned file sentinel cleanup failed.' ); } continue; }
					if ( ! $files->owns( $path ) || ! $file->isFile() || $file->isLink() ) { throw new RuntimeException( 'Unexpected private fixture file retained.' ); } wef_ok( $files->delete( $path ) );
				}
				if ( ! rmdir( $job_directory ) ) { throw new RuntimeException( 'Owned job directory cleanup failed.' ); }
			}
			if ( ! rmdir( $uploads ) || ! rmdir( $directory ) ) { throw new RuntimeException( 'Owned file fixture directory cleanup failed.' ); } $directory = null;
		} catch ( Throwable $e ) { $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); $sandbox->leave(); }
	}
	if ( isset( $private_filter ) ) { remove_filter( 'certloom_private_job_directory', $private_filter, PHP_INT_MAX ); }
	if ( isset( $upload_filter ) ) { remove_filter( 'upload_dir', $upload_filter, PHP_INT_MAX ); }
}
echo wp_json_encode( array( 'success' => null === $failure && is_array( $cleanup ), 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'cleanup' => $cleanup, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure && is_array( $cleanup ) ? 0 : 1 );
