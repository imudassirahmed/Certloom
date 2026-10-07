<?php
/** Exact reviewed account links, private closed notices, rollback and sync races. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Verified private backup and exact Local root required.\n" ); exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { fwrite( STDERR, "Refusing another site.\n" ); exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled', 'No external transport.' ); }, PHP_INT_MAX );

use Certloom\Application\Recipient\RecipientAccountLink;
use Certloom\Application\Recipient\RecipientAccountLinkService;
use Certloom\Application\Recipient\RecipientIssuanceRequests;
use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Repository\FieldSubmissionRepository;
use Certloom\Infrastructure\Recipient\RecipientUserSync;

$checks = array(); $failure = null; $users = array();
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$unwrap = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; };
$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients', 'programs', 'issuers', 'signers', 'batches', 'batch_recipients', 'signer_assignments', 'templates', 'template_revisions', 'template_assignments', 'credential_series', 'credential_versions', 'credential_numbers', 'credential_events', 'jobs', 'email_deliveries', 'destination_deliveries', 'import_rows', 'restore_map', 'program_registrations', 'field_submissions', 'issuance_requests', 'issuance_request_events' ) ) );
$fingerprints = static function () use ( $tables ) {
	global $wpdb; $out = array();
	foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Preservation read failed.' ); } $out[ $table ] = hash( 'sha256', wp_json_encode( $rows ) ); }
	return $out;
};
$options = static function () { global $wpdb; $rows = $wpdb->get_results( "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE LEFT(option_name,9)='certloom_' OR LEFT(option_name,11)='certverify_' OR option_name='cron' ORDER BY option_name", ARRAY_A ); if ( ! is_array( $rows ) || $wpdb->last_error ) { throw new RuntimeException( 'Option preservation failed.' ); } return array( 'sha256' => hash( 'sha256', wp_json_encode( $rows ) ), 'keys' => array_column( $rows, 'option_name' ), 'hashes' => array_combine( array_column( $rows, 'option_name' ), array_map( static function ( $row ) { return hash( 'sha256', wp_json_encode( $row ) ); }, $rows ) ) ); };
$before = $fingerprints(); $before_options = $options();
$result = ( new Transaction() )->run( static function () use ( $check, $unwrap, $fingerprints, $options, &$users, &$failure ) {
	try {
		global $wpdb;
		$nonce = bin2hex( random_bytes( 6 ) ); $actor = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0]; wp_set_current_user( $actor );
		$repo = new RecipientRepository(); $policy = new RecipientAccountLink(); $service = new RecipientAccountLinkService(); $requests = new IssuanceRequestRepository(); $sync = new RecipientUserSync();
		foreach ( array( 'owner', 'other' ) as $role ) { $users[ $role ] = $unwrap( wp_insert_user( array( 'user_login' => 'qa-link-' . $role . '-' . $nonce, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber', 'display_name' => 'QA Account ' . $role ) ) ); }
		$owner = $users['owner']; $other = $users['other'];
		$auto = $unwrap( $repo->find_by_wp_user_id( $owner ) ); $other_recipient = $unwrap( $repo->find_by_wp_user_id( $other ) );
		$check( is_array( $auto ) && is_array( $other_recipient ), 'registration links only the exact new account to its own recipient' );
		$recipient_id = $unwrap( $repo->create( array( 'display_name' => 'Reviewed Certificate Name', 'custom_fields' => array( 'department' => 'Quality' ) ) ) ); $recipient = $unwrap( $repo->find( $recipient_id ) );
		$program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Link Program ' . $nonce, 'code' => 'QL-' . $nonce ) ) );
		$receive = static function ( $key, $recipient, $eligibility = 'unknown' ) use ( $requests, $program, $nonce, $unwrap ) {
			return $unwrap( $requests->receive( array( 'recipient_id' => (int) $recipient, 'program_id' => $program, 'source_type' => 'manual', 'intake_key' => hash( 'sha256', $nonce . $key ) ), array( 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QL-' . $nonce . '-' . $key ), array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => $eligibility, 'checked_at' => current_time( 'mysql', true ), 'valid_until' => null, 'fields' => array() ) ) );
		};
		$waiting = $receive( 'waiting', $recipient_id );
		$bad = $service->update( $recipient_id, array( 'wp_user_id' => $owner ), $policy->revision( $recipient ), true, $other );
		$check( is_wp_error( $bad ) && 403 === $bad->get_error_data()['status'], 'a recipient cannot claim another recipient record' );
		$bad = $service->update( $recipient_id, array( 'wp_user_id' => $owner ), $policy->revision( $recipient ), false, $actor );
		$check( is_wp_error( $bad ) && 'certloom_recipient_account_review_required' === $bad->get_error_code(), 'staff must explicitly confirm ownership' );
		$bad = $service->update( $recipient_id, array( 'wp_user_id' => $owner ), $policy->revision( $recipient ), true, $actor );
		$check( is_wp_error( $bad ) && 'certloom_recipient_wp_user_duplicate' === $bad->get_error_code(), 'an already connected account is never merged or stolen' );
		$unwrap( $service->update( $auto['id'], array( 'wp_user_id' => null ), $policy->revision( $auto ), true, $actor ) );
		$check( RecipientAccountLinkService::sync_held( $owner ) && null === $unwrap( $repo->find_by_wp_user_id( $owner ) ), 'unlink stores a durable account sync hold' );
		$unwrap( $sync->sync_linked_user( $owner ) ); $bad = $sync->ensure_for_user( $owner );
		$check( is_wp_error( $bad ) && null === $unwrap( $repo->find_by_wp_user_id( $owner ) ), 'profile edits and explicit integration ensure cannot undo a reviewed unlink' );
		$unwrap( $service->update( $recipient_id, array( 'wp_user_id' => $owner ), $policy->revision( $recipient ), true, $actor ) );
		$recipient = $unwrap( $repo->find( $recipient_id ) );
		$check( $owner === $recipient['wp_user_id'] && ! RecipientAccountLinkService::sync_held( $owner ) && 'Reviewed Certificate Name' === $recipient['display_name'], 'reviewed link clears only its hold and preserves the organization identity' );
		$check( $waiting['intake_json'] === $unwrap( $requests->find( $waiting['id'] ) )['intake_json'] && 'received' === $unwrap( $requests->find( $waiting['id'] ) )['status'], 'connecting an existing recipient preserves pending qualification and intake' );
		$unwrap( wp_update_user( array( 'ID' => $owner, 'display_name' => 'Changed nickname' ) ) ); update_user_meta( $owner, 'first_name', 'Changed first name' );
		$check( 'Reviewed Certificate Name' === $unwrap( $repo->find( $recipient_id ) )['display_name'], 'WordPress nickname and name sync cannot overwrite a staff-reviewed certificate name' );
		$before_stale = $fingerprints(); $bad = $service->update( $recipient_id, array( 'wp_user_id' => null ), str_repeat( '0', 64 ), true, $actor );
		$check( is_wp_error( $bad ) && 409 === $bad->get_error_data()['status'] && $before_stale === $fingerprints(), 'stale review rejects every connection and workflow change' );
		$recipient = $unwrap( $repo->find( $recipient_id ) ); $bad = $service->update( $recipient_id, array( 'wp_user_id' => $other ), $policy->revision( $recipient ), true, $actor );
		$check( is_wp_error( $bad ) && 'certloom_recipient_account_unlink_first' === $bad->get_error_code(), 'staff must unlink before selecting a different account' );
		$submission = $unwrap( ( new FieldSubmissionRepository() )->propose( $recipient_id, 'recipient', $recipient_id, 'qa_identity', 'Private candidate', hash( 'sha256', 'qa-proof' ), $owner ) );
		$issued_request = $receive( 'issued', $recipient_id, 'eligible' ); $issued = $unwrap( ( new PendingIssuanceService() )->process( $issued_request['id'] ) );
		$check( 'issued' === $issued['status'], 'trusted eligibility still uses canonical details-only issuance' );
		$version_before = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'credential_versions' ), $issued['version_id'] ), ARRAY_A );
		$check( false === strpos( $version_before['snapshot_json'], RecipientAccountLink::KEY ), 'private account connection history stays out of immutable certificate snapshots' );
		$wpdb->update( SchemaManager::table( 'issuance_requests' ), array( 'status' => 'processing', 'series_id' => null, 'version_id' => null, 'revision' => $issued['revision'] + 1 ), array( 'id' => $issued['id'] ) );
		$recipient = $unwrap( $repo->find( $recipient_id ) );
		foreach ( array( 'hold', 'recipient', 'event' ) as $mode ) {
			$prior = $fingerprints(); $prior_options = $options();
			$inject = static function ( $sql ) use ( $mode, $nonce ) {
				global $wpdb;
				if ( ( 'hold' === $mode && 0 === strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, RecipientAccountLinkService::SYNC_HOLD_META ) ) || ( 'recipient' === $mode && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, SchemaManager::table( 'recipients' ) ) ) || ( 'event' === $mode && 0 === strpos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, SchemaManager::table( 'issuance_request_events' ) ) ) ) { return 'UPDATE `__qa_link_missing_' . $nonce . '` SET id=0'; } return $sql;
			};
			$old_errors = $wpdb->suppress_errors(); add_filter( 'query', $inject, PHP_INT_MAX );
			try { $bad = $service->update( $recipient_id, array( 'wp_user_id' => null ), $policy->revision( $recipient ), true, $actor ); } finally { remove_filter( 'query', $inject, PHP_INT_MAX ); $wpdb->suppress_errors( $old_errors ); }
			$check( is_wp_error( $bad ) && $prior === $fingerprints() && $prior_options === $options() && ! RecipientAccountLinkService::sync_held( $owner ), $mode . ' failure rolls back account, canonical receipt repair, submissions and requests together' );
		}
		$unwrap( $service->update( $recipient_id, array( 'wp_user_id' => null ), $policy->revision( $recipient ), true, $actor ) );
		$closed = $unwrap( $requests->find( $waiting['id'] ) );
		$check( 'cancelled' === $closed['status'] && null === $closed['next_attempt_at'] && $waiting['evidence_json'] === $closed['evidence_json'], 'unlink closes waiting work without rewriting trusted evidence' );
		$check( 'cancelled' === $unwrap( ( new FieldSubmissionRepository() )->find( $submission['id'] ) )['status'], 'old-account pending detail approval cannot survive unlink' );
		$check( 'issued' === $unwrap( $requests->find( $issued['id'] ) )['status'] && $version_before === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'credential_versions' ), $issued['version_id'] ), ARRAY_A ), 'unlink reconciles an interrupted receipt and never cancels or changes its committed certificate' );
		$check( ! $unwrap( ( new RecipientIssuanceRequests() )->read_for_user( $owner ) )['linked'], 'disconnected account immediately loses recipient collection access' );
		$recipient = $unwrap( $repo->find( $recipient_id ) ); $unwrap( $service->update( $recipient_id, array( 'wp_user_id' => $owner ), $policy->revision( $recipient ), true, $actor ) );
		$closed_view = $unwrap( ( new RecipientIssuanceRequests() )->read_for_user( $owner, 0, 'closed' ) );
		$check( 2 === count( $closed_view['items'] ) && array( 'cancelled', 'issued' ) === array_column( $closed_view['items'], 'notice' ) && ! array_filter( $closed_view['items'], static function ( $row ) { return $row['can_complete']; } ), 'owner can see private cancelled and issued notices without stale completion actions' );
		$check( empty( $unwrap( ( new RecipientIssuanceRequests() )->read_for_user( $owner ) )['items'] ) && empty( $unwrap( ( new RecipientIssuanceRequests() )->read_for_user( $other, 0, 'closed' ) )['items'] ), 'closed history does not clutter active tasks or leak to another account' );
		$check( false === strpos( wp_json_encode( $closed_view ), 'Private candidate' ) && false === strpos( wp_json_encode( $closed_view ), 'intake_key' ), 'recipient closure notices expose neither detail candidates nor source intake' );
		$check( is_wp_error( ( new RecipientIssuanceRequests() )->read_for_user( $owner, 0, 'everything' ) ), 'unsupported account history views fail closed' );
		$suspended_request = $receive( 'suspended', $recipient_id, 'eligible' );
		( new WP_User( $owner ) )->set_role( '' );
		$suspended = $unwrap( ( new PendingIssuanceService() )->process( $suspended_request['id'] ) );
		$check( 'blocked' === $suspended['status'] && null === $suspended['next_attempt_at'] && 'account_connection_unavailable' === json_decode( $suspended['blockers_json'], true )[0]['code'], 'suspended accounts hold pending work without endless worker retries or changed qualification' );
		( new WP_User( $owner ) )->set_role( 'subscriber' );
		$reactivated = $unwrap( $requests->find( $suspended_request['id'] ) );
		$check( null !== $reactivated['next_attempt_at'] && $suspended['evidence_json'] === $reactivated['evidence_json'], 'account reactivation queues fresh readiness without supplying new qualification' );
		$unwrap( $requests->transition( $reactivated['id'], $reactivated['revision'], array( 'status' => 'cancelled', 'next_attempt_at' => null ), 'organization_cancelled', $actor ) );
		$name_policy = array( '_meta' => array( 'schema' => 'certloom-field-collection', 'schema_version' => 1, 'configured' => true ), 'writer' => 'recipient', 'lifetime' => 'recipient', 'collect_when' => 'profile', 'approval_required' => true, 'show_on_certificate' => true, 'help' => 'Check your legal certificate name.' );
		$fields = Ecapla_CertVerify_Fields::get_fields(); $fields[] = array( 'key' => 'qa_legal_name', 'label' => 'QA Certificate Name', 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'profile', 'collection_policy' => $name_policy );
		$check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'certificate-name field uses the ordinary collection catalog' );
		$settings = Ecapla_CertVerify_Settings::get_all(); $settings['certificate_name_source'] = 'approved_field'; $settings['certificate_name_field_key'] = 'qa_legal_name';
		$contract = new \Certloom\Application\Fields\ScopedFieldContract();
		$excluded = $unwrap( $contract->with_program_metadata( array(), array( 'qa_legal_name' => 'exclude' ), Ecapla_CertVerify_Fields::get_field_map() ) );
		$unwrap( ( new ProgramRepository() )->update( $program, array( 'metadata' => $excluded ) ) );
		$prior_settings = Ecapla_CertVerify_Settings::get_all(); $prior_options = $options();
		$check( ! Ecapla_CertVerify_Settings::save( $settings ) && $prior_settings === Ecapla_CertVerify_Settings::get_all() && $prior_options === $options(), 'organization-wide name selection refuses an existing Program exclusion without changing settings or pending signals' );
		$unwrap( ( new ProgramRepository() )->update( $program, array( 'metadata' => array() ) ) );
		$check( Ecapla_CertVerify_Settings::save( $settings ), 'organization selects one approved-name source for future certificates' );
		$active_fields = Ecapla_CertVerify_Fields::get_fields(); $prior_options = $options();
		$without_name = array_values( array_filter( $active_fields, static function ( $field ) { return 'qa_legal_name' !== $field['key']; } ) );
		$check( ! Ecapla_CertVerify_Fields::save_fields( $without_name ) && $prior_options === $options(), 'the catalog cannot remove an active required name source' );
		$unreviewed_name = $active_fields;
		foreach ( $unreviewed_name as &$field ) { if ( 'qa_legal_name' === $field['key'] ) { $field['collection_policy']['approval_required'] = false; } } unset( $field );
		$check( ! Ecapla_CertVerify_Fields::save_fields( $unreviewed_name ) && $prior_options === $options(), 'the catalog cannot downgrade exact approval for an active name source' );
		$collection = new \Certloom\Application\Recipient\RecipientCollectionService();
		$name_view = $unwrap( $collection->read_for_user( $owner ) );
		$name_fields = array_values( array_filter( $name_view['fields'], static function ( $field ) { return 'qa_legal_name' === $field['key']; } ) );
		$check( 'approved_field' === $name_view['certificate_name']['source'] && 1 === count( $name_fields ) && $name_fields[0]['required'], 'required name appears in the actual customer account before their first applicable certificate' );
		$name_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Name Program ' . $nonce, 'code' => 'QN-' . $nonce ) ) );
		$name_request = $unwrap( $requests->receive( array( 'recipient_id' => $recipient_id, 'program_id' => $name_program, 'source_type' => 'manual', 'intake_key' => hash( 'sha256', $nonce . 'legal-name' ) ), array( 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QN-' . $nonce ), array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => 'eligible', 'checked_at' => current_time( 'mysql', true ), 'valid_until' => null, 'fields' => array() ) ) );
		$check( 'waiting' === $unwrap( ( new PendingIssuanceService() )->process( $name_request['id'] ) )['status'], 'qualified pending request waits for its required certificate name' );
		$unwrap( $collection->save_for_user( $owner, 0, $name_view['revision'], array( 'qa_legal_name' => 'Amina Verified Name' ) ) );
		$name_submission = $unwrap( ( new FieldSubmissionRepository() )->latest( $recipient_id, 'recipient', $recipient_id, 'qa_legal_name' ) );
		$check( 'waiting' === $unwrap( ( new PendingIssuanceService() )->process( $name_request['id'] ) )['status'], 'recipient name submission cannot substitute for exact staff approval' );
		$unwrap( $collection->decide( $name_submission['id'], $name_submission['revision'], true, $actor ) );
		$name_issued = $unwrap( ( new PendingIssuanceService() )->process( $name_request['id'] ) );
		$name_version = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'credential_versions' ), $name_issued['version_id'] ), ARRAY_A ); $name_snapshot = json_decode( $name_version['snapshot_json'], true );
		$check( 'issued' === $name_issued['status'] && 'Amina Verified Name' === $name_snapshot['recipient']['display_name'] && 'Amina Verified Name' === $name_snapshot['merge']['values']['recipient.name'] && 'Reviewed Certificate Name' === $unwrap( $repo->find( $recipient_id ) )['display_name'], 'canonical issuance freezes the approved name in both identity and artwork without rewriting the WordPress profile name' );
		$future_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Future Name Program ' . $nonce, 'code' => 'QNF-' . $nonce ) ) );
		$manual = new ManualIssuanceService(); $manual_request = array( 'recipient_mode' => 'existing', 'recipient_id' => $recipient_id, 'program_id' => $future_program, 'presentation_mode' => 'details_only', 'title_source' => 'program', 'valid_from' => gmdate( 'Y-m-d' ), 'expiry_mode' => 'permanent', 'display_number' => 'QN-NEW-' . $nonce );
		$approved_review = $unwrap( $manual->review( $manual_request ) );
		$correction_view = $unwrap( $collection->read_for_user( $owner ) ); $unwrap( $collection->save_for_user( $owner, 0, $correction_view['revision'], array( 'qa_legal_name' => 'Amina Corrected Name' ) ) );
		$held_review = $manual->review( $manual_request );
		$check( is_wp_error( $held_review ) && 'certloom_manual_issue_required_field_missing' === $held_review->get_error_code() && $name_version === $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'credential_versions' ), $name_issued['version_id'] ), ARRAY_A ), 'a proposed name correction holds future issuance while the issued certificate remains immutable' );
		$held_issue = $manual->issue( $manual_request, $approved_review['review_fingerprint'], $actor );
		$check( is_wp_error( $held_issue ) && 'certloom_manual_issue_required_field_missing' === $held_issue->get_error_code() && '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE recipient_id=%d AND program_id=%d', SchemaManager::table( 'credential_series' ), $recipient_id, $future_program ) ), 'an earlier approved-name review cannot issue after a new pending correction' );
		$settings['certificate_name_source'] = 'profile'; $settings['certificate_name_field_key'] = ''; $check( Ecapla_CertVerify_Settings::save( $settings ), 'organization can deliberately return future names to their declared profile authority' );
		$deletion = $receive( 'delete', $other_recipient['id'] );
		$check( is_wp_error( $service->account_deleted( $other ) ), 'a live account cannot be disconnected through the core-deletion callback' );
		$wpdb->delete( $wpdb->users, array( 'ID' => $other ) ); clean_user_cache( $other );
		$unwrap( $service->account_deleted( $other ) );
		$check( null === $unwrap( $repo->find( $other_recipient['id'] ) )['wp_user_id'] && 'cancelled' === $unwrap( $requests->find( $deletion['id'] ) )['status'], 'deleted accounts lose their bridge and cannot continue outstanding issuance' );
		return new WP_Error( 'qa_rollback', 'Rollback successful fixture.' );
	} catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); return new WP_Error( 'qa_failed', 'Rollback failed fixture.' ); }
} );
foreach ( $users as $user ) { clean_user_cache( $user ); } wp_set_current_user( 0 );
$after_options = $options(); DatabaseOptions::invalidate( array_unique( array_merge( $before_options['keys'], $after_options['keys'] ) ) );
$after = $fingerprints(); $changed_tables = array_keys( array_diff_assoc( $after, $before ) );
$changed_options = array_values( array_unique( array_merge( array_keys( array_diff_assoc( $before_options['hashes'], $after_options['hashes'] ) ), array_keys( array_diff_assoc( $after_options['hashes'], $before_options['hashes'] ) ) ) ) );
$preserved = $before === $after && $before_options === $after_options;
$success = is_wp_error( $result ) && 'qa_rollback' === $result->get_error_code() && $preserved;
echo wp_json_encode( array( 'success' => $success, 'checks_passed' => count( $checks ), 'checks' => $checks, 'failure' => $failure, 'tracked_rows_options_cron_preserved' => $preserved, 'changed_tables' => $changed_tables, 'changed_option_names' => $changed_options, 'php' => PHP_VERSION ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $success ? 0 : 1 );
