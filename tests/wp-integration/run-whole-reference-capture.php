<?php
/** Real bounded legacy/reference/file capture in exact disposable tables; no restore or transport. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( ! $root || realpath( $root ) !== realpath( 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public' ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php'; require __DIR__ . '/isolated-local-sandbox.php';
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX );
use Certloom\Application\Backup\DomainBackupBuilder;
use Certloom\Application\Backup\LegacyBackupBuilder;
use Certloom\Application\Backup\WholeStateReferenceBuilder;
use Certloom\Application\Backup\WholeStateReferenceFormat;
use Certloom\Infrastructure\Backup\WholeStateOptionRepository;
use Certloom\Infrastructure\Backup\WholeStateReferenceRepository;
use Certloom\Infrastructure\Backup\WholeStateAssetRepository;
use Certloom\Infrastructure\Backup\LocalBackupStore;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
function wr_ok( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( $v->get_error_code() ); } return $v; }
function wr_finish( $state, $builder ) { for ( $i = 0; $i < 2000 && ! $state['complete']; ++$i ) { $state = wr_ok( $builder->continue_build( $state ) ); } if ( ! $state['complete'] ) { throw new RuntimeException( 'Capture did not finish within its slice ceiling.' ); } return $state; }
$checks = array(); $failure = null; $cleanup = null; $sandbox = null; $owned_files = array(); $directory = null; $whole_job_keys = array(); $catalog_directory = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
try {
 $directory = sys_get_temp_dir() . '/certloom-isolated-' . bin2hex( random_bytes( 8 ) ); $check( mkdir( $directory, 0700 ), 'private capture fixture directory created' );
 $job_directory = $directory . '/jobs'; $job_directory_filter = static function () use ( $job_directory ) { return $job_directory; };
 add_filter( 'certloom_private_job_directory', $job_directory_filter, PHP_INT_MAX );
 $sandbox = new CertloomIsolatedLocalSandbox( $directory . '/journal.json' ); $sandbox->create(); $sandbox->enter();
 $actor = wr_ok( wp_insert_user( array( 'user_login' => 'qa-recovery-owner', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) ) ); wp_set_current_user( $actor );
 $user = wr_ok( wp_insert_user( array( 'user_login' => 'qa-recovery-recipient', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) ) );
 $recipient = wr_ok( ( new \Certloom\Infrastructure\Repository\RecipientRepository() )->find_by_wp_user_id( $user ) );
 $check( is_array( $recipient ), 'real isolated account registration links one recipient' );
 $program = wr_ok( ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->create( array( 'title' => 'QA Recovery', 'code' => 'QA-RECOVERY' ) ) );
 $image = $directory . '/image.png'; $check( copy( dirname( __DIR__, 2 ) . '/output/audit-20261001/current-template-independent-review/catalog-png-1.png', $image ), 'owned exact PNG copied without changing ordinary uploads' );
 $attachment = wr_ok( wp_insert_attachment( array( 'post_title' => 'QA Recovery Image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), false, 0, true ) );
 $check( update_attached_file( $attachment, wp_slash( str_replace( '/', DIRECTORY_SEPARATOR, realpath( $image ) ) ) ), 'owned local attachment points to the private QA image' );
 $media_layout = ( new \Certloom\Application\Templates\PresetCatalog() )->get( 'modern-a4-landscape' )['layout'];
 $media_z = max( array_column( $media_layout['elements'], 'z' ) ) + 1;
 $media_layout['elements'][] = array( 'id' => ( new \Certloom\Domain\Rendering\ElementId() )->generate(), 'type' => 'image', 'x_mm' => 10.0, 'y_mm' => 10.0, 'width_mm' => 20.0, 'height_mm' => 20.0, 'z' => $media_z, 'asset' => 'attachment:' . $attachment, 'fit' => 'contain' );
 $media_template = wr_ok( ( new \Certloom\Infrastructure\Repository\TemplateRepository() )->create( array( 'machine_key' => 'qa-recovery-media', 'name' => 'QA Recovery Media', 'layout' => $media_layout ), $actor ) );
 $check( $media_template > 0, 'actual editable template adds a current media dependency alongside immutable issued bytes' );
 $media_revision = $wpdb->get_row( $wpdb->prepare( 'SELECT id,layout_sha256 FROM %i WHERE template_id=%d ORDER BY id DESC LIMIT 1', \Certloom\Infrastructure\Database\SchemaManager::table( 'template_revisions' ), $media_template ), ARRAY_A );
 wr_ok( ( new \Certloom\Infrastructure\Repository\TemplateRepository() )->publish( $media_template, $media_revision['id'], $media_revision['layout_sha256'] ) );
 $settings = Ecapla_CertVerify_Settings::get_all(); $settings['numbering_mode'] = 'manual'; $check( Ecapla_CertVerify_Settings::save( $settings ), 'manual test numbering configured only in disposable options' );
 $manual = new \Certloom\Application\Issuance\ManualIssuanceService();
 $request = array( 'recipient_mode' => 'existing', 'recipient_id' => $recipient['id'], 'program_id' => $program, 'presentation_mode' => 'image', 'certificate_image_id' => $attachment, 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QA-RECOVERY-IMAGE' );
 $review = wr_ok( $manual->review( $request ) ); $receipt = wr_ok( $manual->issue( $request, $review['review_fingerprint'], $actor ) );
 $check( $receipt['version_id'] > 0, 'canonical image Version creates actual immutable media proof without delivery' );
 $reference_repo = new WholeStateReferenceRepository(); $asset_repo = new WholeStateAssetRepository(); $asset = wr_ok( $asset_repo->image( 'attachment:' . $attachment ) ); $descriptor = $asset; unset( $descriptor['path'] );
 $check( hash_file( 'sha256', $image ) === $asset['sha256'], 'uncached attachment capture independently verifies exact image bytes' );
 $identity = wr_ok( $reference_repo->account( $recipient['id'], $user ) ); $check( is_string( $identity['identity_sha256'] ), 'account capture exports an opaque exact identity digest' );
 $missing = wr_ok( $reference_repo->account( $recipient['id'], 999999 ) ); $check( null === $missing['identity_sha256'], 'missing account is explicit review evidence rather than inferred ownership' );
 $object = wr_ok( $reference_repo->object( $attachment ) ); $check( 'attachment' === $object['post_type'], 'actual object identity retains the exact WordPress type' );
 $missing = wr_ok( $reference_repo->object( 999999 ) ); $check( null === $missing['post_type'] && null === $missing['identity_sha256'], 'missing object reference is preserved explicitly' );
 // Warm ordinary WordPress metadata; capture must still read the changed SQL evidence.
 get_userdata( $user ); $old = $wpdb->get_var( $wpdb->prepare( 'SELECT user_email FROM %i WHERE ID=%d', $wpdb->users, $user ) );
 $check( 1 === $wpdb->update( $wpdb->users, array( 'user_email' => 'changed@example.invalid' ), array( 'ID' => $user ) ), 'owned current identity mutation committed' );
 $changed = wr_ok( $reference_repo->account( $recipient['id'], $user ) ); $check( $changed['identity_sha256'] !== $identity['identity_sha256'], 'warmed account cache cannot hide a current identity change' );
 $wpdb->update( $wpdb->users, array( 'user_email' => $old ), array( 'ID' => $user ) );
 $domain_builder = new DomainBackupBuilder(); $domain = wr_ok( $domain_builder->start( bin2hex( random_bytes( 16 ) ), CERTLOOM_VERSION ) ); $owned_files[$domain['path']] = true; $domain = wr_finish( $domain, $domain_builder );
 $check( ! is_wp_error( ( new \Certloom\Application\Backup\DomainBackupReader() )->analyze( $domain['path'] ) ), 'native v5 stream remains accepted by its existing reader' );
 $fixed = wr_ok( ( new WholeStateOptionRepository() )->snapshot() ); $builder = new WholeStateReferenceBuilder();
 $references = wr_ok( $builder->start( bin2hex( random_bytes( 16 ) ), $domain['path'], $fixed ) ); $owned_files[$references['path']] = true;
 $persisted = $references; $advanced = wr_ok( $builder->continue_build( $references ) ); $replayed = wr_ok( $builder->continue_build( $persisted ) );
 $check( $advanced === $replayed, 'uncommitted reference append is truncated and the same bounded source slice replays exactly' );
 $references = wr_finish( $replayed, $builder ); foreach ( $references['asset_files'] as $file ) { $owned_files[$file['path']] = true; }
 $handle = fopen( $references['path'], 'rb' ); $seen = array(); $analyzed = wr_ok( ( new WholeStateReferenceFormat() )->analyze( $handle, static function ( $row ) use ( &$seen ) { $seen[] = $row; return true; } ) ); fclose( $handle );
 $check( $analyzed['counts']['account'] === 2 && $analyzed['counts']['object'] === 1 && $analyzed['counts']['image'] === 1, 'complete account/object/immutable-media graph round trips through the strict reference reader' );
 $check( count( $references['asset_files'] ) === 1 && isset( $references['asset_files'][$asset['sha256']] ), 'duplicate historical/current media keeps one exact content-addressed member' );
 $copy = $references['asset_files'][$asset['sha256']]['path']; $check( is_file( $copy ) && hash_file( 'sha256', $copy ) === $asset['sha256'], 'private asset copy matches the historical image hash exactly' );
 $bytes = file_get_contents( $image ); file_put_contents( $image, $bytes . 'changed' );
 $check( is_wp_error( $asset_repo->image( 'attachment:' . $attachment, $descriptor ) ), 'changed present media cannot satisfy an immutable issued descriptor' );
 $check( hash_file( 'sha256', $copy ) === $descriptor['sha256'], 'later attachment changes cannot overwrite captured immutable bytes' ); file_put_contents( $image, $bytes );
 // Existing v4 registry contract, including zero and leading-zero custom facts.
 for ( $i = 1; $i <= 4; ++$i ) { wr_ok( Ecapla_CertVerify_Database::insert( array( 'cert_number' => 'QA-LEGACY-' . $i, 'holder_name' => 'QA Legacy ' . $i, 'status' => 'valid', 'issue_date' => '2026-10-01', 'expiry_date' => '', 'custom_fields' => array() ) ) ); }
 $legacy_builder = new LegacyBackupBuilder(); $legacy = wr_ok( $legacy_builder->start( bin2hex( random_bytes( 16 ) ) ) ); $owned_files[$legacy['path']] = true;
 $saved = $legacy; $advanced = wr_ok( $legacy_builder->continue_build( $legacy, 2 ) ); $replayed = wr_ok( $legacy_builder->continue_build( $saved, 2 ) );
 $check( $advanced === $replayed, 'uncommitted legacy v4 append replays from its authenticated prefix exactly' );
 $legacy = wr_finish( $replayed, $legacy_builder );
 $lines = file( $legacy['path'] ); $footer = json_decode( array_pop( $lines ), true ); $check( 4 === $footer['records'] && hash( 'sha256', implode( '', $lines ) ) === $footer['sha256'], 'legacy v4 footer retains its original whole-prefix SHA-256 contract' );
 $first = json_decode( $lines[0], true ); $check( 4 === $first['format_version'] && 'certverify-backup' === $first['format'], 'asynchronous capture does not relabel or alter the legacy format' );
 $legacy_handle = fopen( $legacy['path'], 'rb' ); $legacy_rows = array();
 try { $legacy_review = wr_ok( ( new \Certloom\Application\Restore\WholeStateLegacyFormat() )->analyze( $legacy_handle, static function ( $row ) use ( &$legacy_rows ) { $legacy_rows[] = $row; return true; } ) ); } finally { fclose( $legacy_handle ); }
 $check( $legacy_review['records'] === 4 && count( $legacy_rows ) === 4 && null === $legacy_rows[0]['expiry_date'], 'strict whole-state registry preflight accepts actual v4 storage without runtime repair' );
 $state = wr_ok( $legacy_builder->start( bin2hex( random_bytes( 16 ) ) ) ); $owned_files[$state['path']] = true;
 wr_ok( Ecapla_CertVerify_Database::insert( array( 'cert_number' => 'QA-LEGACY-CHANGED', 'holder_name' => 'QA Changed', 'status' => 'valid', 'issue_date' => '2026-10-01', 'expiry_date' => '', 'custom_fields' => array() ) ) );
 $check( is_wp_error( $legacy_builder->continue_build( $state ) ), 'registry mutation between slices refuses a mixed-state legacy component' );
 $state = $legacy; $state['offset'] = 0; $check( is_wp_error( $legacy_builder->continue_build( $state ) ), 'untrusted persisted legacy cursor bounds fail closed' );
 $check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \Certloom\Infrastructure\Database\SchemaManager::table( 'email_deliveries' ) ) ), 'no customer mail or destination delivery is created by the capture fixtures' );
 $email = new \Certloom\Infrastructure\Repository\EmailDeliveryRepository(); $destination = new \Certloom\Infrastructure\Repository\DestinationDeliveryRepository();
 $before_delivery_jobs = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \Certloom\Infrastructure\Database\SchemaManager::table( 'jobs' ) ) );
 for ( $i = 0; $i < 20; ++$i ) {
  $hash = hash( 'sha256', 'qa-capture-delivery-' . $i );
  wr_ok( ( $i < 17 ? $email : $destination )->create_delivery( array( 'series_id' => $receipt['series_id'], 'version_id' => $receipt['version_id'], 'destination' => 'qa', 'dedupe_key' => $hash, 'mapping_sha256' => $hash, 'configuration_sha256' => $hash, 'recipient_sha256' => $hash, 'destination_sha256' => $hash, 'subject_sha256' => $hash, 'html_sha256' => $hash, 'text_sha256' => $hash, 'composition_sha256' => $hash, 'evidence' => array( 'zero' => 0, 'id' => '00123' ) ) ) );
 }
 $deliveries_builder = new \Certloom\Application\Backup\WholeStateDeliveryBuilder();
 $runs = ( new \Certloom\Application\Backup\WholeStateOptionRecord() )->from_storage( serialize( array( 'qa-replay-proof' => array( 'state' => 'sent' ) ) ) );
 $delivery_state = wr_ok( $deliveries_builder->start( bin2hex( random_bytes( 16 ) ), $runs ) ); $owned_files[$delivery_state['path']] = true;
 $saved = $delivery_state; $advanced = wr_ok( $deliveries_builder->continue_build( $saved ) ); $replayed = wr_ok( $deliveries_builder->continue_build( $saved ) );
 $check( $advanced === $replayed && $advanced['records'] === 16, 'bounded sixteen-row delivery slice replays exactly after an uncommitted append' );
 $delivery_state = wr_finish( $replayed, $deliveries_builder ); $handle = fopen( $delivery_state['path'], 'rb' ); $seen = 0;
 $delivery_result = wr_ok( ( new \Certloom\Application\Backup\WholeStateDeliveryFormat() )->analyze( $handle, static function ( $type, $row ) use ( &$seen ) { ++$seen; return true; } ) ); fclose( $handle );
 $check( $seen === 20 && $delivery_result['entities']['email_deliveries']['count'] === 17 && $delivery_result['entities']['destination_deliveries']['count'] === 3, 'both complete delivery stores round trip over multiple actual SQL pages' );
 $check( ( new \Certloom\Application\Rendering\CanonicalJson() )->hash( $delivery_result['automation_runs'] ) === ( new \Certloom\Application\Rendering\CanonicalJson() )->hash( $runs ) && $before_delivery_jobs === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \Certloom\Infrastructure\Database\SchemaManager::table( 'jobs' ) ) ), 'automation replay evidence is retained without reconstructing or scheduling send jobs' );
 wr_ok( ( new \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository() )->ensure( $actor ) );
 wr_ok( ( new \Certloom\Infrastructure\Integration\CourseraMappingStore() )->save( array( 'course_id' => 'qa-recovery-course', 'program_id' => $program, 'active' => true ) ) );
 wr_ok( ( new \Certloom\Infrastructure\Integration\CredlyMappingStore() )->save( array( 'program_id' => $program, 'badge_template_id' => 'qa-recovery-badge', 'active' => true ) ) );
 $hook = wr_ok( ( new \Certloom\Infrastructure\Integration\WebhookSourceRepository() )->create( array( 'name' => 'QA Recovery Source Graph', 'program_id' => $program, 'batch_id' => 0, 'template_id' => 999999, 'active' => true ) ) );
 $check( preg_match( '/^hook-[a-f0-9]{12}$/D', $hook['key'] ) && strlen( $hook['secret'] ) === 64, 'nonempty native v1 webhook configuration is captured without exposing its signing secret' ); unset( $hook['secret'] );
 wr_ok( ( new \Certloom\Infrastructure\Email\EmailDesignAssignmentRepository() )->save( 'program', $program, \Certloom\Infrastructure\Email\EmailDesignPurposes::ISSUED, 'default' ) );
 $whole_builder = new \Certloom\Application\Backup\WholeStateBackupBuilder(); $whole_key = bin2hex( random_bytes( 16 ) );
 $track_whole = static function () use ( $whole_key, &$owned_files ) { $files = new PrivateJobFileStore(); $root = wr_ok( $files->directory() ); foreach ( scandir( $root ) as $name ) { $path = $root . '/' . $name; if ( strpos( $name, $whole_key . '-' ) === 0 && $files->owns( $path ) && is_file( $path ) ) { $owned_files[$path] = true; } } };
 $whole = wr_ok( $whole_builder->start( $whole_key ) );
 $track_whole();
 $checkpoint = wr_ok( $whole_builder->save_checkpoint( $whole ) ); $owned_files[$checkpoint['path']] = true; $restored = wr_ok( $whole_builder->load_checkpoint( $checkpoint, $whole_key ) );
 $check( ( new \Certloom\Application\Rendering\CanonicalJson() )->hash( $restored ) === ( new \Certloom\Application\Rendering\CanonicalJson() )->hash( $whole ), 'private immutable capture checkpoint round trips without putting raw configuration into job progress' );
 $tampered = $checkpoint; $tampered['sha256'] = str_repeat( '0', 64 ); $check( is_wp_error( $whole_builder->load_checkpoint( $tampered, $whole_key ) ), 'altered checkpoint proof is refused before work resumes' );
 $check( is_wp_error( $whole_builder->load_checkpoint( $checkpoint, str_repeat( 'a', 32 ) ) ), 'another capture cannot adopt the private checkpoint' );
 for ( $slice = 0; $slice < 2000 && ! $whole['complete']; ++$slice ) {
  $whole = wr_ok( $whole_builder->continue_build( $whole ) ); $track_whole();
  // Exercise an actual saved worker boundary in every capture phase.
  $checkpoint = wr_ok( $whole_builder->save_checkpoint( $whole ) ); $owned_files[$checkpoint['path']] = true; $whole = wr_ok( $whole_builder->load_checkpoint( $checkpoint, $whole_key ) );
 }
 $check( $whole['complete'], 'all six components and actual media finish through one resumable capture builder' );
 $archive = wr_ok( ( new \Certloom\Application\Backup\WholeStateBackupReader() )->analyze( $whole['archive_path'] ) );
 $origin = wr_ok( ( new \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository() )->verify( $archive['manifest'] ) );
 $check( $origin['provenance_verified'] && count( $archive['manifest']['members'] ) === 7 && ! $archive['restore_ready'], 'complete source-signed archive contains six components and one exact asset without granting restore authority' );
 $zip = new ZipArchive(); $check( true === $zip->open( $whole['archive_path'], ZipArchive::RDONLY ), 'actual whole-state ZIP opens read-only' );
 $embedded = $zip->getFromName( 'assets/' . $asset['sha256'] ); $zip->close();
 $check( is_string( $embedded ) && hash( 'sha256', $embedded ) === $asset['sha256'], 'whole-state member contains the exact issued image bytes' );
 $check( filesize( $whole['archive_path'] ) === $whole['archive_bytes'] && hash_file( 'sha256', $whole['archive_path'] ) === $whole['archive_sha256'], 'published capture receipt matches actual final archive bytes' );
 $account_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE wp_user_id IS NOT NULL ORDER BY id', \Certloom\Infrastructure\Database\SchemaManager::table( 'recipients' ) ), ARRAY_A );
 $account_map = array(); foreach ( $account_rows as $row ) { $account_map[(int) $row['id']] = (int) $row['wp_user_id']; }
 $proof_class = \Certloom\Application\Restore\WholeStateAccountMappingProof::class;
 $check( is_wp_error( $proof_class::review( $whole['archive_path'], str_repeat( '0', 64 ), (int) $actor, $account_map, 'same_installation' ) ), 'account review is bound to the actual archive digest' );
 $incomplete = $account_map; array_pop( $incomplete );
 $check( is_wp_error( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $incomplete, 'same_installation' ) ), 'every linked source account requires explicit review' );
 $proof = wr_ok( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $account_map, 'same_installation' ) );
 $check( $proof->receipt()['linked'] === 2 && $proof->receipt()['provenance_verified'] && true === $proof->assert_current(), 'same-installation account proof checks actual source signature and current exact identities' );
 $row = $account_rows[1]; $projected = wr_ok( $proof->project_recipient( $row ) );
 $check( $proof->permits_source( $row ) && $proof->permits_projected( $projected ) && (int) $projected['wp_user_id'] === (int) $row['wp_user_id'], 'typed proof binds the exact source and projected Recipient without changing its account' );
 $altered = $row; $altered['display_name'] = 'Different recipient';
 $check( is_wp_error( $proof->project_recipient( $altered ) ), 'a reviewed account cannot be adopted by a different Recipient row' );
 $collision = $account_map; foreach ( $collision as &$target ) { $target = (int) $user; } unset( $target );
 $check( is_wp_error( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $collision, 'reviewed_target' ) ), 'two restored recipients cannot receive the same target account' );
 $swapped = $account_map; $ids = array_keys( $swapped ); $swapped[$ids[0]] = (int) $user; $swapped[$ids[1]] = (int) $actor;
 $check( is_wp_error( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $swapped, 'same_installation' ) ), 'same-installation proof refuses a different target identity' );
 $mapped_proof = wr_ok( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $swapped, 'reviewed_target' ) );
 $mapped = wr_ok( $mapped_proof->project_recipient( $row ) );
 $check( (int) $mapped['wp_user_id'] === (int) $actor && $mapped_proof->permits_projected( $mapped ), 'explicit data-tools review supports a different existing target account' );
 $unlinks = array_fill_keys( array_keys( $account_map ), null ); $unlink_proof = wr_ok( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $unlinks, 'same_installation' ) );
 $check( $unlink_proof->receipt()['unlinked'] === 2 && null === wr_ok( $unlink_proof->project_recipient( $row ) )['wp_user_id'], 'explicit unlink retains Recipient facts while granting no account access' );
 $serialized_refused = false; try { serialize( $proof ); } catch ( LogicException $error ) { $serialized_refused = true; }
 $check( $serialized_refused, 'serialized ownership flags cannot recreate typed recovery authority' );
 $old_email = $wpdb->get_var( $wpdb->prepare( 'SELECT user_email FROM %i WHERE ID=%d', $wpdb->users, $user ) );
 $wpdb->update( $wpdb->users, array( 'user_email' => 'changed-review@example.invalid' ), array( 'ID' => $user ) );
 $check( is_wp_error( $proof->assert_current() ), 'current account changes invalidate an already reviewed proof despite warm caches' );
 $wpdb->update( $wpdb->users, array( 'user_email' => $old_email ), array( 'ID' => $user ) );
 $check( true === $proof->assert_current(), 'exact original account evidence restores the unchanged review baseline' );
 wp_set_current_user( $user );
 $check( is_wp_error( $proof->assert_current() ), 'reviewed account authority cannot be used by a different current actor' ); wp_set_current_user( $actor );
 $object_proof_class = \Certloom\Application\Restore\WholeStateObjectMappingProof::class;
 $object_proof = wr_ok( $object_proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, array( (int) $attachment => (int) $attachment ), 'same_installation' ) );
 $check( $object_proof->receipt()['mapped'] === 1 && $object_proof->receipt()['provenance_verified'] && $object_proof->target_for( (int) $attachment, 'attachment' ) === (int) $attachment, 'same-installation object review proves the actual exact source attachment identity' );
 $whole_review = wr_ok( \Certloom\Application\Restore\WholeStateRestoreReview::review( $whole['archive_path'], $whole['archive_sha256'], $proof, $object_proof ) );
 $check( $whole_review->counts()['legacy_registry'] === 5 && $whole_review->counts()['email_deliveries'] === 17 && count( $whole_review->proposal()['fixed'] ) === 31 && true === $whole_review->assert_current(), 'complete typed recovery review validates all twenty-one stores and thirty-one settings together' );
 $check( is_wp_error( $object_proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, array(), 'same_installation' ) ), 'every source object requires an explicit reviewed target or disconnect choice' );
 $check( is_wp_error( $object_proof->target_for( (int) $attachment, 'product' ) ), 'a media mapping cannot be adopted as a product or course connection' );
 $page = wr_ok( wp_insert_post( array( 'post_title' => 'QA Wrong Object Type', 'post_status' => 'publish', 'post_type' => 'page' ), true ) );
 $check( is_wp_error( $object_proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, array( (int) $attachment => (int) $page ), 'reviewed_target' ) ), 'explicit target review still refuses a mismatched current WordPress object type' );
 $alias = wr_ok( wp_insert_attachment( array( 'post_title' => 'QA Explicit Target', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), false, 0, true ) );
 $check( is_wp_error( $object_proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, array( (int) $attachment => (int) $alias ), 'same_installation' ) ), 'same-installation object review refuses an unproven replacement attachment' );
 $explicit_objects = wr_ok( $object_proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, array( (int) $attachment => (int) $alias ), 'reviewed_target' ) );
 $check( $explicit_objects->target_for( (int) $attachment, 'attachment' ) === (int) $alias && true === $explicit_objects->assert_current(), 'explicit cross-target review binds an existing object of the exact source type' );
 $old_name = $wpdb->get_var( $wpdb->prepare( 'SELECT post_name FROM %i WHERE ID=%d', $wpdb->posts, $alias ) ); get_post( $alias );
 $wpdb->update( $wpdb->posts, array( 'post_name' => 'replaced-target-object' ), array( 'ID' => $alias ) );
 $check( is_wp_error( $explicit_objects->assert_current() ), 'warmed object caches cannot conceal a changed target identity after review' );
 $wpdb->update( $wpdb->posts, array( 'post_name' => $old_name ), array( 'ID' => $alias ) );
 $disconnected_objects = wr_ok( $object_proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, array( (int) $attachment => null ), 'same_installation' ) );
 $check( null === $disconnected_objects->target_for( (int) $attachment, 'attachment' ) && $disconnected_objects->receipt()['disconnected'] === 1, 'explicit disconnect retains source evidence without inferring a target from names or URLs' );
 $serialized_refused = false; try { serialize( $object_proof ); } catch ( LogicException $error ) { $serialized_refused = true; }
 $check( $serialized_refused, 'serialized object mappings cannot recreate target recovery authority' );
 $ownership = new \Certloom\Infrastructure\Recipient\RestoredAccountOwnership();
 $check( true === $ownership->assert_current( $projected ), 'reviewed projected Recipient retains a stable current account creation identity' );
 $old_login = $wpdb->get_var( $wpdb->prepare( 'SELECT user_login FROM %i WHERE ID=%d', $wpdb->users, $user ) );
 $wpdb->update( $wpdb->users, array( 'user_login' => 'replacement-account' ), array( 'ID' => $user ) );
 $check( is_wp_error( $ownership->assert_current( $projected ) ), 'reusing a restored numeric account ID cannot inherit the previous Recipient identity' );
 $wpdb->update( $wpdb->users, array( 'user_login' => $old_login ), array( 'ID' => $user ) );
 $wpdb->update( $wpdb->users, array( 'user_email' => 'own-profile-change@example.invalid' ), array( 'ID' => $user ) );
 $check( true === $ownership->assert_current( $projected ), 'legitimate profile email changes retain the same restored account ownership' );
 $wpdb->update( $wpdb->users, array( 'user_email' => $old_email ), array( 'ID' => $user ) );
 // Publish actual archived bytes into an isolated private fallback root.
 $asset_directory = $directory . '/asset-private'; $asset_filter = static function () use ( $asset_directory ) { return $asset_directory; };
 add_filter( 'certloom_private_job_directory', $asset_filter, PHP_INT_MAX );
 $asset_owner = bin2hex( random_bytes( 16 ) ); $maintenance = new \Certloom\Infrastructure\Data\DomainMaintenanceLock();
 $publication = new \Certloom\Infrastructure\Restore\WholeStateAssetPublication();
 wr_ok( $maintenance->acquire( $asset_owner, 'whole_asset_recovery', 120 ) );
 $asset_journal = wr_ok( $publication->start( $whole['archive_path'], $whole['archive_sha256'], $asset_owner ) );
 $check( $asset_journal['cursor'] === 0 && $asset_journal['assets'][0]['created'] && $publication->pending_owner() === $asset_owner, 'sealed asset journal records exact owned destinations before publication' );
 $check( is_wp_error( $maintenance->release( $asset_owner ) ), 'maintenance cannot release access while the asset journal is unresolved' );
 $asset_faults = 0; $asset_fault = static function ( $sql ) use ( &$asset_faults ) { if ( $asset_faults === 0 && strpos( $sql, 'UPDATE ' ) === 0 && strpos( $sql, 'certloom_whole_asset_publication' ) !== false ) { ++$asset_faults; return 'SELECT certloom_qa_missing_asset_cursor_column'; } return $sql; };
 $old_suppression = $wpdb->suppress_errors( true ); add_filter( 'query', $asset_fault, PHP_INT_MAX );
 try { $failed_asset_cursor = $publication->continue_publication( $asset_owner ); }
 finally { remove_filter( 'query', $asset_fault, PHP_INT_MAX ); $wpdb->suppress_errors( $old_suppression ); }
 $fallback = wr_ok( ( new WholeStateAssetRepository() )->recovered_path( $asset['sha256'] ) );
 $check( $asset_faults === 1 && is_wp_error( $failed_asset_cursor ) && hash_file( 'sha256', $fallback ) === $asset['sha256'], 'actual cursor SQL failure retains one complete atomically published image' );
 $asset_journal = wr_ok( $publication->continue_publication( $asset_owner ) );
 $check( $asset_journal['phase'] === 'published' && $asset_journal['cursor'] === 1 && count( glob( $asset_directory . '/recovery-assets/*' ) ) === 1, 'publication retry adopts the exact image without creating or replacing another copy' );
 file_put_contents( $image, $bytes . 'changed-current' );
 $recovered = wr_ok( ( new WholeStateAssetRepository() )->image( 'attachment:' . $attachment, $descriptor ) );
 $check( $recovered['path'] === realpath( $fallback ) || $recovered['path'] === $fallback, 'changed ordinary attachment uses exact historical fallback bytes for the issued descriptor' );
 $snapshot = json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot_json FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), $receipt['version_id'] ) ), true );
 $preview_method = new ReflectionMethod( 'Ecapla_CertVerify_Render', 'normalized_certificate_preview_html' );
 $html = $preview_method->invoke( null, $snapshot );
 $check( strpos( $html, 'data:image/png;base64,' . base64_encode( $bytes ) ) !== false && strpos( $html, $asset_directory ) === false, 'actual issued certificate preview embeds verified recovered bytes without revealing private paths' );
 file_put_contents( $image, $bytes );
 file_put_contents( $fallback, $bytes . 'changed-fallback' );
 $check( is_wp_error( $publication->rollback( $asset_owner ) ) && is_file( $fallback ), 'rollback refuses to delete fallback bytes changed after publication' );
 file_put_contents( $fallback, $bytes );
 $sealed_row = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, \Certloom\Infrastructure\Restore\WholeStateAssetPublication::OPTION ) );
 $bad = maybe_serialize( array( 'owner_token' => $asset_owner, 'sealed' => 'v1:gcm:invalid' ) );
 $wpdb->update( $wpdb->options, array( 'option_value' => $bad ), array( 'option_name' => \Certloom\Infrastructure\Restore\WholeStateAssetPublication::OPTION ) );
 $check( is_wp_error( $publication->rollback( $asset_owner ) ) && is_file( $fallback ), 'forged publication journal cannot delete captured bytes' );
 $wpdb->update( $wpdb->options, array( 'option_value' => $sealed_row ), array( 'option_name' => \Certloom\Infrastructure\Restore\WholeStateAssetPublication::OPTION ) );
 wr_ok( $publication->rollback( $asset_owner ) ); wr_ok( $maintenance->release( $asset_owner ) );
 $check( null === $publication->pending_owner() && ! is_file( $fallback ), 'resumed rollback clears its owned byte and sealed journal before releasing maintenance' );
 mkdir( $asset_directory . '/recovery-assets', 0700 ); copy( $image, $fallback );
 wr_ok( $maintenance->acquire( $asset_owner, 'whole_asset_recovery', 120 ) );
 $asset_journal = wr_ok( $publication->start( $whole['archive_path'], $whole['archive_sha256'], $asset_owner ) );
 $check( ! $asset_journal['assets'][0]['created'], 'pre-existing identical fallback bytes are recorded as unowned by this restore' );
 wr_ok( $publication->continue_publication( $asset_owner ) ); wr_ok( $publication->rollback( $asset_owner ) ); wr_ok( $maintenance->release( $asset_owner ) );
 $check( is_file( $fallback ) && hash_file( 'sha256', $fallback ) === $asset['sha256'], 'rollback preserves identical bytes that predated the recovery job' );
 unlink( $fallback ); rmdir( $asset_directory . '/recovery-assets' );
 foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $sentinel ) { if ( is_file( $asset_directory . '/' . $sentinel ) ) { unlink( $asset_directory . '/' . $sentinel ); } }
 $check( rmdir( $asset_directory ), 'isolated asset publication files and directory are fully removed' );
 remove_filter( 'certloom_private_job_directory', $asset_filter, PHP_INT_MAX );
 // Use real job rows and native worker transitions for both purposes.
 $catalog_directory = $directory . '/backups';
 $catalog_filter = static function () use ( $catalog_directory ) { return $catalog_directory; };
 add_filter( 'certloom_private_backup_directory', $catalog_filter, PHP_INT_MAX );
 $runtime = new \Certloom\Infrastructure\Job\JobRuntime();
 $coordinator = new \Certloom\Application\Backup\WholeStateBackupCoordinator( $runtime->queue() );
 $handler = new \Certloom\Application\Backup\WholeStateBackupJobHandler();
 $runner = new \Certloom\Application\Job\JobRunner( $runtime->service(), $runtime->repository(), array( \Certloom\Domain\Job\JobTypes::WHOLE_BACKUP => $handler ) );
 $store = new \Certloom\Infrastructure\Backup\LocalBackupStore();
 foreach ( array( 'manual', 'scheduled' ) as $purpose ) {
  if ( 'scheduled' === $purpose ) {
   wr_ok( \Certloom\Infrastructure\Backup\ScheduledBackup::save_settings( 'daily_whole', 14 ) );
   $check( 'daily_whole' === \Certloom\Infrastructure\Backup\ScheduledBackup::settings()['schedule'] && 'daily' === wp_get_scheduled_event( \Certloom\Infrastructure\Backup\ScheduledBackup::HOOK )->schedule, 'native recovery schedule keeps the complete scope while using the standard daily WordPress recurrence' );
   $key = wr_ok( \Certloom\Infrastructure\Backup\ScheduledBackup::run_scheduled() );
  } else { $key = wr_ok( $coordinator->enqueue( $purpose, (int) $actor ) ); }
  $whole_job_keys[] = $key;
  $check( $runtime->repository()->find( $key )['payload']['purpose'] === $purpose, $purpose . ' capture uses the shared production job contract' );
  if ( 'manual' === $purpose ) {
   $claimed = wr_ok( $runtime->service()->begin( $key, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 120 ) );
   $check( is_wp_error( $handler->handle( $claimed, 'cccccccccccccccccccccccccccccccc' ) ), 'different worker token cannot create capture files or publish an archive' );
   $wpdb->update( \Certloom\Infrastructure\Database\SchemaManager::table( 'jobs' ), array( 'locked_until' => '2000-01-01 00:00:00' ), array( 'job_key' => $key ) );
   $check( is_wp_error( $handler->handle( $claimed, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ) ), 'expired worker lease cannot start or resume capture' );
   $wpdb->update( \Certloom\Infrastructure\Database\SchemaManager::table( 'jobs' ), array( 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 120 ) ), array( 'job_key' => $key ) );
   wr_ok( $runtime->service()->yield_work( $key, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ) );
  }
  $publication_replayed = false; $max_checkpoints = 0;
  for ( $slice = 0; $slice < 2000; ++$slice ) {
   $job = wr_ok( $runtime->repository()->find( $key ) );
   if ( $job['status'] === 'succeeded' ) { break; }
   if ( ! $publication_replayed && $job['phase'] === 'publish' ) {
    $claimed = wr_ok( $runtime->service()->begin( $key, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 120 ) );
    if ( 'manual' === $purpose ) {
     $faults = 0; $fault = static function ( $sql ) use ( &$faults ) { if ( 'COMMIT' === $sql && $faults === 0 ) { ++$faults; return 'SELECT certloom_qa_missing_commit_column'; } return $sql; };
     $old_suppression = $wpdb->suppress_errors( true ); add_filter( 'query', $fault, PHP_INT_MAX );
     try { $failed_publication = $handler->handle( $claimed, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ); }
     finally { remove_filter( 'query', $fault, PHP_INT_MAX ); $wpdb->suppress_errors( $old_suppression ); }
     $check( $faults === 1 && is_wp_error( $failed_publication ) && count( $store->list_backups() ) === 0, 'actual publication COMMIT failure rolls back the catalog without claiming success' );
     $leftover = glob( $catalog_directory . '/' . $key . '-manual-*.cvrecovery' );
     $check( count( $leftover ) === 1, 'interrupted catalog commit retains one exact deterministic private copy for verified adoption' );
    }
    $first_publication = wr_ok( $handler->handle( $claimed, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ) );
    if ( 'manual' === $purpose ) { $check( $store->resolve( $first_publication->result()['backup_id'] )['path'] === $leftover[0] && count( glob( $catalog_directory . '/' . $key . '-manual-*.cvrecovery' ) ) === 1, 'retry adopts the exact verified bytes after a real catalog rollback' ); }
    // Deliberately omit progress save: actual stored bytes/catalog must replay.
    $second_publication = wr_ok( $handler->handle( $claimed, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ) );
    $check( $first_publication->result()['backup_id'] === $second_publication->result()['backup_id'] && count( $store->list_backups() ) === count( $whole_job_keys ), $purpose . ' commit-before-cursor replay preserves one verified catalog copy' );
    wr_ok( $runtime->service()->progress( $key, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $second_publication->cursor(), $second_publication->phase(), $second_publication->result() ) );
    wr_ok( $runtime->service()->yield_work( $key, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ) ); $publication_replayed = true;
   } else { $run = wr_ok( $runner->run( $key, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 120 ) ); $check_failed = $run['status'] === 'failed'; if ( $check_failed ) { throw new RuntimeException( 'Whole worker failed: ' . ( $run['error']['code'] ?? 'unknown' ) ); } }
   $private_root = wr_ok( ( new PrivateJobFileStore() )->directory() ); $count = 0;
   foreach ( new DirectoryIterator( $private_root ) as $file ) { if ( preg_match( '/^' . $key . '-whole-checkpoint-[a-f0-9]{48}\.data$/D', $file->getFilename() ) ) { ++$count; } } $max_checkpoints = max( $max_checkpoints, $count );
  }
  $job = wr_ok( $runtime->repository()->find( $key ) );
  $check( $job['status'] === 'succeeded' && $job['result']['working_files_removed'], $purpose . ' capture finishes through actual job progress and owned-file cleanup' );
  $check( $max_checkpoints <= 2, $purpose . ' worker retains at most the committed and next checkpoint' );
  $entry = wr_ok( $store->resolve( $job['result']['backup_id'] ) );
  $check( $entry['scope'] === 'whole_state' && $entry['purpose'] === $purpose && $entry['export_id'] === $key && hash_file( 'sha256', $entry['path'] ) === $job['result']['file_sha256'], $purpose . ' completed receipt resolves to the actual signed archive bytes' );
 }
 $check( count( $store->list_backups() ) === 2, 'manual and scheduled verified copies remain distinct durable catalog entries' );
 $native_catalog = get_option( LocalBackupStore::CATALOG_OPTION );
 $check( $native_catalog === wr_ok( $store->validate_recovery_value( $native_catalog ) ), 'actual complete capture catalog validates its native file-hash receipts without a fictitious legacy content hash' );
 $bad_catalog = $native_catalog; $bad_catalog[0]['content_sha256'] = 'unverified';
 $check( is_wp_error( $store->validate_recovery_value( $bad_catalog ) ), 'complete capture receipt cannot introduce a malformed optional content hash' );
 $bad_catalog = $native_catalog; $bad_catalog[0]['file_sha256'] = '';
 $check( is_wp_error( $store->validate_recovery_value( $bad_catalog ) ), 'complete capture catalog still requires its actual archive file hash' );
 update_option( 'certloom_safety_backup_retention', 3, false );
 $safety_ids = array();
 for ( $safety_index = 0; $safety_index < 4; ++$safety_index ) {
  $safety_key = wr_ok( $coordinator->enqueue( 'safety', (int) $actor ) ); $whole_job_keys[] = $safety_key;
  for ( $slice = 0; $slice < 2000; ++$slice ) { $safety_job = wr_ok( $runner->run( $safety_key, 'ffffffffffffffffffffffffffffffff', 120 ) ); if ( 'succeeded' === $safety_job['status'] ) { break; } if ( 'failed' === $safety_job['status'] ) { throw new RuntimeException( $safety_job['error']['code'] ?? 'safety_capture_failed' ); } }
  $check( 'succeeded' === $safety_job['status'] && $safety_job['result']['working_files_removed'], 'native safety capture ' . $safety_index . ' finishes through the same guarded worker' ); $safety_ids[] = $safety_job['result']['backup_id'];
 }
 $catalog_after_safety = $store->list_backups(); $purposes = array_count_values( array_column( $catalog_after_safety, 'purpose' ) );
 $check( ( $purposes['safety'] ?? 0 ) === 3 && ( $purposes['manual'] ?? 0 ) === 1 && ( $purposes['scheduled'] ?? 0 ) === 1, 'automatic safety retention removes only excess safety copies while preserving manual and scheduled snapshots' );
 $check( count( array_intersect( $safety_ids, array_column( $catalog_after_safety, 'id' ) ) ) === 3, 'retention leaves three actual owned safety receipts rather than only changing counts' );
 wr_ok( \Certloom\Infrastructure\Backup\ScheduledBackup::save_settings( 'weekly_whole', 14 ) );
 $check( 'certloom_weekly' === wp_get_scheduled_event( \Certloom\Infrastructure\Backup\ScheduledBackup::HOOK )->schedule, 'native weekly recovery schedule preserves its scope without introducing a new option or archive format' );
 wr_ok( \Certloom\Infrastructure\Backup\ScheduledBackup::save_settings( 'off', 14 ) );
 foreach ( $store->list_backups() as $entry ) { wr_ok( $store->delete( $entry['id'] ) ); }
 $check( count( $store->list_backups() ) === 0, 'checked catalog deletion removes only the selected owned copies' );
 // Real DDL promotion uses the reviewed source rows and persistent ownership
 // stamp. The default credential-only path still rejects linked archives.
 $domain_restore_path = wr_ok( ( new PrivateJobFileStore() )->create_path( bin2hex( random_bytes( 16 ) ), 'whole-reviewed-domain' ) );
 $zip = new ZipArchive(); wr_ok( true === $zip->open( $whole['archive_path'], ZipArchive::RDONLY ) ? true : new WP_Error( 'qa_zip_open' ) );
 $restore_bytes = $zip->getFromName( 'data/domain.cvbackup' ); $zip->close();
 $check( is_string( $restore_bytes ) && strlen( $restore_bytes ) === file_put_contents( $domain_restore_path, $restore_bytes, LOCK_EX ), 'actual authenticated domain member copied into an owned staging file' ); $owned_files[$domain_restore_path] = true;
 $proof = wr_ok( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $account_map, 'same_installation' ) );
 $owner = bin2hex( random_bytes( 16 ) ); $sandbox->register_domain_workspace( $owner );
 $workspace = new \Certloom\Infrastructure\Restore\DomainRestoreWorkspace( null, null, null, $proof );
 $stage = new \Certloom\Application\Restore\DomainRestoreStagingService( $workspace, null, null, null, $proof );
 $state = wr_ok( $stage->start( $domain_restore_path, $owner ) );
 for ( $slice = 0; $slice < 500 && ! $state['complete']; ++$slice ) { $state = wr_ok( $stage->continue_stage( $state, 2 ) ); }
 $check( $state['complete'], 'reviewed source accounts cross actual bounded domain staging and graph validation' );
 $old_version = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), $receipt['version_id'] ), ARRAY_A );
 wr_ok( $stage->commit_replace( $state ) );
 $check( true === $stage->commit_replace( $state ), 'commit-before-cursor retries use the actual completion receipt without requiring an obsolete generation' );
 $restored_recipient = wr_ok( ( new \Certloom\Infrastructure\Repository\RecipientRepository() )->find_by_wp_user_id( $user ) );
 $check( isset( $restored_recipient['custom_fields'][\Certloom\Application\Recipient\RecipientAccountLink::KEY]['restored_account_identity_sha256'] ), 'actual promoted Recipient stores the reviewed account ownership stamp' );
 $collection = new \Certloom\Application\Recipient\RecipientCollectionService(); $view = wr_ok( $collection->read_for_user( $user ) );
 $check( $view['linked'] && ! is_wp_error( $collection->save_for_user( $user, 0, $view['revision'], array() ) ), 'real recipient profile read and save retain reviewed account access' );
 $provider = new \Certloom\Infrastructure\Recipient\CollectedFieldValueProviderFactory();
 $check( true === ( new \Certloom\Infrastructure\Database\Transaction() )->run( static function () use ( $provider, $recipient, $program ) { return $provider->lock_context( $recipient['id'], $program, 0 ); } ), 'canonical field context verifies the restored account under real current locks' );
 $wpdb->update( $wpdb->users, array( 'user_login' => 'replacement-account' ), array( 'ID' => $user ) );
 $check( is_wp_error( ( new \Certloom\Infrastructure\Repository\RecipientRepository() )->find_by_wp_user_id( $user ) ) && is_wp_error( $collection->read_for_user( $user ) ) && is_wp_error( $collection->save_for_user( $user, 0, $view['revision'], array() ) ), 'actual repository and recipient profile deny access after numeric account reuse despite warm caches' );
 $check( is_wp_error( ( new \Certloom\Infrastructure\Database\Transaction() )->run( static function () use ( $provider, $recipient, $program ) { return $provider->lock_context( $recipient['id'], $program, 0 ); } ) ), 'canonical issuance refuses a reused restored account under real SQL locks' );
 $wpdb->update( $wpdb->users, array( 'user_login' => $old_login, 'user_email' => 'own-profile-change@example.invalid' ), array( 'ID' => $user ) );
 $check( wr_ok( $collection->read_for_user( $user ) )['linked'], 'actual recipient profile remains available after a legitimate email edit' );
 $wpdb->update( $wpdb->users, array( 'user_email' => $old_email ), array( 'ID' => $user ) );
 $new_version = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), $receipt['version_id'] ), ARRAY_A );
 $check( $old_version === $new_version, 'actual account-aware promotion preserves every issued Version and immutable snapshot byte' );
 $unlink_proof = wr_ok( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $unlinks, 'same_installation' ) );
 $unlink_owner = bin2hex( random_bytes( 16 ) ); $sandbox->register_domain_workspace( $unlink_owner );
 $unlink_workspace = new \Certloom\Infrastructure\Restore\DomainRestoreWorkspace( null, null, null, $unlink_proof );
 $unlink_stage = new \Certloom\Application\Restore\DomainRestoreStagingService( $unlink_workspace, null, null, null, $unlink_proof );
 $unlink_state = wr_ok( $unlink_stage->start( $domain_restore_path, $unlink_owner ) );
 for ( $slice = 0; $slice < 500 && ! $unlink_state['complete']; ++$slice ) { $unlink_state = wr_ok( $unlink_stage->continue_stage( $unlink_state, 2 ) ); }
 wr_ok( \Certloom\Infrastructure\Data\DomainMaintenanceLock::run_internal( static function () { return ( new \Certloom\Infrastructure\Data\DomainGeneration() )->mark_before_mutation(); } ) );
 $check( is_wp_error( $unlink_stage->commit_replace( $unlink_state ) ), 'explicitly unlinked staging still refuses promotion after the reviewed target generation changes' );
 wr_ok( $unlink_workspace->abandon( $unlink_state['workspace'] ) );
 $check( ! ( new \Certloom\Infrastructure\Data\DomainMaintenanceLock() )->active(), 'abandoned stale reviewed workspace clears its exact tables and maintenance evidence' );
 // One real replacement boundary covers registry, domain, delivery evidence,
 // raw option presence/autoload and private files. Ordinary WP data is excluded.
 $complete_workspace = new \Certloom\Infrastructure\Restore\WholeStateRestoreWorkspace();
 $complete_owner = bin2hex( random_bytes( 16 ) ); $sandbox->register_domain_workspace( $complete_owner, true );
 $complete_review = static function () use ( $whole, $actor, $account_map, $attachment, $proof_class, $object_proof_class ) {
  return wr_ok( \Certloom\Application\Restore\WholeStateRestoreReview::review( $whole['archive_path'], $whole['archive_sha256'], wr_ok( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $account_map, 'same_installation' ) ), wr_ok( $object_proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, array( (int) $attachment => (int) $attachment ), 'same_installation' ) ) ) );
 };
 $complete_fingerprint = static function () use ( $wpdb ) {
  $data = array(); foreach ( \Certloom\Infrastructure\Restore\WholeStateRestoreWorkspace::entities() as $entity ) { $table = 'legacy_registry' === $entity ? Ecapla_CertVerify_Database::table_name() : \Certloom\Infrastructure\Database\SchemaManager::table( $entity ); $wpdb->last_error = ''; $data[$entity] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ), ARRAY_A ); if ( $wpdb->last_error ) { throw new RuntimeException( 'Whole target fingerprint read failed.' ); } }
  $data['settings'] = wr_ok( ( new \Certloom\Infrastructure\Restore\WholeStateOptionPublication() )->snapshot() );
  foreach ( array( \Certloom\Infrastructure\Data\DomainGeneration::OPTION, Ecapla_CertVerify_Backup::GENERATION_OPTION ) as $name ) { $data[$name] = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value,autoload FROM %i WHERE option_name=%s', $wpdb->options, $name ), ARRAY_A ); }
  return ( new \Certloom\Application\Rendering\CanonicalJson() )->hash( $data );
 };
 // A record may reach staging before its cursor commit. The exact same slice
 // must adopt it, while stale journals and different owners remain rejected.
 $reviewed = $complete_review(); $before_complete = $complete_fingerprint();
 $initial_stage = wr_ok( $complete_workspace->start( $reviewed, $complete_owner ) );
 $check( true === $complete_workspace->recover() && $initial_stage['revision'] === wr_ok( ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read() )['revision'], 'boot recovery leaves an active owned staging lease untouched' );
 $cursor_faulted = false;
 $cursor_fault = static function ( $sql ) use ( &$cursor_faulted, $wpdb ) {
  if ( ! $cursor_faulted && 0 === strpos( $sql, 'UPDATE `' . $wpdb->options . '`' ) && false !== strpos( $sql, "'certloom_whole_restore_journal'" ) ) { $cursor_faulted = true; return 'SELECT * FROM certloom_qa_missing_whole_cursor_fault'; }
  return $sql;
 };
 $old_suppression = $wpdb->suppress_errors( true ); add_filter( 'query', $cursor_fault, PHP_INT_MAX );
 try { $failed_cursor = $complete_workspace->continue_stage( $complete_owner, $reviewed, 5 ); } finally { remove_filter( 'query', $cursor_fault, PHP_INT_MAX ); $wpdb->suppress_errors( $old_suppression ); }
 $check( $cursor_faulted && is_wp_error( $failed_cursor ) && $initial_stage['revision'] === wr_ok( ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read() )['revision'], 'actual cursor SQL failure retains its prior sealed progress despite inserted staging rows' );
 $replayed_stage = wr_ok( $complete_workspace->continue_stage( $complete_owner, $reviewed, 5 ) );
 $check( $replayed_stage['revision'] === $initial_stage['revision'] + 1 && array_sum( $replayed_stage['counts'] ) > 0, 'cursor retry adopts exact staged records without creating duplicates' );
 $check( is_wp_error( ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->write( $initial_stage ) ) && is_wp_error( $complete_workspace->continue_stage( bin2hex( random_bytes( 16 ) ), $reviewed ) ), 'stale journal revisions and another owner cannot advance the restore' );
 $check( 1 === $wpdb->delete( $wpdb->options, array( 'option_name' => \Certloom\Infrastructure\Data\DomainMaintenanceLock::OPTION ) ), 'disposable interrupted-worker fixture removes only its own maintenance lease' );
 \Certloom\Infrastructure\Data\DatabaseOptions::invalidate( array( \Certloom\Infrastructure\Data\DomainMaintenanceLock::OPTION ) );
 $check( $maintenance->active() && is_wp_error( $maintenance->acquire( bin2hex( random_bytes( 16 ) ), 'qa-other-owner' ) ), 'sealed pending recovery still fences writes and a competing owner after lease loss' );
 $check( is_wp_error( $runtime->queue()->enqueue( \Certloom\Domain\Job\JobTypes::PENDING_REASSESSMENT, $actor, array() ) ), 'native job creation cannot enqueue new issuance work inside pending recovery even without a lease' );
 for ( $cleanup_slice = 0; $cleanup_slice < 100 && ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(); ++$cleanup_slice ) { wr_ok( $complete_workspace->recover() ); }
 $check( $before_complete === $complete_fingerprint() && ! $maintenance->active(), 'interrupted staging recovers only the durable local journal and restores the original state' );
 foreach ( array( 'rename', 'configuration', 'commit', 'rollback_cleanup' ) as $fault_point ) {
  $reviewed = $complete_review(); $before_complete = $complete_fingerprint();
  $complete_state = wr_ok( $complete_workspace->start( $reviewed, $complete_owner ) );
  for ( $slice = 0; $slice < 500 && 'ready' !== $complete_state['phase']; ++$slice ) { $complete_state = wr_ok( $complete_workspace->continue_stage( $complete_owner, $reviewed, 50 ) ); }
  $check( 'ready' === $complete_state['phase'] && array_sum( $complete_state['counts'] ) === array_sum( $reviewed->counts() ), $fault_point . ': all twenty-one stores finish bounded staging before promotion' );
  $fault_fired = false; $cleanup_faulted = false; $cleanup_drops = 0;
  $fault = static function ( $sql ) use ( $fault_point, &$fault_fired, &$cleanup_faulted, &$cleanup_drops, $wpdb, $complete_owner ) {
   if ( 'rollback_cleanup' === $fault_point && $fault_fired && ! $cleanup_faulted && 0 === strpos( $sql, 'DROP TABLE IF EXISTS ' ) && false !== strpos( $sql, substr( $complete_owner, 0, 12 ) ) && ++$cleanup_drops === 4 ) { $cleanup_faulted = true; return 'SELECT * FROM certloom_qa_missing_whole_cleanup_fault'; }
   if ( $fault_fired ) { return $sql; } $match = false;
   if ( 'rename' === $fault_point ) { $match = 0 === strpos( $sql, 'RENAME TABLE ' ) && false !== strpos( $sql, substr( $complete_owner, 0, 12 ) ); }
   elseif ( 'configuration' === $fault_point ) { $match = 0 === strpos( $sql, 'UPDATE `' . $wpdb->options . '`' ) && false !== strpos( $sql, "'certverify_settings'" ); }
   elseif ( 'COMMIT' === trim( $sql ) ) { $journal = ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(); $match = is_array( $journal ) && 'committed' === $journal['phase']; }
   if ( $match ) { $fault_fired = true; return 'SELECT * FROM certloom_qa_missing_whole_restore_fault'; } return $sql;
  };
  $old_suppression = $wpdb->suppress_errors( true ); add_filter( 'query', $fault, PHP_INT_MAX );
  try { $failed_commit = $complete_workspace->commit( $complete_owner, $reviewed ); } finally { remove_filter( 'query', $fault, PHP_INT_MAX ); $wpdb->suppress_errors( $old_suppression ); }
  $check( $fault_fired && is_wp_error( $failed_commit ), $fault_point . ': real SQL failure cannot publish a successful complete restore' );
  if ( 'rollback_cleanup' === $fault_point ) { $check( $cleanup_faulted && 'rolled_back' === wr_ok( ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read() )['phase'], 'cleanup SQL failure retains durable rollback progress after some aliases have been dropped' ); }
  for ( $cleanup_slice = 0; $cleanup_slice < 100 && ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(); ++$cleanup_slice ) { wr_ok( $complete_workspace->rollback( $complete_owner ) ); }
  $check( $before_complete === $complete_fingerprint() && ! $maintenance->active() && null === ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(), $fault_point . ': all tables, exact raw settings and both generations recover before releasing maintenance' );
 }
 $reviewed = $complete_review(); wr_ok( $complete_workspace->start( $reviewed, $complete_owner ) );
 for ( $slice = 0; $slice < 500; ++$slice ) { $complete_state = wr_ok( $complete_workspace->continue_stage( $complete_owner, $reviewed, 50 ) ); if ( 'ready' === $complete_state['phase'] ) { break; } }
 $committed_cleanup_fault = false; $committed_drops = 0; $rename_count = 0;
 $committed_fault = static function ( $sql ) use ( &$committed_cleanup_fault, &$committed_drops, &$rename_count, $complete_owner ) {
  if ( 0 === strpos( $sql, 'RENAME TABLE ' ) && false !== strpos( $sql, substr( $complete_owner, 0, 12 ) ) ) { ++$rename_count; }
  if ( ! $committed_cleanup_fault && 0 === strpos( $sql, 'DROP TABLE IF EXISTS ' ) && false !== strpos( $sql, substr( $complete_owner, 0, 12 ) ) && ++$committed_drops === 4 ) { $committed_cleanup_fault = true; return 'SELECT * FROM certloom_qa_missing_committed_cleanup_fault'; }
  return $sql;
 };
 $old_suppression = $wpdb->suppress_errors( true ); add_filter( 'query', $committed_fault, PHP_INT_MAX );
 try { $interrupted_commit = $complete_workspace->commit( $complete_owner, $reviewed ); } finally { remove_filter( 'query', $committed_fault, PHP_INT_MAX ); $wpdb->suppress_errors( $old_suppression ); }
 $check( $committed_cleanup_fault && is_wp_error( $interrupted_commit ) && 'committed' === wr_ok( ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read() )['phase'] && $maintenance->active(), 'committed cleanup failure retains the durable receipt and write fence after partial alias removal' );
 $release_faulted = false;
 $release_fault = static function ( $sql ) use ( &$release_faulted, $wpdb ) {
  if ( ! $release_faulted && 0 === strpos( $sql, 'DELETE FROM ' ) && false !== strpos( $sql, $wpdb->options ) && false !== strpos( $sql, "'" . \Certloom\Infrastructure\Data\DomainMaintenanceLock::OPTION . "'" ) ) { $release_faulted = true; return 'SELECT * FROM certloom_qa_missing_release_fault'; }
  return $sql;
 };
 $old_suppression = $wpdb->suppress_errors( true ); add_filter( 'query', $release_fault, PHP_INT_MAX );
 try {
  for ( $cleanup_slice = 0; $cleanup_slice < 100 && ! $release_faulted; ++$cleanup_slice ) { $release_result = $complete_workspace->recover(); if ( is_wp_error( $release_result ) ) { break; } }
 } finally { remove_filter( 'query', $release_fault, PHP_INT_MAX ); $wpdb->suppress_errors( $old_suppression ); }
 $check( $release_faulted && is_wp_error( $release_result ) && 'committed' === wr_ok( ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read() )['phase'] && $maintenance->active(), 'lease release SQL failure rolls back journal removal so terminal recovery remains discoverable' );
 for ( $cleanup_slice = 0; $cleanup_slice < 100 && ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(); ++$cleanup_slice ) { wr_ok( $complete_workspace->recover() ); }
 $complete_result = wr_ok( $complete_workspace->commit( $complete_owner, $reviewed ) );
 for ( $cleanup_slice = 0; $cleanup_slice < 100 && empty( $complete_result['complete'] ); ++$cleanup_slice ) { $complete_result = wr_ok( $complete_workspace->commit( $complete_owner, $reviewed ) ); }
 $check( ! empty( $complete_result['complete'] ) && count( $complete_result['receipt']['counts'] ) === 21 && true === $complete_workspace->receipt( $complete_owner )['records'] > 0, 'actual complete SQL and asset commit finishes with one durable twenty-one-store receipt' );
 $check( ! $maintenance->active() && null === ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(), 'complete commit removes private rollback work before reopening certificate writes' );
 $recovered_hook = ( new \Certloom\Infrastructure\Integration\WebhookSourceRepository() )->find( $hook['key'] );
 $check( is_array( $recovered_hook ) && ! $recovered_hook['active'] && (int) $recovered_hook['program_id'] === $program && ! is_wp_error( ( new \Certloom\Infrastructure\Integration\WebhookSourceRepository() )->secret( $hook['key'] ) ), 'same-source restore retains the sealed v1 credential and its real Program link but pauses automatic issuance' );
 foreach ( array( new \Certloom\Infrastructure\Integration\CourseraMappingStore(), new \Certloom\Infrastructure\Integration\CredlyMappingStore() ) as $mapping_store ) { $mapped_rows = get_option( $mapping_store::OPTION ); $check( $mapped_rows === wr_ok( $mapping_store->validate_recovery_value( $mapped_rows ) ) && ! reset( $mapped_rows['mappings'] )['active'], $mapping_store::OPTION . ': complete restore preserves its native indexed identities and pauses actual nonempty mappings' ); }
 $check( ( new \Certloom\Infrastructure\Email\EmailDesignAssignmentRepository() )->get( 'program', $program, \Certloom\Infrastructure\Email\EmailDesignPurposes::ISSUED )['design_id'] === 'default', 'nonempty email assignment restores against its actual source Program and synthesized native design' );
 $check( 1 === $rename_count && wr_ok( $complete_workspace->start( $reviewed, $complete_owner ) )['receipt'] === $complete_result['receipt'] && wr_ok( $complete_workspace->commit( $complete_owner, $reviewed ) ) === $complete_result, 'completed start and commit replay the same durable receipt after the reviewed generation has advanced' );
 $after_whole_version = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), $receipt['version_id'] ), ARRAY_A );
 $check( $old_version === $after_whole_version && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='paused' AND job_key IS NULL", \Certloom\Infrastructure\Database\SchemaManager::table( 'email_deliveries' ) ) ) === 17, 'complete restore preserves every immutable Version byte and holds archived email work without recreating jobs' );
 // Simulate a genuinely different recovery root and explicit destination
 // accounts/media in disposable WP storage. No account or role is imported.
 $fresh_user = wr_ok( wp_insert_user( array( 'user_login' => 'qa-fresh-recipient', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) ) );
 $check( update_attached_file( $alias, wp_slash( str_replace( '/', DIRECTORY_SEPARATOR, realpath( $image ) ) ) ), 'explicit fresh target attachment contains the exact archived image bytes' );
 $old_revision = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE template_id=%d ORDER BY id LIMIT 1', \Certloom\Infrastructure\Database\SchemaManager::table( 'template_revisions' ), $media_template ), ARRAY_A );
 foreach ( array( \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository::OPTION, \Certloom\Infrastructure\Restore\WholeStateRestoreWorkspace::RECEIPTS ) as $local_only_option ) { $check( 1 === $wpdb->delete( $wpdb->options, array( 'option_name' => $local_only_option ) ), 'owned fresh-site fixture removes its prior local-only root or receipt' ); \Certloom\Infrastructure\Data\DatabaseOptions::invalidate( array( $local_only_option ) ); }
 wr_ok( ( new \Certloom\Infrastructure\Backup\WholeStateProvenanceRepository() )->ensure( $actor ) );
 $fresh_accounts = array_fill_keys( array_keys( $account_map ), null ); $fresh_accounts[(int) $recipient['id']] = (int) $fresh_user;
 $fresh_account_proof = wr_ok( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $fresh_accounts, 'reviewed_target' ) );
 $fresh_object_proof = wr_ok( $object_proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, array( (int) $attachment => (int) $alias ), 'reviewed_target' ) );
 $fresh_review = wr_ok( \Certloom\Application\Restore\WholeStateRestoreReview::review( $whole['archive_path'], $whole['archive_sha256'], $fresh_account_proof, $fresh_object_proof ) );
 $check( ! $fresh_object_proof->receipt()['provenance_verified'] && ! $fresh_account_proof->receipt()['provenance_verified'], 'different recovery root requires actual explicit foreign target review' );
 $check( ! $fresh_review->proposal()['fixed']['certloom_webhook_sources_v1']['exists'], 'foreign review disconnects actual nonempty v1 signing credentials before promotion' );
 $fresh_owner = bin2hex( random_bytes( 16 ) ); $sandbox->register_domain_workspace( $fresh_owner, true );
 wr_ok( $complete_workspace->start( $fresh_review, $fresh_owner ) );
 for ( $slice = 0; $slice < 500; ++$slice ) { $fresh_state = wr_ok( $complete_workspace->continue_stage( $fresh_owner, $fresh_review, 50 ) ); if ( 'ready' === $fresh_state['phase'] ) { break; } }
 $fresh_result = wr_ok( $complete_workspace->commit( $fresh_owner, $fresh_review ) );
 for ( $cleanup_slice = 0; $cleanup_slice < 100 && empty( $fresh_result['complete'] ); ++$cleanup_slice ) { $fresh_result = wr_ok( $complete_workspace->commit( $fresh_owner, $fresh_review ) ); }
 $current_template = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'templates' ), $media_template ), ARRAY_A );
 $mapped_revision = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'template_revisions' ), $current_template['current_revision_id'] ), ARRAY_A );
 $historical_revision = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'template_revisions' ), $old_revision['id'] ), ARRAY_A );
 $check( ! empty( $fresh_result['complete'] ) && $mapped_revision['id'] !== $old_revision['id'] && $mapped_revision['parent_revision_id'] === $old_revision['id'] && $current_template['published_revision_id'] === $mapped_revision['id'], 'fresh target remaps both editable design pointers through one new revision' );
 $check( $historical_revision['layout_json'] === $old_revision['layout_json'] && $historical_revision['layout_sha256'] === $old_revision['layout_sha256'] && false !== strpos( $mapped_revision['layout_json'], 'attachment:' . $alias ), 'fresh remapping retains the original design bytes and hash instead of rewriting historical layout identity' );
 $fresh_version = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), $receipt['version_id'] ), ARRAY_A );
 $check( $fresh_version === $old_version && ! wr_ok( $collection->read_for_user( $user ) )['linked'] && wr_ok( $collection->read_for_user( $fresh_user ) )['linked'], 'fresh reviewed ownership changes profile access while every issued Version byte stays unchanged' );
 $check( ! $maintenance->active() && null === ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(), 'fresh complete restore also removes all owned journals before allowing new writes' );
 // The production worker recreates current typed proofs from locally sealed
 // choices. No source approval flag or raw settings enter native job progress.
 $native_review = wr_ok( \Certloom\Application\Restore\WholeStateRestoreReview::review( $whole['archive_path'], $whole['archive_sha256'], wr_ok( $proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, $fresh_accounts, 'reviewed_target' ) ), wr_ok( $object_proof_class::review( $whole['archive_path'], $whole['archive_sha256'], (int) $actor, array( (int) $attachment => (int) $alias ), 'reviewed_target' ) ) ) );
 $restore_key = wr_ok( ( new \Certloom\Application\Restore\WholeStateRestoreCoordinator( $runtime->queue() ) )->enqueue( $native_review ) );
 $sandbox->register_domain_workspace( $restore_key, true );
 $restore_job = wr_ok( $runtime->repository()->find( $restore_key ) );
 $owned_files[$restore_job['payload']['intent']['path']] = true; $owned_files[$restore_job['payload']['source']['path']] = true;
 $check( count( $restore_job['payload'] ) === 3 && ! isset( $restore_job['payload']['settings'] ) && $restore_job['owner_user_id'] === (int) $actor, 'native complete restore queues only protected descriptors owned by the approving organization account' );
 $intent_store = new \Certloom\Infrastructure\Restore\WholeStateRestoreIntentStore();
 $check( wr_ok( $intent_store->read( $restore_job['payload']['intent'], $restore_job['payload']['source'] ) )['fingerprint'] === $native_review->fingerprint(), 'actual target-sealed intent retains the exact initial review fingerprint' );
 $restore_handler = new \Certloom\Application\Restore\WholeStateRestoreJobHandler();
 $restore_runner = new \Certloom\Application\Job\JobRunner( $runtime->service(), $runtime->repository(), array( \Certloom\Domain\Job\JobTypes::WHOLE_RESTORE => $restore_handler ) );
 $restore_token = 'dddddddddddddddddddddddddddddddd';
 $claimed = wr_ok( $runtime->service()->begin( $restore_key, $restore_token, 300 ) );
 $check( is_wp_error( $restore_handler->handle( $claimed, 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee' ) ) && null === ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(), 'wrong native restore worker cannot create a journal or stage data' );
 $wpdb->update( \Certloom\Infrastructure\Database\SchemaManager::table( 'jobs' ), array( 'locked_until' => '2000-01-01 00:00:00' ), array( 'job_key' => $restore_key ) );
 $check( is_wp_error( $restore_handler->handle( $claimed, $restore_token ) ), 'expired native restore lease cannot cross the approval boundary' );
 $wpdb->update( \Certloom\Infrastructure\Database\SchemaManager::table( 'jobs' ), array( 'locked_until' => gmdate( 'Y-m-d H:i:s', time() + 300 ) ), array( 'job_key' => $restore_key ) );
 wr_ok( $runtime->service()->yield_work( $restore_key, $restore_token ) );
 $native_commit_replayed = false;
 for ( $slice = 0; $slice < 200; ++$slice ) {
  $restore_job = wr_ok( $runtime->repository()->find( $restore_key ) );
  if ( 'succeeded' === $restore_job['status'] ) { break; }
  if ( ! $native_commit_replayed && 'ready' === $restore_job['phase'] ) {
   $claimed = wr_ok( $runtime->service()->begin( $restore_key, $restore_token, 300 ) );
   $first_native_commit = wr_ok( $restore_handler->handle( $claimed, $restore_token ) );
   $check( 'cleanup' === $first_native_commit->phase() && 'committed' === wr_ok( ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read() )['phase'], 'actual native worker commits the full replacement before saving its job cursor' );
   $second_native_commit = wr_ok( $restore_handler->handle( $claimed, $restore_token ) );
   $check( 'cleanup' === $second_native_commit->phase() && $first_native_commit->result()['counts'] === $second_native_commit->result()['counts'], 'commit-before-cursor retry resumes durable cleanup without stale proof reapproval or another table swap' );
   wr_ok( $runtime->service()->progress( $restore_key, $restore_token, $second_native_commit->cursor(), $second_native_commit->phase(), $second_native_commit->result() ) );
   $check( is_wp_error( $restore_handler->handle( $claimed, $restore_token ) ), 'stale native progress cannot advance a later current job cursor' );
   wr_ok( $runtime->service()->yield_work( $restore_key, $restore_token ) ); $native_commit_replayed = true;
  } else {
   $run = wr_ok( $restore_runner->run( $restore_key, $restore_token, 300 ) );
   if ( 'failed' === $run['status'] ) { throw new RuntimeException( 'Native restore failed: ' . ( $run['error']['code'] ?? 'unknown' ) ); }
  }
 }
 $restore_job = wr_ok( $runtime->repository()->find( $restore_key ) );
 $check( $native_commit_replayed && 'succeeded' === $restore_job['status'] && $restore_job['result']['working_files_removed'] && $restore_job['result']['receipt']['source_sha256'] === $whole['archive_sha256'], 'real native JobRunner finishes one reviewed mixed-state replacement and exact private-file cleanup' );
 $check( ! is_file( $restore_job['payload']['intent']['path'] ) && ! is_file( $restore_job['payload']['source']['path'] ) && ! $maintenance->active() && null === ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read(), 'native restore removes its owned intent, archive copy, and all journals before reopening writes' );
 $check( $old_version === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), $receipt['version_id'] ), ARRAY_A ) && wr_ok( $collection->read_for_user( $fresh_user ) )['linked'], 'native background replacement preserves immutable issued bytes and explicitly mapped recipient access' );
 // Actual organization review storage must remain private, owner-scoped and
 // invalidated by any changed target baseline before final confirmation.
 $upload_store = new \Certloom\Infrastructure\Restore\WholeStateRestoreUploadSession();
 $upload_token = wr_ok( $upload_store->create( $whole['archive_path'], 'qa-review.cvrecovery', $restore_key ) );
 $upload_session = wr_ok( $upload_store->get( $upload_token ) ); $owned_files[$upload_session['source']['path']] = true;
 $upload_inventory = wr_ok( $upload_store->inventory( $upload_session ) );
 $check( ! $upload_inventory['same_installation'] && count( $upload_inventory['accounts'] ) === count( $fresh_accounts ) && count( $upload_inventory['objects'] ) === 1, 'native upload review reads actual foreign source evidence without importing website identities' );
 $upload_review = wr_ok( $upload_store->review( $upload_session, $fresh_accounts, array( (int) $attachment => (int) $alias ), array() ) );
 wr_ok( $upload_store->remember_review( $upload_session, $upload_review ) ); $upload_session = wr_ok( $upload_store->get( $upload_token ) );
 $check( $upload_session['fingerprint'] === $upload_review->fingerprint() && $upload_session['accounts'] === $fresh_accounts, 'native draft retains the exact reviewed choices and fingerprint rather than serializing approval authority' );
 $upload_raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, \Certloom\Infrastructure\Restore\WholeStateRestoreUploadSession::PREFIX . $upload_token ) );
 $check( is_string( $upload_raw ) && false === strpos( $upload_raw, $upload_session['source']['path'] ), 'uploaded private source paths and target choices remain sealed in owner-scoped storage' );
 wp_set_current_user( $fresh_user );
 $check( is_wp_error( $upload_store->get( $upload_token ) ), 'recipient cannot access or approve an organization recovery review' ); wp_set_current_user( $actor );
 $original_upload_bytes = file_get_contents( $upload_session['source']['path'] ); file_put_contents( $upload_session['source']['path'], $original_upload_bytes . 'changed' );
 $check( is_wp_error( $upload_store->get( $upload_token ) ), 'changed uploaded archive bytes invalidate native draft review' ); file_put_contents( $upload_session['source']['path'], $original_upload_bytes );
 $before_handoff_jobs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \Certloom\Infrastructure\Database\SchemaManager::table( 'jobs' ) ) );
 $handoff_faulted = false;
 $handoff_fault = static function ( $sql ) use ( &$handoff_faulted, $upload_token, $wpdb ) {
  if ( ! $handoff_faulted && 0 === strpos( $sql, 'UPDATE `' . $wpdb->options . '`' ) && false !== strpos( $sql, \Certloom\Infrastructure\Restore\WholeStateRestoreUploadSession::PREFIX . $upload_token ) ) { $handoff_faulted = true; return 'SELECT * FROM certloom_qa_missing_native_handoff'; } return $sql;
 };
 $old_suppression = $wpdb->suppress_errors( true ); add_filter( 'query', $handoff_fault, PHP_INT_MAX );
 try {
  $failed_handoff = $upload_store->with_session( $upload_token, function ( $current ) use ( $upload_store, $upload_review, $runtime ) {
   return ( new \Certloom\Application\Restore\WholeStateRestoreCoordinator( $runtime->queue() ) )->enqueue( $upload_review, function ( $key ) use ( $upload_store, $current ) { $marked = $upload_store->mark_queued( $current, $key ); return is_wp_error( $marked ) ? $marked : true; } );
  } );
 } finally { remove_filter( 'query', $handoff_fault, PHP_INT_MAX ); $wpdb->suppress_errors( $old_suppression ); }
 $check( $handoff_faulted && is_wp_error( $failed_handoff ) && null === wr_ok( $upload_store->get( $upload_token ) )['queued_job'] && $before_handoff_jobs === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \Certloom\Infrastructure\Database\SchemaManager::table( 'jobs' ) ) ), 'real draft consumption SQL failure rolls back native job creation and keeps the original review available' );
 $handoff_key = wr_ok( $upload_store->with_session( $upload_token, function ( $current ) use ( $upload_store, $upload_review, $runtime ) {
  return ( new \Certloom\Application\Restore\WholeStateRestoreCoordinator( $runtime->queue() ) )->enqueue( $upload_review, function ( $key ) use ( $upload_store, $current ) { $marked = $upload_store->mark_queued( $current, $key ); return is_wp_error( $marked ) ? $marked : true; } );
 } ) );
 $handoff_job = wr_ok( $runtime->repository()->find( $handoff_key ) ); $owned_files[$handoff_job['payload']['intent']['path']] = true; $owned_files[$handoff_job['payload']['source']['path']] = true;
 $upload_session = wr_ok( $upload_store->get( $upload_token ) );
 $check( $upload_session['queued_job'] === $handoff_key && $before_handoff_jobs + 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', \Certloom\Infrastructure\Database\SchemaManager::table( 'jobs' ) ) ), 'native draft consumption and one protected recovery job commit together' );
 $check( wr_ok( $upload_store->with_session( $upload_token, static function ( $current ) { return $current['queued_job']; } ) ) === $handoff_key && is_wp_error( $upload_store->remember_review( $upload_session, $upload_review ) ), 'repeat native confirmation adopts the same queued job and a consumed review cannot be reset' );
 $control = new \Certloom\Infrastructure\Restore\WholeStateRecoveryControl();
 $check( wr_ok( $control->rollback( $runtime, $handoff_key ) )['complete'], 'organization can cancel a queued replacement before it stages or changes certificates' );
 wr_ok( $upload_store->with_session( $upload_token, function ( $current ) use ( $upload_store ) { return $upload_store->discard( $current ); } ) );
 $check( ! is_file( $upload_session['source']['path'] ) && is_wp_error( $upload_store->get( $upload_token ) ), 'discard removes only its exact private upload and makes the review unusable' );
 $control_review = wr_ok( $upload_store->review( array( 'source' => array( 'path' => $whole['archive_path'], 'sha256' => $whole['archive_sha256'] ) ), $fresh_accounts, array( (int) $attachment => (int) $alias ), array() ) );
 $control_key = wr_ok( ( new \Certloom\Application\Restore\WholeStateRestoreCoordinator( $runtime->queue() ) )->enqueue( $control_review ) ); $sandbox->register_domain_workspace( $control_key, true );
 $control_job = wr_ok( $runtime->repository()->find( $control_key ) );
 $owned_files[$control_job['payload']['intent']['path']] = true; $owned_files[$control_job['payload']['source']['path']] = true;
 $control = new \Certloom\Infrastructure\Restore\WholeStateRecoveryControl();
 $control_claim = wr_ok( $runtime->service()->begin( $control_key, $restore_token, 300 ) );
 $check( is_wp_error( $control->rollback( $runtime, $control_key ) ), 'native organization rollback refuses a running restore worker' );
 wr_ok( $runtime->service()->yield_work( $control_key, $restore_token ) );
 wr_ok( $restore_runner->run( $control_key, $restore_token, 300 ) );
 $check( in_array( wr_ok( ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read() )['phase'], array( 'building', 'ready' ), true ), 'rollback fixture uses actual native staged recovery progress before any promotion' );
 wr_ok( $runtime->pause_queued( $control_key ) );
 $before_rollback = $complete_fingerprint();
 for ( $slice = 0; $slice < 100; ++$slice ) { $control_result = wr_ok( $control->rollback( $runtime, $control_key ) ); if ( $control_result['complete'] ) { break; } }
 $check( $control_result['complete'] && 'cancelled' === wr_ok( $runtime->repository()->find( $control_key ) )['status'] && null === ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read() && ! $maintenance->active(), 'explicit organization rollback cancels the paused worker and completes bounded recovery cleanup' );
 $check( $before_rollback === $complete_fingerprint() && ! is_file( $control_job['payload']['source']['path'] ) && ! is_file( $control_job['payload']['intent']['path'] ), 'native rollback preserves live tables and exact options while removing only its owned intent and archive' );
 $check( is_wp_error( $control->rollback( $runtime, $restore_key ) ), 'native rollback cannot reinterpret an already committed recovery as an uncommitted replacement' );
 $history = wr_ok( $runtime->repository()->list_page( 1, 50, array( \Certloom\Domain\Job\JobTypes::WHOLE_RESTORE ) ) );
 $check( count( $history['items'] ) === $history['total'] && count( array_unique( array_column( $history['items'], 'job_type' ) ) ) === 1 && \Certloom\Domain\Job\JobTypes::WHOLE_RESTORE === $history['items'][0]['job_type'], 'native recovery history filters operation types in SQL instead of hiding older recoveries behind unrelated jobs' );
 // A full first page of active reviews cannot starve later expired uploads.
 $expiry_sessions = array();
 for ( $i = 0; $i < 103; ++$i ) {
  $token = wr_ok( $upload_store->create( $whole['archive_path'], 'qa-expiry.cvrecovery', $restore_key ) );
  $session = wr_ok( $upload_store->get( $token ) ); $owned_files[$session['source']['path']] = true;
  if ( $i >= 100 ) { $session['expires'] = time() - 60; if ( 102 === $i ) { $session['purpose'] = 'qa-other-purpose'; } $sealed = wr_ok( \Certloom\Infrastructure\Backup\SecretVault::seal( $session ) ); $wpdb->update( $wpdb->options, array( 'option_value' => $sealed ), array( 'option_name' => $upload_store::PREFIX . $token ) ); $expiry_sessions[] = $session; }
 }
 $check( true === wr_ok( $upload_store->prune_expired() ) && (int) get_option( $upload_store::PRUNE_CURSOR ) > 0, 'expiry watchdog saves a bounded cursor past one hundred active reviews' );
 $changed_upload = $expiry_sessions[1]['source']['path']; file_put_contents( $changed_upload, 'qa changed upload' );
 $old_upload = $expiry_sessions[2]['source']['path']; touch( $old_upload, time() - 3 * DAY_IN_SECONDS );
 $check( true === wr_ok( $upload_store->prune_expired() ) && ! is_file( $expiry_sessions[0]['source']['path'] ), 'later expired draft is reached and its exact owned source is removed' );
 $check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s', $wpdb->options, $upload_store::PREFIX . $expiry_sessions[0]['token'] ) ), 'expired draft SQL record is removed together with its proven upload' );
 $check( is_file( $changed_upload ) && is_file( $old_upload ), 'changed source bytes and a different sealed purpose cannot be deleted as expired review authority' );
 copy( $whole['archive_path'], $changed_upload ); wr_ok( $upload_store->prune_expired() ); wr_ok( $upload_store->prune_expired() );
 $check( ! is_file( $changed_upload ) && is_file( $old_upload ), 'restoring exact source bytes allows cleanup while an unrelated registered purpose remains protected' );
} catch ( Throwable $e ) { $failure = get_class( $e ) . ': ' . $e->getMessage(); }
finally {
 $preserve_asset_fixture = false;
 if ( $sandbox && isset( $complete_workspace ) ) {
  try {
   for ( $slice = 0; $slice < 100; ++$slice ) {
    $pending_whole = ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read();
    if ( is_wp_error( $pending_whole ) ) { throw new RuntimeException( $pending_whole->get_error_code() ); }
    if ( ! $pending_whole ) { break; }
    wr_ok( 'committed' === $pending_whole['phase'] ? $complete_workspace->recover() : $complete_workspace->rollback( $pending_whole['owner_token'] ) );
   }
   if ( ( new \Certloom\Infrastructure\Restore\WholeStateRestoreJournal() )->read() ) { throw new RuntimeException( 'Owned whole recovery remains pending.' ); }
  } catch ( Throwable $e ) { $preserve_asset_fixture = true; $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); }
 }
 if ( isset( $asset_filter ) ) {
  try {
   $pending = $publication->pending_owner();
   if ( is_wp_error( $pending ) && isset( $sealed_row ) ) { $wpdb->update( $wpdb->options, array( 'option_value' => $sealed_row ), array( 'option_name' => \Certloom\Infrastructure\Restore\WholeStateAssetPublication::OPTION ) ); $pending = $publication->pending_owner(); }
   if ( $pending ) { wr_ok( $pending ); for ( $i = 0; $i < 205 && true !== ( $rolled = wr_ok( $publication->rollback( $asset_owner ) ) ); ++$i ) {} if ( true !== $rolled ) { throw new RuntimeException( 'Owned asset journal needs continued cleanup.' ); } }
   if ( isset( $asset_owner ) && $maintenance->owned_by( $asset_owner ) ) { wr_ok( $maintenance->release( $asset_owner ) ); }
   if ( is_dir( $asset_directory ) ) {
    $fallback_root = $asset_directory . '/recovery-assets'; $qa_fallback = $fallback_root . '/' . $asset['sha256'];
    if ( is_file( $qa_fallback ) ) { if ( is_link( $qa_fallback ) || hash_file( 'sha256', $qa_fallback ) !== $asset['sha256'] || ! unlink( $qa_fallback ) ) { throw new RuntimeException( 'Changed QA fallback retained.' ); } }
    if ( is_dir( $fallback_root ) && ! rmdir( $fallback_root ) ) { throw new RuntimeException( 'Unexpected QA fallback directory content retained.' ); }
    foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $name ) { if ( is_file( $asset_directory . '/' . $name ) && ! unlink( $asset_directory . '/' . $name ) ) { throw new RuntimeException( 'Asset fixture sentinel cleanup failed.' ); } }
    if ( ! rmdir( $asset_directory ) ) { throw new RuntimeException( 'Owned asset directory cleanup failed.' ); }
   }
  } catch ( Throwable $e ) { $preserve_asset_fixture = true; $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); }
  remove_filter( 'certloom_private_job_directory', $asset_filter, PHP_INT_MAX );
 }
 if ( ! $preserve_asset_fixture && isset( $track_whole ) ) { try { $track_whole(); } catch ( Throwable $e ) { $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); } }
 if ( $sandbox && ! $preserve_asset_fixture ) { try {
  $files = new PrivateJobFileStore(); $private_root = wr_ok( $files->directory() );
  foreach ( $whole_job_keys as $key ) { foreach ( new DirectoryIterator( $private_root ) as $file ) { $path = $private_root . '/' . $file->getFilename(); if ( strpos( $file->getFilename(), $key . '-' ) === 0 && $files->owns( $path ) && is_file( $path ) ) { $owned_files[$path] = true; } } }
  foreach ( array_keys( $owned_files ) as $path ) { wr_ok( $files->delete( $path ) ); }
  $owned_recovery_root = $job_directory . '/recovery-assets';
  if ( is_dir( $owned_recovery_root ) ) { $owned_recovery_file = $owned_recovery_root . '/' . $asset['sha256']; if ( is_file( $owned_recovery_file ) && ( is_link( $owned_recovery_file ) || hash_file( 'sha256', $owned_recovery_file ) !== $asset['sha256'] || ! unlink( $owned_recovery_file ) ) ) { throw new RuntimeException( 'Changed owned QA recovery asset retained.' ); } if ( count( scandir( $owned_recovery_root ) ) !== 2 || ! rmdir( $owned_recovery_root ) ) { throw new RuntimeException( 'Unexpected private QA recovery files retained.' ); } }
  foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $sentinel ) { if ( is_file( $job_directory . '/' . $sentinel ) && ! unlink( $job_directory . '/' . $sentinel ) ) { throw new RuntimeException( 'Owned private job sentinel cleanup failed.' ); } }
  if ( is_dir( $job_directory ) && ! rmdir( $job_directory ) ) { throw new RuntimeException( 'Unexpected private job files retained.' ); }
  remove_filter( 'certloom_private_job_directory', $job_directory_filter, PHP_INT_MAX );
  if ( $catalog_directory && is_dir( $catalog_directory ) ) { foreach ( new DirectoryIterator( $catalog_directory ) as $file ) { $name = $file->getFilename(); if ( $file->isDot() ) { continue; } if ( ! in_array( $name, array( 'index.php', '.htaccess', 'web.config' ), true ) && ! preg_match( '/^[a-f0-9]{32}-(manual|scheduled|safety)-[a-f0-9]{48}\.(cvbackup|cvrecovery)$/D', $name ) ) { throw new RuntimeException( 'Unexpected backup fixture file retained.' ); } if ( $file->isLink() || ! $file->isFile() || ! unlink( $file->getPathname() ) ) { throw new RuntimeException( 'Owned backup fixture cleanup failed.' ); } } if ( ! rmdir( $catalog_directory ) ) { throw new RuntimeException( 'Backup fixture directory cleanup failed.' ); } }
  if ( isset( $catalog_filter ) ) { remove_filter( 'certloom_private_backup_directory', $catalog_filter, PHP_INT_MAX ); }
  $cleanup = $sandbox->cleanup(); if ( is_file( $directory . '/image.png' ) && ! unlink( $directory . '/image.png' ) ) { throw new RuntimeException( 'Owned PNG cleanup failed.' ); } if ( ! rmdir( $directory ) ) { throw new RuntimeException( 'Private directory cleanup failed.' ); } $directory = null;
 } catch ( Throwable $e ) { $failure = ( $failure ? $failure . '; ' : '' ) . $e->getMessage(); } }
 if ( $sandbox && $preserve_asset_fixture ) { $sandbox->leave(); }
}
echo wp_json_encode( array( 'success' => null === $failure && is_array( $cleanup ), 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'cleanup' => $cleanup, 'retained_private_journal' => $directory ? $directory . '/journal.json' : null, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( null === $failure && is_array( $cleanup ) ? 0 : 1 );
