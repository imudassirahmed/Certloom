<?php
/** Real Local organization request operations, private reads and canonical reconciliation. Every fixture is rolled back. */
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

use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Issuance\PendingRequestAdminService;
use Certloom\Infrastructure\Admin\PendingRequestRest;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Repository\RecipientWorkflowStore;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;

$checks = array(); $failure = null; $get_mutations = array();
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
 $hashes = array(); foreach ( $rows as $row ) { $hashes[ $row['option_name'] ] = hash( 'sha256', wp_json_encode( $row ) ); }
 return array( 'sha256' => hash( 'sha256', wp_json_encode( $rows ) ), 'keys' => array_column( $rows, 'option_name' ), 'hashes' => $hashes );
};
$before = $fingerprints(); $before_options = $option_fingerprint();
$qa_users = array();
$result = ( new Transaction() )->run( static function () use ( $check, $unwrap, $fingerprints, $option_fingerprint, &$failure, &$qa_users, &$get_mutations ) {
 try {
  global $wpdb;
  $nonce = bin2hex( random_bytes( 6 ) );
  $admin_ids = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
  $actor = (int) ( $admin_ids[0] ?? 0 ); wp_set_current_user( $actor );
  $check( $actor && current_user_can( Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) && current_user_can( Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ), 'existing administrator has separate management and issuance capabilities' );
  foreach ( array( 'subscriber', 'manager' ) as $role ) {
   $id = $unwrap( wp_insert_user( array( 'user_login' => 'qa-requests-' . $role . '-' . $nonce, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'QA Request ' . $role ) ) );
   $qa_users[] = $id;
   if ( 'manager' === $role ) { ( new WP_User( $id ) )->add_cap( Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ); $manager = $id; } else { $subscriber = $id; }
  }
  $service = new PendingRequestAdminService(); $requests = new IssuanceRequestRepository(); $store = new RecipientWorkflowStore(); $pending = new PendingIssuanceService();
  $recipient = $unwrap( ( new RecipientRepository() )->create( array( 'display_name' => 'QA Requests Recipient ' . $nonce, 'custom_fields' => array() ) ) );
  $program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Requests Program ' . $nonce, 'code' => 'QRQ-' . $nonce ) ) );
  $unwrap( ( new ScopedFieldGlobalRepository() )->save( array(), Ecapla_CertVerify_Fields::get_field_map() ) );
  $receive = static function ( $index, $eligibility = 'unknown', $source = 'manual', $mapping = null, $program_id = 0 ) use ( $unwrap, $nonce, $recipient, $program, $requests ) {
   return $unwrap( $requests->receive( array( 'recipient_id' => $recipient, 'program_id' => $program_id ?: $program, 'source_type' => $source, 'source_ref' => 'private-source-' . $nonce . '-' . $index, 'intake_key' => hash( 'sha256', $nonce . '-' . $index ), 'mapping_sha256' => $mapping ), array( 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QRQ-' . $nonce . '-' . $index ), array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => $eligibility, 'checked_at' => current_time( 'mysql', true ), 'valid_until' => null, 'fields' => array() ) ) );
  };
  $immutable = static function ( $row ) { return array_intersect_key( $row, array_flip( array( 'recipient_id', 'program_id', 'batch_id', 'registration_id', 'source_type', 'source_ref', 'intake_key', 'payload_sha256', 'intake_json', 'evidence_json', 'evidence_revision', 'mapping_sha256', 'restore_state', 'attempt_count' ) ) ); };
  $waiting = $unwrap( $pending->process( $receive( 1 )['id'] ) );
  $check( 'waiting' === $waiting['status'] && null === $waiting['next_attempt_at'] && null === $waiting['version_id'], 'unknown qualification remains a held unissued request' );
  foreach ( array( static function () use ( $service, $subscriber ) { return $service->queue( $subscriber ); }, static function () use ( $service, $subscriber, $waiting ) { return $service->history( $subscriber, $waiting['id'] ); }, static function () use ( $service, $subscriber, $waiting ) { return $service->command( $subscriber, $waiting['id'], $waiting['revision'], 'cancel' ); }, static function () use ( $service, $manager, $waiting ) { return $service->command( $manager, $waiting['id'], $waiting['revision'], 'recheck' ); } ) as $i => $operation ) {
   $denied = $operation(); $check( is_wp_error( $denied ) && 403 === $denied->get_error_data()['status'], 'service enforces capability separation, case ' . $i );
  }
  $before_read = $fingerprints(); $before_read_options = $option_fingerprint();
  $queue = $unwrap( $service->queue( $actor, 'waiting' ) ); $history = $unwrap( $service->history( $manager, $waiting['id'] ) );
  $check( $before_read === $fingerprints() && $before_read_options === $option_fingerprint(), 'queue and history GET services do not mutate any tracked business row or option' );
  $encoded = wp_json_encode( array( $queue, $history ) );
  $check( false === strpos( $encoded, $waiting['intake_key'] ) && false === strpos( $encoded, $waiting['source_ref'] ) && false === strpos( $encoded, 'evidence_json' ) && false === strpos( $encoded, 'mapping_sha256' ), 'private staff summaries omit intake, source references, evidence and mapping payloads' );
  $check( $queue['items'][0]['can_recheck'] && $queue['items'][0]['can_cancel'] && 'source' === $queue['items'][0]['blockers'][0]['owner'], 'held qualification has trusted-source guidance and permitted operational actions' );
  $stale = $service->command( $actor, $waiting['id'], $waiting['revision'] - 1, 'cancel' );
  $check( is_wp_error( $stale ) && 409 === $stale->get_error_data()['status'] && $waiting === $unwrap( $requests->find( $waiting['id'] ) ), 'a stale revision cannot cancel or append an event' );
  $receipt = $unwrap( $service->command( $actor, $waiting['id'], $waiting['revision'], 'recheck' ) );
  $queued = $unwrap( $requests->find( $waiting['id'] ) );
  $check( 'queued' === $receipt['outcome'] && $queued['revision'] === $waiting['revision'] + 1 && null !== $queued['next_attempt_at'] && null === $queued['review_sha256'] && null === $queued['version_id'] && $immutable( $waiting ) === $immutable( $queued ), 'recheck persists a due hint and clears old review without changing source facts or issuing inline' );
  $history = $unwrap( $service->history( $actor, $waiting['id'] ) );
  $check( 'Organization staff' === $history['items'][ count( $history['items'] ) - 1 ]['actor'] && false !== strpos( $history['items'][ count( $history['items'] ) - 1 ]['label'], 'fresh assessment' ), 'recheck appends privacy-safe staff activity' );
  $held = $unwrap( $pending->process( $waiting['id'] ) );
  $check( 'waiting' === $held['status'] && null === $held['version_id'] && $held['evidence_json'] === $waiting['evidence_json'], 'the canonical worker still refuses unknown qualification after staff recheck' );
  $cancelled = $unwrap( $service->command( $manager, $held['id'], $held['revision'], 'cancel' ) );
  $closed = $unwrap( $requests->find( $held['id'] ) );
  $check( 'cancelled' === $cancelled['outcome'] && null === $closed['next_attempt_at'] && $immutable( $held ) === $immutable( $closed ), 'management-only staff can cancel unissued intent without erasing answers or source evidence' );
  $closed_summary = $unwrap( $service->queue( $actor, 'cancelled' ) );
  $check( ! $closed_summary['items'][0]['blockers'] && ! $closed_summary['items'][0]['can_cancel'] && ! $closed_summary['items'][0]['can_recheck'] && $closed['blockers_json'] === $held['blockers_json'], 'closed cards suppress old actionable blockers while preserving their private record and history' );
  $again = $service->command( $actor, $closed['id'], $closed['revision'], 'recheck' );
  $check( is_wp_error( $again ) && 409 === $again->get_error_data()['status'] && $closed === $unwrap( $pending->process( $closed['id'] ) ), 'cancelled intent is terminal and cannot resume via staff recheck or a worker' );

  foreach ( array( 'not_eligible', 'paused', 'mapped', 'external' ) as $i => $mode ) {
   $row = $receive( 10 + $i, 'not_eligible' === $mode ? 'not_eligible' : 'unknown', 'external' === $mode ? 'webhook' : 'manual', 'mapped' === $mode ? str_repeat( 'a', 64 ) : null );
   if ( 'not_eligible' === $mode ) { $row = $unwrap( $pending->process( $row['id'] ) ); }
   if ( 'paused' === $mode ) { $row = $unwrap( $store->patch( 'issuance_requests', $row['id'], $row['revision'], array( 'restore_state' => 'paused' ) ) ); }
   if ( 'paused' === $mode ) { $summaries = $unwrap( $service->queue( $actor, 'all' ) ); $mine = array_values( array_filter( $summaries['items'], static function ( $item ) use ( $row ) { return $item['id'] === $row['id']; } ) ); $check( 'Held after restore' === $mine[0]['status_label'], 'restored hold is visible in the staff status badge without changing its durable lifecycle state' ); }
   $rejected = $service->command( $actor, $row['id'], $row['revision'], 'recheck' );
   $check( is_wp_error( $rejected ) && 409 === $rejected->get_error_data()['status'] && $row === $unwrap( $requests->find( $row['id'] ) ), $mode . ' cannot gain execution authority through staff recheck' );
   $unwrap( $service->command( $actor, $row['id'], $row['revision'], 'cancel' ) );
   $check( $immutable( $row ) === $immutable( $unwrap( $requests->find( $row['id'] ) ) ), $mode . ' cancellation preserves the original hold and evidence authority' );
  }

  $rollback = $receive( 20 ); $signals = 0;
  $observe = static function () use ( &$signals ) { ++$signals; }; add_action( 'certloom_issuance_request_changed', $observe );
  foreach ( array( 'event_insert', 'request_update', 'zero_rows' ) as $mode ) { foreach ( array( 'recheck', 'cancel' ) as $action ) {
   $pre = $fingerprints(); $opts = $option_fingerprint(); $generation = ( new DomainGeneration() )->current();
   $inject = static function ( $sql ) use ( $mode, $nonce ) { global $wpdb;
    if ( ( 'event_insert' === $mode && 0 === strpos( $sql, 'INSERT INTO ' ) && false !== strpos( $sql, SchemaManager::table( 'issuance_request_events' ) ) ) || ( 'request_update' === $mode && 0 === strpos( $sql, 'UPDATE ' ) && false !== strpos( $sql, SchemaManager::table( 'issuance_requests' ) ) ) ) { return 'UPDATE `__certloom_qa_missing_' . $nonce . '` SET id=0 WHERE 1=0'; }
    if ( 'zero_rows' === $mode && 0 === strpos( $sql, 'UPDATE ' ) && false !== strpos( $sql, SchemaManager::table( 'issuance_requests' ) ) ) { return 'UPDATE `' . SchemaManager::table( 'issuance_requests' ) . '` SET revision=revision WHERE 1=0'; } return $sql;
   };
   $old = $wpdb->suppress_errors( true ); add_filter( 'query', $inject, PHP_INT_MAX );
   try { $rejected = $service->command( $actor, $rollback['id'], $rollback['revision'], $action ); } finally { remove_filter( 'query', $inject, PHP_INT_MAX ); $wpdb->suppress_errors( $old ); }
   $check( is_wp_error( $rejected ) && 0 === $signals && $generation === ( new DomainGeneration() )->current() && $pre === $fingerprints() && $opts === $option_fingerprint(), $action . ' ' . $mode . ' rolls back request, event, generation and hints without a scheduling signal' );
  } }
  remove_action( 'certloom_issuance_request_changed', $observe );

  // A real immutable Version is created first; only the fixture's receipt row is interrupted.
  $canonical_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Receipt Program ' . $nonce, 'code' => 'QRC-' . $nonce ) ) );
  $issued = $unwrap( $pending->process( $receive( 30, 'eligible', 'manual', null, $canonical_program )['id'] ) );
  $check( 'issued' === $issued['status'], 'canonical worker creates an actual details-only Version with current trusted qualification' );
  $version_before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'credential_versions' ), $issued['version_id'] ), ARRAY_A );
  foreach ( array( 'cancel', 'recheck' ) as $action ) {
   $wpdb->last_error = ''; $count = $wpdb->update( SchemaManager::table( 'issuance_requests' ), array( 'status' => 'processing', 'series_id' => null, 'version_id' => null, 'revision' => $issued['revision'] + 1 ), array( 'id' => $issued['id'], 'revision' => $issued['revision'] ) );
   $check( 1 === $count && '' === $wpdb->last_error, 'interrupt only the owned request receipt before ' . $action );
   $interrupted = $unwrap( $requests->find( $issued['id'] ) );
   $receipt = $unwrap( $service->command( $actor, $issued['id'], $interrupted['revision'], $action ) );
   $issued = $unwrap( $requests->find( $issued['id'] ) );
   $check( 'already_issued' === $receipt['outcome'] && 'issued' === $issued['status'] && $issued['version_id'] === (int) $version_before['id'], $action . ' reconciles an already committed certificate rather than cancelling or issuing again' );
   $check( $version_before === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'credential_versions' ), $issued['version_id'] ), ARRAY_A ), $action . ' preserves every byte of the immutable canonical Version' );
  }
  $terminal = $service->command( $actor, $issued['id'], $issued['revision'], 'cancel' );
  $check( is_wp_error( $terminal ) && 409 === $terminal->get_error_data()['status'], 'issued request uses separate credential lifecycle instead of request cancellation' );
  foreach ( array( 'received', 'ready', 'processing', 'failed', 'blocked' ) as $i => $state ) {
   $row = $receive( 40 + $i );
   if ( in_array( $state, array( 'ready', 'processing', 'failed' ), true ) ) { $row = $unwrap( $requests->transition( $row['id'], $row['revision'], array( 'status' => 'ready', 'review_sha256' => str_repeat( 'a', 64 ) ), 'readiness_confirmed' ) ); }
   if ( in_array( $state, array( 'processing', 'failed' ), true ) ) { $row = $unwrap( $requests->transition( $row['id'], $row['revision'], array( 'status' => 'processing', 'attempt_count' => 1 ), 'issuance_started' ) ); }
   if ( in_array( $state, array( 'failed', 'blocked' ), true ) ) { $row = $unwrap( $requests->transition( $row['id'], $row['revision'], array( 'status' => $state ), 'readiness_blocked' ) ); }
   $unwrap( $service->command( $actor, $row['id'], $row['revision'], 'recheck' ) ); $next_row = $unwrap( $requests->find( $row['id'] ) );
   $check( 'waiting' === $next_row['status'] && null === $next_row['review_sha256'] && $immutable( $row ) === $immutable( $next_row ), $state . ' rechecks through waiting with a discarded old review and unchanged evidence/attempt history' );
   $unwrap( $service->command( $actor, $next_row['id'], $next_row['revision'], 'cancel' ) );
  }
  $inactive_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Inactive Program ' . $nonce, 'code' => 'QRI-' . $nonce ) ) );
  $inactive = $receive( 50, 'unknown', 'manual', null, $inactive_program );
  $unwrap( ( new ProgramRepository() )->update( $inactive_program, array( 'active' => 0 ) ) );
  $check( 'cancelled' === $unwrap( $service->command( $actor, $inactive['id'], $inactive['revision'], 'cancel' ) )['outcome'], 'an inactive Program does not prevent safe cancellation of its unissued intent' );

  // Status pages cross the NULL/due boundary and equal-date ties using the existing work_due index.
  for ( $i = 0; $i < 54; ++$i ) { $row = $receive( 100 + $i ); $unwrap( $requests->transition( $row['id'], $row['revision'], array( 'status' => 'waiting', 'next_attempt_at' => $i < 12 ? null : '2099-01-01 00:00:00' ), 'readiness_blocked' ) ); }
  $staff_sql = null;
  $capture_staff_read = static function ( $sql ) use ( &$staff_sql ) { if ( 0 === strpos( $sql, 'SELECT q.*, CASE WHEN ' ) ) { $staff_sql = $sql; } return $sql; };
  add_filter( 'query', $capture_staff_read, PHP_INT_MAX );
  try { $page = $unwrap( $service->queue( $actor, 'waiting' ) ); } finally { remove_filter( 'query', $capture_staff_read, PHP_INT_MAX ); }
  $cursor = $page['next_cursor'];
  $next = $unwrap( $service->queue( $actor, 'waiting', $cursor['id'], $cursor['due'] ) );
  $check( count( $page['items'] ) === 50 && $page['has_more'] && count( $next['items'] ) === 4 && ! $next['has_more'] && ! array_intersect( array_column( $page['items'], 'id' ), array_column( $next['items'], 'id' ) ), 'status pagination is bounded, crosses held/scheduled requests, and has no duplicate ties' );
  $recent = $unwrap( $service->queue( $actor, 'all' ) ); $recent_next = $unwrap( $service->queue( $actor, 'all', $recent['next_cursor']['id'] ) );
  $check( count( $recent['items'] ) === 50 && min( array_column( $recent['items'], 'id' ) ) > max( array_column( $recent_next['items'], 'id' ) ), 'recent activity uses descending primary-key pages without scanning terminal statuses for a pending count' );
  $plan = is_string( $staff_sql ) ? $wpdb->get_results( 'EXPLAIN ' . $staff_sql, ARRAY_A ) : array();
  $by_table = array_column( $plan, null, 'table' );
  $check( 'work_due' === ( $by_table['q']['key'] ?? null ) && false === stripos( implode( ' ', array_column( $plan, 'Extra' ) ), 'filesort' ), 'actual joined MySQL staff query uses work_due and primary-key label lookups without a filesort' );
  $history_row = $receive( 200 );
  for ( $i = 0; $i < 53; ++$i ) { $unwrap( $service->command( $actor, $history_row['id'], $history_row['revision'], 'recheck' ) ); $history_row = $unwrap( $requests->find( $history_row['id'] ) ); }
  $events = $unwrap( $service->history( $actor, $history_row['id'] ) ); $events_next = $unwrap( $service->history( $actor, $history_row['id'], $events['next_id'] ) );
  $check( 50 === count( $events['items'] ) && $events['has_more'] && 4 === count( $events_next['items'] ) && ! $events_next['has_more'] && ! array_intersect( array_column( $events['items'], 'id' ), array_column( $events_next['items'], 'id' ) ), 'request history is bounded and resumes through more than fifty exact append-only events' );
  foreach ( array( array( 'waiting', 0, "bad\0date" ), array( 'waiting', 0, '2026-02-30 12:00:00' ), array( 'waiting', 1, '2026-02-30 12:00:00' ), array( 'all', 1, '2026-10-03 12:00:00' ), array( 'unknown_status', 0, null ) ) as $i => $input ) { $check( is_wp_error( $service->queue( $actor, ...$input ) ), 'untyped or expanded cursor is rejected before storage, case ' . $i ); }

  $server = rest_get_server(); $rest = static function ( $method, $path, $body = null ) use ( $server ) { $request = new WP_REST_Request( $method, '/certloom/v1/admin/certificate-requests' . $path ); if ( null !== $body ) { $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $body ) ); } $response = rest_do_request( $request ); return apply_filters( 'rest_post_dispatch', $response, $server, $request ); };
  $health_marker = get_option( SchemaManager::HEALTH_OPTION, null ); delete_option( SchemaManager::HEALTH_OPTION );
  $check( ! SchemaManager::health_marker_is_fresh(), 'read-only REST acceptance explicitly starts without a schema-health marker' );
  $get_before = $fingerprints(); $get_options = $option_fingerprint();
  foreach ( array( '', '/' . $history_row['id'] . '/history', '/health', '/programs' ) as $path ) {
   wp_set_current_user( $actor ); $response = $rest( 'GET', $path ); $headers = $response->get_headers();
   $check( 200 === $response->get_status() && 'private, no-store, max-age=0' === $headers['Cache-Control'] && false !== strpos( $headers['Vary'], 'Cookie' ) && 'noindex' === $headers['X-Robots-Tag'], 'staff GET is available with private/no-store/noindex headers, route ' . ( $path ? 'history' : 'queue' ) );
   wp_set_current_user( $subscriber ); $denied = $rest( 'GET', $path );
   $check( 403 === $denied->get_status() && 'private, no-store, max-age=0' === $denied->get_headers()['Cache-Control'], 'subscriber cannot read staff data and denied response is private' );
   wp_set_current_user( 0 ); $denied = $rest( 'GET', $path ); $check( 401 === $denied->get_status(), 'logged-out visitor cannot read staff data' );
  }
  $get_after = $fingerprints(); $get_options_after = $option_fingerprint();
  foreach ( $get_before as $table => $hash ) { if ( $hash !== $get_after[ $table ] ) { $get_mutations['tables'][] = $table; } }
  foreach ( array_unique( array_merge( $get_options['keys'], $get_options_after['keys'] ) ) as $key ) { if ( ( $get_options['hashes'][ $key ] ?? null ) !== ( $get_options_after['hashes'][ $key ] ?? null ) ) { $get_mutations['options'][] = $key; } }
  $check( $get_before === $get_after && $get_options === $get_options_after, 'successful and forbidden REST GETs create no business writes or execution jobs' );
  if ( null !== $health_marker ) { update_option( SchemaManager::HEALTH_OPTION, $health_marker, false ); }
  wp_set_current_user( $manager ); $health_response = $rest( 'GET', '/health' );
  $check( 200 === $health_response->get_status() && false === $health_response->get_data()['can_repair'] && 403 === $rest( 'POST', '/repair-schedule', array( 'confirm' => true ) )->get_status(), 'management-only staff can read health but cannot repair issuing work' );
  wp_set_current_user( $actor );
  foreach ( array( array(), array( 'confirm' => 1 ), array( 'confirm' => true, 'approve' => true ), array( 'confirm' => true, 'job_type' => 'domain_restore' ) ) as $i => $body ) { $check( 400 === $rest( 'POST', '/repair-schedule', $body )->get_status(), 'scheduler repair refuses unconfirmed or expanded commands, case ' . $i ); }
  $operational_before = $fingerprints(); $health = $rest( 'GET', '/health' )->get_data();
  $check( true === $health['can_repair'] && isset( $health['due']['has_more'], $health['jobs']['queued']['count'], $health['cron']['traffic_enabled'] ) && ! array_intersect( array_keys( $health ), array( 'recipient_id', 'job_key', 'payload', 'secret', 'fields' ) ) && false === strpos( wp_json_encode( $health ), 'QA Requests Recipient ' . $nonce ), 'health contains bounded operational counts and no customer identity or private payload' );
  $health_queries = array(); $capture_health = static function ( $sql ) use ( &$health_queries ) { if ( false !== strpos( $sql, 'FORCE INDEX (work_due)' ) || false !== strpos( $sql, 'FORCE INDEX (type_status)' ) ) { $health_queries[] = $sql; } return $sql; };
  add_filter( 'query', $capture_health, PHP_INT_MAX ); try { $unwrap( ( new \Certloom\Infrastructure\Recipient\PendingWorkflowHealth() )->read() ); } finally { remove_filter( 'query', $capture_health, PHP_INT_MAX ); }
  $indexed = 22 === count( $health_queries ); // Six due states plus four job types in four operational states.
  foreach ( $health_queries as $sql ) { $plans = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); $indexed = $indexed && false !== strpos( $sql, 'LIMIT 51' ) && is_array( $plans ) && isset( $plans[0] ) && in_array( $plans[0]['key'], array( 'work_due', 'type_status' ), true ); }
  $check( $indexed, 'actual health queries are bounded to 51 rows per status/type and use their intended MySQL indexes' );
  for ( $i = 0; $i < 54; ++$i ) { $row = $receive( 300 + $i ); }
  $health_jobs = new \Certloom\Infrastructure\Job\JobRepository();
  for ( $i = 0; $i < 54; ++$i ) { $unwrap( $health_jobs->create( array( 'job_type' => \Certloom\Domain\Job\JobTypes::PENDING_REASSESSMENT, 'payload' => array( 'schema_version' => 1, 'cutoff' => current_time( 'mysql', true ), 'max_id' => $history_row['id'] ) ) ) ); }
  $health = $rest( 'GET', '/health' )->get_data(); $check( 50 === $health['due']['count'] && $health['due']['has_more'] && 50 === $health['jobs']['queued']['count'] && $health['jobs']['queued']['has_more'], 'large pending counts are honestly capped at 50+ rather than doing an unbounded count' );
  $paused_job = $unwrap( ( new \Certloom\Infrastructure\Job\JobRepository() )->create( array( 'job_type' => \Certloom\Domain\Job\JobTypes::PENDING_REASSESSMENT, 'payload' => array( 'schema_version' => 1, 'cutoff' => current_time( 'mysql', true ), 'max_id' => $history_row['id'] ) ) ) );
  $check( 1 === $wpdb->update( SchemaManager::table( 'jobs' ), array( 'status' => 'paused' ), array( 'job_key' => $paused_job ) ), 'owned fixture deliberately pauses a pending job' );
  $paused_rows = $fingerprints(); $paused_options = $option_fingerprint(); $repair = $rest( 'POST', '/repair-schedule', array( 'confirm' => true ) );
  $check( 409 === $repair->get_status() && $paused_rows === $fingerprints() && $paused_options === $option_fingerprint(), 'scheduler repair respects a deliberate pause without any write' );
  $check( 1 === $wpdb->update( SchemaManager::table( 'jobs' ), array( 'status' => 'cancelled' ), array( 'job_key' => $paused_job ) ), 'owned paused fixture can be closed for subsequent repair acceptance' );
  wp_clear_scheduled_hook( \Certloom\Infrastructure\Job\JobDispatcher::WATCHDOG_HOOK );
  $check( null === $rest( 'GET', '/health' )->get_data()['cron']['next_watchdog'], 'read-only health identifies a lost watchdog without repairing it on GET' );
  $pending_job_key = get_option( \Certloom\Infrastructure\Recipient\PendingWorkflowCoordinator::JOB_OPTION ); wp_clear_scheduled_hook( \Certloom\Infrastructure\Job\JobDispatcher::RUN_HOOK, array( $pending_job_key ) );
  $repair_before = $fingerprints(); $repair_options = $option_fingerprint();
  $fail_pending_schedule = static function ( $pre, $event ) { return \Certloom\Infrastructure\Job\JobDispatcher::RUN_HOOK === $event->hook ? false : $pre; };
  add_filter( 'pre_schedule_event', $fail_pending_schedule, PHP_INT_MAX, 2 ); try { $rejected_repair = $rest( 'POST', '/repair-schedule', array( 'confirm' => true ) ); } finally { remove_filter( 'pre_schedule_event', $fail_pending_schedule, PHP_INT_MAX ); }
  $check( 200 !== $rejected_repair->get_status() && $repair_before === $fingerprints() && $repair_options === $option_fingerprint() && ! wp_next_scheduled( \Certloom\Infrastructure\Job\JobDispatcher::WATCHDOG_HOOK ), 'failed scheduling rolls back watchdog, jobs, outbox and cron together and invalidates cached success' );
  $repair = $rest( 'POST', '/repair-schedule', array( 'confirm' => true ) );
  $check( 200 === $repair->get_status() && 'scheduled' === $repair->get_data()['outcome'] && wp_next_scheduled( \Certloom\Infrastructure\Job\JobDispatcher::WATCHDOG_HOOK ), 'authorized repair restores the missing recovery schedule and queues pending checks' );
  $health_options = get_option( \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION, null ); update_option( \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION, '{"schema_version":2}', false );
  $check( 200 !== $rest( 'GET', '/health' )->get_status() && 200 !== $rest( 'POST', '/repair-schedule', array( 'confirm' => true ) )->get_status(), 'malformed outbox state fails closed rather than reporting an empty healthy queue' );
  if ( null === $health_options ) { delete_option( \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION ); } else { update_option( \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION, $health_options, false ); }
  wp_set_current_user( $manager ); $check( 403 === $rest( 'POST', '/' . $rollback['id'] . '/recheck', array( 'revision' => $rollback['revision'] ) )->get_status(), 'REST separately denies recheck without issuance permission' );
  wp_set_current_user( $actor );
  foreach ( array( array( 'revision' => (string) $rollback['revision'] ), array( 'revision' => $rollback['revision'], 'evidence' => array( 'eligibility' => 'eligible' ) ), array( 'revision' => $rollback['revision'], 'recipient_id' => $recipient ), array( 'revision' => $rollback['revision'], 'approve' => true ), array() ) as $i => $body ) { $check( 400 === $rest( 'POST', '/' . $rollback['id'] . '/recheck', $body )->get_status(), 'REST rejects body expansion or untyped revisions, case ' . $i ); }
  $ok = $rest( 'POST', '/' . $rollback['id'] . '/recheck', array( 'revision' => $rollback['revision'] ) );
  $check( 200 === $ok->get_status() && 'queued' === $ok->get_data()['outcome'], 'actual staff REST POST schedules an exact-revision reassessment' );
  $check( 409 === $rest( 'POST', '/' . $rollback['id'] . '/cancel', array( 'revision' => $rollback['revision'] ) )->get_status(), 'REST cancellation rejects the previous stale revision' );
  $check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id=%d', SchemaManager::table( 'email_deliveries' ), $issued['version_id'] ) ), 'the fixture has no recipient destination and creates no email delivery' );
  $filter_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA filtered Program ' . $nonce,'code' => 'QRF-' . $nonce ) ) );
  $fields = Ecapla_CertVerify_Fields::get_fields(); $personal_key = 'qa_filter_personal_' . $nonce;
  $fields[] = array( 'key' => $personal_key,'label' => 'QA personal detail','type' => 'text','sensitivity' => 'personal','public_exposure' => 'private','collection_policy' => array( '_meta' => array( 'schema' => \Certloom\Application\Fields\FieldCollectionPolicy::SCHEMA,'schema_version' => 1,'configured' => true ),'writer' => 'recipient','lifetime' => 'recipient','collect_when' => 'before_issue','approval_required' => true,'show_on_certificate' => false,'help' => '' ) );
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'filter fixture has a real private customer-owned field definition' );
  $rare = $receive( 1000,'unknown','manual',null,$filter_program ); $rare = $unwrap( $requests->transition( $rare['id'],$rare['revision'],array( 'status' => 'waiting','blockers_json' => wp_json_encode( array( array( 'code' => 'missing','field_key' => $personal_key ) ) ),'next_attempt_at' => null ),'readiness_blocked' ) );
  $check( 1 === $wpdb->update( SchemaManager::table( 'issuance_requests' ),array( 'created_at' => gmdate( 'Y-m-d H:i:s',time() - 40 * 86400 ) ),array( 'id' => $rare['id'] ) ), 'only the owned request receives an older timestamp to test age without waiting' );
  for ( $i = 0; $i < 204; ++$i ) { $extra = $receive( 1001 + $i,'unknown','manual',null,$filter_program ); $unwrap( $requests->transition( $extra['id'],$extra['revision'],array( 'status' => 'waiting','next_attempt_at' => null ),'readiness_blocked' ) ); }
  $special = array();
  foreach ( array( 'approval' => 'awaiting_approval','qualification' => 'missing_current_evidence','configuration' => 'source_mapping_changed' ) as $task => $code ) {
   $extra = $receive( 1300 + count( $special ),'unknown','manual',null,$filter_program );
   $special[ $task ] = $unwrap( $requests->transition( $extra['id'],$extra['revision'],array( 'status' => 'waiting','blockers_json' => wp_json_encode( array( array( 'code' => $code ) ) ),'next_attempt_at' => null ),'readiness_blocked' ) );
  }
  $filters = array( 'program_id' => $filter_program,'owner' => 'recipient','task' => 'details','age' => 'month' ); $filter_before = $fingerprints(); $filter_options = $option_fingerprint();
  $sparse = $unwrap( $service->queue( $actor,'all',0,null,$filters ) );
  $check( array() === $sparse['items'] && true === $sparse['has_more'] && 200 === $sparse['scanned'] && is_int( $sparse['next_cursor']['id'] ), 'a sparse filter checks at most 200 requests and returns an explicit continuation instead of a false empty result' );
  $sparse_next = $unwrap( $service->queue( $actor,'all',$sparse['next_cursor']['id'],null,$sparse['filters'] ) );
  $check( array( $rare['id'] ) === array_column( $sparse_next['items'],'id' ) && ! $sparse_next['has_more'] && $sparse['filters']['as_of'] === $sparse_next['filters']['as_of'], 'sparse continuation reaches the exact older customer-detail match with stable age boundaries and no skipped request' );
  foreach ( array( 'approval' => 'organization','qualification' => 'source','configuration' => 'organization' ) as $task => $owner ) {
   $filtered = $unwrap( $service->queue( $manager,'all',0,null,array( 'program_id' => $filter_program,'owner' => $owner,'task' => $task ) ) );
   $check( array( $special[ $task ]['id'] ) === array_column( $filtered['items'],'id' ), 'management-only staff can filter the correct ' . $task . ' task and actor without gaining issuance authority' );
  }
  $filter_sql = null; $capture_filter = static function ( $sql ) use ( &$filter_sql ) { if ( 0 === strpos( $sql,'SELECT q.*, CASE WHEN ' ) && false !== strpos( $sql,'FORCE INDEX (program_status)' ) ) { $filter_sql = $sql; } return $sql; };
  add_filter( 'query',$capture_filter,PHP_INT_MAX ); try { $program_page = $unwrap( $service->queue( $actor,'all',0,null,array( 'program_id' => $filter_program ) ) ); } finally { remove_filter( 'query',$capture_filter,PHP_INT_MAX ); }
  $program_next = $unwrap( $service->queue( $actor,'all',$program_page['next_cursor']['id'],null,$program_page['filters'] ) );
  $check( 50 === count( $program_page['items'] ) && 50 === count( $program_next['items'] ) && min( array_column( $program_page['items'],'id' ) ) > max( array_column( $program_next['items'],'id' ) ) && array( 'QA filtered Program ' . $nonce ) === array_unique( array_column( $program_page['items'],'program' ) ), 'Program pages preserve descending keyset order without crossing Programs or repeating ties' );
  $program_plan = $wpdb->get_results( 'EXPLAIN ' . $filter_sql,ARRAY_A );
  $check( count( \Certloom\Domain\Issuance\IssuanceRequestState::all() ) === count( array_filter( $program_plan,static function ( $row ) { return 'program_status' === ( $row['key'] ?? null ); } ) ), 'every actual MySQL Program/status branch uses the existing program_status index before the bounded merge' );
  $status_program = $unwrap( $service->queue( $actor,'waiting',0,null,array( 'program_id' => $filter_program ) ) );
  $check( 50 === count( $status_program['items'] ) && null === $status_program['next_cursor']['due'], 'Program plus lifecycle status uses its own safe descending-ID cursor' );
  foreach ( array( array( 'program_id' => '1' ),array( 'program_id' => -1 ),array( 'owner' => 'administrator' ),array( 'task' => 'issue_now' ),array( 'age' => 30 ),array( 'secret' => 'hidden' ),array( 'as_of' => '2026-02-30 01:02:03' ),array( 'as_of' => '2099-01-01 00:00:00' ),array( 'as_of' => '2020-01-01 00:00:00' ) ) as $i => $invalid_filters ) { $check( is_wp_error( $service->queue( $actor,'all',0,null,$invalid_filters ) ), 'invalid or expanded filters fail closed, case ' . $i ); }
  $check( is_wp_error( $service->queue( $actor,'waiting',1,'2026-10-03 00:00:00',array( 'program_id' => $filter_program ) ) ) && is_wp_error( $service->programs( $subscriber ) ), 'cross-order cursors and subscriber Program browsing are refused' );
  // Query parameters are supplied through WP_REST_Request, not appended to its route.
  $filter_http = new WP_REST_Request( 'GET','/certloom/v1/admin/certificate-requests' ); $filter_http->set_query_params( array( 'program_id' => $filter_program,'owner' => 'recipient','task' => 'details','age' => 'month' ) ); $filtered_rest = $server->dispatch( $filter_http );
  $check( 200 === $filtered_rest->get_status() && 200 === $filtered_rest->get_data()['scanned'] && true === $filtered_rest->get_data()['has_more'] && 'private, no-store, max-age=0' === $filtered_rest->get_headers()['Cache-Control'], 'actual private staff REST transport retains sparse continuation and typed filter semantics' );
  $check( $filter_before === $fingerprints() && $filter_options === $option_fingerprint(), 'all filtered queue, continuation and denied reads leave business rows, options and jobs unchanged' );
  $restore_only = $receive( 1400,'unknown','manual',null,$filter_program ); $restore_only = $unwrap( $store->patch( 'issuance_requests',$restore_only['id'],$restore_only['revision'],array( 'restore_state' => 'paused','blockers_json' => '[]' ) ) );
  $configuration_owners = $unwrap( $service->queue( $actor,'all',0,null,array( 'program_id' => $filter_program,'owner' => 'organization','task' => 'configuration' ) ) );
  $check( in_array( $restore_only['id'],array_column( $configuration_owners['items'],'id' ),true ), 'a restored request needs organization review even before a blocker has been computed' );
  $not_eligible = $receive( 1401,'not_eligible','manual',null,$filter_program ); $not_eligible = $unwrap( $requests->transition( $not_eligible['id'],$not_eligible['revision'],array( 'status' => 'not_eligible','blockers_json' => '[]' ),'readiness_blocked' ) );
  $qualification_owners = $unwrap( $service->queue( $actor,'all',0,null,array( 'program_id' => $filter_program,'owner' => 'source','task' => 'qualification' ) ) );
  $check( in_array( $not_eligible['id'],array_column( $qualification_owners['items'],'id' ),true ), 'unqualified lifecycle state needs the trusted source even when no field blocker is present' );
  $unwrap( $service->command( $actor,$restore_only['id'],$restore_only['revision'],'cancel' ) ); $closed_tasks = $unwrap( $service->queue( $actor,'all',0,null,array( 'program_id' => $filter_program,'task' => 'configuration' ) ) );
  $check( ! in_array( $restore_only['id'],array_column( $closed_tasks['items'],'id' ),true ), 'closed restored requests do not appear as active organization tasks' );
  for ( $i = 0; $i < 51; ++$i ) { $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA chooser ' . $i . ' ' . $nonce,'code' => 'QRP-' . $nonce . '-' . $i ) ) ); }
  $chooser = $unwrap( $service->programs( $manager ) ); $chooser_next = $unwrap( $service->programs( $manager,$chooser['next_id'] ) );
  $check( 50 === count( $chooser['items'] ) && $chooser['has_more'] && $chooser_next['items'] && ! array_intersect( array_column( $chooser['items'],'id' ),array_column( $chooser_next['items'],'id' ) ), 'Program choices continue beyond fifty with exact primary-key pages and no credential count or identity payload' );
  $wpdb->last_error = ''; $erased = $wpdb->update( SchemaManager::table( 'recipients' ), array( 'privacy_anonymized_at' => current_time( 'mysql', true ) ), array( 'id' => $recipient ) );
  $check( 1 === $erased && '' === $wpdb->last_error, 'simulate an anonymized recipient only inside the rollback fixture' );
  $hidden = $unwrap( $service->queue( $actor, 'waiting' ) );
  $check( false === strpos( wp_json_encode( $hidden ), 'QA Requests Recipient ' . $nonce ) && ! array_filter( $hidden['items'], static function ( $item ) { return $item['can_recheck'] || $item['can_cancel']; } ), 'anonymized recipient names are hidden and request actions are unavailable' );
  $held_row = $unwrap( $requests->find( $rollback['id'] ) ); $denied = $service->command( $actor, $held_row['id'], $held_row['revision'], 'cancel' );
  $check( is_wp_error( $denied ) && 409 === $denied->get_error_data()['status'] && $held_row === $unwrap( $requests->find( $held_row['id'] ) ), 'anonymized intent cannot be operated on through a stale staff screen' );
  return new WP_Error( 'qa_rollback', 'Successful fixture; roll back all state.' );
 } catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); return new WP_Error( 'qa_failed', 'Failed fixture; roll back all state.' ); }
} );
foreach ( $qa_users as $id ) { clean_user_cache( $id ); } wp_set_current_user( 0 );
Ecapla_CertVerify_Fields::reset_runtime_cache();
$after_options = $option_fingerprint(); DatabaseOptions::invalidate( array_unique( array_merge( $before_options['keys'], $after_options['keys'] ) ) );
$preserved = $before === $fingerprints() && $before_options === $after_options;
$success = is_wp_error( $result ) && 'qa_rollback' === $result->get_error_code() && $preserved;
echo wp_json_encode( array( 'success' => $success, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'get_mutations' => $get_mutations, 'tracked_rows_and_options_preserved' => $preserved, 'error_code' => $success ? null : ( is_wp_error( $result ) ? $result->get_error_code() : 'unexpected_result' ), 'environment' => array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'database' => $wpdb->db_version() ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $success ? 0 : 1 );
