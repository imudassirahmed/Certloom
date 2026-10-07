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
use Certloom\Application\Integration\WebhookV2Contract;
use Certloom\Application\Recipient\RecipientCollectionService;
use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Repository\FieldSubmissionRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Integration\WebhookV2ConnectionRepository;
use Certloom\Infrastructure\Integration\WebhookV2Bridge;
$checks = array(); $failure = null; $users = array();
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$unwrap = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients', 'programs', 'issuers', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map', 'program_registrations', 'field_submissions', 'issuance_requests', 'issuance_request_events' ) ) );
$fingerprints = static function () use ( $tables ) { global $wpdb; $out = array(); foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Preservation read failed.' ); } $out[ $table ] = hash( 'sha256', wp_json_encode( $rows ) ); } return $out; };
$options = static function () { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE LEFT(option_name,9)='certloom_' OR LEFT(option_name,11)='certverify_' OR option_name='cron' ORDER BY option_name", ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Option preservation read failed.' ); } return array( 'sha256' => hash( 'sha256', wp_json_encode( $rows ) ), 'keys' => array_column( $rows, 'option_name' ), 'hashes' => array_combine( array_column( $rows, 'option_name' ), array_map( static function ( $row ) { return hash( 'sha256', wp_json_encode( $row ) ); }, $rows ) ) ); };
$before = $fingerprints(); $before_options = $options();

$result = ( new Transaction() )->run( static function () use ( $check, $unwrap, $fingerprints, $options, &$users, &$failure ) {
 try {
  global $wpdb; $nonce = bin2hex( random_bytes( 6 ) ); $actor = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0]; wp_set_current_user( $actor );
  $settings = Ecapla_CertVerify_Settings::get_all(); $settings['certificate_name_source'] = 'profile'; $settings['certificate_name_field_key'] = ''; $settings['numbering_mode'] = 'sequential'; $settings['numbering_prefix'] = 'QV2-' . $nonce . '-';
  $check( Ecapla_CertVerify_Settings::save( $settings ) && Ecapla_CertVerify_Numbering::is_enabled(), 'app intake uses the existing automatic numbering policy' );
  $users[] = $user = $unwrap( wp_insert_user( array( 'user_login' => 'qa-v2-' . $nonce, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'QA App Recipient' ) ) );
  $recipients = new RecipientRepository(); $recipient = $unwrap( $recipients->find_by_wp_user_id( $user ) ); $external = 'qa-v2-person-' . $nonce; $unwrap( $recipients->update( $recipient['id'], array( 'external_id' => $external ) ) );
  $keys = array( 'personal' => 'qa_v2_person_' . $nonce, 'result' => 'qa_v2_score_' . $nonce ); $fields = Ecapla_CertVerify_Fields::get_fields();
  foreach ( $keys as $kind => $key ) { $fields[] = array( 'key' => $key, 'label' => 'QA App ' . $kind, 'type' => 'personal' === $kind ? 'text' : 'number', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => 'personal' === $kind ? 'recipient' : 'source', 'lifetime' => 'personal' === $kind ? 'recipient' : 'achievement', 'collect_when' => 'personal' === $kind ? 'before_issue' : 'never', 'approval_required' => 'personal' === $kind, 'show_on_certificate' => false, 'help' => '' ) ); }
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'catalog separates customer information from app-owned results' );
  $catalog = Ecapla_CertVerify_Fields::get_field_map(); $metadata = $unwrap( ( new ScopedFieldContract() )->with_program_metadata( array(), array_fill_keys( array_values( $keys ), 'required' ), $catalog ) );
  $program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA App Program ' . $nonce, 'code' => 'QVP-' . $nonce, 'metadata' => $metadata ) ) );
  $templates = new TemplateRepository(); $design = $unwrap( $templates->create( array( 'name' => 'QA App Design ' . $nonce, 'machine_key' => 'qa-v2-design-' . $nonce, 'layout' => TemplateDefaults::blank_a4_landscape() ) ) );
  $design_row = $unwrap( $templates->find( $design ) ); $unwrap( $templates->publish( $design, $design_row['current_revision_id'], $design_row['current_revision']['layout_sha256'] ) ); $unwrap( $templates->assign( $design, 'program', $program ) );
  $connections = new WebhookV2ConnectionRepository(); $bad = $connections->create( 'Bad app', $program, 0, array( 'ID' => $keys['personal'] ) );
  $check( is_wp_error( $bad ), 'a connection cannot map a result onto customer-owned information' );
  $created = $unwrap( $connections->create( 'QA App', $program, 0, array( 'Score' => $keys['result'] ) ) ); $source = $unwrap( $connections->find( $created['key'] ) ); $secret = $created['secret'];
  $check( false === strpos( wp_json_encode( get_option( WebhookV2ConnectionRepository::OPTION ) ), $secret ) && $secret === $unwrap( $connections->secret( $source ) ), 'connection secrets are encrypted and returned once' );
  $admin = new \Certloom\Infrastructure\Admin\WebhookV2IntegrationAdmin(); $secret_transient = \Certloom\Infrastructure\Admin\WebhookV2IntegrationAdmin::SECRET_PREFIX . $actor;
  if ( false !== get_transient( $secret_transient ) ) { throw new RuntimeException( 'An existing one-time setup secret must not be replaced by QA.' ); }
  $store_secret = new ReflectionMethod( $admin, 'store_setup_secret' );
  $check( $store_secret->invoke( $admin, $created ) && false === strpos( wp_json_encode( get_transient( $secret_transient ) ), $secret ), 'one-time organization setup storage is encrypted too' );
  wp_set_current_user( $user ); $customer_screen = $admin->screen_data(); $check( ! $customer_screen['one_time_secret'] && ! $customer_screen['connections'] && false !== get_transient( $secret_transient ), 'customer access cannot read or consume organization connection credentials' ); wp_set_current_user( $actor );
  $setup_screen = $admin->screen_data(); $check( $secret === $setup_screen['one_time_secret']['secret'] && ! isset( $setup_screen['connections'][0]['secret_sealed'] ) && false === get_transient( $secret_transient ) && ! $admin->screen_data()['one_time_secret'], 'the authorized organization screen reveals once and omits encrypted credentials from list data' );
  $wire = new WebhookV2Contract(); $bridge = new WebhookV2Bridge(); $timestamp = (string) time(); $date = gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 );
  $payload = array( 'schema_version' => 2, 'event_id' => 'attempt-' . $nonce, 'recipient_external_id' => $external, 'registration_id' => 0, 'completed_at' => $date, 'evidence' => array( 'eligibility' => 'eligible', 'checked_at' => $date, 'valid_until' => null, 'fields' => array( 'Score' => array( 'value' => '0', 'revision' => 'attempt-rev1' ) ) ) );
  $raw = wp_json_encode( $payload );
  $send = static function ( $mode, $body, $ref = '', $signing_secret = null ) use ( $wire, $bridge, $source, &$secret, &$timestamp ) { $input = $wire->signing_input( $source['key'], $mode, $timestamp, $body, $ref ); if ( is_wp_error( $input ) ) { return $input; } return $bridge->handle( $source['key'], $mode, $timestamp, 'sha256=' . hash_hmac( 'sha256', $input, null === $signing_secret ? $secret : $signing_secret ), $body, $ref ); };
  $bad = $bridge->handle( $source['key'], 'intake', $timestamp, 'sha256=' . str_repeat( '0', 64 ), $raw );
  $check( is_wp_error( $bad ) && 'certloom_webhook_v2_signature_invalid' === $bad->get_error_code(), 'invalid signature is rejected before accepting intent' );
  $before_test = $fingerprints(); $test_options = $options(); $test = $unwrap( $send( 'test', $raw ) );
  $check( 'tested' === $test['status'] && ! $test['ready'] && ! $test['mutations'] && $test['blockers'][0]['field_key'] === $keys['personal'], 'signed test uses canonical readiness and identifies missing customer data' );
  $check( $before_test === $fingerprints() && $test_options['sha256'] === $options()['sha256'], 'signed test writes no rows, options, cron, requests, numbering or delivery' );
  $rest_test = new WP_REST_Request( 'POST', $wire->route( $source['key'], 'test' ) ); $rest_test->set_body( $raw ); $rest_test->set_header( 'x-certloom-timestamp', $timestamp ); $rest_test->set_header( 'x-certloom-signature', 'sha256=' . hash_hmac( 'sha256', $wire->signing_input( $source['key'], 'test', $timestamp, $raw ), $secret ) );
  $rest_reply = rest_do_request( $rest_test ); $check( 200 === $rest_reply->get_status() && 'tested' === $rest_reply->get_data()['status'] && false !== strpos( $rest_reply->get_headers()['Cache-Control'], 'no-store' ), 'real WordPress REST dispatch returns a private nonmutating test receipt' );
  $rest_test->set_query_params( array( 'source' => 'v2-000000000000' ) ); $rest_reply = rest_do_request( $rest_test ); $check( 200 === $rest_reply->get_status(), 'query parameters cannot replace the matched signed source route' ); $rest_test->set_query_params( array() );
  $rest_test->set_header( 'x-certloom-signature', 'sha256=' . str_repeat( '0', 64 ) ); $rest_reply = rest_do_request( $rest_test ); $check( 401 === $rest_reply->get_status(), 'real REST boundary rejects an invalid signature' );
  $check( $before_test === $fingerprints() && $test_options['sha256'] === $options()['sha256'], 'REST tests and rejected signatures preserve all tracked state' );
  $noop_tables = $fingerprints(); $noop_options = $options(); $unwrap( $connections->update_mapping( $source['key'], $connections->revision( $source ), $source['fields'], true ) );
  $check( $noop_tables === $fingerprints() && $noop_options['sha256'] === $options()['sha256'], 'unchanged mapping does not manufacture an outbox signal or revision' );
  foreach ( array( 'error', 'zero' ) as $mode ) {
   $fault = static function ( $sql ) use ( $mode, $nonce ) { global $wpdb; if ( 0 === strpos( $sql, 'UPDATE ' ) && false !== strpos( $sql, \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION ) ) { return 'error' === $mode ? 'UPDATE `__certloom_qa_missing_' . $nonce . '` SET option_value=0 WHERE 1=0' : 'UPDATE `' . $wpdb->options . '` SET option_value=option_value WHERE 1=0'; } return $sql; };
   $operations = array( 'create' => static function () use ( $connections, $program, $keys ) { return $connections->create( 'Fault app', $program, 0, array( 'Score' => $keys['result'] ) ); }, 'pause' => static function () use ( $connections, $source ) { return $connections->update_mapping( $source['key'], $connections->revision( $source ), $source['fields'], false ); }, 'rotate' => static function () use ( $connections, $source ) { return $connections->rotate( $source['key'], $connections->revision( $source ) ); }, 'delete' => static function () use ( $connections, $source ) { return $connections->delete( $source['key'], $connections->revision( $source ) ); } );
   foreach ( $operations as $label => $operation ) {
    $prior_tables = $fingerprints(); $prior_options = $options(); $prior_errors = $wpdb->suppress_errors( true ); add_filter( 'query', $fault, PHP_INT_MAX );
    try { $rejected = $operation(); } finally { remove_filter( 'query', $fault, PHP_INT_MAX ); $wpdb->suppress_errors( $prior_errors ); }
    $check( is_wp_error( $rejected ) && $prior_tables === $fingerprints() && $prior_options['sha256'] === $options()['sha256'], $label . ' rejects ' . $mode . ' outbox failure and rolls back secrets, mappings, generation and scheduler state' );
   }
  }
  $rest_intake = new WP_REST_Request( 'POST', $wire->route( $source['key'], 'intake' ) ); $rest_intake->set_body( $raw ); $rest_intake->set_header( 'x-certloom-timestamp', $timestamp ); $rest_intake->set_header( 'x-certloom-signature', 'sha256=' . hash_hmac( 'sha256', $wire->signing_input( $source['key'], 'intake', $timestamp, $raw ), $secret ) );
  $rest_reply = rest_do_request( $rest_intake ); $check( 202 === $rest_reply->get_status() && false !== strpos( $rest_reply->get_headers()['Cache-Control'], 'no-store' ), 'real REST intake accepts durable intent with a private receipt' );
  $received = $rest_reply->get_data(); $check( 'waiting' === $received['status'] && null === $received['version_id'], 'qualified app result waits for missing customer details' );
  $requests = new IssuanceRequestRepository(); $row = $unwrap( $requests->find_by_public_ref( $received['request_ref'] ) );
  $check( 'webhook_v2' === $row['source_type'] && $connections->mapping_sha256( $source ) === $row['mapping_sha256'], 'durable request binds its exact connection mapping' );
  $replay = $unwrap( $send( 'intake', $raw ) ); $check( $received['request_ref'] === $replay['request_ref'] && $replay['idempotent_replay'], 'duplicate intake returns its original request' );
  $gate = new ReflectionProperty( \Certloom\Infrastructure\Migration\DomainFeatureGate::class, 'scopes' ); $authority = $gate->getValue();
  try {
   $gate->setValue( null, array( 'write_paths' => false, 'admin_preview' => true, 'data_tools' => false ) );
   $closed_test = $unwrap( $send( 'test', $raw ) ); $prior_tables = $fingerprints(); $prior_options = $options();
   $closed_intake = $send( 'intake', $raw ); $closed_review = ( new \Certloom\Application\Issuance\ManualIssuanceService() )->review_pending( $row['id'], true );
   $check( 'tested' === $closed_test['status'] && is_wp_error( $closed_intake ) && is_wp_error( $closed_review ) && 'source_authority_closed' === $closed_review->get_error_data()['reason_code'] && $prior_tables === $fingerprints() && $prior_options['sha256'] === $options()['sha256'], 'closed writing authority allows a nonmutating test but rejects live intake and canonical app review' );
   $closed_worker = $unwrap( ( new PendingIssuanceService() )->process( $row['id'] ) );
   $check( 'blocked' === $closed_worker['status'] && 'source_authority_closed' === json_decode( $closed_worker['blockers_json'], true )[0]['code'], 'background execution independently reloads current app writing authority' );
  } finally { $gate->setValue( null, $authority ); }
  $check( 'waiting' === $unwrap( ( new PendingIssuanceService() )->process( $row['id'] ) )['status'], 'restored writing authority reassesses the original qualification without inventing customer details' );
  $prior_tables = $fingerprints(); $prior_options = $options();
  $proposed_program = $unwrap( ( new ProgramRepository() )->find( $program ) ); $proposed_program['active'] = false;
  $check( is_wp_error( ( new \Certloom\Infrastructure\Admin\IntegrationConfigurationDependencyGuard() )->validate_program_context_change( $proposed_program ) ) && $prior_tables === $fingerprints() && $prior_options['sha256'] === $options()['sha256'], 'the organization configuration guard rejects an active app mapping losing its qualification context' );
  $current_sources = get_option( WebhookV2ConnectionRepository::OPTION ); update_option( WebhookV2ConnectionRepository::OPTION, array( 'schema_version' => 3, 'connections' => array() ), false );
  $check( is_wp_error( $connections->read() ) && is_wp_error( ( new \Certloom\Infrastructure\Admin\IntegrationConfigurationDependencyGuard() )->validate_program_context_change( $unwrap( ( new ProgramRepository() )->find( $program ) ) ) ), 'malformed source configuration fails closed in dependent configuration checks' );
  update_option( WebhookV2ConnectionRepository::OPTION, $current_sources, false );
  $changed = $payload; $changed['evidence']['fields']['Score']['value'] = '90'; $bad = $send( 'intake', wp_json_encode( $changed ) );
  $check( is_wp_error( $bad ) && 'certloom_webhook_v2_replay_conflict' === $bad->get_error_code(), 'same intake event with changed exact bytes is rejected' );
  wp_set_current_user( $user ); $collection = new RecipientCollectionService(); $view = $unwrap( $collection->read_for_user( $user, 0, 0, $row['id'] ) );
  $check( array( $keys['personal'] ) === array_column( $view['fields'], 'key' ), 'customer request form contains its permitted personal detail and no app result' );
  $unwrap( $collection->save_for_user( $user, 0, $view['revision'], array( $keys['personal'] => '00123' ), $row['id'] ) );
  $held = $unwrap( ( new PendingIssuanceService() )->process( $row['id'] ) ); $check( 'waiting' === $held['status'], 'profile completion cannot approve itself or replace source qualification' );
  $proposal = $unwrap( ( new FieldSubmissionRepository() )->latest( $recipient['id'], 'recipient', $recipient['id'], $keys['personal'] ) ); wp_set_current_user( $actor ); $unwrap( $collection->decide( $proposal['id'], $proposal['revision'], true, $actor ) );
  $issued = $unwrap( ( new PendingIssuanceService() )->process( $row['id'] ) );
  $check( 'issued' === $issued['status'], 'approved customer detail releases app intake through canonical pending issuance' );
  $version = $unwrap( ( new CredentialRepository() )->find_version( $issued['version_id'] ) );
  $check( '00123' === $version['snapshot']['fields']['values'][ $keys['personal'] ] && '0' === $version['snapshot']['fields']['values'][ $keys['result'] ], 'immutable Version retains approved text ID and trusted zero result' );
  $check( ! isset( $version['snapshot']['recipient']['custom_fields'][ $keys['personal'] ] ) && 'source' === $version['snapshot']['fields']['provenance'][ $keys['result'] ]['kind'] && 'attempt-rev1' === $version['snapshot']['fields']['provenance'][ $keys['result'] ]['source_revision'], 'managed values use reviewed projection and preserve exact app result provenance' );
  $late = $unwrap( $send( 'intake', $raw ) ); $check( $late['idempotent_replay'] && $issued['version_id'] === $late['version_id'], 'late source retry returns the immutable issued receipt' );
  $old_secret = $secret; $rotated = $unwrap( $connections->rotate( $source['key'], $connections->revision( $source ) ) ); $secret = $rotated['secret']; $rotated_source = $unwrap( $connections->find( $source['key'] ) );
  $check( $connections->mapping_sha256( $source ) === $connections->mapping_sha256( $rotated_source ), 'rotating transport credentials does not remap accepted qualification' );
  $check( is_wp_error( $send( 'intake', $raw, '', $old_secret ) ) && $unwrap( $send( 'intake', $raw ) )['idempotent_replay'], 'old secret stops authenticating and new secret can reconcile original intake' );
  $bad = $connections->update_mapping( $source['key'], $connections->revision( $source ), $source['fields'], false ); $check( is_wp_error( $bad ) && 'certloom_webhook_v2_connection_stale' === $bad->get_error_code(), 'stale connection command cannot overwrite a rotation' );
  $unwrap( $connections->update_mapping( $source['key'], $connections->revision( $rotated_source ), $source['fields'], false ) );
  $paused = $unwrap( $connections->find( $source['key'] ) ); $new = $payload; $new['event_id'] .= '-new';
  $check( is_wp_error( $send( 'intake', wp_json_encode( $new ) ) ) && $unwrap( $send( 'intake', $raw ) )['idempotent_replay'], 'paused connection blocks new work while preserving exact historical receipt' );
  $unwrap( $connections->update_mapping( $source['key'], $connections->revision( $paused ), $source['fields'], true ) );
  $unknown = $new; $unknown['event_id'] .= '-unknown'; $unknown['evidence']['eligibility'] = 'unknown'; $unknown['evidence']['checked_at'] = null;
  // A separate Program uses the same source-owned result but no customer requirement.
  $metadata2 = $unwrap( ( new ScopedFieldContract() )->with_program_metadata( array(), array( $keys['result'] => 'required' ), $catalog ) );
  $program2 = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA App Updates ' . $nonce, 'code' => 'QVU-' . $nonce, 'metadata' => $metadata2 ) ) ); $unwrap( $templates->assign( $design, 'program', $program2 ) );
  $connection2 = $unwrap( $connections->create( 'QA Updates', $program2, 0, array( 'Score' => $keys['result'] ) ) );
  $send2 = static function ( $mode, $body, $ref = '' ) use ( $wire, $bridge, $connection2, &$timestamp ) { return $bridge->handle( $connection2['key'], $mode, $timestamp, 'sha256=' . hash_hmac( 'sha256', $wire->signing_input( $connection2['key'], $mode, $timestamp, $body, $ref ), $connection2['secret'] ), $body, $ref ); };
  $waiting = $unwrap( $send2( 'intake', wp_json_encode( $unknown ) ) ); $check( 'waiting' === $waiting['status'], 'unknown qualification stays durable and cannot issue with complete customer details' );
  $update = array( 'schema_version' => 2, 'event_id' => 'new-evidence-' . $nonce, 'expected_revision' => $waiting['revision'], 'evidence' => $payload['evidence'] ); $update_raw = wp_json_encode( $update );
  $rest_update = new WP_REST_Request( 'POST', $wire->route( $connection2['key'], 'evidence', $waiting['request_ref'] ) ); $rest_update->set_body( $update_raw ); $rest_update->set_header( 'x-certloom-timestamp', $timestamp ); $rest_update->set_header( 'x-certloom-signature', 'sha256=' . hash_hmac( 'sha256', $wire->signing_input( $connection2['key'], 'evidence', $timestamp, $update_raw, $waiting['request_ref'] ), $connection2['secret'] ) );
  $rest_reply = rest_do_request( $rest_update ); $check( 202 === $rest_reply->get_status() && false !== strpos( $rest_reply->get_headers()['Cache-Control'], 'no-store' ), 'real REST evidence route returns a private accepted receipt' );
  $updated = $rest_reply->get_data(); $check( 'issued' === $updated['status'], 'new signed current evidence can release the same request' );
  $rest_update->set_query_params( array( 'source' => $source['key'], 'request_ref' => str_repeat( 'a', 32 ) ) ); $rest_reply = rest_do_request( $rest_update ); $check( 202 === $rest_reply->get_status() && $updated['request_ref'] === $rest_reply->get_data()['request_ref'] && $rest_reply->get_data()['idempotent_replay'], 'query parameters cannot replace an evidence route source or request reference' );
  $evidence_replay = $unwrap( $send2( 'evidence', $update_raw, $waiting['request_ref'] ) ); $check( $evidence_replay['idempotent_replay'] && $updated['version_id'] === $evidence_replay['version_id'], 'exact evidence retry reconciles after issuance without reopening it' );
  $changed_update = $update; $changed_update['evidence']['fields']['Score']['value'] = '1'; $bad = $send2( 'evidence', wp_json_encode( $changed_update ), $waiting['request_ref'] );
  $check( is_wp_error( $bad ), 'changed evidence event cannot rewrite an issued receipt' );
  $check( is_wp_error( $send2( 'evidence', $update_raw . "\n", $waiting['request_ref'] ) ), 'the same evidence event with changed raw bytes conflicts even when normalized values match' );
  $check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id IN (%d,%d)', SchemaManager::table( 'email_deliveries' ), $issued['version_id'], $updated['version_id'] ) ), 'blank customer email has no delivery side effect' );
  $connection3 = $unwrap( $connections->create( 'QA Changed Map', $program2, 0, array( 'Score' => $keys['result'] ) ) );
  $body3 = wp_json_encode( $unknown ); $held3 = $unwrap( $bridge->handle( $connection3['key'], 'intake', $timestamp, 'sha256=' . hash_hmac( 'sha256', $wire->signing_input( $connection3['key'], 'intake', $timestamp, $body3 ), $connection3['secret'] ), $body3 ) );
  $check( $held3['request_ref'] !== $waiting['request_ref'], 'same event ID is isolated between exact connections' );
  $source3 = $unwrap( $connections->find( $connection3['key'] ) ); $unwrap( $connections->update_mapping( $source3['key'], $connections->revision( $source3 ), array( 'Marks' => $keys['result'] ), true ) );
  $held_row3 = $unwrap( $requests->find_by_public_ref( $held3['request_ref'] ) ); $held3 = $unwrap( ( new PendingIssuanceService() )->process( $held_row3['id'] ) );
  $check( 'blocked' === $held3['status'] && 'source_mapping_changed' === json_decode( $held3['blockers_json'], true )[0]['code'], 'changed mapping holds original pending qualification instead of remapping it' );
  $source3 = $unwrap( $connections->find( $connection3['key'] ) ); $unwrap( $connections->delete( $source3['key'], $connections->revision( $source3 ) ) );
  $held3 = $unwrap( ( new PendingIssuanceService() )->process( $held_row3['id'] ) );
  $check( 'blocked' === $held3['status'] && null === $connections->find( $source3['key'] ), 'removing a connection preserves its held request and never creates a replacement mapping' );

  $enrollment_key = 'qa_v2_enrollment_' . $nonce; $enrollment_field = $catalog[ $keys['personal'] ]; $enrollment_field['key'] = $enrollment_key; $enrollment_field['label'] = 'QA Registration ID'; $enrollment_field['collection_policy']['lifetime'] = 'registration'; $enrollment_field['collection_policy']['collect_when'] = 'registration'; $enrollment_field['collection_policy']['approval_required'] = false;
  $fields = Ecapla_CertVerify_Fields::get_fields(); $fields[] = $enrollment_field; $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'enrollment details have a distinct recipient-owned collection policy' ); $catalog = Ecapla_CertVerify_Fields::get_field_map();
  $metadata3 = $unwrap( ( new ScopedFieldContract() )->with_program_metadata( array(), array( $keys['result'] => 'required', $enrollment_key => 'required' ), $catalog ) );
  $program3 = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Exact Enrollment ' . $nonce, 'code' => 'QVE-' . $nonce, 'metadata' => $metadata3 ) ) ); $unwrap( $templates->assign( $design, 'program', $program3 ) );
  $connection4 = $unwrap( $connections->create( 'QA Enrollments', $program3, 0, array( 'Score' => $keys['result'] ) ) );
  $send4 = static function ( $mode, $body ) use ( $wire, $bridge, $connection4, &$timestamp ) { return $bridge->handle( $connection4['key'], $mode, $timestamp, 'sha256=' . hash_hmac( 'sha256', $wire->signing_input( $connection4['key'], $mode, $timestamp, $body ), $connection4['secret'] ), $body ); };
  $registrations = new \Certloom\Infrastructure\Repository\ProgramRegistrationRepository(); $enrollment1 = $unwrap( $registrations->ensure( $recipient['id'], $program3, 0, 'manual', 'v2-attempt1-' . $nonce ) ); $enrollment2 = $unwrap( $registrations->ensure( $recipient['id'], $program3, 0, 'manual', 'v2-attempt2-' . $nonce ) );
  $enrollment_payload = $payload; $enrollment_payload['event_id'] = 'enrollment-' . $nonce;
  $missing_enrollment = $send4( 'intake', wp_json_encode( $enrollment_payload ) );
  $check( is_wp_error( $missing_enrollment ) && 'certloom_webhook_v2_exact_registration_required' === $missing_enrollment->get_error_code(), 'the app cannot guess or create an enrollment when Program details need an exact registration' );
  $enrollment_payload['registration_id'] = $enrollment1['id']; $enrollment_raw = wp_json_encode( $enrollment_payload ); $enrollment_receipt = $unwrap( $send4( 'intake', $enrollment_raw ) ); $enrollment_request = $unwrap( $requests->find_by_public_ref( $enrollment_receipt['request_ref'] ) );
  $check( 'waiting' === $enrollment_receipt['status'] && $enrollment1['id'] === $enrollment_request['registration_id'], 'accepted app intent keeps the exact first attempt rather than selecting a sibling enrollment' );
  wp_set_current_user( $user ); $view = $unwrap( $collection->read_for_user( $user, $enrollment1['id'], 0, $enrollment_request['id'] ) );
  $check( array( $enrollment_key ) === array_column( $view['fields'], 'key' ) && is_wp_error( $collection->save_for_user( $user, $enrollment2['id'], $view['revision'], array( $enrollment_key => '00126' ), $enrollment_request['id'] ) ), 'request collection stays bound to the exact enrollment and excludes trusted results' );
  $unwrap( $collection->save_for_user( $user, $enrollment1['id'], $view['revision'], array( $enrollment_key => '00126' ), $enrollment_request['id'] ) ); wp_set_current_user( $actor );
  $enrollment_ready = $unwrap( $send4( 'test', $enrollment_raw ) ); $check( $enrollment_ready['ready'] && ! $enrollment_ready['mutations'], 'signed readiness preview becomes ready after its exact customer enrollment is complete' );
  $enrollment_issued = $unwrap( ( new PendingIssuanceService() )->process( $enrollment_request['id'] ) ); $enrollment_version = $unwrap( ( new CredentialRepository() )->find_version( $enrollment_issued['version_id'] ) );
  $check( 'issued' === $enrollment_issued['status'] && '00126' === $enrollment_version['snapshot']['fields']['values'][ $enrollment_key ] && ! $unwrap( $registrations->find( $enrollment2['id'] ) )['fields'], 'canonical app issuance freezes only the completed enrollment and leaves a sibling untouched' );
  $users[] = $other_user = $unwrap( wp_insert_user( array( 'user_login' => 'qa-v2-other-' . $nonce, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'QA Other App Customer' ) ) ); $other_recipient = $unwrap( $recipients->find_by_wp_user_id( $other_user ) );
  $other_enrollment = $unwrap( $registrations->ensure( $other_recipient['id'], $program3, 0, 'manual', 'v2-other-' . $nonce ) ); $enrollment_payload['event_id'] .= '-other'; $enrollment_payload['registration_id'] = $other_enrollment['id']; $prior_tables = $fingerprints(); $prior_options = $options();
  $check( is_wp_error( $send4( 'intake', wp_json_encode( $enrollment_payload ) ) ) && $prior_tables === $fingerprints() && $prior_options['sha256'] === $options()['sha256'], 'an app cannot bind one recipient to another customer enrollment or mutate rejected state' );

  $program4 = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Repair Connection ' . $nonce, 'code' => 'QVR-' . $nonce ) ) ); $unwrap( $templates->assign( $design, 'program', $program4 ) ); $orphan_created = $unwrap( $connections->create( 'QA Repair', $program4, 0, array() ) ); $orphan = $unwrap( $connections->find( $orphan_created['key'] ) );
  $check( 1 === $wpdb->delete( SchemaManager::table( 'programs' ), array( 'id' => $program4 ), array( '%d' ) ), 'owned fixture simulates an orphaned Program reference' );
  $unwrap( $connections->update_mapping( $orphan['key'], $connections->revision( $orphan ), array(), false ) ); $orphan = $unwrap( $connections->find( $orphan['key'] ) );
  $check( ! $orphan['active'] && is_wp_error( $connections->update_mapping( $orphan['key'], $connections->revision( $orphan ), array(), true ) ), 'an orphaned connection can be paused but cannot be activated without its current Program' );
  $unwrap( $connections->rotate( $orphan['key'], $connections->revision( $orphan ) ) ); $orphan = $unwrap( $connections->find( $orphan['key'] ) ); $unwrap( $connections->delete( $orphan['key'], $connections->revision( $orphan ) ) );
  $check( null === $connections->find( $orphan['key'] ), 'staff can rotate and remove an orphan safely without a missing-target lock' );
 } catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); }
 return new WP_Error( 'qa_v2_rollback', 'Rollback owned fixtures.' );
} );
DatabaseOptions::invalidate( array_merge( $before_options['keys'], $options()['keys'] ) ); Ecapla_CertVerify_Fields::reset_runtime_cache();
$after = $fingerprints(); $after_options = $options(); $changed_tables = array_keys( array_diff_assoc( $after, $before ) );
$new_options = array_keys( array_diff_assoc( $after_options['hashes'], $before_options['hashes'] ) );
$old_options = array_keys( array_diff_assoc( $before_options['hashes'], $after_options['hashes'] ) );
$changed_options = array_values( array_unique( array_merge( $new_options, $old_options ) ) );
$preserved = $before === $after && $before_options['sha256'] === $after_options['sha256']; $success = null === $failure && is_wp_error( $result ) && 'qa_v2_rollback' === $result->get_error_code() && $preserved;
echo wp_json_encode( array( 'success' => $success, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'tracked_rows_options_cron_preserved' => $preserved, 'changed_tables' => $changed_tables, 'changed_option_names' => $changed_options, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; exit( $success ? 0 : 1 );
