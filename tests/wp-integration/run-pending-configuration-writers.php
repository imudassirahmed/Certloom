<?php
/** Real Local configuration/outbox/worker acceptance. Every fixture is rolled back. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' );
$backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
$backup_sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $backup_sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $backup_sha ) || ! hash_equals( $backup_sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local root and verified private snapshot required.\n" ); exit( 1 ); }
define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || realpath( ABSPATH ) !== realpath( $site ) || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { fwrite( STDERR, "Refusing another site or database.\n" ); exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'No external transport in fixture acceptance.' ); }, PHP_INT_MAX );

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Issuance\PendingConfigurationJobHandler;
use Certloom\Application\Job\JobRunner;
use Certloom\Application\Job\JobService;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Job\JobRepository;
use Certloom\Infrastructure\Recipient\PendingConfigurationQueue;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;
use Certloom\Infrastructure\Repository\SignerRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Application\Templates\TemplateDefaults;

$checks = array(); $failure = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$unwrap = static function ( $value ) { if ( is_wp_error( $value ) ) { $data = $value->get_error_data(); throw new RuntimeException( $value->get_error_code() . ( is_array( $data ) && isset( $data['stage'] ) ? ':' . $data['stage'] : '' ) ); } return $value; };
$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients', 'programs', 'issuers', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map', 'program_registrations', 'field_submissions', 'issuance_requests', 'issuance_request_events' ) ) );
$fingerprints = static function () use ( $tables ) {
 global $wpdb; $out = array();
 foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Preservation read failed.' ); } $out[ $table ] = hash( 'sha256', wp_json_encode( $rows ) ); }
 return $out;
};
$option_fingerprint = static function () {
 global $wpdb; $wpdb->last_error = '';
 $rows = $wpdb->get_results( "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE LEFT(option_name,9)='certloom_' OR LEFT(option_name,11)='certverify_' OR option_name='cron' ORDER BY option_name", ARRAY_A );
 if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Option preservation read failed.' ); }
 return array( 'sha256' => hash( 'sha256', wp_json_encode( $rows ) ), 'keys' => array_column( $rows, 'option_name' ) );
};
$before = $fingerprints(); $before_options = $option_fingerprint();
$result = ( new Transaction() )->run( static function () use ( $check, $unwrap, $fingerprints, $option_fingerprint, &$failure ) {
 try {
  global $wpdb;
  $nonce = bin2hex( random_bytes( 6 ) );
  $field = 'qa_cfg_' . $nonce;
  $fields = Ecapla_CertVerify_Fields::get_fields();
  $fields[] = array( 'key' => $field, 'label' => 'QA organization result', 'type' => 'number', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'lifetime' => 'achievement', 'writer' => 'staff', 'collect_when' => 'never', 'approval_required' => false, 'show_on_certificate' => false, 'help' => 'QA staff-only result' ) );
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'QA catalog uses a private staff-owned result without customer qualification authority' );
  $catalog = Ecapla_CertVerify_Fields::get_field_map();
  $global = new ScopedFieldGlobalRepository(); $issuers = new IssuerRepository(); $programs = new ProgramRepository(); $batches = new BatchRepository();
  $requests = new IssuanceRequestRepository(); $pending = new PendingIssuanceService(); $versions = new CredentialRepository();
  $recipient = $unwrap( ( new RecipientRepository() )->create( array( 'display_name' => 'QA Configuration Recipient ' . $nonce, 'custom_fields' => array() ) ) );
  $queue = new PendingConfigurationQueue(); $jobs = new JobRepository(); $job_service = new JobService( $jobs );
  $runner = new JobRunner( $job_service, $jobs, array( JobTypes::PENDING_CONFIGURATION => new PendingConfigurationJobHandler() ) );
  $read_state = static function () use ( $unwrap ) { $rows = $unwrap( DatabaseOptions::read( array( PendingConfigurationQueue::OPTION ) ) ); return $rows[ PendingConfigurationQueue::OPTION ]['exists'] ? json_decode( $rows[ PendingConfigurationQueue::OPTION ]['value'], true ) : null; };
  $reset_hint = static function () use ( $check ) { global $wpdb; $wpdb->last_error = ''; $deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => PendingConfigurationQueue::OPTION ) ); $check( false !== $deleted && '' === $wpdb->last_error, 'isolate the disposable configuration hint inside the rollback transaction' ); LegacyWriteGuard::invalidate_option( PendingConfigurationQueue::OPTION ); };
  $drain = static function () use ( $unwrap, $check, $queue, $job_service, $runner, $jobs, $read_state ) {
   $key = $unwrap( $queue->dispatch( $job_service ) ); $check( is_string( $key ), 'the durable signal dispatches a real configuration worker' );
   for ( $i = 0; $i < 10; ++$i ) { $unwrap( $runner->run( $key, bin2hex( random_bytes( 16 ) ) ) ); $job = $unwrap( $jobs->find( $key ) ); if ( 'succeeded' === $job['status'] ) { break; } }
   $check( 'succeeded' === $job['status'] && false === $read_state()['pending'], 'bounded configuration slices complete and acknowledge exactly their generation' );
   return $job;
  };
  $receive = static function ( $index, $program_id, $issuer_id = 0, $batch_id = 0, $eligible = true ) use ( $nonce, $recipient, $requests, $unwrap ) {
   return $unwrap( $requests->receive( array( 'recipient_id' => $recipient, 'program_id' => $program_id, 'batch_id' => $batch_id ?: null, 'source_type' => 'manual', 'source_ref' => 'qa-writer-' . $nonce . '-' . $index, 'intake_key' => hash( 'sha256', 'qa-writer-' . $nonce . '-' . $index ) ), array( 'issuer_id' => $issuer_id, 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QAW-' . $nonce . '-' . $index ), array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => $eligible ? 'eligible' : 'unknown', 'checked_at' => current_time( 'mysql', true ), 'valid_until' => null, 'fields' => array() ) ) );
  };
  $held = static function ( $request ) use ( $unwrap, $check, $pending, $field ) { $row = $unwrap( $pending->process( $request['id'] ) ); $check( 'waiting' === $row['status'] && null === $row['version_id'] && false !== strpos( $row['blockers_json'], $field ), 'eligible request waits on the missing organization-owned result' ); return $row; };
  $assert_issued = static function ( $request ) use ( $unwrap, $check, $requests, $versions ) { $row = $unwrap( $requests->find( $request['id'] ) ); $check( 'issued' === $row['status'] && is_int( $row['version_id'] ), 'worker issues the qualified request after its configuration blocker is repaired' ); return $unwrap( $versions->find_version( $row['version_id'] ) ); };

  // Global requirement changes are atomic, uncached, no-op aware and durable.
  $unwrap( $global->save( array( $field => 'required' ), $catalog ) );
  $program_a = $unwrap( $programs->create( array( 'title' => 'QA Global Program ' . $nonce, 'code' => 'QWG-' . $nonce ) ) );
  $first = $held( $receive( 1, $program_a ) );
  $unqualified = $receive( 2, $program_a, 0, 0, false ); $unwrap( $pending->process( $unqualified['id'] ) );
  $reset_hint(); $unwrap( $global->save( array( $field => 'optional' ), $catalog ) );
  $state = $read_state();
  $check( $state['pending'] && 0 === $state['program_id'] && $state['max_id'] >= $unqualified['id'], 'Global requirement repair queues a private all-Program signal with a fixed intake ceiling' );
  $check( 'waiting' === $unwrap( $requests->find( $first['id'] ) )['status'], 'saving requirements does not issue synchronously in the configuration request' );
  $generation = ( new DomainGeneration() )->current();
  wp_cache_set( ScopedFieldGlobalRepository::OPTION, 'stale cache fixture', 'options' );
  $unwrap( $global->save( array( $field => 'optional' ), $catalog ) );
  $check( $state === $read_state() && $generation === ( new DomainGeneration() )->current() && ! is_wp_error( $global->get( $catalog ) ), 'unchanged Global save uses fresh storage, clears a stale cache and creates no generation' );
  $drain(); $first_version = $assert_issued( $first );
  $unqualified_now = $unwrap( $requests->find( $unqualified['id'] ) );
  $check( 'waiting' === $unqualified_now['status'] && null === $unqualified_now['version_id'] && false !== strpos( $unqualified_now['blockers_json'], 'missing_current_evidence' ), 'configuration repair never grants qualification to a request with unknown evidence' );

  // One issuer's settings can affect multiple Programs, so its signal is global.
  $unwrap( $global->save( array(), $catalog ) );
  $contract = new ScopedFieldContract();
  $issuer_settings = $unwrap( $contract->with_scope_metadata( array(), 'issuer', array( $field => 'required' ), $catalog ) );
  $issuer = $unwrap( $issuers->create( array( 'name' => 'QA Issuer ' . $nonce, 'slug' => 'qa-issuer-' . $nonce, 'settings' => $issuer_settings ) ) );
  $program_b = $unwrap( $programs->create( array( 'title' => 'QA Issuer Program ' . $nonce, 'code' => 'QWI-' . $nonce, 'issuer_id' => $issuer ) ) );
  $second = $held( $receive( 3, $program_b, $issuer ) );
  $reset_hint(); $issuer_settings = $unwrap( $contract->with_scope_metadata( $issuer_settings, 'issuer', array( $field => 'optional' ), $catalog ) );
  $unwrap( $issuers->update( $issuer, array( 'settings' => $issuer_settings ) ) );
  $check( $read_state()['pending'] && 0 === $read_state()['program_id'], 'Issuer settings repair retains a global configuration signal' );
  $drain(); $second_version = $assert_issued( $second );

  // Batch overrides target one Program; a moved Batch targets both with one pass.
  $program_c = $unwrap( $programs->create( array( 'title' => 'QA Batch Program ' . $nonce, 'code' => 'QWT-' . $nonce ) ) );
  $batch_metadata = $unwrap( $contract->with_batch_metadata( array(), array( $field => 'required' ), $catalog ) );
  $batch = $unwrap( $batches->create( array( 'name' => 'QA Batch ' . $nonce, 'code' => 'QWB-' . $nonce, 'program_id' => $program_c, 'metadata' => $batch_metadata, 'active' => 1 ) ) );
  $third = $held( $receive( 4, $program_c, 0, $batch ) );
  $reset_hint(); $batch_metadata = $unwrap( $contract->with_batch_metadata( $batch_metadata, array( $field => 'optional' ), $catalog ) );
  $unwrap( $batches->update( $batch, array( 'metadata' => $batch_metadata ) ) );
  $check( $read_state()['pending'] && $program_c === $read_state()['program_id'], 'Batch requirement repair uses the original bounded Program scope' );
  $drain(); $third_version = $assert_issued( $third );
  $move_batch = $unwrap( $batches->create( array( 'name' => 'QA Moved Batch ' . $nonce, 'code' => 'QWM-' . $nonce, 'program_id' => $program_c, 'active' => 1 ) ) );
  $reset_hint(); $unwrap( $batches->update( $move_batch, array( 'program_id' => $program_b ) ) );
  $check( $read_state()['pending'] && 0 === $read_state()['program_id'], 'moving a Batch cannot drop pending work from its previous Program' );

  // All writers reject both a failed signal and a zero-row signal acknowledgement.
  $signal_before = $read_state();
  $issuer_before = $unwrap( $issuers->find( $issuer ) ); $batch_before = $unwrap( $batches->find( $batch ) );
  $global_before = $unwrap( $global->get( $catalog ) );
  $signer = $unwrap( ( new SignerRepository() )->create( array( 'display_name' => 'QA Signer ' . $nonce, 'slug' => 'qa-signer-' . $nonce, 'active' => 1 ) ) );
  $assignments_before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', SchemaManager::table( 'signer_assignments' ) ), ARRAY_A );
  $operations = array(
   'Issuer' => static function () use ( $issuers, $issuer, $nonce ) { return $issuers->update( $issuer, array( 'name' => 'Must roll back ' . $nonce ) ); },
   'Batch' => static function () use ( $batches, $batch, $signer ) { return $batches->update( $batch, array( 'name' => 'Must roll back', 'instructor_signer_id' => $signer ) ); },
   'Global requirements' => static function () use ( $global, $field, $catalog ) { return $global->save( array( $field => 'required' ), $catalog ); },
  );
  foreach ( array( 'error', 'zero_rows' ) as $mode ) {
   $fail_signal = static function ( $sql ) use ( $mode, $nonce ) { global $wpdb; if ( 0 === strpos( $sql, 'UPDATE ' ) && false !== strpos( $sql, PendingConfigurationQueue::OPTION ) ) { return 'error' === $mode ? 'UPDATE `__certloom_qa_missing_' . $nonce . '` SET option_value=0 WHERE 1=0' : 'UPDATE `' . $wpdb->options . '` SET option_value=option_value WHERE 1=0'; } return $sql; };
   foreach ( $operations as $label => $operation ) {
    $generation_before = ( new DomainGeneration() )->current();
    $old_errors = $wpdb->suppress_errors( true ); add_filter( 'query', $fail_signal, PHP_INT_MAX );
    try { $rejected = $operation(); } finally { remove_filter( 'query', $fail_signal, PHP_INT_MAX ); $wpdb->suppress_errors( $old_errors ); }
    $check( is_wp_error( $rejected ) && 'certloom_pending_configuration_failed' === $rejected->get_error_code(), $label . ' rejects ' . $mode . ' outbox persistence' );
    $check( $issuer_before === $unwrap( $issuers->find( $issuer ) ) && $batch_before === $unwrap( $batches->find( $batch ) ) && $global_before === $unwrap( $global->get( $catalog ) ) && $signal_before === $read_state() && $generation_before === ( new DomainGeneration() )->current(), $label . ' rolls back configuration, caches, generation and the previous signal after ' . $mode );
    $check( $assignments_before === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', SchemaManager::table( 'signer_assignments' ) ), ARRAY_A ), 'Batch primary-signature projection also rolls back after ' . $label . ' ' . $mode );
   }
  }
  $skip_global_write = static function ( $sql ) { global $wpdb; return 0 === strpos( $sql, 'UPDATE ' ) && false !== strpos( $sql, ScopedFieldGlobalRepository::OPTION ) ? 'UPDATE `' . $wpdb->options . '` SET option_value=option_value WHERE 1=0' : $sql; };
  add_filter( 'query', $skip_global_write, PHP_INT_MAX );
  try { $rejected = $global->save( array( $field => 'required' ), $catalog ); } finally { remove_filter( 'query', $skip_global_write, PHP_INT_MAX ); }
  $check( is_wp_error( $rejected ) && 'certloom_global_field_contract_save_failed' === $rejected->get_error_code() && $global_before === $unwrap( $global->get( $catalog ) ) && $signal_before === $read_state(), 'a zero-row Global option write cannot claim a saved requirement or queue work' );
  $check( true === $issuers->update( $issuer, array() ) && $signal_before === $read_state(), 'an empty Issuer patch retains compatibility without creating another signal' );
  $missing_batch = $batches->update( PHP_INT_MAX, array( 'name' => 'Missing' ) );
  $check( is_wp_error( $missing_batch ) && 'certloom_batch_missing' === $missing_batch->get_error_code() && $signal_before === $read_state(), 'missing Batch fails without scheduling phantom work' );
  $check( $first_version['snapshot'] === $unwrap( $versions->find_version( $first_version['id'] ) )['snapshot'] && $second_version['snapshot'] === $unwrap( $versions->find_version( $second_version['id'] ) )['snapshot'] && $third_version['snapshot'] === $unwrap( $versions->find_version( $third_version['id'] ) )['snapshot'], 'later configuration edits and failures preserve all three immutable issued snapshots' );
  $wpdb->last_error = ''; $deliveries = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id IN (%d,%d,%d)', SchemaManager::table( 'email_deliveries' ), $first_version['id'], $second_version['id'], $third_version['id'] ) );
  $check( '' === $wpdb->last_error && '0' === (string) $deliveries, 'recipient has no destination and no email delivery was created' );

  // Design and signer commands use the same outbox and roll back if it cannot be retained.
  $templates = new TemplateRepository(); $signers = new SignerRepository();
  $design = $unwrap( $templates->create( array( 'name' => 'QA Design ' . $nonce, 'machine_key' => 'qa-design-' . $nonce, 'layout' => TemplateDefaults::blank_a4_landscape() ) ) );
  $design_row = $unwrap( $templates->find( $design ) ); $reset_hint();
  $unwrap( $templates->publish( $design, $design_row['current_revision_id'], $design_row['current_revision']['layout_sha256'] ) );
  $check( $read_state()['pending'] && 0 === $read_state()['program_id'], 'publishing a design retains a global signal for explicit and inherited consumers' );
  $state = $read_state(); $unwrap( $templates->publish( $design, $design_row['current_revision_id'], $design_row['current_revision']['layout_sha256'] ) );
  $check( $state === $read_state(), 'republishing the same immutable revision creates no new pending generation' );
  $reset_hint(); $unwrap( $templates->assign( $design, 'program', $program_c ) );
  $check( $read_state()['pending'] && $program_c === $read_state()['program_id'], 'Program design assignment uses a bounded Program signal' );
  $assignment = $unwrap( $templates->get_assignment( 'program', $program_c ) ); $state = $read_state();
  $unwrap( $templates->assign( $design, 'program', $program_c, 100, $assignment['id'], $assignment['updated_at'] ) );
  $check( $state === $read_state(), 'an unchanged reviewed assignment creates no new pending signal' );
  $reset_hint(); $unwrap( $templates->clear_assignment( 'program', $program_c, $assignment['id'], $assignment['updated_at'] ) );
  $check( $read_state()['pending'] && $program_c === $read_state()['program_id'], 'clearing a Program design wakes its inherited-design readiness checks' );
  $state = $read_state(); $unwrap( $templates->save_revision( $design, TemplateDefaults::blank_a4_landscape() ) );
  $check( $state === $read_state(), 'saving an unpublished draft does not manufacture a publication repair signal' );
  $reset_hint(); $unwrap( $templates->update_metadata( $design, array( 'name' => 'QA Renamed Design ' . $nonce ) ) );
  $check( $read_state()['pending'] && 0 === $read_state()['program_id'], 'design metadata edits retain reassessment for reviewed consumers' );
  $state = $read_state(); $unwrap( $templates->update_metadata( $design, array( 'name' => 'QA Renamed Design ' . $nonce ) ) );
  $check( $state === $read_state(), 'unchanged design metadata creates no new pending signal' );
  foreach ( array( 'unpublish', 'archive', 'restore' ) as $method ) {
   $reset_hint(); $unwrap( $templates->$method( $design ) );
   $check( $read_state()['pending'] && 0 === $read_state()['program_id'], 'design ' . $method . ' retains its lifecycle reassessment' );
   $state = $read_state(); $unwrap( $templates->$method( $design ) );
   $check( $state === $read_state(), 'idempotent design ' . $method . ' creates no new pending signal' );
  }
  $reset_hint(); $unwrap( $signers->update( $signer, array( 'title' => 'Reviewed QA Signer' ) ) );
  $check( $read_state()['pending'] && 0 === $read_state()['program_id'], 'signer profile edits wake requests across all assignment scopes' );
  $state = $read_state(); $unwrap( $signers->update( $signer, array( 'title' => 'Reviewed QA Signer' ) ) );
  $check( $state === $read_state(), 'unchanged signer profile creates no new pending signal' );
  $reset_hint(); $signer_assignment = $unwrap( $signers->add_assignment( $signer, 'program', $program_c ) );
  $check( $read_state()['pending'] && $program_c === $read_state()['program_id'], 'new signer assignment retains its Program repair signal and correct assignment ID' );
  $state = $read_state(); $check( $signer_assignment === $unwrap( $signers->ensure_assignment( $signer, 'program', $program_c ) ) && $state === $read_state(), 'ensuring an existing signer assignment retains its identity without requeueing' );
  $reset_hint(); $unwrap( $signers->remove_assignment( $signer, $signer_assignment ) );
  $check( $read_state()['pending'], 'removing a signer assignment durably rechecks its inherited fallback' );
  $reset_hint(); $unwrap( $signers->sync_primary_assignment( $signer, 'program', $program_c ) );
  $check( $read_state()['pending'] && $program_c === $read_state()['program_id'], 'compatibility projection repair retains its Program signal even without a parent-row edit' );
  $state = $read_state(); $unwrap( $signers->sync_primary_assignment( $signer, 'program', $program_c ) );
  $check( $state === $read_state(), 'unchanged primary signer projection creates no new pending signal' );
  $reset_hint(); $unwrap( $signers->sync_primary_assignment( 0, 'program', $program_c ) );
  $check( $read_state()['pending'] && $program_c === $read_state()['program_id'], 'removing a primary projection independently retains reassessment' );

  $publish_row = $unwrap( $templates->find( $design ) );
  $published = $unwrap( $templates->create( array( 'name' => 'QA Published ' . $nonce, 'machine_key' => 'qa-published-' . $nonce, 'layout' => TemplateDefaults::blank_a4_landscape() ) ) );
  $published_row = $unwrap( $templates->find( $published ) ); $unwrap( $templates->publish( $published, $published_row['current_revision_id'], $published_row['current_revision']['layout_sha256'] ) );
  $archived = $unwrap( $templates->create( array( 'name' => 'QA Archived ' . $nonce, 'machine_key' => 'qa-archived-' . $nonce, 'layout' => TemplateDefaults::blank_a4_landscape() ) ) ); $unwrap( $templates->archive( $archived ) );
  $inactive_assignment = $unwrap( $templates->get_assignment( 'program', $program_c ) );
  $unwrap( $templates->assign( $published, 'program', $program_c, 100, $inactive_assignment['id'], $inactive_assignment['updated_at'] ) ); $clear = $unwrap( $templates->get_assignment( 'program', $program_c ) );
  $signer_assignment = $unwrap( $signers->add_assignment( $signer, 'program', $program_c ) );
  $operations = array(
   'design publish' => static function () use ( $templates, $design, $publish_row ) { return $templates->publish( $design, $publish_row['current_revision_id'], $publish_row['current_revision']['layout_sha256'] ); },
   'design metadata' => static function () use ( $templates, $design ) { return $templates->update_metadata( $design, array( 'name' => 'Must roll back' ) ); },
   'design assignment' => static function () use ( $templates, $design, $program_b ) { return $templates->assign( $design, 'program', $program_b ); },
   'design assignment removal' => static function () use ( $templates, $program_c, $clear ) { return $templates->clear_assignment( 'program', $program_c, $clear['id'], $clear['updated_at'] ); },
   'design unpublish' => static function () use ( $templates, $published ) { return $templates->unpublish( $published ); },
   'design archive' => static function () use ( $templates, $design ) { return $templates->archive( $design ); },
   'design restore' => static function () use ( $templates, $archived ) { return $templates->restore( $archived ); },
   'signer profile' => static function () use ( $signers, $signer ) { return $signers->update( $signer, array( 'title' => 'Must roll back' ) ); },
   'signer assignment' => static function () use ( $signers, $signer, $program_b ) { return $signers->add_assignment( $signer, 'program', $program_b ); },
   'signer removal' => static function () use ( $signers, $signer, $signer_assignment ) { return $signers->remove_assignment( $signer, $signer_assignment ); },
   'primary signer repair' => static function () use ( $signers, $signer, $program_b ) { return $signers->sync_primary_assignment( $signer, 'program', $program_b ); },
  );
  // Unpublish needs an unassigned published design.
  $unassigned = $unwrap( $templates->create( array( 'name' => 'QA Unassigned ' . $nonce, 'machine_key' => 'qa-unassigned-' . $nonce, 'layout' => TemplateDefaults::blank_a4_landscape() ) ) );
  $unassigned_row = $unwrap( $templates->find( $unassigned ) ); $unwrap( $templates->publish( $unassigned, $unassigned_row['current_revision_id'], $unassigned_row['current_revision']['layout_sha256'] ) );
  $operations['design unpublish'] = static function () use ( $templates, $unassigned ) { return $templates->unpublish( $unassigned ); };
  foreach ( array( 'error', 'zero_rows' ) as $mode ) {
   $fail_signal = static function ( $sql ) use ( $mode, $nonce ) { global $wpdb; if ( 0 === strpos( $sql, 'UPDATE ' ) && false !== strpos( $sql, PendingConfigurationQueue::OPTION ) ) { return 'error' === $mode ? 'UPDATE `__certloom_qa_missing_' . $nonce . '` SET option_value=0 WHERE 1=0' : 'UPDATE `' . $wpdb->options . '` SET option_value=option_value WHERE 1=0'; } return $sql; };
   foreach ( $operations as $label => $operation ) {
    $prior_tables = $fingerprints(); $prior_options = $option_fingerprint(); $old_errors = $wpdb->suppress_errors( true ); add_filter( 'query', $fail_signal, PHP_INT_MAX );
    try { $rejected = $operation(); } finally { remove_filter( 'query', $fail_signal, PHP_INT_MAX ); $wpdb->suppress_errors( $old_errors ); }
    $check( is_wp_error( $rejected ) && 'certloom_pending_configuration_failed' === $rejected->get_error_code() && $prior_tables === $fingerprints() && $prior_options === $option_fingerprint(), $label . ' rejects ' . $mode . ' signal failure and rolls back rows, projections, generation, options and scheduler changes' );
   }
  }
  return new WP_Error( 'qa_rollback', 'Successful fixture; roll back all state.' );
 } catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); return new WP_Error( 'qa_failed', 'Failed fixture; roll back all state.' ); }
} );
Ecapla_CertVerify_Fields::reset_runtime_cache();
$after_options = $option_fingerprint(); DatabaseOptions::invalidate( array_unique( array_merge( $before_options['keys'], $after_options['keys'] ) ) );
$preserved = $before === $fingerprints() && $before_options === $after_options;
$success = is_wp_error( $result ) && 'qa_rollback' === $result->get_error_code() && $preserved;
echo wp_json_encode( array( 'success' => $success, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'tracked_rows_and_options_preserved' => $preserved, 'error_code' => $success ? null : ( is_wp_error( $result ) ? $result->get_error_code() : 'unexpected_result' ), 'environment' => array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'database' => $wpdb->db_version() ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $success ? 0 : 1 );
