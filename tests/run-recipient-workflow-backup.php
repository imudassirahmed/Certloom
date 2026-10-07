<?php
/** Real workflow row semantics, portable archive versions and restore execution holds. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
use Certloom\Application\Backup\DomainBackupFormat;
use Certloom\Application\Restore\RecipientWorkflowRowValidator;
use Certloom\Application\Restore\RecipientWorkflowGraphValidator;
use Certloom\Application\Restore\RecipientWorkflowRestorePolicy;
use Certloom\Domain\Recipient\FieldSubmissionScope;
use Certloom\Infrastructure\Database\SchemaManager;
$passed = 0; $failed = 0;
function rwb_check( $condition, $label ) { global $passed, $failed; if ( $condition ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
$registration = array( 'id' => '8', 'recipient_id' => '2', 'program_id' => '3', 'batch_id' => null, 'instance_key' => str_repeat( 'a', 64 ), 'source_type' => 'manual', 'source_ref' => null, 'status' => 'active', 'fields_json' => '{"registration_number":"0009"}', 'revision' => '1', 'created_at' => '2026-10-02 00:00:00', 'updated_at' => '2026-10-02 00:00:00' );
$submission = array( 'id' => '9', 'public_ref' => str_repeat( 'b', 32 ), 'recipient_id' => '2', 'scope_kind' => 'registration', 'scope_id' => '8', 'field_key' => 'registration_number', 'scope_key' => FieldSubmissionScope::key( 2, 'registration', 8, 'registration_number' ), 'open_slot' => '1', 'candidate_value' => '0009', 'policy_sha256' => str_repeat( 'c', 64 ), 'status' => 'pending', 'submitted_by' => '11', 'decided_by' => null, 'decided_at' => null, 'revision' => '1', 'created_at' => '2026-10-02 00:00:00', 'updated_at' => '2026-10-02 00:00:00' );
$request = array_fill_keys( SchemaManager::column_names( 'issuance_requests' ), null );
$request = array_merge( $request, array( 'id' => '10', 'public_ref' => str_repeat( 'd', 32 ), 'intake_key' => str_repeat( 'e', 64 ), 'payload_sha256' => str_repeat( 'f', 64 ), 'recipient_id' => '2', 'program_id' => '3', 'registration_id' => '8', 'source_type' => 'manual', 'intake_json' => '{}', 'evidence_json' => '{}', 'evidence_revision' => '1', 'restore_state' => 'local', 'status' => 'ready', 'attempt_count' => '0', 'revision' => '1', 'created_at' => '2026-10-02 00:00:00', 'updated_at' => '2026-10-02 00:00:00', 'review_sha256' => str_repeat( 'a', 64 ), 'next_attempt_at' => '2026-10-02 00:00:00' ) );
$evidence = array( '_meta' => array( 'schema' => 'certloom-issuance-evidence', 'schema_version' => 1 ), 'eligibility' => 'unknown', 'checked_at' => null, 'valid_until' => null, 'fields' => array() );
$request['intake_json'] = ( new \Certloom\Application\Rendering\CanonicalJson() )->encode( array( '_meta' => array( 'schema' => 'certloom-issuance-intake', 'schema_version' => 1 ), 'request' => array( 'recipient_mode' => 'existing', 'recipient_id' => 2, 'credential_basis' => 'program', 'program_id' => 3, 'batch_id' => 0 ), 'initial_evidence' => $evidence ) );
$request['payload_sha256'] = hash( 'sha256', $request['intake_json'] );
$request['evidence_json'] = wp_json_encode( $evidence );
$validator = new RecipientWorkflowRowValidator();
foreach ( array( 'program_registrations' => $registration, 'field_submissions' => $submission, 'issuance_requests' => $request ) as $entity => $row ) {
	rwb_check( true === $validator->validate( $entity, $row ), 'valid ' . $entity . ' row keeps scalar identity and text identifiers' );
	$bad = $row; $bad['recipient_id'] = array( 2 );
	rwb_check( is_wp_error( $validator->validate( $entity, $bad ) ), 'structured IDs fail closed for ' . $entity );
}
$bad = $submission; $bad['scope_key'] = str_repeat( 'a', 64 );
rwb_check( is_wp_error( $validator->validate( 'field_submissions', $bad ) ), 'submission scope digest cannot point at another owner or context' );
$bad = $submission; $bad['status'] = 'approved';
rwb_check( is_wp_error( $validator->validate( 'field_submissions', $bad ) ), 'a decided submission cannot occupy the pending slot' );
$bad = $registration; $bad['fields_json'] = '{"score":{"value":0}}';
rwb_check( is_wp_error( $validator->validate( 'program_registrations', $bad ) ), 'uncontracted repeating values are rejected' );
$bad = $request; $bad['intake_json'] = '{}';
rwb_check( is_wp_error( $validator->validate( 'issuance_requests', $bad ) ), 'an arbitrary JSON object is not executable intake' );
$bad = $request; $bad['recipient_id'] = '7';
rwb_check( is_wp_error( $validator->validate( 'issuance_requests', $bad ) ), 'local intake cannot change owners independently of its immutable payload' );
$bad = $request; $bad['evidence_json'] = '{}';
rwb_check( is_wp_error( $validator->validate( 'issuance_requests', $bad ) ), 'untyped evidence cannot enter live storage or recovery' );
$bad = $request; $bad['blockers_json'] = '[{"code":"missing","value":"private-answer"}]';
rwb_check( is_wp_error( $validator->validate( 'issuance_requests', $bad ) ), 'general request blockers cannot store private candidate values' );
$erased = $request; $erased['status'] = 'cancelled'; $erased['intake_json'] = '{}'; $erased['evidence_json'] = null; $erased['review_sha256'] = null; $erased['next_attempt_at'] = null;
rwb_check( true === $validator->validate( 'issuance_requests', $erased ), 'cancelled privacy tombstones retain durable history without executable data' );
$graph = new RecipientWorkflowGraphValidator();
$graph->observe( 'recipients', array( 'id' => 2 ) ); $graph->observe( 'programs', array( 'id' => 3 ) );
rwb_check( true === $graph->validate( 'program_registrations', $registration ), 'registration references existing recipient and Program' );
$graph->observe( 'program_registrations', $registration );
rwb_check( true === $graph->validate( 'field_submissions', $submission ), 'submission belongs to the exact registration owner' );
$bad = $submission; $bad['recipient_id'] = 7;
rwb_check( is_wp_error( $graph->validate( 'field_submissions', $bad ) ), 'another recipient cannot inherit a registration submission' );
rwb_check( true === $graph->validate( 'issuance_requests', $request ), 'request binds recipient, Program and registration together' );
$bad = $request; $bad['public_ref'] = str_repeat( 'c', 32 );
rwb_check( is_wp_error( $graph->validate( 'issuance_requests', $bad ) ), 'duplicate intake remains a conflict with a new public reference' );
$restored = RecipientWorkflowRestorePolicy::prepare( 'issuance_requests', $request );
rwb_check( 'blocked' === $restored['status'] && 'paused' === $restored['restore_state'] && null === $restored['next_attempt_at'] && null === $restored['review_sha256'], 'restoring ready work removes execution authority and queued due time' );
rwb_check( $request['intake_json'] === $restored['intake_json'] && $request['payload_sha256'] === $restored['payload_sha256'], 'restore preserves immutable intake and replay identity' );
$approved = $submission; $approved['status'] = 'approved'; $approved['open_slot'] = null; $approved['decided_by'] = 12;
$restored = RecipientWorkflowRestorePolicy::prepare( 'field_submissions', $approved );
rwb_check( null === $restored['submitted_by'] && null === $restored['decided_by'] && '0009' === $restored['candidate_value'], 'restored actor IDs do not authorize approval while candidate history is retained' );
$format = new DomainBackupFormat();
foreach ( DomainBackupFormat::supported_versions() as $version ) {
	$entities = array_fill_keys( DomainBackupFormat::entity_types_for_version( $version ), array( 'count' => 0, 'max_id' => 0 ) );
	$manifest = $format->manifest( array( 'entities' => $entities ), 'test' ); $manifest['format_version'] = $version;
	$normalized = $format->normalize_manifest( $manifest );
	rwb_check( ! is_wp_error( $normalized ) && 0 === $normalized['entities']['issuance_requests']['count'], 'archive version ' . $version . ' retains its canonical entity set and normalizes missing workflows' );
}
rwb_check( $format->next_hash( '', "record\n", 4 ) === hash( 'sha256', DomainBackupFormat::HASH_SEED_V4 . "\nrecord\n" ), 'existing v4 archive hash seed is unchanged' );
rwb_check( $format->next_hash( '', "record\n", 4 ) !== $format->next_hash( '', "record\n", 5 ), 'workflow archive version has a distinct hash seed' );
echo "\n$passed checks passed, $failed failures\n"; exit( $failed ? 1 : 0 );
