<?php
/** Exact Local, rollback-only lifecycle/notice/authoring write acceptance. Mail is intercepted. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local root and verified private snapshot required.\n" ); exit( 1 ); }
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { fwrite( STDERR, "Refusing another site/database.\n" ); exit( 1 ); }
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
$mail_calls = 0; $mail_accept = true; $mail_bodies = array();
add_filter( 'pre_wp_mail', static function ( $return, $atts ) use ( &$mail_calls, &$mail_accept, &$mail_bodies ) { ++$mail_calls; $mail_bodies[] = $atts['message']; return $mail_accept; }, PHP_INT_MAX, 2 );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'No external transport.' ); }, PHP_INT_MAX );
use Certloom\Application\Issuance\PendingRequestPolicy;
use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Recipient\RecipientNoticeService;
use Certloom\Application\Recipient\RecipientNoticeRecord;
use Certloom\Infrastructure\Recipient\RecipientNoticeLedger;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;
$checks = array(); $failure = null; $users = array();
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$unwrap = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; };
$fingerprint = static function () {
 global $wpdb; $out = array();
 $tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->options ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients', 'issuers', 'programs', 'batches', 'signers', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map', 'program_registrations', 'field_submissions', 'issuance_requests', 'issuance_request_events' ) ) );
 foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Preservation read failed.' ); } $out[$table] = hash( 'sha256', wp_json_encode( $rows ) ); }
 return $out;
};
$before = $fingerprint();
$result = ( new Transaction() )->run( static function () use ( $unwrap, $check, &$users, &$failure, &$mail_calls, &$mail_accept, &$mail_bodies ) {
 try {
  global $wpdb; $nonce = bin2hex( random_bytes( 6 ) ); $actor = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0] ?? 0 ); wp_set_current_user( $actor );
  $policy_service = new PendingRequestPolicy(); $old_policy = $unwrap( $policy_service->read() );
  $policy = $policy_service::defaults(); $policy['reminder_days'] = 1; $policy['expiry_days'] = 30; $policy['cancellation_notice'] = true; $policy['completion_notice'] = true;
  $check( true === $unwrap( $policy_service->save( $actor, $policy, $policy_service->fingerprint( $old_policy ) ) ), 'organization saves typed policy under its exact revision' );
  $check( is_wp_error( $policy_service->save( $actor, $old_policy, $policy_service->fingerprint( $old_policy ) ) ), 'a stale policy tab cannot overwrite newer settings' );
  $users[] = $user = $unwrap( wp_insert_user( array( 'user_login' => 'qa-lifecycle-' . $nonce, 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'qa-' . $nonce . '@example.test', 'role' => 'subscriber' ) ) );
  $recipient = $unwrap( ( new RecipientRepository() )->find_by_wp_user_id( $user ) ); $check( is_array( $recipient ), 'recipient is connected by exact WordPress account identity' );
  $check( is_wp_error( $policy_service->save( $user, $old_policy, $policy_service->fingerprint( $policy ) ) ), 'the recipient cannot configure organizational policy' );
  $key = 'qa_lifecycle_id_' . $nonce; $fields = Ecapla_CertVerify_Fields::get_fields();
  $fields[] = array( 'key' => $key, 'label' => 'QA identity', 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => array( '_meta' => array( 'schema' => \Certloom\Application\Fields\FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => 'recipient', 'lifetime' => 'recipient', 'collect_when' => 'before_issue', 'approval_required' => false, 'show_on_certificate' => false, 'help' => '' ) );
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'owned private collection field appended safely' );
  $unwrap( ( new ScopedFieldGlobalRepository() )->save( array(), Ecapla_CertVerify_Fields::get_field_map() ) );
  $metadata = $unwrap( ( new \Certloom\Application\Fields\ScopedFieldContract() )->with_program_metadata( array(), array( $key => 'required' ), Ecapla_CertVerify_Fields::get_field_map() ) );
  $program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Lifecycle ' . $nonce, 'code' => 'QLIFE-' . $nonce, 'metadata' => $metadata ) ) );
  $requests = new IssuanceRequestRepository(); $pending = new PendingIssuanceService(); $notices = new RecipientNoticeService(); $ledger = new RecipientNoticeLedger();
  $receive = static function ( $i, $age = 1 ) use ( $unwrap, $requests, $recipient, $program, $nonce, $pending ) {
   global $wpdb; $row = $unwrap( $requests->receive( array( 'recipient_id' => $recipient['id'], 'program_id' => $program, 'source_type' => 'manual', 'intake_key' => hash( 'sha256', $nonce . ':' . $i ) ), array( 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QLIFE-' . $nonce . '-' . $i ), array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => 'unknown', 'checked_at' => current_time( 'mysql', true ), 'valid_until' => null, 'fields' => array() ) ) );
   $unwrap( $pending->process( $row['id'] ) );
   $wpdb->last_error = ''; $saved = $wpdb->update( SchemaManager::table( 'issuance_requests' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS - 60 ) ), array( 'id' => $row['id'] ) ); if ( 1 !== $saved || $wpdb->last_error ) { throw new RuntimeException( 'Owned age staging failed.' ); }
   return $unwrap( $requests->find( $row['id'] ) );
  };
  $waiting = $receive( 1 ); $job_key = $unwrap( $notices->queue( $waiting['id'] ) ); $check( is_string( $job_key ), 'missing recipient detail queues a distinct notice without a Version' );
  $recipient_before_noop = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'recipients' ), $recipient['id'] ), ARRAY_A ); $generation_before_noop = $unwrap( ( new DomainGeneration() )->read_current() ); $request_before_noop = $unwrap( $requests->find( $waiting['id'] ) );
  $recipient_writes = 0; $observe_noop = static function ( $query ) use ( &$recipient_writes ) { if ( preg_match( '/^UPDATE\s+`?' . preg_quote( SchemaManager::table( 'recipients' ), '/' ) . '`?\s/i', $query ) ) { ++$recipient_writes; } return $query; }; add_filter( 'query', $observe_noop, PHP_INT_MAX );
  try { $noop = ( new RecipientRepository() )->update( $recipient['id'], array( 'display_name' => $recipient['display_name'], 'email' => $recipient['email'], 'wp_user_id' => $recipient['wp_user_id'] ) ); } finally { remove_filter( 'query', $observe_noop, PHP_INT_MAX ); }
  $check( true === $unwrap( $noop ) && 0 === $recipient_writes, 'identical account facts are confirmed under a current row lock without a recipient UPDATE' );
  $check( $recipient_before_noop === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'recipients' ), $recipient['id'] ), ARRAY_A ) && $generation_before_noop === $unwrap( ( new DomainGeneration() )->read_current() ), 'account refresh preserves exact recipient bytes and the approved domain generation' );
  $check( $request_before_noop === $unwrap( $requests->find( $waiting['id'] ) ), 'account refresh cannot advance or reschedule a waiting certificate request' );
  $job = $unwrap( ( new \Certloom\Infrastructure\Job\JobRepository() )->find( $job_key ) ); $notice_key = $job['payload']['notice_key'];
  $check( null === $unwrap( $notices->queue( $waiting['id'] ) ), 'repeated sweep cannot queue the same reminder again' );
  $other = $receive( 2 ); $check( null === $unwrap( $notices->queue( $other['id'] ) ), 'overlapping requests reserve one daily recipient notice' );
  $delivered = $unwrap( $notices->deliver( $waiting['id'], $notice_key, $job_key ) ); $check( 'sent' === $delivered['state'] && 1 === $mail_calls, 'one committed claim records intercepted mail acceptance exactly once' );
  $check( 'sent' === $unwrap( $notices->deliver( $waiting['id'], $notice_key, $job_key ) )['state'] && 1 === $mail_calls, 'job replay observes its receipt and never calls mail twice' );
  $check( false === strpos( implode( '', $mail_bodies ), $key ) && false === strpos( implode( '', $mail_bodies ), 'QLIFE-' ) && false !== strpos( implode( '', $mail_bodies ), '?certloom_account=1' ), 'notice contains only generic guidance and a safe account return link' );
  $current = $unwrap( $requests->find( $waiting['id'] ) ); $check( null === $current['version_id'] && 'waiting' === $current['status'], 'sending a reminder cannot issue or qualify the request' );
  $old = $receive( 3, 31 ); $closed = $unwrap( $pending->expire( $old['id'] ) );
  $check( 'cancelled' === $closed['status'] && '[{"code":"request_expired"}]' === $closed['blockers_json'] && null === $closed['next_attempt_at'] && null === $closed['version_id'], 'expired unissued request closes durably without deleting answers or issuing' );
  $check( $closed === $unwrap( $pending->expire( $old['id'] ) ), 'expiry replay is a confirmed terminal no-op' );
  $check( 'expired' === ( new \Certloom\Application\Recipient\RecipientIssuanceRequests() )->read_for_user( $user, $old['id'] - 1, 'closed' )['items'][0]['notice'], 'recipient receives a distinct request deadline explanation' );
  $restored = $receive( 4, 31 ); $wpdb->update( SchemaManager::table( 'issuance_requests' ), array( 'restore_state' => 'paused' ), array( 'id' => $restored['id'] ) );
  $check( 'waiting' === $unwrap( $pending->expire( $restored['id'] ) )['status'] && null === $unwrap( $notices->queue( $restored['id'] ) ), 'restored paused request neither expires nor queues transport' );
  // Isolate transport boundaries from the daily queue limit using owned,
  // schema-valid queued events. These never leave the intercepted transport.
  $stage_notice = static function ( $row ) use ( $unwrap, $ledger, $user ) {
   $key = RecipientNoticeRecord::key( $row['public_ref'], 'reminder', 1 );
   $job = $unwrap( ( new \Certloom\Application\Job\JobService( new \Certloom\Infrastructure\Job\JobRepository() ) )->create( \Certloom\Domain\Job\JobTypes::RECIPIENT_NOTICE, 0, array( 'schema_version' => 1, 'request_id' => $row['id'], 'notice_key' => $key ), 1 ) );
   $meta = array( 'version' => 1, 'notice_key' => $key, 'kind' => 'reminder', 'slot' => 1, 'state' => 'queued', 'user_id' => $user, 'destination_sha256' => hash( 'sha256', strtolower( get_userdata( $user )->user_email ) ), 'job_key' => $job, 'attempt_sha256' => null );
   $unwrap( $ledger->append( $row, $meta ) ); return array( $key, $job, $meta );
  };
  $skippable = $receive( 5 ); list( $skip_key, $skip_job ) = $stage_notice( $skippable );
  $disabled = $policy; $disabled['reminder_days'] = 0; $unwrap( $policy_service->save( $actor, $disabled, $policy_service->fingerprint( $policy ) ) );
  $calls = $mail_calls; $check( 'skipped' === $unwrap( $notices->deliver( $skippable['id'], $skip_key, $skip_job ) )['state'] && $calls === $mail_calls, 'turning reminders off suppresses already queued transport' );
  $unwrap( $policy_service->save( $actor, $policy, $policy_service->fingerprint( $disabled ) ) );
  $faulty = $receive( 6 ); list( $fault_key, $fault_job ) = $stage_notice( $faulty );
  $check( is_wp_error( $notices->deliver( $faulty['id'], $fault_key, str_repeat( 'f', 32 ) ) ) && $mail_calls === $calls, 'a foreign job cannot claim a recipient notice' );
  $sql_error = static function ( $sql ) { return 0 === strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, 'issuance_request_events' ) && false !== strpos( $sql, 'notice_sent' ) ? 'INSERT INTO certloom_missing_notice_result_qa VALUES (1)' : $sql; };
  $old_errors = $wpdb->suppress_errors( true ); add_filter( 'query', $sql_error, PHP_INT_MAX );
  try { $bad = $notices->deliver( $faulty['id'], $fault_key, $fault_job ); } finally { remove_filter( 'query', $sql_error, PHP_INT_MAX ); $wpdb->suppress_errors( $old_errors ); }
  $check( is_wp_error( $bad ) && $calls + 1 === $mail_calls && is_array( $unwrap( $ledger->find( $faulty['id'], RecipientNoticeRecord::event_key( $fault_key, 'sending' ) ) ) ), 'accepted transport with failed receipt persistence retains its committed attempt' );
  $check( 'uncertain' === $unwrap( $notices->deliver( $faulty['id'], $fault_key, $fault_job ) )['state'] && $calls + 1 === $mail_calls, 'interrupted attempt becomes uncertain without sending a second notice' );
  $rejected = $receive( 7 ); list( $rejected_key, $rejected_job ) = $stage_notice( $rejected ); $mail_accept = false; $calls = $mail_calls;
  $check( 'failed' === $unwrap( $notices->deliver( $rejected['id'], $rejected_key, $rejected_job ) )['state'] && $calls + 1 === $mail_calls, 'rejected intercepted mail records an exact failed attempt' );
  $mail_accept = true; $check( 'failed' === $unwrap( $notices->deliver( $rejected['id'], $rejected_key, $rejected_job ) )['state'] && $calls + 1 === $mail_calls, 'replaying a failed notice job cannot resend it' );
  $cancelled = $receive( 8 ); list( $cancelled_key, $cancelled_job ) = $stage_notice( $cancelled );
  $unwrap( $requests->transition( $cancelled['id'], $cancelled['revision'], array( 'status' => 'cancelled', 'next_attempt_at' => null ), 'organization_cancelled', $actor ) ); $calls = $mail_calls;
  $check( 'skipped' === $unwrap( $notices->deliver( $cancelled['id'], $cancelled_key, $cancelled_job ) )['state'] && $calls === $mail_calls, 'a cancelled request cannot send its old reminder' );
  $unlinked = $receive( 9 ); list( $unlinked_key, $unlinked_job ) = $stage_notice( $unlinked );
  $wpdb->update( SchemaManager::table( 'recipients' ), array( 'wp_user_id' => null ), array( 'id' => $recipient['id'] ) );
  $check( 'skipped' === $unwrap( $notices->deliver( $unlinked['id'], $unlinked_key, $unlinked_job ) )['state'] && $calls === $mail_calls, 'unlinking account ownership suppresses queued mail without guessing an address' );
  $wpdb->update( SchemaManager::table( 'recipients' ), array( 'wp_user_id' => $user ), array( 'id' => $recipient['id'] ) );
  $completed = $receive( 10 ); list( $completed_key, $completed_job ) = $stage_notice( $completed );
  $collection = new \Certloom\Application\Recipient\RecipientCollectionService(); $form = $unwrap( $collection->read_for_user( $user, 0, 0, $completed['id'] ) );
  $unwrap( $collection->save_for_user( $user, 0, $form['revision'], array( $key => '00123' ), $completed['id'] ) );
  $check( 'skipped' === $unwrap( $notices->deliver( $completed['id'], $completed_key, $completed_job ) )['state'] && $calls === $mail_calls, 'completing the actual recipient field suppresses its obsolete reminder' );
  $receipt_request = $receive( 11 );
  $evidence = json_decode( $receipt_request['evidence_json'], true ); $evidence['eligibility'] = 'eligible';
  $receipt_request = $unwrap( $requests->update_evidence( $receipt_request['id'], $receipt_request['revision'], hash( 'sha256', $nonce . ':eligible' ), $evidence, $actor ) );
  $issued = $unwrap( $pending->process( $receipt_request['id'] ) );
  $check( 'issued' === $issued['status'] && $issued['version_id'] > 0, 'eligible completed details issue through the actual canonical boundary' );
  $check( $issued === $unwrap( $pending->expire( $issued['id'] ) ), 'request expiry preserves the issued immutable receipt' );
  $wpdb->last_error = ''; $staged = $wpdb->update( SchemaManager::table( 'issuance_requests' ), array( 'status' => 'processing', 'series_id' => null, 'version_id' => null, 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'id' => $issued['id'] ) );
  $check( 1 === $staged && '' === $wpdb->last_error, 'owned interrupted request receipt staged without changing its Version' );
  $reconciled = $unwrap( $pending->expire( $issued['id'] ) );
  $check( 'issued' === $reconciled['status'] && $issued['version_id'] === $reconciled['version_id'], 'expiry reconciles a committed canonical Version before applying its overdue deadline' );
  $runtime = new \Certloom\Infrastructure\Job\JobRuntime(); $coordinator = new \Certloom\Infrastructure\Recipient\PendingLifecycleCoordinator( $runtime->service(), $runtime->dispatcher() );
  $lifecycle_key = $unwrap( $coordinator->dispatch() ); $check( is_string( $lifecycle_key ) && $lifecycle_key === $unwrap( $coordinator->dispatch() ), 'repeated daily sweep signals coalesce into one durable job' );
  $lifecycle_job = $unwrap( $runtime->repository()->find( $lifecycle_key ) );
  // The worker boundary is exercised on the owned primary-key interval, rather
  // than allowing this rollback-only fixture to assess original requests.
  $lifecycle_job['cursor'] = $waiting['id'] - 1; $lifecycle_job['payload']['max_id'] = $reconciled['id'];
  $handler = new \Certloom\Application\Issuance\PendingLifecycleJobHandler(); $slice = $unwrap( $handler->handle( $lifecycle_job, bin2hex( random_bytes( 16 ) ) ) );
  $check( 10 === $slice->result()['processed'] && $slice->cursor() > $lifecycle_job['cursor'], 'lifecycle slice checks exactly ten owned primary-key rows and persists continuation' );
  $lifecycle_job['cursor'] = $slice->cursor(); $lifecycle_job['result'] = $slice->result(); $slice = $unwrap( $handler->handle( $lifecycle_job, bin2hex( random_bytes( 16 ) ) ) );
  $check( 11 === $slice->result()['processed'], 'lifecycle continuation completes the remaining owned row without replaying transport' );
  // Activation is deliberately prospective, including after disable/re-enable.
  $users[] = $terminal_user = $unwrap( wp_insert_user( array( 'user_login' => 'qa-terminal-' . $nonce, 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'terminal-' . $nonce . '@example.test', 'role' => 'subscriber' ) ) );
  $terminal_recipient = $unwrap( ( new RecipientRepository() )->find_by_wp_user_id( $terminal_user ) );
  $terminal = $unwrap( $requests->receive( array( 'recipient_id' => $terminal_recipient['id'], 'program_id' => $program, 'source_type' => 'manual', 'intake_key' => hash( 'sha256', $nonce . ':terminal' ) ), array( 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QLIFE-' . $nonce . '-terminal' ), array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => 'unknown', 'checked_at' => current_time( 'mysql', true ), 'valid_until' => null, 'fields' => array() ) ) );
  $terminal = $unwrap( $requests->transition( $terminal['id'], $terminal['revision'], array( 'status' => 'cancelled', 'next_attempt_at' => null ), 'organization_cancelled', $actor ) );
  $since = $unwrap( $policy_service->read_notice_since() );
  $wpdb->update( SchemaManager::table( 'issuance_requests' ), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', strtotime( $since['cancellation'] . ' UTC' ) - 1 ) ), array( 'id' => $terminal['id'] ) );
  $check( null === $unwrap( $notices->queue( $terminal['id'] ) ), 'enabling cancellation notices never sends the historical closed backlog' );
  $wpdb->update( SchemaManager::table( 'issuance_requests' ), array( 'updated_at' => $since['cancellation'] ), array( 'id' => $terminal['id'] ) );
  $terminal_job = $unwrap( $notices->queue( $terminal['id'] ) );
  $check( is_string( $terminal_job ), 'a newly closed request queues its distinct recipient-bound cancellation notice' );
  $terminal_payload = $unwrap( ( new \Certloom\Infrastructure\Job\JobRepository() )->find( $terminal_job ) )['payload'];
  $calls = $mail_calls;
  $check( 'sent' === $unwrap( $notices->deliver( $terminal['id'], $terminal_payload['notice_key'], $terminal_job ) )['state'] && $calls + 1 === $mail_calls, 'new cancellation notice uses one intercepted transport and a durable receipt' );
  $off = $policy; $off['cancellation_notice'] = false;
  $unwrap( $policy_service->save( $actor, $off, $policy_service->fingerprint( $policy ) ) );
  $check( null === $unwrap( $policy_service->read_notice_since() )['cancellation'], 'disabling terminal notices clears their activation boundary' );
  $unwrap( $policy_service->save( $actor, $policy, $policy_service->fingerprint( $off ) ) );
  $check( null !== $unwrap( $policy_service->read_notice_since() )['cancellation'] && null === $unwrap( $notices->queue( $terminal['id'] ) ), 're-enabling notices preserves past receipts and does not resend a closure' );
  // Check every explicit raw writer against actual SQL, preserving original
  // bytes between cases and rolling all effects back at the outer boundary.
  $writer = new \Certloom\Infrastructure\Data\DurableOptionMutation();
  $read_raw = static function ( $name ) { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $name ), ARRAY_A ); return is_array( $row ) ? $row['option_value'] : null; };
  foreach ( \Certloom\Application\Backup\WholeStateOptionInventory::keys() as $option_name ) {
   $original_raw = $read_raw( $option_name );
   $sentinel = 'qa-durable-' . $nonce;
   $check( true === $unwrap( $writer->set( $option_name, $sentinel ) ) && $sentinel === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $option_name ) ), 'bounded raw mutation commits exact bytes: ' . $option_name );
   $generation = $unwrap( ( new DomainGeneration() )->read_current() );
   $check( true === $unwrap( $writer->set( $option_name, $sentinel ) ) && $generation === $unwrap( ( new DomainGeneration() )->read_current() ), 'identical raw mutation is a confirmed generation no-op: ' . $option_name );
   $unwrap( $writer->change( $option_name, static function () use ( $original_raw ) { return $original_raw; } ) );
  }
  $scalar_option = 'certloom_safety_backup_retention';
  $scalar_original = $read_raw( $scalar_option );
  foreach ( array( 0, 7, false, true, null, '00123' ) as $scalar ) {
   $check( true === $unwrap( $writer->set( $scalar_option, $scalar ) ) && (string) maybe_serialize( $scalar ) === $read_raw( $scalar_option ), 'raw writer preserves WordPress scalar storage: ' . gettype( $scalar ) );
  }
  $unwrap( $writer->change( $scalar_option, static function () use ( $scalar_original ) { return $scalar_original; } ) );
  $check( is_wp_error( $writer->set( 'certloom_unknown_qa_setting', 'forbidden' ) ) && null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, 'certloom_unknown_qa_setting' ) ), 'writer refuses an unowned option namespace' );
  $check( is_wp_error( $writer->set( $scalar_option, str_repeat( 'x', \Certloom\Application\Backup\WholeStateOptionInventory::MAX_OPTION_BYTES + 1 ) ) ), 'writer refuses an oversized value before persistence' );
  $lock = new \Certloom\Infrastructure\Data\DomainMaintenanceLock(); $lock_owner = bin2hex( random_bytes( 16 ) );
  $unwrap( $lock->acquire( $lock_owner, 'qa_durable_writer', 120 ) );
  try { $check( is_wp_error( $writer->set( $scalar_option, 42 ) ) && $scalar_original === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, $scalar_option ) ), 'active recovery fence refuses ordinary durable settings mutation' ); }
  finally { $unwrap( $lock->release( $lock_owner ) ); }
  $before_generation = $unwrap( ( new DomainGeneration() )->read_current() );
  $zero = static function ( $sql ) { return 0 === strpos( $sql, 'UPDATE `' ) && false !== strpos( $sql, 'option_value' ) && false !== strpos( $sql, "'certverify_settings'" ) ? $sql . ' AND 1=0' : $sql; };
  add_filter( 'query', $zero, PHP_INT_MAX );
  try { $changed = $policy; $changed['expiry_days'] = 29; $bad = $policy_service->save( $actor, $changed, $policy_service->fingerprint( $policy ) ); } finally { remove_filter( 'query', $zero, PHP_INT_MAX ); }
  $check( is_wp_error( $bad ) && $policy === $unwrap( $policy_service->read() ) && $before_generation === $unwrap( ( new DomainGeneration() )->read_current() ), 'zero-row settings mutation rolls back policy and generation' );
  $recommendations = new \Certloom\Infrastructure\Repository\TemplateRecommendationRepository();
  $check( true === $unwrap( $recommendations->save( 9000001, array( 'training_lms' ) ) ) && array( 'training_lms' ) === $recommendations->get( 9000001 ), 'recommendation metadata uses the durable boundary' );
  $check( true === $unwrap( $recommendations->delete( 9000001 ) ) && false === get_option( $recommendations::OPTION_PREFIX . 9000001, false ), 'recommendation deletion is checked and complete' );
  $run = new \Certloom\Infrastructure\Email\EmailAutomationRunRepository(); $check( $run->record( 'qa_' . $nonce, 0, 'manual_admin', 'skipped', 'qa' ), 'automation outcome appends against locked current storage' );
  return new WP_Error( 'qa_rollback', 'Owned fixture complete; roll back.' );
 } catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); return new WP_Error( 'qa_failed', 'Roll back failed fixture.' ); }
} );
foreach ( $users as $id ) { clean_user_cache( $id ); wp_cache_delete( $id, 'user_meta' ); }
DatabaseOptions::invalidate( array( 'cron', 'certverify_fields', 'certverify_settings', 'certloom_global_scoped_fields_v1', 'certloom_email_automation_runs_v1', 'certloom_pending_lifecycle_job' ) );
$after = $fingerprint(); $preserved = $before === $after;
$success = is_wp_error( $result ) && 'qa_rollback' === $result->get_error_code() && $preserved;
echo wp_json_encode( array( 'success' => $success, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'original_state_unchanged' => $preserved, 'changed_scopes' => array_keys( array_diff_assoc( $after, $before ) ), 'mail_transport' => 'intercepted; no messages sent', 'mail_calls' => $mail_calls, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; exit( $success ? 0 : 1 );
