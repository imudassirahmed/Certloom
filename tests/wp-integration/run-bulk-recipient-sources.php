<?php
/** Real CSV, account completion, durable review/commit and replay; rollback-only Local fixtures. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local root and verified private snapshot required.\n" ); exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { fwrite( STDERR, "Refusing another site.\n" ); exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'No external transport.' ); }, PHP_INT_MAX );
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Issuance\BulkIssuanceCsvSchema;
use Certloom\Application\Issuance\BulkIssuancePreflightJobHandler;
use Certloom\Application\Issuance\BulkIssuanceCommitJobHandler;
use Certloom\Application\Job\JobRunner;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Recipient\RecipientCollectionService;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Job\JobRuntime;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Repository\FieldSubmissionRepository;
use Certloom\Infrastructure\Repository\CredentialRepository;
$checks = array(); $failure = null; $users = array(); $owned_files = array(); $source = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$unwrap = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients', 'programs', 'issuers', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map', 'program_registrations', 'field_submissions', 'issuance_requests', 'issuance_request_events' ) ) );
$fingerprints = static function () use ( $tables ) { global $wpdb; $out = array(); foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Preservation read failed.' ); } $out[ $table ] = hash( 'sha256', wp_json_encode( $rows ) ); } return $out; };
$options = static function () { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE LEFT(option_name,9)='certloom_' OR LEFT(option_name,11)='certverify_' OR option_name='cron' ORDER BY option_name", ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Option preservation read failed.' ); } return array( 'sha256' => hash( 'sha256', wp_json_encode( $rows ) ), 'keys' => array_column( $rows, 'option_name' ), 'hashes' => array_combine( array_column( $rows, 'option_name' ), array_map( static function ( $row ) { return hash( 'sha256', wp_json_encode( $row ) ); }, $rows ) ) ); };
$before = $fingerprints(); $before_options = $options();
$runtime = new JobRuntime(); $files = $runtime->private_files(); $jobs = $runtime->repository();
$result = ( new Transaction() )->run( static function () use ( $check, $unwrap, $runtime, $files, $jobs, &$users, &$owned_files, &$source, &$failure ) {
 try {
  global $wpdb; $nonce = bin2hex( random_bytes( 6 ) ); $actor = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0]; wp_set_current_user( $actor );
  $settings = Ecapla_CertVerify_Settings::get_all(); $settings['certificate_name_source'] = 'profile'; $settings['certificate_name_field_key'] = ''; $settings['numbering_mode'] = 'manual'; $check( Ecapla_CertVerify_Settings::save( $settings ), 'fixture uses explicit manual numbers and retains the ordinary account-name source' );
  $users[] = $user = $unwrap( wp_insert_user( array( 'user_login' => 'qa-bulk-' . $nonce, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'QA Bulk Recipient' ) ) );
  $recipient = $unwrap( ( new RecipientRepository() )->find_by_wp_user_id( $user ) ); $check( is_array( $recipient ), 'actual WordPress registration links the exact customer account' );
  $keys = array( 'personal' => 'qa_bulk_person_' . $nonce, 'result' => 'qa_bulk_result_' . $nonce ); $fields = Ecapla_CertVerify_Fields::get_fields();
  foreach ( $keys as $kind => $key ) { $fields[] = array( 'key' => $key, 'label' => 'QA Bulk ' . $kind, 'type' => 'personal' === $kind ? 'text' : 'number', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => 'personal' === $kind ? 'recipient' : 'staff', 'lifetime' => 'personal' === $kind ? 'recipient' : 'achievement', 'collect_when' => 'personal' === $kind ? 'profile' : 'never', 'approval_required' => 'personal' === $kind, 'show_on_certificate' => false, 'help' => '' ) ); }
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'private customer ID and staff result use the ordinary catalog policies' );
  $catalog = Ecapla_CertVerify_Fields::get_field_map(); $metadata = $unwrap( ( new ScopedFieldContract() )->with_program_metadata( array(), array_fill_keys( array_values( $keys ), 'required' ), $catalog ) );
  $program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Bulk Program ' . $nonce, 'code' => 'QBP-' . $nonce, 'metadata' => $metadata ) ) );
  $templates = new TemplateRepository(); $design = $unwrap( $templates->create( array( 'name' => 'QA Bulk Design ' . $nonce, 'machine_key' => 'qa-bulk-design-' . $nonce, 'layout' => TemplateDefaults::blank_a4_landscape() ) ) );
  $design_row = $unwrap( $templates->find( $design ) ); $unwrap( $templates->publish( $design, $design_row['current_revision_id'], $design_row['current_revision']['layout_sha256'] ) ); $unwrap( $templates->assign( $design, 'program', $program ) );
  $bulk = $runtime->bulk_issuance(); $schema = $unwrap( $bulk->csv_schema( $program ) ); $columns = $unwrap( ( new BulkIssuanceCsvSchema() )->columns( $schema ) );
  $check( in_array( 'custom:' . $keys['result'], $columns, true ) && ! in_array( 'custom:' . $keys['personal'], $columns, true ), 'actual Program CSV includes its staff result and excludes its private customer-owned ID' );
  $source = tempnam( sys_get_temp_dir(), 'certloom-qa-bulk-' ); if ( ! is_string( $source ) ) { throw new RuntimeException( 'Temporary QA source unavailable.' ); }
  $shared = array( 'program_id' => $program, 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent' );
  $stream = fopen( $source, 'wb' ); fputcsv( $stream, array_merge( $columns, array( 'custom:' . $keys['personal'] ) ), ',', '"', '' ); fputcsv( $stream, array( $recipient['id'], 'QB-' . $nonce, '0', 'Unapproved substitute' ), ',', '"', '' ); fclose( $stream );
  $before_jobs = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', SchemaManager::table( 'jobs' ) ) ); $private_before = glob( $unwrap( $files->directory() ) . '/*.data' );
  $bad = $bulk->enqueue_preflight( $source, 'qa-bulk.csv', $actor, $shared );
  $check( is_wp_error( $bad ) && 'certloom_bulk_csv_managed_field' === $bad->get_error_code() && $before_jobs === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', SchemaManager::table( 'jobs' ) ) ) && $private_before === glob( $unwrap( $files->directory() ) . '/*.data' ), 'a CSV attempt to replace a customer-owned ID is rejected before durable review and removes its protected copy' );
  $stream = fopen( $source, 'wb' ); fputcsv( $stream, $columns, ',', '"', '' ); fputcsv( $stream, array( $recipient['id'], 'QB-' . $nonce, '0' ), ',', '"', '' ); fclose( $stream );
  $runner = new JobRunner( $runtime->service(), $jobs, array( JobTypes::BULK_ISSUANCE_PREFLIGHT => new BulkIssuancePreflightJobHandler(), JobTypes::BULK_ISSUANCE_COMMIT => new BulkIssuanceCommitJobHandler( $jobs ) ) );
  $drain = static function ( $key ) use ( $runner, $jobs, $unwrap, &$owned_files ) {
   $job = $unwrap( $jobs->find( $key ) ); if ( isset( $job['payload']['path'] ) ) { $owned_files[] = $job['payload']['path']; }
   for ( $slice = 0; $slice < 10; ++$slice ) { $unwrap( $runner->run( $key, bin2hex( random_bytes( 16 ) ) ) ); $job = $unwrap( $jobs->find( $key ) ); if ( ! empty( $job['result']['plan_path'] ) ) { $owned_files[] = $job['result']['plan_path']; } if ( in_array( $job['status'], array( 'succeeded', 'partially_succeeded', 'failed' ), true ) ) { return $job; } }
   throw new RuntimeException( 'Bounded worker did not finish.' );
  };
  $first = $drain( $unwrap( $bulk->enqueue_preflight( $source, 'qa-bulk.csv', $actor, $shared ) ) );
  $check( 'partially_succeeded' === $first['status'] && ! $first['result']['ready_for_issue'] && 1 === $first['result']['summary']['errors'] && 'certloom_manual_issue_required_field_missing' === $first['result']['summary']['error_details'][0]['code'], 'real CSV review waits for a missing account-owned field without demanding it as a CSV header' );
  $check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE recipient_id=%d', SchemaManager::table( 'credential_series' ), $recipient['id'] ) ), 'blocked CSV review creates no credential' );
  wp_set_current_user( $user ); $collection = new RecipientCollectionService(); $view = $unwrap( $collection->read_for_user( $user ) );
  $unwrap( $collection->save_for_user( $user, 0, $view['revision'], array( $keys['personal'] => '00123' ) ) );
  $proposal = $unwrap( ( new FieldSubmissionRepository() )->latest( $recipient['id'], 'recipient', $recipient['id'], $keys['personal'] ) ); wp_set_current_user( $actor ); $unwrap( $collection->decide( $proposal['id'], $proposal['revision'], true, $actor ) );
  $second = $drain( $unwrap( $bulk->enqueue_preflight( $source, 'qa-bulk.csv', $actor, $shared ) ) );
  $check( 'succeeded' === $second['status'] && $second['result']['ready_for_issue'] && 1 === $second['result']['summary']['ready'], 'the same CSV becomes ready after the customer submits their exact ID and staff approve it' );
  $bad = $bulk->enqueue_commit( $second['job_key'], $actor, str_repeat( '0', 64 ), true ); $check( is_wp_error( $bad ) && 'certloom_bulk_review_stale' === $bad->get_error_code(), 'a stale batch confirmation cannot enqueue commit' );
  $commit_key = $unwrap( $bulk->enqueue_commit( $second['job_key'], $actor, $second['result']['review_fingerprint'], true ) );
  // Simulate a process dying after the row's canonical commit and before saving its job cursor.
  $interrupted = $unwrap( ( new BulkIssuanceCommitJobHandler( $jobs ) )->handle( $unwrap( $jobs->find( $commit_key ) ), bin2hex( random_bytes( 16 ) ) ) );
  $check( 1 === $interrupted->result()['summary']['succeeded'], 'canonical row commit can be recovered when its job cursor was not saved' );
  $committed_version_id = $interrupted->result()['summary']['receipts'][0]['version_id'];
  $commit = $drain( $commit_key );
  $check( 'succeeded' === $commit['status'] && 1 === $commit['result']['summary']['succeeded'] && 0 === $commit['result']['summary']['failed'], 'real durable CSV commit issues through canonical ManualIssuanceService' );
  $receipt = $commit['result']['summary']['receipts'][0]; $version = $unwrap( ( new CredentialRepository() )->find_version( $receipt['version_id'] ) );
  $check( 1 === $commit['result']['summary']['idempotent_replays'] && $committed_version_id === $receipt['version_id'] && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE recipient_id=%d AND program_id=%d', SchemaManager::table( 'credential_series' ), $recipient['id'], $program ) ), 'real worker replay reconciles the same immutable Version and creates no duplicate credential' );
  $check( '00123' === $version['snapshot']['fields']['values'][ $keys['personal'] ] && '0' === $version['snapshot']['fields']['values'][ $keys['result'] ], 'immutable receipt preserves the approved leading-zero customer ID and a valid zero staff result' );
  $check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id=%d', SchemaManager::table( 'email_deliveries' ), $receipt['version_id'] ) ), 'blank-destination QA account generates no email delivery' );

  $deferred_key = 'qa_bulk_deferred_' . $nonce; $fields = Ecapla_CertVerify_Fields::get_fields();
  $deferred = $catalog[ $keys['personal'] ]; $deferred['key'] = $deferred_key; $deferred['label'] = 'QA Required Before Issue'; $deferred['collection_policy']['collect_when'] = 'before_issue'; $fields[] = $deferred;
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'organization can defer a private reusable detail until its certificate request' );
  $catalog = Ecapla_CertVerify_Fields::get_field_map(); $program_row = $unwrap( ( new ProgramRepository() )->find( $program ) );
  $metadata = $unwrap( ( new ScopedFieldContract() )->with_program_metadata( $program_row['metadata'], array_fill_keys( array_merge( array_values( $keys ), array( $deferred_key ) ), 'required' ), $catalog ) );
  $unwrap( ( new ProgramRepository() )->update( $program, array( 'metadata' => $metadata ) ) );
  $users[] = $waiting_user = $unwrap( wp_insert_user( array( 'user_login' => 'qa-bulk-wait-' . $nonce, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'QA Waiting Customer' ) ) );
  $waiting_recipient = $unwrap( ( new RecipientRepository() )->find_by_wp_user_id( $waiting_user ) );
  $shared['waiting_policy'] = 'customer';
  $stream = fopen( $source, 'wb' ); fputcsv( $stream, $columns, ',', '"', '' ); fputcsv( $stream, array( $waiting_recipient['id'], 'QBW-' . $nonce, '' ), ',', '"', '' ); fclose( $stream );
  $missing_result = $drain( $unwrap( $bulk->enqueue_preflight( $source, 'qa-bulk-wait.csv', $actor, $shared ) ) );
  $check( ! $missing_result['result']['ready_for_issue'] && 0 === $missing_result['result']['summary']['waiting'] && 1 === $missing_result['result']['summary']['errors'], 'waiting policy cannot conceal a missing staff result or create qualification from a customer form' );
  $stream = fopen( $source, 'wb' ); fputcsv( $stream, $columns, ',', '"', '' ); fputcsv( $stream, array( $waiting_recipient['id'], 'QBW-' . $nonce, '0' ), ',', '"', '' ); fclose( $stream );
  $waiting_preflight = $drain( $unwrap( $bulk->enqueue_preflight( $source, 'qa-bulk-wait.csv', $actor, $shared ) ) );
  $check( $waiting_preflight['result']['ready_for_issue'] && 1 === $waiting_preflight['result']['summary']['waiting'] && 0 === $waiting_preflight['result']['summary']['ready'], 'review groups a qualified connected customer separately from immediately issuable rows' );
  $bad = $bulk->enqueue_commit( $waiting_preflight['job_key'], $actor, $waiting_preflight['result']['review_fingerprint'], true );
  $check( is_wp_error( $bad ) && 'certloom_bulk_pending_qualification_required' === $bad->get_error_code(), 'waiting rows require their own explicit organization qualification confirmation' );
  $waiting_entry = $unwrap( ( new \Certloom\Infrastructure\Issuance\BulkIssuancePlanStore() )->read_slice( $waiting_preflight['result']['plan_path'], 0, 1 ) )['entries'][0];
  $bad = ( new \Certloom\Application\Issuance\BulkWaitingRequestService() )->receive_reviewed( $waiting_entry, $waiting_preflight['job_key'], $waiting_user );
  $check( is_wp_error( $bad ) && 'certloom_bulk_pending_forbidden' === $bad->get_error_code(), 'a customer cannot turn a pending bulk row into qualified organization intake' );
  $waiting_key = $unwrap( $bulk->enqueue_commit( $waiting_preflight['job_key'], $actor, $waiting_preflight['result']['review_fingerprint'], true, true ) );
  $interrupted_waiting = $unwrap( ( new BulkIssuanceCommitJobHandler( $jobs ) )->handle( $unwrap( $jobs->find( $waiting_key ) ), bin2hex( random_bytes( 16 ) ) ) );
  if ( $interrupted_waiting->result()['summary']['failed'] ) { throw new RuntimeException( 'Waiting row: ' . $interrupted_waiting->result()['summary']['error_details'][0]['code'] ); }
  $check( 1 === $interrupted_waiting->result()['summary']['requests_accepted'] && 0 === $interrupted_waiting->result()['summary']['succeeded'], 'qualified commit creates only a waiting request while customer details are incomplete' );
  $waiting_commit = $drain( $waiting_key );
  $check( 'succeeded' === $waiting_commit['status'] && 1 === $waiting_commit['result']['summary']['requests_accepted'] && 1 === $waiting_commit['result']['summary']['request_replays'], 'an interrupted waiting-row cursor replays its original request instead of creating another intake' );
  $request_id = $waiting_commit['result']['summary']['requests'][0]['request_id']; $requests = new \Certloom\Infrastructure\Repository\IssuanceRequestRepository(); $waiting_request = $unwrap( $requests->find( $request_id ) );
  $check( 'waiting' === $waiting_request['status'] && 'bulk_admin' === $waiting_request['source_type'] && null === $waiting_request['version_id'], 'bulk request records its organization source and remains separate from issued credentials' );
  $account_requests = $unwrap( ( new \Certloom\Application\Recipient\RecipientIssuanceRequests() )->read_for_user( $waiting_user ) );
  $check( 1 === count( $account_requests['items'] ) && $account_requests['items'][0]['can_complete'], 'the exact customer sees an actionable details-needed task on their website account' );
  $bad = $collection->read_for_user( $user, 0, 0, $request_id );
  $check( is_wp_error( $bad ) && 'certloom_recipient_collection_forbidden' === $bad->get_error_code(), 'another registered customer cannot open the request form even with its exact request ID' );
  $waiting_profile = $unwrap( $collection->read_for_user( $waiting_user ) );
  $check( ! in_array( $deferred_key, array_column( $waiting_profile['fields'], 'key' ), true ), 'a before-issue detail does not appear prematurely in the ordinary profile' );
  wp_set_current_user( $waiting_user ); $waiting_view = $unwrap( $collection->read_for_user( $waiting_user, 0, 0, $request_id ) );
  $field_map = array_column( $waiting_view['fields'], null, 'key' );
  $check( $waiting_view['request_id'] === $request_id && $waiting_view['title'] === $program_row['title'] && $field_map[ $deferred_key ]['required'] && $field_map[ $keys['personal'] ]['required'] && ! isset( $field_map[ $keys['result'] ] ), 'the exact waiting form uses Program requirements, includes deferred customer details and excludes staff results' );
  $bad = $collection->save_for_user( $waiting_user, 0, $waiting_profile['revision'], array( $keys['personal'] => '00045' ), $request_id );
  $check( is_wp_error( $bad ) && 'certloom_recipient_collection_stale' === $bad->get_error_code(), 'a general profile revision cannot authorize a request-specific edit' );
  $bad = $collection->save_for_user( $waiting_user, 0, $waiting_view['revision'], array( $keys['result'] => '100' ), $request_id );
  $check( is_wp_error( $bad ), 'an owned request form still cannot set a staff achievement' );
  $unwrap( $collection->save_for_user( $waiting_user, 0, $waiting_view['revision'], array( $keys['personal'] => '00045', $deferred_key => 'Pending ID' ), $request_id ) );
  $pending_service = new \Certloom\Application\Issuance\PendingIssuanceService();
  $check( 'waiting' === $unwrap( $pending_service->process( $request_id ) )['status'], 'a customer ID submission cannot replace its staff approval even after qualification was confirmed' );
  $waiting_proposal = $unwrap( ( new FieldSubmissionRepository() )->latest( $waiting_recipient['id'], 'recipient', $waiting_recipient['id'], $keys['personal'] ) ); wp_set_current_user( $actor ); $unwrap( $collection->decide( $waiting_proposal['id'], $waiting_proposal['revision'], true, $actor ) );
  $deferred_proposal = $unwrap( ( new FieldSubmissionRepository() )->latest( $waiting_recipient['id'], 'recipient', $waiting_recipient['id'], $deferred_key ) );
  $unwrap( $collection->decide( $deferred_proposal['id'], $deferred_proposal['revision'], true, $actor ) );
  $issued_pending = $unwrap( $pending_service->process( $request_id ) ); $waiting_version = $unwrap( ( new CredentialRepository() )->find_version( $issued_pending['version_id'] ) );
  $check( 'issued' === $issued_pending['status'] && '00045' === $waiting_version['snapshot']['fields']['values'][ $keys['personal'] ] && '0' === $waiting_version['snapshot']['fields']['values'][ $keys['result'] ] && 'bulk_admin' === $waiting_version['snapshot']['issuance']['pending_request_context']['source_type'], 'approved details release the qualified bulk request through the canonical pending worker with exact staff results' );
  $bad = $collection->save_for_user( $waiting_user, 0, $waiting_view['revision'], array( $deferred_key => 'Late correction' ), $request_id );
  $check( is_wp_error( $bad ) && 'certloom_recipient_collection_stale' === $bad->get_error_code(), 'an already-issued request cannot accept a stale completion form or alter its snapshot' );
  $replayed_request = $unwrap( ( new \Certloom\Application\Issuance\BulkWaitingRequestService() )->receive_reviewed( $waiting_entry, $waiting_preflight['job_key'], $actor ) );
  $check( $replayed_request['idempotent_replay'] && $replayed_request['id'] === $request_id && $replayed_request['version_id'] === $issued_pending['version_id'] && $waiting_version === $unwrap( ( new CredentialRepository() )->find_version( $issued_pending['version_id'] ) ), 'late bulk intake replay reconciles its issued request without re-reviewing changed customer data or rewriting the immutable Version' );
  $registration_key = 'qa_bulk_enrollment_' . $nonce; $fields = Ecapla_CertVerify_Fields::get_fields();
  $registration_field = $catalog[ $keys['personal'] ]; $registration_field['key'] = $registration_key; $registration_field['label'] = 'QA Enrollment Detail';
  $registration_field['collection_policy']['lifetime'] = 'registration'; $registration_field['collection_policy']['collect_when'] = 'before_issue'; $registration_field['collection_policy']['approval_required'] = false; $fields[] = $registration_field;
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'organization can collect a detail separately for each Program enrollment' );
  $catalog = Ecapla_CertVerify_Fields::get_field_map(); $metadata = $unwrap( ( new ScopedFieldContract() )->with_program_metadata( array(), array_fill_keys( array( $keys['personal'], $keys['result'], $registration_key ), 'required' ), $catalog ) );
  $enrollment_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Enrollment Program ' . $nonce, 'code' => 'QBPE-' . $nonce, 'metadata' => $metadata ) ) );
  $unwrap( $templates->assign( $design, 'program', $enrollment_program ) );
  $registrations = new \Certloom\Infrastructure\Repository\ProgramRegistrationRepository();
  $first_enrollment = $unwrap( $registrations->ensure( $recipient['id'], $enrollment_program, 0, 'manual', 'qa-bulk-enrollment-first-' . $nonce ) );
  $other_enrollment = $unwrap( $registrations->ensure( $recipient['id'], $enrollment_program, 0, 'manual', 'qa-bulk-enrollment-other-' . $nonce ) );
  $unwrap( $registrations->save_fields( $other_enrollment['id'], $other_enrollment['revision'], array( $registration_key => 'Only for other enrollment' ) ) );
  $ready_enrollment = $unwrap( $registrations->ensure( $waiting_recipient['id'], $enrollment_program, 0, 'manual', 'qa-bulk-enrollment-ready-' . $nonce ) );
  $unwrap( $registrations->save_fields( $ready_enrollment['id'], $ready_enrollment['revision'], array( $registration_key => 'Ready enrollment' ) ) );
  $enrollment_columns = $unwrap( ( new BulkIssuanceCsvSchema() )->columns( $unwrap( $bulk->csv_schema( $enrollment_program ) ) ) );
  $check( array( 'recipient_id', 'registration_id', 'certificate_number', 'custom:' . $keys['result'] ) === $enrollment_columns, 'Program CSV adds its exact enrollment ID instead of accepting enrollment-owned values' );
  $shared['program_id'] = $enrollment_program;
  $write_enrollments = static function ( array $rows ) use ( &$source, $enrollment_columns ) { $stream = fopen( $source, 'wb' ); fputcsv( $stream, $enrollment_columns, ',', '"', '' ); foreach ( $rows as $row ) { fputcsv( $stream, array( $row[0], $row[2], $row[1], $row[3] ), ',', '"', '' ); } fclose( $stream ); };
  $write_enrollments( array( array( $recipient['id'], 'QBE-' . $nonce, 0, '0' ) ) );
  $ambiguous = $drain( $unwrap( $bulk->enqueue_preflight( $source, 'qa-enrollment.csv', $actor, $shared ) ) );
  $check( ! $ambiguous['result']['ready_for_issue'] && 1 === $ambiguous['result']['summary']['errors'], 'multiple enrollments cannot silently select an arbitrary participation for a CSV row' );
  $write_enrollments( array( array( $recipient['id'], 'QBE-' . $nonce, $ready_enrollment['id'], '0' ) ) );
  $forged = $drain( $unwrap( $bulk->enqueue_preflight( $source, 'qa-enrollment.csv', $actor, $shared ) ) );
  $check( ! $forged['result']['ready_for_issue'] && 1 === $forged['result']['summary']['errors'], 'CSV cannot pair one customer with another customer enrollment' );
  $write_enrollments( array( array( $recipient['id'], 'QBE-' . $nonce, $first_enrollment['id'], '0' ), array( $waiting_recipient['id'], 'QBER-' . $nonce, $ready_enrollment['id'], '0' ) ) );
  $mixed = $drain( $unwrap( $bulk->enqueue_preflight( $source, 'qa-enrollment.csv', $actor, $shared ) ) );
  if ( ! $mixed['result']['ready_for_issue'] ) { throw new RuntimeException( 'Mixed review codes: ' . implode( ',', array_column( $mixed['result']['summary']['error_details'], 'code' ) ) ); }
  $check( $mixed['result']['ready_for_issue'] && 1 === $mixed['result']['summary']['ready'] && 1 === $mixed['result']['summary']['waiting'], 'one real CSV groups a ready customer and an exact-enrollment waiting customer' );
  $mixed_plan = $unwrap( ( new \Certloom\Infrastructure\Issuance\BulkIssuancePlanStore() )->read_slice( $mixed['result']['plan_path'], 0, 2 ) );
  $old_entry = $mixed_plan['entries'][0]; $changed_entry = $old_entry; $changed_entry['review_fingerprint'] = str_repeat( '0', 64 );
  $bad = ( new \Certloom\Application\Issuance\BulkWaitingRequestService() )->receive_reviewed( $changed_entry, $mixed['job_key'], $actor );
  $check( is_wp_error( $bad ) && 'certloom_bulk_row_review_stale' === $bad->get_error_code(), 'a changed waiting-row fingerprint cannot create qualified intake' );
  $mixed_commit = $drain( $unwrap( $bulk->enqueue_commit( $mixed['job_key'], $actor, $mixed['result']['review_fingerprint'], true, true ) ) );
  $check( 'succeeded' === $mixed_commit['status'] && 1 === $mixed_commit['result']['summary']['succeeded'] && 1 === $mixed_commit['result']['summary']['requests_accepted'] && 0 === $mixed_commit['result']['summary']['failed'], 'mixed durable commit issues its ready row and creates only a request for its waiting row' );
  $enrollment_request_id = $mixed_commit['result']['summary']['requests'][0]['request_id'];
  $enrollment_request = $unwrap( $requests->find( $enrollment_request_id ) );
  $check( (int) $enrollment_request['registration_id'] === (int) $first_enrollment['id'] && 'waiting' === $enrollment_request['status'], 'the waiting request pins the selected enrollment and ignores a populated sibling enrollment' );
  $bad = $collection->read_for_user( $user, $other_enrollment['id'], 0, $enrollment_request_id );
  $check( is_wp_error( $bad ) && 'certloom_recipient_collection_forbidden' === $bad->get_error_code(), 'a request completion form cannot be switched to a sibling enrollment' );
  wp_set_current_user( $user ); $enrollment_form = $unwrap( $collection->read_for_user( $user, $first_enrollment['id'], 0, $enrollment_request_id ) );
  $unwrap( $collection->save_for_user( $user, $first_enrollment['id'], $enrollment_form['revision'], array( $registration_key => '000ENROLL' ), $enrollment_request_id ) );
  wp_set_current_user( $actor ); $enrollment_issued = $unwrap( $pending_service->process( $enrollment_request_id ) );
  $enrollment_version = $unwrap( ( new CredentialRepository() )->find_version( $enrollment_issued['version_id'] ) );
  $check( 'issued' === $enrollment_issued['status'] && '000ENROLL' === $enrollment_version['snapshot']['fields']['values'][ $registration_key ] && 'Only for other enrollment' === $unwrap( $registrations->find( $other_enrollment['id'] ) )['fields'][ $registration_key ], 'completion releases only the selected enrollment and preserves its sibling values' );
  return new WP_Error( 'qa_rollback', 'Rollback all successful fixtures.' );
 } catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); return new WP_Error( 'qa_failed', 'Rollback failed fixtures.' ); }
} );
$cleanup = true; foreach ( array_unique( $owned_files ) as $path ) { if ( is_wp_error( $files->delete( $path ) ) || file_exists( $path ) ) { $cleanup = false; } }
if ( null !== $source && is_file( $source ) ) { $cleanup = unlink( $source ) && $cleanup; }
foreach ( $users as $user ) { clean_user_cache( $user ); } wp_set_current_user( 0 ); Ecapla_CertVerify_Fields::reset_runtime_cache();
$after_options = $options(); DatabaseOptions::invalidate( array_unique( array_merge( $before_options['keys'], $after_options['keys'] ) ) );
$after = $fingerprints(); $preserved = $before === $after && $before_options === $after_options;
$changed_tables = array_keys( array_diff_assoc( $after, $before ) ); $changed_options = array_unique( array_merge( array_keys( array_diff_assoc( $after_options['hashes'], $before_options['hashes'] ) ), array_keys( array_diff_assoc( $before_options['hashes'], $after_options['hashes'] ) ) ) );
$success = is_wp_error( $result ) && 'qa_rollback' === $result->get_error_code() && $preserved && $cleanup;
echo wp_json_encode( array( 'success' => $success, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'tracked_rows_options_cron_preserved' => $preserved, 'owned_files_removed' => $cleanup, 'changed_tables' => $changed_tables, 'changed_option_names' => $changed_options, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; exit( $success ? 0 : 1 );
